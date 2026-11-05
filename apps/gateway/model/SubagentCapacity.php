<?php
declare(strict_types=1);
namespace app\gateway\model;

use app\gateway\database\Schema;

/** Global Web-child admission, shared by MCP dispatch and the asynchronous browser scheduler. */
final class SubagentCapacity
{
    /**
     * Gather exact terminal evidence outside a writer, for the existing maintenance process.
     * Only reservations that never acquired a durable Web turn are considered here.
     */
    public static function pendingTerminalEvidence(): array
    {
        $db = SDB(null, 'medoo', 'sqlite');
        $pending = $db->table('gateway_subagent_slots')->where('status', 'held')->where('turn_id', null)
            ->limit(129)->select();
        self::readable($pending);
        if (count($pending) > 128) return [];
        $proofs = [];
        foreach ($pending as $slot) {
            $call = $slot['invocation_id'] === null ? null : $db->table('gateway_tool_invocations')->where('id', $slot['invocation_id'])->find('status,result_json');
            if ($slot['thread_id'] === null && is_array($call) && self::spawnDidNotStart($call)) {
                $proofs[] = ['slot'=>$slot, 'native_limit'=>true];
                continue;
            }
            if (!is_string($slot['thread_id']) || !method_exists(CodexSubagent::class, 'terminalTurn')) continue;
            $scope = CodexSubagent::scope($slot['thread_id']);
            if (!is_array($scope) || $scope['parent_thread_id'] !== $slot['parent_thread_id']
                || $scope['agent_path'] !== $slot['agent_path']) continue;
            $terminal = CodexSubagent::terminalTurn($slot['thread_id']);
            if ($terminal === null || $terminal['finished_at'] < (int)$slot['updated_at']) continue;
            $precise = isset($slot['reserved_at_us']) && (int)$slot['reserved_at_us'] > 0;
            if ($precise ? $terminal['started_at_us'] < (int)$slot['reserved_at_us']
                : $terminal['started_at'] <= (int)$slot['updated_at']) continue;
            $tasks = $db->table('gateway_tasks')->where('thread_id', $slot['thread_id'])->select('id');
            self::readable($tasks);
            foreach ($tasks as $task) {
                $events = $db->table('gateway_task_events')->where('task_id', $task['id'])->where('response_id', null)
                    ->where('event_type', 'gateway_failure_recorded')->where('created_at', '>=', $terminal['started_at'])
                    ->where('created_at', '<=', $terminal['finished_at'])->order([['id','DESC']])->limit(12)->select('id,data_json');
                self::readable($events);
                foreach ($events as $event) {
                    $facts = json_decode((string)$event['data_json'], true);
                    if (!in_array($facts['error_code'] ?? '', ['context_length_exceeded','native_agent_assignment_unavailable'], true)) continue;
                    $exact = ($facts['native_turn_id'] ?? '') === $terminal['native_turn_id'];
                    // Legacy events lack a turn ID: never equate an older completion in
                    // the reservation's same second with the newly reserved native turn.
                    if (!$exact && isset($facts['native_turn_id'])) continue;
                    $proofs[] = ['slot'=>$slot, 'terminal'=>$terminal, 'failure_event_id'=>$event['id'], 'task_id'=>$task['id']];
                    continue 3;
                }
            }
        }
        return $proofs;
    }

