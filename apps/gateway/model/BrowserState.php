<?php
declare(strict_types=1);
namespace app\gateway\model;

use app\gateway\database\Schema;

/** Persistent private-browser control state and bounded command queue shared by API and browser worker. */
final class BrowserState
{
    /** Shared cadence/expiry: allow two idle samples plus CDP latency, without increasing idle CPU. */
    public const IDLE_CAPABILITY_REFRESH_SECONDS = 8.0;
    public const CAPABILITY_MAX_AGE_SECONDS = 20;
    /** SQLite reservations can briefly delay heartbeat writes; this is liveness, not a task timeout. */
    public const WORKER_HEARTBEAT_MAX_AGE_SECONDS = 30;

    /**
     * Check worker liveness consistently without treating a live worker as a ready browser.
     * @param array $state Durable browser state; a missing/zero heartbeat means not initialized.
     * @param int|null $now Unix seconds, or the current clock; an explicit clock supports isolated checks.
     * @return bool Whether the last worker heartbeat is within the bounded liveness window.
     */
    public static function isWorkerReady(array $state, ?int $now = null): bool
    {
        $heartbeat = (int) ($state['worker_heartbeat'] ?? 0);
        return $heartbeat > 0 && $heartbeat >= ($now ?? time()) - self::WORKER_HEARTBEAT_MAX_AGE_SECONDS;
    }

    /** Read the singleton state. @return array @throws GatewayError */
    public static function state(): array
    {
        $row = SDB(null, 'medoo', 'sqlite')->table('gateway_browser_state')->where('id', 1)->find();
        if (!is_array($row)) {
            throw new GatewayError('BROWSER_STATE_UNAVAILABLE', '浏览器工作区状态暂不可读取。', 503);
        }
        return $row;
    }

    /** Safe API snapshot; process paths and launch nonce stay local. @return array */
    public static function snapshot(): array
    {
        $row = self::state();
        return [
            'status' => $row['status'],
            'browser_kind' => $row['browser_kind'],
            'browser_pid' => $row['browser_pid'] === null ? null : (int) $row['browser_pid'],
            'network_revision' => $row['network_revision'] === null ? null : (int) $row['network_revision'],
            'active_target' => $row['active_target'],
            'surface_generation' => (int) $row['surface_generation'],
            'document_nonce' => $row['document_nonce'],
            'lease_mode' => $row['lease_mode'],
            'url' => $row['url'],
            'title' => $row['title'],
            'tabs' => json_decode((string) $row['tabs_json'], true, 32, JSON_THROW_ON_ERROR),
            'viewport' => ['width' => (int) $row['viewport_width'], 'height' => (int) $row['viewport_height']],
            'screenshot_sequence' => (int) $row['screenshot_sequence'],
            'screenshot_mime' => $row['screenshot_mime'],
            'worker_ready' => self::isWorkerReady($row),
            'auto_start' => (int) ($row['auto_start'] ?? 0) === 1,
            'profile_reset_required' => (int) ($row['profile_reset_required'] ?? 0) === 1,
            'setup_completed' => (int) ($row['setup_completed'] ?? 0) === 1,
            'last_error' => $row['last_error'],
            'updated_at' => (int) $row['updated_at'],
        ];
    }

    /** Publish selected fields on the singleton within the caller's short transaction. @throws GatewayError */
    public static function write(array $values): void
    {
        $values['updated_at'] = time();
        $result = SDB(null, 'medoo', 'sqlite')->table('gateway_browser_state')->where('id', 1)->update($values);
        if (!$result || $result->rowCount() !== 1) {
            throw new GatewayError('BROWSER_STATE_UNAVAILABLE', '浏览器工作区状态写入失败。', 503);
        }
    }

    /** Update worker liveness without changing browser ownership. */
    public static function heartbeat(): void
    {
        self::write(['worker_heartbeat' => time()]);
    }

