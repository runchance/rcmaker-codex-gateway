<?php
declare(strict_types=1);
namespace app\gateway\model;

use app\gateway\database\Schema;
use support\browser\WebModelPage;

/** Task-owned Web conversation identity, independent of browser tabs and logical context epochs. */
final class TaskWebChat
{
    /**
     * Pin a new task's preference, or move the same conversation's binding across an admitted recovery.
     * @param string $taskId Newly created durable task ID.
     * @param string|null $sourceTaskId Retired/reconciled source selected by TaskLedger's existing recovery guards.
     * @param bool $freshConversation Start a new chat of the source mode after verified repeated-failure retirement.
     * @return void
     * @throws GatewayError On unavailable settings/storage or a foreign recovery scope.
     */
    public static function createUnlocked(string $taskId, ?string $sourceTaskId = null, bool $freshConversation = false): void
    {
        $db = SDB(null, 'medoo', 'sqlite');
        $settings = $db->table('gateway_settings')->where('id', 1)->find('web_chat_mode');
        if (!is_array($settings)) throw new GatewayError('storage_unavailable', 'Web chat preference could not be saved.', 503);
        $values = ['task_id' => $taskId, 'mode' => (string) $settings['web_chat_mode'], 'updated_at' => time()];
        $source = $sourceTaskId === null ? null : self::state($sourceTaskId);
        if (is_array($source) && ($freshConversation || ($source['mode'] === 'regular' && !empty($source['conversation_id'])))) {
            $oldTask = $db->table('gateway_tasks')->where('id', $sourceTaskId)->find('client_id,thread_id,branch_id');
            $newTask = $db->table('gateway_tasks')->where('id', $taskId)->find('client_id,thread_id,branch_id');
            if ($sourceTaskId === $taskId || !is_array($oldTask) || !is_array($newTask) || $oldTask !== $newTask) {
                throw new GatewayError('CHATGPT_CHAT_IDENTITY_CHANGED', 'The saved Web chat identity could not be confirmed.', 409);
            }
        }
        if ($freshConversation && is_array($source)) $values['mode'] = $source['mode'];
        if (!$freshConversation && is_array($source) && $source['mode'] === 'regular' && !empty($source['conversation_id'])) {
            // An internal task ID rotation is not deletion of its saved Web conversation.
            // Release the unique UUID conditionally before assigning it to the same native conversation.
            // The enclosing ledger transaction rolls both writes back if any later reservation fails.
            $released = $db->table('gateway_task_web_chats')->where('task_id', $sourceTaskId)
                ->where('conversation_id', $source['conversation_id'])->where('account_key', $source['account_key'])
                ->update(['conversation_id' => null, 'account_key' => null, 'updated_at' => time()]);
            if (!$released || $released->rowCount() !== 1) {
                throw new GatewayError('CHATGPT_CHAT_IDENTITY_CHANGED', 'The saved Web chat identity could not be confirmed.', 409);
            }
            $values += ['conversation_id' => $source['conversation_id'], 'account_key' => $source['account_key']];
            $values['mode'] = 'regular';
        }
        if (!$db->table('gateway_task_web_chats')->add($values)) {
            throw new GatewayError('storage_unavailable', 'Web chat preference could not be saved.', 503);
        }
    }

