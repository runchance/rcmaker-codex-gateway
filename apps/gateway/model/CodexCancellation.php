<?php
declare(strict_types=1);
namespace app\gateway\model;

use RC\Config;

/** Read explicit local Codex interruption events; a closed HTTP stream is never cancellation. */
final class CodexCancellation
{
    private const READ_LIMIT = 524288;
    /** Bounded, worker-local file cursors, without message bodies or authentication data. */
    private array $files = [];

    /**
     * Read a complete, structured interruption for precisely this session and native turn.
     * Missing/inaccessible/ambiguous files and incomplete records provide no stop evidence.
     * @param string $threadId Native session UUID. @param string $turnId Native turn UUID.
     * @param int $notBefore Earliest gateway admission time for this native turn.
     * @return ?array{turn_id:string,interrupted_at:int,reason:string} Verified event metadata only.
     */
    public function interruption(string $threadId, string $turnId, int $notBefore): ?array
    {
        if (!self::uuid($threadId) || !self::uuid($turnId)) return null;
        $home = trim((string) Config::get('gateway', 'codex_home'));
        if ($home === '') return null;
        $root = realpath(rtrim($home, PHP_OS_FAMILY === 'Windows' ? '/\\' : '/') . '/sessions');
        if ($root === false) return null;
        $key = $root . '|' . $threadId . '|' . $turnId;
        if (!isset($this->files[$key])) {
            if (count($this->files) >= 32) array_shift($this->files);
            $this->files[$key] = ['path' => null, 'lookup_at' => 0.0, 'offset' => null, 'event' => null];
        }
        $cursor =& $this->files[$key];
        if ($cursor['path'] === null) {
            if (microtime(true) - $cursor['lookup_at'] < 15.0) return null;
            $cursor['lookup_at'] = microtime(true);
            $literalRoot = strtr($root, ['[' => '[[]', '*' => '[*]', '?' => '[?]']);
            $paths = glob($literalRoot . '/*/*/*/rollout-*-' . $threadId . '.jsonl', GLOB_NOESCAPE) ?: [];
            if (count($paths) !== 1) return null;
            $cursor['path'] = realpath($paths[0]);
        }
        $path = $cursor['path'];
        $boundary = $root . '/';
        $resolved = is_string($path) ? realpath($path) : false;
        $candidate = $resolved === false ? '' : $resolved;
        if (PHP_OS_FAMILY === 'Windows') {
            $boundary = strtolower(str_replace('\\', '/', $boundary));
            $candidate = strtolower(str_replace('\\', '/', $candidate));
        }
        if ($resolved === false || !is_file($resolved) || !str_starts_with($candidate, $boundary)) return null;
        $stream = @fopen($resolved, 'rb');
        if (!is_resource($stream)) return null;
        try {
            $header = fgets($stream, 65536);
            $meta = is_string($header) && str_ends_with($header, "\n") ? json_decode($header, true) : null;
            if (($meta['type'] ?? '') !== 'session_meta' || ($meta['payload']['id'] ?? '') !== $threadId) return null;
            $size = (int) (fstat($stream)['size'] ?? 0);
            $offset = $cursor['offset'];
            if ($offset === null || $offset > $size || $size - $offset > self::READ_LIMIT) {
                $offset = max(0, $size - self::READ_LIMIT);
                $cursor['event'] = null;
                fseek($stream, max(0, $offset - 1));
                $atBoundary = $offset === 0 || fread($stream, 1) === "\n";
                fseek($stream, $offset);
                if (!$atBoundary) {
                    $fragment = fgets($stream, self::READ_LIMIT + 1);
                    // A growing, very large JSON record must not turn this bounded tail into a full read.
                    if (!is_string($fragment) || !str_ends_with($fragment, "\n")) return null;
                }
                $offset = ftell($stream);
            }
            fseek($stream, $offset);
            $available = min(self::READ_LIMIT, max(0, $size - $offset));
            $bytes = $available > 0 ? fread($stream, $available) : '';
            if (!is_string($bytes)) return null;
            $end = strrpos($bytes, "\n");
            if ($end !== false) {
                foreach (explode("\n", substr($bytes, 0, $end)) as $line) {
                    // Avoid decoding large prompts, tool payloads or model reasoning.
                    if (strlen($line) > 16384 || !str_contains($line, 'turn_aborted')) continue;
                    $record = json_decode($line, true, 16);
                    $event = $record['payload'] ?? null;
                    if (($record['type'] ?? '') !== 'event_msg' || !is_array($event)
                        || ($event['type'] ?? '') !== 'turn_aborted' || ($event['reason'] ?? '') !== 'interrupted'
                        || ($event['turn_id'] ?? '') !== $turnId) continue;
                    $at = is_string($record['timestamp'] ?? null) ? strtotime($record['timestamp']) : false;
                    if ($at !== false && $at >= $notBefore && $at <= time() + 5) {
                        $cursor['event'] = ['turn_id' => $turnId, 'interrupted_at' => $at, 'reason' => 'interrupted'];
                    }
                }
                $cursor['offset'] = $offset + $end + 1;
            } else {
                $cursor['offset'] = $offset;
            }
            return is_array($cursor['event']) && $cursor['event']['interrupted_at'] >= $notBefore ? $cursor['event'] : null;
        } finally { fclose($stream); }
    }

    /** Accept only native UUID identities; never interpolate arbitrary request text into a path. */
    public static function uuid(string $value): bool
    {
        return preg_match('/\A[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\z/D', $value) === 1;
    }
}
