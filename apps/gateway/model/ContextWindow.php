<?php
declare(strict_types=1);
namespace app\gateway\model;

/** Bounded browser context and verified first-import history selection backed by original records. */
final class ContextWindow
{
    /**
     * Keep instructions and user messages verbatim; move old assistant/tool history to the existing
     * task-scoped reader before ChatGPT can silently evict it. No model summary replaces the source.
     * @param array $spec Normalized request, inside the existing reservation transaction.
     * @param int $attachmentTokens Logical history reserve; physical uploads are selected separately.
     * @return array Request with an optional bounded working projection and immutable archive flags.
     * @throws GatewayError If instructions alone cannot fit or original-context access is unavailable.
     */
    public static function prepare(array $spec, int $attachmentTokens): array
    {
        // Unsent recovery reconstructs from the durable archive. Reapply only a verified
        // import selection; ordinary compaction and established task windows stay unchanged.
        $spec = self::selectImportedHistory($spec, false);
        if (isset($spec['imported_budget_context_json'])) return $spec;
        $budget = $spec['budget'];
        if ((int)($budget['version'] ?? 0) < 5 || !empty($spec['tool_outputs'])) return $spec;
        $verifiedSummary = ($spec['operation_kind'] ?? 'response') !== 'response'
            && !empty($spec['verified_compaction_source']);
        $gpt6Capacity = (int)($budget['version'] ?? 0) >= 7 && ($budget['model_family'] ?? null) === '6';
        if ($verifiedSummary && !$gpt6Capacity) return $spec;
        $original = (string)($spec['full_context_json'] ?? $spec['context_json']);
        $source = (string)($spec['transport_context_json'] ?? $original);
        $fixedReserve = (int)$budget['contract_reserve_tokens'] + (int)$budget['output_reserve_tokens'];
        $logicalReserve = $fixedReserve + $attachmentTokens;
        // The resident logical history may contain dozens of tool screenshots, but a rebuilt
        // message uploads only its selected files. Count omitted-image notices and inline skill
        // fallbacks as physical text without rewriting the canonical history or receipt evidence.
        $physicalSource = AttachmentStore::transportJson($source);
        $physicalAttachmentTokens = AttachmentStore::tokenReserve(AttachmentStore::selectForContext([], $source), $budget);
        $reserve = $fixedReserve + $physicalAttachmentTokens;
        if ($verifiedSummary) {
            if (ContextBudget::tokens($source, $budget) + $logicalReserve <= (int)$budget['context_tokens']) return $spec;
            // An exact source page proves provenance, not retention after Pro
            // changes to High for the summary. Reuse its immutable source through
            // the existing bounded reader on a fresh page when High cannot hold it.
            $budget = ContextBudget::forCompactionTransport($budget);
            $spec['budget'] = $budget;
            $spec['verified_compaction_source'] = false;
            $spec['continuation_source_response_id'] = null;
            $spec['prompt'] = ContextCompaction::prompt($source);
            $spec['browser_lease_key'] = hash('sha256', $spec['browser_lease_key'] . '|summary-capacity|' . hash('sha256', $original));
        }
        $luna = (int)($budget['version'] ?? 0) >= 8
            && in_array((string)($budget['model'] ?? ''), ['chatgpt-web/luna','chatgpt-web/think'], true);
        $working = $luna ? (int)$budget['browser_context_tokens'] : (int)$budget['base_context_tokens'];
        if (ContextBudget::tokens($physicalSource, $budget) + $reserve <= $working) return $spec;
        if (($spec['operation_kind'] ?? 'response') !== 'response'
            && !empty($budget['context_extended'])
            && ContextBudget::tokens($source, $budget) + $logicalReserve <= (int)$budget['context_tokens']) {
            // A checkpoint is read-only. When its complete source fits the existing staged
            // transaction, send every tool receipt instead of replacing them with a
            // bounded view that then requires a separate context-reader tool call.
            try {
                \support\browser\ChatGptTransport::plan(
                    ContextCompaction::prompt($source), $budget, $budget,
                    ['sol_available' => true], null, $physicalAttachmentTokens);
                return $spec;
            } catch (GatewayError $error) {
                if ($error->errorCode !== 'CHATGPT_CONTEXT_TOO_LARGE') throw $error;
                // An indivisible record may still exceed a physical stage. The original
                // source remains available through the existing bounded reader below.
            }
        }
        // A standard-window GPT-6 checkpoint may still use the existing original
        // reader instead of pretending that six uploads enlarge model capacity.
        // Normal responses retain their established extension admission rule.
        $compactionTransport = ($spec['operation_kind'] ?? 'response') !== 'response'
            && !empty($budget['compaction_transport']);
        if ((empty($budget['context_extended']) && !$compactionTransport)
            || empty(GatewaySettings::internal()['mcp_enabled'])) {
            throw new GatewayError('context_length_exceeded', '上下文超过网页工作窗口，需要先压缩；原文读取不可用时不会继续发送并丢失早期内容。', 400);
        }
        $environment = json_decode((string)$spec['tool_environment_json'], true, 128, JSON_THROW_ON_ERROR);
        // A tool-capable development turn needs its actual prior tool receipts. The 3x
        // transport already partitions complete history into bounded Medium stages; clipping
        // here discards that evidence and also breaks exact-prefix reuse of a retained page.
        if (($spec['tool_choice'] ?? 'auto') !== 'none' && !empty($environment['tools'])) return $spec;
        foreach ($environment['tools'] ?? [] as $tool) {
            if (($tool['wire_name'] ?? '') === TaskContext::WIRE_NAME) {
                throw new GatewayError('context_length_exceeded', '原文读取名称与宿主工具冲突，请先压缩上下文。', 400);
            }
        }
        $context = json_decode($source, true, 128, JSON_THROW_ON_ERROR);
        $physicalContext = json_decode($physicalSource, true, 128, JSON_THROW_ON_ERROR);
        $messages = $context['messages'];
        $physicalMessages = $physicalContext['messages'];
        $kept = [];
        $used = ContextBudget::tokens(ContextHistory::canonical(['system'=>$physicalContext['system'], 'messages'=>[]]), $budget);
        // Leave room for bounded original-context queries and their results during this turn.
        $target = max(1024, (int)floor(($working - $reserve) * 0.65));
        foreach ($messages as $index => $message) {
            if (in_array($message['role'] ?? '', ['user','developer','system'], true)) {
                $kept[$index] = $message;
                $used += ContextBudget::tokens(ContextHistory::canonical($physicalMessages[$index]), $budget) + 4;
            }
        }
        if ($used > $target) {
            throw new GatewayError('context_length_exceeded', '用户原话和指令本身已超过安全工作预算，请先压缩；未截断当前请求或移除指令。', 400);
        }
        // Keep whole recent records. Tool calls/results are historical JSON, never live wire items.
        for ($index = count($messages) - 1; $index >= 0; $index--) {
            if (isset($kept[$index])) continue;
            $weight = ContextBudget::tokens(ContextHistory::canonical($physicalMessages[$index]), $budget) + 4;
            if ($used + $weight > $target) continue;
            $kept[$index] = $messages[$index];
            $used += $weight;
        }
        ksort($kept);
        $context['messages'] = array_values($kept);
        unset($context['continuation']);
        $context['original_context'] = [
            'source'=>'current_task_original_context', 'sha256'=>hash('sha256', $original),
            'total_messages'=>count($messages), 'working_message_indices'=>array_keys($kept),
            'reader'=>TaskContext::WIRE_NAME,
            'notice'=>'Older assistant/tool records remain verbatim in the original context. Read relevant archived facts before answering or acting; absence from this working view is not absence from the task.',
        ];
        $projected = ContextHistory::canonical($context);
        // Removing archived records can change which ten files are selected. Recount the actual
        // final projection, including newly-inline skill text, rather than reusing the old reserve.
        $physicalProjected = AttachmentStore::transportJson($projected);
        $projectedAttachmentTokens = AttachmentStore::tokenReserve(AttachmentStore::selectForContext([], $projected), $budget);
        $workingTokens = ContextBudget::tokens($physicalProjected, $budget) + $fixedReserve + $projectedAttachmentTokens;
        if ($workingTokens > $working) {
            throw new GatewayError('context_length_exceeded', '保留指令后的工作上下文仍超出预算，未丢弃原文。', 400);
        }
        $spec['full_context_json'] = $original;
        $spec['context_json'] = $spec['transport_context_json'] = $projected;
        $spec['prompt'] = ($spec['operation_kind'] ?? 'response') === 'response'
            ? ContextHistory::plainPrompt($context)
            : ContextCompaction::prompt($projected, false, true);
        $spec['continuation_source_response_id'] = null;
        $spec['browser_lease_key'] = hash('sha256', $spec['browser_lease_key'].'|working-context|'.hash('sha256', $projected));
        $spec['budget']['original_context_required'] = true;
        $spec['budget']['working_context_tokens'] = $workingTokens;
        $spec['budget']['original_context_sha256'] = hash('sha256', $original);
        return $spec;
    }

