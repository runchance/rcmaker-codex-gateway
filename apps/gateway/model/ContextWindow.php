<?php
declare(strict_types=1);
namespace app\gateway\model;

/** Bounded browser working context backed by the current request's immutable original history. */
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
        $physicalAttachmentTokens = AttachmentStore::tokenReserve(AttachmentStore::selectForContext([], $source));
        $reserve = $fixedReserve + $physicalAttachmentTokens;
        if ($verifiedSummary) {
            if (ContextBudget::tokens($source) + $logicalReserve <= (int)$budget['context_tokens']) return $spec;
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
        $working = (int)$budget['base_context_tokens'];
        if (ContextBudget::tokens($physicalSource) + $reserve <= $working) return $spec;
        if (($spec['operation_kind'] ?? 'response') !== 'response'
            && !empty($budget['context_extended'])
            && ContextBudget::tokens($source) + $logicalReserve <= (int)$budget['context_tokens']) {
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
        $used = ContextBudget::tokens(ContextHistory::canonical(['system'=>$physicalContext['system'], 'messages'=>[]]));
        // Leave room for bounded original-context queries and their results during this turn.
        $target = max(1024, (int)floor(($working - $reserve) * 0.65));
        foreach ($messages as $index => $message) {
            if (in_array($message['role'] ?? '', ['user','developer','system'], true)) {
                $kept[$index] = $message;
                $used += ContextBudget::tokens(ContextHistory::canonical($physicalMessages[$index])) + 4;
            }
        }
        if ($used > $target) {
            throw new GatewayError('context_length_exceeded', '用户原话和指令本身已超过安全工作预算，请先压缩；未截断当前请求或移除指令。', 400);
        }
        // Keep whole recent records. Tool calls/results are historical JSON, never live wire items.
        for ($index = count($messages) - 1; $index >= 0; $index--) {
            if (isset($kept[$index])) continue;
            $weight = ContextBudget::tokens(ContextHistory::canonical($physicalMessages[$index])) + 4;
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
        $projectedAttachmentTokens = AttachmentStore::tokenReserve(AttachmentStore::selectForContext([], $projected));
        $workingTokens = ContextBudget::tokens($physicalProjected) + $fixedReserve + $projectedAttachmentTokens;
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
}