    /**
     * Repair the earlier recovery-route omission only for a task that has never submitted Web work.
     * Existing pages and submitted history require the explicit mode-migration path instead.
     * @param string $taskId Existing task selected by native conversation routing.
     * @return void The caller owns the admission transaction; reads never repair unrelated tasks.
     * @throws GatewayError If an identity/evidence read or the new preference write fails.
     */
    public static function initializeMissingUnsentUnlocked(string $taskId): void
    {
        if ((GatewaySettings::internal()['web_chat_mode'] ?? 'temporary') !== 'regular') return;
        $db = SDB(null, 'medoo', 'sqlite');
        $row = $db->table('gateway_task_web_chats')->where('task_id', $taskId)->find('task_id');
        if (is_array($row)) return;
        if ($row === false) throw new GatewayError('storage_unavailable', 'Web chat identity is temporarily unavailable.', 503);
        $evidence = [
            ['gateway_response_jobs', 'submit_intent_at', '<>', null],
            ['gateway_response_jobs', 'accepted_at', '<>', null],
            ['gateway_response_jobs', 'transport_stage_sent', '>', 0],
            ['gateway_response_jobs', 'output_started', '>', 0],
            ['gateway_mcp_bindings', 'sent_at', '<>', null],
            ['gateway_mcp_bindings', 'started_at', '<>', null],
            ['gateway_browser_surfaces', 'url', 'LIKE', '%/c/%'],
        ];
        foreach ($evidence as [$table, $field, $operator, $value]) {
            $found = $db->table($table)->where('task_id', $taskId)->where($field, $operator, $value)->limit(1)->find('id');
            if (is_array($found)) return;
            if ($found === false) throw new GatewayError('storage_unavailable', 'Web chat identity is temporarily unavailable.', 503);
        }
        $call = $db->table('gateway_tool_invocations')->where('task_id', $taskId)->limit(1)->find('id');
        if (is_array($call)) return;
        if ($call === false) throw new GatewayError('storage_unavailable', 'Tool receipt state is temporarily unavailable.', 503);
        self::createUnlocked($taskId);
    }

    /**
     * Stage a temporary task's first ordinary submission using its complete readable history.
     * Call only after SteeringContext has deferred outstanding host calls; receipt-only rounds keep the old page.
     * @param array $spec Prepared native request inside its admission transaction.
     * @return array Full first-ordinary transport and a new lease, or the unchanged request.
     * @throws GatewayError On unavailable settings or task identity storage.
     */
    public static function prepareModeChangeUnlocked(array $spec): array
    {
        if (!empty($spec['gateway_error_rebuild']) || empty($spec['task_key'])
            || (!empty($spec['tool_outputs']) && empty($spec['steering_restart']))) {
            return $spec;
        }
        if ((GatewaySettings::internal()['web_chat_mode'] ?? 'temporary') !== 'regular') {
            return $spec;
        }
        $db = SDB(null, 'medoo', 'sqlite');
        $taskId = 'task_' . substr((string) $spec['task_key'], 0, 24);
        $task = $db->table('gateway_tasks')->where('id', $taskId)->find('id');
        if ($task === false) {
            throw new GatewayError('storage_unavailable', 'Web chat identity is temporarily unavailable.', 503);
        }
        if (!is_array($task) || self::state($taskId)['mode'] !== 'temporary') {
            return $spec;
        }
        $pending = $db->table('gateway_tool_invocations')->where('task_id', $taskId)
            ->where('status', 'IN', ['registered', 'delivered', 'unknown'])->select('call_id');
        if (!is_array($pending)) {
            throw new GatewayError('storage_unavailable', 'Tool receipt state is temporarily unavailable.', 503);
        }
        $receivedCalls = array_column((array) ($spec['tool_outputs'] ?? []), 'call_id');
        if (array_diff(array_column($pending, 'call_id'), $receivedCalls) !== []) {
            return $spec;
        }
        $context = json_decode((string) ($spec['full_context_json'] ?? $spec['context_json']), true, 128, JSON_THROW_ON_ERROR);
        unset($context['continuation']);
        $spec['web_chat_mode_change'] = true;
        $spec['steering_full_context'] = true;
        unset($spec['steering_delta'], $spec['budget']['steering_delta'], $spec['budget']['postcompact_page']);
        $spec['context_json'] = $spec['full_context_json'] = $spec['transport_context_json'] = ContextHistory::canonical($context);
        $spec['continuation_source_response_id'] = $spec['rolling_source_response_id'] = null;
        $spec['browser_lease_key'] = hash('sha256', $spec['browser_lease_key'] . '|web-chat-mode|regular');
        $spec['prompt'] = ($spec['operation_kind'] ?? 'response') === 'response'
            ? ContextHistory::plainPrompt($context) : ContextCompaction::prompt($spec['context_json']);
        return $spec;
    }

