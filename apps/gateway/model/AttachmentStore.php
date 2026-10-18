<?php
declare(strict_types=1);
namespace app\gateway\model;

use RC\Config;
use app\gateway\database\Schema;

/** Project-private, content-addressed Step 08 input/artifact storage. */
final class AttachmentStore
{
    public const MAX_ATTACHMENTS = 10;
    public const MAX_FILE_BYTES = 20000000;
    public const MAX_TURN_BYTES = 50000000;

    private const IMAGE_EXTENSIONS = [
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
    ];

    /** Persist one inline Codex image without ever copying its base64 into the browser prompt. */
    public static function image(string $dataUrl, ?string $detail = null): array
    {
        if (str_contains($dataUrl, "\r") || str_contains($dataUrl, "\n")) {
            throw new GatewayError('invalid_image', '图片 data URL 格式无效。', 400);
        }
        if (!preg_match('/\Adata:([^;,]+);base64,([A-Za-z0-9+\/=]*)\z/D', $dataUrl, $match)) {
            throw new GatewayError('unsupported_image_source', '当前 Web 模型图片只接受内联 base64 data URL。', 400);
        }
        $mime = strtolower((string) $match[1]);
        $extension = self::IMAGE_EXTENSIONS[$mime] ?? null;
        if ($extension === null) {
            throw new GatewayError('unsupported_image_type', '当前 Web 模型不支持该图片格式：' . $mime, 400);
        }
        $base64 = (string) $match[2];
        if ($base64 === '' || strlen($base64) % 4 !== 0 || preg_match('/\A[A-Za-z0-9+\/]*={0,2}\z/D', $base64) !== 1) {
            throw new GatewayError('invalid_image', '图片 base64 数据无效。', 400);
        }
        $bytes = base64_decode($base64, true);
        if (!is_string($bytes) || $bytes === '') throw new GatewayError('invalid_image', '图片内容为空或无法解码。', 400);
        if (strlen($bytes) > self::MAX_FILE_BYTES) throw new GatewayError('attachment_too_large', '单个图片不能超过 20 MB。', 400);
        $imageInfo = @getimagesizefromstring($bytes);
        if (!is_array($imageInfo) || strtolower((string) ($imageInfo['mime'] ?? '')) !== $mime) {
            throw new GatewayError('invalid_image', '图片内容与声明格式不一致或无法解析。', 400);
        }
        $sourceDetail = strtolower(trim((string) $detail));
        $detail = $sourceDetail;
        if ($detail === 'original') $detail = 'high';
        if ($detail !== '' && !in_array($detail, ['auto', 'low', 'high'], true)) {
            throw new GatewayError('invalid_image_detail', '图片 detail 仅支持 auto、low 或 high。', 400);
        }
        $sha = hash('sha256', $bytes);
        $filename = 'codex-input-image-' . substr($sha, 0, 16) . '.' . $extension;
        $metadata = $detail === '' ? [] : ['detail' => $detail];
        if ($sourceDetail === 'original') $metadata['source_detail'] = 'original';
        return self::persist('image', $filename, $mime, $bytes, $metadata);
    }

    /** Persist an explicitly selected Codex skill. Arbitrary user text can never call this path. */
    public static function selectedSkill(string $text): array
    {
        if ($text === '' || strlen($text) > self::MAX_FILE_BYTES) {
            throw new GatewayError('invalid_skill_attachment', '所选技能为空或超过 20 MB。', 400);
        }
        if (!preg_match('/\A<skill>\s*<name>([^<>\r\n]+)<\/name>/u', $text, $match)
            || !str_ends_with(rtrim($text), '</skill>')) {
            throw new GatewayError('invalid_skill_attachment', '所选技能缺少有效的 Codex <skill> 包装。', 400);
        }
        $name = trim((string) $match[1]);
        if (class_exists(\Normalizer::class)) {
            $normalized = \Normalizer::normalize($name, \Normalizer::FORM_KC);
            if (is_string($normalized)) $name = $normalized;
        }
        $stem = preg_replace('/[^\p{L}\p{N}_-]+/u', '-', $name) ?? '';
        $stem = trim(mb_substr($stem, 0, 64), '-');
        if ($stem === '') $stem = 'skill';
        $sha = hash('sha256', $text);
        $filename = $stem . '--' . substr($sha, 0, 16) . '.txt';
        return self::persist('skill', $filename, 'text/plain', $text, ['encoding' => 'utf-8']);
    }