    /** Reconcile native terminal evidence outside the writer before changing owned slot or page state. */
    public static function refreshNativeTerminals(): void
    {
        try { self::retireDetachedRejectedTurns(); }
        catch (\Throwable) { /* Retry page retirement without delaying unused reservation cleanup. */ }
        $proofs = self::pendingTerminalEvidence();
        if ($proofs === []) return;
        Schema::transaction(static function() use ($proofs): void {
            $db = SDB(null, 'medoo', 'sqlite');
            foreach ($proofs as $proof) {
                $slot = $db->table('gateway_subagent_slots')->where('id', $proof['slot']['id'])->find();
                if (!is_array($slot) || $slot['status'] !== 'held' || $slot['turn_id'] !== null) continue;
                foreach (['thread_id','parent_thread_id','agent_path','invocation_id','updated_at'] as $key) {
                    if ($slot[$key] !== $proof['slot'][$key]) continue 2;
                }
                if (($slot['reserved_at_us'] ?? null) !== ($proof['slot']['reserved_at_us'] ?? null)) continue;
                $call = $db->table('gateway_tool_invocations')->where('id', $slot['invocation_id'])->find('rowid,task_id,status,result_json');
                if (!is_array($call)) continue;
                $parent = $db->table('gateway_tasks')->where('id', $call['task_id'])->find('thread_id');
                if (!is_array($parent) || $parent['thread_id'] !== $slot['parent_thread_id']) continue;
                if (!empty($proof['native_limit'])) {
                    if (!self::spawnDidNotStart($call)) continue;
                } else {
                    if ($call['status'] !== 'completed') continue;
                    $active = $db->table('gateway_task_turns AS r')->join('gateway_tasks AS t', 't.id', 'r.task_id')
                        ->where('t.thread_id', $slot['thread_id'])->where('r.status', 'IN', ['queued','submit_intent','submitted','running','needs_attention'])->limit(1)->find('r.id');
                    $pending = $db->table('gateway_tool_invocations AS i')->join('gateway_tasks AS t', 't.id', 'i.task_id')
                        ->where('t.thread_id', $slot['thread_id'])->where('i.status', 'IN', ['registered','delivered','unknown'])->limit(1)->find('i.id');
                    if ($active === false || $pending === false) self::unavailable();
                    if (is_array($active) || is_array($pending)) continue;
                    // A newer parent delivery may have restarted this child after the
                    // read-only native snapshot. Such a delivery keeps the reservation.
                    $later = $db->table('gateway_tool_invocations AS i')->join('gateway_tasks AS t', 't.id', 'i.task_id')
                        ->where('t.thread_id', $slot['parent_thread_id'])->where('i.rowid', '>', (int)$call['rowid'])
                        ->where('i.wire_name', 'IN', ['collaboration__followup_task','collaboration__send_message'])
                        ->limit(129)->select('i.arguments_json');
                    self::readable($later);
                    if (count($later) > 128) continue;
                    foreach ($later as $next) {
                        $args = json_decode((string)$next['arguments_json'], true);
                        $target = $args['target'] ?? null;
                        $relative = substr($slot['agent_path'], strrpos($slot['agent_path'], '/') + 1);
                        if (in_array($target, [$slot['thread_id'],$slot['agent_path'],$relative], true)) continue 2;
                    }
                    $event = $db->table('gateway_task_events')->where('id', $proof['failure_event_id'])
                        ->where('task_id', $proof['task_id'])->where('response_id', null)->find('id');
                    if (!is_array($event)) continue;
                }
                if (!$db->table('gateway_subagent_slots')->where('id', $slot['id'])->where('status', 'held')
                    ->where('turn_id', null)->update(['status'=>'released','updated_at'=>time()])) self::unavailable();
            }
        });
    }

    /** Look for a tool offer recorded after the same native child ended on a budget rejection. */
    private static function retireDetachedRejectedTurns(): void
    {
        $db = SDB(null, 'medoo', 'sqlite');
        $candidates = $db->table('gateway_subagent_slots AS s')
            ->join('gateway_task_turns AS t', 't.id', 's.turn_id')
            ->join('gateway_response_jobs AS j', 'j.id', 't.response_id')
            ->where('s.status', 'held')->where('t.status', 'IN', ['submitted', 'running'])
            ->where('j.status', 'completed')->where('j.round_kind', 'tool_call')
            ->where('j.finished_at', '<=', time() - 30)->limit(128)
            ->select('s.thread_id,s.parent_thread_id,s.agent_path,t.id AS durable_turn_id,t.task_id,t.client_turn_id,j.id AS response_id,j.created_at,j.finished_at');
        self::readable($candidates);
        foreach ($candidates as $candidate) {
            $thread = $candidate['thread_id'];
            $nativeTurn = $candidate['client_turn_id'];
            if (!is_string($thread) || !is_string($nativeTurn) || !CodexCancellation::uuid($nativeTurn)) continue;
            // Read only a bounded indexed timeline tail, even for long reused children.
            $events = $db->table('gateway_task_events')->where('task_id', $candidate['task_id'])
                ->order([['id','DESC']])->limit(128)->select('id,response_id,event_type,created_at');
            self::readable($events);
            foreach ($events as $event) {
                if ($event['response_id'] !== null || $event['event_type'] !== 'gateway_failure_recorded'
                    || $event['created_at'] < $candidate['created_at'] || $event['created_at'] >= $candidate['finished_at']) continue;
                $details = $db->table('gateway_task_events')->where('id', $event['id'])->find('data_json');
                $failure = json_decode((string) ($details['data_json'] ?? ''), true);
                if (($failure['error_code'] ?? null) !== 'context_length_exceeded'
                    || ($failure['native_turn_id'] ?? null) !== $nativeTurn) continue;
                $scope = CodexSubagent::scope($thread);
                if (($scope['parent_thread_id'] ?? null) !== $candidate['parent_thread_id']
                    || ($scope['agent_path'] ?? null) !== $candidate['agent_path']) break;
                $terminal = CodexSubagent::terminalTurn($thread);
                if (($terminal['native_turn_id'] ?? null) === $nativeTurn
                    && $terminal['finished_at'] >= (int) $event['created_at']
                    && $terminal['finished_at'] < (int) $candidate['finished_at']) {
                    TaskLedger::retireDetachedRejectedChild($candidate, $terminal, (int) $event['id']);
                }
                break;
            }
        }
    }

