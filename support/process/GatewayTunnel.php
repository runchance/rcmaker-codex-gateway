<?php
declare(strict_types=1);
namespace support\process;

use app\gateway\database\Schema;
use app\gateway\model\GatewayError;
use app\gateway\model\TunnelRuntime;

/** Isolated owner for tunnel-client installation, health checks and reconnects. */
final class GatewayTunnel
{
    private float $nextAttempt = 0.0;
    private int $settingsVersion = -1;
    /** @var resource|null */
    private $ownershipLock = null;

    public function __construct($type, $worker, $timer)
    {
        $cleanup = function (): void {
            if ($this->ownershipLock === null) return;
            try { TunnelRuntime::shutdown(); }
            catch (\Throwable $error) { error_log('[gateway-tunnel] shutdown failed: ' . get_class($error)); }
        };
        register_shutdown_function($cleanup);
        if ($type === 'workerman' && is_object($worker)) {
            $previous = $worker->onWorkerStop ?? null;
            $worker->onWorkerStop = static function ($stoppedWorker) use ($cleanup, $previous): void {
                $cleanup();
                if (is_callable($previous)) $previous($stoppedWorker);
            };
        }
        $timer::add(1, function (): void {
            try {
                Schema::initialize();
                $settingsVersion = (int) \app\gateway\model\GatewaySettings::internal()['updated_at'];
                if ($settingsVersion !== $this->settingsVersion) {
                    $this->settingsVersion = $settingsVersion;
                    $this->nextAttempt = 0.0;
                }
                if (microtime(true) < $this->nextAttempt) return;
                if ($this->ownershipLock === null) {
                    $lock = @fopen(\RC\Config::get('gateway', 'database_file') . '.tunnel.lock', 'c');
                    if ($lock === false) {
                        $this->nextAttempt = microtime(true) + 15;
                        return;
                    }
                    if (!flock($lock, LOCK_EX | LOCK_NB)) {
                        fclose($lock);
                        $this->nextAttempt = microtime(true) + 15;
                        return;
                    }
                    $this->ownershipLock = $lock;
                }
                TunnelRuntime::ensureReady();
                $this->nextAttempt = microtime(true) + 5;
            } catch (GatewayError $error) {
                try { TunnelRuntime::noteFailure($error->errorCode); } catch (\Throwable) {}
                $this->nextAttempt = microtime(true) + 15;
            } catch (\Throwable) {
                try { TunnelRuntime::noteFailure('TUNNEL_RUNTIME_ERROR'); } catch (\Throwable) {}
                $this->nextAttempt = microtime(true) + 15;
            }
        });
    }
}