    /** Enforce ChatGPT's shared image + skill attachment envelope. */
    public static function assertTurn(array $attachments): void
    {
        if (count($attachments) > self::MAX_ATTACHMENTS) {
            throw new GatewayError('too_many_attachments', '图片与技能文件合计不能超过 10 个。', 400);
        }
        $bytes = 0;
        $seen = [];
        foreach ($attachments as $attachment) {
            if (!is_array($attachment) || !is_string($attachment['id'] ?? null)) {
                throw new GatewayError('invalid_attachment', '附件描述无效。', 400);
            }
            $ref = (string) $attachment['id'];
            if (isset($seen[$ref])) continue;
            $seen[$ref] = true;
            $size = (int) ($attachment['byte_size'] ?? -1);
            if ($size < 1 || $size > self::MAX_FILE_BYTES) throw new GatewayError('attachment_too_large', '单个附件不能超过 20 MB。', 400);
            $bytes += $size;
        }
        if ($bytes > self::MAX_TURN_BYTES) throw new GatewayError('attachments_too_large', '本轮附件合计不能超过 50 MB。', 400);
    }

    /**
     * Project a physical message to the shared ten-file envelope without rewriting logical history.
     * Skills reserve slots first; images are selected by last occurrence in message order, not file time.
     * Disabled/excess skill files remain inline at their original positions; instructions are never lost.
     * @param array $context Role-preserving structured context.
     * @return array Copy with skipped images explicitly marked and excess skills carried as text.
     * @throws GatewayError If a skill needed for inline fallback cannot be verified/read.
     */
    public static function transportContext(array $context): array
    {
        $refs = self::contextReferences($context);
        $skills = array_filter($refs, static fn(string $type): bool => $type === 'skill_attachment');
        $images = array_filter($refs, static fn(string $type): bool => $type === 'image_attachment');
        $uploadSkills = $skills !== [] && (GatewaySettings::internal()['skill_transport'] ?? 'inline') === 'file';
        if (count($refs) <= self::MAX_ATTACHMENTS && ($skills === [] || $uploadSkills)) return $context;
        $selectedSkills = $uploadSkills ? array_slice($skills, -self::MAX_ATTACHMENTS, null, true) : [];
        $imageSlots = self::MAX_ATTACHMENTS - count($selectedSkills);
        $selected = $selectedSkills + ($imageSlots > 0 ? array_slice($images, -$imageSlots, null, true) : []);
        $inlineSkills = [];
        $project = static function (mixed $value) use (&$project, $selected, &$inlineSkills): mixed {
            if (!is_array($value)) return $value;
            // Tool schemas can contain a JSON Schema union such as type: ["string", "null"].
            // This recursive walk must treat those as ordinary data, not PHP warnings.
            $type = is_string($value['type'] ?? null) ? $value['type'] : '';
            $id = $value['attachment_ref'] ?? null;
            if (is_string($id) && in_array($type, ['image_attachment', 'skill_attachment'], true) && !isset($selected[$id])) {
                if ($type === 'image_attachment') return ['type' => 'text',
                    'text' => '[Earlier image omitted from this upload to retain the most recent images within the 10-file limit: '
                        . (is_string($value['filename'] ?? null) ? $value['filename'] : $id)
                        . '. Do not infer its contents.]'];
                if (!isset($inlineSkills[$id])) {
                    $attachment = self::readReference($id);
                    $text = @file_get_contents($attachment['path']);
                    if ($attachment['kind'] !== 'skill' || !is_string($text)) {
                        throw new GatewayError('attachment_missing', '技能正文无法读取，不能安全转换为正文传输。', 409);
                    }
                    $inlineSkills[$id] = $text;
                }
                return ['type' => 'text', 'text' => $inlineSkills[$id]];
            }
            foreach ($value as &$item) $item = $project($item);
            return $value;
        };
        return $project($context);
    }

