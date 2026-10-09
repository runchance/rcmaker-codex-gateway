<?php
declare(strict_types=1);
namespace support\browser;

use Workerman\Connection\AsyncTcpConnection;
use Workerman\Timer;

/** Minimal asynchronous Chrome DevTools Protocol client on the existing Workerman event loop. */
final class CdpClient
{
    private ?AsyncTcpConnection $connection = null;
    private int $nextId = 1;
    /** @var array<int,array{callback:callable,timer:int}> */
    private array $pending = [];
    private bool $ready = false;
    private string $closeDetail = '';
    private $eventHandler = null;
    private $closeHandler = null;

    /** Connect to a loopback browser WebSocket discovered from /json/version. */
    public function connect(string $webSocketUrl, callable $ready, callable $event, callable $closed): void
    {
        $parts = parse_url($webSocketUrl);
        if (!$parts || ($parts['scheme'] ?? '') !== 'ws' || !in_array(strtolower((string) ($parts['host'] ?? '')), ['127.0.0.1', 'localhost', '::1'], true)) {
            throw new \InvalidArgumentException('CDP websocket must be loopback ws://');
        }
        $this->close('BROWSER_CDP_REPLACED');
        $this->eventHandler = $event;
        $this->closeHandler = $closed;
        $this->closeDetail = '';
        $connection = new AsyncTcpConnection($webSocketUrl);
        // Chromium enforces client masking; use the project-local RFC-compliant encoder without modifying Workerman.
        $connection->protocol = CdpWs::class;
        $connection->maxPackageSize = 16 * 1024 * 1024;
        $connection->onWebSocketConnect = function () use ($connection, $ready): void {
            if ($this->connection !== $connection) return;
            $this->ready = true;
            $ready($this);
        };
        $connection->onMessage = function ($connection, $raw): void {
            if ($this->connection !== $connection) return;
            if (!is_string($raw) || strlen($raw) > 16 * 1024 * 1024) {
                $connection->close();
                return;
            }
            try {
                $message = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
            } catch (\Throwable) {
                return;
            }
            if (!is_array($message)) return;
            if (isset($message['id'])) {
                $id = (int) $message['id'];
                $pending = $this->pending[$id] ?? null;
                if ($pending === null) return;
                unset($this->pending[$id]);
                Timer::del($pending['timer']);
                ($pending['callback'])($message['error'] ?? null, $message['result'] ?? []);
                return;
            }
            if (isset($message['method']) && is_callable($this->eventHandler)) {
                ($this->eventHandler)($message);
            }
        };
        $connection->onError = function ($connection, $code, $message): void {
            if ($this->connection !== $connection) return;
            $this->closeDetail = 'transport ' . (int) $code . ': ' . mb_substr((string) $message, 0, 200);
            $this->failPending('BROWSER_CDP_ERROR', $this->closeDetail);
        };
        $connection->onWebSocketClose = function ($connection, $payload): void {
            if ($this->connection !== $connection) {
                $connection->close();
                return;
            }
            $payload = is_string($payload) ? $payload : '';
            $code = strlen($payload) >= 2 ? unpack('ncode', substr($payload, 0, 2))['code'] : 1005;
            $reason = strlen($payload) > 2 ? mb_substr(substr($payload, 2), 0, 200) : '';
            $this->closeDetail = 'websocket ' . $code . ($reason !== '' ? ': ' . $reason : '');
            $connection->close();
        };
        $connection->onClose = function () use ($connection): void {
            if ($this->connection !== $connection) return;
            $wasReady = $this->ready;
            $this->ready = false;
            $this->connection = null;
            $detail = $this->closeDetail !== '' ? $this->closeDetail : '浏览器控制连接已断开。';
            $this->failPending('BROWSER_CDP_DISCONNECTED', $detail);
            if (is_callable($this->closeHandler)) {
                ($this->closeHandler)($wasReady);
            }
        };
        $this->connection = $connection;
        $connection->connect();
    }

    /** Send one bounded CDP request. Callback receives nullable protocol error and result. @return int */
    public function send(string $method, array $params, ?string $sessionId, callable $callback, float $timeout = 5.0): int
    {
        if (!$this->ready || $this->connection === null) {
            $callback(['code' => -1, 'message' => 'BROWSER_CDP_DISCONNECTED'], []);
            return 0;
        }
        $id = $this->nextId++;
        $message = ['id' => $id, 'method' => $method];
        // CDP expects params to be an object. Omitting empty params avoids encoding PHP [] as JSON [].
        if ($params !== []) $message['params'] = $params;
        if ($sessionId !== null && $sessionId !== '') $message['sessionId'] = $sessionId;
        $timer = Timer::add($timeout, function () use ($id): void {
            $pending = $this->pending[$id] ?? null;
            if ($pending === null) return;
            unset($this->pending[$id]);
            ($pending['callback'])(['code' => -2, 'message' => 'BROWSER_CDP_TIMEOUT'], []);
        }, null, false);
        $this->pending[$id] = ['callback' => $callback, 'timer' => $timer];
        $encoded = json_encode($message, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if ($this->connection->send($encoded) === false) {
            Timer::del($timer);
            unset($this->pending[$id]);
            $callback(['code' => -3, 'message' => 'BROWSER_CDP_SEND_FAILED'], []);
            return 0;
        }
        return $id;
    }

    /** Whether the WebSocket handshake is complete. */
    public function ready(): bool
    {
        return $this->ready;
    }

    /** Close only the CDP transport; browser process ownership is managed separately. */
    public function close(string $code = 'BROWSER_CDP_CLOSED'): void
    {
        $this->ready = false;
        $this->failPending($code, '浏览器控制连接已关闭。');
        if ($this->connection !== null) {
            $connection = $this->connection;
            $this->connection = null;
            $connection->close();
        }
    }

    private function failPending(string $code, string $message): void
    {
        $pending = $this->pending;
        $this->pending = [];
        foreach ($pending as $entry) {
            Timer::del($entry['timer']);
            ($entry['callback'])(['code' => -1, 'message' => $code, 'detail' => $message], []);
        }
    }
}
