<?php
declare(strict_types=1);
namespace app\gateway\model;

/** PHP o200k_base byte-pair token counter, using OpenAI's pinned vocabulary and pre-tokenizer. */
final class WebTokenizer
{
    /** SHA-256 of the immutable upstream LF vocabulary, also used by release verification. */
    public const VOCABULARY_SHA256 = '446a9538cb6c348e3516120d7c08b09f57c36495e2acfffe59a5bf8b0cfb1a2d';

    private static ?array $ranks = null;
    private static array $cache = [];
    private static array $boundedCache = [];

    /**
     * Count ordinary text; literal special-token strings remain user text, never control tokens.
     * @param bool $boundedLongAtoms Opt in only through a new pinned budget; legacy partitions use the byte bound.
     */
    public static function count(string $text, bool $boundedLongAtoms = false): int
    {
        if ($text === '') return 0;
        self::load();
        $pattern = <<<'REGEX'
~[^\r\n\p{L}\p{N}]?[\p{Lu}\p{Lt}\p{Lm}\p{Lo}\p{M}]*[\p{Ll}\p{Lm}\p{Lo}\p{M}]+(?i:'s|'t|'re|'ve|'m|'ll|'d)?|[^\r\n\p{L}\p{N}]?[\p{Lu}\p{Lt}\p{Lm}\p{Lo}\p{M}]+[\p{Ll}\p{Lm}\p{Lo}\p{M}]*(?i:'s|'t|'re|'ve|'m|'ll|'d)?|\p{N}{1,3}| ?[^\s\p{L}\p{N}]+[\r\n/]*|\s*[\r\n]+|\s+(?!\S)|\s+~u
REGEX;
        if (preg_match_all($pattern, $text, $matches) === false) throw new GatewayError('tokenizer_invalid_text', '上下文不是可计数的 UTF-8 文本。', 400);
        $count = 0;
        foreach ($matches[0] as $piece) {
            if (isset(self::$ranks[$piece])) { $count++; continue; }
            if (isset(self::$cache[$piece])) { $count += self::$cache[$piece]; continue; }
            // Extremely long unbroken records use a conservative bound, avoiding quadratic work
            // in the API event loop. Normal text is exact o200k_base; the budget reports this bound.
            if (strlen($piece) > 2048) {
                if (!$boundedLongAtoms) { $count += strlen($piece); continue; }
                // Reference-style bounded BPE, without sending an unbroken multi-MB
                // atom into a quadratic scan. Lost cross-chunk merges only overcount.
                foreach (mb_str_split($piece, 4096, 'UTF-8') as $chunk) $count += self::boundedPiece($chunk);
                continue;
            }
            $pieces = str_split($piece);
            while (count($pieces) > 1) {
                $rank = PHP_INT_MAX; $chosen = -1;
                for ($index = 0, $last = count($pieces) - 1; $index < $last; $index++) {
                    $candidate = self::$ranks[$pieces[$index] . $pieces[$index + 1]] ?? PHP_INT_MAX;
                    if ($candidate < $rank) { $rank = $candidate; $chosen = $index; }
                }
                if ($chosen < 0) break;
                array_splice($pieces, $chosen, 2, [$pieces[$chosen] . $pieces[$chosen + 1]]);
            }
            $size = count($pieces); $count += $size;
            if (count(self::$cache) >= 4096) self::$cache = [];
            if (strlen($piece) <= 256) self::$cache[$piece] = $size;
        }
        return $count;
    }

    /** Count one bounded ordinary BPE piece in O(bytes log bytes), with leftmost rank ties. */
    private static function boundedPiece(string $piece): int
    {
        if (isset(self::$ranks[$piece])) return 1;
        $key = hash('sha256', $piece);
        if (isset(self::$boundedCache[$key])) return self::$boundedCache[$key];
        $count = strlen($piece);
        $next = $previous = $lengths = $versions = [];
        for ($i = 0; $i < $count; $i++) {
            $next[$i] = $i + 1 < $count ? $i + 1 : -1;
            $previous[$i] = $i - 1;
            $lengths[$i] = 1;
            $versions[$i] = 0;
        }
        $queue = new \SplPriorityQueue();
        $queue->setExtractFlags(\SplPriorityQueue::EXTR_DATA);
        $enqueue = static function (int $left) use ($piece, &$next, &$lengths, &$versions, $queue): void {
            if ($left < 0 || $lengths[$left] === 0 || ($right = $next[$left]) < 0) return;
            $rank = self::$ranks[substr($piece, $left, $lengths[$left] + $lengths[$right])] ?? null;
            if ($rank !== null) $queue->insert([$left, $right, $versions[$left], $versions[$right]], [-$rank, -$left]);
        };
        for ($i = 0; $i < $count - 1; $i++) $enqueue($i);
        while (!$queue->isEmpty()) {
            [$left, $right, $leftVersion, $rightVersion] = $queue->extract();
            if ($lengths[$left] === 0 || $next[$left] !== $right
                || $versions[$left] !== $leftVersion || $versions[$right] !== $rightVersion) continue;
            $lengths[$left] += $lengths[$right];
            $lengths[$right] = 0;
            $next[$left] = $next[$right];
            $versions[$left]++;
            $versions[$right]++;
            $count--;
            if ($next[$left] >= 0) $previous[$next[$left]] = $left;
            $enqueue($previous[$left]);
            $enqueue($left);
        }
        if (count(self::$boundedCache) >= 1024) self::$boundedCache = [];
        return self::$boundedCache[$key] = $count;
    }

    /** Load immutable vocabulary once per worker; no network, environment cache or user state. */
    private static function load(): void
    {
        if (self::$ranks !== null) return;
        $path = base_path() . '/apps/gateway/resource/o200k_base.tiktoken';
        $hash = is_file($path) ? hash_file('sha256', $path) : false;
        if ($hash !== self::VOCABULARY_SHA256) {
            // Git may convert LF to CRLF on Windows. Accept only the same pinned vocabulary
            // after that exact reversal; token/rank changes, BOM and bare CR remain invalid.
            $contents = is_file($path) ? file_get_contents($path) : false;
            if ($contents === false || hash('sha256', str_replace("\r\n", "\n", $contents)) !== self::VOCABULARY_SHA256) {
                throw new GatewayError('tokenizer_unavailable', '项目内置上下文词表缺失或损坏，请修复运行组件。', 503);
            }
        }
        $ranks = []; $file = fopen($path, 'rb');
        try {
            while (($line = fgets($file)) !== false) {
                [$token, $rank] = explode(' ', trim($line), 2);
                $ranks[base64_decode($token, true)] = (int) $rank;
            }
        } finally { fclose($file); }
        self::$ranks = $ranks;
    }
}