    /** @return string Physical-message JSON; canonical ledger/history remains unchanged. @throws GatewayError */
    public static function transportJson(string $contextJson): string
    {
        try { $context = json_decode($contextJson, true, 128, JSON_THROW_ON_ERROR); }
        catch (\Throwable) { throw new GatewayError('invalid_attachment_context', '附件上下文无法解析。', 500); }
        if (!is_array($context)) throw new GatewayError('invalid_attachment_context', '附件上下文必须是对象。', 500);
        $projected = self::transportContext($context);
        return $projected === $context ? $contextJson : ContextHistory::canonical($projected);
    }

    /** Collect unique refs ordered by last use; repeated bytes occupy just one upload slot. */
    private static function contextReferences(array $context): array
    {
        $refs = [];
        $walk = static function (mixed $value) use (&$walk, &$refs): void {
            if (!is_array($value)) return;
            $type = is_string($value['type'] ?? null) ? $value['type'] : '';
            $id = $value['attachment_ref'] ?? null;
            if (is_string($id) && in_array($type, ['image_attachment', 'skill_attachment'], true)) {
                unset($refs[$id]);
                $refs[$id] = $type;
            }
            foreach ($value as $item) $walk($item);
        };
        $walk($context);
        return $refs;
    }

    /** Resolve only project-private persisted refs, verifying bytes before upload or inline fallback. */
    private static function readReference(string $id): array
    {
        if (!preg_match('/\Aatt_[a-f0-9]{32}\z/D', $id)) {
            throw new GatewayError('invalid_attachment_ref', '附件引用格式无效。', 400);
        }
        $row = SDB(null, 'medoo', 'sqlite')->table('gateway_attachments')->where('id', $id)->find();
        if (!is_array($row)) throw new GatewayError('attachment_missing', '上下文引用的附件已不存在，无法安全恢复。', 409);
        return self::verifyRow($row);
    }

    /**
     * Resolve the selected physical upload list, or all logical refs for conservative token accounting.
     * @param array $attachments Existing request descriptors; persisted refs remain the authority.
     * @param string $contextJson Physical delta or full history, depending on the caller.
     * @param bool $forUpload False only for budget accounting; a resident page can retain earlier files.
     * @return array Verified attachment descriptors. @throws GatewayError
     */
    public static function selectForContext(array $attachments, string $contextJson, bool $forUpload = true): array
    {
        try { $context = json_decode($contextJson, true, 128, JSON_THROW_ON_ERROR); }
        catch (\Throwable) { throw new GatewayError('invalid_attachment_context', '附件上下文无法解析。', 500); }
        if (!is_array($context)) throw new GatewayError('invalid_attachment_context', '附件上下文必须是对象。', 500);
        if ($forUpload) $context = self::transportContext($context);
        $selected = [];
        foreach (self::contextReferences($context) as $id => $type) $selected[] = self::readReference($id);
        if ($forUpload) self::assertTurn($selected);
        return $selected;
    }

    /** Add response bindings inside the caller's existing SQLite writer transaction. */
    public static function bindResponseUnlocked(string $responseId, array $attachments): void
    {
        $db = SDB(null, 'medoo', 'sqlite');
        foreach (array_values($attachments) as $ordinal => $attachment) {
            $id = (string) ($attachment['id'] ?? '');
            if ($id === '') throw new GatewayError('invalid_attachment', '附件缺少稳定标识。', 500);
            if (!$db->table('gateway_response_attachments')->add([
                'response_id' => $responseId,
                'attachment_id' => $id,
                'attachment_ref' => $id,
                'ordinal' => $ordinal,
                'transport_mode' => 'upload',
                'created_at' => time(),
            ])) throw new GatewayError('storage_unavailable', '附件无法绑定到 Responses 请求。', 503);
        }
    }

    /** Replace a response's final-message bindings after an unsent page repair expands its context. */
    public static function replaceResponseBindingsUnlocked(string $responseId, array $attachments): void
    {
        $db = SDB(null, 'medoo', 'sqlite');
        $deleted = $db->table('gateway_response_attachments')->where('response_id', $responseId)->delete();
        if ($deleted === false) throw new GatewayError('storage_unavailable', '旧附件绑定无法清理。', 503);
        self::bindResponseUnlocked($responseId, $attachments);
    }

