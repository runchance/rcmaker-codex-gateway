<?php
declare(strict_types=1);
namespace app\gateway\model;

use app\gateway\database\Schema;

/** SQLite-backed response round bound to the Step 05 durable task ledger. */
final class ResponseJob
{
    /** @return array Job row with decoded response/error values. */
    public static function createOrReuse(array $spec, ?string $idempotencyKey): array
    {
        Schema::initialize();
        return TaskMaintenance::withAdmission(static fn(): array => self::reserveOrReuse($spec, $idempotencyKey));
    }

    /** Preserve the existing reservation protocol inside the shared maintenance admission boundary. */
    private static function reserveOrReuse(array $spec, ?string $idempotencyKey): array
    {
        Schema::initialize();
        $joined = TaskLedger::joinedOperationalContextResponse($spec, $idempotencyKey);
        if ($joined !== null) return self::get($joined);
        if ($idempotencyKey !== null) {
            $existing = SDB(null, 'medoo', 'sqlite')->table('gateway_response_jobs')->where('idempotency_key', $idempotencyKey)->find();
            if (is_array($existing)) {
                if (!hash_equals((string) $existing['request_hash'], (string) $spec['request_hash'])) {
                    throw new GatewayError('idempotency_conflict', '同一 Idempotency-Key 已用于不同请求。', 409);
                }
                return self::decode($existing);
            }
        }

        $requestIdentity = !empty($spec['turn_id']) || !empty($spec['thread_id']) || ($spec['client_id'] ?? '') !== 'responses'
            ? hash('sha256', $spec['task_key'] . '|' . ($spec['turn_id'] ?? '') . '|' . $spec['request_hash']) : null;
        if ($requestIdentity !== null) {
            $existing = SDB(null, 'medoo', 'sqlite')->table('gateway_response_jobs')->where('request_identity', $requestIdentity)->find();
            if (is_array($existing)) return self::decode($existing);
        }
        // Codex may open a new temporary compaction thread for the same read-only
        // transcript. Join its pending producer or replay its terminal without a new message.
        if ($idempotencyKey === null) {
            $checkpointReplay = ContextCompaction::readonlyReplay($spec);
            if ($checkpointReplay !== null) return self::decode($checkpointReplay);
        }
        // A failed stream may be replayed with the original native turn ID and a
        // reframed request after compaction. Resolve its verified checkpoint lineage
        // before browser admission; replaying a failure requires no live browser.
        $failedReplay = self::failedTurnReplayUnlocked($spec);
        if ($failedReplay !== null) return self::decode($failedReplay);
        ModelCooldown::assertReady();
        $budget = $spec['budget'] ?? ContextBudget::snapshot($spec['model'], $spec['reasoning_effort'] ?? null);
        $spec['budget'] = $budget;
        $isToolContinuation = (array) ($spec['tool_outputs'] ?? []) !== [];
        $activeSteeringCandidate = !$isToolContinuation && (self::potentialActiveUserSteering($spec)
            || ContextHistory::activeToolBoundarySteeringUnlocked($spec) !== null
            || ContextHistory::activeMailboxContextUpdateUnlocked($spec) !== null);
        $browser = BrowserState::state();
        // A tool-result round resumes the already-submitted browser turn; it does not
        // enqueue another browser command. The durable binding/surface checks below
        // validate that continuation. A brief worker event-loop stall must not reject
        // the host receipt before the browser has a chance to resume polling it.
        if (!$isToolContinuation && !$activeSteeringCandidate
            && ($browser['status'] !== 'ready' || !BrowserState::isWorkerReady($browser))) {
            throw new GatewayError('browser_not_ready', '受控浏览器尚未就绪。', 503);
        }
        if (!$isToolContinuation) {
            $page = self::pageSnapshot($browser);
            if (!$page['fresh'] && !$activeSteeringCandidate) {
                throw new GatewayError('chatgpt_surface_not_ready', 'ChatGPT 页面状态尚未刷新，请稍后重试。', 503);
            }
            if ($page['fresh'] && $page['login_required']) {
                throw new GatewayError('authentication_required', 'ChatGPT 网页需要重新登录。', 401);
            }
            if ((!$page['on_chatgpt'] || !$page['editor_ready']) && !$activeSteeringCandidate) {
                throw new GatewayError('chatgpt_surface_not_ready', 'ChatGPT 页面尚未进入可发送状态；请在浏览器工作区完成登录并等待编辑器出现。', 503);
            }
            if (!$activeSteeringCandidate) ModelCatalog::awaitCurrentObservation((string) $spec['model']);
        }
        $responseId = 'resp_' . bin2hex(random_bytes(12));
        $itemId = 'msg_' . bin2hex(random_bytes(12));
        $created = time();
        $roundInfo = null;
        $reservedId = Schema::transaction(function () use ($spec, $idempotencyKey, $responseId, $itemId,
            $created, $requestIdentity, $budget, &$roundInfo): string {
            $db = SDB(null, 'medoo', 'sqlite');
            $joined = TaskLedger::joinedOperationalContextResponse($spec, $idempotencyKey);
            if ($joined !== null) return $joined;
            if ($idempotencyKey !== null) {
                $existing = $db->table('gateway_response_jobs')->where('idempotency_key', $idempotencyKey)->find();
                if (is_array($existing)) {
                    if (!hash_equals((string) $existing['request_hash'], (string) $spec['request_hash'])) {
                        throw new GatewayError('idempotency_conflict', '同一 Idempotency-Key 已用于不同请求。', 409);
                    }
                    return (string) $existing['id'];
                }
            }
            if ($requestIdentity !== null) {
                $existing = $db->table('gateway_response_jobs')->where('request_identity', $requestIdentity)->find();
                if (is_array($existing)) return (string) $existing['id'];
            }
            $spec = ContextHistory::demoteHistoricalToolOutputsUnlocked($spec);
            // The early lookup avoids browser admission for a replay; repeat it under the
            // write transaction so concurrent temporary threads cannot reserve two producers.
            if ($idempotencyKey === null) {
                $checkpointReplay = ContextCompaction::readonlyReplay($spec, true);
                if ($checkpointReplay !== null) return (string)$checkpointReplay['id'];
            }
            // Serialize with BrowserState::enqueue(reset_profile): a request that passed the
            // controller's early check cannot reserve a new Web turn after clearing begins.
            BrowserState::assertNotRestarting();
            if (BrowserState::profileResetPending()) {
                throw new GatewayError('browser_profile_reset_pending', '浏览器登录资料正在清空，请稍后重试。', 503);
            }
            $conversation = TaskLedger::routeConversationUnlocked($spec);
            $spec = $conversation['spec'];
            if (!$conversation['prepared']) $spec = ContextHistory::prepareUnlocked($spec);
            // An exact native host receipt may settle before the browser's DOM acceptance poll.
            // Prove that receipt and acknowledge its original submission before reserving the next
            // round; later validation failures roll back both changes in this same transaction.
            ToolBroker::acknowledgeHostReceiptUnlocked($spec);
            $spec = SteeringContext::deferToolsUnlocked($spec);
            if (!empty($spec['operational_context_source_response_id'])) {
                $source = $db->table('gateway_response_jobs')->where('id', (string) $spec['operational_context_source_response_id'])->find();
                TaskLedger::queueOperationalContextUnlocked($source, $spec['operational_context_updates'],
                    (string) $spec['request_hash'], $idempotencyKey, !empty($spec['mailbox_context_join']),
                    !empty($spec['steering_context_join']));
                return (string) $source['id'];
            }
            // Steering policy is selected once per request, never inherited from an older round.
            unset($spec['budget']['steering_delta']);
            if (($spec['operation_kind'] ?? 'response') === 'response'
                && (array) ($spec['steering_messages'] ?? []) !== []
                && ((array) ($spec['tool_outputs'] ?? []) !== [] || !empty($spec['steering_restart']))) {
                // A newer native user item needs an actual new Web submission. Preserve
                // its full history first; the configured policy below can derive an
                // owned-page suffix after all accepted tool receipts have settled.
                $spec['steering_restart'] = true;
                $fresh = json_decode((string) ($spec['full_context_json'] ?? $spec['context_json']), true, 128, JSON_THROW_ON_ERROR);
                unset($fresh['continuation']);
                $spec['context_json'] = $spec['full_context_json'] = ContextHistory::canonical($fresh);
                $spec['transport_context_json'] = $spec['context_json'];
                $spec['rolling_source_response_id'] = null;
                $spec['continuation_source_response_id'] = null;
                $spec['prompt'] = ContextHistory::plainPrompt($fresh);
            }
            $spec = ContextCompaction::resumeCommittedTurnUnlocked($spec);
            // Full native histories can initially contain historical tool outputs.
            // Their normalization above makes the post-checkpoint lineage provable
            // here. Observe its durable failure instead of reserving another send.
            $failedReplay = self::failedTurnReplayUnlocked($spec);
            if ($failedReplay !== null) return (string) $failedReplay['id'];
            // Historical tool outputs are normalized above. Only an actual receipt for this
            // task's pending invocation may continue without rediscovering the account catalog.
            if (($spec['tool_outputs'] ?? []) === [] && empty($spec['steering_restart'])) {
                $route = ModelCatalog::resolveForOperation($spec['model'], $spec['reasoning_effort'] ?? null,
                    (string) ($spec['operation_kind'] ?? 'response'));
                $outputControl = (array)($spec['budget']['output_control'] ?? []);
                $spec['budget'] = ContextBudget::snapshot($spec['model'], $route['effort'], $route['capabilities']);
                if ($outputControl !== []) $spec['budget']['output_control'] = $outputControl;
                $spec['budget'] = ContextRecovery::applyObservedMessageLimitUnlocked($spec, $spec['budget']);
            }
            $budget = $spec['budget'] ?? $budget;
            $spec = LunaCheckpoint::applyUnlocked($spec);
            if (($spec['operation_kind'] ?? 'response') === 'response') {
                // A foreign/forked conversation can be larger than this model's window before
                // it has any local epoch. Select its recent history before the admission check;
                // later rounds must inherit the same selection instead of importing it again.
                $contextTaskId = 'task_' . substr((string) $spec['task_key'], 0, 24);
                $historyJob = null;
                if (!empty($spec['previous_response_id'])) {
                    $historyJob = $db->table('gateway_response_jobs')->where('id', (string) $spec['previous_response_id'])
                        ->where('task_id', $contextTaskId)->find();
                }
                if (!is_array($historyJob)) {
                    $historyJob = $db->table('gateway_response_jobs')->where('task_id', $contextTaskId)
                        ->order([['rowid', 'DESC']])->limit(1)->find();
                }
                $noGatewayHistory = !is_array($historyJob)
                    && !is_array($db->table('gateway_task_epochs')->where('task_id', $contextTaskId)->limit(1)->find());
                if ($noGatewayHistory && LunaCheckpoint::enabled($spec)) {
                    // Count the same private footer that this first physical submission
                    // will use; generating it after selection would enlarge the prompt.
                    $spec['checkpoint_marker'] = LunaCheckpoint::marker($spec);
                }
                $spec = ContextWindow::exposeMultipartSource($spec);
                $spec = ContextWindow::selectImportedHistory($spec, $noGatewayHistory, is_array($historyJob) ? $historyJob : null);
                if (!empty($spec['steering_restart']) && isset($spec['imported_budget_context_json'])) {
                    // Steering starts a fresh physical page. Carry the verified selected
                    // history plus every new instruction/receipt, not its oversized archive.
                    // Ordinary receipt rounds keep their already-bound selected transport.
                    $spec['context_json'] = $spec['transport_context_json'] = $spec['imported_budget_context_json'];
                    $spec['prompt'] = ContextHistory::plainPrompt(json_decode($spec['context_json'], true, 128, JSON_THROW_ON_ERROR));
                    $spec['continuation_source_response_id'] = null;
                    // This is a new physical page carrying new instructions, not a rebuild
                    // of the import's original staged transaction.
                    unset($spec['budget']['imported_transport_transaction_id'],
                        $spec['budget']['imported_transport_context_sha256']);
                }
                $budget = $spec['budget'];
            }
            if ((!empty($spec['steering_restart']) || !empty($spec['stopped_source_response_id']))
                && !($spec['steering_full_context'] ?? GatewaySettings::internal()['steering_full_context'])) {
                $spec = SteeringContext::prepareUnlocked($spec);
                $budget = $spec['budget'];
            }
            $logicalContext = (string) ($spec['full_context_json'] ?? $spec['context_json']);
            $physical = json_decode((string) $spec['context_json'], true, 128, JSON_THROW_ON_ERROR);
            $exactPrefix = ($physical['continuation']['mode'] ?? null) === 'exact_prefix';
            $transportContext = (string) ($spec['transport_context_json']
                ?? ($exactPrefix ? $spec['context_json'] : $logicalContext));
            $spec['transport_context_json'] = $transportContext;
            $budgetContext = (string) ($spec['imported_budget_context_json']
                ?? (!empty($spec['rolling_source_response_id']) ? $transportContext : $logicalContext));
            // context_json is the context carried by this physical browser message after exact-prefix
            // continuation / rolling compaction. full_context_json remains the durable recovery/budget source.
            // Full logical context still owns the model budget even when exact-prefix continuation
            // sends only a suffix. File-backed skill text and retained images must therefore keep
            // consuming context tokens after their bytes stop being re-uploaded on the same page.
            // An explicitly selected import counts its retained history, while the complete
            // incoming source remains stored separately as read-only evidence.
            $budgetAttachments = AttachmentStore::selectForContext((array) ($spec['attachments'] ?? []), $budgetContext, false);
            $attachmentTokens = AttachmentStore::tokenReserve($budgetAttachments, $budget);
            $inputTokens = ContextBudget::inputTokens($budgetContext, $budget, $attachmentTokens);
            // A genuine result continues the already-submitted Web generation. It must settle
            // before native auto-compaction can take ownership; it never uploads this full history.
            // prepareRoundUnlocked still proves the exact pending call/binding and rejects forged receipts.
            $receiptContinuation = (array) ($spec['tool_outputs'] ?? []) !== [] && empty($spec['steering_restart']);
            if (($spec['operation_kind'] ?? 'response') === 'response' && !$receiptContinuation) {
                ContextBudget::validate($budgetContext, $budget, $attachmentTokens);
            }
            if (($spec['operation_kind'] ?? 'response') !== 'response') {
                $reuse = ContextCompaction::reserveUnlocked($spec, $responseId);
                if ($reuse !== null) return $reuse;
                // A complete native checkpoint can exceed the ordinary one-message
                // window that triggered it. The enlarged budget belongs only to this
                // checkpoint's browser transport and is pinned with the job below.
                // This is the next physical checkpoint upload, not every image resident in the
                // logical source above. Keep input usage and ordinary validation unchanged.
                $compactAttachmentTokens = AttachmentStore::tokenReserve(
                    AttachmentStore::selectForContext((array) ($spec['attachments'] ?? []), $transportContext), $budget);
                $spec['budget'] = ContextBudget::forCompactionPrompt(
                    $budget, (string) $spec['prompt'], $compactAttachmentTokens,
                    !empty($spec['verified_compaction_source']) ? null : $transportContext);
            }
            if (empty($spec['steering_delta'])) $spec = ContextWindow::prepare($spec, $attachmentTokens);
            $spec = ContextCompaction::preparePageResumeUnlocked($spec);
            $budget = $spec['budget'];
            $transportContext = (string)($spec['transport_context_json'] ?? $transportContext);
            $reservation = TaskLedger::reserveTurnUnlocked($spec, $responseId);
            if ($reservation['reused']) return (string) $reservation['response_id'];
            $spec = ContextHistory::bindReservedSurfaceUnlocked($spec, $reservation);
            // Rebuilding a delta on a new page also needs the original retained attachments.
            $attachmentContext = (string) ($spec['context_json'] ?? $transportContext);
            $attachments = AttachmentStore::selectForContext((array) ($spec['attachments'] ?? []), $attachmentContext);
            $prior = null;
            if (!empty($spec['previous_response_id'])) {
                $prior = $db->table('gateway_response_jobs')->where('id', (string) $spec['previous_response_id'])->find();
            }
            // All logical tool rounds belong to one physical submission and share its delimiter.
            $marker = !empty($spec['tool_outputs']) && empty($spec['steering_restart']) && is_array($prior)
                ? ($prior['checkpoint_marker'] ?? null) : ($spec['checkpoint_marker'] ?? LunaCheckpoint::marker($spec));
            $spec['checkpoint_marker'] = $marker;
            $bridge = ToolBroker::prepareRoundUnlocked($spec, $reservation, $responseId);
            $prompt = (string) $spec['prompt'];
            if ($bridge['control_prompt'] !== '') {
                $prompt = $bridge['control_prompt'];
            } elseif (is_string($marker) && $marker !== '' && !$bridge['is_continuation']) {
                $prompt = LunaCheckpoint::instructions($marker) . "\n\n" . $prompt;
            }
            if ($bridge['control_prompt'] === '' && !$bridge['is_continuation']
                && ($spec['operation_kind'] ?? 'response') === 'response') {
                $outputControl = ToolBroker::outputControlPrompt((array)($budget['output_control'] ?? []));
                if ($outputControl !== '') $prompt = $outputControl . "\n" . $prompt;
            }
            $prompt = WebContract::withSteeringNotice($prompt,
                ($spec['operation_kind'] ?? 'response') === 'response'
                    && (!empty($spec['steering_restart']) || !empty($spec['stopped_source_response_id'])
                        || !empty($spec['postcompact_user_input'])),
                !empty($spec['stopped_source_response_id']) ? 'stopped_followup' : 'interjection');
            if ($bridge['is_continuation'] && ($spec['operation_kind'] ?? 'response') === 'response' && is_array($prior)) {
                // Receipts do not submit another Web message. Preserve the physical
                // request's scope in durable round metadata for any later page recovery.
                $prompt = WebContract::withSteeringNotice($prompt, WebContract::hasSteeringNotice((string) $prior['prompt']),
                    WebContract::steeringScope((string) $prior['prompt']));
            }
            $roundInfo = $bridge;
            if (!$db->table('gateway_response_jobs')->add([
                'id' => $responseId,
                'item_id' => $itemId,
                'idempotency_key' => $idempotencyKey,
                'request_hash' => $spec['request_hash'],
                'model' => $spec['model'],
                'prompt' => $prompt,
                'context_json' => $spec['full_context_json'] ?? $spec['context_json'],
                'transport_context_json' => $transportContext,
                'rolling_source_response_id' => $spec['rolling_source_response_id'] ?? null,
                'checkpoint_marker' => $marker,
                'request_identity' => $requestIdentity,
                'budget_json' => json_encode($budget, JSON_THROW_ON_ERROR),
                'input_tokens' => $inputTokens,
                'operation_kind' => $spec['operation_kind'] ?? 'response',
                'compact_id' => $spec['compact_id'] ?? null,
                'repair_count' => $spec['repair_count'] ?? 0,
                'integration_id' => $spec['integration_id'] ?? null,
                'browser_owner_key' => $spec['browser_owner_key'] ?? null,
                'browser_lease_key' => $spec['browser_lease_key'] ?? null,
                'thread_id' => $spec['thread_id'] ?? null,
                'turn_id' => $spec['turn_id'] ?? null,
                'reasoning_effort' => $spec['reasoning_effort'] ?? null,
                'compaction_epoch' => $spec['compaction_epoch'] ?? 'base',
                'task_id' => $reservation['task_id'],
                'epoch_id' => $reservation['epoch_id'],
                'durable_turn_id' => $reservation['turn_id'],
                'surface_id' => $reservation['surface_id'],
                'generation_id' => $reservation['generation_id'],
                'round_no' => $reservation['round_no'],
                'previous_response_id' => $spec['previous_response_id'] ?? null,
                'continuation_source_response_id' => $spec['continuation_source_response_id'] ?? null,
                'submitted_document_nonce' => $bridge['is_continuation'] && is_array($prior)
                    ? ($prior['submitted_document_nonce'] ?? null) : null,
                'command_id' => $bridge['is_continuation'] && is_array($prior) ? $prior['command_id'] : null,
                'mcp_binding_id' => $bridge['binding_id'],
                'tool_environment_hash' => $spec['tool_environment_hash'] ?? null,
                'tool_environment_json' => $spec['tool_environment_json'] ?? null,
                'round_kind' => 'text',
                'status' => 'queued',
                'created_at' => $created,
            ])) {
                throw new GatewayError('storage_unavailable', 'Responses 请求无法保存。', 503);
            }
            AttachmentStore::bindResponseUnlocked($responseId, $attachments);
            TaskLedger::attachOperationalContextUnlocked($reservation, $responseId,
                (array) ($spec['operational_context_event_ids'] ?? []));
            if (!empty($spec['mailbox_context_restart'])) {
                TaskLedger::bindMailboxContextRoundUnlocked($reservation, $responseId,
                    (string) $spec['request_hash'], $idempotencyKey);
            }
            if ((!empty($spec['steering_restart']) || !empty($spec['mailbox_context_restart'])) && is_array($prior)
                && in_array((string) ($prior['status'] ?? ''), ['queued', 'running'], true)) {
                self::failSupersededUnlocked((string) $prior['id'], !empty($spec['mailbox_context_restart'])
                    ? '网页回合已接收新的代理消息。' : null);
            }
            return $responseId;
        });
        if ($reservedId !== $responseId) return self::get($reservedId);
        if (is_array($roundInfo) && !empty($roundInfo['is_continuation'])) {
            return self::get($responseId);
        }

        try {
            $commandId = self::enqueueBrowserTurn($responseId);
            $updated = SDB(null, 'medoo', 'sqlite')->table('gateway_response_jobs')->where('id', $responseId)->where('status', 'queued')->update(['command_id' => $commandId]);
            if (!$updated || $updated->rowCount() !== 1) {
                throw new GatewayError('storage_unavailable', 'Responses 请求无法绑定浏览器命令。', 503);
            }
        } catch (\Throwable $error) {
            self::fail($responseId, 'browser_queue_failed', $error instanceof GatewayError ? $error->getMessage() : '浏览器任务无法入队。');
            if ($error instanceof GatewayError) throw $error;
            throw new GatewayError('browser_queue_failed', '浏览器任务无法入队。', 503);
        }
        return self::get($responseId);
    }

