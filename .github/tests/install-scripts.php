<?php

/**
 * Offline integration coverage for the public PowerShell and Bash installers.
 * Run: php .github/tests/install-scripts.php
 * Optional Windows dispatch simulation: php .github/tests/install-scripts.php --unix-dispatch-only "path/to/Git/bin/bash.exe"
 * Only inert fixtures are installed. The gateway and public GitHub never run.
 */
declare(strict_types=1);

const INSTALLER_REPOSITORY = 'runchance/rcmaker-codex-gateway';
const INSTALLER_BASENAME = 'rcmaker-codex-gateway';

/** Fail a test with the specific observed condition. */
function installerAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** Find a host command without launching it or changing global configuration. */
function installerCommand(string $name): ?string
{
    $extensions = PHP_OS_FAMILY === 'Windows' ? ['', '.exe'] : [''];
    foreach (explode(PATH_SEPARATOR, getenv('PATH') ?: '') as $directory) {
        foreach ($extensions as $extension) {
            $candidate = rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . $name . $extension;
            if (is_file($candidate) && (PHP_OS_FAMILY === 'Windows' || is_executable($candidate))) {
                return $candidate;
            }
        }
    }
    return null;
}

/** Remove only a resolved fixture descendant, including on a failed test. */
function installerRemoveFixture(string $path, string $allowedRoot): void
{
    $resolved = realpath($path);
    $root = realpath($allowedRoot);
    installerAssert($resolved !== false && $root !== false, 'Fixture cleanup path cannot be resolved.');
    $prefix = rtrim($root, '/\\') . DIRECTORY_SEPARATOR;
    installerAssert(str_starts_with($resolved, $prefix), 'Fixture cleanup escaped data/generated.');
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($resolved, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $entry) {
        if ($entry->isDir() && !$entry->isLink()) {
            rmdir($entry->getPathname());
        } else {
            unlink($entry->getPathname());
        }
    }
    rmdir($resolved);
}

/** Produce a portable USTAR member without invoking a target executable. */
function installerTarMember(string $name, string $content, int $mode): string
{
    $header = str_pad($name, 100, "\0")
        . sprintf('%07o', $mode) . "\0"
        . '0000000' . "\0" . '0000000' . "\0"
        . sprintf('%011o', strlen($content)) . "\0"
        . '00000000000' . "\0" . str_repeat(' ', 8)
        . '0' . str_repeat("\0", 100)
        . 'ustar' . "\0" . '00'
        . str_repeat("\0", 32 + 32 + 8 + 8 + 155 + 12);
    installerAssert(strlen($header) === 512, 'Invalid fixture tar header.');
    $checksum = array_sum(unpack('C*', $header));
    $header = substr_replace($header, sprintf('%06o', $checksum) . "\0 ", 148, 8);
    return $header . $content . str_repeat("\0", (512 - strlen($content) % 512) % 512);
}

/** Build every supported platform artifact and its real SHA-256 receipt. */
function installerReleaseFixtures(string $directory, string $tag): array
{
    $extras = [];
    foreach (['.env.example', 'LICENSE', 'NOTICE', 'THIRD_PARTY_NOTICES'] as $name) {
        $extras[$name] = $name . " fixture\n";
    }
    if ($tag === 'v1.0.0') {
        unset($extras['.env.example']);
    }
    $checksums = [];
    $binaries = [];
    foreach ([['windows', 'x86_64'], ['linux', 'x86_64'], ['linux', 'aarch64'], ['macos', 'x86_64'], ['macos', 'aarch64']] as [$system, $architecture]) {
        $stem = INSTALLER_BASENAME . "-$tag-$system-$architecture";
        $executable = INSTALLER_BASENAME . ($system === 'windows' ? '.exe' : '');
        $binary = ($system === 'windows' ? 'MZ' : ($system === 'macos' ? "\xcf\xfa\xed\xfe" : "\x7fELF"))
            . str_repeat("$tag-$system-$architecture fixture\n", 8);
        $binaries["$system-$architecture"] = $binary;
        $members = [$executable => $binary] + $extras;
        $asset = $stem . ($system === 'windows' ? '.zip' : '.tar.gz');
        $path = $directory . DIRECTORY_SEPARATOR . $asset;
        if ($system === 'windows') {
            $archive = new ZipArchive();
            installerAssert($archive->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true, 'Cannot create fixture ZIP.');
            foreach ($members as $name => $content) {
                installerAssert($archive->addFromString($name, $content), 'Cannot write fixture ZIP member.');
            }
            installerAssert($archive->close(), 'Cannot close fixture ZIP.');
        } else {
            $tar = '';
            foreach ($members as $name => $content) {
                $tar .= installerTarMember($name, $content, $name === $executable ? 0755 : 0644);
            }
            file_put_contents($path, gzencode($tar . str_repeat("\0", 1024), 9));
        }
        $checksums[] = hash('sha256', $binary) . '  ' . $stem . ($system === 'windows' ? '.exe' : '');
        $checksums[] = hash_file('sha256', $path) . '  ' . $asset;
    }
    file_put_contents($directory . DIRECTORY_SEPARATOR . 'SHA256SUMS', implode("\n", $checksums) . "\n");
    return $binaries;
}

