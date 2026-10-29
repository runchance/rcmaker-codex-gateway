<?php
declare(strict_types=1);
namespace support\process;

use app\gateway\database\Schema;
use app\gateway\model\TaskMaintenance;
use app\gateway\model\TaskLedger;
use app\gateway\model\CodexIntegration;
use app\gateway\model\GatewayError;

/** Independent storage worker for cleanup batches, settled child pages and nonblocking WAL checkpoints. */
final class GatewayMaintenance
{
    private bool $compressionChecked = false;
    /** @param string $type Framework runtime. @param mixed $worker Owning worker. @param string $timer Framework timer class. */
    public function __construct($type, $worker, $timer)
    {
        $timer::add(1, function (): void {
            if (!$this->compressionChecked) {
                try {
                    CodexIntegration::upgradeCompressionDefaults();
                    $this->compressionChecked = true;
                } catch (GatewayError $error) {
                    // Retry only transient locks/storage. A conflicting user config stays intact.
                    $this->compressionChecked = !in_array($error->errorCode,['codex_config_busy','storage_busy','storage_unavailable'],true);
                } catch (\Throwable) { /* Startup storage may still be initializing; retry in this isolated worker. */ }
            }
            try {
                // No writer transaction or cleanup admission lock surrounds this work.
                // PASSIVE skips contended pages; never switch to FULL/RESTART/TRUNCATE.
                Schema::checkpointWal();
            } catch (\Throwable) {
                // Keep durable WAL records for the next tick or worker restart.
            }
        });
        $timer::add(5, static function (): void {
            try {
                Schema::initialize();
                TaskLedger::retireCancelledUnsentSurfaces();
                TaskLedger::retireFinishedSubagentPages();
            } catch (\Throwable) {
                // Ownership and receipt checks are retried here; never fail a parent response.
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
