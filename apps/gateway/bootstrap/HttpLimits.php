<?php
declare(strict_types=1);
namespace app\gateway\bootstrap;

use RC\Config;
use Workerman\Connection\TcpConnection;
use Workerman\Worker;

/** Applies the gateway HTTP envelope limit before Workerman parses an incoming request. */
final class HttpLimits implements \RC\Bootstrap
{
    /**
     * Bind the limit to the ingress and both API APP groups, retaining connection hooks.
     * The installed framework does not apply independent APP package limits on Windows;
     * setting each connection avoids changing other workers or modifying vendor code.
     * Swoole receives the same package_max_length directly from process configuration.
     * @param mixed $worker Framework-owned application worker; other runtimes are ignored.
     * @return void
     */
    public static function start($worker = null): void
    {
        if (!$worker instanceof Worker || !in_array($worker->name,
            ['RC_GATEWAY_API', 'RC_GATEWAY_NATIVE_API', 'RC_GATEWAY_WEB_API'], true)) {
            return;
        }
        $limit = (int) Config::get('process', $worker->name)['package_max_length'];
        $onConnect = $worker->onConnect;
        $worker->onConnect = static function (TcpConnection $connection) use ($limit, $onConnect): void {
            $connection->maxPackageSize = $limit;
            if ($onConnect !== null) {
                $onConnect($connection);
            }
        };
    }
}
