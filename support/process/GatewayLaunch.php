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
        self::lockInstallation();
    }

    /** Run an explicit offline database compaction without starting any gateway workers. */
    public static function compactDatabase(): int
    {
        try {
            self::lockInstallation();
            \Workerman\Timer::init(new \Workerman\Events\Select());
            \RC\Helper\Db\Medoo::start();
            $size = \app\gateway\database\Schema::compactOffline(static function (int $bytes): void {
                fwrite(STDOUT, 'Reclaiming database space. Initial size: ' . $bytes . ' bytes.' . PHP_EOL);
            });
            fwrite(STDOUT, 'Database size: ' . $size['before'] . ' → ' . $size['after'] . ' bytes.' . PHP_EOL);
            return 0;
        } catch (\Throwable $error) {
            $detail = $error instanceof \app\gateway\model\GatewayError ? $error->getMessage() :
                'Close all RCMAKER processes and check available disk space before retrying.';
            fwrite(STDERR, 'Database compaction failed. ' . $detail . PHP_EOL);
            return 1;
        }
    }

    /** Serialize with other offline runs, and with the Windows start supervisor. */
    private static function lockInstallation(): void
    {
        $directory = \RC\Config::get('gateway', 'data_directory') . '/runtime';
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException('Cannot create the external gateway data directory.');
        }
        $lockName = getenv('PHP_GATEWAY_TEST_MODE') === '1'
            ? basename((string) \RC\Config::get('gateway', 'database_file')) . '.application.lock' : 'application.lock';
        $lock = fopen($directory . '/' . $lockName, 'c+');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) fclose($lock);
            throw new \RuntimeException('RCMAKER AI Gateway is already running. Close its current window before starting again.');
        }
        self::$lock = $lock;
    }
}
