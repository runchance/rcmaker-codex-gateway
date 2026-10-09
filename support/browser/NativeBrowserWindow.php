<?php
declare(strict_types=1);
namespace support\browser;

use app\gateway\model\BrowserState;
use app\gateway\model\GatewayError;
use RC\Config;
use Workerman\Timer;

/** One optional native setup window in the existing private Chrome; no profile or task-page replacement. */
final class NativeBrowserWindow
{
    /** Return the last confirmed native-window state for this browser incarnation, without exposing ownership secrets. */
    public static function snapshot(array $browser): array
    {
        $record = self::record($browser);
        return ['available' => ($browser['status'] ?? '') === 'ready',
            'opened' => is_string($record['target_id'] ?? null), 'visible' => !empty($record['visible'])];
    }

    /** Keep new automation/probe tabs out of an optional user-facing native window; ordinary creation stays byte-equivalent. */
    public static function backgroundPageParameters(array $parameters, array $browser): array
    {
        if (self::record($browser) === null) return $parameters;
        return $parameters + ['newWindow' => true, 'background' => true,
            'left' => -32000, 'top' => -32000, 'width' => 1280, 'height' => 720];
    }

    /** Forget a closed native page only after a successful complete target snapshot, never close another page. */
    public static function reconcileTargets(array $browser, array $targets): void
    {
        $record = self::record($browser);
        if (!is_string($record['target_id'] ?? null)) return;
        foreach ($targets as $target) if (($target['id'] ?? $target['targetId'] ?? null) === $record['target_id']) return;
        @unlink(self::path());
    }

    /** Exclude this optional page from the legacy manual-tab chooser; that surface keeps its original document/session. */
    public static function targetId(array $browser): ?string
    {
        $record = self::record($browser);
        return is_string($record['target_id'] ?? null) ? $record['target_id'] : null;
    }

    /**
     * Read login capabilities from the owned native page without adopting, activating or navigating it.
     * @param CdpClient $client Existing browser-level connection; the acquired session is never a task session.
     * @param array $browser Browser incarnation to which the native ownership record belongs.
     * @param callable $done Receives (?GatewayError, ?array); null capabilities mean the native page is confirmed absent.
     * @return void Every acquired inspection session is detached while the same browser still owns the connection.
     */
    public static function inspectCapability(CdpClient $client, array $browser, callable $done): void
    {
        $finished = false;
        $finish = static function ($error, $value) use (&$finished, $done): void {
            if ($finished) return;
            $finished = true;
            $done($error, $value);
        };
        try {
            $targetId = self::targetId($browser);
            if ($targetId === null) { $finish(null, null); return; }
            self::request($client, $browser, 'Target.getTargets', [],
                function ($error, $result) use (&$finished, $client, $browser, $targetId, $finish): void {
                    if ($finished) return;
                    try {
                        if ($error !== null) { $finish($error, null); return; }
                        if (!is_array($result['targetInfos'] ?? null)) { $finish(self::windowError(), null); return; }
                        if (self::targetId($browser) !== $targetId) { $finish(self::windowError(), null); return; }
                        self::reconcileTargets($browser, $result['targetInfos']);
                        if (self::targetId($browser) !== $targetId) { $finish(null, null); return; }
                        $client->send('Target.attachToTarget', ['targetId' => $targetId, 'flatten' => true], null,
                            function ($error, $result) use (&$finished, $client, $browser, $targetId, $finish): void {
                                $sessionId = (string) ($result['sessionId'] ?? '');
                                if ($error !== null || $sessionId === '') { $finish(self::windowError(), null); return; }
                                $released = false;
                                $release = static function () use (&$released, $client, $browser, $sessionId): void {
                                    if ($released) return;
                                    $released = true;
                                    if (!self::sameBrowser($browser) || !$client->ready()) return;
                                    try {
                                        $client->send('Target.detachFromTarget', ['sessionId' => $sessionId], null, static function (): void {}, 2.0);
                                    } catch (\Throwable) {}
                                };
                                if ($finished) { $release(); return; }
                                try {
                                    if (!self::sameBrowser($browser) || self::targetId($browser) !== $targetId) {
                                        $release(); $finish(self::windowError(), null); return;
                                    }
                                    $client->send('Runtime.evaluate', [
                                        'expression' => WebModelPage::capabilityExpression(), 'returnByValue' => true,
                                    ], $sessionId, static function ($error, $result) use (&$finished, $browser, $targetId, $release, $finish): void {
                                        if ($finished) { $release(); return; }
                                        $failure = self::windowError();
                                        $value = null;
                                        try {
                                            $value = $result['result']['value'] ?? null;
                                            if ($error === null && is_array($value) && self::sameBrowser($browser)
                                                && self::targetId($browser) === $targetId) $failure = null;
                                        } catch (\Throwable) {
                                            // Unavailable ownership state cannot publish authentication evidence.
                                        } finally {
                                            $release();
                                        }
                                        $finish($failure, $failure === null ? $value : null);
                                    }, 2.0);
                                } catch (\Throwable) {
                                    $release(); $finish(self::windowError(), null);
                                }
                            }, 2.0);
                    } catch (\Throwable) {
                        $finish(self::windowError(), null);
                    }
                });
        } catch (\Throwable) {
            $finish(self::windowError(), null);
        }
    }

