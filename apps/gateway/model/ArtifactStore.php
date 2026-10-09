<?php
declare(strict_types=1);
namespace app\gateway\model;

use RC\Config;
use app\gateway\database\Schema;

/** Verified files produced by a Web model and copied into project-private storage. */
final class ArtifactStore
{
    public const MAX_ARTIFACT_BYTES = 100000000;

    /** Import one browser download after the browser has reported it complete. */
    public static function ingestDownload(string $responseId, string $sourceUrl, string $suggestedFilename, string $sourcePath, ?string $mimeType = null): array
    {
        Schema::initialize();
        $job = SDB(null, 'medoo', 'sqlite')->table('gateway_response_jobs')->where('id', $responseId)->find();
        if (!is_array($job) || !is_string($job['task_id'] ?? null) || (string) $job['task_id'] === '') {
            throw new GatewayError('artifact_response_missing', '产物无法绑定到当前任务。', 409);
        }
        if (!is_file($sourcePath)) throw new GatewayError('artifact_download_missing', '浏览器报告下载完成，但本地文件不存在。', 502);
        $size = (int) @filesize($sourcePath);
        if ($size < 1 || $size > self::MAX_ARTIFACT_BYTES) {
            throw new GatewayError('artifact_size_invalid', '生成文件为空或超过 100 MB，未保存为产物。', 502);
        }
        $sha = @hash_file('sha256', $sourcePath);
        if (!is_string($sha) || !preg_match('/\A[a-f0-9]{64}\z/D', $sha)) {
            throw new GatewayError('artifact_hash_failed', '生成文件无法完成哈希校验。', 502);
        }
        $filename = self::filename($suggestedFilename, $sha);
        $id = 'art_' . substr(hash('sha256', $responseId . "\0" . $sha . "\0" . $filename), 0, 32);
        $relative = 'artifact/' . substr($sha, 0, 2) . '/' . $id . '/' . $filename;
        $target = self::root() . '/' . $relative;
        self::copyVerified($sourcePath, $target, $size, $sha);
        $mimeType = self::mime($filename, $mimeType);
        $now = time();
        $db = SDB(null, 'medoo', 'sqlite');
        $existing = $db->table('gateway_artifacts')->where('id', $id)->find();
        if (!is_array($existing)) {
            if (!$db->table('gateway_artifacts')->add([
                'id' => $id,
                'response_id' => $responseId,
                'task_id' => (string) $job['task_id'],
                'filename' => $filename,
                'mime_type' => $mimeType,
                'byte_size' => $size,
                'sha256' => $sha,
                'relative_path' => $relative,
                'source_url' => mb_substr($sourceUrl, 0, 4000),
                'source_kind' => 'browser_download',
                'status' => 'ready',
                'created_at' => $now,
                'verified_at' => $now,
            ])) throw new GatewayError('storage_unavailable', '产物索引写入失败。', 503);
            $existing = $db->table('gateway_artifacts')->where('id', $id)->find();
        }
        if (!is_array($existing)) throw new GatewayError('storage_unavailable', '产物索引读取失败。', 503);
        return self::public(self::verifyRow($existing));
    }

    public static function get(string $id): array
    {
        Schema::initialize();
        if (!preg_match('/\Aart_[a-f0-9]{32}\z/D', $id)) throw new GatewayError('artifact_not_found', '产物不存在。', 404);
        $row = SDB(null, 'medoo', 'sqlite')->table('gateway_artifacts')->where('id', $id)->find();
        if (!is_array($row)) throw new GatewayError('artifact_not_found', '产物不存在。', 404);
        return self::verifyRow($row);
    }

    public static function forTask(string $taskId): array
    {
        Schema::initialize();
        $rows = SDB(null, 'medoo', 'sqlite')->table('gateway_artifacts')->where('task_id', $taskId)
            ->where('status', '<>', 'deleted')->order([['created_at', 'ASC'], ['id', 'ASC']])->select();
        if ($rows === false) throw new GatewayError('storage_unavailable', '任务产物暂不可读取。', 503);
        $result = [];
        foreach ($rows as $row) {
            try { $result[] = self::public(self::verifyRow($row)); }
            catch (GatewayError $error) {
                $result[] = self::public(array_replace($row, [
                    'path' => null,
                    'status' => $error->errorCode === 'artifact_corrupt' ? 'corrupt' : 'missing',
                ]));
            }
        }
        return $result;
    }