    /** Preserve unknown native outcomes; recognize only definitive pre-start failure receipts. */
    private static function spawnDidNotStart(array $call): bool
    {
        if ($call['status'] === 'cancelled') return true;
        if ($call['status'] !== 'completed') return false;
        $result = json_decode((string)$call['result_json'], true);
        $output = $result['output'] ?? null;
        if ($output === 'collab spawn failed: agent thread limit reached') return true;
        if (is_string($output)) $output = json_decode($output, true);
        return is_array($output) && (!empty($output['error']) || ($output['ok'] ?? null) === false);
    }
    /**
     * Reserve before a new native spawn/followup is issued, inside ToolBroker's writer.
     * Existing call identities bypass admission. A full pool returns advisory MCP feedback,
     * never a fabricated host receipt or a queued descendant which could deadlock its parent.
     * @param array $tool Exact bound host capability. @param array $arguments Native arguments.
     * @param array $binding Owning live capability. @param string $invocationId New durable call ID.
     * @return ?array Capacity notice, or null when this call may be registered normally.
     * @throws GatewayError On unavailable admission metadata.
     */
    public static function reserveHostUnlocked(array $tool, array $arguments, array $binding, string $invocationId): ?array
    {
        if (($tool['namespace'] ?? '') !== 'collaboration' || ($tool['type'] ?? '') !== 'function'
            || !empty($tool['freeform'])) return null;
        $name = $tool['name'] ?? '';
        if (!in_array($name, ['spawn_agent', 'followup_task'], true)
            || ($tool['wire_name'] ?? '') !== 'collaboration__' . $name) return null;
        $db = SDB(null, 'medoo', 'sqlite');
        $parent = $db->table('gateway_tasks')->where('id', $binding['task_id'])->find('thread_id,native_agent_path');
        if (!is_array($parent) || !is_string($parent['thread_id'] ?? null)
            || !CodexCancellation::uuid($parent['thread_id'])) return null;
        self::refreshUnlocked();
        $thread = null;
        $path = '';
        if ($name === 'spawn_agent') {
            $taskName = $arguments['task_name'] ?? null;
            if (!is_string($taskName) || !preg_match('/\A[a-z0-9_]+\z/D', $taskName)) return null;
            $path = ($parent['native_agent_path'] ?: '/root') . '/' . $taskName;
        } else {
            $target = $arguments['target'] ?? null;
            if (!is_string($target)) return null;
            if (CodexCancellation::uuid($target)) {
                $child = $db->table('gateway_tasks')->where('thread_id', $target)
                    ->where('native_parent_thread_id', '<>', null)->order([['rowid','DESC']])->find('thread_id,native_agent_path');
            } else {
                $path = str_starts_with($target, '/') ? $target : ($parent['native_agent_path'] ?: '/root') . '/' . $target;
                $candidates = $db->table('gateway_tasks')->where('native_agent_path', $path)
                    ->where('native_parent_thread_id', '<>', null)->select('thread_id,native_agent_path');
                self::readable($candidates);
                $root = self::rootThreadUnlocked((string)$parent['thread_id']);
                $child = null;
                foreach ($candidates as $candidate) {
                    if ($root === null || self::rootThreadUnlocked((string)$candidate['thread_id']) !== $root) continue;
                    if ($child !== null && $child['thread_id'] !== $candidate['thread_id']) { $child = null; break; }
                    $child = $candidate;
                }
            }
            if ($child === false) self::unavailable();
            if (is_array($child)) {
                $thread = (string)$child['thread_id'];
                $path = (string)$child['native_agent_path'];
                $held = $db->table('gateway_subagent_slots')->where('thread_id', $thread)->where('status', 'held')->find('id,turn_id');
                if ($held === false) self::unavailable();
                if (is_array($held)) {
                    // A pending child may receive another assignment before its first Web
                    // request. Transfer the same reservation to that newer native call;
                    // the precise clock prevents an older native completion releasing it.
                    if ($held['turn_id'] === null && defined(Schema::class . '::SUBAGENT_RESERVATION_CLOCK_VERSION')) {
                        if (!$db->table('gateway_subagent_slots')->where('id', $held['id'])->update([
                            'invocation_id'=>$invocationId, 'updated_at'=>time(),
                            'reserved_at_us'=>(int)round(microtime(true)*1000000)])) self::unavailable();
                    }
                    return null; // Messaging an already running child takes no second slot.
                }
            } else {
                // An opaque native alias can address a non-Web or not-yet-arrived child.
                // Preserve native resolution; a proven Web arrival still uses acquire().
                // When full, never queue an unknown descendant behind its waiting parent.
                return self::availableUnlocked() ? null : self::notice();
            }
        }
        if (!self::availableUnlocked()) return self::notice();
        $row = $thread === null ? null : $db->table('gateway_subagent_slots')->where('thread_id', $thread)->find('id');
        if ($row === false) self::unavailable();
        $data = ['thread_id'=>$thread, 'parent_thread_id'=>(string)$parent['thread_id'], 'agent_path'=>$path,
            'invocation_id'=>$invocationId, 'turn_id'=>null, 'status'=>'held', 'updated_at'=>time()];
        if (defined(Schema::class . '::SUBAGENT_RESERVATION_CLOCK_VERSION')) $data['reserved_at_us'] = (int)round(microtime(true)*1000000);
        if (is_array($row)) $saved = $db->table('gateway_subagent_slots')->where('id', $row['id'])->update($data);
        else $saved = $db->table('gateway_subagent_slots')->add($data + ['id'=>$invocationId, 'created_at'=>time()]);
        if (!$saved) self::unavailable();
        return null;
    }

