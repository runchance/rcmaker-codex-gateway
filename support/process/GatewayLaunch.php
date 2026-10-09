<?php
declare(strict_types=1);
namespace support\process;

/** Installation-local supervisor lock, outside the source archive and independent of cwd. */
final class GatewayLaunch
{
    /** @var resource|null Held until supervisor exit. */
    private static $lock = null;

    /** Acquire only in the main Windows start supervisor, never in MCP/test/worker children. */
    public static function acquire(): void
    {
        global $argv;
        if (PHP_OS_FAMILY !== 'Windows' || defined('IS_SCRIPT') || self::$lock !== null
            || strtolower((string) ($argv[1] ?? '')) !== 'start') return;
        $directory = \RC\Config::get('gateway', 'data_directory') . '/runtime';
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException('Cannot create the external gateway data directory.');
        }
        $lock = fopen($directory . '/application.lock', 'c+');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            throw new \RuntimeException('RCMAKER AI Gateway is already running. Close its current window before starting again.');
        }
        self::$lock = $lock;
    }
}