    public static function forResponse(string $responseId): array
    {
        Schema::initialize();
        $rows = SDB(null, 'medoo', 'sqlite')->table('gateway_artifacts')->where('response_id', $responseId)
            ->where('status', '<>', 'deleted')->order([['created_at', 'ASC'], ['id', 'ASC']])->select();
        if ($rows === false) throw new GatewayError('storage_unavailable', 'Responses 产物暂不可读取。', 503);
        $result = [];
        foreach ($rows as $row) $result[] = self::public(self::verifyRow($row));
        return $result;
    }

    /** Append only local, hash-verified links; the model's own sandbox link is never treated as proof. */
    public static function appendMarkdown(string $text, array $artifacts): string
    {
        if ($artifacts === []) return $text;
        $lines = [];
        foreach ($artifacts as $artifact) {
            if (!is_array($artifact) || !is_string($artifact['id'] ?? null) || !is_string($artifact['filename'] ?? null)) continue;
            $verified = self::public(self::get((string) $artifact['id']));
            $label = str_replace(['[', ']'], ['(', ')'], (string) $verified['filename']);
            $lines[] = '- [' . $label . '](' . self::downloadUrl((string) $verified['id']) . ') · SHA-256 `' . (string) $verified['sha256'] . '`';
        }
        if ($lines === []) return $text;
        return rtrim($text) . "\n\n本机已验证产物：\n" . implode("\n", $lines);
    }

    public static function downloadUrl(string $id): string
    {
        return rtrim((string) Config::get('gateway', 'api_origin'), '/') . '/api/artifacts/' . rawurlencode($id);
    }

    /** Remove abandoned browser partials; indexed artifacts stay until a later retention policy proves them unreferenced. */
    public static function cleanupOrphans(int $olderThanSeconds = 3600): int
    {
        $dir = self::downloadRoot();
        if (!is_dir($dir)) return 0;
        $cutoff = time() - max(300, $olderThanSeconds);
        $removed = 0;
        foreach (new \DirectoryIterator($dir) as $item) {
            if ($item->isDot() || !$item->isFile() || $item->getMTime() >= $cutoff) continue;
            if (@unlink($item->getPathname())) $removed++;
        }
        return $removed;
    }

    public static function downloadRoot(): string
    {
        $root = rtrim(str_replace('\\', '/', (string) Config::get('gateway', 'artifact_download_dir')), '/');
        if ($root === '') throw new GatewayError('storage_unavailable', '浏览器产物下载目录未配置。', 503);
        if (!is_dir($root) && !@mkdir($root, 0700, true) && !is_dir($root)) throw new GatewayError('storage_unavailable', '无法创建浏览器产物下载目录。', 503);
        return $root;
    }

    private static function verifyRow(array $row): array
    {
        $relative = str_replace('\\', '/', (string) ($row['relative_path'] ?? ''));
        if ($relative === '' || str_starts_with($relative, '/') || str_contains('/' . $relative . '/', '/../')) {
            throw new GatewayError('artifact_path_invalid', '产物路径不在项目私有存储中。', 503);
        }
        $path = self::root() . '/' . $relative;
        $size = (int) ($row['byte_size'] ?? -1);
        $sha = (string) ($row['sha256'] ?? '');
        if (!is_file($path) || (int) @filesize($path) !== $size) {
            self::mark((string) ($row['id'] ?? ''), 'missing');
            throw new GatewayError('artifact_missing', '产物文件已经不存在。', 404);
        }
        $actual = @hash_file('sha256', $path);
        if (!is_string($actual) || !hash_equals($sha, $actual)) {
            self::mark((string) ($row['id'] ?? ''), 'corrupt');
            throw new GatewayError('artifact_corrupt', '产物文件哈希校验失败。', 409);
        }
        return $row + ['path' => $path, 'status' => 'ready'];
    }