    /**
     * Admit the exact child generation before touching its page; primary/fork tasks bypass the pool.
     * Legacy/external children wait in the existing asynchronous browser command, not a global worker.
     * @param string $responseId Current owned response. @return bool Whether page preparation may proceed.
     * @throws GatewayError On stale ownership or unavailable storage.
     */
    public static function acquire(string $responseId): bool
    {
        $execution = TaskLedger::execution($responseId);
        $db = SDB(null, 'medoo', 'sqlite');
        $task = $db->table('gateway_tasks')->where('id', $execution['turn']['task_id'])->find('thread_id,native_parent_thread_id,native_agent_path');
        if ($task === false) self::unavailable();
        if (!is_array($task) || empty($task['native_parent_thread_id'])) return true;
        return Schema::transaction(static function () use ($responseId, $task): bool {
            $execution = TaskLedger::execution($responseId);
            $turn = $execution['turn'];
            if (($turn['response_id'] ?? null) !== $responseId
                || !in_array($turn['status'], ['queued','submit_intent','submitted','running'], true)) return false;
            self::refreshUnlocked();
            $db = SDB(null, 'medoo', 'sqlite');
            $slot = $db->table('gateway_subagent_slots')->where('thread_id', $task['thread_id'])->find();
            if ($slot === false) self::unavailable();
            if (!is_array($slot)) {
                // Native session metadata proves the spawn parent/path before this writer is entered.
                $slot = $db->table('gateway_subagent_slots')->where('thread_id', null)->where('status', 'held')
                    ->where('parent_thread_id', $task['native_parent_thread_id'])->where('agent_path', $task['native_agent_path'])
                    ->order([['created_at','ASC']])->find();
                if ($slot === false) self::unavailable();
            }
            if ((!is_array($slot) || $slot['status'] !== 'held') && !self::availableUnlocked()) {
                self::recordWaitUnlocked($execution);
                return false;
            }
            $data = ['thread_id'=>$task['thread_id'], 'parent_thread_id'=>$task['native_parent_thread_id'],
                'agent_path'=>$task['native_agent_path'], 'turn_id'=>$turn['id'], 'status'=>'held', 'updated_at'=>time()];
            if (is_array($slot)) $saved = $db->table('gateway_subagent_slots')->where('id', $slot['id'])->update($data);
            else $saved = $db->table('gateway_subagent_slots')->add($data + ['id'=>$turn['id'], 'created_at'=>time()]);
            if (!$saved) self::unavailable();
            return true;
        });
    }