    /**
     * Admit an oversized first readable import by retaining a complete recent suffix and all
     * current instructions. The original archive remains immutable; later requests reuse the
     * same selection only after proving its complete initial prefix, never dropping new work.
     * @param array $spec Prepared request with its pinned model budget and complete readable history.
     * @param bool $noGatewayHistory Caller proved this task has no local history or live epoch.
     * @param ?array $prior Owned local response carrying the persisted selection and original archive.
     * @return array Request with selected transport and imported_budget_context_json for logical usage.
     * @throws GatewayError If the current request/instructions or their atomic tool block cannot fit.
     */
    public static function selectImportedHistory(array $spec, bool $noGatewayHistory, ?array $prior = null): array
    {
        $budget = (array) ($spec['budget'] ?? []);
        if ((int) ($budget['version'] ?? 0) < 5 || ($spec['operation_kind'] ?? 'response') !== 'response'
            || !empty($spec['rolling_source_response_id'])) return $spec;
        $original = (string) ($spec['full_context_json'] ?? $spec['context_json'] ?? '');
        $context = json_decode($original, true, 128, JSON_THROW_ON_ERROR);
        if (!is_array($context) || !is_array($context['messages'] ?? null) || !is_array($context['system'] ?? null)) return $spec;
        $messages = $context['messages'];
        $priorBudget = is_array($prior) ? json_decode((string) ($prior['budget_json'] ?? '{}'), true, 128, JSON_THROW_ON_ERROR) : [];
        $selection = $priorBudget['imported_history_selection'] ?? $budget['imported_history_selection'] ?? null;
        $receiptOrSteering = (array) ($spec['tool_outputs'] ?? []) !== [] || !empty($spec['steering_restart'])
            || (array) ($spec['steering_messages'] ?? []) !== [] || !empty($spec['operational_context_source_response_id']);
        $reader = self::importReaderAvailable($spec);

        if (is_array($selection)) {
            $alreadySelected = isset($spec['imported_budget_context_json']);
            if (!self::matchesImportSelection($context, $selection)) return $spec;
            if (is_array($prior)) {
                $priorContext = json_decode((string) ($prior['context_json'] ?? ''), true, 128, JSON_THROW_ON_ERROR);
                if (!is_array($priorContext) || !self::matchesImportSelection($priorContext, $selection)) return $spec;
            }
            $indices = $selection['selected_message_indices'];
            if (count($messages) > $selection['source_message_count']) {
                $indices = array_merge($indices, range($selection['source_message_count'], count($messages) - 1));
            }
            $projected = self::importProjection($context, $indices, hash('sha256', $original), $reader);
            $spec['imported_budget_context_json'] = ContextHistory::canonical($projected);
            // A real host receipt already extends its proven selected transport. Restore usage
            // only; changing this prompt, source or budget would invalidate the active capability.
            if ($receiptOrSteering) return $spec;
            $physical = json_decode((string) ($spec['context_json'] ?? $original), true, 128, JSON_THROW_ON_ERROR);
            $exactPrefix = ($physical['continuation']['mode'] ?? null) === 'exact_prefix';
            $spec['budget']['imported_history_selection'] = $selection;
            $spec['budget']['imported_original_context_available'] = $reader;
            $spec = self::importTransportBudget($spec,
                $exactPrefix ? (string)$spec['context_json'] : $spec['imported_budget_context_json'],
                is_array($prior) && !$alreadySelected);
            if ($exactPrefix) return $spec;
            $spec['context_json'] = $spec['transport_context_json'] = $spec['imported_budget_context_json'];
            $spec['prompt'] = ContextHistory::plainPrompt($projected);
            $spec['continuation_source_response_id'] = null;
            return $spec;
        }

        if (!$noGatewayHistory || $receiptOrSteering || !empty($spec['input_is_delta'])
            || !empty($spec['previous_response_id']) || !empty($spec['continuation_source_response_id'])
            || !empty($spec['verified_compaction_source']) || isset($context['continuation'])) return $spec;
        $reserve = (int) $budget['contract_reserve_tokens'] + (int) $budget['output_reserve_tokens'];
        $capacity = min((int)$budget['context_tokens'], (int)($budget['browser_context_tokens'] ?? $budget['context_tokens'])) - $reserve;
        $originalAttachments = AttachmentStore::selectForContext((array) ($spec['attachments'] ?? []), $original, false);
        if (ContextBudget::tokens($original, $budget) + AttachmentStore::tokenReserve($originalAttachments, $budget) <= $capacity) return $spec;
        $protected = self::importProtectedIndices($messages);
        if ($protected === []) return $spec;
        [$blocks, $unsafe] = self::importAtomicBlocks($messages);
        $mandatory = [];
        $firstSafeSuffix = 0;
        foreach ($blocks as $number => [$start, $end]) {
            $required = false;
            for ($index = $start; $index <= $end; $index++) {
                $required = $required || isset($protected[$index]);
                if (isset($unsafe[$index])) $firstSafeSuffix = $number + 1;
            }
            if (!$required) continue;
            for ($index = $start; $index <= $end; $index++) {
                if (isset($unsafe[$index])) {
                    throw new GatewayError('context_length_exceeded', '当前需求关联的工具记录缺少完整调用或回执，无法安全选择历史。', 400);
                }
                $mandatory[$index] = true;
            }
        }
        $project = static function (int $suffix) use ($context, $blocks, $mandatory, $original, $reader): array {
            $kept = $mandatory;
            for ($block = $suffix, $total = count($blocks); $block < $total; $block++) {
                for ($index = $blocks[$block][0]; $index <= $blocks[$block][1]; $index++) $kept[$index] = true;
            }
            ksort($kept);
            return self::importProjection($context, array_keys($kept), hash('sha256', $original), $reader);
        };
        $previewSpec = $spec;
        $previewSpec['budget']['imported_original_context_available'] = $reader;
        // This temporary marker scopes the planner's internal ID; the final persisted
        // selection below records the actual complete source and selected indices.
        $previewSpec['budget']['imported_history_selection'] = ['version'=>1];
        $previewSpec['budget']['imported_transport_transaction_id'] = 'ctx_' . bin2hex(random_bytes(16));
        $previewToken = ToolBroker::previewPromptForBudget($previewSpec,
            ContextHistory::canonical($project(count($blocks))))['turn_token'];
        $fits = static function (array $projection) use ($capacity, $spec, $previewSpec, $previewToken): bool {
            $json = ContextHistory::canonical($projection);
            if (ContextBudget::tokens($json, $spec['budget']) + AttachmentStore::tokenReserve(
                AttachmentStore::selectForContext((array) ($spec['attachments'] ?? []), $json, false), $spec['budget']) > $capacity) return false;
            $budget = $previewSpec['budget'];
            try {
                $preview = ToolBroker::previewPromptForBudget($previewSpec, $json, $previewToken);
                \support\browser\ChatGptTransport::plan($preview['prompt'], $budget, $budget,
                    ['sol_available' => !empty($budget['sol_available']),
                        'pro_supported' => !empty($budget['observed_pro_capacity'])], null,
                    AttachmentStore::tokenReserve(AttachmentStore::selectForContext(
                        (array) ($spec['attachments'] ?? []), $json), $budget));
                return true;
            } catch (GatewayError $error) {
                if (!in_array($error->errorCode, ['CHATGPT_CONTEXT_TOO_LARGE', 'CHATGPT_MULTIPART_UNAVAILABLE'], true)) throw $error;
                return false;
            }
        };
        if (!$fits($project(count($blocks)))) {
            throw new GatewayError('context_length_exceeded', '当前需求、附件和系统/开发者指令本身已超过预算；未截断正文或移除指令。', 400);
        }
        // Find the earliest fitting atomic boundary, including the existing physical planner,
        // instead of recounting every historical prefix or changing the account's stage modes.
        $low = $firstSafeSuffix;
        $high = count($blocks);
        while ($low < $high) {
            $middle = intdiv($low + $high, 2);
            if ($fits($project($middle))) $high = $middle;
            else $low = $middle + 1;
        }
        $projected = $project($low);
        $selection = ['version' => 1, 'source_sha256' => hash('sha256', ContextHistory::canonical($context)),
            'source_message_count' => count($messages),
            'selected_message_indices' => $projected['original_context']['working_message_indices']];
        $spec['budget']['imported_history_selection'] = $selection;
        $spec['budget']['imported_original_context_available'] = $reader;
        $spec['budget']['imported_transport_transaction_id'] = $previewSpec['budget']['imported_transport_transaction_id'];
        if ($previewToken !== null) $spec['imported_prompt_turn_token'] = $previewToken;
        $spec['full_context_json'] = $original;
        $spec['context_json'] = $spec['transport_context_json'] = $spec['imported_budget_context_json'] = ContextHistory::canonical($projected);
        $spec['budget']['imported_transport_context_sha256'] = hash('sha256', $spec['context_json']);
        $spec['prompt'] = ContextHistory::plainPrompt($projected);
        $spec['continuation_source_response_id'] = null;
        $spec['browser_lease_key'] = hash('sha256', (string) ($spec['browser_lease_key'] ?? '') . '|imported-history|' . hash('sha256', $spec['context_json']));
        return $spec;
    }

