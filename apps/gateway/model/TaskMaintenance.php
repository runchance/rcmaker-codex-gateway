<?php
declare(strict_types=1);
namespace app\gateway\model;

use app\gateway\database\Schema;
use RC\Config;

/** Task-only deletion and idle SQLite reclamation, consumed by the dedicated maintenance worker. */
final class TaskMaintenance
{
    /** SQL aliases are internal constants. In-flight work, not a task's display category, protects its records. */
    private const WORKING = "EXISTS(SELECT 1 FROM gateway_response_jobs j WHERE j.task_id=t.id AND j.status IN ('queued','running'))
        OR EXISTS(SELECT 1 FROM gateway_task_turns u WHERE u.task_id=t.id AND u.status IN ('queued','submit_intent','submitted','running'))
        OR EXISTS(SELECT 1 FROM gateway_tool_invocations i WHERE i.task_id=t.id AND i.status IN ('registered','delivered'))
        OR EXISTS(SELECT 1 FROM gateway_mcp_bindings b WHERE b.task_id=t.id AND b.status IN ('awaiting_start','running') AND b.expires_at>strftime('%s','now'))
        OR EXISTS(SELECT 1 FROM gateway_response_jobs other WHERE other.task_id<>t.id AND other.status IN ('queued','running')
            AND (other.previous_response_id IN (SELECT id FROM gateway_response_jobs WHERE task_id=t.id)
            OR other.rolling_source_response_id IN (SELECT id FROM gateway_response_jobs WHERE task_id=t.id)
            OR other.continuation_source_response_id IN (SELECT id FROM gateway_response_jobs WHERE task_id=t.id)))";

    /** @return bool True for any live response, tool delivery, approval wait or retained Web generation. */
    public static function isWorking(string $taskId): bool
    {
        $row = DB('medoo', 'sqlite')->query('SELECT 1 FROM gateway_tasks t WHERE t.id=:task AND (' . self::WORKING . ') LIMIT 1',
            [':task'=>$taskId])->fetchColumn();
        return $row !== false;
    }

