<?php
declare(strict_types=1);
namespace app\gateway\http;

use RC\Config;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Response as WorkermanResponse;
use Workerman\Protocols\Http\ServerSentEvents;
use Workerman\Timer;
use app\gateway\model\ResponseJob;
use app\gateway\model\ResponsesProtocol;
use app\gateway\model\TaskLedger;
use app\gateway\model\ContextHistory;

/** Non-blocking local HTTP subscribers for one SQLite-backed Responses job. */
final class ResponseTransport
{
    private const POLL_SECONDS = 0.08;
    private const HEARTBEAT_SECONDS = 2.0;
    private const BACKPRESSURE_BYTES = 262144;
    private const ABANDON_BYTES = 1048576;

    /**
     * Stream a durable response job and send parser-visible heartbeats while it is idle.
     *
     * @param TcpConnection $connection Open Workerman HTTP connection.
     * @param string $responseId Durable response identifier.
     * @param array<string,true> $receivedPublic Exact public items already present in this client's input.
     * @return void
     * @throws \Throwable If the durable subscriber cannot be recorded before opening the stream.
     */
    public static function stream(TcpConnection $connection, string $responseId, array $receivedPublic = []): void
    {
        $sequence = -1;
        $lastWrite = microtime(true);
        $timerId = null;
        $removedIndices = [];
        $projectedSequence = 0;
        $detach = self::observeClientTransport($connection, $responseId, $timerId);
        $timerId = Timer::add(self::POLL_SECONDS, function () use ($connection, $responseId, $detach, $receivedPublic,
            &$removedIndices, &$projectedSequence, &$sequence, &$lastWrite, &$timerId): void {
            if (!self::open($connection)) {
                $detach();
                if ($timerId !== null) Timer::del($timerId);
                return;
            }
            $queued = $connection->getSendBufferQueueSize();
            if ($queued >= self::ABANDON_BYTES) {
                $detach();
                if ($timerId !== null) Timer::del($timerId);
                $connection->close();
                return;
            }
            if ($queued >= self::BACKPRESSURE_BYTES) return;
            try {
                $events = ResponseJob::eventsAfter($responseId, $sequence);
                $terminal = false;
                foreach ($events as $event) {
                    $number = (int) ($event['sequence_number'] ?? -1);
                    if ($number <= $sequence || !is_string($event['type'] ?? null)) continue;
                    // Also normalize replayed failures recorded by earlier gateway versions.
                    if ($event['type'] === 'response.failed' && is_array($event['response']['error'] ?? null)) {
                        $event['response']['error'] = ResponsesProtocol::clientFailure(['error' => $event['response']['error']])['body']['error'];
                    }
                    $sequence = $number;
                    if ($receivedPublic !== []) {
                        $event = self::withoutReceivedPublic($event, $receivedPublic, $removedIndices);
                        if ($event === null) continue;
                        // Subscriber numbering is contiguous; the durable cursor above
                        // still advances by the original event number, including skips.
                        $event['sequence_number'] = $projectedSequence++;
                    }
                    $payload = json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                    $frame = new ServerSentEvents(['event' => $event['type'], 'data' => $payload]);
                    if ($connection->send((string) $frame, true) === false) {
                        $detach();
                        if ($timerId !== null) Timer::del($timerId);
                        $connection->close();
                        return;
                    }
                    $lastWrite = microtime(true);
                    if (in_array($event['type'], ['response.completed', 'response.failed', 'response.incomplete'], true)) {
                        $terminal = true;
                        break;
                    }
                }
                if ($terminal) {
                    $detach(true);
                    $connection->send("data: [DONE]\n\n", true);
                    if ($timerId !== null) Timer::del($timerId);
                    $connection->close();
                    return;
                }
                if ($events === [] && microtime(true) - $lastWrite >= self::HEARTBEAT_SECONDS) {
                    // This parser-ignored event resets the Codex SSE idle timer; it has no durable sequence number.
                    $heartbeat = new ServerSentEvents([
                        'event' => 'response.heartbeat',
                        'data' => '{"type":"response.heartbeat"}',
                    ]);
                    if ($connection->send((string) $heartbeat, true) === false) {
                        $detach();
                        if ($timerId !== null) Timer::del($timerId);
                        $connection->close();
                        return;
                    }
                    $lastWrite = microtime(true);
                }
            } catch (\Throwable) {
                $detach();
                if ($timerId !== null) Timer::del($timerId);
                $connection->close();
            }
        }, null, true);
    }

