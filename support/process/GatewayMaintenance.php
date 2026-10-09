<?php
declare(strict_types=1);
namespace support\process;

use app\gateway\database\Schema;
use app\gateway\model\TaskMaintenance;

/** Framework timer consumer for short cleanup batches, independent of synchronous network diagnostics. */
final class GatewayMaintenance
{
    /** @param string $type Framework runtime. @param mixed $worker Owning worker. @param string $timer Framework timer class. */
    public function __construct($type, $worker, $timer)
    {
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