    /** Enqueue an immutable command bound to the current surface/document. @return int @throws GatewayError */
    public static function enqueue(string $action, array $payload, bool $documentBound = true, string $requiredLease = 'manual'): int
    {
        return Schema::transaction(function () use ($action, $payload, $documentBound, $requiredLease): int {
            $state = self::state();
            $db = SDB(null, 'medoo', 'sqlite');
            if (!self::isWorkerReady($state)) {
                throw new GatewayError('BROWSER_WORKER_UNAVAILABLE', '浏览器控制进程尚未就绪。', 503);
            }
            $reset = $db->table('gateway_browser_commands')->where('action', 'reset_profile')
                ->where('status', 'IN', ['queued', 'running'])->find();
            if ($reset === false) throw new GatewayError('BROWSER_STATE_UNAVAILABLE', '浏览器操作状态暂不可读取。', 503);
            if (is_array($reset) || ($action !== 'reset_profile' && (int) ($state['profile_reset_required'] ?? 0) === 1)) {
                throw new GatewayError('BROWSER_PROFILE_RESET_PENDING', '浏览器资料正在清空，请等待操作结束。', 409);
            }
            if ($action === 'reset_profile') self::assertProfileResetIdle();
            if ($action === 'start') {
                // Auto-start and a simultaneous login click must observe the same pending launch.
                $pendingStart = $db->table('gateway_browser_commands')->where('action', 'start')
                    ->where('status', 'IN', ['queued', 'running'])->order([['id', 'ASC']])->find('id');
                if ($pendingStart === false) throw new GatewayError('BROWSER_STATE_UNAVAILABLE', '浏览器启动状态暂不可读取。', 503);
                if (is_array($pendingStart)) return (int) $pendingStart['id'];
            }
            if (!in_array($action, ['start', 'install', 'reset_profile'], true) && $state['status'] === 'stopped') {
                throw new GatewayError('BROWSER_NOT_RUNNING', '请先启动受控浏览器。', 409);
            }
            if ($documentBound && $state['lease_mode'] !== $requiredLease) {
                if ($requiredLease === 'automation') {
                    throw new GatewayError('BROWSER_MANUAL_OWNS_SURFACE', '页面当前由人工控制，自动任务未取得控制权。', 409);
                }
                throw new GatewayError('BROWSER_AUTOMATION_OWNS_SURFACE', '自动任务正在控制页面，请先切换为人工接管。', 409);
            }
            $pending = $db->table('gateway_browser_commands')->where('status', 'queued')->count();
            if ($pending === false || $pending === null || $pending > 24) {
                throw new GatewayError('BROWSER_COMMAND_BUSY', '浏览器操作队列繁忙，请稍后重试。', 409);
            }
            $row = [
                'action' => $action,
                'surface_generation' => (int) $state['surface_generation'],
                'document_nonce' => $documentBound ? $state['document_nonce'] : null,
                'payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'status' => 'queued',
                'created_at' => time(),
            ];
            if (!$db->table('gateway_browser_commands')->add($row)) {
                throw new GatewayError('BROWSER_STATE_UNAVAILABLE', '浏览器操作无法入队。', 503);
            }
            if ($action === 'reset_profile') self::write(['profile_reset_required' => 1, 'auto_start' => 0]);
            return (int) $db->id();
        });
    }

    /** Reject profile clearing while a browser command or Web turn can still use the private Chrome. */
    public static function assertProfileResetIdle(?int $resetCommandId = null): void
    {
        $db = SDB(null, 'medoo', 'sqlite');
        $responses = $db->table('gateway_response_jobs')->where('status', 'IN', ['queued', 'running'])->count();
        $turns = $db->table('gateway_task_turns')
            ->where('status', 'IN', ['queued', 'submit_intent', 'submitted', 'running'])->count();
        $commands = $db->table('gateway_browser_commands')->where('status', 'IN', ['queued', 'running']);
        if ($resetCommandId !== null) $commands->where('id', '<>', $resetCommandId);
        $otherCommands = $commands->count();
        if ($responses === false || $turns === false || $otherCommands === false) {
            throw new GatewayError('BROWSER_STATE_UNAVAILABLE', '浏览器任务状态暂不可读取。', 503);
        }
        if ((int) $responses > 0 || (int) $turns > 0 || (int) $otherCommands > 0) {
            throw new GatewayError('BROWSER_PROFILE_BUSY', '仍有浏览器操作或网页任务在运行，请等待完成后再清空登录资料。', 409);
        }
    }

    /** Whether a profile reset remains required, including after a failed or interrupted command. */
    public static function profileResetPending(): bool
    {
        if ((int) (self::state()['profile_reset_required'] ?? 0) === 1) return true;
        $row = SDB(null, 'medoo', 'sqlite')->table('gateway_browser_commands')->where('action', 'reset_profile')
            ->where('status', 'IN', ['queued', 'running'])->find();
        if ($row === false) throw new GatewayError('BROWSER_STATE_UNAVAILABLE', '浏览器操作状态暂不可读取。', 503);
        return is_array($row);
    }

