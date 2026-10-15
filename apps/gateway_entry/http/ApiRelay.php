<?php
declare(strict_types=1);
namespace app\gateway_entry\http;

use RC\Config;
use RC\Request;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Chunk;
use Workerman\Protocols\Http\Response;
use Workerman\Timer;
use app\gateway\http\DeferredResponse;
use app\gateway\http\ResponseTransport;
use app\gateway\model\ResponsesProtocol;

/** One-pass loopback HTTP relay; never parses responses, touches task state or retries requests. */
final class ApiRelay
{
    private const HOP_HEADERS = ['connection', 'keep-alive', 'proxy-authenticate', 'proxy-authorization',
        'te', 'trailer', 'transfer-encoding', 'upgrade', 'content-length'];

    /**
     * Forward to a fixed deployment-owned worker while preserving method, query and wire body.
     * @param Request $request Authorized framework request; not retained in callbacks.
     * @param string $target gateway_native or gateway, selected by GatewayRoute.
     * @return mixed Deferred response, or a setup error before any backend request.
     */
    public static function forward(Request $request, string $target)
    {
        $connection = $request->RCresponse?->getConnection();
        if (!$connection instanceof TcpConnection || !in_array($target, ['gateway_native', 'gateway'], true)) {
            return $request->json(ResponsesProtocol::error('API 转发暂不可用。', 'api_relay_unavailable', null, 'server_error'))->withStatus(503);
        }
        $port = (int) Config::get('gateway', $target === 'gateway_native' ? 'native_api_port' : 'web_api_port');
        $query = (string) $request->queryString();
        $url = 'http://127.0.0.1:' . $port . $request->path() . ($query === '' ? '' : '?' . $query);
        self::start($connection, $url, (string) $request->method(), (array) $request->header(),
            (string) $request->rawBody());
        self::deferConnectionClose($request);
        return new DeferredResponse($request);
    }