    /** Verify the fixed initial prefix and every selected index before restoring a local projection. */
    private static function matchesImportSelection(array $context, array $selection): bool
    {
        $count = $selection['source_message_count'] ?? null;
        $indices = $selection['selected_message_indices'] ?? null;
        if (($selection['version'] ?? null) !== 1 || !is_int($count) || $count < 1
            || !is_array($context['messages'] ?? null) || count($context['messages']) < $count
            || !is_array($indices) || !array_is_list($indices) || $indices === []
            || !is_string($selection['source_sha256'] ?? null)) return false;
        $last = -1;
        foreach ($indices as $index) {
            if (!is_int($index) || $index <= $last || $index >= $count) return false;
            $last = $index;
        }
        $prefix = $context;
        $prefix['messages'] = array_slice($context['messages'], 0, $count);
        unset($prefix['continuation']);
        return hash_equals($selection['source_sha256'], hash('sha256', ContextHistory::canonical($prefix)));
    }

    /** Pin one physical import transaction, refreshing it for a changed projection or a new round. */
    private static function importTransportBudget(array $spec, string $physicalContext, bool $freshRound = false): array
    {
        $hash = hash('sha256', $physicalContext);
        if ($freshRound || !isset($spec['budget']['imported_transport_transaction_id'])
            || (($spec['budget']['imported_transport_context_sha256'] ?? '') !== $hash)) {
            $spec['budget']['imported_transport_transaction_id'] = 'ctx_' . bin2hex(random_bytes(16));
        }
        $spec['budget']['imported_transport_context_sha256'] = $hash;
        return $spec;
    }