    /**
     * Claim a command, fencing manual UI actions to their original surface.
     * Task-owned turns use TaskLedger's own surface/epoch fences in the browser worker, so an
     * unrelated console-tab or browser-generation change must not strand an unsent response.
     * Empty ticks use a read-only preflight; any candidate is reselected and fenced inside the
     * original writer transaction. An enqueue racing an empty sample is picked up next tick.
     * @return array|null Claimed command, or null when no command is ready.
     * @throws GatewayError When durable queue state cannot be read or updated.
     */
    public static function claim(): ?array
    {
        $candidate = SDB(null, 'medoo', 'sqlite')->table('gateway_browser_commands')
            ->where('status', 'queued')->limit(1)->find('id');
        if ($candidate === false) {
            throw new GatewayError('BROWSER_STATE_UNAVAILABLE', '浏览器操作队列暂不可读取。', 503);
        }
        if ($candidate === null) return null;
        $rejected = null;
        $claimedCommand = Schema::transaction(function () use (&$rejected): ?array {
            $db = SDB(null, 'medoo', 'sqlite');
            $state = self::state();
            if ((int) ($state['profile_reset_required'] ?? 0) === 1) {
                $row = $db->table('gateway_browser_commands')->where('action', 'reset_profile')
                    ->where('status', 'queued')->order([['id', 'ASC']])->find();
                if ($row === false) throw new GatewayError('BROWSER_STATE_UNAVAILABLE', '浏览器操作队列暂不可读取。', 503);
                if (!is_array($row)) return null;
            } else {
                // A fresh tool task may already be queued while the tunnel reconnects. Its deferred
                // command must not starve the one no-side-effect check needed before it can send.
                $row = $state['status'] === 'ready' && ConnectorState::runtimeCheckPending()
                    ? $db->table('gateway_browser_commands')->where('action', 'verify_connector')->where('status', 'queued')->order([['id', 'ASC']])->find()
                    : null;
                if ($row === null) {
                    $query = $db->table('gateway_browser_commands')->where('status', 'queued');
                    // Deferred model work must not repeatedly reclaim the head of the queue ahead
                    // of the start/install command that makes its own execution possible.
                    if ($state['status'] !== 'ready') {
                        // A queued login/native operation cannot overtake slow Chrome startup.
                        $query->where('action', 'IN', ['install', 'start', 'stop', 'reset_profile']);
                    }
                    $row = $query->order([['id', 'ASC']])->find();
                }
            }
            if ($row === false) {
                throw new GatewayError('BROWSER_STATE_UNAVAILABLE', '浏览器操作队列暂不可读取。', 503);
            }
            if (!is_array($row)) {
                return null;
            }
            $maxQueueAge = $row['action'] === 'reset_profile' ? 600
                : (in_array((string) $row['action'], ['chatgpt_text', 'verify_connector', 'probe_models'], true) ? 180 : 30);
            if ((int) $row['created_at'] < time() - $maxQueueAge) {
                $rejected = ['command'=>$row, 'code'=>'BROWSER_COMMAND_EXPIRED', 'message'=>'浏览器操作等待过久，已取消。'];
                self::finish((int)$row['id'], false, ['code'=>$rejected['code'], 'message'=>$rejected['message']]);
                return null;
            }
            // Connector verification navigates to its own check page. A harmless page change
            // after enqueue must not discard the automatic runtime recheck before it starts.
            $documentBound = !in_array($row['action'], ['install', 'start', 'stop', 'reset_profile', 'lease', 'new_tab', 'switch_tab', 'close_tab', 'chatgpt_text', 'verify_connector', 'native_window'], true);
            if ($row['action'] === 'probe_models') $documentBound = false;
            $pageIndependent = in_array($row['action'], ['chatgpt_text', 'verify_connector', 'reset_profile'], true);
            if ((!$pageIndependent && (int) $row['surface_generation'] !== (int) $state['surface_generation'])
                || ($documentBound && $row['document_nonce'] !== $state['document_nonce'])) {
                $rejected = ['command'=>$row, 'code'=>'BROWSER_SURFACE_STALE', 'message'=>'页面已变化，旧操作未执行。'];
                self::finish((int)$row['id'], false, ['code'=>$rejected['code'], 'message'=>$rejected['message']]);
                return null;
            }
            $claimed = $db->table('gateway_browser_commands')->where('id', $row['id'])->where('status', 'queued')->update([
                'status' => 'running', 'started_at' => time(),
            ]);
            if (!$claimed || $claimed->rowCount() !== 1) {
                return null;
            }
            $row['payload'] = json_decode($row['payload_json'], true, 32, JSON_THROW_ON_ERROR);
            return $row;
        });
        // ResponseJob owns its own transactions; do not nest BEGIN IMMEDIATE. If this process
        // exits here, recoverInterrupted rechecks the still-bound unsent task on the next boot.
        if (is_array($rejected)) self::rejectQueuedResponse($rejected['command'], $rejected['code'], $rejected['message']);
        return $claimedCommand;
    }