    /**
     * Show/reuse or hide one dedicated native window through the existing browser-level CDP connection.
     * @param CdpClient $client Already authenticated to the product-owned loopback Chrome.
     * @param array $browser Current private browser state, captured by the owning worker.
     * @param bool $visible True opens/shows; false hides without closing any page.
     * @param string $url Initial setup URL, from server configuration only. Existing native pages are not navigated again.
     * @param callable $done Receives (?GatewayError, array); a lost create acknowledgement is reconciled, never blindly resent.
     * @return void All I/O is asynchronous; only a small ephemeral ownership record is written.
     */
    public static function setVisible(CdpClient $client, array $browser, bool $visible, string $url, callable $done): void
    {
        if (($browser['status'] ?? '') !== 'ready' || !$client->ready() || self::owner($browser) === null) {
            $done(new GatewayError('BROWSER_NOT_READY', '请先打开登录页面，等待私有浏览器就绪。', 409), []); return;
        }
        $record = self::record($browser);
        if ($record === null) {
            if (!$visible) { $done(null, ['visible' => false]); return; }
            self::create($client, $browser, $url, $done); return;
        }
        self::request($client, $browser, 'Target.getTargets', [], function ($error, $result) use ($client, $browser, $record, $visible, $url, $done): void {
            if ($error !== null) { $done($error, []); return; }
            $matches = array_values(array_filter($result['targetInfos'] ?? [], static fn(array $target): bool =>
                ($target['type'] ?? '') === 'page' && (isset($record['target_id'])
                    ? ($target['targetId'] ?? '') === $record['target_id'] : ($target['url'] ?? '') === $record['marker'])));
            if (count($matches) !== 1) {
                if (!isset($record['target_id'])) {
                    $done(new GatewayError('NATIVE_WINDOW_PENDING', '原生窗口创建结果尚未确认，请稍后再试。', 409), []); return;
                }
                @unlink(self::path());
                if ($visible) self::create($client, $browser, $url, $done);
                else $done(null, ['visible' => false]);
                return;
            }
            $record['target_id'] = $matches[0]['targetId'];
            self::position($client, $browser, $record, $visible, $url, $done);
        });
    }

    /** Create only an inert, marked native page; lost acknowledgements leave recoverable provenance. */
    private static function create(CdpClient $client, array $browser, string $url, callable $done): void
    {
        $record = ['owner' => self::owner($browser), 'marker' => 'about:blank#rcmaker-native-' . bin2hex(random_bytes(16)), 'visible' => false];
        try { self::save($record); } catch (GatewayError $error) { $done($error, []); return; }
        self::request($client, $browser, 'Target.createTarget', ['url' => $record['marker'], 'newWindow' => true,
            'left' => 80, 'top' => 80, 'width' => 1280, 'height' => 900, 'background' => false],
            function ($error, $result) use ($client, $browser, $record, $url, $done): void {
                if ($error !== null || !is_string($result['targetId'] ?? null)) {
                    $done($error ?? new GatewayError('NATIVE_WINDOW_FAILED', '原生浏览器窗口未能创建。', 503), []); return;
                }
                $record['target_id'] = $result['targetId'];
                self::position($client, $browser, $record, true, $url, $done);
            });
    }