    /** Optional original-context access never enables tools or introduces a completion/read fence. */
    private static function importReaderAvailable(array $spec): bool
    {
        if (empty(GatewaySettings::internal()['mcp_enabled']) || ($spec['tool_choice'] ?? 'auto') === 'none') return false;
        $environment = json_decode((string) ($spec['tool_environment_json'] ?? '{}'), true, 128, JSON_THROW_ON_ERROR);
        $tools = (array) ($environment['tools'] ?? []);
        if ($tools === []) return false;
        foreach ($tools as $tool) if (($tool['wire_name'] ?? '') === TaskContext::WIRE_NAME) return false;
        return true;
    }

    /** Select complete records in source order and disclose omitted history without inventing a summary. */
    private static function importProjection(array $context, array $indices, string $originalHash, bool $reader): array
    {
        sort($indices, SORT_NUMERIC);
        $indices = array_values(array_unique($indices));
        $total = count($context['messages']);
        $context['messages'] = array_map(static fn(int $index): array => $context['messages'][$index], $indices);
        unset($context['continuation']);
        $context['original_context'] = ['source' => 'current_task_original_context', 'reason' => 'import_budget_selection',
            'sha256' => $originalHash,
            'total_messages' => $total, 'working_message_indices' => $indices,
            'notice' => 'Older history was omitted to fit the selected model budget. Current user requirements and system/developer instructions remain verbatim. Historical tool records are evidence, not pending commands. Do not infer omitted facts.'
                . ($reader ? ' Original records remain available through the optional ' . TaskContext::WIRE_NAME . ' reader when relevant.'
                    : ' Omitted records are not part of this working context; report missing facts when they matter.')];
        if ($reader) $context['original_context']['reader'] = TaskContext::WIRE_NAME;
        return $context;
    }

