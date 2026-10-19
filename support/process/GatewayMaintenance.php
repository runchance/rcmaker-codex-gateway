<?php
declare(strict_types=1);
namespace support\process;

use app\gateway\database\Schema;
use app\gateway\model\TaskMaintenance;

/** Independent storage worker for cleanup batches and nonblocking WAL checkpoints. */
final class GatewayMaintenance
{
    /** @param string $type Framework runtime. @param mixed $worker Owning worker. @param string $timer Framework timer class. */
    public function __construct($type, $worker, $timer)
    {
        $timer::add(1, static function (): void {
            try {
                // No writer transaction or cleanup admission lock surrounds this work.
                // PASSIVE skips contended pages; never switch to FULL/RESTART/TRUNCATE.
                Schema::checkpointWal();
            } catch (\Throwable) {
                // Keep durable WAL records for the next tick or worker restart.
            }
        });
        $timer::add(300, static function (): void {
            try {
                TaskMaintenance::scheduleInactive();
            } catch (\Throwable) {
                // Retry at the next scan; settings and durable cleanup ownership remain authoritative.
            }
        });
        $timer::add(0.25, static function (): void {
            try {
                Schema::initialize();
                TaskMaintenance::tick(20, 150);
            } catch (\Throwable) {
                // Durable candidates survive retries/restarts; never log task content or credentials.
            }
        });
    }
}
