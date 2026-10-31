<?php
declare(strict_types=1);
namespace app\gateway\model;

/** Restore opaque native parent deliveries from the exact verified parent's issued tool inputs. */
final class NativeAgentAssignment
{
    /** Immutable verified native delivery facts only; no ciphertext, plaintext or negative-result cache. */
    private static array $nativeDeliveries = [];

    /**
     * Verify agent delivery IDs outside the reservation writer. A native NEW_TASK may be
     * an idle-agent followup, so its exact delivery and owning parent call must be retained.
     * Canonical native history and its hashes remain unchanged.
     */
    public static function prepareEvidence(array $spec): array
    {
        $scope = $spec['budget']['native_subagent'] ?? null;
        $records = (array)($spec['native_agent_records'] ?? []);
        if (!is_array($scope) || $records === [] || !method_exists(CodexSubagent::class, 'sessionPath')) return $spec;
        CodexSubagent::scope((string)$scope['parent_thread_id']);
        $path = CodexSubagent::sessionPath((string)$scope['thread_id']);
        if ($path === null) return $spec;
        $wanted = [];
        foreach ($records as $record) if (is_string($record['id'] ?? null)) {
            $key = $path . '|' . $record['id'];
            if (isset(self::$nativeDeliveries[$key])) $spec['native_agent_evidence'][$record['id']] = self::$nativeDeliveries[$key];
            else $wanted[$record['id']] = true;
        }
        if ($wanted === []) return $spec;
        $stream = @fopen($path, 'rb');
        if (!is_resource($stream)) return $spec;
        try {
            $header = json_decode((string)fgets($stream, 262145), true);
            if (($header['payload']['id'] ?? null) !== $scope['thread_id']) return $spec;
            $size = fstat($stream)['size'];
            $offset = max(0, $size - 8388608);
            fseek($stream, $offset);
            if ($offset > 0) fgets($stream);
            while (($line = fgets($stream, 1048577)) !== false) {
                if (!str_ends_with($line, "\n")) {
                    while (!feof($stream) && !str_ends_with($line, "\n")) $line = (string)fgets($stream, 1048577);
                    continue;
                }
                if (!str_contains($line, '"agent_message"')) continue;
                $row = json_decode($line, true);
                $message = $row['payload'] ?? null;
                if (($row['type'] ?? '') !== 'response_item' || !is_array($message)
                    || ($message['type'] ?? '') !== 'agent_message' || !isset($wanted[$message['id'] ?? ''])) continue;
                $context = ContextHistory::compile([$message], '');
                $record = $context['messages'][0] ?? null;
                if (!is_array($record) || ($record['recipient'] ?? '') !== $scope['agent_path']) continue;
                if (!is_string($row['timestamp'] ?? null) || $row['timestamp'] === '') continue;
                try { $at = (float)(new \DateTimeImmutable($row['timestamp']))->format('U.u'); }
                catch (\Throwable) { continue; }
                $facts = ['record_sha256'=>hash('sha256', ContextHistory::canonical($record)), 'at'=>$at];
                if (count(self::$nativeDeliveries) >= 256) array_shift(self::$nativeDeliveries);
                self::$nativeDeliveries[$path . '|' . $message['id']] = $facts;
                $spec['native_agent_evidence'][$message['id']] = $facts;
                unset($wanted[$message['id']]);
                if ($wanted === []) break;
            }
        } finally { fclose($stream); }
        return $spec;
    }
    /**
     * Project proven parent NEW_TASK/MESSAGE records at browser dispatch preparation. The canonical
     * native archive stays byte-for-byte unchanged for receipt, restart and checkpoint matching.
     * Parent tool inputs are readable evidence, not a replay or a claim that a pending spawn finished.
     * Retained readable rows and normalized tool-contract envelopes are accepted only as exact
     * generated views of these same proven source records; arbitrary replacement text is rejected.
     * @param array $spec Reserved request whose native child scope was independently verified.
     * @param string $prompt Final browser prompt including its existing execution contracts.
     * @param string $transport Physical context selected by the existing history planner.
     * @param array $budget Pinned model budget; only source identity metadata is added.
     * @param int $inputTokens Previously counted logical input usage.
     * @param int $contextTokens Previously counted logical context before attachment reserves.
     * @param int $attachmentTokens Original logical attachment reserve.
     * @param ?string $budgetContext Exact selected logical source already counted by admission.
     * @return array Final prompt/context/budget/usage, without changing the native archive or tools.
     * @throws GatewayError If the missing initial assignment has no unique owned source or cannot fit.
     */
    public static function projectUnlocked(array $spec, string $prompt, string $transport, array $budget,
        int $inputTokens, int $contextTokens, int $attachmentTokens, ?string $budgetContext = null): array
    {
        $unchanged = [$prompt, $transport, $budget, $inputTokens];
        $scope = $budget['native_subagent'] ?? null;
        if (!is_array($scope) || ($scope['thread_id'] ?? '') !== ($spec['thread_id'] ?? null)
            || ContextHistory::canonical(CodexSubagent::scope($spec['thread_id'] ?? null))
                !== ContextHistory::canonical(array_intersect_key($scope,
                    array_flip(['thread_id','parent_thread_id','agent_path','depth'])))) return $unchanged;
        $db = SDB(null, 'medoo', 'sqlite');
        $ownTask = 'task_' . substr((string) $spec['task_key'], 0, 24);
        $prior = $db->table('gateway_response_jobs')->where('task_id', $ownTask)
            ->order([['rowid','DESC']])->limit(1)->find('budget_json');
        $known = is_array($prior) ? json_decode((string) $prior['budget_json'], true, 64) : null;
        $pinned = $known['native_subagent']['assignment'] ?? null;
        if (is_array($pinned)) {
            // An exact-prefix/receipt round may omit the initial record physically. Keep
            // its read-only source identity so a later full page recovery can re-project it.
            $budget['native_subagent']['assignment'] = $pinned;
            $unchanged[2] = $budget;
        }
        if (isset($known['native_subagent']['deliveries'])) {
            $budget['native_subagent']['deliveries'] = $known['native_subagent']['deliveries'];
            $unchanged[2] = $budget;
        }
        $physical = json_decode($transport, true, 128, JSON_THROW_ON_ERROR);
        $records = [];
        $parentScope = CodexSubagent::scope((string) $scope['parent_thread_id']);
        $parentPath = $parentScope['agent_path'] ?? '/root';
        $followup = false;
        foreach ((array) ($physical['messages'] ?? []) as $record) {
            if (self::initialRecord($record, $scope, $parentPath)) $records[] = $record;
            elseif (self::parentRecord($record, $scope, $parentPath)) $followup = true;
            if (self::parentRecord($record, $scope, $parentPath)) foreach ((array)($spec['native_agent_records'] ?? []) as $delivery) {
                $id = $delivery['id'] ?? null;
                if (is_string($id) && ($spec['native_agent_evidence'][$id]['record_sha256'] ?? '')
                    === hash('sha256', ContextHistory::canonical($record))) $followup = true;
            }
        }
        if ($records === [] && !$followup) return $unchanged;
        if (count($records) > 1 || $followup || !empty($budget['native_subagent']['deliveries'])) {
            return self::projectDeliveriesUnlocked($spec, $prompt, $physical, $budget, $inputTokens,
                $contextTokens, $attachmentTokens, $budgetContext, $scope, $parentPath, $pinned);
        }
        if ($records === []) return $unchanged;
        if (count($records) !== 1) self::unavailable();
        $original = reset($records);
        $source = self::sourceUnlocked($spec, $scope, $parentPath, hash('sha256', ContextHistory::canonical($original)), $pinned);
        $replacement = $original;
        $content = (string) $original['content'];
        $boundary = ContextHistory::OPAQUE_AGENT_NOTICE;
        if (substr_count($content, $boundary) !== 1) self::unavailable();
        $replacement['content'] = str_replace($boundary, $source['message'], $content);
        $originalCanonical = ContextHistory::canonical($original);
        $project = static function (array $context) use ($originalCanonical, $replacement): array {
            foreach ((array) ($context['messages'] ?? []) as $index => $message) {
                if (($message['role'] ?? '') === 'agent_message' && ContextHistory::canonical($message) === $originalCanonical) {
                    $context['messages'][$index] = $replacement;
                }
            }
            return $context;
        };
        $physical = $project($physical);
        // Work on the role-labelled envelope in place; do not regenerate or discard tool,
        // steering, checkpoint or task-binding contracts outside that envelope.
        $open = '<codex_context_json>';
        $start = strpos($prompt, $open);
        if ($start !== false) {
            $envelope = \support\browser\ChatGptTransport::contextEnvelope($prompt);
            $at = $envelope['start'] + strlen($open);
            $prompt = substr($prompt, 0, $at) . "\n" . ContextHistory::canonical($project($envelope['context']))
                . "\n" . substr($prompt, $envelope['end']);
        }
        // Count the replacement in the same logical source used by admission. Never append
        // an unbudgeted assignment after accepting a nearly full imported/checkpoint window.
        $logical = json_decode($budgetContext ?? (string) ($spec['full_context_json'] ?? $spec['context_json']), true, 128, JSON_THROW_ON_ERROR);
        $projectedTokens = max($contextTokens,
            ContextBudget::tokens(ContextHistory::canonical($project($logical)), $budget),
            ContextBudget::tokens(ContextHistory::canonical($physical), $budget));
        if (($spec['operation_kind'] ?? 'response') === 'response' && empty($spec['tool_outputs'])) {
            ContextBudget::validateTokens($projectedTokens, $budget, $attachmentTokens);
        }
        $inputTokens = ContextBudget::inputTokensFromCount($projectedTokens, $budget, $attachmentTokens);
        $budget['native_subagent']['assignment'] = [
            'task_id'=>$source['task_id'], 'call_id'=>$source['call_id'],
            'message_sha256'=>hash('sha256', $source['message']),
            'record_sha256'=>hash('sha256', ContextHistory::canonical($original)),
        ];
        foreach ((array)($spec['native_agent_records'] ?? []) as $delivery) {
            $id = $delivery['id'] ?? null;
            if (is_string($id) && ($spec['native_agent_evidence'][$id]['record_sha256'] ?? '')
                === $budget['native_subagent']['assignment']['record_sha256']) {
                $budget['native_subagent']['assignment']['native_message_id'] = $id;
                break;
            }
        }
        return [$prompt, ContextHistory::canonical($physical), $budget, $inputTokens];
    }