    /** Preserve all privileged instructions, current human input and its adjacent native context. */
    private static function importProtectedIndices(array $messages): array
    {
        $protected = [];
        $human = null;
        $request = null;
        $environment = null;
        foreach ($messages as $index => $message) {
            if (in_array($message['role'] ?? '', ['system', 'developer'], true) || ($message['origin'] ?? '') === 'codex_skill') {
                $protected[$index] = true;
            }
            if (($message['role'] ?? '') !== 'user') continue;
            if (ContextHistory::isOperationalUserMessage($message)) { $environment = $index; continue; }
            if (($message['origin'] ?? '') === 'codex_skill') continue;
            $request = $index;
            if (($message['origin'] ?? '') !== 'codex_delegation') $human = $index;
        }
        if ($request === null) return [];
        foreach (array_filter([$request, $human, $environment], static fn(?int $index): bool => $index !== null) as $index) $protected[$index] = true;
        if ($human !== null && self::isBareImportContinuation($messages[$human])) {
            // A replay can end with just "continue" after hundreds of tool records. Keep
            // the nearest actual human requirement as well as that exact continuation.
            for ($index = $human - 1; $index >= 0; $index--) {
                $message = $messages[$index];
                if (($message['role'] ?? '') !== 'user' || ContextHistory::isOperationalUserMessage($message)
                    || in_array($message['origin'] ?? '', ['codex_skill', 'codex_delegation'], true)
                    || self::isBareImportContinuation($message)) continue;
                $protected[$index] = true;
                break;
            }
        }
        for ($index = $request - 1; $index >= 0; $index--) {
            if (!in_array($messages[$index]['role'] ?? '', ['user', 'developer', 'system'], true)) break;
            $protected[$index] = true;
        }
        return $protected;
    }

