<?php
declare(strict_types=1);
namespace support\process;

/** Commands for shipped gateway child modes, usable from source and a Micro executable. */
final class GatewayEntrypoint
{
    /** @param string $mode Internal entry mode. @param array $arguments Child arguments. @return array */
    public static function command(string $mode, array $arguments = []): array
    {
        if (!in_array($mode, ['--gateway-mcp', '--gateway-runtime-guard'], true)) {
            throw new \InvalidArgumentException('Unknown gateway child mode.');
        }
        // Micro SFX may expose an empty PHP_BINARY. The native executable, rather than
        // a PHAR code path, is authoritative for respawning the packaged application.
        $program = PHP_SAPI === 'micro'
            ? (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) ?: '') : PHP_BINARY;
        if ($program === '' || !is_file($program)) throw new \RuntimeException('Gateway runtime executable is unavailable.');
        $prefix = [$program];
        if (PHP_SAPI !== 'micro') {
            if ($mode === '--gateway-runtime-guard' && PHP_OS_FAMILY === 'Windows') $prefix[] = '-n';
            $prefix[] = \Phar::running(false) ?: dirname(__DIR__, 2) . '/index.php';
        }
        return [...$prefix, $mode, ...$arguments];
    }
}
