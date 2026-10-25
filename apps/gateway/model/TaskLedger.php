<?php
declare(strict_types=1);
namespace app\gateway\model;

use app\gateway\database\Schema;

/**
 * Durable Step 05 task/epoch/turn ledger and five-surface reservation policy.
 * All external effects happen outside these short SQLite transactions.
 */
final class TaskLedger
{
    public const MAX_SURFACES = 5;
    private const ACTIVE_TURNS = ['queued', 'submit_intent', 'submitted', 'running'];
    private const CLIENT_TRANSPORT_EVENT_LIMIT = 256;

    /**
     * List owned turns for explicit-stop observation, including a host timeout after a missed stop.
     * @return list<array> Internal execution rows; never exposed through the console API.
     * @throws GatewayError If the active ledger cannot be read.
     */
    public static function cancellableExecutions(): array
    {
        $rows = SDB(null, 'medoo', 'sqlite')->table('gateway_task_turns')->where('status', 'IN', self::ACTIVE_TURNS)->select();
        if (!is_array($rows)) throw new GatewayError('storage_unavailable', '停止观察账本暂不可读取。', 503);
        $unknown = SDB(null, 'medoo', 'sqlite')->table('gateway_task_turns')->where('status', 'needs_attention')
            ->where('error_code', 'IN', ['TOOL_OUTCOME_UNKNOWN', 'CODEX_USER_STOP_PENDING'])->select();
        if (!is_array($unknown)) throw new GatewayError('storage_unavailable', '停止观察的待确认回合暂不可读取。', 503);
        foreach ($unknown as $row) {
            // Historical unknown turns may retain large transcripts. Reject retired/replaced
            // surfaces using metadata before loading an execution for the current stop observer.
            $surface = SDB(null, 'medoo', 'sqlite')->table('gateway_browser_surfaces')->where('id', $row['surface_id'])->find('status');
            if (is_array($surface) && self::userStopEligible(['task_id'=>$row['task_id'], 'epoch_id'=>$row['epoch_id']], $row, $surface)) {
                $rows[] = $row;
            }
        }
        $executions = [];
        foreach ($rows as $row) {
            if (empty($row['response_id'])) continue;
            $execution = self::execution((string) $row['response_id']);
            if (self::ownsCurrentGeneration($execution['job'], $execution['turn'])
                && self::userStopEligible($execution['job'], $execution['turn'], $execution['surface'])) $executions[] = $execution;
        }
        return $executions;
    }

    /**
     * Find the exact native turn through a verified tool reconnect, Response ancestry or checkpoint derivation.
     * Shared by stop observation and detached-request routing; a checkpoint-derived turn
     * is still the same native turn when deciding whether new input is steering.
     * @param array $execution Current durable execution, not an arbitrary session's latest event.
     * @return ?string Native UUID, or null when readable same-task ancestry is unavailable.
     */
    public static function cancellationNativeTurn(array $execution): ?string
    {
        $job = $execution['job'];
        for ($depth = 0; $depth < 64; $depth++) {
            $rejoined = self::rejoinedNativeTurn($job);
            if ($rejoined !== null) return $rejoined;
            $id = (string) ($job['turn_id'] ?? '');
            if (CodexCancellation::uuid($id)) return $id;
            if (str_starts_with($id, 'postcompact_')) {
                $native = self::checkpointNativeTurn($job);
                if ($native !== null) return $native;
            }
            if (!str_starts_with($id, 'postcompact_') || empty($job['previous_response_id'])) return null;
            $parent = SDB(null, 'medoo', 'sqlite')->table('gateway_response_jobs')->where('id', $job['previous_response_id'])
                ->find('id,turn_id,previous_response_id,task_id,compaction_epoch,durable_turn_id');
            if (!is_array($parent) || $parent['task_id'] !== $execution['job']['task_id']) return null;
            $job = $parent;
        }
        return null;
    }

    /** Read the native UUID journaled by a proven tool-batch join or human revision on this exact durable turn. */
    private static function rejoinedNativeTurn(array $job): ?string
    {
        if (empty($job['task_id']) || empty($job['durable_turn_id'])) return null;
        $event = SDB(null, 'medoo', 'sqlite')->table('gateway_task_events')
            ->where('task_id', $job['task_id'])->where('turn_id', $job['durable_turn_id'])
            ->where('event_type', 'native_tool_batch_rejoined')->order([['id', 'DESC']])->limit(1)->find();
        $data = is_array($event) ? json_decode((string) $event['data_json'], true) : null;
        $native = (string) ($data['native_turn_id'] ?? '');
        return CodexCancellation::uuid($native) ? $native : null;
    }

    /**
     * Retain the native owner of an admitted human revision even when receipts reuse a ledger turn.
     * Call only inside response reservation after ContextHistory proved the new user revision.
     * @param string $responseId Newly reserved current response. @param string $nativeTurnId Original request UUID.
     * @return void
     * @throws GatewayError If reservation ownership changed before the provenance was recorded.
     */
    public static function recordSteeringNativeTurnUnlocked(string $responseId, string $nativeTurnId): void
    {
        if (!CodexCancellation::uuid($nativeTurnId)) return;
        [$job, $turn, $surface] = self::executionRowsUnlocked($responseId);
        if (!self::ownsCurrentGeneration($job, $turn)) {
            throw new GatewayError('steering_source_superseded', 'The user revision no longer owns this task turn.', 409);
        }
        if (self::rejoinedNativeTurn($job) === $nativeTurnId) return;
        self::eventUnlocked((string) $job['task_id'], (string) $turn['id'], $responseId, (string) $surface['id'],
            'native_tool_batch_rejoined', ['native_turn_id' => $nativeTurnId]);
    }

    /**
     * Reverse only the exact committed checkpoint/hash chain used to derive postcompact IDs.
     * A full post-checkpoint request has no previous_response_id. Its source epoch, producer,
     * committed parent turn and derived hash must all agree; a session's latest stop is never used.
     * @param array $job Internal job metadata from the current owned execution or same-task ancestor.
     * @return string|null Original native UUID, or null without unambiguous durable proof.
     */
    private static function checkpointNativeTurn(array $job): ?string
    {
        $taskId = (string) ($job['task_id'] ?? '');
        $clientTurn = (string) ($job['turn_id'] ?? '');
        $epoch = (string) ($job['compaction_epoch'] ?? 'base');
        if ($taskId === '' || $epoch === 'base') return null;
        $seen = [];
        while (str_starts_with($clientTurn, 'postcompact_') && !isset($seen[$clientTurn])) {
            $seen[$clientTurn] = true;
            $parent = null; $cursor = null;
            do {
                $query = SDB(null, 'medoo', 'sqlite')->table('gateway_context_checkpoints')->where('task_id', $taskId)
                    ->where('state', 'committed')->where('protocol', 'compact_v2');
                if ($cursor !== null) $query->where('id', '>', $cursor);
                $checkpoints = $query->order([['id', 'ASC']])->limit(128)
                    ->select('id,state,protocol,response_id,source_response_id,source_epoch_id,response_json');
                if (!is_array($checkpoints)) throw new GatewayError('storage_unavailable', '停止观察的检查点关联暂不可读取。', 503);
                foreach ($checkpoints as $checkpoint) {
                    $cursor = (string) $checkpoint['id'];
                    if (!ContextCompaction::matchesCommittedEpoch($checkpoint, $epoch)) continue;
                    $producer = SDB(null, 'medoo', 'sqlite')->table('gateway_response_jobs')->where('id', $checkpoint['response_id'])
                        ->find('id,status,operation_kind,task_id,compact_id,durable_turn_id');
                    if (!is_array($producer) || $producer['task_id'] !== $taskId || $producer['status'] !== 'completed'
                        || $producer['operation_kind'] !== 'compact_v2' || $producer['compact_id'] !== $checkpoint['id']) continue;
                    $ids = array_values(array_filter([$checkpoint['response_id'], $checkpoint['source_response_id']]));
                    $turns = SDB(null, 'medoo', 'sqlite')->table('gateway_task_turns')->where('task_id', $taskId)
                        ->where('epoch_id', $checkpoint['source_epoch_id'])->where('status', 'committed')
                        ->where('response_id', 'IN', $ids)->select('id,client_turn_id,response_id,epoch_id');
                    if (!is_array($turns)) throw new GatewayError('storage_unavailable', '停止观察的源回合暂不可读取。', 503);
                    foreach ($turns as $turn) {
                        $producerOwns = $producer['durable_turn_id'] === $turn['id'] && $turn['response_id'] === $producer['id'];
                        if (!$producerOwns && $turn['response_id'] !== $checkpoint['source_response_id']) continue;
                        $derived = 'postcompact_' . substr(hash('sha256', (string) $turn['client_turn_id'] . "\0" . $checkpoint['id']), 0, 32);
                        if (!hash_equals($derived, $clientTurn)) continue;
                        if ($parent !== null && $parent['id'] !== $turn['id']) return null;
                        $parent = $turn;
                    }
                }
            } while ($parent === null && count($checkpoints) === 128);
            if ($parent === null) return null;
            $rejoined = self::rejoinedNativeTurn(['task_id' => $taskId, 'durable_turn_id' => $parent['id']]);
            if ($rejoined !== null) return $rejoined;
            $clientTurn = (string) $parent['client_turn_id'];
            if (CodexCancellation::uuid($clientTurn)) return $clientTurn;
            $parentEpoch = SDB(null, 'medoo', 'sqlite')->table('gateway_task_epochs')->where('id', $parent['epoch_id'])
                ->where('task_id', $taskId)->find('compaction_epoch');
            if (!is_array($parentEpoch)) return null;
            $epoch = (string) $parentEpoch['compaction_epoch'];
        }
        return null;
    }

    /**
     * Preserve tool receipts for a proven manual stop, then fence the turn when they settle.
     * The browser may pause immediately in WAITING_TOOLS without revoking the receipt capability.
     * @param string $responseId Current logical response. @param array $evidence Exact local interruption metadata.
     * @return bool True when receipts have settled and terminal stop confirmation is allowed.
     * False means rejected or waiting for receipts; WAITING_TOOLS still permits an owned Web pause.
     * @throws GatewayError If the stop intent/capability revocation cannot commit atomically.
     */
    public static function requestUserStop(string $responseId, array $evidence): bool
    {
        return Schema::transaction(static function () use ($responseId, $evidence): bool {
            [$job, $turn, $surface] = self::executionRowsUnlocked($responseId);
            if (!self::ownsCurrentGeneration($job, $turn) || !self::userStopEligible($job, $turn, $surface)) return false;
            $nativeTurn = self::cancellationNativeTurn(['job' => $job, 'turn' => $turn]);
            if ($nativeTurn === null || ($evidence['turn_id'] ?? '') !== $nativeTurn
                || ($evidence['reason'] ?? '') !== 'interrupted' || !is_int($evidence['interrupted_at'] ?? null)
                || $evidence['interrupted_at'] < (int) $turn['created_at'] || $evidence['interrupted_at'] > time() + 5) return false;
            if (($turn['error_code'] ?? '') === 'CODEX_USER_STOP_PENDING') return true;
            $superseded = SDB(null, 'medoo', 'sqlite')->table('gateway_task_events')->where('turn_id', $turn['id'])
                ->where('event_type', 'codex_user_stop_superseded')->order([['id', 'DESC']])->limit(1)->find();
            if (is_array($superseded)) {
                $handled = json_decode((string) $superseded['data_json'], true);
                $sameEvent = isset($evidence['event_id'], $handled['event_id'])
                    ? $evidence['event_id'] === $handled['event_id']
                    : ($handled['interrupted_at'] ?? null) === $evidence['interrupted_at'];
                if ($sameEvent) return false;
            }
            if (!empty($job['mcp_binding_id']) && ToolBroker::hasUnsettledCallsUnlocked((string) $job['mcp_binding_id'])) {
                $last = SDB(null, 'medoo', 'sqlite')->table('gateway_task_events')->where('turn_id', $turn['id'])
                    ->where('event_type', 'codex_user_stop_waiting_tools')->order([['id', 'DESC']])->limit(1)->find();
                $waiting = is_array($last) ? json_decode((string) $last['data_json'], true) : [];
                if (($turn['error_code'] ?? '') !== 'CODEX_USER_STOP_WAITING_TOOLS'
                    || ($waiting['event_id'] ?? null) !== ($evidence['event_id'] ?? null)
                    || ($waiting['interrupted_at'] ?? null) !== $evidence['interrupted_at']) {
                    $updated = SDB(null, 'medoo', 'sqlite')->table('gateway_task_turns')->where('id', $turn['id'])
                        ->where('response_id', $responseId)->where('generation_id', $turn['generation_id'])
                        ->update(['error_code' => 'CODEX_USER_STOP_WAITING_TOOLS', 'updated_at' => time()]);
                    if (!$updated || $updated->rowCount() !== 1) throw new GatewayError('storage_unavailable', '等待工具完成的停止意图无法保存。', 503);
                    self::eventUnlocked((string) $turn['task_id'], (string) $turn['id'], $responseId,
                        (string) $surface['id'], 'codex_user_stop_waiting_tools', $evidence);
                }
                return false;
            }
            $updated = SDB(null, 'medoo', 'sqlite')->table('gateway_task_turns')->where('id', $turn['id'])
                ->where('response_id', $responseId)->where('generation_id', $turn['generation_id'])
                ->where('status', $turn['status'])->update(['error_code' => 'CODEX_USER_STOP_PENDING', 'updated_at' => time()]);
            if (!$updated || $updated->rowCount() !== 1) throw new GatewayError('storage_unavailable', '手动停止意图无法保存。', 503);
            ToolBroker::revokeTaskUnlocked((string) $turn['task_id'], 'codex_user_interrupted');
            self::eventUnlocked((string) $turn['task_id'], (string) $turn['id'], $responseId, (string) $surface['id'],
                'codex_user_stop_requested', $evidence);
            return true;
        });
    }

    /**
     * Recheck the precise pending generation before every asynchronous stop action/acknowledgement.
     * @param array $expected Internal execution captured after durable stop intent.
     * @return bool Whether its response, turn, target, document and owner still match.
     */
    public static function userStopOwns(array $expected): bool
    {
        $actual = self::execution((string) $expected['job']['id']);
        if (!self::ownsCurrentGeneration($actual['job'], $actual['turn'])
            || !self::userStopEligible($actual['job'], $actual['turn'], $actual['surface'])
            || !in_array($actual['turn']['error_code'] ?? '', ['CODEX_USER_STOP_PENDING', 'CODEX_USER_STOP_WAITING_TOOLS'], true)) return false;
        foreach (['id', 'generation_id', 'surface_id', 'response_id'] as $key) {
            if ($actual['turn'][$key] !== $expected['turn'][$key]) return false;
        }
        foreach (['target_id', 'document_nonce', 'owner_incarnation', 'fencing_version'] as $key) {
            if ($actual['surface'][$key] !== $expected['surface'][$key]) return false;
        }
        return true;
    }

    /**
     * Avoid repeating a confirmed Web pause while the exact stopped generation awaits host receipts.
     * @param array $expected Current fenced execution. @return bool True only for its exact page/owner.
     */
    public static function userStopPagePaused(array $expected): bool
    {
        $event = SDB(null, 'medoo', 'sqlite')->table('gateway_task_events')
            ->where('turn_id', $expected['turn']['id'])->where('event_type', 'codex_user_stop_page_paused')
            ->order([['id', 'DESC']])->limit(1)->find('data_json');
        $data = is_array($event) ? json_decode((string) $event['data_json'], true) : null;
        if (!is_array($data) || ($data['generation_id'] ?? null) !== $expected['turn']['generation_id']) return false;
        foreach (['target_id', 'document_nonce', 'owner_incarnation', 'fencing_version'] as $key) {
            if (($data[$key] ?? null) !== $expected['surface'][$key]) return false;
        }
        return true;
    }

    /**
     * Recover the already-proven stop intent while receipts drain, including after a worker restart.
     * @param array $execution Current waiting execution. @return ?array Stored native evidence, revalidated by requestUserStop.
     */
    public static function deferredUserStopEvidence(array $execution): ?array
    {
        if (($execution['turn']['error_code'] ?? '') !== 'CODEX_USER_STOP_WAITING_TOOLS') return null;
        $event = SDB(null, 'medoo', 'sqlite')->table('gateway_task_events')
            ->where('task_id', $execution['job']['task_id'])->where('turn_id', $execution['turn']['id'])
            ->where('event_type', 'codex_user_stop_waiting_tools')->order([['id', 'DESC']])->limit(1)->find('data_json');
        $data = is_array($event) ? json_decode((string) $event['data_json'], true) : null;
        return is_array($data) && ($data['turn_id'] ?? null) === self::cancellationNativeTurn($execution) ? $data : null;
    }

    /**
     * Keep unknown host effects reviewable while permitting an exact native interruption to stop its page.
     * Only the current unretired epoch's TOOL_OUTCOME_UNKNOWN branch is added; other failures,
     * ordinary disconnects, retired pages and unrelated tasks keep their previous behavior.
     */
    private static function userStopEligible(array $job, array $turn, array $surface): bool
    {
        if (in_array($turn['status'], self::ACTIVE_TURNS, true)) return true;
        if ($turn['status'] !== 'needs_attention'
            || !in_array($turn['error_code'] ?? '', ['TOOL_OUTCOME_UNKNOWN', 'CODEX_USER_STOP_PENDING'], true)
            || ($surface['status'] ?? '') === 'retired') return false;
        $task = SDB(null, 'medoo', 'sqlite')->table('gateway_tasks')->where('id', $job['task_id'])->find('current_epoch_id');
        return is_array($task) && ($task['current_epoch_id'] ?? null) === $job['epoch_id'];
    }

    /**
     * Confirm an owned Web pause; release the stopped turn only after host receipts have settled.
     * Retain its target/context and immutable tool receipts.
     * @param array $expected Exact stop ownership. @param bool $missing Successful target inventory proved absence.
     * @param bool $invalid Owned target was read successfully but no longer contains its expected Web chat.
     * @return bool False for a stale callback; true after durable page-pause or terminal acknowledgement.
     * @throws GatewayError If the stop confirmation or response terminal cannot be committed.
     */
    public static function confirmUserStop(array $expected, bool $missing = false, bool $invalid = false): bool
    {
        return Schema::transaction(static function () use ($expected, $missing, $invalid): bool {
            if (!self::userStopOwns($expected)) return false;
            [$job, $turn, $surface] = self::executionRowsUnlocked((string) $expected['job']['id']);
            $db = SDB(null, 'medoo', 'sqlite');
            $now = time();
            if (($turn['error_code'] ?? '') === 'CODEX_USER_STOP_WAITING_TOOLS') {
                // Pausing the Web model must not cancel, fabricate or consume an accepted host call.
                // Keep the original turn and capability until its accurate receipts settle.
                if (!self::userStopPagePaused($expected)) {
                    self::eventUnlocked((string) $turn['task_id'], (string) $turn['id'], (string) $job['id'],
                        (string) $surface['id'], 'codex_user_stop_page_paused',
                        ['generation_id' => $turn['generation_id'], 'page_missing' => $missing, 'page_invalid' => $invalid]
                            + array_intersect_key($surface, array_flip(['target_id', 'document_nonce', 'owner_incarnation', 'fencing_version'])));
                }
                return true;
            }
            $updated = $db->table('gateway_task_turns')->where('id', $turn['id'])->where('response_id', $job['id'])
                ->where('error_code', 'CODEX_USER_STOP_PENDING')->update([
                    'status' => 'cancelled', 'error_code' => 'CODEX_USER_STOPPED', 'retired_at' => $now, 'updated_at' => $now,
                ]);
            if (!$updated || $updated->rowCount() !== 1) throw new GatewayError('storage_unavailable', '手动停止确认无法保存。', 503);
            $values = ['status' => 'ready', 'session_id' => null, 'updated_at' => $now, 'last_observed_at' => $now];
            if ($missing) $values['target_id'] = null;
            $updated = $db->table('gateway_browser_surfaces')->where('id', $surface['id'])->update($values);
            if (!$updated || $updated->rowCount() !== 1) throw new GatewayError('storage_unavailable', '保留停止页面失败。', 503);
            if ($invalid) self::retireEpochUnlocked((string) $turn['task_id'], (string) $turn['epoch_id'], 'codex_user_stop_page_invalid');
            $unknown = $db->table('gateway_tool_invocations')->where('turn_id', $turn['id'])->where('status', 'unknown')->count();
            if ($unknown === false || $unknown === null) throw new GatewayError('storage_unavailable', '停止后的工具状态无法读取。', 503);
            $updated = $db->table('gateway_tasks')->where('id', $turn['task_id'])->update([
                'status' => (int) $unknown > 0 ? 'needs_attention' : 'active', 'updated_at' => $now,
            ]);
            if (!$updated) throw new GatewayError('storage_unavailable', '停止后的任务状态无法保存。', 503);
            // Completed tool-call responses are immutable. A live response gets one cancelled terminal.
            ResponseJob::failSupersededUnlocked((string) $job['id'], '当前回合已由 Codex 用户手动停止。', 'codex_user_cancelled');
            $responses = $db->table('gateway_response_jobs')->where('durable_turn_id', $turn['id'])
                ->where('command_id', '<>', null)->select('id,command_id');
            if (!is_array($responses)) throw new GatewayError('storage_unavailable', '停止回合的浏览器命令无法读取。', 503);
            foreach ($responses as $response) {
                $command = $db->table('gateway_browser_commands')->where('id', $response['command_id'])->find();
                if (!is_array($command) || !in_array($command['status'], ['queued', 'running'], true)) continue;
                $payload = json_decode((string) $command['payload_json'], true);
                if ($command['action'] === 'chatgpt_text' && ($payload['response_id'] ?? '') === $response['id']) {
                    BrowserState::finish((int) $command['id'], true, ['status' => 'codex_user_stopped']);
                }
            }
            self::eventUnlocked((string) $turn['task_id'], (string) $turn['id'], (string) $job['id'], (string) $surface['id'],
                'codex_user_stop_confirmed', ['page_missing' => $missing, 'page_invalid' => $invalid, 'unknown_host_results' => (int) $unknown]);
            return true;
        });
    }

    /**
     * Record one HTTP subscriber's lifecycle without cancelling generation or host work.
     * @param string $responseId Owned durable response, including a terminal tool-call round.
     * @param string $subscriberId Opaque identifier generated by ResponseTransport for this connection.
     * @param bool $attached True when subscribing, false when this connection closes.
     * @param bool $terminal True only after delivering a terminal; durable job state confirms it.
     * @return void Terminal disconnects clear subscribers but never authorize supersession.
     * @throws GatewayError If the subscriber identity or durable evidence cannot be stored.
     */
    public static function recordClientTransport(string $responseId, string $subscriberId, bool $attached, bool $terminal = false): void
    {
        if (preg_match('/\Ahttp_[a-f0-9]{24}\z/D', $subscriberId) !== 1) {
            throw new GatewayError('invalid_transport_subscriber', 'HTTP 订阅标识无效。', 500);
        }
        Schema::transaction(static function () use ($responseId, $subscriberId, $attached, $terminal): void {
            $db = SDB(null, 'medoo', 'sqlite');
            $job = $db->table('gateway_response_jobs')->where('id', $responseId)->find();
            if (!is_array($job) || !in_array($job['status'], ['queued', 'running', 'completed', 'failed'], true)) return;
            $isTerminal = in_array($job['status'], ['completed', 'failed'], true);
            if (($attached && $isTerminal) || ($terminal && !$isTerminal)) return;
            $turn = $db->table('gateway_task_turns')->where('id', $job['durable_turn_id'])->find();
            if (!is_array($turn)) return;
            if (!$isTerminal && (!in_array($turn['status'], self::ACTIVE_TURNS, true)
                || !self::ownsCurrentGeneration($job, $turn))) return;
            self::eventUnlocked((string)$job['task_id'], (string)$job['durable_turn_id'], $responseId,
                (string)$job['surface_id'], $attached ? 'response_client_attached' : 'response_client_detached',
                ['subscriber_id'=>$subscriberId, 'terminal'=>$isTerminal]);
        });
    }

