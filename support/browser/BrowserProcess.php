<?php
declare(strict_types=1);
namespace support\browser;

use RC\Config;
use app\gateway\model\BrowserState;
use app\gateway\model\GatewayError;

/** Detects and owns only browser processes launched with this product's dedicated profile and loopback CDP port. */
final class BrowserProcess
{
    /**
     * Declare this browser's host product while preserving its actual native UA and Client Hints.
     *
     * The ChatGPT task surface needs a stable application identity, like the reference launcher's
     * default Electron product token. Never borrow its product name or freeze a Chrome version.
     * Unsupported native metadata leaves the browser unchanged; it must not block local work.
     *
     * @param string $userAgent Browser.getVersion's native userAgent from the owned browser.
     * @param array $identity Native navigator.platform and userAgentData from an owned secure page.
     * @return array|null Parameters for Network.setUserAgentOverride, or null when unavailable.
     */
    public static function taskIdentityParameters(string $userAgent, array $identity): ?array
    {
        $platform = $identity['platform'] ?? null;
        $metadata = $identity['metadata'] ?? null;
        if ($userAgent === '' || strlen($userAgent) > 512
            || !str_contains($userAgent, '(KHTML, like Gecko)')
            || !is_string($platform) || $platform === ''
            || !is_array($metadata) || !is_array($metadata['brands'] ?? null)
            || !is_array($metadata['fullVersionList'] ?? null)) return null;
        if (!preg_match('~(?:^|\s)RCMAKER/[0-9.]+(?:\s|$)~', $userAgent)) {
            $userAgent = str_replace('(KHTML, like Gecko)', '(KHTML, like Gecko) RCMAKER/1.0.0', $userAgent);
        }
        return ['userAgent' => $userAgent, 'platform' => $platform,
            'userAgentMetadata' => array_intersect_key($metadata, array_flip([
                'brands', 'fullVersionList', 'platform', 'platformVersion',
                'architecture', 'bitness', 'model', 'mobile', 'wow64',
            ]))];
    }

    /** Advertise only the project-private browser Runtime. No system browser is inspected here. */
    public static function detected(): array
    {
        if ((ChromeForTestingRuntime::platform()['cft_platform'] ?? null) === null) return [];
        return ['bundled' => [
            'id' => 'bundled', 'kind' => 'chrome', 'label' => 'Chrome for Testing（项目私有）',
            'path' => '', 'provider' => 'bundled',
        ]];
    }

    /** Resolve only the project-private Runtime. System browsers are never part of the product runtime chain. */
    public static function select(string $preference, ?array $revision = null): array
    {
        if (!in_array($preference, ['auto', 'bundled'], true)) {
            throw new GatewayError('BROWSER_RUNTIME_PRIVATE_ONLY', '当前单机版只允许使用项目私有 Chrome for Testing Runtime。', 422);
        }
        if ($revision !== null) return self::ensureBundled($revision);
        $installed = ChromeForTestingRuntime::installed();
        if ($installed !== null) return $installed;
        throw new GatewayError('BROWSER_NOT_FOUND', '项目私有 Chrome for Testing Runtime 尚未安装。', 422);
    }

