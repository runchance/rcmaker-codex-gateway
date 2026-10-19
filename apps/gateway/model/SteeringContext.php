<?php
declare(strict_types=1);
namespace app\gateway\model;

/** Same-document steering proofs; never changes checkpoint history or replays host actions. */
final class SteeringContext
{
    /**
     * Keep accepted host calls on their original capability until their exact receipts arrive.
     * @param array $spec Prepared native request, within the response reservation transaction.
     * @return array Ordinary receipt continuation with steering deferred, or the unchanged request.
     * @throws GatewayError If the existing call ledger cannot be read or the updates cannot be journaled.
     */
    public static function deferToolsUnlocked(array $spec): array
    {
        $messages = (array) ($spec['steering_messages'] ?? []);
        if ($messages === [] || empty($spec['previous_response_id'])) return $spec;
        $db = SDB(null, 'medoo', 'sqlite');
        $source = $db->table('gateway_response_jobs')->where('id', $spec['previous_response_id'])->find();
        if (!is_array($source) || empty($source['mcp_binding_id'])) return $spec;
        $settling = array_column((array) ($spec['tool_outputs'] ?? []), 'call_id');
        $rows = $db->table('gateway_tool_invocations')->where('binding_id', $source['mcp_binding_id'])
            ->where('status', 'IN', ['registered', 'delivered'])->select('call_id');
        if (!is_array($rows)) throw new GatewayError('storage_unavailable', '插话工具队列暂不可读取。', 503);
        if (array_diff(array_column($rows, 'call_id'), $settling) === []) return $spec;
        $updates = ['version' => 3, 'system' => [], 'messages' => $messages];
        if ($settling === []) {
            $spec['operational_context_source_response_id'] = $source['id'];
            $spec['operational_context_updates'] = $updates;
            $spec['steering_context_join'] = true;
            return $spec;
        }
        // This request must still return the next queued batch. Do not alias its identity to
        // the old batch, and do not mark the deferred human records as already attached.
        TaskLedger::queueOperationalContextUnlocked($source, $updates, $spec['request_hash'], null, false, true, false);
        $keys = array_fill_keys(array_map([ContextHistory::class, 'canonical'], $messages), true);
        foreach (['context_json', 'full_context_json', 'transport_context_json'] as $key) {
            if (empty($spec[$key])) continue;
            $context = json_decode($spec[$key], true, 128, JSON_THROW_ON_ERROR);
            $context['messages'] = array_values(array_filter($context['messages'],
                static fn(array $message): bool => !isset($keys[ContextHistory::canonical($message)])));
            $spec[$key] = ContextHistory::canonical($context);
        }
        $spec['prompt'] = ContextHistory::plainPrompt(json_decode($spec['context_json'], true, 128, JSON_THROW_ON_ERROR));
        $spec['steering_messages'] = [];
        unset($spec['steering_restart'], $spec['operational_context_event_ids']);
        return $spec;
    }

