<?php
declare(strict_types=1);
namespace app\gateway\model;

/**
 * Route verified native async-question answers without changing ordinary user steering.
 * Uses the current task's exact invocation ledger and existing receipt/page transports.
 */
final class QuestionReply
{
    private const TOOLS = ['request_user_input_async', 'functions__request_user_input_async'];

    /**
     * Select an outstanding native receipt when it can carry the complete answer suffix.
     * Called inside response reservation, after history/ownership validation and before steering.
     * @param array $spec Normalized native request; never trusts a client-supplied routing flag.
     * @return array Request preserving canonical history and accepted native calls.
     * @throws GatewayError On an unavailable task ledger.
     */
    public static function prepareUnlocked(array $spec): array
    {
        unset($spec['question_reply_messages'], $spec['question_reply_call_id']);
        if (($spec['operation_kind'] ?? 'response') !== 'response') return $spec;
        $messages = (array) ($spec['steering_messages'] ?? []);
        $taskId = 'task_' . substr((string) $spec['task_key'], 0, 24);
        if (self::verifiedUnlocked($taskId, $messages, (array) ($spec['tool_outputs'] ?? [])) === null) return $spec;

        // A question answer is independent of the preference for full-context interjections.
        // Existing destination proofs must still succeed before a short page message is used.
        $spec['steering_full_context'] = false;
        if (empty($spec['tool_outputs']) || empty($spec['previous_response_id'])) return $spec;
        $db = SDB(null, 'medoo', 'sqlite');
        $source = $db->table('gateway_response_jobs')->where('id', $spec['previous_response_id'])
            ->where('task_id', $taskId)->find('id,mcp_binding_id,durable_turn_id,round_kind');
        if (!is_array($source) || empty($source['mcp_binding_id']) || $source['round_kind'] !== 'tool_call') return $spec;
        $binding = $db->table('gateway_mcp_bindings')->where('id', $source['mcp_binding_id'])
            ->find('task_id,status,current_response_id,turn_id,expires_at');
        $turn = $db->table('gateway_task_turns')->where('id', $source['durable_turn_id'])
            ->find('id,response_id,status,error_code');
        if (!is_array($binding) || !is_array($turn) || $binding['task_id'] !== $taskId
            || $binding['status'] !== 'running' || $binding['current_response_id'] !== $source['id']
            || $binding['turn_id'] !== $turn['id'] || $turn['response_id'] !== $source['id']
            || !in_array($turn['status'], ['submitted', 'running'], true) || !empty($turn['error_code'])
            || (int) $binding['expires_at'] <= time()) return $spec;
        foreach ($spec['tool_outputs'] as $output) {
            $call = $db->table('gateway_tool_invocations')->where('binding_id', $source['mcp_binding_id'])
                ->where('response_id', $source['id'])->where('call_id', $output['call_id'])
                ->find('call_id,status,result_delivered_at,binding_id,call_key');
            // Never amend an already flushed receipt, and never invent a replacement call.
            if (!is_array($call) || $call['status'] !== 'delivered' || $call['result_delivered_at'] !== null
                || !ToolBroker::hasReceiptActivityUnlocked($call)) continue;
            $spec['question_reply_messages'] = $messages;
            $spec['question_reply_call_id'] = $call['call_id'];
            $spec['steering_messages'] = [];
            unset($spec['steering_restart']);
            break;
        }
        return $spec;
    }