    /**
     * Commit the staged mode only after reservation isolated an unsent ordinary destination.
     * @param array $spec Request returned by prepareModeChangeUnlocked.
     * @param array $reservation Current task/surface reservation; the caller owns its transaction.
     * @return void Existing ordinary bindings are never replaced by a preference change.
     * @throws GatewayError If the destination is not isolated or a conditional identity write fails.
     */
    public static function commitModeChangeUnlocked(array $spec, array $reservation): void
    {
        if (empty($spec['web_chat_mode_change'])) return;
        $db = SDB(null, 'medoo', 'sqlite');
        $surface = $db->table('gateway_browser_surfaces')->where('id', $reservation['surface_id'])
            ->where('task_id', $reservation['task_id'])->find();
        if (!is_array($surface) || $surface['status'] !== 'reserved' || !empty($surface['target_id'])) {
            throw new GatewayError('CHATGPT_CHAT_IDENTITY_CHANGED', 'The saved Web chat identity could not be confirmed.', 409);
        }
        $row = $db->table('gateway_task_web_chats')->where('task_id', $reservation['task_id'])->find();
        if ($row === false) throw new GatewayError('storage_unavailable', 'Web chat identity is temporarily unavailable.', 503);
        if (!is_array($row)) {
            self::createUnlocked($reservation['task_id']);
            return;
        }
        if ($row['mode'] !== 'temporary' || !empty($row['conversation_id'])) {
            throw new GatewayError('CHATGPT_CHAT_IDENTITY_CHANGED', 'The saved Web chat identity could not be confirmed.', 409);
        }
        $changed = $db->table('gateway_task_web_chats')->where('task_id', $reservation['task_id'])
            ->where('mode', 'temporary')->where('conversation_id', null)->update(['mode' => 'regular', 'updated_at' => time()]);
        if (!$changed || $changed->rowCount() !== 1) {
            throw new GatewayError('storage_unavailable', 'Web chat preference could not be saved.', 503);
        }
    }

    /**
     * Read pinned identity; legacy tasks without a record retain Temporary Chat behavior.
     * @param string $taskId Durable task ID.
     * @return array Mode, saved conversation UUID and private account fingerprint, if bound.
     * @throws GatewayError When storage fails rather than returning an absent legacy record.
     */
    public static function state(string $taskId): array
    {
        $row = SDB(null, 'medoo', 'sqlite')->table('gateway_task_web_chats')->where('task_id', $taskId)->find();
        if ($row === false) throw new GatewayError('storage_unavailable', 'Web chat identity is temporarily unavailable.', 503);
        return is_array($row) ? $row : ['task_id' => $taskId, 'mode' => 'temporary', 'conversation_id' => null, 'account_key' => null];
    }

    /**
     * Resolve a saved ordinary conversation for an already-routed native request.
     * @param array $spec Request with its canonical task_key.
     * @return array|null Bound ordinary state, or null for new, legacy or temporary tasks.
     * @throws GatewayError On unavailable identity storage.
     */
    public static function boundSpec(array $spec): ?array
    {
        if (empty($spec['task_key'])) return null;
        $chat = self::state('task_' . substr((string) $spec['task_key'], 0, 24));
        return $chat['mode'] === 'regular' && !empty($chat['conversation_id']) ? $chat : null;
    }

    /**
     * Add the first ordinary submission's unique message witness, also on proven-unsent rebuilds.
     * @param string $prompt Rendered physical message.
     * @param string $responseId Physical browser submission ID.
     * @param string $taskId Owning task.
     * @return string Original message or one prefixed with its exact witness.
     * @throws GatewayError On unavailable identity storage.
     */
    public static function withInitialBindingMarker(string $prompt, string $responseId, string $taskId): string
    {
        $chat = self::state($taskId);
        $marker = '<rcmaker_chat_binding>' . $responseId . '</rcmaker_chat_binding>';
        return $chat['mode'] === 'regular' && empty($chat['conversation_id']) && !str_contains($prompt, $marker)
            ? $marker . "\n" . $prompt : $prompt;
    }

