<?php
declare(strict_types=1);
namespace app\gateway\model;

/** Startup preferences outside the binary; SQLite remains the console authority. */
final class GatewayPresentation
{
    /** Read locale without opening a database in the pre-fork banner process. */
    public static function locale(): string
    {
        $fallback = (string) \RC\Config::get('gateway', 'default_locale');
        $path = \RC\Config::get('gateway', 'presentation_file');
        if (!is_file($path) || filesize($path) > 1024) return $fallback;
        $data = json_decode((string) file_get_contents($path), true);
        return in_array($data['locale'] ?? null, ['en', 'zh-CN'], true) ? $data['locale'] : $fallback;
    }

    /** Mirror committed locale for the next startup banner, without secrets or task state. */
    public static function publishLocale(string $locale): void
    {
        if (!in_array($locale, ['en', 'zh-CN'], true)) return;
        $path = \RC\Config::get('gateway', 'presentation_file');
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new GatewayError('PRESENTATION_UNAVAILABLE', 'Cannot save startup language preferences.', 503);
        }
        $temporary = $path . '.tmp-' . bin2hex(random_bytes(8));
        $content = json_encode(['locale' => $locale], JSON_THROW_ON_ERROR) . "\n";
        if (file_put_contents($temporary, $content, LOCK_EX) !== strlen($content) || !rename($temporary, $path)) {
            @unlink($temporary);
            throw new GatewayError('PRESENTATION_UNAVAILABLE', 'Language was saved, but the next-start banner preference could not be written.', 503);
        }
    }
}
