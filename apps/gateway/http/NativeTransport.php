<?php
declare(strict_types=1);
namespace app\gateway\http;

use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Response;
use Workerman\Timer;
use app\gateway\model\GatewayError;
use app\gateway\model\ModelCatalog;
use app\gateway\model\NetworkSettings;
use app\gateway\model\NetworkTransport;

/** Timer adapter for framework Curl: authenticated native HTTP/SSE without blocking the API worker. */
final class NativeTransport
{
    private const ENDPOINTS = ['models', 'responses', 'responses/compact', 'alpha/search', 'images/generations', 'images/edits'];
    private const MAX_SSE_LINE_BYTES = 8388608;

    /**
     * Forward only fixed Codex endpoints. Credentials stay in this request and never enter SQLite/logs.
     * @param array<string,string> $headers Explicit end-to-end incoming headers.
     * @param callable|null $onCatalog Called after a genuine native catalog is successfully augmented.
     * @param callable|null $onResponse Internal read-only handoff receiver (status, headers, body).
     * When set, upstream bytes stay private and this callback owns the downstream response.
     * It must reject incomplete output: a known HTTP response is delivered even when the
     * upstream resets after its terminal SSE event, which is a normal native backend close.
     * @throws GatewayError Before any response bytes if transport setup is invalid.
     */
    public static function start(TcpConnection $connection, string $endpoint, string $method, string $query,
        array $headers, string $body, ?callable $onCatalog = null, ?callable $onResponse = null): void
    {
        if (!in_array($endpoint, self::ENDPOINTS, true)) throw new GatewayError('native_route_unsupported', '原生路径不受支持。', 404);
        if (trim((string) ($headers['authorization'] ?? '')) === '') throw new GatewayError('native_auth_required', '此原生路径需要 Codex 自身登录凭据。', 401);
        parse_str($query, $params);
        if ($endpoint === 'models' && empty($params['client_version'])
            && preg_match('~^(?:codex_cli_rs|codex-tui|codex_vscode|codex_atlas|codex_chatgpt_desktop|Codex [A-Za-z0-9._ -]+)/([0-9]+\.[0-9]+\.[0-9]+)~', $headers['user-agent'] ?? '', $match)) {
            $params['client_version'] = $match[1];
        }
        $url = 'https://chatgpt.com/backend-api/codex/' . $endpoint . ($params ? '?' . http_build_query($params) : '');
        $client = curl();
        $state = NetworkSettings::state();
        $revision = NetworkSettings::revision((int) $state['saved_revision']);
        NetworkTransport::configure($client, $revision, $url, $onResponse !== null ? 180 : ($endpoint === 'models' ? 30 : 900));
        foreach ($headers as $name => $value) {
            if (in_array(strtolower($name), ['authorization', 'chatgpt-account-id', 'openai-beta', 'originator',
                'user-agent', 'accept', 'content-type', 'content-encoding', 'x-codex-turn-metadata', 'x-codex-beta-features',
                'x-codex-session-id', 'session_id', 'conversation_id', 'x-request-id', 'idempotency-key'], true)) {
                $client->setHeader($name, $value);
            }
        }
        $client->setHeader('Accept-Encoding', 'identity');
        $client->setOpt(CURLOPT_ENCODING, '');
        $client->setUrl($url);
        $client->setOpt(CURLOPT_CUSTOMREQUEST, $method);
        if ($method === 'POST') $client->setOpt(CURLOPT_POSTFIELDS, $body);
        $client->childOfMultiCurl = true;
        $started = false; $stream = false; $buffer = ''; $lineBuffer = ''; $terminated = false; $bytes = 0;
        $responseHeaders = []; $status = 0;
        $client->setOpt(CURLOPT_HEADERFUNCTION, static function ($handle, string $line) use (&$status, &$responseHeaders): int {
            if (preg_match('~^HTTP/\S+\s+(\d+)~', $line, $match)) { $status = (int) $match[1]; $responseHeaders = []; }
            elseif (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2); $name = strtolower(trim($name));
                if (in_array($name, ['content-type', 'retry-after', 'x-request-id'], true)) $responseHeaders[$name] = trim($value);
            }
            return strlen($line);
        });
        $client->setOpt(CURLOPT_WRITEFUNCTION, static function ($handle, string $chunk) use ($connection, $endpoint, $onResponse,
            &$status, &$responseHeaders, &$started, &$stream, &$buffer, &$lineBuffer, &$terminated, &$bytes): int {
            $size = strlen($chunk); $bytes += $size;
            // The aggregate limit bounds bodies retained for JSON/catalog/handoff processing.
            // Once SSE is forwarding, only its pending line and downstream queue consume memory;
            // applying the body limit there cuts a valid long stream before its terminal frame.
            if ((!$stream && $bytes > 33554432) || !self::open($connection) || $connection->getSendBufferQueueSize() > 1048576) return 0;
            if ($onResponse !== null) { $buffer .= $chunk; return $size; }
            if (!$started) {
                $buffer .= $chunk;
                // Native compaction can return valid SSE without any Content-Type header.
                // Wait only for its first complete Responses frame, then flush immediately.
                $stream = $endpoint !== 'models' && $status === 200
                    && self::isEventStream($responseHeaders['content-type'] ?? '', substr($buffer, 0, self::MAX_SSE_LINE_BYTES));
                if (!$stream) return $size;
                $connection->send(new Response($status, self::headers($responseHeaders, 'text/event-stream'), ''));
                $started = true;
                $chunk = $buffer;
                $buffer = '';
            }
            $lineBuffer .= $chunk;
            while (($position = strpos($lineBuffer, "\n")) !== false) {
                $line = rtrim(substr($lineBuffer, 0, $position), "\r");
                $lineBuffer = substr($lineBuffer, $position + 1);
                if ($line === 'data: [DONE]') $terminated = true;
            }
            if (strlen($lineBuffer) > self::MAX_SSE_LINE_BYTES) return 0;
            return $connection->send($chunk, true) === false ? 0 : $size;
        });
        // Framework MultiCurl has no nonblocking tick. Only handle scheduling is adapted here;
        // framework Curl owns HTTP configuration and decoding. No curl_init / alternative client.
        $multi = curl_multi_init();
        if (curl_multi_add_handle($multi, $client->curl) !== CURLM_OK) {
            $client->close(); curl_multi_close($multi);
            throw new GatewayError('native_transport_unavailable', '原生连接无法初始化。', 503);
        }
        $timer = null;
        $timer = Timer::add(0.02, static function () use ($client, $multi, $connection, $endpoint, $onCatalog, $onResponse,
            &$timer, &$started, &$stream, &$buffer, &$status, &$responseHeaders, &$terminated, &$lineBuffer): void {
            $finished = !self::open($connection); $error = 0;
            if (!$finished) {
                $code = curl_multi_exec($multi, $active);
                if ($code !== CURLM_OK) { $finished = true; $error = $code; }
                while ($info = curl_multi_info_read($multi)) { $finished = true; $error = (int) $info['result']; }
            }
            if (!$finished) return;
            Timer::del($timer);
            curl_multi_remove_handle($multi, $client->curl);
            $client->close(); curl_multi_close($multi);
            if (!self::open($connection)) return;
            if ($stream && $started) {
                // A real terminator remains complete after an unclean close. Never invent one.
                $terminated = $terminated || rtrim($lineBuffer, "\r") === 'data: [DONE]';
                $connection->close(); return;
            }
            try {
                // Only the receiver can determine whether a complete terminal response
                // arrived before an unclean close. Never accept deltas as a checkpoint.
                if ($onResponse !== null && $status !== 0) {
                    $onResponse($status, $responseHeaders, $buffer);
                    return;
                }
                if ($error !== 0 || $status === 0) throw new GatewayError('native_transport_failed', 'Codex 原生连接失败，请检查已配置的代理。', 502);
                if ($endpoint === 'models' && $status === 200) {
                    $catalog = json_decode($buffer, true, 128, JSON_THROW_ON_ERROR);
                    if (!is_array($catalog)) throw new GatewayError('native_catalog_invalid', '原生目录不是 JSON 对象。', 502);
                    $augmented = ModelCatalog::augment($catalog);
                    if ($onCatalog !== null) $onCatalog($catalog);
                    $buffer = json_encode($augmented, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                }
                $connection->close(new Response($status, self::headers($responseHeaders, $responseHeaders['content-type'] ?? 'application/json'), $buffer));
            } catch (\Throwable $failure) {
                $connection->close(new Response($failure instanceof GatewayError ? $failure->httpStatus : 502,
                    ResponseTransport::headers('application/json'), json_encode(['error' => ['type' => 'server_error',
                        'code' => $failure instanceof GatewayError ? $failure->errorCode
                            : ($endpoint === 'models' ? 'native_catalog_invalid' : 'native_response_invalid'),
                        'message' => $failure instanceof GatewayError ? $failure->getMessage()
                            : ($endpoint === 'models' ? 'Codex 原生目录无法解析。' : 'Codex 原生响应无法解析。')]])));
            }
        });
    }

