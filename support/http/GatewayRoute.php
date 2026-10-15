<?php
declare(strict_types=1);
namespace support\http;

use RC\Request;
use app\gateway\controller\Responses;
use app\gateway\model\GatewayError;
use app\gateway\model\ResponsesProtocol;
use app\gateway_entry\http\ApiRelay;

/** Shared route ownership for the ingress and two API applications; no task or database work. */
final class GatewayRoute
{
    /**
     * Bind an existing controller action to the framework router in all three applications.
     * @param class-string $controller Existing gateway controller from config/route.php.
     * @param string $action Existing action; route arguments retain their original order.
     * @return \Closure Request-scoped handler, after the selected APP's middleware.
     */
    public static function handler(string $controller, string $action): \Closure
    {
        return static function (Request $request, ...$arguments) use ($controller, $action) {
            $application = $request->app['app'] ?? '';
            if (!in_array($application, ['gateway_entry', 'gateway_native', 'gateway'], true)) {
                return $request->response('404 Not Found', 404);
            }
            $target = self::target($request, $controller, $action);
            if ($application === 'gateway_entry') return ApiRelay::forward($request, $target);
            // A request on the wrong internal port must not execute work in the other pool.
            if ($application !== $target) return $request->response('404 Not Found', 404);
            return (new $controller())->{$action}($request, ...$arguments);
        };
    }

    /**
     * Select the existing Responses branch, without normalizing or storing its history.
     * Invalid bodies go to the Web controller so its established validation/error contract runs.
     * @param Request $request Current request; compressed wire bytes remain unchanged.
     * @param string $controller Registered controller class.
     * @param string $action Registered controller action.
     * @return string Framework application owning this operation.
     */
    public static function target(Request $request, string $controller, string $action): string
    {
        if ($controller !== Responses::class) return 'gateway';
        if ($action === 'models') return (string) $request->header('authorization', '') !== '' ? 'gateway_native' : 'gateway';
        if (in_array($action, ['native', 'websocket'], true)) return 'gateway_native';
        try {
            $raw = self::decodedBody($request);
            $body = $action === 'compact' ? json_decode($raw, true, 128, JSON_THROW_ON_ERROR) : json_decode($raw, true);
            if (self::nativeModel($body, $action === 'compact')) return 'gateway_native';
        } catch (\Throwable) {
            // The existing controller owns malformed compression/JSON responses, not routing.
        }
        return 'gateway';
    }

    /**
     * Preserve the distinct create/compact model-selection rules in one shared predicate.
     * @param mixed $body Decoded JSON, including invalid/non-object values.
     * @param bool $compact True for the native unary compaction endpoint.
     * @return bool Whether the original controller would select native forwarding.
     */
    public static function nativeModel(mixed $body, bool $compact = false): bool
    {
        if ($compact) return !str_starts_with((string) ($body['model'] ?? ''), 'chatgpt-web/');
        return is_array($body) && is_string($body['model'] ?? null) && !str_starts_with($body['model'], 'chatgpt-web/');
    }

    /**
     * Decode a bounded inspection copy using the existing controller's compression contract.
     * @param Request $request Current request; never cached beyond its framework lifetime.
     * @return string Decoded bytes; the HTTP wire body is not changed.
     * @throws GatewayError On unsupported, corrupt or oversized compressed input.
     */
    public static function decodedBody(Request $request): string
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
}