    /**
     * Observe the current failed response for an owned native turn or its proven checkpoint alias.
     * The request hash may change on a Codex reconnect; the original native turn ID does not.
     * This read-only lookup cannot reserve a browser send or authorize another host invocation.
     * @param array $spec Parsed or transaction-normalized request; routing is applied to a copy.
     * @return array|null Persisted failed job, excluding an inert precommit failure that can be retried.
     * @throws GatewayError If checkpoint lineage or durable state cannot be read.
     */
    private static function failedTurnReplayUnlocked(array $spec): ?array
    {
        if (empty($spec['turn_id'])) return null;
        $db = SDB(null, 'medoo', 'sqlite');
        $baseTaskId = 'task_' . substr((string) $spec['task_key'], 0, 24);
        $link = $db->table('gateway_task_conversations')->where('base_task_id', $baseTaskId)->find();
        $taskId = is_array($link) ? (string) $link['current_task_id'] : $baseTaskId;
        $task = $db->table('gateway_tasks')->where('id', $taskId)->find();
        $ownerKey = is_array($link) ? (string) $link['current_owner_key'] : (string) $spec['browser_owner_key'];
        if (!is_array($task) || $task['client_id'] !== ($spec['client_id'] ?? 'responses')
            || $task['thread_id'] !== ($spec['thread_id'] ?? null)
            || $task['branch_id'] !== ($spec['branch_id'] ?? 'main')
            || $task['owner_key'] !== $ownerKey) return null;
        if (is_array($link)) {
            $spec['task_key'] = (string) $link['current_task_key'];
            $spec['browser_owner_key'] = $ownerKey;
        }
        $spec = ContextCompaction::resumeCommittedTurnUnlocked($spec);
        $turn = $db->table('gateway_task_turns')->where('task_id', $taskId)
            ->where('client_turn_id', (string) $spec['turn_id'])->find();
        if (!is_array($turn) || empty($turn['response_id'])) return null;
        $failed = $db->table('gateway_response_jobs')->where('id', (string) $turn['response_id'])
            ->where('task_id', $taskId)->where('turn_id', (string) $spec['turn_id'])
            ->where('durable_turn_id', (string) $turn['id'])->where('status', 'failed')->find();
        if (!is_array($failed) || ($task['status'] !== 'needs_attention'
            && ContextRecovery::canRetryFailedBeforeCommitUnlocked((string) $failed['id']))) return null;
        return $failed;
    }