    /**
     * Release the execution slot of a retired page with no remaining gateway work.
     * The browser observer must positively inventory both the exact target and conversation absent.
     * Preserve the stop obligation and unknown tool results for this task's own recovery.
     * @param array $snapshot Exact owned retirement witness from the browser observer.
     * @return bool Whether a held slot was released.
     */
    public static function releaseAbsentRetiredPage(array $snapshot): bool
    {
        if (!in_array($snapshot['chat_mode'] ?? null, ['temporary','regular'], true) || ($snapshot['state'] ?? '') !== 'pending'
            || empty($snapshot['source_response_id'])
            || isset($snapshot['origin_surface_id'])) return false;

        $db = SDB(null, 'medoo', 'sqlite');
        $task = $db->table('gateway_tasks')->where('id', $snapshot['task_id'])
            ->find('thread_id,native_parent_thread_id,native_agent_path');
        if (empty($task['native_parent_thread_id'])) return false;
        $slot = $db->table('gateway_subagent_slots')->where('thread_id', $task['thread_id'])
            ->where('status', 'held')->find('id,turn_id,parent_thread_id,agent_path');
        if (empty($slot['turn_id']) || $slot['parent_thread_id'] !== $task['native_parent_thread_id']
            || $slot['agent_path'] !== $task['native_agent_path']) return false;

        return Schema::transaction(static function () use ($snapshot, $slot, $task): bool {
            $db = SDB(null, 'medoo', 'sqlite');
            if (!TaskPageRetirement::owns($snapshot)) return false;
            $turn = $db->table('gateway_task_turns')->where('id', $slot['turn_id'])
                ->where('task_id', $snapshot['task_id'])->where('surface_id', $snapshot['surface_id'])
                ->where('status', 'IN', ['failed','cancelled','needs_attention'])->find('id');
            $source = $db->table('gateway_response_jobs')->where('id', $snapshot['source_response_id'])
                ->where('durable_turn_id', $slot['turn_id'])->where('task_id', $snapshot['task_id'])
                ->where('surface_id', $snapshot['surface_id'])->find('mcp_binding_id');
            if (!is_array($turn) || empty($source['mcp_binding_id'])) return false;
            $binding = $db->table('gateway_mcp_bindings')->where('id', $source['mcp_binding_id'])
                ->where('turn_id', $turn['id'])->where('task_id', $snapshot['task_id'])
                ->where('status', 'revoked')->find('id,token_hash,revoked_at');
            if (empty($binding['revoked_at'])
                || !in_array($binding['token_hash'], $snapshot['turn_token_hashes'] ?? [], true)) return false;

            $active = [
                $db->table('gateway_response_jobs')->where('task_id', $snapshot['task_id'])->where('status', 'IN', ['queued','running'])->count(),
                $db->table('gateway_tool_invocations')->where('turn_id', $turn['id'])->where('status', 'IN', ['registered','delivered'])->count(),
                $db->table('gateway_mcp_activities')->where('binding_id', $binding['id'])->where('status', 'active')->count(),
            ];
            foreach ($active as $count) {
                if (!is_numeric($count)) self::unavailable();
                if ((int)$count !== 0) return false;
            }
            $changed = $db->table('gateway_subagent_slots')->where('id', $slot['id'])
                ->where('status', 'held')->where('turn_id', $turn['id'])->where('thread_id', $task['thread_id'])
                ->where('parent_thread_id', $task['native_parent_thread_id'])->where('agent_path', $task['native_agent_path'])
                ->update(['status'=>'released','updated_at'=>time()]);
            if (!$changed) self::unavailable();
            return $changed->rowCount() === 1;
        });
    }