    /**
     * Project only unseen native updates for a saved ordinary chat, independent of tab/document lifetimes.
     * The complete native request stays in full_context_json for audit and a future explicitly new chat.
     * @param array $spec Routed native request. @return array Same request with an ordinary-chat physical suffix.
     * @throws GatewayError When an exact steering source or current human message cannot be identified.
     */
    public static function prepareContinuationUnlocked(array $spec): array
    {
        $chat = self::boundSpec($spec);
        if ($chat === null || ($spec['operation_kind'] ?? 'response') !== 'response'
            || (!empty($spec['tool_outputs']) && empty($spec['steering_restart']))) return $spec;
        $full = json_decode((string) ($spec['full_context_json'] ?? $spec['context_json']), true, 128, JSON_THROW_ON_ERROR);
        $physical = json_decode((string) $spec['context_json'], true, 128, JSON_THROW_ON_ERROR);
        $db = SDB(null, 'medoo', 'sqlite');
        $sourceId = (string) ($spec['previous_response_id'] ?? $spec['stopped_source_response_id'] ?? '');
        $source = $sourceId !== '' ? $db->table('gateway_response_jobs')->where('id', $sourceId)->where('task_id', $chat['task_id'])->find() : null;
        if (!is_array($source)) $source = ResponseJob::latestForTaskUnlocked($chat['task_id']);
        $prior = is_array($source) ? json_decode((string) $source['context_json'], true, 128, JSON_THROW_ON_ERROR) : [];
        $checkpointResume = is_array($source) ? ContextCompaction::savedChatResumeUnlocked($spec, $source) : null;
        if ($checkpointResume !== null) {
            $messages = $checkpointResume['messages'];
            $spec['postcompact_user_input'] = $checkpointResume['user_input'];
        } elseif (($physical['continuation']['mode'] ?? '') === 'exact_prefix') {
            $messages = $physical['messages'];
        } elseif (!empty($spec['steering_messages'])) {
            $messages = array_values($spec['steering_messages']);
        } else {
            $messages = [];
            foreach (array_reverse((array) ($full['messages'] ?? []), true) as $index => $record) {
                if (($record['role'] ?? '') !== 'user' || ContextHistory::isOperationalUserMessage($record)
                    || str_starts_with(ContextHistory::text($record['content'] ?? ''), '# AGENTS.md instructions for ')
                    || str_starts_with(ContextHistory::text($record['content'] ?? ''), ContextCompaction::SUMMARY_PREFIX)) continue;
                $messages = array_slice($full['messages'], $index);
                break;
            }
        }
        if ($messages === []) throw new GatewayError('empty_context_delta', 'No current request could be identified for the saved Web chat.', 400);
        // A failed pre-send suffix never reached the saved conversation. Preserve its new
        // requirements too, without replaying the historical context in the durable audit.
        $unsentSource = $source;
        if (!empty($spec['regular_recovery_source_response_id']) && !empty($spec['regular_recovery_source_task_id'])) {
            $unsentSource = $db->table('gateway_response_jobs')->where('id', $spec['regular_recovery_source_response_id'])
                ->where('task_id', $spec['regular_recovery_source_task_id'])->find();
        }
        $unsentBudget = is_array($unsentSource) ? json_decode((string) $unsentSource['budget_json'], true, 128, JSON_THROW_ON_ERROR) : [];
        $unsentProof = $unsentBudget['regular_chat'] ?? [];
        if (is_array($unsentSource) && $unsentSource['operation_kind'] === 'response'
            && $unsentSource['submit_intent_at'] === null && $unsentSource['accepted_at'] === null
            && (int) $unsentSource['transport_stage_sent'] === 0 && (int) $unsentSource['output_started'] === 0
            && ($unsentProof['conversation_id'] ?? null) === $chat['conversation_id']
            && ($unsentProof['task_id'] ?? null) === $unsentSource['task_id']
            && ($unsentProof['request_hash'] ?? null) === $unsentSource['request_hash']) {
            $pending = json_decode((string) $unsentSource['transport_context_json'], true, 128, JSON_THROW_ON_ERROR);
            $unique = [];
            foreach ([...(array) ($pending['messages'] ?? []), ...$messages] as $record) $unique[ContextHistory::canonical($record)] = $record;
            $messages = array_values($unique);
        }
        if ((!empty($spec['steering_restart']) || !empty($spec['stopped_source_response_id'])) && !empty($source['mcp_binding_id'])) {
            $needed = array_fill_keys(array_column((array) ($spec['tool_outputs'] ?? []), 'call_id'), true);
            $pending = $db->table('gateway_tool_invocations')->where('binding_id', $source['mcp_binding_id'])
                ->where('status', 'completed')->where('result_delivered_at', null)->select('call_id');
            if (!is_array($pending)) throw new GatewayError('storage_unavailable', 'Tool receipt state is temporarily unavailable.', 503);
            foreach ($pending as $call) $needed[$call['call_id']] = true;
            $already = array_fill_keys(array_map([ContextHistory::class, 'canonical'], $messages), true);
            $receipts = array_values(array_filter((array) ($full['messages'] ?? []), static fn(array $record): bool =>
                isset($needed[(string) ($record['call_id'] ?? '')]) && !isset($already[ContextHistory::canonical($record)])));
            $messages = [...$receipts, ...$messages];
        }
        // Preserve new environment/developer updates, without replaying historical user/assistant messages.
        $known = array_fill_keys(array_map([ContextHistory::class, 'canonical'], (array) ($prior['messages'] ?? [])), true);
        $selected = array_fill_keys(array_map([ContextHistory::class, 'canonical'], $messages), true);
        $updates = [];
        foreach ((array) ($full['messages'] ?? []) as $record) {
            $key = ContextHistory::canonical($record);
            if (!isset($known[$key]) && !isset($selected[$key])
                && (in_array($record['role'] ?? '', ['developer', 'system'], true) || ContextHistory::isOperationalUserMessage($record))) $updates[] = $record;
        }
        $delta = ['version' => $full['version'] ?? 3, 'system' => ContextHistory::canonical($full['system'] ?? []) === ContextHistory::canonical($prior['system'] ?? [])
            ? [] : ($full['system'] ?? []), 'messages' => [...$updates, ...$messages]];
        $proof = ['task_id' => $chat['task_id'], 'conversation_id' => $chat['conversation_id'],
            'request_hash' => $spec['request_hash'], 'source_response_id' => $source['id'] ?? null];
        $spec['budget']['regular_chat'] = $proof;
        if ((!empty($spec['steering_restart']) || !empty($spec['stopped_source_response_id'])) && is_array($source)) {
            $surface = $db->table('gateway_browser_surfaces')->where('id', $source['surface_id'])->find();
            $anchor = $db->table('gateway_response_jobs')->where('task_id', $chat['task_id'])
                ->where('submit_intent_at', '<>', null)->order([['rowid', 'DESC']])->limit(1)->find('id');
            if (!is_array($surface) || !is_array($anchor)) throw new GatewayError('steering_source_unavailable', 'The saved Web chat steering source could not be confirmed.', 409);
            $steer = ['mode' => 'exact_prefix', 'steering' => true, 'regular_conversation_id' => $chat['conversation_id'],
                'request_hash' => $spec['request_hash'], 'source_response_id' => $source['id'],
                'source_surface_id' => $surface['id'], 'source_epoch_id' => $surface['epoch_id'],
                'source_document_nonce' => $surface['document_nonce'], 'source_target_id' => $surface['target_id'],
                'witness_response_id' => $anchor['id'], 'previous_context_sha256' => hash('sha256', (string) $source['context_json']),
                'connector_name' => '', 'current_tool_environment_sha256' => $spec['tool_environment_hash'] ?? null];
            $spec['steering_delta'] = true;
            $spec['budget']['steering_delta'] = $steer;
            $spec['continuation_source_response_id'] = $source['id'];
            $delta['continuation'] = $steer;
        } else {
            unset($spec['budget']['steering_delta'], $spec['steering_delta']);
            $spec['continuation_source_response_id'] = $source['id'] ?? null;
            $delta['continuation'] = ['mode' => 'exact_prefix', 'regular_conversation_id' => $chat['conversation_id']];
        }
        $spec['context_json'] = $spec['transport_context_json'] = ContextHistory::canonical($delta);
        $spec['prompt'] = ContextHistory::plainPrompt($delta);
        return $spec;
    }

