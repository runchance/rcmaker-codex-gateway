<?php
declare(strict_types=1);

namespace RC {
    /** Test-only configuration adapter; no framework bootstrap or live application is loaded. */
    final class Config
    {
        public static function get(string $name, ?string $key = null): mixed
        {
            if ($name === 'release') return 'v2.7.3-beta.1';
            return ['console_origin' => 'http://127.0.0.1:28910', 'data_directory' => 'data'];
        }
    }
}
namespace app\gateway\model {
    /** Only the banner's locale projection is needed by this isolated rendering test. */
    final class GatewayPresentation
    {
        public static string $language = 'en';
        public static function locale(): string { return self::$language; }
    }
}
namespace {
    function rcEnv(string $name, mixed $default = null): mixed { return $default; }

    $root = dirname(__DIR__, 2);
    $fixture = $root . '/data/generated/release-identity-' . bin2hex(random_bytes(5));
    foreach (['config', 'vendor', 'elsewhere'] as $directory) {
        if (!mkdir($fixture . '/' . $directory, 0777, true)) throw new RuntimeException('Fixture creation failed');
    }
    copy($root . '/index.php', $fixture . '/index.php');
    file_put_contents($fixture . '/vendor/autoload.php', '<?php file_put_contents(__DIR__ . "/bootstrap-called", "yes"); throw new RuntimeException("Forbidden bootstrap");');
    $identity = ['tag' => 'v2.7.3-beta.1', 'commit' => str_repeat('b', 40), 'php' => '8.5',
        'encrypted' => true, 'platform' => 'windows', 'arch' => 'x86_64'];
    file_put_contents($fixture . '/config/release.php', '<?php return ' . var_export($identity, true) . ';');
    $checks = 0;
    function identityCheck(string $name, bool $passed): void
    {
        global $checks;
        if (!$passed) throw new RuntimeException('FAIL ' . $name);
        ++$checks;
        echo 'PASS ', $name, PHP_EOL;
    }
    /** Invoke the real entry file from another working directory without any runtime dependencies. */
    function identityQuery(string $argument): array
    {
        global $fixture;
        $process = proc_open([PHP_BINARY, $fixture . '/index.php', $argument],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $fixture . '/elsewhere');
        if (!is_resource($process)) throw new RuntimeException('CLI fixture could not start');
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        return [proc_close($process), $output, $error];
    }
    [$exit, $output, $error] = identityQuery('--version');
    identityCheck('version-reports-full-tag', $exit === 0 && trim($output) === 'RCMAKER AI Gateway v2.7.3-beta.1' && $error === '');
    [$exit, $output, $error] = identityQuery('--build-info');
    $info = json_decode($output, true, 64, JSON_THROW_ON_ERROR);
    identityCheck('metadata-query-succeeds', $exit === 0 && $error === '');
    foreach ($identity as $key => $value) identityCheck('immutable-' . $key, ($info[$key] ?? null) === $value);
    identityCheck('actual-runtime-evidence', ($info['runtime']['php'] ?? null) === PHP_VERSION
        && ($info['runtime']['sapi'] ?? null) === PHP_SAPI
        && ($info['runtime']['os'] ?? null) === PHP_OS_FAMILY);
    identityCheck('queries-never-load-framework', !file_exists($fixture . '/vendor/bootstrap-called'));
    identityCheck('queries-never-initialize-data-or-runtime', !file_exists($fixture . '/data') && !file_exists($fixture . '/runtime'));
    foreach (['en', 'zh-CN'] as $locale) {
        \app\gateway\model\GatewayPresentation::$language = $locale;
        $banner = require $root . '/config/banner.php';
        identityCheck($locale . '-banner-uses-the-release-tag', $banner['app']['version'] === $identity['tag']
            && str_contains($banner['lines'][0]['text'], '{app.version}'));
    }
    echo 'TOTAL ', $checks, ' FAILED 0', PHP_EOL;
}
