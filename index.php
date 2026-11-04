<?php
define('ROOT_PATH', dirname(__FILE__));
// Windows handler children do not apply the framework's per-process memory setting.
// Set the gateway default before bootstrap, including each fresh Micro process.
ini_set('memory_limit', '-1');
// Query the encrypted package before framework bootstrap; do not open ports or initialize data.
if (in_array($argv[1] ?? '', ['--version', '--build-info'], true)) {
    $release = require ROOT_PATH . '/config/release.php';
    if ($argv[1] === '--version') {
        echo 'RCMAKER AI Gateway ', $release['tag'], PHP_EOL;
    } else {
        $extensions = [];
        foreach (['curl', 'openssl', 'pdo_sqlite', 'sqlite3', 'mbstring', 'sockets', 'zip', 'phar', 'sodium', 'pcntl', 'posix'] as $extension) {
            $extensions[$extension] = extension_loaded($extension);
        }
        echo json_encode(['name' => 'RCMAKER AI Gateway'] + $release + [
            'runtime' => ['php' => PHP_VERSION, 'sapi' => PHP_SAPI, 'os' => PHP_OS_FAMILY,
                'arch' => php_uname('m'), 'memory_limit' => ini_get('memory_limit'), 'extensions' => $extensions],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), PHP_EOL;
    }
    exit;
}
// Child entries ship inside the code archive; a clean installation needs no data/scripts.
if (($argv[1] ?? '') === '--gateway-runtime-guard') {
    require ROOT_PATH . '/support/process/runtime-guard.php';
    exit;
}
if (($argv[1] ?? '') === '--gateway-mcp') {
    array_splice($argv, 1, 1);
    require ROOT_PATH . '/apps/gateway/http/mcp-entry.php';
    exit;
}
require_once(ROOT_PATH."/vendor/autoload.php");
?>
