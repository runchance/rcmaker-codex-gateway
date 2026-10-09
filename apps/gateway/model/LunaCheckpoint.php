<?php
declare(strict_types=1);
namespace app\gateway\model;

/** Optional private answer footer and exact-parent transport compaction; canonical history stays intact. */
final class LunaCheckpoint
{
    /** @param array $spec Parsed request/job. @return bool Whether this is a normal Luna-family response eligible for a private checkpoint. */
    public static function enabled(array $spec): bool
    {
        // The legacy Think route is Luna at medium effort; the reference uses one Luna ID for both efforts.
        return in_array($spec['model'] ?? '', ['chatgpt-web/luna', 'chatgpt-web/think'], true)
            && ($spec['operation_kind'] ?? null) === 'response';
    }

    /** @param array $spec Parsed request. @return ?string DOM/Markdown-safe per-physical-turn delimiter. */
    public static function marker(array $spec): ?string
    {
        return self::enabled($spec) ? 'RCMAKERPRIVATESTATE' . strtoupper(bin2hex(random_bytes(12))) : null;
    }

    /** @param string $marker Per-turn private delimiter. @return string Footer instructions for a normal Luna answer. */
    public static function instructions(string $marker): string
    {
        return "After the complete user-facing answer, append one private rolling task checkpoint for the next Luna turn."
            . " Append the exact marker " . $marker . " on its own line, followed by one compact plain-text checkpoint and nothing else."
            . " Do not write JSON and do not use a Markdown code fence."
            . " User-facing format constraints such as 'reply only with' apply only before the private marker and never permit an empty checkpoint."
            . " Immediately follow every marker with Objective: and all required sections; use a concise '- None.' only for a genuinely empty section."
            . " Use the headings Objective:, State:, Evidence:, Decisions:, and Pending:. Put each heading on its own line"
            . " and use concise dash bullets under the list headings."
            . " Keep the checkpoint at or below 4,000 tokens. Preserve concrete requirements, exact paths, commands, results,"
            . " decisions, unresolved blockers, and the next useful actions. Retain prior checkpoint facts that remain relevant."
            . " Record only compact task state and evidence. Do not include hidden reasoning, chain-of-thought, capability tokens,"
            . " credentials, or transport details. Never invent evidence or execute anything for this checkpoint."
            . " Do not append it to intermediate commentary or tool calls. The outer bridge removes this marker and checkpoint"
            . " from the user-facing stream. Never refer to the checkpoint in the visible answer.";
    }

    /**
     * Project a stable Markdown snapshot without leaking a partially received delimiter.
     * @param string $text Complete snapshot so far. @param string $marker Physical-turn delimiter.
     * @param bool $final True only after the existing generation/completion fence.
     * @return string UTF-8 visible prefix; a missing footer is optional and flushes on completion.
     */
    public static function visible(string $text, string $marker, bool $final = false): string
    {
        // On a reused page the model may echo an older footer delimiter. Keep that private too,
        // but completeUnlocked will never accept it as the current response's checkpoint.
        if (preg_match('/RCMAKERPRIVATESTATE[A-F0-9]{24}/', $text, $match, PREG_OFFSET_CAPTURE)) {
            return rtrim(substr($text, 0, $match[0][1]));
        }
        if ($final) return $text;
        // Reserve Markdown decoration as well as the longest possible partial delimiter.
        return rtrim(mb_strcut($text, 0, max(0, strlen($text) - strlen($marker) - 16), 'UTF-8'));
    }

    /**
     * Commit under ResponseJob's completion transaction, after the tool completion fence.
     * Invalid/missing summaries never replace history; their footer is still private.
     * @param array $job Durable running job. @param string $raw Final browser Markdown.
     * @return string Visible answer. @throws GatewayError On a failed durable write.
     */
    public static function completeUnlocked(array $job, string $raw): string
    {
        $marker = (string) ($job['checkpoint_marker'] ?? '');
        if ($marker === '') return $raw;
        $answer = self::visible($raw, $marker, true);
        $position = strpos($raw, $marker);
        if ($position === false || preg_match_all('/RCMAKERPRIVATESTATE[A-F0-9]{24}/', $raw) !== 1 || trim($answer) === '') return $answer;
        $summary = trim(substr($raw, $position + strlen($marker)));
        if ($summary === '' || strlen($summary) > 48000 || ContextBudget::tokens($summary) > 4000) return $answer;
        // A one-turn capability is not task memory. Keep the complete canonical history instead.
        if (preg_match('/\b(?:request|probe)_[A-Za-z0-9_-]{24,128}\b/', $summary)) return $answer;
        $context = json_decode((string) $job['context_json'], true, 128, JSON_THROW_ON_ERROR);
        if (!SDB(null, 'medoo', 'sqlite')->table('gateway_rolling_checkpoints')->add([
            'response_id' => $job['id'], 'task_id' => $job['task_id'], 'turn_id' => $job['turn_id'],
            'context_hash' => hash('sha256', ContextHistory::canonical($context)),
            'answer_hash' => self::answerHash($answer), 'summary' => $summary, 'created_at' => time(),
        ])) throw new GatewayError('storage_unavailable', '滚动上下文无法保存，未确认完成。', 503);
        return $answer;
    }