    /**
     * Refresh small durable admission records under the caller's writer; no filesystem/CDP/history reads.
     * Existing active children are grandfathered on upgrade or limit reduction. Uncertain work keeps
     * its slot until terminal evidence or the browser's verified absent, revoked retirement path.
     */
    private static function refreshUnlocked(): void
    {
        $db = SDB(null, 'medoo', 'sqlite');
        // TaskMaintenance removes source rows only after its existing idle/Stop/close gate.
        // Their FK-null admission metadata must not leave a permanent phantom slot.
        if (!$db->table('gateway_subagent_slots')->where('turn_id', null)->where('invocation_id', null)->delete()) self::unavailable();
        $active = $db->table('gateway_task_turns AS r')->join('gateway_tasks AS t', 't.id', 'r.task_id')
            ->where('t.native_parent_thread_id', '<>', null)->where('r.status', 'IN', ['submit_intent','submitted','running','needs_attention'])
            ->order([['r.rowid','DESC']])->select('r.id,t.thread_id,t.native_parent_thread_id,t.native_agent_path');
        self::readable($active);
        $seen = [];
        foreach ($active as $turn) {
            if (isset($seen[$turn['thread_id']])) continue;
            $seen[$turn['thread_id']] = true;
            $latest = $db->table('gateway_task_turns AS r')->join('gateway_tasks AS t', 't.id', 'r.task_id')
                ->where('t.thread_id', $turn['thread_id'])->order([['r.rowid','DESC']])->find('r.id');
            if ($latest === false) self::unavailable();
            if (!is_array($latest) || $latest['id'] !== $turn['id']) continue;
            $row = $db->table('gateway_subagent_slots')->where('thread_id', $turn['thread_id'])->find('id,turn_id,status');
            if ($row === false) self::unavailable();
            if (is_array($row)) {
                if ($row['turn_id'] !== null && $row['turn_id'] !== $turn['id']) {
                    if (!$db->table('gateway_subagent_slots')->where('id', $row['id'])
                        ->update(['turn_id'=>$turn['id'],'status'=>'held','updated_at'=>time()])) self::unavailable();
                }
                continue;
            }
            // Adopt pending spawn reservations instead of double-counting their first native request.
            $pending = $db->table('gateway_subagent_slots')->where('thread_id', null)->where('status', 'held')
                ->where('parent_thread_id', $turn['native_parent_thread_id'])->where('agent_path', $turn['native_agent_path'])->find('id');
            if ($pending === false) self::unavailable();
            $data = ['thread_id'=>$turn['thread_id'], 'parent_thread_id'=>$turn['native_parent_thread_id'],
                'agent_path'=>$turn['native_agent_path'], 'turn_id'=>$turn['id'], 'status'=>'held', 'updated_at'=>time()];
            $saved = is_array($pending) ? $db->table('gateway_subagent_slots')->where('id', $pending['id'])->update($data)
                : $db->table('gateway_subagent_slots')->add($data + ['id'=>$turn['id'], 'created_at'=>time()]);
            if (!$saved) self::unavailable();
        }
        $slots = $db->table('gateway_subagent_slots')->where('status', 'held')->select('id,thread_id,turn_id,invocation_id');
        self::readable($slots);
        foreach ($slots as $slot) {
            $release = false;
            if ($slot['turn_id'] !== null) {
                $turn = $db->table('gateway_task_turns')->where('id', $slot['turn_id'])->find('status,error_code,submit_intent_at,surface_id');
                if ($turn === false) self::unavailable();
                if (is_array($turn)) {
                    $release = $turn['status'] === 'committed' || $turn['error_code'] === 'CODEX_USER_STOPPED'
                        || (in_array($turn['status'], ['failed','cancelled'], true) && $turn['submit_intent_at'] === null);
                    if (!$release && in_array($turn['status'], ['failed','cancelled','needs_attention'], true)) {
                        $retired = $db->table('gateway_surface_retirements')->where('surface_id', $turn['surface_id'])
                            ->where('state', 'IN', ['stopped','closed'])->find('surface_id');
                        if ($retired === false) self::unavailable();
                        $release = is_array($retired);
                    }
                }
            } elseif ($slot['invocation_id'] !== null) {
                $call = $db->table('gateway_tool_invocations')->where('id', $slot['invocation_id'])->find('status,result_json');
                if ($call === false) self::unavailable();
                if (is_array($call)) {
                    $release = self::spawnDidNotStart($call);
                }
                if (!$release && $slot['thread_id'] !== null) {
                    // An issued spawn without a child ID is still pending. A known child ID
                    // comes from an existing task and can outlive that task's deletion.
                    $child = $db->table('gateway_tasks')->where('thread_id', $slot['thread_id'])->find('id');
                    if ($child === false) self::unavailable();
                    $release = $child === null;
                }
            }
            if ($release && !$db->table('gateway_subagent_slots')->where('id', $slot['id'])->update(['status'=>'released','updated_at'=>time()])) self::unavailable();
        }
    }

