<?php
declare(strict_types=1);
namespace support\process;

/** Resolve the Codex state directory for the actual OS user running RCMAKER. */
final class CodexEnvironment
{
    /**
     * Resolve with injectable inputs so Windows can deterministically cover Linux/macOS rules.
     * CODEX_HOME follows Codex itself; otherwise use the current platform user's home + /.codex.
     *
     * @param array<string,mixed> $environment
     */
    public static function resolve(array $environment, string $osFamily, ?string $unixAccountHome = null): string
    {
        $codexHome = self::value($environment['CODEX_HOME'] ?? null);
        if ($codexHome !== '') return self::normalize($codexHome, $osFamily);

        if ($osFamily === 'Windows') {
            $home = self::value($environment['USERPROFILE'] ?? null);
            if ($home === '') {
                $drive = self::value($environment['HOMEDRIVE'] ?? null);
                $path = self::value($environment['HOMEPATH'] ?? null);
                if ($drive !== '' && $path !== '') $home = $drive . $path;
            }
            if ($home === '') $home = self::value($environment['HOME'] ?? null);
        } else {
            $home = self::value($environment['HOME'] ?? null);
            if ($home === '') $home = self::value($unixAccountHome);
        }

        return $home === '' ? '' : rtrim(self::normalize($home, $osFamily), '/') . '/.codex';
    }

    /** Resolve from the real process environment/effective user. */
    public static function detect(): string
    {
        $environment = getenv();
        if (!is_array($environment)) $environment = [];
        $unixAccountHome = null;
        if (PHP_OS_FAMILY !== 'Windows' && function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
            $account = @posix_getpwuid(posix_geteuid());
            if (is_array($account)) $unixAccountHome = self::value($account['dir'] ?? null);
        }
        return self::resolve($environment, PHP_OS_FAMILY, $unixAccountHome);
    }

    private static function value(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }

    /** Preserve Unix filename characters; backslashes are separators only on Windows. */
    private static function normalize(string $path, string $osFamily): string
    {
        $path = trim($path);
        if ($osFamily === 'Windows') $path = str_replace('\\', '/', $path);
        $normalized = rtrim($path, '/');
        return $normalized === '' ? '/' : $normalized;
    }
}
