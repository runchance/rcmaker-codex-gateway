<?php
declare(strict_types=1);
namespace app\gateway\model;

use app\gateway\database\Schema;

/** Bounded page rebuilds from proven inert work or confirmed receipts, preserving durable context. */
final class ContextRecovery
{
    /** Prove that a failed Codex retry never reached the executable Web commit or a host effect. */
    public static function canRetryFailedBeforeCommitUnlocked(string $responseId): bool
    {
        $db = SDB(null, 'medoo', 'sqlite');
        $job = $db->table('gateway_response_jobs')->where('id', $responseId)->find();
        if (!is_array($job) || $job['status'] !== 'failed'
            || !in_array($job['operation_kind'], ['response', 'compact_v1', 'compact_v2'], true)
            || $job['submit_intent_at'] !== null || $job['accepted_at'] !== null
            || (int) $job['output_started'] !== 0 || (string) $job['output_text'] !== '') return false;
        $turn = $db->table('gateway_task_turns')->where('id', $job['durable_turn_id'])->find();
        if (!is_array($turn) || $turn['status'] !== 'failed' || $turn['submit_intent_at'] !== null
            || $turn['submitted_at'] !== null || $turn['committed_at'] !== null) return false;
        $active = $db->table('gateway_response_jobs')->where('task_id', $job['task_id'])
            ->where('status', 'IN', ['queued', 'running'])->count();
        $calls = $db->table('gateway_tool_invocations')->where('turn_id', $turn['id'])->count();
        if (!is_numeric($active) || (int) $active !== 0 || !is_numeric($calls)) return false;
        if (self::failedReadonlyReplayIsInertUnlocked($job, $turn)) return true;
        if ((int) $calls !== 0) return false;
        if (!empty($job['mcp_binding_id'])) {
            $binding = $db->table('gateway_mcp_bindings')->where('id', $job['mcp_binding_id'])->find();
            if (!is_array($binding) || $binding['sent_at'] !== null || $binding['started_at'] !== null
                || (int) $binding['activity_revision'] !== 0) return false;
            $activities = $db->table('gateway_mcp_activities')->where('binding_id', $binding['id'])->count();
            if (!is_numeric($activities) || (int) $activities !== 0) return false;
        }
        return true;
    }

    /** Distinguish historical completed work from the failed, never-submitted read-only checkpoint on that same turn. */
    private static function failedReadonlyReplayIsInertUnlocked(array $job, array $turn): bool
    {
        if (!in_array($job['operation_kind'], ['compact_v1','compact_v2'], true) || !empty($job['previous_response_id'])) return false;
        $db = SDB(null, 'medoo', 'sqlite');
        $event = $db->table('gateway_task_events')->where('response_id', $job['id'])
            ->where('event_type', 'mcp_readonly_compaction_replay_prepared')->order([['id','DESC']])->limit(1)->find();
        $evidence = is_array($event) ? json_decode((string)$event['data_json'], true) : null;
        if (!is_array($evidence) || ($event['task_id'] ?? null) !== $job['task_id'] || ($event['turn_id'] ?? null) !== $turn['id']
            || !is_string($evidence['context_sha256'] ?? null)
            || !hash_equals($evidence['context_sha256'], hash('sha256', (string)$job['context_json']))) return false;
        $environment = json_decode((string)$job['tool_environment_json'], true);
        if (!is_array($environment) || ($environment['tools'] ?? null) !== []) return false;
        $calls = $db->table('gateway_tool_invocations')->where('turn_id', $turn['id'])->select();
        if (!is_array($calls)) return false;
        foreach ($calls as $call) {
            if (($call['response_id'] ?? null) === $job['id'] || $call['status'] !== 'completed' || $call['result_delivered_at'] === null) return false;
        }
        if (!ContextCompaction::readonlyReplayHistoryIsCompleteUnlocked($job)) return false;
        $bindings = $db->table('gateway_mcp_bindings')->where('turn_id', $turn['id'])->select();
        if (!is_array($bindings)) return false;
        foreach ($bindings as $binding) {
            $active = $db->table('gateway_mcp_activities')->where('binding_id', $binding['id'])->where('status', 'active')->count();
            if (!is_numeric($active) || (int)$active !== 0) return false;
            if ($binding['id'] !== ($job['mcp_binding_id'] ?? null)) {
                if (!in_array($binding['status'], ['completed','revoked'], true)) return false;
                continue;
            }
            $boundEnvironment = json_decode((string)$binding['tool_environment_json'], true);
            if (!in_array($binding['status'], ['awaiting_start','revoked'], true) || $binding['sent_at'] !== null
                || $binding['started_at'] !== null || ($boundEnvironment['tools'] ?? null) !== []
                || $binding['current_response_id'] !== $job['id']) return false;
        }
        return true;
    }