    /** Exact, hash-verified local files for one browser submission. */
    public static function forResponse(string $responseId): array
    {
        Schema::initialize();
        $bindings = SDB(null, 'medoo', 'sqlite')->table('gateway_response_attachments')
            ->where('response_id', $responseId)->order([['ordinal', 'ASC']])->select();
        if ($bindings === false) throw new GatewayError('storage_unavailable', '附件绑定暂不可读取。', 503);
        $result = [];
        foreach ($bindings as $binding) {
            $row = SDB(null, 'medoo', 'sqlite')->table('gateway_attachments')->where('id', (string) $binding['attachment_id'])->find();
            if (!is_array($row)) throw new GatewayError('attachment_missing', '任务引用的附件记录不存在。', 409);
            $result[] = self::verifyRow($row) + ['attachment_ref' => (string) $binding['attachment_ref']];
        }
        self::assertTurn($result);
        return $result;
    }

    /**
     * Export checkpoint-owned user messages as portable Responses items with verified image bytes.
     * Callers must establish provenance for persisted refs; arbitrary request refs are not accepted here.
     * @param array $messages Canonical retained user messages generated by ContextCompaction.
     * @return array Native input messages, preserving block order and visual detail.
     * @throws GatewayError If an owned image is missing, corrupt or not an image.
     */
    public static function checkpointMessages(array $messages): array
    {
        $output = [];
        foreach ($messages as $message) {
            $blocks = is_string($message['content'] ?? null)
                ? [['type'=>'text', 'text'=>$message['content']]] : ($message['content'] ?? []);
            $content = [];
            foreach ($blocks as $block) {
                if (($block['type'] ?? '') === 'text') {
                    $content[] = ['type'=>'input_text', 'text'=>(string)$block['text']];
                } elseif (($block['type'] ?? '') === 'image_attachment') {
                    $image = self::readReference((string)$block['attachment_ref']);
                    if ($image['kind'] !== 'image') throw new GatewayError('invalid_checkpoint_image', '压缩检查点中的图片类型无效。', 409);
                    $bytes = @file_get_contents($image['path']);
                    if (!is_string($bytes) || !hash_equals($image['sha256'], hash('sha256', $bytes))) {
                        throw new GatewayError('attachment_corrupt', '检查点图片读取校验失败，未继续发送。', 409);
                    }
                    $content[] = ['type'=>'input_image', 'image_url'=>'data:'.$image['mime_type'].';base64,'.base64_encode($bytes),
                        'detail'=>$block['detail'] ?? $image['metadata']['detail'] ?? 'auto'];
                }
            }
            if ($content !== []) $output[] = ['type'=>'message', 'role'=>'user', 'content'=>$content];
        }
        return $output;
    }

    /** Extra model budget consumed by file-backed skills and visual inputs. */
    public static function tokenReserve(array $attachments, ?array $budget = null): int
    {
        $tokens = 0;
        foreach ($attachments as $attachment) {
            if (($attachment['kind'] ?? '') === 'skill') {
                $path = (string) ($attachment['path'] ?? '');
                $text = $path !== '' ? @file_get_contents($path) : false;
                if (!is_string($text)) throw new GatewayError('attachment_missing', '技能附件无法读取。', 409);
                $tokens += ContextBudget::tokens($text, $budget);
                continue;
            }
            if (($attachment['kind'] ?? '') === 'image') {
                // Reserve visual input capacity. Codex's original detail is
                // uploaded as high, but still needs its larger original-detail budget.
                $tokens += ($attachment['metadata']['source_detail'] ?? '') === 'original' ? 8192 : 4096;
            }
        }
        return $tokens;
    }