    /**
     * Validate saved-chat provenance independently of disposable Chrome target/document identities.
     * @param array $job Routed spec or persisted execution metadata with its budget proof.
     * @param array $surface Current task surface; its URL/account are verified separately at runtime.
     * @return bool Whether the current task still owns this exact saved conversation.
     * @throws GatewayError On unavailable identity storage.
     */
    public static function retained(array $job, array $surface): bool
    {
        $budget = $job['budget'] ?? json_decode((string) ($job['budget_json'] ?? '{}'), true);
        $proof = $budget['regular_chat'] ?? null;
        if (!is_array($proof) || ($proof['request_hash'] ?? null) !== ($job['request_hash'] ?? null)
            || !is_string($proof['conversation_id'] ?? null)
            || ($proof['task_id'] ?? null) !== ($job['task_id'] ?? $surface['task_id'] ?? null)
            || ($surface['task_id'] ?? null) !== $proof['task_id'] || ($surface['status'] ?? '') === 'retired') return false;
        $chat = self::state($proof['task_id']);
        return $chat['mode'] === 'regular' && !empty($chat['conversation_id']) && $chat['conversation_id'] === $proof['conversation_id'];
    }

    /**
     * Fence a missing target while preserving the exact unsent message and capability.
     * Caller owns the write transaction and has already proved this request was never submitted.
     * @param array $job Queued unsent job.
     * @param array $turn Its current durable turn.
     * @param array $surface Its current disposable browser surface.
     * @return array Unchanged command/response IDs for the worker's reopen operation.
     * @throws GatewayError If ownership changed or a conditional write fails.
     */
    public static function reopenUnsentUnlocked(array $job, array $turn, array $surface): array
    {
        $db = SDB(null, 'medoo', 'sqlite');
        if ($turn['response_id'] !== $job['id'] || $turn['generation_id'] !== $job['generation_id']
            || $turn['surface_id'] !== $surface['id'] || $surface['task_id'] !== $job['task_id']
            || $turn['task_id'] !== $job['task_id']) {
            throw new GatewayError('surface_fence_lost', 'Saved Web chat reopening lost ownership.', 409);
        }
        $fence = (int) $surface['fencing_version'] + 1;
        $surfaceWrite = $db->table('gateway_browser_surfaces')->where('id', $surface['id'])->where('fencing_version', $surface['fencing_version'])->update([
            'target_id' => null, 'session_id' => null, 'status' => 'reserved', 'url' => '', 'title' => '',
            'generation' => (int) $surface['generation'] + 1, 'document_nonce' => bin2hex(random_bytes(12)),
            'owner_incarnation' => 'unclaimed', 'fencing_version' => $fence, 'updated_at' => time(),
        ]);
        $turnWrite = $db->table('gateway_task_turns')->where('id', $turn['id'])->where('status', 'queued')->update([
            'owner_incarnation' => 'unclaimed', 'fencing_version' => $fence, 'updated_at' => time(),
        ]);
        $jobWrite = $db->table('gateway_response_jobs')->where('id', $job['id'])->where('repair_count', $job['repair_count'])
            ->update(['repair_count' => (int) $job['repair_count'] + 1]);
        if (!$surfaceWrite || $surfaceWrite->rowCount() !== 1 || !$turnWrite || $turnWrite->rowCount() !== 1
            || !$jobWrite || $jobWrite->rowCount() !== 1) throw new GatewayError('surface_fence_lost', 'Saved Web chat reopening lost ownership.', 409);
        return ['command_id' => (int) $job['command_id'], 'response_id' => $job['id']];
    }