    /**
     * Let the worker publish a real heartbeat after a reservation releases SQLite's writer.
     * Only the pre-insert liveness rejection is retryable: it has enqueued no command. Each
     * attempt retains BrowserState's freshness/ownership guards, and waits outside a transaction.
     * Storage errors or any other failure remain non-retryable here.
     */
    private static function enqueueBrowserTurn(string $responseId, bool $recover = false): int
    {
        $payload = ['response_id' => $responseId];
        if ($recover) $payload['recover'] = true;
        $deadline = microtime(true) + 5.0;
        do {
            try {
                return BrowserState::enqueue('chatgpt_text', $payload, false);
            } catch (GatewayError $error) {
                if ($error->errorCode !== 'BROWSER_WORKER_UNAVAILABLE' || microtime(true) >= $deadline) throw $error;
            }
            usleep(100000);
        } while (true);
    }

    /** Reconcile interrupted durable turns without replaying anything that may already have reached the page. */
    public static function recoverInterrupted(): array
    {
        $ledger = TaskLedger::recoverInterrupted();
        $safeQueued = 0;
        foreach ($ledger['safe_queued'] as $responseId) {
            $row = SDB(null, 'medoo', 'sqlite')->table('gateway_response_jobs')->where('id', $responseId)->find();
            if (!is_array($row) || !in_array((string) $row['status'], ['queued', 'running'], true)) continue;
            $commandId = (int) ($row['command_id'] ?? 0);
            $command = $commandId > 0
                ? SDB(null, 'medoo', 'sqlite')->table('gateway_browser_commands')->where('id', $commandId)->find() : null;
            if (!is_array($command) || (string) ($command['status'] ?? '') !== 'queued') {
                $commandId = self::enqueueBrowserTurn($responseId);
                $updated = SDB(null, 'medoo', 'sqlite')->table('gateway_response_jobs')->where('id', $responseId)->where('status', 'IN', ['queued', 'running'])->update(['command_id' => $commandId]);
                if (!$updated || $updated->rowCount() !== 1) throw new GatewayError('storage_unavailable', '未发送任务无法重新排队。', 503);
            }
            $safeQueued++;
        }
        foreach ($ledger['unknown'] as $responseId) {
            self::fail($responseId, 'RECOVERY_OUTCOME_UNKNOWN', '应用重启后无法确认上一网页提交结果；为避免重复发送，本次不会自动重试。');
        }
        foreach ($ledger['resume'] as $responseId) {
            $row = SDB(null, 'medoo', 'sqlite')->table('gateway_response_jobs')->where('id', $responseId)->find();
            if (!is_array($row) || (string) $row['status'] !== 'running') continue;
            $commandId = self::enqueueBrowserTurn($responseId, true);
            $updated = SDB(null, 'medoo', 'sqlite')->table('gateway_response_jobs')->where('id', $responseId)->where('status', 'running')->update(['command_id' => $commandId]);
            if (!$updated || $updated->rowCount() !== 1) throw new GatewayError('storage_unavailable', '恢复观察任务无法重新排队。', 503);
        }

        // Migration compatibility for any pre-v5 active row that has no durable task binding.
        $rows = SDB(null, 'medoo', 'sqlite')->table('gateway_response_jobs')->where('status', 'IN', ['queued', 'running'])->select();
        if ($rows === false) throw new GatewayError('storage_unavailable', 'Responses 恢复状态暂不可读取。', 503);
        $browser = BrowserState::state();
        $failed = 0;
        foreach ($rows as $row) {
            if (!empty($row['task_id'])) continue;
            $responseId = (string) ($row['id'] ?? '');
            if ($responseId === '') continue;
            $unsafe = (string) ($row['status'] ?? '') === 'running';
            if (!$unsafe && (string) ($row['status'] ?? '') === 'queued') {
                $commandId = (int) ($row['command_id'] ?? 0);
                $command = $commandId > 0
                    ? SDB(null, 'medoo', 'sqlite')->table('gateway_browser_commands')->where('id', $commandId)->find()
                    : null;
                $stillBound = is_array($command)
                    && ($command['status'] ?? '') === 'queued'
                    && ($browser['lease_mode'] ?? '') === 'automation'
                    && (int) ($command['surface_generation'] ?? -1) === (int) ($browser['surface_generation'] ?? -2)
                    && (string) ($command['document_nonce'] ?? '') === (string) ($browser['document_nonce'] ?? '');
                if ($stillBound) {
                    $safeQueued++;
                    continue;
                }
                $unsafe = true;
            }
            if (!$unsafe) continue;
            self::fail($responseId, 'CHATGPT_WORKER_RESTARTED', '浏览器控制进程重启，上一网页请求的完成状态无法确认；为避免重复发送，本次不会自动重试。');
            $failed++;
        }
        return [
            'failed' => $failed + count($ledger['unknown']),
            'safe_queued' => $safeQueued,
            'resume' => $ledger['resume'],
            'unknown' => $ledger['unknown'],
        ];
    }

    public static function get(string $responseId): array
    {
        $row = SDB(null, 'medoo', 'sqlite')->table('gateway_response_jobs')->where('id', $responseId)->find();
        if (!is_array($row)) throw new GatewayError('response_not_found', 'Responses 请求不存在。', 404);
        return self::decode($row);
    }

    /** Internal browser-worker view. Prompt is intentionally excluded from public API snapshots. */
    public static function execution(string $responseId): array
    {
        $row = self::row($responseId);
        return [
            'id' => (string) $row['id'],
            'item_id' => (string) $row['item_id'],
            'model' => (string) $row['model'],
            'prompt' => (string) $row['prompt'],
            'request_hash' => (string) $row['request_hash'],
            'continuation_source_response_id' => $row['continuation_source_response_id'] ?? null,
            'submitted_document_nonce' => $row['submitted_document_nonce'] ?? null,
            'budget' => json_decode((string) ($row['budget_json'] ?? '{}'), true) ?: [],
            'operation_kind' => $row['operation_kind'] ?? 'response',
            'browser_owner_key' => $row['browser_owner_key'] === null ? null : (string) $row['browser_owner_key'],
            'browser_lease_key' => $row['browser_lease_key'] === null ? null : (string) $row['browser_lease_key'],
            'thread_id' => $row['thread_id'] === null ? null : (string) $row['thread_id'],
            'turn_id' => $row['turn_id'] === null ? null : (string) $row['turn_id'],
            'reasoning_effort' => $row['reasoning_effort'] === null ? null : (string) $row['reasoning_effort'],
            'compaction_epoch' => $row['compaction_epoch'] === null ? 'base' : (string) $row['compaction_epoch'],
            'task_id' => $row['task_id'] === null ? null : (string) $row['task_id'],
            'epoch_id' => $row['epoch_id'] === null ? null : (string) $row['epoch_id'],
            'durable_turn_id' => $row['durable_turn_id'] === null ? null : (string) $row['durable_turn_id'],
            'surface_id' => $row['surface_id'] === null ? null : (string) $row['surface_id'],
            'generation_id' => $row['generation_id'] === null ? null : (string) $row['generation_id'],
            'submit_intent_at' => $row['submit_intent_at'] === null ? null : (int) $row['submit_intent_at'],
            'accepted_at' => $row['accepted_at'] === null ? null : (int) $row['accepted_at'],
            'output_text' => (string) ($row['output_text'] ?? ''),
            'checkpoint_marker' => $row['checkpoint_marker'] ?? null,
            'baseline_users' => self::decodeList($row['baseline_users_json'] ?? null),
            'baseline_assistants' => self::decodeList($row['baseline_assistants_json'] ?? null),
            'status' => (string) $row['status'],
            'command_id' => $row['command_id'] === null ? null : (int) $row['command_id'],
            'round_kind' => (string) ($row['round_kind'] ?? 'text'),
            'mcp_binding_id' => $row['mcp_binding_id'] === null ? null : (string) $row['mcp_binding_id'],
            'browser_prefix' => (string) ($row['browser_prefix'] ?? ''),
            'previous_response_id' => $row['previous_response_id'] === null ? null : (string) $row['previous_response_id'],
            'transport_transaction_id' => $row['transport_transaction_id'] === null ? null : (string) $row['transport_transaction_id'],
            'transport_parts_total' => (int) ($row['transport_parts_total'] ?? 0),
            'transport_stage_sent' => (int) ($row['transport_stage_sent'] ?? 0),
            'transport_stage_acked' => (int) ($row['transport_stage_acked'] ?? 0),
            'transport_stage_effort' => $row['transport_stage_effort'] === null ? null : (string) $row['transport_stage_effort'],
            'transport_final_effort' => $row['transport_final_effort'] === null ? null : (string) $row['transport_final_effort'],
            'transport_budget' => $row['transport_budget_json'] === null ? null
                : json_decode((string) $row['transport_budget_json'], true, 32, JSON_THROW_ON_ERROR),
            'attachments' => AttachmentStore::forResponse($responseId),
        ];
    }