    /**
     * Launch one headed controlled browser whose native window stays off-screen while its pixels
     * are shown by the Web workspace. ChatGPT/Cloudflare treats Chromium headless mode differently
     * from an ordinary desktop browser, so the product keeps the normal renderer path here.
     * @param array $revision Immutable network revision selected by the caller.
     * @return array{process:resource,pid:int,kind:string,path:string,profile:string,debug_port:int}
     * @throws GatewayError
     */
    public static function launch(string $preference, int $debugPort, array $revision, ?string $initialUrl = null): array
    {
        if (!function_exists('proc_open')) {
            throw new GatewayError('BROWSER_PROCESS_UNAVAILABLE', '当前 PHP 环境不允许启动本机浏览器。', 503);
        }
        if ($revision['mode'] === 'unconfigured') {
            throw new GatewayError('NETWORK_UNCONFIGURED', '请先保存直连或 HTTP 代理配置。', 422);
        }
        if ($revision['mode'] === 'manual' && ($revision['protocol'] !== 'http')) {
            throw new GatewayError('BROWSER_PROXY_UNSUPPORTED', '受控浏览器当前只支持 HTTP 代理。', 422);
        }
        if ($revision['mode'] === 'manual' && ($revision['username'] !== '' || $revision['protected_password'] !== null)) {
            throw new GatewayError('BROWSER_PROXY_AUTH_UNSUPPORTED', '受控浏览器暂不支持需要用户名/密码的代理，请使用无认证 HTTP 代理。', 422);
        }
        $browser = self::select($preference, $revision);
        $profile = self::privateDirectory('browser_profile_dir', 'Profile');
        $cache = self::privateDirectory('browser_cache_dir', 'Cache');
        $temp = self::privateDirectory('browser_temp_dir', 'Temp');
        $home = self::privateDirectory('browser_home_dir', 'Home');
        $logDir = self::privateDirectory('browser_log_dir', 'Log');
        @unlink(rtrim($profile, '\\/') . DIRECTORY_SEPARATOR . 'DevToolsActivePort');
        $chromeLog = $logDir . DIRECTORY_SEPARATOR . 'chrome.log';
        $stderrTarget = $logDir . DIRECTORY_SEPARATOR . 'stderr.log';
        @unlink($chromeLog);
        @unlink($stderrTarget);
        $args = [
            $browser['path'],
            '--user-data-dir=' . $profile,
            '--disk-cache-dir=' . $cache,
            '--remote-debugging-address=127.0.0.1',
            '--remote-debugging-port=' . $debugPort,
            '--window-size=1280,720',
            '--window-position=-32000,-32000',
            '--force-device-scale-factor=1',
            '--disable-backgrounding-occluded-windows',
            '--disable-renderer-backgrounding',
            '--disable-background-mode',
            '--no-first-run',
            '--no-default-browser-check',
            '--disable-component-update',
            '--disable-extensions',
            '--disable-sync',
            '--disable-breakpad',
            '--disable-crash-reporter',
            '--enable-logging',
            '--log-file=' . $chromeLog,
        ];
        if ($revision['mode'] === 'manual') {
            $host = str_contains((string) $revision['host'], ':') ? '[' . $revision['host'] . ']' : $revision['host'];
            $args[] = '--proxy-server=http://' . $host . ':' . (int) $revision['port'];
        } else {
            $args[] = '--no-proxy-server';
        }
        $args[] = self::initialUrl($initialUrl);
        $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $environment = self::privateEnvironment($home, $cache, $temp);
        $owned = \support\process\OwnedRuntime::launch($args, $environment,
            (string) Config::get('gateway', 'browser_runtime_dir'), 'chrome', $stderrTarget);
        return $owned + ['kind' => $browser['kind'], 'profile' => $profile, 'debug_port' => $debugPort, 'mode' => 'controlled', 'provider' => 'bundled'];
    }