    /** Match only an opaque parent-to-this-child NEW_TASK or MESSAGE wrapper, never a child reply. */
    private static function parentRecord(mixed $record, array $scope, string $parentPath): bool
    {
        if (!is_array($record) || ($record['role'] ?? '') !== 'agent_message'
            || ($record['author'] ?? '') !== $parentPath || ($record['recipient'] ?? '') !== $scope['agent_path']
            || !is_string($record['content'] ?? null) || substr_count($record['content'], ContextHistory::OPAQUE_AGENT_NOTICE) !== 1) return false;
        foreach (['NEW_TASK','MESSAGE'] as $type) if (str_starts_with($record['content'],
            'Message Type: ' . $type . "\nTask name: " . $scope['agent_path'] . "\nSender: " . $parentPath . "\nPayload:\n")) return true;
        return false;
    }

    /** Restore each verified delivery independently; identical wrappers do not identify identical assignments. */
    private static function projectDeliveriesUnlocked(array $spec, string $prompt, array $physical, array $budget,
        int $inputTokens, int $contextTokens, int $attachmentTokens, ?string $budgetContext, array $scope,
        string $parentPath, mixed $legacyPin): array
    {
        $logical = json_decode((string)($spec['full_context_json'] ?? $spec['context_json']), true, 128, JSON_THROW_ON_ERROR);
        $logicalHash = hash('sha256', ContextHistory::canonical($logical));
        $full = $logical['messages'];
        $agentIndices = array_keys(array_filter($full, static fn(array $record): bool => ($record['role'] ?? '') === 'agent_message'));
        $wire = (array)($spec['native_agent_records'] ?? []);
        $ids = [];
        if ($wire !== [] && count($wire) <= count($agentIndices)) {
            $indices = array_slice($agentIndices, -count($wire));
            foreach ($wire as $i=>$record) {
                $index = $indices[$i];
                if (($record['record_sha256'] ?? '') !== hash('sha256', ContextHistory::canonical($full[$index]))) self::unavailable();
                $ids[$index] = $record['id'] ?? null;
            }
        }
        $old = (array)($budget['native_subagent']['deliveries'] ?? []);
        $replacements = [];
        $pins = [];
        $first = true;
        $previousOrder = 0;
        $incomingTimes = [];
        foreach ($ids as $id) if (is_string($id) && isset($spec['native_agent_evidence'][$id]['at'])) {
            $incomingTimes[] = (float)$spec['native_agent_evidence'][$id]['at'];
        }
        // Native compaction may omit all earlier deliveries. Their pinned source order
        // remains a lower bound; a delayed older HTTP request cannot borrow a later pin.
        if ($incomingTimes !== []) foreach ($old as $oldId=>$saved) {
            if (in_array($oldId, $ids, true) || !isset($saved['native_at'])
                || (float)$saved['native_at'] >= min($incomingTimes)) continue;
            $prior = SDB(null, 'medoo', 'sqlite')->table('gateway_tool_invocations')->where('task_id', $saved['task_id'])
                ->where('call_id', $saved['call_id'])->find('rowid');
            if (is_array($prior)) $previousOrder = max($previousOrder, (int)$prior['rowid']);
        }
        foreach ($full as $index=>$record) {
            if (!self::parentRecord($record, $scope, $parentPath)) continue;
            $hash = hash('sha256', ContextHistory::canonical($record));
            $id = $ids[$index] ?? null;
            $pin = is_string($id) ? ($old[$id] ?? null) : null;
            if ($id === null) foreach ($old as $savedId=>$saved) {
                if (($saved['source_sha256'] ?? '') === $logicalHash
                    && ($saved['message_index'] ?? null) === $index && ($saved['record_sha256'] ?? '') === $hash) {
                    $id = $savedId; $pin = $saved; break;
                }
            }
            // Existing stores proved their first spawn before native delivery IDs were retained.
            if ($first && self::initialRecord($record, $scope, $parentPath)
                && is_array($legacyPin) && ($legacyPin['record_sha256'] ?? '') === $hash
                && self::legacyDeliveryMatches($id, $legacyPin, $spec['native_agent_evidence'][$id ?? ''] ?? null)) {
                $source = self::sourceUnlocked($spec, $scope, $parentPath, $hash, $legacyPin);
                $pin = $legacyPin + ['wire_name'=>'collaboration__spawn_agent'];
            } else {
                if (!is_string($id)) self::unavailable();
                $evidence = $spec['native_agent_evidence'][$id] ?? null;
                $source = self::deliverySourceUnlocked($spec, $scope, $parentPath, $hash, $pin, $evidence, $previousOrder);
                $pin = ['task_id'=>$source['task_id'], 'call_id'=>$source['call_id'], 'wire_name'=>$source['wire_name'],
                    'message_sha256'=>hash('sha256', $source['message']), 'record_sha256'=>$hash];
                if ($source['wire_name'] === 'collaboration__spawn_agent' && empty($budget['native_subagent']['assignment'])) {
                    $budget['native_subagent']['assignment'] = $pin + ['native_message_id'=>$id];
                }
            }
            $order = SDB(null, 'medoo', 'sqlite')->table('gateway_tool_invocations')->where('task_id', $source['task_id'])
                ->where('call_id', $source['call_id'])->find('rowid');
            if (!is_array($order) || (int)$order['rowid'] <= $previousOrder) self::unavailable();
            $previousOrder = (int)$order['rowid'];
            $first = false;
            $copy = $record;
            $copy['content'] = str_replace(ContextHistory::OPAQUE_AGENT_NOTICE, $source['message'], $copy['content']);
            $replacements[$index] = $copy;
            if (is_string($id)) $pins[$id] = array_replace($pin, ['message_index'=>$index,
                'native_at'=>$spec['native_agent_evidence'][$id]['at'] ?? ($pin['native_at'] ?? null),
                'source_sha256'=>$logicalHash]);
        }
        $project = static function(array $context, bool $readable = true) use ($full, $replacements): array {
            $messages = (array)($context['messages'] ?? []);
            $indices = $context['original_context']['working_message_indices'] ?? null;
            if (!is_array($indices)) {
                $offset = count($full) - count($messages);
                $indices = $offset >= 0 ? array_keys(array_slice($full, $offset, null, true)) : [];
            }
            if (count($indices) !== count($messages)) self::unavailable();
            foreach ($messages as $i=>$record) {
                $at = $indices[$i];
                // A retained mailbox view can already contain a previous round's
                // proven readable assignment. Accept only this exact re-projection,
                // not arbitrary readable text in place of the canonical opaque row.
                // Native tool turn/time annotations can differ in a retained view.
                // Reuse the established tool-history comparison; actual call IDs,
                // parameters, results and every non-tool record remain exact.
                if (!isset($full[$at]) || (ContextHistory::nativeToolHistoryCanonical([$full[$at]]) !== ContextHistory::nativeToolHistoryCanonical([$record])
                    && (!isset($replacements[$at])
                        || ContextHistory::canonical($replacements[$at]) !== ContextHistory::canonical($record)))) self::unavailable();
                if (isset($replacements[$at])) $context['messages'][$i] = $readable ? $replacements[$at] : $full[$at];
            }
            return $context;
        };
        $beforeProjection = $physical;
        $physical = $project($physical);
        if (strpos($prompt, '<codex_context_json>') !== false) {
            $envelope = \support\browser\ChatGptTransport::contextEnvelope($prompt);
            // The prompt's semantic view merges native tool calls/results and may
            // omit old uploads. Compare that exact existing projection, not raw indices.
            $expected = ContextHistory::canonical($envelope['context']);
            $matched = false;
            $contractView = false;
            // The control prompt can use the canonical opaque rows while its
            // retained physical view already has proved readable rows. Both must
            // match an exact generated view of those same source records.
            foreach ([$beforeProjection, $project($beforeProjection, false)] as $before) {
                $semanticBefore = ContextHistory::semanticWebContext(AttachmentStore::transportContext($before));
                if ($expected === ContextHistory::canonical($semanticBefore)) { $matched = true; break; }
                // Tool contracts omit continuation metadata and superseded model
                // switches. Do not reinsert either into that existing envelope.
                $contractBefore = json_decode(WebContract::contextJson(ContextHistory::canonical($before)), true, 128, JSON_THROW_ON_ERROR);
                if ($expected === ContextHistory::canonical($contractBefore)) { $matched = true; $contractView = true; break; }
            }
            if (!$matched) self::unavailable();
            $semanticAfter = $contractView
                ? json_decode(WebContract::contextJson(ContextHistory::canonical($physical)), true, 128, JSON_THROW_ON_ERROR)
                : ContextHistory::semanticWebContext(AttachmentStore::transportContext($physical));
            $at = $envelope['start'] + strlen('<codex_context_json>');
            $prompt = substr($prompt, 0, $at) . "\n" . ContextHistory::canonical($semanticAfter)
                . "\n" . substr($prompt, $envelope['end']);
        }
        $counted = json_decode($budgetContext ?? (string)($spec['full_context_json'] ?? $spec['context_json']), true, 128, JSON_THROW_ON_ERROR);
        $countedJson = ContextHistory::canonical($project($counted));
        $physicalJson = ContextHistory::canonical($physical);
        $countedTokens = ContextBudget::tokens($countedJson, $budget);
        $tokens = max($contextTokens, $countedTokens,
            $physicalJson === $countedJson ? $countedTokens : ContextBudget::tokens($physicalJson, $budget));
        if (($spec['operation_kind'] ?? 'response') === 'response' && empty($spec['tool_outputs'])) ContextBudget::validateTokens($tokens, $budget, $attachmentTokens);
        $budget['native_subagent']['deliveries'] = $pins;
        return [$prompt, $physicalJson, $budget, ContextBudget::inputTokensFromCount($tokens, $budget, $attachmentTokens)];
    }