    /**
     * Read fresh execution metadata without allocating prompts, transcripts, output or attachments.
     * Browser/HTTP polls must still read this state on every observation; full execution is
     * loaded separately when adopting a new round or settling its payload.
     * @param string $responseId Exact durable Responses identifier.
     * @return array<string,mixed> Raw ledger fields, including ownership and lifecycle identity.
     * @throws GatewayError If the response is missing or its state cannot be read.
     */
    public static function pollState(string $responseId): array
    {
        $row = SDB(null, 'medoo', 'sqlite')->table('gateway_response_jobs')->where('id', $responseId)->find(
            'id,status,operation_kind,round_kind,previous_response_id,checkpoint_marker,'
            . 'durable_turn_id,surface_id,generation_id,task_id,epoch_id,mcp_binding_id,command_id');
        if (!is_array($row)) throw new GatewayError('response_not_found', 'Responses 请求不存在。', 404);
        return $row;
    }

    /** @return array<int,array> */
    public static function eventsAfter(string $responseId, int $sequence): array
    {
        $rows = SDB(null, 'medoo', 'sqlite')->table('gateway_response_events')
            ->where('response_id', $responseId)->where('sequence_number', '>', $sequence)
            ->order([['sequence_number', 'ASC']])->limit(128)->select();
        if ($rows === false) throw new GatewayError('storage_unavailable', 'Responses 事件暂不可读取。', 503);
        $events = [];
        foreach ($rows as $row) {
            $event = json_decode((string) $row['event_json'], true, 64, JSON_THROW_ON_ERROR);
            // Older versions persisted a physical Web upload failure under Codex's
            // logical overflow code. Repair only that projection on replay, using the
            // original failure ledger; neither the stored event nor history is edited.
            if (($event['type'] ?? null) === 'response.failed'
                && ($event['response']['error']['code'] ?? null) === 'context_length_exceeded') {
                $failed = SDB(null, 'medoo', 'sqlite')->table('gateway_response_jobs')
                    ->where('id', $responseId)->find('error_json');
                $original = is_array($failed) ? json_decode((string) ($failed['error_json'] ?? ''), true) : null;
                if (in_array($original['error']['code'] ?? null, ['CHATGPT_MESSAGE_TOO_LARGE', 'CHATGPT_CONTEXT_TOO_LARGE'], true)) {
                    $event['response']['error'] = ResponsesProtocol::clientFailure($original)['body']['error'];
                }
            }
            if (is_array($event)) $events[] = $event;
        }
        return $events;
    }

    /** Mark browser execution begun and publish response.created/in_progress exactly once. */
    public static function start(string $responseId): array
    {
        return Schema::transaction(function () use ($responseId): array {
            $job = self::row($responseId);
            if ($job['status'] !== 'queued') return self::decode($job);
            $updated = SDB(null, 'medoo', 'sqlite')->table('gateway_response_jobs')->where('id', $responseId)->where('status', 'queued')->update([
                'status' => 'running', 'started_at' => time(),
            ]);
            if (!$updated || $updated->rowCount() !== 1) throw new GatewayError('storage_unavailable', 'Responses 状态无法启动。', 503);
            $job['status'] = 'running';
            $job['started_at'] = time();
            self::appendEventsUnlocked($job, ResponsesProtocol::initialEvents($responseId));
            $sequence = SDB(null, 'medoo', 'sqlite')->table('gateway_response_jobs')->where('id', $responseId)->where('status', 'running')->update([
                'sequence_next' => (int) $job['sequence_next'],
            ]);
            if (!$sequence || $sequence->rowCount() !== 1) throw new GatewayError('storage_unavailable', 'Responses 初始事件无法提交。', 503);
            return self::decode($job);
        });
    }

    /**
     * Pin the physically verified picker mode for the next audit record, without changing task provenance.
     * @param string $responseId Owned running response (logical checkpoint id for retained handoff).
     * @param array $mode Family/effort/purpose proved by the existing browser selection flow.
     * @return void @throws GatewayError If the response ended or the selection cannot be persisted.
     */
    public static function recordTransportSelection(string $responseId, array $mode): void
    {
        $selection = ['model'=>\support\browser\ChatGptTransport::physicalModel($mode),
            'effort'=>(string) $mode['effort'], 'purpose'=>(string) ($mode['purpose'] ?? 'task')];
        Schema::transaction(static function () use ($responseId, $selection): void {
            $row = self::row($responseId);
            if ((string) $row['status'] !== 'running') throw new GatewayError('response_state_invalid',
                '只能记录运行中回合的实际发送模型。', 409);
            $updated = SDB(null, 'medoo', 'sqlite')->table('gateway_response_jobs')->where('id', $responseId)
                ->where('status', 'running')->update(['physical_selection_json'=>json_encode($selection, JSON_THROW_ON_ERROR)]);
            if ($updated === false) throw new GatewayError('storage_unavailable', '实际发送模型无法保存。', 503);
        });
    }

    /**
     * Persist the first multipart identity and its bounded preparation deadline atomically.
     * Recovery of the same identity preserves its deadline and never renews the final summary.
     * @param string $responseId Running response. @param string $transactionId Stable transport identity.
     * @param int $partsTotal Two to six physical messages, including the final commit.
     * @param string $stageEffort Inert stage effort. @param string $finalEffort Final generation effort.
     * @param ?array $transportBudget Checkpoint composer envelope, separate from its logical source budget.
     * @return void @throws GatewayError If the transaction or generation no longer matches.
     */
    public static function configureMultipart(string $responseId, string $transactionId, int $partsTotal, string $stageEffort, string $finalEffort, ?array $transportBudget = null): void
    {
        if (!preg_match('/\Actx_[a-f0-9]{32}\z/', $transactionId) || $partsTotal < 2 || $partsTotal > 6) {
            throw new GatewayError('multipart_state_invalid', 'Multipart 事务参数无效。', 500);
        }
        Schema::transaction(function () use ($responseId, $transactionId, $partsTotal, $stageEffort, $finalEffort, $transportBudget): void {
            $row = self::row($responseId);
            if ((string) $row['status'] !== 'running') throw new GatewayError('response_state_invalid', 'Multipart 事务只能绑定到运行中的 Responses。', 409);
            $compact = in_array((string) ($row['operation_kind'] ?? 'response'), ['compact_v1', 'compact_v2'], true);
            $transportJson = $compact && $transportBudget !== null ? json_encode($transportBudget, JSON_THROW_ON_ERROR) : null;
            if ($row['transport_transaction_id'] !== null) {
                // A legacy checkpoint may have staged history on Medium but not yet sent its
                // Pro commit. Adopt High only before that commit's durable send intent, while
                // keeping the original partition, bytes, source budget and deadlines intact.
                $adoptHigh = $compact && $row['transport_final_effort'] === 'max' && $finalEffort === 'high'
                    && $row['submit_intent_at'] === null && $row['accepted_at'] === null;
                if (!hash_equals((string) $row['transport_transaction_id'], $transactionId)
                    || (int) $row['transport_parts_total'] !== $partsTotal
                    || (string) $row['transport_stage_effort'] !== $stageEffort
                    || ((string) $row['transport_final_effort'] !== $finalEffort && !$adoptHigh)
                    || ($row['transport_budget_json'] !== null && $transportJson !== null
                        && !hash_equals((string) $row['transport_budget_json'], $transportJson))) {
                    throw new GatewayError('multipart_state_conflict', 'Multipart 恢复参数与已保存事务不一致。', 409);
                }
                if ($adoptHigh || ($compact && $row['transport_budget_json'] === null && $transportJson !== null)) {
                    $updated = SDB(null, 'medoo', 'sqlite')->table('gateway_response_jobs')->where('id', $responseId)
                        ->where('status', 'running')->where('transport_final_effort', $row['transport_final_effort'])
                        ->update(['transport_final_effort'=>$finalEffort, 'transport_budget_json'=>$transportJson ?? $row['transport_budget_json']]);
                    if (!$updated || $updated->rowCount() !== 1) throw new GatewayError('storage_unavailable', 'Multipart 物理预算无法保存。', 503);
                }
                return;
            }
            $updated = SDB(null, 'medoo', 'sqlite')->table('gateway_response_jobs')
                ->where('id', $responseId)->where('status', 'running')->where('transport_transaction_id', null)->update([
                    'transport_transaction_id' => $transactionId,
                    'transport_parts_total' => $partsTotal,
                    'transport_stage_effort' => $stageEffort,
                    'transport_final_effort' => $finalEffort,
                    'transport_budget_json' => $transportJson,
                ]);
            if (!$updated || $updated->rowCount() !== 1) throw new GatewayError('storage_unavailable', 'Multipart 事务无法保存。', 503);
            if (in_array((string) ($row['operation_kind'] ?? 'response'), ['compact_v1', 'compact_v2'], true)) {
                TaskLedger::prepareMultipartCompactionUnlocked($responseId, $partsTotal);
            }
        });
    }

