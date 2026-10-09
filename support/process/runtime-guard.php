<?php
declare(strict_types=1);
/** Private PHP child entry; no framework HTTP server, database connection or external request is started here. */
require __DIR__ . '/OwnedRuntime.php';
try {
    exit(\support\process\OwnedRuntime::guard());
} catch (Throwable $error) {
    $message = '[runtime-guard] ' . get_class($error) . ': ' . $error->getMessage() . PHP_EOL;
    $log = getenv('RCMAKER_GUARD_LOG');
    if (is_string($log) && $log !== '') file_put_contents($log, $message, FILE_APPEND);
    else fwrite(STDERR, $message);
    exit(1);
}