/** Invoke only an installer copy with closed stdin and a bounded process wait. */
function installerRun(array $command, string $cwd, array $environment, string $logs): array
{
    $stdout = $logs . '.out';
    $stderr = $logs . '.err';
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['file', $stdout, 'w'], 2 => ['file', $stderr, 'w']], $pipes, $cwd, $environment);
    installerAssert(is_resource($process), 'Cannot start isolated installer.');
    fclose($pipes[0]);
    $deadline = microtime(true) + 60;
    do {
        $status = proc_get_status($process);
        if (!$status['running']) {
            $code = $status['exitcode'];
            proc_close($process);
            return [$code, file_get_contents($stdout) . file_get_contents($stderr)];
        }
        if (microtime(true) >= $deadline) {
            proc_terminate($process);
            proc_close($process);
            throw new RuntimeException('Installer exceeded 60 seconds. ' . file_get_contents($stdout) . file_get_contents($stderr));
        }
        usleep(25_000);
    } while (true);
}

/** Single localhost release server shared by all isolated installation cases. */
final class InstallerFixtureServer
{
    public string $base;
    private $process;

    /** Bind an ephemeral localhost server; fixtures never use public network URLs. */
    public function __construct(private string $directory)
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        installerAssert(is_resource($socket), 'Cannot reserve localhost fixture port: ' . $error);
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        $this->base = 'http://' . $address;
        $router = <<<'PHP'
<?php
declare(strict_types=1);
$root = __DIR__;
$state = json_decode(file_get_contents($root . '/state.json'), true, 512, JSON_THROW_ON_ERROR);
$tag = $state['tag'];
$base = 'http://' . $_SERVER['HTTP_HOST'];
$release = '/runchance/rcmaker-codex-gateway/releases';
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
file_put_contents($root . '/requests.log', $path . "\n", FILE_APPEND | LOCK_EX);
if ($path === $release . '/latest') {
    header('Location: ' . $base . $release . '/tag/' . $tag, true, 302);
    exit;
}
if ($path === $release . '/tag/' . $tag) {
    echo 'Fixture Release';
    exit;
}
if ($path === '/repos/runchance/rcmaker-codex-gateway/releases/latest') {
    $assets = [];
    foreach (glob($root . '/' . $tag . '/*') as $file) {
        $name = basename($file);
        $assets[] = ['name' => $name, 'state' => 'uploaded', 'browser_download_url' => $base . $release . '/download/' . $tag . '/' . $name];
    }
    header('Content-Type: application/json');
    echo json_encode(['tag_name' => $tag, 'draft' => false, 'prerelease' => false, 'assets' => $assets], JSON_THROW_ON_ERROR);
    exit;
}
$prefix = $release . '/download/' . $tag . '/';
if (str_starts_with($path, $prefix)) {
    $name = substr($path, strlen($prefix));
    if (preg_match('/^[A-Za-z0-9_.-]+$/D', $name) && is_file($root . '/' . $tag . '/' . $name)) {
        header('Content-Type: application/octet-stream');
        readfile($root . '/' . $tag . '/' . $name);
        if ($state['tamper'] && $name !== 'SHA256SUMS') {
            echo 'tampered';
        }
        exit;
    }
}
http_response_code(404);
echo 'Not Found';
PHP;
        file_put_contents($directory . '/router.php', $router);
        $this->state('v1.0.0');
        $this->process = proc_open([PHP_BINARY, '-S', $address, $directory . '/router.php'], [0 => ['pipe', 'r'], 1 => ['file', $directory . '/server.out', 'w'], 2 => ['file', $directory . '/server.err', 'w']], $pipes, $directory);
        installerAssert(is_resource($this->process), 'Cannot start localhost fixture server.');
        fclose($pipes[0]);
        $deadline = microtime(true) + 5;
        do {
            $connection = @stream_socket_client('tcp://' . $address, $errno, $error, 0.1);
            if (is_resource($connection)) {
                fclose($connection);
                return;
            }
            usleep(25_000);
        } while (microtime(true) < $deadline);
        $this->stop();
        throw new RuntimeException('Localhost fixture server did not become ready.');
    }

    /** Select the mock stable release and corruption mode before the next request. */
    public function state(string $tag, bool $tamper = false): void
    {
        file_put_contents($this->directory . '/state.json', json_encode(['tag' => $tag, 'tamper' => $tamper], JSON_THROW_ON_ERROR));
    }

    /** Stop only this test's localhost server, never the application. */
    public function stop(): void
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
        }
        $this->process = null;
    }
}

