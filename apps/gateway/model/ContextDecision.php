<?php
declare(strict_types=1);
namespace app\gateway\model;

use app\gateway\database\Schema;

/** Read-only console evidence of a transport choice; never judges completion or changes model behavior. */
final class ContextDecision
{
    /** Best-effort immutable decision record after planning; failure of optional diagnostics cannot fail a task. */
    public static function record(string $responseId, string $prompt, array $budget, ?array $plan, int $attachmentTokens, ?array $evidence = null): void
    {
        try {
            $decision = $evidence ?? [
                'version'=>1, 'reason'=>$plan === null ? 'inline' : 'pinned_recovery',
                'parts'=>$plan['total_parts'] ?? 1, 'prompt_tokens'=>ContextBudget::tokens($prompt, $budget),
                'prompt_chars'=>mb_strlen($prompt), 'attachment_tokens'=>$attachmentTokens,
                'message_tokens'=>$budget['message_tokens'] ?? 0, 'message_chars'=>$budget['message_chars'] ?? 0,
                'soft_inline_tokens'=>max(12000,intdiv((int)($budget['base_compact_tokens'] ?? $budget['message_tokens'] ?? 0),2)),
                'context_tokens'=>$budget['context_tokens'] ?? 0, 'compact_tokens'=>$budget['compact_tokens'] ?? 0,
                'context_multiplier'=>$budget['context_multiplier'] ?? 1,
            ];
            $decision += self::budgetPolicyEvidence($budget);
            Schema::transaction(static function () use ($responseId, $decision): void {
                DB('medoo', 'sqlite')->query('UPDATE gateway_response_jobs SET transport_decision_json=:decision
                    WHERE id=:id AND transport_decision_json IS NULL',
                    [':decision'=>json_encode($decision, JSON_THROW_ON_ERROR), ':id'=>$responseId]);
            });
        } catch (\Throwable) { /* This projection must never interfere with the native/Web tool loop. */ }
    }

    /** @return array Last 12 physical preparation decisions, without loading transcript payloads. */
    public static function forTask(string $taskId): array
    {
        $rows = DB('medoo', 'sqlite')->query('SELECT id,created_at,operation_kind,input_tokens,transport_parts_total,
            transport_stage_sent,transport_stage_acked,transport_decision_json,budget_json
            FROM gateway_response_jobs WHERE task_id=:task AND (round_no=1 OR transport_parts_total>1
                OR transport_decision_json IS NOT NULL) ORDER BY rowid DESC LIMIT 12', [':task'=>$taskId])->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $decision = json_decode((string)$row['transport_decision_json'], true) ?: null;
            $budget = json_decode((string)$row['budget_json'], true) ?: [];
            unset($row['transport_decision_json'], $row['budget_json']);
            $row['decision'] = $decision;
            $row['logical_context_tokens'] = (int)($budget['context_tokens'] ?? 0);
            $row['logical_compact_tokens'] = (int)($budget['compact_tokens'] ?? 0);
            $row['context_multiplier'] = (int)($budget['context_multiplier'] ?? 1);
            $row += self::budgetPolicyEvidence($budget);
        }
        return $rows;
    }

    /** Project the pinned budget's explanation without recalculating or upgrading old jobs. */
    private static function budgetPolicyEvidence(array $budget): array
    {
        return [
            'budget_policy'=>$budget['budget_policy'] ?? null,
            'budget_reason'=>$budget['budget_reason'] ?? null,
            'model_family'=>$budget['model_family'] ?? null,
            'extension_requested'=>$budget['extension_requested'] ?? null,
            'extension_allowed'=>$budget['extension_allowed'] ?? null,
            'context_effective_ratio'=>$budget['context_effective_ratio'] ?? null,
            'compaction_capacity_effort'=>$budget['compaction_capacity_effort'] ?? null,
            'browser_context_tokens'=>$budget['browser_context_tokens'] ?? null,
            'counter'=>$budget['counter'] ?? null,
        ];
    }
}