    /** Old stores can bind their initial source to a native ID only within that exact spawn's interval. */
    private static function legacyDeliveryMatches(?string $id, array $pin, mixed $evidence): bool
    {
        if ($id === null || ($pin['native_message_id'] ?? null) === $id) return true;
        if (!is_array($evidence) || ($evidence['record_sha256'] ?? '') !== $pin['record_sha256']) return false;
        $call = SDB(null, 'medoo', 'sqlite')->table('gateway_tool_invocations')->where('task_id', $pin['task_id'])
            ->where('call_id', $pin['call_id'])->where('wire_name', 'collaboration__spawn_agent')
            ->find('delivered_at,completed_at,status');
        return is_array($call) && $call['delivered_at'] !== null
            && (int)$call['delivered_at'] <= (int)ceil($evidence['at'])
            && ($call['status'] === 'delivered' || ($call['completed_at'] !== null
                && (int)$call['completed_at'] >= (int)floor($evidence['at'])));
    }

    /** Prove a unique parent's exact followup within its native delivery interval; never replay that call. */
    private static function deliverySourceUnlocked(array $spec, array $scope, string $parentPath, string $hash,
        mixed $pin, mixed $evidence, int $afterOrder): array
    {
        $db = SDB(null, 'medoo', 'sqlite');
        if (is_array($pin) && ($pin['record_sha256'] ?? '') === $hash) {
            $task = $db->table('gateway_tasks')->where('id', $pin['task_id'])->find('id,client_id,branch_id,thread_id');
            $wire = $pin['wire_name'] ?? '';
            if (!in_array($wire, ['collaboration__spawn_agent','collaboration__followup_task','collaboration__send_message'], true)) self::unavailable();
            $calls = is_array($task) ? $db->table('gateway_tool_invocations')->where('task_id', $task['id'])
                ->where('call_id', $pin['call_id'])->where('wire_name', $wire)->select('rowid,task_id,call_id,wire_name,status,arguments_json,result_json,delivered_at,completed_at') : [];
        } else {
            if (!is_array($evidence) || ($evidence['record_sha256'] ?? '') !== $hash || !is_numeric($evidence['at'] ?? null)) self::unavailable();
            $tasks = $db->table('gateway_tasks')->where('thread_id', $scope['parent_thread_id'])
                ->where('client_id', $spec['client_id'])->where('branch_id', $spec['branch_id'])->select('id,client_id,branch_id,thread_id');
            if (!is_array($tasks)) self::unavailable();
            $calls = [];
            foreach ($tasks as $task) foreach (['completed','delivered'] as $status) {
                $query = $db->table('gateway_tool_invocations')->where('task_id', $task['id'])
                    ->where('wire_name', 'IN', ['collaboration__spawn_agent','collaboration__followup_task','collaboration__send_message'])->where('status', $status)
                    ->where('rowid', '>', $afterOrder)
                    ->where('delivered_at', '<=', (int)ceil($evidence['at']));
                // Active-child messages can remain in Codex's inbox after send_message
                // returns. Use proven call order and uniqueness, not an invented delivery timeout.
                $found = $query->limit(129)->select('rowid,task_id,call_id,wire_name,status,arguments_json,result_json,delivered_at,completed_at');
                if (!is_array($found) || count($found) > 128) self::unavailable();
                array_push($calls, ...$found);
            }
            $task = null;
        }
        if (!is_array($calls)) self::unavailable();
        $matches = [];
        foreach ($calls as $call) {
            if ((int)$call['rowid'] <= $afterOrder) continue;
            $owner = $task ?? $db->table('gateway_tasks')->where('id', $call['task_id'])->find('id,client_id,branch_id,thread_id');
            if (!is_array($owner) || $owner['thread_id'] !== $scope['parent_thread_id']
                || $owner['client_id'] !== $spec['client_id'] || $owner['branch_id'] !== $spec['branch_id']) continue;
            $arguments = json_decode((string)$call['arguments_json'], true, 32);
            if ($call['wire_name'] === 'collaboration__spawn_agent') {
                if (!is_array($pin) && $call['status'] === 'completed'
                    && ($call['completed_at'] === null || (int)$call['completed_at'] < (int)floor($evidence['at']))) continue;
                $message = self::message($call, $scope, $parentPath);
                if ($message === null || (is_array($pin)
                    && !hash_equals((string)($pin['message_sha256'] ?? ''), hash('sha256', $message)))) continue;
                $matches[] = ['task_id'=>$call['task_id'], 'call_id'=>$call['call_id'],
                    'wire_name'=>$call['wire_name'], 'message'=>$message];
                continue;
            }
            $target = $arguments['target'] ?? null;
            if (!is_string($target) || !is_string($arguments['message'] ?? null) || trim($arguments['message']) === ''
                || !in_array($call['status'], ['completed','delivered'], true)) continue;
            $target = str_starts_with($target, '/') || CodexCancellation::uuid($target) ? $target : $parentPath . '/' . $target;
            if ($target !== $scope['thread_id'] && $target !== $scope['agent_path']) continue;
            if (is_array($pin) && !hash_equals((string)($pin['message_sha256'] ?? ''), hash('sha256', $arguments['message']))) continue;
            $matches[] = ['task_id'=>$call['task_id'], 'call_id'=>$call['call_id'],
                'wire_name'=>$call['wire_name'], 'message'=>$arguments['message']];
        }
        if (count($matches) !== 1) self::unavailable();
        return $matches[0];
    }

