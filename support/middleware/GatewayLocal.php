<?php
declare(strict_types=1);
namespace support\middleware;

use RC\Config;
use app\gateway\model\ResponsesProtocol;

/** Loopback, exact Host/Origin and preflight boundary for the login-free console API. No session or user account is created. */
final class GatewayLocal
{
    /** @param \RC\Request $request Current request. @param callable $next Next framework handler. @return mixed Framework response. */
    public function handle($request, callable $next)
    {
        $gateway = Config::get('gateway');
        $path = (string) $request->path();
        $responsesApi = str_starts_with($path, '/v1/');
        $origin = (string) $request->header('origin', '');
        // Framework application ownership selects the port; caller-supplied Host cannot.
        $portKey = match ($request->app['app'] ?? '') {
            'gateway_native' => 'native_api_port',
            'gateway' => 'web_api_port',
            default => 'api_port',
        };
        $expectedHost = '127.0.0.1:' . $gateway[$portKey];
        $headers = [
            'Access-Control-Allow-Origin' => $gateway['console_origin'],
            'Access-Control-Allow-Methods' => 'GET, POST, OPTIONS',
            'Access-Control-Allow-Headers' => $responsesApi ? 'Content-Type, Idempotency-Key' : 'Content-Type, X-Gateway-Request',
            'Access-Control-Expose-Headers' => 'X-Browser-Sequence, X-Browser-Surface',
            'Access-Control-Max-Age' => '300',
            'Vary' => 'Origin',
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ];
        $errorBody = static function (string $code, string $message) use ($responsesApi): array {
            return $responsesApi
                ? ResponsesProtocol::error($message, strtolower($code))
                : ['code' => $code, 'message' => $message];
        };
        $originAllowed = $responsesApi
            ? ($origin === '' || $origin === $gateway['console_origin'])
            : $origin === $gateway['console_origin'];
        if ($request->remoteIp() !== '127.0.0.1' || strtolower($request->host()) !== $expectedHost || !$originAllowed) {
            return $request->json($errorBody('LOCAL_ORIGIN_REQUIRED', '此接口仅接受本机控制台请求。'))
                ->withStatus(403)->withHeaders($headers);
        }
        if ($request->method() === 'OPTIONS') {
            $method = strtoupper((string) $request->header('access-control-request-method', ''));
            $requestedHeaders = array_filter(array_map('trim', explode(',', strtolower((string) $request->header('access-control-request-headers', '')))));
            $allowedHeaders = $responsesApi ? ['content-type', 'idempotency-key'] : ['content-type', 'x-gateway-request'];
            if (!in_array($method, ['GET', 'POST'], true) || array_diff($requestedHeaders, $allowedHeaders)) {
                return $request->json($errorBody('PREFLIGHT_REJECTED', '请求方式不受支持。'))
                    ->withStatus(403)->withHeaders($headers);
            }
            $response = $request->response('', 204);
        } else {
            if (!$responsesApi && $request->header('x-gateway-request', '') !== 'console') {
                return $request->json($errorBody('CONSOLE_HEADER_REQUIRED', '缺少控制台请求标识。'))
                    ->withStatus(403)->withHeaders($headers);
            }
            $mediaType = strtolower(trim(explode(';', (string) $request->header('content-type', ''), 2)[0]));
            $maxBody = $responsesApi ? ResponsesProtocol::MAX_BODY_BYTES : 16384;
            if ($request->method() === 'POST' && strlen((string) $request->rawBody()) > $maxBody) {
                return $request->json($errorBody('REQUEST_BODY_TOO_LARGE', 'HTTP 请求正文超过网关字节上限；这不是模型上下文 token 上限。'))
                    ->withStatus(413)->withHeaders($headers);
            }
            if ($request->method() === 'POST' && $mediaType !== 'application/json') {
                return $request->json($errorBody('INVALID_BODY', '请求必须是大小受限的 JSON。'))
                    ->withStatus(400)->withHeaders($headers);
            }
            $response = $next($request);
        }
        return $response->withHeaders($headers);
    }
}