    /** Release an older no-progress failure after every native effect is confirmed delivered. */
    public static function releaseKnownNoProgress(string $responseId): bool
    {
        return Schema::transaction(static fn(): bool => self::releaseKnownNoProgressUnlocked($responseId));
    }

    /** The caller may already own the ledger transaction. No host operation is replayed. */
    public static function releaseKnownNoProgressUnlocked(string $responseId): bool
    {
        $db = SDB(null, 'medoo', 'sqlite');
        $job = $db->table('gateway_response_jobs')->where('id', $responseId)->find();
        if (!is_array($job) || $job['status'] !== 'failed') return false;
        $error = json_decode((string)$job['error_json'], true);
        if (($error['error']['code'] ?? '') !== 'continuation_no_progress') return false;
        $turn = $db->table('gateway_task_turns')->where('id', $job['durable_turn_id'])->find();
        $task = $db->table('gateway_tasks')->where('id', $job['task_id'])->find();
        $epoch = $db->table('gateway_task_epochs')->where('id', $job['epoch_id'])->find();
        $surface = $db->table('gateway_browser_surfaces')->where('id', $job['surface_id'])->find();
        if (!is_array($turn) || !is_array($task) || !is_array($epoch) || !is_array($surface)
            || $turn['status'] !== 'needs_attention' || $task['status'] !== 'needs_attention'
            || $epoch['status'] !== 'needs_attention' || $task['current_epoch_id'] !== $epoch['id']
            || $turn['error_code'] !== 'continuation_no_progress'
            || !in_array($surface['status'], ['needs_attention','retired'], true)) return false;
        $accepted = $db->table('gateway_task_events')->where('turn_id', $turn['id'])
            ->where('event_type', 'task_continuation_accepted')->count();
        $activeJobs = $db->table('gateway_response_jobs')->where('task_id', $task['id'])
            ->where('status', 'IN', ['queued','running'])->count();
        $otherAttention = $db->table('gateway_task_turns')->where('task_id', $task['id'])
            ->where('id', '<>', $turn['id'])->where('status', 'needs_attention')->count();
        if (!is_numeric($accepted) || (int)$accepted === 0 || !is_numeric($activeJobs) || (int)$activeJobs !== 0
            || !is_numeric($otherAttention) || (int)$otherAttention !== 0) return false;
        $calls = $db->table('gateway_tool_invocations')->where('turn_id', $turn['id'])->select();
        $bindings = $db->table('gateway_mcp_bindings')->where('turn_id', $turn['id'])->select();
        if (!is_array($calls) || $calls === [] || !is_array($bindings)) return false;
        foreach ($calls as $call) {
            if ($call['status'] !== 'completed' || $call['result_delivered_at'] === null) return false;
        }
        foreach ($bindings as $binding) {
            $active = $db->table('gateway_mcp_activities')->where('binding_id', $binding['id'])
                ->where('status', 'active')->count();
            if (!is_numeric($active) || (int)$active !== 0) return false;
        }
        $now = time();
        foreach ($bindings as $binding) {
            if (in_array($binding['status'], ['completed','revoked'], true)) continue;
            $updated = $db->table('gateway_mcp_bindings')->where('id', $binding['id'])->update([
                'status'=>'revoked','revoked_at'=>$now,'updated_at'=>$now]);
            if (!$updated || $updated->rowCount() !== 1) throw new GatewayError('storage_unavailable', '工具绑定无法安全撤销。', 503);
        }
        $updates = [
            $db->table('gateway_task_turns')->where('id', $turn['id'])->update(['status'=>'failed','updated_at'=>$now]),
            $db->table('gateway_browser_surfaces')->where('id', $surface['id'])->update([
                'status'=>'retired','session_id'=>null,'retired_at'=>$now,'updated_at'=>$now]),
            $db->table('gateway_task_epochs')->where('id', $epoch['id'])->update(['status'=>'retired','retired_at'=>$now]),
            $db->table('gateway_tasks')->where('id', $task['id'])->update([
                'status'=>'active','current_epoch_id'=>null,'updated_at'=>$now]),
        ];
        foreach ($updates as $updated) {
            if (!$updated || $updated->rowCount() !== 1) throw new GatewayError('storage_unavailable', '已确认任务状态无法完整恢复。', 503);
        }
        if (!$db->table('gateway_task_events')->add(['task_id'=>$task['id'],'turn_id'=>$turn['id'],
            'response_id'=>$responseId,'surface_id'=>$surface['id'],'event_type'=>'confirmed_no_progress_released',
            'data_json'=>json_encode(['delivered_tool_calls'=>count($calls)], JSON_THROW_ON_ERROR),
            'created_at'=>$now])) {
            throw new GatewayError('storage_unavailable', '已确认任务的恢复证据无法保存。', 503);
        }
        return true;
    }

