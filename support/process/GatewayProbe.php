<?php
declare(strict_types=1);
namespace support\process;

use app\gateway\database\Schema;
use app\gateway\model\NetworkProbe;
use app\gateway\model\ReleaseUpdates;
use app\gateway\model\StartupProgress;

/** Single diagnostic consumer. Synchronous Curl is isolated here, never in the console/settings APP loop. */
final class GatewayProbe
{
    private bool $recovered = false;
    /** @var resource|null Per-database ownership lock held for this process lifetime. */
    private $ownershipLock = null;

    /** @param string $type Framework runtime. @param mixed $worker Owning Workerman worker. @param string $timer Framework timer class. */
    public function __construct($type, $worker, $timer)
    {
        $updates = new ReleaseUpdates();
        $timer::add(1, function () use ($updates): void {
            try {
                Schema::initialize();
                if ($this->ownershipLock === null) {
                    $lock = @fopen(\RC\Config::get('gateway', 'database_file') . '.probe.lock', 'c');
                    if ($lock === false) {
                        return;
                    }
                    if (!flock($lock, LOCK_EX | LOCK_NB)) {
                        fclose($lock);
                        return;
                    }
                    $this->ownershipLock = $lock;
                }
                if (!$this->recovered) {
                    NetworkProbe::recover();
                    $this->recovered = true;
                }
                NetworkProbe::tick();
                $notice = $updates->tick();
                if ($notice !== []) echo StartupProgress::render($notice);
            } catch (\Throwable $error) {
                // No request, URL, password or upstream body is logged; readiness expires visibly in the API.
            }
        });
    }
}