    /**
     * Poll metadata until a JSON terminal needs decoding, without cancelling on disconnect.
     * @param TcpConnection $connection Current local HTTP subscriber.
     * @param string $responseId Durable response to await or reconnect.
     * @param array<string,true> $receivedPublic Exact public items already present in this client's input.
     * @return void
     * @throws \Throwable If the durable subscriber cannot be recorded before polling begins.
     */
    public static function nonStream(TcpConnection $connection, string $responseId, array $receivedPublic = []): void
    {
        $timerId = null;
        $detach = self::observeClientTransport($connection, $responseId, $timerId);
        $timerId = Timer::add(self::POLL_SECONDS, function () use ($connection, $responseId, $detach, $receivedPublic, &$timerId): void {
            if (!self::open($connection)) {
                $detach();
                if ($timerId !== null) Timer::del($timerId);
                return;
            }
            try {
                $state = ResponseJob::pollState($responseId);
                if (!in_array($state['status'], ['completed', 'failed'], true)) return;
                $job = ResponseJob::get($responseId);
                if (($job['status'] ?? '') === 'completed' && is_array($job['response'] ?? null)) {
                    $detach(true);
                    if ($timerId !== null) Timer::del($timerId);
                    $response = $job['response'];
                    if ($receivedPublic !== []) $response = self::publicOutputProjection($response, $receivedPublic);
                    self::finishJson($connection, 200, $response);
                } elseif (($job['status'] ?? '') === 'failed') {
                    $detach(true);
                    if ($timerId !== null) Timer::del($timerId);
                    $error = is_array($job['error'] ?? null) ? $job['error'] : [
                        'error' => ['message' => 'ChatGPT 网页生成失败。', 'type' => 'server_error', 'param' => null, 'code' => 'response_failed'],
                    ];
                    $failure = ResponsesProtocol::clientFailure($error);
                    self::finishJson($connection, $failure['status'], $failure['body']);
                }
            } catch (\Throwable) {
                $detach();
                if ($timerId !== null) Timer::del($timerId);
                self::finishJson($connection, 503, [
                    'error' => ['message' => 'Responses 状态暂不可读取。', 'type' => 'server_error', 'param' => null, 'code' => 'storage_unavailable'],
                ]);
            }
        }, null, true);
    }

    /**
     * Prove public items this exact response already delivered before a mailbox reconnect.
     * Matching IDs alone are insufficient; the readable commentary/summary must match too.
     * Tools, finals, drafts, private reasoning and native forwarding are never filtered.
     * @param mixed $input Parsed client input. @param ?array $response Reserved Web response snapshot.
     * @return array<string,true> Already received public item IDs, empty for ordinary/new requests.
     */
    public static function receivedPublicItems(mixed $input, ?array $response): array
    {
        if (!is_array($input) || !is_array($response) || empty($response['output'])) return [];
        $published = [];
        foreach ($response['output'] as $item) {
            if (!is_string($item['id'] ?? null)) continue;
            $commentary = ($item['type'] ?? '') === 'message' && ($item['role'] ?? '') === 'assistant'
                && ($item['phase'] ?? '') === 'commentary' && ($item['status'] ?? '') === 'completed';
            $summary = ($item['type'] ?? '') === 'reasoning' && !empty($item['summary']);
            if ($commentary || $summary) $published[$item['id']] = ContextHistory::canonical(ContextHistory::compile([$item], ''));
        }
        $received = [];
        foreach ($input as $item) {
            if (!is_array($item) || !is_string($item['id'] ?? null) || !isset($published[$item['id']])) continue;
            // Only the same public wire shape may prove delivery; do not compile arbitrary
            // input content (including attachments) just because it reuses an output ID.
            if (($item['type'] ?? '') === 'message') {
                if (($item['role'] ?? '') !== 'assistant' || ($item['phase'] ?? '') !== 'commentary'
                    || ($item['status'] ?? 'completed') !== 'completed') continue;
                $textOnly = is_string($item['content'] ?? null) || (is_array($item['content'] ?? null)
                    && array_reduce($item['content'], static fn(bool $ok, mixed $part): bool => $ok && is_array($part)
                        && in_array($part['type'] ?? '', ['text','input_text','output_text'], true)
                        && is_string($part['text'] ?? null), true));
                if (!$textOnly) continue;
            } elseif (($item['type'] ?? '') !== 'reasoning' || empty($item['summary'])) continue;
            if (ContextHistory::canonical(ContextHistory::compile([$item], '')) === $published[$item['id']]) {
                $received[$item['id']] = true;
            }
        }
        return $received;
    }