    /**
     * Identify native Responses SSE, including the observed missing-MIME compaction response.
     * A complete first data frame must contain a Responses event; JSON strings mentioning
     * SSE, incomplete data lines and unrelated event streams cannot change HTTP framing.
     * @param string $contentType Observed upstream MIME type, possibly absent.
     * @param string $prefix Bounded beginning of the response body.
     * @return bool Whether the response has explicit or structurally verified SSE framing.
     */
    public static function isEventStream(string $contentType, string $prefix): bool
    {
        if (str_contains(strtolower($contentType), 'text/event-stream')) return true;
        $frames = preg_split('/\r?\n\r?\n/', substr($prefix, 0, self::MAX_SSE_LINE_BYTES));
        array_pop($frames); // The trailing partial frame has no dispatch boundary yet.
        foreach ($frames as $frame) {
            $data = [];
            foreach (preg_split('/\r?\n/', $frame) as $line) {
                if ($line === '' || str_starts_with($line, ':')) continue;
                if (str_starts_with($line, 'data:')) $data[] = ltrim(substr($line, 5), ' ');
                elseif (!preg_match('/\A(?:event|id|retry):/', $line)) return false;
            }
            if ($data === []) continue;
            $event = json_decode(implode("\n", $data), true);
            return is_array($event) && is_string($event['type'] ?? null) && str_starts_with($event['type'], 'response.');
        }
        return false;
    }

    /** Emit one canonical Content-Type so Workerman and HTTP clients agree on streaming. */
    private static function headers(array $upstream, string $contentType): array
    {
        unset($upstream['content-type']);
        return array_merge(ResponseTransport::headers($contentType), $upstream);
    }

    /** Whether this subscriber is still writable; a closed subscriber never cancels Web jobs. */
    private static function open(TcpConnection $connection): bool
    {
        return !in_array($connection->getStatus(), [TcpConnection::STATUS_ENDING, TcpConnection::STATUS_CLOSING, TcpConnection::STATUS_CLOSED], true);
    }
}