    /**
     * Rebuild an unbound request after authoritative page loss. The caller owns the ledger
     * transaction and the retry/output fence; this method proves no host capability existed.
     * @param array $job Durable response row, never a browser-provided transcript.
     * @return array|null Fresh prompt and persisted transport fields, or null for possible host work.
     * @throws GatewayError If the canonical context cannot fit or storage is unavailable.
     */
    public static function prepareUnboundReplayUnlocked(array $job): ?array
    {
        if (!empty($job['mcp_binding_id'])) return null;
        $db = SDB(null, 'medoo', 'sqlite');
        foreach (['gateway_mcp_bindings', 'gateway_tool_invocations'] as $table) {
            $count = $db->table($table)->where('turn_id', $job['durable_turn_id'])->count();
            if (!is_numeric($count)) throw new GatewayError('storage_unavailable', '只读任务恢复证据暂不可读取。', 503);
            if ((int)$count !== 0) return null;
        }
        $environment = json_decode((string)$job['tool_environment_json'], true, 128, JSON_THROW_ON_ERROR);
        $environment['tools'] = [];
        $environmentJson = ContextHistory::canonical($environment);
        $budget = json_decode((string)$job['budget_json'], true, 128, JSON_THROW_ON_ERROR);
        $spec = [
            'budget'=>$budget, 'context_json'=>$job['context_json'], 'full_context_json'=>$job['context_json'],
            'tool_environment_json'=>$environmentJson, 'tool_environment_hash'=>hash('sha256', $environmentJson),
            'browser_lease_key'=>$job['browser_lease_key'], 'operation_kind'=>$job['operation_kind'],
            'checkpoint_marker'=>$job['checkpoint_marker'], 'tools'=>[], 'tool_choice'=>'none', 'tool_outputs'=>[],
        ];
        if ($job['operation_kind'] === 'response' && array_key_exists('imported_history_selection', $budget)) {
            $spec['model'] = $job['model'] ?? null;
            $spec['reasoning_effort'] = $job['reasoning_effort'] ?? null;
            $spec = self::restoreImportedWindow($spec);
        }
        $logicalContext = (string)($spec['imported_budget_context_json'] ?? $job['context_json']);
        $attachmentTokens = AttachmentStore::tokenReserve(AttachmentStore::selectForContext([], $logicalContext, false));
        if ($job['operation_kind'] !== 'response') {
            $physicalAttachmentTokens = AttachmentStore::tokenReserve(
                AttachmentStore::selectForContext([], $job['context_json']));
            $spec['budget'] = ContextBudget::forCompactionPrompt($budget,
                ContextCompaction::prompt((string) $job['context_json']), $physicalAttachmentTokens);
        }
        $spec = ContextWindow::prepare($spec, $attachmentTokens);
        $transport = (string)($spec['transport_context_json'] ?? $spec['context_json']);
        if ($job['operation_kind'] === 'response') {
            // A selected import owns only its retained logical images. Ordinary responses
            // continue to owe the complete resident reserve, even on a physical projection.
            $logicalContext = (string)($spec['imported_budget_context_json'] ?? $transport);
            if (isset($spec['imported_budget_context_json'])) {
                $attachmentTokens = AttachmentStore::tokenReserve(AttachmentStore::selectForContext([], $logicalContext, false));
            }
            ContextBudget::validate($logicalContext, $spec['budget'], $attachmentTokens);
        } else {
            $physicalAttachments = AttachmentStore::selectForContext([], $transport);
            ContextBudget::validate(AttachmentStore::transportJson($transport), $spec['budget'],
                AttachmentStore::tokenReserve($physicalAttachments));
        }
        $binding = ToolBroker::prepareRoundUnlocked($spec,
            ['task_id'=>$job['task_id'], 'turn_id'=>$job['durable_turn_id']], $job['id']);
        $prompt = $binding['control_prompt'];
        if ($prompt === '') {
            $prompt = $job['operation_kind'] === 'response'
                ? ContextHistory::plainPrompt(json_decode($transport, true, 128, JSON_THROW_ON_ERROR))
                : ContextCompaction::prompt($transport);
            if ($job['operation_kind'] === 'response' && !empty($job['checkpoint_marker'])) {
                $prompt = LunaCheckpoint::instructions($job['checkpoint_marker'])."\n\n".$prompt;
            }
        }
        $prompt = WebContract::withSteeringNotice($prompt,
            $job['operation_kind'] === 'response' && WebContract::hasSteeringNotice((string)$job['prompt']));
        return ['binding_id'=>$binding['binding_id'], 'prompt'=>$prompt, 'job_fields'=>[
            'mcp_binding_id'=>$binding['binding_id'], 'transport_context_json'=>$transport,
            'budget_json'=>json_encode($spec['budget'], JSON_THROW_ON_ERROR),
            'tool_environment_json'=>$environmentJson, 'tool_environment_hash'=>hash('sha256', $environmentJson),
        ]];
    }

