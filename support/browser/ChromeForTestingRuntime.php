<?php
declare(strict_types=1);
namespace support\browser;

use RC\Config;
use app\gateway\model\GatewayError;
use app\gateway\model\NetworkTransport;

/** Installs and reuses one project-private Stable Chrome for Testing runtime. */
final class ChromeForTestingRuntime
{
    private const PROVIDER = 'chrome-for-testing';
    private const CHANNEL = 'Stable';
    private const MAX_MANIFEST_BYTES = 4_000_000;
    private const MAX_ARCHIVE_BYTES = 1_000_000_000;
    private const DOWNLOAD_TIMEOUT_SECONDS = 7200;
    private const DOWNLOAD_STALL_SECONDS = 120;
    private const INTEGRITY_FILE = '.rcmaker-integrity.json';

    /**
     * Share the install command's observation budget with the console; download and stall limits stay independent.
     * @return int Seconds covering download, lock, manifest, publication and a ten-minute local verification margin.
     */
    public static function installWaitTimeoutSeconds(): int
    {
        return self::DOWNLOAD_TIMEOUT_SECONDS + (int) Config::get('gateway', 'browser_runtime_lock_timeout_seconds')
            + 30 + (int) Config::get('gateway', 'browser_runtime_publish_timeout_seconds') + 600;
    }

    /** @return array{os:string,arch:string,cft_platform:?string} */
    public static function platform(): array
    {
        return BrowserPlatform::detect();
    }

    /**
     * Lightweight setup-page projection. Launch still calls installed()/ensure(), which performs
     * the complete integrity verification before any executable is trusted.
     * @return array{installed:bool,version:?string,platform:?string}
     */
    public static function status(): array
    {
        $platform = self::platform();
        $root = self::root();
        $manifest = rtrim($root, '\\/') . DIRECTORY_SEPARATOR . 'installed.json';
        if (!is_file($manifest)) {
            return ['installed' => false, 'version' => null, 'platform' => $platform['cft_platform'] ?? null];
        }
        $raw = @file_get_contents($manifest);
        if (!is_string($raw) || strlen($raw) > 64 * 1024) {
            return ['installed' => false, 'version' => null, 'platform' => $platform['cft_platform'] ?? null];
        }
        try { $data = json_decode($raw, true, 32, JSON_THROW_ON_ERROR); } catch (\Throwable) { $data = null; }
        if (!is_array($data)
            || ($data['provider'] ?? '') !== self::PROVIDER
            || ($data['channel'] ?? '') !== self::CHANNEL
            || ($data['platform'] ?? '') !== ($platform['cft_platform'] ?? null)
            || !is_string($data['version'] ?? null)
            || !preg_match('/\A\d+(?:\.\d+){3}\z/', $data['version'])
            || !is_string($data['executable'] ?? null)) {
            return ['installed' => false, 'version' => null, 'platform' => $platform['cft_platform'] ?? null];
        }
        $relative = self::safeRelative((string) $data['executable']);
        $path = $relative === null ? '' : rtrim($root, '\\/') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        $installed = $path !== '' && is_file($path);
        return [
            'installed' => $installed,
            'version' => $installed ? (string) $data['version'] : null,
            'platform' => (string) $data['platform'],
        ];
    }

    /** Return a valid installed Runtime without any network access. */
    public static function installed(?array $platform = null, ?string $root = null): ?array
    {
        $platform ??= self::platform();
        $root ??= self::root();
        $manifest = rtrim($root, '\\/') . DIRECTORY_SEPARATOR . 'installed.json';
        if (!is_file($manifest)) return null;
        $raw = @file_get_contents($manifest);
        if (!is_string($raw) || strlen($raw) > 64 * 1024) return null;
        try { $data = json_decode($raw, true, 32, JSON_THROW_ON_ERROR); } catch (\Throwable) { return null; }
        if (!is_array($data)
            || ($data['provider'] ?? '') !== self::PROVIDER
            || ($data['channel'] ?? '') !== self::CHANNEL
            || ($data['platform'] ?? '') !== ($platform['cft_platform'] ?? null)
            || !is_string($data['version'] ?? null)
            || !preg_match('/\A\d+(?:\.\d+){3}\z/', $data['version'])
            || !is_string($data['executable'] ?? null)
            || !is_string($data['integrity_manifest'] ?? null)
            || !is_string($data['integrity_sha256'] ?? null)
            || !preg_match('/\A[a-f0-9]{64}\z/', $data['integrity_sha256'])) return null;
        $relative = self::safeRelative((string) $data['executable']);
        if ($relative === null) return null;
        $path = rtrim($root, '\\/') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        if (!is_file($path) || (PHP_OS_FAMILY !== 'Windows' && !is_executable($path))) return null;
        $integrityRelative = self::safeRelative((string) $data['integrity_manifest']);
        $expectedIntegrity = 'stable/' . $data['version'] . '/' . self::INTEGRITY_FILE;
        if ($integrityRelative !== $expectedIntegrity) return null;
        $versionRoot = rtrim($root, '\\/') . DIRECTORY_SEPARATOR . 'stable' . DIRECTORY_SEPARATOR . $data['version'];
        $unixPermissions = PHP_OS_FAMILY !== 'Windows' && ($platform['os'] ?? '') !== 'windows';
        if (!self::verifyIntegrity($versionRoot, (string) $data['integrity_sha256'], $unixPermissions)) return null;
        return self::browserRecord($data, $path);
    }

