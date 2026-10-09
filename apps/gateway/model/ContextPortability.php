<?php
declare(strict_types=1);
namespace app\gateway\model;

use app\gateway\database\Schema;

/** Cross-provider checkpoints: readable Web envelopes and explicit opaque boundaries. */
final class ContextPortability
{
    /** Identify only documented bridge envelopes; real OpenAI ciphertext remains opaque. */
    public static function readableEnvelope(string $value): bool
    {
        return str_starts_with($value, 'rcmx1:') || str_starts_with($value, 'ocx1:');
    }

    /**
     * Replace bridge-owned compaction with its summary and retained user/image input before native responses/compact.
     * Complete inline records keep roles and call_id; provider-local item IDs are not references.
     * Native-only requests are returned byte for byte, including official encrypted content.
     * @param string $raw Decoded JSON request, never compressed HTTP bytes.
     * @return string Normalized JSON only when a known bridge artifact is present.
     * @throws GatewayError On damaged bridge checkpoints or missing content behind a local reference.
     */
    public static function forNative(string $raw): string
    {
        $body = json_decode($raw, false, 128, JSON_THROW_ON_ERROR);
        if (!$body instanceof \stdClass) return $raw;
        $localReference = preg_match('/\Aresp_[a-f0-9]{24}\z/', (string) ($body->previous_response_id ?? '')) === 1;
        if (!is_array($body->input ?? null)) {
            if ($localReference) throw new GatewayError('cross_model_history_required', '切换模型需要完整可读历史，不能只引用网页模型的 response_id。', 400);
            return $raw;
        }
        $crossed = false;
        $hasCheckpoint = false;
        foreach ($body->input as $item) {
            if (!$item instanceof \stdClass) continue;
            if (($item->type ?? '') === 'compaction' && self::readableEnvelope((string) ($item->encrypted_content ?? ''))) $crossed = $hasCheckpoint = true;
            if (($item->type ?? '') === 'reasoning' && str_starts_with((string) ($item->encrypted_content ?? ''), 'ocxr1:')) $crossed = true;
            if (($item->type ?? '') === 'reasoning' && preg_match('/\Ars_[a-f0-9]{32}\z/i', (string) ($item->id ?? ''))
                && !isset($item->encrypted_content) && (is_array($item->summary ?? null) || is_array($item->content ?? null))) $crossed = true;
            // RCMAKER generates 12-byte IDs; native records use their own longer identity format.
            if (preg_match('/\A(?:msg_|fc_)[a-f0-9]{24}\z/', (string) ($item->id ?? ''))
                || preg_match('/\Amsg_c_[a-f0-9]{32}\z/', (string) ($item->id ?? ''))) $crossed = true;
        }
        if ($localReference) {
            if (!$hasCheckpoint) throw new GatewayError('cross_model_history_required', '切换模型需要可读历史检查点，不能丢弃网页模型 response_id 所指向的旧历史。', 400);
            $crossed = true;
        }
        if (!$crossed) return $raw;
        $input = [];
        foreach ($body->input as $item) {
            if (!$item instanceof \stdClass) { $input[] = $item; continue; }
            $type = $item->type ?? 'message';
            if ($type === 'item_reference') {
                throw new GatewayError('cross_model_history_required', '跨模型请求含仅在原提供方存在的历史引用，请由客户端提供完整历史。', 400);
            }
            if ($type === 'compaction' && self::readableEnvelope((string) ($item->encrypted_content ?? ''))) {
                $input = array_merge($input, ContextCompaction::replay($item->encrypted_content));
                continue;
            }
            // Only inline, complete items lose their backend-scoped IDs. Real ciphertext stays intact.
            unset($item->id);
            if ($type === 'reasoning' && str_starts_with((string) ($item->encrypted_content ?? ''), 'ocxr1:')) {
                unset($item->encrypted_content);
            }
            if ($type === 'reasoning' && empty($item->encrypted_content) && empty($item->summary) && empty($item->content)) continue;
            $input[] = $item;
        }
        $body->input = $input;
        // Official response handles still own earlier server-side history. Only
        // a local handle covered by a readable checkpoint is safe to remove.
        if ($localReference) unset($body->previous_response_id);
        return json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /** Replace only unreadable official checkpoints with an explicit gap marker. No native request is made. */
    public static function prepareWeb(string $raw): string
    {
        $body = json_decode($raw, false, 128, JSON_THROW_ON_ERROR);
        if (!$body instanceof \stdClass || !is_array($body->input ?? null)) return $raw;
        $changed = false;
        foreach ($body->input as $index => $item) {
            if (!$item instanceof \stdClass || ($item->type ?? '') !== 'compaction'
                || self::readableEnvelope((string) ($item->encrypted_content ?? ''))) continue;
            $body->input[$index] = (object) [
                'type' => 'message', 'role' => 'developer', 'content' => [[
                    'type' => 'input_text',
                    'text' => 'RCMAKER context boundary: earlier conversation was compacted into an official encrypted checkpoint that ChatGPT Web cannot read. Earlier details are unknown. Use the readable messages here and inspect the project before asserting prior actions or decisions.',
                ]],
            ];
            $changed = true;
        }
        if (!$changed) return $raw;
        // A provider-local response handle cannot restore the unreadable prefix on Web.
        unset($body->previous_response_id);
        return json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /** Opt-in native checkpoint read. A cached summary is usable only while this option is enabled. */
    public static function prepareNativeHandoff(string $raw, #[\SensitiveParameter] array $headers): ?array
    {
        $body = json_decode($raw, false, 128, JSON_THROW_ON_ERROR);
        if (!$body instanceof \stdClass || !is_array($body->input ?? null)) return null;
        $last = -1;
        foreach ($body->input as $index => $item) {
            if ($item instanceof \stdClass && ($item->type ?? '') === 'compaction'
                && !self::readableEnvelope((string) ($item->encrypted_content ?? ''))) $last = $index;
        }
        if ($last < 0) return null;
        if (trim((string) ($headers['authorization'] ?? '')) === '') {
            throw new GatewayError('native_handoff_auth_required', '已开启官方交接摘要，但当前请求没有 Codex 官方登录凭据。', 401);
        }
        $prefix = array_slice($body->input, 0, $last + 1);
        $scope = hash('sha256', ($headers['chatgpt-account-id'] ?? '') . "\0" . $headers['authorization']);
        $key = hash('sha256', $scope . "\0" . ContextHistory::canonical($prefix));
        $cached = SDB(null, 'medoo', 'sqlite')->table('gateway_portable_checkpoints')->where('id', $key)->find();
        if (is_array($cached)) return ['raw' => self::installSummary($raw, $last + 1, (string) $cached['summary'])];
        $catalog = SDB(null, 'medoo', 'sqlite')->table('gateway_model_catalog')->where('id', 1)->find();
        $native = json_decode((string) ($catalog['native_catalog_json'] ?? '{}'), true, 128, JSON_THROW_ON_ERROR);
        $models = array_values(array_filter($native['models'] ?? [], static fn($row): bool => is_array($row)
            && is_string($row['slug'] ?? null) && str_starts_with($row['slug'], 'gpt-')
            && ($row['visibility'] ?? 'list') === 'list'));
        usort($models, static fn($a, $b): int => [str_starts_with($a['slug'], 'gpt-6') ? 0 : 1, $a['priority'] ?? 999]
            <=> [str_starts_with($b['slug'], 'gpt-6') ? 0 : 1, $b['priority'] ?? 999]);
        if ($models === []) throw new GatewayError('native_handoff_model_unavailable', '尚未取得官方模型目录，无法生成交接摘要。', 409);
        $request = ['model' => $models[0]['slug'],
            'instructions' => 'Create a read-only CONTEXT CHECKPOINT for a model switch. Summarize the supplied prior conversation including its official encrypted checkpoint. Do not continue the task, call tools, or invent actions. Preserve the objective, user constraints, paths, completed work, actual results, unresolved failures and next steps. Output only a concise factual handoff, at most 2000 words.',
            'input' => $prefix, 'tools' => [], 'tool_choice' => 'none', 'parallel_tool_calls' => false,
            'store' => false, 'stream' => true];
        return ['raw' => $raw, 'key' => $key, 'prefix_count' => $last + 1,
            'request' => self::forNative(json_encode($request, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR))];
    }

    /** Accept only a terminal official response; SSE deltas alone are never a checkpoint. */
    public static function nativeResponse(int $status, string $contentType, string $body): array
    {
        if ($status !== 200) throw new GatewayError('native_handoff_upstream_failed',
            '官方交接摘要失败（HTTP ' . $status . '）；Web 任务未开始。', $status === 401 || $status === 403 ? 401 : 502);
        if (!\app\gateway\http\NativeTransport::isEventStream($contentType, $body)) {
            $response = json_decode($body, true);
            if (is_array($response) && ($response['status'] ?? '') === 'completed') return $response;
        } else {
            $terminal = null; $done = []; $added = [];
            foreach (preg_split('/\r?\n\r?\n/', $body) as $frame) {
                $data = [];
                foreach (preg_split('/\r?\n/', $frame) as $line) {
                    if (str_starts_with($line, 'data:')) $data[] = ltrim(substr($line, 5), ' ');
                }
                $event = json_decode(implode("\n", $data), true);
                if (!is_array($event)) continue;
                if (in_array($event['type'] ?? '', ['response.failed', 'response.incomplete', 'error'], true)) {
                    throw new GatewayError('native_handoff_failed', '官方交接摘要未完成；Web 任务未开始。', 502);
                }
                if (in_array($event['type'] ?? '', ['response.output_item.added', 'response.output_item.done'], true)) {
                    $index = $event['output_index'] ?? null;
                    if (!is_int($index) || $index < 0 || !is_array($event['item'] ?? null)) {
                        throw new GatewayError('native_handoff_incomplete', '官方交接输出记录不完整。', 502);
                    }
                    if ($event['type'] === 'response.output_item.added') $added[$index] = true;
                    else $done[$index] = $event['item'];
                }
                if (($event['type'] ?? '') === 'response.completed' && is_array($event['response'] ?? null)) $terminal = $event['response'];
            }
            if (is_array($terminal) && ($terminal['status'] ?? '') === 'completed') {
                if (empty($terminal['output'])) {
                    ksort($done, SORT_NUMERIC);
                    if (array_diff_key($added, $done) !== [] || ($done !== [] && array_keys($done) !== range(0, count($done)-1))) {
                        throw new GatewayError('native_handoff_incomplete', '官方交接缺少已完成的输出记录。', 502);
                    }
                    if ($done !== []) $terminal['output'] = array_values($done);
                }
                return $terminal;
            }
        }
        throw new GatewayError('native_handoff_incomplete', '官方交接连接结束前没有完整完成回执。', 502);
    }

    /** Persist a complete opt-in summary, then resume the unchanged Web suffix. */
    public static function completeNativeHandoff(array $plan, array $response): string
    {
        if (($response['status'] ?? '') !== 'completed' || !empty($response['error'])) {
            throw new GatewayError('native_handoff_failed', '官方交接摘要未完成；Web 任务未开始。', 502);
        }
        $parts = [];
        foreach ($response['output'] ?? [] as $item) {
            if (($item['type'] ?? '') !== 'message' || ($item['role'] ?? '') !== 'assistant') continue;
            foreach ($item['content'] ?? [] as $part) {
                if (($part['type'] ?? '') === 'output_text' && is_string($part['text'] ?? null)) $parts[] = $part['text'];
            }
        }
        $summary = trim(implode("\n\n", $parts));
        if ($summary === '' || !mb_check_encoding($summary, 'UTF-8') || strlen($summary) > 131072
            || ContextBudget::tokens($summary) > 12000) {
            throw new GatewayError('native_handoff_summary_invalid', '官方交接摘要为空、过长或无效。', 502);
        }
        $summary = Schema::transaction(static function () use ($plan, $response, $summary): string {
            $db = SDB(null, 'medoo', 'sqlite');
            $existing = $db->table('gateway_portable_checkpoints')->where('id', $plan['key'])->find();
            if (is_array($existing)) return (string) $existing['summary'];
            if (!$db->table('gateway_portable_checkpoints')->add(['id' => $plan['key'],
                'source_model' => (string) ($response['model'] ?? ''), 'summary' => $summary, 'created_at' => time()])) {
                throw new GatewayError('native_handoff_storage_failed', '交接摘要无法保存，Web 任务未开始。', 503);
            }
            return $summary;
        });
        return self::installSummary($plan['raw'], (int) $plan['prefix_count'], $summary);
    }

    private static function installSummary(string $raw, int $prefixCount, string $summary): string
    {
        $body = json_decode($raw, false, 128, JSON_THROW_ON_ERROR);
        $readable = [];
        foreach (array_slice($body->input, 0, $prefixCount) as $item) {
            if (!$item instanceof \stdClass) continue;
            if (($item->type ?? 'message') === 'message' && ($item->role ?? '') === 'user') $readable[] = $item;
            elseif (($item->type ?? '') === 'compaction'
                && self::readableEnvelope((string) ($item->encrypted_content ?? ''))) {
                $readable = array_merge($readable, ContextCompaction::replay($item->encrypted_content));
            }
        }
        $retained = $readable === [] ? [] : AttachmentStore::checkpointMessages(
            ContextCompaction::retainedMessages(ContextHistory::compile($readable, '')));
        $note = ['type' => 'message', 'role' => 'user', 'content' => [[
            'type' => 'input_text', 'text' => ContextCompaction::SUMMARY_PREFIX . "\n" . $summary]]];
        $body->input = array_merge($retained, [$note], array_slice($body->input, $prefixCount));
        unset($body->previous_response_id);
        return json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