    /** @return bool True when an explicit deletion already owns this idle task's records. */
    public static function isPending(string $taskId): bool
    {
        return DB('medoo', 'sqlite')->query("SELECT 1 FROM gateway_task_cleanup_items i JOIN gateway_task_cleanup c ON c.id=i.cleanup_id
            WHERE i.task_id=:task AND i.status IN ('pending','waiting_surface','deleting_rows')
            AND c.status IN ('queued','deleting','waiting_idle','compacting') LIMIT 1", [':task'=>$taskId])->fetchColumn() !== false;
    }

    /**
     * Snapshot deletion candidates. Tasks created later, and tasks working at this moment, never enter the batch.
     * @param string|null $taskId Null clears all idle tasks; otherwise deletes exactly one idle task.
     * @return array Persisted cleanup request and initial counts. @throws GatewayError for an active/missing task.
     */
    public static function request(?string $taskId = null): array
    {
        Schema::initialize();
        return self::withAdmission(static fn(): array => self::queueRequest($taskId));
    }

    /** Queue deletion candidates inside the shared admission lock, then release it before any browser operation. */
    private static function queueRequest(?string $taskId): array
    {
        return Schema::transaction(static function () use ($taskId): array {
            $db = DB('medoo', 'sqlite');
            $scope = $taskId === null ? 'all_idle' : $taskId;
            $existing = SDB(null, 'medoo', 'sqlite')->table('gateway_task_cleanup')->where('scope', $scope)
                ->where('status', 'IN', ['queued','deleting','compacting'])->order([['id','DESC']])->limit(1)->find();
            if (is_array($existing)) return self::snapshot((int) $existing['id']);
            if ($taskId !== null) {
                if (!is_array(SDB(null, 'medoo', 'sqlite')->table('gateway_tasks')->where('id', $taskId)->find())) {
                    throw new GatewayError('task_not_found', '任务不存在。', 404);
                }
                if (self::isWorking($taskId)) throw new GatewayError('TASK_WORKING', '任务仍在工作或等待工具审批，不能删除。', 409);
            }
            $where = $taskId === null ? '' : ' AND t.id=:task';
            $parameters = $taskId === null ? [] : [':task'=>$taskId];
            $skipped = $taskId === null ? (int) $db->query('SELECT COUNT(*) FROM gateway_tasks t WHERE (' . self::WORKING . ')')->fetchColumn() : 0;
            $now = time();
            $inserted = SDB(null, 'medoo', 'sqlite')->table('gateway_task_cleanup')->add([
                'scope'=>$scope, 'status'=>'queued', 'requested_at'=>$now,
                'updated_at'=>$now, 'skipped_count'=>$skipped, 'bytes_before'=>self::databaseBytes(),
            ]);
            if (!$inserted) throw new GatewayError('storage_unavailable', '无法登记任务清理。', 503);
            $id = (int) $db->id();
            $parameters[':cleanup'] = (int) $id;
            $db->query("INSERT INTO gateway_task_cleanup_items(cleanup_id,task_id,status)
                SELECT :cleanup,t.id,'pending' FROM gateway_tasks t WHERE NOT (" . self::WORKING . ')' . $where, $parameters);
            return self::snapshot((int) $id);
        });
    }

    /** @return array Small progress view; never returns prompts, credentials or deleted task contents. */
    public static function snapshot(?int $id = null): array
    {
        $query = SDB(null, 'medoo', 'sqlite')->table('gateway_task_cleanup');
        $row = $id === null ? $query->order([['id','DESC']])->limit(1)->find() : $query->where('id', $id)->find();
        if (is_array($row)) $row['pending_count'] = (int) DB('medoo', 'sqlite')->query("SELECT COUNT(*) FROM gateway_task_cleanup_items
            WHERE cleanup_id=:id AND status IN ('pending','waiting_surface','deleting_rows')", [':id'=>$row['id']])->fetchColumn();
        $pageSize = (int) DB('medoo', 'sqlite')->query('PRAGMA page_size')->fetchColumn();
        $free = (int) DB('medoo', 'sqlite')->query('PRAGMA freelist_count')->fetchColumn();
        return ['job'=>is_array($row) ? $row : null, 'database_bytes'=>self::databaseBytes(),
            'reusable_bytes'=>$free * $pageSize];
    }

    /**
     * Protect response admission against the brief idle VACUUM window, without changing request identities or rejecting work.
     * @param callable $operation Existing response reservation. @return mixed Its original return value.
     */
    public static function withAdmission(callable $operation): mixed
    {
        $lock = self::lock(LOCK_SH);
        try { return $operation(); }
        finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    /**
     * Consume a bounded batch, releasing the admission/writer locks after each deletion unit.
     * Page closure remains asynchronous in the browser worker; waiting pages never block unrelated candidates.
     * @param int $maxOperations Maximum task/response/file units; one preserves single-step diagnostics.
     * @param int $maxMilliseconds Scheduling budget between units, not a timeout that interrupts a transaction.
     * @return void Durable progress is visible through snapshot(); an interrupted batch resumes normally.
     */
    public static function tick(int $maxOperations = 20, int $maxMilliseconds = 150): void
    {
        $deadline = hrtime(true) + max(1, $maxMilliseconds) * 1000000;
        $waitingTasks = [];
        $waitingJobs = [];
        for ($unit = 0; $unit < max(1, min(100, $maxOperations)); $unit++) {
            if (!self::consumeOne($waitingTasks, $waitingJobs)) break;
            if (hrtime(true) >= $deadline) break;
        }
    }

    /** Select and recheck one unit under the admission lock; local exclusions last only for this batch. */
    private static function consumeOne(array &$waitingTasks, array &$waitingJobs): bool
    {
        $lock = self::lock(LOCK_EX | LOCK_NB, false);
        if ($lock === null) return false;
        $id = null;
        try {
            $parameters = [':retry_before' => time() - 30];
            $excludedJobs = self::excludedIds('id', $waitingJobs, 'job', $parameters);
            $sql = DB('medoo', 'sqlite');
            $job = $sql->query("SELECT id FROM gateway_task_cleanup WHERE status IN ('queued','deleting','waiting_idle','compacting')
                AND (error_code IS NULL OR error_code='' OR updated_at<=:retry_before)" . $excludedJobs . "
                ORDER BY CASE WHEN status IN ('queued','deleting') THEN 0 ELSE 1 END,id LIMIT 1", $parameters)->fetch(\PDO::FETCH_ASSOC);
            if (!is_array($job)) return self::cleanupFile(true);
            $id = (int) $job['id'];
            $parameters = [':cleanup' => $id];
            $excludedTasks = self::excludedIds('task_id', $waitingTasks, 'task', $parameters);
            $item = $sql->query("SELECT task_id,status FROM gateway_task_cleanup_items WHERE cleanup_id=:cleanup
                AND status IN ('pending','waiting_surface','deleting_rows')" . $excludedTasks . ' ORDER BY task_id DESC LIMIT 1',
                $parameters)->fetch(\PDO::FETCH_ASSOC);
            if (is_array($item)) {
                $progressed = Schema::transaction(static fn(): bool => self::deleteItemUnlocked($id, $item));
                if (!$progressed) $waitingTasks[] = (string) $item['task_id'];
                return true;
            }
            $waitingJobs[] = $id;
            // Excluding a still-open browser page does not make its cleanup eligible for VACUUM.
            $remaining = $sql->query("SELECT 1 FROM gateway_task_cleanup_items WHERE cleanup_id=:id
                AND status IN ('pending','waiting_surface','deleting_rows') LIMIT 1", [':id' => $id])->fetchColumn();
            if ($remaining === false) self::reclaimIdle($id);
            return true;
        } catch (\Throwable $error) {
            // Lock contention is retryable and must not consume the deletion candidate or fail an active task.
            if ($id === null) return false;
            $waitingJobs[] = $id;
            SDB(null, 'medoo', 'sqlite')->table('gateway_task_cleanup')->where('id', $id)->update([
                'status'=>'waiting_idle', 'error_code'=>$error instanceof GatewayError ? $error->errorCode : 'CLEANUP_RETRY',
                'updated_at'=>time(),
            ]);
            return true;
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    /** Bind bounded internal IDs for batch exclusions; columns/prefixes are supplied only by consumeOne(). */
    private static function excludedIds(string $column, array $ids, string $prefix, array &$parameters): string
    {
        if ($ids === []) return '';
        $placeholders = [];
        foreach ($ids as $index => $id) {
            $placeholder = ':' . $prefix . $index;
            $parameters[$placeholder] = $id;
            $placeholders[] = $placeholder;
        }
        return ' AND ' . $column . ' NOT IN (' . implode(',', $placeholders) . ')';
    }

    /** Delete one immutable candidate under the writer transaction; false means awaiting live work/page closure. */
    private static function deleteItemUnlocked(int $cleanupId, array $item): bool
    {
        $taskId = (string) $item['task_id'];
        $db = SDB(null, 'medoo', 'sqlite');
        $task = $db->table('gateway_tasks')->where('id', $taskId)->find();
        if (!is_array($task)) { self::finishItem($cleanupId, $taskId, 'deleted'); return true; }
        if (self::isWorking($taskId)) {
            if ($item['status'] === 'deleting_rows') {
                $db->table('gateway_task_cleanup')->where('id', $cleanupId)->update(['status'=>'waiting_idle','updated_at'=>time()]);
                return false;
            }
            self::finishItem($cleanupId, $taskId, 'skipped');
            return true;
        }
        $surfaces = $db->table('gateway_browser_surfaces')->where('task_id', $taskId)->select();
        $hasTarget = false;
        foreach ($surfaces ?: [] as $surface) if (!empty($surface['target_id'])) $hasTarget = true;
        $browser = $db->table('gateway_browser_state')->where('id', 1)->find();
        if ($hasTarget && ($browser['status'] ?? '') !== 'stopped') {
            if ($item['status'] !== 'waiting_surface') {
                TaskLedger::cancelTaskUnlocked($taskId);
                $db->table('gateway_task_cleanup_items')->where('cleanup_id', $cleanupId)->where('task_id', $taskId)
                    ->update(['status'=>'waiting_surface']);
                $db->table('gateway_task_cleanup')->where('id', $cleanupId)->update(['status'=>'deleting','updated_at'=>time()]);
            }
            return false;
        }
        if ($item['status'] !== 'deleting_rows') {
            TaskLedger::cancelTaskUnlocked($taskId);
            ContextCompaction::archiveTaskProofsUnlocked($taskId);
        }
        $sql = DB('medoo', 'sqlite');
        $parameters = [':task'=>$taskId];
        // While another task is working, release the writer after one response's heavy payload.
        // Idle cleanup keeps the fast whole-task path. This is maintenance batching, not a user/tool limit.
        $otherWork = $sql->query('SELECT 1 FROM gateway_tasks t WHERE t.id<>:task AND (' . self::WORKING . ') LIMIT 1', $parameters)->fetchColumn() !== false;
        $responseId = null;
        if ($otherWork) {
            $responseId = $sql->query('SELECT id FROM gateway_response_jobs WHERE task_id=:task ORDER BY rowid DESC LIMIT 1', $parameters)->fetchColumn();
            $responseId = is_string($responseId) ? $responseId : null;
        }
        GatewayUsage::archiveTaskUnlocked($taskId, $responseId);
        $jobWhere = 'task_id=:task' . ($responseId === null ? '' : ' AND id=:response');
        if ($responseId !== null) $parameters[':response'] = $responseId;
        $jobIds = 'SELECT id FROM gateway_response_jobs WHERE ' . $jobWhere;
        $commands = $sql->query('SELECT DISTINCT command_id FROM gateway_response_jobs WHERE ' . $jobWhere . ' AND command_id IS NOT NULL', $parameters)->fetchAll(\PDO::FETCH_COLUMN);
        $files = $sql->query('SELECT id,relative_path FROM gateway_artifacts WHERE response_id IN (' . $jobIds . ')', $parameters)->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($files ?: [] as $file) self::queueFile($cleanupId, 'artifact', $file);
        $files = $sql->query('SELECT DISTINCT a.id,a.relative_path FROM gateway_attachments a JOIN gateway_response_attachments r ON r.attachment_id=a.id
            WHERE r.response_id IN (' . $jobIds . ')', $parameters)->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($files as $file) self::queueFile($cleanupId, 'attachment', $file);
        // Optional historical pointers may outlive an old task; active dependents were excluded above.
        foreach (['rolling_source_response_id','continuation_source_response_id'] as $column) {
            self::mustWrite($sql->query('UPDATE gateway_response_jobs SET ' . $column . '=NULL WHERE ' . $column . ' IN (' . $jobIds . ')', $parameters));
        }
        self::mustWrite($sql->query('DELETE FROM gateway_compaction_handoffs WHERE compact_response_id IN (' . $jobIds . ') OR source_response_id IN (' . $jobIds . ')', $parameters));
        self::mustWrite($sql->query('DELETE FROM gateway_rolling_checkpoints WHERE response_id IN (' . $jobIds . ')', $parameters));
        foreach (['gateway_artifacts','gateway_tool_invocations'] as $table) {
            self::mustWrite($sql->query('DELETE FROM ' . $table . ' WHERE response_id IN (' . $jobIds . ')', $parameters));
        }
        self::mustWrite($sql->query('DELETE FROM gateway_response_jobs WHERE ' . $jobWhere, $parameters));
        foreach ($commands as $command) {
            $sql->query('DELETE FROM gateway_browser_commands WHERE id=:id AND NOT EXISTS(SELECT 1 FROM gateway_response_jobs WHERE command_id=:reference)',
                [':id'=>(int)$command, ':reference'=>(int)$command]);
        }
        if ($db->table('gateway_response_jobs')->where('task_id', $taskId)->count() > 0) {
            $db->table('gateway_task_cleanup_items')->where('cleanup_id', $cleanupId)->where('task_id', $taskId)->update(['status'=>'deleting_rows']);
            $db->table('gateway_task_cleanup')->where('id', $cleanupId)->update(['status'=>'deleting','error_code'=>null,'updated_at'=>time()]);
            return true;
        }
        foreach (['gateway_compaction_handoffs','gateway_rolling_checkpoints','gateway_context_checkpoints','gateway_tool_invocations',
            'gateway_mcp_bindings','gateway_task_turns','gateway_browser_surfaces','gateway_task_epochs'] as $table) {
            self::mustWrite($db->table($table)->where('task_id', $taskId)->delete());
        }
        self::mustWrite($db->table('gateway_task_conversations')->where('current_task_id', $taskId)->delete());
        self::mustWrite($db->table('gateway_tasks')->where('id', $taskId)->delete());
        self::finishItem($cleanupId, $taskId, 'deleted');
        return true;
    }

    /** Persist per-item completion atomically with deletion, so retries never double-count. */
    private static function finishItem(int $cleanupId, string $taskId, string $status): void
    {
        SDB(null, 'medoo', 'sqlite')->table('gateway_task_cleanup_items')->where('cleanup_id', $cleanupId)
            ->where('task_id', $taskId)->update(['status'=>$status]);
        $db = DB('medoo', 'sqlite');
        $db->query("UPDATE gateway_task_cleanup SET deleted_count=(SELECT COUNT(*) FROM gateway_task_cleanup_items WHERE cleanup_id=:id1 AND status='deleted'),
            skipped_count=skipped_count+:skipped,status='deleting',error_code=NULL,updated_at=:now WHERE id=:id2",
            [':id1'=>$cleanupId, ':id2'=>$cleanupId, ':skipped'=>$status === 'skipped' ? 1 : 0, ':now'=>time()]);
    }

    /** Reclaim the physical file only while there is no admitted work, using SQLite through the framework connection. */
    private static function reclaimIdle(int $cleanupId): void
    {
        $sql = DB('medoo', 'sqlite');
        $working = (int) $sql->query('SELECT COUNT(*) FROM gateway_tasks t WHERE (' . self::WORKING . ')')->fetchColumn();
        $setup = (int) $sql->query("SELECT COUNT(*) FROM gateway_browser_commands WHERE status IN ('queued','running')")->fetchColumn();
        if ($working > 0 || $setup > 0) {
            SDB(null, 'medoo', 'sqlite')->table('gateway_task_cleanup')->where('id', $cleanupId)
                ->update(['status'=>'waiting_idle','updated_at'=>time()]);
            return;
        }
        SDB(null, 'medoo', 'sqlite')->table('gateway_task_cleanup')->where('id', $cleanupId)
            ->update(['status'=>'compacting','updated_at'=>time()]);
        // VACUUM cannot run in a transaction. The admission lock remains held until it settles.
        // Deleted pages are reusable immediately; this final idle step actually shrinks the file.
        $job = SDB(null, 'medoo', 'sqlite')->table('gateway_task_cleanup')->where('id', $cleanupId)->find();
        if (empty($job['vacuum_done']) && (int)($job['deleted_count'] ?? 0) > 0) {
            $sql->query('VACUUM');
            SDB(null, 'medoo', 'sqlite')->table('gateway_task_cleanup')->where('id', $cleanupId)->update(['vacuum_done'=>1]);
        }
        $checkpoint = $sql->query('PRAGMA wal_checkpoint(TRUNCATE)')->fetch(\PDO::FETCH_NUM);
        if (is_array($checkpoint) && (int)$checkpoint[0] !== 0) {
            SDB(null, 'medoo', 'sqlite')->table('gateway_task_cleanup')->where('id', $cleanupId)
                ->update(['status'=>'waiting_idle','error_code'=>'CHECKPOINT_WAIT','updated_at'=>time()]);
            return;
        }
        SDB(null, 'medoo', 'sqlite')->table('gateway_task_cleanup')->where('id', $cleanupId)
            ->update(['status'=>'completed','bytes_after'=>self::databaseBytes(),'updated_at'=>time(),'error_code'=>null]);
        // Keep small operational summaries bounded, without retaining deleted conversation contents.
        $sql->query('DELETE FROM gateway_task_cleanup WHERE status IN (\'completed\',\'failed\') AND id<:keep
            AND NOT EXISTS(SELECT 1 FROM gateway_task_cleanup_files f WHERE f.cleanup_id=gateway_task_cleanup.id)', [':keep'=>max(0,$cleanupId-20)]);
        $sql->query('DELETE FROM gateway_usage_archive WHERE accepted_at<:since', [':since'=>time()-8*86400]);
    }

    /** @return int Main database file size, not an estimate of reclaimable pages. */
    private static function databaseBytes(): int
    {
        $path = (string) Config::get('gateway', 'database_file');
        clearstatcache(true, $path);
        return is_file($path) ? (int) filesize($path) : 0;
    }

    /** Queue private copies only; repository/project files and browser login profiles are never deletion targets. */
    private static function queueFile(int $cleanupId, string $kind, array $file): void
    {
        // A shared attachment can occur in several deleted response chunks. Queue it once atomically.
        self::mustWrite(DB('medoo', 'sqlite')->query('INSERT INTO gateway_task_cleanup_files
            (cleanup_id,kind,resource_id,relative_path,available_at) VALUES(:cleanup,:kind,:resource,:path,:now)
            ON CONFLICT(cleanup_id,kind,resource_id) DO NOTHING', [
                ':cleanup' => $cleanupId, ':kind' => $kind, ':resource' => (string) $file['id'],
                ':path' => (string) $file['relative_path'], ':now' => time(),
            ]));
    }

    /** A framework driver may return null/false instead of throwing; failed deletion must roll back the entire task. */
    private static function mustWrite(mixed $result): void
    {
        if ($result === false || $result === null) throw new GatewayError('storage_unavailable', '任务记录删除未完成，原记录已回滚。', 503);
    }

    /** Recheck shared/recent attachments; true means a file was handled or deferred, false means no eligible work. */
    private static function cleanupFile(bool $alreadyLocked = false): bool
    {
        $lock = $alreadyLocked ? null : self::lock(LOCK_EX | LOCK_NB, false);
        if (!$alreadyLocked && $lock === null) return false;
        try {
            $file = SDB(null, 'medoo', 'sqlite')->table('gateway_task_cleanup_files')->where('available_at', '<=', time())
                ->order([['cleanup_id','ASC']])->limit(1)->find();
            if (!is_array($file)) return false;
            // The idle timer also reaches this path when there is no file to clean.
            // Never scan historical work under the exclusive admission lock just to
            // discover an empty queue: a waiting Web request would block its API loop.
            if (DB('medoo', 'sqlite')->query('SELECT 1 FROM gateway_tasks t WHERE (' . self::WORKING . ') LIMIT 1')->fetchColumn() !== false) return false;
            $sql = DB('medoo', 'sqlite');
            $id = (string) $file['resource_id'];
            $protected = false;
            if ($file['kind'] === 'attachment') {
                $row = SDB(null, 'medoo', 'sqlite')->table('gateway_attachments')->where('id', $id)->find();
                if (is_array($row) && (int)($row['verified_at'] ?? $row['created_at']) > time()-300) {
                    SDB(null, 'medoo', 'sqlite')->table('gateway_task_cleanup_files')->where('cleanup_id', $file['cleanup_id'])
                        ->where('kind', $file['kind'])->where('resource_id', $id)->update(['available_at'=>time()+300]);
                    return true;
                }
                $protected = $sql->query('SELECT 1 FROM gateway_response_attachments WHERE attachment_id=:id LIMIT 1', [':id'=>$id])->fetchColumn() !== false
                    || $sql->query('SELECT 1 FROM gateway_checkpoint_proofs WHERE retained_refs_json LIKE :needle LIMIT 1', [':needle'=>'%'.$id.'%'])->fetchColumn() !== false;
                if (!$protected) {
                    $needle = '%' . $id . '%';
                    foreach ([
                        'SELECT 1 FROM gateway_response_jobs WHERE prompt LIKE :n1 OR context_json LIKE :n2 OR transport_context_json LIKE :n3 LIMIT 1',
                        'SELECT 1 FROM gateway_context_checkpoints WHERE summary LIKE :n1 OR response_json LIKE :n2 LIMIT 1',
                        'SELECT 1 FROM gateway_rolling_checkpoints WHERE summary LIKE :n1 LIMIT 1',
                    ] as $query) {
                        $parameters = [':n1'=>$needle];
                        if (str_contains($query, ':n2')) $parameters[':n2'] = $needle;
                        if (str_contains($query, ':n3')) $parameters[':n3'] = $needle;
                        if ($sql->query($query, $parameters)->fetchColumn() !== false) { $protected = true; break; }
                    }
                }
            }
            if (!$protected) {
                self::unlinkPrivate((string) $file['relative_path']);
                if ($file['kind'] === 'attachment') SDB(null, 'medoo', 'sqlite')->table('gateway_attachments')->where('id', $id)->delete();
            }
            SDB(null, 'medoo', 'sqlite')->table('gateway_task_cleanup_files')->where('cleanup_id', $file['cleanup_id'])
                ->where('kind', $file['kind'])->where('resource_id', $id)->delete();
            return true;
        } finally {
            if (is_resource($lock)) { flock($lock, LOCK_UN); fclose($lock); }
        }
    }

    /** Delete only a canonical file strictly below the configured private attachment root; never follow an escaping symlink. */
    private static function unlinkPrivate(string $relative): void
    {
        $root = realpath((string) Config::get('gateway', 'attachment_store_dir'));
        $relative = str_replace('\\', '/', $relative);
        if ($root === false || $relative === '' || preg_match('/\A(?:\/|[A-Za-z]:)/', $relative)
            || str_contains('/' . $relative . '/', '/../')) throw new GatewayError('CLEANUP_UNSAFE_PATH', '任务私有文件路径无法安全确认。', 409);
        $path = $root . '/' . $relative;
        if (!file_exists($path) && !is_link($path)) return;
        $resolved = realpath($path);
        $prefix = strtolower(rtrim(str_replace('\\', '/', $root), '/') . '/');
        if ($resolved === false || !str_starts_with(strtolower(str_replace('\\', '/', $resolved)), $prefix)
            || strtolower(str_replace('\\', '/', $resolved)) !== strtolower(str_replace('\\', '/', $path))
            || is_link($path) || !is_file($path)) throw new GatewayError('CLEANUP_UNSAFE_PATH', '任务私有文件路径无法安全确认。', 409);
        if (!unlink($path)) throw new GatewayError('CLEANUP_FILE_BUSY', '任务私有文件仍被占用，稍后自动清理。', 409);
    }

    /** @return resource|null Stable per-database lock; it is never removed or replaced while held. */
    private static function lock(int $mode, bool $required = true)
    {
        $path = (string) Config::get('gateway', 'database_file') . '.maintenance.lock';
        $directory = dirname($path);
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            if (!$required) return null;
            throw new GatewayError('storage_unavailable', '无法创建任务维护锁目录。', 503);
        }
        $lock = @fopen($path, 'c');
        if ($lock !== false && flock($lock, $mode)) return $lock;
        if (is_resource($lock)) fclose($lock);
        if ($required) throw new GatewayError('storage_unavailable', '无法取得任务维护锁。', 503);
        return null;
    }
}