    /** Ensure a Runtime exists, installing Stable only when no valid local Runtime is present. */
    public static function ensure(array $revision, ?callable $progress = null): array
    {
        $platform = self::platform();
        self::emit($progress, ['stage' => 'detecting'] + $platform);
        $root = self::root();
        $existing = self::installed($platform, $root);
        if ($existing === null && PHP_OS_FAMILY === 'Windows' && self::repairInstalledRuntimePermissions($root, $platform)) {
            $existing = self::installed($platform, $root);
        }
        if ($existing !== null) return $existing;
        if (($platform['cft_platform'] ?? null) === null) {
            self::fail('BROWSER_RUNTIME_PLATFORM_UNSUPPORTED', '当前平台没有可用的 Chrome for Testing Runtime。', $platform, 'detecting', 422);
        }
        self::mkdir($root);
        $lockPath = $root . DIRECTORY_SEPARATOR . '.install.lock';
        $lock = @fopen($lockPath, 'c+b');
        if ($lock === false) self::fail('BROWSER_RUNTIME_LOCK_FAILED', '无法创建浏览器 Runtime 安装锁。', $platform, 'locking');
        $locked = false;
        try {
            $deadline = microtime(true) + (int) Config::get('gateway', 'browser_runtime_lock_timeout_seconds');
            do {
                if (@flock($lock, LOCK_EX | LOCK_NB)) { $locked = true; break; }
                usleep(100000);
            } while (microtime(true) < $deadline);
            if (!$locked) self::fail('BROWSER_RUNTIME_LOCK_FAILED', '等待浏览器 Runtime 安装锁超时。', $platform, 'locking');
            // Double-check after the lock: another worker may have completed installation.
            $existing = self::installed($platform, $root);
            if ($existing !== null) return $existing;
            // Unix schema-1 installations can have intact bytes but sealed, non-executable helpers.
            // Repair only authenticated local files while holding the same lock as installation.
            if (PHP_OS_FAMILY !== 'Windows' && self::repairInstalledRuntimePermissions($root, $platform)) {
                $existing = self::installed($platform, $root);
                if ($existing !== null) return $existing;
            }
            return self::installLocked($revision, $platform, $root, $progress);
        } finally {
            if ($locked) @flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** Validate the executable by running --version with a short timeout. */
    public static function validate(array $runtime): bool
    {
        $path = (string) ($runtime['path'] ?? '');
        return self::validateExecutable($path)['ok'];
    }

    /** @return array{ok:bool,detail:string,version?:string} */
    private static function validateExecutable(string $path): array
    {
        if ($path === '' || !is_file($path) || !function_exists('proc_open')) return ['ok' => false, 'detail' => 'executable unavailable'];
        if (PHP_OS_FAMILY === 'Windows') {
            // Windows Chrome does not implement --version like Linux/macOS. Read the PE version
            // resource without launching a browser process; the real launch/CDP path is the
            // functional validation and uses the product-private environment.
            $version = self::windowsVersionInfo($path);
            $identityOk = $version !== null && preg_match('/Chrome/i', $version['product']) === 1
                && preg_match('/\A\d+(?:\.\d+){3}\z/', $version['version']) === 1;
            $reported = $version !== null ? ($version['product'] . ' ' . $version['version']) : 'version resource unavailable';
            return ['ok' => $identityOk, 'detail' => mb_substr($reported, 0, 1200), 'version' => $version['version'] ?? null];
        }
        $args = [$path, '--version'];
        $temp = rtrim((string) Config::get('gateway', 'browser_temp_dir'), '\\/');
        if (!is_dir($temp) && !@mkdir($temp, 0700, true) && !is_dir($temp)) return ['ok' => false, 'detail' => 'private temp unavailable'];
        $environment = ['HOME' => dirname($path), 'TMPDIR' => $temp, 'PATH' => '/usr/bin:/bin:/usr/sbin:/sbin'];
        foreach (['LANG', 'LC_ALL', 'LC_CTYPE', 'DISPLAY', 'WAYLAND_DISPLAY'] as $name) {
            $value = getenv($name);
            if (is_string($value) && $value !== '') $environment[$name] = $value;
        }
        $process = @proc_open($args, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $environment,
            ['bypass_shell' => true]);
        if (!is_resource($process)) return ['ok' => false, 'detail' => 'process start failed'];
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false); stream_set_blocking($pipes[2], false);
        $stdout = ''; $stderr = ''; $deadline = microtime(true) + 8.0; $exit = null;
        do {
            $stdout .= (string) stream_get_contents($pipes[1]);
            $stderr .= (string) stream_get_contents($pipes[2]);
            if (strlen($stdout) + strlen($stderr) > 32768) break;
            $status = proc_get_status($process);
            if (!$status['running']) { $exit = $status['exitcode']; break; }
            usleep(20000);
        } while (microtime(true) < $deadline);
        $running = isset($status) && !empty($status['running']);
        if ($running) @proc_terminate($process);
        $stdout .= (string) stream_get_contents($pipes[1]); $stderr .= (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        $code = proc_close($process);
        if ($exit === null) $exit = $code;
        $text = trim($stdout . "\n" . $stderr);
        $detail = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text) ?? '';
        $ok = $exit === 0 && preg_match('/(?:Google )?Chrome(?: for Testing)?\s+\d+/i', $text) === 1;
        return ['ok' => $ok, 'detail' => mb_substr($detail !== '' ? $detail : ('exit=' . $exit), 0, 1200)];
    }

    /** Read the bounded PE resource in PHP; this check never launches Chrome or a shell. */
    private static function windowsVersionInfo(string $path): ?array
    {
        return PeVersion::read($path);
    }

    /** Deterministic Stable manifest parser used by production and tests. */
    public static function resolveStable(array $manifest, array $platform): array
    {
        $cft = (string) ($platform['cft_platform'] ?? '');
        if ($cft === '') self::fail('BROWSER_RUNTIME_PLATFORM_UNSUPPORTED', '当前平台没有可用的 Chrome for Testing Runtime。', $platform, 'fetching_manifest', 422);
        $stable = $manifest['channels']['Stable'] ?? null;
        $version = is_array($stable) ? (string) ($stable['version'] ?? '') : '';
        $downloads = is_array($stable) ? ($stable['downloads']['chrome'] ?? null) : null;
        if (!preg_match('/\A\d+(?:\.\d+){3}\z/', $version) || !is_array($downloads)) {
            self::fail('BROWSER_RUNTIME_MANIFEST_FAILED', 'Google Stable manifest 格式无效。', $platform, 'fetching_manifest');
        }
        foreach ($downloads as $download) {
            if (!is_array($download) || ($download['platform'] ?? '') !== $cft) continue;
            $url = (string) ($download['url'] ?? '');
            self::assertDownloadUrl($url, $version, $cft, $platform);
            return ['version' => $version, 'platform' => $cft, 'url' => $url];
        }
        self::fail('BROWSER_RUNTIME_PLATFORM_UNSUPPORTED', 'Stable manifest 没有当前平台的 Chrome 包。', $platform, 'fetching_manifest', 422);
    }

    private static function installLocked(array $revision, array $platform, string $root, ?callable $progress): array
    {
        try {
            self::log('resolving Stable Chrome for Testing', $platform);
            self::emit($progress, ['stage' => 'fetching_manifest'] + $platform);
            $manifest = self::fetchManifest($revision, $platform);
            $selected = self::resolveStable($manifest, $platform);
            self::log('version selected version=' . $selected['version'] . ' platform=' . $selected['platform'], $platform);

            $downloadDir = $root . DIRECTORY_SEPARATOR . '.download';
            self::mkdir($downloadDir);
            $base = 'chrome-' . $selected['platform'] . '-' . $selected['version'] . '.zip';
            $archive = $downloadDir . DIRECTORY_SEPARATOR . $base;
            self::download($revision, $selected['url'], $archive, $platform, $progress);

            self::emit($progress, ['stage' => 'extracting'] + $platform);
            self::log('extracting', $platform);
            $versionRoot = $root . DIRECTORY_SEPARATOR . 'stable' . DIRECTORY_SEPARATOR . $selected['version'];
            $tempRoot = $root . DIRECTORY_SEPARATOR . 'stable' . DIRECTORY_SEPARATOR . '.install-' . bin2hex(random_bytes(6));
            self::mkdir(dirname($tempRoot)); self::mkdir($tempRoot);
            try {
                self::extractSafe($archive, $tempRoot, $platform);
                $executable = self::locateExecutable($tempRoot, $selected['platform'], $platform);
                if (PHP_OS_FAMILY !== 'Windows') @chmod($executable, 0755);
                self::emit($progress, ['stage' => 'validating'] + $platform);
                self::log('validating', $platform);
                $validation = self::validateExecutable($executable);
                if (!$validation['ok'] || (isset($validation['version']) && $validation['version'] !== $selected['version'])) {
                    self::fail('BROWSER_RUNTIME_VALIDATE_FAILED', 'Chrome for Testing Runtime 校验失败：' . $validation['detail'], $platform, 'validating');
                }
                $integrityHash = self::writeIntegrity($tempRoot, $platform);
                if (is_dir($versionRoot)) {
                    self::makeRuntimeWritable($versionRoot, $platform);
                    self::removeTree($versionRoot, $root);
                }
                self::publishVerifiedRuntime($tempRoot, $versionRoot, $platform, $progress);
                $relativeWithinTemp = substr($executable, strlen(rtrim($tempRoot, '\\/')) + 1);
                $finalExecutable = $versionRoot . DIRECTORY_SEPARATOR . $relativeWithinTemp;
                $relative = 'stable/' . $selected['version'] . '/' . str_replace('\\', '/', $relativeWithinTemp);
                self::sealRuntimePermissions($versionRoot, $platform);
                $installed = [
                    'provider' => self::PROVIDER, 'channel' => self::CHANNEL, 'version' => $selected['version'],
                    'platform' => $selected['platform'], 'executable' => $relative, 'download_url' => $selected['url'], 'installed_at' => time(),
                    'integrity_manifest' => 'stable/' . $selected['version'] . '/' . self::INTEGRITY_FILE,
                    'integrity_sha256' => $integrityHash,
                ];
                self::writeInstalled($root, $installed, $platform);
                @unlink($archive);
                self::emit($progress, ['stage' => 'installed', 'version' => $selected['version']] + $platform);
                self::log('installed version=' . $selected['version'] . ' executable=' . $relative, $platform);
                return self::browserRecord($installed, $finalExecutable);
            } finally {
                if (is_dir($tempRoot)) self::removeTree($tempRoot, $root);
            }
        } catch (GatewayError $error) {
            throw $error;
        } catch (\Throwable) {
            self::fail('BROWSER_RUNTIME_INSTALL_FAILED', '浏览器 Runtime 安装未能完成。', $platform, 'installing');
        }
    }

    private static function fetchManifest(array $revision, array $platform): array
    {
        $url = (string) Config::get('gateway', 'browser_runtime_manifest_url');
        $parts = parse_url($url);
        if (!$parts || ($parts['scheme'] ?? '') !== 'https' || strtolower((string) ($parts['host'] ?? '')) !== 'googlechromelabs.github.io'
            || ($parts['path'] ?? '') !== '/chrome-for-testing/last-known-good-versions-with-downloads.json') {
            self::fail('BROWSER_RUNTIME_MANIFEST_FAILED', 'Chrome for Testing manifest 地址不是允许的 Google 官方地址。', $platform, 'fetching_manifest');
        }
        $client = curl();
        try {
            NetworkTransport::configure($client, $revision, $url, 30, 8, false);
            $client->setOpt(CURLOPT_MAXFILESIZE, self::MAX_MANIFEST_BYTES);
            $raw = $client->get($url);
            $raw = is_string($client->rawResponse ?? null) ? $client->rawResponse : (is_string($raw) ? $raw : '');
            if ((int) $client->curlErrorCode !== 0 || (int) $client->httpStatusCode !== 200 || $raw === '' || strlen($raw) > self::MAX_MANIFEST_BYTES) {
                self::fail('BROWSER_RUNTIME_MANIFEST_FAILED', '无法通过当前网络配置读取 Google Stable manifest。', $platform, 'fetching_manifest');
            }
            try { $decoded = json_decode($raw, true, 64, JSON_THROW_ON_ERROR); } catch (\Throwable) {
                self::fail('BROWSER_RUNTIME_MANIFEST_FAILED', 'Google Stable manifest 不是有效 JSON。', $platform, 'fetching_manifest');
            }
            if (!is_array($decoded)) self::fail('BROWSER_RUNTIME_MANIFEST_FAILED', 'Google Stable manifest 格式无效。', $platform, 'fetching_manifest');
            return $decoded;
        } finally { $client->close(); }
    }

    /** Download atomically with independent connection, stalled-transfer and total limits; report byte progress even without a total. */
    private static function download(array $revision, string $url, string $archive, array $platform, ?callable $progress): void
    {
        $part = $archive . '.part';
        @unlink($part);
        if (self::archiveIsReusable($archive, $platform)) {
            $size = (int) filesize($archive);
            self::emit($progress, ['stage' => 'downloading', 'downloaded' => $size, 'total' => $size, 'percent' => 100.0, 'cached' => true] + $platform);
            self::log('download cache reused', $platform);
            return;
        }
        @unlink($archive);
        $file = @fopen($part, 'wb');
        if ($file === false) self::fail('BROWSER_RUNTIME_DOWNLOAD_FAILED', '无法创建浏览器下载临时文件。', $platform, 'downloading');
        $client = null; $lastPercent = -1; $completed = false;
        $startedAt = microtime(true); $lastProgressAt = 0.0; $receivedBytes = 0;
        try {
            $client = curl();
            NetworkTransport::configure($client, $revision, $url, self::DOWNLOAD_TIMEOUT_SECONDS, 15, false);
            $client->setOpt(CURLOPT_LOW_SPEED_LIMIT, 1);
            $client->setOpt(CURLOPT_LOW_SPEED_TIME, self::DOWNLOAD_STALL_SECONDS);
            $client->setFile($file);
            $client->progress(function ($resource, $total, $downloaded) use ($progress, $platform, &$lastPercent, &$lastProgressAt, &$receivedBytes) {
                $receivedBytes = (int) $downloaded;
                if ($downloaded > self::MAX_ARCHIVE_BYTES || ($total > 0 && $total > self::MAX_ARCHIVE_BYTES)) return 1;
                $percent = $total > 0 ? min(100.0, ($downloaded / $total) * 100) : null;
                $bucket = $percent === null ? -1 : (int) floor($percent);
                $now = microtime(true);
                if ($bucket !== $lastPercent || $now - $lastProgressAt >= 2.0) {
                    $lastPercent = $bucket;
                    $lastProgressAt = $now;
                    self::emit($progress, ['stage' => 'downloading', 'downloaded' => (int) $downloaded, 'total' => (int) $total, 'percent' => $percent] + $platform);
                }
                return 0;
            });
            self::log('download started', $platform);
            $client->get($url);
            $errno = (int) $client->curlErrorCode; $http = (int) $client->httpStatusCode;
            if ($errno !== 0 || $http !== 200) {
                self::log('download failed errno=' . $errno . ' http=' . $http . ' downloaded_bytes=' . $receivedBytes
                    . ' elapsed_seconds=' . (int) (microtime(true) - $startedAt), $platform);
                self::fail('BROWSER_RUNTIME_DOWNLOAD_FAILED', 'Chrome for Testing 下载失败；请检查当前网络配置。', $platform, 'downloading');
            }
            $completed = true;
        } finally {
            if ($client !== null) $client->close();
            if (is_resource($file)) fclose($file);
            if (!$completed) @unlink($part);
        }
        clearstatcache(true, $part);
        if (!is_file($part) || (int) filesize($part) < 1 || (int) filesize($part) > self::MAX_ARCHIVE_BYTES || !@rename($part, $archive)) {
            @unlink($part); @unlink($archive);
            self::fail('BROWSER_RUNTIME_DOWNLOAD_FAILED', '浏览器下载文件不完整。', $platform, 'downloading');
        }
    }

    /** A completed retry cache is reusable only when it is a bounded ZIP containing this platform's expected Chrome executable. */
    private static function archiveIsReusable(string $archive, array $platform): bool
    {
        clearstatcache(true, $archive);
        if (!is_file($archive)) return false;
        $size = (int) filesize($archive);
        if ($size < 1 || $size > self::MAX_ARCHIVE_BYTES) return false;
        $expected = match ((string) ($platform['cft_platform'] ?? '')) {
            'win64' => 'chrome-win64/chrome.exe',
            'win32' => 'chrome-win32/chrome.exe',
            'linux64' => 'chrome-linux64/chrome',
            'linux-arm64' => 'chrome-linux-arm64/chrome',
            'mac-x64' => 'chrome-mac-x64/Google Chrome for Testing.app/Contents/MacOS/Google Chrome for Testing',
            'mac-arm64' => 'chrome-mac-arm64/Google Chrome for Testing.app/Contents/MacOS/Google Chrome for Testing',
            default => '',
        };
        if ($expected === '') return false;
        $zip = new \ZipArchive();
        if ($zip->open($archive) !== true) return false;
        try {
            return $zip->numFiles > 0 && $zip->locateName($expected) !== false;
        } finally {
            $zip->close();
        }
    }

    /** Extract regular files first, then restore only links resolving inside the complete private tree. */
    private static function extractSafe(string $archive, string $destination, array $platform): void
    {
        $zip = new \ZipArchive();
        if ($zip->open($archive) !== true) self::fail('BROWSER_RUNTIME_EXTRACT_FAILED', 'Chrome for Testing ZIP 无法打开。', $platform, 'extracting');
        $links = [];
        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i); $name = is_array($stat) ? (string) ($stat['name'] ?? '') : '';
                $relative = self::safeRelative($name);
                if ($relative === null || $relative === '') self::fail('BROWSER_RUNTIME_EXTRACT_FAILED', 'ZIP 包含不安全路径。', $platform, 'extracting');
                $target = rtrim($destination, '\\/') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
                if (str_ends_with(str_replace('\\', '/', $name), '/')) { self::mkdir($target); continue; }
                self::mkdir(dirname($target));

                $opsys = 0; $attr = 0; $isSymlink = false;
                if ($zip->getExternalAttributesIndex($i, $opsys, $attr)) $isSymlink = ((($attr >> 16) & 0170000) === 0120000);
                if ($isSymlink) {
                    $link = $zip->getFromIndex($i);
                    if (!is_string($link) || self::linkTargetRelative($relative, $link) === null) {
                        self::fail('BROWSER_RUNTIME_EXTRACT_FAILED', 'ZIP 包含不安全符号链接。', $platform, 'extracting');
                    }
                    // macOS helpers legitimately point upwards within the same application bundle.
                    // Defer every link so subsequent ZIP writes cannot follow one outside the tree.
                    $links[$relative] = $link;
                    continue;
                }
                $input = $zip->getStream($name); $output = @fopen($target, 'wb');
                if (!is_resource($input) || $output === false) self::fail('BROWSER_RUNTIME_EXTRACT_FAILED', '无法解压浏览器文件。', $platform, 'extracting');
                try { if (stream_copy_to_stream($input, $output) === false) self::fail('BROWSER_RUNTIME_EXTRACT_FAILED', '浏览器文件解压中断。', $platform, 'extracting'); }
                finally { fclose($input); fclose($output); }
                if (PHP_OS_FAMILY !== 'Windows' && !@chmod($target, self::archiveFileMode($opsys, $attr))) {
                    self::fail('BROWSER_RUNTIME_PERMISSIONS_FAILED', '无法恢复浏览器文件的安全执行权限。', $platform, 'extracting');
                }
            }
        } finally { $zip->close(); }
        self::restoreArchiveLinks($destination, $links, $platform);
    }

    /**
     * Normalize a ZIP link target relative to its parent, allowing internal .. but never root escape.
     * @param string $relative Validated archive entry path. @param string $link Raw relative link target.
     * @return string|null Archive-relative destination, or null for an unsafe target.
     */
    private static function linkTargetRelative(string $relative, string $link): ?string
    {
        if ($link === '' || str_starts_with($link, '/') || str_contains($link, '\\')
            || preg_match('/[\x00-\x1f:]/', $link)) return null;
        $parent = dirname($relative);
        $parts = $parent === '.' ? [] : explode('/', $parent);
        foreach (explode('/', $link) as $part) {
            if ($part === '' || $part === '.') continue;
            if ($part === '..') {
                if ($parts === []) return null;
                array_pop($parts);
            } else {
                $parts[] = $part;
            }
        }
        return implode('/', $parts);
    }

    /** Restore internal link chains independently of ZIP order; dangling/cyclic/external links fail closed. */
    private static function restoreArchiveLinks(string $destination, array $links, array $platform): void
    {
        while ($links !== []) {
            $restored = false;
            foreach ($links as $relative => $link) {
                $target = rtrim($destination, '\\/') . '/' . $relative;
                $linkPath = dirname($target) . '/' . $link;
                clearstatcache(true, $linkPath);
                $resolved = realpath($linkPath);
                if ($resolved === false) continue;
                if (!self::pathInside($resolved, realpath($destination) ?: $destination)
                    || !function_exists('symlink') || !@symlink($link, $target)) {
                    self::fail('BROWSER_RUNTIME_EXTRACT_FAILED', '无法恢复浏览器包中的安全符号链接。', $platform, 'extracting');
                }
                unset($links[$relative]);
                $restored = true;
            }
            if (!$restored) self::fail('BROWSER_RUNTIME_EXTRACT_FAILED', '浏览器包中的符号链接目标缺失或循环。', $platform, 'extracting');
        }
    }

    /**
     * Preserve only a Unix archive's executable intent while files remain private and mutable.
     * @param int $opsys ZIP creator OS; only Unix attributes can grant execute permission.
     * @param int $attributes ZIP external attributes, including the Unix mode in the high word.
     * @return int Owner-only read/write, plus owner execute when the original regular file executes.
     */
    private static function archiveFileMode(int $opsys, int $attributes): int
    {
        $mode = ($attributes >> 16) & 0177777;
        return $opsys === \ZipArchive::OPSYS_UNIX && ($mode & 0170000) === 0100000 && ($mode & 0111) !== 0 ? 0700 : 0600;
    }

    private static function locateExecutable(string $root, string $cft, array $platform): string
    {
        $relative = match ($cft) {
            'win64' => 'chrome-win64/chrome.exe', 'win32' => 'chrome-win32/chrome.exe',
            'linux64' => 'chrome-linux64/chrome', 'linux-arm64' => 'chrome-linux-arm64/chrome',
            'mac-x64' => 'chrome-mac-x64/Google Chrome for Testing.app/Contents/MacOS/Google Chrome for Testing',
            'mac-arm64' => 'chrome-mac-arm64/Google Chrome for Testing.app/Contents/MacOS/Google Chrome for Testing',
            default => '',
        };
        $path = $relative === '' ? '' : $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        if ($path === '' || !is_file($path)) self::fail('BROWSER_RUNTIME_VALIDATE_FAILED', '解压完成但未找到预期的 Chrome executable。', $platform, 'validating');
        return $path;
    }

    private static function writeInstalled(string $root, array $data, array $platform): void
    {
        $path = $root . DIRECTORY_SEPARATOR . 'installed.json'; $tmp = $path . '.tmp';
        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
        if (@file_put_contents($tmp, $json, LOCK_EX) !== strlen($json) || !@rename($tmp, $path)) {
            @unlink($tmp); self::fail('BROWSER_RUNTIME_INSTALL_FAILED', '无法保存 installed.json。', $platform, 'installing');
        }
    }

    /**
     * Publish the verified sibling tree under the existing installation lock. Windows scanners
     * can briefly deny directory moves; retry only those native sharing/access errors without
     * redownloading, copying partial files, or overwriting a remaining destination tree.
     * @param string $source Already validated temporary version directory.
     * @param string $destination Final version directory in the same parent.
     * @param array $platform Current browser platform metadata.
     * @param callable|null $progress Existing installation progress observer.
     * @throws GatewayError When publication remains blocked or its paths are invalid.
     */
    private static function publishVerifiedRuntime(string $source, string $destination, array $platform, ?callable $progress): void
    {
        $parent = realpath(dirname($source));
        if ($parent === false || realpath(dirname($destination)) !== $parent || is_link($source)
            || !is_dir($source) || !preg_match('/\A\.install-[a-f0-9]{12}\z/', basename($source))
            || !preg_match('/\A\d+(?:\.\d+){3}\z/', basename($destination))) {
            self::fail('BROWSER_RUNTIME_INSTALL_FAILED', '浏览器 Runtime 发布目录无效。', $platform, 'installing');
        }
        self::emit($progress, ['stage'=>'installing'] + $platform);
        $deadline = microtime(true) + (float) Config::get('gateway', 'browser_runtime_publish_timeout_seconds');
        $attempts = 0;
        $nativeCode = null;
        $detail = '';
        do {
            clearstatcache(true, $destination);
            if (file_exists($destination) || is_link($destination)) {
                $detail = 'destination still exists after stale-version cleanup';
                break;
            }
            ++$attempts;
            error_clear_last();
            if (@rename($source, $destination)) return;
            $detail = (string) (error_get_last()['message'] ?? 'directory rename failed');
            $nativeCode = preg_match('/\(code:\s*(\d+)\)/', $detail, $match) === 1 ? (int) $match[1] : null;
            if (PHP_OS_FAMILY !== 'Windows' || !in_array($nativeCode, [5, 32, 33], true)
                || microtime(true) >= $deadline) break;
            usleep(100000);
        } while (true);
        $detail = preg_replace('/[\x00-\x1F\x7F]/', ' ', $detail) ?? '';
        self::log('publication failed attempts=' . $attempts . ' native_code=' . ($nativeCode ?? 'unknown')
            . ' detail=' . mb_substr($detail, 0, 1200), $platform);
        self::fail('BROWSER_RUNTIME_INSTALL_FAILED', '无法发布已验证的浏览器 Runtime。'
            . ($nativeCode !== null ? ' Windows error=' . $nativeCode . '。' : '')
            . ' 请确认安装目录可写，且浏览器文件未被其他程序持续占用。', $platform, 'installing');
    }

    /** Write a deterministic hash manifest before the version directory becomes immutable. */
    private static function writeIntegrity(string $versionRoot, array $platform): string
    {
        $unixPermissions = PHP_OS_FAMILY !== 'Windows';
        $entries = self::integrityEntries($versionRoot, $unixPermissions);
        if ($entries === null) self::fail('BROWSER_RUNTIME_INTEGRITY_FAILED', '无法生成浏览器 Runtime 完整性清单。', $platform, 'integrity');
        $payload = ['schema' => $unixPermissions ? 2 : 1, 'files' => $entries];
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
        $path = rtrim($versionRoot, '\\/') . DIRECTORY_SEPARATOR . self::INTEGRITY_FILE;
        if (@file_put_contents($path, $json, LOCK_EX) !== strlen($json)) {
            self::fail('BROWSER_RUNTIME_INTEGRITY_FAILED', '无法保存浏览器 Runtime 完整性清单。', $platform, 'integrity');
        }
        return hash('sha256', $json);
    }

    /**
     * Verify all bytes/links and, for Unix schema 2, executable intent before a Runtime is reused.
     * Permission repair can ignore current mode only after the hashed manifest and all file bytes agree.
     */
    private static function verifyIntegrity(string $versionRoot, string $expectedHash, bool $requireUnixPermissions = false, bool $checkPermissions = true): bool
    {
        $path = rtrim($versionRoot, '\\/') . DIRECTORY_SEPARATOR . self::INTEGRITY_FILE;
        $raw = @file_get_contents($path);
        if (!is_string($raw) || strlen($raw) > 32 * 1024 * 1024 || !hash_equals($expectedHash, hash('sha256', $raw))) return false;
        try { $data = json_decode($raw, true, 64, JSON_THROW_ON_ERROR); } catch (\Throwable) { return false; }
        if (!is_array($data) || !in_array($data['schema'] ?? null, [1, 2], true) || !is_array($data['files'] ?? null)) return false;
        if ($requireUnixPermissions && $data['schema'] !== 2) return false;
        $expected = $data['files'];
        if ($data['schema'] === 2) {
            foreach ($expected as &$entry) {
                if (!is_array($entry)) return false;
                if (($entry['type'] ?? '') === 'file') {
                    if (!is_bool($entry['executable'] ?? null)) return false;
                    if (!$checkPermissions) unset($entry['executable']);
                }
            }
            unset($entry);
        }
        $actual = self::integrityEntries($versionRoot, $data['schema'] === 2 && $checkPermissions);
        return $actual !== null && $actual === $expected;
    }

    /** @return array<string,array>|null Content records, optionally binding each regular file's executable intent. */
    private static function integrityEntries(string $versionRoot, bool $includePermissions = false): ?array
    {
        if (!is_dir($versionRoot)) return null;
        $root = rtrim($versionRoot, '\\/');
        $entries = [];
        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST
            );
            foreach ($iterator as $info) {
                $full = $info->getPathname();
                $relative = str_replace('\\', '/', substr($full, strlen($root) + 1));
                if ($relative === self::INTEGRITY_FILE || $relative === '') continue;
                if (self::safeRelative($relative) === null) return null;
                if ($info->isLink()) {
                    $target = readlink($full);
                    if (!is_string($target)) return null;
                    $entries[$relative] = ['type' => 'link', 'target' => $target];
                    continue;
                }
                if ($info->isDir()) continue;
                if (!$info->isFile()) return null;
                $hash = @hash_file('sha256', $full);
                if (!is_string($hash)) return null;
                $entries[$relative] = ['type' => 'file', 'size' => (int) $info->getSize(), 'sha256' => $hash];
                if ($includePermissions) $entries[$relative]['executable'] = ($info->getPerms() & 0111) !== 0;
            }
        } catch (\Throwable) {
            return null;
        }
        ksort($entries, SORT_STRING);
        return $entries;
    }

    /** Seal only the product-owned version tree. Never change drive/project parent ACLs. */
    private static function sealRuntimePermissions(string $versionRoot, array $platform): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            self::chmodTreeReadOnly($versionRoot);
            return;
        }
        $icacls = (getenv('SystemRoot') ?: 'C:\\Windows') . '\\System32\\icacls.exe';
        self::runIcacls($icacls, [
            $versionRoot, '/inheritance:r', '/grant:r',
            // /T applies these permissions to every existing object. Do not use OI/CI here:
            // on a file those flags can turn the ACE into inherit-only and make chrome.exe unreadable.
            '*S-1-5-32-545:(RX)',
            '*S-1-5-18:(F)',
            '*S-1-5-32-544:(F)',
            '*S-1-15-2-1:(RX)',
            '*S-1-15-2-2:(RX)',
            '/T', '/C', '/Q',
        ], $platform, true);
        self::log('project Runtime version sealed read-only', $platform);
    }

    /**
     * Repair only a previously installed version. Unix callers hold the installation lock; bytes
     * must match the original hashed manifest before chmod or a legacy manifest migration occurs.
     */
    private static function repairInstalledRuntimePermissions(string $root, array $platform): bool
    {
        $manifest = rtrim($root, '\\/') . DIRECTORY_SEPARATOR . 'installed.json';
        $raw = @file_get_contents($manifest);
        if (!is_string($raw) || strlen($raw) > 64 * 1024) return false;
        try { $data = json_decode($raw, true, 32, JSON_THROW_ON_ERROR); } catch (\Throwable) { return false; }
        if (!is_array($data)
            || ($data['provider'] ?? '') !== self::PROVIDER
            || ($data['channel'] ?? '') !== self::CHANNEL
            || ($data['platform'] ?? '') !== ($platform['cft_platform'] ?? null)
            || !is_string($data['version'] ?? null)
            || !preg_match('/\A\d+(?:\.\d+){3}\z/', $data['version'])) return false;
        $versionRoot = rtrim($root, '\\/') . DIRECTORY_SEPARATOR . 'stable' . DIRECTORY_SEPARATOR . $data['version'];
        if (!is_dir($versionRoot)) return false;
        if (PHP_OS_FAMILY !== 'Windows') {
            $realRoot = realpath($root);
            $realVersion = realpath($versionRoot);
            $executable = is_string($data['executable'] ?? null) ? self::safeRelative($data['executable']) : null;
            if ($realRoot === false || $realVersion === false || is_link($versionRoot)
                || !self::pathInside($realVersion, $realRoot)
                || $executable === null || !str_starts_with($executable, 'stable/' . $data['version'] . '/')
                || !is_file($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $executable))
                || ($data['integrity_manifest'] ?? '') !== 'stable/' . $data['version'] . '/' . self::INTEGRITY_FILE
                || !is_string($data['integrity_sha256'] ?? null)
                || !preg_match('/\A[a-f0-9]{64}\z/', $data['integrity_sha256'])
                || !self::verifyIntegrity($versionRoot, $data['integrity_sha256'], false, false)) return false;
            $integrityPath = $versionRoot . DIRECTORY_SEPARATOR . self::INTEGRITY_FILE;
            $integrity = json_decode((string) file_get_contents($integrityPath), true, 64, JSON_THROW_ON_ERROR);
            $legacy = $integrity['schema'] === 1;
            try {
                foreach ($integrity['files'] as $relative => $entry) {
                    if (($entry['type'] ?? '') !== 'file') continue;
                    $path = $versionRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
                    $executable = $legacy ? self::legacyExecutable($path, $relative) : $entry['executable'];
                    if (!@chmod($path, $executable ? 0555 : 0444)) {
                        self::fail('BROWSER_RUNTIME_PERMISSIONS_FAILED', '无法修复浏览器辅助程序的执行权限。', $platform, 'permissions');
                    }
                }
                if ($legacy) {
                    // Only the integrity metadata becomes writable; authenticated runtime bytes stay sealed.
                    if (!@chmod($versionRoot, 0700) || !@chmod($integrityPath, 0600)) {
                        self::fail('BROWSER_RUNTIME_PERMISSIONS_FAILED', '无法迁移浏览器权限清单。', $platform, 'permissions');
                    }
                    clearstatcache();
                    $data['integrity_sha256'] = self::writeIntegrity($versionRoot, $platform);
                }
            } finally {
                clearstatcache();
                self::sealRuntimePermissions($versionRoot, $platform);
            }
            if ($legacy) self::writeInstalled($root, $data, $platform);
            clearstatcache();
            return self::verifyIntegrity($versionRoot, $data['integrity_sha256'], true);
        }
        self::sealRuntimePermissions($versionRoot, $platform);
        return true;
    }

    /**
     * Recover executable intent from authenticated legacy bytes after the old installer lost ZIP modes.
     * ELF executable/shared-object and Mach-O executable/dylib headers cover CfT native helpers and
     * libraries. Only Chrome's exact Linux wrapper path may obtain execute from a script header.
     * @param string $path Integrity-verified regular file; never a symlink or an external path.
     * @param string $relative Safe path within the verified version tree.
     * @return bool Whether the old file is a native runtime program/library or the official wrapper.
     */
    private static function legacyExecutable(string $path, string $relative): bool
    {
        $stream = @fopen($path, 'rb');
        if ($stream === false) return false;
        try {
            $header = (string) fread($stream, 64);
            if (str_starts_with($header, "\x7fELF") && strlen($header) >= 18 && in_array(ord($header[5]), [1, 2], true)) {
                $type = unpack(ord($header[5]) === 1 ? 'vtype' : 'ntype', substr($header, 16, 2));
                return in_array($type['type'], [2, 3], true);
            }
            $magic = substr($header, 0, 4);
            $little = in_array($magic, ["\xce\xfa\xed\xfe", "\xcf\xfa\xed\xfe"], true);
            if (strlen($header) >= 16 && ($little || in_array($magic, ["\xfe\xed\xfa\xce", "\xfe\xed\xfa\xcf"], true))) {
                $type = unpack($little ? 'Vtype' : 'Ntype', substr($header, 12, 4));
                return in_array($type['type'], [2, 6], true);
            }
            if (in_array($relative, ['chrome-linux64/chrome-wrapper', 'chrome-linux-arm64/chrome-wrapper'], true)) {
                return str_starts_with($header, '#!');
            }
            return false;
        } finally {
            fclose($stream);
        }
    }

    /** Temporarily unlock only a stale product-owned version tree before replacing it. */
    private static function makeRuntimeWritable(string $versionRoot, array $platform): void
    {
        if (!is_dir($versionRoot)) return;
        if (PHP_OS_FAMILY !== 'Windows') {
            self::chmodTreeWritable($versionRoot);
            return;
        }
        $icacls = (getenv('SystemRoot') ?: 'C:\\Windows') . '\\System32\\icacls.exe';
        self::runIcacls($icacls, [
            $versionRoot, '/inheritance:e', '/grant:r',
            '*S-1-5-32-545:(M)', '*S-1-5-18:(F)', '*S-1-5-32-544:(F)',
            '/T', '/C', '/Q',
        ], $platform, false);
    }

    private static function runIcacls(string $icacls, array $arguments, array $platform, bool $required): void
    {
        if (!is_file($icacls) || !function_exists('proc_open')) {
            if ($required) self::fail('BROWSER_RUNTIME_PERMISSIONS_FAILED', '无法锁定项目私有浏览器 Runtime。', $platform, 'permissions');
            return;
        }
        $process = @proc_open(array_merge([$icacls], $arguments),
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null,
            ['bypass_shell' => true]);
        if (!is_resource($process)) {
            if ($required) self::fail('BROWSER_RUNTIME_PERMISSIONS_FAILED', '无法锁定项目私有浏览器 Runtime。', $platform, 'permissions');
            return;
        }
        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        $code = proc_close($process);
        if ($code === 0 || !$required) return;
        $detail = trim($stderr !== '' ? $stderr : $stdout);
        $detail = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $detail) ?? '';
        self::fail('BROWSER_RUNTIME_PERMISSIONS_FAILED', '无法锁定项目私有浏览器 Runtime。' . ($detail !== '' ? ' ' . mb_substr($detail, 0, 240) : ''), $platform, 'permissions');
    }

    private static function chmodTreeReadOnly(string $root): void
    {
        if (!is_dir($root)) return;
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $info) {
            if ($info->isLink()) continue;
            if ($info->isDir()) @chmod($info->getPathname(), 0555);
            elseif ($info->isFile()) @chmod($info->getPathname(), (($info->getPerms() & 0111) !== 0) ? 0555 : 0444);
        }
        @chmod($root, 0555);
    }

    private static function chmodTreeWritable(string $root): void
    {
        if (!is_dir($root)) return;
        @chmod($root, 0700);
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $info) {
            if ($info->isLink()) continue;
            if ($info->isDir()) @chmod($info->getPathname(), 0700);
            elseif ($info->isFile()) @chmod($info->getPathname(), 0600 | (($info->getPerms() & 0111) !== 0 ? 0100 : 0));
        }
    }

    private static function assertDownloadUrl(string $url, string $version, string $cft, array $platform): void
    {
        $parts = parse_url($url);
        $expected = '/chrome-for-testing-public/' . $version . '/' . $cft . '/chrome-' . $cft . '.zip';
        if (!$parts || ($parts['scheme'] ?? '') !== 'https' || strtolower((string) ($parts['host'] ?? '')) !== 'storage.googleapis.com'
            || ($parts['path'] ?? '') !== $expected || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            self::fail('BROWSER_RUNTIME_INVALID_DOWNLOAD_URL', 'Stable manifest 返回了不允许的 Chrome 下载地址。', $platform, 'fetching_manifest');
        }
    }

    private static function browserRecord(array $data, string $path): array
    {
        return ['id' => 'bundled', 'kind' => 'chrome', 'label' => 'Chrome for Testing', 'path' => $path,
            'provider' => 'bundled', 'version' => (string) $data['version']];
    }

    private static function root(): string
    {
        $root = rtrim((string) Config::get('gateway', 'browser_runtime_dir'), '\\/');
        $project = realpath(phar_path()) ?: phar_path();
        $probe = $root;
        while ($probe !== '' && !file_exists($probe)) {
            $parent = dirname($probe);
            if ($parent === $probe) break;
            $probe = $parent;
        }
        $resolved = realpath($probe);
        if ($root === '' || $resolved === false || !self::pathInside($resolved, $project)) {
            throw new GatewayError('BROWSER_RUNTIME_STORAGE_ESCAPE', '项目私有浏览器 Runtime 目录越出了项目边界。', 503);
        }
        if (file_exists($root)) {
            $realRoot = realpath($root);
            if ($realRoot === false || !self::pathInside($realRoot, $project)) {
                throw new GatewayError('BROWSER_RUNTIME_STORAGE_ESCAPE', '项目私有浏览器 Runtime 目录越出了项目边界。', 503);
            }
        }
        return $root;
    }

    private static function pathInside(string $path, string $root): bool
    {
        $path = rtrim(str_replace('\\', '/', $path), '/');
        $root = rtrim(str_replace('\\', '/', $root), '/');
        if (PHP_OS_FAMILY === 'Windows') { $path = strtolower($path); $root = strtolower($root); }
        return $path === $root || str_starts_with($path . '/', $root . '/');
    }

    private static function safeRelative(string $path): ?string
    {
        $path = str_replace('\\', '/', trim($path));
        if ($path === '' || str_starts_with($path, '/') || preg_match('/\A[A-Za-z]:/', $path) || str_contains($path, "\0")) return null;
        $parts = [];
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.') continue;
            if ($part === '..' || str_contains($part, ':')) return null;
            $parts[] = $part;
        }
        return implode('/', $parts);
    }

    private static function mkdir(string $path): void
    {
        if (!is_dir($path) && !@mkdir($path, 0700, true) && !is_dir($path)) throw new \RuntimeException('mkdir failed');
    }

    private static function removeTree(string $path, string $root): void
    {
        $root = rtrim(str_replace('\\', '/', $root), '/'); $normalized = str_replace('\\', '/', $path);
        if ($normalized === $root || !str_starts_with($normalized . '/', $root . '/')) return;
        if (is_link($path) || is_file($path)) { @unlink($path); return; }
        if (!is_dir($path)) return;
        foreach (scandir($path) ?: [] as $name) if ($name !== '.' && $name !== '..') self::removeTree($path . DIRECTORY_SEPARATOR . $name, $root);
        @rmdir($path);
    }

    private static function emit(?callable $progress, array $event): void { if ($progress !== null) $progress($event); }
    private static function log(string $message, array $platform): void
    {
        error_log('[browser-runtime] ' . $message . ' os=' . ($platform['os'] ?? 'unknown') . ' arch=' . ($platform['arch'] ?? 'unknown') . ' cft=' . ($platform['cft_platform'] ?? 'unsupported'));
    }

    /** @return never */
    private static function fail(string $code, string $message, array $platform, string $stage, int $http = 503): never
    {
        $suffix = ' [os=' . ($platform['os'] ?? 'unknown') . ', arch=' . ($platform['arch'] ?? 'unknown') . ', cft=' . ($platform['cft_platform'] ?? 'unsupported') . ', stage=' . $stage . ']';
        throw new GatewayError($code, $message . $suffix, $http);
    }
}
