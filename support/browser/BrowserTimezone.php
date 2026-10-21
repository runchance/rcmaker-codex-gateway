<?php
declare(strict_types=1);
namespace support\browser;

use RC\Config;
use app\gateway\model\GatewayError;
use app\gateway\model\NetworkTransport;

/** Resolves the private browser's egress timezone and applies it before new page scripts run. */
final class BrowserTimezone
{
    private ?CdpClient $client = null;
    private string $socket = '';
    private string $timezone = '';
    private int $generation = 0;
    private int $pending = 0;
    private bool $enabled = false;
    private ?array $error = null;
    /** @var array<string,true> Sessions being initialized; closing tabs is not a setup failure. */
    private array $sessions = [];
    /** Failures belong to their target session; a closed probe must not poison other pages. */
    private array $sessionErrors = [];
    private float $retryAt = 0.0;
    private int $retryAttempt = 0;

    /**
     * A renderer may be unable to service CDP while its JavaScript thread is busy or the browser
     * worker is briefly waiting on shared storage. Keep this below the bounded browser-start
     * budget, but do not turn the generic five-second RPC default into a false timezone failure.
     */
    private static function sessionCommandTimeout(): float
    {
        $startup = (float) Config::get('gateway', 'browser_start_timeout_seconds');
        return max(15.0, min(30.0, $startup / 6.0));
    }