    /**
     * Transfer only this task's exact saved-chat tab across epochs, fencing old observers and cleanup.
     * @param string $taskId Owning task. @param string $oldEpoch Retired source epoch.
     * @param string $surfaceId Reserved destination surface. @param string $modelUrl Configured origin.
     * @return void No transfer occurs if the disposable target cannot be proven reusable.
     * @throws GatewayError On identity storage or conditional-write failure; caller owns the transaction.
     */
    public static function transferPageUnlocked(string $taskId, string $oldEpoch, string $surfaceId, string $modelUrl): void
    {
        $chat = self::state($taskId);
        if ($chat['mode'] !== 'regular' || empty($chat['conversation_id']) || $oldEpoch === '') return;
        $db = SDB(null, 'medoo', 'sqlite');
        $old = $db->table('gateway_browser_surfaces')->where('task_id', $taskId)->where('epoch_id', $oldEpoch)->find();
        $new = $db->table('gateway_browser_surfaces')->where('id', $surfaceId)->where('task_id', $taskId)->find();
        if (!is_array($old) || !is_array($new) || $old['status'] !== 'retired' || empty($old['target_id'])
            || !empty($new['target_id']) || !WebModelPage::isTaskChatUrl((string) $old['url'], $modelUrl, 'regular', $chat['conversation_id'])) return;
        $fence = (int) $old['fencing_version'] + 1;
        $released = $db->table('gateway_browser_surfaces')->where('id', $old['id'])->where('fencing_version', $old['fencing_version'])
            ->update(['target_id' => null, 'session_id' => null, 'fencing_version' => $fence]);
        $claimed = $db->table('gateway_browser_surfaces')->where('id', $surfaceId)->where('status', 'reserved')->update([
            'target_id' => $old['target_id'], 'document_nonce' => $old['document_nonce'], 'status' => 'ready',
            'url' => $old['url'], 'title' => $old['title'], 'generation' => (int) $old['generation'] + 1, 'fencing_version' => $fence,
        ]);
        if (!$released || $released->rowCount() !== 1 || !$claimed || $claimed->rowCount() !== 1) throw new GatewayError('surface_fence_lost', 'Saved Web chat transfer lost ownership.', 409);
    }