    /**
     * Restore a persisted import on a fresh page, never the exact-prefix delta of its lost page.
     * @param array $spec Durable ordinary-response source and its persisted selection budget.
     * @return array Verified selected transport with the complete archive retained separately.
     * @throws GatewayError If the durable prefix or selection no longer proves a safe replay.
     */
    private static function restoreImportedWindow(array $spec): array
    {
        unset($spec['budget']['imported_transport_transaction_id'], $spec['budget']['imported_transport_context_sha256']);
        $source = json_decode((string)($spec['full_context_json'] ?? $spec['context_json']), true, 128, JSON_THROW_ON_ERROR);
        unset($source['continuation']);
        $spec['context_json'] = ContextHistory::canonical($source);
        unset($spec['transport_context_json']);
        $spec['continuation_source_response_id'] = null;
        $spec = ContextWindow::selectImportedHistory($spec, false);
        if (!isset($spec['imported_budget_context_json'])) {
            throw new GatewayError('recovery_conflict', '已保存的导入历史选择无法验证，未重新发送完整原文。', 409);
        }
        return $spec;
    }

    /**
     * Learn a conservative physical envelope from this task's proven single-message rejection.
     * Called in the request reservation transaction. No global account/window setting is changed,
     * no existing request is replayed, and native tool continuations retain their pinned budget.
     */
    public static function applyObservedMessageLimitUnlocked(array $spec, array $budget): array
    {
        if (($spec['tool_outputs'] ?? []) !== []) return $budget;
        $taskId = 'task_' . substr((string) $spec['task_key'], 0, 24);
        $rows = SDB(null, 'medoo', 'sqlite')->table('gateway_response_jobs')->where('task_id', $taskId)
            ->where('gateway_response_jobs.model', $spec['model'])->where('reasoning_effort', $spec['reasoning_effort'] ?? null)
            ->where('status', 'failed')->where('transport_parts_total', 0)->order([['rowid','DESC']])->limit(8)
            ->select('id,error_json,budget_json,prompt');
        foreach ($rows ?: [] as $row) {
            $error = json_decode((string) $row['error_json'], true);
            $prior = json_decode((string) $row['budget_json'], true);
            if (($error['error']['code'] ?? '') !== 'CHATGPT_MESSAGE_TOO_LARGE'
                || ($prior['account_plan'] ?? 'unknown') !== ($budget['account_plan'] ?? 'unknown')
                || trim((string) $row['prompt']) === '') continue;
            $limits = ['tokens'=>ContextBudget::tokens($row['prompt']), 'chars'=>mb_strlen($row['prompt'])];
            foreach ($limits as $unit => $observed) {
                $limit = max(1, (int) floor($observed * 0.75));
                foreach (['message_', 'stage_message_', 'stage_fallback_message_'] as $prefix) {
                    $key = $prefix . $unit;
                    if (isset($budget[$key])) $budget[$key] = min((int) $budget[$key], $limit);
                }
            }
            $budget['message_limit_source_response_id'] = $row['id'];
            return $budget;
        }
        return $budget;
    }

    /** Whether an error belongs to inert context transport rather than the executable commit. */
    private static function stagingError(string $code): bool
    {
        return str_starts_with($code, 'CHATGPT_MULTIPART_') || in_array($code,
            ['CHATGPT_MESSAGE_TOO_LARGE', 'CHATGPT_CONTEXT_TOO_LARGE', 'RECOVERY_MULTIPART_SURFACE_UNKNOWN'], true);
    }