    /** Only the native initial-task wrapper with a missing private block is eligible; replies/followups stay intact. */
    private static function initialRecord(mixed $record, array $scope, string $parentPath): bool
    {
        return is_array($record) && ($record['role'] ?? '') === 'agent_message'
            && ($record['author'] ?? '') === $parentPath && ($record['recipient'] ?? '') === $scope['agent_path']
            && is_string($record['content'] ?? null)
            && str_starts_with($record['content'], "Message Type: NEW_TASK\nTask name: " . $scope['agent_path']
                . "\nSender: " . $parentPath . "\nPayload:\n")
            && str_contains($record['content'], ContextHistory::OPAQUE_AGENT_NOTICE);
    }

    /**
     * Match the exact native parent and unique issued spawn, using indexed task/call lookups.
     * A completed result must name this child; a delivered call may still be executing when
     * its child makes its first request. Neither case mutates or acknowledges the parent call.
     */
    private static function sourceUnlocked(array $spec, array $scope, string $parentPath, string $recordHash, mixed $pinned): array
    {
        $db = SDB(null, 'medoo', 'sqlite');
        if (is_array($pinned) && ($pinned['record_sha256'] ?? '') === $recordHash) {
            $task = $db->table('gateway_tasks')->where('id', (string) ($pinned['task_id'] ?? ''))->find('id,client_id,thread_id,branch_id');
            if (is_array($task) && $task['thread_id'] === $scope['parent_thread_id']
                && $task['client_id'] === $spec['client_id'] && $task['branch_id'] === $spec['branch_id']) {
                $call = $db->table('gateway_tool_invocations')->where('task_id', $task['id'])
                    ->where('call_id', (string) ($pinned['call_id'] ?? ''))->where('wire_name', 'collaboration__spawn_agent')->find();
                $message = is_array($call) ? self::message($call, $scope, $parentPath) : null;
                if ($message !== null && hash_equals((string) ($pinned['message_sha256'] ?? ''), hash('sha256', $message))) {
                    return ['task_id'=>$task['id'], 'call_id'=>$call['call_id'], 'message'=>$message];
                }
            }
            self::unavailable();
        }
        $owner = hash('sha256', json_encode(['kind'=>'thread','id'=>$scope['parent_thread_id']], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $base = 'task_' . substr(hash('sha256', json_encode(['client'=>$spec['client_id'], 'owner'=>$owner,
            'branch'=>$spec['branch_id']], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)), 0, 24);
        $link = $db->table('gateway_task_conversations')->where('base_task_id', $base)->find();
        $tasks = array_unique([$base, is_array($link) ? (string) $link['current_task_id'] : $base]);
        $matches = [];
        foreach ($tasks as $taskId) {
            $task = $db->table('gateway_tasks')->where('id', $taskId)->find('id,client_id,thread_id,branch_id');
            if (!is_array($task) || $task['thread_id'] !== $scope['parent_thread_id']
                || $task['client_id'] !== $spec['client_id'] || $task['branch_id'] !== $spec['branch_id']) continue;
            $calls = $db->table('gateway_tool_invocations')->where('task_id', $taskId)
                ->where('wire_name', 'collaboration__spawn_agent')->order([['rowid','DESC']])->limit(129)
                ->select('task_id,call_id,status,arguments_json,result_json');
            if (!is_array($calls) || count($calls) > 128) self::unavailable();
            foreach ($calls as $call) {
                $message = self::message($call, $scope, $parentPath);
                if ($message !== null) $matches[] = ['task_id'=>$taskId, 'call_id'=>$call['call_id'], 'message'=>$message];
            }
        }
        if (count($matches) !== 1) self::unavailable();
        return $matches[0];
    }

    /** Read an issued parent input; completed native errors or another child's result cannot prove this assignment. */
    private static function message(array $call, array $scope, string $parentPath): ?string
    {
        $arguments = json_decode((string) $call['arguments_json'], true, 32);
        if (!is_array($arguments) || !is_string($arguments['task_name'] ?? null)
            || $parentPath . '/' . $arguments['task_name'] !== $scope['agent_path']
            || !is_string($arguments['message'] ?? null) || trim($arguments['message']) === ''
            || !in_array($call['status'], ['delivered','completed'], true)) return null;
        if ($call['status'] === 'completed') {
            $result = json_decode((string) $call['result_json'], true, 32);
            $output = $result['output'] ?? null;
            if (is_string($output)) $output = json_decode($output, true, 32);
            if (!is_array($output) || ($output['task_name'] ?? '') !== $scope['agent_path']) return null;
        }
        return $arguments['message'];
    }

    /** Fail before any browser submission instead of running an agent with no actual assignment. */
    private static function unavailable(): never
    {
        throw new GatewayError('native_agent_assignment_unavailable',
            'The encrypted subagent assignment has no unique verified parent tool input. Provide a readable assignment; no Web message was sent.', 400);
    }
}