    /**
     * Pump a framework Curl handle with bounded downstream buffering and no total stream timeout.
     * Framework MultiCurl lacks a nonblocking tick, so only its scheduling is adapted, as in
     * NativeTransport. Responses are chunked through Workerman; SSE bytes are never interpreted.
     * @param TcpConnection $connection Downstream subscriber; its closure cancels only this hop.
     * @param string $url Fixed loopback backend URL, constructed above, never a client target.
     * @param string $method Original HTTP method.
     * @param array $headers Snapshot of original headers; credentials remain in memory only.
     * @param string $body Original wire bytes, including compression when present.
     * @return void
     */
    private static function start(TcpConnection $connection, string $url, string $method, array $headers, string $body): void
    {
        $client = curl();
        $client->setUrl($url);
        $client->setOpt(CURLOPT_PROTOCOLS, CURLPROTO_HTTP);
        $client->setOpt(CURLOPT_PROXY, '');
        $client->setOpt(CURLOPT_NOPROXY, '*');
        $client->setOpt(CURLOPT_FOLLOWLOCATION, false);
        $client->setOpt(CURLOPT_CONNECTTIMEOUT, 3);
        $client->setOpt(CURLOPT_TIMEOUT, 0);
        $client->setOpt(CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
        $client->setOpt(CURLOPT_HTTP_CONTENT_DECODING, false);
        $client->setOpt(CURLOPT_PATH_AS_IS, true);
        foreach (self::endToEndHeaders($headers) as $name => $value) {
            if (!in_array(strtolower($name), ['host', 'expect'], true)) $client->setHeader($name, $value);
        }
        // Framework deferred responses require keep-alive until the transport sends its body.
        $client->setHeader('Connection', 'keep-alive');
        $client->setHeader('Expect', '');
        if ($method === 'POST' || $body !== '') $client->setOpt(CURLOPT_POSTFIELDS, $body);
        $client->setOpt(CURLOPT_CUSTOMREQUEST, $method);
        $client->childOfMultiCurl = true;
        $status = 0;
        $responseHeaders = [];
        $started = false;
        $chunked = false;
        $paused = false;
        $client->setOpt(CURLOPT_HEADERFUNCTION, static function ($handle, string $line) use ($connection,
            &$status, &$responseHeaders, &$started, &$chunked): int {
            if (!self::open($connection)) return 0;
            if (preg_match('~^HTTP/\S+\s+(\d+)~', $line, $match)) {
                $status = (int) $match[1];
                $responseHeaders = [];
            } elseif (trim($line) === '' && $status >= 200 && !$started) {
                $out = self::endToEndHeaders($responseHeaders);
                $out['Connection'] = 'close';
                $chunked = !in_array($status, [204, 304], true);
                if ($chunked) $out['Transfer-Encoding'] = 'chunked';
                if ($connection->send(new Response($status, $out, '')) === false) return 0;
                $started = true;
            } elseif (!$started && str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $name = strtolower(trim($name));
                $value = trim($value);
                if (isset($responseHeaders[$name])) {
                    $responseHeaders[$name] = (array) $responseHeaders[$name];
                    $responseHeaders[$name][] = $value;
                } else $responseHeaders[$name] = $value;
            }
            return strlen($line);
        });
        $client->setOpt(CURLOPT_WRITEFUNCTION, static function ($handle, string $chunk) use ($connection, &$paused, &$chunked): int {
            if (!self::open($connection)) return 0;
            if ($connection->getSendBufferQueueSize() >= min(524288, intdiv($connection->maxSendBufferSize, 2))) {
                // Pause before consuming any bytes: libcurl delivers this same chunk on resume.
                $paused = true;
                return CURL_WRITEFUNC_PAUSE;
            }
            if (!$chunked) return 0;
            return $connection->send(new Chunk($chunk)) === false ? 0 : strlen($chunk);
        });
        $multi = curl_multi_init();
        if (curl_multi_add_handle($multi, $client->curl) !== CURLM_OK) {
            $client->close();
            curl_multi_close($multi);
            self::unavailable($connection);
            return;
        }
        $timer = null;
        $timer = Timer::add(0.01, static function () use ($client, $multi, $connection, &$timer,
            &$paused, &$started, &$chunked, &$status): void {
            $finished = !self::open($connection);
            $error = CURLE_OK;
            if (!$finished && $paused && $connection->getSendBufferQueueSize()
                <= min(131072, intdiv($connection->maxSendBufferSize, 4))) {
                // Resumption may synchronously invoke WRITEFUNCTION and pause again.
                $paused = false;
                $error = curl_pause($client->curl, CURLPAUSE_CONT);
                $finished = $error !== CURLE_OK || !self::open($connection);
            }
            if (!$finished) {
                $code = curl_multi_exec($multi, $active);
                if ($code !== CURLM_OK) { $finished = true; $error = $code; }
                while ($info = curl_multi_info_read($multi)) { $finished = true; $error = (int) $info['result']; }
            }
            if (!$finished) return;
            Timer::del($timer);
            curl_multi_remove_handle($multi, $client->curl);
            $client->close();
            curl_multi_close($multi);
            if (!self::open($connection)) return;
            if (!$started) { self::unavailable($connection); return; }
            // An interrupted body stays truncated. Never append a model terminal event or retry.
            if ($error === CURLE_OK && $chunked) $connection->close(new Chunk(''));
            else $connection->close();
        });
    }

    /**
     * Remove hop-by-hop framing and Connection-nominated headers, preserving end-to-end values.
     * Workerman needs canonical Content-Type casing to frame SSE correctly.
     * @param array $headers HTTP header snapshot, including repeated response header values.
     * @return array Sanitized headers without framing; body bytes are not decoded.
     */
    private static function endToEndHeaders(array $headers): array
    {
        $normalized = [];
        foreach ($headers as $name => $value) {
            if (is_string($name)) $normalized[strtolower($name)] = $value;
        }
        $connection = implode(',', (array) ($normalized['connection'] ?? []));
        $excluded = array_merge(self::HOP_HEADERS, array_map('trim', explode(',', strtolower($connection))));
        $out = [];
        foreach ($normalized as $name => $value) {
            if (in_array($name, $excluded, true) || preg_match('/\A[A-Za-z0-9!#$%&\'*+.^_`|~-]+\z/D', $name) !== 1) continue;
            $values = array_values(array_filter((array) $value, static fn($item) => is_string($item) && strpbrk($item, "\r\n\0") === false));
            if ($values === []) continue;
            $canonical = implode('-', array_map('ucfirst', explode('-', $name)));
            $out[$canonical] = count($values) === 1 ? $values[0] : $values;
        }
        return $out;
    }

    /** Send a transport error only before headers; failed/unknown requests are never replayed. */
    private static function unavailable(TcpConnection $connection): void
    {
        $connection->close(new Response(502, ResponseTransport::headers('application/json'),
            json_encode(ResponsesProtocol::error('API 工作进程连接中断或暂不可用。', 'api_worker_unavailable', null, 'server_error'), JSON_UNESCAPED_UNICODE)));
    }

    /**
     * Delay the framework's immediate close for HTTP/1.0 or Connection: close subscribers.
     * The relay has already snapshotted the original request and always closes after its reply.
     * Only the remaining synchronous response-dispatch view changes; no request is retained.
     * @param Request $request Current ingress request, after authorization and route selection.
     * @return void
     */
    private static function deferConnectionClose(Request $request): void
    {
        $tokens = array_map('trim', explode(',', strtolower((string) $request->header('connection', ''))));
        if (!in_array('close', $tokens, true) && (in_array('keep-alive', $tokens, true)
            || in_array(strtoupper((string) $request->protocol()), ['HTTP/1.1', '1.1'], true))) return;
        $native = $request->getRequest();
        $deferred = new class($native->rawBuffer()) extends \Workerman\Protocols\Http\Request {
            /**
             * Keep deferred dispatch open; the relay owns the final close.
             * @param ?string $name Requested header, or null for the unchanged full snapshot.
             * @param mixed $default Value returned for an absent header.
             * @return mixed Existing header value, except the dispatch-only connection override.
             */
            public function header(?string $name = null, mixed $default = null): mixed
            {
                return strtolower($name ?? '') === 'connection' ? 'keep-alive' : parent::header($name, $default);
            }
        };
        $deferred->connection = $native->connection;
        $request->set($deferred, $request->id(), $request->RCresponse);
    }

    /** Whether the downstream subscriber can still receive bytes. */
    private static function open(TcpConnection $connection): bool
    {
        return !in_array($connection->getStatus(), [TcpConnection::STATUS_ENDING, TcpConnection::STATUS_CLOSING, TcpConnection::STATUS_CLOSED], true);
    }
}