    /** Accept IANA names, including UTC, rather than fixed offsets that lose daylight-saving rules.
     * @param string $timezone Candidate IANA identifier. @return bool Whether PHP recognizes the identifier.
     */
    public static function valid(string $timezone): bool
    {
        return in_array($timezone, \DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC), true);
    }

    /**
     * Resolve once during browser preparation, outside transactions and model/API requests.
     * Uses the exact browser network revision, never the PHP applied pointer or OS proxy.
     * @param array $revision Immutable browser network revision with its saved timezone selection.
     * @return string Empty means explicitly use the host timezone (also auto with a direct route).
     * @throws GatewayError No guessed timezone or silent direct-network fallback on lookup failure.
     */
    public static function resolve(array $revision): string
    {
        $configured = (string) ($revision['browser_timezone'] ?? 'auto');
        if ($configured === 'system' || ($configured === 'auto' && ($revision['mode'] ?? '') !== 'manual')) return '';
        if ($configured !== 'auto') {
            if (self::valid($configured)) return $configured;
            throw new GatewayError('BROWSER_TIMEZONE_INVALID', '浏览器时区必须是 auto、system 或有效的 IANA 时区。', 422);
        }
        foreach ((array) Config::get('gateway', 'browser_timezone_lookup_urls') as $url) {
            $client = curl();
            $body = '';
            try {
                NetworkTransport::configure($client, $revision, (string) $url, 5, 3, false);
                $client->setOpt(CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
                $client->setOpt(CURLOPT_WRITEFUNCTION, static function ($handle, string $chunk) use (&$body): int {
                    if (strlen($body) + strlen($chunk) > 16384) return 0;
                    $body .= $chunk;
                    return strlen($chunk);
                });
                $client->get((string) $url);
                if ((int) $client->curlErrorCode !== 0 || (int) $client->httpStatusCode !== 200) continue;
                $timezone = self::fromResponse($body);
                if ($timezone !== null) return $timezone;
            } catch (\Throwable) {
                // Try the second configured HTTPS service through the same revision only.
            } finally {
                $client->close();
            }
        }
        throw new GatewayError('BROWSER_TIMEZONE_LOOKUP_FAILED',
            '无法通过浏览器代理确定出口时区。请返回第一步“网络设置”，检查代理或手动选择代理所在地时区后重新应用。', 503);
    }

    /** Extract only a verified timezone name; location, IP and response text are not persisted.
     * @param string $body Bounded provider JSON. @return string|null Valid identifier, or no usable result.
     */
    public static function fromResponse(string $body): ?string
    {
        if (strlen($body) > 16384) return null;
        $value = json_decode($body, true, 16);
        if (!is_array($value) || !empty($value['error']) || (array_key_exists('success', $value) && $value['success'] !== true)) return null;
        $timezone = $value['timezone'] ?? null;
        if (is_array($timezone)) $timezone = $timezone['id'] ?? null;
        return is_string($timezone) && self::valid($timezone) ? $timezone : null;
    }

    /**
     * Maintain a dedicated browser-level session; task/login/CDP sessions keep their ownership.
     * The caller must first prove that the loopback socket belongs to the private Chrome.
     * @param string $socket Ownership-verified browser CDP WebSocket URL.
     * @param string $timezone Resolved IANA identifier; empty explicitly restores the host timezone.
     * @return bool True after existing pages are configured and future-page auto-attach is armed.
     * @throws GatewayError A protocol failure must not be advertised as a synchronized browser.
     */
    public function ensure(string $socket, string $timezone): bool
    {
        if ($timezone === '') {
            if ($this->client !== null) $this->close();
            return true;
        }
        if ($this->socket === $socket && $this->timezone === $timezone) {
            if ($this->error !== null || $this->sessionErrors !== []) {
                if ($this->retryAt === 0.0) {
                    $this->retryAt = microtime(true) + [1, 3, 10, 30][min($this->retryAttempt++, 3)];
                    $failure = $this->error ?? reset($this->sessionErrors);
                    self::recordDiagnostic($failure);
                    throw new GatewayError('BROWSER_TIMEZONE_APPLY_FAILED', '浏览器时区控制暂未就绪，正在自动重新检查；也可返回第一步调整时区。', 503);
                }
                if (microtime(true) < $this->retryAt || $this->pending > 0 || !$this->client?->ready()) return false;
                $failedSessions = array_keys($this->sessionErrors);
                $rootFailed = $this->error !== null;
                $this->error = null;
                $this->sessionErrors = [];
                $this->retryAt = 0.0;
                if ($rootFailed) $this->arm($this->client, $this->generation);
                foreach ($failedSessions as $session) $this->configureSession($this->client, $session, $this->generation);
                return false;
            }
            $ready = $this->enabled && $this->pending === 0 && $this->client?->ready();
            if ($ready) { $this->retryAttempt = 0; $this->retryAt = 0.0; }
            return $ready;
        }
        if (!self::valid($timezone)) throw new \InvalidArgumentException('Invalid browser timezone.');
        $this->close();
        $this->socket = $socket;
        $this->timezone = $timezone;
        $generation = $this->generation;
        $client = $this->client = new CdpClient();
        $client->connect($socket, function () use ($generation, $client): void {
            $this->arm($client, $generation);
        }, function (array $event) use ($generation, $client): void {
            if ($generation !== $this->generation) return;
            $session = (string) ($event['params']['sessionId'] ?? '');
            if ($session === '') return;
            if (($event['method'] ?? '') === 'Target.detachedFromTarget') {
                if (isset($this->sessions[$session])) { unset($this->sessions[$session]); $this->pending--; }
                unset($this->sessionErrors[$session]);
                return;
            }
            if (($event['method'] ?? '') !== 'Target.attachedToTarget') return;
            if (!in_array($event['params']['targetInfo']['type'] ?? '', ['page', 'iframe'], true)) {
                // Workers inherit the renderer timezone but still need their debugger-start gate released.
                $client->send('Runtime.runIfWaitingForDebugger', [], $session, static function (): void {},
                    self::sessionCommandTimeout());
                return;
            }
            $this->configureSession($client, $session, $generation);
        }, function () use ($generation): void {
            if ($generation !== $this->generation) return;
            // A reconnect re-arms auto-attach without navigating, reloading or replaying a task.
            $this->socket = '';
            $this->enabled = false;
        });
        return false;
    }

    /** Re-arm a timed-out subscription on the same transport, retaining healthy page overrides. */
    private function arm(CdpClient $client, int $generation): void
    {
        $this->enabled = false;
        $client->send('Target.setAutoAttach', self::autoAttachParameters(), null,
            function ($error) use ($generation): void {
                if ($generation !== $this->generation) return;
                $this->error = $error === null ? null : $error + ['method'=>'Target.setAutoAttach'];
                $this->enabled = $error === null;
            }, self::sessionCommandTimeout());
    }

    /** Include page targets and cross-origin frames; Chrome propagates the renderer timezone to workers. */
    private static function autoAttachParameters(): array
    {
        return ['autoAttach' => true, 'waitForDebuggerOnStart' => true, 'flatten' => true,
            'filter' => [['type' => 'page'], ['type' => 'iframe'], ['type' => 'worker'],
                ['type' => 'shared_worker'], ['type' => 'service_worker'], ['exclude' => true]]];
    }

    /** Always release a paused target, even on an unsupported CDP operation; never leave user tabs frozen. */
    private function configureSession(CdpClient $client, string $session, int $generation): void
    {
        if (isset($this->sessions[$session])) return;
        $this->pending++;
        $this->sessions[$session] = true;
        $client->send('Emulation.setTimezoneOverride', ['timezoneId' => $this->timezone], $session,
            function ($error) use ($client, $session, $generation): void {
                if ($generation !== $this->generation || !isset($this->sessions[$session])) return;
                $this->recordSessionError($session, $error, 'Emulation.setTimezoneOverride');
                // Nested frames are not automatically attached by the browser-level subscription.
                $client->send('Target.setAutoAttach', self::autoAttachParameters(), $session,
                    function ($attachError) use ($client, $session, $generation): void {
                        if ($generation !== $this->generation || !isset($this->sessions[$session])) return;
                        $this->recordSessionError($session, $attachError, 'Target.setAutoAttach');
                        $client->send('Runtime.runIfWaitingForDebugger', [], $session,
                            function ($resumeError) use ($generation, $session): void {
                                if ($generation !== $this->generation || !isset($this->sessions[$session])) return;
                                unset($this->sessions[$session]);
                                $this->pending--;
                                $this->recordSessionError($session, $resumeError, 'Runtime.runIfWaitingForDebugger');
                            }, self::sessionCommandTimeout());
                    }, self::sessionCommandTimeout());
            }, self::sessionCommandTimeout());
    }

    /** Keep transient protocol facts in the existing exported browser diagnostic, not stdout. */
    private static function recordDiagnostic(array $failure): void
    {
        $payload = json_encode(['event'=>'browser_timezone_retry', 'method'=>$failure['method'] ?? '',
            'code'=>$failure['code'] ?? 0,
            'message'=>mb_substr((string) ($failure['message'] ?? ''), 0, 200)], JSON_UNESCAPED_UNICODE);
        if (!is_string($payload)) return;
        @file_put_contents((string) Config::get('gateway', 'browser_log_dir') . '/runtime-errors.log',
            date('c') . ' ' . $payload . PHP_EOL, FILE_APPEND);
    }

    /** A page may close between any two acknowledgements; that does not invalidate the browser subscription. */
    private function recordSessionError(string $session, ?array $error, string $method): void
    {
        if ($error === null) return;
        $message = strtolower(rtrim((string) ($error['message'] ?? ''), '.'));
        if (in_array($message, ['session with given id not found', 'no target with given id found',
            'target closed', 'inspected target navigated or closed'], true)) {
            unset($this->sessionErrors[$session]);
            return;
        }
        $this->sessionErrors[$session] ??= $error + ['method'=>$method];
    }

    /** Release only our protocol sessions. Closing a debugger connection releases its paused targets.
     * @return void No browser pages or processes are closed.
     */
    public function close(): void
    {
        $this->generation++;
        $this->client?->close();
        $this->client = null;
        $this->socket = '';
        $this->timezone = '';
        $this->pending = 0;
        $this->enabled = false;
        $this->error = null;
        $this->sessions = [];
        $this->sessionErrors = [];
        $this->retryAt = 0.0;
        $this->retryAttempt = 0;
    }
}