    /** Recognize only explicit continuation-only text; never interpret or rewrite a substantive request. */
    private static function isBareImportContinuation(array $message): bool
    {
        try { $text = ContextHistory::text($message['content'] ?? ''); }
        catch (GatewayError) { return false; }
        return preg_match('/\A\s*(?:请?继续(?:吧|执行|处理|完成)?|continue(?:\s+please)?|please\s+continue|resume)\s*[.!。！]?\s*\z/iu', $text) === 1;
    }

    /**
     * Merge overlapping call/result intervals, including parallel calls and adjacent public reasoning.
     * Unpaired native records form barriers to retained history; ordinary delegation is not a receipt.
     * @return array{array<array{int,int}>,array<int,bool>} Atomic blocks and unsafe native record indices.
     */
    private static function importAtomicBlocks(array $messages): array
    {
        $calls = [];
        $unsafe = [];
        foreach ($messages as $index => $message) {
            $type = $message['type'] ?? '';
            $call = in_array($type, ['function_call', 'custom_tool_call', 'tool_search_call'], true);
            $output = in_array($type, ['function_call_output', 'custom_tool_call_output', 'tool_search_output'], true);
            if (!$call && !$output) continue;
            $id = $message['call_id'] ?? null;
            if (!is_string($id) || $id === '') { $unsafe[$index] = true; continue; }
            $calls[$id][$call ? 'calls' : 'outputs'][] = $index;
        }
        $ranges = [];
        foreach ($calls as $records) {
            $indices = array_merge($records['calls'] ?? [], $records['outputs'] ?? []);
            if (count($records['calls'] ?? []) !== 1 || count($records['outputs'] ?? []) !== 1
                || $records['calls'][0] > $records['outputs'][0]
                || (['function_call' => 'function_call_output', 'custom_tool_call' => 'custom_tool_call_output',
                    'tool_search_call' => 'tool_search_output'][$messages[$records['calls'][0]]['type']] ?? null)
                    !== $messages[$records['outputs'][0]]['type']) {
                foreach ($indices as $index) $unsafe[$index] = true;
                continue;
            }
            $start = $records['calls'][0];
            while ($start > 0 && ($messages[$start - 1]['type'] ?? '') === 'reasoning_summary') $start--;
            $ranges[] = [$start, $records['outputs'][0]];
        }
        usort($ranges, static fn(array $left, array $right): int => $left[0] <=> $right[0]);
        $merged = [];
        foreach ($ranges as [$start, $end]) {
            $last = array_key_last($merged);
            if ($last !== null && $start <= $merged[$last][1]) $merged[$last][1] = max($end, $merged[$last][1]);
            else $merged[] = [$start, $end];
        }
        $blocks = [];
        $range = 0;
        for ($index = 0, $total = count($messages); $index < $total; $index++) {
            if (isset($merged[$range]) && $merged[$range][0] === $index) {
                $blocks[] = $merged[$range];
                $index = $merged[$range++][1];
            } else $blocks[] = [$index, $index];
        }
        return [$blocks, $unsafe];
    }
}