    /** Subscriber-only event projection; durable events and native call identities stay intact. */
    private static function withoutReceivedPublic(array $event, array $received, array &$removedIndices): ?array
    {
        $itemId = $event['item']['id'] ?? $event['item_id'] ?? null;
        $publicEvent = in_array($event['type'] ?? '', ['response.output_item.added','response.output_item.done',
            'response.content_part.added','response.content_part.done','response.output_text.delta','response.output_text.done',
            'response.output_text.annotation.added','response.reasoning_summary_part.added','response.reasoning_summary_part.done',
            'response.reasoning_summary_text.delta','response.reasoning_summary_text.done'], true);
        if ($publicEvent && is_string($itemId) && isset($received[$itemId])) {
            if (isset($event['output_index'])) $removedIndices[(int)$event['output_index']] = true;
            return null;
        }
        if (isset($event['output_index'])) {
            $index = (int)$event['output_index'];
            $event['output_index'] = $index - count(array_filter(array_keys($removedIndices), static fn(int $removed): bool => $removed < $index));
        }
        if (is_array($event['response']['output'] ?? null)) {
            $event['response'] = self::publicOutputProjection($event['response'], $received);
        }
        return $event;
    }

    /** Remove only the previously proved public IDs from a terminal/create response projection. */
    private static function publicOutputProjection(array $response, array $received): array
    {
        if (is_array($response['output'] ?? null)) $response['output'] = array_values(array_filter($response['output'],
            static fn(array $item): bool => !isset($received[$item['id'] ?? ''])));
        return $response;
    }

    /**
     * Track this subscriber while preserving Workerman's existing close callback.
     * @param TcpConnection $connection Current local HTTP subscriber, never a browser connection.
     * @param string $responseId Durable response whose reconnect remains independently runnable.
     * @param ?int $timerId Poll timer shared with the close callback for prompt cleanup.
     * @return callable(bool=):void Idempotent disconnect recorder; terminal closures are distinguished.
     * @throws \Throwable If durable attachment evidence is unavailable; no subscription is opened.
     */
    private static function observeClientTransport(TcpConnection $connection, string $responseId, ?int &$timerId): callable
    {
        $subscriberId = 'http_' . bin2hex(random_bytes(12));
        $detached = false;
        // An unrecorded live subscriber would invalidate "all disconnected" evidence.
        // The controller reports storage failure before any poll/subscription is opened.
        TaskLedger::recordClientTransport($responseId, $subscriberId, true);
        $detach = static function (bool $terminal = false) use ($responseId, $subscriberId, &$detached): void {
            if ($detached) return;
            $detached = true;
            try { TaskLedger::recordClientTransport($responseId, $subscriberId, false, $terminal); } catch (\Throwable) {}
        };
        $previousClose = $connection->onClose;
        $connection->onClose = static function (TcpConnection $closed) use ($previousClose, $detach, &$timerId): void {
            $detach();
            if ($timerId !== null) Timer::del($timerId);
            if (is_callable($previousClose)) $previousClose($closed);
        };
        return $detach;
    }

    private static function finishJson(TcpConnection $connection, int $status, array $body): void
    {
        if (!self::open($connection)) return;
        $json = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $connection->close(new WorkermanResponse($status, self::headers('application/json; charset=utf-8'), $json));
    }

    public static function headers(string $contentType): array
    {
        return [
            'Content-Type' => $contentType,
            'Cache-Control' => 'no-store',
            'X-Accel-Buffering' => 'no',
            'X-Content-Type-Options' => 'nosniff',
            'Access-Control-Allow-Origin' => (string) Config::get('gateway', 'console_origin'),
            'Vary' => 'Origin',
        ];
    }

    private static function open(TcpConnection $connection): bool
    {
        $status = $connection->getStatus();
        return !in_array($status, [TcpConnection::STATUS_ENDING, TcpConnection::STATUS_CLOSING, TcpConnection::STATUS_CLOSED], true);
    }
}