    /** Publish the rejected command's still-bound response terminal after the queue transaction. */
    private static function rejectQueuedResponse(array $command, string $code, string $message): void
    {
        $id = (int) $command['id'];
        if (($command['action'] ?? '') !== 'chatgpt_text') return;
        $job = SDB(null, 'medoo', 'sqlite')->table('gateway_response_jobs')->where('command_id', $id)
            ->where('status', 'IN', ['queued', 'running'])->find();
        if ($job === false) throw new GatewayError('storage_unavailable', '被拒绝的浏览器命令无法同步任务状态。', 503);
        if (is_array($job)) ResponseJob::fail((string) $job['id'], $code, $message, $job['status'] === 'running');
    }

    /** Set one terminal command result. Caller may invoke inside an existing transaction. */
    public static function finish(int $id, bool $ok, array $result): void
    {
        $status = $ok ? 'succeeded' : 'failed';
        $updated = SDB(null, 'medoo', 'sqlite')->table('gateway_browser_commands')->where('id', $id)->where('status', 'IN', ['queued', 'running'])->update([
            'status' => $status,
            'result_json' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'finished_at' => time(),
        ]);
        if (!$updated) {
            throw new GatewayError('BROWSER_STATE_UNAVAILABLE', '浏览器操作结果无法保存。', 503);
        }
    }