    private static function public(array $row): array
    {
        $id = (string) ($row['id'] ?? '');
        return [
            'id' => $id,
            'response_id' => (string) ($row['response_id'] ?? ''),
            'task_id' => (string) ($row['task_id'] ?? ''),
            'filename' => (string) ($row['filename'] ?? ''),
            'mime_type' => (string) ($row['mime_type'] ?? 'application/octet-stream'),
            'byte_size' => (int) ($row['byte_size'] ?? 0),
            'sha256' => (string) ($row['sha256'] ?? ''),
            'status' => (string) ($row['status'] ?? 'missing'),
            'created_at' => (int) ($row['created_at'] ?? 0),
            'download_url' => $id !== '' ? self::downloadUrl($id) : null,
        ];
    }

    private static function copyVerified(string $source, string $target, int $size, string $sha): void
    {
        $directory = dirname($target);
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) throw new GatewayError('storage_unavailable', '无法创建产物私有目录。', 503);
        if (is_file($target)) {
            if ((int) @filesize($target) !== $size || !hash_equals($sha, (string) @hash_file('sha256', $target))) throw new GatewayError('artifact_corrupt', '已有产物副本校验失败。', 503);
            return;
        }
        $temp = $directory . '/.' . basename($target) . '.tmp-' . bin2hex(random_bytes(8));
        $in = @fopen($source, 'rb');
        $out = @fopen($temp, 'xb');
        if ($in === false || $out === false) {
            if (is_resource($in)) fclose($in);
            if (is_resource($out)) fclose($out);
            @unlink($temp);
            throw new GatewayError('storage_unavailable', '产物副本无法创建。', 503);
        }
        $copied = stream_copy_to_stream($in, $out);
        fflush($out);
        fclose($in);
        fclose($out);
        @chmod($temp, 0600);
        if ($copied !== $size || !hash_equals($sha, (string) @hash_file('sha256', $temp)) || !@rename($temp, $target)) {
            @unlink($temp);
            throw new GatewayError('artifact_copy_failed', '产物复制或校验失败。', 503);
        }
    }

    private static function filename(string $value, string $sha): string
    {
        $value = basename(str_replace('\\', '/', trim($value)));
        $value = preg_replace('~[\x00-\x1f\x7f<>:"/\\\\|?*]+~u', '-', $value) ?? '';
        $value = trim($value, " .\t\r\n");
        if ($value === '') $value = 'artifact-' . substr($sha, 0, 16) . '.bin';
        if (mb_strlen($value) > 180) {
            $extension = pathinfo($value, PATHINFO_EXTENSION);
            $stem = pathinfo($value, PATHINFO_FILENAME);
            $value = mb_substr($stem, 0, 150) . '-' . substr($sha, 0, 12) . ($extension !== '' ? '.' . mb_substr($extension, 0, 16) : '');
        }
        return $value;
    }

    private static function mime(string $filename, ?string $hint): string
    {
        $hint = strtolower(trim((string) $hint));
        if (preg_match('/\A[a-z0-9.+-]+\/[a-z0-9.+-]+\z/D', $hint)) return $hint;
        return match (strtolower(pathinfo($filename, PATHINFO_EXTENSION))) {
            'png' => 'image/png', 'jpg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp',
            'pdf' => 'application/pdf', 'json' => 'application/json', 'csv' => 'text/csv', 'txt', 'md' => 'text/plain',
            'zip' => 'application/zip', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            default => 'application/octet-stream',
        };
    }

    private static function mark(string $id, string $status): void
    {
        if ($id === '') return;
        try { SDB(null, 'medoo', 'sqlite')->table('gateway_artifacts')->where('id', $id)->update(['status' => $status, 'verified_at' => time()]); } catch (\Throwable) {}
    }

    private static function root(): string
    {
        $root = rtrim(str_replace('\\', '/', (string) Config::get('gateway', 'attachment_store_dir')), '/');
        if ($root === '') throw new GatewayError('storage_unavailable', '产物私有目录未配置。', 503);
        return $root;
    }
}
