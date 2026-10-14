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
     * @return void
     * @throws \Throwable If the durable subscriber cannot be recorded before opening the stream.
     */
    public static function stream(TcpConnection $connection, string $responseId): void
    {
        $sequence = -1;
        $lastWrite = microtime(true);
        $timerId = null;
        $detach = self::observeClientTransport($connection, $responseId, $timerId);
        $timerId = Timer::add(self::POLL_SECONDS, function () use ($connection, $responseId, $detach, &$sequence, &$lastWrite, &$timerId): void {
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
                    $payload = json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                    $frame = new ServerSentEvents(['event' => $event['type'], 'data' => $payload]);
                    if ($connection->send((string) $frame, true) === false) {
                        $detach();
                        if ($timerId !== null) Timer::del($timerId);
                        $connection->close();
                        return;
                    }
                    $sequence = $number;
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
     * @return void
     * @throws \Throwable If the durable subscriber cannot be recorded before polling begins.
     */
    public static function nonStream(TcpConnection $connection, string $responseId): void
    {
        $timerId = null;
        $detach = self::observeClientTransport($connection, $responseId, $timerId);
        $timerId = Timer::add(self::POLL_SECONDS, function () use ($connection, $responseId, $detach, &$timerId): void {
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
                    self::finishJson($connection, 200, $job['response']);
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