    /**
     * Record bounded startup diagnostics without changing the command lifecycle or admitting
     * downstream work. Terminal/recovered commands cannot be overwritten by late stage callbacks.
     * @param int $id Running start command ID.
     * @param array $progress Non-secret stage/elapsed/PID metadata from its owning browser worker.
     * @throws GatewayError When the diagnostic update cannot be persisted.
     */
    public static function startupProgress(int $id, array $progress): void
    {
        $updated = SDB(null, 'medoo', 'sqlite')->table('gateway_browser_commands')->where('id', $id)
            ->where('action', 'start')->where('status', 'running')->update([
                'result_json'=>json_encode($progress, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ]);
        if ($updated === false) throw new GatewayError('BROWSER_STATE_UNAVAILABLE', '浏览器启动诊断暂不可保存。', 503);
    }

    /**
     * Close a browser command whose only remaining host result is explicitly unknown.
     * The Codex result, task attention state, and original tool-call response are retained;
     * this only releases an orphaned page polling command after the capability is revoked.
     */
    public static function finishUnknownToolCommand(int $id, string $responseId): bool
    {
        return Schema::transaction(static fn(): bool => self::finishUnknownToolCommandUnlocked($id, $responseId));
    }

    /** Apply the same fenced terminal transition during a live poll or worker recovery. */
    private static function finishUnknownToolCommandUnlocked(int $id, string $responseId): bool
    {
        $db = SDB(null, 'medoo', 'sqlite');
        $command = $db->table('gateway_browser_commands')->where('id', $id)->where('status', 'running')->find();
        if (!is_array($command) || (string) $command['action'] !== 'chatgpt_text') return false;
        $payload = json_decode((string) $command['payload_json'], true);
        if (!is_array($payload) || (string) ($payload['response_id'] ?? '') !== $responseId) return false;
        $response = $db->table('gateway_response_jobs')->where('id', $responseId)->where('command_id', $id)->find();
        if (!is_array($response) || (string) $response['status'] !== 'completed'
            || (string) $response['round_kind'] !== 'tool_call') return false;
        $turnId = (string) ($response['durable_turn_id'] ?? '');
        $turn = $db->table('gateway_task_turns')->where('id', $turnId)->find();
        if (!is_array($turn) || (string) $turn['status'] !== 'needs_attention'
            || (string) $turn['error_code'] !== 'TOOL_OUTCOME_UNKNOWN') return false;
        $task = $db->table('gateway_tasks')->where('id', (string) $turn['task_id'])->find();
        $surface = $db->table('gateway_browser_surfaces')->where('id', (string) $turn['surface_id'])->find();
        $binding = $db->table('gateway_mcp_bindings')->where('turn_id', $turnId)->find();
        if (!is_array($task) || (string) $task['status'] !== 'needs_attention'
            || !is_array($surface) || (string) $surface['status'] !== 'needs_attention'
            || !is_array($binding) || (string) $binding['status'] !== 'revoked') return false;
        $activeResponses = $db->table('gateway_response_jobs')->where('durable_turn_id', $turnId)
            ->where('status', 'IN', ['queued', 'running'])->count();
        $activeActivities = $db->table('gateway_mcp_activities')->where('binding_id', (string) $binding['id'])
            ->where('status', 'active')->count();
        $pendingCalls = $db->table('gateway_tool_invocations')->where('binding_id', (string) $binding['id'])
            ->where('status', 'IN', ['registered', 'delivered'])->count();
        $unknownCalls = $db->table('gateway_tool_invocations')->where('binding_id', (string) $binding['id'])
            ->where('status', 'unknown')->count();
        if (!is_numeric($activeResponses) || (int) $activeResponses !== 0
            || !is_numeric($activeActivities) || (int) $activeActivities !== 0
            || !is_numeric($pendingCalls) || (int) $pendingCalls !== 0
            || !is_numeric($unknownCalls) || (int) $unknownCalls === 0) return false;
        self::finish($id, false, [
            'code' => 'TOOL_OUTCOME_UNKNOWN',
            'message' => '本机工具结果需要在任务时间线确认；网页命令已停止等待，未重发或改变结果。',
        ]);
        return true;
    }

    /** Return a claimed command to the queue only when no external effect has started. */
    public static function defer(int $id): void
    {
        $updated = SDB(null, 'medoo', 'sqlite')->table('gateway_browser_commands')
            ->where('id', $id)->where('status', 'running')->update([
                'status' => 'queued', 'started_at' => null,
            ]);
        if (!$updated || $updated->rowCount() !== 1) {
            throw new GatewayError('BROWSER_STATE_UNAVAILABLE', '浏览器操作无法安全延后。', 503);
        }
    }

    /** Fail commands left in running state by a worker restart; they are never replayed automatically. */
    public static function recover(): void
    {
        Schema::transaction(function (): void {
            $db = SDB(null, 'medoo', 'sqlite');
            $rows = $db->table('gateway_browser_commands')->where('status', 'running')->select();
            if ($rows === false) {
                throw new GatewayError('BROWSER_STATE_UNAVAILABLE', '浏览器操作恢复失败。', 503);
            }
            foreach ($rows as $row) {
                $payload = (string) $row['action'] === 'chatgpt_text'
                    ? json_decode((string) $row['payload_json'], true) : null;
                if (is_array($payload) && is_string($payload['response_id'] ?? null)
                    && self::finishUnknownToolCommandUnlocked((int) $row['id'], $payload['response_id'])) continue;
                $result = ['code'=>'BROWSER_COMMAND_INTERRUPTED', 'message'=>'浏览器控制进程已重启，操作结果未知，请重新执行。'];
                if ($row['action'] === 'start') {
                    $progress = json_decode((string) ($row['result_json'] ?? ''), true);
                    if (is_array($progress) && ($progress['status'] ?? '') === 'starting') $result['startup'] = $progress;
                }
                self::finish((int) $row['id'], false, $result);
            }
            self::write(['worker_heartbeat' => time()]);
        });
    }

    /** Atomically transfer the surface lease and invalidate commands from the previous controller. @return int */
    public static function setLease(string $mode): int
    {
        if (!in_array($mode, ['manual', 'automation'], true)) {
            throw new GatewayError('INVALID_BROWSER_LEASE', '浏览器控制模式无效。');
        }
        return Schema::transaction(function () use ($mode): int {
            if (self::profileResetPending()) {
                throw new GatewayError('BROWSER_PROFILE_RESET_PENDING', '浏览器资料正在清空，请等待操作结束。', 409);
            }
            $state = self::state();
            if ($state['lease_mode'] === $mode) {
                return (int) $state['surface_generation'];
            }
            $generation = (int) $state['surface_generation'] + 1;
            self::write(['lease_mode' => $mode, 'surface_generation' => $generation]);
            ModelCatalog::advanceSurfaceGeneration((int) $state['surface_generation'], $generation);
            return $generation;
        });
    }

    /** Persist whether the private browser should start with the application after setup begins. */
    public static function setAutoStart(bool $enabled): void
    {
        Schema::transaction(function () use ($enabled): void {
            if ($enabled && self::profileResetPending()) {
                throw new GatewayError('BROWSER_PROFILE_RESET_PENDING', '浏览器资料正在清空，请等待操作结束。', 409);
            }
            self::write(['auto_start' => $enabled ? 1 : 0]);
        });
    }

    /** Remember that this profile has reached an authenticated ChatGPT editor at least once. */
    public static function markSetupCompleted(): void
    {
        $state = self::state();
        if ((int) ($state['setup_completed'] ?? 0) !== 1) self::write(['setup_completed' => 1]);
    }
}
