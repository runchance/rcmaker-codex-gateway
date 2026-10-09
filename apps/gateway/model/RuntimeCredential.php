<?php
declare(strict_types=1);
namespace app\gateway\model;

use RC\Config;

/**
 * Project-private secretbox storage for local runtime credentials.
 * Ciphertext lives in SQLite; the random key stays inside this application's data directory.
 */
final class RuntimeCredential
{
    private const PREFIX = 'v1:';

    public static function available(): bool
    {
        return function_exists('sodium_crypto_secretbox')
            && function_exists('sodium_crypto_secretbox_open')
            && defined('SODIUM_CRYPTO_SECRETBOX_KEYBYTES');
    }

    public static function protect(#[\SensitiveParameter] string $value): string
    {
        if (!self::available() || $value === '' || strlen($value) > 65536) {
            throw new GatewayError('RUNTIME_SECRET_UNAVAILABLE', '当前运行环境无法安全保存工具隧道密钥。', 422);
        }
        $key = self::key();
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = sodium_crypto_secretbox($value, $nonce, $key);
        sodium_memzero($key);
        return self::PREFIX . base64_encode($nonce . $cipher);
    }

    public static function reveal(#[\SensitiveParameter] string $protected): string
    {
        if (!self::available() || !str_starts_with($protected, self::PREFIX)) {
            throw new GatewayError('RUNTIME_SECRET_UNAVAILABLE', '工具隧道密钥无法读取，请重新输入。', 422);
        }
        $raw = base64_decode(substr($protected, strlen(self::PREFIX)), true);
        if (!is_string($raw) || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new GatewayError('RUNTIME_SECRET_UNAVAILABLE', '工具隧道密钥无法读取，请重新输入。', 422);
        }
        $key = self::key();
        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, $key);
        sodium_memzero($key);
        if (!is_string($plain)) {
            throw new GatewayError('RUNTIME_SECRET_UNAVAILABLE', '工具隧道密钥无法读取，请重新输入。', 422);
        }
        return $plain;
    }

    private static function key(): string
    {
        $path = (string) Config::get('gateway', 'runtime_secret_key_file');
        $directory = dirname($path);
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new GatewayError('RUNTIME_SECRET_UNAVAILABLE', '无法创建项目私有密钥目录。', 503);
        }
        if (is_file($path)) {
            $key = @file_get_contents($path);
            if (is_string($key) && strlen($key) === SODIUM_CRYPTO_SECRETBOX_KEYBYTES) return $key;
            throw new GatewayError('RUNTIME_SECRET_UNAVAILABLE', '项目私有密钥文件已损坏。', 503);
        }
        $key = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
        $handle = @fopen($path, 'x+b');
        if ($handle === false) {
            if (is_file($path)) return self::key();
            throw new GatewayError('RUNTIME_SECRET_UNAVAILABLE', '无法创建项目私有密钥文件。', 503);
        }
        try {
            if (@fwrite($handle, $key) !== strlen($key) || !@fflush($handle)) {
                throw new GatewayError('RUNTIME_SECRET_UNAVAILABLE', '项目私有密钥文件写入失败。', 503);
            }
        } finally {
            fclose($handle);
        }
        @chmod($path, 0600);
        return $key;
    }
}