    /** Resolve the exact window from its owned target and ensure it differs from the legacy setup window. */
    private static function position(CdpClient $client, array $browser, array $record, bool $visible, string $url, callable $done): void
    {
        self::request($client, $browser, 'Browser.getWindowForTarget', ['targetId' => $record['target_id']],
            function ($error, $result) use ($client, $browser, $record, $visible, $url, $done): void {
                if ($error !== null || !is_int($result['windowId'] ?? null)) { $done($error ?? self::windowError(), []); return; }
                $record['window_id'] = $result['windowId'];
                self::request($client, $browser, 'Browser.getWindowForTarget', ['targetId' => (string) $browser['active_target']],
                    function ($error, $base) use ($client, $browser, $record, $visible, $url, $done): void {
                        if ($error !== null || !is_int($base['windowId'] ?? null) || $base['windowId'] === $record['window_id']) {
                            $done($error ?? self::windowError(), []); return;
                        }
                        self::request($client, $browser, 'Browser.setWindowBounds', ['windowId' => $record['window_id'], 'bounds' => ['windowState' => 'normal']],
                            function ($error) use ($client, $browser, $record, $visible, $url, $done): void {
                                if ($error !== null) { $done($error, []); return; }
                                // macOS acknowledges leaving fullscreen before the native window finishes its transition.
                                self::waitForBounds($client, $browser, $record['window_id'],
                                    static fn(array $bounds): bool => ($bounds['windowState'] ?? '') === 'normal',
                                    function ($error) use ($client, $browser, $record, $visible, $url, $done): void {
                                        if ($error !== null) { $done($error, []); return; }
                                        self::request($client, $browser, 'Browser.setWindowBounds', ['windowId' => $record['window_id'],
                                            'bounds' => $visible ? ['left' => 80, 'top' => 80, 'width' => 1280, 'height' => 900]
                                                : ['left' => -32000, 'top' => -32000]],
                                            function ($error) use ($client, $browser, $record, $visible, $url, $done): void {
                                                if ($error !== null) { $done($error, []); return; }
                                                self::waitForBounds($client, $browser, $record['window_id'],
                                                    static fn(array $bounds): bool => ($bounds['windowState'] ?? '') === 'normal'
                                                        && ($visible || ((int) ($bounds['left'] ?? 0) === -32000 && (int) ($bounds['top'] ?? 0) === -32000)),
                                                    function ($error) use ($client, $browser, $record, $visible, $url, $done): void {
                                                        if ($error !== null) { $done($error, []); return; }
                                                        self::publishVisibility($client, $browser, $record, $visible, $url, $done);
                                                    }, microtime(true) + 5.0);
                                            });
                                    }, microtime(true) + 5.0);
                            });
                    });
            });
    }

    /** Publish only confirmed window state; opening a reused page must not reload its login form. */
    private static function publishVisibility(CdpClient $client, array $browser, array $record, bool $visible, string $url, callable $done): void
    {
        $record['visible'] = $visible;
        try { self::save($record); } catch (GatewayError $failure) { $done($failure, []); return; }
        if (!$visible) { $done(null, ['visible' => false]); return; }
        self::request($client, $browser, 'Target.activateTarget', ['targetId' => $record['target_id']],
            function ($error) use ($client, $browser, $record, $url, $done): void {
                if ($error !== null) { $done($error, []); return; }
                if (!empty($record['initialized'])) { $done(null, ['visible' => true]); return; }
                self::navigate($client, $browser, $record, $url, $done);
            });
    }

    /** Wait without blocking the browser worker; an acknowledgement alone is not native-window state evidence. */
    private static function waitForBounds(CdpClient $client, array $browser, int $windowId, callable $matches, callable $done, float $deadline): void
    {
        self::request($client, $browser, 'Browser.getWindowBounds', ['windowId' => $windowId],
            function ($error, $result) use ($client, $browser, $windowId, $matches, $done, $deadline): void {
                if ($error !== null) { $done($error); return; }
                if (!is_array($result['bounds'] ?? null)) { $done(self::windowError()); return; }
                if ($matches($result['bounds'])) { $done(null); return; }
                if (microtime(true) >= $deadline) { $done(self::windowError()); return; }
                Timer::add(0.1, static function () use ($client, $browser, $windowId, $matches, $done, $deadline): void {
                    self::waitForBounds($client, $browser, $windowId, $matches, $done, $deadline);
                }, [], false);
            });
    }

