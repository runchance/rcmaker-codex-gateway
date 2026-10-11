<?php
declare(strict_types=1);
namespace app\gateway\controller;

use RC\Request;
use Workerman\Connection\TcpConnection;
use app\gateway\database\Schema;
use app\gateway\http\DeferredResponse;
use app\gateway\http\ResponseTransport;
use app\gateway\model\BrowserState;
use app\gateway\model\GatewayError;
use app\gateway\model\ResponseJob;
use app\gateway\model\ResponsesProtocol;
use app\gateway\model\ModelCatalog;
use app\gateway\model\CodexIntegration;
use app\gateway\model\ContextCompaction;
use app\gateway\model\ContextPortability;
use app\gateway\model\GatewaySettings;
use app\gateway\http\NativeTransport;

/** Loopback Responses-compatible text entry points backed by the owned ChatGPT browser. */
final class Responses
{
    public function models(Request $request)
    {
        try {
            Schema::initialize();
            if ((string) $request->header('authorization', '') !== '') return $this->forward($request, 'models');
            $browser = BrowserState::state();
            $page = ResponseJob::pageSnapshot($browser);
            if ($browser['status'] !== 'ready' || (int) $browser['worker_heartbeat'] < time() - 5 || !$page['fresh']) {
                throw new GatewayError('browser_not_ready', '受控 ChatGPT 浏览器尚未就绪。', 503);
            }
            if ($page['login_required']) {
                throw new GatewayError('authentication_required', 'ChatGPT 网页需要登录。', 401);
            }
            if (!ResponseJob::accountReady($browser, $page)) {
                throw new GatewayError('chatgpt_surface_not_ready', 'ChatGPT 编辑器尚未就绪。', 503);
            }
            return $request->json([
                'object' => 'list',
                'data' => array_merge([[
                    'id' => ResponsesProtocol::MODEL_ID,
                    'object' => 'model',
                    'created' => 0,
                    'owned_by' => 'chatgpt-web',
                    'metadata' => ['model_label' => $page['model_label']],
                ]], array_map(static fn(array $route): array => ['id' => $route['id'], 'object' => 'model',
                    'created' => 0, 'owned_by' => 'chatgpt-web', 'metadata' => ['model_label' => $route['label']]], ModelCatalog::routes())),
            ]);
        } catch (GatewayError $error) {
            return $this->error($request, $error);
        } catch (\Throwable) {
            return $request->json(ResponsesProtocol::error('模型目录暂不可用。', 'service_unavailable', null, 'server_error'))
                ->withStatus(503);
        }
    }