    /** Called in a SQLite transaction: prove no executable commit, output or MCP work exists. */
    private static function inertStagingUnlocked(array $job): bool
    {
        if (empty($job['transport_transaction_id']) || (int) $job['transport_parts_total'] < 2
            || $job['submit_intent_at'] !== null || $job['accepted_at'] !== null
            || (int) $job['output_started'] !== 0 || $job['output_text'] !== '') return false;
        $db = SDB(null, 'medoo', 'sqlite');
        $turn = $db->table('gateway_task_turns')->where('id', $job['durable_turn_id'])->find();
        if (!is_array($turn) || $turn['submit_intent_at'] !== null || $turn['submitted_at'] !== null
            || $turn['committed_at'] !== null) return false;
        $calls = $db->table('gateway_tool_invocations')->where('turn_id', $turn['id'])->count();
        if ($calls === false || $calls === null || (int) $calls !== 0) return false;
        if (!empty($job['mcp_binding_id'])) {
            $binding = $db->table('gateway_mcp_bindings')->where('id', $job['mcp_binding_id'])->find();
            if (!is_array($binding) || $binding['status'] !== 'awaiting_start' || $binding['sent_at'] !== null
                || $binding['started_at'] !== null || (int) $binding['activity_revision'] !== 0) return false;
            $activities = $db->table('gateway_mcp_activities')->where('binding_id', $binding['id'])->count();
            if ($activities === false || $activities === null || (int) $activities !== 0) return false;
        }
        return true;
    }

