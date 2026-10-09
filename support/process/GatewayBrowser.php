<?php
declare(strict_types=1);
namespace support\process;

use support\browser\BrowserRuntime;
use app\gateway\model\StartupProgress;

/** Dedicated browser/CDP worker. Browser I/O and screenshots never block the console/API APP processes. */
final class GatewayBrowser
{
    private BrowserRuntime $runtime;

    /** @param string $type Framework runtime. @param mixed $worker Owning worker. @param string $timer Framework timer class. */
    public function __construct($type, $worker, $timer)
    {
        $this->runtime = new BrowserRuntime();
        $cleaned = false;
        $cleanup = function () use (&$cleaned): void {
            if ($cleaned) return;
            $cleaned = true;
            try {
                $this->runtime->shutdown();
            } catch (\Throwable) {
            }
        };
        register_shutdown_function($cleanup);
        if ($type === 'workerman' && is_object($worker)) {
            $previousOnWorkerStop = $worker->onWorkerStop ?? null;
            $worker->onWorkerStop = function ($stoppedWorker) use ($cleanup, $previousOnWorkerStop): void {
                $cleanup();
                if (is_callable($previousOnWorkerStop)) $previousOnWorkerStop($stoppedWorker);
            };
        }
        $timer::add(0.1, function (): void {
            try {
                $this->runtime->tick();
            } catch (\Throwable) {
                // Readiness expires in the API; no browser URL/profile data is written to logs here.
            }
        });
        $timer::add(0.65, function (): void {
            try {
                $this->runtime->capture();
            } catch (\Throwable) {
            }
        });
        // Only this count=1 browser worker reports startup progress. Bootstrap/API workers
        // stay silent, and this observer never schedules another preparation or tool call.
        $progress = new StartupProgress();
        $timer::add(1, static function () use ($progress): void {
            try {
                $output = StartupProgress::render($progress->poll());
                if ($output !== '') fwrite(STDOUT, $output);
            } catch (\Throwable) {
                // Terminal presentation cannot interrupt browser execution or task recovery.
            }
        });
    }
}