    /** Durable submit intent for an inert multipart stage. Never resend this stage after this point. */
    public static function multipartStageIntent(string $responseId, int $stage, ?string $webText = null): void
    {
        Schema::transaction(function () use ($responseId, $stage, $webText): void {
            $row = self::row($responseId);
            $total = (int) ($row['transport_parts_total'] ?? 0);
            $sent = (int) ($row['transport_stage_sent'] ?? 0);
            $acked = (int) ($row['transport_stage_acked'] ?? 0);
            if ((string) $row['status'] !== 'running' || $total < 2 || $stage < 1 || $stage >= $total) {
                throw new GatewayError('multipart_state_invalid', 'Multipart 分段发送状态无效。', 409);
            }
            if ($sent >= $stage) return;
            if ($stage !== $sent + 1 || $acked !== $stage - 1) {
                throw new GatewayError('multipart_stage_order_invalid', 'Multipart 分段必须按顺序发送并确认。', 409);
            }
            if ($webText !== null) WebMessageAudit::recordOutgoingUnlocked($row, 'stage', $webText, $stage);
            $updated = SDB(null, 'medoo', 'sqlite')->table('gateway_response_jobs')
                ->where('id', $responseId)->where('status', 'running')
                ->where('transport_stage_sent', $sent)->where('transport_stage_acked', $acked)
                ->update(['transport_stage_sent' => $stage]);
            if (!$updated || $updated->rowCount() !== 1) throw new GatewayError('storage_unavailable', 'Multipart 分段发送意图无法保存。', 503);
        });
    }

    /** Commit the exact transaction-bound acknowledgement before the next stage may begin. */
    public static function multipartStageAcknowledged(string $responseId, int $stage): void
    {
        Schema::transaction(function () use ($responseId, $stage): void {
            $row = self::row($responseId);
            $acked = (int) ($row['transport_stage_acked'] ?? 0);
            if ($acked >= $stage) return;
            if ((string) $row['status'] !== 'running'
                || (int) ($row['transport_stage_sent'] ?? 0) < $stage
                || $stage !== $acked + 1) {
                throw new GatewayError('multipart_stage_order_invalid', 'Multipart ACK 顺序无效。', 409);
            }
            $updated = SDB(null, 'medoo', 'sqlite')->table('gateway_response_jobs')
                ->where('id', $responseId)->where('status', 'running')
                ->where('transport_stage_acked', $acked)->update(['transport_stage_acked' => $stage]);
            if (!$updated || $updated->rowCount() !== 1) throw new GatewayError('storage_unavailable', 'Multipart ACK 无法保存。', 503);
        });
        WebMessageAudit::markAccepted($responseId, 'stage', $stage);
        WebMessageAudit::markAccepted($responseId, 'stage_ack_repair', $stage);
    }

    /** Record proof that ChatGPT accepted the click as a new user/assistant/generation turn. */
    public static function accepted(string $responseId): void
    {
        $updated = SDB(null, 'medoo', 'sqlite')->table('gateway_response_jobs')->where('id', $responseId)->where('status', 'running')->where('accepted_at', null)->update(['accepted_at' => time()]);
        if ($updated === false) throw new GatewayError('storage_unavailable', '页面接收证据无法保存。', 503);
        TaskLedger::submitted($responseId);
        ToolBroker::markSentByResponse($responseId);
        WebMessageAudit::markAccepted($responseId, 'commit');
        WebMessageAudit::markAccepted($responseId, 'continuation');
    }

    /**
     * Publish one completed, model-authored public progress block exactly once per physical turn.
     * sourceKey identifies a visible DOM block or an authenticated Web progress tool call;
     * neither source is reasoning generated by the gateway.
     * @param string $responseId Running ordinary round receiving the observed block.
     * @param string $text Exact public Markdown authored by the model.
     * @param string $sourceKey Stable block identity shared across this physical turn's rounds.
     * @return bool True when published or already durable; false when the round cannot accept progress.
     * @throws GatewayError If progress or its durable deduplication evidence cannot be saved/read.
     */
    public static function appendCommentary(string $responseId, string $text, string $sourceKey): bool
    {
        if ($text === '' || $sourceKey === '') return false;
        $published = Schema::transaction(function () use ($responseId, $text, $sourceKey): ?bool {
            $job = self::row($responseId);
            if ($job['status'] !== 'running' || ($job['operation_kind'] ?? 'response') !== 'response') return null;
            $itemId = 'msg_c_' . substr(hash('sha256', (string) ($job['durable_turn_id'] ?? $responseId) . "\0" . $sourceKey), 0, 32);
            $output = self::streamOutput($job);
            foreach ($output as $item) if (($item['id'] ?? '') === $itemId) return false;
            // Native tool continuations share the physical browser turn. A recovered browser
            // can observe old progress again; the durable round history is the dedupe source.
            $roundCursor = -1;
            do {
                $rounds = SDB(null, 'medoo', 'sqlite')->table('gateway_response_jobs')
                    ->where('durable_turn_id', $job['durable_turn_id'])->where('status', 'completed')
                    ->where('round_no', '>', $roundCursor)->order([['round_no', 'ASC']])->limit(128)
                    ->select('round_no,response_json');
                if ($rounds === false) throw new GatewayError('storage_unavailable', 'Responses 进度历史无法读取。', 503);
                foreach ($rounds as $round) {
                    $roundCursor = (int) $round['round_no'];
                    foreach (self::streamOutput($round) as $item) if (($item['id'] ?? '') === $itemId) return false;
                }
            } while (count($rounds) === 128);
            $index = count($output);
            $events = ResponsesProtocol::openOutputEvents($itemId, $index, 'commentary');
            $events[] = ResponsesProtocol::deltaEvent($itemId, $text, $index);
            $events = array_merge($events, array_slice(ResponsesProtocol::completionEvents($responseId, $itemId, $text, $index, 'commentary'), 0, 3));
            $output[] = ResponsesProtocol::messageItem($itemId, 'completed', $text, 'commentary');
            self::appendEventsUnlocked($job, $events);
            $updated = SDB(null, 'medoo', 'sqlite')->table('gateway_response_jobs')->where('id', $responseId)->where('status', 'running')->update([
                'response_json' => self::streamResponseJson($job, $output), 'sequence_next' => (int) $job['sequence_next'],
            ]);
            if (!$updated || $updated->rowCount() !== 1) throw new GatewayError('storage_unavailable', 'Responses 进度无法保存。', 503);
            return true;
        });
        if ($published) TaskLedger::renewOnVisibleProgress($responseId);
        return $published !== null;
    }

    /**
     * Stream a stable snapshot of still-growing public prose as provisional commentary.
     * Each source retains its exact delivered prefix across native receipt rounds. Only
     * the new suffix is appended; closed Responses items are never reopened. A rewrite
     * starts a separate progress item rather than changing already delivered events.
     * @param string $responseId Running ordinary round; checkpoints cannot accept progress.
     * @param string $text Exact visible, settled Markdown supplied by the owned adapter.
     * @param string $sourceKey Physical assistant/block identity, without a content hash.
     * @return bool Whether this round accepted or had already delivered the snapshot.
     * @throws GatewayError If its durable progress history or events cannot be saved.
     */
    public static function appendProgressSnapshot(string $responseId, string $text, string $sourceKey): bool
    {
        if ($text === '' || $sourceKey === '') return false;
        $published = Schema::transaction(function () use ($responseId, $text, $sourceKey): ?bool {
            $job = self::row($responseId);
            if ($job['status'] !== 'running' || ($job['operation_kind'] ?? 'response') !== 'response') return null;
            $source = 'msg_p_' . substr(hash('sha256', (string) ($job['durable_turn_id'] ?? $responseId) . "\0" . $sourceKey), 0, 32) . '_';
            $output = self::streamOutput($job);
            $prefix = ''; $number = 0; $open = null;
            $collect = static function (array $items, bool $current) use ($source, &$prefix, &$number, &$open): void {
                foreach ($items as $index => $item) {
                    $id = (string) ($item['id'] ?? '');
                    if (!str_starts_with($id, $source)) continue;
                    $parts = explode('_', substr($id, strlen($source)));
                    if (count($parts) !== 2 || !ctype_digit($parts[0]) || !ctype_digit($parts[1])) continue;
                    $number = max($number, (int) $parts[0] + 1);
                    $offset = (int) $parts[1];
                    $value = (string) ($item['content'][0]['text'] ?? '');
                    if ($offset === 0) $prefix = $value;
                    elseif ($offset === strlen($prefix)) $prefix .= $value;
                    if ($current && ($item['status'] ?? '') === 'in_progress') $open = [$index, $item];
                }
            };
            // A source starting in this round needs no history scan on each text delta.
            $localStart = false;
            foreach ($output as $item) {
                $id = (string) ($item['id'] ?? '');
                if (str_starts_with($id, $source)) { $localStart = str_ends_with($id, '_0'); break; }
            }
            if (!$localStart) {
                $cursor = -1;
                do {
                    $rounds = SDB(null, 'medoo', 'sqlite')->table('gateway_response_jobs')
                        ->where('durable_turn_id', $job['durable_turn_id'])->where('status', 'completed')
                        ->where('round_no', '>', $cursor)->order([['round_no', 'ASC']])->limit(128)
                        ->select('round_no,response_json');
                    if ($rounds === false) throw new GatewayError('storage_unavailable', 'Responses 进度历史无法读取。', 503);
                    foreach ($rounds as $round) { $cursor = (int) $round['round_no']; $collect(self::streamOutput($round), false); }
                } while (count($rounds) === 128);
            }
            $collect($output, true);
            if ($prefix === $text) return false;
            // A temporarily shortened DOM observation is not a new model-authored revision.
            if ($text !== '' && str_starts_with($prefix, $text)) return false;
            $events = [];
            if (!str_starts_with($text, $prefix)) {
                if ($open !== null) {
                    [$index, $item] = $open;
                    $value = (string) ($item['content'][0]['text'] ?? '');
                    $events = array_slice(ResponsesProtocol::completionEvents($responseId, $item['id'], $value, $index, 'commentary'), 0, 3);
                    $output[$index] = ResponsesProtocol::messageItem($item['id'], 'completed', $value, 'commentary');
                }
                $prefix = ''; $open = null;
            }
            $delta = substr($text, strlen($prefix));
            if ($open === null) {
                $index = count($output); $itemId = $source . $number . '_' . strlen($prefix); $value = $delta;
                $events = array_merge($events, ResponsesProtocol::openOutputEvents($itemId, $index, 'commentary'));
            } else {
                [$index, $item] = $open; $itemId = $item['id'];
                $value = (string) ($item['content'][0]['text'] ?? '') . $delta;
            }
            $events[] = ResponsesProtocol::deltaEvent($itemId, $delta, $index);
            $output[$index] = ResponsesProtocol::messageItem($itemId, 'in_progress', $value, 'commentary');
            self::appendEventsUnlocked($job, $events);
            $updated = SDB(null, 'medoo', 'sqlite')->table('gateway_response_jobs')->where('id', $responseId)->where('status', 'running')->update([
                'response_json' => self::streamResponseJson($job, $output), 'sequence_next' => (int) $job['sequence_next'],
            ]);
            if (!$updated || $updated->rowCount() !== 1) throw new GatewayError('storage_unavailable', 'Responses 进度无法保存。', 503);
            return true;
        });
        if ($published) TaskLedger::renewOnVisibleProgress($responseId);
        return $published !== null;
    }