    /**
     * Rebuild at most once, on a fresh page; never repeat a stage in its contaminated chat.
     * @param string $responseId Durable Responses job to inspect under the ledger transaction.
     * @param string $code Observed transport error, not model-authored recovery instructions.
     * @return array{target_id:?string,command_id:int}|null Old target to close and command to resume, or no safe repair.
     * @throws GatewayError If cooldown, context validation, storage or ownership prevents recovery.
     */
    public static function repairUnsent(string $responseId, string $code): ?array
    {
        $staging = self::stagingError($code);
        // A transient composer/menu failure is not loss of the conversation. Reconcile it
        // on the same page; rebuilding here discards retained context and repeats staging.
        if (!$staging && $code !== 'CHATGPT_SURFACE_MISSING') return null;
        ModelCooldown::assertReady();
        return Schema::transaction(static function () use ($responseId, $code, $staging): ?array {
            $db = SDB(null, 'medoo', 'sqlite');
            $job = $db->table('gateway_response_jobs')->where('id', $responseId)->find();
            $readOnlyFallback = is_array($job) && $job['operation_kind'] !== 'response' && $code === 'CHATGPT_SURFACE_MISSING';
            $stagingCompact = is_array($job) && $staging && $job['operation_kind'] === 'compact_v2';
            if (!is_array($job) || !in_array($job['status'], ['queued','running'], true) || (int) $job['repair_count'] > ($readOnlyFallback ? 1 : 0)
                || $job['submit_intent_at'] !== null || $job['accepted_at'] !== null || (!$staging && (int) $job['transport_stage_sent'] > 0)
                || $job['output_text'] !== '' || ($job['operation_kind'] !== 'response' && !$readOnlyFallback && !$stagingCompact)) return null;
            if ($staging && !self::inertStagingUnlocked($job)) return null;
            $turn = $db->table('gateway_task_turns')->where('id', $job['durable_turn_id'])->find();
            $surface = $db->table('gateway_browser_surfaces')->where('id', $job['surface_id'])->find();
            if (!is_array($turn) || $turn['status'] !== 'queued' || (int) $turn['deadline_at'] <= time()
                || !is_array($surface) || !in_array($surface['status'], ['ready','attaching','reserved','busy'], true)) return null;
            $budget = json_decode($job['budget_json'], true, 128, JSON_THROW_ON_ERROR);
            if ($staging && $code === 'CHATGPT_MESSAGE_TOO_LARGE') {
                $budget['context_min_parts'] = min((int) $budget['context_max_parts'], (int) $job['transport_parts_total'] + 1);
                // The observed staging envelope was rejected. Retry with smaller messages,
                // not the same overlong payload or a larger model fallback.
                foreach (['tokens','chars'] as $unit) {
                    $key = 'stage_message_' . $unit;
                    $budget[$key] = (int) floor($budget[$key] * 0.75);
                    $budget['stage_fallback_message_' . $unit] = $budget[$key];
                    // Shrinking only staging shifts its discarded load into the executable
                    // final message. A size retry must shrink that envelope too.
                    $budget['message_' . $unit] = min((int) $budget['message_' . $unit], $budget[$key]);
                }
            }
            // History is recompiled from the durable request; never from whatever text the page shows.
            $rebuildFull = !empty($job['continuation_source_response_id']);
            $context = json_decode((string) ($rebuildFull ? $job['context_json']
                : ($job['transport_context_json'] ?? $job['context_json'])), true, 128, JSON_THROW_ON_ERROR);
            $canonicalContext = ContextHistory::canonical($context);
            $importedRestore = null;
            if ($job['operation_kind'] === 'response' && array_key_exists('imported_history_selection', $budget)) {
                $environment = json_decode((string)$job['tool_environment_json'], true, 128, JSON_THROW_ON_ERROR);
                $importedRestore = self::restoreImportedWindow([
                    'budget'=>$budget, 'context_json'=>$job['context_json'], 'full_context_json'=>$job['context_json'],
                    'tool_environment_json'=>$job['tool_environment_json'], 'tool_environment_hash'=>$job['tool_environment_hash'],
                    'browser_lease_key'=>$job['browser_lease_key'], 'operation_kind'=>'response',
                    'checkpoint_marker'=>$job['checkpoint_marker'], 'tools'=>$environment['tools'] ?? [],
                    'tool_choice'=>empty($job['mcp_binding_id']) ? 'none' : 'auto', 'tool_outputs'=>[],
                ]);
                $logicalAttachments = AttachmentStore::selectForContext([], $importedRestore['imported_budget_context_json'], false);
                $importedRestore = ContextWindow::prepare($importedRestore, AttachmentStore::tokenReserve($logicalAttachments));
                $budget = $importedRestore['budget'];
                $canonicalContext = (string)$importedRestore['transport_context_json'];
                $context = json_decode($canonicalContext, true, 128, JSON_THROW_ON_ERROR);
            }
            $newBindingId = $job['mcp_binding_id'];
            $archivePrompt = null;
            if ($readOnlyFallback && empty($job['mcp_binding_id'])) {
                // A verified-page checkpoint loses its short prompt optimization with that page.
                // Rebuild the complete source in a bounded multipart transport when possible.
                // No host or archive-reader capability is introduced by this recovery.
                $sourceAttachmentTokens = AttachmentStore::tokenReserve(
                    AttachmentStore::selectForContext([], $job['context_json'], false));
                $physicalSourceAttachmentTokens = AttachmentStore::tokenReserve(
                    AttachmentStore::selectForContext([], $job['context_json']));
                $budget = ContextBudget::forCompactionPrompt($budget,
                    ContextCompaction::prompt((string) $job['context_json']), $physicalSourceAttachmentTokens);
                $restore = ContextWindow::prepare([
                    'budget'=>$budget, 'context_json'=>$job['context_json'], 'full_context_json'=>$job['context_json'],
                    'tool_environment_json'=>$job['tool_environment_json'], 'tool_environment_hash'=>$job['tool_environment_hash'],
                    'browser_lease_key'=>$job['browser_lease_key'], 'operation_kind'=>$job['operation_kind'],
                    'tools'=>[], 'tool_choice'=>'none', 'tool_outputs'=>[],
                ], $sourceAttachmentTokens);
                $budget = $restore['budget'];
                if (!empty($restore['budget']['original_context_required'])) {
                    $canonicalContext = $restore['transport_context_json'];
                    $context = json_decode($canonicalContext, true);
                    $bridge = ToolBroker::prepareRoundUnlocked($restore,
                        ['task_id'=>$job['task_id'], 'turn_id'=>$job['durable_turn_id']], $responseId);
                    $newBindingId = $bridge['binding_id'];
                    $archivePrompt = $bridge['control_prompt'];
                }
            }
            $attachments = AttachmentStore::selectForContext([], $canonicalContext);
            if ($job['operation_kind'] === 'response') {
                // Disabled skill files may now be inline; resident logical content still consumes tokens.
                $budgetAttachments = AttachmentStore::selectForContext([], $canonicalContext, false);
                ContextBudget::validate($canonicalContext, $budget, AttachmentStore::tokenReserve($budgetAttachments));
            } else {
                ContextBudget::validate(AttachmentStore::transportJson($canonicalContext), $budget,
                    AttachmentStore::tokenReserve($attachments));
            }
            $promptJob = $job;
            if ($importedRestore !== null) {
                // The existing capability still proves it was never sent or used. Give its
                // prompt builder the restored transport without replacing its original archive.
                $promptJob['transport_context_json'] = $canonicalContext;
                $promptJob['continuation_source_response_id'] = null;
                $promptJob['budget_json'] = json_encode($budget, JSON_THROW_ON_ERROR);
            }
            $exactImportedReadonly = $importedRestore !== null && empty($job['mcp_binding_id'])
                && $job['operation_kind'] === 'response';
            if ($exactImportedReadonly) {
                // Keep the import omission notice outside the JSON record projection, where
                // multipart commits preserve it. Reuse the original answer/footer controls.
                $readonlySpec = array_replace($importedRestore, [
                    'model'=>$job['model'], 'reasoning_effort'=>$job['reasoning_effort'] ?? null,
                    'checkpoint_marker'=>$job['checkpoint_marker'], 'budget'=>$budget,
                    'context_json'=>$canonicalContext, 'full_context_json'=>$job['context_json'],
                    'tool_environment_json'=>$job['tool_environment_json'],
                    'tool_environment_hash'=>$job['tool_environment_hash'],
                    'tools'=>[], 'tool_choice'=>'none', 'tool_outputs'=>[],
                ]);
                $prompt = ToolBroker::previewPromptForBudget($readonlySpec, $canonicalContext)['prompt'];
            } else {
                $prompt = $archivePrompt ?? (empty($job['mcp_binding_id'])
                    ? (in_array((string)($job['operation_kind'] ?? ''), ['compact_v1', 'compact_v2'], true)
                        ? ContextCompaction::prompt($readOnlyFallback ? (string)$job['context_json'] : $canonicalContext)
                        : ContextHistory::plainPrompt($context))
                    : ToolBroker::rebuildUnsentPromptUnlocked($promptJob));
            }
            if ($prompt === null) return null;
            if (!$exactImportedReadonly && !$readOnlyFallback && empty($job['mcp_binding_id']) && !empty($job['checkpoint_marker'])) {
                $prompt = LunaCheckpoint::instructions($job['checkpoint_marker']) . "\n\n" . $prompt;
            }
            if ($staging && $code === 'CHATGPT_MESSAGE_TOO_LARGE') {
                // Prove the smaller complete transaction fits before retiring/creating a page.
                // If not, native compaction receives the size error with all history intact.
                \support\browser\ChatGptTransport::plan($prompt, $budget, $budget,
                    ['sol_available'=>true], null, AttachmentStore::tokenReserve($attachments));
            }
            $fence = (int) $surface['fencing_version'] + 1;
            $surfaceUpdated = $db->table('gateway_browser_surfaces')->where('id', $surface['id'])->where('fencing_version', $surface['fencing_version'])->update([
                'target_id'=>null,'session_id'=>null,'status'=>'reserved','url'=>'','title'=>'',
                'generation'=>(int)$surface['generation']+1,'document_nonce'=>bin2hex(random_bytes(12)),
                'owner_incarnation'=>'unclaimed','fencing_version'=>$fence,'updated_at'=>time(),
            ]);
            $turnUpdated = $db->table('gateway_task_turns')->where('id',$turn['id'])->where('status','queued')->update(['owner_incarnation'=>'unclaimed','fencing_version'=>$fence,'updated_at'=>time()]);
            if (!$surfaceUpdated || $surfaceUpdated->rowCount() !== 1 || !$turnUpdated || $turnUpdated->rowCount() !== 1) {
                throw new GatewayError('recovery_conflict','页面恢复所有权发生冲突。',409);
            }
            $updated = $db->table('gateway_response_jobs')->where('id',$responseId)->where('repair_count',$job['repair_count'])->update([
                'repair_count'=>(int)$job['repair_count']+1,'prompt'=>$prompt,'transport_transaction_id'=>null,'transport_parts_total'=>0,
                'continuation_source_response_id'=>null,
                'transport_stage_acked'=>0,'transport_stage_sent'=>0,'transport_stage_effort'=>null,'transport_final_effort'=>null,
                'budget_json'=>json_encode($budget, JSON_THROW_ON_ERROR),
                'mcp_binding_id'=>$newBindingId, 'transport_context_json'=>$canonicalContext,
            ]);
            if (!$updated || $updated->rowCount() !== 1) throw new GatewayError('recovery_conflict','页面恢复状态发生冲突。',409);
            AttachmentStore::replaceResponseBindingsUnlocked($responseId, $attachments);
            if (!$db->table('gateway_task_events')->add(['task_id'=>$job['task_id'],'turn_id'=>$turn['id'],'response_id'=>$responseId,
                'surface_id'=>$surface['id'],'event_type'=>'unsent_surface_rebuilt',
                'data_json'=>json_encode(['cause'=>$code,'generation'=>(int)$surface['generation']+1,'context_hash'=>hash('sha256',ContextHistory::canonical($context)),
                    'discarded_transaction'=>$job['transport_transaction_id'],'final_commit_sent'=>false]),'created_at'=>time()])) {
                throw new GatewayError('storage_unavailable','页面恢复证据无法保存。',503);
            }
            return ['target_id'=>$surface['target_id'],'command_id'=>$job['command_id']];
        });
    }