/** Each lifecycle proves actual downloaded bytes, integrity rejection, and upgrade preservation. */
function installerScenario(string $root, string $fixture, InstallerFixtureServer $server, array $versions, array $variant): void
{
    [$system, $architecture, $mode, $command] = $variant;
    $directory = $fixture . '/case-' . $system . '-' . $architecture . '-' . $mode . '-' . bin2hex(random_bytes(3));
    mkdir($directory, 0777, true);
    $cwd = $directory . '/working folder';
    mkdir($cwd);
    $exe = INSTALLER_BASENAME . ($system === 'windows' ? '.exe' : '');
    $install = match ($mode) {
        'default-child', 'existing-child' => $cwd . '/' . INSTALLER_BASENAME,
        'existing-current' => $cwd,
        'relative' => $cwd . '/chosen folder',
        'override' => $cwd . '/command-line folder',
        default => $directory . '/explicit install folder',
    };
    if (str_starts_with($mode, 'existing-')) {
        if (!is_dir($install)) {
            mkdir($install, 0777, true);
        }
        file_put_contents($install . '/' . $exe, 'old fixture before latest install');
    }
    $sourcePath = $root . '/.github/scripts/install.' . ($system === 'windows' ? 'ps1' : 'sh');
    $source = file_get_contents($sourcePath);
    if ($system === 'windows') {
        $source = str_replace('https://api.github.com/repos/$repository/releases/latest', $server->base . '/repos/$repository/releases/latest', $source);
        $source = str_replace('https://github.com/$repository/releases/download/$tag/$name', $server->base . '/' . INSTALLER_REPOSITORY . '/releases/download/$tag/$name', $source);
    } else {
        $source = str_replace('https://github.com/' . INSTALLER_REPOSITORY, $server->base . '/' . INSTALLER_REPOSITORY, $source);
        $source = str_replace("--proto '=https' --proto-redir '=https'", "--proto '=http' --proto-redir '=http'", $source);
        if ($mode === 'dispatch') {
            $shim = $directory . '/shim';
            mkdir($shim);
            file_put_contents($shim . '/uname', "#!/usr/bin/env bash\ncase \"\$1\" in -s) printf '%s\\n' '" . ($system === 'macos' ? 'Darwin' : 'Linux') . "';; -m) printf '%s\\n' '$architecture';; *) exit 1;; esac\n");
            file_put_contents($shim . '/lsof', "#!/usr/bin/env bash\nexit 1\n");
            chmod($shim . '/uname', 0755);
            chmod($shim . '/lsof', 0755);
            if (PHP_OS_FAMILY === 'Windows' && $system === 'macos') {
                // Git Bash lacks macOS's shasum; verify its required arguments
                // and actual SHA-256 bytes here. Native macOS CI uses real shasum.
                file_put_contents($shim . '/shasum', "#!/usr/bin/env bash\n[[ \"\$#\" == 2 && \"\$1\" == '-a' && \"\$2\" == 256 ]] || exit 2\nexec sha256sum\n");
                chmod($shim . '/shasum', 0755);
            }
            $source = "export PATH=\"\$(cd \"\$(dirname \"\$0\")/shim\" && pwd -P):\$PATH\"\n" . $source;
        }
    }
    $script = $directory . '/installer.' . ($system === 'windows' ? 'ps1' : 'sh');
    file_put_contents($script, $source);
    $environment = getenv();
    unset($environment['RCMAKER_INSTALL_DIR']);
    $ignoredEnvironmentDirectory = $directory . '/ignored environment folder';
    if ($mode === 'override') {
        $environment['RCMAKER_INSTALL_DIR'] = $ignoredEnvironmentDirectory;
    }
    if (in_array($mode, ['explicit', 'pipe', 'dispatch'], true)) {
        $environment['RCMAKER_INSTALL_DIR'] = $install;
        if ($system !== 'windows' && PHP_OS_FAMILY === 'Windows') {
            $environment['RCMAKER_INSTALL_DIR'] = preg_replace_callback('/^([A-Za-z]):\//', static fn(array $match): string => '/' . strtolower($match[1]) . '/', $install);
        }
    }
    // Test neither machine-specific proxy settings nor external networking.
    foreach (['http_proxy', 'https_proxy', 'all_proxy', 'HTTP_PROXY', 'HTTPS_PROXY', 'ALL_PROXY'] as $proxy) {
        unset($environment[$proxy]);
    }
    $environment['NO_PROXY'] = '127.0.0.1,localhost';
    $environment['no_proxy'] = '127.0.0.1,localhost';
    if ($system === 'windows') {
        if ($mode === 'pipe') {
            $args = [$command, '-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass', '-Command', "Get-Content -Raw -LiteralPath '" . str_replace("'", "''", $script) . "' | Invoke-Expression"];
        } else {
            $args = [$command, '-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass', '-File', $script];
            if ($mode === 'relative') {
                array_push($args, '-InstallDir', 'chosen folder');
            } elseif ($mode === 'override') {
                array_push($args, '-InstallDir', 'command-line folder');
            }
        }
    } else {
        $args = $mode === 'pipe'
            ? [$command, '--noprofile', '--norc', '-c', 'cat "$1" | bash --noprofile --norc -s', 'installer-fixture', $script]
            : [$command, '--noprofile', '--norc', $script];
        if ($mode === 'relative') {
            $args[] = 'chosen folder';
        } elseif ($mode === 'override') {
            $args[] = 'command-line folder';
        }
    }
    $invoke = static function (string $expectedTag, bool $success = true) use ($args, $cwd, $environment, $directory, $install, $exe, $versions, $system, $architecture): string {
        [$code, $output] = installerRun($args, $cwd, $environment, $directory . '/run-' . bin2hex(random_bytes(3)));
        installerAssert($success ? $code === 0 : $code !== 0, "Unexpected installer result ($code): $output");
        installerAssert(is_file($install . '/' . $exe), "Installer did not use the expected directory $install: $output");
        installerAssert(file_get_contents($install . '/' . $exe) === $versions[$expectedTag]["$system-$architecture"], 'Installer selected incorrect platform, architecture, or release bytes.');
        installerAssert((glob($install . '/.install-*') ?: []) === [] && (glob($install . '/.install.*') ?: []) === [], 'Installer left its staging directory behind.');
        return $output;
    };
    $server->state('v1.0.0');
    $invoke('v1.0.0');
    installerAssert(!is_dir($install . '/data'), 'Installer initialized user data before the gateway first ran.');
    mkdir($install . '/data');
    file_put_contents($install . '/data/keep.db', 'private state');
    file_put_contents($install . '/.env', 'secret unchanged');
    mkdir($install . '/runtime');
    file_put_contents($install . '/runtime/keep.txt', 'runtime unchanged');
    $output = $invoke('v1.0.0');
    installerAssert(str_contains($output, 'Already up to date'), 'Same-release installation did not take the verified no-op path.');
    $server->state('v1.0.1', true);
    $output = $invoke('v1.0.0', false);
    installerAssert(str_contains($output, 'SHA-256 verification failed'), 'Corrupt archive was not rejected by checksum verification.');
    $server->state('v1.0.1');
    $invoke('v1.0.1');
    installerAssert(file_get_contents($install . '/' . $exe . '.previous') === $versions['v1.0.0']["$system-$architecture"], 'Upgrade did not preserve the old executable.');
    installerAssert(file_get_contents($install . '/.env.example') === ".env.example fixture\n", 'New optional sample configuration was not installed.');
    installerAssert(file_get_contents($install . '/data/keep.db') === 'private state', 'Upgrade changed persistent data.');
    installerAssert(file_get_contents($install . '/.env') === 'secret unchanged', 'Upgrade changed user configuration.');
    installerAssert(file_get_contents($install . '/runtime/keep.txt') === 'runtime unchanged', 'Upgrade changed unrelated runtime files.');
    if ($mode === 'override') {
        installerAssert(!file_exists($ignoredEnvironmentDirectory), 'The installer created the lower-priority environment directory.');
    }
    echo "PASS $system $architecture $mode (initial, no-op, rejected corruption, upgrade, preserved data/env)\n";
}