    /**
     * Publish one settled, visibly rendered ChatGPT reasoning summary as native Responses
     * reasoning events. The caller must prove the source is public DOM content; neither
     * hidden chain of thought nor an invented encrypted envelope is accepted here.
     * @param string $responseId Running ordinary round receiving the visible summary.
     * @param string $text Exact visibly rendered summary, never private reasoning.
     * @param string $sourceKey Stable observed block identity used for durable deduplication.
     * @return bool True when published or already durable; false when the round cannot accept progress.
     * @throws GatewayError If the summary or its durable deduplication evidence cannot be saved/read.
     */
    public static function appendReasoningSummary(string $responseId, string $text, string $sourceKey): bool
    {
        if ($text === '' || $sourceKey === '') return false;
        $published = Schema::transaction(function () use ($responseId, $text, $sourceKey): ?bool {
            $job = self::row($responseId);
            if ($job['status'] !== 'running' || ($job['operation_kind'] ?? 'response') !== 'response') return null;
            $itemId = 'rs_' . substr(hash('sha256', (string) ($job['durable_turn_id'] ?? $responseId) . "\0" . $sourceKey), 0, 32);
            $output = self::streamOutput($job);
            foreach ($output as $item) if (($item['id'] ?? '') === $itemId) return false;
            // A browser restart may observe the same public summary in a later Responses
            // sub-round of this physical turn. Durable completed rounds provide the proof.
            $roundCursor = -1;
            do {
                $rounds = SDB(null, 'medoo', 'sqlite')->table('gateway_response_jobs')
                    ->where('durable_turn_id', $job['durable_turn_id'])->where('status', 'completed')
                    ->where('round_no', '>', $roundCursor)->order([['round_no', 'ASC']])->limit(128)
                    ->select('round_no,response_json');
                if ($rounds === false) throw new GatewayError('storage_unavailable', 'Responses 推理摘要历史无法读取。', 503);
                foreach ($rounds as $round) {
                    $roundCursor = (int) $round['round_no'];
                    foreach (self::streamOutput($round) as $item) if (($item['id'] ?? '') === $itemId) return false;
                }
            } while (count($rounds) === 128);
            $index = count($output);
            self::appendEventsUnlocked($job, ResponsesProtocol::reasoningSummaryEvents($itemId, $text, $index));
            $output[] = ResponsesProtocol::reasoningSummaryItem($itemId, $text);
            $updated = SDB(null, 'medoo', 'sqlite')->table('gateway_response_jobs')->where('id', $responseId)->where('status', 'running')->update([
                'response_json' => self::streamResponseJson($job, $output), 'sequence_next' => (int) $job['sequence_next'],
            ]);
            if (!$updated || $updated->rowCount() !== 1) throw new GatewayError('storage_unavailable', 'Responses 推理摘要无法保存。', 503);
            return true;
        });
        if ($published) TaskLedger::renewOnVisibleProgress($responseId);
        return $published !== null;
    }

    /**
     * Stream a provisional answer as Codex commentary while host work is still open.
     * A later tool call or continuation may replace this browser text, so it cannot be
     * published as the irreversible final-answer prefix. The draft identity and text
     * live in response_json and survive a browser worker restart.
     */
    public static function appendDraftText(string $responseId, string $delta): void
    {
        if ($delta === '') return;
        Schema::transaction(function () use ($responseId, $delta): void {
            $job = self::row($responseId);
            if ($job['status'] !== 'running' || ($job['operation_kind'] ?? 'response') !== 'response') return;
            $output = self::streamOutput($job);
            $draft = self::draftItem($output);
            $events = [];
            if ($draft === null) {
                $number = count(array_filter($output, static fn(array $item): bool => str_starts_with((string)($item['id'] ?? ''), 'msg_d_')));
                $id = 'msg_d_' . substr(hash('sha256', (string)$job['item_id'] . "\0" . $number), 0, 32);
                $index = count($output);
                $text = $delta;
                $events = ResponsesProtocol::openOutputEvents($id, $index, 'commentary');
            } else {
                [$index, $item] = $draft;
                $id = (string)$item['id'];
                $text = (string)($item['content'][0]['text'] ?? '') . $delta;
            }
            $events[] = ResponsesProtocol::deltaEvent($id, $delta, $index);
            $output[$index] = ResponsesProtocol::messageItem($id, 'in_progress', $text, 'commentary');
            self::appendEventsUnlocked($job, $events);
            $updated = SDB(null, 'medoo', 'sqlite')->table('gateway_response_jobs')->where('id', $responseId)
                ->where('status', 'running')->update([
                    'response_json' => self::streamResponseJson($job, $output),
                    'sequence_next' => (int)$job['sequence_next'],
                ]);
            if (!$updated || $updated->rowCount() !== 1) throw new GatewayError('storage_unavailable', 'Responses 工作进度无法保存。', 503);
        });
        TaskLedger::running($responseId);
        TaskLedger::renewOnVisibleProgress($responseId);
    }

    /** Close provisional answer and public-prose items before a tool boundary, retry or terminal reply. */
    public static function finishDraftText(string $responseId): void
    {
        Schema::transaction(function () use ($responseId): void {
            $job = self::row($responseId);
            if ($job['status'] !== 'running') return;
            $output = self::streamOutput($job);
            $events = [];
            foreach ($output as $index => $item) {
                $id = (string) ($item['id'] ?? '');
                if (($item['status'] ?? '') !== 'in_progress'
                    || (!str_starts_with($id, 'msg_d_') && !str_starts_with($id, 'msg_p_'))) continue;
                $text = (string) ($item['content'][0]['text'] ?? '');
                $events = array_merge($events, array_slice(ResponsesProtocol::completionEvents($responseId, $id, $text, $index, 'commentary'), 0, 3));
                $output[$index] = ResponsesProtocol::messageItem($id, 'completed', $text, 'commentary');
            }
            if ($events === []) return;
            self::appendEventsUnlocked($job, $events);
            $updated = SDB(null, 'medoo', 'sqlite')->table('gateway_response_jobs')->where('id', $responseId)
                ->where('status', 'running')->update([
                    'response_json' => self::streamResponseJson($job, $output),
                    'sequence_next' => (int)$job['sequence_next'],
                ]);
            if (!$updated || $updated->rowCount() !== 1) throw new GatewayError('storage_unavailable', 'Responses 工作进度结束状态无法保存。', 503);
        });
    }

    /** Return the durable open commentary prefix for a recovered browser worker. */
    public static function draftText(string $responseId): string
    {
        $draft = self::draftItem(self::streamOutput(self::row($responseId)));
        return $draft === null ? '' : (string)($draft[1]['content'][0]['text'] ?? '');
    }

    /**
     * Append an ordinary response's irrevocable Markdown prefix, preserving its output index.
     * Checkpoints publish only their final envelope; partial page text is never a wire prefix.
     */
    public static function appendText(string $responseId, string $delta): void
    {
        if ($delta === '') return;
        Schema::transaction(function () use ($responseId, $delta): void {
            $job = self::row($responseId);
            if ($job['status'] !== 'running' || ($job['operation_kind'] ?? 'response') !== 'response') return;
            $output = self::streamOutput($job);
            $outputIndex = self::textOutputIndex($job, $output);
            $events = [];
            if ((int) $job['output_started'] === 0) {
                $events = ResponsesProtocol::openOutputEvents((string) $job['item_id'], $outputIndex);
                $job['output_started'] = 1;
            }
            $events[] = ResponsesProtocol::deltaEvent((string) $job['item_id'], $delta, $outputIndex);
            self::appendEventsUnlocked($job, $events);
            $text = (string) $job['output_text'] . $delta;
            $output[$outputIndex] = ResponsesProtocol::messageItem((string) $job['item_id'], 'in_progress', $text);
            $updated = SDB(null, 'medoo', 'sqlite')->table('gateway_response_jobs')->where('id', $responseId)->where('status', 'running')->update([
                'output_started' => 1, 'output_text' => $text, 'sequence_next' => (int) $job['sequence_next'],
                'response_json' => self::streamResponseJson($job, $output),
            ]);
            if (!$updated || $updated->rowCount() !== 1) throw new GatewayError('storage_unavailable', 'Responses 文本无法保存。', 503);
        });
        TaskLedger::running($responseId);
        TaskLedger::renewOnVisibleProgress($responseId);
    }

    /**
     * Commit a final page snapshot. Ordinary streamed text must remain an exact prefix.
     * Fresh checkpoints in older databases may contain private drafts without text events;
     * validate and commit their final summary atomically instead of treating drafts as output.
     */
    public static function complete(string $responseId, string $finalText): void
    {
        self::finishDraftText($responseId);
        Schema::transaction(function () use ($responseId, $finalText): void {
            $job = self::row($responseId);
            if (in_array($job['status'], ['completed', 'failed'], true)) return;
            if ($job['status'] !== 'running') throw new GatewayError('response_state_invalid', 'Responses 状态无法完成。', 503);
            if (!empty($job['mcp_binding_id'])) {
                $completion = ToolBroker::completionForResponse($responseId);
                if (!is_array($completion) || (string) $completion['status'] !== 'completed') {
                    throw new GatewayError('mcp_completion_required', '工具回合尚未通过完成栅栏，不能提前结束 Responses。', 409);
                }
            }
            $finalText = LunaCheckpoint::completeUnlocked($job, $finalText);
            $retainedHandoff = ($job['operation_kind'] ?? 'response') !== 'response'
                && is_string($job['previous_response_id'] ?? null) && $job['previous_response_id'] !== '';
            $events = [];
            if ($retainedHandoff) {
                // The source assistant's ordinary settlement is not a compaction summary.
                // Only the separate one-shot control receipt can complete this Responses job.
                if ((string) $job['output_text'] !== '' || (int) $job['output_started'] !== 0) {
                    throw new GatewayError('compaction_stream_conflict', '压缩交接之前已经提交了正文，未替换原上下文。', 409);
                }
            } elseif (($job['operation_kind'] ?? 'response') === 'response') {
                $emitted = (string) $job['output_text'];
                if (!str_starts_with($finalText, $emitted)) {
                    throw new GatewayError('response_changed_after_stream', 'ChatGPT 修改了已经流式提交的文本，已拒绝伪造一致结果。', 502);
                }
                $output = self::streamOutput($job);
                $outputIndex = self::textOutputIndex($job, $output);
                $remaining = substr($finalText, strlen($emitted));
                if ($remaining !== '') {
                    if ((int) $job['output_started'] === 0) {
                        $events = ResponsesProtocol::openOutputEvents((string) $job['item_id'], $outputIndex);
                        $job['output_started'] = 1;
                    }
                    $events[] = ResponsesProtocol::deltaEvent((string) $job['item_id'], $remaining, $outputIndex);
                }
                if ((int) $job['output_started'] === 0) {
                    throw new GatewayError('empty_response', 'ChatGPT 完成了页面生成但没有可返回的文本。', 502);
                }
            }
            if (($job['operation_kind'] ?? 'response') !== 'response') {
                $response = ContextCompaction::completeUnlocked($job, $finalText);
                $events = ContextCompaction::completionEvents($job, $response);
            } else {
                $output[$outputIndex] = ResponsesProtocol::messageItem((string) $job['item_id'], 'completed', $finalText);
                $response = ResponsesProtocol::responseWithOutput($responseId, 'completed', $output, true);
                $response['model'] = (string) $job['model'];
                $outputTokens = ContextBudget::tokens($finalText, json_decode((string)$job['budget_json'], true, 128, JSON_THROW_ON_ERROR));
                $response['usage'] = ['input_tokens' => (int) $job['input_tokens'], 'output_tokens' => $outputTokens,
                    'total_tokens' => (int) $job['input_tokens'] + $outputTokens];
                $events = array_merge($events, ResponsesProtocol::completionEvents($responseId, (string) $job['item_id'], $finalText, $outputIndex));
                foreach ($events as &$event) if ($event['type'] === 'response.completed') $event['response'] = $response;
                unset($event);
            }
            self::appendEventsUnlocked($job, $events);
            $updated = SDB(null, 'medoo', 'sqlite')->table('gateway_response_jobs')->where('id', $responseId)->where('status', 'running')->update([
                'status' => 'completed', 'output_text' => $finalText, 'output_started' => 1,
                'response_json' => json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'sequence_next' => (int) $job['sequence_next'], 'terminal_type' => 'response.completed', 'finished_at' => time(),
            ]);
            if (!$updated || $updated->rowCount() !== 1) throw new GatewayError('storage_unavailable', 'Responses 完成状态无法保存。', 503);
        });
        TaskLedger::committed($responseId);
        $job = self::row($responseId);
        CodexIntegration::clientSeen((string) ($job['integration_id'] ?? ''), 'response');
    }