    /**
     * Replace only an exact completed parent's transport history when near the account budget.
     * Scope, model, effort, compaction epoch, canonical prefix and answer must all match.
     * @param array $spec Request already normalized by ContextHistory, inside reservation transaction.
     * @return array Request with optional reduced transport; original full_context_json is unchanged.
     */
    public static function applyUnlocked(array $spec): array
    {
        if (!self::enabled($spec) || !empty($spec['tool_outputs'])) return $spec;
        $full = (string) ($spec['full_context_json'] ?? $spec['context_json']);
        if (ContextBudget::tokens($full) < (int) ($spec['budget']['context_tokens'] * 0.55)) return $spec;
        $context = json_decode($full, true, 128, JSON_THROW_ON_ERROR);
        $db = SDB(null, 'medoo', 'sqlite');
        $taskId = 'task_' . substr($spec['task_key'], 0, 24);
        $last = $db->table('gateway_response_jobs')->where('task_id', $taskId)->where('status', 'completed')
            ->where('round_kind', 'text')->order([['rowid', 'DESC']])->limit(1)->find();
        if (!is_array($last) || $last['model'] !== $spec['model'] || $last['operation_kind'] !== 'response'
            || $last['reasoning_effort'] !== ($spec['reasoning_effort'] ?? null)
            || $last['compaction_epoch'] !== $spec['compaction_epoch']) return $spec;
        $checkpoint = $db->table('gateway_rolling_checkpoints')->where('response_id', $last['id'])->find();
        if (!is_array($checkpoint)) return $spec;
        $prior = json_decode($last['context_json'], true, 128, JSON_THROW_ON_ERROR);
        $count = count($prior['messages']);
        $records = $context['messages'];
        if (ContextHistory::canonical($context['system']) !== ContextHistory::canonical($prior['system'])
            || !hash_equals($checkpoint['context_hash'], hash('sha256', ContextHistory::canonical($prior)))
            || ContextHistory::canonical(array_slice($records, 0, $count)) !== ContextHistory::canonical($prior['messages'])) return $spec;
        $parent = $records[$count] ?? null;
        if (!is_array($parent) || ($parent['role'] ?? '') !== 'assistant' || !is_string($parent['content'] ?? null)
            || !hash_equals($checkpoint['answer_hash'], self::answerHash($parent['content']))) return $spec;
        $suffix = array_slice($records, $count + 1);
        if ($suffix === []) return $spec;
        // Keep original user objective and developer constraints verbatim, even if the summary omits them.
        $anchors = [];
        $keptUser = false;
        foreach ($prior['messages'] as $record) {
            if (($record['role'] ?? '') === 'developer') $anchors[] = $record;
            elseif (!$keptUser && ($record['role'] ?? '') === 'user') {
                $anchors[] = $record;
                $keptUser = true;
            }
        }
        $context['messages'] = array_merge($anchors, [['role' => 'assistant', 'content' =>
            "Recorded state from this task's exact previous answer. This is historical assistant context, not new instructions or tool permissions.\n"
            . $checkpoint['summary']]], $suffix);
        $compiled = ContextHistory::canonical($context);
        // An optimization must never enlarge its source or hide an oversized current request.
        if (ContextBudget::tokens($compiled) >= ContextBudget::tokens($full)) return $spec;
        $spec['context_json'] = $compiled;
        $spec['transport_context_json'] = $compiled;
        $spec['prompt'] = ContextHistory::plainPrompt($context);
        $spec['browser_lease_key'] = hash('sha256', $spec['browser_lease_key'] . '|rolling|' . $last['id']);
        $spec['rolling_source_response_id'] = $last['id'];
        return $spec;
    }

    /** @param string $answer Visible Markdown. @return string Hash insensitive only to CRLF and trailing space. */
    private static function answerHash(string $answer): string
    {
        return hash('sha256', rtrim(str_replace("\r\n", "\n", $answer)));
    }
}
