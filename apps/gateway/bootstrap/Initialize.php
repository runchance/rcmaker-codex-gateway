<?php
declare(strict_types=1);
namespace app\gateway\bootstrap;

use app\gateway\database\Schema;

/** Initializes only API/probe groups; failures leave the offline static console available. */
final class Initialize implements \RC\Bootstrap
{
    /** Framework bootstrap entry. @param mixed $worker Optional framework argument. @return void */
    public static function start($worker = null): void
    {
        try {
            Schema::initialize();
        } catch (\Throwable $error) {
            // The API reports a safe storage error; the probe worker retries with a bounded interval.
        }
    }
}
