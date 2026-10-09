<?php
declare(strict_types=1);
namespace app\gateway\model;

use app\gateway\database\Schema;

/** Account-scoped local usage evidence. Never invents an official remaining quota or billed tokens. */
final class GatewayUsage
{
    /** Persist only a SHA-256 account identity and plan, never a session response or credentials. */
    public static function observeAccount(array $observation): void
    {
        $key = $observation['account_key'] ?? null;
        $plan = $observation['plan_type'] ?? null;
        if (!is_string($key) || preg_match('/\A[a-f0-9]{64}\z/', $key) !== 1
            || !is_string($plan) || strlen($plan) > 80) return;
        SDB(null, 'medoo', 'sqlite')->table('gateway_usage_account')->where('id', 1)->update([
            'account_key' => $key, 'plan_type' => $plan, 'observed_at' => time(),
            'profile_identity' => hash('sha256', (string) \RC\Config::get('gateway', 'browser_profile_dir')),
        ]);
    }

    /** Return a recently verified identity; unknown historical traffic is never attributed retroactively. */
    public static function accountKey(): ?string
    {
        // Optional observability must never turn a usable host-tool transaction into a failure.
        try { $row = SDB(null, 'medoo', 'sqlite')->table('gateway_usage_account')->where('id', 1)->find(); }
        catch (\Throwable) { return null; }
        return is_array($row) && (int) $row['observed_at'] >= time() - 86400
            && $row['profile_identity'] === hash('sha256', (string) \RC\Config::get('gateway', 'browser_profile_dir'))
            ? $row['account_key'] : null;
    }

    /** Clear active identity after profile reset; prior usage stays attached to its own account. */
    public static function resetAccount(): void
    {
        SDB(null, 'medoo', 'sqlite')->table('gateway_usage_account')->where('id', 1)->update([
            'account_key' => null, 'plan_type' => 'unknown', 'observed_at' => 0,
        ]);
    }

    /** Best-effort physical probe intent. Store a token hash only; absence of acceptance is not billed usage. */
    public static function probeIntent(string $token, array $mode): void
    {
        if (($mode['purpose'] ?? null) !== 'connector_probe' || ($mode['effort'] ?? null) !== 'medium') return;
        try {
            Schema::transaction(static function () use ($token, $mode): void {
                $hash = hash('sha256', $token);
                $db = SDB(null, 'medoo', 'sqlite');
                if (is_array($db->table('gateway_probe_messages')->where('token_hash', $hash)->find())) return;
                $db->table('gateway_probe_messages')->add([
                    'token_hash'=>$hash, 'account_key'=>self::accountKey(),
                    'selected_model'=>\support\browser\ChatGptTransport::physicalModel($mode),
                    'selected_effort'=>'medium', 'status'=>'intent', 'created_at'=>time(), 'updated_at'=>time(),
                ]);
            });
        } catch (\Throwable) { /* Optional quota evidence cannot break connectivity. */ }
    }

    /** A real MCP probe receipt proves Web acceptance; retries of the same token count once. */
    public static function probeAccepted(string $token): void
    {
        try {
            SDB(null, 'medoo', 'sqlite')->table('gateway_probe_messages')->where('token_hash', hash('sha256', $token))
                ->where('status', 'intent')->update(['status'=>'accepted', 'updated_at'=>time()]);
        } catch (\Throwable) { /* Preserve the connector's successful receipt if statistics are unavailable. */ }
    }

    /** Preserve only small usage counters before the caller removes an idle task in the same writer transaction. */
    public static function archiveTaskUnlocked(string $taskId, ?string $responseId = null): void
    {
        $responseFilter = $responseId === null ? '' : ' AND j.id=:response';
        $parameters = [':task'=>$taskId, ':since'=>time()-7*86400];
        if ($responseId !== null) $parameters[':response'] = $responseId;
        // Conditional counters and grouped UPSERT need SQLite SQL beyond field CRUD.
        $result = DB('medoo', 'sqlite')->query("INSERT INTO gateway_usage_archive
            (account_scope,accepted_at,model,purpose,submissions,pro_submissions,verified_submissions,stages,input_tokens,output_tokens)
            SELECT COALESCE(w.account_key,''),w.created_at,
                COALESCE(w.selected_model,CASE WHEN w.stage_no>0 THEN 'chatgpt-web/medium' ELSE j.model END),
                COALESCE(w.purpose,CASE WHEN w.stage_no>0 THEN 'context_stage' WHEN j.operation_kind IN ('compact_v1','compact_v2') THEN 'compaction' ELSE 'task' END),
                COUNT(*),
                SUM(CASE WHEN w.selected_effort='max' THEN 1 WHEN w.selected_effort IS NULL AND w.stage_no=0
                    AND (j.model LIKE '%pro%' OR j.reasoning_effort IN ('max','ultra')) THEN 1 ELSE 0 END),
                SUM(CASE WHEN w.selected_model IS NOT NULL THEN 1 ELSE 0 END),
                SUM(CASE WHEN w.stage_no>0 THEN 1 ELSE 0 END),
                SUM(CASE WHEN w.stage_no=0 THEN COALESCE(j.input_tokens,0) ELSE 0 END),
                SUM(CASE WHEN w.stage_no=0 AND json_valid(j.response_json) THEN COALESCE(json_extract(j.response_json,'$.usage.output_tokens'),0) ELSE 0 END)
            FROM gateway_web_messages w JOIN gateway_response_jobs j ON j.id=w.response_id
            WHERE w.task_id=:task AND w.direction='outbound' AND w.status='accepted' AND w.created_at>=:since" . $responseFilter . "
            GROUP BY 1,2,3,4
            ON CONFLICT(account_scope,accepted_at,model,purpose) DO UPDATE SET
                submissions=submissions+excluded.submissions,pro_submissions=pro_submissions+excluded.pro_submissions,
                verified_submissions=verified_submissions+excluded.verified_submissions,stages=stages+excluded.stages,
                input_tokens=input_tokens+excluded.input_tokens,output_tokens=output_tokens+excluded.output_tokens",
            $parameters);
        if ($result === null || $result === false) throw new GatewayError('storage_unavailable', '清理前无法保留轻量用量统计。', 503);
    }

