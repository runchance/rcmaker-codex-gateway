<?php
declare(strict_types=1);
namespace support\http;

use app\gateway\model\GatewayError;

/** Shared, bounded request decoding for ingress/API workers and Codex capability negotiation. */
final class RequestCompression
{
    /**
     * Test the actual decoder, including a streaming frame without an advertised content size.
     * The result is immutable for this PHP process; it never probes another process or edits config.
     * @return bool Whether this runtime can safely accept Codex's Zstandard request bodies.
     */
    public static function zstdAvailable(): bool
    {
        static $available = null;
        if ($available !== null) return $available;
        $available = false;
        if (!function_exists('zstd_uncompress_init') || !function_exists('zstd_uncompress_add')) return false;
        try {
            $available = self::zstd("\x28\xb5\x2f\xfd\x20\x02\x11\x00\x00{}", 32) === '{}'
                && self::zstd("\x28\xb5\x2f\xfd\x00\x00\x11\x00\x00{}", 32) === '{}';
        } catch (\Throwable) { $available = false; }
        return $available;
    }

    /**
     * Decode an inspection copy while retaining the original HTTP body for native forwarding.
     * @param string $raw Exact wire bytes. @param string $encoding Content-Encoding header.
     * @param int $maxBytes Existing application limit for both wire and decoded bytes.
     * @return string Complete decoded body, never a truncated prefix.
     * @throws GatewayError On unsupported encoding, invalid data or an exceeded byte/window budget.
     */
    public static function decode(string $raw, string $encoding, int $maxBytes): string
    {
        if (strlen($raw) > $maxBytes) self::tooLarge(false);
        $encoding = strtolower(trim($encoding));
        if ($encoding === '' || $encoding === 'identity') return $raw;
        if ($encoding === 'gzip') $decoded = @gzdecode($raw, $maxBytes + 1);
        elseif ($encoding === 'zstd' && self::zstdAvailable()) return self::zstd($raw, $maxBytes);
        else throw new GatewayError('unsupported_content_encoding',
            '当前 PHP 运行时不支持 ' . $encoding . ' 请求压缩，请通过接入配置关闭客户端请求压缩。', 415);
        if (!is_string($decoded)) self::invalid();
        if (strlen($decoded) > $maxBytes) self::tooLarge();
        return $decoded;
    }

    /**
     * Walk only the standard frame/block envelope; libzstd performs all actual decompression.
     * Incremental extension calls accept incomplete frames, so a last-block/checksum boundary
     * must be established explicitly. Each call contains at most one 128-KiB decoded block.
     * @param string $wire Exact Zstandard frames, optionally with standard skippable frames.
     * @param int $maxBytes Maximum total decoded body; advertised sizes are checked before allocation.
     * @return string Concatenated complete frame contents.
     * @throws GatewayError On a malformed frame, decoder failure or exceeded budget.
     */
    private static function zstd(string $wire, int $maxBytes): string
    {
        $length = strlen($wire);
        if ($length === 0) self::invalid();
        $offset = 0;
        $decoded = '';
        while ($offset < $length) {
            $start = $offset;
            $magic = self::number($wire, $offset, 4);
            if (($magic & 0xfffffff0) === 0x184d2a50) {
                $skip = self::number($wire, $offset, 4);
                self::requireBytes($wire, $offset, $skip);
                $offset += $skip;
                continue;
            }
            if ($magic !== 0xfd2fb528) self::invalid();
            $descriptor = self::number($wire, $offset, 1);
            if ($descriptor & 8) self::invalid();
            $single = (bool) ($descriptor & 32);
            if (!$single) {
                $window = self::number($wire, $offset, 1);
                $base = 1 << (10 + ($window >> 3));
                if ($base + ($base >> 3) * ($window & 7) > max($maxBytes, 8 * 1024 * 1024)) self::tooLarge();
            }
            $dictionaryBytes = [0, 1, 2, 4][$descriptor & 3];
            self::number($wire, $offset, $dictionaryBytes);
            $sizeBytes = [($single ? 1 : 0), 2, 4, 8][$descriptor >> 6];
            $size = null;
            if ($sizeBytes > 0) {
                self::requireBytes($wire, $offset, $sizeBytes);
                $size = $sizeBytes === 2 ? 256 : 0;
                $remaining = $maxBytes - strlen($decoded) - $size;
                $value = 0;
                for ($i = $sizeBytes - 1; $i >= 0; --$i) {
                    $byte = ord($wire[$offset + $i]);
                    if ($remaining < $byte || $value > intdiv($remaining - $byte, 256)) self::tooLarge();
                    $value = $value * 256 + $byte;
                }
                $size += $value;
                $offset += $sizeBytes;
            }
            $context = @zstd_uncompress_init();
            if ($context === false) self::invalid();
            $frameStart = strlen($decoded);
            do {
                $block = self::number($wire, $offset, 3);
                $last = (bool) ($block & 1);
                $type = ($block >> 1) & 3;
                $blockSize = $block >> 3;
                if ($type === 3 || $blockSize > 131072) self::invalid();
                $bytes = $type === 1 ? 1 : $blockSize;
                if ($last && ($descriptor & 4)) $bytes += 4;
                self::requireBytes($wire, $offset, $bytes);
                $offset += $bytes;
                $chunk = @zstd_uncompress_add($context, substr($wire, $start, $offset - $start));
                if (!is_string($chunk)) self::invalid();
                if (strlen($chunk) > $maxBytes - strlen($decoded)) self::tooLarge();
                $decoded .= $chunk;
                $start = $offset;
            } while (!$last);
            if ($size !== null && strlen($decoded) - $frameStart !== $size) self::invalid();
        }
        return $decoded;
    }

    /** Read a small little-endian envelope integer (at most four bytes), checking truncation first. */
    private static function number(string $wire, int &$offset, int $bytes): int
    {
        self::requireBytes($wire, $offset, $bytes);
        $value = 0;
        for ($i = 0; $i < $bytes; ++$i) $value |= ord($wire[$offset + $i]) << (8 * $i);
        $offset += $bytes;
        return $value;
    }

    /** Require a complete envelope field or payload without copying its bytes. */
    private static function requireBytes(string $wire, int $offset, int $bytes): void
    {
        if ($bytes > strlen($wire) - $offset) self::invalid();
    }

    /** Keep malformed-frame failures on the existing application error contract. */
    private static function invalid(): never
    {
        throw new GatewayError('invalid_compressed_body', '压缩请求损坏。', 400);
    }

    /** Apply the existing 413 boundary before allocating an excessive output or decoder window. */
    private static function tooLarge(bool $decoded = true): never
    {
        throw new GatewayError('request_body_too_large', $decoded
            ? '解压后的 HTTP 请求正文超过网关字节上限。' : 'HTTP 请求正文超过网关字节上限。', 413);
    }
}