    /** Remove old parse-stage files unless retained for native history or referenced by live/archived checkpoints. */
    public static function cleanupOrphans(int $olderThanSeconds = 86400): int
    {
        Schema::initialize();
        $cutoff = time() - max(3600, $olderThanSeconds);
        $db = DB('medoo', 'sqlite');
        $candidates = $db->query(
            "SELECT a.id,a.relative_path FROM gateway_attachments a "
            . "WHERE a.status='ready' AND a.retained_for_history=0 AND a.created_at<:cutoff "
            . "AND NOT EXISTS(SELECT 1 FROM gateway_response_attachments ra WHERE ra.attachment_id=a.id)",
            [':cutoff' => $cutoff]
        )->fetchAll(\PDO::FETCH_ASSOC);
        $removed = 0;
        foreach ($candidates as $candidate) {
            $id = (string) ($candidate['id'] ?? '');
            if (!preg_match('/\Aatt_[a-f0-9]{32}\z/D', $id)) continue;
            $needle = '%' . $id . '%';
            // A task's transcript can be removed while its native checkpoint remains usable.
            // Its archived references carry the same ownership protection as a live checkpoint.
            $archived = SDB(null, 'medoo', 'sqlite')->table('gateway_checkpoint_proofs')
                ->where('retained_refs_json', 'LIKE', $needle)->limit(1)->find('id');
            if ($archived === false) throw new GatewayError('storage_unavailable', '无法读取检查点校验凭证，请稍后重试。', 503);
            if (is_array($archived)) continue;
            $inResponse = $db->query(
                'SELECT 1 FROM gateway_response_jobs WHERE prompt LIKE :n1 OR context_json LIKE :n2 OR transport_context_json LIKE :n3 LIMIT 1',
                [':n1'=>$needle, ':n2'=>$needle, ':n3'=>$needle]
            )->fetchColumn();
            if ($inResponse !== false) continue;
            $inCheckpoint = $db->query(
                'SELECT 1 FROM gateway_context_checkpoints WHERE summary LIKE :n1 OR response_json LIKE :n2 LIMIT 1',
                [':n1'=>$needle, ':n2'=>$needle]
            )->fetchColumn();
            if ($inCheckpoint !== false) continue;
            $inRolling = $db->query(
                'SELECT 1 FROM gateway_rolling_checkpoints WHERE summary LIKE :n LIMIT 1',
                [':n'=>$needle]
            )->fetchColumn();
            if ($inRolling !== false) continue;

            $relative = str_replace('\\', '/', (string) ($candidate['relative_path'] ?? ''));
            if ($relative === '' || str_starts_with($relative, '/') || str_contains('/' . $relative . '/', '/../')) continue;
            $deleted = SDB(null, 'medoo', 'sqlite')->table('gateway_attachments')->where('id', $id)->delete();
            if ($deleted === false) continue;
            @unlink(self::root() . '/' . $relative);
            $removed++;
        }
        return $removed;
    }

    private static function persist(string $kind, string $filename, string $mime, string $bytes, array $metadata): array
    {
        return TaskMaintenance::withAdmission(static fn(): array => self::persistAdmitted($kind, $filename, $mime, $bytes, $metadata));
    }