    /** Navigate a newly created native page once, then detach; native keyboard/clipboard input stays entirely in Chrome. */
    private static function navigate(CdpClient $client, array $browser, array $record, string $url, callable $done): void
    {
        self::request($client, $browser, 'Target.attachToTarget', ['targetId' => $record['target_id'], 'flatten' => true],
            function ($error, $result) use ($client, $browser, $record, $url, $done): void {
                $session = (string) ($result['sessionId'] ?? '');
                if ($error !== null || $session === '') { $done($error ?? self::windowError(), []); return; }
                $record['initialized'] = true;
                try { self::save($record); } catch (GatewayError $failure) { $done($failure, []); return; }
                $client->send('Page.navigate', ['url' => $url], $session, function ($error) use ($client, $session, $done): void {
                    $client->send('Target.detachFromTarget', ['sessionId' => $session], null, static function (): void {});
                    $done($error === null ? null : new GatewayError('NATIVE_WINDOW_NAVIGATION', '原生窗口已经打开，请在地址栏打开 ChatGPT。', 503), ['visible' => true]);
                }, 5.0);
            });
    }

    /** Fence every asynchronous control command against a replaced browser; protocol details stay out of public errors. */
    private static function request(CdpClient $client, array $browser, string $method, array $parameters, callable $done): void
    {
        if (!self::sameBrowser($browser)) { $done(self::windowError(), []); return; }
        $client->send($method, $parameters, null, static function ($error, $result) use ($browser, $done): void {
            if ($error !== null || !self::sameBrowser($browser)) {
                $done(self::windowError(), []); return;
            }
            $done(null, is_array($result) ? $result : []);
        }, 5.0);
    }

    /** Treat unavailable state as an ownership failure so asynchronous inspection always reaches its completion callback. */
    private static function sameBrowser(array $browser): bool
    {
        try {
            $owner = self::owner($browser);
            return $owner !== null && $owner === self::owner(BrowserState::state());
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return GatewayError Safe control/ownership failure; original task pages remain untouched. */
    private static function windowError(): GatewayError
    {
        return new GatewayError('NATIVE_WINDOW_FAILED', '无法确认原生浏览器窗口，请稍后重试；内置页面仍可使用。', 503);
    }

    /** Hash only local incarnation evidence; a restart/profile change cannot reuse another browser's window ID. */
    private static function owner(array $browser): ?string
    {
        if ((int) ($browser['browser_pid'] ?? 0) <= 0 || empty($browser['launch_nonce'])) return null;
        return hash('sha256', Config::get('gateway', 'browser_profile_dir') . '|' . $browser['browser_pid'] . '|'
            . ($browser['debug_port'] ?? '') . '|' . $browser['launch_nonce']);
    }

    /** Read a small ephemeral record only for the current private Chrome. */
    private static function record(array $browser): ?array
    {
        $path = self::path();
        if (!is_file($path) || filesize($path) > 4096) return null;
        $record = json_decode((string) @file_get_contents($path), true);
        return is_array($record) && self::owner($browser) !== null && ($record['owner'] ?? null) === self::owner($browser) ? $record : null;
    }

    /** Publish small local ownership metadata atomically; it contains no cookies, account details or user messages. */
    private static function save(array $record): void
    {
        $path = self::path();
        if (!is_dir(dirname($path)) && !@mkdir(dirname($path), 0700, true) && !is_dir(dirname($path))) throw self::windowError();
        if (@file_put_contents($path . '.tmp', json_encode($record, JSON_THROW_ON_ERROR), LOCK_EX) === false
            || !@rename($path . '.tmp', $path)) throw self::windowError();
    }

    /** Runtime-only record scoped to the existing screenshot/database identity; packaged external data paths remain unchanged. */
    private static function path(): string
    {
        return dirname(BrowserRuntime::screenshotPath()) . '/native-window.json';
    }
}