    /** Cleanup is scoped to the verified private executable identity, never a vendor-wide process name. */
    private static function cleanupWindowsRuntimeProcesses(string $executable): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            \support\process\OwnedRuntime::cleanWindows((string) Config::get('gateway', 'browser_runtime_dir'), 'chrome');
        }
    }

    private static function privateDirectory(string $configKey, string $label): string
    {
        $path = rtrim((string) Config::get('gateway', $configKey), '\\/');
        $project = realpath(phar_path()) ?: phar_path();
        if ($path === '' || (!is_dir($path) && !@mkdir($path, 0700, true) && !is_dir($path))) {
            throw new GatewayError('BROWSER_PRIVATE_STORAGE_UNAVAILABLE', '无法创建项目私有浏览器' . $label . '目录。', 503);
        }
        $real = realpath($path);
        if ($real === false || !self::pathInside($real, $project)) {
            throw new GatewayError('BROWSER_PRIVATE_STORAGE_ESCAPE', '项目私有浏览器' . $label . '目录越出了项目边界。', 503);
        }
        if (PHP_OS_FAMILY !== 'Windows') @chmod($real, 0700);
        return $real;
    }

    private static function pathInside(string $path, string $root): bool
    {
        $path = rtrim(str_replace('\\', '/', $path), '/');
        $root = rtrim(str_replace('\\', '/', $root), '/');
        if (PHP_OS_FAMILY === 'Windows') { $path = strtolower($path); $root = strtolower($root); }
        return $path === $root || str_starts_with($path . '/', $root . '/');
    }

    /** Build a minimal child environment with browser-owned home/cache/temp paths. */
    private static function privateEnvironment(string $home, string $cache, string $temp): array
    {
        $environment = [];
        $copy = ['LANG', 'LC_ALL', 'LC_CTYPE'];
        if (PHP_OS_FAMILY === 'Windows') {
            $copy = array_merge($copy, ['SystemRoot', 'WINDIR', 'ComSpec', 'SystemDrive', 'PATHEXT', 'PROCESSOR_ARCHITECTURE', 'NUMBER_OF_PROCESSORS', 'USERNAME']);
        } else {
            $copy = array_merge($copy, ['DISPLAY', 'WAYLAND_DISPLAY', 'XAUTHORITY', 'DBUS_SESSION_BUS_ADDRESS', 'XDG_RUNTIME_DIR']);
        }
        foreach ($copy as $name) {
            $value = getenv($name);
            if (is_string($value) && $value !== '') $environment[$name] = $value;
        }
        $environment['HOME'] = $home;
        if (PHP_OS_FAMILY === 'Windows') {
            $roaming = $home . DIRECTORY_SEPARATOR . 'AppData' . DIRECTORY_SEPARATOR . 'Roaming';
            $local = $home . DIRECTORY_SEPARATOR . 'AppData' . DIRECTORY_SEPARATOR . 'Local';
            $localLow = $home . DIRECTORY_SEPARATOR . 'AppData' . DIRECTORY_SEPARATOR . 'LocalLow';
            foreach ([$roaming, $local, $localLow] as $directory) if (!is_dir($directory)) @mkdir($directory, 0700, true);
            $systemRoot = $environment['SystemRoot'] ?? $environment['WINDIR'] ?? 'C:\\Windows';
            $environment['USERPROFILE'] = $home;
            $environment['APPDATA'] = $roaming;
            $environment['LOCALAPPDATA'] = $local;
            $environment['TEMP'] = $temp;
            $environment['TMP'] = $temp;
            $environment['Path'] = $systemRoot . '\\System32;' . $systemRoot . ';' . $systemRoot . '\\System32\\Wbem';
        } else {
            $config = $home . DIRECTORY_SEPARATOR . '.config';
            $data = $home . DIRECTORY_SEPARATOR . '.local' . DIRECTORY_SEPARATOR . 'share';
            foreach ([$config, $data] as $directory) if (!is_dir($directory)) @mkdir($directory, 0700, true);
            $environment['TMPDIR'] = $temp;
            $environment['XDG_CONFIG_HOME'] = $config;
            $environment['XDG_CACHE_HOME'] = $cache;
            $environment['XDG_DATA_HOME'] = $data;
            $environment['PATH'] = '/usr/bin:/bin:/usr/sbin:/sbin';
        }
        return $environment;
    }
    private static function ensureBundled(array $revision): array
    {
        $lastPercent = -1;
        $runtime = ChromeForTestingRuntime::ensure($revision, static function (array $event) use (&$lastPercent): void {
            $stage = (string) ($event['stage'] ?? 'unknown');
            $percent = isset($event['percent']) && is_numeric($event['percent']) ? (int) floor((float) $event['percent']) : null;
            if ($stage === 'downloading' && $percent !== null) {
                if ($percent === $lastPercent || ($percent % 5 !== 0 && $percent !== 100)) return;
                $lastPercent = $percent;
                error_log('[browser-runtime] download progress percent=' . $percent);
                return;
            }
            error_log('[browser-runtime] stage=' . $stage);
        });
        error_log('[browser] selected provider=bundled kind=chrome version=' . ($runtime['version'] ?? 'unknown'));
        return $runtime;
    }

    /** Stop a browser only after ownership is established by the exact dedicated profile and CDP port. */
    public static function terminate(?array &$owned, int $pid, string $profile, int $debugPort, string $webSocketUrl = ''): void
    {
        if ($owned !== null && isset($owned['control']) && (int) ($owned['pid'] ?? 0) === $pid
            && self::sameProfile((string) ($owned['profile'] ?? ''), $profile) && (int) ($owned['debug_port'] ?? 0) === $debugPort) {
            if ($webSocketUrl !== '' && self::endpointMatches($profile, $debugPort, $webSocketUrl)) {
                self::requestBrowserClose($webSocketUrl);
            }
            \support\process\OwnedRuntime::stop($owned);
            return;
        }
        $ownedHere = $owned !== null
            && isset($owned['process']) && is_resource($owned['process'])
            && (int) ($owned['pid'] ?? 0) === $pid
            && (int) ($owned['debug_port'] ?? 0) === $debugPort
            && self::sameProfile((string) ($owned['profile'] ?? ''), $profile);
        $ownershipConfirmed = $pid > 0 && (
            $ownedHere
            || ($webSocketUrl !== '' && self::recoveredOwnershipConfirmed($pid, $profile, $debugPort, $webSocketUrl))
            || self::ownershipConfirmed($pid, $profile, $debugPort)
        );
        if (!$ownershipConfirmed) {
            throw new GatewayError('BROWSER_OWNERSHIP_UNCONFIRMED', '无法确认该进程属于本工作区，已拒绝结束它。', 409);
        }

        // Prefer asking the already-verified Chromium instance to exit itself.
        // This still works after a worker reload when Windows no longer allows
        // the new worker to terminate the old process tree directly.
        if ($webSocketUrl !== '' && self::endpointMatches($profile, $debugPort, $webSocketUrl)
            && self::requestBrowserClose($webSocketUrl)) {
            $deadline = microtime(true) + 3;
            do {
                if (PHP_OS_FAMILY === 'Windows' && self::windowsLoopbackListenerOwnedByPid($pid, $debugPort) === false) {
                    self::releaseOwnedProcess($owned);
                    return;
                }
                usleep(30000);
            } while (microtime(true) < $deadline);
        }

        if ($ownedHere) {
            $ownedExecutable = (string) ($owned['path'] ?? '');
            $status = proc_get_status($owned['process']);
            if (PHP_OS_FAMILY === 'Windows' && $status['running']) {
                // Chromium uses a multi-process tree. Once the product-owned root exits,
                // detached renderer/GPU children are harder to attribute safely, so kill
                // the exact owned tree while the verified root PID is still alive.
                self::killPid($pid);
            } elseif ($status['running']) {
                @proc_terminate($owned['process']);
            }
            $deadline = microtime(true) + 3;
            do {
                $status = proc_get_status($owned['process']);
                if (!$status['running']) break;
                usleep(30000);
            } while (microtime(true) < $deadline);
            if ($status['running'] && self::ownershipConfirmed($pid, $profile, $debugPort)) {
                self::killPid($pid);
            }
            @proc_close($owned['process']);
            $owned = null;
            if (PHP_OS_FAMILY === 'Windows' && $ownedExecutable !== '') {
                self::cleanupWindowsRuntimeProcesses($ownedExecutable);
            }
            return;
        }
        self::killPid($pid);
    }

    /**
     * Drop only Chromium's tab/session-restore files from the product-owned profile.
     * Cookies, account login state, Local Storage and other profile data are preserved.
     */
    public static function clearSessionRestore(string $profile): int
    {
        $real = realpath($profile);
        $project = realpath(phar_path()) ?: phar_path();
        if ($real === false) return 0;
        if (!self::pathInside($real, $project)) {
            throw new GatewayError('BROWSER_PRIVATE_STORAGE_ESCAPE', '项目私有浏览器 Profile 越出了项目边界。', 503);
        }
        $default = $real . DIRECTORY_SEPARATOR . 'Default';
        if (!is_dir($default)) return 0;
        $removed = 0;
        foreach (['Current Session', 'Current Tabs', 'Last Session', 'Last Tabs'] as $legacy) {
            $path = $default . DIRECTORY_SEPARATOR . $legacy;
            if (is_file($path) && @unlink($path)) $removed++;
        }
        $sessions = $default . DIRECTORY_SEPARATOR . 'Sessions';
        if (!is_dir($sessions)) return $removed;
        foreach (scandir($sessions) ?: [] as $name) {
            if ($name === '.' || $name === '..' || preg_match('/^(?:Session|Tabs)_/i', $name) !== 1) continue;
            $path = $sessions . DIRECTORY_SEPARATOR . $name;
            if (is_file($path) && @unlink($path)) $removed++;
        }
        return $removed;
    }

    /**
     * Remove every Chrome user Profile and account hint owned by this application after its browser stops.
     * This explicit account-switch operation also includes the known pre-CfT profile, Chrome
     * disk cache and exact Chrome user-data directories under the isolated browser HOME;
     * normal start/stop continues to preserve login state through clearSessionRestore().
     * @return array{directories:int,files:int} Counts of removed private entries, without exposing paths.
     * @throws GatewayError If the browser is live, a path escapes the application, or removal is incomplete.
     */
    public static function purgePrivateProfileStorage(): array
    {
        $state = BrowserState::state();
        if ($state['status'] !== 'stopped' || (int) ($state['browser_pid'] ?? 0) > 0) {
            throw new GatewayError('BROWSER_PROFILE_BUSY', '请先等待项目私有浏览器完全停止。', 409);
        }
        $project = realpath(phar_path());
        if ($project === false) {
            throw new GatewayError('BROWSER_PRIVATE_STORAGE_UNAVAILABLE', '项目私有浏览器目录不可用。', 503);
        }
        $name = basename(rtrim((string) Config::get('gateway', 'browser_profile_dir'), '\\/'));
        if (preg_match('/\A[a-zA-Z0-9_-]{1,80}\z/', $name) !== 1) {
            throw new GatewayError('BROWSER_PRIVATE_STORAGE_ESCAPE', '项目私有浏览器 Profile 名称无效。', 503);
        }
        $base = $project . DIRECTORY_SEPARATOR . 'data';
        $targets = [
            'profile' => $base . '/browser-profile/cft/' . $name,
            'legacy_profile' => $base . '/browser-profile/' . $name,
            'cache' => $base . '/browser-cache/cft/' . $name,
            'temp' => $base . '/browser-temp/cft/' . $name,
            // Windows populates the private HOME with system junctions. Never recurse over
            // that whole directory; remove only Chrome's known profile subtrees within it.
            'home_chrome_local' => $base . '/browser-home/cft/' . $name . '/AppData/Local/Google/Chrome/User Data',
            'home_chrome_roaming' => $base . '/browser-home/cft/' . $name . '/AppData/Roaming/Google/Chrome/User Data',
            'home_chrome_config' => $base . '/browser-home/cft/' . $name . '/.config/google-chrome',
            'home_chromium_config' => $base . '/browser-home/cft/' . $name . '/.config/chromium',
            'home_chrome_cache' => $base . '/browser-home/cft/' . $name . '/.cache/google-chrome',
        ];
        $configured = [
            'browser_profile_dir' => $targets['profile'],
            'browser_cache_dir' => $targets['cache'],
            'browser_temp_dir' => $targets['temp'],
            'browser_home_dir' => $base . '/browser-home/cft/' . $name,
        ];
        foreach ($configured as $setting => $expected) {
            if (!self::samePrivatePath((string) Config::get('gateway', $setting), $expected)) {
                throw new GatewayError('BROWSER_PRIVATE_STORAGE_ESCAPE', '项目私有浏览器目录配置不在预期位置。', 503);
            }
        }

        // Preflight every entry before the first delete; a junction or symlink must not be traversed.
        $files = [];
        $directories = [];
        foreach ($targets as $target) {
            if (!file_exists($target) && !is_link($target)) continue;
            self::planPrivateRemoval($target, $base, $files, $directories, 0);
        }
        foreach ($files as $path) {
            self::assertPrivateRemovalPath($path, $base);
            if (!@unlink($path)) {
                throw new GatewayError('BROWSER_PROFILE_PURGE_FAILED', '浏览器资料未能全部清空；请确认浏览器已关闭后重试。', 503);
            }
        }
        foreach ($directories as $path) {
            self::assertPrivateRemovalPath($path, $base);
            if (!@rmdir($path)) {
                throw new GatewayError('BROWSER_PROFILE_PURGE_FAILED', '浏览器资料未能全部清空；请确认浏览器已关闭后重试。', 503);
            }
        }
        return ['directories' => count($directories), 'files' => count($files)];
    }

    /** Build a child-first deletion list only from regular entries under an exact private path. */
    private static function planPrivateRemoval(string $path, string $base, array &$files, array &$directories, int $depth): void
    {
        if ($depth > 80 || count($files) + count($directories) > 1000000) {
            throw new GatewayError('BROWSER_PROFILE_PURGE_LIMIT', '浏览器资料目录过大或层级异常，已停止清理。', 503);
        }
        self::assertPrivateRemovalPath($path, $base);
        if (is_file($path)) { $files[] = $path; return; }
        if (!is_dir($path)) {
            throw new GatewayError('BROWSER_PRIVATE_STORAGE_ESCAPE', '浏览器资料含不受支持的文件类型。', 503);
        }
        try {
            $items = new \FilesystemIterator($path, \FilesystemIterator::SKIP_DOTS);
            foreach ($items as $item) self::planPrivateRemoval($item->getPathname(), $base, $files, $directories, $depth + 1);
        } catch (GatewayError $error) {
            throw $error;
        } catch (\Throwable) {
            throw new GatewayError('BROWSER_PROFILE_PURGE_FAILED', '浏览器资料无法完整读取，尚未开始删除。', 503);
        }
        $directories[] = $path;
    }

    /** Reject links, junctions and any resolved entry outside its exact application-owned path. */
    private static function assertPrivateRemovalPath(string $path, string $base): void
    {
        $resolved = realpath($path);
        if (is_link($path) || $resolved === false || !self::pathInside($resolved, $base)
            || !self::samePrivatePath($resolved, $path)) {
            throw new GatewayError('BROWSER_PRIVATE_STORAGE_ESCAPE', '浏览器资料包含越界或链接目录，已拒绝清理。', 503);
        }
    }

    /** Compare canonical private paths without changing case-sensitive hosts' semantics. */
    private static function samePrivatePath(string $left, string $right): bool
    {
        $left = rtrim(str_replace('\\', '/', $left), '/');
        $right = rtrim(str_replace('\\', '/', $right), '/');
        return PHP_OS_FAMILY === 'Windows' ? strcasecmp($left, $right) === 0 : $left === $right;
    }

    /**
     * Compare existing profile directories using the host's canonical path and case rules.
     * Config paths can contain forward slashes while launch() returns Windows realpath backslashes.
     * @param string $left Owned launch profile. @param string $right Configured profile.
     * @return bool True only for the same existing directory; never infer ownership from a prefix.
     */
    public static function sameProfile(string $left, string $right): bool
    {
        $a = $left !== '' ? realpath($left) : false;
        $b = $right !== '' ? realpath($right) : false;
        if ($a === false || $b === false || !is_dir($a) || !is_dir($b)) return false;
        return PHP_OS_FAMILY === 'Windows' ? strcasecmp($a, $b) === 0 : $a === $b;
    }

    /** Confirm stored PID still advertises both product-owned launch markers. */
    public static function ownershipConfirmed(int $pid, string $profile, int $debugPort): bool
    {
        if ($pid < 1) return false;
        $commandLine = self::commandLine($pid);
        if ($commandLine === null) return false;
        $normalized = str_replace('/', '\\', strtolower($commandLine));
        $profileNeedle = '--user-data-dir=' . str_replace('/', '\\', strtolower($profile));
        return str_contains($normalized, $profileNeedle)
            && str_contains($commandLine, '--remote-debugging-port=' . $debugPort);
    }

    /**
     * Verify that the discovered DevTools endpoint is the configured loopback port.
     *
     * Chromium does not reliably write DevToolsActivePort when a fixed
     * --remote-debugging-port is supplied. Process ownership is established
     * separately from PID + dedicated profile + debug-port launch markers.
     * When Chrome does publish DevToolsActivePort, treat it as an additional
     * consistency check rather than a required ownership primitive.
     */
    public static function endpointMatches(string $profile, int $debugPort, string $webSocketUrl): bool
    {
        $parts = parse_url($webSocketUrl);
        if (!$parts || ($parts['scheme'] ?? '') !== 'ws' || !in_array(strtolower((string) ($parts['host'] ?? '')), ['127.0.0.1', 'localhost', '::1'], true)
            || (int) ($parts['port'] ?? 0) !== $debugPort) return false;

        $marker = rtrim($profile, '\\/') . DIRECTORY_SEPARATOR . 'DevToolsActivePort';
        if (!is_file($marker)) return true;
        $lines = @file($marker, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines) || count($lines) < 2 || (int) trim((string) $lines[0]) !== $debugPort) return false;
        $path = (string) ($parts['path'] ?? '');
        return hash_equals(trim((string) $lines[1]), $path);
    }

    /**
     * Recover ownership after the worker reloads and loses its proc_open handle.
     *
     * The fallback is intentionally Windows-only and requires all independent
     * evidence to agree: the dedicated profile exists, DevTools reports the
     * configured loopback endpoint, and the stored browser PID owns that exact
     * 127.0.0.1 listening port. It never infers ownership from a process name.
     */
    public static function recoveredOwnershipConfirmed(int $pid, string $profile, int $debugPort, string $webSocketUrl): bool
    {
        if (PHP_OS_FAMILY !== 'Windows' || $pid < 1 || $debugPort < 1) return false;
        if (!is_file(rtrim($profile, '\\/') . DIRECTORY_SEPARATOR . 'Local State')) return false;
        if (!self::endpointMatches($profile, $debugPort, $webSocketUrl)) return false;
        return self::windowsLoopbackListenerOwnedByPid($pid, $debugPort) === true;
    }

    /** Whether the exact PID still owns the configured IPv4 loopback CDP listener. */
    public static function hasWindowsLoopbackListener(int $pid, int $debugPort): ?bool
    {
        if (PHP_OS_FAMILY !== 'Windows' || $pid < 1 || $debugPort < 1) return null;
        return self::windowsLoopbackListenerOwnedByPid($pid, $debugPort);
    }

    /** Linux exposes launch markers locally; Windows recovery uses the recorded CDP listener instead. */
    private static function commandLine(int $pid): ?string
    {
        if (PHP_OS_FAMILY !== 'Linux' || $pid < 1) return null;
        $value = @file_get_contents('/proc/' . $pid . '/cmdline');
        return $value === false ? null : str_replace("\0", ' ', $value);
    }

    private static function initialUrl(?string $url): string
    {
        $url = trim((string) $url);
        if ($url === '') return 'https://chatgpt.com/';
        $parts = parse_url($url);
        if (!$parts || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true) || isset($parts['user']) || isset($parts['pass'])) {
            return 'https://chatgpt.com/';
        }
        return $url;
    }

    /** Confirm one exact PID owns the configured IPv4 loopback listening socket. */
    private static function windowsLoopbackListenerOwnedByPid(int $pid, int $debugPort): ?bool
    {
        $systemRoot = getenv('SystemRoot') ?: 'C:\\Windows';
        $netstat = $systemRoot . '\\System32\\netstat.exe';
        if (!is_file($netstat)) return null;
        $process = @proc_open([$netstat, '-ano', '-p', 'tcp'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null,
            ['bypass_shell' => true]);
        if (!is_resource($process)) return null;
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);
        if ($code !== 0 || !is_string($output) || $output === '') return null;

        $local = '127.0.0.1:' . $debugPort;
        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            $columns = preg_split('/\s+/', trim($line));
            if (!is_array($columns) || count($columns) < 5) continue;
            if (strcasecmp((string) $columns[0], 'TCP') !== 0) continue;
            if ((string) $columns[1] !== $local) continue;
            if (strcasecmp((string) $columns[3], 'LISTENING') !== 0) continue;
            if ((int) $columns[4] === $pid) return true;
        }
        return false;
    }

    /** Send Browser.close over the already-verified loopback DevTools browser websocket. */
    private static function requestBrowserClose(string $webSocketUrl): bool
    {
        $parts = parse_url($webSocketUrl);
        if (!$parts || ($parts['scheme'] ?? '') !== 'ws') return false;
        $host = strtolower((string) ($parts['host'] ?? ''));
        $port = (int) ($parts['port'] ?? 0);
        if (!in_array($host, ['127.0.0.1', 'localhost', '::1'], true) || $port < 1) return false;
        $connectHost = $host === '::1' ? '[::1]' : ($host === 'localhost' ? '127.0.0.1' : $host);
        $socket = @stream_socket_client('tcp://' . $connectHost . ':' . $port, $errno, $error, 1.0, STREAM_CLIENT_CONNECT);
        if (!is_resource($socket)) return false;
        stream_set_timeout($socket, 1);
        try {
            $path = (string) ($parts['path'] ?? '/');
            if ($path === '') $path = '/';
            if (isset($parts['query']) && $parts['query'] !== '') $path .= '?' . $parts['query'];
            $key = base64_encode(random_bytes(16));
            $hostHeader = $host === '::1' ? '[::1]:' . $port : $host . ':' . $port;
            $request = "GET {$path} HTTP/1.1\r\n"
                . "Host: {$hostHeader}\r\n"
                . "Upgrade: websocket\r\n"
                . "Connection: Upgrade\r\n"
                . "Sec-WebSocket-Key: {$key}\r\n"
                . "Sec-WebSocket-Version: 13\r\n\r\n";
            if (!self::writeAll($socket, $request)) return false;

            $headers = '';
            while (!str_contains($headers, "\r\n\r\n") && strlen($headers) < 16384) {
                $chunk = fread($socket, 2048);
                if ($chunk === false || $chunk === '') break;
                $headers .= $chunk;
            }
            if (!preg_match('/\AHTTP\/1\.[01] 101\b/i', $headers)) return false;
            $accept = '';
            foreach (explode("\r\n", $headers) as $line) {
                if (stripos($line, 'Sec-WebSocket-Accept:') === 0) {
                    $accept = trim(substr($line, strlen('Sec-WebSocket-Accept:')));
                    break;
                }
            }
            $expected = base64_encode(sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
            if ($accept === '' || !hash_equals($expected, $accept)) return false;

            $payload = json_encode(['id' => 1, 'method' => 'Browser.close'], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $mask = random_bytes(4);
            $length = strlen($payload);
            if ($length >= 126) return false;
            $maskStream = str_repeat($mask, intdiv($length, 4)) . substr($mask, 0, $length % 4);
            $frame = "\x81" . chr(0x80 | $length) . $mask . ($payload ^ $maskStream);
            return self::writeAll($socket, $frame);
        } catch (\Throwable) {
            return false;
        } finally {
            fclose($socket);
        }
    }

    /** @param resource $stream */
    private static function writeAll($stream, string $bytes): bool
    {
        $offset = 0;
        $length = strlen($bytes);
        while ($offset < $length) {
            $written = fwrite($stream, substr($bytes, $offset));
            if ($written === false || $written === 0) return false;
            $offset += $written;
        }
        fflush($stream);
        return true;
    }

    private static function releaseOwnedProcess(?array &$owned): void
    {
        if ($owned !== null && isset($owned['process']) && is_resource($owned['process'])) {
            $status = proc_get_status($owned['process']);
            if (!$status['running']) @proc_close($owned['process']);
        }
        $owned = null;
    }

    private static function killPid(int $pid): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $process = @proc_open(['taskkill', '/PID', (string) $pid, '/T', '/F'],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null, ['bypass_shell' => true]);
            if (!is_resource($process)) throw new GatewayError('BROWSER_STOP_FAILED', '无法结束受控浏览器。', 503);
            fclose($pipes[0]);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            if (proc_close($process) !== 0) {
                $detail = trim((string) $stderr . ' ' . (string) $stdout);
                throw new GatewayError('BROWSER_STOP_FAILED', '无法结束受控浏览器。' . ($detail !== '' ? ' ' . mb_substr($detail, 0, 160) : ''), 503);
            }
            return;
        }
        if (!function_exists('posix_kill') || !@posix_kill($pid, SIGTERM)) {
            throw new GatewayError('BROWSER_STOP_FAILED', '无法结束受控浏览器。', 503);
        }
    }
}