    /**
     * Route a Codex conversation to its current task or open a fresh task after a verified retirement boundary.
     * The caller owns the response reservation transaction; browser sends and host effects never occur here.
     * Recovery-created tasks pin the saved mode or retain their own proven ordinary conversation binding.
     * Retired source-only checkpoints carry a digest-bound, transaction-local proof into summary reservation.
     * @param array $spec Normalized request carrying the client, owner, branch and readable context.
     * @return array{spec:array,prepared:bool} Routed request and whether its full context is already prepared.
     * @throws GatewayError If ownership conflicts or the durable routing/retirement evidence cannot be saved.
     */
    public static function routeConversationUnlocked(array $spec): array
    {
        $db = SDB(null, 'medoo', 'sqlite');
        $baseTaskId = 'task_' . substr((string) $spec['task_key'], 0, 24);
        $baseOwnerKey = (string) $spec['browser_owner_key'];
        $link = $db->table('gateway_task_conversations')->where('base_task_id', $baseTaskId)->find();
        if (is_array($link)) {
            $spec['task_key'] = (string) $link['current_task_key'];
            $spec['browser_owner_key'] = (string) $link['current_owner_key'];
            $spec['browser_lease_key'] = hash('sha256', $spec['browser_lease_key'] . '|task|' . $spec['task_key']);
        }
        $taskId = 'task_' . substr((string) $spec['task_key'], 0, 24);
        $task = $db->table('gateway_tasks')->where('id', $taskId)->find();
        $hasToolOutputs = (array) ($spec['tool_outputs'] ?? []) !== [];
        $incoming = json_decode((string) $spec['context_json'], true, 128, JSON_THROW_ON_ERROR);
        $messages = (array) ($incoming['messages'] ?? []);
        $lastMessage = $messages[array_key_last($messages)] ?? [];
        $newUserMessage = ($lastMessage['role'] ?? '') === 'user'
            && !ContextHistory::isOperationalUserMessage($lastMessage);
        $operation = (string) ($spec['operation_kind'] ?? 'response');
        if (is_array($task) && $newUserMessage && !$hasToolOutputs && $operation === 'response') {
            TaskWebChat::initializeMissingUnsentUnlocked($taskId);
        }
        if (is_array($task) && ($task['status'] === 'active'
                || ($task['status'] === 'needs_attention' && empty(GatewaySettings::internal()['steering_full_context'])))
            && $newUserMessage && !$hasToolOutputs && $operation === 'response') {
            $latestTurn = $db->table('gateway_task_turns')->where('task_id', $taskId)->order([['rowid', 'DESC']])->limit(1)->find();
            if (is_array($latestTurn) && $latestTurn['status'] === 'cancelled'
                && ($latestTurn['error_code'] ?? '') === 'CODEX_USER_STOPPED'
                && ($spec['turn_id'] ?? null) !== $latestTurn['client_turn_id']) {
                if ($task['status'] === 'needs_attention') {
                    throw new GatewayError('tool_outcome_unknown', '停止前的工具结果尚未确认；未全量重发或重新执行。', 409);
                }
                $parent = empty($spec['previous_response_id']) ? null
                    : $db->table('gateway_response_jobs')->where('id', $spec['previous_response_id'])->find();
                if ($parent === null || (is_array($parent) && $parent['task_id'] === $taskId
                    && $parent['durable_turn_id'] === $latestTurn['id'])) {
                    // A stopped response is readable history, never a live continuation handle.
                    // Reconstruct a delta from its exact stored context before clearing that handle.
                    $spec['previous_response_id'] = (string) $latestTurn['response_id'];
                    $spec = ContextHistory::prepareUnlocked($spec);
                    // The stopped capability cannot authorize an exact-prefix suffix. Keep the
                    // retained tab, but send the reconstructed complete context and its attachments.
                    $complete = json_decode((string) ($spec['full_context_json'] ?? $spec['context_json']), true, 128, JSON_THROW_ON_ERROR);
                    unset($complete['continuation']);
                    $spec['context_json'] = $spec['full_context_json'] = $spec['transport_context_json'] = ContextHistory::canonical($complete);
                    $spec['prompt'] = ContextHistory::plainPrompt($complete);
                    $spec['previous_response_id'] = null;
                    $spec['continuation_source_response_id'] = null;
                    $spec['rolling_source_response_id'] = null;
                    // The setting also governs a confirmed manual stop followed by a new native turn.
                    // Keep a separate source proof; the cancelled response is not a live tool handle.
                    $spec['stopped_source_response_id'] = (string) $latestTurn['response_id'];
                    return ['spec' => $spec, 'prepared' => true];
                }
            }
        }
        $detachedRestart = is_array($task) && self::retireDetachedTurnForNewHumanUnlocked($spec, $task, $incoming);
        if ($detachedRestart) $task = $db->table('gateway_tasks')->where('id', $taskId)->find();
        $lateReceiptsRecovered = is_array($task) && ToolBroker::settleLateHostReceiptsUnlocked($spec, $task);
        if ($lateReceiptsRecovered) {
            // Complete the canonical source/receipt history before turning the settled
            // native output into import data. A delta alone cannot seed the new page.
            $spec = ContextHistory::prepareUnlocked($spec);
            $complete = json_decode((string) ($spec['full_context_json'] ?? $spec['context_json']), true, 128, JSON_THROW_ON_ERROR);
            unset($complete['continuation']);
            $spec['context_json'] = $spec['full_context_json'] = $spec['transport_context_json'] = ContextHistory::canonical($complete);
            $spec['prompt'] = ContextHistory::plainPrompt($complete);
            $spec['tool_outputs'] = [];
            $spec['previous_response_id'] = null;
            $spec['steering_restart'] = false;
            $spec['continuation_source_response_id'] = null;
            $spec['rolling_source_response_id'] = null;
            $hasToolOutputs = false;
        }
        $expandedReceiptIds = [];
        $sourceWaitingSuperseded = is_array($task) && ContextCompaction::supersedeSourceWaitingUnlocked($taskId, $spec, $expandedReceiptIds);
        if ($sourceWaitingSuperseded) {
            // Exact completed receipts now belong to a fresh read-only history import.
            $spec['tools'] = [];
            $spec['tools_provided'] = true;
            $spec['tool_environment_provided'] = true;
            $spec['tool_outputs'] = [];
            $hasToolOutputs = false;
            $task = $db->table('gateway_tasks')->where('id', $taskId)->find();
        }
        $unpublishedDraftRecovered = false;
        $failedFreshRecovered = is_array($task)
            && self::abandonFailedFreshCompactionUnlocked($spec, $task, $incoming, true, $unpublishedDraftRecovered);
        if ($failedFreshRecovered) {
            // The entire native transcript was verified against the failed read-only import.
            // Its trailing receipts remain history; no old host capability is resumed.
            $spec['tool_outputs'] = [];
            $hasToolOutputs = false;
        }
        if (is_array($task) && $hasToolOutputs
            && self::terminalCompactionHistoryCompleteUnlocked($spec, $task, $messages)) {
            // Native pre-turn compaction runs before the next user message is recorded.
            // A stopped source's complete client transcript is read-only import data;
            // it cannot revive its revoked capability or confirm its unknown host result.
            $spec['tool_outputs'] = [];
            $hasToolOutputs = false;
        }
        $failedHandoffRecovered = false;
        if (is_array($task) && in_array($task['status'], ['active', 'needs_attention'], true)
            && in_array($operation, ['compact_v1', 'compact_v2'], true)) {
            // The old active-source receipt batch is already delivered. Once its missing
            // control handoff is proven terminal, these are historical context on the new
            // task, not a pending batch to execute again.
            $failedHandoffRecovered = ContextCompaction::abandonFailedRetainedHandoffUnlocked($taskId, $spec);
            if ($failedHandoffRecovered) {
                $spec['tool_outputs'] = [];
                $hasToolOutputs = false;
            }
        }
        $errorRebuild = is_array($task) && $newUserMessage && !$hasToolOutputs
            && ContextRecovery::retireRepeatedFailurePageUnlocked($spec, $task);
        if ($errorRebuild) $task = $db->table('gateway_tasks')->where('id', $taskId)->find();
        $precommitRetry = false;
        if (is_array($task) && $task['status'] === 'active' && !$hasToolOutputs
            && $operation === 'response' && $newUserMessage && !empty($spec['turn_id'])) {
            $priorTurn = $db->table('gateway_task_turns')->where('task_id', $taskId)
                ->where('client_turn_id', (string) $spec['turn_id'])->find();
            if (is_array($priorTurn) && $priorTurn['status'] === 'failed'
                && !hash_equals((string) $priorTurn['request_hash'], (string) $spec['request_hash'])) {
                $precommitRetry = ContextRecovery::canRetryFailedBeforeCommitUnlocked((string) $priorTurn['response_id']);
            }
        }
        if (is_array($link) && !$hasToolOutputs && !empty($spec['previous_response_id'])) {
            $parent = $db->table('gateway_response_jobs')->where('id', (string) $spec['previous_response_id'])->find();
            if (is_array($parent) && (string) ($parent['task_id'] ?? '') !== $taskId) {
                $parentTask = $db->table('gateway_tasks')->where('id', (string) ($parent['task_id'] ?? ''))->find();
                if (is_array($parentTask) && $parentTask['client_id'] === ($spec['client_id'] ?? 'responses')
                    && $parentTask['thread_id'] === ($spec['thread_id'] ?? null)
                    && $parentTask['branch_id'] === ($spec['branch_id'] ?? 'main')) {
                    $spec['previous_response_id'] = null;
                    if (count($messages) === 1 && $newUserMessage) $spec['input_is_delta'] = true;
                }
            }
        }
        if (!is_array($task) || $hasToolOutputs
            || (!in_array($task['status'], ['cancelled', 'needs_attention'], true) && !$precommitRetry && !$failedHandoffRecovered)) {
            return ['spec' => $spec, 'prepared' => false];
        }
        $newResponse = $operation === 'response' && ($newUserMessage || $lateReceiptsRecovered);
        // Codex can compact before submitting the next user turn. A checkpoint has no
        // host-tool authority, so it can start on a fresh task after cancellation.
        $newCheckpoint = in_array($operation, ['compact_v1', 'compact_v2'], true);
        if ($newCheckpoint && $task['status'] === 'needs_attention') {
            // A failed active-source handoff with no accepted summary and only delivered
            // host receipts is inert. Retire that checkpoint before routing the complete
            // readable history to a fresh task. Other uncertain generations keep the fence.
            if (!$failedHandoffRecovered) {
                $failedHandoffRecovered = ContextCompaction::abandonFailedRetainedHandoffUnlocked($taskId, $spec);
            }
            $unconfirmed = $db->table('gateway_context_checkpoints')->where('task_id', $taskId)
                ->where('state', 'generating')->find();
            if (is_array($unconfirmed)) $newCheckpoint = false;
        }
        if (!$newResponse && !$newCheckpoint) return ['spec' => $spec, 'prepared' => false];
        $lastFailed = null;
        if ($task['status'] === 'needs_attention') {
            $lastFailed = $db->table('gateway_response_jobs')->where('task_id', $taskId)->where('status', 'failed')
                ->order([['rowid', 'DESC']])->limit(1)->find();
            if (is_array($lastFailed) && (ContextRecovery::retireFailedStagingUnlocked((string) $lastFailed['id'])
                || ContextRecovery::releaseKnownNoProgressUnlocked((string) $lastFailed['id']))) {
                $task = $db->table('gateway_tasks')->where('id', $taskId)->find();
                if (is_array($task) && $task['status'] === 'active') return ['spec' => $spec, 'prepared' => false];
            }
        }
        // Reconstruct any delta while the old task is still in scope. The new page must
        // receive the full readable transcript; it cannot inherit the retired surface.
        $spec = ContextHistory::prepareUnlocked($spec);
        $context = json_decode((string) ($spec['full_context_json'] ?? $spec['context_json']), true, 128, JSON_THROW_ON_ERROR);
        $unknownHostResult = $task['status'] === 'cancelled'
            && is_array($db->table('gateway_tool_invocations')->where('task_id', $taskId)
                ->where('status', 'unknown')->find());
        if ($task['status'] === 'needs_attention' || $unknownHostResult || $detachedRestart) {
            $lastError = is_array($lastFailed) ? json_decode((string) ($lastFailed['error_json'] ?? ''), true) : null;
            $knownIncompleteFinal = in_array((string) ($lastError['error']['code'] ?? ''),
                ['web_task_incomplete', 'web_task_self_report_incomplete', 'web_task_unverified'], true);
            $warning = $lateReceiptsRecovered
                ? 'RCMAKER task boundary: the native host supplied the complete exact result batch after the prior transport wait timed out. These calls are settled evidence in the supplied history. The old capability remains revoked. Continue only the remaining authorized work without repeating those calls.'
                : (($failedHandoffRecovered || $unpublishedDraftRecovered) && !$unknownHostResult
                ? ContextCompaction::FAILED_CHECKPOINT_NOTICE
                : ($knownIncompleteFinal && !$unknownHostResult
                ? 'RCMAKER task boundary: the prior Web answer ended with unfinished work. Its delivered Codex host receipts are recorded; inspect the current project and continue only the remaining requirements. Do not treat the prior partial answer as completion.'
                : ($detachedRestart && !$unknownHostResult
                ? 'RCMAKER task boundary: the prior request was superseded after its client disconnected and a new human turn arrived. Its capability remains revoked. Preserve the supplied history and confirmed host receipts; do not repeat previously completed host work.'
                : 'RCMAKER task boundary: a prior browser or host operation has an unconfirmed result. Inspect current project state and durable receipts before repeating or changing it. Do not assume that operation succeeded or failed.')));
            if (!in_array($warning, (array) ($context['system'] ?? []), true)) $context['system'][] = $warning;
        }
        $spec['context_json'] = $spec['full_context_json'] = ContextHistory::canonical($context);
        $spec['prompt'] = $operation === 'response'
            ? ContextHistory::plainPrompt($context)
            : ContextCompaction::prompt($spec['context_json']);
        $spec['previous_response_id'] = null;
        unset($spec['transport_context_json'], $spec['continuation_source_response_id'], $spec['rolling_source_response_id']);
        $generation = (int) ($link['generation'] ?? 0) + 1;
        $ownerKey = hash('sha256', $baseOwnerKey . '|conversation|' . $generation . '|' . bin2hex(random_bytes(12)));
        $taskKey = hash('sha256', json_encode(['client' => $spec['client_id'] ?? 'responses',
            'owner' => $ownerKey, 'branch' => $spec['branch_id'] ?? 'main'], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $newTaskId = 'task_' . substr($taskKey, 0, 24);
        $now = time();
        if (!$db->table('gateway_tasks')->add(['id' => $newTaskId, 'client_id' => $spec['client_id'] ?? 'responses',
            'thread_id' => $spec['thread_id'] ?? null, 'branch_id' => $spec['branch_id'] ?? 'main',
            'owner_key' => $ownerKey, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now,
            'consecutive_gateway_errors'=>$errorRebuild ? 0 : (int)($task['consecutive_gateway_errors'] ?? 0),
            'gateway_rebuild_pending'=>$errorRebuild ? 0 : (int)($task['gateway_rebuild_pending'] ?? 0)])) {
            throw new GatewayError('storage_unavailable', '续聊任务无法创建。', 503);
        }
        // Recovery creates the task before reserveTurnUnlocked sees it. Pin the saved
        // chat preference here too; otherwise this new task looks like a legacy temporary task.
        TaskWebChat::createUnlocked($newTaskId, $taskId, $errorRebuild);
        $newChat = TaskWebChat::state($newTaskId);
        if ($newChat['mode'] === 'regular' && !empty($newChat['conversation_id'])) {
            $sourceJob = ResponseJob::latestForTaskUnlocked($taskId);
            if (is_array($sourceJob)) {
                $spec['regular_recovery_source_response_id'] = $sourceJob['id'];
                $spec['regular_recovery_source_task_id'] = $taskId;
                if ($sourceWaitingSuperseded && $newCheckpoint) {
                    // The same transaction proved every source receipt and revoked its
                    // capability. The saved chat holds that source even though no final
                    // assistant answer or successful checkpoint was ever produced.
                    $spec['regular_compaction_recovery'] = ['source_response_id' => $sourceJob['id'],
                        'context_sha256' => hash('sha256', (string) $spec['full_context_json']),
                        'expanded_receipt_ids'=>$expandedReceiptIds];
                }
            }
        }
        if ($precommitRetry || ($failedHandoffRecovered && $task['status'] === 'active') || $lateReceiptsRecovered) {
            $retired = $db->table('gateway_tasks')->where('id', $taskId)->where('status', $task['status'])->update([
                'status' => 'cancelled', 'cancelled_at' => $now, 'updated_at' => $now,
            ]);
            if (!$retired || $retired->rowCount() !== 1) throw new GatewayError('storage_unavailable', '未提交回合无法安全退休。', 503);
        }
        $values = ['current_task_id' => $newTaskId, 'current_task_key' => $taskKey,
            'current_owner_key' => $ownerKey, 'generation' => $generation, 'updated_at' => $now];
        $written = is_array($link)
            ? $db->table('gateway_task_conversations')->where('base_task_id', $baseTaskId)->update($values)
            : $db->table('gateway_task_conversations')->add(['base_task_id' => $baseTaskId] + $values);
        if (!$written) throw new GatewayError('storage_unavailable', '续聊任务映射无法保存。', 503);
        $restartReason = $errorRebuild ? 'consecutive_gateway_errors' : ($detachedRestart ? 'client_detached_new_turn' : ($lateReceiptsRecovered ? 'late_native_receipts_confirmed' : ($sourceWaitingSuperseded ? 'source_waiting_compaction_superseded' : ($precommitRetry ? 'precommit_retry'
            : ($failedHandoffRecovered && $task['status'] === 'active' ? 'unsent_model_handoff_failed' : $task['status'])))));
        self::eventUnlocked($taskId, null, null, null, 'task_conversation_restarted',
            ['new_task_id' => $newTaskId, 'reason' => $restartReason, 'generation' => $generation]);
        self::eventUnlocked($newTaskId, null, null, null, 'task_restarted_from',
            ['old_task_id' => $taskId, 'reason' => $restartReason, 'generation' => $generation]);
        $spec['task_key'] = $taskKey;
        $spec['browser_owner_key'] = $ownerKey;
        $spec['browser_lease_key'] = hash('sha256', $spec['browser_lease_key'] . '|task|' . $taskKey);
        return ['spec' => $spec, 'prepared' => true];
    }

    /**
     * An explicit new human turn can supersede a disconnected request after full-prefix proof.
     * The caller owns the reservation transaction; disconnect alone never enters this boundary.
     * @param array $spec Current native full request; only an owned previous handle is cleared.
     * @param array $task Current conversation task selected by the existing owner mapping.
     * @param array $incoming Complete readable history, not a delta or tool/steering continuation.
     * @return bool Whether the old capability, turn and surface were atomically retired.
     * @throws GatewayError If retirement or response-terminal evidence cannot be persisted.
     */
    private static function retireDetachedTurnForNewHumanUnlocked(array &$spec, array $task, array $incoming): bool
    {
        $messages = $incoming['messages'] ?? null;
        $last = is_array($messages) ? ($messages[array_key_last($messages)] ?? []) : [];
        if ($task['status'] !== 'active' || ($spec['operation_kind'] ?? 'response') !== 'response'
            || !is_array($messages) || $messages === [] || ($last['role'] ?? '') !== 'user'
            || ContextHistory::isOperationalUserMessage($last)
            || in_array($last['origin'] ?? '', ['codex_skill', 'codex_delegation'], true)
            || empty($spec['thread_id']) || empty($spec['turn_id']) || !empty($spec['input_is_delta'])
            || (array)($spec['tool_outputs'] ?? []) !== [] || (array)($spec['late_tool_outputs'] ?? []) !== []
            || !empty($spec['steering_restart']) || (array)($spec['steering_messages'] ?? []) !== []
            || !empty($spec['mailbox_context_restart']) || !empty($spec['mailbox_context_join'])
            || !empty($spec['operational_context_source_response_id']) || isset($incoming['continuation'])
            || $task['client_id'] !== ($spec['client_id'] ?? 'responses')
            || $task['thread_id'] !== ($spec['thread_id'] ?? null) || $task['branch_id'] !== ($spec['branch_id'] ?? 'main')
            || $task['owner_key'] !== ($spec['browser_owner_key'] ?? null)) return false;
        if (AttachmentStore::checkpointReferenceIds([$last]) === []
            && trim(ContextHistory::text($last['content'] ?? '')) === '') return false;
        $db = SDB(null, 'medoo', 'sqlite');
        $taskId = (string)$task['id'];
        if (!empty($spec['previous_response_id'])) {
            $previous = $db->table('gateway_response_jobs')->where('id', $spec['previous_response_id'])->find();
            if (!is_array($previous) || $previous['task_id'] !== $taskId) return false;
        }
        $turns = $db->table('gateway_task_turns')->where('task_id', $taskId)
            ->where('status', 'IN', self::ACTIVE_TURNS)->limit(2)->select();
        if (!is_array($turns) || count($turns) !== 1) return false;
        $turn = $turns[0];
        if (empty($turn['client_turn_id']) || $turn['client_turn_id'] === $spec['turn_id']
            || ($task['current_epoch_id'] ?? null) !== $turn['epoch_id']) return false;
        $job = $db->table('gateway_response_jobs')->where('id', $turn['response_id'])->find();
        if (!is_array($job) || $job['task_id'] !== $taskId || ($job['operation_kind'] ?? '') !== 'response'
            || !in_array($job['status'], ['queued', 'running'], true) || !self::ownsCurrentGeneration($job, $turn)
            || ($job['turn_id'] ?? null) !== $turn['client_turn_id']) return false;
        // Codex keeps its native turn ID when steering after automatic compaction.
        // A detached HTTP stream does not turn its verified postcompact descendant
        // into a new human turn. Leave it to the existing steering/settings path.
        if (str_starts_with((string) $turn['client_turn_id'], 'postcompact_')
            && self::cancellationNativeTurn(['job' => $job, 'turn' => $turn]) === $spec['turn_id']) return false;
        $source = json_decode((string)$job['context_json'], true);
        if (!is_array($source) || !is_array($source['messages'] ?? null)
            || count($messages) <= count($source['messages'])
            || !ContextHistory::matchesCompactionSource($source, $incoming, !empty($spec['native_responses_lite']))) return false;
        if (!self::clientTransportDetachedUnlocked($job)) return false;
        ToolBroker::revokeTaskUnlocked($taskId, 'client_detached_new_turn');
        $cancelled = self::cancelTaskUnlocked($taskId);
        if (!in_array($job['id'], $cancelled['response_ids'], true)) {
            throw new GatewayError('recovery_conflict', '断开回合的退休所有权发生变化。', 409);
        }
        ResponseJob::failSupersededUnlocked((string)$job['id'], '客户端已断开；新的用户回合已替代原请求。');
        self::eventUnlocked($taskId, (string)$turn['id'], (string)$job['id'], (string)$turn['surface_id'],
            'detached_response_superseded', ['client_turn_id'=>$turn['client_turn_id'], 'new_client_turn_id'=>$spec['turn_id']]);
        $spec['previous_response_id'] = null;
        return true;
    }

    /**
     * Prove all recorded subscribers of an already scoped response detached before its terminal.
     * The caller owns the transaction and separately validates the task, generation and request.
     * @param array $job Exact owned source with task_id and id, not an incoming client handle.
     * @return bool False for live subscribers, missing/malformed evidence or a truncated event history.
     */
    public static function clientTransportDetachedUnlocked(array $job): bool
    {
        if (empty($job['task_id']) || empty($job['id'])) return false;
        $events = SDB(null, 'medoo', 'sqlite')->table('gateway_task_events')
            ->where('task_id', $job['task_id'])->where('response_id', $job['id'])
            ->where('event_type', 'IN', ['response_client_attached', 'response_client_detached'])
            ->order([['id','DESC']])->limit(self::CLIENT_TRANSPORT_EVENT_LIMIT + 1)->select();
        // The extra row detects truncation. Never infer "all disconnected" from a tail
        // that could omit an earlier subscriber which is still connected to this response.
        if (!is_array($events) || $events === [] || count($events) > self::CLIENT_TRANSPORT_EVENT_LIMIT) return false;
        $subscribers = [];
        $nonterminalDetached = false;
        foreach ($events as $event) {
            $data = json_decode((string)$event['data_json'], true);
            $subscriber = $data['subscriber_id'] ?? null;
            if (!is_string($subscriber) || preg_match('/\Ahttp_[a-f0-9]{24}\z/D', $subscriber) !== 1
                || !is_bool($data['terminal'] ?? null)) return false;
            if (isset($subscribers[$subscriber])) continue;
            $subscribers[$subscriber] = true;
            if ($event['event_type'] === 'response_client_attached') return false;
            $nonterminalDetached = $nonterminalDetached || !$data['terminal'];
        }
        return $nonterminalDetached;
    }

    /**
     * Retire a fresh read-only compact timeout or proven unpublished-draft failure when a request supplies its
     * complete original history. This is task routing, never a resend or host-result recovery.
     * Exact request replays remain behind ResponseJob's existing identity fence. The caller owns
     * the reservation transaction; the old job and turn retain their failed/unknown terminal.
     * @param array $spec New full native v2 compact request, with only verified historical outputs demoted.
     * @param array $task Current task selected by the conversation mapping.
     * @param array $incoming Parsed full context carried by this request.
     * @param bool $retire False checks the proof without mutations for an exact-replay recovery identity.
     * @param bool|null $unpublishedDraftRecovered Reports a verified private-draft retirement; timeouts retain their uncertainty notice.
     * @return bool Whether the sole failed fresh checkpoint can be retired (and was, when requested).
     * @throws GatewayError If checkpoint retirement or its durable evidence cannot be saved.
     */
    private static function abandonFailedFreshCompactionUnlocked(array &$spec, array $task, array $incoming,
        bool $retire = true, ?bool &$unpublishedDraftRecovered = null): bool
    {
        if (($spec['operation_kind'] ?? '') !== 'compact_v2' || $task['status'] !== 'needs_attention'
            || !empty($spec['input_is_delta']) || !empty($spec['previous_response_id'])
            || !empty($spec['continuation_source_response_id']) || !empty($spec['rolling_source_response_id'])
            || (array) ($spec['tools'] ?? []) !== [] || ($spec['tool_choice'] ?? null) !== 'none'
            || $task['client_id'] !== ($spec['client_id'] ?? 'responses')
            || $task['thread_id'] !== ($spec['thread_id'] ?? null)
            || $task['branch_id'] !== ($spec['branch_id'] ?? 'main')
            || $task['owner_key'] !== ($spec['browser_owner_key'] ?? null)
            || isset($incoming['continuation'])) return false;
        $db = SDB(null, 'medoo', 'sqlite');
        $taskId = (string) $task['id'];
        $checkpoints = $db->table('gateway_context_checkpoints')->where('task_id', $taskId)
            ->where('state', 'generating')->select();
        if (!is_array($checkpoints) || count($checkpoints) !== 1) return false;
        $checkpoint = $checkpoints[0];
        if ($checkpoint['protocol'] !== 'compact_v2' || !str_starts_with((string) $checkpoint['source_epoch_id'], 'import_')
            || !empty($checkpoint['source_response_id'])
            || $checkpoint['summary'] !== null || $checkpoint['response_json'] !== null
            || $checkpoint['committed_at'] !== null || $checkpoint['consumed_at'] !== null) return false;
        $job = $db->table('gateway_response_jobs')->where('id', (string) $checkpoint['response_id'])->find();
        $latest = ResponseJob::latestForTaskUnlocked($taskId);
        if (!is_array($job) || !is_array($latest) || $latest['id'] !== $job['id']
            || $job['task_id'] !== $taskId || $job['compact_id'] !== $checkpoint['id']
            || $job['operation_kind'] !== 'compact_v2' || $job['round_kind'] !== 'text'
            || $job['status'] !== 'failed' || $job['terminal_type'] !== 'response.failed'
            || $job['accepted_at'] === null || $job['submit_intent_at'] === null || $job['finished_at'] === null
            || !empty($job['previous_response_id']) || !empty($job['continuation_source_response_id'])
            || !empty($job['rolling_source_response_id']) || !empty($job['mcp_binding_id'])
            || $job['model'] !== $spec['model'] || $job['reasoning_effort'] !== ($spec['reasoning_effort'] ?? null)
            || $job['compaction_epoch'] !== ($spec['compaction_epoch'] ?? 'base')
            || !hash_equals((string) $checkpoint['request_hash'], (string) $job['request_hash'])
            || hash_equals((string) $job['request_hash'], (string) $spec['request_hash'])) return false;
        $error = json_decode((string) $job['error_json'], true);
        $failureCode = (string)($error['error']['code'] ?? '');
        $legacyDraft = ContextCompaction::unpublishedDraftFailure($job);
        $environment = json_decode((string) $job['tool_environment_json'], true);
        if ((!$legacyDraft && ($failureCode !== 'CHATGPT_RESPONSE_TIMEOUT'
                || (int)$job['output_started'] !== 0 || (string)$job['output_text'] !== ''))
            || !is_array($environment) || !is_array($environment['tools'] ?? null)
            || $environment['tools'] !== []) return false;
        $turn = $db->table('gateway_task_turns')->where('id', (string) $job['durable_turn_id'])->find();
        $epoch = $db->table('gateway_task_epochs')->where('id', (string) $job['epoch_id'])->find();
        $surface = $db->table('gateway_browser_surfaces')->where('id', (string) $job['surface_id'])->find();
        if (!is_array($turn) || !is_array($epoch) || !is_array($surface)
            || !self::ownsCurrentGeneration($job, $turn)
            || $turn['task_id'] !== $taskId || $turn['epoch_id'] !== $job['epoch_id']
            || $turn['status'] !== 'needs_attention' || $turn['error_code'] !== $failureCode
            || $turn['submit_intent_at'] === null || $turn['submitted_at'] === null || $turn['committed_at'] !== null
            || $epoch['task_id'] !== $taskId || $epoch['status'] !== 'needs_attention'
            || $task['current_epoch_id'] !== $epoch['id']
            || $surface['task_id'] !== $taskId || $surface['epoch_id'] !== $epoch['id']
            || !in_array($surface['status'], ['needs_attention', 'retired'], true)
            || empty($job['submitted_document_nonce']) || $job['submitted_document_nonce'] !== $surface['document_nonce']
            || $turn['owner_incarnation'] !== $surface['owner_incarnation']
            || (int) $turn['fencing_version'] !== (int) $surface['fencing_version']) return false;
        foreach (['gateway_mcp_bindings', 'gateway_tool_invocations', 'gateway_compaction_handoffs'] as $table) {
            $count = $db->table($table)->where('task_id', $taskId)->count();
            // Activities require a binding by foreign key: zero bindings also proves zero task activities.
            if (!is_numeric($count) || (int) $count !== 0) return false;
        }
        $activeJobs = $db->table('gateway_response_jobs')->where('task_id', $taskId)
            ->where('status', 'IN', ['queued', 'running'])->count();
        $activeTurns = $db->table('gateway_task_turns')->where('task_id', $taskId)
            ->where('status', 'IN', self::ACTIVE_TURNS)->count();
        $otherAttention = $db->table('gateway_task_turns')->where('task_id', $taskId)
            ->where('id', '<>', (string) $turn['id'])->where('status', 'needs_attention')->count();
        if (!is_numeric($activeJobs) || (int) $activeJobs !== 0
            || !is_numeric($activeTurns) || (int) $activeTurns !== 0
            || !is_numeric($otherAttention) || (int) $otherAttention !== 0) return false;
        $original = json_decode((string) $job['context_json'], true);
        $oldMessages = $original['messages'] ?? null;
        $newMessages = $incoming['messages'] ?? null;
        if (!is_array($original) || ($original['version'] ?? null) !== ($incoming['version'] ?? null)
            || !is_array($oldMessages) || $oldMessages === [] || !array_is_list($oldMessages)
            || !is_array($newMessages) || !array_is_list($newMessages) || count($newMessages) < count($oldMessages)
            || ContextHistory::nativeToolHistoryCanonical($oldMessages)
                !== ContextHistory::nativeToolHistoryCanonical(array_slice($newMessages, 0, count($oldMessages)))) return false;
        foreach (array_slice($newMessages, count($oldMessages)) as $message) {
            if (($message['role'] ?? '') !== 'user') return false;
        }
        $outputs = (array) ($spec['tool_outputs'] ?? []);
        $receipts = $outputs === [] ? ['messages' => []] : ContextHistory::compile($outputs, '');
        $seenReceipts = [];
        foreach ($receipts['messages'] as $receipt) {
            $callId = (string) ($receipt['call_id'] ?? '');
            if ($callId === '' || isset($seenReceipts[$callId])) return false;
            $seenReceipts[$callId] = true;
            $found = false;
            foreach ($oldMessages as $message) {
                if (ContextHistory::nativeToolHistoryCanonical([$receipt])
                    === ContextHistory::nativeToolHistoryCanonical([$message])) { $found = true; break; }
            }
            if (!$found) return false;
        }
        // Gateway boundary warnings are not returned to the native client. Reattach only exact
        // warnings from the durable source; every caller-supplied instruction must still match.
        $oldSystem = $original['system'] ?? null;
        $newSystem = $incoming['system'] ?? null;
        if (!is_array($oldSystem) || !is_array($newSystem)) return false;
        foreach ($oldSystem as $instruction) {
            if (is_string($instruction) && str_starts_with($instruction, 'RCMAKER task boundary: ')
                && !in_array($instruction, $newSystem, true)) $newSystem[] = $instruction;
        }
        if (ContextHistory::canonical($oldSystem) !== ContextHistory::canonical($newSystem)) return false;
        $incoming['system'] = $newSystem;
        if (!$retire) return true;
        $updated = $db->table('gateway_context_checkpoints')->where('id', (string) $checkpoint['id'])
            ->where('response_id', (string) $job['id'])->where('state', 'generating')->update(['state' => 'abandoned']);
        if (!$updated || $updated->rowCount() !== 1) {
            throw new GatewayError('compaction_conflict', '失败只读摘要检查点恢复所有权发生变化。', 409);
        }
        if ($surface['status'] === 'needs_attention') {
            $retired = $db->table('gateway_browser_surfaces')->where('id', (string) $surface['id'])
                ->where('status', 'needs_attention')->where('owner_incarnation', (string) $surface['owner_incarnation'])
                ->where('fencing_version', (int) $surface['fencing_version'])->update([
                    'status' => 'retired', 'session_id' => null, 'retired_at' => time(), 'updated_at' => time(),
                ]);
            if (!$retired || $retired->rowCount() !== 1) {
                throw new GatewayError('storage_unavailable', '失败只读摘要页面无法退休。', 503);
            }
        }
        self::eventUnlocked($taskId, (string) $turn['id'], (string) $job['id'], (string) $surface['id'],
            'failed_fresh_compaction_abandoned', ['checkpoint_id' => $checkpoint['id'],
                'source_context_sha256' => hash('sha256', (string) $job['context_json']),
                'original_messages' => count($oldMessages), 'host_capabilities' => 0, 'failure_code' => $failureCode]);
        $spec['context_json'] = $spec['full_context_json'] = ContextHistory::canonical($incoming);
        $unpublishedDraftRecovered = $legacyDraft;
        return true;
    }

    /**
     * Check the same fresh-checkpoint retirement proof before crossing an exact HTTP replay fence.
     * The eventual reservation revalidates every fact under its write transaction.
     * @param array $spec Full request with a deterministic new recovery identity and routed owner.
     * @param string $taskId Current task selected by the conversation link.
     * @return bool Whether the complete source is recoverable without publishing or changing a row.
     * @throws GatewayError On storage failure.
     */
    public static function canRecoverFailedFreshCompaction(array $spec, string $taskId): bool
    {
        return Schema::transaction(static function () use ($spec, $taskId): bool {
            $task = SDB(null, 'medoo', 'sqlite')->table('gateway_tasks')->where('id', $taskId)->find();
            $incoming = json_decode((string)($spec['full_context_json'] ?? $spec['context_json']), true);
            return is_array($task) && is_array($incoming)
                && self::abandonFailedFreshCompactionUnlocked($spec, $task, $incoming, false);
        });
    }

    /**
     * Verify an inert terminal source before importing its full native v2 history into a new task.
     * This read-only check never accepts receipts or changes invocation/binding state. Active
     * sources and unresolved checkpoint generations retain their existing compaction fences.
     * @param array $spec Normalized read-only compact request on the resolved conversation task.
     * @param array $task Existing task selected by the durable conversation mapping.
     * @param array $messages Complete compiled client history, including the trailing tool batch.
     * @return bool Whether the trailing outputs may be treated only as historical import data.
     */
    private static function terminalCompactionHistoryCompleteUnlocked(array $spec, array $task, array $messages): bool
    {
        if (($spec['operation_kind'] ?? '') !== 'compact_v2'
            || !in_array($task['status'], ['cancelled', 'needs_attention'], true)
            || !empty($spec['input_is_delta']) || (array)($spec['tools'] ?? []) !== []
            || ($spec['tool_choice'] ?? null) !== 'none'
            || $task['client_id'] !== ($spec['client_id'] ?? 'responses')
            || $task['thread_id'] !== ($spec['thread_id'] ?? null)
            || $task['branch_id'] !== ($spec['branch_id'] ?? 'main')) return false;
        $db = SDB(null, 'medoo', 'sqlite');
        $taskId = (string)$task['id'];
        foreach (['gateway_response_jobs'=>['queued', 'running'],
            'gateway_task_turns'=>self::ACTIVE_TURNS,
            'gateway_context_checkpoints'=>['generating']] as $table=>$states) {
            $field = $table === 'gateway_context_checkpoints' ? 'state' : 'status';
            $count = $db->table($table)->where('task_id', $taskId)->where($field, 'IN', $states)->count();
            if (!is_numeric($count) || (int)$count !== 0) return false;
        }
        $bindings = $db->table('gateway_mcp_bindings')->where('task_id', $taskId)->select();
        $calls = $db->table('gateway_tool_invocations')->where('task_id', $taskId)->select();
        if (!is_array($bindings) || !is_array($calls)) return false;
        foreach ($bindings as $binding) {
            if (!in_array($binding['status'], ['revoked', 'completed'], true)) return false;
            $active = $db->table('gateway_mcp_activities')->where('binding_id', $binding['id'])
                ->where('status', 'active')->count();
            if (!is_numeric($active) || (int)$active !== 0) return false;
        }
        $byCallId = [];
        foreach ($calls as $call) {
            if (in_array($call['status'], ['registered', 'delivered'], true)) return false;
            $byCallId[(string)$call['call_id']] = $call;
        }
        $outputs = (array)($spec['tool_outputs'] ?? []);
        $sourceId = null;
        $received = [];
        foreach ($outputs as $output) {
            $callId = (string)($output['call_id'] ?? '');
            $call = $byCallId[$callId] ?? null;
            if (!is_array($call) || isset($received[$callId])
                || !in_array($call['status'], ['unknown', 'completed'], true)
                || empty($call['response_id']) || empty($call['binding_id'])) return false;
            if ($sourceId !== null && $sourceId !== (string)$call['response_id']) return false;
            $sourceId = (string)$call['response_id'];
            $received[$callId] = $call;
            if ($call['result_json'] !== null || $call['result_hash'] !== null || $call['status'] === 'completed') {
                $result = json_decode((string)($call['result_json'] ?? ''), true);
                if (!is_array($result) || !is_string($result['output'] ?? null)
                    || $result['output'] !== ($output['output'] ?? null)
                    || !is_string($call['result_hash'])
                    || !hash_equals($call['result_hash'], ContextHistory::toolReceiptHash($output['output']))) return false;
            }
        }
        if ($sourceId === null) return false;
        $source = $db->table('gateway_response_jobs')->where('id', $sourceId)->find();
        if (!is_array($source) || $source['task_id'] !== $taskId
            || $source['status'] !== 'completed' || $source['round_kind'] !== 'tool_call'
            || $source['operation_kind'] !== 'response' || empty($source['mcp_binding_id'])
            || $source['model'] !== $spec['model']
            || $source['reasoning_effort'] !== ($spec['reasoning_effort'] ?? null)
            || (!empty($spec['previous_response_id']) && $spec['previous_response_id'] !== $sourceId)) return false;
        $sourceBinding = null;
        foreach ($bindings as $binding) {
            if ($binding['id'] === $source['mcp_binding_id']) $sourceBinding = $binding;
        }
        if (!is_array($sourceBinding) || $sourceBinding['current_response_id'] !== $sourceId
            || $sourceBinding['turn_id'] !== $source['durable_turn_id']) return false;
        $batch = array_filter($calls, static fn(array $call): bool => $call['response_id'] === $sourceId);
        if (count($batch) !== count($received)) return false;
        foreach ($batch as $call) {
            if (!isset($received[$call['call_id']]) || $call['binding_id'] !== $sourceBinding['id']
                || $call['turn_id'] !== $source['durable_turn_id']) return false;
        }
        $prior = json_decode((string)$source['context_json'], true, 128, JSON_THROW_ON_ERROR);
        $incoming = json_decode((string)$spec['context_json'], true, 128, JSON_THROW_ON_ERROR);
        $response = json_decode((string)$source['response_json'], true, 128, JSON_THROW_ON_ERROR);
        $round = ContextHistory::compile((array)($response['output'] ?? []), '');
        $callTypes = [];
        foreach ($round['messages'] as $item) {
            $type = $item['type'] ?? '';
            if (in_array($type, ['function_call', 'custom_tool_call', 'tool_search_call'], true)) {
                $callTypes[(string)($item['call_id'] ?? '')] = match ($type) {
                    'custom_tool_call'=>'custom_tool_call_output', 'tool_search_call'=>'tool_search_output',
                    default=>'function_call_output',
                };
            }
        }
        if (count($callTypes) !== count($received)) return false;
        foreach ($outputs as $output) {
            if (($callTypes[$output['call_id']] ?? null) !== ($output['type'] ?? null)) return false;
        }
        $nativeOutputs = $outputs;
        foreach ($nativeOutputs as &$output) {
            if (($output['type'] ?? '') !== 'tool_search_output') continue;
            $search = json_decode((string)$output['output'], true);
            if (!is_array($search) || !is_array($search['tools'] ?? null)) return false;
            $output = ['type'=>'tool_search_output', 'call_id'=>$output['call_id'],
                'status'=>$search['status'] ?? 'completed', 'tools'=>$search['tools']];
        }
        unset($output);
        $receiptContext = ContextHistory::compile($nativeOutputs, '');
        $expected = array_merge((array)($prior['messages'] ?? []), $round['messages'], $receiptContext['messages']);
        return !empty($prior['messages']) && ContextHistory::matchesCompactionSource(
            ['system'=>(array)($prior['system'] ?? []), 'messages'=>$expected], $incoming,
            (bool)($spec['native_responses_lite'] ?? false), true);
    }

    /**
     * Reserve one logical turn and its task surface inside the caller's existing transaction.
     * @return array{reused:bool,response_id:?string,task_id:string,epoch_id:string,turn_id:string,surface_id:string,generation_id:string,round_no:int}
     */
    public static function reserveTurnUnlocked(array $spec, string $responseId): array
    {
        $db = SDB(null, 'medoo', 'sqlite');
        $now = time();
        $taskId = 'task_' . substr((string) $spec['task_key'], 0, 24);
        $clientId = (string) ($spec['client_id'] ?? 'responses');
        $branchId = (string) ($spec['branch_id'] ?? 'main');
        $ownerKey = (string) $spec['browser_owner_key'];
        $threadId = $spec['thread_id'] ?? null;

        $task = $db->table('gateway_tasks')->where('id', $taskId)->find();
        if (!is_array($task)) {
            if (!$db->table('gateway_tasks')->add([
                'id' => $taskId,
                'client_id' => $clientId,
                'thread_id' => $threadId,
                'branch_id' => $branchId,
                'owner_key' => $ownerKey,
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ])) {
                throw new GatewayError('storage_unavailable', '任务记录无法创建。', 503);
            }
            TaskWebChat::createUnlocked($taskId);
            $task = $db->table('gateway_tasks')->where('id', $taskId)->find();
        }
        if (!is_array($task)
            || (string) $task['client_id'] !== $clientId
            || (string) $task['branch_id'] !== $branchId
            || (string) $task['owner_key'] !== $ownerKey) {
            throw new GatewayError('task_identity_conflict', '任务身份与已有记录冲突。', 409);
        }
        if ((string) $task['status'] === 'cancelled') {
            throw new GatewayError('task_cancelled', '该任务已经取消，不能继续创建新回合。', 409);
        }
        if ((string) $task['status'] === 'needs_attention') {
            $last = $db->table('gateway_response_jobs')->where('task_id', $taskId)->where('status', 'failed')->order([['rowid','DESC']])->limit(1)->find();
            if (is_array($last) && (ContextRecovery::retireFailedStagingUnlocked((string) $last['id'])
                || ContextRecovery::releaseKnownNoProgressUnlocked((string) $last['id']))) {
                $task = $db->table('gateway_tasks')->where('id', $taskId)->find();
            }
        }
        if ((string) $task['status'] === 'needs_attention') {
            throw new GatewayError('task_needs_attention', '该任务存在需要确认的未知执行结果，请先在任务时间线处理。', 409);
        }

        $previous = $spec['previous_response_id'] ?? null;
        $prior = null;
        if (is_string($previous) && $previous !== '') {
            $prior = $db->table('gateway_response_jobs')->where('id', $previous)->find();
            if (!is_array($prior)) throw new GatewayError('previous_response_not_found', 'previous_response_id 不存在或已清理。', 400);
            if ((string) ($prior['task_id'] ?? '') !== $taskId) {
                throw new GatewayError('previous_response_scope_mismatch', 'previous_response_id 不属于当前任务/分支。', 409);
            }
            $activeSteeringSource = (!empty($spec['steering_restart']) || !empty($spec['mailbox_context_restart']))
                && (array) ($spec['tool_outputs'] ?? []) === []
                && in_array((string) ($prior['status'] ?? ''), ['queued', 'running'], true);
            if ((string) ($prior['status'] ?? '') !== 'completed' && !$activeSteeringSource) {
                throw new GatewayError('previous_response_not_completed', 'previous_response_id 尚未完成，不能作为续接点。', 409);
            }
        }

        $clientTurnId = $spec['turn_id'] ?? null;
        if (is_string($clientTurnId) && $clientTurnId !== '') {
            $existingTurn = $db->table('gateway_task_turns')
                ->where('task_id', $taskId)->where('client_turn_id', $clientTurnId)->find();
            if (is_array($existingTurn)) {
                if ($previous === null || $previous === '') {
                    if (!hash_equals((string) $existingTurn['request_hash'], (string) $spec['request_hash'])) {
                        throw new GatewayError('turn_identity_conflict', '同一客户端 turn_id 已用于不同请求。', 409);
                    }
                    $existingResponse = (string) ($existingTurn['response_id'] ?? '');
                    if ($existingResponse === '') throw new GatewayError('task_ledger_incomplete', '已有回合缺少 Response 绑定。', 503);
                    $existingJob = $db->table('gateway_response_jobs')->where('id', $existingResponse)->find();
                    return [
                        'reused' => true,
                        'response_id' => $existingResponse,
                        'task_id' => $taskId,
                        'epoch_id' => (string) $existingTurn['epoch_id'],
                        'turn_id' => (string) $existingTurn['id'],
                        'surface_id' => (string) $existingTurn['surface_id'],
                        'generation_id' => (string) $existingTurn['generation_id'],
                        'round_no' => is_array($existingJob) ? (int) ($existingJob['round_no'] ?? 1) : 1,
                    ];
                }
                if (!is_array($prior) || (string) ($prior['durable_turn_id'] ?? '') !== (string) $existingTurn['id']) {
                    throw new GatewayError('previous_response_turn_mismatch', '同一 turn_id 的下一 Round 必须续接该回合自己的 previous_response_id。', 409);
                }
                $toolContinuation = is_array($prior) && (string) ($prior['round_kind'] ?? '') === 'tool_call';
                $steeringRestart = !empty($spec['steering_restart'])
                    && ($toolContinuation || (array) ($spec['tool_outputs'] ?? []) === []);
                $mailboxRestart = !empty($spec['mailbox_context_restart']) && !$toolContinuation
                    && (array) ($spec['tool_outputs'] ?? []) === [];
                if (($steeringRestart || $mailboxRestart) && (string) ($existingTurn['response_id'] ?? '') !== $previous) {
                    throw new GatewayError('steering_source_superseded', '中途指令的源回合已被较新的回合取代。', 409);
                }
                $allowedTurnStates = $mailboxRestart ? [...self::ACTIVE_TURNS, 'committed'] : ($steeringRestart ? self::ACTIVE_TURNS
                    : ($toolContinuation ? ['submitted', 'running'] : ['committed', 'failed']));
                if (!in_array((string) $existingTurn['status'], $allowedTurnStates, true)) {
                    throw new GatewayError('turn_round_busy', '上一 Response Round 尚未进入可续接状态。', 409);
                }
                $surface = $db->table('gateway_browser_surfaces')->where('id', (string) $existingTurn['surface_id'])->find();
                if (!is_array($surface) || (string) $surface['status'] === 'retired') {
                    throw new GatewayError('task_surface_missing', '续接回合的任务页面已经退休。', 409);
                }
                $roundNo = (int) ($db->table('gateway_response_jobs')->where('durable_turn_id', (string) $existingTurn['id'])->agg('max', 'round_no') ?: 0) + 1;
                $generationId = 'gen_' . bin2hex(random_bytes(12));
                $nextValues = [
                    'request_hash' => (string) $spec['request_hash'],
                    'generation_id' => $generationId,
                    'response_id' => $responseId,
                    // A real tool receipt starts another model step. Bound inactivity per step,
                    // not total task duration; transport polling alone never extends this lease.
                    'deadline_at' => $now + (int) ($spec['budget']['response_timeout_seconds'] ?? 600),
                    'updated_at' => $now,
                ];
                if (!$toolContinuation || !empty($spec['steering_delta'])) {
                    $nextValues += [
                        'status' => 'queued',
                        'owner_incarnation' => (string) $surface['owner_incarnation'],
                        'fencing_version' => (int) $surface['fencing_version'],
                        'deadline_at' => $now + (int) ($spec['budget']['response_timeout_seconds'] ?? 600),
                        'submit_intent_at' => null,
                        'submitted_at' => null,
                        'committed_at' => null,
                        'retired_at' => null,
                        'error_code' => null,
                    ];
                }
                $updated = $db->table('gateway_task_turns')->where('id', $existingTurn['id'])->where('status', (string) $existingTurn['status'])->update($nextValues);
                if (!$updated || $updated->rowCount() !== 1) throw new GatewayError('storage_unavailable', '下一 Response Round 无法登记。', 503);
                if ($steeringRestart && !empty($spec['steering_delta']) && empty($spec['receipt_model_switch'])) {
                    // Fence the old observer immediately, retaining only its physical document.
                    $fence = (int) $surface['fencing_version'] + 1;
                    $claimed = $db->table('gateway_browser_surfaces')->where('id', $surface['id'])
                        ->where('fencing_version', (int) $surface['fencing_version'])->update([
                            'status' => 'ready', 'session_id' => null, 'owner_incarnation' => 'unclaimed',
                            'fencing_version' => $fence, 'updated_at' => $now,
                        ]);
                    if (!$claimed || $claimed->rowCount() !== 1) throw new GatewayError('surface_fence_lost', '插话原页所有权已变化。', 409);
                    $updated = $db->table('gateway_task_turns')->where('id', $existingTurn['id'])->where('response_id', $responseId)
                        ->update(['owner_incarnation' => 'unclaimed', 'fencing_version' => $fence]);
                    if (!$updated || $updated->rowCount() !== 1) throw new GatewayError('surface_fence_lost', '插话原页所有权已变化。', 409);
                    self::eventUnlocked($taskId, (string) $existingTurn['id'], $responseId, (string) $surface['id'],
                        'user_steering_queued', ['source_response_id' => $previous, 'generation_id' => $generationId,
                            'transport' => 'same_page_delta']);
                    return ['reused' => false, 'response_id' => null, 'task_id' => $taskId,
                        'epoch_id' => (string) $existingTurn['epoch_id'], 'turn_id' => (string) $existingTurn['id'],
                        'surface_id' => (string) $surface['id'], 'generation_id' => $generationId, 'round_no' => $roundNo];
                }
                if ($steeringRestart || $mailboxRestart) {
                    return self::rotateContextSurfaceUnlocked($spec, $responseId, $task, $existingTurn,
                        $surface, $roundNo, $generationId, $now, $previous);
                }
                self::eventUnlocked($taskId, (string) $existingTurn['id'], $responseId, (string) $surface['id'], $toolContinuation ? 'tool_result_round_queued' : 'response_round_queued', [
                    'round_no' => $roundNo, 'previous_response_id' => $previous, 'generation_id' => $generationId,
                ]);
                return [
                    'reused' => false,
                    'response_id' => null,
                    'task_id' => $taskId,
                    'epoch_id' => (string) $existingTurn['epoch_id'],
                    'turn_id' => (string) $existingTurn['id'],
                    'surface_id' => (string) $surface['id'],
                    'generation_id' => $generationId,
                    'round_no' => $roundNo,
                ];
            }
        }

        // An edit/model switch must not retire a page that still owns a pending tool or generation.
        $activeTaskTurn = $db->table('gateway_task_turns')->where('task_id', $taskId)->where('status', 'IN', self::ACTIVE_TURNS)->find();
        if (is_array($activeTaskTurn)) throw new GatewayError('surface_busy', '该任务还有未结束的回合，暂不能更换上下文或模型。', 409);
        $epoch = null;
        $currentEpochId = (string) ($task['current_epoch_id'] ?? '');
        if ($currentEpochId !== '') {
            $candidate = $db->table('gateway_task_epochs')->where('id', $currentEpochId)->find();
            if (is_array($candidate) && (string) $candidate['status'] === 'active'
                && hash_equals((string) $candidate['lease_key'], (string) $spec['browser_lease_key'])) {
                $epoch = $candidate;
            }
        }
        if (!is_array($epoch)) {
            if ($currentEpochId !== '') self::retireEpochUnlocked($taskId, $currentEpochId,
                isset($spec['budget']['postcompact_page']) ? 'checkpoint_page_retained' : 'lease_changed');
            $activeSurfaces = $db->table('gateway_browser_surfaces')->where('status', '<>', 'retired')->count();
            if ($activeSurfaces === false || $activeSurfaces === null) {
                throw new GatewayError('storage_unavailable', '任务页面池状态暂不可读取。', 503);
            }
            if ((int) $activeSurfaces >= self::MAX_SURFACES) {
                throw new GatewayError('surface_capacity_exceeded', '最多只能同时保留 5 个任务页面；请先关闭或取消一个任务页面后再试。', 409);
            }
            $last = $db->table('gateway_task_epochs')->where('task_id', $taskId)->order([['epoch_no', 'DESC']])->limit(1)->find();
            $epochNo = is_array($last) ? (int) $last['epoch_no'] + 1 : 1;
            $epochId = 'epoch_' . bin2hex(random_bytes(12));
            if (!$db->table('gateway_task_epochs')->add([
                'id' => $epochId,
                'task_id' => $taskId,
                'epoch_no' => $epochNo,
                'lease_key' => (string) $spec['browser_lease_key'],
                'model' => (string) $spec['model'],
                'reasoning_effort' => $spec['reasoning_effort'] ?? null,
                'compaction_epoch' => (string) ($spec['compaction_epoch'] ?? 'base'),
                'status' => 'active',
                'created_at' => $now,
            ])) throw new GatewayError('storage_unavailable', '任务 Epoch 无法创建。', 503);
            $surfaceId = 'surf_' . bin2hex(random_bytes(12));
            if (!$db->table('gateway_browser_surfaces')->add([
                'id' => $surfaceId,
                'task_id' => $taskId,
                'epoch_id' => $epochId,
                'lease_key' => (string) $spec['browser_lease_key'],
                'status' => 'reserved',
                'generation' => 1,
                'document_nonce' => bin2hex(random_bytes(12)),
                'owner_incarnation' => 'unclaimed',
                'fencing_version' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ])) throw new GatewayError('storage_unavailable', '任务页面租约无法创建。', 503);
            self::transferCheckpointPageUnlocked($spec, $taskId, $currentEpochId, $surfaceId, $now);
            if (!empty($spec['budget']['regular_chat'])) {
                $targets = (array) \RC\Config::get('gateway', 'probe_targets');
                TaskWebChat::transferPageUnlocked($taskId, $currentEpochId, $surfaceId, (string) ($targets['model_site']['url'] ?? 'https://chatgpt.com/'));
            }
            $updated = $db->table('gateway_tasks')->where('id', $taskId)->update([
                'current_epoch_id' => $epochId, 'status' => 'active', 'updated_at' => $now,
            ]);
            if (!$updated) throw new GatewayError('storage_unavailable', '任务当前 Epoch 无法更新。', 503);
            self::eventUnlocked($taskId, null, null, $surfaceId, 'surface_reserved', ['epoch_id' => $epochId, 'epoch_no' => $epochNo]);
            $epoch = $db->table('gateway_task_epochs')->where('id', $epochId)->find();
        }
        if (!is_array($epoch)) throw new GatewayError('task_ledger_incomplete', '任务 Epoch 状态不可读取。', 503);
        $surface = $db->table('gateway_browser_surfaces')->where('task_id', $taskId)->where('epoch_id', (string) $epoch['id'])->find();
        if (!is_array($surface)) throw new GatewayError('task_surface_missing', '任务页面记录缺失。', 503);
        if ((string) $surface['status'] === 'needs_attention') {
            throw new GatewayError('surface_needs_attention', '任务页面存在未知执行结果，需要人工确认。', 409);
        }
        $activeTurn = $db->table('gateway_task_turns')->where('surface_id', (string) $surface['id'])->where('status', 'IN', self::ACTIVE_TURNS)->find();
        if (is_array($activeTurn)) {
            throw new GatewayError('surface_busy', '该任务页面已有一个回合正在执行。', 409);
        }

        $turnId = is_string($clientTurnId) && $clientTurnId !== ''
            ? 'turn_' . substr(hash('sha256', $taskId . "\0" . $clientTurnId), 0, 24)
            : 'turn_' . bin2hex(random_bytes(12));
        $generationId = 'gen_' . bin2hex(random_bytes(12));
        if (!$db->table('gateway_task_turns')->add([
            'id' => $turnId,
            'task_id' => $taskId,
            'epoch_id' => (string) $epoch['id'],
            'surface_id' => (string) $surface['id'],
            'client_turn_id' => $clientTurnId,
            'request_hash' => (string) $spec['request_hash'],
            'generation_id' => $generationId,
            'response_id' => $responseId,
            'status' => 'queued',
            'owner_incarnation' => (string) $surface['owner_incarnation'],
            'fencing_version' => (int) $surface['fencing_version'],
            'deadline_at' => $now + (int) ($spec['budget'][($spec['operation_kind'] ?? 'response') === 'response'
                ? 'response_timeout_seconds' : 'compaction_timeout_seconds'] ?? 600),
            'created_at' => $now,
            'updated_at' => $now,
        ])) throw new GatewayError('storage_unavailable', '任务回合无法创建。', 503);
        self::eventUnlocked($taskId, $turnId, $responseId, (string) $surface['id'], 'turn_queued', [
            'epoch_id' => (string) $epoch['id'], 'generation_id' => $generationId,
        ]);
        return [
            'reused' => false,
            'response_id' => null,
            'task_id' => $taskId,
            'epoch_id' => (string) $epoch['id'],
            'turn_id' => $turnId,
            'surface_id' => (string) $surface['id'],
            'generation_id' => $generationId,
            'round_no' => 1,
        ];
    }

    /**
     * Move only a proven completed checkpoint's physical document to its new logical epoch.
     * Clearing the retired target in the same transaction prevents cleanup from closing the
     * retained page; release any old Stop obligation and fence every old observer. No host work is replayed.
     * @param array $spec Prepared request with an optional exact checkpoint-page proof.
     * @param string $taskId Owned task. @param string $oldEpochId Previous logical epoch.
     * @param string $surfaceId Newly reserved destination. @param int $now Transaction timestamp.
     * @throws GatewayError If the source changed before the atomic transfer.
     */
    private static function transferCheckpointPageUnlocked(array $spec, string $taskId,
        string $oldEpochId, string $surfaceId, int $now): void
    {
        $proof = $spec['budget']['postcompact_page'] ?? null;
        if (!is_array($proof) || ($proof['request_hash'] ?? '') !== $spec['request_hash']) return;
        $db = SDB(null, 'medoo', 'sqlite');
        $old = $db->table('gateway_browser_surfaces')->where('id', $proof['source_surface_id'])->find();
        if (!is_array($old) || $old['status'] !== 'retired' || $old['task_id'] !== $taskId
            || $old['epoch_id'] !== $oldEpochId || $oldEpochId !== $proof['source_epoch_id']
            || $old['target_id'] !== $proof['target_id'] || $old['document_nonce'] !== $proof['document_nonce']
            || (int) $old['fencing_version'] !== $proof['fencing_version']) {
            throw new GatewayError('surface_fence_lost', '压缩后续接的原页面所有权已变化。', 409);
        }
        $released = $db->table('gateway_browser_surfaces')->where('id', $old['id'])
            ->where('target_id', $old['target_id'])->where('fencing_version', $old['fencing_version'])
            ->update(['target_id' => null, 'session_id' => null, 'fencing_version' => (int) $old['fencing_version'] + 1]);
        $claimed = $db->table('gateway_browser_surfaces')->where('id', $surfaceId)->where('status', 'reserved')->update([
            'target_id' => $old['target_id'], 'document_nonce' => $old['document_nonce'],
            'generation' => (int) $old['generation'] + 1, 'fencing_version' => (int) $old['fencing_version'] + 1,
            'status' => 'ready', 'url' => $old['url'], 'title' => $old['title'], 'updated_at' => $now,
        ]);
        if (!$released || $released->rowCount() !== 1 || !$claimed || $claimed->rowCount() !== 1) {
            throw new GatewayError('surface_fence_lost', '压缩后续接无法保留原页面。', 409);
        }
        if (!TaskPageRetirement::releaseTransferredUnlocked($old,$surfaceId)) {
            throw new GatewayError('surface_fence_lost','Retained checkpoint retirement transfer lost ownership.',409);
        }
        self::eventUnlocked($taskId, null, null, $surfaceId, 'checkpoint_page_retained', [
            'checkpoint_id' => $proof['checkpoint_id'], 'source_surface_id' => $old['id'],
            'source_response_id' => $proof['source_response_id'],
        ]);
    }

    /** A proven revision rotates its epoch; model handoffs may transfer the task's owned saved-chat page. */
    private static function rotateContextSurfaceUnlocked(array $spec, string $responseId, array $task,
        array $turn, array $surface, int $roundNo, string $generationId, int $now, string $sourceResponseId): array
    {
        $db = SDB(null, 'medoo', 'sqlite');
        if ((string) $surface['status'] === 'retired' || (string) $surface['task_id'] !== (string) $task['id']
            || (string) $turn['surface_id'] !== (string) $surface['id']) {
            throw new GatewayError('steering_source_unavailable', '中途指令的原网页租约已失效。', 409);
        }
        $epoch = $db->table('gateway_task_epochs')->where('id', (string) $surface['epoch_id'])->find();
        if (!is_array($epoch) || (string) $epoch['status'] !== 'active') {
            throw new GatewayError('steering_source_unavailable', '中途指令的原上下文代次已失效。', 409);
        }
        $retired = $db->table('gateway_browser_surfaces')->where('id', (string) $surface['id'])
            ->where('status', (string) $surface['status'])->update([
                'status' => 'retired', 'session_id' => null, 'retired_at' => $now, 'updated_at' => $now,
            ]);
        if (!$retired || $retired->rowCount() !== 1) throw new GatewayError('steering_source_unavailable', '原网页租约状态已变化。', 409);
        $epochRetired = $db->table('gateway_task_epochs')->where('id', (string) $epoch['id'])
            ->where('status', 'active')->update(['status' => 'retired', 'retired_at' => $now]);
        if (!$epochRetired || $epochRetired->rowCount() !== 1) throw new GatewayError('steering_source_unavailable', '原上下文代次状态已变化。', 409);
        $last = $db->table('gateway_task_epochs')->where('task_id', (string) $task['id'])
            ->order([['epoch_no', 'DESC']])->limit(1)->find();
        $newEpochId = 'epoch_' . bin2hex(random_bytes(12));
        $modelSwitch = !empty($spec['receipt_model_switch']);
        $leaseKey = (string) ($modelSwitch ? $spec['browser_lease_key'] : $surface['lease_key']);
        if (!$db->table('gateway_task_epochs')->add([
            'id' => $newEpochId, 'task_id' => (string) $task['id'],
            'epoch_no' => (int) ($last['epoch_no'] ?? $epoch['epoch_no']) + 1,
            'lease_key' => $leaseKey, 'model' => (string) ($modelSwitch ? $spec['model'] : $epoch['model']),
            'reasoning_effort' => $modelSwitch ? ($spec['reasoning_effort'] ?? null) : $epoch['reasoning_effort'],
            'compaction_epoch' => (string) $epoch['compaction_epoch'],
            'status' => 'active', 'created_at' => $now,
        ])) throw new GatewayError('storage_unavailable', '中途指令无法创建新上下文代次。', 503);
        $newSurfaceId = 'surf_' . bin2hex(random_bytes(12));
        if (!$db->table('gateway_browser_surfaces')->add([
            'id' => $newSurfaceId, 'task_id' => (string) $task['id'], 'epoch_id' => $newEpochId,
            'lease_key' => $leaseKey, 'status' => 'reserved',
            'generation' => 1, 'document_nonce' => bin2hex(random_bytes(12)),
            'owner_incarnation' => 'unclaimed', 'fencing_version' => 1,
            'created_at' => $now, 'updated_at' => $now,
        ])) throw new GatewayError('storage_unavailable', '中途指令无法创建新网页租约。', 503);
        $newFence = 1;
        if ($modelSwitch && !empty($spec['budget']['regular_chat'])) {
            $targets = (array) \RC\Config::get('gateway', 'probe_targets');
            TaskWebChat::transferPageUnlocked((string) $task['id'], (string) $epoch['id'], $newSurfaceId,
                (string) ($targets['model_site']['url'] ?? 'https://chatgpt.com/'));
            $transferred = $db->table('gateway_browser_surfaces')->where('id', $newSurfaceId)->find('fencing_version');
            $newFence = (int) $transferred['fencing_version'];
        }
        $turnUpdated = $db->table('gateway_task_turns')->where('id', (string) $turn['id'])
            ->where('response_id', $responseId)->update([
                'epoch_id' => $newEpochId, 'surface_id' => $newSurfaceId,
                'generation_id' => $generationId, 'status' => 'queued',
                'owner_incarnation' => 'unclaimed', 'fencing_version' => $newFence,
                'submit_intent_at' => null, 'submitted_at' => null, 'committed_at' => null,
                'retired_at' => null, 'error_code' => null, 'updated_at' => $now,
            ]);
        if (!$turnUpdated || $turnUpdated->rowCount() !== 1) throw new GatewayError('storage_unavailable', '中途指令无法接管 Codex 回合。', 503);
        $taskUpdated = $db->table('gateway_tasks')->where('id', (string) $task['id'])
            ->where('current_epoch_id', (string) $epoch['id'])->update([
                'current_epoch_id' => $newEpochId, 'updated_at' => $now,
            ]);
        if (!$taskUpdated || $taskUpdated->rowCount() !== 1) throw new GatewayError('storage_unavailable', '中途指令无法绑定新网页。', 503);
        self::eventUnlocked((string) $task['id'], (string) $turn['id'], $responseId, $newSurfaceId,
            !empty($spec['mailbox_context_restart']) ? 'native_mailbox_context_round_queued' : 'user_steering_queued', ['source_response_id' => $sourceResponseId,
                'old_surface_id' => (string) $surface['id'], 'generation_id' => $generationId]);
        self::eventUnlocked((string) $task['id'], (string) $turn['id'], $responseId, $newSurfaceId,
            !empty($spec['mailbox_context_restart']) ? 'response_round_queued' : 'tool_result_round_queued', ['round_no' => $roundNo,
                'previous_response_id' => $sourceResponseId, 'generation_id' => $generationId]);
        return ['reused' => false, 'response_id' => null, 'task_id' => (string) $task['id'],
            'epoch_id' => $newEpochId, 'turn_id' => (string) $turn['id'],
            'surface_id' => $newSurfaceId, 'generation_id' => $generationId, 'round_no' => $roundNo];
    }

    /** Claim/reattach one task surface for the current browser-worker incarnation. */
    /**
     * Claim a CDP observer; an exact retained active document transfers its turn's fence too.
     * Reattaching cannot resend, accept, renew or complete work. A different target/document
     * keeps the ordinary ready state and must pass the existing recovery checks separately.
     * @param string $surfaceId Durable surface. @param string $targetId Observed Chrome target.
     * @param string $sessionId New CDP session. @param string $url Observed page URL.
     * @param array|null $compactObserver Missing-target proof for an unsent saved-chat checkpoint observer only.
     * @return array New owner, fence, generation and current document nonce (preserved for the same target).
     * @throws GatewayError If ownership or the exact retained turn cannot be transferred.
     */
    public static function claimSurface(string $surfaceId, string $targetId, string $sessionId, string $url, ?array $compactObserver = null): array
    {
        return Schema::transaction(function () use ($surfaceId, $targetId, $sessionId, $url, $compactObserver): array {
            $db = SDB(null, 'medoo', 'sqlite');
            $surface = $db->table('gateway_browser_surfaces')->where('id', $surfaceId)->find();
            if (!is_array($surface) || (string) $surface['status'] === 'retired') {
                throw new GatewayError('task_surface_missing', '任务页面已退休或不存在。', 409);
            }
            $incarnation = 'inc_' . bin2hex(random_bytes(12));
            $fence = (int) $surface['fencing_version'] + 1;
            $generation = (int) $surface['generation'] + (((string) ($surface['target_id'] ?? '') !== '' && (string) $surface['target_id'] !== $targetId) ? 1 : 0);
            $nonce = ((string) ($surface['target_id'] ?? '') === $targetId && (string) ($surface['document_nonce'] ?? '') !== '')
                ? (string) $surface['document_nonce'] : bin2hex(random_bytes(12));
            $retainedTurn = null;
            $observerChat = null;
            if ($compactObserver !== null) {
                $ledger = self::execution((string)$compactObserver['response_id']);
                $observerChat = TaskWebChat::compactObserverChat($ledger);
                if ($observerChat === null || $surfaceId !== $ledger['surface']['id']
                    || $surface['owner_incarnation'] !== ($compactObserver['owner_incarnation'] ?? '')
                    || (int)$surface['fencing_version'] !== (int)($compactObserver['fencing_version'] ?? -1)
                    || $surface['document_nonce'] !== ($compactObserver['document_nonce'] ?? '')
                    || $ledger['job']['generation_id'] !== ($compactObserver['generation_id'] ?? '')
                    || $observerChat['conversation_id'] !== ($compactObserver['conversation_id'] ?? '')
                    || $observerChat['account_key'] !== ($compactObserver['account_key'] ?? '') || $url !== 'about:blank') {
                    throw new GatewayError('surface_fence_lost', 'Saved checkpoint reopening lost ownership.', 409);
                }
                $retainedTurn = $ledger['turn'];
            }
            if ($surface['status'] === 'busy' && (string) ($surface['target_id'] ?? '') === $targetId) {
                $candidate = $db->table('gateway_task_turns')->where('surface_id', $surfaceId)
                    ->where('status', 'IN', ['submit_intent', 'submitted', 'running'])->find();
                if ($candidate === false) throw new GatewayError('storage_unavailable', '恢复观察的回合状态暂不可读取。', 503);
                $job = is_array($candidate)
                    ? $db->table('gateway_response_jobs')->where('id', (string) $candidate['response_id'])->find() : null;
                if (is_array($candidate) && is_array($job)
                    && self::ownsCurrentGeneration($job, $candidate)
                    && $candidate['task_id'] === $surface['task_id'] && $candidate['epoch_id'] === $surface['epoch_id']
                    && $candidate['owner_incarnation'] === $surface['owner_incarnation']
                    && (int) $candidate['fencing_version'] === (int) $surface['fencing_version']
                    && (($job['submitted_document_nonce'] ?? null) === $nonce
                        || TaskWebChat::compactObserverReopenState(['job'=>$job,'turn'=>$candidate,'surface'=>$surface]) !== null)) {
                    $retainedTurn = $candidate;
                }
            }
            $updated = $db->table('gateway_browser_surfaces')->where('id', $surfaceId)->where('fencing_version', (int) $surface['fencing_version'])->update([
                'target_id' => $targetId,
                'session_id' => $sessionId,
                'status' => $retainedTurn === null ? 'ready' : 'busy',
                'generation' => $generation,
                'document_nonce' => $nonce,
                'owner_incarnation' => $incarnation,
                'fencing_version' => $fence,
                'url' => mb_substr($url, 0, 2000),
                'last_observed_at' => time(),
                'updated_at' => time(),
            ]);
            if (!$updated || $updated->rowCount() !== 1) throw new GatewayError('surface_fence_lost', '任务页面所有权已经变化。', 409);
            if ($retainedTurn !== null) {
                $transferred = $db->table('gateway_task_turns')->where('id', $retainedTurn['id'])
                    ->where('response_id', $retainedTurn['response_id'])->where('generation_id', $retainedTurn['generation_id'])
                    ->where('status', $retainedTurn['status'])->where('owner_incarnation', $surface['owner_incarnation'])
                    ->where('fencing_version', (int) $surface['fencing_version'])
                    ->update(['owner_incarnation' => $incarnation, 'fencing_version' => $fence, 'updated_at' => time()]);
                if (!$transferred || $transferred->rowCount() !== 1) {
                    throw new GatewayError('surface_fence_lost', '恢复观察时源回合所有权已经变化。', 409);
                }
            }
            self::eventUnlocked((string) $surface['task_id'], null, null, $surfaceId, 'surface_attached', [
                'generation' => $generation, 'fencing_version' => $fence,
            ]);
            if ($observerChat !== null) self::eventUnlocked($surface['task_id'], $retainedTurn['id'],
                $compactObserver['response_id'], $surfaceId, 'regular_compact_observer_reopened', [
                    'generation_id'=>$compactObserver['generation_id'], 'target_id'=>$targetId,
                    'source_response_id'=>$observerChat['source_response_id'],
                    'conversation_id'=>$observerChat['conversation_id'], 'account_key'=>$observerChat['account_key'],
                ]);
            return ['owner_incarnation' => $incarnation, 'fencing_version' => $fence, 'generation' => $generation, 'document_nonce' => $nonce];
        });
    }

    /**
     * Reserve preparation time for one newly configured compact multipart transaction.
     * The caller owns the transaction and calls only after its first transport-identity CAS.
     * Each inert stage retains its own pinned timeout; none consumes the final summary budget.
     * @param string $responseId Current compact generation. @param int $partsTotal Two to six messages.
     * @return void @throws GatewayError If the queued generation or durable update is unavailable.
     */
    public static function prepareMultipartCompactionUnlocked(string $responseId, int $partsTotal): void
    {
        [$job, $turn, $surface] = self::executionRowsUnlocked($responseId);
        if (!in_array((string) ($job['operation_kind'] ?? 'response'), ['compact_v1', 'compact_v2'], true)) return;
        if (!self::ownsCurrentGeneration($job, $turn)) {
            throw new GatewayError('response_superseded', '旧网页回合已被新的用户指令接管。', 409);
        }
        if ((string) $turn['status'] !== 'queued') {
            throw new GatewayError('turn_state_invalid', '上下文压缩已离开发送准备状态。', 409);
        }
        $budget = json_decode((string) $job['budget_json'], true, 32, JSON_THROW_ON_ERROR);
        $now = time();
        $deadline = max((int) $turn['deadline_at'], $now
            + ($partsTotal - 1) * (int) ($budget['context_stage_timeout_seconds'] ?? 180)
            + (int) ($budget['compaction_timeout_seconds'] ?? 180));
        $updated = SDB(null, 'medoo', 'sqlite')->table('gateway_task_turns')
            ->where('id', (string) $turn['id'])->where('response_id', $responseId)
            ->where('generation_id', (string) $turn['generation_id'])
            ->where('surface_id', (string) $surface['id'])->where('status', 'queued')
            ->where('deadline_at', (int) $turn['deadline_at'])
            ->update(['deadline_at' => $deadline, 'updated_at' => $now]);
        if (!$updated || $updated->rowCount() !== 1) {
            throw new GatewayError('storage_unavailable', '上下文压缩准备截止时间无法保存。', 503);
        }
        self::eventUnlocked((string) $turn['task_id'], (string) $turn['id'], $responseId,
            (string) $surface['id'], 'multipart_compaction_prepared', ['parts_total' => $partsTotal, 'deadline_at' => $deadline]);
    }

    /**
     * Persist the first send intent and pre-send DOM identities before any click is attempted.
     * A compact final submission starts its pinned summary budget after inert staging finishes.
     * Repeated intents preserve the original deadline and cannot authorize another send.
     * @param string $responseId Current response. @param array $users Pre-send user identities.
     * @param array $assistants Pre-send assistant identities. @param string $incarnation Surface owner.
     * @param int $fence Surface fencing version. @param string|null $webText Exact final prompt for audit.
     * @return void @throws GatewayError If generation ownership, surface fencing or storage fails.
     */
    public static function submitIntent(string $responseId, array $users, array $assistants, string $incarnation, int $fence,
        ?string $webText = null): void
    {
        Schema::transaction(function () use ($responseId, $users, $assistants, $incarnation, $fence, $webText): void {
            $db = SDB(null, 'medoo', 'sqlite');
            [$job, $turn, $surface] = self::executionRowsUnlocked($responseId);
            if (!self::ownsCurrentGeneration($job, $turn)) {
                throw new GatewayError('response_superseded', '旧网页回合已被新的用户指令接管。', 409);
            }
            self::assertFence($surface, $incarnation, $fence);
            if ((string) $turn['status'] !== 'queued') {
                if ((string) $turn['status'] === 'submit_intent') return;
                throw new GatewayError('turn_state_invalid', '任务回合已离开发送准备状态。', 409);
            }
            if (!empty($job['continuation_source_response_id']) && !ContextHistory::retainedContext($job, $surface)) {
                throw new GatewayError('CHATGPT_SURFACE_MISSING', '发送前续接源页面已变化，需要恢复完整上下文。', 409);
            }
            if ($webText !== null) WebMessageAudit::recordOutgoingUnlocked($job, 'commit', $webText);
            $now = time();
            $jobUpdate = $db->table('gateway_response_jobs')->where('id', $responseId)->where('submit_intent_at', null)->update([
                'submit_intent_at' => $now,
                'submitted_document_nonce' => $surface['document_nonce'],
                'baseline_users_json' => json_encode(array_values($users), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'baseline_assistants_json' => json_encode(array_values($assistants), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ]);
            if ($jobUpdate === false) throw new GatewayError('storage_unavailable', '发送意图无法持久化。', 503);
            $turnValues = [
                'status' => 'submit_intent',
                'submit_intent_at' => $now,
                'owner_incarnation' => $incarnation,
                'fencing_version' => $fence,
                'updated_at' => $now,
            ];
            if (in_array((string) ($job['operation_kind'] ?? 'response'), ['compact_v1', 'compact_v2'], true)) {
                $budget = json_decode((string) $job['budget_json'], true, 32, JSON_THROW_ON_ERROR);
                $turnValues['deadline_at'] = $now + (int) ($budget['compaction_timeout_seconds'] ?? 180);
            }
            $turnUpdate = $db->table('gateway_task_turns')->where('id', $turn['id'])->where('status', 'queued')->update($turnValues);
            if (!$turnUpdate || $turnUpdate->rowCount() !== 1) throw new GatewayError('storage_unavailable', '发送意图账本无法提交。', 503);
            $db->table('gateway_browser_surfaces')->where('id', $surface['id'])->update(['status' => 'busy', 'updated_at' => $now]);
            self::eventUnlocked((string) $turn['task_id'], (string) $turn['id'], $responseId, (string) $surface['id'], 'submit_intent', []);
        });
    }

    /**
     * Reserve one same-page continuation only after the preceding answer admits unfinished
     * work and all current MCP activity has drained. The event is the no-duplicate send
     * boundary across worker restarts; no host operation is replayed by this method.
     * @return array{attempt:int,marker:string,deadline_at:int}
     */
    public static function reserveContinuation(string $responseId, string $answerHash, int $activityRevision, int $hostCalls,
        string $reason = 'unfinished'): array
    {
        if (!in_array($reason, ['unfinished','audit'], true)) {
            throw new GatewayError('continuation_reason_invalid', '任务继续原因无效。', 400);
        }
        return Schema::transaction(function () use ($responseId, $answerHash, $activityRevision, $hostCalls, $reason): array {
            $db = SDB(null, 'medoo', 'sqlite');
            [$job, $turn, $surface] = self::executionRowsUnlocked($responseId);
            if (!self::ownsCurrentGeneration($job, $turn)) {
                throw new GatewayError('response_superseded', '旧网页回合已被新的用户指令接管。', 409);
            }
            if (!in_array((string)$turn['status'], ['submitted', 'running'], true) || $hostCalls < 1) {
                throw new GatewayError('continuation_state_invalid', '任务未处于可继续的工具执行状态。', 409);
            }
            $binding = $db->table('gateway_mcp_bindings')->where('current_response_id', $responseId)->find();
            if (!is_array($binding)
                || ((string)$binding['status'] !== 'running'
                    && !ToolBroker::recoveredReceiptOwnerReadyForResponse($responseId))
                || (int)$binding['activity_revision'] !== $activityRevision) {
                throw new GatewayError('continuation_activity_changed', '准备继续时工具活动发生变化。', 409);
            }
            $previous = self::continuationStateUnlocked((string)$turn['id']);
            if (!empty($previous['pending'])) {
                throw new GatewayError('continuation_outcome_unknown', '上一次网页继续消息的接收结果尚未确认，不能重复发送。', 409);
            }
            if (($previous['attempt'] ?? 0) >= 64) {
                throw new GatewayError('continuation_limit_reached', '同一任务已连续继续过多次，仍未报告完成。', 504);
            }
            if (($previous['answer_hash'] ?? '') === $answerHash) {
                throw new GatewayError('continuation_no_progress', '网页重复了相同的未完成答复。', 409);
            }
            $withoutNewHostCall = ($previous['attempt'] ?? 0) > 0 && $hostCalls <= (int)($previous['host_calls'] ?? 0);
            $noProgressAttempts = $withoutNewHostCall ? (int)($previous['no_progress_attempts'] ?? 0) + 1 : 0;
            // One accepted answer can omit tools because ChatGPT dropped the per-message
            // Connector. Retry once with a freshly selected Connector, then stop looping.
            if ($noProgressAttempts > 1) {
                throw new GatewayError('continuation_no_progress', '重新连接工具后仍没有新的主机工具执行，任务仍未完成。', 409);
            }
            $attempt = (int)($previous['attempt'] ?? 0) + 1;
            $marker = 'RCMAKER-CONTINUE-' . substr(hash('sha256', (string)$turn['id'] . "\0" . $attempt), 0, 16);
            // A verified completion audit or admitted unfinished step is a new Web
            // generation, not idle polling of the old answer. Give its send and
            // generation their own bounded lease without replaying any host call.
            $budget = json_decode((string) ($job['budget_json'] ?? ''), true);
            $deadline = max((int) $turn['deadline_at'], time() + (int) ($budget['response_timeout_seconds'] ?? 600));
            $renewed = $db->table('gateway_task_turns')->where('id', (string) $turn['id'])
                ->where('status', (string) $turn['status'])->update(['deadline_at'=>$deadline, 'updated_at'=>time()]);
            if (!$renewed || $renewed->rowCount() !== 1) {
                throw new GatewayError('storage_unavailable', '网页续做截止时间无法保存。', 503);
            }
            self::eventUnlocked((string)$turn['task_id'], (string)$turn['id'], $responseId, (string)$surface['id'],
                'task_continuation_intent', ['attempt'=>$attempt,'reason'=>$reason,'marker'=>$marker,'answer_hash'=>$answerHash,
                    'activity_revision'=>$activityRevision,'host_calls'=>$hostCalls,
                    'no_progress_attempts'=>$noProgressAttempts,'intent_at'=>time(),'deadline_at'=>$deadline]);
            return ['attempt'=>$attempt,'marker'=>$marker,'deadline_at'=>$deadline];
        });
    }

    /** Read the latest same-page continuation identity for safe browser recovery. */
    public static function continuationState(string $responseId): array
    {
        $job = SDB(null, 'medoo', 'sqlite')->table('gateway_response_jobs')->where('id', $responseId)->find();
        if (!is_array($job) || empty($job['durable_turn_id'])) return [];
        return self::continuationStateUnlocked((string)$job['durable_turn_id']);
    }

    /** Confirm only the exact visible user message for a previously reserved continuation. */
    public static function continuationAccepted(string $responseId, string $marker): void
    {
        Schema::transaction(function () use ($responseId, $marker): void {
            [$job, $turn, $surface] = self::executionRowsUnlocked($responseId);
            if (!self::ownsCurrentGeneration($job, $turn)) return;
            $state = self::continuationStateUnlocked((string)$turn['id']);
            if (($state['marker'] ?? '') !== $marker) throw new GatewayError('continuation_marker_mismatch', '网页继续消息与持久化意图不一致。', 409);
            if (empty($state['pending'])) return;
            $budget = json_decode((string) ($job['budget_json'] ?? ''), true);
            // An accepted recovery message is not proof that the server lifted its limit.
            // Keep that wait bounded; actual task progress retains its normal renewal path.
            $deadline = ($state['reason'] ?? '') === 'rate_limit_recovery' ? (int)$turn['deadline_at']
                : max((int) $turn['deadline_at'], time() + (int) ($budget['response_timeout_seconds'] ?? 600));
            $renewed = SDB(null, 'medoo', 'sqlite')->table('gateway_task_turns')->where('id', (string) $turn['id'])
                ->where('status', (string) $turn['status'])->update(['deadline_at'=>$deadline, 'updated_at'=>time()]);
            if (!$renewed || $renewed->rowCount() !== 1) throw new GatewayError('storage_unavailable', '网页续做接收截止时间无法保存。', 503);
            self::eventUnlocked((string)$turn['task_id'], (string)$turn['id'], $responseId, (string)$surface['id'],
                'task_continuation_accepted', ['attempt'=>(int)$state['attempt'],'marker'=>$marker,'deadline_at'=>$deadline]);
        });
        WebMessageAudit::markAccepted($responseId, 'continuation');
    }

    /** @return array<string,mixed> */
    private static function continuationStateUnlocked(string $turnId): array
    {
        $rows = SDB(null, 'medoo', 'sqlite')->table('gateway_task_events')->where('turn_id', $turnId)
            ->where('event_type', 'IN', ['task_continuation_intent','task_continuation_accepted','task_continuation_reset'])
            ->order([['id','ASC']])->select('event_type,data_json');
        if (!is_array($rows)) throw new GatewayError('storage_unavailable', '任务继续状态暂不可读取。', 503);
        $state = [];
        $auditSeen = false;
        foreach ($rows as $row) {
            $data = json_decode((string)$row['data_json'], true);
            if (!is_array($data)) continue;
            if ($row['event_type'] === 'task_continuation_intent') {
                if (($data['reason'] ?? '') === 'audit') $auditSeen = true;
                $state = $data + ['pending'=>true];
            } elseif (($state['marker'] ?? '') === ($data['marker'] ?? null)) {
                if ($row['event_type'] === 'task_continuation_reset') $state = [];
                else $state['pending'] = false;
            }
        }
        if ($auditSeen) $state['audit_seen'] = true;
        return $state;
    }

    /**
     * Commit first page acceptance using the job's already persisted evidence.
     * Compaction generation is anchored to accepted_at so interrupted or repeated callbacks
     * cannot restart its timeout; ordinary response deadlines retain their existing behavior.
     * @param string $responseId Current response with durable acceptance evidence.
     * @return void @throws GatewayError If acceptance state or storage is unavailable.
     */
    public static function submitted(string $responseId): void
    {
        Schema::transaction(function () use ($responseId): void {
            self::submittedUnlocked($responseId);
        });
    }

    /**
     * Apply the ordinary acceptance transition inside a caller-owned writer transaction.
     * Both the browser callback and a verified complete native receipt use identical ownership,
     * state and deadline checks; this method never opens a nested SQLite transaction.
     * @param string $responseId Response with acceptance evidence persisted by its caller.
     * @return void Superseded or already acknowledged generations remain unchanged.
     * @throws GatewayError If the turn state, compact acceptance evidence or storage is invalid.
     */
    public static function submittedUnlocked(string $responseId): void
    {
        $db = SDB(null, 'medoo', 'sqlite');
        [$job, $turn, $surface] = self::executionRowsUnlocked($responseId);
        if (!self::ownsCurrentGeneration($job, $turn)) return;
        if (in_array((string) $turn['status'], ['submitted', 'running', 'committed'], true)) return;
        if ((string) $turn['status'] !== 'submit_intent') throw new GatewayError('turn_state_invalid', '页面接收证据对应的回合状态无效。', 409);
        $now = time();
        $turnValues = [
            'status' => 'submitted', 'submitted_at' => $now, 'updated_at' => $now,
        ];
        if (in_array((string) ($job['operation_kind'] ?? 'response'), ['compact_v1', 'compact_v2'], true)) {
            $acceptedAt = (int) ($job['accepted_at'] ?? 0);
            if ($acceptedAt <= 0) {
                throw new GatewayError('turn_state_invalid', '上下文压缩缺少已保存的页面接收证据。', 409);
            }
            $budget = json_decode((string) $job['budget_json'], true, 32, JSON_THROW_ON_ERROR);
            $turnValues['deadline_at'] = $acceptedAt + (int) ($budget['compaction_timeout_seconds'] ?? 180);
        }
        $updated = $db->table('gateway_task_turns')->where('id', $turn['id'])->where('status', 'submit_intent')->update($turnValues);
        if (!$updated || $updated->rowCount() !== 1) throw new GatewayError('storage_unavailable', '页面接收证据账本无法提交。', 503);
        self::eventUnlocked((string) $turn['task_id'], (string) $turn['id'], $responseId, (string) $surface['id'], 'page_submitted', []);
    }

    public static function running(string $responseId): void
    {
        Schema::transaction(function () use ($responseId): void {
            $db = SDB(null, 'medoo', 'sqlite');
            [$job, $turn, $surface] = self::executionRowsUnlocked($responseId);
            if (!self::ownsCurrentGeneration($job, $turn)) return;
            if ((string) $turn['status'] !== 'submitted') return;
            $db->table('gateway_task_turns')->where('id', $turn['id'])->where('status', 'submitted')->update(['status' => 'running', 'updated_at' => time()]);
            self::eventUnlocked((string) $turn['task_id'], (string) $turn['id'], $responseId, (string) $surface['id'], 'response_running', []);
        });
    }

    /**
     * Extend one model step only after newly published, browser-observed output.
     * Polls and unchanged DOM snapshots cannot keep a stalled generation alive.
     * The ceiling is per logical Responses round, so real tool results may start
     * another step while one endlessly streaming answer remains bounded.
     */
    public static function renewOnVisibleProgress(string $responseId): void
    {
        Schema::transaction(static function () use ($responseId): void {
            [$job, $turn, $surface] = self::executionRowsUnlocked($responseId);
            if ((string) $job['status'] !== 'running'
                || (string) ($job['operation_kind'] ?? 'response') !== 'response'
                || !in_array((string) $turn['status'], ['submitted', 'running'], true)
                || (string) $turn['response_id'] !== $responseId) return;
            $now = time();
            $budget = json_decode((string) $job['budget_json'], true);
            $idleSeconds = max(60, (int) ($budget['response_timeout_seconds'] ?? 600));
            // A long native/Web task can continue for hours while real public
            // progress is observed. A silent snapshot never renews this ceiling.
            $ceiling = (int) $job['created_at'] + 86400;
            $deadline = min($ceiling, $now + $idleSeconds);
            // Renew at most once per 30 seconds of verified new content.
            if ($deadline <= (int) $turn['deadline_at'] + 30) return;
            $updated = SDB(null, 'medoo', 'sqlite')->table('gateway_task_turns')
                ->where('id', (string) $turn['id'])
                ->where('response_id', $responseId)
                ->where('status', (string) $turn['status'])
                ->where('deadline_at', (int) $turn['deadline_at'])
                ->update(['deadline_at' => $deadline, 'updated_at' => $now]);
            if (!$updated || $updated->rowCount() !== 1) return;
            self::eventUnlocked((string) $turn['task_id'], (string) $turn['id'], $responseId,
                (string) $surface['id'], 'visible_progress_renewed', ['deadline_at' => $deadline]);
        });
    }

    /**
     * Record a delayed renderer observation without ending or extending the owned turn.
     * @param string $responseId Existing logical round. @param string $phase Observation stage.
     * @param array $observation Allow-listed stop readiness or load cooldown facts, never page text or tokens.
     * @return void
     * @throws GatewayError When the durable job/ledger is unavailable.
     */
    public static function observationDelayed(string $responseId, string $phase = 'response', array $observation = []): void
    {
        Schema::transaction(static function () use ($responseId, $phase, $observation): void {
            [$job, $turn, $surface] = self::executionRowsUnlocked($responseId);
            if (!self::ownsCurrentGeneration($job, $turn)) return;
            $steering = $phase === 'steering_stop' && SteeringContext::isDelta($job) && $turn['status'] === 'queued';
            $loading = $phase === 'saved_chat_load' && $job['status'] === 'running'
                && $job['operation_kind'] === 'response' && $turn['status'] === 'queued' && empty($job['submit_intent_at']);
            $cooling = $phase === 'rate_limit' && in_array($job['status'], ['queued', 'running'], true);
            if (!$steering && !$loading && !$cooling && ($job['status'] !== 'running' || !in_array($turn['status'], ['submit_intent', 'submitted', 'running'], true))) return;
            $data = ['deadline_at' => (int) $turn['deadline_at'],
                'phase' => $cooling ? 'rate_limit' : ($loading ? 'saved_chat_load' : ($steering ? 'steering_stop' : ($phase === 'send_confirmation' ? 'send_confirmation' : 'response')))];
            if ($loading || $cooling) $data += ['http_status'=>429,'attempt'=>max(0,min(64,(int)($observation['attempt']??0))),
                'retry_after_seconds'=>max(1,min(86400,(int)($observation['retry_after_seconds']??30)))];
            if ($steering) foreach (['owned', 'generation_running', 'editor_ready', 'stop_clicked', 'observation_failed',
                'initial_binding_witness', 'initial_response_witness', 'token_witness', 'retained_identity_witness'] as $key) {
                $data[$key] = isset($observation[$key]) ? (bool) $observation[$key] : null;
            }
            if ($steering) foreach (['user_count', 'stop_count'] as $key) {
                $data[$key] = isset($observation[$key]) ? max(0, min(10000, (int)$observation[$key])) : null;
            }
            self::eventUnlocked((string) $turn['task_id'], (string) $turn['id'], $responseId, (string) $surface['id'],
                'browser_observation_delayed', $data);
        });
    }

    /**
     * Persist the exact empty root observed before a first saved-chat submission.
     * Multipart staging later replaces the rolling response baseline with its own
     * messages, so UUID binding must not depend on that mutable baseline.
     * @param string $responseId Exact unsent response.
     * @param array $users Pre-send user identities; must be empty.
     * @param array $assistants Pre-send assistant identities; must be empty.
     * @param string $incarnation Current surface owner.
     * @param int $fence Current surface fencing version.
     * @return void Duplicate observations are idempotent.
     * @throws GatewayError If ownership changed or durable evidence cannot be saved.
     */
    public static function initialRegularChatEmptyObserved(string $responseId, array $users, array $assistants,
        string $incarnation, int $fence): void
    {
        Schema::transaction(static function () use ($responseId, $users, $assistants, $incarnation, $fence): void {
            [$job, $turn, $surface] = self::executionRowsUnlocked($responseId);
            if (!self::ownsCurrentGeneration($job, $turn)) {
                throw new GatewayError('surface_fence_lost', 'Web chat ownership changed.', 409);
            }
            self::assertFence($surface, $incarnation, $fence);
            if ($users !== [] || $assistants !== [] || $job['status'] !== 'running'
                || $turn['status'] !== 'queued' || $surface['status'] !== 'ready'
                || !empty($job['submit_intent_at']) || (int)($job['transport_stage_sent'] ?? 0) !== 0
                || (int)($job['transport_stage_acked'] ?? 0) !== 0) {
                return;
            }
            $chat = TaskWebChat::state((string)$turn['task_id']);
            if (($chat['mode'] ?? 'temporary') !== 'regular' || !empty($chat['conversation_id'])) return;
            $db = SDB(null, 'medoo', 'sqlite');
            $existing = $db->table('gateway_task_events')->where('task_id', (string)$turn['task_id'])
                ->where('turn_id', (string)$turn['id'])->where('response_id', $responseId)
                ->where('surface_id', (string)$surface['id'])
                ->where('event_type', 'initial_regular_chat_empty_observed')->count();
            if ($existing > 0) return;
            self::eventUnlocked((string)$turn['task_id'], (string)$turn['id'], $responseId,
                (string)$surface['id'], 'initial_regular_chat_empty_observed', [
                    'generation_id'=>(string)$turn['generation_id'],
                    'document_nonce_sha256'=>hash('sha256', (string)$surface['document_nonce']),
                ]);
        });
    }

    /**
     * Retain one content-free audit of the first saved-chat UUID binding attempt.
     * This never changes ownership, deadlines, delivery state or retry state.
     * @param string $responseId Exact current response.
     * @param array $observation Allow-listed boolean transport/UI facts only.
     */
    public static function initialChatBindingObserved(string $responseId, array $observation): void
    {
        Schema::transaction(static function () use ($responseId, $observation): void {
            [$job, $turn, $surface] = self::executionRowsUnlocked($responseId);
            if (!self::ownsCurrentGeneration($job, $turn) || $job['status'] !== 'running'
                || !in_array($turn['status'], ['submit_intent', 'submitted', 'running'], true)
                || $surface['status'] !== 'busy' || (string)$turn['response_id'] !== $responseId) return;
            $db = SDB(null, 'medoo', 'sqlite');
            $existing = $db->table('gateway_task_events')->where('response_id', $responseId)
                ->where('surface_id', (string)$surface['id'])
                ->where('event_type', 'initial_chat_binding_observed')->count();
            if ($existing > 0) return;
            $data = [];
            foreach (['generation_running', 'initial_binding_witness', 'observed_submission',
                'document_changed', 'account_verified', 'submit_intent', 'empty_baseline',
                'binding_prompt'] as $key) {
                $data[$key] = isset($observation[$key]) ? (bool)$observation[$key] : null;
            }
            self::eventUnlocked((string)$turn['task_id'], (string)$turn['id'], $responseId,
                (string)$surface['id'], 'initial_chat_binding_observed', $data);
        });
    }

    public static function committed(string $responseId): void
    {
        Schema::transaction(function () use ($responseId): void {
            $db = SDB(null, 'medoo', 'sqlite');
            [$job, $turn, $surface] = self::executionRowsUnlocked($responseId);
            if (!self::ownsCurrentGeneration($job, $turn)) return;
            if ((string) $turn['status'] === 'committed') return;
            if (!in_array((string) $turn['status'], ['submitted', 'running'], true)) throw new GatewayError('turn_state_invalid', '任务回合无法提交完成。', 409);
            $now = time();
            $updated = $db->table('gateway_task_turns')->where('id', $turn['id'])->where('status', 'IN', ['submitted', 'running'])->update([
                'status' => 'committed', 'committed_at' => $now, 'updated_at' => $now,
            ]);
            if (!$updated || $updated->rowCount() !== 1) throw new GatewayError('storage_unavailable', '任务完成账本无法提交。', 503);
            $db->table('gateway_browser_surfaces')->where('id', $surface['id'])->where('status', 'busy')->update([
                'status' => 'ready', 'session_id' => null, 'updated_at' => $now, 'last_observed_at' => $now,
            ]);
            $db->table('gateway_tasks')->where('id', $turn['task_id'])->update(['updated_at' => $now]);
            self::eventUnlocked((string) $turn['task_id'], (string) $turn['id'], $responseId, (string) $surface['id'], 'turn_committed', []);
        });
    }

    public static function failed(string $responseId, string $code, bool $unknown = false): void
    {
        Schema::transaction(function () use ($responseId, $code, $unknown): void {
            $db = SDB(null, 'medoo', 'sqlite');
            try { [$job, $turn, $surface] = self::executionRowsUnlocked($responseId); }
            catch (GatewayError $error) {
                if ($error->errorCode === 'task_ledger_missing') return;
                throw $error;
            }
            if (!self::ownsCurrentGeneration($job, $turn)) return;
            if (($turn['error_code'] ?? '') === 'CODEX_USER_STOP_PENDING') return;
            if (in_array((string) $turn['status'], ['committed', 'failed', 'cancelled', 'needs_attention'], true)) return;
            $now = time();
            $status = $unknown ? 'needs_attention' : 'failed';
            $db->table('gateway_task_turns')->where('id', $turn['id'])->where('status', 'IN', self::ACTIVE_TURNS)->update([
                'status' => $status, 'error_code' => $code, 'retired_at' => $now, 'updated_at' => $now,
            ]);
            if ($unknown) {
                $db->table('gateway_browser_surfaces')->where('id', $surface['id'])->update([
                    'status' => 'needs_attention', 'session_id' => null, 'updated_at' => $now,
                ]);
                $db->table('gateway_task_epochs')->where('id', $turn['epoch_id'])->update(['status' => 'needs_attention']);
                $db->table('gateway_tasks')->where('id', $turn['task_id'])->update(['status' => 'needs_attention', 'updated_at' => $now]);
            } else {
                $db->table('gateway_browser_surfaces')->where('id', $surface['id'])->where('status', 'busy')->update([
                    'status' => 'ready', 'session_id' => null, 'updated_at' => $now,
                ]);
            }
            self::eventUnlocked((string) $turn['task_id'], (string) $turn['id'], $responseId, (string) $surface['id'],
                $unknown ? 'outcome_unknown' : 'turn_failed', ['code' => $code]);
        });
    }

    /**
     * Rewind a proven no-host-execution attempt onto a fresh epoch/surface. A missing page may also
     * resume exact delivered host receipts; active work and unknown effects are never replayed. The previous
     * Temporary Chat is retained as a retired surface so the normal cleanup path can close it.
     * ToolBroker rotates the capability token in the same SQLite transaction, invalidating any
     * late request from the completed browser page before a fresh prompt can be submitted.
     * Ordinary chats permit this replacement only after their persisted retry/refresh/resume
     * sequence failed, or after the owned current page proves the conversation itself reached
     * its native capacity terminal. The latter skips futile same-page retries but retains every
     * ownership, receipt, active-work and human-update fence.
     * Large regular-page history is planned before the writer transaction, then revalidated
     * against the current response/capability before any page or token is changed.
     *
     * @param array|null $preparedRegularContext Internal precomputed plan; an empty array
     *     records no settled proof. Null preserves planning for existing callers.
     * @return array{retry:bool,exhausted:bool,attempt:int,old_surface_id?:string,new_surface_id?:string,generation_id?:string,binding_id?:?string}
     */
    public static function prepareConnectorNoStartRetry(
        string $responseId,
        string $prompt,
        string $incarnation,
        int $fence,
        int $maxRetries = 3,
        bool $missingSurface = false,
        string $retryCause = 'missing_surface_with_complete_receipts',
        ?array $terminalProof = null,
        ?array $preparedRegularContext = null
    ): array {
        // A browser capacity recovery reads the current page after this expensive plan.
        // Revalidate its source under the writer lock without repeating planning afterward.
        $preparedRegularContext ??= $missingSurface && $maxRetries > 0
            && in_array($retryCause, ['conversation_capacity_exhausted','regular_stream_recovery_exhausted'], true)
            ? ToolBroker::prepareRegularRetryContext($responseId) : null;
        return Schema::transaction(function () use ($responseId, $prompt, $incarnation, $fence, $maxRetries,
            $missingSurface, $retryCause, $terminalProof, $preparedRegularContext): array {
            $db = SDB(null, 'medoo', 'sqlite');
            [$job, $turn, $surface] = self::executionRowsUnlocked($responseId);
            self::assertFence($surface, $incarnation, $fence);
            $chat = TaskWebChat::state((string) $turn['task_id']);
            $conversationCapacity = $missingSurface && $retryCause === 'conversation_capacity_exhausted';
            $regularExhausted = $chat['mode'] === 'regular' && $missingSurface
                && $retryCause === 'regular_stream_recovery_exhausted';
            $regularRecovery = $regularExhausted || ($chat['mode'] === 'regular' && $conversationCapacity);
            if ($chat['mode'] === 'regular' && !$regularRecovery) {
                return ['retry' => false, 'exhausted' => false, 'attempt' => 0];
            }
            $terminalFailureKey = null;
            if ($conversationCapacity) {
                $hash = static fn($value): bool => is_string($value) && preg_match('/\A[a-f0-9]{64}\z/', $value) === 1;
                $userId = is_array($terminalProof) ? (string)($terminalProof['user_id'] ?? '') : '';
                $assistantId = is_array($terminalProof) ? (string)($terminalProof['response_id'] ?? '') : '';
                if (!is_array($terminalProof) || !$hash($terminalProof['descriptor_hash'] ?? null)
                    || !$hash($terminalProof['failure_id_hash'] ?? null) || $userId === '' || $assistantId === ''
                    || strlen($userId) > 512 || strlen($assistantId) > 512
                    || !self::ownsCurrentGeneration($job, $turn)
                    || (string)($terminalProof['generation_id'] ?? '') !== (string)$job['generation_id']
                    || (string)($terminalProof['document_nonce'] ?? '') !== (string)$surface['document_nonce']
                    || empty($surface['target_id']) || empty($surface['session_id']) || $surface['status'] !== 'busy'
                    || (string)$surface['task_id'] !== (string)$job['task_id']
                    || (string)$surface['epoch_id'] !== (string)$job['epoch_id']
                    || (string)($job['submitted_document_nonce'] ?? '') !== (string)$surface['document_nonce']) {
                    return ['retry'=>false, 'exhausted'=>false, 'attempt'=>0];
                }
                if ($chat['mode'] === 'regular' && (empty($chat['conversation_id'])
                    || !hash_equals((string)$chat['conversation_id'], (string)($terminalProof['conversation_id'] ?? '')))) {
                    return ['retry'=>false, 'exhausted'=>false, 'attempt'=>0];
                }
                $terminalFailureKey = hash('sha256', json_encode([
                    'conversation_capacity_exhausted', (string)$job['generation_id'], (string)$surface['document_nonce'],
                    (string)($chat['conversation_id'] ?? ''), $userId, $assistantId,
                    (string)$terminalProof['descriptor_hash'], (string)$terminalProof['failure_id_hash'],
                ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
                if (self::regularRecoveryHumanUpdatePendingUnlocked($job, $turn)) {
                    return ['retry'=>false, 'exhausted'=>false, 'attempt'=>0, 'waiting'=>true];
                }
            }
            if ($regularExhausted) {
                $recovery = self::regularStreamRecoveryState($responseId);
                $continuation = self::continuationStateUnlocked((string) $turn['id']);
                if (($recovery['stage'] ?? '') !== 'replace' || empty($recovery['marker'])
                    || ($continuation['marker'] ?? '') !== $recovery['marker'] || !empty($continuation['pending'])
                    || ($recovery['conversation_id'] ?? '') !== ($chat['conversation_id'] ?? '')
                    || ($recovery['document_nonce'] ?? '') !== $surface['document_nonce']) {
                    return ['retry' => false, 'exhausted' => false, 'attempt' => 0];
                }
                if (self::regularRecoveryHumanUpdatePendingUnlocked($job, $turn)) {
                    return ['retry'=>false, 'exhausted'=>false, 'attempt'=>0, 'waiting'=>true];
                }
            }
            if ($maxRetries < 1
                || in_array($turn['error_code'] ?? '', ['CODEX_USER_STOP_PENDING', 'CODEX_USER_STOP_WAITING_TOOLS', 'CODEX_USER_STOPPED'], true)
                || !in_array((string)$job['status'], $missingSurface ? ['queued','running'] : ['running'], true)
                || (int) ($job['output_started'] ?? 0) !== 0
                || (string) ($job['output_text'] ?? '') !== ''
                || !in_array((string)$turn['status'], $missingSurface ? ['queued','submit_intent','submitted','running'] : ['submitted','running'], true)) {
                return ['retry' => false, 'exhausted' => false, 'attempt' => 0];
            }

            $attempts = $db->table('gateway_task_events')
                ->where('turn_id', (string) $turn['id'])
                ->where('response_id', $responseId)
                ->where('event_type', 'connector_no_start_retry_prepared')
                ->count();
            if ($attempts === false || $attempts === null) {
                throw new GatewayError('storage_unavailable', 'Connector 重试计数暂不可读取。', 503);
            }
            $attempt = (int) $attempts + 1;
            if ((int) $attempts >= $maxRetries) {
                return ['retry' => false, 'exhausted' => true, 'attempt' => (int) $attempts];
            }

            $retainedCompact = $missingSurface && !empty($job['previous_response_id'])
                && in_array($job['operation_kind'], ['compact_v1', 'compact_v2'], true);
            // A missing source page changes only the checkpoint transport: no historical
            // host permission may be replayed on the new page. Provenance remains pinned.
            $rotated = $retainedCompact
                ? ToolBroker::prepareReadOnlyCompactionReplayUnlocked($job, true)
                : ($missingSurface && empty($job['mcp_binding_id'])
                ? ContextRecovery::prepareUnboundReplayUnlocked($job)
                : ToolBroker::rotateNoStartRetryUnlocked($responseId, $prompt, $missingSurface, $regularRecovery, $preparedRegularContext));
            if (!is_array($rotated)) {
                if ($conversationCapacity) {
                    // Keep the terminal page parked while already-authorized work settles. Read
                    // this inside the same writer transaction so a completion race cannot turn a
                    // safe next-poll replacement into a false terminal failure.
                    $pending = $db->table('gateway_tool_invocations')->where('task_id', $job['task_id'])
                        ->where('status', 'IN', ['registered','delivered'])->count();
                    $bindings = $db->table('gateway_mcp_bindings')->where('task_id', $job['task_id'])->select('id');
                    if (!is_numeric($pending) || !is_array($bindings)) {
                        throw new GatewayError('storage_unavailable', 'Conversation replacement tool state is unavailable.', 503);
                    }
                    $active = 0;
                    foreach ($bindings as $candidate) {
                        $count = $db->table('gateway_mcp_activities')->where('binding_id', $candidate['id'])
                            ->where('status', 'active')->count();
                        if (!is_numeric($count)) throw new GatewayError('storage_unavailable',
                            'Conversation replacement activity state is unavailable.', 503);
                        $active += (int)$count;
                    }
                    if ((int)$pending + $active > 0) {
                        return ['retry'=>false, 'exhausted'=>false, 'attempt'=>(int)$attempts, 'waiting'=>true];
                    }
                }
                return ['retry' => false, 'exhausted' => false, 'attempt' => (int) $attempts];
            }
            // The source arrays still describe the original page/token. Rotation may have
            // rewritten the response row, so capture those pre-rotation values explicitly.
            TaskPageRetirement::captureUnlocked($surface, $job, $chat);
            if ($regularRecovery) {
                // A network recovery is the explicit exception to saved-chat reuse. Keep the
                // account pin, retire the old surface below, and bind only a witnessed new chat.
                $released = $db->table('gateway_task_web_chats')->where('task_id', $turn['task_id'])
                    ->where('conversation_id', $chat['conversation_id'])->where('account_key', $chat['account_key'])
                    ->update(['conversation_id'=>null, 'updated_at'=>time()]);
                if (!$released || $released->rowCount() !== 1) {
                    throw new GatewayError('CHATGPT_CHAT_IDENTITY_CHANGED', 'The saved Web chat identity could not be confirmed.', 409);
                }
                $rotated['prompt'] = TaskWebChat::withInitialBindingMarker($rotated['prompt'], $responseId, $turn['task_id']);
            }

            $epoch = $db->table('gateway_task_epochs')->where('id', (string) $turn['epoch_id'])->find();
            if (!is_array($epoch) || (string) $epoch['status'] !== 'active') {
                throw new GatewayError('task_epoch_invalid', 'Connector 安全重试时当前任务 Epoch 已失效。', 409);
            }
            $task = $db->table('gateway_tasks')->where('id', (string) $turn['task_id'])->find();
            if (!is_array($task) || (string) $task['status'] !== 'active') {
                throw new GatewayError('task_state_invalid', 'Connector 安全重试时任务已经不再活动。', 409);
            }

            $now = time();
            $oldSurfaceId = (string) $surface['id'];
            $retired = $db->table('gateway_browser_surfaces')
                ->where('id', $oldSurfaceId)
                ->where('fencing_version', $fence)
                ->update([
                    'status' => 'retired',
                    'session_id' => null,
                    'retired_at' => $now,
                    'updated_at' => $now,
                ]);
            if (!$retired || $retired->rowCount() !== 1) {
                throw new GatewayError('surface_fence_lost', 'Connector 安全重试时页面所有权已经变化。', 409);
            }
            $epochRetired = $db->table('gateway_task_epochs')->where('id', $epoch['id'])->where('status', 'active')->update([
                'status' => 'retired', 'retired_at' => $now,
            ]);
            if (!$epochRetired || $epochRetired->rowCount() !== 1) {
                throw new GatewayError('storage_unavailable', 'Connector 安全重试无法退休旧 Epoch。', 503);
            }
            self::eventUnlocked((string) $turn['task_id'], (string) $turn['id'], $responseId, $oldSurfaceId, 'surface_retired', [
                'reason' => 'connector_no_start_retry', 'attempt' => $attempt,
            ]);
            $continuation = self::continuationStateUnlocked((string)$turn['id']);
            if (!empty($continuation['marker'])) {
                // The new page receives the complete durable context, not the old page's
                // marker-only follow-up. Start its continuation counter from that page.
                self::eventUnlocked((string)$turn['task_id'], (string)$turn['id'], $responseId, $oldSurfaceId,
                    'task_continuation_reset', ['marker'=>(string)$continuation['marker'],'reason'=>'surface_rotated']);
            }
            if (!$retainedCompact && !empty($continuation['audit_seen']) && $retryCause === 'connector_unavailable_after_receipts') {
                $rotated['prompt'] .= "\nBefore closing this recovered turn, audit every requested outcome against the confirmed native receipts and current files. Complete any missing authorized work and verify it.\n";
            }

            $last = $db->table('gateway_task_epochs')->where('task_id', (string) $turn['task_id'])
                ->order([['epoch_no', 'DESC']])->limit(1)->find();
            $epochNo = is_array($last) ? (int) $last['epoch_no'] + 1 : (int) $epoch['epoch_no'] + 1;
            $newEpochId = 'epoch_' . bin2hex(random_bytes(12));
            if (!$db->table('gateway_task_epochs')->add([
                'id' => $newEpochId,
                'task_id' => (string) $turn['task_id'],
                'epoch_no' => $epochNo,
                'lease_key' => (string) $epoch['lease_key'],
                'model' => (string) $epoch['model'],
                'reasoning_effort' => $epoch['reasoning_effort'],
                'compaction_epoch' => (string) $epoch['compaction_epoch'],
                'status' => 'active',
                'created_at' => $now,
            ])) throw new GatewayError('storage_unavailable', 'Connector 安全重试无法创建新 Epoch。', 503);

            $newSurfaceId = 'surf_' . bin2hex(random_bytes(12));
            if (!$db->table('gateway_browser_surfaces')->add([
                'id' => $newSurfaceId,
                'task_id' => (string) $turn['task_id'],
                'epoch_id' => $newEpochId,
                'lease_key' => (string) $epoch['lease_key'],
                'status' => 'reserved',
                'generation' => 1,
                'document_nonce' => bin2hex(random_bytes(12)),
                'owner_incarnation' => 'unclaimed',
                'fencing_version' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ])) throw new GatewayError('storage_unavailable', 'Connector 安全重试无法创建新页面租约。', 503);

            $generationId = 'gen_' . bin2hex(random_bytes(12));
            $turnUpdate = $db->table('gateway_task_turns')->where('id', $turn['id'])->where('status', (string) $turn['status'])->update([
                'epoch_id' => $newEpochId,
                'surface_id' => $newSurfaceId,
                'generation_id' => $generationId,
                'status' => 'queued',
                'owner_incarnation' => 'unclaimed',
                'fencing_version' => 1,
                'deadline_at' => $now + (int)(json_decode($job['budget_json'], true)[
                    $job['operation_kind'] === 'response' ? 'response_timeout_seconds' : 'compaction_timeout_seconds'] ?? 600),
                'submit_intent_at' => null,
                'submitted_at' => null,
                'committed_at' => null,
                'retired_at' => null,
                'error_code' => null,
                'updated_at' => $now,
            ]);
            if (!$turnUpdate || $turnUpdate->rowCount() !== 1) {
                throw new GatewayError('storage_unavailable', 'Connector 安全重试无法重置任务回合。', 503);
            }
            $jobUpdate = $db->table('gateway_response_jobs')->where('id', $responseId)->where('status', (string)$job['status'])->update([
                'prompt' => (string) $rotated['prompt'],
                'epoch_id' => $newEpochId,
                'surface_id' => $newSurfaceId,
                'generation_id' => $generationId,
                'accepted_at' => null,
                'submit_intent_at' => null,
                'baseline_users_json' => '[]',
                'baseline_assistants_json' => '[]',
                'browser_prefix' => '',
                'continuation_source_response_id' => null,
                'submitted_document_nonce' => null,
                'transport_transaction_id' => null,
                'transport_parts_total' => 0,
                'transport_stage_sent' => 0,
                'transport_stage_acked' => 0,
                'transport_stage_effort' => null,
                'transport_final_effort' => null,
            ] + ($rotated['job_fields'] ?? []));
            if (!$jobUpdate || $jobUpdate->rowCount() !== 1) {
                throw new GatewayError('storage_unavailable', 'Connector 安全重试无法重置 Responses 传输状态。', 503);
            }
            // Every retry owns a fresh page, including a no-start retry of an old-page delta.
            AttachmentStore::replaceResponseBindingsUnlocked($responseId,
                AttachmentStore::selectForContext([], (string)($rotated['job_fields']['transport_context_json']
                    ?? $job['transport_context_json'] ?? $job['context_json'])));
            $taskUpdate = $db->table('gateway_tasks')->where('id', $turn['task_id'])->where('status', 'active')->update([
                'current_epoch_id' => $newEpochId, 'updated_at' => $now,
            ]);
            if (!$taskUpdate || $taskUpdate->rowCount() !== 1) {
                throw new GatewayError('storage_unavailable', 'Connector 安全重试无法更新当前 Epoch。', 503);
            }

            self::eventUnlocked((string) $turn['task_id'], (string) $turn['id'], $responseId, $newSurfaceId, 'surface_reserved', [
                'epoch_id' => $newEpochId, 'epoch_no' => $epochNo, 'retry_attempt' => $attempt,
            ]);
            self::eventUnlocked((string) $turn['task_id'], (string) $turn['id'], $responseId, $newSurfaceId, 'connector_no_start_retry_prepared', [
                'attempt' => $attempt,
                'cause' => $missingSurface ? $retryCause : 'missing_required_tool_call',
                'binding_id' => $rotated['binding_id'] === null ? null : (string) $rotated['binding_id'],
                'old_surface_id' => $oldSurfaceId,
                'new_surface_id' => $newSurfaceId,
                'generation_id' => $generationId,
            ] + ($terminalFailureKey === null ? [] : ['terminal_failure_key'=>$terminalFailureKey]));
            return [
                'retry' => true,
                'exhausted' => false,
                'attempt' => $attempt,
                'old_surface_id' => $oldSurfaceId,
                'new_surface_id' => $newSurfaceId,
                'generation_id' => $generationId,
                'binding_id' => $rotated['binding_id'] === null ? null : (string) $rotated['binding_id'],
            ];
        });
    }

    /**
     * Reconcile interrupted ledger states from persisted submission and acceptance evidence.
     * A compact accepted before interruption retains the original acceptance-based deadline;
     * recovery never starts a new generation or extends a previously submitted summary.
     * @return array{safe_queued:list<string>,resume:list<string>,unknown:list<string>}
     * @throws GatewayError If the recovery ledger cannot be read or a transition cannot commit.
     */
    public static function recoverInterrupted(): array
    {
        return Schema::transaction(function (): array {
            $db = SDB(null, 'medoo', 'sqlite');
            $turns = $db->table('gateway_task_turns')->where('status', 'IN', self::ACTIVE_TURNS)->select();
            if ($turns === false) throw new GatewayError('storage_unavailable', '任务恢复账本暂不可读取。', 503);
            $safe = []; $resume = []; $unknown = [];
            foreach ($turns as $turn) {
                $responseId = (string) ($turn['response_id'] ?? '');
                if ($responseId === '') continue;
                $job = $db->table('gateway_response_jobs')->where('id', $responseId)->find();
                $surface = $db->table('gateway_browser_surfaces')->where('id', (string) $turn['surface_id'])->find();
                if (!is_array($job) || !is_array($surface)) continue;
                // Explicit stop recovery belongs to the stop observer, never the resend/resume queue.
                if (($turn['error_code'] ?? '') === 'CODEX_USER_STOP_PENDING') continue;
                $jobStatus = (string) ($job['status'] ?? '');
                if ($jobStatus === 'completed') {
                    if ((string) ($job['round_kind'] ?? '') === 'tool_call') {
                        if ((string) ($surface['target_id'] ?? '') !== '' && (string) $surface['status'] !== 'retired') {
                            $resume[] = $responseId;
                            self::eventUnlocked(
                                (string) $turn['task_id'],
                                (string) $turn['id'],
                                $responseId,
                                (string) $surface['id'],
                                'recovery_tool_round_waiting',
                                ['binding_id' => $job['mcp_binding_id'] ?? null]
                            );
                            continue;
                        }
                        $now = time();
                        $unknown[] = $responseId;
                        $db->table('gateway_task_turns')->where('id', $turn['id'])->update([
                            'status' => 'needs_attention',
                            'error_code' => 'RECOVERY_TOOL_SURFACE_UNKNOWN',
                            'updated_at' => $now,
                        ]);
                        $db->table('gateway_browser_surfaces')->where('id', $surface['id'])->update([
                            'status' => 'needs_attention',
                            'updated_at' => $now,
                        ]);
                        $db->table('gateway_tasks')->where('id', $turn['task_id'])->update([
                            'status' => 'needs_attention',
                            'updated_at' => $now,
                        ]);
                        self::eventUnlocked(
                            (string) $turn['task_id'],
                            (string) $turn['id'],
                            $responseId,
                            (string) $surface['id'],
                            'recovery_outcome_unknown',
                            ['code' => 'RECOVERY_TOOL_SURFACE_UNKNOWN']
                        );
                        continue;
                    }
                    $now = time();
                    $db->table('gateway_task_turns')->where('id', $turn['id'])->where('status', 'IN', self::ACTIVE_TURNS)->update([
                        'status' => 'committed',
                        'committed_at' => $job['finished_at'] ?? $now,
                        'updated_at' => $now,
                    ]);
                    $db->table('gateway_browser_surfaces')->where('id', $surface['id'])->where('status', 'busy')->update([
                        'status' => 'ready', 'session_id' => null, 'updated_at' => $now,
                    ]);
                    self::eventUnlocked((string) $turn['task_id'], (string) $turn['id'], $responseId, (string) $surface['id'], 'recovery_committed', []);
                    continue;
                }
                if ($jobStatus === 'failed') {
                    $code = 'response_failed';
                    if (is_string($job['error_json'] ?? null) && $job['error_json'] !== '') {
                        try {
                            $decoded = json_decode((string) $job['error_json'], true, 32, JSON_THROW_ON_ERROR);
                            if (is_array($decoded) && is_string($decoded['error']['code'] ?? null)) $code = $decoded['error']['code'];
                        } catch (\Throwable) {}
                    }
                    $unknownFailure = self::unknownFailureCode($code);
                    $now = time();
                    $db->table('gateway_task_turns')->where('id', $turn['id'])->where('status', 'IN', self::ACTIVE_TURNS)->update([
                        'status' => $unknownFailure ? 'needs_attention' : 'failed',
                        'error_code' => $code,
                        'retired_at' => $job['finished_at'] ?? $now,
                        'updated_at' => $now,
                    ]);
                    $db->table('gateway_browser_surfaces')->where('id', $surface['id'])->update([
                        'status' => $unknownFailure ? 'needs_attention' : 'ready',
                        'session_id' => null,
                        'updated_at' => $now,
                    ]);
                    if ($unknownFailure) $db->table('gateway_tasks')->where('id', $turn['task_id'])->update(['status' => 'needs_attention', 'updated_at' => $now]);
                    self::eventUnlocked((string) $turn['task_id'], (string) $turn['id'], $responseId, (string) $surface['id'],
                        $unknownFailure ? 'recovery_outcome_unknown' : 'recovery_failed', ['code' => $code]);
                    continue;
                }
                $state = (string) $turn['status'];
                if ($state === 'submit_intent' && $job['accepted_at'] !== null) {
                    $recoveredValues = [
                        'status' => 'submitted',
                        'submitted_at' => $job['accepted_at'],
                        'updated_at' => time(),
                    ];
                    if (in_array((string) ($job['operation_kind'] ?? 'response'), ['compact_v1', 'compact_v2'], true)) {
                        $budget = json_decode((string) $job['budget_json'], true, 32, JSON_THROW_ON_ERROR);
                        $recoveredValues['deadline_at'] = (int) $job['accepted_at']
                            + (int) ($budget['compaction_timeout_seconds'] ?? 180);
                    }
                    $updated = $db->table('gateway_task_turns')->where('id', $turn['id'])
                        ->where('status', 'submit_intent')->update($recoveredValues);
                    if (!$updated || $updated->rowCount() !== 1) {
                        throw new GatewayError('storage_unavailable', '恢复页面接收证据无法提交。', 503);
                    }
                    $state = 'submitted';
                    $turn['status'] = 'submitted';
                    self::eventUnlocked((string) $turn['task_id'], (string) $turn['id'], $responseId, (string) $surface['id'], 'recovery_submitted', []);
                }
                if ($state === 'submitted' && (string) ($job['output_text'] ?? '') !== '') {
                    $db->table('gateway_task_turns')->where('id', $turn['id'])->where('status', 'submitted')->update([
                        'status' => 'running', 'updated_at' => time(),
                    ]);
                    $state = 'running';
                    $turn['status'] = 'running';
                    self::eventUnlocked((string) $turn['task_id'], (string) $turn['id'], $responseId, (string) $surface['id'], 'recovery_running', []);
                }
                // Multipart staging already changed the remote Temporary Chat even though the
                // final Codex task has not reached submit_intent yet. Treat it as a resumable
                // browser observation, never as a safe unsent turn that may be replayed.
                if ($state === 'queued' && (int) ($job['transport_stage_sent'] ?? 0) > 0) {
                    if ((string) ($surface['target_id'] ?? '') !== '' && (string) $surface['status'] !== 'retired') {
                        $resume[] = $responseId;
                        self::eventUnlocked(
                            (string) $turn['task_id'],
                            (string) $turn['id'],
                            $responseId,
                            (string) $surface['id'],
                            'recovery_multipart_reconcile',
                            [
                                'stage_sent' => (int) ($job['transport_stage_sent'] ?? 0),
                                'stage_acked' => (int) ($job['transport_stage_acked'] ?? 0),
                            ]
                        );
                        continue;
                    }
                    $unknown[] = $responseId;
                    $now = time();
                    $db->table('gateway_task_turns')->where('id', $turn['id'])->update([
                        'status' => 'needs_attention',
                        'error_code' => 'RECOVERY_MULTIPART_SURFACE_UNKNOWN',
                        'updated_at' => $now,
                    ]);
                    $db->table('gateway_browser_surfaces')->where('id', $surface['id'])->update([
                        'status' => 'needs_attention', 'updated_at' => $now,
                    ]);
                    $db->table('gateway_tasks')->where('id', $turn['task_id'])->update([
                        'status' => 'needs_attention', 'updated_at' => $now,
                    ]);
                    self::eventUnlocked(
                        (string) $turn['task_id'],
                        (string) $turn['id'],
                        $responseId,
                        (string) $surface['id'],
                        'recovery_outcome_unknown',
                        ['code' => 'RECOVERY_MULTIPART_SURFACE_UNKNOWN']
                    );
                    continue;
                }
                if ($state === 'queued') {
                    $safe[] = $responseId;
                    self::eventUnlocked((string) $turn['task_id'], (string) $turn['id'], $responseId, (string) $surface['id'], 'recovery_unsent', []);
                    continue;
                }
                if (in_array($state, ['submit_intent', 'submitted', 'running'], true)
                    && (string) ($surface['target_id'] ?? '') !== ''
                    && (string) $surface['status'] !== 'retired') {
                    $resume[] = $responseId;
                    self::eventUnlocked((string) $turn['task_id'], (string) $turn['id'], $responseId, (string) $surface['id'], 'recovery_reconcile', ['state' => $state]);
                    continue;
                }
                $unknown[] = $responseId;
                $db->table('gateway_task_turns')->where('id', $turn['id'])->update([
                    'status' => 'needs_attention', 'error_code' => 'RECOVERY_OUTCOME_UNKNOWN', 'updated_at' => time(),
                ]);
                $db->table('gateway_browser_surfaces')->where('id', $surface['id'])->update(['status' => 'needs_attention', 'updated_at' => time()]);
                $db->table('gateway_tasks')->where('id', $turn['task_id'])->update(['status' => 'needs_attention', 'updated_at' => time()]);
                self::eventUnlocked((string) $turn['task_id'], (string) $turn['id'], $responseId, (string) $surface['id'], 'outcome_unknown', ['code' => 'RECOVERY_OUTCOME_UNKNOWN']);
            }
            return ['safe_queued' => $safe, 'resume' => $resume, 'unknown' => $unknown];
        });
    }

    /** Current durable execution identity used by the browser worker. */
    public static function execution(string $responseId): array
    {
        $job = SDB(null, 'medoo', 'sqlite')->table('gateway_response_jobs')->where('id', $responseId)->find();
        return self::executionLedger($job);
    }

    /**
     * Read the same fresh turn/surface fence as execution(), excluding large response payloads.
     * @param string $responseId Logical Responses round being observed by the browser worker.
     * @return array{job:array,turn:array,surface:array} Current metadata; never a cached ownership proof.
     * @throws GatewayError If the response, turn or surface cannot be read.
     */
    public static function pollExecution(string $responseId): array
    {
        try {
            $job = ResponseJob::pollState($responseId);
        } catch (GatewayError $error) {
            if ($error->errorCode !== 'response_not_found') throw $error;
            // Preserve execution()'s missing-ledger diagnostic for browser observations.
            throw new GatewayError('task_ledger_missing', 'Response 尚未绑定持久任务账本。', 503);
        }
        return self::executionLedger($job);
    }

    /** Resolve the existing turn and surface validation for either full or metadata-only job reads. */
    private static function executionLedger(array|false|null $job): array
    {
        if (!is_array($job) || empty($job['durable_turn_id']) || empty($job['surface_id'])) {
            throw new GatewayError('task_ledger_missing', 'Response 尚未绑定持久任务账本。', 503);
        }
        $turn = SDB(null, 'medoo', 'sqlite')->table('gateway_task_turns')->where('id', (string) $job['durable_turn_id'])->find();
        $surface = SDB(null, 'medoo', 'sqlite')->table('gateway_browser_surfaces')->where('id', (string) $job['surface_id'])->find();
        if (!is_array($turn) || !is_array($surface)) throw new GatewayError('task_ledger_missing', '任务回合或页面记录缺失。', 503);
        return ['job' => $job, 'turn' => $turn, 'surface' => $surface];
    }

    /**
     * Record the verified Connector preparation once before sending, under the current page fence.
     * @param string $responseId Current response; $mode is retained or mention, never page content.
     * @throws GatewayError When page ownership or durable storage is unavailable.
     */
    public static function connectorPrepared(string $responseId, string $mode, string $incarnation, int $fence): void
    {
        Schema::transaction(function () use ($responseId, $mode, $incarnation, $fence): void {
            [$job, $turn, $surface] = self::executionRowsUnlocked($responseId);
            if (!self::ownsCurrentGeneration($job, $turn)) {
                throw new GatewayError('response_superseded', '旧网页回合已被新的用户指令接管。', 409);
            }
            self::assertFence($surface, $incarnation, $fence);
            self::eventUnlocked($job['task_id'], $turn['id'], $responseId, $surface['id'], 'connector_prepared', [
                'mode' => $mode, 'generation' => (int) $surface['generation'],
                'continuation_source_response_id' => $job['continuation_source_response_id'],
            ]);
        });
    }

    /**
     * Persist a bounded task-session identity verification without changing task completion.
     * @param string $responseId Current response owning this task session.
     * @param bool $verified Whether the task document reflects the successfully applied native identity.
     * @param string $code Gateway-authored verification phase, without browser/account content.
     * @param int $attempt Bounded initialization attempt count.
     * @param string $incarnation Observer incarnation claiming the surface.
     * @param int $fence Exact ownership fence; stale callbacks cannot record against a newer owner.
     * @throws GatewayError For a superseded response or changed ownership.
     */
    public static function browserIdentityObserved(string $responseId, bool $verified, string $code, int $attempt,
        string $incarnation, int $fence): void
    {
        Schema::transaction(function () use ($responseId, $verified, $code, $attempt, $incarnation, $fence): void {
            [$job, $turn, $surface] = self::executionRowsUnlocked($responseId);
            if (!self::ownsCurrentGeneration($job, $turn)) {
                throw new GatewayError('response_superseded', '旧网页回合已被新的用户指令接管。', 409);
            }
            self::assertFence($surface, $incarnation, $fence);
            self::eventUnlocked($job['task_id'], $turn['id'], $responseId, $surface['id'], 'browser_identity_observed',
                ['ready'=>$verified, 'code'=>$code, 'attempt'=>$attempt]);
        });
    }

    /**
     * Preserve the actual Page.enable failure without storing browser content or CDP payloads.
     * @param string $responseId Physical observer or current response on this generation.
     * @param array $error CDP error; only its numeric code and known transport reason are retained.
     * @param float $waitSeconds Preparation budget used, bounded by the existing turn deadline.
     * @param string $incarnation Browser worker owning the claimed surface.
     * @param int $fence Exact ownership fence; stale diagnostics must not cross generations.
     * @return bool Whether the diagnostic belongs to the still-owned task generation.
     * @throws GatewayError On unavailable storage or changed ownership.
     */
    public static function browserPageInitializationFailed(string $responseId, array $error, float $waitSeconds,
        string $incarnation, int $fence): bool
    {
        $reason = (string) ($error['message'] ?? '');
        $facts = ['method'=>'Page.enable', 'cdp_code'=>max(-2147483648, min(2147483647, (int) ($error['code'] ?? 0))),
            'cause'=>in_array($reason, ['BROWSER_CDP_TIMEOUT','BROWSER_CDP_DISCONNECTED','BROWSER_CDP_ERROR','BROWSER_CDP_SEND_FAILED'], true)
                ? $reason : 'CDP_PROTOCOL_ERROR',
            'wait_budget_ms'=>(int) round(max(0.0, min(600.0, $waitSeconds)) * 1000)];
        return Schema::transaction(function () use ($responseId, $facts, $incarnation, $fence): bool {
            [$job, $turn, $surface] = self::executionRowsUnlocked($responseId);
            if ($job['generation_id'] !== $turn['generation_id'] || $job['surface_id'] !== $turn['surface_id']
                || in_array($turn['status'], ['failed','cancelled','superseded','committed','needs_attention'], true)) return false;
            self::assertFence($surface, $incarnation, $fence);
            self::eventUnlocked($job['task_id'], $turn['id'], $responseId, $surface['id'],
                'browser_page_initialization_failed', $facts);
            return true;
        });
    }

    /**
     * Choose recovery for a stopped ordinary task page after host/MCP work has drained.
     * Completed host work can reserve a bounded same-page continuation when every receipt
     * was delivered; otherwise it requires exact-receipt fresh-page recovery. Native Retry
     * remains limited to tasks without completed host work. Terminal historical bindings
     * with no active RPC cannot keep an unrelated current turn waiting indefinitely.
     * A closed historical wait is drained only when the current context proves its exact
     * saved receipt; this never acknowledges delivery to the retired Web page.
     * Completed receipts already canonically handed off from older bindings are also drained.
     * @param string $responseId Current logical ordinary Responses round.
     * @param string $incarnation Browser worker owning the exact surface.
     * @param int $fence Surface ownership version.
     * @param string $documentNonce Exact retained page identity.
     * @param string $failureKey SHA-256 of the observed native error-card identity.
     * @param bool $nativeControlAvailable Whether the observed native Retry can be clicked now.
     * A disabled button cannot reserve a click or prevent receipt-based page recovery.
     * @param bool $preferSamePage Caller proved a stopped, owned page with a returned Send button.
     * @param array|null $regularRecovery Ordinary-chat-only anchored document facts; stopped_thinking also requires fresh baseline IDs.
     * @return array{retry:bool,waiting:bool,exhausted:bool,attempt:int,recover?:bool,continue?:bool,marker?:string,deadline_at?:int,regular_action?:string,state?:array,published?:bool}
     * @throws GatewayError For changed ownership, unknown effects or unavailable storage.
     */
    public static function reserveTaskNetworkRetry(string $responseId, string $incarnation,
        int $fence, string $documentNonce, string $failureKey, bool $nativeControlAvailable = true,
        bool $preferSamePage = false, ?array $regularRecovery = null): array
    {
        if (preg_match('/\A[a-f0-9]{64}\z/D', $failureKey) !== 1) {
            throw new GatewayError('task_network_retry_invalid', '网页网络重试标识无效。', 400);
        }
        return Schema::transaction(function () use ($responseId, $incarnation, $fence, $documentNonce, $failureKey, $nativeControlAvailable, $preferSamePage, $regularRecovery): array {
            $db = SDB(null, 'medoo', 'sqlite');
            [$job, $turn, $surface] = self::executionRowsUnlocked($responseId);
            self::assertFence($surface, $incarnation, $fence);
            $task = $db->table('gateway_tasks')->where('id', $job['task_id'])->find();
            $epoch = $db->table('gateway_task_epochs')->where('id', $job['epoch_id'])->find();
            $binding = empty($job['mcp_binding_id']) ? null
                : $db->table('gateway_mcp_bindings')->where('id', $job['mcp_binding_id'])->find();
            $regularAction = (string)($regularRecovery['action'] ?? '');
            $expiredIdle = $regularAction === 'expired_idle';
            $expiredIdleState = $expiredIdle ? self::regularStreamRecoveryState($responseId) : [];
            $expiredIdleReserved = $expiredIdle
                && ($expiredIdleState['stage'] ?? '') === 'resume'
                && ($expiredIdleState['failure_key'] ?? '') === $failureKey;
            $stoppedBeforeStart = in_array($regularAction, ['stopped_thinking', 'rate_limit'], true)
                && is_array($binding) && $binding['status'] === 'awaiting_start' && !empty($binding['sent_at']);
            if (!self::ownsCurrentGeneration($job, $turn) || $job['status'] !== 'running'
                || $job['operation_kind'] !== 'response' || !in_array($turn['status'], ['submitted','running'], true)
                || ($expiredIdle
                    ? ((int)$turn['deadline_at'] > time() && !$expiredIdleReserved)
                    : (int)$turn['deadline_at'] <= time())
                || $surface['status'] !== 'busy'
                || empty($surface['target_id']) || empty($surface['session_id']) || $documentNonce === ''
                || $surface['document_nonce'] !== $documentNonce || $surface['task_id'] !== $job['task_id']
                || $surface['epoch_id'] !== $job['epoch_id'] || $turn['owner_incarnation'] !== $incarnation
                || (int)$turn['fencing_version'] !== $fence
                || !is_array($task) || $task['status'] !== 'active' || $task['current_epoch_id'] !== $job['epoch_id']
                || !is_array($epoch) || $epoch['status'] !== 'active' || $epoch['task_id'] !== $job['task_id']
                || (!empty($job['mcp_binding_id']) && (!is_array($binding) || ($binding['status'] !== 'running' && !$stoppedBeforeStart)
                    || $binding['current_response_id'] !== $responseId || $binding['task_id'] !== $job['task_id']
                    || $binding['turn_id'] !== $turn['id']))) {
                throw new GatewayError('task_network_retry_unavailable', '网页重试的当前回合或页面所有权已变化。', 409);
            }
            $idle = ['retry'=>false,'waiting'=>false,'exhausted'=>false,'attempt'=>0];
            if ($regularRecovery !== null && self::regularRecoveryHumanUpdatePendingUnlocked($job, $turn)) {
                return array_replace($idle, ['waiting'=>true]);
            }
            if (($regularRecovery['action'] ?? '') === 'refresh_observed' && is_array($binding)) {
                // Observing the exact answer after a reload sends no message and rotates no
                // capability. Do not wait for the MCP activity that the recovered answer may
                // already be using; doing so can keep the recovery fence alive indefinitely.
                $completed = $db->table('gateway_tool_invocations')->where('binding_id', $binding['id'])
                    ->where('status', 'completed')->count();
                if (!is_numeric($completed)) throw new GatewayError('storage_unavailable', 'Recovery activity could not be read.', 503);
                return self::reserveRegularStreamRecoveryUnlocked($job, $turn, $surface, $binding,
                    $failureKey, false, (int)$completed, $regularRecovery, $idle);
            }
            // Native regeneration must never rewrite final text already committed to Codex.
            if (!empty($job['output_started']) || (string)$job['output_text'] !== '') {
                return $regularRecovery === null ? $idle : array_replace($idle, ['published'=>true]);
            }
            $calls = $db->table('gateway_tool_invocations')->where('task_id', $job['task_id'])->select();
            $bindings = $db->table('gateway_mcp_bindings')->where('task_id', $job['task_id'])->select();
            if (!is_array($calls) || !is_array($bindings)) {
                throw new GatewayError('storage_unavailable', '网页重试的工具状态暂不可读取。', 503);
            }
            $waiting = false;
            $hasCompletedWork = false;
            $currentCompletedCalls = 0;
            $allReceiptsDelivered = true;
            $closedWaitFailure = null;
            foreach ($calls as $call) {
                if ($call['status'] === 'unknown') {
                    throw new GatewayError('task_network_retry_unknown', '工具执行结果未知，不能自动重试网页回答。', 409);
                }
                if ($call['status'] === 'cancelled') {
                    $result = json_decode((string)($call['result_json'] ?? ''), true);
                    if (empty($result['compaction_interrupted']) && empty($result['steering_interrupted'])) {
                        throw new GatewayError('task_network_retry_unknown', '已取消工具缺少执行前中止证据，不能自动重试网页回答。', 409);
                    }
                }
                if (in_array($call['status'], ['registered','delivered'], true)) $waiting = true;
                if ($call['status'] === 'completed') $hasCompletedWork = true;
                if ($call['status'] === 'completed' && $call['binding_id'] === ($binding['id'] ?? null)) $currentCompletedCalls++;
                if ($call['status'] === 'completed' && ($call['result_delivered_at'] === null || $call['result_json'] === null)) {
                    // Canonical handoff preserved this result in a later round. Its retired
                    // Web wait remains unacknowledged, but is no longer pending delivery here.
                    $result = is_string($call['result_json']) ? json_decode($call['result_json'], true) : null;
                    if (!empty($result['canonical_handoff_response_id'])
                        && ($call['binding_id'] !== ($binding['id'] ?? null)
                            || ToolBroker::canonicalSteeringHandoffCoversReceiptUnlocked($call, $job))) continue;
                    if ($call['binding_id'] !== ($binding['id'] ?? null)
                        && (ToolBroker::closedWaitReceiptForCompactionUnlocked($call, $job, (string)$job['task_id'])
                            || ToolBroker::completedHistoricalReceiptForRecoveryUnlocked($call, $job))) {
                        // An old failed wait or a committed successor proves the exact result
                        // is covered here. Its missing MCP ACK is not current in-flight work.
                        continue;
                    }
                    $allReceiptsDelivered = false;
                    $closedWaitFailure ??= ToolBroker::settledWaitTransportFailureForResponse($responseId);
                    if ($call['binding_id'] === ($binding['id'] ?? null)
                        && in_array($closedWaitFailure, ['tool_wait_scope_changed', 'tool_receipt_transport_lost',
                            'tool_receipt_wait_closed', 'tool_result_transport_failed'], true)
                        && $call['result_json'] !== null) {
                        // The old wait failed conclusively, rather than remaining in flight.
                        // Reuse the exact receipt/checkpoint coverage proof; do not acknowledge
                        // delivery to the Web page or execute the completed host action again.
                        ContextCompaction::assertClosedWaitReceiptCoverageUnlocked($job, $call, $job['task_id']);
                    } else $waiting = true;
                }
            }
            foreach ($bindings as $candidate) {
                $active = $db->table('gateway_mcp_activities')->where('binding_id', $candidate['id'])->where('status', 'active')->count();
                if (!is_numeric($active)) throw new GatewayError('storage_unavailable', '网页重试的 MCP 活动状态暂不可读取。', 503);
                if ((int)$active > 0) $waiting = true;
                if ($candidate['id'] !== ($binding['id'] ?? null)
                    && in_array($candidate['status'], ['awaiting_start','running'], true)) {
                    $priorTurn = $db->table('gateway_task_turns')->where('id', $candidate['turn_id'])->find('status,task_id');
                    $priorJob = $db->table('gateway_response_jobs')->where('id', $candidate['current_response_id'])->find('status,task_id,durable_turn_id');
                    // Failed uploads in old databases can leave an unused binding awaiting_start.
                    // Ignore only a conclusively terminal owner; active RPCs/calls above still block.
                    if (!is_array($priorTurn) || !is_array($priorJob)
                        || $priorTurn['task_id'] !== $job['task_id'] || $priorJob['task_id'] !== $job['task_id']
                        || $priorJob['durable_turn_id'] !== $candidate['turn_id']
                        || !in_array($priorTurn['status'], ['committed','failed','cancelled'], true)
                        || !in_array($priorJob['status'], ['completed','failed','cancelled'], true)) $waiting = true;
                }
            }
            if ($waiting) return array_replace($idle, ['waiting'=>true]);
            if ($regularRecovery !== null) {
                if (!$allReceiptsDelivered) return array_replace($idle, ['waiting'=>true]);
                return self::reserveRegularStreamRecoveryUnlocked($job, $turn, $surface, $binding,
                    $failureKey, $nativeControlAvailable, $currentCompletedCalls, $regularRecovery, $idle);
            }
            if ($preferSamePage && $allReceiptsDelivered && $currentCompletedCalls > 0) {
                return self::reserveStreamContinuationUnlocked($job, $turn, $surface, $binding,
                    $failureKey, $currentCompletedCalls, $idle);
            }
            if ($hasCompletedWork) return array_replace($idle, ['recover'=>true]);
            if (!$nativeControlAvailable) return $idle;
            $events = $db->table('gateway_task_events')->where('turn_id', $turn['id'])
                ->where('event_type', 'page_network_retry_intent')->order([['id','ASC']])->select('data_json');
            if (!is_array($events)) throw new GatewayError('storage_unavailable', '网页重试次数暂不可读取。', 503);
            $attempts = 0;
            foreach ($events as $event) {
                $data = json_decode((string)$event['data_json'], true, 128, JSON_THROW_ON_ERROR);
                if (($data['generation_id'] ?? null) !== $job['generation_id'] || ($data['document_nonce'] ?? null) !== $documentNonce) continue;
                $attempts++;
                if (($data['failure_key'] ?? null) === $failureKey) return array_replace($idle, ['attempt'=>$attempts]);
            }
            if ($attempts >= 3) return array_replace($idle, ['exhausted'=>true,'attempt'=>$attempts]);
            self::eventUnlocked($job['task_id'], $turn['id'], $responseId, $surface['id'], 'page_network_retry_intent',
                ['failure_key'=>$failureKey,'attempt'=>$attempts+1,'generation_id'=>$job['generation_id'],
                    'document_nonce'=>$documentNonce,'operation_kind'=>'response']);
            return array_replace($idle, ['retry'=>true,'attempt'=>$attempts+1]);
        });
    }

    /**
     * Read the current generation's ordinary-chat recovery intent, including after restart.
     * @param string $responseId Current logical response; historical generations never authorize actions.
     * @return array Persisted phase/ownership facts, or an empty state for legacy and temporary turns.
     * @throws GatewayError If recovery storage cannot be read.
     */
    public static function regularStreamRecoveryState(string $responseId): array
    {
        $db = SDB(null, 'medoo', 'sqlite');
        $job = $db->table('gateway_response_jobs')->where('id', $responseId)->find('durable_turn_id,generation_id');
        if ($job === false) throw new GatewayError('storage_unavailable', 'Web recovery state is temporarily unavailable.', 503);
        if (!is_array($job) || empty($job['durable_turn_id'])) return [];
        $row = $db->table('gateway_task_events')->where('turn_id', $job['durable_turn_id'])
            ->where('event_type', 'regular_stream_recovery_intent')->order([['id','DESC']])->limit(1)->find('data_json');
        if ($row === false) throw new GatewayError('storage_unavailable', 'Web recovery state is temporarily unavailable.', 503);
        $state = is_array($row) ? json_decode((string) $row['data_json'], true, 32, JSON_THROW_ON_ERROR) : [];
        return ($state['generation_id'] ?? null) === $job['generation_id'] ? $state : [];
    }

    /**
     * Fence new host actions during native regeneration and refresh; old call IDs still return receipts.
     * @param string $responseId Capability's current response. @return bool True until the exact resume message is accepted.
     * @throws GatewayError On unavailable recovery or continuation storage.
     */
    public static function regularStreamRecoveryPending(string $responseId): bool
    {
        $state = self::regularStreamRecoveryState($responseId);
        if ($state === []) return false;
        // A fresh document that still owns the same answer has been handed back to
        // normal polling. It must be allowed to call tools while the recovered Web
        // generation continues; no gateway message is pending in this state.
        if (($state['stage'] ?? '') === 'reattached') return false;
        if (($state['stage'] ?? '') !== 'resume') return true;
        $continuation = self::continuationState($responseId);
        return ($continuation['marker'] ?? '') !== ($state['marker'] ?? '') || !empty($continuation['pending']);
    }

    /** A queued human update or explicit stop always takes priority over automated ordinary recovery. */
    private static function regularRecoveryHumanUpdatePendingUnlocked(array $job, array $turn): bool
    {
        if (in_array($turn['error_code'] ?? '', ['CODEX_USER_STOP_PENDING','CODEX_USER_STOP_WAITING_TOOLS','CODEX_USER_STOPPED'], true)) return true;
        $updates = self::pendingOperationalContextUnlocked($job);
        foreach ($updates['messages'] as $message) {
            if (($message['role'] ?? '') === 'user' && !ContextHistory::isOperationalUserMessage($message)) return true;
        }
        return false;
    }

    /**
     * Advance only a drained ordinary response. A stopped saved chat is refreshed first so a
     * server-side generation hidden by a stale page can be reattached without sending anything.
     * A confirmed stopped fresh document may then use Retry or a short resume before the fenced
     * fresh-chat fallback. Explicit stopped-thinking terminals retain their existing bounded
     * same-page continuation contract.
     * Caller owns the writer transaction and has validated task, capability, deadline and document.
     * @param array $facts Current bound user/assistant IDs and document time origin; resume adds fresh baseline proof.
     * @return array One durable action, bounded wait, or a request for the separately fenced fresh-chat fallback.
     * @throws GatewayError On changed chat/account/ownership or unavailable storage.
     */
    private static function reserveRegularStreamRecoveryUnlocked(array $job, array $turn, array $surface,
        ?array $binding, string $failureKey, bool $nativeControl, int $hostCalls, array $facts, array $idle): array
    {
        $chat = TaskWebChat::state((string) $job['task_id']);
        if ($chat['mode'] !== 'regular' || empty($chat['conversation_id']) || empty($chat['account_key'])
            || !is_array($binding) || empty($facts['user_id']) || empty($facts['assistant_id'])
            || !is_string($facts['document_id'] ?? null) || $facts['document_id'] === '') {
            throw new GatewayError('CHATGPT_CHAT_IDENTITY_CHANGED', 'The saved Web chat identity could not be confirmed.', 409);
        }
        $state = self::regularStreamRecoveryState($job['id']);
        if ($state !== [] && (($state['surface_id'] ?? '') !== $surface['id']
            || ($state['conversation_id'] ?? '') !== $chat['conversation_id'])) {
            throw new GatewayError('surface_fence_lost', 'Web recovery ownership changed.', 409);
        }
        // New, completed host work proves the preceding recovery resumed real execution.
        // A later incident starts with a fresh observation, rather than discarding a healthy chat.
        if (in_array($state['stage'] ?? '', ['resume','reattached'], true)
            && $hostCalls > (int) $state['host_calls']
            && !self::regularStreamRecoveryPending($job['id'])) $state = [];
        $expiredIdle = ($facts['action'] ?? '') === 'expired_idle';
        $stoppedThinking = ($facts['action'] ?? '') === 'stopped_thinking';
        $rateLimited = ($facts['action'] ?? '') === 'rate_limit'
            && preg_match('/\A[a-f0-9]{64}\z/D', (string)($facts['rate_limit_key'] ?? '')) === 1;
        $resumeAfterRetry = ($facts['action'] ?? '') === 'retry_settled';
        $refreshObserved = ($facts['action'] ?? '') === 'refresh_observed';
        if ($expiredIdle) {
            if (empty($facts['terminal']) || $hostCalls < 1
                || !is_array($facts['user_ids'] ?? null) || !is_array($facts['assistant_ids'] ?? null)
                || !in_array($facts['user_id'], $facts['user_ids'], true)
                || !in_array($facts['assistant_id'], $facts['assistant_ids'], true)) {
                throw new GatewayError('CHATGPT_CHAT_IDENTITY_CHANGED', 'The saved Web chat identity could not be confirmed.', 409);
            }
            if (($state['stage'] ?? '') === 'resume' && ($state['failure_key'] ?? '') === $failureKey) {
                return array_replace($idle, ['waiting'=>true]);
            }
            $continued = self::reserveStreamContinuationUnlocked($job, $turn, $surface, $binding,
                $failureKey, $hostCalls, $idle, 'response_timeout_recovery');
            if (empty($continued['continue'])) return $continued;
            $stage = 'resume';
        } elseif ($rateLimited) {
            if (empty($facts['terminal']) || !is_array($facts['user_ids'] ?? null) || !is_array($facts['assistant_ids'] ?? null)
                || !in_array($facts['user_id'], $facts['user_ids'], true) || !in_array($facts['assistant_id'], $facts['assistant_ids'], true)) {
                throw new GatewayError('CHATGPT_CHAT_IDENTITY_CHANGED', 'The saved Web chat identity could not be confirmed.', 409);
            }
            if (($state['failure_key'] ?? '') === $failureKey) {
                return array_replace($idle, ['waiting'=>true]);
            }
            $rateAttempts = (int)($state['rate_limit_attempts'] ?? 0) + 1;
            if ($rateAttempts > 64) return array_replace($idle, ['exhausted'=>true]);
            if ($hostCalls > 0) {
                // Regenerating an answer that already called native tools can repeat work.
                // Resume with the existing exact receipts and a unique control-only marker.
                $continued = self::reserveStreamContinuationUnlocked($job, $turn, $surface, $binding,
                    $failureKey, $hostCalls, $idle, 'rate_limit_recovery');
                if (empty($continued['continue'])) return $continued;
                $stage = 'resume';
            } else {
                $stage = $nativeControl ? 'retry' : 'refresh';
            }
        } elseif ($refreshObserved) {
            if (($state['stage'] ?? '') !== 'refresh' || $state['document_id'] === $facts['document_id']
                || !hash_equals($chat['account_key'], (string)($facts['account_key'] ?? ''))
                || $state['user_id'] !== $facts['user_id'] || $state['assistant_id'] !== $facts['assistant_id']
                || !is_array($facts['user_ids'] ?? null) || !is_array($facts['assistant_ids'] ?? null)
                || !in_array($state['user_id'], $facts['user_ids'], true)
                || !in_array($state['assistant_id'], $facts['assistant_ids'], true)) {
                throw new GatewayError('CHATGPT_CHAT_IDENTITY_CHANGED', 'The saved Web chat identity could not be confirmed.', 409);
            }
            // The same answer survived a real document reload. Hand it back to the
            // ordinary stream collector without clicking Retry or sending Continue.
            $stage = 'reattached';
        } elseif ($stoppedThinking) {
            if (empty($facts['terminal']) || !is_array($facts['user_ids'] ?? null) || !is_array($facts['assistant_ids'] ?? null)
                || !in_array($facts['user_id'], $facts['user_ids'], true) || !in_array($facts['assistant_id'], $facts['assistant_ids'], true)) {
                throw new GatewayError('CHATGPT_CHAT_IDENTITY_CHANGED', 'The saved Web chat identity could not be confirmed.', 409);
            }
            $continuation = self::continuationStateUnlocked($turn['id']);
            if (($state['stage'] ?? '') === 'replace') return array_replace($idle, ['regular_action'=>'replace','state'=>$state]);
            if (!empty($continuation['pending']) || ($state['failure_key'] ?? '') === $failureKey) {
                return array_replace($idle, ['waiting'=>true]);
            }
            $stoppedAttempts = (int)($state['stopped_thinking_attempts'] ?? 0);
            if ($stoppedAttempts >= 3) {
                if (empty($state['marker']) || ($continuation['marker'] ?? '') !== $state['marker']) {
                    throw new GatewayError('continuation_marker_mismatch', '网页继续消息与持久化意图不一致。', 409);
                }
                $stage = 'replace';
            } else {
                $continued = self::reserveStreamContinuationUnlocked($job, $turn, $surface, $binding,
                    $failureKey, $hostCalls, $idle, 'stopped_thinking_recovery');
                if (empty($continued['continue'])) return $continued;
                $stoppedAttempts++; $stage = 'resume';
            }
        } elseif (($facts['action'] ?? '') === 'resume' || $resumeAfterRetry) {
            if (($state['stage'] ?? '') === 'resume'
                && ($state['document_nonce'] ?? '') === $surface['document_nonce']
                && ($state['document_id'] ?? '') === $facts['document_id']
                && ($resumeAfterRetry
                    || hash_equals($chat['account_key'], (string)($facts['account_key'] ?? '')))) {
                return array_replace($idle, ['waiting'=>true]);
            }
            $validResume = $resumeAfterRetry
                ? ($state['stage'] ?? '') === 'retry' && $state['document_id'] === $facts['document_id']
                    && $state['user_id'] === $facts['user_id'] && !empty($facts['terminal'])
                : ($state['stage'] ?? '') === 'refresh' && $state['document_id'] !== $facts['document_id']
                    && hash_equals($chat['account_key'], (string) ($facts['account_key'] ?? ''))
                    && $state['user_id'] === $facts['user_id'] && $state['assistant_id'] === $facts['assistant_id'];
            if (!$validResume) {
                throw new GatewayError('CHATGPT_CHAT_IDENTITY_CHANGED', 'The saved Web chat identity could not be confirmed.', 409);
            }
            $continued = self::reserveStreamContinuationUnlocked($job, $turn, $surface, $binding, $failureKey, $hostCalls, $idle,
                !empty($state['rate_limit_attempts']) ? 'rate_limit_recovery' : 'stream_recovery');
            if (empty($continued['continue'])) return $continued;
            $stage = 'resume';
        } elseif (($state['stage'] ?? '') === 'resume') {
            $continuation = self::continuationStateUnlocked($turn['id']);
            if (!empty($continuation['pending'])) return array_replace($idle, ['waiting'=>true]);
            $stage = !empty($state['refreshed']) ? 'replace' : 'refresh';
        } elseif (($state['stage'] ?? '') === 'replace') {
            return array_replace($idle, ['regular_action'=>'replace', 'state'=>$state]);
        } elseif (($state['stage'] ?? '') === 'refresh') {
            // The first caller already persisted the reload intent. Polling (and a
            // restarted browser worker) reconciles that intent directly, so another
            // observer must not reserve a second reload for the same document.
            return array_replace($idle, ['waiting'=>true, 'state'=>$state]);
        } elseif (($state['stage'] ?? '') === 'reattached') {
            if (!is_array($facts['user_ids'] ?? null) || !is_array($facts['assistant_ids'] ?? null)
                || !in_array($facts['user_id'], $facts['user_ids'], true)
                || !in_array($facts['assistant_id'], $facts['assistant_ids'], true)) {
                throw new GatewayError('CHATGPT_CHAT_IDENTITY_CHANGED', 'The saved Web chat identity could not be confirmed.', 409);
            }
            if ($nativeControl) $stage = 'retry';
            else {
                $continued = self::reserveStreamContinuationUnlocked($job, $turn, $surface, $binding,
                    $failureKey, $hostCalls, $idle, 'stream_recovery');
                if (empty($continued['continue'])) return $continued;
                $stage = 'resume';
            }
        } elseif (($state['stage'] ?? '') === 'retry') {
            if ($state['user_id'] !== $facts['user_id'] || $state['document_id'] !== $facts['document_id']) {
                throw new GatewayError('CHATGPT_CHAT_IDENTITY_CHANGED', 'The saved Web chat identity could not be confirmed.', 409);
            }
            // A lost click acknowledgement must never click twice. Give an unchanged stopped
            // card two minutes; a new owned failure/terminal response can advance immediately.
            if (($state['failure_key'] === $failureKey || empty($facts['terminal'])) && time() - (int) $state['intent_at'] < 120) {
                return array_replace($idle, ['waiting'=>true]);
            }
            $stage = 'refresh';
        } else {
            // The first observation can be a stale client-side terminal while the
            // server is still generating. Reload the same saved chat before taking
            // any action that could create a duplicate Web turn.
            $stage = 'refresh';
        }
        $wasRefreshed = !empty($state['refreshed']);
        $state = ['stage'=>$stage, 'generation_id'=>$job['generation_id'], 'surface_id'=>$surface['id'],
            'document_nonce'=>$surface['document_nonce'], 'document_id'=>$facts['document_id'],
            'conversation_id'=>$chat['conversation_id'], 'user_id'=>$facts['user_id'],
            'assistant_id'=>$facts['assistant_id'], 'failure_key'=>$failureKey, 'host_calls'=>$hostCalls,
            'intent_at'=>time()] + ($rateLimited ? ['rate_limit_attempts'=>$rateAttempts]
                : (!empty($state['rate_limit_attempts']) ? ['rate_limit_attempts'=>$state['rate_limit_attempts']] : []))
            + ($stoppedThinking ? ['stopped_thinking_attempts'=>$stoppedAttempts]
                : (!empty($state['stopped_thinking_attempts']) ? ['stopped_thinking_attempts'=>$state['stopped_thinking_attempts']] : []))
            + ($stage === 'reattached' || $wasRefreshed ? ['refreshed'=>true] : [])
            + ($stage === 'resume' ? ['marker'=>$continued['marker'],
                'refreshed'=>$rateLimited ? $wasRefreshed : ($stoppedThinking ? $wasRefreshed : ($resumeAfterRetry ? $wasRefreshed : true))]
                : ($stage === 'replace' ? ['marker'=>$state['marker']] : []));
        if (in_array($stage, ['resume','reattached'], true)) {
            // Reattachment observes an existing answer: its pre-submit baseline must stay
            // intact so a restarted worker can discover that same answer again. Only an
            // actual new continuation message establishes a new pre-submit baseline.
            $values = ['submitted_document_nonce'=>$surface['document_nonce']];
            if ($stage === 'resume') {
                $values['baseline_users_json'] = json_encode($facts['user_ids'], JSON_THROW_ON_ERROR);
                $values['baseline_assistants_json'] = json_encode($facts['assistant_ids'], JSON_THROW_ON_ERROR);
            }
            $write = SDB(null, 'medoo', 'sqlite')->table('gateway_response_jobs')->where('id', $job['id'])
                ->where('generation_id', $job['generation_id'])->update($values);
            if (!$write || $write->rowCount() !== 1) throw new GatewayError('surface_fence_lost', 'Web recovery ownership changed.', 409);
        }
        self::eventUnlocked($job['task_id'], $turn['id'], $job['id'], $surface['id'], 'regular_stream_recovery_intent', $state);
        return array_replace($idle, $stage === 'resume' ? $continued : [], ['regular_action'=>$stage, 'state'=>$state]);
    }

    /**
     * Reserve one control-only continuation after the caller's writer transaction proved a
     * stopped stream and fully delivered native work. The existing durable marker prevents
     * duplicate sends after lost acknowledgements/restarts; neither page nor token is rotated.
     * @param array $job Current ordinary response, $turn/$surface its fenced active owner.
     * @param array $binding Drained current tool capability, never a historical binding.
     * @param string $failureKey Hash of the owned failed-stream identity.
     * @param int $hostCalls Number of completed calls on this capability.
     * @param array $idle Default retry projection returned for a duplicate or exhausted intent.
     * @param string $reason Internal retry family; existing stream and stopped-thinking budgets stay distinct.
     * @return array Retry projection with the reserved continuation marker or bounded wait.
     */
    private static function reserveStreamContinuationUnlocked(array $job, array $turn, array $surface,
        array $binding, string $failureKey, int $hostCalls, array $idle, string $reason = 'stream_recovery'): array
    {
        $previous = self::continuationStateUnlocked((string)$turn['id']);
        if (!empty($previous['pending']) || ($previous['answer_hash'] ?? '') === $failureKey) {
            return array_replace($idle, ['waiting'=>true]);
        }
        $attempt = (int)($previous['attempt'] ?? 0) + 1;
        // Older releases used these markers for ordinary unfinished-work follow-ups.
        // Preserve their marker sequence without counting them as failed-stream retries.
        $sameFamily = ($previous['reason'] ?? '') === $reason
            && ($reason !== 'stopped_thinking_recovery' || $hostCalls <= (int) ($previous['host_calls'] ?? 0));
        $streamAttempt = $sameFamily
            ? (int)($previous['stream_recovery_attempt'] ?? 0) + 1 : 1;
        $maximum = $reason === 'rate_limit_recovery' ? 64 : 3;
        if ($streamAttempt > $maximum) return array_replace($idle, ['exhausted'=>true,'attempt'=>$maximum]);
        $marker = 'RCMAKER-CONTINUE-' . substr(hash('sha256', (string)$turn['id'] . "\0" . $attempt), 0, 16);
        $budget = json_decode((string)$job['budget_json'], true);
        $deadline = $reason === 'rate_limit_recovery' ? (int)$turn['deadline_at']
            : max((int)$turn['deadline_at'], time() + (int)($budget['response_timeout_seconds'] ?? 600));
        $updated = SDB(null, 'medoo', 'sqlite')->table('gateway_task_turns')->where('id', $turn['id'])
            ->where('status', $turn['status'])->update(['deadline_at'=>$deadline,'updated_at'=>time()]);
        if (!$updated || $updated->rowCount() !== 1) {
            throw new GatewayError('storage_unavailable', '网页续做截止时间无法保存。', 503);
        }
        self::eventUnlocked($job['task_id'], $turn['id'], $job['id'], $surface['id'], 'task_continuation_intent', [
            'attempt'=>$attempt,'stream_recovery_attempt'=>$streamAttempt,'reason'=>$reason,'marker'=>$marker,'answer_hash'=>$failureKey,
            'activity_revision'=>(int)$binding['activity_revision'],'host_calls'=>$hostCalls,
            'no_progress_attempts'=>0,'intent_at'=>time(),'deadline_at'=>$deadline,
        ]);
        return array_replace($idle, ['continue'=>true,'marker'=>$marker,'attempt'=>$attempt,'deadline_at'=>$deadline]);
    }

    /**
     * Record native response-stream health separately from model-authored conversation content.
     * This observation never changes a deadline, receipt, retry, capability or completion state.
     * @param string $responseId Current logical response owning the exact browser document.
     * @param string $state Upload boundary, stream HTTP/failure/recovery or terminal observation.
     * @param array $metadata Bounded timing/protocol facts and validated server IDs; no raw headers/body.
     * @param string $incarnation Browser worker owning the observed surface.
     * @param int $fence Exact surface ownership fence.
     * @param string $documentNonce Exact document identity at the time of observation.
     * @return bool False for a stale or already settled observer, without recording against another task.
     * @throws GatewayError When durable observation storage is unavailable.
     */
    public static function browserTransportObserved(string $responseId, string $state, array $metadata,
        string $incarnation, int $fence, string $documentNonce): bool
    {
        if (!in_array($state, ['stream_failed','recovery_visible','recovery_cleared','response_http',
            'empty_candidate','empty_cleared','empty_settlement','empty_stream_recovery','stopped_thinking',
            'stream_metrics','background_failed','background_finished',
            'capacity_candidate','capacity_revalidated','capacity_deferred',
            'attachment_wait','attachment_ready','attachment_timeout'], true)) return false;
        $uploadObservation = in_array($state, ['attachment_wait','attachment_ready','attachment_timeout'], true);
        $facts = ['state'=>$state];
        foreach (['canceled','generation_running','response_present','send_button_visible','send_button_disabled',
            'completion_action_visible','content_present','transport_recovery_visible','transport_failed',
            'transport_settled','ui_receipts_settled','request_finished','fence_drained',
            'source_receipt_present','has_artifacts','stopped_thinking_visible','network_retry_available','connection_reused',
            'upload_pending','upload_error','duplicate','from_service_worker','from_disk_cache',
            'conversation_replacement_available','same_terminal'] as $key) {
            if (is_bool($metadata[$key] ?? null)) $facts[$key] = $metadata[$key];
        }
        if (is_int($metadata['elapsed_ms'] ?? null)) $facts['elapsed_ms'] = max(0, min(86400000, $metadata['elapsed_ms']));
        foreach (['answer_root_count','commentary_root_count','markdown_chars','visible_chars','candidate_age_ms','mcp_revision',
            'tool_invocations_pending','tool_receipts_pending','mcp_activities_pending',
            'request_bytes','received_bytes','encoded_bytes','data_events','last_data_age_ms',
            'browser_elapsed_ms','browser_last_data_age_ms','response_headers_ms','retry_after_seconds',
            'expected_count','matched_count','wait_budget_ms'] as $key) {
            if (is_int($metadata[$key] ?? null)) $facts[$key] = max(0, min(2147483647, $metadata[$key]));
        }
        if (is_int($metadata['http_status'] ?? null) && $metadata['http_status'] >= 100 && $metadata['http_status'] <= 599) {
            $facts['http_status'] = $metadata['http_status'];
        }
        $protocol = $metadata['protocol'] ?? null;
        if (in_array($protocol, ['h2','http/1.1','http/1.0','h3'], true)) $facts['protocol'] = $protocol;
        $errorCode = $metadata['error_code'] ?? null;
        if (is_string($errorCode) && preg_match('/\Anet::ERR_[A-Z0-9_]{1,80}\z/D', $errorCode) === 1) $facts['error_code'] = $errorCode;
        if (($metadata['page_error_code'] ?? null) === 'CHATGPT_STOPPED_THINKING') {
            $facts['page_error_code'] = 'CHATGPT_STOPPED_THINKING';
        }
        if (in_array($metadata['endpoint'] ?? null, ['submission','stream_status','snapshot','resume'], true)) {
            $facts['endpoint'] = $metadata['endpoint'];
        }
        $requestFingerprint = $metadata['request_fingerprint'] ?? null;
        if (is_string($requestFingerprint) && preg_match('/\A[a-f0-9]{64}\z/D', $requestFingerprint) === 1) $facts['request_fingerprint'] = $requestFingerprint;
        $responseFingerprint = $metadata['response_fingerprint'] ?? null;
        if (is_string($responseFingerprint) && preg_match('/\A[a-f0-9]{64}\z/D', $responseFingerprint) === 1) $facts['response_fingerprint'] = $responseFingerprint;
        foreach (['edge_trace_id' => '/\A[a-f0-9]{16,32}-[A-Z]{3}\z/D',
            'upstream_request_id' => '/\A(?:[a-f0-9]{32}|[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12})\z/iD'] as $key => $pattern) {
            if (is_string($metadata[$key] ?? null) && strlen($metadata[$key]) <= 64
                && preg_match($pattern, $metadata[$key]) === 1) $facts[$key] = $metadata[$key];
        }
        return Schema::transaction(function () use ($responseId, $facts, $incarnation, $fence, $documentNonce, $uploadObservation): bool {
            [$job, $turn, $surface] = self::executionRowsUnlocked($responseId);
            // Closing an immutable tool-call Response does not close the browser turn. Keep
            // network evidence during host execution, without appending to its SSE or output.
            $observable = $job['status'] === 'running'
                || ($job['status'] === 'completed' && ($job['round_kind'] ?? '') === 'tool_call'
                    && ($turn['response_id'] ?? '') === $job['id']);
            // Unsent uploads own a ready surface; this exception is diagnostic-only and
            // never lets a response-stream event bypass the original submitted/busy fence.
            $surfaceObservable = $surface['status'] === 'busy'
                || ($uploadObservation && $job['status'] === 'running'
                    && $turn['status'] === 'queued' && $surface['status'] === 'ready');
            if (!self::ownsCurrentGeneration($job, $turn) || !$observable
                || !in_array($turn['status'], $uploadObservation ? self::ACTIVE_TURNS : ['submit_intent','submitted','running'], true)
                || !$surfaceObservable || $documentNonce === ''
                || !hash_equals((string)$surface['document_nonce'], $documentNonce)
                || !hash_equals((string)$surface['owner_incarnation'], $incarnation)
                || (int)$surface['fencing_version'] !== $fence) return false;
            self::eventUnlocked($job['task_id'], $turn['id'], $responseId, $surface['id'],
                'browser_response_transport', $facts);
            return true;
        });
    }

    /** Rotate document identity when the attached page performs a top-level document navigation. */
    public static function documentChanged(string $surfaceId, string $incarnation, int $fence, string $url): void
    {
        Schema::transaction(function () use ($surfaceId, $incarnation, $fence, $url): void {
            $db = SDB(null, 'medoo', 'sqlite');
            $surface = $db->table('gateway_browser_surfaces')->where('id', $surfaceId)->find();
            if (!is_array($surface)) return;
            self::assertFence($surface, $incarnation, $fence);
            $db->table('gateway_browser_surfaces')->where('id', $surfaceId)->update([
                'document_nonce' => bin2hex(random_bytes(12)),
                'url' => mb_substr($url, 0, 2000),
                'last_observed_at' => time(),
                'updated_at' => time(),
            ]);
        });
    }

    public static function observeSurface(string $surfaceId, string $incarnation, int $fence, string $url, string $title = ''): void
    {
        $surface = SDB(null, 'medoo', 'sqlite')->table('gateway_browser_surfaces')->where('id', $surfaceId)->find();
        if (!is_array($surface)) return;
        self::assertFence($surface, $incarnation, $fence);
        SDB(null, 'medoo', 'sqlite')->table('gateway_browser_surfaces')->where('id', $surfaceId)->where('fencing_version', $fence)->update([
            'url' => mb_substr($url, 0, 2000),
            'title' => mb_substr($title, 0, 200),
            'last_observed_at' => time(),
            'updated_at' => time(),
        ]);
    }

    /** Record an explicit ChatGPT backend security challenge without persisting query strings or headers. */
    public static function observeSecurityChallenge(string $surfaceId, string $incarnation, int $fence, string $responseId, string $url): void
    {
        Schema::transaction(function () use ($surfaceId, $incarnation, $fence, $responseId, $url): void {
            $db = SDB(null, 'medoo', 'sqlite');
            $surface = $db->table('gateway_browser_surfaces')->where('id', $surfaceId)->find();
            if (!is_array($surface)) return;
            self::assertFence($surface, $incarnation, $fence);
            $job = $responseId !== '' ? $db->table('gateway_response_jobs')->where('id', $responseId)->find() : null;
            $path = (string) (parse_url($url, PHP_URL_PATH) ?: '/backend-api/');
            self::eventUnlocked(
                (string) $surface['task_id'],
                is_array($job) ? (string) ($job['durable_turn_id'] ?? '') : null,
                $responseId,
                $surfaceId,
                'chatgpt_security_challenge',
                ['path' => mb_substr($path, 0, 500)]
            );
        });
    }

    /** Detach a CDP session without retiring the conversation page. */
    public static function detachSurface(string $surfaceId, string $incarnation, int $fence): void
    {
        $surface = SDB(null, 'medoo', 'sqlite')->table('gateway_browser_surfaces')->where('id', $surfaceId)->find();
        if (!is_array($surface)) return;
        self::assertFence($surface, $incarnation, $fence);
        SDB(null, 'medoo', 'sqlite')->table('gateway_browser_surfaces')->where('id', $surfaceId)->where('fencing_version', $fence)->update([
            'session_id' => null, 'updated_at' => time(),
        ]);
    }

    /** @return list<string> Targets owned by task surfaces; used to keep them out of the manual-login tab projection. */
    public static function managedTargetIds(): array
    {
        $rows = SDB(null, 'medoo', 'sqlite')->table('gateway_browser_surfaces')->where('target_id', '<>', null)->select();
        if (!is_array($rows)) return [];
        return array_values(array_unique(array_filter(array_map(
            static fn(array $row): string => (string) ($row['target_id'] ?? ''),
            $rows
        ))));
    }

    /** @return array<string,string> target_id => surface_id for retired pages waiting for physical cleanup. */
    public static function retiredTargets(): array
    {
        $rows = SDB(null, 'medoo', 'sqlite')->table('gateway_browser_surfaces')->where('status', 'retired')->select();
        if (!is_array($rows)) return [];
        $targets = [];
        foreach ($rows as $row) {
            $target = (string) ($row['target_id'] ?? '');
            if ($target !== '') $targets[$target] = (string) $row['id'];
        }
        return $targets;
    }

    public static function targetClosed(string $surfaceId): void
    {
        $retirement = SDB(null, 'medoo', 'sqlite')->table('gateway_surface_retirements')
            ->where('surface_id', $surfaceId)->find('state');
        // Tracked pages close only through TaskPageRetirement::closed(), which records
        // the confirmed stop and clears this target in the same transaction.
        if (is_array($retirement) && $retirement['state'] !== 'closed') return;
        if ($retirement === false) throw new GatewayError('storage_unavailable', 'Retired page stop state could not be read.', 503);
        $updated = SDB(null, 'medoo', 'sqlite')->table('gateway_browser_surfaces')->where('id', $surfaceId)->where('status', 'retired')->update([
            'target_id' => null, 'session_id' => null, 'updated_at' => time(),
        ]);
        if ($updated === false) throw new GatewayError('storage_unavailable', '已退休页面清理状态无法保存。', 503);
    }

    /**
     * Reconcile durable surfaces against one successful DevTools target snapshot.
     *
     * A terminal/idle surface whose physical target no longer exists must not consume one of the
     * five browser slots forever. Active turns are deliberately left untouched so the existing
     * response recovery path can decide whether replay is safe or human confirmation is required.
     *
     * @param list<string> $liveTargetIds
     * @return array{retired:int,skipped_active:int,bindings_revoked:int}
     */
    public static function reconcilePhysicalTargets(array $liveTargetIds): array
    {
        $live = array_fill_keys(array_values(array_filter($liveTargetIds,
            static fn(mixed $id): bool => is_string($id) && $id !== '')), true);
        return Schema::transaction(static function () use ($live): array {
            $db = SDB(null, 'medoo', 'sqlite');
            $rows = $db->table('gateway_browser_surfaces')->where('status', '<>', 'retired')
                ->where('target_id', '<>', null)->select();
            if (!is_array($rows)) {
                throw new GatewayError('storage_unavailable', '任务页面物理状态无法读取。', 503);
            }
            $retired = 0;
            $skippedActive = 0;
            $bindingsRevoked = 0;
            $handledEpochs = [];
            foreach ($rows as $surface) {
                $targetId = (string) ($surface['target_id'] ?? '');
                $epochId = (string) ($surface['epoch_id'] ?? '');
                if ($targetId === '' || isset($live[$targetId]) || isset($handledEpochs[$epochId])) continue;
                $active = $db->table('gateway_task_turns')->where('surface_id', (string) $surface['id'])
                    ->where('status', 'IN', self::ACTIVE_TURNS)->find();
                if (is_array($active)) {
                    $skippedActive++;
                    continue;
                }
                $taskId = (string) $surface['task_id'];
                $bindingsRevoked += ToolBroker::revokeTaskBindingsForMissingSurfaceUnlocked($taskId);
                self::retireEpochUnlocked($taskId, $epochId, 'physical_target_missing');
                $handledEpochs[$epochId] = true;
                $retired++;
            }
            return ['retired'=>$retired, 'skipped_active'=>$skippedActive, 'bindings_revoked'=>$bindingsRevoked];
        });
    }

    /** Mark the entire task cancelled and retire its surfaces; caller publishes response terminals. */
    public static function cancelTask(string $taskId): array
    {
        return Schema::transaction(static fn(): array => self::cancelTaskUnlocked($taskId));
    }

    /**
     * Retire task ownership inside the caller's existing reservation transaction.
     * The caller must publish response terminals and establish the business boundary.
     * @param string $taskId Existing durable task identity.
     * @return array{response_ids:array,target_ids:array} Retired response and browser targets.
     * @throws GatewayError If the task is absent or a durable event cannot be stored.
     */
    public static function cancelTaskUnlocked(string $taskId): array
    {
        $db = SDB(null, 'medoo', 'sqlite');
        $task = $db->table('gateway_tasks')->where('id', $taskId)->find();
        if (!is_array($task)) throw new GatewayError('task_not_found', '任务不存在。', 404);
        $now = time();
        $db->table('gateway_tasks')->where('id', $taskId)->update(['status' => 'cancelled', 'cancelled_at' => $now, 'updated_at' => $now]);
        $turns = $db->table('gateway_task_turns')->where('task_id', $taskId)->where('status', 'IN', self::ACTIVE_TURNS)->select();
        $responseIds = [];
        foreach (is_array($turns) ? $turns : [] as $turn) {
            if (!empty($turn['response_id'])) $responseIds[] = (string) $turn['response_id'];
            $db->table('gateway_task_turns')->where('id', $turn['id'])->update([
                'status' => 'cancelled', 'error_code' => 'task_cancelled', 'retired_at' => $now, 'updated_at' => $now,
            ]);
            self::eventUnlocked($taskId, (string) $turn['id'], (string) ($turn['response_id'] ?? ''), (string) $turn['surface_id'], 'turn_cancelled', []);
        }
        $surfaces = $db->table('gateway_browser_surfaces')->where('task_id', $taskId)->where('status', '<>', 'retired')->select();
        $targets = [];
        foreach (is_array($surfaces) ? $surfaces : [] as $surface) {
            TaskPageRetirement::captureUnlocked($surface);
            if (!empty($surface['target_id'])) $targets[] = (string) $surface['target_id'];
            $db->table('gateway_browser_surfaces')->where('id', $surface['id'])->update([
                'status' => 'retired', 'session_id' => null, 'retired_at' => $now, 'updated_at' => $now,
            ]);
            self::eventUnlocked($taskId, null, null, (string) $surface['id'], 'surface_retired', ['reason' => 'task_cancelled']);
        }
        $db->table('gateway_task_epochs')->where('task_id', $taskId)->where('status', '<>', 'retired')->update(['status' => 'retired', 'retired_at' => $now]);
        return ['response_ids' => array_values(array_unique($responseIds)), 'target_ids' => array_values(array_unique($targets))];
    }

    /** Read-only console summary with deterministic pagination; all is for internal callers, while the console selects one status. */
    public static function listTasks(int $page = 1, int $pageSize = 20, string $status = 'all'): array
    {
        Schema::initialize();
        $page = max(1, $page);
        $pageSize = max(5, min(100, $pageSize));
        if (!in_array($status, ['all', 'active', 'needs_attention', 'completed', 'cancelled'], true)) {
            throw new GatewayError('invalid_task_status', '任务状态筛选无效。', 400);
        }
        $db = SDB(null, 'medoo', 'sqlite');
        $total = $db->table('gateway_tasks')->count();
        if ($total === false || $total === null) throw new GatewayError('storage_unavailable', '任务数量暂不可读取。', 503);
        $counts = ['total' => (int) $total];
        foreach (['active', 'needs_attention', 'completed', 'cancelled'] as $key) {
            $count = $db->table('gateway_tasks')->where('status', $key)->count();
            if ($count === false || $count === null) throw new GatewayError('storage_unavailable', '任务概览暂不可读取。', 503);
            $counts[$key] = (int) $count;
        }
        $filteredTotal = $status === 'all' ? (int) $total : $counts[$status];
        $offset = ($page - 1) * $pageSize;
        $query = $db->table('gateway_tasks');
        if ($status !== 'all') $query = $query->where('status', $status);
        $rows = $query->order([['updated_at', 'DESC'], ['id', 'DESC']])
            ->limit($pageSize, $offset)
            ->select();
        if ($rows === false) throw new GatewayError('storage_unavailable', '任务列表暂不可读取。', 503);
        $items = [];
        foreach ($rows as $task) {
            $epoch = !empty($task['current_epoch_id'])
                ? SDB(null, 'medoo', 'sqlite')->table('gateway_task_epochs')->where('id', (string) $task['current_epoch_id'])->find() : null;
            $surface = is_array($epoch)
                ? SDB(null, 'medoo', 'sqlite')->table('gateway_browser_surfaces')->where('epoch_id', (string) $epoch['id'])->find() : null;
            $turn = SDB(null, 'medoo', 'sqlite')->table('gateway_task_turns')->where('task_id', (string) $task['id'])->order([['created_at', 'DESC']])->limit(1)->find();
            $turnCount = $db->table('gateway_task_turns')->where('task_id', (string) $task['id'])->count();
            $firstRound = $db->table('gateway_response_jobs')->where('task_id', (string) $task['id'])
                ->where('round_no', 1)->order([['created_at', 'DESC']])->limit(1)->select('context_json');
            $contextJson = is_array($firstRound) && is_array($firstRound[0] ?? null)
                ? (string) ($firstRound[0]['context_json'] ?? '') : '';
            $item = self::publicTask($task, is_array($epoch) ? $epoch : null, is_array($surface) ? $surface : null, is_array($turn) ? $turn : null);
            $item += self::conversationIdentity($contextJson, (int) $turnCount);
            $item['working'] = TaskMaintenance::isWorking((string) $task['id']);
            $item['cleanup_pending'] = TaskMaintenance::isPending((string) $task['id']);
            $item['can_delete'] = !$item['working'] && !$item['cleanup_pending'];
            foreach (['task_restarted_from' => 'restarted_from_task_id',
                'task_conversation_restarted' => 'continued_in_task_id'] as $eventType => $field) {
                $event = $db->table('gateway_task_events')->where('task_id', (string) $task['id'])
                    ->where('event_type', $eventType)->order([['id', 'DESC']])->limit(1)->find();
                if (!is_array($event)) continue;
                $data = json_decode((string) $event['data_json'], true);
                $item[$field] = (string) ($data[$eventType === 'task_restarted_from' ? 'old_task_id' : 'new_task_id'] ?? '');
            }
            $items[] = $item;
        }
        return [
            'items' => $items,
            'page' => $page,
            'page_size' => $pageSize,
            'total' => $filteredTotal,
            'pages' => max(1, (int) ceil($filteredTotal / $pageSize)),
            'counts' => $counts,
        ];
    }

    public static function detail(string $taskId): array
    {
        Schema::initialize();
        $db = SDB(null, 'medoo', 'sqlite');
        $task = $db->table('gateway_tasks')->where('id', $taskId)->find();
        if (!is_array($task)) throw new GatewayError('task_not_found', '任务不存在。', 404);
        $epochs = $db->table('gateway_task_epochs')->where('task_id', $taskId)->order([['epoch_no', 'ASC']])->select();
        $turns = $db->table('gateway_task_turns')->where('task_id', $taskId)->order([['created_at', 'ASC']])->select();
        $surfaces = $db->table('gateway_browser_surfaces')->where('task_id', $taskId)->order([['created_at', 'ASC']])->select();
        $events = $db->table('gateway_task_events')->where('task_id', $taskId)->order([['id', 'ASC']])->limit(500)->select();
        $epochRows = is_array($epochs) ? $epochs : [];
        $turnRows = is_array($turns) ? $turns : [];
        $surfaceRows = is_array($surfaces) ? $surfaces : [];
        $currentEpochId = (string) ($task['current_epoch_id'] ?? '');
        $currentEpoch = null;
        $currentSurface = null;
        foreach ($epochRows as $row) {
            if ((string) $row['id'] === $currentEpochId) {
                $currentEpoch = $row;
                break;
            }
        }
        if (is_array($currentEpoch)) {
            foreach ($surfaceRows as $row) {
                if ((string) $row['epoch_id'] === (string) $currentEpoch['id']) {
                    $currentSurface = $row;
                    break;
                }
            }
        }
        $latestTurn = $turnRows ? $turnRows[count($turnRows) - 1] : null;
        return [
            'task' => self::publicTask($task, $currentEpoch, $currentSurface, is_array($latestTurn) ? $latestTurn : null)
                + ['working'=>TaskMaintenance::isWorking($taskId)],
            'epochs' => array_map([self::class, 'publicEpoch'], $epochRows),
            'turns' => array_map([self::class, 'publicTurn'], $turnRows),
            'surfaces' => array_map([self::class, 'publicSurface'], $surfaceRows),
            'artifacts' => ArtifactStore::forTask($taskId),
            'conversation' => self::conversation($db, $taskId, $turnRows),
            'web_messages' => WebMessageAudit::forTask($taskId),
            'context_decisions' => ContextDecision::forTask($taskId),
            'events' => array_map(static function (array $row): array {
                $data = [];
                try { $data = json_decode((string) $row['data_json'], true, 32, JSON_THROW_ON_ERROR); } catch (\Throwable) {}
                return [
                    'id' => (int) $row['id'], 'turn_id' => $row['turn_id'], 'response_id' => $row['response_id'],
                    'surface_id' => $row['surface_id'], 'type' => (string) $row['event_type'],
                    'data' => is_array($data) ? $data : [], 'created_at' => (int) $row['created_at'],
                ];
            }, is_array($events) ? $events : []),
        ];
    }

    /** Read a bounded, human-readable projection of recent Web exchanges without exposing transport prompts or tool arguments. */
    private static function conversation($db, string $taskId, array $turnRows): array
    {
        $recentTurns = array_slice($turnRows, -8);
        if ($recentTurns === []) return ['turns' => [], 'limited' => false];
        $turnIds = array_map(static fn(array $turn): string => (string) $turn['id'], $recentTurns);
        $firstRounds = $db->table('gateway_response_jobs')->where('task_id', $taskId)
            ->where('durable_turn_id', 'IN', $turnIds)->where('round_no', 1)
            ->select('durable_turn_id,context_json');
        $userByTurn = [];
        foreach (is_array($firstRounds) ? $firstRounds : [] as $job) {
            try { $context = json_decode((string) ($job['context_json'] ?? ''), true, 64, JSON_THROW_ON_ERROR); }
            catch (\Throwable) { continue; }
            if (!is_array($context['messages'] ?? null)) continue;
            foreach (array_reverse($context['messages']) as $message) {
                if (!is_array($message) || ($message['role'] ?? '') !== 'user' || ($message['origin'] ?? '') === 'codex_skill'
                    || ContextHistory::isOperationalUserMessage($message)) continue;
                $text = self::conversationText($message['content'] ?? null);
                if (!self::displayableUserText($text)) continue;
                $userByTurn[(string) $job['durable_turn_id']] = self::conversationExcerpt($text, 12000, true);
                break;
            }
        }

        $jobs = $db->table('gateway_response_jobs')->where('task_id', $taskId)
            ->where('durable_turn_id', 'IN', $turnIds)
            ->order([['created_at', 'DESC'], ['round_no', 'DESC']])->limit(61)
            ->select('id,durable_turn_id,round_no,operation_kind,status,response_json,output_text,created_at');
        $jobRows = is_array($jobs) ? $jobs : [];
        $limited = count($jobRows) > 60 || count($turnRows) > count($recentTurns);
        $jobRows = array_slice($jobRows, 0, 60);
        $itemsByTurn = [];
        foreach ($jobRows as $job) {
            if (($job['operation_kind'] ?? 'response') !== 'response') continue;
            try { $response = json_decode((string) ($job['response_json'] ?? ''), true, 64, JSON_THROW_ON_ERROR); }
            catch (\Throwable) { $response = []; }
            $output = is_array($response['output'] ?? null) ? $response['output'] : [];
            if ($output === [] && (string) ($job['output_text'] ?? '') !== '') {
                $output = [['type' => 'message', 'role' => 'assistant', 'phase' => 'final_answer',
                    'content' => [['type' => 'output_text', 'text' => (string) $job['output_text']]]]];
            }
            foreach ($output as $index => $item) {
                if (!is_array($item)) continue;
                $type = (string) ($item['type'] ?? '');
                if ($type === 'message' && ($item['role'] ?? '') === 'assistant') {
                    $text = self::conversationText($item['content'] ?? null);
                    if ($text === '') continue;
                    $entry = ['kind' => 'assistant', 'phase' => ($item['phase'] ?? '') === 'commentary' ? 'commentary' : 'final_answer',
                        'text' => self::conversationExcerpt($text, 12000), 'status' => (string) ($item['status'] ?? $job['status'])];
                } elseif (in_array($type, ['function_call', 'custom_tool_call'], true)) {
                    $name = (string) ($item['name'] ?? '');
                    if ($name === '') continue;
                    $entry = ['kind' => 'tool', 'name' => mb_substr($name, 0, 160), 'status' => (string) ($item['status'] ?? $job['status'])];
                } else {
                    continue;
                }
                $entry += ['key' => (string) $job['id'] . ':' . $index, 'item_index' => (int) $index,
                    'round_no' => (int) $job['round_no'],
                    'created_at' => (int) $job['created_at']];
                $itemsByTurn[(string) $job['durable_turn_id']][] = $entry;
            }
        }
        $turns = [];
        foreach (array_reverse($recentTurns) as $turn) {
            $turnId = (string) $turn['id'];
            $entries = $itemsByTurn[$turnId] ?? [];
            usort($entries, static fn(array $a, array $b): int => [$a['round_no'], $a['created_at'], $a['item_index']]
                <=> [$b['round_no'], $b['created_at'], $b['item_index']]);
            $turns[] = ['turn_id' => $turnId, 'created_at' => (int) $turn['created_at'],
                'status' => (string) $turn['status'], 'user' => $userByTurn[$turnId] ?? null, 'entries' => $entries];
        }
        return ['turns' => $turns, 'limited' => $limited];
    }

    /** Extract text blocks only; attachments and non-text records stay out of the console projection. */
    private static function conversationText(mixed $content): string
    {
        if (is_string($content)) return $content;
        if (!is_array($content)) return '';
        $parts = [];
        foreach ($content as $part) {
            if (is_array($part) && in_array($part['type'] ?? '', ['text', 'input_text', 'output_text'], true)
                && is_string($part['text'] ?? null)) $parts[] = $part['text'];
        }
        return implode("\n", $parts);
    }

    /** Exclude Codex operational envelopes from console labels while preserving the execution context. */
    private static function displayableUserText(string $text): bool
    {
        $text = trim($text);
        return $text !== '' && !preg_match('/\A<(?:recommended_plugins|environment_context|permissions|app-context|skills_instructions|collaboration_mode|external_codex_apps_open_page)(?:\s|>)/i', $text)
            && !preg_match('/\A#\s*AGENTS\.md instructions for\b/i', $text)
            && !str_starts_with($text, ContextCompaction::SUMMARY_PREFIX);
    }

    /** Keep polling payloads bounded and mark omissions explicitly. */
    private static function conversationExcerpt(string $text, int $limit, bool $keepEnd = false): array
    {
        $length = mb_strlen($text);
        return ['text' => $length > $limit ? mb_substr($text, $keepEnd ? -$limit : 0, $limit) : $text,
            'truncated' => $length > $limit, 'omitted' => $keepEnd ? 'start' : 'end'];
    }

    /** Distinguish a new Codex conversation from a routed continuation and provide a short list label. */
    private static function conversationIdentity(string $contextJson, int $turnCount): array
    {
        try { $context = json_decode($contextJson, true, 64, JSON_THROW_ON_ERROR); }
        catch (\Throwable) { return ['conversation_kind' => $turnCount > 1 ? 'continued' : 'unknown',
            'conversation_title' => '', 'latest_user' => '']; }
        $human = [];
        $assistantSeen = false;
        foreach (is_array($context['messages'] ?? null) ? $context['messages'] : [] as $message) {
            if (!is_array($message)) continue;
            if (($message['role'] ?? '') === 'assistant') $assistantSeen = true;
            if (($message['role'] ?? '') !== 'user' || ($message['origin'] ?? '') === 'codex_skill'
                || ContextHistory::isOperationalUserMessage($message)) continue;
            $text = trim(self::conversationText($message['content'] ?? null));
            if (self::displayableUserText($text) && TaskLabel::summarize($text) !== '') $human[] = $text;
        }
        $latest = $human ? $human[count($human) - 1] : '';
        $title = $human ? $human[0] : $latest;
        return ['conversation_kind' => $turnCount > 1 || count($human) > 1 || $assistantSeen
                ? 'continued' : ($human ? 'new' : 'unknown'),
            'conversation_title' => TaskLabel::summarize($title),
            'latest_user' => TaskLabel::summarize($latest)];
    }

    /** Small read-only projection for the console browser monitor. */
    public static function monitor(): array
    {
        Schema::initialize();
        $db = SDB(null, 'medoo', 'sqlite');
        $turn = $db->table('gateway_task_turns')
            ->where('status', 'IN', self::ACTIVE_TURNS)
            ->order([['updated_at', 'DESC'], ['created_at', 'DESC']])
            ->limit(1)
            ->find();
        if ($turn === false) throw new GatewayError('storage_unavailable', '任务监看状态暂不可读取。', 503);
        if (!is_array($turn)) return ['active' => false];

        $surface = $db->table('gateway_browser_surfaces')->where('id', (string) $turn['surface_id'])->find();
        $task = $db->table('gateway_tasks')->where('id', (string) $turn['task_id'])->find();
        if (!is_array($surface) || !is_array($task)) return ['active' => false];

        return [
            'active' => true,
            'task_id' => (string) $task['id'],
            'thread_id' => $task['thread_id'],
            'turn_id' => (string) $turn['id'],
            'response_id' => $turn['response_id'],
            'turn_status' => (string) $turn['status'],
            'surface_id' => (string) $surface['id'],
            'surface_status' => (string) $surface['status'],
            'url' => (string) $surface['url'],
            'title' => (string) $surface['title'],
            'updated_at' => max((int) $turn['updated_at'], (int) $surface['updated_at']),
        ];
    }

    /**
     * Project retained pages and bounded pending physical closes into the browser workspace.
     * Closing rows do not reserve task slots and disappear only after the browser confirms absence.
     * @param list<string> $closingSurfaceIds Up to five operator-requested closes, shown first.
     * @return list<array> At most five retained pages plus five pending physical closes.
     * @throws GatewayError If the page projection cannot be read from durable storage.
     */
    public static function monitors(array $closingSurfaceIds = []): array
    {
        Schema::initialize();
        // The first-round context is immutable. Keep only titles for the bounded current pages
        // in this API worker so frequent console polling does not decode large histories.
        static $titleByTask = [];
        $db = SDB(null, 'medoo', 'sqlite');
        $surfaces = $db->table('gateway_browser_surfaces')->where('status', '<>', 'retired')
            ->order([['created_at', 'ASC'], ['id', 'ASC']])->limit(self::MAX_SURFACES)->select();
        if (!is_array($surfaces)) throw new GatewayError('storage_unavailable', '任务页面列表暂不可读取。', 503);
        $requestedClosing = [];
        if ($closingSurfaceIds !== []) {
            $requestedClosing = $db->table('gateway_browser_surfaces')->where('status', 'retired')
                ->where('target_id', '<>', null)->where('id', 'IN', array_slice($closingSurfaceIds, 0, self::MAX_SURFACES))
                ->order([['retired_at', 'ASC'], ['id', 'ASC']])->limit(self::MAX_SURFACES)->select();
            if (!is_array($requestedClosing)) throw new GatewayError('storage_unavailable', '任务页面关闭状态暂不可读取。', 503);
        }
        $closing = $db->table('gateway_browser_surfaces')->where('status', 'retired')
            ->where('target_id', '<>', null)
            ->order([['retired_at', 'ASC'], ['id', 'ASC']])->limit(self::MAX_SURFACES)->select();
        if (!is_array($closing)) throw new GatewayError('storage_unavailable', '任务页面关闭状态暂不可读取。', 503);
        $closingById = [];
        foreach (array_merge($requestedClosing, $closing) as $surface) $closingById[(string) $surface['id']] = $surface;
        $closing = array_slice(array_values($closingById), 0, self::MAX_SURFACES);
        $surfaces = array_merge($surfaces, $closing);
        $items = [];
        $seenTasks = [];
        foreach ($surfaces as $surface) {
            $taskId = (string) $surface['task_id'];
            $task = $db->table('gateway_tasks')->where('id', $taskId)->find();
            if (!is_array($task) || (string) $task['status'] === 'cancelled') continue;
            $seenTasks[$taskId] = true;
            $turn = $db->table('gateway_task_turns')->where('surface_id', (string) $surface['id'])
                ->order([['rowid', 'DESC']])->limit(1)->find();
            if (!array_key_exists($taskId, $titleByTask)) {
                $firstRound = $db->table('gateway_response_jobs')->where('task_id', $taskId)
                    ->where('round_no', 1)->order([['created_at', 'ASC'], ['id', 'ASC']])
                    ->limit(1)->select('context_json');
                $identity = is_array($firstRound) && is_array($firstRound[0] ?? null)
                    ? self::conversationIdentity((string) ($firstRound[0]['context_json'] ?? ''), 1) : [];
                $titleByTask[$taskId] = trim((string) ($identity['conversation_title'] ?? ''));
            }
            $threadId = (string) ($task['thread_id'] ?? '');
            $title = $titleByTask[$taskId];
            if ($title === '') $title = $threadId !== '' ? mb_substr($threadId, 0, 80) : '任务 ' . mb_substr($taskId, -8);
            $isClosing = (string) $surface['status'] === 'retired';
            $closeBlocker = $isClosing ? 'page_closing' : self::idleSurfaceCloseBlockerUnlocked($db, $task, $surface, $turn);
            $items[] = [
                'surface_id' => (string) $surface['id'],
                'task_id' => $taskId,
                'thread_id' => $task['thread_id'],
                'title' => $title,
                'conversation_title' => $titleByTask[$taskId],
                'status' => (string) $surface['status'],
                'surface_status' => (string) $surface['status'],
                'turn_status' => is_array($turn) ? (string) $turn['status'] : null,
                'url' => (string) $surface['url'],
                'closing' => $isClosing,
                'closable' => $closeBlocker === null,
                'close_blocker_code' => $closeBlocker,
            ];
        }
        $titleByTask = array_intersect_key($titleByTask, $seenTasks);
        return $items;
    }

    /**
     * Confirm exact requested page closures independently of the bounded workspace projection.
     * Missing history or an open surface never establishes physical closure. No browser action is sent.
     * @param list<string> $surfaceIds Up to five validated surface IDs from the local console.
     * @return array<string,string> Each ID maps to closing, closed, open or unknown.
     * @throws GatewayError If durable closure evidence cannot be read.
     */
    public static function monitorClosures(array $surfaceIds): array
    {
        if ($surfaceIds === []) return [];
        Schema::initialize();
        $ids = array_values(array_unique(array_slice($surfaceIds, 0, self::MAX_SURFACES)));
        $rows = SDB(null, 'medoo', 'sqlite')->table('gateway_browser_surfaces')->where('id', 'IN', $ids)
            ->select('id,status,target_id');
        if (!is_array($rows)) throw new GatewayError('storage_unavailable', '任务页面关闭状态暂不可读取。', 503);
        $states = array_fill_keys($ids, 'unknown');
        foreach ($rows as $row) {
            $states[(string) $row['id']] = (string) $row['status'] !== 'retired' ? 'open'
                : (!empty($row['target_id']) ? 'closing' : 'closed');
        }
        return $states;
    }

    /**
     * Request physical closure of a completed page, preserving task history and close provenance.
     * The browser worker confirms disappearance before the workspace removes its pending row.
     * @param string $surfaceId Completed task surface selected by the operator.
     * @return array{task_id:string,surface_id:string,closing:bool} Pending physical-close receipt.
     * @throws GatewayError If the surface is missing, unsafe to close, or cannot be persisted.
     */
    public static function retireIdleTaskSurface(string $surfaceId): array
    {
        return Schema::transaction(static function () use ($surfaceId): array {
            $db = SDB(null, 'medoo', 'sqlite');
            $surface = $db->table('gateway_browser_surfaces')->where('id', $surfaceId)->find();
            if (!is_array($surface) || (string) $surface['status'] === 'retired') {
                throw new GatewayError('surface_not_found', '任务页面不存在或已关闭。', 404);
            }
            $taskId = (string) $surface['task_id'];
            $task = $db->table('gateway_tasks')->where('id', $taskId)->find();
            $lastTurn = $db->table('gateway_task_turns')->where('surface_id', $surfaceId)
                ->order([['rowid', 'DESC']])->limit(1)->find();
            if (!is_array($task) || !self::idleSurfaceClosableUnlocked($db, $task, $surface, $lastTurn)) {
                throw new GatewayError('surface_not_closable', '只有已完成且无待确认的空闲任务页面可以关闭。', 409);
            }
            self::retireEpochUnlocked($taskId, (string) $surface['epoch_id'], 'operator_closed_idle_page');
            return ['task_id' => $taskId, 'surface_id' => $surfaceId, 'closing' => !empty($surface['target_id'])];
        });
    }

    /** Authoritative close guard shared by the list hint and the transactional close action. */
    private static function idleSurfaceClosableUnlocked($db, array $task, array $surface, mixed $lastTurn): bool
    {
        return self::idleSurfaceCloseBlockerUnlocked($db, $task, $surface, $lastTurn) === null;
    }

    /** Return a stable UI reason while retaining the same authoritative transactional close guard. */
    private static function idleSurfaceCloseBlockerUnlocked($db, array $task, array $surface, mixed $lastTurn): ?string
    {
        if ((string) $surface['status'] === 'needs_attention' || (string) $task['status'] === 'needs_attention') {
            return 'page_needs_attention';
        }
        if ((string) $task['status'] !== 'active'
            || (string) $task['current_epoch_id'] !== (string) $surface['epoch_id']) return 'page_inactive_epoch';
        if ((string) $surface['status'] !== 'ready') return 'page_active';
        if (!is_array($lastTurn) || (string) $lastTurn['status'] !== 'committed') return 'page_incomplete';
        $activeTurns = $db->table('gateway_task_turns')->where('surface_id', (string) $surface['id'])
            ->where('status', 'IN', self::ACTIVE_TURNS)->count();
        if ($activeTurns === false || $activeTurns === null || (int) $activeTurns !== 0) return 'page_active';
        $openBindings = $db->table('gateway_mcp_bindings')->where('task_id', (string) $task['id'])
            ->where('status', 'NOT IN', ['completed', 'revoked'])->count();
        if ($openBindings === false || $openBindings === null || (int) $openBindings !== 0) return 'page_tools_pending';
        return null;
    }

    /** Resolve an active page's physical target for a requested monitor screenshot. */
    public static function monitorSurfaceTarget(string $surfaceId): ?array
    {
        Schema::initialize();
        $db = SDB(null, 'medoo', 'sqlite');
        $surface = $db->table('gateway_browser_surfaces')->where('id', $surfaceId)->find();
        if (!is_array($surface) || (string) $surface['status'] === 'retired') return null;
        $task = $db->table('gateway_tasks')->where('id', (string) $surface['task_id'])->find();
        if (!is_array($task) || (string) $task['status'] === 'cancelled') return null;
        return [
            'surface_id' => (string) $surface['id'],
            'target_id' => (string) ($surface['target_id'] ?? ''),
            'generation' => (int) $surface['generation'],
            'status' => (string) $surface['status'],
        ];
    }

    /** Preserve the Web model's visible no-tool final before a safe no-start retry closes its page. */
    public static function observeNoToolFinal(string $responseId, string $markdown): void
    {
        Schema::transaction(static function () use ($responseId, $markdown): void {
            $job = SDB(null, 'medoo', 'sqlite')->table('gateway_response_jobs')->where('id', $responseId)->find();
            if (!is_array($job) || empty($job['durable_turn_id'])) return;
            self::eventUnlocked((string) $job['task_id'], (string) $job['durable_turn_id'], $responseId,
                !empty($job['surface_id']) ? (string) $job['surface_id'] : null, 'web_no_tool_final', [
                    'excerpt' => mb_substr(trim($markdown), 0, 4000),
                    'truncated' => mb_strlen(trim($markdown)) > 4000,
                ]);
        });
    }

    /**
     * Journal proven native context without another browser submission or a renewed lease.
     * @param array $source Exact active response proven by ContextHistory.
     * @param array $updates Role-preserving environment, privileged updates, mailbox or proven human revisions.
     * @param string $requestHash Exact native request identity for reconnects.
     * @param ?string $idempotencyKey Optional global Responses idempotency identity.
     * @param bool $mailboxJoin Permit agent records only after the full active-source mailbox proof.
     * @param bool $steeringJoin Permit human/runtime records only after the exact pending-tool source proof.
     * @param bool $joinResponse False when a receipt request must reserve its own next batch.
     * @param ?string $nativeTurnId Native UUID of a proven human tool join, retained for later stop/steering.
     * @return void
     * @throws GatewayError If source ownership changed or a record does not belong to this channel.
     */
    public static function queueOperationalContextUnlocked(array $source, array $updates, string $requestHash,
        ?string $idempotencyKey, bool $mailboxJoin = false, bool $steeringJoin = false, bool $joinResponse = true,
        ?string $nativeTurnId = null): void
    {
        $db = SDB(null, 'medoo', 'sqlite');
        $turn = $db->table('gateway_task_turns')->where('id', (string) $source['durable_turn_id'])->find();
        if (!is_array($turn) || !in_array((string) $turn['status'], self::ACTIVE_TURNS, true)
            || (string) $turn['response_id'] !== (string) $source['id']) {
            throw new GatewayError('context_update_source_closed', '环境更新的源回合已结束。', 409);
        }
        if ($steeringJoin) self::supersedeDeferredStopUnlocked($source);
        $events = $db->table('gateway_task_events')->where('task_id', (string) $source['task_id'])
            ->where('turn_id', (string) $source['durable_turn_id'])->where('event_type', 'native_context_update_queued')->select();
        if (!is_array($events)) throw new GatewayError('storage_unavailable', '环境更新记录暂不可读取。', 503);
        $known = [];
        $humanIds = [];
        foreach ($events as $event) {
            $data = json_decode((string) $event['data_json'], true, 128, JSON_THROW_ON_ERROR);
            $known[(string) ($data['record_hash'] ?? '')] = true;
            $message = $data['message'] ?? [];
            if (($message['role'] ?? '') === 'user' && !ContextHistory::isOperationalUserMessage($message)
                && !empty($message['id'])) $humanIds[(string) $message['id']] = (string) $data['record_hash'];
        }
        foreach ((array) ($updates['messages'] ?? []) as $message) {
            if (!ContextHistory::isOperationalUserMessage($message)
                && !(($mailboxJoin || $steeringJoin) && ($message['role'] ?? '') === 'agent_message')
                && !($steeringJoin && ($message['role'] ?? '') === 'developer')
                && !($steeringJoin && ($message['role'] ?? '') === 'user')) {
                throw new GatewayError('invalid_context_update', '此通道只接收 Codex 环境信息。', 400);
            }
            $hash = hash('sha256', ContextHistory::canonical($message));
            $messageId = (string) ($message['id'] ?? '');
            if ($steeringJoin && $messageId !== '' && isset($humanIds[$messageId]) && $humanIds[$messageId] !== $hash) {
                throw new GatewayError('turn_identity_conflict', '同一用户消息 ID 的插话内容发生变化。', 409);
            }
            if (isset($known[$hash])) continue;
            self::eventUnlocked((string) $source['task_id'], (string) $source['durable_turn_id'],
                (string) $source['id'], (string) $source['surface_id'], 'native_context_update_queued',
                ['record_hash' => $hash, 'message' => $message,
                    ...($steeringJoin ? ['steering_full_context' => (bool) GatewaySettings::internal()['steering_full_context']] : [])]);
            $known[$hash] = true;
        }
        if (!$joinResponse) return;
        self::eventUnlocked((string) $source['task_id'], (string) $source['durable_turn_id'],
            (string) $source['id'], (string) $source['surface_id'], 'native_context_request_joined',
            ['request_hash' => $requestHash, 'idempotency_key' => $idempotencyKey,
                ...($mailboxJoin ? ['mailbox_join' => true] : []),
                ...($steeringJoin ? ['steering_join' => true] : [])]);
        if ($steeringJoin && CodexCancellation::uuid((string) $nativeTurnId)) {
            self::eventUnlocked((string) $source['task_id'], (string) $source['durable_turn_id'],
                (string) $source['id'], (string) $source['surface_id'], 'native_tool_batch_rejoined',
                ['native_turn_id' => $nativeTurnId]);
        }
    }

    /**
     * A proven newer human revision continues a manually stopped turn after its tools drain.
     * @param array $source Exact owned source already proved by ContextHistory.
     * @return void Records the handled native stop so its old rollout event cannot stop the new work.
     * @throws GatewayError If the durable stop transition cannot be stored.
     */
    public static function supersedeDeferredStopUnlocked(array $source): void
    {
        $db = SDB(null, 'medoo', 'sqlite');
        $turn = $db->table('gateway_task_turns')->where('id', $source['durable_turn_id'])->find();
        if (!is_array($turn) || $turn['response_id'] !== $source['id']
            || ($turn['error_code'] ?? '') !== 'CODEX_USER_STOP_WAITING_TOOLS') return;
        $event = $db->table('gateway_task_events')->where('turn_id', $turn['id'])
            ->where('event_type', 'codex_user_stop_waiting_tools')->order([['id', 'DESC']])->limit(1)->find();
        if (!is_array($event)) throw new GatewayError('storage_unavailable', '等待工具完成的停止记录无法读取。', 503);
        $updated = $db->table('gateway_task_turns')->where('id', $turn['id'])->where('response_id', $source['id'])
            ->where('error_code', 'CODEX_USER_STOP_WAITING_TOOLS')->update(['error_code' => null, 'updated_at' => time()]);
        if (!$updated || $updated->rowCount() !== 1) throw new GatewayError('storage_unavailable', '停止后的续发意图无法保存。', 503);
        self::eventUnlocked((string) $source['task_id'], (string) $turn['id'], (string) $source['id'],
            (string) $source['surface_id'], 'codex_user_stop_superseded', json_decode($event['data_json'], true, 32, JSON_THROW_ON_ERROR));
    }

    /** A reconnect of a coalesced request keeps its original response, even after the model advanced. */
    public static function joinedOperationalContextResponse(array $spec, ?string $idempotencyKey): ?string
    {
        if (($spec['operation_kind'] ?? 'response') !== 'response') return null;
        $db = SDB(null, 'medoo', 'sqlite');
        $baseTaskId = 'task_' . substr((string) $spec['task_key'], 0, 24);
        $link = $db->table('gateway_task_conversations')->where('base_task_id', $baseTaskId)->find();
        $taskId = is_array($link) ? (string) $link['current_task_id'] : $baseTaskId;
        $task = $db->table('gateway_tasks')->where('id', $taskId)->find();
        $owner = is_array($link) ? $link['current_owner_key'] : ($spec['browser_owner_key'] ?? null);
        $scopeMatches = is_array($task) && $task['owner_key'] === $owner
            && $task['client_id'] === ($spec['client_id'] ?? 'responses')
            && $task['thread_id'] === ($spec['thread_id'] ?? null)
            && $task['branch_id'] === ($spec['branch_id'] ?? 'main');
        if (!$scopeMatches && $idempotencyKey === null) return null;
        $query = $db->table('gateway_task_events')->where('event_type', 'native_context_request_joined');
        // Idempotency keys share the same global namespace as ordinary Responses jobs.
        if ($idempotencyKey === null) $query = $query->where('task_id', $taskId);
        $events = $query->order([['id','DESC']])->select();
        if (!is_array($events)) throw new GatewayError('storage_unavailable', '环境请求记录暂不可读取。', 503);
        foreach ($events as $event) {
            $data = json_decode((string) $event['data_json'], true, 32, JSON_THROW_ON_ERROR);
            $sameHash = hash_equals((string) $data['request_hash'], (string) $spec['request_hash']);
            $sameKey = $idempotencyKey !== null && $idempotencyKey === ($data['idempotency_key'] ?? null);
            if ($sameKey && (!$sameHash || !$scopeMatches)) throw new GatewayError('idempotency_conflict', '同一 Idempotency-Key 已用于不同请求。', 409);
            if (!$sameHash || !$scopeMatches || $event['task_id'] !== $taskId) continue;
            $source = $db->table('gateway_response_jobs')->where('id', (string) $event['response_id'])->find();
            if (is_array($source) && $source['task_id'] === $taskId && $source['model'] === $spec['model']
                && ($source['reasoning_effort'] ?? null) === ($spec['reasoning_effort'] ?? null)) {
                if (!empty($data['mailbox_join']) || !empty($data['steering_join'])) {
                    $unknown = $db->table('gateway_tool_invocations')->where('task_id', $taskId)
                        ->where('turn_id', (string) $source['durable_turn_id'])->where('status', 'unknown')->count();
                    if (!is_numeric($unknown)) throw new GatewayError('storage_unavailable', '代理请求的宿主结果状态暂不可读取。', 503);
                    if ((int) $unknown !== 0) {
                        throw new GatewayError('tool_outcome_unknown', '代理请求的宿主操作结果尚未确认，不能重放原工具批次。', 409);
                    }
                }
                if (!empty($data['mailbox_join']) && $source['status'] === 'completed' && $source['round_kind'] === 'text') {
                    $pending = self::pendingOperationalContextUnlocked($source);
                    foreach ($pending['messages'] as $record) if (($record['role'] ?? '') === 'agent_message') return null;
                }
                return (string) $source['id'];
            }
        }
        return null;
    }

    /**
     * Bind a mailbox restart's exact native request to the new response after pending records attach.
     * @param array $reservation Durable turn/surface reservation from the current transaction.
     * @param string $responseId New response carrying the complete mailbox context.
     * @param string $requestHash Exact native request identity.
     * @param ?string $idempotencyKey Optional global Responses idempotency key.
     * @return void
     */
    public static function bindMailboxContextRoundUnlocked(array $reservation, string $responseId,
        string $requestHash, ?string $idempotencyKey): void
    {
        self::eventUnlocked((string) $reservation['task_id'], (string) $reservation['turn_id'], $responseId,
            (string) $reservation['surface_id'], 'native_context_request_joined',
            ['request_hash' => $requestHash, 'idempotency_key' => $idempotencyKey, 'mailbox_join' => true]);
    }

    /** Read pending records for this exact durable turn, retaining their original role, ID and content. */
    public static function pendingOperationalContextUnlocked(array $source): array
    {
        $events = SDB(null, 'medoo', 'sqlite')->table('gateway_task_events')->where('task_id', (string) $source['task_id'])
            ->where('turn_id', (string) $source['durable_turn_id'])
            ->where('event_type', 'IN', ['native_context_update_queued', 'native_context_updates_attached'])
            ->order([['id', 'ASC']])->select();
        if (!is_array($events)) throw new GatewayError('storage_unavailable', '环境更新记录暂不可读取。', 503);
        $pending = [];
        $policies = [];
        foreach ($events as $event) {
            $data = json_decode((string) $event['data_json'], true, 128, JSON_THROW_ON_ERROR);
            if ($event['event_type'] === 'native_context_update_queued') {
                $pending[(int) $event['id']] = $data['message'];
                if (isset($data['steering_full_context'])) $policies[(int) $event['id']] = (bool) $data['steering_full_context'];
            }
            else foreach ((array) ($data['event_ids'] ?? []) as $id) unset($pending[(int) $id]);
        }
        $policies = array_intersect_key($policies, $pending);
        return ['messages' => array_values($pending), 'event_ids' => array_keys($pending),
            ...($policies !== [] ? ['steering_full_context' => end($policies)] : [])];
    }

    /** The receipt job and update attachment are committed atomically; this is not an MCP delivery claim. */
    public static function attachOperationalContextUnlocked(array $reservation, string $responseId, array $eventIds): void
    {
        if ($eventIds === []) return;
        $events = SDB(null, 'medoo', 'sqlite')->table('gateway_task_events')
            ->where('task_id', (string) $reservation['task_id'])->where('id', 'IN', $eventIds)
            ->where('event_type', 'native_context_update_queued')->select();
        if (!is_array($events)) throw new GatewayError('storage_unavailable', '环境更新记录暂不可读取。', 503);
        $byTurn = [];
        foreach ($events as $event) $byTurn[(string) $event['turn_id']][] = (int) $event['id'];
        foreach ($byTurn as $turnId => $ids) {
            self::eventUnlocked((string) $reservation['task_id'], $turnId, $responseId,
                (string) $reservation['surface_id'], 'native_context_updates_attached', ['event_ids' => $ids]);
        }
    }

    /** Step 06 can register calls here; Step 05 deliberately has no execution/result shortcut. */
    public static function registerToolInvocation(string $taskId, string $turnId, string $responseId, string $callId, string $requestHash): string
    {
        return Schema::transaction(function () use ($taskId, $turnId, $responseId, $callId, $requestHash): string {
            $db = SDB(null, 'medoo', 'sqlite');
            $existing = $db->table('gateway_tool_invocations')->where('task_id', $taskId)->where('turn_id', $turnId)->where('call_id', $callId)->find();
            if (is_array($existing)) {
                if (!hash_equals((string) $existing['request_hash'], $requestHash)) throw new GatewayError('tool_invocation_conflict', '同一 call_id 已登记为不同请求。', 409);
                return (string) $existing['id'];
            }
            $id = 'inv_' . bin2hex(random_bytes(12));
            if (!$db->table('gateway_tool_invocations')->add([
                'id' => $id, 'task_id' => $taskId, 'turn_id' => $turnId, 'response_id' => $responseId,
                'call_id' => $callId, 'request_hash' => $requestHash, 'status' => 'registered',
                'created_at' => time(), 'updated_at' => time(),
            ])) throw new GatewayError('storage_unavailable', '工具调用账本无法登记。', 503);
            self::eventUnlocked($taskId, $turnId, $responseId, null, 'tool_registered', ['invocation_id' => $id, 'call_id' => $callId]);
            return $id;
        });
    }

    /** Retire one logical epoch after reconciling retained predecessors, preserving real page Stop obligations. */
    private static function retireEpochUnlocked(string $taskId, string $epochId, string $reason): void
    {
        $db = SDB(null, 'medoo', 'sqlite');
        // Reconcile a pre-upgrade retained-page handoff before retiring its destination.
        TaskPageRetirement::reconcileTransferredUnlocked($taskId);
        $now = time();
        $db->table('gateway_task_epochs')->where('id', $epochId)->where('status', 'active')->update(['status' => 'retired', 'retired_at' => $now]);
        $surfaces = $db->table('gateway_browser_surfaces')->where('task_id', $taskId)->where('epoch_id', $epochId)->where('status', '<>', 'retired')->select();
        foreach (is_array($surfaces) ? $surfaces : [] as $surface) {
            if ($reason !== 'checkpoint_page_retained') TaskPageRetirement::captureUnlocked($surface);
            $db->table('gateway_browser_surfaces')->where('id', $surface['id'])->update([
                'status' => 'retired', 'session_id' => null, 'retired_at' => $now, 'updated_at' => $now,
            ]);
            // A checkpoint advances the logical epoch while transferring the physical page.
            // Its dedicated transfer event records both rows without reporting a page closure.
            if ($reason !== 'checkpoint_page_retained') {
                self::eventUnlocked($taskId, null, null, (string) $surface['id'], 'surface_retired', ['reason' => $reason]);
            }
        }
    }

    private static function executionRowsUnlocked(string $responseId): array
    {
        $db = SDB(null, 'medoo', 'sqlite');
        $job = $db->table('gateway_response_jobs')->where('id', $responseId)->find();
        if (!is_array($job) || empty($job['durable_turn_id']) || empty($job['surface_id'])) {
            throw new GatewayError('task_ledger_missing', 'Response 尚未绑定持久任务账本。', 503);
        }
        $turn = $db->table('gateway_task_turns')->where('id', (string) $job['durable_turn_id'])->find();
        $surface = $db->table('gateway_browser_surfaces')->where('id', (string) $job['surface_id'])->find();
        if (!is_array($turn) || !is_array($surface)) throw new GatewayError('task_ledger_missing', '任务回合或页面记录缺失。', 503);
        return [$job, $turn, $surface];
    }

    /** A retired Web callback must never mutate the replacement generation. */
    private static function ownsCurrentGeneration(array $job, array $turn): bool
    {
        return (string) ($turn['response_id'] ?? '') === (string) ($job['id'] ?? '')
            && (string) ($turn['generation_id'] ?? '') === (string) ($job['generation_id'] ?? '')
            && (string) ($turn['surface_id'] ?? '') === (string) ($job['surface_id'] ?? '');
    }

    private static function assertFence(array $surface, string $incarnation, int $fence): void
    {
        if (!hash_equals((string) $surface['owner_incarnation'], $incarnation) || (int) $surface['fencing_version'] !== $fence) {
            throw new GatewayError('surface_fence_lost', '任务页面所有权已经变化，旧 Worker 不能提交结果。', 409);
        }
    }

    private static function unknownFailureCode(string $code): bool
    {
        return in_array($code, [
            'CHATGPT_WORKER_RESTARTED',
            'RECOVERY_OUTCOME_UNKNOWN',
            'CHATGPT_PAGE_CHANGED',
            'CHATGPT_STATE_UNAVAILABLE',
            'CHATGPT_SEND_UNCONFIRMED',
            'BROWSER_CDP_DISCONNECTED',
        ], true);
    }

    private static function eventUnlocked(string $taskId, ?string $turnId, ?string $responseId, ?string $surfaceId, string $type, array $data): void
    {
        $ok = SDB(null, 'medoo', 'sqlite')->table('gateway_task_events')->add([
            'task_id' => $taskId,
            'turn_id' => $turnId !== '' ? $turnId : null,
            'response_id' => $responseId !== '' ? $responseId : null,
            'surface_id' => $surfaceId !== '' ? $surfaceId : null,
            'event_type' => $type,
            'data_json' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'created_at' => time(),
        ]);
        if (!$ok) throw new GatewayError('storage_unavailable', '任务时间线事件无法保存。', 503);
    }

    private static function publicTask(array $task, ?array $epoch, ?array $surface, ?array $turn): array
    {
        $chat = TaskWebChat::state((string) $task['id']);
        return [
            'id' => (string) $task['id'], 'client_id' => (string) $task['client_id'],
            'web_chat_mode' => $chat['mode'], 'web_conversation_id' => $chat['conversation_id'] ?? null,
            'thread_id' => $task['thread_id'], 'branch_id' => (string) $task['branch_id'],
            'status' => (string) $task['status'], 'current_epoch_id' => $task['current_epoch_id'],
            'consecutive_gateway_errors'=>(int)($task['consecutive_gateway_errors'] ?? 0),
            'gateway_rebuild_pending'=>!empty($task['gateway_rebuild_pending']),
            'epoch_no' => is_array($epoch) ? (int) $epoch['epoch_no'] : null,
            'surface_status' => is_array($surface) ? (string) $surface['status'] : null,
            'turn_status' => is_array($turn) ? (string) $turn['status'] : null,
            'created_at' => (int) $task['created_at'], 'updated_at' => (int) $task['updated_at'],
            'cancelled_at' => $task['cancelled_at'] === null ? null : (int) $task['cancelled_at'],
        ];
    }

    private static function publicEpoch(array $row): array
    {
        return [
            'id' => (string) $row['id'], 'epoch_no' => (int) $row['epoch_no'], 'model' => (string) $row['model'],
            'reasoning_effort' => $row['reasoning_effort'], 'compaction_epoch' => (string) $row['compaction_epoch'],
            'status' => (string) $row['status'], 'created_at' => (int) $row['created_at'],
            'retired_at' => $row['retired_at'] === null ? null : (int) $row['retired_at'],
        ];
    }

    private static function publicTurn(array $row): array
    {
        return [
            'id' => (string) $row['id'], 'client_turn_id' => $row['client_turn_id'], 'epoch_id' => (string) $row['epoch_id'],
            'surface_id' => (string) $row['surface_id'], 'generation_id' => (string) $row['generation_id'],
            'response_id' => $row['response_id'], 'status' => (string) $row['status'],
            'fencing_version' => (int) $row['fencing_version'], 'deadline_at' => (int) $row['deadline_at'],
            'submit_intent_at' => $row['submit_intent_at'] === null ? null : (int) $row['submit_intent_at'],
            'submitted_at' => $row['submitted_at'] === null ? null : (int) $row['submitted_at'],
            'committed_at' => $row['committed_at'] === null ? null : (int) $row['committed_at'],
            'error_code' => $row['error_code'], 'created_at' => (int) $row['created_at'], 'updated_at' => (int) $row['updated_at'],
        ];
    }

    private static function publicSurface(array $row): array
    {
        return [
            'id' => (string) $row['id'], 'epoch_id' => (string) $row['epoch_id'], 'status' => (string) $row['status'],
            'generation' => (int) $row['generation'], 'fencing_version' => (int) $row['fencing_version'],
            'target_bound' => !empty($row['target_id']), 'url' => (string) $row['url'], 'title' => (string) $row['title'],
            'last_observed_at' => (int) $row['last_observed_at'], 'created_at' => (int) $row['created_at'], 'updated_at' => (int) $row['updated_at'],
        ];
    }
}