    /**
     * Bind only a submitted message observed on the exact current task/turn/surface fence.
     * @param array $expected Current execution identity. @param string $url Observed URL.
     * @param string $accountKey Fresh browser account fingerprint, never a token or email.
     * @param string $modelUrl Configured model origin.
     * @param bool $initialMessageWitness Exact physical request marker observed in the current DOM.
     * @param string|null $physicalResponseId Original browser submission pinned by native receipt rounds or exact retained steering.
     * @return array Pinned conversation state. @throws GatewayError On identity or storage conflict.
     */
    public static function observe(array $expected, string $url, string $accountKey, string $modelUrl,
        bool $initialMessageWitness = false, ?string $physicalResponseId = null): array
    {
        return Schema::transaction(static function () use ($expected, $url, $accountKey, $modelUrl, $initialMessageWitness, $physicalResponseId): array {
            $current = TaskLedger::pollExecution((string) $expected['job']['id']);
            foreach (['id', 'task_id', 'generation_id', 'response_id'] as $key) {
                if (($current['turn'][$key] ?? null) !== ($expected['turn'][$key] ?? null)) {
                    throw new GatewayError('surface_fence_lost', 'Web chat ownership changed.', 409);
                }
            }
            foreach (['id', 'owner_incarnation', 'fencing_version', 'document_nonce', 'target_id'] as $key) {
                if (($current['surface'][$key] ?? null) !== ($expected['surface'][$key] ?? null)) {
                    throw new GatewayError('surface_fence_lost', 'Web chat ownership changed.', 409);
                }
            }
            $chat = self::state((string) $current['turn']['task_id']);
            if ($chat['mode'] !== 'regular') return $chat;
            $id = WebModelPage::regularConversationId($url, $modelUrl);
            if ($id === null) return $chat;
            if (!preg_match('/\A[a-f0-9]{64}\z/D', $accountKey)) throw new GatewayError('CHATGPT_CHAT_ACCOUNT_UNVERIFIED', 'The Web chat account could not be verified.', 409);
            if (!empty($chat['account_key']) && !hash_equals((string) $chat['account_key'], $accountKey)) {
                throw new GatewayError('CHATGPT_CHAT_ACCOUNT_CHANGED', 'The Web chat belongs to another account or workspace.', 409);
            }
            if (!empty($chat['conversation_id'])) {
                if (!hash_equals((string) $chat['conversation_id'], $id)) throw new GatewayError('CHATGPT_CHAT_IDENTITY_CHANGED', 'The task page opened a different Web chat.', 409);
                return $chat;
            }
            $assigned = SDB(null, 'medoo', 'sqlite')->table('gateway_task_web_chats')->where('conversation_id', $id)->find('task_id');
            if (is_array($assigned) && $assigned['task_id'] !== $chat['task_id']) throw new GatewayError('CHATGPT_CHAT_IDENTITY_CHANGED', 'The Web chat is already assigned to another task.', 409);
            $physicalResponseId ??= $current['job']['id'];
            $submission = $current['job'];
            if ($physicalResponseId !== $current['job']['id']) {
                // Receipts or an interjection can advance before the first binding observation.
                // Only the same capability or pinned steering source, task, turn and document
                // may supply that original physical submission witness.
                $submission = SDB(null, 'medoo', 'sqlite')->table('gateway_response_jobs')->where('id', $physicalResponseId)->find();
                $sameCapability = is_array($submission) && !empty($submission['mcp_binding_id'])
                    && $submission['mcp_binding_id'] === $current['job']['mcp_binding_id'];
                $steeringSource = false;
                if (!$sameCapability && is_array($submission)) {
                    // Text-only interjections also pin a physical source. Never substitute
                    // an arbitrary older request merely because it belongs to this task.
                    $steering = ResponseJob::execution((string)$current['job']['id']);
                    $steeringSource = ($steering['budget']['steering_delta']['witness_response_id'] ?? null) === $physicalResponseId
                        && SteeringContext::retained($steering, $current['surface']);
                }
                if (!is_array($submission) || (!$sameCapability && !$steeringSource)
                    || $submission['task_id'] !== $current['turn']['task_id']
                    || $submission['durable_turn_id'] !== $current['turn']['id']
                    || $submission['surface_id'] !== $current['surface']['id']) {
                    throw new GatewayError('CHATGPT_CHAT_IDENTITY_CHANGED', 'The saved Web chat identity could not be confirmed.', 409);
                }
            }
            if (!$initialMessageWitness || empty($submission['submit_intent_at'])
                || ($submission['submitted_document_nonce'] ?? null) !== $current['surface']['document_nonce']) {
                throw new GatewayError('CHATGPT_CHAT_IDENTITY_CHANGED', 'A new task must start in an empty Web chat.', 409);
            }
            $updated = SDB(null, 'medoo', 'sqlite')->table('gateway_task_web_chats')->where('task_id', $chat['task_id'])
                ->where('conversation_id', null)->update(['conversation_id' => $id, 'account_key' => $accountKey, 'updated_at' => time()]);
            if (!$updated || $updated->rowCount() !== 1) throw new GatewayError('CHATGPT_CHAT_IDENTITY_CHANGED', 'The Web chat is already assigned or its ownership changed.', 409);
            return self::state((string) $chat['task_id']);
        });
    }

