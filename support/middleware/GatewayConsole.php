<?php
declare(strict_types=1);
namespace support\middleware;

use RC\Config;

/** Protects static resources and supplies public runtime port discovery without a login or frontend hardcoded port. */
final class GatewayConsole
{
    /** @param \RC\Request $request Current static request. @param callable $next Framework static handler. @return mixed */
    public function handle($request, callable $next)
    {
        $config = Config::get('gateway');
        if ($request->remoteIp() !== '127.0.0.1' || strtolower($request->host()) !== '127.0.0.1:' . $config['console_port'] || !in_array($request->method(), ['GET', 'HEAD'], true)) {
            $request->response('');
            return $request->json(['code' => 'LOCAL_CONSOLE_REQUIRED', 'message' => '请从本机控制台地址打开。'])->withStatus(403);
        }
        if ($request->path() === '/gateway-bootstrap.json') {
            $request->response('');
            $targets = [];
            foreach ($config['probe_targets'] as $id => $target) {
                $targets[] = ['id' => $id, 'label' => $target['label']];
            }
            $response = $request->json(['api_origin' => $config['api_origin'], 'targets' => $targets,
                'product' => 'RCMAKER AI Gateway', 'default_locale' => $config['default_locale']]);
        } else {
            $response = $next($request);
        }
        return $response->withHeaders([
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'SAMEORIGIN',
            'Referrer-Policy' => 'no-referrer',
            'Cross-Origin-Resource-Policy' => 'same-origin',
            'Content-Security-Policy' => "default-src 'self'; script-src 'self' 'unsafe-eval'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; font-src 'self' data:; connect-src 'self' " . $config['api_origin'] . "; frame-src 'self'; frame-ancestors 'self'; base-uri 'self'; form-action 'none'; object-src 'none'",
        ]);
    }
}