$root = str_replace('\\', '/', dirname(__DIR__, 2));
$generated = $root . '/data/generated';
if (!is_dir($generated)) {
    mkdir($generated, 0777, true);
}
$fixture = $generated . '/installer-test-' . bin2hex(random_bytes(8));
mkdir($fixture);
$server = null;
$failure = null;
$count = 0;
try {
    installerAssert(class_exists(ZipArchive::class), 'The fixture suite requires the PHP zip extension.');
    $versions = [];
    foreach (['v1.0.0', 'v1.0.1'] as $tag) {
        mkdir($fixture . '/' . $tag);
        $versions[$tag] = installerReleaseFixtures($fixture . '/' . $tag, $tag);
    }
    $server = new InstallerFixtureServer($fixture);
    $variants = [];
    if (($argv[1] ?? '') === '--unix-dispatch-only') {
        $bash = $argv[2] ?? '';
        installerAssert(is_file($bash), 'Dispatch simulation requires an explicit Bash executable path.');
        foreach (['linux', 'macos'] as $system) {
            foreach (['x86_64', 'aarch64'] as $architecture) {
                $variants[] = [$system, $architecture, 'dispatch', $bash];
            }
        }
    } elseif (PHP_OS_FAMILY === 'Windows') {
        foreach (['powershell', 'pwsh'] as $name) {
            if (($command = installerCommand($name)) !== null) {
                foreach (['pipe', 'explicit', 'relative', 'override', 'default-child', 'existing-current', 'existing-child'] as $mode) {
                    $variants[] = ['windows', 'x86_64', $mode, $command];
                }
            }
        }
    } else {
        $bash = installerCommand('bash');
        installerAssert($bash !== null, 'Native Unix installer tests require Bash.');
        $system = PHP_OS_FAMILY === 'Darwin' ? 'macos' : 'linux';
        $architecture = in_array(php_uname('m'), ['aarch64', 'arm64'], true) ? 'aarch64' : 'x86_64';
        foreach (['pipe', 'explicit', 'relative', 'override', 'default-child', 'existing-current', 'existing-child'] as $mode) {
            $variants[] = [$system, $architecture, $mode, $bash];
        }
        // Selection tests use uname/lsof shims; native OS behavior is covered above.
        foreach (['linux', 'macos'] as $selectedSystem) {
            foreach (['x86_64', 'aarch64'] as $selectedArchitecture) {
                $variants[] = [$selectedSystem, $selectedArchitecture, 'dispatch', $bash];
            }
        }
    }
    installerAssert($variants !== [], 'No compatible native installer command is available.');
    foreach ($variants as $variant) {
        installerScenario($root, $fixture, $server, $versions, $variant);
        ++$count;
    }
} catch (Throwable $error) {
    $failure = $error;
} finally {
    $server?->stop();
    installerRemoveFixture($fixture, $generated);
}
if ($failure !== null) {
    fwrite(STDERR, "Installer regression failed: {$failure->getMessage()}\n");
    exit(1);
}
echo "Installer regressions passed: $count isolated scenarios. Native platform: " . PHP_OS_FAMILY . ".\n";