    public function create(Request $request)
    {
        $phase = 'schema';
        try {
            Schema::initialize();
            $phase = 'decode';
            // Routing must inspect decoded JSON. A gzip native request otherwise falls
            // through to the Web parser and is rejected as an unsupported Web model.
            $raw = $this->decodedBody($request);
            $phase = 'route';
            $body = json_decode($raw, true);
            if (is_array($body) && is_string($body['model'] ?? null) && !str_starts_with($body['model'], 'chatgpt-web/')) {
                return $this->forward($request, 'responses', $raw);
            }
            if (BrowserState::profileResetPending()) {
                throw new GatewayError('browser_profile_reset_pending', '浏览器登录资料正在清空，请稍后重试。', 503);
            }
            $phase = 'metadata';
            $raw = $this->withMetadata($request, $raw);
            $phase = 'composer_suggestion';
            $suggestion = ResponsesProtocol::localComposerSuggestion($raw);
            if ($suggestion !== null) {
                if (!$suggestion['stream']) {
                    return $request->json($suggestion['response']);
                }
                $frames = '';
                foreach ($suggestion['events'] as $event) {
                    $frames .= 'event: ' . $event['type'] . "\ndata: "
                        . json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
                        . "\n\n";
                }
                return $request->response($frames, 200, [
                    'Content-Type' => 'text/event-stream', 'Cache-Control' => 'no-cache',
                ]);
            }
            $phase = 'portability';
            if ((int) GatewaySettings::internal()['official_handoff_summary'] === 1) {
                $plan = ContextPortability::prepareNativeHandoff($raw, $this->nativeHeaders($request));
                if (is_array($plan) && isset($plan['request'])) return $this->handoff($request, $plan, false);
                $raw = $plan['raw'] ?? $raw;
            } else $raw = ContextPortability::prepareWeb($raw);
            $phase = 'parse';
            $spec = ResponsesProtocol::parse($raw);
            $spec['integration_id'] = $this->integrationId($request);
            $idempotencyKey = $this->idempotencyKey($request);
            $connection = $request->RCresponse?->getConnection();
            if (!$connection instanceof TcpConnection) {
                throw new GatewayError('transport_unavailable', '当前运行时不支持异步 Responses 连接。', 503);
            }
            $phase = 'reserve';
            $job = ($spec['operation_kind'] ?? 'response') === 'compact_v2'
                ? ContextCompaction::create($spec, $idempotencyKey) : ResponseJob::createOrReuse($spec, $idempotencyKey);
            $responseId = (string) $job['id'];
            $phase = 'response_transport';
            if ($spec['stream']) {
                ResponseTransport::stream($connection, $responseId);
                return $request->response('', 200, ['Content-Type' => 'text/event-stream']);
            }
            ResponseTransport::nonStream($connection, $responseId);
            return new DeferredResponse($request);
        } catch (GatewayError $error) {
            // Codex replaces these details with its generic context-window message.
            // Admission failures precede job creation, so keep structural evidence
            // in the existing diagnostic sink without logging any request content.
            if (in_array($error->errorCode, ['context_length_exceeded', 'CHATGPT_CONTEXT_TOO_LARGE', 'CHATGPT_MESSAGE_TOO_LARGE'], true)) {
                $this->logUnexpectedCreateFailure($error, $phase . ':' . $error->errorCode);
            }
            return $this->error($request, $error);
        } catch (\Throwable $error) {
            $diagnosticId = $this->logUnexpectedCreateFailure($error, $phase);
            return $request->json(ResponsesProtocol::error('Responses 服务暂不可用。诊断号：' . $diagnosticId,
                'service_unavailable', null, 'server_error'))
                ->withStatus(503);
        }
    }

    /** Record only structural failure evidence; exception messages and traces can contain request secrets. */
    private function logUnexpectedCreateFailure(\Throwable $error, string $phase): string
    {
        try {
            $diagnosticId = bin2hex(random_bytes(8));
        } catch (\Throwable) {
            $diagnosticId = substr(hash('sha256', microtime(true) . ':' . getmypid()), 0, 16);
        }
        $class = preg_replace('/[^A-Za-z0-9_\\\\]/', '_', get_class($error));
        $file = preg_replace('/[^A-Za-z0-9_.-]/', '_', basename(str_replace('\\', '/', $error->getFile())));
        $line = date('c') . ' [responses.create] id=' . $diagnosticId . ' phase=' . $phase
            . ' class=' . $class . ' file=' . $file . ' line=' . $error->getLine() . PHP_EOL;
        try {
            $directory = runtime_path() . '/logs';
            if ((is_dir($directory) || @mkdir($directory, 0700, true))
                && @file_put_contents($directory . '/RC_GATEWAY_API.log', $line, FILE_APPEND | LOCK_EX) !== false) {
                return $diagnosticId;
            }
        } catch (\Throwable) {
            // Preserve the original HTTP failure even when the diagnostic sink is unavailable.
        }
        error_log($line);
        return $diagnosticId;
    }

    /** Explicit HTTP fallback for Codex clients that probe Responses WebSocket transport. */
    public function websocket(Request $request)
    {
        return $request->json(ResponsesProtocol::error('此网关使用 HTTP/SSE，请通过 POST /responses 连接。', 'websocket_not_supported'))->withStatus(426);
    }