    /**
     * Release a terminal staging failure or a lost-page failure with no host effects or output,
     * without replaying its request. The next caller
     * gets a new epoch and full context; retired target IDs remain available to normal cleanup.
     * Returns false for unknown host effects, active MCP work, concurrent turns or unrelated errors.
     * @param string $responseId Failed Responses job to reconcile.
     * @return bool Whether its proven-inert page and capability were retired.
     * @throws GatewayError If the transactional retirement cannot be persisted.
     */
    public static function retireFailedStaging(string $responseId): bool
    {
        return Schema::transaction(static fn(): bool => self::retireFailedStagingUnlocked($responseId));
    }

    /**
     * Same proof/retirement inside an existing request reservation transaction; no nested BEGIN.
     * @param string $responseId Failed job, read and reconciled in the caller's transaction.
     * @return bool Whether the inert failure was retired; callers must hold the SQLite transaction.
     * @throws GatewayError If the recovery evidence cannot be persisted.
     */
    public static function retireFailedStagingUnlocked(string $responseId): bool
    {
        $db = SDB(null, 'medoo', 'sqlite');
        $job = $db->table('gateway_response_jobs')->where('id', $responseId)->find();
        if (!is_array($job) || $job['status'] !== 'failed') return false;
        $error = json_decode((string) $job['error_json'], true);
        $code = (string)($error['error']['code'] ?? '');
        $inertStage = self::stagingError($code) && self::inertStagingUnlocked($job);
        // An older worker may have failed a lost page before safe recovery was implemented.
        // A new explicit user request may release that failure when no host effect/output exists.
        $emptyLostPage = $code === 'RECOVERY_OUTCOME_UNKNOWN' && (int)$job['output_started'] === 0 && $job['output_text'] === '';
        if ($emptyLostPage) {
            $calls = $db->table('gateway_tool_invocations')->where('turn_id', $job['durable_turn_id'])->count();
            $activities = empty($job['mcp_binding_id']) ? 0 : $db->table('gateway_mcp_activities')
                ->where('binding_id', $job['mcp_binding_id'])->where('status', 'active')->count();
            $emptyLostPage = is_numeric($calls) && (int)$calls === 0 && is_numeric($activities) && (int)$activities === 0;
        }
        if (!$inertStage && !$emptyLostPage) return false;
        $task = $db->table('gateway_tasks')->where('id', $job['task_id'])->find();
        $turn = $db->table('gateway_task_turns')->where('id', $job['durable_turn_id'])->find();
        if (!is_array($task) || !is_array($turn) || !in_array($task['status'], ['active','needs_attention'], true)
            || !in_array($turn['status'], ['failed','needs_attention'], true) || $task['current_epoch_id'] !== $turn['epoch_id']) return false;
        $other = $db->table('gateway_task_turns')->where('task_id', $task['id'])->where('id', '<>', $turn['id'])
            ->where('status', 'IN', ['queued','submit_intent','submitted','running','needs_attention'])->count();
        if ($other === false || $other === null || (int) $other !== 0) return false;
        $now = time();
        $updates = [];
        if (!empty($job['mcp_binding_id'])) {
            $updates[] = $db->table('gateway_mcp_bindings')->where('id', $job['mcp_binding_id'])->update([
                'status'=>'revoked','revoked_at'=>$now,'updated_at'=>$now]);
        }
        $updates[] = $db->table('gateway_task_turns')->where('id', $turn['id'])->update(['status'=>'failed','updated_at'=>$now]);
        $updates[] = $db->table('gateway_browser_surfaces')->where('id', $job['surface_id'])->update([
            'status'=>'retired','session_id'=>null,'retired_at'=>$now,'updated_at'=>$now]);
        $updates[] = $db->table('gateway_task_epochs')->where('id', $turn['epoch_id'])->update(['status'=>'retired','retired_at'=>$now]);
        $updates[] = $db->table('gateway_tasks')->where('id', $task['id'])->update(['status'=>'active','current_epoch_id'=>null,'updated_at'=>$now]);
        foreach ($updates as $updated) {
            if (!$updated || $updated->rowCount() !== 1) throw new GatewayError('storage_unavailable', '上下文失败恢复状态无法完整保存。', 503);
        }
        if (!$db->table('gateway_task_events')->add(['task_id'=>$task['id'],'turn_id'=>$turn['id'],'response_id'=>$responseId,
            'surface_id'=>$job['surface_id'],'event_type'=>$emptyLostPage ? 'empty_lost_page_failure_released' : 'inert_staging_discarded',
            'data_json'=>json_encode(['transaction_id'=>$job['transport_transaction_id'],
                'final_commit_sent'=>$job['submit_intent_at'] !== null,'tool_calls'=>0]),'created_at'=>$now])) {
            throw new GatewayError('storage_unavailable', '上下文失败恢复证据无法保存。', 503);
        }
        return true;
    }
}
