<?php
declare(strict_types=1);
namespace app\gateway\model;

use app\gateway\database\Schema;
use RC\Config;

/**
 * Project-private openai/tunnel-client runtime.
 *
 * No system profile, system proxy or global environment is modified. Every networked child gets
 * an explicit environment derived from one immutable Gateway network revision.
 */
final class TunnelRuntime
{
    public const VERSION = '0.0.12';
    private const ALIAS = 'rcmaker-ai-web';
    private const PROFILE = 'rcmaker-ai-web';
    private const RELEASE_BASE = 'https://github.com/openai/tunnel-client/releases/download/v' . self::VERSION;
    private const MAX_DOWNLOAD_BYTES = 104857600;
    /** Handle lives only in the dedicated GatewayTunnel worker. API workers never launch a daemon. */
    private static ?array $owned = null;
    private static ?string $ownedConfiguration = null;

    public static function snapshot(): array
    {
        Schema::initialize();
        $state = self::state();
        $settings = GatewaySettings::snapshot();
        $network = NetworkSettings::state();
        $binary = self::binaryPath();
        return [
            'status' => (string) $state['status'],
            'network_revision' => $state['network_revision'] === null ? null : (int) $state['network_revision'],
            'binary_version' => is_string($state['binary_version']) ? $state['binary_version'] : null,
            'binary_installed' => is_file($binary),
            'configured' => $settings['mcp_enabled']
                && $settings['tunnel_id'] !== ''
                && $settings['runtime_key_configured'],
            'active_network_revision' => $network['applied_php_revision'] === null ? null : (int) $network['applied_php_revision'],
            'last_error' => is_string($state['last_error']) ? $state['last_error'] : null,
            'updated_at' => (int) $state['updated_at'],
        ];
    }

    public static function state(): array
    {
        Schema::initialize();
        $row = SDB(null, 'medoo', 'sqlite')->table('gateway_tunnel_state')->where('id', 1)->find();
        if (!is_array($row)) throw new GatewayError('STORAGE_UNAVAILABLE', '工具隧道状态暂不可读取。', 503);
        return $row;
    }