    /** Persist under the existing maintenance admission guard, protecting fresh parse-stage files from reclamation. */
    private static function persistAdmitted(string $kind, string $filename, string $mime, string $bytes, array $metadata): array
    {
        Schema::initialize();
        $size = strlen($bytes);
        if ($size < 1 || $size > self::MAX_FILE_BYTES) throw new GatewayError('attachment_too_large', '单个附件不能超过 20 MB。', 400);
        $sha = hash('sha256', $bytes);
        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        $id = 'att_' . substr(hash('sha256', $kind . "\0" . $mime . "\0" . $sha), 0, 32);
        // Keep the visible upload filename on disk: CDP DOM.setFileInputFiles exposes basename(path)
        // to ChatGPT, and the UI acceptance proof checks that exact name.
        $relative = $kind . '/' . substr($sha, 0, 2) . '/' . $filename;
        $root = self::root();
        $path = $root . '/' . $relative;
        $directory = dirname($path);
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new GatewayError('storage_unavailable', '无法创建附件私有目录。', 503);
        }
        if (is_file($path)) {
            if ((int) @filesize($path) !== $size || !hash_equals($sha, (string) @hash_file('sha256', $path))) {
                throw new GatewayError('attachment_corrupt', '附件存储校验失败，已停止复用。', 503);
            }
        } else {
            $temp = $directory . '/.' . basename($path) . '.tmp-' . bin2hex(random_bytes(8));
            $written = @file_put_contents($temp, $bytes, LOCK_EX);
            if ($written !== $size) { @unlink($temp); throw new GatewayError('storage_unavailable', '附件写入失败。', 503); }
            @chmod($temp, 0600);
            if (!@rename($temp, $path)) {
                if (!is_file($path) || !hash_equals($sha, (string) @hash_file('sha256', $path))) {
                    @unlink($temp);
                    throw new GatewayError('storage_unavailable', '附件原子落盘失败。', 503);
                }
                @unlink($temp);
            }
        }
        $now = time();
        $db = SDB(null, 'medoo', 'sqlite');
        $existing = $db->table('gateway_attachments')->where('id', $id)->find();
        if (!is_array($existing)) {
            if (!$db->table('gateway_attachments')->add([
                'id' => $id, 'kind' => $kind, 'filename' => $filename, 'mime_type' => $mime,
                'byte_size' => $size, 'sha256' => $sha, 'relative_path' => $relative,
                'metadata_json' => json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'status' => 'ready', 'created_at' => $now, 'verified_at' => $now,
            ])) throw new GatewayError('storage_unavailable', '附件索引写入失败。', 503);
            $existing = $db->table('gateway_attachments')->where('id', $id)->find();
        }
        if (!is_array($existing)) throw new GatewayError('storage_unavailable', '附件索引读取失败。', 503);
        // The same image bytes may later arrive with original detail. Keep the larger
        // budget reserve for every future use of this stable reference.
        if ($kind === 'image' && ($metadata['source_detail'] ?? '') === 'original') {
            $recorded = json_decode((string) ($existing['metadata_json'] ?? '{}'), true) ?: [];
            if (($recorded['source_detail'] ?? '') !== 'original') {
                $recorded['source_detail'] = 'original';
                $updated = $db->table('gateway_attachments')->where('id', $id)->update([
                    'metadata_json' => json_encode($recorded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                ]);
                if ($updated === false) throw new GatewayError('storage_unavailable', '图片预算元数据无法更新。', 503);
                $existing['metadata_json'] = json_encode($recorded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            }
        }
        return self::verifyRow($existing);
    }

    private static function verifyRow(array $row): array
    {
        $relative = str_replace('\\', '/', (string) ($row['relative_path'] ?? ''));
        if ($relative === '' || str_starts_with($relative, '/') || str_contains('/' . $relative . '/', '/../')) {
            throw new GatewayError('attachment_path_invalid', '附件路径不在项目私有存储中。', 503);
        }
        $path = self::root() . '/' . $relative;
        $sha = (string) ($row['sha256'] ?? '');
        $size = (int) ($row['byte_size'] ?? -1);
        if (!is_file($path) || (int) @filesize($path) !== $size) {
            self::mark((string) $row['id'], 'missing');
            throw new GatewayError('attachment_missing', '任务所需附件文件不存在。', 409);
        }
        $actual = @hash_file('sha256', $path);
        if (!is_string($actual) || !hash_equals($sha, $actual)) {
            self::mark((string) $row['id'], 'corrupt');
            throw new GatewayError('attachment_corrupt', '任务所需附件哈希校验失败。', 409);
        }
        $metadata = [];
        try { $metadata = json_decode((string) ($row['metadata_json'] ?? '{}'), true, 32, JSON_THROW_ON_ERROR); }
        catch (\Throwable) { $metadata = []; }
        return [
            'id' => (string) $row['id'], 'kind' => (string) $row['kind'], 'filename' => (string) $row['filename'],
            'mime_type' => (string) $row['mime_type'], 'byte_size' => $size, 'sha256' => $sha,
            'path' => $path, 'relative_path' => $relative, 'metadata' => is_array($metadata) ? $metadata : [],
        ];
    }

    private static function mark(string $id, string $status): void
    {
        try { SDB(null, 'medoo', 'sqlite')->table('gateway_attachments')->where('id', $id)->update(['status' => $status, 'verified_at' => time()]); }
        catch (\Throwable) {}
    }

    /** Return retained attachment identities without reading or uploading their files. */
    public static function checkpointReferenceIds(array $messages): array
    {
        return array_keys(self::contextReferences(['system'=>[], 'messages'=>$messages]));
    }

    private static function root(): string
    {
        $root = rtrim(str_replace('\\', '/', (string) Config::get('gateway', 'attachment_store_dir')), '/');
        if ($root === '') throw new GatewayError('storage_unavailable', '附件私有目录未配置。', 503);
        return $root;
    }
}
