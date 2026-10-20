<?php
declare(strict_types=1);
namespace app\gateway\model;

use app\gateway\database\Schema;

/** Durable consecutive gateway failures and deduplication, scoped to one native conversation. */
final class TaskFailureState
{
    public const REBUILD_THRESHOLD = 3;

    /**
     * Record a rejected, already parsed native request after its admission transaction rolled back.
     * @param array $spec Original request identity, before any recovery task rotation.
     * @param string $code Gateway failure category; cancellation and rate-limit controls are excluded.
     * @return void Unknown tasks/owners are never created or modified by this diagnostic boundary.
     * @throws GatewayError On unavailable storage; callers preserve the original request error.
     */
    public static function admissionFailed(array $spec, string $code): void
    {
        if (!self::counts($code) || empty($spec['task_key']) || empty($spec['request_hash'])) return;
        Schema::transaction(static function () use ($spec, $code): void {
            $db = SDB(null, 'medoo', 'sqlite');
            $id = 'task_' . substr((string) $spec['task_key'], 0, 24);
            $link = $db->table('gateway_task_conversations')->where('base_task_id', $id)->find();
            $task = $db->table('gateway_tasks')->where('id', $link['current_task_id'] ?? $id)->find();
            $owner = $link['current_owner_key'] ?? ($spec['browser_owner_key'] ?? null);
            if (!is_array($task) || $task['client_id'] !== ($spec['client_id'] ?? 'responses')
                || $task['thread_id'] !== ($spec['thread_id'] ?? null)
                || $task['branch_id'] !== ($spec['branch_id'] ?? 'main') || $task['owner_key'] !== $owner) return;
            self::recordUnlocked($task, $spec, $code, null);
        });
    }

    /**
     * Record a newly published failed response in its existing ledger transaction.
     * @param array $job Exact persisted response, before its failed terminal is published.
     * @param string $code Stable gateway error category. @return void
     * @throws GatewayError On unavailable storage or inconsistent writes.
     */
    public static function failedJobUnlocked(array $job, string $code): void
    {
        if (!self::counts($code) || empty($job['task_id'])) return;
        $task = SDB(null, 'medoo', 'sqlite')->table('gateway_tasks')->where('id', $job['task_id'])->find();
        if (is_array($task)) self::recordUnlocked($task, $job, $code, $job['id']);
    }

    /**
     * A successfully committed text/checkpoint or native tool batch breaks the failure streak.
     * Keep the deduplication journal so reconnecting an older failure cannot count it again.
     * @param array $job Exact response that completed successfully. @return void
     * @throws GatewayError On unavailable storage. Caller owns the response transaction.
     */
    public static function succeededUnlocked(array $job): void
    {
        if (empty($job['task_id'])) return;
        $db = SDB(null, 'medoo', 'sqlite');
        $task = $db->table('gateway_tasks')->where('id', $job['task_id'])->find('consecutive_gateway_errors,gateway_rebuild_pending');
        if (!is_array($task) || ((int)$task['consecutive_gateway_errors'] === 0 && (int)$task['gateway_rebuild_pending'] === 0)) return;
        $write = $db->table('gateway_tasks')->where('id', $job['task_id'])->update([
            'consecutive_gateway_errors'=>0, 'gateway_rebuild_pending'=>0]);
        if (!$write || $write->rowCount() !== 1) throw new GatewayError('storage_unavailable', 'Gateway failure state could not be reset.', 503);
        self::eventUnlocked((string)$job['task_id'], $job['id'], 'gateway_failure_streak_reset', 0);
    }

    /** Control requests, user decisions and account-wide waiting never imply a defective task page. */
    private static function counts(string $code): bool
    {
        $code = strtoupper($code);
        return $code !== '' && !str_starts_with($code, 'CODEX_USER_STOP')
            && !str_contains($code, 'RATE_LIMIT') && !str_contains($code, 'COOLDOWN')
            && !str_contains($code, 'SUPERSEDED') && !in_array($code, [
                'TASK_CANCELLED', 'TASK_DELETED', 'CODEX_USER_CANCELLED', 'AUTHENTICATION_REQUIRED', 'INVALID_API_KEY',
                'BROWSER_PROFILE_RESET_PENDING', 'BROWSER_RESTART_PENDING', 'BROWSER_RESTARTING',
            ], true);
    }

    /** One unique native request counts once, including concurrent HTTP retries and replayed failures. */
    private static function recordUnlocked(array $task, array $request, string $code, ?string $responseId): void
    {
        if (empty($request['request_hash']) || $task['status'] === 'cancelled') return;
        $identity = [$task['client_id'], $task['thread_id'] ?? $task['id'], $task['branch_id'],
            $request['turn_id'] ?? '', $request['operation_kind'] ?? 'response', $request['request_hash']];
        $key = hash('sha256', ContextHistory::canonical($identity));
        $db = SDB(null, 'medoo', 'sqlite');
        $known = $db->table('gateway_task_error_attempts')->where('request_key', $key)->find('request_key');
        if (is_array($known)) return;
        if ($known === false) throw new GatewayError('storage_unavailable', 'Gateway failure evidence could not be read.', 503);
        if (!$db->table('gateway_task_error_attempts')->add(['request_key'=>$key, 'task_id'=>$task['id'],
            'error_code'=>$code, 'created_at'=>time()])) throw new GatewayError('storage_unavailable', 'Gateway failure evidence could not be saved.', 503);
        $count = min(self::REBUILD_THRESHOLD, (int)$task['consecutive_gateway_errors'] + 1);
        $write = $db->table('gateway_tasks')->where('id', $task['id'])->update([
            'consecutive_gateway_errors'=>$count, 'gateway_rebuild_pending'=>(int)($count >= self::REBUILD_THRESHOLD)]);
        if (!$write || $write->rowCount() !== 1) throw new GatewayError('storage_unavailable', 'Gateway failure state could not be saved.', 503);
        self::eventUnlocked((string)$task['id'], $responseId, 'gateway_failure_recorded', $count, $code);
    }

    /** Persist scalar-only timeline evidence through the existing framework connection. */
    private static function eventUnlocked(string $taskId, ?string $responseId, string $type, int $count, ?string $code = null): void
    {
        if (!SDB(null, 'medoo', 'sqlite')->table('gateway_task_events')->add([
            'task_id'=>$taskId, 'response_id'=>$responseId, 'event_type'=>$type,
            'data_json'=>ContextHistory::canonical(['count'=>$count, 'error_code'=>$code]), 'created_at'=>time(),
        ])) throw new GatewayError('storage_unavailable', 'Gateway failure event could not be saved.', 503);
    }
}
