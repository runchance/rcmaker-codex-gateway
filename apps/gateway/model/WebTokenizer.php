<?php
declare(strict_types=1);
namespace app\gateway\model;

/** PHP o200k_base byte-pair token counter, using OpenAI's pinned vocabulary and pre-tokenizer. */
final class WebTokenizer
{
    private static ?array $ranks = null;
    private static array $cache = [];

    /** Count ordinary text; literal special-token strings remain user text, never control tokens. */
    public static function count(string $text): int
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
            if (strlen($piece) > 2048) { $count += strlen($piece); continue; }
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

    /** Load immutable vocabulary once per worker; no network, environment cache or user state. */
    private static function load(): void
    {
        if (self::$ranks !== null) return;
        $path = base_path() . '/apps/gateway/resource/o200k_base.tiktoken';
        if (!is_file($path) || hash_file('sha256', $path) !== '446a9538cb6c348e3516120d7c08b09f57c36495e2acfffe59a5bf8b0cfb1a2d') {
            throw new GatewayError('tokenizer_unavailable', '项目内置上下文词表缺失或损坏，请修复运行组件。', 503);
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