    /** Native unary compaction contract; the summary is produced by the existing task browser. */
    public function compact(Request $request)
    {
        try {
            Schema::initialize();
            $raw = $this->decodedBody($request);
            $body = json_decode($raw, true, 128, JSON_THROW_ON_ERROR);
            if (!str_starts_with((string) ($body['model'] ?? ''), 'chatgpt-web/')) return $this->forward($request, 'responses/compact', $raw);
            if (BrowserState::profileResetPending()) {
                throw new GatewayError('browser_profile_reset_pending', '浏览器登录资料正在清空，请稍后重试。', 503);
            }
            $raw = $this->withMetadata($request, $raw);
            if ((int) GatewaySettings::internal()['official_handoff_summary'] === 1) {
                $plan = ContextPortability::prepareNativeHandoff($raw, $this->nativeHeaders($request));
                if (is_array($plan) && isset($plan['request'])) return $this->handoff($request, $plan, true);
                $raw = $plan['raw'] ?? $raw;
            } else $raw = ContextPortability::prepareWeb($raw);
            $spec = ResponsesProtocol::parse($raw);
            $spec['operation_kind'] = 'compact_v1';
            $spec['integration_id'] = $this->integrationId($request);
            $connection = $request->RCresponse?->getConnection();
            if (!$connection instanceof TcpConnection) throw new GatewayError('transport_unavailable', '压缩需要异步连接。', 503);
            $job = ContextCompaction::create($spec, $this->idempotencyKey($request));
            ResponseTransport::nonStream($connection, (string) $job['id']);
            return new DeferredResponse($request);
        } catch (GatewayError $error) { return $this->error($request, $error); }
        catch (\Throwable) { return $request->json(ResponsesProtocol::error('上下文压缩请求无效。', 'invalid_compaction'))->withStatus(400); }
    }

    /** Forward the fixed ancillary endpoints used by the native Codex client. */
    public function native(Request $request)
    {
        try {
            Schema::initialize();
            $path = preg_replace('~^/v1/(?:rcm_[a-f0-9]{24}/)?~', '', (string) $request->path());
            return $this->forward($request, $path);
        } catch (GatewayError $error) { return $this->error($request, $error); }
    }

    /** Snapshot only endpoint-safe headers; never retain the Request in asynchronous callbacks. */
    private function forward(Request $request, string $endpoint, ?string $decodedBody = null)
    {
        $connection = $request->RCresponse?->getConnection();
        if (!$connection instanceof TcpConnection) throw new GatewayError('transport_unavailable', '原生转发需要异步连接。', 503);
        $headers = $this->nativeHeaders($request);
        $wireBody = (string) $request->rawBody();
        if ($decodedBody !== null) {
            $normalized = ContextPortability::forNative($decodedBody);
            if ($normalized !== $decodedBody) {
                $wireBody = $normalized;
                unset($headers['content-encoding']);
            }
        }
        $integration = $this->integrationId($request);
        NativeTransport::start($connection, $endpoint, (string) $request->method(), (string) $request->queryString(), $headers,
            $wireBody, static function (array $catalog) use ($integration): void {
                SDB(null, 'medoo', 'sqlite')->table('gateway_model_catalog')->where('id', 1)->update([
                    'native_catalog_json' => json_encode($catalog, JSON_THROW_ON_ERROR), 'native_verified_at' => time(),
                ]);
                CodexIntegration::clientSeen($integration, 'catalog');
            });
        return new DeferredResponse($request);
    }

    /** Copy explicit headers before asynchronous work; never close over the reusable Request. */
    private function nativeHeaders(Request $request): array
    {
        $headers = [];
        foreach (['authorization', 'chatgpt-account-id', 'openai-beta', 'originator', 'user-agent', 'accept', 'content-type', 'content-encoding',
            'x-codex-turn-metadata', 'x-codex-beta-features', 'x-codex-session-id', 'session_id', 'conversation_id', 'x-request-id', 'idempotency-key'] as $name) {
            $value = (string) $request->header($name, '');
            if ($value !== '') $headers[$name] = $value;
        }
        return $headers;
    }