    /** Keep existing single-call callers on the atomic batch output path. */
    public static function completeToolCall(string $responseId, array $invocation, string $browserPrefix = ''): void
    {
        self::completeToolCalls($responseId, [$invocation], $browserPrefix);
    }

    /**
     * End one Responses round with every concurrently registered host call. The broker seals
     * and delivers the exact call set in this same transaction, so a crash cannot leave a
     * completed Responses stream with only some calls marked delivered. Replays must match
     * the committed call IDs, arguments, and order before returning idempotently.
     * Item IDs use native type-specific prefixes; call_id remains the broker receipt identity.
     * @param list<array> $invocations Verified broker invocations in delivery order.
     * @param ?string $visibleRoundText Answer-root Markdown from this Web round, if observed.
     */
    public static function completeToolCalls(string $responseId, array $invocations, string $browserPrefix = '', ?string $visibleRoundText = null): void
    {
        if ($invocations === [] || !array_is_list($invocations)) {
            throw new GatewayError('tool_call_batch_invalid', '工具调用批次必须是非空有序列表。', 409);
        }
        $ids = []; $callIds = [];
        foreach ($invocations as $invocation) {
            if (!is_array($invocation)
                || !is_string($invocation['id'] ?? null) || $invocation['id'] === ''
                || !is_string($invocation['call_id'] ?? null) || $invocation['call_id'] === ''
                || !is_string($invocation['name'] ?? null) || $invocation['name'] === '') {
                throw new GatewayError('tool_call_batch_invalid', '工具调用批次包含无效项目。', 409);
            }
            if (isset($ids[$invocation['id']]) || isset($callIds[$invocation['call_id']])) {
                throw new GatewayError('tool_call_batch_invalid', '工具调用批次包含重复 ID。', 409);
            }
            $ids[$invocation['id']] = true;
            $callIds[$invocation['call_id']] = true;
        }
        if ($visibleRoundText !== null && $visibleRoundText !== '') {
            // A tool can arrive before the two-snapshot streaming poll publishes the
            // visible answer. Keep that provisional text as commentary at this boundary.
            $draft = self::draftText($responseId);
            if (!str_starts_with($visibleRoundText, $draft)) {
                self::finishDraftText($responseId);
                $draft = '';
            }
            self::appendDraftText($responseId, substr($visibleRoundText, strlen($draft)));
        }
        self::finishDraftText($responseId);
        Schema::transaction(function () use ($responseId, $invocations, $ids, $browserPrefix): void {
            $job = self::row($responseId);
            if ((string) $job['status'] === 'completed') {
                if ((string) ($job['round_kind'] ?? '') !== 'tool_call') {
                    throw new GatewayError('response_state_invalid', 'Responses Round 已以其他方式完成。', 409);
                }
                $recorded = array_values(array_filter(self::streamOutput($job), static fn(array $item): bool =>
                    in_array((string) ($item['type'] ?? ''), ['function_call', 'custom_tool_call', 'tool_search_call'], true)));
                if (count($recorded) !== count($invocations)) {
                    throw new GatewayError('tool_call_replay_conflict', '重复提交的工具批次与已完成 Round 不一致。', 409);
                }
                foreach ($invocations as $index => $invocation) {
                    $item = $recorded[$index];
                    $expected = ResponsesProtocol::brokerToolItem($invocation, (string) ($item['id'] ?? ''));
                    $same = (string) ($item['type'] ?? '') === $expected['type']
                        && (string) ($item['call_id'] ?? '') === $expected['call_id']
                        && (string) ($item['name'] ?? '') === (string) ($expected['name'] ?? '')
                        && (string) ($item['status'] ?? '') === 'completed';
                    if ($expected['type'] === 'tool_search_call') {
                        $same = $same && ($item['execution'] ?? '') === 'client'
                            && ContextHistory::canonical($item['arguments'] ?? []) === ContextHistory::canonical($expected['arguments']);
                    } elseif ($expected['type'] === 'custom_tool_call') {
                        $same = $same && (string) ($item['input'] ?? '') === $expected['input'];
                    } else {
                        $actualNamespace = (string) ($item['namespace'] ?? '');
                        if ($actualNamespace === 'functions') $actualNamespace = '';
                        $same = $same && (string) ($item['arguments'] ?? '') === $expected['arguments']
                            && $actualNamespace === (string) ($expected['namespace'] ?? '');
                    }
                    $registered = SDB(null, 'medoo', 'sqlite')->table('gateway_tool_invocations')
                        ->where('id', (string) $invocation['id'])->where('response_id', $responseId)->find();
                    if (!$same || !is_array($registered)
                        || (string) $registered['call_id'] !== (string) $invocation['call_id']) {
                        throw new GatewayError('tool_call_replay_conflict', '重复提交的工具批次与已完成 Round 不一致。', 409);
                    }
                }
                return;
            }
            if ((string) $job['status'] !== 'running') throw new GatewayError('response_state_invalid', 'Responses 状态无法提交工具调用。', 409);
            $registered = ToolBroker::invocationsForResponse($responseId);
            if (count($registered) !== count($invocations)) {
                throw new GatewayError('tool_batch_changed', '待交付工具批次与已登记请求不一致。', 409);
            }
            foreach ($invocations as $index => $invocation) {
                $original = $registered[$index];
                foreach (['id', 'call_id', 'wire_name', 'name', 'namespace', 'freeform', 'tool_search', 'arguments', 'input'] as $key) {
                    if (($invocation[$key] ?? null) !== ($original[$key] ?? null)) {
                        throw new GatewayError('tool_batch_changed', '待交付工具批次与已登记请求不一致。', 409);
                    }
                }
            }
            ToolBroker::sealInvocationBatchUnlocked($responseId, array_keys($ids));
            $output = self::streamOutput($job);
            $events = [];
            $textIndex = self::textOutputIndex($job, $output);
            if ((string) $job['output_text'] !== '') {
                $text = (string) $job['output_text'];
                $output[$textIndex] = ResponsesProtocol::messageItem((string) $job['item_id'], 'completed', $text);
                $events = array_slice(ResponsesProtocol::completionEvents($responseId, (string) $job['item_id'], $text, $textIndex), 0, 3);
            }
            foreach ($invocations as $invocation) {
                $toolItemPrefix = !empty($invocation['tool_search']) ? 'tsc_'
                    : (!empty($invocation['freeform']) ? 'ctc_' : 'fc_');
                $toolItemId = $toolItemPrefix . bin2hex(random_bytes(12));
                $toolIndex = count($output);
                $events = array_merge($events, ResponsesProtocol::brokerToolItemEvents($invocation, $toolItemId, $toolIndex));
                $output[] = ResponsesProtocol::brokerToolItem($invocation, $toolItemId);
            }
            $response = ResponsesProtocol::responseWithOutput($responseId, 'completed', $output, false);
            $response['model'] = (string) $job['model'];
            $evidence = ['answer' => (string) $job['output_text'], 'tool_calls' => array_map(
                static fn(array $invocation): array => [
                    'call_id' => (string) $invocation['call_id'],
                    'name' => (string) $invocation['name'],
                    ...(!empty($invocation['freeform'])
                        ? ['input' => (string) ($invocation['input'] ?? '')]
                        : ['arguments' => $invocation['arguments'] ?? []]),
                ], $invocations)];
            $outputTokens = ContextBudget::tokens(json_encode($evidence,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                json_decode((string)$job['budget_json'], true, 128, JSON_THROW_ON_ERROR));
            $response['usage'] = [
                'input_tokens' => (int) $job['input_tokens'],
                'output_tokens' => $outputTokens,
                'total_tokens' => (int) $job['input_tokens'] + $outputTokens,
            ];
            $events[] = ['type' => 'response.completed', 'response' => $response];
            self::appendEventsUnlocked($job, $events);
            $updated = SDB(null, 'medoo', 'sqlite')->table('gateway_response_jobs')
                ->where('id', $responseId)->where('status', 'running')->update([
                    'status' => 'completed',
                    'round_kind' => 'tool_call',
                    'browser_prefix' => $browserPrefix,
                    'response_json' => json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                    'sequence_next' => (int) $job['sequence_next'],
                    'terminal_type' => 'response.completed',
                    'finished_at' => time(),
                ]);
            if (!$updated || $updated->rowCount() !== 1) throw new GatewayError('storage_unavailable', '工具调用 Responses Round 无法提交。', 503);
        });
    }

    /**
     * Publish the native failed terminal for a proven superseded or manually interrupted response.
     * The caller owns the ledger transaction and proves the newer native context or explicit interruption;
     * an existing failed terminal is preserved without rewriting its error or events.
     * @param string $responseId Source response replaced by a proven native context revision.
     * @param ?string $message Human- or mailbox-specific terminal explanation; null keeps the human default.
     * @param string $code Defaults to supersession; explicit stop uses codex_user_cancelled.
     * @return void
     * @throws GatewayError If the old response changes while its terminal is recorded.
     */
    public static function failSupersededUnlocked(string $responseId, ?string $message = null, string $code = 'response_superseded'): void
    {
        $job = self::row($responseId);
        if (!in_array((string) $job['status'], ['queued', 'running'], true)) return;
        if ((int) $job['sequence_next'] === 0) {
            self::appendEventsUnlocked($job, ResponsesProtocol::initialEvents($responseId));
        }
        $message ??= '网页回合已接收新的用户指令。';
        self::appendEventsUnlocked($job, [ResponsesProtocol::failedEvent($responseId,
            (string) $job['item_id'], $message, $code)]);
        $error = ResponsesProtocol::error($message, $code, null, 'server_error');
        $updated = SDB(null, 'medoo', 'sqlite')->table('gateway_response_jobs')->where('id', $responseId)
            ->where('status', (string) $job['status'])->update([
                'status' => 'failed',
                'error_json' => json_encode($error, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'sequence_next' => (int) $job['sequence_next'], 'terminal_type' => 'response.failed',
                'finished_at' => time(),
            ]);
        if (!$updated || $updated->rowCount() !== 1) {
            throw new GatewayError('response_superseded_conflict', '旧网页回合状态已变化。', 409);
        }
    }

    /** Publish at most one failed terminal. */
    public static function fail(string $responseId, string $code, string $message, ?bool $outcomeUnknown = null): void
    {
        ModelCooldown::record($code);
        Schema::transaction(function () use ($responseId, $code, $message): void {
            $job = self::row($responseId);
            if (in_array($job['status'], ['completed', 'failed'], true)) return;
            if ((int) $job['sequence_next'] === 0) {
                self::appendEventsUnlocked($job, ResponsesProtocol::initialEvents($responseId));
            }
            self::appendEventsUnlocked($job, [ResponsesProtocol::failedEvent($responseId, (string) $job['item_id'], $message, $code)]);
            $error = ResponsesProtocol::error($message, $code, null, 'server_error');
            $updated = SDB(null, 'medoo', 'sqlite')->table('gateway_response_jobs')->where('id', $responseId)->where('status', 'IN', ['queued', 'running'])->update([
                'status' => 'failed',
                'error_json' => json_encode($error, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'sequence_next' => (int) $job['sequence_next'], 'terminal_type' => 'response.failed', 'finished_at' => time(),
            ]);
            if (!$updated) throw new GatewayError('storage_unavailable', 'Responses 失败状态无法保存。', 503);
        });
        TaskLedger::failed($responseId, $code, $outcomeUnknown ?? self::outcomeUnknown($code));
        ContextRecovery::retireFailedStaging($responseId);
    }

    /** Publish cancelled terminals for responses already made non-runnable by TaskLedger::cancelTask(). */
    public static function cancelTask(string $taskId): array
    {
        ToolBroker::revokeTask($taskId);
        $cancelled = TaskLedger::cancelTask($taskId);
        foreach ($cancelled['response_ids'] as $responseId) {
            self::fail((string) $responseId, 'task_cancelled', '任务已由本机用户取消。');
        }
        return $cancelled;
    }

    /** Only a plausible same-turn user update may bypass the busy page's editor snapshot. */
    private static function potentialActiveUserSteering(array $spec): bool
    {
        if (($spec['operation_kind'] ?? 'response') !== 'response'
            || !is_string($spec['turn_id'] ?? null) || $spec['turn_id'] === '') return false;
        $context = json_decode((string) ($spec['context_json'] ?? ''), true);
        $messages = is_array($context) ? (array) ($context['messages'] ?? []) : [];
        if (($messages[array_key_last($messages)]['role'] ?? '') !== 'user') return false;
        $db = SDB(null, 'medoo', 'sqlite');
        $baseTaskId = 'task_' . substr((string) ($spec['task_key'] ?? ''), 0, 24);
        $link = $db->table('gateway_task_conversations')->where('base_task_id', $baseTaskId)->find();
        $taskId = is_array($link) ? (string) $link['current_task_id'] : $baseTaskId;
        $task = $db->table('gateway_tasks')->where('id', $taskId)->find();
        if (!is_array($task) || (string) $task['status'] !== 'active'
            || (string) $task['client_id'] !== (string) ($spec['client_id'] ?? 'responses')
            || ($task['thread_id'] ?? null) !== ($spec['thread_id'] ?? null)
            || (string) $task['branch_id'] !== (string) ($spec['branch_id'] ?? 'main')) return false;
        $turn = $db->table('gateway_task_turns')->where('task_id', $taskId)
            ->where('client_turn_id', (string) $spec['turn_id'])->find();
        if (!is_array($turn) || !in_array((string) ($turn['status'] ?? ''),
            ['queued', 'submit_intent', 'submitted', 'running'], true)) {
            $active = $db->table('gateway_task_turns')->where('task_id', $taskId)
                ->where('status', 'IN', ['queued', 'submit_intent', 'submitted', 'running'])->find();
            if (is_array($active) && str_starts_with((string) ($active['client_turn_id'] ?? ''), 'postcompact_')) {
                $turn = $active;
            }
        }
        if (!is_array($turn) || !in_array((string) $turn['status'],
            ['queued', 'submit_intent', 'submitted', 'running'], true)
            || (string) $turn['request_hash'] === (string) ($spec['request_hash'] ?? '')) return false;
        $source = $db->table('gateway_response_jobs')->where('id', (string) $turn['response_id'])->find();
        $previous = (string) ($spec['previous_response_id'] ?? '');
        return is_array($source) && ($previous === '' || $previous === (string) $source['id'])
            && in_array((string) $source['status'], ['queued', 'running'], true)
            && (string) $source['model'] === (string) ($spec['model'] ?? '')
            && ($source['reasoning_effort'] ?? null) === ($spec['reasoning_effort'] ?? null);
    }

    /** Safe, freshness-tagged ChatGPT DOM capability projection. */
    public static function pageSnapshot(?array $browser = null): array
    {
        $browser ??= BrowserState::state();
        $observed = (int) ($browser['chatgpt_observed_at'] ?? 0);
        $page = [];
        if (is_string($browser['chatgpt_json'] ?? null) && $browser['chatgpt_json'] !== '') {
            try { $page = json_decode($browser['chatgpt_json'], true, 32, JSON_THROW_ON_ERROR); } catch (\Throwable) { $page = []; }
        }
        return [
            'fresh' => $observed > 0 && $observed >= time() - BrowserState::CAPABILITY_MAX_AGE_SECONDS,
            'observed_at' => $observed,
            'on_chatgpt' => (bool) ($page['on_chatgpt'] ?? false),
            'login_required' => (bool) ($page['login_required'] ?? false),
            'editor_ready' => (bool) ($page['editor_ready'] ?? false),
            'generation_running' => (bool) ($page['generation_running'] ?? false),
            'model_label' => is_string($page['model_label'] ?? null) ? $page['model_label'] : null,
        ];
    }

    /**
     * Whether the project-private ChatGPT profile still has authenticated account evidence.
     *
     * After login has been proven once by a real editor, ChatGPT settings/plugin pages may not
     * contain a composer. Staying on chatgpt.com without an explicit login prompt must not make
     * setup regress to "not logged in"; an auth/login surface still revokes readiness immediately.
     */
    public static function accountReady(array $browser, ?array $page = null): bool
    {
        $page ??= self::pageSnapshot($browser);
        return (string) ($browser['status'] ?? '') === 'ready'
            && BrowserState::isWorkerReady($browser)
            && !empty($page['fresh'])
            && !empty($page['on_chatgpt'])
            && empty($page['login_required'])
            && (!empty($page['editor_ready']) || (int) ($browser['setup_completed'] ?? 0) === 1);
    }

    private static function row(string $responseId): array
    {
        $row = SDB(null, 'medoo', 'sqlite')->table('gateway_response_jobs')->where('id', $responseId)->find();
        if (!is_array($row)) throw new GatewayError('response_not_found', 'Responses 请求不存在。', 404);
        return $row;
    }

    /** Published output layout is durable in response_json, including while a round is running. */
    private static function streamOutput(array $job): array
    {
        $response = is_string($job['response_json'] ?? null)
            ? json_decode($job['response_json'], true, 64, JSON_THROW_ON_ERROR) : null;
        if (is_array($response['output'] ?? null)) return array_values($response['output']);
        // Jobs recovered from an older binary may already have emitted index zero.
        return !empty($job['output_started'])
            ? [ResponsesProtocol::messageItem((string) $job['item_id'], 'in_progress', (string) $job['output_text'])] : [];
    }

    /** @return array{int,array}|null */
    private static function draftItem(array $output): ?array
    {
        foreach (array_reverse($output, true) as $index => $item) {
            if (str_starts_with((string)($item['id'] ?? ''), 'msg_d_') && ($item['status'] ?? '') === 'in_progress') {
                return [$index, $item];
            }
        }
        return null;
    }

    /** Locate the already-open answer item without renumbering late progress blocks. */
    private static function textOutputIndex(array $job, array $output): int
    {
        foreach ($output as $index => $item) if (($item['id'] ?? '') === (string) $job['item_id']) return $index;
        return count($output);
    }

    /** Persist the same layout a reconnecting SSE client replays from gateway_response_events. */
    private static function streamResponseJson(array $job, array $output): string
    {
        $response = ResponsesProtocol::responseWithOutput((string) $job['id'], 'in_progress', $output);
        $response['model'] = (string) $job['model'];
        return json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /** Caller must own Schema transaction; mutates $job sequence_next. */
    private static function appendEventsUnlocked(array &$job, array $events): void
    {
        $db = SDB(null, 'medoo', 'sqlite');
        $sequence = (int) $job['sequence_next'];
        foreach ($events as $event) {
            $event['sequence_number'] = $sequence;
            if (!$db->table('gateway_response_events')->add([
                'response_id' => $job['id'],
                'sequence_number' => $sequence,
                'event_json' => json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'created_at' => time(),
            ])) {
                throw new GatewayError('storage_unavailable', 'Responses 事件无法保存。', 503);
            }
            $sequence++;
        }
        $job['sequence_next'] = $sequence;
    }

    private static function decode(array $row): array
    {
        $row['response'] = $row['response_json'] === null ? null : json_decode((string) $row['response_json'], true, 64, JSON_THROW_ON_ERROR);
        $row['error'] = $row['error_json'] === null ? null : json_decode((string) $row['error_json'], true, 64, JSON_THROW_ON_ERROR);
        unset($row['response_json'], $row['error_json'], $row['prompt'], $row['request_hash'], $row['idempotency_key']);
        return $row;
    }

    /** @return list<string> */
    private static function decodeList(mixed $json): array
    {
        if (!is_string($json) || $json === '') return [];
        try { $value = json_decode($json, true, 32, JSON_THROW_ON_ERROR); }
        catch (\Throwable) { return []; }
        if (!is_array($value)) return [];
        return array_values(array_filter($value, static fn($item): bool => is_string($item)));
    }

    private static function outcomeUnknown(string $code): bool
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
}