    /** Background worker entry. Returns a public snapshot and never mutates process-global proxy state. */
    public static function ensureReady(): array
    {
        if (!(int) self::state()['desired_running']) {
            self::shutdown();
            return self::snapshot();
        }
        $settings = GatewaySettings::snapshot();
        if (!$settings['mcp_enabled'] || $settings['tunnel_id'] === '' || !$settings['runtime_key_configured']) {
            if (is_file(self::binaryPath())) {
                try { self::stop(); } catch (\Throwable) {}
            }
            self::writeState('stopped', null, is_file(self::binaryPath()) ? self::VERSION : null, null);
            return self::snapshot();
        }

        $networkState = NetworkSettings::state();
        $revisionId = $networkState['applied_php_revision'] === null ? 0 : (int) $networkState['applied_php_revision'];
        if ($revisionId < 1) {
            self::writeState('error', null, null, 'NETWORK_NOT_APPLIED');
            throw new GatewayError('NETWORK_NOT_APPLIED', '请先在“网络与代理”中应用一份网络配置。', 422);
        }
        $revision = NetworkSettings::revision($revisionId);
        NetworkSettings::usable($revision);

        $env = self::childEnvironment($revision);
        $state = self::state();
        $configuration = self::configurationFingerprint(GatewaySettings::internal());
        if (self::$owned !== null && ((int) ($state['network_revision'] ?? 0) !== $revisionId
            || self::$ownedConfiguration !== $configuration)) self::shutdown();
        if (!\support\process\OwnedRuntime::alive(self::$owned)) {
            \support\process\OwnedRuntime::stop(self::$owned);
            // Installation integrity/version checks are launch-time work. Re-running install()
            // on every idle health tick used to hash the Runtime and spawn `tunnel-client
            // --version` about every six seconds even while the owned process was healthy.
            // A live OwnedRuntime is already fenced to this project and exact executable, so
            // periodic readiness only needs the bounded loopback /readyz probe below.
            $binary = self::install($revision);
            // Migrate the previous detached alias once. Both directories are pinned to this installation.
            self::stopWith($binary, $env);
            self::writeRuntimeKey();
            $tunnelId = (string) GatewaySettings::internal()['tunnel_id'];
            if (!preg_match('/\Atunnel_[a-f0-9]{32}\z/', $tunnelId)) throw new GatewayError('TUNNEL_ID_INVALID', 'Tunnel ID 格式不正确。', 422);
            $health = self::root() . '/health.url';
            self::removeFile($health);
            $config = [
                'config_version' => 1,
                'admin_ui' => ['open_browser' => false],
                'control_plane' => ['base_url' => 'https://api.openai.com', 'tunnel_id' => $tunnelId, 'api_key' => 'file:' . self::runtimeKeyPath()],
                'health' => ['listen_addr' => '127.0.0.1:0', 'url_file' => $health],
                'log' => ['file' => 'stdout', 'format' => 'json', 'level' => 'warn'],
                'mcp' => ['commands' => [['channel' => 'main', 'command' => self::mcpCommand()]]],
            ];
            $profile = self::profileDir() . '/owned-runtime.json';
            self::atomicWrite($profile, json_encode($config, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            ConnectorState::runtimeStarted((string) $settings['connector_name']);
            self::writeState('starting', $revisionId, self::VERSION, null);
            self::$owned = \support\process\OwnedRuntime::launch([$binary, 'run', '--config', $profile], $env,
                self::root(), 'tunnel', self::root() . '/runtime.log');
            self::$ownedConfiguration = $configuration;
        }
        $status = self::localStatus(self::binaryPath(), $env);
        self::writeState($status['ready'] ? 'ready' : 'starting', $revisionId, self::VERSION, null);
        if ($status['ready']) ConnectorState::scheduleRuntimeCheck();
        return self::snapshot();
    }

    /** Download/install only; useful for an explicit UI preparation action. */
    public static function prepare(): array
    {
        try {
            $networkState = NetworkSettings::state();
            $revisionId = $networkState['applied_php_revision'] === null ? 0 : (int) $networkState['applied_php_revision'];
            if ($revisionId < 1) throw new GatewayError('NETWORK_NOT_APPLIED', '请先应用网络配置。', 422);
            $revision = NetworkSettings::revision($revisionId);
            self::install($revision);
            self::writeState('stopped', null, self::VERSION, null);
            return self::snapshot();
        } catch (GatewayError $error) {
            try { self::noteFailure($error->errorCode); } catch (\Throwable) {}
            throw $error;
        } catch (\Throwable $error) {
            try { self::noteFailure('TUNNEL_PREPARE_FAILED'); } catch (\Throwable) {}
            throw new GatewayError(
                'TUNNEL_PREPARE_FAILED',
                '工具隧道准备失败：' . self::safeErrorDetail($error),
                502
            );
        }
    }

    public static function refresh(): array
    {
        if (!is_file(self::binaryPath())) {
            self::writeState('stopped', null, null, null);
            return self::snapshot();
        }
        $networkState = NetworkSettings::state();
        $revisionId = $networkState['applied_php_revision'] === null ? 0 : (int) $networkState['applied_php_revision'];
        if ($revisionId < 1) return self::snapshot();
        $revision = NetworkSettings::revision($revisionId);
        $status = self::localStatus(self::binaryPath(), self::childEnvironment($revision));
        self::writeState($status['ready'] ? 'ready' : ($status['running'] ? 'starting' : 'stopped'), $revisionId, self::VERSION, null);
        return self::snapshot();
    }

    /** Persist user intent; the owner worker performs process changes outside the HTTP request. */
    public static function requestConnect(): array
    {
        self::setDesired(true);
        return self::snapshot();
    }

    /** Persist a stop command so the background worker cannot immediately reconnect it. */
    public static function stop(): array
    {
        self::setDesired(false);
        if (self::$owned !== null) self::shutdown();
        return self::snapshot();
    }

    /** Worker lifecycle cleanup preserves user intent, allowing a subsequent application start to reconnect. */
    public static function shutdown(): void
    {
        \support\process\OwnedRuntime::stop(self::$owned);
        self::$ownedConfiguration = null;
        self::writeState('stopped', null, is_file(self::binaryPath()) ? self::VERSION : null, null);
    }

    /** Store lifecycle intent through the configured SQLite component. */
    private static function setDesired(bool $running): void
    {
        Schema::initialize();
        Schema::transaction(static function () use ($running): void {
            $result = SDB(null, 'medoo', 'sqlite')->table('gateway_tunnel_state')->where('id', 1)->update(['desired_running' => (int) $running]);
            if (!$result) throw new GatewayError('STORAGE_UNAVAILABLE', '无法保存工具隧道运行状态。', 503);
        });
    }

    public static function noteFailure(string $code): void
    {
        $allowed = preg_match('/\A[A-Z0-9_]{3,80}\z/', $code) ? $code : 'TUNNEL_RUNTIME_ERROR';
        $network = NetworkSettings::state();
        self::writeState(
            'error',
            $network['applied_php_revision'] === null ? null : (int) $network['applied_php_revision'],
            is_file(self::binaryPath()) ? self::VERSION : null,
            $allowed
        );
    }

    /** @return array<string,string> Explicit child-only environment; exposed for PHP acceptance tests. */
    public static function childEnvironment(array $revision): array
    {
        NetworkSettings::usable($revision);
        $env = self::cleanEnvironment();
        $env['NO_PROXY'] = '127.0.0.1,localhost,::1';
        if ((string) $revision['mode'] !== 'manual') return $env;
        $host = (string) $revision['host'];
        if (str_contains($host, ':') && !str_starts_with($host, '[')) $host = '[' . $host . ']';
        $auth = '';
        if ((string) ($revision['username'] ?? '') !== '') {
            if ($revision['protected_password'] === null) {
                throw new GatewayError('CREDENTIAL_REQUIRED', '代理认证信息不完整。', 422);
            }
            $password = ProxyCredential::reveal((string) $revision['protected_password']);
            try {
                $auth = rawurlencode((string) $revision['username']) . ':' . rawurlencode($password) . '@';
            } finally {
                unset($password);
            }
        }
        $proxy = 'http://' . $auth . $host . ':' . (int) $revision['port'];
        $env['HTTP_PROXY'] = $proxy;
        $env['HTTPS_PROXY'] = $proxy;
        // tunnel-client maintains HTTPS/WebSocket control-plane connections. ALL_PROXY covers
        // libraries that do not consult the scheme-specific variables, while NO_PROXY keeps the
        // product's loopback MCP/health traffic local. No process-global environment is changed.
        $env['ALL_PROXY'] = $proxy;
        return $env;
    }

    /**
     * Parse the local `tunnel-client runtimes cleanup --json` inventory.
     * tunnel-client 0.0.12 derives runtime_state from the recorded process plus
     * local health/readiness probes, so this stays local and does not perform an
     * optional control-plane lookup merely to refresh the console.
     * @return array{running:bool,healthy:bool,ready:bool,state:string}
     */
    public static function parseStatus(string $json): array
    {
        try { $data = json_decode(trim($json), true, 32, JSON_THROW_ON_ERROR); }
        catch (\Throwable) { throw new GatewayError('TUNNEL_STATUS_INVALID', '工具隧道返回了无效状态。', 502); }
        $entries = is_array($data['entries'] ?? null) ? $data['entries'] : null;
        if ($entries === null) throw new GatewayError('TUNNEL_STATUS_INVALID', '工具隧道状态缺少 entries。', 502);
        $matches = array_values(array_filter(
            $entries,
            static fn($row): bool => is_array($row) && ($row['alias'] ?? null) === self::ALIAS
        ));
        if (count($matches) > 1) throw new GatewayError('TUNNEL_STATUS_INVALID', '检测到重复的本机工具隧道实例。', 502);
        $state = $matches === [] ? 'stopped' : (string) ($matches[0]['runtime_state'] ?? '');
        if (!in_array($state, ['stopped', 'starting', 'healthy', 'ready'], true)) {
            throw new GatewayError('TUNNEL_STATUS_INVALID', '工具隧道返回了未知运行状态。', 502);
        }
        return [
            'running' => $state !== 'stopped',
            'healthy' => in_array($state, ['healthy', 'ready'], true),
            'ready' => $state === 'ready',
            'state' => $state,
        ];
    }

    /** Validate, clean and publish the private executable while holding one installation lock. */
    private static function install(array $revision): string
    {
        self::ensureDirs();
        $binary = self::binaryPath();
        $manifestFile = self::manifestPath();
        $lock = self::quiet(static fn() => fopen(self::root() . '/install.lock', 'c'));
        if ($lock === false) throw new GatewayError('TUNNEL_INSTALL_FAILED', '无法创建工具隧道安装锁。', 503);
        $deadline = microtime(true) + 120;
        $locked = false;
        try {
            do {
                $locked = flock($lock, LOCK_EX | LOCK_NB);
                if (!$locked) usleep(50000);
            } while (!$locked && microtime(true) < $deadline);
            if (!$locked) throw new GatewayError('TUNNEL_INSTALL_BUSY', '工具隧道正在由另一个进程准备。', 409);

            clearstatcache(true, $binary);
            clearstatcache(true, $manifestFile);
            self::cleanupStaleInstallArtifacts(self::root(), $binary);
            if (is_file($binary) && is_file($manifestFile)) {
                try {
                    $manifest = json_decode((string) file_get_contents($manifestFile), true, 16, JSON_THROW_ON_ERROR);
                    if (($manifest['version'] ?? null) === 1
                        && ($manifest['tunnel_client_version'] ?? null) === self::VERSION
                        && is_string($manifest['binary_sha256'] ?? null)
                        && hash_equals((string) $manifest['binary_sha256'], hash_file('sha256', $binary) ?: '')
                        && self::binaryReportsVersion($binary)) {
                        return $binary;
                    }
                } catch (\Throwable) {}
                self::removeFile($binary, true);
                self::removeFile($manifestFile, true);
            } elseif (is_file($binary) || is_file($manifestFile)) {
                self::removeFile($binary, true);
                self::removeFile($manifestFile, true);
            }

            $asset = self::assetName();
            $archive = self::root() . '/tmp/' . $asset;
            $sums = self::downloadText($revision, self::RELEASE_BASE . '/SHA256SUMS.txt', 1048576);
            $expected = self::checksumFor($sums, $asset);
            self::downloadFile($revision, self::RELEASE_BASE . '/' . $asset, $archive);
            $actual = hash_file('sha256', $archive);
            if (!is_string($actual) || !hash_equals($expected, strtolower($actual))) {
                self::removeFile($archive);
                throw new GatewayError('TUNNEL_CHECKSUM_MISMATCH', '工具隧道下载文件校验失败。', 502);
            }
            $staged = $binary . '.install-' . getmypid() . '-' . bin2hex(random_bytes(4))
                . (PHP_OS_FAMILY === 'Windows' ? '.exe' : '');
            self::extractBinary($archive, $staged);
            if (!self::binaryReportsVersion($staged)) {
                self::removeFile($staged);
                throw new GatewayError('TUNNEL_VERSION_MISMATCH', '下载的工具隧道版本与固定版本不一致。', 502);
            }
            self::removeFile($binary, true);
            if (!self::moveFile($staged, $binary)) {
                self::removeFile($staged);
                throw new GatewayError('TUNNEL_INSTALL_FAILED', '工具隧道无法写入项目 Runtime 目录。', 503);
            }
            if (PHP_OS_FAMILY !== 'Windows') self::quiet(static fn() => chmod($binary, 0700));
            $manifest = [
                'version' => 1,
                'tunnel_client_version' => self::VERSION,
                'asset' => $asset,
                'archive_sha256' => $expected,
                'binary_sha256' => hash_file('sha256', $binary),
            ];
            self::atomicWrite($manifestFile, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
            self::removeFile($archive);
            return $binary;
        } finally {
            if ($locked) flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** Remove interrupted installer/probe files while the caller holds this Runtime's install.lock. */
    private static function cleanupStaleInstallArtifacts(string $root, string $binary): void
    {
        $binDir = dirname($binary);
        $prefix = basename($binary) . '.install-';
        foreach (self::quiet(static fn() => scandir($binDir)) ?: [] as $name) {
            if (!is_string($name) || !str_starts_with($name, $prefix)) continue;
            if (!preg_match('/\A[A-Za-z0-9._-]+\z/', $name)) continue;
            self::removeFile($binDir . '/' . $name);
        }

        $tmpDir = rtrim(str_replace('\\', '/', $root), '/') . '/tmp';
        foreach (['version-probe.exe', 'version-probe'] as $name) {
            self::removeFile($tmpDir . '/' . $name);
        }
    }

    /** Local readiness of the foreground runtime; no remote inventory lookup or user credentials needed. */
    private static function localStatus(string $binary, array $env): array
    {
        $file = self::root() . '/health.url';
        $url = is_file($file) ? trim((string) file_get_contents($file)) : '';
        $stopped = ['running' => false, 'healthy' => false, 'ready' => false, 'state' => 'stopped'];
        if (!preg_match('~\Ahttp://127\.0\.0\.1:([1-9][0-9]{0,4})/?\z~', $url, $match) || (int) $match[1] > 65535) return $stopped;
        $client = curl();
        try {
            $client->setTimeout(1);
            $client->setOpt(CURLOPT_CONNECTTIMEOUT_MS, 300);
            $client->setOpt(CURLOPT_PROXY, '');
            $client->setOpt(CURLOPT_NOPROXY, '*');
            $client->get(rtrim($url, '/') . '/readyz');
            $code = (int) $client->httpStatusCode;
            $running = (int) $client->curlErrorCode === 0 && in_array($code, [200, 503], true);
            return ['running' => $running, 'healthy' => $code === 200, 'ready' => $code === 200,
                'state' => $code === 200 ? 'ready' : ($running ? 'starting' : 'stopped')];
        } finally { $client->close(); }
    }

    private static function stopWith(string $binary, array $env): void
    {
        $result = self::run([$binary, 'runtimes', 'stop', self::ALIAS, '--json'], $env, 15000);
        if ($result['exit'] !== 0) {
            $text = strtolower($result['stdout'] . "\n" . $result['stderr']);
            if (!str_contains($text, 'not found') && !str_contains($text, 'not running')
                && !str_contains($text, 'unknown alias') && !str_contains($text, 'is not known')) {
                throw new GatewayError('TUNNEL_STOP_FAILED', '工具隧道未能正常停止。', 502);
            }
        }
    }

    /** @return array{healthy:bool} */
    private static function parseConnect(string $json): array
    {
        try { $data = json_decode(trim($json), true, 32, JSON_THROW_ON_ERROR); }
        catch (\Throwable) { return ['healthy' => false]; }
        return ['healthy' => ($data['running'] ?? false) === true && ($data['healthy'] ?? false) === true];
    }

    private static function writeRuntimeKey(): void
    {
        $key = GatewaySettings::revealRuntimeKey();
        try { self::atomicWrite(self::runtimeKeyPath(), $key); }
        finally { unset($key); }
        if (PHP_OS_FAMILY !== 'Windows') self::quiet(static fn() => chmod(self::runtimeKeyPath(), 0600));
    }

    private static function writeState(string $status, ?int $revision, ?string $version, ?string $error): void
    {
        Schema::transaction(function () use ($status, $revision, $version, $error): void {
            $result = SDB(null, 'medoo', 'sqlite')->table('gateway_tunnel_state')->where('id', 1)->update([
                'status' => $status,
                'network_revision' => $revision,
                'binary_version' => $version,
                'last_error' => $error,
                'updated_at' => time(),
            ]);
            if (!$result) throw new GatewayError('STORAGE_UNAVAILABLE', '工具隧道状态无法保存。', 503);
        });
    }

    private static function downloadText(array $revision, string $url, int $max): string
    {
        $client = curl();
        try {
            NetworkTransport::configure($client, $revision, $url, 60, 10, true);
            $client->setOpt(CURLOPT_MAXFILESIZE, $max);
            $value = $client->get($url);
            $raw = is_string($client->rawResponse ?? null) ? $client->rawResponse : (is_string($value) ? $value : '');
            if ((int) $client->curlErrorCode !== 0 || (int) $client->httpStatusCode !== 200 || $raw === '' || strlen($raw) > $max) {
                throw new GatewayError('TUNNEL_DOWNLOAD_FAILED', '无法通过当前网络配置读取工具隧道校验文件。', 502);
            }
            return $raw;
        } finally { $client->close(); }
    }

    private static function downloadFile(array $revision, string $url, string $path): void
    {
        $part = $path . '.part';
        self::removeFile($part);
        $file = self::quiet(static fn() => fopen($part, 'wb'));
        if ($file === false) throw new GatewayError('TUNNEL_DOWNLOAD_FAILED', '无法创建工具隧道下载临时文件。', 503);
        $client = curl();
        $ok = false;
        try {
            NetworkTransport::configure($client, $revision, $url, 180, 15, true);
            $client->setFile($file);
            $client->setOpt(CURLOPT_MAXFILESIZE, self::MAX_DOWNLOAD_BYTES);
            $client->progress(static function ($resource, $total, $downloaded) {
                return ($downloaded > self::MAX_DOWNLOAD_BYTES || ($total > 0 && $total > self::MAX_DOWNLOAD_BYTES)) ? 1 : 0;
            });
            $client->get($url);
            $ok = (int) $client->curlErrorCode === 0 && (int) $client->httpStatusCode === 200;
        } finally {
            $client->close();
            fclose($file);
        }
        clearstatcache(true, $part);
        $size = is_file($part) ? (int) filesize($part) : 0;
        if (!$ok || $size < 1 || $size > self::MAX_DOWNLOAD_BYTES || !self::moveFile($part, $path)) {
            self::removeFile($part);
            self::removeFile($path);
            throw new GatewayError('TUNNEL_DOWNLOAD_FAILED', '工具隧道下载失败，请检查当前代理配置。', 502);
        }
    }

    private static function extractBinary(string $archive, string $destination): void
    {
        if (!class_exists(\ZipArchive::class)) throw new GatewayError('TUNNEL_ZIP_UNAVAILABLE', '当前 PHP Runtime 缺少 ZipArchive。', 503);
        $zip = new \ZipArchive();
        if ($zip->open($archive) !== true) throw new GatewayError('TUNNEL_ARCHIVE_INVALID', '工具隧道压缩包无法打开。', 502);
        $expected = PHP_OS_FAMILY === 'Windows' ? 'tunnel-client.exe' : 'tunnel-client';
        $entry = null;
        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if (!is_string($name)) continue;
                $normalized = str_replace('\\', '/', $name);
                if (basename($normalized) !== $expected || str_ends_with($normalized, '/')) continue;
                if ($entry !== null) throw new GatewayError('TUNNEL_ARCHIVE_INVALID', '工具隧道压缩包包含重复可执行文件。', 502);
                $entry = $name;
            }
            if ($entry === null) throw new GatewayError('TUNNEL_ARCHIVE_INVALID', '工具隧道压缩包缺少可执行文件。', 502);
            $input = $zip->getStream($entry);
            $output = self::quiet(static fn() => fopen($destination, 'wb'));
            if (!is_resource($input) || $output === false) throw new GatewayError('TUNNEL_INSTALL_FAILED', '无法解压工具隧道可执行文件。', 503);
            $written = 0;
            try {
                while (!feof($input)) {
                    $chunk = fread($input, 1048576);
                    if ($chunk === false) throw new GatewayError('TUNNEL_ARCHIVE_INVALID', '读取工具隧道压缩包失败。', 502);
                    $written += strlen($chunk);
                    if ($written > self::MAX_DOWNLOAD_BYTES || fwrite($output, $chunk) !== strlen($chunk)) {
                        throw new GatewayError('TUNNEL_INSTALL_FAILED', '工具隧道解压文件超过限制或写入失败。', 503);
                    }
                }
            } finally {
                fclose($input);
                fclose($output);
            }
            if ($written < 1) throw new GatewayError('TUNNEL_ARCHIVE_INVALID', '工具隧道可执行文件为空。', 502);
        } finally {
            $zip->close();
        }
    }

    private static function checksumFor(string $sums, string $asset): string
    {
        foreach (preg_split('/\r?\n/', $sums) ?: [] as $line) {
            if (!str_ends_with(trim($line), $asset)) continue;
            $parts = preg_split('/\s+/', trim($line)) ?: [];
            $hash = strtolower((string) ($parts[0] ?? ''));
            if (preg_match('/\A[a-f0-9]{64}\z/', $hash)) return $hash;
        }
        throw new GatewayError('TUNNEL_CHECKSUM_MISSING', '官方 SHA256SUMS 中没有当前平台的工具隧道文件。', 502);
    }

    private static function binaryReportsVersion(string $binary): bool
    {
        if (!is_file($binary)) return false;
        if (PHP_OS_FAMILY !== 'Windows') self::quiet(static fn() => chmod($binary, 0700));
        try {
            $result = self::run([$binary, '--version'], self::cleanEnvironment(), 10000);
            return $result['exit'] === 0 && str_contains($result['stdout'] . "\n" . $result['stderr'], self::VERSION);
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return array{exit:int,stdout:string,stderr:string} */
    private static function run(array $command, array $env, int $timeoutMs): array
    {
        $pipes = [];
        $process = self::quiet(static function () use ($command, $env, &$pipes) {
            return proc_open($command, [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ], $pipes, self::root(), $env, ['bypass_shell' => true]);
        });
        if (!is_resource($process)) throw new GatewayError('TUNNEL_PROCESS_FAILED', '无法启动项目私有工具隧道进程。', 503);
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stdout = '';
        $stderr = '';
        $deadline = microtime(true) + ($timeoutMs / 1000);
        $exit = -1;
        try {
            while (true) {
                $stdout .= stream_get_contents($pipes[1]) ?: '';
                $stderr .= stream_get_contents($pipes[2]) ?: '';
                if (strlen($stdout) > 4194304 || strlen($stderr) > 4194304) {
                    proc_terminate($process);
                    throw new GatewayError('TUNNEL_PROCESS_OUTPUT_LIMIT', '工具隧道进程输出超过限制。', 502);
                }
                $status = proc_get_status($process);
                if (!$status['running']) {
                    $exit = (int) $status['exitcode'];
                    break;
                }
                if (microtime(true) >= $deadline) {
                    proc_terminate($process);
                    usleep(100000);
                    throw new GatewayError('TUNNEL_PROCESS_TIMEOUT', '工具隧道进程等待超时。', 504);
                }
                usleep(50000);
            }
            $stdout .= stream_get_contents($pipes[1]) ?: '';
            $stderr .= stream_get_contents($pipes[2]) ?: '';
        } finally {
            fclose($pipes[1]);
            fclose($pipes[2]);
            $closed = proc_close($process);
            if ($exit < 0 && is_int($closed)) $exit = $closed;
        }
        return ['exit' => $exit, 'stdout' => $stdout, 'stderr' => $stderr];
    }

    /** @return array<string,string> */
    private static function cleanEnvironment(): array
    {
        $env = [];
        foreach (['SystemRoot', 'WINDIR', 'SystemDrive', 'ComSpec', 'PATHEXT', 'LANG', 'LC_ALL', 'LC_CTYPE'] as $name) {
            $value = getenv($name);
            if (is_string($value) && $value !== '') $env[$name] = $value;
        }
        $home = self::root() . '/home';
        $temp = self::root() . '/tmp';
        foreach ([$home, $temp, $home . '/AppData/Roaming', $home . '/AppData/Local'] as $directory) {
            if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) throw new GatewayError('TUNNEL_STORAGE_FAILED', '无法创建工具隧道私有目录。', 503);
        }
        $env['HOME'] = $home; $env['USERPROFILE'] = $home;
        $env['APPDATA'] = $home . '/AppData/Roaming'; $env['LOCALAPPDATA'] = $home . '/AppData/Local';
        $env['TMP'] = $temp; $env['TEMP'] = $temp; $env['TMPDIR'] = $temp;
        $env['PATH'] = PHP_OS_FAMILY === 'Windows'
            ? ($env['SystemRoot'] ?? 'C:\\Windows') . '\\System32'
            : '/usr/bin:/bin:/usr/sbin:/sbin';

        // tunnel-client otherwise shares ~/.local/state with every other installation on the
        // machine. Pin both XDG roots inside this project's private runtime so aliases, process
        // metadata, logs, health files and generated config cannot collide with other software.
        $env['XDG_STATE_HOME'] = self::stateHome();
        $env['XDG_CONFIG_HOME'] = self::configHome();
        return $env;
    }

    private static function mcpCommand(): string
    {
        return implode(' ', array_map(self::tunnelQuote(...),
            \support\process\GatewayEntrypoint::command('--gateway-mcp')));
    }

    /** Credential identity only: changing language, templates or timeouts must not reconnect a live tunnel. */
    public static function configurationFingerprint(array $settings): string
    {
        return hash('sha256', json_encode([
            (string) ($settings['tunnel_id'] ?? ''),
            (string) ($settings['protected_runtime_key'] ?? ''),
        ], JSON_THROW_ON_ERROR));
    }

    private static function tunnelQuote(string $value): string
    {
        if (str_contains($value, "\r") || str_contains($value, "\n")) throw new GatewayError('TUNNEL_COMMAND_INVALID', 'MCP Runtime 路径无效。', 503);
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
    }

    private static function assetName(): string
    {
        $os = match (PHP_OS_FAMILY) {
            'Windows' => 'windows',
            'Linux' => 'linux',
            'Darwin' => 'darwin',
            default => throw new GatewayError('TUNNEL_PLATFORM_UNSUPPORTED', '当前操作系统没有固定的工具隧道发行包。', 422),
        };
        $machine = strtolower(php_uname('m'));
        $arch = match (true) {
            in_array($machine, ['amd64', 'x86_64', 'x64'], true) => 'amd64',
            in_array($machine, ['arm64', 'aarch64'], true) => 'arm64',
            default => throw new GatewayError('TUNNEL_PLATFORM_UNSUPPORTED', '当前 CPU 架构没有固定的工具隧道发行包。', 422),
        };
        return 'tunnel-client-v' . self::VERSION . '-' . $os . '-' . $arch . '.zip';
    }

    private static function ensureDirs(): void
    {
        foreach ([
            self::root(),
            dirname(self::binaryPath()),
            self::profileDir(),
            dirname(self::runtimeKeyPath()),
            self::root() . '/tmp',
            self::stateHome(),
            self::configHome(),
        ] as $dir) {
            if (!is_dir($dir) && !self::quiet(static fn() => mkdir($dir, 0700, true)) && !is_dir($dir)) {
                throw new GatewayError('TUNNEL_STORAGE_FAILED', '无法创建项目私有工具隧道目录。', 503);
            }
        }
    }

    private static function atomicWrite(string $path, string $contents): void
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !self::quiet(static fn() => mkdir($dir, 0700, true)) && !is_dir($dir)) {
            throw new GatewayError('TUNNEL_STORAGE_FAILED', '无法创建工具隧道配置目录。', 503);
        }
        $temp = $path . '.tmp-' . getmypid() . '-' . bin2hex(random_bytes(4));
        if (self::quiet(static fn() => file_put_contents($temp, $contents, LOCK_EX)) !== strlen($contents)
            || !self::moveFile($temp, $path)) {
            self::removeFile($temp);
            throw new GatewayError('TUNNEL_STORAGE_FAILED', '工具隧道配置无法原子写入。', 503);
        }
    }

    private static function quiet(callable $operation): mixed
    {
        set_error_handler(static fn(): bool => true);
        try {
            return $operation();
        } finally {
            restore_error_handler();
        }
    }

    private static function removeFile(string $path, bool $required = false): void
    {
        clearstatcache(true, $path);
        if (!is_file($path) && !is_link($path)) return;
        $removed = self::quiet(static fn() => unlink($path)) === true;
        clearstatcache(true, $path);
        if (!$removed && $required && (is_file($path) || is_link($path))) {
            throw new GatewayError('TUNNEL_STORAGE_FAILED', '项目私有工具隧道文件无法清理。', 503);
        }
    }

    private static function moveFile(string $source, string $destination): bool
    {
        return self::quiet(static fn() => rename($source, $destination)) === true;
    }

    private static function safeErrorDetail(\Throwable $error): string
    {
        $text = get_class($error) . ': ' . $error->getMessage();
        $text = preg_replace('/tunnel_[a-f0-9]{32}/', '[tunnel-id]', $text) ?? $text;
        $text = preg_replace('/\b(?:sk|rk)-[A-Za-z0-9_-]{12,}\b/', '[redacted-key]', $text) ?? $text;
        $text = preg_replace('/[A-Za-z]:\\[^\r\n]{1,500}/', '[project-path]', $text) ?? $text;
        return mb_substr($text, 0, 500);
    }

    private static function root(): string
    {
        return rtrim(str_replace('\\', '/', (string) Config::get('gateway', 'tunnel_runtime_root')), '/');
    }
    private static function binaryPath(): string { return self::root() . '/bin/' . (PHP_OS_FAMILY === 'Windows' ? 'tunnel-client.exe' : 'tunnel-client'); }
    private static function manifestPath(): string { return self::root() . '/bin/manifest.json'; }
    private static function profileDir(): string { return self::root() . '/profiles'; }
    private static function runtimeKeyPath(): string { return self::root() . '/secrets/runtime.key'; }
    private static function stateHome(): string { return str_replace('/', DIRECTORY_SEPARATOR, self::root() . '/xdg-state'); }
    private static function configHome(): string { return str_replace('/', DIRECTORY_SEPARATOR, self::root() . '/xdg-config'); }
}
