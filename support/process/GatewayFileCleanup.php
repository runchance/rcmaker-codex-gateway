<?php
declare(strict_types=1);
namespace support\process;

use app\gateway\model\ArtifactStore;
use app\gateway\model\AttachmentStore;
use app\gateway\model\BrowserState;
use app\gateway\model\TaskMaintenance;

/** Isolate legacy file-reference scans from browser I/O, WAL checkpoints and task deletion. */
final class GatewayFileCleanup
{
    private ?string $attachmentCursor = null;

    /** @param string $type Framework runtime. @param mixed $worker Owning worker. @param string $timer Framework timer class. */
    public function __construct($type, $worker, $timer)
    {
        // One old attachment can require a large historical read. It owns neither the
        // browser event loop nor the checkpoint/retention worker, and no lock spans that read.
        $timer::add(300, function (): void {
            try {
                $state = BrowserState::state();
                if (($state['status'] ?? '') !== 'ready' || !BrowserState::isWorkerReady($state)
                    || TaskMaintenance::hasActiveWork()) return;
                ArtifactStore::cleanupOrphans(3600);
                AttachmentStore::cleanupOrphans(86400, $this->attachmentCursor, 1);
            } catch (\Throwable) {
                // Missing proof preserves the file. This optional pass never holds up startup.
            }
        });
    }
}
