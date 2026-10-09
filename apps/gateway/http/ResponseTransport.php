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
     */
    public static function stream(TcpConnection $connection, string $responseId): void
    {
        $sequence = -1;
        $lastWrite = microtime(true);
        $timerId = null;
        $timerId = Timer::add(self::POLL_SECONDS, function () use ($connection, $responseId, &$sequence, &$lastWrite, &$timerId): void {
            if (!self::open($connection)) {
                if ($timerId !== null) Timer::del($timerId);
                return;
            }
            $queued = $connection->getSendBufferQueueSize();
            if ($queued >= self::ABANDON_BYTES) {
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
                        if ($timerId !== null) Timer::del($timerId);
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
                        if ($timerId !== null) Timer::del($timerId);
                        return;
                    }
                    $lastWrite = microtime(true);
                }
            } catch (\Throwable) {
                if ($timerId !== null) Timer::del($timerId);
                $connection->close();
            }
        }, null, true);
    }

    public static function nonStream(TcpConnection $connection, string $responseId): void
    {
        $timerId = null;
        $timerId = Timer::add(self::POLL_SECONDS, function () use ($connection, $responseId, &$timerId): void {
            if (!self::open($connection)) {
                if ($timerId !== null) Timer::del($timerId);
                return;
            }
            try {
                $job = ResponseJob::get($responseId);
                if (($job['status'] ?? '') === 'completed' && is_array($job['response'] ?? null)) {
                    if ($timerId !== null) Timer::del($timerId);
                    self::finishJson($connection, 200, $job['response']);
                } elseif (($job['status'] ?? '') === 'failed') {
                    if ($timerId !== null) Timer::del($timerId);
                    $error = is_array($job['error'] ?? null) ? $job['error'] : [
                        'error' => ['message' => 'ChatGPT 网页生成失败。', 'type' => 'server_error', 'param' => null, 'code' => 'response_failed'],
                    ];
                    $failure = ResponsesProtocol::clientFailure($error);
                    self::finishJson($connection, $failure['status'], $failure['body']);
                }
            } catch (\Throwable) {
                if ($timerId !== null) Timer::del($timerId);
                self::finishJson($connection, 503, [
                    'error' => ['message' => 'Responses 状态暂不可读取。', 'type' => 'server_error', 'param' => null, 'code' => 'storage_unavailable'],
                ]);
            }
        }, null, true);
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
