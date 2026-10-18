<?php
declare(strict_types=1);
namespace app\gateway\bootstrap;

use app\gateway\database\Schema;
use app\gateway\model\StartupProgress;

/** Initializes gateway worker storage and reports upgrades; failures leave the offline static console available. */
final class Initialize implements \RC\Bootstrap
{
    /** Framework bootstrap entry. @param mixed $worker Optional framework argument. @return void */
    public static function start($worker = null): void
    {
        try {
            // Only gateway worker startup may write human progress. MCP/script stdout
            // remains a protocol stream, and ordinary HTTP initialization stays silent.
            $progress = in_array(PHP_SAPI, ['cli', 'micro'], true) && !defined('IS_SCRIPT') && defined('STDOUT')
                ? static function (array $state): void {
                    $output = StartupProgress::render(StartupProgress::schemaLines($state));
                    if ($output !== '') fwrite(STDOUT, $output);
                } : null;
            Schema::initialize($progress);
        } catch (\Throwable $error) {
            // The API reports a safe storage error; the probe worker retries with a bounded interval.
        }
    }
}