    /** Only the explicit setting can schedule this extra official-model summary call. */
    private function handoff(Request $request, array $plan, bool $compact): DeferredResponse
    {
        $connection = $request->RCresponse?->getConnection();
        if (!$connection instanceof TcpConnection) throw new GatewayError('transport_unavailable', '模型切换需要异步连接。', 503);
        $headers = $this->nativeHeaders($request);
        unset($headers['content-encoding'], $headers['idempotency-key'], $headers['x-request-id']);
        $headers['content-type'] = 'application/json';
        $integration = $this->integrationId($request);
        $idempotencyKey = $this->idempotencyKey($request);
        NativeTransport::start($connection, 'responses', 'POST', '', $headers, $plan['request'], null,
            static function (int $status, array $responseHeaders, string $body) use ($connection, $plan, $compact, $integration, $idempotencyKey): void {
                $response = ContextPortability::nativeResponse($status, $responseHeaders['content-type'] ?? '', $body);
                $raw = ContextPortability::completeNativeHandoff($plan, $response);
                $spec = ResponsesProtocol::parse($raw);
                $spec['integration_id'] = $integration;
                if ($compact) $spec['operation_kind'] = 'compact_v1';
                $job = in_array($spec['operation_kind'] ?? '', ['compact_v1', 'compact_v2'], true)
                    ? ContextCompaction::create($spec, $idempotencyKey) : ResponseJob::createOrReuse($spec, $idempotencyKey);
                if (!$compact && $spec['stream']) {
                    $connection->send(new \Workerman\Protocols\Http\Response(200, ResponseTransport::headers('text/event-stream'), ''));
                    ResponseTransport::stream($connection, (string) $job['id']);
                } else ResponseTransport::nonStream($connection, (string) $job['id']);
            });
        return new DeferredResponse($request);
    }

    /** Decode a bounded request for route selection without altering the native wire payload. */
    private function decodedBody(Request $request): string
    {
        $raw = (string) $request->rawBody();
        if (strlen($raw) > ResponsesProtocol::MAX_BODY_BYTES) {
            throw new GatewayError('request_body_too_large', 'HTTP 请求正文超过网关字节上限。', 413);
        }
        $encoding = strtolower(trim((string) $request->header('content-encoding', '')));
        if ($encoding !== '' && $encoding !== 'identity') {
            if ($encoding === 'gzip') $decoded = @gzdecode($raw, ResponsesProtocol::MAX_BODY_BYTES + 1);
            elseif ($encoding === 'zstd' && function_exists('zstd_uncompress')) $decoded = zstd_uncompress($raw);
            else throw new GatewayError('unsupported_content_encoding', '当前 PHP 运行时不支持 ' . $encoding . ' 请求压缩，请通过接入配置关闭客户端请求压缩。', 415);
            if (!is_string($decoded)) throw new GatewayError('invalid_compressed_body', '压缩请求损坏。', 400);
            if (strlen($decoded) > ResponsesProtocol::MAX_BODY_BYTES) {
                throw new GatewayError('request_body_too_large', '解压后的 HTTP 请求正文超过网关字节上限。', 413);
            }
            $raw = $decoded;
        }
        return $raw;
    }

    /** Preserve the real native client's HTTP metadata when it is not repeated in the JSON body. */
    private function withMetadata(Request $request, string $raw): string
    {
        $metadata = (string) $request->header('x-codex-turn-metadata', '');
        if ($metadata === '') return $raw;
        $body = json_decode($raw, false, 128, JSON_THROW_ON_ERROR);
        if (!$body instanceof \stdClass) return $raw;
        $body->client_metadata ??= new \stdClass();
        if (!$body->client_metadata instanceof \stdClass) return $raw;
        $body->client_metadata->{'x-codex-turn-metadata'} ??= $metadata;
        return json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /** Identify the exact installed configuration from its gateway-owned path prefix. */
    private function integrationId(Request $request): string
    {
        return preg_match('~^/v1/(rcm_[a-f0-9]{24})/~', (string) $request->path(), $match) ? $match[1] : '';
    }

    private function idempotencyKey(Request $request): ?string
    {
        $value = trim((string) $request->header('idempotency-key', ''));
        if ($value === '') return null;
        try {
            $value = (string) validator()->check($value, [
                'rule' => 'alnum',
                'options' => ['attach' => ['.', '_', ':', '-']],
                'len' => [8, 128],
                'name' => 'Idempotency-Key',
            ]);
        } catch (\Throwable) {
            throw new GatewayError('invalid_idempotency_key', 'Idempotency-Key 必须为 8—128 位 ASCII 标识。', 400);
        }
        return $value;
    }

    private function error(Request $request, GatewayError $error)
    {
        $type = $error->httpStatus >= 500 ? 'server_error' : 'invalid_request_error';
        if ($error->httpStatus === 401) $type = 'authentication_error';
        $failure = ResponsesProtocol::clientFailure(
            ResponsesProtocol::error($error->getMessage(), $error->errorCode, null, $type), $error->httpStatus
        );
        return $request->json($failure['body'])->withStatus($failure['status']);
    }
}