    /** Read the global setting while holding the admission writer, so processes cannot overbook. */
    private static function availableUnlocked(): bool
    {
        $count = SDB(null, 'medoo', 'sqlite')->table('gateway_subagent_slots')->where('status', 'held')->count();
        if (!is_numeric($count)) self::unavailable();
        return (int)$count < self::limitUnlocked();
    }

    /** Read only the numeric preference, avoiding template materialization under the admission writer. */
    private static function limitUnlocked(): int
    {
        $row = SDB(null, 'medoo', 'sqlite')->table('gateway_settings')->where('id', 1)->find('subagent_max_concurrency');
        if (!is_array($row) || !is_numeric($row['subagent_max_concurrency'] ?? null)) self::unavailable();
        return (int)$row['subagent_max_concurrency'];
    }

    /** Record one content-free capacity wait per response, without extending its existing deadline. */
    private static function recordWaitUnlocked(array $execution): void
    {
        $db = SDB(null, 'medoo', 'sqlite');
        $turn = $execution['turn'];
        $recorded = $db->table('gateway_task_events')->where('task_id', $turn['task_id'])
            ->where('response_id', $turn['response_id'])->where('event_type', 'subagent_capacity_wait')->find('id');
        if ($recorded === false) self::unavailable();
        if (is_array($recorded)) return;
        $data = ['limit'=>self::limitUnlocked(), 'deadline_at'=>(int)$turn['deadline_at']];
        if (!$db->table('gateway_task_events')->add(['task_id'=>$turn['task_id'], 'turn_id'=>$turn['id'],
            'response_id'=>$turn['response_id'], 'surface_id'=>$turn['surface_id'], 'event_type'=>'subagent_capacity_wait',
            'data_json'=>json_encode($data, JSON_THROW_ON_ERROR), 'created_at'=>time()])) self::unavailable();
    }

    /** Resolve only persisted native ancestry to distinguish identical agent paths in different roots. */
    private static function rootThreadUnlocked(string $thread): ?string
    {
        $seen = [];
        while (!isset($seen[$thread]) && count($seen) < 32) {
            $seen[$thread] = true;
            $task = SDB(null, 'medoo', 'sqlite')->table('gateway_tasks')->where('thread_id', $thread)
                ->order([['rowid','DESC']])->find('native_parent_thread_id');
            if ($task === false) self::unavailable();
            if (!is_array($task) || empty($task['native_parent_thread_id'])) return $thread;
            $thread = (string)$task['native_parent_thread_id'];
        }
        return null;
    }

    /** A successful control notice means no native operation was registered or executed. */
    private static function notice(string $detail = ''): array
    {
        return ['ok'=>true, 'paused'=>true, 'capacity_limited'=>true, 'dispatched'=>false,
            'instruction'=>$detail !== '' ? $detail : 'The gateway-wide Web subagent pool is full. This assignment was not registered or executed. Wait for existing children to finish, reuse a running child, or continue independent work yourself. Do not wait for an uncreated child or retry in a tight loop. Existing calls and exact receipts remain available.'];
    }

    /** Enforce the SDB select contract without treating storage failures as an empty pool. */
    private static function readable(mixed $rows): void
    {
        if (!is_array($rows)) self::unavailable();
    }

    /** Fail closed on an unreadable pool; never manufacture a free execution slot. */
    private static function unavailable(): never
    {
        throw new GatewayError('storage_unavailable', 'Web subagent admission state is unavailable.', 503);
    }
}