    /**
     * Pin the actual submitted source and build only its unseen suffix inside the reservation transaction.
     * Unsent consecutive steers accumulate against that source, not against an unsent predecessor.
     * @param array $spec Prepared request with complete durable history.
     * @return array Request with a destination-proven physical suffix and unchanged canonical history.
     * @throws GatewayError When the current document, history prefix or compaction scope is unproven.
     */
    public static function prepareUnlocked(array $spec): array
    {
        if (TaskWebChat::boundSpec($spec) !== null) return TaskWebChat::prepareContinuationUnlocked($spec);
        $db = SDB(null, 'medoo', 'sqlite');
        $source = $db->table('gateway_response_jobs')->where('id', (string) ($spec['stopped_source_response_id'] ?? $spec['previous_response_id']))->find();
        if (is_array($source) && self::isDelta($source) && empty($source['submit_intent_at'])) {
            $source = $db->table('gateway_response_jobs')->where('id', (string) $source['continuation_source_response_id'])->find();
        }
        $taskId = 'task_' . substr((string) $spec['task_key'], 0, 24);
        $surface = is_array($source) ? $db->table('gateway_browser_surfaces')->where('id', $source['surface_id'])->find() : null;
        $task = $db->table('gateway_tasks')->where('id', $taskId)->find();
        $epoch = is_array($surface) ? $db->table('gateway_task_epochs')->where('id', $surface['epoch_id'])->find() : null;
        if (is_array($source) && is_array($surface) && is_array($epoch) && is_array($task)
            && $source['task_id'] === $taskId && $source['operation_kind'] === 'response'
            && $source['model'] === $spec['model'] && $source['reasoning_effort'] === ($spec['reasoning_effort'] ?? null)
            && $task['current_epoch_id'] === $epoch['id'] && $source['epoch_id'] === $epoch['id']
            && $epoch['status'] === 'active' && empty($source['submit_intent_at'])) {
            $submitted = $db->table('gateway_response_jobs')->where('surface_id', $surface['id'])
                ->where('submit_intent_at', '<>', null)->count();
            if (!is_numeric($submitted)) throw new GatewayError('storage_unavailable', '插话原页提交记录暂不可读取。', 503);
            // There is no remote history to resend before this page's first submission.
            // Supersede the unsent request using the existing first-send reservation path.
            if ((int) $submitted === 0) return $spec;
        }
        if (!is_array($source) || !is_array($surface) || !is_array($epoch) || !is_array($task)
            || $source['task_id'] !== $taskId || $source['operation_kind'] !== 'response'
            || $source['model'] !== $spec['model'] || $source['reasoning_effort'] !== ($spec['reasoning_effort'] ?? null)
            || $surface['task_id'] !== $taskId || $epoch['task_id'] !== $taskId || $epoch['status'] !== 'active'
            || $task['current_epoch_id'] !== $epoch['id'] || $source['epoch_id'] !== $epoch['id']
            || !in_array($surface['status'], ['ready', 'busy'], true) || empty($surface['target_id'])
            || empty($source['submitted_document_nonce'])
            || $source['submitted_document_nonce'] !== $surface['document_nonce']) {
            // This is a preference for a healthy retained document, not a recovery veto.
            // Keep the already reconstructed full request for the existing ledger recovery
            // checks, capability rotation and old-page isolation below this preparation step.
            return $spec;
        }
        $anchor = $db->table('gateway_response_jobs')->where('surface_id', $surface['id'])
            ->where('epoch_id', $epoch['id'])->where('submitted_document_nonce', $surface['document_nonce'])
            ->where('submit_intent_at', '<>', null)->order([['rowid', 'DESC']])->limit(1)->find();
        if (!is_array($anchor) || empty($anchor['accepted_at']) || $anchor['operation_kind'] !== 'response'
            || $anchor['model'] !== $source['model'] || $anchor['task_id'] !== $taskId) {
            return $spec;
        }
        if (!empty($spec['stopped_source_response_id']) && !empty($source['mcp_binding_id'])) {
            $unsettled = $db->table('gateway_tool_invocations')->where('binding_id', $source['mcp_binding_id'])
                ->where('status', 'IN', ['registered', 'delivered', 'unknown'])->count();
            if ($unsettled === false || $unsettled === null || (int) $unsettled > 0) {
                throw new GatewayError('tool_outcome_pending', '停止前仍有未确认的工具结果，不能继续发送。', 409);
            }
        }
        $prior = json_decode((string) $source['context_json'], true, 128, JSON_THROW_ON_ERROR);
        $full = json_decode((string) $spec['full_context_json'], true, 128, JSON_THROW_ON_ERROR);
        $known = (array) ($prior['messages'] ?? []);
        $systems = (array) ($prior['system'] ?? []);
        if (ContextHistory::canonical(array_slice($full['messages'], 0, count($known))) !== ContextHistory::canonical($known)
            || ContextHistory::canonical(array_slice($full['system'], 0, count($systems))) !== ContextHistory::canonical($systems)) {
            throw new GatewayError('steering_history_mismatch', '插话历史与原页不一致；未发送增量或全量上下文。', 409);
        }
        // A completed receipt can still be waiting for MCP delivery when steering wins the race.
        // Include its original call/result records, with exact text/image content, before the new suffix.
        $pendingIds = [];
        if (!empty($source['mcp_binding_id'])) {
            $rows = $db->table('gateway_tool_invocations')->where('binding_id', $source['mcp_binding_id'])
                ->where('status', 'completed')->where('result_delivered_at', null)->select();
            if (!is_array($rows)) throw new GatewayError('storage_unavailable', '插话工具回执暂不可读取。', 503);
            $pendingIds = array_fill_keys(array_column($rows, 'call_id'), true);
        }
        $pending = array_values(array_filter($known, static fn(array $record): bool =>
            isset($pendingIds[(string) ($record['call_id'] ?? '')])));
        $delta = ['version' => $full['version'], 'system' => array_slice($full['system'], count($systems)),
            'messages' => array_merge($pending, array_slice($full['messages'], count($known)))];
        $binding = !empty($source['mcp_binding_id'])
            ? $db->table('gateway_mcp_bindings')->where('id', $source['mcp_binding_id'])->find() : null;
        $proof = ['mode' => 'exact_prefix', 'steering' => true, 'request_hash' => $spec['request_hash'],
            'previous_context_sha256' => hash('sha256', (string) $source['context_json']),
            'source_response_id' => $source['id'], 'source_surface_id' => $surface['id'],
            'source_epoch_id' => $epoch['id'], 'source_document_nonce' => $surface['document_nonce'],
            'source_target_id' => $surface['target_id'],
            'witness_response_id' => $anchor['id'],
            'connector_name' => is_array($binding) && !empty($binding['sent_at']) && !empty($binding['started_at'])
                ? (string) $binding['connector_name'] : '',
            'current_tool_environment_sha256' => $spec['tool_environment_hash'] ?? null];
        $delta['continuation'] = $proof;
        $spec['steering_delta'] = true;
        $spec['budget']['steering_delta'] = $proof;
        $spec['context_json'] = $spec['transport_context_json'] = ContextHistory::canonical($delta);
        $spec['continuation_source_response_id'] = $source['id'];
        $spec['compaction_epoch'] = $epoch['compaction_epoch'];
        $spec['browser_lease_key'] = $surface['lease_key'];
        $spec['prompt'] = ContextHistory::plainPrompt($delta);
        return $spec;
    }

