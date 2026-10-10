<?php
declare(strict_types=1);
namespace app\gateway\model;

use app\gateway\database\Schema;

/** Immutable, unabridged text actually queued for ChatGPT or observed on its page. */
final class WebMessageAudit
{
    public static function recordOutgoing(string $responseId, string $kind, string $text, int $stageNo = 0): void
    {
        Schema::transaction(static function () use ($responseId, $kind, $text, $stageNo): void {
            $job = SDB(null, 'medoo', 'sqlite')->table('gateway_response_jobs')->where('id', $responseId)->find();
            if (!is_array($job)) throw new GatewayError('response_not_found', '网页消息所属任务不存在。', 404);
            self::recordOutgoingUnlocked($job, $kind, $text, $stageNo);
        });
    }

    public static function recordOutgoingUnlocked(array $job, string $kind, string $text, int $stageNo = 0): void
    {
        self::recordUnlocked($job, 'outbound', $kind, $stageNo, 'intent', $text);
    }

    public static function recordIncoming(string $responseId, string $kind, string $text, int $stageNo = 0): void
    {
        Schema::transaction(static function () use ($responseId, $kind, $text, $stageNo): void {
            $job = SDB(null, 'medoo', 'sqlite')->table('gateway_response_jobs')->where('id', $responseId)->find();
            if (!is_array($job)) throw new GatewayError('response_not_found', '网页回复所属任务不存在。', 404);
            self::recordUnlocked($job, 'inbound', $kind, $stageNo, 'observed', $text);
        });
    }

    public static function markAccepted(string $responseId, string $kind, ?int $stageNo = null): void
    {
        Schema::transaction(static function () use ($responseId, $kind, $stageNo): void {
            self::markAcceptedUnlocked($responseId, $kind, $stageNo);
        });
    }

    /**
     * Mark existing outgoing intent records inside the caller's acceptance transaction.
     * @param string $responseId Exact response whose persisted submission was acknowledged.
     * @param string $kind Outgoing message kind; historical text is never changed.
     * @param ?int $stageNo Restrict to one multipart stage, or acknowledge every stage of this kind.
     * @return void Already accepted or absent records remain unchanged.
     * @throws GatewayError If the message acceptance update cannot be saved.
     */
    public static function markAcceptedUnlocked(string $responseId, string $kind, ?int $stageNo = null): void
    {
        $query = SDB(null, 'medoo', 'sqlite')->table('gateway_web_messages')
            ->where('response_id', $responseId)->where('direction', 'outbound')
            ->where('kind', $kind)->where('status', 'intent');
        if ($stageNo !== null) $query = $query->where('stage_no', $stageNo);
        $updated = $query->update(['status' => 'accepted', 'updated_at' => time()]);
        if ($updated === false) throw new GatewayError('storage_unavailable', '网页消息接收记录无法保存。', 503);
    }

    /** Metadata stays small; the exact body is fetched only when the user opens it. */
    public static function forTask(string $taskId): array
    {
        Schema::initialize();
        $rows = SDB(null, 'medoo', 'sqlite')->table('gateway_web_messages')
            ->where('task_id', $taskId)->order([['id', 'ASC']])
            ->select('id,turn_id,response_id,direction,kind,stage_no,status,sha256,byte_count,created_at,updated_at,selected_model,selected_effort,purpose');
        if (!is_array($rows)) throw new GatewayError('storage_unavailable', '网页消息目录暂不可读取。', 503);
        return array_map(static fn(array $row): array => [
            'id' => (int)$row['id'], 'turn_id' => (string)$row['turn_id'],
            'response_id' => (string)$row['response_id'], 'direction' => (string)$row['direction'],
            'kind' => (string)$row['kind'], 'stage_no' => (int)$row['stage_no'],
            'status' => (string)$row['status'], 'sha256' => (string)$row['sha256'],
            'byte_count' => (int)$row['byte_count'], 'created_at' => (int)$row['created_at'],
            'updated_at' => (int)$row['updated_at'],
            'selected_model' => $row['selected_model'], 'selected_effort' => $row['selected_effort'], 'purpose' => $row['purpose'],
        ], $rows);
    }

    public static function textForTask(string $taskId, int $id): array
    {
        Schema::initialize();
        $row = SDB(null, 'medoo', 'sqlite')->table('gateway_web_messages')
            ->where('task_id', $taskId)->where('id', $id)->find();
        if (!is_array($row)) throw new GatewayError('web_message_not_found', '网页消息记录不存在。', 404);
        return ['id' => (int)$row['id'], 'text' => (string)$row['text'],
            'sha256' => (string)$row['sha256'], 'byte_count' => strlen((string)$row['text'])];
    }

    private static function recordUnlocked(array $job, string $direction, string $kind, int $stageNo,
        string $status, string $text): void
    {
        $taskId = (string)($job['task_id'] ?? '');
        $turnId = (string)($job['durable_turn_id'] ?? '');
        $responseId = (string)($job['id'] ?? '');
        if ($taskId === '' || $turnId === '' || $responseId === '') {
            throw new GatewayError('web_message_owner_missing', '网页消息缺少任务归属。', 503);
        }
        $hash = hash('sha256', $text);
        $db = SDB(null, 'medoo', 'sqlite');
        $existing = $db->table('gateway_web_messages')->where('response_id', $responseId)
            ->where('direction', $direction)->where('kind', $kind)
            ->where('stage_no', $stageNo)->where('sha256', $hash)->find();
        if (is_array($existing)) return;
        $now = time();
        $selection = $direction === 'outbound' ? json_decode((string) ($job['physical_selection_json'] ?? ''), true) : null;
        $added = $db->table('gateway_web_messages')->add([
            'task_id' => $taskId, 'turn_id' => $turnId, 'response_id' => $responseId,
            'direction' => $direction, 'kind' => $kind, 'stage_no' => $stageNo,
            'status' => $status, 'text' => $text, 'sha256' => $hash, 'byte_count' => strlen($text),
            'account_key' => GatewayUsage::accountKey(),
            'selected_model' => is_array($selection) ? ($selection['model'] ?? null) : null,
            'selected_effort' => is_array($selection) ? ($selection['effort'] ?? null) : null,
            'purpose' => is_array($selection) ? ($selection['purpose'] ?? null) : null,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        if ($added === false) throw new GatewayError('storage_unavailable', '网页消息原文无法保存。', 503);
    }
}