    /**
     * Prove every human record is an answer to an issued question in this exact task.
     * Unknown/mixed/quoted envelopes remain ordinary input; no external identity is adopted.
     * @param string $taskId Authorized task including branch identity.
     * @param array $messages Only the new human suffix, never the full transcript.
     * @param array $outputs Current exact native receipts, which may acknowledge the question now.
     * @return array|null Unique question answers, or null when the dedicated path is inapplicable.
     * @throws GatewayError On a ledger read failure; malformed envelopes simply do not qualify.
     */
    public static function verifiedUnlocked(string $taskId, array $messages, array $outputs = []): ?array
    {
        if ($messages === [] || count($messages) > 64) return null;
        $answers = [];
        $calls = [];
        $itemCount = 0;
        foreach ($messages as $message) {
            if (($message['role'] ?? '') !== 'user' || ContextHistory::isOperationalUserMessage($message)) return null;
            $content = $message['content'] ?? '';
            if (is_array($content)) {
                foreach ($content as $part) {
                    if (!is_array($part) || !in_array($part['type'] ?? '', ['text', 'input_text'], true)
                        || !is_string($part['text'] ?? null)) return null;
                }
            } elseif (!is_string($content)) return null;
            $text = trim(ContextHistory::text($content));
            $open = '<send_user_message_question_reply>';
            $close = '</send_user_message_question_reply>';
            if (!str_starts_with($text, $open) || !str_ends_with($text, $close)) return null;
            try {
                $items = json_decode(substr($text, strlen($open), -strlen($close)), true, 32, JSON_THROW_ON_ERROR);
            } catch (\JsonException) { return null; }
            if (!is_array($items) || !array_is_list($items) || $items === [] || count($items) > 64) return null;
            foreach ($items as $item) {
                if (++$itemCount > 64) return null;
                if (!is_array($item) || array_diff(array_keys($item), ['questionItemId', 'question', 'answer']) !== []) return null;
                $valid = validator()->input($item, [
                    'questionItemId' => ['rule'=>'string', 'len'=>[1,512]],
                    'question' => ['rule'=>'string', 'len'=>[1,65536]],
                    'answer' => ['rule'=>'string', 'len'=>[0,1048576]],
                ], static function ($field, $failure): void {});
                if (!is_array($valid) || count($valid) !== 3) return null;
                foreach (['questionItemId', 'question', 'answer'] as $field) {
                    if (!is_string($item[$field] ?? null)) return null;
                }
                try { $identity = json_decode($item['questionItemId'], true, 8, JSON_THROW_ON_ERROR); }
                catch (\JsonException) { return null; }
                if (!is_array($identity) || !array_is_list($identity) || count($identity) !== 3
                    || !in_array($identity[0], self::TOOLS, true) || !is_string($identity[1])
                    || $identity[1] === '' || !is_int($identity[2]) || $identity[2] < 0) return null;
                if (!isset($calls[$identity[1]])) {
                    $rows = SDB(null, 'medoo', 'sqlite')->table('gateway_tool_invocations')
                        ->where('task_id', $taskId)->where('call_id', $identity[1])->limit(2)
                        ->select('call_id,wire_name,status,arguments_json,result_json');
                    if (!is_array($rows)) throw new GatewayError('storage_unavailable', '工具调用状态暂不可读取。', 503);
                    if (count($rows) !== 1) return null;
                    $calls[$identity[1]] = $rows[0];
                }
                $call = $calls[$identity[1]];
                if (!in_array($call['wire_name'], self::TOOLS, true)
                    || !in_array($call['status'], ['delivered', 'completed'], true)) return null;
                $arguments = json_decode((string) $call['arguments_json'], true);
                $title = $arguments['questions'][$identity[2]]['title'] ?? null;
                if (!is_string($title)) return null;
                // Native CLI bounds the displayed question to 512 UTF-8 bytes; desktop may keep it whole.
                $short = str_replace(["\r", "\n"], ' ', mb_strcut($title, 0, 512, 'UTF-8'));
                if ($item['question'] !== $title && $item['question'] !== $short) return null;
                $receipt = json_decode((string) ($call['result_json'] ?? ''), true);
                $ack = $receipt['output'] ?? null;
                foreach ($outputs as $output) {
                    if (($output['call_id'] ?? null) === $call['call_id']) $ack = $output['output'] ?? null;
                }
                if (!is_string($ack) || (json_decode($ack, true)['accepted'] ?? null) !== true) return null;
                $key = ContextHistory::canonical([$identity[1], $identity[2]]);
                if (isset($answers[$key]) && $answers[$key]['answer'] !== $item['answer']) return null;
                $answers[$key] = $item;
            }
        }
        return array_values($answers);
    }