    /**
     * True only for the physical steering job; inherited tool-round budgets cannot impersonate it.
     * @param array $job Stored or decoded response job. @return bool Whether its own identity matches the proof.
     */
    public static function isDelta(array $job): bool
    {
        $budget = $job['budget'] ?? json_decode((string) ($job['budget_json'] ?? '{}'), true, 128, JSON_THROW_ON_ERROR);
        $proof = $budget['steering_delta'] ?? [];
        return !empty($proof['steering']) && ($proof['request_hash'] ?? null) === ($job['request_hash'] ?? '')
            && ($proof['source_response_id'] ?? '') === ($job['continuation_source_response_id'] ?? null);
    }

    /**
     * Verify the pinned physical source again after worker reattachment, never borrowing another epoch.
     * @param array $job Steering response. @param array $surface Current destination ledger row.
     * @return bool Whether document, source history and active epoch still match.
     */
    public static function retained(array $job, array $surface): bool
    {
        if (!self::isDelta($job)) return false;
        $budget = $job['budget'] ?? json_decode($job['budget_json'], true, 128, JSON_THROW_ON_ERROR);
        $proof = $budget['steering_delta'];
        if (!empty($proof['regular_conversation_id']) && TaskWebChat::retained($job, $surface)) {
            $source = SDB(null, 'medoo', 'sqlite')->table('gateway_response_jobs')->where('id', $proof['source_response_id'])->find('task_id,context_json');
            return is_array($source) && $source['task_id'] === $job['task_id']
                && hash_equals($proof['previous_context_sha256'], hash('sha256', (string) $source['context_json']));
        }
        $db = SDB(null, 'medoo', 'sqlite');
        $source = $db->table('gateway_response_jobs')->where('id', $proof['source_response_id'])->find();
        $task = $db->table('gateway_tasks')->where('id', $job['task_id'])->find();
        return is_array($source) && is_array($task) && $source['operation_kind'] === 'response'
            && $source['task_id'] === $job['task_id'] && $source['epoch_id'] === $job['epoch_id']
            && $source['surface_id'] === $surface['id'] && $proof['source_surface_id'] === $surface['id']
            && $proof['source_epoch_id'] === $surface['epoch_id'] && $task['current_epoch_id'] === $surface['epoch_id']
            && $proof['source_target_id'] === $surface['target_id']
            && in_array($surface['status'], ['ready', 'busy'], true)
            && $proof['source_document_nonce'] === $surface['document_nonce']
            && $source['submitted_document_nonce'] === $surface['document_nonce']
            && hash_equals($proof['previous_context_sha256'], hash('sha256', (string) $source['context_json']));
    }
}