    /** Read bounded, aggregate last-seven-day totals; tool rounds are not counted as new Web submissions. */
    public static function snapshot(): array
    {
        Schema::initialize();
        $key = self::accountKey();
        $since = time() - 7 * 86400;
        // Conditional aggregates across the immutable audit and native job records need one
        // SQLite join, beyond field CRUD. No message bodies enter the console response.
        $groups = DB('medoo', 'sqlite')->query(
            "SELECT model, purpose, SUM(submissions) AS submissions, SUM(stage) AS stages, SUM(pro) AS pro_submissions, "
            . "SUM(verified) AS verified_submissions, SUM(input_tokens) AS input_tokens, SUM(output_tokens) AS output_tokens FROM ("
            . "SELECT COALESCE(w.selected_model,CASE WHEN w.stage_no>0 THEN 'chatgpt-web/medium' ELSE j.model END) AS model, "
            . "COALESCE(w.purpose,CASE WHEN w.stage_no>0 THEN 'context_stage' WHEN j.operation_kind IN ('compact_v1','compact_v2') THEN 'compaction' ELSE 'task' END) AS purpose, "
            . "1 AS submissions, "
            . "CASE WHEN w.stage_no>0 THEN 1 ELSE 0 END AS stage, CASE WHEN w.selected_effort='max' THEN 1 "
            . "WHEN w.selected_effort IS NULL AND w.stage_no=0 AND (j.model LIKE '%pro%' OR j.reasoning_effort IN ('max','ultra')) THEN 1 ELSE 0 END AS pro, "
            . "CASE WHEN w.selected_model IS NOT NULL THEN 1 ELSE 0 END AS verified, "
            . "CASE WHEN w.stage_no=0 THEN COALESCE(j.input_tokens,0) ELSE 0 END AS input_tokens, "
            . "CASE WHEN w.stage_no=0 AND json_valid(j.response_json) THEN COALESCE(json_extract(j.response_json,'$.usage.output_tokens'),0) ELSE 0 END AS output_tokens "
            . "FROM gateway_web_messages w JOIN gateway_response_jobs j ON j.id=w.response_id "
            . "WHERE w.direction='outbound' AND w.status='accepted' AND w.created_at>=:since AND (:account IS NULL OR w.account_key=:account) "
            . "UNION ALL SELECT model,purpose,submissions,stages,pro_submissions,verified_submissions,input_tokens,output_tokens FROM gateway_usage_archive "
            . "WHERE accepted_at>=:since AND (:account IS NULL OR account_scope=:account) "
            . "UNION ALL SELECT selected_model,'connector_probe',1,0,0,1,0,0 FROM gateway_probe_messages "
            . "WHERE status='accepted' AND created_at>=:since AND (:account IS NULL OR account_key=:account)"
            . ") GROUP BY model,purpose ORDER BY submissions DESC LIMIT 128",
            [':since' => $since, ':account' => $key]
        )->fetchAll(\PDO::FETCH_ASSOC);
        $totals = ['submissions'=>0, 'pro_submissions'=>0, 'verified_submissions'=>0, 'input_tokens'=>0, 'output_tokens'=>0];
        $models = $purposes = [];
        foreach ($groups as $group) {
            $model = (string) $group['model'];
            $purpose = (string) $group['purpose'];
            $models[$model] ??= ['model'=>$model, 'submissions'=>0, 'stages'=>0, 'pro_submissions'=>0,
                'verified_submissions'=>0, 'input_tokens'=>0, 'output_tokens'=>0];
            $purposes[$purpose] ??= ['purpose'=>$purpose, 'submissions'=>0, 'pro_submissions'=>0];
            foreach (['submissions','stages','pro_submissions','verified_submissions','input_tokens','output_tokens'] as $field) {
                $value = (int) $group[$field];
                $models[$model][$field] += $value;
                if (array_key_exists($field, $totals)) $totals[$field] += $value;
                if (array_key_exists($field, $purposes[$purpose])) $purposes[$purpose][$field] += $value;
            }
        }
        $rows = array_values($models);
        usort($rows, static fn(array $a, array $b): int => $b['submissions'] <=> $a['submissions']);
        $budget = (int) GatewaySettings::internal()['pro_weekly_budget'];
        $account = ModelCatalog::account();
        if ($key !== null) {
            $identity = SDB(null, 'medoo', 'sqlite')->table('gateway_usage_account')->where('id', 1)->find();
            $account['verified_plan_type'] = (string) ($identity['plan_type'] ?? 'unknown');
        }
        return ['account' => $account, 'models' => ModelCatalog::routes(),
            'scope' => $key === null ? 'installation' : 'verified_account', 'since' => $since,
            'totals' => $totals, 'by_model' => $rows, 'tokens_estimated' => true,
            'by_purpose'=>array_values($purposes), 'historical_model_inferred'=>$totals['verified_submissions'] < $totals['submissions'],
            'pro_budget' => $budget ?: null,
            'pro_remaining_estimate' => $key !== null && $budget > 0 ? max(0, $budget - $totals['pro_submissions']) : null,
            'official_remaining' => null, 'period' => 'rolling_seven_days'];
    }
}