    /** Small role-preserving notice shared by MCP delivery and retained-page fallback. */
    public static function notice(): string
    {
        return 'The human answered your earlier question(s), identified by questionItemId below. '
            . 'Read the actual answers, briefly acknowledge their meaning publicly before new tools, and apply them within the authorized task and approval scope. '
            . 'An answer may decline, stop or change the task; respect that intent. Do not infer approval from the question acknowledgement or a suggested option. '
            . 'Reuse completed work and exact receipts; wait for existing pending calls instead of reissuing them. '
            . 'Do not reapply an already handled answer or repeat completed actions if this delivery is replayed. '
            . 'Continue only the work authorized by the answer and current task state; after a manual stop do not resume unrelated unfinished work. '
            . 'Use the existing task history, retrieving missing facts only when needed, and give brief public progress during continued work.';
    }

    /**
     * Render an answer-only message only after the existing same-page proof has survived reservation.
     * @param array $spec Prepared request with exact transport suffix and canonical recovery history.
     * @param array $bridge Newly prepared capability; receipt continuations never submit a prompt.
     * @return string|null Short prompt, custom-template-preserving variant, or normal-path fallback.
     * @throws GatewayError On unavailable question ownership evidence.
     */
    public static function pagePromptUnlocked(array $spec, array $bridge): ?string
    {
        if (!empty($bridge['is_continuation']) || empty($bridge['token'])
            || ($spec['operation_kind'] ?? 'response') !== 'response'
            || !empty($spec['budget']['original_context_required'])) return null;
        $transport = (string) ($spec['transport_context_json'] ?? $spec['context_json']);
        // Ordinary initial/follow-up requests should not decode another full transcript
        // merely to discover that no native question-answer envelope is present.
        if (!str_contains($transport, '<send_user_message_question_reply>')) return null;
        $context = json_decode($transport, true, 128, JSON_THROW_ON_ERROR);
        if (($context['continuation']['mode'] ?? '') !== 'exact_prefix') return null;
        $humans = array_values(array_filter((array) ($context['messages'] ?? []), static fn(array $message): bool =>
            ($message['role'] ?? '') === 'user' && !ContextHistory::isOperationalUserMessage($message)));
        $taskId = 'task_' . substr((string) $spec['task_key'], 0, 24);
        if (self::verifiedUnlocked($taskId, $humans, (array) ($spec['tool_outputs'] ?? [])) === null) return null;
        $settings = GatewaySettings::internal();
        $notice = "<codex_question_reply>\n" . self::notice() . "\n</codex_question_reply>";
        $custom = (string) ($settings['web_resume_contract_template'] ?? '');
        if ($custom !== '' && $custom !== WebContract::defaults()['web_resume_contract_template']) {
            // User-edited protocols remain effective; only the stock answer envelope is shortened.
            return $bridge['control_prompt'] . "\n" . $notice;
        }
        $connector = json_encode((string) $settings['connector_name'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $prompt = $notice . "\nThis is the existing task conversation. Use only the attached connector "
            . $connector . " for this task's Codex tools. Retain the existing instruction priorities and tool safety rules. "
            . "Use codex_tool_inventory for a needed tool schema and codex_tool_call for its invocation. "
            . "Missing history or receipts may be read through the scoped context/result tools; never borrow another task's capability.\n"
            . ToolBroker::outputControlPrompt((array) ($spec['budget']['output_control'] ?? []));
        if (!empty($spec['checkpoint_marker'])) $prompt .= "\n" . LunaCheckpoint::instructions($spec['checkpoint_marker']);
        return $prompt . "\n<codex_context_json>\n"
            . WebContract::contextJson(AttachmentStore::transportJson(ContextHistory::canonical($context)))
            . "\n</codex_context_json>\n<codex_transport_resume>\nPass turn_token "
            . $bridge['token'] . " unchanged to every Codex Native call in this response; do not expose it. "
            . "Earlier tokens are superseded. Complete only after required tool results settle.\n</codex_transport_resume>";
    }
}