    /**
     * Release only an exact saved chat after a natural same-account GET positively returned 404/410.
     * @param string $responseId Current response. @param string $incarnation Current browser owner.
     * @param int $fence Current surface fence. @param string $conversationId Confirmed missing UUID.
     * @param string $accountKey Fresh account/workspace fingerprint.
     * @return void The remote conversation is never deleted by this method.
     * @throws GatewayError If any task, page or account identity changed, or storage fails.
     */
    public static function forgetDeleted(string $responseId, string $incarnation, int $fence, string $conversationId, string $accountKey): void
    {
        Schema::transaction(static function () use ($responseId, $incarnation, $fence, $conversationId, $accountKey): void {
            $ledger = TaskLedger::pollExecution($responseId);
            if ($ledger['turn']['response_id'] !== $responseId || $ledger['surface']['owner_incarnation'] !== $incarnation
                || (int) $ledger['surface']['fencing_version'] !== $fence) {
                throw new GatewayError('surface_fence_lost', 'Web chat ownership changed.', 409);
            }
            $chat = self::state((string) $ledger['turn']['task_id']);
            if ($chat['mode'] !== 'regular' || $chat['conversation_id'] !== $conversationId
                || empty($chat['account_key']) || !hash_equals((string) $chat['account_key'], $accountKey)) {
                throw new GatewayError('CHATGPT_CHAT_IDENTITY_CHANGED', 'The saved Web chat identity could not be confirmed.', 409);
            }
            $updated = SDB(null, 'medoo', 'sqlite')->table('gateway_task_web_chats')->where('task_id', $chat['task_id'])
                ->where('conversation_id', $conversationId)->where('account_key', $accountKey)
                ->update(['conversation_id' => null, 'account_key' => null, 'updated_at' => time()]);
            if (!$updated || $updated->rowCount() !== 1) throw new GatewayError('storage_unavailable', 'The deleted Web chat identity could not be released.', 503);
        });
    }
}
