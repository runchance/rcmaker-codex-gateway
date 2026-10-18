<?php
declare(strict_types=1);
namespace support\browser;

use RC\Config;
use Workerman\Timer;
use app\gateway\database\Schema;
use app\gateway\model\BrowserState;
use app\gateway\model\ArtifactStore;
use app\gateway\model\AttachmentStore;
use app\gateway\model\AdapterCapabilities;
use app\gateway\model\ConnectorState;
use app\gateway\model\ContextCompaction;
use app\gateway\model\ContextHistory;
use app\gateway\model\CodexCancellation;
use app\gateway\model\GatewayError;
use app\gateway\model\GatewaySettings;
use app\gateway\model\NetworkSettings;
use app\gateway\model\ResponseJob;
use app\gateway\model\TaskLedger;
use app\gateway\model\ToolBroker;
use app\gateway\model\WebMessageAudit;
use support\process\OwnedRuntime;

/** Owns one dedicated browser, its CDP session, screenshot surface, and serialized input command stream. */
final class BrowserRuntime
{
    private const CONNECTOR_NO_START_MAX_RETRIES = 3;
    private const CONNECTOR_RUNTIME_PROBE_MAX_RETRIES = 3;
    /** A terminal DOM must remain unchanged before completion. */
    private const COMPLETION_SETTLE_SECONDS = 2.0;
    private const TOOL_POST_ANSWER_GRACE_SECONDS = 60.0;
    /** Console screenshot endpoints refresh these tiny runtime markers while a surface is visible. */
    private const OBSERVER_TTL_SECONDS = 4;
    private const MONITOR_CAPTURE_INTERVAL_SECONDS = 1.25;
    private const IDLE_MONITOR_CAPTURE_INTERVAL_SECONDS = 15.0;
    private const IDLE_MONITOR_RETRY_SECONDS = 5.0;
    private const SETUP_VIEWER_AUTOMATION_PAUSE_SECONDS = 20;
    private const IDLE_TASK_RECONCILE_SECONDS = 10.0;
    private const IDLE_CAPABILITY_REFRESH_SECONDS = BrowserState::IDLE_CAPABILITY_REFRESH_SECONDS;
    /** Fresh Temporary Chat pages can expose the composer before the model picker hydrates. */
    private const TASK_MODEL_CONTROL_READY_SECONDS = 30.0;
    /** First-profile recovery requires a continuous absence of initialization progress. */
    private const COLD_PROFILE_IDLE_RETRY_SECONDS = 45.0;

    private CdpClient $cdp;
    private CdpClient $taskCdp;
    private BrowserTimezone $browserTimezone;
    private ?string $browserTimezoneId = null;
    private ?GatewayError $browserTimezoneFailure = null;
    /** Native Client Hints cached only with the actual owned browser's version identity. */
    private ?array $taskBrowserIdentityCache = null;
    private ?array $ownedProcess = null;
    private bool $recovered = false;
    private bool $responsesRecovered = false;
    private bool $commandBusy = false;
    private bool $screenshotBusy = false;
    private bool $taskScreenshotBusy = false;
    private ?string $lastMonitorCapturedSurface = null;
    private float $lastMonitorCaptureAt = 0.0;
    /** @var array<string,float> Last successful capture of each detached, retained page. */
    private array $idleMonitorCapturedAt = [];
    /** @var array<string,float> Last attempted capture of each detached, retained page. */
    private array $idleMonitorAttemptAt = [];
    /** @var array<string,int> Target generation last observed for each retained page. */
    private array $idleMonitorGeneration = [];
    private bool $connecting = false;
    private bool $switchingTarget = false;
    private bool $stopping = false;
    private float $lastDiscovery = 0.0;
    /** Bound Unix death checks independently of CDP discovery and in-flight startup deadlines. */
    private float $lastUnixExitCheck = 0.0;
    private float $lastHeartbeat = 0.0;
    /** Optional account-change reminders have no role in native/Web task execution. */
    private float $lastLoginIdentityCheck = 0.0;
    private float $lastChatGptInspection = 0.0;
    /** Serialize event/tick capability reads so an older setup document cannot replace a native-page observation. */
    private bool $capabilityInspectionBusy = false;
    /** One pending refresh of the embedded login document after the separately owned native page closes. */
    private ?array $closedNativeSetupRefresh = null;
    private float $lastTaskCleanup = 0.0;
    private bool $autoStartAttempted = false;
    private ?int $pendingStartCommand = null;
    private float $startDeadline = 0.0;
    private string $startupStage = 'idle';
    /** The first browser profile can need one bounded, pre-ready restart; never used for live Web tasks. */
    private ?array $startupPlan = null;
    /** One setup-tab creation intent per actual Chrome incarnation; a lost reply is reconciled, not resent. */
    private ?array $startupPageCreation = null;
    private float $startupStartedAt = 0.0;
    /** Closing an unready page for the owned cold-start retry must not fail its original command callbacks. */
    private bool $startupRestarting = false;
    private bool $taskConnecting = false;
    private string $taskBrowserSocket = '';
    private bool $taskDownloadsReady = false;
    /** @var array<string,array<string,mixed>> browser download guid => owned task download */
    private array $taskDownloads = [];
    private float $lastArtifactCleanup = 0.0;
    /** @var array<string,array<string,mixed>> response_id => live turn */
    private array $chatGptTurns = [];
    /** @var array<string,array{surface_id:string,owner_incarnation:string,fencing_version:int,response_id:string,security_challenge_active:bool,security_challenge_path:?string}> */
    private array $taskSessions = [];
    /** @var array<string,true> */
    private array $pendingResumes = [];
    /** Explicit native stops use separate CDP observers; ordinary transport recovery is unchanged. */
    private ?CodexCancellation $codexCancellation = null;
    private float $lastCodexStopObservation = 0.0;
    /** @var array<string,array> Current stop intent and acknowledgement, scoped to its exact generation. */
    private array $codexUserStops = [];
    /** @var array<int,int> Browser command id => submitted no-side-effect Connector probe attempts. */
    private array $connectorProbeAttempts = [];
    /** Runtime fences live only for the current asynchronous connector commands. */
    private array $connectorProbeGenerations = [];
    /** @var array<string,array> Isolated probe picker owners; never added to the task execution registry. */
    private array $connectorModelSelections = [];
    /** @var array<int,array> Verified non-Pro mode for each current readiness command. */
    private array $connectorProbeModes = [];
    /** A connector check owns a background page; the setup browser remains on the user's tab. */
    private ?string $connectorProbeTargetId = null;
    private ?string $connectorProbeSessionId = null;
    /** @var array<int,float> Setup/native/connector commands cannot wait forever for browser-level CDP. */
    private array $connectorProbeCdpWaitSince = [];
    /** @var resource|null */
    private $ownershipLock = null;

    public function __construct()
    {
        $this->cdp = new CdpClient();
        $this->taskCdp = new CdpClient();
        $this->browserTimezone = new BrowserTimezone();
    }

    /** Stop the exact project-owned browser when the browser worker itself is shutting down. */
    public function shutdown(): void
    {
        Schema::initialize();
        $state = BrowserState::state();
        $profile = (string) Config::get('gateway', 'browser_profile_dir');
        $port = (int) ($state['debug_port'] ?? 0);
        $pid = (int) ($state['browser_pid'] ?? 0);
        $version = $port > 0 ? $this->devtoolsRequest($port, '/json/version') : null;
        $browserSocket = is_array($version) ? (string) ($version['webSocketDebuggerUrl'] ?? '') : '';

        $this->stopping = true;
        $this->browserTimezone->close();
        if ($this->connectorProbeTargetId !== null && $port > 0) {
            $this->devtoolsRequest($port, '/json/close/' . rawurlencode($this->connectorProbeTargetId));
        }
        $this->connectorProbeTargetId = null;
        $this->connectorProbeSessionId = null;
        $this->connectorProbeCdpWaitSince = [];
        $this->cdp->close('BROWSER_WORKER_STOPPING');
        $this->taskCdp->close('BROWSER_WORKER_STOPPING');
        try {
            if ($pid > 0) {
                BrowserProcess::terminate($this->ownedProcess, $pid, $profile, $port, $browserSocket);
            }
            BrowserProcess::clearSessionRestore($profile);
        } finally {
            $this->stopping = false;
        }

        $this->ownedProcess = null;
        $this->pendingStartCommand = null;
        $this->startupPlan = null;
        $this->startupPageCreation = null;
        $this->chatGptTurns = [];
        $this->taskSessions = [];
        $this->taskDownloads = [];
        $this->taskDownloadsReady = false;
        @unlink(self::screenshotPath());
        $this->clearMonitorScreenshots();
        BrowserState::write([
            'status' => 'stopped', 'browser_pid' => null, 'debug_port' => null, 'network_revision' => null,
            'active_target' => null, 'session_id' => null, 'url' => '', 'title' => '', 'tabs_json' => '[]',
            'document_nonce' => bin2hex(random_bytes(12)), 'surface_generation' => (int) $state['surface_generation'] + 1,
            'screenshot_sequence' => 0, 'screenshot_mime' => null, 'last_error' => null,
            'chatgpt_json' => null, 'chatgpt_observed_at' => 0,
        ]);
    }

    /** One short worker tick: heartbeat, recovery, CDP reconnect and one queued operation. */
    public function tick(): void
    {
        Schema::initialize();
        if (!$this->acquireOwnership()) return;
        if (!$this->recovered) {
            BrowserState::recover();
            $this->recovered = true;
        }
        if (microtime(true) - $this->lastHeartbeat >= 1.0) {
            BrowserState::heartbeat();
            $this->lastHeartbeat = microtime(true);
        }
        if (microtime(true) - $this->lastLoginIdentityCheck >= 5.0) {
            $this->lastLoginIdentityCheck = microtime(true);
            try { \app\gateway\model\BrowserLoginLifecycle::pollCodexIdentity(); } catch (\Throwable) {}
        }
        $state = BrowserState::state();
        if ((int) ($state['profile_reset_required'] ?? 0) === 1) {
            if (!$this->commandBusy) {
                $command = BrowserState::claim();
                if ($command !== null) {
                    $this->commandBusy = true;
                    $this->execute($command);
                }
            }
            return;
        }
        if (!$this->autoStartAttempted && $state['status'] === 'stopped' && (int) ($state['auto_start'] ?? 0) === 1) {
            $this->autoStartAttempted = true;
            $runtime = ChromeForTestingRuntime::status();
            if ($runtime['installed']) {
                try {
                    $network = NetworkSettings::state();
                    $revision = NetworkSettings::revision((int) $network['saved_revision']);
                    if ($revision['mode'] !== 'unconfigured') {
                        BrowserState::enqueue('start', [
                            'revision' => (int) $revision['id'],
                            'browser' => 'bundled',
                        ], false);
                    }
                } catch (\Throwable) {
                    // The setup page remains available for an explicit retry after repair.
                }
            }
        }
        // A Windows supervisor/console restart can terminate the headed Chrome before the
        // browser worker gets a normal onWorkerStop callback. In that case SQLite may still
        // say ready/starting even though the recorded PID no longer owns the CDP listener.
        // A freshly-created BrowserRuntime has no proc_open handle ($ownedProcess === null),
        // so reconcile that detached stale state before attempting a CDP reconnect. Do not
        // apply this check to a browser launched by this worker: its listener can legitimately
        // take a short moment to appear while status=starting.
        if ($state['status'] !== 'stopped' && PHP_OS_FAMILY === 'Windows' && $this->ownedProcess === null) {
            $pid = (int) ($state['browser_pid'] ?? 0);
            $port = (int) ($state['debug_port'] ?? 0);
            if ($pid > 0 && $port > 0 && BrowserProcess::hasWindowsLoopbackListener($pid, $port) === false) {
                $this->forgetExitedBrowser($state);
                $state = BrowserState::state();
            }
        }
        if ($state['status'] !== 'stopped' && in_array(PHP_OS_FAMILY, ['Linux', 'Darwin'], true)
            && $this->pendingStartCommand === null && microtime(true) - $this->lastUnixExitCheck >= 0.5) {
            $this->lastUnixExitCheck = microtime(true);
            $state = $this->reconcileExitedUnixBrowser($state);
        }
        if ($this->recoverColdStartup($state)) $state = BrowserState::state();
        if ($state['status'] !== 'stopped' && !$this->cdp->ready() && !$this->connecting) {
            $this->tryConnect($state);
        }
        if ($this->pendingStartCommand !== null && $this->cdp->ready() && !$this->connecting) {
            // Establish the browser-level transport before publishing startup readiness. Native
            // window/login operations use this connection, not just the setup page WebSocket.
            $this->ensureTaskCdp($state);
        }
        if ($state['status'] === 'ready' && $this->pendingStartCommand === null) {
            $this->observeCodexUserStops();
            $this->recoverResponses($state);
            $this->ensureTaskCdp($state);
            $cleanupInterval = ($this->chatGptTurns !== [] || $this->pendingResumes !== [])
                ? 1.0 : self::IDLE_TASK_RECONCILE_SECONDS;
            if (microtime(true) - $this->lastTaskCleanup >= $cleanupInterval) {
                $this->cleanupRetiredTaskSurfaces($state);
                $this->lastTaskCleanup = microtime(true);
            }
            if (microtime(true) - $this->lastArtifactCleanup >= 60.0) {
                try { ArtifactStore::cleanupOrphans(3600); } catch (\Throwable) {}
                try { AttachmentStore::cleanupOrphans(86400); } catch (\Throwable) {}
                $this->lastArtifactCleanup = microtime(true);
            }
        }
        $inspectionInterval = ($this->commandBusy || $this->pendingStartCommand !== null || $this->chatGptTurns !== [])
            ? 1.0 : self::IDLE_CAPABILITY_REFRESH_SECONDS;
        if ($state['status'] === 'ready' && $this->pendingStartCommand === null && ($this->cdp->ready() || $this->taskCdp->ready())
            && microtime(true) - $this->lastChatGptInspection >= $inspectionInterval) {
            $this->refreshChatGptCapability();
            $this->lastChatGptInspection = microtime(true);
        }
        if ($this->pendingStartCommand !== null && microtime(true) >= $this->startDeadline) {
            $id = $this->pendingStartCommand;
            $this->pendingStartCommand = null;
            $this->commandBusy = false;
            BrowserState::finish($id, false, ['code' => 'BROWSER_START_TIMEOUT', 'message' => '浏览器启动后未能建立本机 CDP 连接。', 'detail' => $this->startupStage]);
            $failedState = BrowserState::state();
            try {
                $pid = (int) ($failedState['browser_pid'] ?? 0);
                $port = (int) ($failedState['debug_port'] ?? 0);
                if ($pid > 0) {
                    BrowserProcess::terminate(
                        $this->ownedProcess,
                        $pid,
                        (string) Config::get('gateway', 'browser_profile_dir'),
                        $port
                    );
                }
            } catch (\Throwable $error) {
                error_log('[browser] failed-start cleanup: ' . mb_substr($error->getMessage(), 0, 300));
            }
            $this->ownedProcess = null;
            $this->cdp->close('BROWSER_START_TIMEOUT');
            $this->taskCdp->close('BROWSER_START_TIMEOUT');
            $this->taskDownloadsReady = false;
            @unlink(self::screenshotPath());
            $this->clearMonitorScreenshots();
            BrowserState::write([
                'status' => 'stopped', 'browser_pid' => null, 'debug_port' => null, 'network_revision' => null,
                'active_target' => null, 'session_id' => null, 'url' => '', 'title' => '', 'tabs_json' => '[]',
                'screenshot_sequence' => 0, 'screenshot_mime' => null, 'last_error' => 'BROWSER_START_TIMEOUT',
            ]);
        }
        if (!$this->commandBusy) {
            $command = BrowserState::claim();
            if ($command !== null) {
                $this->commandBusy = true;
                $this->execute($command);
            }
        }
    }

    /** Recover task work only after browser startup; queue insertion requires a running browser. */
    private function recoverResponses(array $state): void
    {
        if ($this->responsesRecovered || ($state['status'] ?? '') !== 'ready') return;
        $responses = ResponseJob::recoverInterrupted();
        foreach ((array) ($responses['resume'] ?? []) as $responseId) {
            if (!is_string($responseId) || $responseId === '') continue;
            $job = ResponseJob::execution($responseId);
            $commandId = (int) ($job['command_id'] ?? 0);
            $command = $commandId > 0
                ? SDB(null, 'medoo', 'sqlite')->table('gateway_browser_commands')->where('id', $commandId)->find() : null;
            $payload = is_array($command) ? json_decode((string) ($command['payload_json'] ?? ''), true) : null;
            // Running responses already own a durable recovery command. Starting them again
            // from CDP's ready callback would reclaim the same page and invalidate its first
            // observer. Completed tool-call rounds have no new command and keep that callback.
            if ((string) ($job['status'] ?? '') === 'running'
                && is_array($command) && (string) ($command['status'] ?? '') === 'queued'
                && (string) ($command['action'] ?? '') === 'chatgpt_text'
                && is_array($payload) && ($payload['response_id'] ?? null) === $responseId
                && ($payload['recover'] ?? null) === true) continue;
            $this->pendingResumes[$responseId] = true;
        }
        if ((int) ($responses['failed'] ?? 0) > 0 && (int) ($responses['safe_queued'] ?? 0) === 0) {
            BrowserState::setLease('manual');
        }
        $this->responsesRecovered = true;
    }

    /**
     * Observe precise local manual-stop events once per second, even between native tool rounds.
     * No connection close, inactivity timeout or browser error enters this path.
     */
    private function observeCodexUserStops(): void
    {
        if (microtime(true) - $this->lastCodexStopObservation < 1.0) return;
        $this->lastCodexStopObservation = microtime(true);
        $this->codexCancellation ??= new CodexCancellation();
        foreach (TaskLedger::cancellableExecutions() as $execution) {
            $id = (string) $execution['job']['id'];
            if (($execution['turn']['error_code'] ?? '') !== 'CODEX_USER_STOP_PENDING') {
                $native = TaskLedger::cancellationNativeTurn($execution);
                $task = SDB(null, 'medoo', 'sqlite')->table('gateway_tasks')->where('id', $execution['turn']['task_id'])->find();
                $evidence = $native !== null && is_array($task)
                    ? $this->codexCancellation->interruption((string) ($task['thread_id'] ?? ''), $native,
                        (int) $execution['turn']['created_at']) : null;
                if ($evidence === null || !TaskLedger::requestUserStop($id, $evidence)) continue;
                $execution = TaskLedger::execution($id);
            }
            // Detaching cancels queued callbacks, not the browser target. A dedicated observer confirms Stop.
            foreach ($this->chatGptTurns as $observer => $turn) {
                if (($turn['durable_turn_id'] ?? '') === $execution['turn']['id']) $this->releaseTaskSession((string) $observer);
            }
            unset($this->pendingResumes[$id]);
            if (!isset($this->codexUserStops[$id])) {
                $this->codexUserStops[$id] = ['execution' => $execution, 'session_id' => null,
                    'busy' => false, 'clicked' => false, 'idle_since' => null];
            }
        }
        foreach (array_keys($this->codexUserStops) as $id) $this->pollCodexUserStop($id);
    }

    /**
     * Stop only the owned generation, then confirm idle without resending its request.
     * A renderer timeout keeps its CDP session and requires read-back before another click;
     * only a protocol/session error reattaches. Slow observations share the normal 15s read budget.
     */
    private function pollCodexUserStop(string $responseId): void
    {
        $stop = $this->codexUserStops[$responseId] ?? null;
        if (!is_array($stop) || $stop['busy']) return;
        $expected = $stop['execution'];
        if (!TaskLedger::userStopOwns($expected)) { $this->releaseCodexStopObserver($responseId); return; }
        $targetId = (string) ($expected['surface']['target_id'] ?? '');
        if ($targetId === '' && empty($expected['job']['submit_intent_at'])) {
            TaskLedger::confirmUserStop($expected, true);
            $this->releaseCodexStopObserver($responseId);
            return;
        }
        if (!$this->taskCdp->ready()) return;
        $this->codexUserStops[$responseId]['busy'] = true;
        if ($stop['session_id'] === null) {
            $this->taskCdp->send('Target.getTargets', [], null, function ($error, $result) use ($responseId, $expected, $targetId): void {
                if (!isset($this->codexUserStops[$responseId])) return;
                $this->codexUserStops[$responseId]['busy'] = false;
                if ($error !== null || !is_array($result['targetInfos'] ?? null) || !TaskLedger::userStopOwns($expected)) return;
                $matches = array_values(array_filter($result['targetInfos'], static fn($row) => is_array($row) && ($row['targetId'] ?? '') === $targetId));
                if ($matches === []) {
                    TaskLedger::confirmUserStop($expected, true);
                    $this->releaseCodexStopObserver($responseId);
                    return;
                }
                $this->codexUserStops[$responseId]['busy'] = true;
                $this->taskCdp->send('Target.attachToTarget', ['targetId' => $targetId, 'flatten' => true], null,
                    function ($attachError, $attached) use ($responseId, $expected): void {
                        $sessionId = (string) ($attached['sessionId'] ?? '');
                        if (!isset($this->codexUserStops[$responseId]) || !TaskLedger::userStopOwns($expected)) {
                            if ($sessionId !== '') $this->detachTaskSessionRaw($sessionId);
                            return;
                        }
                        $this->codexUserStops[$responseId]['busy'] = false;
                        if ($attachError !== null || $sessionId === '') return;
                        $this->codexUserStops[$responseId]['session_id'] = $sessionId;
                        $this->pollCodexUserStop($responseId);
                    }, 3.0);
            }, 3.0);
            return;
        }
        preg_match_all('/(?<![A-Za-z0-9_-])turn_[A-Za-z0-9_-]{32}(?![A-Za-z0-9_-])/', (string) $expected['job']['prompt'], $tokens);
        $witnesses = count(array_unique($tokens[0])) === 1 ? [(string) $tokens[0][0]] : [];
        if (!empty($expected['job']['transport_transaction_id']) && (int) ($expected['job']['transport_stage_sent'] ?? 0) > 0) {
            $witnesses[] = (string) $expected['job']['transport_transaction_id'];
        }
        $users = json_decode((string) ($expected['job']['baseline_users_json'] ?? '[]'), true) ?: [];
        $url = (string) $expected['surface']['url'];
        if ($url === 'about:blank' && empty($expected['job']['submit_intent_at'])) $url = self::taskModelUrl();
        $expression = WebModelPage::userStopExpression($url, $witnesses, $users,
            !$stop['clicked'] && empty($stop['verify_only']), false,
            self::acceptedInitialUser($expected['job'], $expected['surface']));
        $sessionId = (string) $stop['session_id'];
        $this->evaluateTaskSession($sessionId, $expression,
            function ($error, $result) use ($responseId, $expected, $sessionId): void {
                if (($this->codexUserStops[$responseId]['session_id'] ?? null) !== $sessionId) return;
                $this->codexUserStops[$responseId]['busy'] = false;
                if (!TaskLedger::userStopOwns($expected)) { $this->releaseCodexStopObserver($responseId); return; }
                if ($error !== null || !is_array($result)) {
                    $this->codexUserStops[$responseId]['idle_since'] = null;
                    $this->codexUserStops[$responseId]['verify_only'] = true;
                    // A timeout says nothing about session validity or whether Stop was clicked.
                    // Read this same owned page before deciding whether another click is needed.
                    if ($error === null || ($error['message'] ?? '') === 'BROWSER_CDP_TIMEOUT') return;
                    // Browser-level reconnect invalidates flattened CDP sessions, not the retained tab.
                    $this->codexUserStops[$responseId]['session_id'] = null;
                    if ($sessionId !== '') $this->detachTaskSessionRaw($sessionId);
                    return;
                }
                if (($result['page_invalid'] ?? null) === true) {
                    TaskLedger::confirmUserStop($expected, false, true);
                    $this->releaseCodexStopObserver($responseId);
                    return;
                }
                if (empty($result['owned'])) return;
                $this->codexUserStops[$responseId]['verify_only'] = false;
                if (!empty($result['stopped'])) $this->codexUserStops[$responseId]['clicked'] = true;
                if (!empty($result['generation_running']) || empty($result['editor_ready'])) {
                    $this->codexUserStops[$responseId]['idle_since'] = null;
                    return;
                }
                $since = $this->codexUserStops[$responseId]['idle_since'];
                if ($since === null) { $this->codexUserStops[$responseId]['idle_since'] = microtime(true); return; }
                if (microtime(true) - $since < 1.0) return;
                TaskLedger::confirmUserStop($expected);
                $this->releaseCodexStopObserver($responseId);
            }, 15.0);
    }

    /** Release only the stop observer; the Chrome tab remains available for the user's next turn. */
    private function releaseCodexStopObserver(string $responseId): void
    {
        $sessionId = (string) ($this->codexUserStops[$responseId]['session_id'] ?? '');
        unset($this->codexUserStops[$responseId]);
        if ($sessionId !== '') $this->detachTaskSessionRaw($sessionId);
    }

    /** Capture the current page surface without blocking the API worker. */
    public function capture(): void
    {
        $this->captureTaskMonitor();
        // Page.captureScreenshot is one of the most expensive idle CDP operations. The setup
        // console polls /api/browser/screenshot only while its surface is actually visible, so
        // keep the browser idle unless a viewer is present. Startup still captures one frame to
        // prove renderer readiness and finish the pending start command.
        if ($this->pendingStartCommand === null && !self::observerActive(self::screenshotObserverPath())) return;
        if ($this->screenshotBusy || $this->connecting || $this->switchingTarget || !$this->cdp->ready()) return;
        $state = BrowserState::state();
        if ($state['status'] !== 'ready' && !($state['status'] === 'starting' && $this->pendingStartCommand !== null)) return;
        $this->screenshotBusy = true;
        $this->cdp->send('Page.captureScreenshot', [
            'format' => 'jpeg', 'quality' => 68, 'fromSurface' => true, 'captureBeyondViewport' => false,
        ], null, function ($error, $result) use ($state): void {
            $this->screenshotBusy = false;
            $current = BrowserState::state();
            if (($current['launch_nonce'] ?? null) !== ($state['launch_nonce'] ?? null)
                || ($current['active_target'] ?? null) !== ($state['active_target'] ?? null)
                || !in_array($current['status'], ['starting', 'ready'], true)) return;
            if ($error !== null || !isset($result['data']) || !is_string($result['data']) || strlen($result['data']) > 8 * 1024 * 1024) return;
            $bytes = base64_decode($result['data'], true);
            if ($bytes === false || $bytes === '' || strlen($bytes) > 6 * 1024 * 1024) return;
            $path = self::screenshotPath();
            $directory = dirname($path);
            if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) return;
            $tmp = $path . '.tmp';
            if (file_put_contents($tmp, $bytes, LOCK_EX) === false || !@rename($tmp, $path)) {
                @unlink($tmp);
                return;
            }
            try {
                Schema::transaction(function (): void {
                    $state = BrowserState::state();
                    BrowserState::write([
                        'screenshot_sequence' => (int) $state['screenshot_sequence'] + 1,
                        'screenshot_mime' => 'image/jpeg',
                    ]);
                });
                $this->refreshMetadata();
                $this->refreshTabs();
                if ($this->pendingStartCommand !== null && $this->taskCdp->ready()) {
                    $pending = $this->pendingStartCommand;
                    $this->recordStartupStage('ready');
                    $this->pendingStartCommand = null;
                    $this->startupPlan = null;
                    BrowserState::write(['status'=>'ready', 'last_error'=>null]);
                    $this->finishCommand($pending, ['status' => 'ready', 'target_id' => BrowserState::state()['active_target'],
                        'timezone' => $this->browserTimezoneId === '' ? 'system' : $this->browserTimezoneId]);
                }
            } catch (\Throwable) {
            }
        }, 4.0);
    }

    /** Keep the viewed task page at its latest message and capture it for the console monitor. */
    private function captureTaskMonitor(): void
    {
        if (!self::observerActive(self::monitorObserverPath())) return;
        if ($this->taskScreenshotBusy || !$this->taskCdp->ready()
            || microtime(true) - $this->lastMonitorCaptureAt < self::MONITOR_CAPTURE_INTERVAL_SECONDS) return;
        $observed = self::observedMonitorSurfaces();
        if ($observed === []) return;
        $previous = array_search($this->lastMonitorCapturedSurface, $observed, true);
        if ($previous !== false) {
            $observed = array_merge(array_slice($observed, $previous + 1), array_slice($observed, 0, $previous + 1));
        }
        foreach ($observed as $surfaceId) {
            try { $surface = TaskLedger::monitorSurfaceTarget($surfaceId); }
            catch (\Throwable) { continue; }
            if (!is_array($surface) || $surface['target_id'] === '') continue;
            $targetId = (string) $surface['target_id'];
            $generation = (int) $surface['generation'];
            if (($this->idleMonitorGeneration[$surfaceId] ?? null) !== $generation) {
                unset($this->idleMonitorCapturedAt[$surfaceId], $this->idleMonitorAttemptAt[$surfaceId]);
                $this->idleMonitorGeneration[$surfaceId] = $generation;
            }
            $sessionId = null;
            foreach ($this->taskSessions as $candidate => $owner) {
                $turn = $this->chatGptTurns[(string) ($owner['response_id'] ?? '')] ?? null;
                if ((string) ($owner['surface_id'] ?? '') === $surfaceId
                    && is_array($turn) && (string) ($turn['target_id'] ?? '') === $targetId) {
                    $sessionId = (string) $candidate;
                    break;
                }
            }
            if ($sessionId !== null && $sessionId !== '') {
                unset($this->idleMonitorCapturedAt[$surfaceId], $this->idleMonitorAttemptAt[$surfaceId]);
            } elseif (!in_array((string) $surface['status'], ['ready', 'needs_attention'], true)) {
                // The task worker is still attaching or recovering this target; do not add an
                // observer session until it is truly retained without an active turn session.
                continue;
            } elseif (microtime(true) - ($this->idleMonitorCapturedAt[$surfaceId] ?? 0.0)
                < self::IDLE_MONITOR_CAPTURE_INTERVAL_SECONDS
                || microtime(true) - ($this->idleMonitorAttemptAt[$surfaceId] ?? 0.0)
                < self::IDLE_MONITOR_RETRY_SECONDS) {
                continue;
            }
            $this->taskScreenshotBusy = true;
            $this->lastMonitorCapturedSurface = $surfaceId;
            $this->lastMonitorCaptureAt = microtime(true);
            if ($sessionId !== null && $sessionId !== '') {
                $this->captureMonitorSession($sessionId, $surfaceId, $targetId, $generation, false);
                return;
            }
            $this->idleMonitorAttemptAt[$surfaceId] = $this->lastMonitorCaptureAt;
            // A completed conversation keeps its page but releases its turn session. Attach only
            // the selected page to follow the latest message and capture it, then detach.
            $this->taskCdp->send('Target.attachToTarget', ['targetId' => $targetId, 'flatten' => true], null,
                function ($error, $result) use ($surfaceId, $targetId, $generation): void {
                    $sessionId = (string) ($result['sessionId'] ?? '');
                    if ($error !== null || $sessionId === '') { $this->taskScreenshotBusy = false; return; }
                    $this->taskCdp->send('Page.enable', [], $sessionId,
                        function ($pageError) use ($sessionId, $surfaceId, $targetId, $generation): void {
                            if ($pageError !== null) {
                                $this->detachTaskSessionRaw($sessionId);
                                $this->taskScreenshotBusy = false;
                                return;
                            }
                            $this->captureMonitorSession($sessionId, $surfaceId, $targetId, $generation, true);
                        }, 3.0);
                }, 5.0);
            return;
        }
    }

    /** Follow a viewed task page's latest message, then capture its frame even if scrolling fails. */
    private function captureMonitorSession(string $sessionId, string $surfaceId, string $targetId,
        int $generation, bool $detach): void
    {
        try { $current = TaskLedger::monitorSurfaceTarget($surfaceId); }
        catch (\Throwable) { $current = null; }
        if (!is_array($current) || $current['target_id'] !== $targetId
            || (int) $current['generation'] !== $generation) {
            if ($detach) $this->detachTaskSessionRaw($sessionId);
            $this->taskScreenshotBusy = false;
            return;
        }
        $this->taskCdp->send('Runtime.evaluate', [
            'expression' => ChatGptPage::followLatestMessageExpression(),
            'returnByValue' => true,
        ], $sessionId, function () use ($sessionId, $surfaceId, $targetId, $generation, $detach): void {
            $this->captureMonitorFrame($sessionId, $surfaceId, $targetId, $generation, $detach);
        }, 1.0);
    }

    /** Persist only a frame from the same target generation selected for this capture. */
    private function captureMonitorFrame(string $sessionId, string $surfaceId, string $targetId,
        int $generation, bool $detach): void
    {
        $this->taskCdp->send('Page.captureScreenshot', [
            'format' => 'jpeg', 'quality' => 68, 'fromSurface' => true, 'captureBeyondViewport' => false,
        ], $sessionId, function ($error, $result) use ($sessionId, $surfaceId, $targetId, $generation, $detach): void {
            if ($detach) $this->detachTaskSessionRaw($sessionId);
            $this->taskScreenshotBusy = false;
            if ($error !== null || !isset($result['data']) || !is_string($result['data']) || strlen($result['data']) > 8 * 1024 * 1024) return;
            $bytes = base64_decode($result['data'], true);
            if ($bytes === false || strlen($bytes) > 6 * 1024 * 1024) return;
            try { $current = TaskLedger::monitorSurfaceTarget($surfaceId); }
            catch (\Throwable) { return; }
            if (!is_array($current) || $current['target_id'] !== $targetId
                || (int) $current['generation'] !== $generation) return;
            $path = self::monitorScreenshotPath($surfaceId, $generation);
            $directory = dirname($path);
            if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) return;
            $tmp = $path . '.tmp';
            if (file_put_contents($tmp, $bytes, LOCK_EX) === false || !@rename($tmp, $path)) {
                @unlink($tmp);
                return;
            }
            if ($detach) $this->idleMonitorCapturedAt[$surfaceId] = microtime(true);
        }, 4.0);
    }

    /** Current ephemeral surface path; never stored under persistent data/. */
    public static function screenshotPath(): string
    {
        $key = substr(hash('sha256', (string) Config::get('gateway', 'database_file')), 0, 20);
        return runtime_path() . '/gateway-browser/' . $key . '/surface.jpg';
    }

    /** Ephemeral screenshot for the task-owned automation surface. */
    public static function monitorScreenshotPath(?string $surfaceId = null, ?int $generation = null): string
    {
        $key = substr(hash('sha256', (string) Config::get('gateway', 'database_file')), 0, 20);
        $directory = runtime_path() . '/gateway-browser/' . $key;
        if ($surfaceId === null) return $directory . '/automation.jpg';
        if (!preg_match('/\Asurf_[a-f0-9]{24}\z/D', $surfaceId) || $generation === null || $generation < 1) {
            throw new \InvalidArgumentException('Invalid monitored browser surface');
        }
        return $directory . '/automation-' . $surfaceId . '-' . $generation . '.jpg';
    }

    /** Remove only disposable monitor frames owned by this gateway instance. */
    private function clearMonitorScreenshots(?string $surfaceId = null): void
    {
        if ($surfaceId === null) {
            $this->idleMonitorCapturedAt = [];
            $this->idleMonitorAttemptAt = [];
            $this->idleMonitorGeneration = [];
            $this->lastMonitorCapturedSurface = null;
            $this->lastMonitorCaptureAt = 0.0;
        } else {
            unset($this->idleMonitorCapturedAt[$surfaceId], $this->idleMonitorAttemptAt[$surfaceId],
                $this->idleMonitorGeneration[$surfaceId]);
            if ($this->lastMonitorCapturedSurface === $surfaceId) {
                $this->lastMonitorCapturedSurface = null;
                $this->lastMonitorCaptureAt = 0.0;
            }
        }
        $directory = dirname(self::monitorScreenshotPath());
        foreach (glob($directory . '/automation-surf_*') ?: [] as $path) {
            $name = basename($path);
            if (!preg_match('/\Aautomation-(surf_[a-f0-9]{24})(?:-[1-9][0-9]*\.jpg|\.viewer)\z/D', $name, $match)) continue;
            if ($surfaceId === null || $match[1] === $surfaceId) @unlink($path);
        }
        if ($surfaceId === null) {
            @unlink(self::monitorScreenshotPath());
            @unlink(self::monitorObserverPath());
        }
    }

    /** Mark that the ordinary setup/login surface is currently visible in the local console. */
    public static function noteScreenshotObserver(): void
    {
        self::touchObserver(self::screenshotObserverPath());
    }

    /** Mark that the automatic-task surface is currently visible in the local console. */
    public static function noteMonitorObserver(?string $surfaceId = null): void
    {
        if ($surfaceId === null) {
            $monitor = TaskLedger::monitor();
            $surfaceId = !empty($monitor['active']) ? (string) ($monitor['surface_id'] ?? '') : '';
        }
        if (!preg_match('/\Asurf_[a-f0-9]{24}\z/D', $surfaceId)) return;
        self::touchObserver(self::monitorSurfaceObserverPath($surfaceId));
        self::touchObserver(self::monitorObserverPath());
    }

    /** List recently observed task pages; each console window refreshes its own marker. @return list<string> */
    private static function observedMonitorSurfaces(): array
    {
        $observed = [];
        foreach (glob(dirname(self::monitorObserverPath()) . '/automation-surf_*.viewer') ?: [] as $path) {
            if (!preg_match('/\Aautomation-(surf_[a-f0-9]{24})\.viewer\z/D', basename($path), $match)
                || !self::observerActive($path)) continue;
            $observed[] = $match[1];
        }
        return $observed;
    }

    private static function screenshotObserverPath(): string
    {
        return dirname(self::screenshotPath()) . '/surface.viewer';
    }

    private static function monitorObserverPath(): string
    {
        return dirname(self::monitorScreenshotPath()) . '/automation.viewer';
    }

    /** Observer marker for one validated task surface. */
    private static function monitorSurfaceObserverPath(string $surfaceId): string
    {
        return dirname(self::monitorObserverPath()) . '/automation-' . $surfaceId . '.viewer';
    }

    private static function touchObserver(string $path): void
    {
        $directory = dirname($path);
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) return;
        @touch($path);
    }

    private static function observerActive(string $path, int $ttl = self::OBSERVER_TTL_SECONDS): bool
    {
        clearstatcache(true, $path);
        $mtime = @filemtime($path);
        return is_int($mtime) && $mtime >= time() - $ttl;
    }

    private function acquireOwnership(): bool
    {
        if ($this->ownershipLock !== null) return true;
        $path = Config::get('gateway', 'database_file') . '.browser.lock';
        $lock = @fopen($path, 'c');
        if ($lock === false) return false;
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            return false;
        }
        $this->ownershipLock = $lock;
        return true;
    }

    private function execute(array $command): void
    {
        $action = (string) $command['action'];
        $payload = is_array($command['payload']) ? $command['payload'] : [];
        try {
            match ($action) {
                'install' => $this->installRuntime((int) $command['id'], $payload),
                'start' => $this->startBrowser((int) $command['id'], $payload),
                'restart' => $this->restartBrowser((int) $command['id'], $payload),
                'native_window' => $this->nativeWindow((int) $command['id'], $payload),
                'stop' => $this->stopBrowser((int) $command['id']),
                'reset_profile' => $this->resetProfile((int) $command['id']),
                'navigate' => $this->cdpCommand((int) $command['id'], 'Page.navigate', ['url' => (string) $payload['url']]),
                'click' => $this->click((int) $command['id'], (float) $payload['x'], (float) $payload['y']),
                'scroll' => $this->scroll(
                    (int) $command['id'],
                    (float) ($payload['x'] ?? 640),
                    (float) ($payload['y'] ?? 360),
                    (float) ($payload['delta_x'] ?? 0),
                    (float) $payload['delta_y']
                ),
                'text' => $this->cdpCommand((int) $command['id'], 'Input.insertText', ['text' => (string) $payload['text']]),
                'key' => $this->key((int) $command['id'], (string) $payload['key']),
                'new_tab' => $this->newTab((int) $command['id'], (string) ($payload['url'] ?? 'about:blank')),
                'switch_tab' => $this->switchTab((int) $command['id'], (string) $payload['target_id']),
                'close_tab' => $this->closeTab((int) $command['id'], (string) $payload['target_id']),
                'reload' => $this->cdpCommand((int) $command['id'], 'Page.reload', ['ignoreCache' => false]),
                'verify_connector' => $this->verifyConnector((int) $command['id'], $payload),
                // Full model discovery reads every family's current range on an isolated page.
                // The embedded login/user page and active tasks keep their selected model.
                'probe_models' => $this->probeAccountModelsInBackground((int)$command['id']),
                'chatgpt_text' => $this->chatGptText((int) $command['id'], $payload),
                default => $this->failCommand((int) $command['id'], 'BROWSER_ACTION_UNSUPPORTED', '浏览器操作不受支持。'),
            };
        } catch (GatewayError $error) {
            $this->failCommand((int) $command['id'], $error->errorCode, $error->getMessage());
        } catch (\Throwable $error) {
            error_log('[browser-command] ' . get_class($error) . ' at ' . basename($error->getFile()) . ':' . $error->getLine());
            @file_put_contents((string) Config::get('gateway', 'browser_log_dir') . '/runtime-errors.log',
                date('c') . ' ' . get_class($error) . ' ' . basename($error->getFile()) . ':' . $error->getLine() . PHP_EOL, FILE_APPEND);
            $this->failCommand((int) $command['id'], 'BROWSER_COMMAND_FAILED', '浏览器操作未能完成。');
        }
    }

    /** Display/hide a separate native setup window without replacing the embedded page or any task session. */
    private function nativeWindow(int $commandId, array $payload): void
    {
        if (!$this->taskCdp->ready()) {
            // Worker reattachment can restore the setup page before the browser WebSocket.
            // No native-window effect has started, so defer the same command instead of failing it.
            $since = $this->connectorProbeCdpWaitSince[$commandId] ?? microtime(true);
            $this->connectorProbeCdpWaitSince[$commandId] = $since;
            if (microtime(true) - $since >= 20.0) {
                $this->failCommand($commandId, 'BROWSER_NOT_READY', '浏览器控制连接尚未就绪。');
                return;
            }
            BrowserState::defer($commandId);
            $this->commandBusy = false;
            return;
        }
        NativeBrowserWindow::setVisible($this->taskCdp, BrowserState::state(), ($payload['mode'] ?? '') === 'show',
            self::modelUrl(), function ($error, $result) use ($commandId): void {
                if ($error instanceof GatewayError) $this->failCommand($commandId, $error->errorCode, $error->getMessage());
                else $this->finishCommand($commandId, $result);
            });
    }

    /** Periodic availability discovery owns one private page, leaving user/task menus and logical models unchanged. */
    private function probeAccountModelsInBackground(int $commandId): void
    {
        if (!$this->taskCdp->ready()) { $this->failCommand($commandId, 'BROWSER_NOT_READY', '浏览器控制连接尚未就绪。'); return; }
        (new BackgroundModelProbe($this->taskCdp, BrowserState::state(), function ($error, $capabilities) use ($commandId): void {
            try {
                if ($error instanceof GatewayError) { $this->failCommand($commandId, $error->errorCode, $error->getMessage()); return; }
                \app\gateway\model\ModelCatalog::observe($capabilities);
                $this->observeUsageAccount();
                $this->finishCommand($commandId, ['models'=>\app\gateway\model\ModelCatalog::routes($capabilities)]);
            } catch (\Throwable) { $this->failCommand($commandId, 'MODEL_PROBE_FAILED', '后台模型检测暂未完成，保留原模型和额度记录。'); }
        }))->start(self::taskModelUrl());
    }

    /** Observe the logged-in account controls without sending a message or changing its chosen model. */
    private function probeAccountModels(int $commandId, float $deadline, bool $opened = false, ?float $absenceSince = null): void
    {
        $expression = $opened ? WebModelPage::effortSliderExpression() : WebModelPage::modelControlStateExpression();
        $this->evaluateBrowser($expression, function ($error, $value) use ($commandId, $deadline, $opened, $absenceSince): void {
            if ($error === null && is_array($value) && !empty($value['ok'])) {
                if ($opened) {
                    $capabilities = ChatGptTransport::modelCapabilities($value);
                    // Slider hydration alone is not a complete family observation.
                    if (\app\gateway\model\ModelCatalog::families($capabilities) === []) {
                        if (microtime(true) < $deadline) {
                            Timer::add(0.15, fn() => $this->probeAccountModels($commandId, $deadline, true), null, false);
                            return;
                        }
                        $this->dispatchBrowserKey('Escape', function () use ($commandId): void {
                            $this->failCommand($commandId, 'MODEL_PROBE_FAILED', '模型家族选项尚未稳定，保留原检测记录。');
                        });
                        return;
                    }
                    $this->dispatchBrowserKey('Escape', function ($keyError) use ($commandId, $capabilities): void {
                        if ($keyError !== null) { $this->failCommand($commandId, 'MODEL_PROBE_FAILED', '模型菜单未能收起。'); return; }
                        \app\gateway\model\ModelCatalog::observe($capabilities);
                        $this->observeUsageAccount();
                        $this->finishCommand($commandId, ['models' => \app\gateway\model\ModelCatalog::routes($capabilities)]);
                    });
                    return;
                }
                if (!empty($value['sol_available']) && (int) ($value['effort_control_count'] ?? 0) === 1) {
                    $this->evaluateBrowser(WebModelPage::focusEffortControlExpression(), function ($focusError, $focused) use ($commandId, $deadline): void {
                        if ($focusError !== null || empty($focused['ok'])) {
                            $this->evaluateBrowser(WebModelPage::dismissOptionalSetupDialogExpression(),
                                function ($dismissError, $dismissed) use ($commandId, $deadline): void {
                                    if ($dismissError === null && !empty($dismissed['ok']) && !empty($dismissed['dismissed'])
                                        && microtime(true) < $deadline) {
                                        Timer::add(0.15, fn() => $this->probeAccountModels($commandId, $deadline), null, false);
                                        return;
                                    }
                                    $this->failCommand($commandId, 'MODEL_PROBE_FAILED',
                                        '模型控制器无法获得焦点；请检查并关闭 ChatGPT 弹窗。');
                                });
                            return;
                        }
                        $next = fn() => $this->probeAccountModels($commandId, $deadline, true);
                        if (!empty($focused['expanded'])) { $next(); return; }
                        $this->dispatchBrowserKey('Enter', function ($keyError) use ($next, $commandId): void {
                            if ($keyError !== null) { $this->failCommand($commandId, 'MODEL_PROBE_FAILED', '模型菜单无法打开。'); return; }
                            Timer::add(0.15, $next, null, false);
                        });
                    });
                    return;
                }
                $lunaPlan = in_array((string) ($value['account_plan'] ?? 'unknown'), ['free', 'go'], true);
                if (!empty($value['document_ready']) && $lunaPlan
                    && (int) ($value['effort_control_count'] ?? -1) === 0) {
                    $absenceSince ??= microtime(true);
                    if (microtime(true) - $absenceSince >= 3) {
                        $capabilities = ['account_plan' => $value['account_plan'] ?? 'unknown', 'sol_available' => false,
                            'extra_high_available' => false, 'pro_available' => false,
                            'think_available' => (int)($value['think_control_count'] ?? 0) === 1
                                && is_bool($value['think_pressed'] ?? null)];
                        \app\gateway\model\ModelCatalog::observe($capabilities);
                        $this->observeUsageAccount();
                        $this->finishCommand($commandId, ['models' => \app\gateway\model\ModelCatalog::routes($capabilities)]);
                        return;
                    }
                }
            }
            if (microtime(true) >= $deadline) {
                $this->failCommand($commandId, 'MODEL_PROBE_FAILED',
                    '未读到稳定的账号模型选项；缺少明确 Free / Go 账号证据时不会推断为 Luna / Think。请确认 ChatGPT 已登录并加载完成。');
                return;
            }
            Timer::add(0.15, fn() => $this->probeAccountModels($commandId, $deadline, $opened, $absenceSince), null, false);
        });
    }

    /** Install/verify without opening a window, retaining a bounded worker heartbeat through synchronous download progress. */
    private function installRuntime(int $commandId, array $payload): void
    {
        $revision = NetworkSettings::revision((int) ($payload['revision'] ?? 0));
        $lastHeartbeat = 0.0;
        $runtime = BrowserProcess::select('bundled', $revision, static function (array $event) use (&$lastHeartbeat): void {
            $now = microtime(true);
            if ($now - $lastHeartbeat < 5.0) return;
            BrowserState::heartbeat();
            $lastHeartbeat = $now;
        });
        BrowserState::write(['last_error' => null, 'worker_heartbeat' => time()]);
        $this->finishCommand($commandId, [
            'installed' => true,
            'version' => (string) ($runtime['version'] ?? ''),
            'platform' => (string) ($runtime['platform'] ?? ''),
        ]);
    }

    /** Start the private Chrome once; complete only after both CDP transports and a real frame exist. */
    private function startBrowser(int $commandId, array $payload): void
    {
        $state = BrowserState::state();
        if ((int) ($state['profile_reset_required'] ?? 0) === 1) {
            $this->failCommand($commandId, 'BROWSER_PROFILE_RESET_PENDING', '浏览器资料正在清空，请等待操作结束。');
            return;
        }
        if ($state['status'] !== 'stopped') {
            $this->failCommand($commandId, 'BROWSER_ALREADY_RUNNING', '受控浏览器已经启动；更换代理或浏览器前请先停止。');
            return;
        }
        $revisionId = (int) $payload['revision'];
        $revision = NetworkSettings::revision($revisionId);
        // No browser is running here. Resolve through its selected route before launching any page.
        $this->browserTimezoneFailure = null;
        $this->browserTimezoneId = BrowserTimezone::resolve($revision);
        $this->browserTimezone->close();
        $port = (int) Config::get('gateway', 'browser_debug_port');
        $preference = (string) ($payload['browser'] ?? 'auto');
        $profile = (string) Config::get('gateway', 'browser_profile_dir');
        $coldProfile = !is_file(rtrim($profile, '\\/') . '/Local State');
        $this->startupPlan = null;
        $this->startupPageCreation = null;
        $this->startupStartedAt = microtime(true);
        $this->recordStartupStage('preparing_runtime', $commandId);
        BrowserProcess::clearSessionRestore($profile);
        // The first renderer must not depend on ChatGPT/network initialization. Navigate only
        // after the owned browser and page CDP transports can actually control this blank page.
        $launch = BrowserProcess::launch($preference, $port, $revision, 'about:blank');
        $this->ownedProcess = $launch;
        $generation = (int) $state['surface_generation'] + 1;
        BrowserState::write([
            'status' => 'starting', 'browser_kind' => $launch['kind'], 'executable' => $launch['path'],
            'browser_pid' => $launch['pid'], 'debug_port' => $port, 'network_revision' => $revisionId,
            'active_target' => null, 'session_id' => null, 'surface_generation' => $generation,
            'document_nonce' => bin2hex(random_bytes(12)), 'lease_mode' => 'manual', 'url' => '', 'title' => '',
            'tabs_json' => '[]', 'screenshot_sequence' => 0, 'screenshot_mime' => null,
            'last_error' => null, 'owner_identity' => self::identity(), 'launch_nonce' => bin2hex(random_bytes(16)),
            'chatgpt_json' => null, 'chatgpt_observed_at' => 0,
        ]);
        @unlink(self::screenshotPath());
        $this->pendingStartCommand = $commandId;
        $this->startDeadline = microtime(true) + (float) Config::get('gateway', 'browser_start_timeout_seconds');
        $this->startupPageCreation = null;
        $this->startupPlan = ['revision'=>$revisionId, 'browser'=>$preference, 'cold_profile'=>$coldProfile,
            'retry_count'=>0, 'launch_nonce'=>BrowserState::state()['launch_nonce'],
            'launched_at'=>microtime(true), 'last_progress_at'=>microtime(true)];
        $this->recordStartupStage('cdp_discovery');
        $this->tryConnect(BrowserState::state(), true);
    }

    /**
     * Recover one first-profile Chrome that never supplied an initialized page. The original
     * command/deadline/profile survive; only the exact guardian-owned Chrome is stopped. A task,
     * navigation already underway, unknown ownership or an ordinary warm start is never restarted.
     * @return bool True when one cold-start recovery attempt changed the browser state.
     */
    private function recoverColdStartup(array $state): bool
    {
        $plan = $this->startupPlan;
        if ($this->pendingStartCommand === null || !is_array($plan) || empty($plan['cold_profile'])
            || (int) $plan['retry_count'] !== 0
            || microtime(true) - max((float) $plan['launched_at'], (float) ($plan['last_progress_at'] ?? 0)) < self::COLD_PROFILE_IDLE_RETRY_SECONDS
            || microtime(true) >= $this->startDeadline - 20.0 || ($state['status'] ?? '') !== 'starting'
            || (string) ($state['launch_nonce'] ?? '') !== (string) $plan['launch_nonce']
            || (int) ($state['screenshot_sequence'] ?? 0) !== 0 || $this->chatGptTurns !== [] || $this->pendingResumes !== []
            || !in_array($this->startupStage, ['cdp_discovery', 'page_target_wait', 'page_cdp_connect',
                'page_enable', 'viewport', 'cdp_disconnected', 'cdp_initialization_retry'], true)
            || $this->ownedProcess === null || (int) ($this->ownedProcess['pid'] ?? 0) !== (int) ($state['browser_pid'] ?? 0)
            || !OwnedRuntime::alive($this->ownedProcess)) return false;
        $this->startupPlan['retry_count'] = 1;
        $this->recordStartupStage('cold_profile_restart');
        $this->startupRestarting = true;
        $this->switchingTarget = true;
        $this->cdp->close('BROWSER_COLD_START_RETRY');
        $this->taskCdp->close('BROWSER_COLD_START_RETRY');
        $this->connecting = false;
        $this->taskConnecting = false;
        $this->taskDownloadsReady = false;
        $this->startupPageCreation = null;
        try {
            BrowserProcess::terminate($this->ownedProcess, (int) $state['browser_pid'],
                (string) Config::get('gateway', 'browser_profile_dir'), (int) $state['debug_port']);
            $launch = BrowserProcess::launch((string) $plan['browser'], (int) $state['debug_port'],
                NetworkSettings::revision((int) $plan['revision']), 'about:blank');
            $this->ownedProcess = $launch;
            $nonce = bin2hex(random_bytes(16));
            BrowserState::write(['status'=>'starting', 'browser_pid'=>$launch['pid'], 'executable'=>$launch['path'],
                'active_target'=>null, 'session_id'=>null, 'launch_nonce'=>$nonce,
                'surface_generation'=>(int) $state['surface_generation'] + 1, 'document_nonce'=>bin2hex(random_bytes(12)),
                'url'=>'', 'title'=>'', 'tabs_json'=>'[]', 'chatgpt_json'=>null, 'chatgpt_observed_at'=>0, 'last_error'=>null]);
            $this->startupPlan['launch_nonce'] = $nonce;
            $this->startupPlan['launched_at'] = microtime(true);
            $this->recordStartupStage('cdp_discovery');
        } catch (\Throwable $error) {
            $this->recordStartupStage('cold_profile_restart_failed');
            $this->connectionFailed('BROWSER_COLD_START_RETRY_FAILED', ['code'=>0,
                'message'=>get_class($error), 'detail'=>mb_substr($error->getMessage(), 0, 160)]);
        } finally {
            $this->switchingTarget = false;
            $this->startupRestarting = false;
        }
        return true;
    }

    /** Best-effort non-secret stage evidence survives a manual restart and never changes readiness. */
    private function recordStartupStage(string $stage, ?int $commandId = null): void
    {
        if ($this->startupStage === $stage && $commandId === null) return;
        $this->startupStage = $stage;
        if ($this->startupPlan !== null) $this->startupPlan['last_progress_at'] = microtime(true);
        $commandId ??= $this->pendingStartCommand;
        if ($commandId === null) return;
        try {
            $state = BrowserState::state();
            $progress = ['status'=>'starting', 'stage'=>$stage, 'observed_at'=>time(),
                'elapsed_ms'=>$this->startupStartedAt > 0 ? (int) ((microtime(true) - $this->startupStartedAt) * 1000) : 0,
                'browser_pid'=>$state['browser_pid'] ?? null, 'retry_count'=>(int) ($this->startupPlan['retry_count'] ?? 0)];
            BrowserState::startupProgress($commandId, $progress);
            $path = dirname(self::screenshotPath()) . '/startup.log';
            if (!is_dir(dirname($path))) @mkdir(dirname($path), 0700, true);
            @file_put_contents($path, json_encode(['command_id'=>$commandId] + $progress, JSON_UNESCAPED_SLASHES) . PHP_EOL, FILE_APPEND | LOCK_EX);
        } catch (\Throwable) {
            // A diagnostic cannot abort a verified owned browser's initialization.
        }
    }

    /** Verify the exact connector with a no-host-effect round trip in the signed-in setup browser. */
    private function verifyConnector(int $commandId, array $payload): void
    {
        $connectorName = trim((string) ($payload['connector_name'] ?? ''));
        $settings = GatewaySettings::snapshot();
        if ($connectorName === '' || !hash_equals((string) $settings['connector_name'], $connectorName)) {
            $this->failCommand($commandId, 'CHATGPT_CONNECTOR_CONFIG_CHANGED', '连接器设置已经变化，请重新载入设置后再检查。');
            return;
        }
        if (isset($payload['runtime_generation'])) {
            $current = SDB(null, 'medoo', 'sqlite')->table('gateway_connector_state')->where('id', 1)->find();
            if (!hash_equals((string) ($current['runtime_generation'] ?? ''), (string) $payload['runtime_generation'])) {
                $this->failCommand($commandId, 'CHATGPT_CONNECTOR_RUNTIME_CHANGED', '工具连接已变化，旧检查已取消。');
                return;
            }
        }
        $state = BrowserState::state();
        if (!$this->cdp->ready() || (string) ($state['status'] ?? '') !== 'ready') {
            $this->failCommand($commandId, 'BROWSER_NOT_READY', 'ChatGPT 登录浏览器尚未就绪。');
            return;
        }
        if (!$this->taskCdp->ready()) {
            // The browser-level CDP transport is established just after the manual page.
            // Bound deferral so this priority command cannot starve other browser work.
            $since = $this->connectorProbeCdpWaitSince[$commandId] ?? microtime(true);
            $this->connectorProbeCdpWaitSince[$commandId] = $since;
            if (microtime(true) - $since >= 5.0) {
                $this->failCommand($commandId, 'BROWSER_NOT_READY', '独立检查页面的浏览器控制连接未就绪。');
                return;
            }
            try {
                BrowserState::defer($commandId);
                $this->commandBusy = false;
            } catch (\Throwable) {
                $this->failCommand($commandId, 'BROWSER_NOT_READY', '连接器检查未能等待浏览器控制连接。');
            }
            return;
        }
        unset($this->connectorProbeCdpWaitSince[$commandId]);
        $this->connectorProbeGenerations[$commandId] = ConnectorState::runtimeGeneration();
        ConnectorState::invalidate($connectorName, $this->connectorProbeGenerations[$commandId]);
        $this->connectorProbeAttempts[$commandId] = 0;
        $this->taskCdp->send('Target.createTarget', NativeBrowserWindow::backgroundPageParameters(
            ['url' => 'about:blank', 'background' => true], BrowserState::state()), null,
            function ($error, $result) use ($commandId, $connectorName): void {
                $targetId = (string) ($result['targetId'] ?? '');
                if ($error !== null || $targetId === '') {
                    $this->connectorVerifyFail($commandId, $connectorName, 'CHATGPT_CONNECTOR_PAGE_FAILED', '无法创建独立的 ChatGPT 连接器检查页面。');
                    return;
                }
                $this->connectorProbeTargetId = $targetId;
                $this->taskCdp->send('Target.attachToTarget', ['targetId' => $targetId, 'flatten' => true], null,
                    function ($attachError, $attached) use ($commandId, $connectorName): void {
                        $sessionId = (string) ($attached['sessionId'] ?? '');
                        if ($attachError !== null || $sessionId === '') {
                            $this->connectorVerifyFail($commandId, $connectorName, 'CHATGPT_CONNECTOR_PAGE_FAILED', '无法连接独立的连接器检查页面。');
                            return;
                        }
                        $this->connectorProbeSessionId = $sessionId;
                        $this->taskCdp->send('Page.enable', [], $sessionId,
                            function ($enableError) use ($commandId, $connectorName): void {
                                if ($enableError !== null) {
                                    $this->connectorVerifyFail($commandId, $connectorName, 'CHATGPT_CONNECTOR_PAGE_FAILED', '无法初始化独立的连接器检查页面。');
                                    return;
                                }
                                $this->taskCdp->send('Emulation.setFocusEmulationEnabled', ['enabled' => true], $this->connectorProbeSessionId,
                                    function ($focusError) use ($commandId, $connectorName): void {
                                        if ($focusError !== null) {
                                            $this->connectorVerifyFail($commandId, $connectorName, 'CHATGPT_CONNECTOR_PAGE_FAILED', '无法隔离连接器检查页面的输入焦点。');
                                            return;
                                        }
                                        $this->navigateConnectorProbe($commandId, $connectorName);
                                    }, 5.0);
                            }, 5.0);
                    }, 5.0);
            }, 15.0);
    }

    private function navigateConnectorProbe(int $commandId, string $connectorName): void
    {
        $this->taskCdp->send('Page.navigate', ['url' => self::taskModelUrl()], $this->connectorProbeSessionId,
            function ($error) use ($commandId, $connectorName): void {
                // Navigation can commit before a slow/lost CDP ACK. The read-only page check
                // below establishes readiness; never issue a second navigation on this timeout.
                if ($error !== null && ($error['code'] ?? null) !== -2) {
                    $this->connectorVerifyFail($commandId, $connectorName, 'CHATGPT_CONNECTOR_PAGE_FAILED', '无法打开 ChatGPT 连接器检查页面。');
                    return;
                }
                $this->waitConnectorProbePage($commandId, $connectorName, microtime(true) + 20.0);
            }, 15.0);
    }

    private function waitConnectorProbePage(int $commandId, string $connectorName, float $deadline): void
    {
        $this->evaluateConnectorProbe(WebModelPage::capabilityExpression(), function ($error, $capability) use ($commandId, $connectorName, $deadline): void {
            if ($error === null && is_array($capability)) {
                if (!empty($capability['login_required'])) {
                    $this->connectorVerifyFail($commandId, $connectorName, 'CHATGPT_LOGIN_REQUIRED', 'ChatGPT 登录状态已经失效，请重新登录。');
                    return;
                }
                if (!empty($capability['on_chatgpt']) && !empty($capability['editor_ready'])) {
                    $selectionId = 'connector_model_' . $commandId . '_' . bin2hex(random_bytes(6));
                    $this->connectorModelSelections = [];
                    $this->connectorModelSelections[$selectionId] = [
                        'command_id'=>$commandId, 'connector_name'=>$connectorName,
                        'session_id'=>$this->connectorProbeSessionId, 'target_id'=>$this->connectorProbeTargetId,
                    ];
                    $this->prepareTaskModel($selectionId, false);
                    return;
                }
            }
            if (microtime(true) < $deadline) {
                Timer::add(0.2, fn() => $this->waitConnectorProbePage($commandId, $connectorName, $deadline), null, false);
                return;
            }
            $this->connectorVerifyFail($commandId, $connectorName, 'CHATGPT_EDITOR_NOT_READY', 'ChatGPT 对话页面没有在限定时间内准备完成。');
        });
    }

    /** Prepare the exact connector mention; retain safe CDP failure details before any probe is sent. */
    private function beginConnectorProbe(int $commandId, string $connectorName, int $attempt): void
    {
        $this->evaluateConnectorProbe(WebModelPage::focusAndClearExpression(), function ($error, $cleared) use ($commandId, $connectorName, $attempt): void {
            if ($error !== null || !is_array($cleared) || empty($cleared['ok'])) {
                $this->connectorVerifyFail($commandId, $connectorName, 'CHATGPT_EDITOR_CLEAR_FAILED', '检查连接器前无法准备 ChatGPT 输入框。');
                return;
            }
            $this->typeConnectorMention($this->taskCdp, $this->connectorProbeSessionId,
                function ($insertError) use ($commandId, $connectorName, $attempt): void {
                    if ($insertError !== null) {
                        $reason = (string) ($insertError['message'] ?? '');
                        $timedOut = in_array($reason, ['CONNECTOR_MENTION_TIMEOUT', 'BROWSER_CDP_TIMEOUT'], true);
                        $this->connectorVerifyFail($commandId, $connectorName,
                            $timedOut ? 'CHATGPT_CONNECTOR_INPUT_TIMEOUT' : 'CHATGPT_CONNECTOR_TRIGGER_FAILED',
                            $timedOut ? '浏览器未及时响应连接器输入，检查消息尚未发送。' : 'ChatGPT 未接受连接器检查指令。',
                            ['stage'=>'connector_mention', 'cdp_code'=>(int) ($insertError['code'] ?? 0),
                                'cause'=>in_array($reason, ['CONNECTOR_MENTION_TIMEOUT', 'BROWSER_CDP_TIMEOUT',
                                    'BROWSER_CDP_CLOSED', 'BROWSER_CDP_NOT_READY', 'BROWSER_CDP_SEND_FAILED'], true)
                                    ? $reason : 'CDP_PROTOCOL_ERROR',
                                'key_index'=>(int) ($insertError['mention_key_index'] ?? 0)]);
                        return;
                    }
                    $this->waitConnectorProbeMenu($commandId, $connectorName, $attempt, microtime(true) + 2.5);
                }, 5.0, $connectorName);
        });
    }

    private function waitConnectorProbeMenu(int $commandId, string $connectorName, int $attempt, float $deadline): void
    {
        $this->evaluateConnectorProbe(WebModelPage::connectorMenuExpression($connectorName),
            function ($error, $menu) use ($commandId, $connectorName, $attempt, $deadline): void {
                if ($error === null && is_array($menu)) {
                    $count = (int) ($menu['exact_count'] ?? 0);
                    if ($count > 1) {
                        $this->connectorVerifyFail($commandId, $connectorName, 'CHATGPT_CONNECTOR_DUPLICATE', 'ChatGPT 中存在重复的同名连接器，请只保留一个。');
                        return;
                    }
                    if ($count === 1) {
                        $this->activateConnectorProbeChoice($commandId, $connectorName, 0, $attempt);
                        return;
                    }
                }
                if (microtime(true) < $deadline) {
                    Timer::add(0.1, fn() => $this->waitConnectorProbeMenu($commandId, $connectorName, $attempt, $deadline), null, false);
                    return;
                }
                if ($attempt < 3) {
                    $this->beginConnectorProbe($commandId, $connectorName, $attempt + 1);
                    return;
                }
                $this->connectorVerifyFail(
                    $commandId,
                    $connectorName,
                    'CHATGPT_CONNECTOR_UNAVAILABLE',
                    '没有在 ChatGPT 中找到连接器“' . $connectorName . '”。请确认名称、Tunnel 和 Developer Mode 设置。'
                );
            });
    }

    /** Activate only the uniquely identified Connector through its native keyboard owner. */
    private function activateConnectorProbeChoice(int $commandId, string $connectorName, int $step, int $attempt): void
    {
        $this->evaluateConnectorProbe(WebModelPage::connectorMenuExpression($connectorName),
            function ($error, $menu) use ($commandId, $connectorName, $step, $attempt): void {
                if ($error !== null || !is_array($menu) || (int) ($menu['exact_count'] ?? 0) !== 1) {
                    // Nothing was submitted. A transient menu redraw can be retried from
                    // a cleared editor without duplicating a host or MCP operation.
                    if ($attempt < 3 && (int) ($menu['exact_count'] ?? 0) < 2) {
                        $this->beginConnectorProbe($commandId, $connectorName, $attempt + 1);
                    } else {
                        $this->connectorVerifyFail($commandId, $connectorName, 'CHATGPT_CONNECTOR_SELECTION_FAILED', '连接器菜单状态发生变化，无法安全选中。');
                    }
                    return;
                }
                if (!empty($menu['exact_highlighted'])) {
                    $this->dispatchConnectorProbeKey('Enter', function ($keyError) use ($commandId, $connectorName): void {
                        // The renderer may select the App before the CDP key acknowledgement is lost.
                        // Inspect the composer once rather than pressing Enter again or failing early.
                        $this->waitConnectorProbeSelected($commandId, $connectorName, microtime(true) + 3.0);
                    });
                    return;
                }
                $visibleCount = max(1, (int) ($menu['visible_count'] ?? 0));
                if ($step >= $visibleCount) {
                    $this->connectorVerifyFail($commandId, $connectorName, 'CHATGPT_CONNECTOR_SELECTION_FAILED', '连接器菜单无法把键盘高亮移动到目标项。');
                    return;
                }
                $this->evaluateConnectorProbe(WebModelPage::focusComposerExpression(),
                    function ($focusError, $focused) use ($commandId, $connectorName, $step, $attempt): void {
                        if ($focusError !== null || !is_array($focused) || empty($focused['ok'])) {
                            $this->connectorVerifyFail($commandId, $connectorName, 'CHATGPT_CONNECTOR_SELECTION_FAILED', '连接器菜单打开后输入框无法保持键盘焦点。');
                            return;
                        }
                        $this->dispatchConnectorProbeKey('ArrowDown', function ($keyError) use ($commandId, $connectorName, $step, $attempt): void {
                            if ($keyError !== null) {
                                $this->connectorVerifyFail($commandId, $connectorName, 'CHATGPT_CONNECTOR_SELECTION_FAILED', '连接器菜单键盘导航未完成。');
                                return;
                            }
                            Timer::add(0.05, fn() => $this->activateConnectorProbeChoice($commandId, $connectorName, $step + 1, $attempt), null, false);
                        });
                    });
            });
    }

    private function waitConnectorProbeSelected(int $commandId, string $connectorName, float $deadline): void
    {
        $this->evaluateConnectorProbe(WebModelPage::connectorSelectedExpression($connectorName),
            function ($error, $selected) use ($commandId, $connectorName, $deadline): void {
                if ($error === null && is_array($selected) && (int) ($selected['count'] ?? 0) > 1) {
                    $this->connectorVerifyFail($commandId, $connectorName, 'CHATGPT_CONNECTOR_DUPLICATE', 'ChatGPT 中存在重复的同名连接器。');
                    return;
                }
                if ($error === null && is_array($selected) && !empty($selected['selected'])) {
                    $this->beginConnectorRuntimeProbe($commandId, $connectorName);
                    return;
                }
                if (microtime(true) < $deadline) {
                    Timer::add(0.1, fn() => $this->waitConnectorProbeSelected($commandId, $connectorName, $deadline), null, false);
                    return;
                }
                $this->connectorVerifyFail($commandId, $connectorName, 'CHATGPT_CONNECTOR_SELECTION_FAILED', 'ChatGPT 没有确认连接器已经选中。');
            });
    }

    /** Prove the selected ChatGPT Connector reaches this exact local MCP before declaring setup ready. */
    private function beginConnectorRuntimeProbe(int $commandId, string $connectorName): void
    {
        $attempt = (int) ($this->connectorProbeAttempts[$commandId] ?? 0) + 1;
        $this->connectorProbeAttempts[$commandId] = $attempt;
        try {
            $token = ConnectorState::beginProbe($connectorName, $this->connectorProbeGenerations[$commandId] ?? null);
        } catch (GatewayError $error) {
            $this->closeConnectorProbeSurface();
            $this->failCommand($commandId, $error->errorCode, $error->getMessage());
            return;
        }
        $prompt = 'RCMAKER connection readiness check. Use the already selected RCMAKER Connector and call codex_exec exactly once with turn_token '
            . $token
            . ', cmd RCMAKER_PERMISSION_PROBE, and omit all optional arguments. Do not call any other Connector tool. '
            . 'The local MCP intercepts this exact probe before host execution, so it has no host side effect. '
            . 'After codex_exec returns successfully, reply exactly RCMAKER_CONNECTOR_READY.';
        $this->evaluateConnectorProbe(WebModelPage::insertPlainTextExpression(' ' . $prompt),
            function ($insertError, $inserted) use ($commandId, $connectorName, $token, $prompt): void {
                if ($insertError !== null || !is_array($inserted) || empty($inserted['ok'])) {
                    $this->connectorVerifyFail($commandId, $connectorName, 'CHATGPT_CONNECTOR_PROBE_INPUT_FAILED', '连接器已选中，但运行时探针无法写入 ChatGPT。');
                    return;
                }
                $this->evaluateConnectorProbe(WebModelPage::promptTextExpression(),
                    function ($readError, $draft) use ($commandId, $connectorName, $token, $prompt): void {
                        if ($readError !== null || !is_array($draft) || empty($draft['ok']) || (string) ($draft['text'] ?? '') !== $prompt) {
                            $this->connectorVerifyFail($commandId, $connectorName, 'CHATGPT_CONNECTOR_PROBE_VERIFY_FAILED', '连接器运行时探针写入后内容不一致，已停止发送。');
                            return;
                        }
                        $this->evaluateConnectorProbe(WebModelPage::connectorSelectedExpression($connectorName),
                            function ($connectorError, $selected) use ($commandId, $connectorName, $token): void {
                                if ($connectorError !== null || !is_array($selected) || empty($selected['selected']) || (int) ($selected['count'] ?? 0) !== 1) {
                                    $this->connectorVerifyFail($commandId, $connectorName, 'CHATGPT_CONNECTOR_SELECTION_LOST', '写入运行时探针后连接器选择状态发生变化。');
                                    return;
                                }
                                $this->waitConnectorProbeSendReady($commandId, $connectorName, $token, microtime(true) + 3.0);
                            });
                    });
            });
    }

    private function waitConnectorProbeSendReady(int $commandId, string $connectorName, string $token, float $deadline, string $lastReadinessCode = ''): void
    {
        $this->evaluateConnectorProbe(WebModelPage::sendReadinessExpression(),
            function ($error, $state) use ($commandId, $connectorName, $token, $deadline, $lastReadinessCode): void {
                if ($error === null && is_array($state) && !empty($state['ready'])) {
                    $this->verifyConnectorProbeModel($commandId, $connectorName, function () use ($commandId, $connectorName, $token): void {
                    $this->evaluateConnectorProbe(WebModelPage::focusSendExpression(),
                        function ($focusError, $focused) use ($commandId, $connectorName, $token): void {
                            if ($focusError !== null || !is_array($focused) || empty($focused['ok'])) {
                                $this->connectorVerifyFail($commandId, $connectorName, 'CHATGPT_CONNECTOR_PROBE_SEND_FAILED', '连接器运行时探针无法激活发送按钮。');
                                return;
                            }
                            \app\gateway\model\GatewayUsage::probeIntent($token, (array) ($this->connectorProbeModes[$commandId] ?? []));
                            $this->dispatchConnectorProbeKey('Enter', function ($keyError) use ($commandId, $connectorName, $token): void {
                                if ($keyError !== null) {
                                    $this->connectorVerifyFail($commandId, $connectorName, 'CHATGPT_CONNECTOR_PROBE_SEND_FAILED', '连接器运行时探针未能发送。');
                                    return;
                                }
                                $this->waitConnectorRuntimeProbe(
                                    $commandId,
                                    $connectorName,
                                    $token,
                                    microtime(true) + ConnectorState::probeTimeoutSeconds()
                                );
                            });
                        });
                    });
                    return;
                }
                $code = is_array($state) && in_array($state['code'] ?? null,
                    ['CHATGPT_EDITOR_MISSING', 'CHATGPT_SEND_BUTTON_MISSING', 'CHATGPT_SEND_BUTTON_AMBIGUOUS', 'CHATGPT_SEND_DISABLED'], true)
                    ? (string) $state['code'] : $lastReadinessCode;
                if (microtime(true) < $deadline) {
                    Timer::add(0.08, fn() => $this->waitConnectorProbeSendReady($commandId, $connectorName, $token, $deadline, $code), null, false);
                    return;
                }
                $this->connectorVerifyFail($commandId, $connectorName, 'CHATGPT_CONNECTOR_PROBE_SEND_FAILED',
                    'ChatGPT 发送控件没有及时进入可用状态' . ($code !== '' ? '（' . $code . '）' : '') . '。');
            });
    }

    private function waitConnectorRuntimeProbe(int $commandId, string $connectorName, string $token, float $deadline): void
    {
        try {
            $probe = ConnectorState::probeStatus($connectorName, $token);
        } catch (GatewayError $error) {
            $this->closeConnectorProbeSurface();
            $this->failCommand($commandId, $error->errorCode, $error->getMessage());
            return;
        }
        if (!empty($probe['accepted'])) {
            $this->finishConnectorRuntimeProbe($commandId, $connectorName);
            return;
        }
        if (empty($probe['expired']) && microtime(true) < $deadline) {
            $this->resolveConnectorProbeConfirmation($commandId, $connectorName, $token, $deadline);
            return;
        }
        $attempt = (int) ($this->connectorProbeAttempts[$commandId] ?? 1);
        if (empty($probe['started'])
            && empty($probe['expired'])
            && $attempt < self::CONNECTOR_RUNTIME_PROBE_MAX_RETRIES) {
            $this->retryConnectorRuntimeProbe($commandId, $connectorName, $attempt + 1);
            return;
        }
        $message = !empty($probe['started'])
            ? 'ChatGPT 已经到达本机 MCP，但命令类 action 探针没有完成；为避免重复执行，本次不自动重发。请重试检查连接器。'
            : 'ChatGPT 能找到该连接器，但连续多次都没有启动命令类 action。请确认连接器选择的是当前 Tunnel、Authentication 为 None，并重试检查。';
        $this->connectorVerifyFail(
            $commandId,
            $connectorName,
            !empty($probe['started']) ? 'CHATGPT_CONNECTOR_ACTION_UNAVAILABLE' : 'CHATGPT_CONNECTOR_ACTION_NOT_STARTED',
            $message
        );
    }

    /**
     * A probe that never reached MCP has no local side effect. Invalidate its token first, then
     * retry from a fresh Temporary Chat so the old completed page cannot claim the next attempt.
     */
    private function retryConnectorRuntimeProbe(int $commandId, string $connectorName, int $attempt): void
    {
        try { ConnectorState::invalidate($connectorName, $this->connectorProbeGenerations[$commandId] ?? null); }
        catch (GatewayError $error) {
            $this->closeConnectorProbeSurface();
            $this->failCommand($commandId, $error->errorCode, $error->getMessage());
            return;
        }
        $this->taskCdp->send('Page.navigate', ['url' => self::taskModelUrl()], $this->connectorProbeSessionId,
            function ($error) use ($commandId, $connectorName, $attempt): void {
                if ($error !== null && ($error['code'] ?? null) !== -2) {
                    $this->connectorVerifyFail($commandId, $connectorName, 'CHATGPT_CONNECTOR_RETRY_PAGE_FAILED', '连接器探针安全重试时无法打开新的 Temporary Chat。');
                    return;
                }
                if ((int) ($this->connectorProbeAttempts[$commandId] ?? 0) + 1 !== $attempt) {
                    $this->connectorVerifyFail($commandId, $connectorName, 'CHATGPT_CONNECTOR_RETRY_STATE_INVALID', '连接器探针重试序号发生变化，已停止自动操作。');
                    return;
                }
                $this->waitConnectorProbePage($commandId, $connectorName, microtime(true) + 20.0);
            }, 15.0);
    }

    /** Resolve only the current-action ChatGPT confirmation for this exact Connector probe. */
    private function resolveConnectorProbeConfirmation(int $commandId, string $connectorName, string $token, float $deadline): void
    {
        $this->evaluateConnectorProbe(WebModelPage::toolConfirmationExpression($connectorName),
            function ($error, $confirmation) use ($commandId, $connectorName, $token, $deadline): void {
                if ($error !== null || !is_array($confirmation) || empty($confirmation['visible'])) {
                    Timer::add(0.25, fn() => $this->waitConnectorRuntimeProbe($commandId, $connectorName, $token, $deadline), null, false);
                    return;
                }
                if ((int) ($confirmation['dialog_count'] ?? 0) !== 1 || (int) ($confirmation['one_shot_count'] ?? 0) !== 1) {
                    $this->connectorVerifyFail($commandId, $connectorName, 'CHATGPT_TOOL_CONFIRMATION_UNSAFE', 'ChatGPT 工具确认窗口无法唯一识别一次性 Allow 操作，已停止自动操作。');
                    return;
                }
                $settings = GatewaySettings::internal();
                if (empty($settings['auto_approve_tool_calls'])) {
                    $this->connectorVerifyFail($commandId, $connectorName, 'CHATGPT_TOOL_CONFIRMATION_REQUIRED', 'ChatGPT 要求一次性工具确认。请在“设置”中开启自动确认一次性工具调用后重新检查。');
                    return;
                }
                $this->evaluateConnectorProbe(WebModelPage::focusToolConfirmationExpression($connectorName),
                    function ($focusError, $focused) use ($commandId, $connectorName, $token, $deadline): void {
                        if ($focusError !== null || !is_array($focused) || empty($focused['ok'])) {
                            $this->connectorVerifyFail($commandId, $connectorName, 'CHATGPT_TOOL_CONFIRMATION_UNSAFE', 'ChatGPT 一次性工具确认按钮无法安全获得焦点。');
                            return;
                        }
                        $this->dispatchConnectorProbeKey('Enter', function ($keyError) use ($commandId, $connectorName, $token, $deadline): void {
                            if ($keyError !== null) {
                                $this->connectorVerifyFail($commandId, $connectorName, 'CHATGPT_TOOL_CONFIRMATION_FAILED', 'ChatGPT 一次性工具确认未完成。');
                                return;
                            }
                            Timer::add(0.25, fn() => $this->waitConnectorRuntimeProbe($commandId, $connectorName, $token, $deadline), null, false);
                        });
                    });
            });
    }

    private function finishConnectorRuntimeProbe(int $commandId, string $connectorName): void
    {
        $this->closeConnectorProbeSurface();
        $this->finishCommand($commandId, [
            'verified' => true,
            'runtime_verified' => true,
            'connector_name' => $connectorName,
        ]);
    }

    /** Finish a connector probe with optional allow-listed diagnostics; only safe first automatic failures retry. */
    private function connectorVerifyFail(int $commandId, string $connectorName, string $code, string $message, array $diagnostics = []): void
    {
        $generation = $this->connectorProbeGenerations[$commandId] ?? null;
        // A first automatic check that never reached the local MCP can retry
        // once on a fresh page. Manual checks and later attempts remain terminal.
        if (!ConnectorState::retryableRuntimeCheckFailure($commandId, $code)) {
            try { ConnectorState::failed($connectorName, $message, $generation); } catch (\Throwable) {}
        }
        $this->closeConnectorProbeSurface();
        $this->failCommand($commandId, $code, $message, $diagnostics === [] ? [] : ['diagnostics'=>$diagnostics]);
    }

    /** Close only the probe's background tab, leaving the user's active tab and URL untouched. */
    private function closeConnectorProbeSurface(): void
    {
        $this->connectorModelSelections = [];
        $this->connectorProbeModes = [];
        $targetId = $this->connectorProbeTargetId;
        $this->connectorProbeTargetId = null;
        $this->connectorProbeSessionId = null;
        if ($targetId !== null && $this->taskCdp->ready()) {
            $this->taskCdp->send('Target.closeTarget', ['targetId' => $targetId], null,
                function (): void { $this->refreshTabs(); }, 5.0);
            return;
        }
        if ($targetId !== null) {
            $port = (int) (BrowserState::state()['debug_port'] ?? 0);
            if ($port > 0) $this->devtoolsRequest($port, '/json/close/' . rawurlencode($targetId));
        }
        $this->refreshTabs();
    }

    /** Evaluate only within the isolated probe session. */
    private function evaluateConnectorProbe(string $expression, callable $callback, float $timeout = 3.0): void
    {
        $sessionId = $this->connectorProbeSessionId;
        if ($sessionId === null || !$this->taskCdp->ready()) {
            $callback(['code' => -1, 'message' => 'CONNECTOR_PROBE_SURFACE_MISSING'], null);
            return;
        }
        $this->taskCdp->send('Runtime.evaluate', ['expression' => $expression, 'returnByValue' => true], $sessionId,
            static function ($error, $result) use ($callback): void {
                $callback(is_array($error) ? $error : null, $result['result']['value'] ?? null);
            }, $timeout);
    }

    /** Send one keyboard event only to the isolated probe session. */
    private function dispatchConnectorProbeKey(string $key, callable $callback): void
    {
        $sessionId = $this->connectorProbeSessionId;
        if ($sessionId === null || !$this->taskCdp->ready()) {
            $callback(['code' => -1, 'message' => 'CONNECTOR_PROBE_SURFACE_MISSING']);
            return;
        }
        $this->dispatchCdpKey($this->taskCdp, $sessionId, $key, $callback);
    }

    /** Recheck the exact non-Pro mode after composing the probe, before its irreversible send. */
    private function verifyConnectorProbeModel(int $commandId, string $connectorName, callable $after): void
    {
        $mode = $this->connectorProbeModes[$commandId] ?? null;
        if (!is_array($mode) || ($mode['effort'] ?? null) !== 'medium') {
            $this->connectorVerifyFail($commandId, $connectorName, 'CHATGPT_MODEL_SELECTION_UNPROVEN',
                '连接检测没有已验证的非 Pro 中档选择，未发送。');
            return;
        }
        $selectionId = 'connector_model_' . $commandId . '_' . bin2hex(random_bytes(6));
        $this->connectorModelSelections = [$selectionId=>[
            'command_id'=>$commandId, 'connector_name'=>$connectorName,
            'session_id'=>$this->connectorProbeSessionId, 'target_id'=>$this->connectorProbeTargetId,
        ]];
        $this->verifyModelSelection($selectionId, $mode, function () use ($selectionId, $commandId, $connectorName, $after): void {
            if (!$this->modelSelectionActive($selectionId)) return;
            unset($this->connectorModelSelections[$selectionId]);
            $this->evaluateConnectorProbe(WebModelPage::connectorSelectedExpression($connectorName),
                function ($error, $selected) use ($commandId, $connectorName, $after): void {
                    if ($error !== null || !is_array($selected) || empty($selected['selected'])
                        || (int) ($selected['count'] ?? 0) > 1) {
                        $this->connectorVerifyFail($commandId, $connectorName, 'CHATGPT_CONNECTOR_SELECTION_FAILED',
                            'ChatGPT 没有确认连接器已经选中。');
                        return;
                    }
                    $after();
                });
        });
    }

    /** Existing picker operations share one implementation, with task and probe ownership kept separate. */
    private function modelSelectionActive(string $selectionId): bool
    {
        if (!str_starts_with($selectionId, 'connector_model_')) return isset($this->chatGptTurns[$selectionId]);
        $owner = $this->connectorModelSelections[$selectionId] ?? null;
        return is_array($owner) && $owner['session_id'] !== null
            && $owner['session_id'] === $this->connectorProbeSessionId
            && $owner['target_id'] === $this->connectorProbeTargetId;
    }

    /** Route a picker read only to its owned session; stale probe callbacks cannot reach a task tab. */
    private function evaluateModelSelection(string $selectionId, string $expression, callable $callback, float $timeout = 3.0): void
    {
        if (!str_starts_with($selectionId, 'connector_model_')) {
            $this->evaluateTask($selectionId, $expression, $callback, $timeout);
            return;
        }
        if (!$this->modelSelectionActive($selectionId)) return;
        $this->evaluateConnectorProbe($expression, function ($error, $result) use ($selectionId, $callback): void {
            if ($this->modelSelectionActive($selectionId)) $callback($error, $result);
        }, $timeout);
    }

    /** Dispatch picker keys through the same native path, fenced to the task or readiness surface. */
    private function dispatchModelSelectionKey(string $selectionId, string $key, callable $callback): void
    {
        if (!str_starts_with($selectionId, 'connector_model_')) {
            $this->dispatchTaskKey($selectionId, $key, $callback);
            return;
        }
        if (!$this->modelSelectionActive($selectionId)) return;
        $this->dispatchConnectorProbeKey($key, function ($error) use ($selectionId, $callback): void {
            if ($this->modelSelectionActive($selectionId)) $callback($error);
        });
    }

    /** A failed non-Pro selection fails only its readiness command and sends no probe. */
    private function failModelSelection(string $selectionId, string $code, string $message, bool $recover = false): void
    {
        if (!str_starts_with($selectionId, 'connector_model_')) {
            $this->taskFailTurn($selectionId, $code, $message, $recover);
            return;
        }
        if (!$this->modelSelectionActive($selectionId)) return;
        $owner = $this->connectorModelSelections[$selectionId];
        $this->connectorVerifyFail((int) $owner['command_id'], (string) $owner['connector_name'], $code, $message);
    }

    /** Best-effort account identity after an idle model probe; never changes task submission or model choice. */
    private function observeUsageAccount(): void
    {
        if (!$this->cdp->ready()) return;
        $expression = <<<'JS'
(async()=>{
  if(location.origin!=='https://chatgpt.com')return null;
  try{
    const response=await fetch('/api/auth/session',{credentials:'same-origin',cache:'no-store',redirect:'error',signal:AbortSignal.timeout(3000)});
    const url=new URL(response.url);
    if(response.status===401)return {authenticated:false};
    if(!response.ok||url.origin!==location.origin||url.pathname!=='/api/auth/session')return null;
    const session=await response.json();
    if(session.error||!session.user?.id||(session.expires&&Date.parse(session.expires)<=Date.now()))return {authenticated:false};
    const id=session.user?.id,account=session.account?.id,plan=session.account?.planType;
    if([id,account,plan].some(value=>typeof value!=='string'||!value||value.length>256))return null;
    const hash=await crypto.subtle.digest('SHA-256',new TextEncoder().encode(id+'\0'+account));
    return {authenticated:true,account_key:[...new Uint8Array(hash)].map(value=>value.toString(16).padStart(2,'0')).join(''),plan_type:plan};
  }catch(_){return null;}
})()
JS;
        $this->cdp->send('Runtime.evaluate', ['expression' => $expression, 'returnByValue' => true, 'awaitPromise' => true], null,
            static function ($error, $result): void {
                $value = $result['result']['value'] ?? null;
                if ($error === null && is_array($value)) {
                    try { \app\gateway\model\GatewayUsage::observeAccount($value); } catch (\Throwable) {}
                    try {
                        if (($value['authenticated'] ?? null) === false) \app\gateway\model\BrowserLoginLifecycle::observeWebLogout();
                        else \app\gateway\model\BrowserLoginLifecycle::observeWebLogin((string) ($value['account_key'] ?? ''));
                    } catch (\Throwable) {}
                }
            }, 4.0);
    }

    /** @param callable(?array,mixed):void $callback */
    private function evaluateBrowser(string $expression, callable $callback, float $timeout = 3.0): void
    {
        if (!$this->cdp->ready()) {
            $callback(['code' => -1, 'message' => 'BROWSER_CDP_DISCONNECTED'], null);
            return;
        }
        $this->cdp->send('Runtime.evaluate', ['expression' => $expression, 'returnByValue' => true], null,
            function ($error, $result) use ($callback): void {
                $callback(is_array($error) ? $error : null, $result['result']['value'] ?? null);
            }, $timeout);
    }

    /** Stop only the owned private Chrome; a restart retains this command until its new first frame. */
    private function stopBrowser(int $commandId, bool $finish = true): void
    {
        $state = BrowserState::state();
        $port = (int) ($state['debug_port'] ?? 0);
        $version = $port > 0 ? $this->devtoolsRequest($port, '/json/version') : null;
        $browserSocket = is_array($version) ? (string) ($version['webSocketDebuggerUrl'] ?? '') : '';
        $this->stopping = true;
        $this->cdp->close('BROWSER_STOPPING');
        if (!$finish) $this->taskCdp->close('BROWSER_RESTARTING');
        $this->browserTimezone->close();
        try {
            $profile = (string) Config::get('gateway', 'browser_profile_dir');
            if ((int) ($state['browser_pid'] ?? 0) > 0) {
                BrowserProcess::terminate($this->ownedProcess, (int) $state['browser_pid'], $profile, $port, $browserSocket);
            }
            BrowserProcess::clearSessionRestore($profile);
        } finally {
            $this->stopping = false;
        }
        @unlink(self::screenshotPath());
        $this->clearMonitorScreenshots();
        BrowserState::write([
            'status' => 'stopped', 'browser_pid' => null, 'debug_port' => null, 'network_revision' => null,
            'active_target' => null, 'session_id' => null, 'url' => '', 'title' => '', 'tabs_json' => '[]',
            'document_nonce' => bin2hex(random_bytes(12)), 'surface_generation' => (int) $state['surface_generation'] + 1,
            'screenshot_sequence' => 0, 'screenshot_mime' => null, 'last_error' => null,
        ]);
        if ($finish) $this->finishCommand($commandId, ['status' => 'stopped']);
    }

    /** Reinitialize an idle private browser with the chosen network revision, preserving its login profile. */
    private function restartBrowser(int $commandId, array $payload): void
    {
        BrowserState::assertRestartIdle($commandId);
        if ($this->chatGptTurns !== [] || $this->pendingResumes !== []) {
            throw new GatewayError('BROWSER_RESTART_BUSY', '仍有浏览器操作或网页任务在运行，设置已保存，请等待完成后再应用。', 409);
        }
        NetworkSettings::usable(NetworkSettings::revision((int) $payload['revision']));
        $this->stopBrowser($commandId, false);
        $this->taskSessions = [];
        $this->taskDownloads = [];
        $this->taskDownloadsReady = false;
        $this->connectorProbeTargetId = null;
        $this->connectorProbeSessionId = null;
        $this->connectorProbeAttempts = [];
        $this->connectorProbeGenerations = [];
        $this->connectorProbeCdpWaitSince = [];
        $this->startBrowser($commandId, $payload);
    }

    /** Stop the owned Chrome, erase all application-owned login profiles, and require a new manual sign-in. */
    private function resetProfile(int $commandId): void
    {
        BrowserState::assertProfileResetIdle($commandId);
        $state = BrowserState::state();
        $pid = (int) ($state['browser_pid'] ?? 0);
        $port = (int) ($state['debug_port'] ?? 0);
        $version = $port > 0 ? $this->devtoolsRequest($port, '/json/version') : null;
        $browserSocket = is_array($version) ? (string) ($version['webSocketDebuggerUrl'] ?? '') : '';
        $this->stopping = true;
        $this->cdp->close('BROWSER_PROFILE_RESET');
        $this->taskCdp->close('BROWSER_PROFILE_RESET');
        try {
            if ($pid > 0) {
                BrowserProcess::terminate($this->ownedProcess, $pid,
                    (string) Config::get('gateway', 'browser_profile_dir'), $port, $browserSocket);
                if (PHP_OS_FAMILY === 'Windows' && BrowserProcess::hasWindowsLoopbackListener($pid, $port) !== false) {
                    throw new GatewayError('BROWSER_PROFILE_BUSY', '项目私有浏览器尚未完全退出，未清空登录资料。', 409);
                }
                if (PHP_OS_FAMILY === 'Linux' && BrowserProcess::ownershipConfirmed($pid,
                    (string) Config::get('gateway', 'browser_profile_dir'), $port)) {
                    throw new GatewayError('BROWSER_PROFILE_BUSY', '项目私有浏览器尚未完全退出，未清空登录资料。', 409);
                }
            }
        } finally {
            $this->stopping = false;
        }
        if (PHP_OS_FAMILY === 'Windows') {
            $runtimeDirectory = (string) Config::get('gateway', 'browser_runtime_dir');
            if (is_dir($runtimeDirectory)) {
                try {
                    OwnedRuntime::cleanWindows($runtimeDirectory, 'chrome');
                } catch (\Throwable) {
                    throw new GatewayError('BROWSER_PROFILE_BUSY', '项目私有浏览器尚未完全退出，未清空登录资料。', 409);
                }
            }
        }
        $this->ownedProcess = null;
        $this->chatGptTurns = [];
        $this->taskSessions = [];
        $this->pendingResumes = [];
        $this->taskDownloads = [];
        $this->taskDownloadsReady = false;
        $this->connectorProbeTargetId = null;
        $this->connectorProbeSessionId = null;
        $this->connectorProbeAttempts = [];
        $this->connectorProbeGenerations = [];
        $this->connectorProbeCdpWaitSince = [];
        @unlink(self::screenshotPath());
        $this->clearMonitorScreenshots();
        BrowserState::write([
            'status' => 'stopped', 'browser_pid' => null, 'debug_port' => null, 'network_revision' => null,
            'active_target' => null, 'session_id' => null, 'url' => '', 'title' => '', 'tabs_json' => '[]',
            'document_nonce' => bin2hex(random_bytes(12)),
            'surface_generation' => (int) $state['surface_generation'] + 1,
            'lease_mode' => 'manual', 'auto_start' => 0, 'setup_completed' => 0,
            'screenshot_sequence' => 0, 'screenshot_mime' => null, 'last_error' => null,
            'chatgpt_json' => null, 'chatgpt_observed_at' => 0,
        ]);
        \app\gateway\model\ModelCatalog::resetAfterProfileClear();
        ConnectorState::resetAfterProfileClear((string) GatewaySettings::snapshot()['connector_name']);
        $removed = BrowserProcess::purgePrivateProfileStorage();
        if (BrowserProcess::purgePrivateProfileStorage() !== ['directories' => 0, 'files' => 0]) {
            throw new GatewayError('BROWSER_PROFILE_PURGE_FAILED', '浏览器资料未能全部清空；请重试。', 503);
        }
        Schema::transaction(function () use ($commandId, $removed): void {
            $command = SDB(null, 'medoo', 'sqlite')->table('gateway_browser_commands')
                ->where('id', $commandId)->where('action', 'reset_profile')->where('status', 'running')->find();
            if (!is_array($command)) {
                throw new GatewayError('BROWSER_STATE_UNAVAILABLE', '浏览器清理命令状态已变化，仍需重试清空。', 503);
            }
            BrowserState::write(['profile_reset_required' => 0]);
            \app\gateway\model\BrowserLoginLifecycle::profileClearedUnlocked();
            BrowserState::finish($commandId, true, ['status' => 'cleared', 'removed' => $removed]);
        });
        $this->commandBusy = false;
    }

    /**
     * Reconcile Unix hard-exit state without authorizing a process kill or replaying a submitted task.
     * @param array $state Current durable browser state, protected by the browser worker ownership lock.
     * @return array Updated state only when the PID is certainly dead and the CDP listener is certainly absent.
     * @throws \Throwable When cleanup of this worker's verified guardian or durable state fails.
     */
    private function reconcileExitedUnixBrowser(array $state): array
    {
        $pid = (int) ($state['browser_pid'] ?? 0);
        $port = (int) ($state['debug_port'] ?? 0);
        if (BrowserProcess::unixProcessRunning($pid) !== false
            || BrowserProcess::unixLoopbackListenerPresent($port) !== false) return $state;
        if ($this->ownedProcess !== null) {
            $profile = (string) Config::get('gateway', 'browser_profile_dir');
            if ((int) ($this->ownedProcess['pid'] ?? 0) !== $pid
                || (int) ($this->ownedProcess['debug_port'] ?? 0) !== $port
                || !BrowserProcess::sameProfile((string) ($this->ownedProcess['profile'] ?? ''), $profile)) return $state;
            // The live handle belongs to this exact launch. Wait for its guardian to finish reclaiming descendants.
            OwnedRuntime::stop($this->ownedProcess);
        }
        $this->forgetExitedBrowser($state);
        return BrowserState::state();
    }

    /** Clear stale runtime state after the exact recorded browser/CDP listener has disappeared. */
    private function forgetExitedBrowser(array $state): void
    {
        if ($this->ownedProcess !== null && isset($this->ownedProcess['process']) && is_resource($this->ownedProcess['process'])) {
            $status = proc_get_status($this->ownedProcess['process']);
            if (!$status['running']) @proc_close($this->ownedProcess['process']);
        }
        $this->ownedProcess = null;
        $this->cdp->close('BROWSER_PROCESS_EXITED');
        $this->browserTimezone->close();
        @unlink(self::screenshotPath());
        $this->clearMonitorScreenshots();
        try { BrowserProcess::clearSessionRestore((string) Config::get('gateway', 'browser_profile_dir')); } catch (\Throwable) {}
        BrowserState::write([
            'status' => 'stopped', 'browser_pid' => null, 'debug_port' => null, 'network_revision' => null,
            'active_target' => null, 'session_id' => null, 'url' => '', 'title' => '', 'tabs_json' => '[]',
            'document_nonce' => bin2hex(random_bytes(12)), 'surface_generation' => (int) $state['surface_generation'] + 1,
            'screenshot_sequence' => 0, 'screenshot_mime' => null, 'last_error' => null,
        ]);
    }

    private function tryConnect(array $state, bool $immediate = false): void
    {
        if ($this->connecting || $this->cdp->ready()) return;
        $now = microtime(true);
        if (!$immediate && $now - $this->lastDiscovery < 0.4) return;
        $this->lastDiscovery = $now;
        $port = (int) ($state['debug_port'] ?? 0);
        if ($port < 1) return;
        $targets = $this->discoverTargetsIfAvailable($port);
        if ($targets === null) return;
        if ($targets === [] && $this->pendingStartCommand === null) return;
        $profile = (string) Config::get('gateway', 'browser_profile_dir');
        $pid = (int) ($state['browser_pid'] ?? 0);
        $ownedHere = $this->ownedProcess !== null
            && (int) ($this->ownedProcess['pid'] ?? 0) === $pid
            && (int) ($this->ownedProcess['debug_port'] ?? 0) === $port
            && BrowserProcess::sameProfile((string) ($this->ownedProcess['profile'] ?? ''), $profile);
        $version = $this->devtoolsRequest($port, '/json/version');
        $browserSocket = is_array($version) ? (string) ($version['webSocketDebuggerUrl'] ?? '') : '';
        if ($browserSocket === '' && $this->pendingStartCommand !== null && $ownedHere) return;
        // After a FileMonitor/worker reload the proc_open handle is gone. On Windows, querying
        // Win32_Process command lines can be denied (and can block for tens of seconds), while the
        // product-owned profile + exact loopback DevTools endpoint + listener PID are already
        // sufficient recovery evidence. Prefer that bounded evidence before the command-line
        // fallback so a healthy private browser cannot age past the startup deadline.
        $ownershipConfirmed = $ownedHere
            || ($browserSocket !== '' && BrowserProcess::recoveredOwnershipConfirmed($pid, $profile, $port, $browserSocket))
            || BrowserProcess::ownershipConfirmed($pid, $profile, $port);
        if (!$ownershipConfirmed || $browserSocket === '' || !BrowserProcess::endpointMatches($profile, $port, $browserSocket)) {
            try { BrowserState::write(['status' => 'error', 'last_error' => 'BROWSER_OWNERSHIP_UNCONFIRMED']); } catch (\Throwable) {}
            return;
        }
        if (!$this->ensureBrowserTimezone($state, $browserSocket)) return;
        if ($targets === []) {
            if ($this->pendingStartCommand !== null && $ownedHere) {
                $this->recordStartupStage('page_target_wait');
                // A fresh profile may expose browser CDP before any page exists. Waiting for
                // page CDP before connecting browser CDP makes that state impossible to recover.
                $this->ensureTaskCdp($state);
                if ($this->discoverPhysicalPageIdsIfAvailable($port) === []) {
                    $this->createStartupPage($state, $browserSocket);
                }
            }
            return;
        }
        $targetId = (string) ($state['active_target'] ?? '');
        $startupTarget = (string) ($this->startupPageCreation['target_id'] ?? '');
        if ($targetId === '' && $startupTarget !== '' && isset($targets[$startupTarget])) $targetId = $startupTarget;
        if ($targetId === '' || !isset($targets[$targetId])) $targetId = self::preferredTargetId($targets, self::modelUrl());
        $this->connectTarget($targetId, null, $targets);
    }

    /**
     * Create one blank setup page only after an authoritative empty page inventory and exact
     * launch ownership were proved by tryConnect(). Timeout keeps the intent: subsequent discovery
     * can adopt a page created despite a lost reply, but must never create another setup tab.
     */
    private function createStartupPage(array $state, string $browserSocket): void
    {
        if ($this->pendingStartCommand === null || $this->startupPageCreation !== null || !$this->taskCdp->ready()
            || $this->taskBrowserSocket !== $browserSocket) return;
        $commandId = $this->pendingStartCommand;
        $nonce = (string) $state['launch_nonce'];
        $this->startupPageCreation = ['command_id'=>$commandId, 'launch_nonce'=>$nonce];
        $this->recordStartupStage('page_target_wait');
        $this->taskCdp->send('Target.createTarget', ['url'=>'about:blank'], null,
            function ($error, $result) use ($commandId, $nonce): void {
                $current = BrowserState::state();
                if ($this->pendingStartCommand !== $commandId || (string) ($current['launch_nonce'] ?? '') !== $nonce
                    || ($current['status'] ?? '') !== 'starting') return;
                if ($error !== null && (int) ($error['code'] ?? 0) !== -2) {
                    $this->connectionFailed('BROWSER_SETUP_PAGE_FAILED', is_array($error) ? $error : null);
                    return;
                }
                if ($error === null && is_string($result['targetId'] ?? null) && $result['targetId'] !== '') {
                    $this->startupPageCreation['target_id'] = $result['targetId'];
                }
                // The next normal tick discovers/attaches the real page. No second create,
                // navigation, login inference or readiness publication is performed here.
            }, 10.0);
    }

    /** Prefer the configured model-site target when browsers also expose a vendor new-tab page. */
    private static function preferredTargetId(array $targets, string $preferredUrl): string
    {
        $preferred = parse_url($preferredUrl);
        if (is_array($preferred) && isset($preferred['scheme'], $preferred['host'])) {
            $preferredScheme = strtolower((string) $preferred['scheme']);
            $preferredHost = strtolower((string) $preferred['host']);
            $preferredPort = (int) ($preferred['port'] ?? ($preferredScheme === 'https' ? 443 : 80));
            $matching = [];
            foreach ($targets as $id => $target) {
                $parts = parse_url((string) ($target['url'] ?? ''));
                if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) continue;
                $scheme = strtolower((string) $parts['scheme']);
                $host = strtolower((string) $parts['host']);
                $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
                if ($scheme !== $preferredScheme || $host !== $preferredHost || $port !== $preferredPort) continue;
                $matching[(string) $id] = $target;
            }
            // A restored target with a title has a live renderer; a command-line target can expose
            // the final URL in /json/list while its renderer is still initializing.
            foreach ($matching as $id => $target) if (trim((string) ($target['title'] ?? '')) !== '') return $id;
            if ($matching !== []) return (string) array_key_first($matching);
        }
        return (string) array_key_first($targets);
    }

    /** @return array<string,array> Page targets keyed by DevTools target id. */
    private function discoverTargets(int $port): array
    {
        return $this->discoverTargetsIfAvailable($port) ?? [];
    }

    /** @return array<string,array>|null null means the DevTools snapshot itself was unavailable. */
    private function discoverTargetsIfAvailable(int $port): ?array
    {
        $response = $this->devtoolsRequest($port, '/json/list');
        if (!is_array($response)) return null;
        $targets = [];
        foreach ($response as $target) {
            if (!is_array($target) || ($target['type'] ?? '') !== 'page' || empty($target['id']) || empty($target['webSocketDebuggerUrl'])) continue;
            $targets[(string) $target['id']] = $target;
        }
        return $targets;
    }

    /**
     * Read a loopback DevTools endpoint through framework Curl.
     * @param int $port Existing private browser's debug port.
     * @param string $path DevTools endpoint path.
     * @param string $method GET or PUT according to the endpoint's protocol.
     * @param bool $rawResponse Preserve JSON root types for authoritative physical-target checks.
     * @return mixed Decoded response, raw bytes when requested, or null when transport failed.
     */
    private function devtoolsRequest(int $port, string $path, string $method = 'GET', bool $rawResponse = false): mixed
    {
        $client = curl();
        try {
            $client->setTimeout(2);
            $client->setOpt(CURLOPT_CONNECTTIMEOUT_MS, 400);
            $client->setOpt(CURLOPT_PROXY, '');
            $client->setOpt(CURLOPT_NOPROXY, '*');
            $client->setOpt(CURLOPT_PROTOCOLS, CURLPROTO_HTTP);
            $url = 'http://127.0.0.1:' . $port . $path;
            $response = $method === 'PUT' ? $client->put($url) : $client->get($url);
            if ($client->curlErrorCode !== 0 || (int) $client->httpStatusCode !== 200) return null;
            $raw = (string) $client->rawResponse;
            if ($rawResponse) return $raw;
            $decoded = json_decode($raw, true, 32);
            if (json_last_error() === JSON_ERROR_NONE) return $decoded;
            return $raw !== '' ? $raw : $response;
        } finally {
            $client->close();
        }
    }

    /**
     * Read a complete physical page inventory without confusing attachability with existence.
     * A page can exist before its WebSocket URL is available. An invalid or partial inventory
     * must retain the pending close target rather than falsely confirm that it disappeared.
     * @param int $port Existing private browser's debug port.
     * @return array<string,true>|null Physical page IDs, or null when the snapshot is untrustworthy.
     */
    private function discoverPhysicalPageIdsIfAvailable(int $port): ?array
    {
        $raw = $this->devtoolsRequest($port, '/json/list', 'GET', true);
        if (!is_string($raw)) return null;
        try { $rows = json_decode($raw, false, 32, JSON_THROW_ON_ERROR); }
        catch (\JsonException) { return null; }
        if (!is_array($rows)) return null;
        $seen = [];
        $pages = [];
        foreach ($rows as $row) {
            if (!$row instanceof \stdClass || !is_string($row->id ?? null) || $row->id === ''
                || !is_string($row->type ?? null) || $row->type === '' || isset($seen[$row->id])) return null;
            $seen[$row->id] = true;
            if ($row->type === 'page') $pages[$row->id] = true;
        }
        return $pages;
    }

    /** Connect directly to one page target websocket; switching tabs replaces this transport. */
    private function connectTarget(string $targetId, ?int $commandId = null, ?array $knownTargets = null): void
    {
        $state = BrowserState::state();
        $port = (int) ($state['debug_port'] ?? 0);
        $targets = $knownTargets ?? $this->discoverTargets($port);
        $target = $targets[$targetId] ?? null;
        if (!is_array($target) || empty($target['webSocketDebuggerUrl'])) {
            if ($commandId !== null) $this->failCommand($commandId, 'BROWSER_TAB_NOT_FOUND', '标签页已经关闭或不存在。');
            else $this->connectionFailed('BROWSER_TARGET_DISCOVERY_FAILED');
            return;
        }
        $this->connecting = true;
        $this->switchingTarget = true;
        $this->recordStartupStage('page_cdp_connect');
        $this->cdp->close('BROWSER_TARGET_SWITCH');
        try {
            $this->cdp->connect((string) $target['webSocketDebuggerUrl'], function () use ($targetId, $commandId): void {
                if ($this->startupRestarting) return;
                $state = BrowserState::state();
                BrowserState::write([
                    'active_target' => $targetId, 'session_id' => null,
                    'surface_generation' => (int) $state['surface_generation'] + 1,
                    'document_nonce' => bin2hex(random_bytes(12)), 'last_error' => null,
                ]);
                \app\gateway\model\ModelCatalog::advanceSurfaceGeneration((int) $state['surface_generation'], (int) $state['surface_generation'] + 1);
                if ($this->pendingStartCommand !== null) $this->recordStartupStage('page_enable');
                $this->cdp->send('Page.enable', [], null, function ($pageError) use ($commandId): void {
                    if ($this->startupRestarting) return;
                    if ($pageError !== null) {
                        $this->connecting = false;
                        $this->switchingTarget = false;
                        if ($commandId !== null) $this->failCommand($commandId, 'BROWSER_PAGE_ENABLE_FAILED', '页面控制能力初始化失败。');
                        $this->connectionFailed('BROWSER_PAGE_ENABLE_FAILED', is_array($pageError) ? $pageError : null);
                        return;
                    }
                    if ($this->pendingStartCommand !== null) $this->recordStartupStage('viewport');
                    $this->cdp->send('Emulation.setDeviceMetricsOverride', ['width' => 1280, 'height' => 720, 'deviceScaleFactor' => 1, 'mobile' => false], null,
                        function ($metricsError) use ($commandId): void {
                            if ($this->startupRestarting) return;
                            if ($metricsError !== null) {
                                $this->connecting = false;
                                $this->switchingTarget = false;
                                if ($commandId !== null) $this->failCommand($commandId, 'BROWSER_VIEWPORT_FAILED', '浏览器工作区尺寸初始化失败。');
                                $this->connectionFailed('BROWSER_VIEWPORT_FAILED', is_array($metricsError) ? $metricsError : null);
                                return;
                            }
                            if ($this->pendingStartCommand !== null) {
                                $this->initializeStartupLocation($commandId);
                                return;
                            }
                            $this->finishTargetConnection($commandId);
                        }, $this->pendingStartCommand !== null ? 20.0 : 5.0);
                }, $this->pendingStartCommand !== null ? 20.0 : 5.0);
            }, function (array $message): void { $this->onEvent($message); }, function (bool $wasReady): void {
                $this->connecting = false;
                if ($this->switchingTarget || $this->startupRestarting) return;
                if ($this->pendingStartCommand !== null) $this->recordStartupStage('cdp_disconnected');
                if ($this->stopping) return;
                try { BrowserState::write(['status' => $this->pendingStartCommand !== null ? 'starting' : 'error',
                    'session_id' => null, 'last_error' => 'BROWSER_CDP_DISCONNECTED']); } catch (\Throwable) {}
            });
        } catch (\Throwable) {
            $this->connecting = false;
            $this->switchingTarget = false;
            if ($commandId !== null) $this->failCommand($commandId, 'BROWSER_CDP_CONNECT_FAILED', '无法连接所选标签页。');
            else $this->connectionFailed('BROWSER_CDP_CONNECT_FAILED');
        }
    }

    /** Confirm the renderer's real URL before deciding whether startup needs navigation. */
    private function initializeStartupLocation(?int $commandId): void
    {
        $this->recordStartupStage('initial_location_check');
        $this->cdp->send('Runtime.evaluate', [
            'expression' => 'String(location.href||"")', 'returnByValue' => true,
        ], null, function ($locationError, $locationResult) use ($commandId): void {
            $url = (string) ($locationResult['result']['value'] ?? '');
            if ($locationError === null && self::sameOrigin($url, self::modelUrl())) {
                $this->finishTargetConnection($commandId);
                return;
            }
            $this->navigateStartup($commandId);
        }, 3.0);
    }

    /** Navigate once, then reconcile a lost/late Page.navigate reply against the live renderer. */
    private function navigateStartup(?int $commandId): void
    {
        $this->recordStartupStage('initial_navigation');
        $this->cdp->send('Page.navigate', ['url' => self::modelUrl()], null,
            function ($navigationError) use ($commandId): void {
                if ($navigationError === null) {
                    $this->finishTargetConnection($commandId);
                    return;
                }
                if ((int) ($navigationError['code'] ?? 0) !== -2) {
                    $this->connecting = false;
                    $this->switchingTarget = false;
                    $this->connectionFailed('BROWSER_INITIAL_NAVIGATION_FAILED', is_array($navigationError) ? $navigationError : null);
                    return;
                }
                $this->verifyStartupLocation($commandId, $navigationError, 0);
            }, 8.0);
    }

    private function verifyStartupLocation(?int $commandId, array $navigationError, int $attempt): void
    {
        if ($this->pendingStartCommand === null) return;
        $this->recordStartupStage('initial_navigation_verify');
        $this->cdp->send('Runtime.evaluate', [
            'expression' => 'String(location.href||"")', 'returnByValue' => true,
        ], null, function ($locationError, $locationResult) use ($commandId, $navigationError, $attempt): void {
            $url = (string) ($locationResult['result']['value'] ?? '');
            if ($locationError === null && self::sameOrigin($url, self::modelUrl())) {
                $this->finishTargetConnection($commandId);
                return;
            }
            if ($attempt < 2 && $this->pendingStartCommand !== null) {
                Timer::add(0.4, fn() => $this->verifyStartupLocation($commandId, $navigationError, $attempt + 1), null, false);
                return;
            }
            $this->connecting = false;
            $this->switchingTarget = false;
            $this->connectionFailed('BROWSER_INITIAL_NAVIGATION_FAILED', $navigationError);
        }, 2.0);
    }

    private static function sameOrigin(string $actual, string $expected): bool
    {
        $a = parse_url($actual); $e = parse_url($expected);
        if (!is_array($a) || !is_array($e) || !isset($a['scheme'], $a['host'], $e['scheme'], $e['host'])) return false;
        $as = strtolower((string) $a['scheme']); $es = strtolower((string) $e['scheme']);
        $ap = (int) ($a['port'] ?? ($as === 'https' ? 443 : 80));
        $ep = (int) ($e['port'] ?? ($es === 'https' ? 443 : 80));
        return $as === $es && strtolower((string) $a['host']) === strtolower((string) $e['host']) && $ap === $ep;
    }

    /** Publish page readiness immediately on ordinary tab switches; fresh startup still waits for both transports and its first frame. */
    private function finishTargetConnection(?int $commandId): void
    {
        $this->connecting = false;
        $this->switchingTarget = false;
        BrowserState::write(['status' => $this->pendingStartCommand !== null ? 'starting' : 'ready', 'last_error' => null]);
        $this->refreshTabs();
        $this->refreshMetadata();
        if ($commandId !== null) $this->finishCommand($commandId, ['target_id' => BrowserState::state()['active_target']]);
        if ($this->pendingStartCommand !== null) {
            // Startup is not complete until the Web workspace has produced its first actual frame.
            // capture() will finish the pending start command after persisting screenshot #1.
            $this->recordStartupStage('first_frame');
        }
    }

    private function cdpCommand(int $commandId, string $method, array $params): void
    {
        if (!$this->cdp->ready()) {
            $this->failCommand($commandId, 'BROWSER_CDP_DISCONNECTED', '浏览器控制连接不可用。');
            return;
        }
        $this->cdp->send($method, $params, null, function ($error, $result) use ($commandId): void {
            if ($error !== null) {
                $this->failCommand($commandId, 'BROWSER_CDP_COMMAND_FAILED', '浏览器未接受本次操作。');
                return;
            }
            $this->finishCommand($commandId, $result);
            $this->refreshMetadata();
            $this->captureAfterManualInput();
        });
    }

    private function click(int $commandId, float $x, float $y): void
    {
        $press = ['type' => 'mousePressed', 'x' => $x, 'y' => $y, 'button' => 'left', 'clickCount' => 1];
        $release = ['type' => 'mouseReleased', 'x' => $x, 'y' => $y, 'button' => 'left', 'clickCount' => 1];
        $this->cdp->send('Input.dispatchMouseEvent', $press, null, function ($error) use ($commandId, $release): void {
            if ($error !== null) { $this->failCommand($commandId, 'BROWSER_CLICK_FAILED', '页面点击未完成。'); return; }
            $this->cdp->send('Input.dispatchMouseEvent', $release, null, function ($releaseError) use ($commandId): void {
                if ($releaseError !== null) $this->failCommand($commandId, 'BROWSER_CLICK_FAILED', '页面点击未完成。');
                else {
                    $this->finishCommand($commandId, ['clicked' => true]);
                    $this->refreshMetadata();
                    $this->captureAfterManualInput();
                }
            });
        });
    }

    /** Move the virtual pointer into the viewport before dispatching a wheel event. */
    private function scroll(int $commandId, float $x, float $y, float $deltaX, float $deltaY): void
    {
        if (!$this->cdp->ready()) {
            $this->failCommand($commandId, 'BROWSER_CDP_DISCONNECTED', '浏览器控制连接不可用。');
            return;
        }
        $positionExpression = '({x:Number(window.scrollX||0),y:Number(window.scrollY||0)})';
        $this->cdp->send('Runtime.evaluate', ['expression' => $positionExpression, 'returnByValue' => true], null,
            function ($positionError, $positionResult) use ($commandId, $x, $y, $deltaX, $deltaY, $positionExpression): void {
                $before = $positionResult['result']['value'] ?? null;
                if ($positionError !== null || !is_array($before)) {
                    $this->failCommand($commandId, 'BROWSER_SCROLL_FAILED', '页面滚动状态读取失败。');
                    return;
                }
                $this->cdp->send('Input.dispatchMouseEvent', [
                    'type' => 'mouseMoved', 'x' => $x, 'y' => $y, 'button' => 'none', 'pointerType' => 'mouse',
                ], null, function ($moveError) use ($commandId, $x, $y, $deltaX, $deltaY, $positionExpression, $before): void {
                    if ($moveError !== null) {
                        $this->failCommand($commandId, 'BROWSER_SCROLL_FAILED', '页面滚动未完成。');
                        return;
                    }
                    $this->cdp->send('Input.dispatchMouseEvent', [
                        'type' => 'mouseWheel', 'x' => $x, 'y' => $y, 'deltaX' => $deltaX, 'deltaY' => $deltaY,
                        'button' => 'none', 'pointerType' => 'mouse',
                    ], null, function ($wheelError) use ($commandId, $deltaX, $deltaY, $positionExpression, $before): void {
                        if ($wheelError !== null) {
                            $this->failCommand($commandId, 'BROWSER_SCROLL_FAILED', '页面滚动未完成。');
                            return;
                        }
                        Timer::add(0.08, function () use ($commandId, $deltaX, $deltaY, $positionExpression, $before): void {
                            $this->cdp->send('Runtime.evaluate', ['expression' => $positionExpression, 'returnByValue' => true], null,
                                function ($afterError, $afterResult) use ($commandId, $deltaX, $deltaY, $before): void {
                                    $after = $afterResult['result']['value'] ?? null;
                                    $moved = $afterError === null && is_array($after)
                                        && ((float) ($after['x'] ?? 0) !== (float) ($before['x'] ?? 0)
                                            || (float) ($after['y'] ?? 0) !== (float) ($before['y'] ?? 0));
                                    if ($moved) {
                                        $this->finishCommand($commandId, ['scrolled' => true, 'fallback' => false]);
                                        $this->refreshMetadata();
                                        $this->captureAfterManualInput();
                                        return;
                                    }
                                    $expression = 'window.scrollBy(' . json_encode($deltaX, JSON_THROW_ON_ERROR) . ','
                                        . json_encode($deltaY, JSON_THROW_ON_ERROR) . ');'
                                        . '({x:Number(window.scrollX||0),y:Number(window.scrollY||0)})';
                                    $this->cdp->send('Runtime.evaluate', ['expression' => $expression, 'returnByValue' => true], null,
                                        function ($fallbackError) use ($commandId): void {
                                            if ($fallbackError !== null) {
                                                $this->failCommand($commandId, 'BROWSER_SCROLL_FAILED', '页面滚动未完成。');
                                                return;
                                            }
                                            $this->finishCommand($commandId, ['scrolled' => true, 'fallback' => true]);
                                            $this->refreshMetadata();
                                            $this->captureAfterManualInput();
                                        });
                                });
                        }, null, false);
                    });
                });
            });
    }

    /** Route manual workspace keys through the same native keyboard path as task automation. */
    private function key(int $commandId, string $key): void
    {
        $this->dispatchBrowserKey($key, function ($error) use ($commandId): void {
            if ($error !== null) { $this->failCommand($commandId, 'BROWSER_KEY_FAILED', '键盘操作未完成。'); return; }
            $this->finishCommand($commandId, ['key' => true]);
            $this->refreshMetadata();
            $this->captureAfterManualInput();
        });
    }

    /** Refresh the viewed setup tab after paint, with no faster idle capture loop or task-tab work. */
    private function captureAfterManualInput(int $attempt = 0): void
    {
        Timer::add(0.08, function () use ($attempt): void {
            if (!self::observerActive(self::screenshotObserverPath())) return;
            if ($this->screenshotBusy && $attempt < 3) {
                $this->captureAfterManualInput($attempt + 1);
                return;
            }
            $this->capture();
        }, null, false);
    }

    /** Dispatch one real keyboard key through the main browser CDP session. */
    private function dispatchBrowserKey(string $key, callable $callback): void
    {
        $this->dispatchCdpKey($this->cdp, null, $key, $callback);
    }

    /** Dispatch one real keyboard key through the task's flattened CDP session. */
    private function dispatchTaskKey(string $responseId, string $key, callable $callback): void
    {
        $turn = $this->chatGptTurns[$responseId] ?? null;
        if (!is_array($turn) || (string) ($turn['session_id'] ?? '') === '') {
            $callback(['code' => -1, 'message' => 'TASK_SURFACE_MISSING']);
            return;
        }
        $sessionId = (string) $turn['session_id'];
        $this->dispatchCdpKey($this->taskCdp, $sessionId, $key, function ($error) use ($responseId, $sessionId, $callback): void {
            if (($this->chatGptTurns[$responseId]['session_id'] ?? null) !== $sessionId) return;
            $callback($error);
        });
    }

    /** Send a native key once, including Enter's character event required by buttons/links.
     * @param callable(?array):void $callback Receives a protocol error or null after key-up.
     */
    private function dispatchCdpKey(CdpClient $client, ?string $sessionId, string $key, callable $callback): void
    {
        $params = self::keyParams($key);
        $downParams = $params + ['type' => 'keyDown'];
        if ($key === 'Enter') $downParams['text'] = "\r";
        $client->send('Input.dispatchKeyEvent', $downParams, $sessionId,
            function ($error) use ($client, $sessionId, $params, $callback): void {
                if ($error !== null && ($error['message'] ?? '') !== 'BROWSER_CDP_TIMEOUT') {
                    $callback(is_array($error) ? $error : ['code' => -1, 'message' => 'KEY_DOWN_FAILED']);
                    return;
                }
                unset($params['text'], $params['unmodifiedText']);
                $client->send('Input.dispatchKeyEvent', $params + ['type' => 'keyUp'], $sessionId,
                    function ($upError) use ($callback, $error): void {
                        $callback(is_array($error) ? $error : (is_array($upError) ? $upError : null));
                    }, 3.0);
            }, 3.0);
    }

    /**
     * Open mention autocomplete with native keystrokes, independent of the custom RCMAKER suffix.
     * The ASCII brand query supports Unicode suffixes; callers still select only the exact configured name.
     * Preserve the old query for existing non-RCMAKER connector names accepted by settings migration.
     */
    private function typeConnectorMention(CdpClient $client, ?string $sessionId, callable $callback, float $timeout = 5.0, string $connectorName = 'RCMAKER Codex'): void
    {
        $query = str_starts_with($connectorName, 'RCMAKER ') ? '@RCMAKER' : '@codex';
        $index = 0; $deadline = microtime(true) + $timeout;
        $advance = function () use ($client, $sessionId, $callback, $query, &$index, $deadline, &$advance): void {
            // Once the final key was acknowledged, a slow callback does not undo successful input.
            if ($index >= strlen($query)) { $callback(null); return; }
            if (microtime(true) >= $deadline) { $callback(['code' => -2, 'message' => 'CONNECTOR_MENTION_TIMEOUT', 'mention_key_index'=>$index]); return; }
            $key = $query[$index++];
            $this->dispatchCdpKey($client, $sessionId, $key, function ($error) use ($callback, $index, &$advance): void {
                if ($error !== null) { $callback($error + ['mention_key_index'=>$index]); return; }
                Timer::add(0.025, $advance, null, false);
            });
        };
        Timer::add(0.05, $advance, null, false);
    }

    private function newTab(int $commandId, string $url): void
    {
        $state = BrowserState::state();
        $port = (int) ($state['debug_port'] ?? 0);
        $created = $this->devtoolsRequest($port, '/json/new?' . rawurlencode($url), 'PUT');
        if (!is_array($created) || empty($created['id'])) { $this->failCommand($commandId, 'BROWSER_TAB_CREATE_FAILED', '无法创建新标签页。'); return; }
        $this->connectTarget((string) $created['id'], $commandId);
    }

    private function switchTab(int $commandId, string $targetId): void
    {
        $state = BrowserState::state();
        $port = (int) ($state['debug_port'] ?? 0);
        $targets = $this->discoverTargets($port);
        if (!isset($targets[$targetId])) { $this->failCommand($commandId, 'BROWSER_TAB_NOT_FOUND', '标签页已经关闭或不存在。'); return; }
        $activated = $this->devtoolsRequest($port, '/json/activate/' . rawurlencode($targetId));
        if ($activated === null) { $this->failCommand($commandId, 'BROWSER_TAB_ACTIVATE_FAILED', '无法切换到所选标签页。'); return; }
        $this->connectTarget($targetId, $commandId, $targets);
    }

    private function closeTab(int $commandId, string $targetId): void
    {
        $state = BrowserState::state();
        $port = (int) ($state['debug_port'] ?? 0);
        $targets = $this->discoverTargets($port);
        if (!isset($targets[$targetId])) {
            $this->failCommand($commandId, 'BROWSER_TAB_NOT_FOUND', '标签页已经关闭或不存在。');
            return;
        }
        if (count($targets) <= 1) {
            $this->failCommand($commandId, 'BROWSER_LAST_TAB_REQUIRED', '工作区至少保留一个标签页；如需全部关闭请停止浏览器。');
            return;
        }
        $closingActive = $targetId === (string) ($state['active_target'] ?? '');
        $remaining = $targets;
        unset($remaining[$targetId]);
        if ($closingActive) $this->switchingTarget = true;
        $closed = $this->devtoolsRequest($port, '/json/close/' . rawurlencode($targetId));
        if ($closed === null) {
            $this->switchingTarget = false;
            $this->failCommand($commandId, 'BROWSER_TAB_CLOSE_FAILED', '无法关闭所选标签页。');
            return;
        }
        if (!$closingActive) {
            $this->finishCommand($commandId, ['target_id' => $targetId, 'closed' => true]);
            $this->refreshTabs();
            return;
        }
        $nextId = self::preferredTargetId($remaining, self::modelUrl());
        Timer::add(0.08, function () use ($nextId, $commandId, $remaining): void {
            $this->connectTarget($nextId, $commandId, $remaining);
        }, null, false);
    }

    /** Publish manual tabs only from a complete snapshot; unavailable discovery must retain native ownership. */
    private function refreshTabs(): void
    {
        $state = BrowserState::state();
        $port = (int) ($state['debug_port'] ?? 0);
        if ($port < 1) return;
        $managed = array_fill_keys(TaskLedger::managedTargetIds(), true);
        if ($this->connectorProbeTargetId !== null) $managed[$this->connectorProbeTargetId] = true;
        $allTargets = $this->discoverTargetsIfAvailable($port);
        if ($allTargets === null) return;
        $previousNativeTarget = NativeBrowserWindow::targetId($state);
        NativeBrowserWindow::reconcileTargets($state, $allTargets);
        $nativeTarget = NativeBrowserWindow::targetId($state);
        if ($previousNativeTarget !== null && $nativeTarget === null) $this->closedNativeSetupRefresh = $state;
        if ($nativeTarget !== null) $managed[$nativeTarget] = true;
        $targets = array_values(array_filter(
            $allTargets,
            static fn(array $target): bool => !isset($managed[(string) ($target['id'] ?? '')])
        ));
        $safe = array_map(static fn(array $target): array => [
            'target_id' => (string) $target['id'],
            'title' => mb_substr((string) ($target['title'] ?? ''), 0, 200),
            'url' => mb_substr((string) ($target['url'] ?? ''), 0, 2000),
        ], $targets);
        BrowserState::write(['tabs_json' => json_encode($safe, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
    }

    private function refreshMetadata(): void
    {
        if (!$this->cdp->ready()) return;
        $observedState = BrowserState::state();
        if ((int) ($observedState['profile_reset_required'] ?? 0) === 1) return;
        $generation = (int) $observedState['surface_generation'];
        $documentNonce = (string) ($observedState['document_nonce'] ?? '');
        $this->cdp->send('Runtime.evaluate', [
            'expression' => '({title:String(document.title||""),url:String(location.href||"")})',
            'returnByValue' => true,
        ], null, function ($error, $result) use ($generation, $documentNonce): void {
            $value = $result['result']['value'] ?? null;
            if ($error !== null || !is_array($value)) return;
            try {
                Schema::transaction(function () use ($generation, $documentNonce, $value): void {
                    $current = BrowserState::state();
                    if ((int) ($current['profile_reset_required'] ?? 0) === 1
                        || (int) $current['surface_generation'] !== $generation
                        || (string) ($current['document_nonce'] ?? '') !== $documentNonce) return;
                    BrowserState::write([
                        'title' => mb_substr((string) ($value['title'] ?? ''), 0, 200),
                        'url' => mb_substr((string) ($value['url'] ?? ''), 0, 2000),
                    ]);
                });
            } catch (\Throwable) {}
        }, 3);
    }

    /** Read one owned setup surface for account readiness, preserving the embedded page and every task session. */
    private function refreshChatGptCapability(): void
    {
        if ($this->capabilityInspectionBusy) return;
        $observedState = BrowserState::state();
        if (($observedState['status'] ?? '') !== 'ready' || (int) ($observedState['profile_reset_required'] ?? 0) === 1) return;
        $nativeTarget = NativeBrowserWindow::targetId($observedState);
        if ($nativeTarget !== null ? !$this->taskCdp->ready() : !$this->cdp->ready()) return;
        $generation = (int) $observedState['surface_generation'];
        $documentNonce = (string) ($observedState['document_nonce'] ?? '');
        $this->capabilityInspectionBusy = true;
        $finished = false;
        $complete = function ($error, $value) use (&$finished, $observedState, $nativeTarget, $generation, $documentNonce): void {
            if ($finished) return;
            $finished = true;
            $this->capabilityInspectionBusy = false;
            try {
                if ($error === null && $value === null && $nativeTarget !== null) {
                    // A complete native snapshot proved the page closed; resume the embedded source immediately.
                    $this->closedNativeSetupRefresh = $observedState;
                    $this->refreshChatGptCapability();
                    return;
                }
                if ($error !== null || !is_array($value)) return;
                if ($nativeTarget === null && $this->refreshClosedNativeSetup($observedState, $value)) return;
                $accepted = Schema::transaction(function () use ($observedState, $nativeTarget, $generation, $documentNonce, $value): bool {
                    $current = BrowserState::state();
                    if (($current['status'] ?? '') !== 'ready' || (int) ($current['profile_reset_required'] ?? 0) === 1
                        || NativeBrowserWindow::targetId($current) !== $nativeTarget) return false;
                    if ($nativeTarget !== null) {
                        // Native login is independent of navigation on the embedded manual surface.
                        foreach (['browser_pid', 'debug_port', 'launch_nonce'] as $field) {
                            if (($current[$field] ?? null) !== ($observedState[$field] ?? null)) return false;
                        }
                    } elseif ((int) $current['surface_generation'] !== $generation
                        || (string) ($current['document_nonce'] ?? '') !== $documentNonce) return false;
                    $authenticated = !empty($value['on_chatgpt']) && empty($value['login_required'])
                        && !empty($value['editor_ready']);
                    BrowserState::write([
                        'chatgpt_json' => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                        'chatgpt_observed_at' => time(),
                        ...($authenticated ? ['setup_completed' => 1] : []),
                    ]);
                    return true;
                });
                if (!$accepted) return;
                if (!empty($value['on_chatgpt']) && empty($value['login_required']) && !empty($value['editor_ready'])) {
                    if (!$this->commandBusy && empty($value['generation_running'])
                        && !self::observerActive(self::screenshotObserverPath(), self::SETUP_VIEWER_AUTOMATION_PAUSE_SECONDS)) {
                        \app\gateway\model\ModelCatalog::refreshIfChanged((string) ($value['account_plan'] ?? 'unknown'));
                    }
                }
            } catch (\Throwable) {}
        };
        try {
            if ($nativeTarget !== null) {
                NativeBrowserWindow::inspectCapability($this->taskCdp, $observedState, $complete);
            } else {
                $this->cdp->send('Runtime.evaluate', [
                    'expression' => WebModelPage::capabilityExpression(), 'returnByValue' => true,
                ], null, static function ($error, $result) use ($complete): void {
                    $complete($error, $result['result']['value'] ?? null);
                }, 2.0);
            }
        } catch (\Throwable) {
            $complete(new GatewayError('BROWSER_CAPABILITY_UNAVAILABLE', '浏览器状态暂未完成检测。', 503), null);
        }
    }

    /** Refresh one stale, composer-free login document after native closure; never navigate task pages or active commands. */
    private function refreshClosedNativeSetup(array $observedState, array $value): bool
    {
        $closed = $this->closedNativeSetupRefresh;
        if ($closed === null) return false;
        $current = BrowserState::state();
        foreach (['browser_pid', 'debug_port', 'launch_nonce', 'active_target', 'surface_generation', 'document_nonce'] as $field) {
            if (($current[$field] ?? null) !== ($closed[$field] ?? null)) {
                $this->closedNativeSetupRefresh = null;
                return false;
            }
        }
        if (NativeBrowserWindow::targetId($current) !== null || ($current['status'] ?? '') !== 'ready'
            || (int) ($current['profile_reset_required'] ?? 0) === 1) return true;
        if ((int) $current['surface_generation'] !== (int) $observedState['surface_generation']
            || (string) $current['document_nonce'] !== (string) $observedState['document_nonce']) return true;
        $url = (string) ($value['url'] ?? '');
        $authHost = strtolower((string) (parse_url($url, PHP_URL_HOST) ?: ''));
        $loginDocument = self::sameOrigin($url, self::modelUrl()) || in_array($authHost, ['auth.openai.com', 'auth0.openai.com'], true);
        if (!$loginDocument || empty($value['login_required']) || !empty($value['editor_ready']) || !empty($value['generation_running'])) {
            $this->closedNativeSetupRefresh = null;
            return false;
        }
        $targetId = (string) ($current['active_target'] ?? '');
        if ($targetId === '' || $targetId === $this->connectorProbeTargetId || in_array($targetId, TaskLedger::managedTargetIds(), true)) {
            $this->closedNativeSetupRefresh = null;
            return false;
        }
        // An active serialized command may still be navigating or reading this document; wait for its normal completion.
        if ($this->commandBusy || $this->pendingStartCommand !== null || $this->connecting || $this->switchingTarget || !$this->cdp->ready()) return true;
        $this->closedNativeSetupRefresh = null;
        $this->capabilityInspectionBusy = true;
        $finished = false;
        $done = function ($error) use (&$finished): void {
            if ($finished) return;
            $finished = true;
            $this->capabilityInspectionBusy = false;
            if ($error === null) $this->refreshChatGptCapability();
        };
        try {
            $this->cdp->send('Page.navigate', ['url' => self::modelUrl()], null, $done, 5.0);
        } catch (\Throwable) {
            $done(['message' => 'BROWSER_CDP_SEND_FAILED']);
        }
        return true;
    }

    /** Execute one page-backed Responses text turn without blocking the API worker. */
    private function chatGptText(int $commandId, array $payload): void
    {
        $responseId = (string) ($payload['response_id'] ?? '');
        if ($responseId === '') {
            $this->failCommand($commandId, 'CHATGPT_RESPONSE_STATE_INVALID', 'Responses 请求缺少持久响应标识。');
            return;
        }
        if (!$this->taskCdp->ready()) {
            try { BrowserState::defer($commandId); }
            catch (\Throwable) { $this->failCommand($commandId, 'CHATGPT_BROWSER_NOT_READY', '任务浏览器控制连接尚未就绪。'); }
            $this->commandBusy = false;
            return;
        }
        try {
            $ledger = TaskLedger::execution($responseId);
            $job = ResponseJob::execution($responseId);
            if (!in_array((string) ($job['status'] ?? ''), ['queued', 'running'], true)) {
                throw new GatewayError('CHATGPT_RESPONSE_STATE_INVALID', 'Responses 请求状态已变化，未重复发送网页消息。', 409);
            }
            if (!$this->settleSupersededTaskSurface($responseId, $job, $ledger)) {
                if (time() >= (int) ($ledger['turn']['deadline_at'] ?? 0)) {
                    throw new GatewayError('steering_page_retire_timeout', '中途指令等待旧网页退出超时，未发送新消息。', 504);
                }
                BrowserState::defer($commandId);
                $this->commandBusy = false;
                return;
            }
            $recover = !empty($payload['recover'])
                || in_array((string) ($ledger['turn']['status'] ?? ''), ['submit_intent', 'submitted', 'running'], true)
                || (int) ($job['submit_intent_at'] ?? 0) > 0;
            $bindingConnector = !empty($job['mcp_binding_id']) ? ToolBroker::connectorNameForJob($job) : '';
            // A settings migration probes the new Connector. Older pinned bindings must
            // still select their own name and must not fail on the new name's probe state.
            $currentConnector = $bindingConnector !== ''
                && hash_equals((string) GatewaySettings::internal()['connector_name'], $bindingConnector);
            if (!$recover && $currentConnector && ConnectorState::runtimeCheckPending()) {
                BrowserState::defer($commandId);
                $this->commandBusy = false;
                return;
            }
            if (!$recover && $currentConnector) ConnectorState::assertRuntimeReady();
            $this->prepareTaskSurface($responseId, $commandId, $recover);
            // Task pages use independent CDP sessions. Releasing the queue gate here allows
            // other task commands to attach and progress concurrently.
            $this->commandBusy = false;
        } catch (GatewayError $error) {
            try { ResponseJob::fail($responseId, $error->errorCode, $error->getMessage()); } catch (\Throwable) {}
            $this->failCommand($commandId, $error->errorCode, $error->getMessage());
        } catch (\Throwable) {
            try { ResponseJob::fail($responseId, 'CHATGPT_AUTOMATION_FAILED', 'ChatGPT 任务页面自动化未能启动。'); } catch (\Throwable) {}
            $this->failCommand($commandId, 'CHATGPT_AUTOMATION_FAILED', 'ChatGPT 任务页面自动化未能启动。');
        }
    }

    /** Do not submit the new instruction until the superseded Web page is physically gone. */
    private function settleSupersededTaskSurface(string $responseId, array $job, array $ledger): bool
    {
        if (\app\gateway\model\SteeringContext::isDelta($job)) {
            // Retire old observers, not the page. Their capabilities and ledger fence were
            // revoked atomically when the incremental response was reserved.
            foreach ($this->chatGptTurns as $oldResponseId => $turn) {
                if ($oldResponseId === $responseId || ($turn['surface_id'] ?? '') !== $ledger['surface']['id']) continue;
                $oldCommandId = (int) ($turn['command_id'] ?? 0);
                $this->releaseTaskSession((string) $oldResponseId);
                if ($oldCommandId > 0) {
                    try { $this->finishCommand($oldCommandId, ['status' => 'superseded']); } catch (\Throwable) {}
                }
            }
            return true;
        }
        $previousId = (string) ($job['previous_response_id'] ?? '');
        if ($previousId === '') return true;
        try { $source = TaskLedger::execution($previousId); }
        catch (GatewayError) { return true; }
        $oldSurface = $source['surface'];
        if ((string) ($oldSurface['id'] ?? '') === (string) ($ledger['surface']['id'] ?? '')
            || (string) ($oldSurface['status'] ?? '') !== 'retired'
            || (string) ($source['turn']['id'] ?? '') !== (string) ($ledger['turn']['id'] ?? '')) return true;
        // Several native steers may arrive before the replacement page starts. The
        // immediate predecessor can have no target while an older retired target is
        // still generating; close every retired target owned by this task first.
        $retired = SDB(null, 'medoo', 'sqlite')->table('gateway_browser_surfaces')
            ->where('task_id', (string) $job['task_id'])->where('status', 'retired')->select();
        if (!is_array($retired)) return false;
        $retiredIds = array_fill_keys(array_map(static fn(array $row): string => (string) $row['id'], $retired), true);
        foreach ($this->chatGptTurns as $oldResponseId => $turn) {
            if (!isset($retiredIds[(string) ($turn['surface_id'] ?? '')])) continue;
            $oldCommandId = (int) ($turn['command_id'] ?? 0);
            $this->releaseTaskSession((string) $oldResponseId);
            if ($oldCommandId > 0) {
                try { $this->finishCommand($oldCommandId, ['status' => 'superseded']); }
                catch (\Throwable) {}
            }
        }
        $targets = array_values(array_filter($retired,
            static fn(array $row): bool => (string) ($row['target_id'] ?? '') !== ''));
        if ($targets === []) return true;
        $port = (int) (BrowserState::state()['debug_port'] ?? 0);
        if ($port < 1) return false;
        $live = $this->discoverTargetsIfAvailable($port);
        if ($live === null) return false;
        foreach ($targets as $surface) {
            $targetId = (string) $surface['target_id'];
            if (isset($live[$targetId])) {
                $this->devtoolsRequest($port, '/json/close/' . rawurlencode($targetId));
                $live = $this->discoverTargetsIfAvailable($port);
                if ($live === null || isset($live[$targetId])) return false;
            }
            TaskLedger::targetClosed((string) $surface['id']);
            $this->clearMonitorScreenshots((string) $surface['id']);
        }
        return true;
    }

    /** Maintain one browser-level CDP connection that multiplexes all automatic task surfaces. */
    private function ensureTaskCdp(array $state): void
    {
        if ($this->stopping) return;
        if ($this->taskCdp->ready()) {
            $this->ensureBrowserTimezone($state, $this->taskBrowserSocket);
            return;
        }
        if ($this->taskConnecting) return;
        $port = (int) ($state['debug_port'] ?? 0);
        if ($port < 1) return;
        $version = $this->devtoolsRequest($port, '/json/version');
        $socket = is_array($version) ? (string) ($version['webSocketDebuggerUrl'] ?? '') : '';
        $profile = (string) Config::get('gateway', 'browser_profile_dir');
        if ($socket === '' || !BrowserProcess::endpointMatches($profile, $port, $socket)) return;
        if (!$this->ensureBrowserTimezone($state, $socket)) return;
        $this->taskConnecting = true;
        if ($this->taskBrowserSocket !== $socket) $this->taskBrowserIdentityCache = null;
        $this->taskBrowserSocket = $socket;
        try {
            $this->taskCdp->connect(
                $socket,
                function (): void {
                    $this->taskConnecting = false;
                    $this->configureTaskDownloads(function (): void {
                        $pending = array_keys($this->pendingResumes);
                        $this->pendingResumes = [];
                        foreach ($pending as $responseId) {
                            Timer::add(0.02, function () use ($responseId): void {
                                try {
                                    $job = ResponseJob::execution($responseId);
                                    $commandId = (int) ($job['command_id'] ?? 0);
                                    if ($commandId > 0) $this->prepareTaskSurface($responseId, $commandId, true);
                                } catch (\Throwable) {}
                            }, null, false);
                        }
                    });
                },
                function (array $message): void { $this->onTaskEvent($message); },
                function (bool $wasReady): void {
                    $this->taskConnecting = false;
                    if ($this->stopping) return;
                    foreach ($this->chatGptTurns as $responseId => $_turn) $this->pendingResumes[$responseId] = true;
                    $this->chatGptTurns = [];
                    $this->taskSessions = [];
                    $this->taskDownloads = [];
                    $this->taskDownloadsReady = false;
                }
            );
        } catch (\Throwable) {
            $this->taskConnecting = false;
        }
    }

    /** Arm timezone emulation before navigation; retry protocol failures without restarting pages or replaying tasks. */
    private function ensureBrowserTimezone(array $state, string $socket): bool
    {
        try {
            if ($this->browserTimezoneFailure !== null) throw $this->browserTimezoneFailure;
            $this->browserTimezoneId ??= BrowserTimezone::resolve(NetworkSettings::revision((int) $state['network_revision']));
            return $this->browserTimezone->ensure($socket, $this->browserTimezoneId);
        } catch (GatewayError $error) {
            // A transient target/CDP error is repaired on its own session with bounded backoff.
            // Closing this guard here would remove timezone overrides from every healthy task page.
            if ($error->errorCode !== 'BROWSER_TIMEZONE_APPLY_FAILED') {
                $this->browserTimezoneFailure = $error;
                $this->browserTimezone->close();
            }
            $this->connectionFailed($error->errorCode, ['code' => 0, 'message' => $error->getMessage()]);
            return false;
        }
    }

    /** Route Chromium downloads into the project-private temp directory and require lifecycle events. */
    private function configureTaskDownloads(callable $after): void
    {
        $this->taskDownloadsReady = false;
        if (!$this->taskCdp->ready()) { $after(); return; }
        try { $path = str_replace('/', DIRECTORY_SEPARATOR, ArtifactStore::downloadRoot()); }
        catch (\Throwable) { $after(); return; }
        $this->taskCdp->send('Browser.setDownloadBehavior', [
            'behavior' => 'allowAndName',
            'downloadPath' => $path,
            'eventsEnabled' => true,
        ], null, function ($error) use ($after): void {
            $this->taskDownloadsReady = $error === null;
            $after();
        }, 5.0);
    }

    private function prepareTaskSurface(string $responseId, int $commandId, bool $recover): void
    {
        if ((TaskLedger::execution($responseId)['turn']['error_code'] ?? '') === 'CODEX_USER_STOP_PENDING') {
            $this->finishCommand($commandId, ['status' => 'user_stop_pending']);
            return;
        }
        if (!$this->taskCdp->ready()) {
            if ($recover) $this->pendingResumes[$responseId] = true;
            else {
                BrowserState::defer($commandId);
                $this->commandBusy = false;
            }
            return;
        }
        $ledger = TaskLedger::execution($responseId);
        $turn = $ledger['turn'];
        $surface = $ledger['surface'];
        $state = BrowserState::state();
        $port = (int) ($state['debug_port'] ?? 0);
        if ($state['status'] !== 'ready' || $port < 1) {
            $this->taskFailTurn($responseId, 'CHATGPT_BROWSER_NOT_READY', '受控浏览器尚未就绪。', $recover);
            return;
        }
        $targetId = (string) ($surface['target_id'] ?? '');
        $targets = $this->discoverTargetsIfAvailable($port);
        if ($targets === null) {
            // Failure to observe DevTools is not proof that the task page disappeared.
            if (time() < (int)$turn['deadline_at']) {
                Timer::add(0.5, fn() => $this->prepareTaskSurface($responseId, $commandId, $recover), null, false);
            } else $this->taskFailTurn($responseId, 'CHATGPT_SURFACE_NOT_READY', '任务页面状态暂不可读取，等待已超时。', true);
            return;
        }
        if ($targetId !== '' && isset($targets[$targetId])) {
            $this->attachTaskSurface($responseId, $commandId, $targetId, (string) ($targets[$targetId]['url'] ?? ''), $recover);
            return;
        }
        if ((string) ($turn['status'] ?? '') !== 'queued') {
            try {
                $job = ResponseJob::execution($responseId);
                $retry = TaskLedger::prepareConnectorNoStartRetry($responseId, (string)$job['prompt'],
                    (string)$surface['owner_incarnation'], (int)$surface['fencing_version'], 3, true);
                if (!empty($retry['retry'])) {
                    $this->releaseTaskSession($responseId);
                    Timer::add(0.02, fn() => $this->prepareTaskSurface($responseId, $commandId, false), null, false);
                    return;
                }
            } catch (GatewayError $error) {
                $this->taskFailTurn($responseId, $error->errorCode, $error->getMessage(), true);
                return;
            }
            $this->taskFailTurn($responseId, 'RECOVERY_OUTCOME_UNKNOWN', '原任务页面已经丢失，上一提交结果无法确认；不会自动重发。', true);
            return;
        }
        if ($targetId !== '' || !empty($ledger['job']['continuation_source_response_id'])) {
            // A saved target id is not evidence that the physical page still exists. A fresh page
            // must receive canonical history, never the suffix prepared for the previous page.
            try {
                $repair = \app\gateway\model\ContextRecovery::repairUnsent($responseId, 'CHATGPT_SURFACE_MISSING');
                if ($repair === null) {
                    $this->taskFailTurn($responseId, 'RECOVERY_OUTCOME_UNKNOWN', '原页面已经丢失，当前请求不能安全重建；不会重复提交。', true);
                    return;
                }
                $this->releaseTaskSession($responseId);
                $recover = false;
            } catch (GatewayError $error) {
                $this->taskFailTurn($responseId, $error->errorCode, $error->getMessage(), true);
                return;
            }
        }
        // Create an inert document first. Starting network navigation in Target.createTarget and
        // then attaching/evaluating immediately can race ChatGPT's SPA bootstrap and trigger a
        // second navigation to the same Temporary Chat URL. Attach and size the surface first,
        // then let prepareTaskTemporarySurface perform the one intentional navigation.
        $this->taskCdp->send('Target.createTarget', NativeBrowserWindow::backgroundPageParameters(
            ['url' => 'about:blank'], BrowserState::state()), null,
            function ($error, $result) use ($responseId, $commandId, $recover): void {
                $targetId = (string) ($result['targetId'] ?? '');
                if ($error !== null || $targetId === '') {
                    $detail = is_array($error) ? ' [CDP ' . (int) ($error['code'] ?? 0) . ']' : '';
                    $this->taskFailTurn($responseId, 'CHATGPT_SURFACE_CREATE_FAILED', '无法为任务创建独立 ChatGPT 页面。' . $detail);
                    return;
                }
                $this->attachTaskSurface($responseId, $commandId, $targetId, 'about:blank', $recover);
            }, 15.0);
    }

    /** Attach the owned task page; Page.enable uses the preparation budget without renewing its turn deadline. */
    private function attachTaskSurface(string $responseId, int $commandId, string $targetId, string $url, bool $recover): void
    {
        $this->taskCdp->send('Target.attachToTarget', ['targetId' => $targetId, 'flatten' => true], null,
            function ($error, $result) use ($responseId, $commandId, $targetId, $url, $recover): void {
                $sessionId = (string) ($result['sessionId'] ?? '');
                if ((TaskLedger::execution($responseId)['turn']['error_code'] ?? '') === 'CODEX_USER_STOP_PENDING') {
                    if ($sessionId !== '') $this->detachTaskSessionRaw($sessionId);
                    $this->finishCommand($commandId, ['status' => 'user_stop_pending']);
                    return;
                }
                if ($error !== null || $sessionId === '') {
                    $this->taskFailTurn($responseId, 'CHATGPT_SURFACE_ATTACH_FAILED', '无法附着任务 ChatGPT 页面。', $recover);
                    return;
                }
                try {
                    $ledger = TaskLedger::execution($responseId);
                    if (\app\gateway\model\SteeringContext::isDelta(ResponseJob::execution($responseId))
                        && ($ledger['turn']['response_id'] !== $responseId
                            || $ledger['turn']['generation_id'] !== $ledger['job']['generation_id'])) {
                        $this->detachTaskSessionRaw($sessionId);
                        $this->finishCommand($commandId, ['status' => 'superseded']);
                        return;
                    }
                    $ownership = TaskLedger::claimSurface((string) $ledger['surface']['id'], $targetId, $sessionId, $url);
                    $this->taskSessions[$sessionId] = [
                        'surface_id' => (string) $ledger['surface']['id'],
                        'owner_incarnation' => (string) $ownership['owner_incarnation'],
                        'fencing_version' => (int) $ownership['fencing_version'],
                        'response_id' => $responseId,
                        'main_frame_id' => null,
                        'security_challenge_active' => false,
                        'security_challenge_path' => null,
                    ];
                } catch (GatewayError $ledgerError) {
                    $this->detachTaskSessionRaw($sessionId);
                    $this->taskFailTurn($responseId, $ledgerError->errorCode, $ledgerError->getMessage(), $recover);
                    return;
                }
                // Page.enable is a local control subscription, not a model response. A busy
                // renderer may acknowledge it late. Reuse the configured preparation budget,
                // capped by this turn's existing deadline, without extending the task lifetime.
                $pageOwner = $this->taskSessions[$sessionId];
                $pageGeneration = (string) $ledger['job']['generation_id'];
                $pageDeadline = min((float) $ledger['turn']['deadline_at'], microtime(true)
                    + (float) Config::get('gateway', 'browser_start_timeout_seconds'));
                $pageWait = max(0.0, $pageDeadline - microtime(true));
                $pageEnabled = function ($pageError) use ($responseId, $commandId, $sessionId, $recover, $pageOwner, $pageGeneration, $pageWait): void {
                    $currentOwner = $this->taskSessions[$sessionId] ?? [];
                    foreach (['response_id','surface_id','owner_incarnation','fencing_version'] as $key) {
                        if (($currentOwner[$key] ?? null) !== ($pageOwner[$key] ?? null)) return;
                    }
                    if (!$this->taskCdp->ready()) return;
                    try { $currentLedger = TaskLedger::pollExecution($responseId); }
                    catch (GatewayError) { return; }
                    if ((string) $currentLedger['turn']['generation_id'] !== $pageGeneration
                        || (string) $currentLedger['surface']['owner_incarnation'] !== (string) $pageOwner['owner_incarnation']
                        || (int) $currentLedger['surface']['fencing_version'] !== (int) $pageOwner['fencing_version']
                        || in_array($currentLedger['turn']['status'], ['failed','cancelled','superseded','committed','needs_attention'], true)) return;
                    if (in_array($currentLedger['turn']['error_code'] ?? '', ['CODEX_USER_STOP_PENDING','CODEX_USER_STOP_WAITING_TOOLS'], true)) {
                        unset($this->taskSessions[$sessionId]);
                        $this->detachTaskSessionRaw($sessionId);
                        $this->finishCommand($commandId, ['status'=>'user_stop_pending']);
                        return;
                    }
                    if ($pageError !== null) {
                        try {
                            if (!TaskLedger::browserPageInitializationFailed($responseId, $pageError, $pageWait,
                                (string) $pageOwner['owner_incarnation'], (int) $pageOwner['fencing_version'])) return;
                        } catch (GatewayError $observationError) {
                            if (in_array($observationError->errorCode, ['surface_fence_lost','response_superseded','task_ledger_missing'], true)) return;
                        } catch (\Throwable) { /* Diagnostic failure must not replace the original CDP failure. */ }
                        $this->taskFailTurn($responseId, 'CHATGPT_PAGE_ENABLE_FAILED', '任务页面控制能力初始化失败。', $recover);
                        return;
                    }
                    $this->taskCdp->send('Page.getFrameTree', [], $sessionId,
                        function ($frameError, $frameResult) use ($sessionId): void {
                            if ($frameError !== null || !isset($this->taskSessions[$sessionId])) return;
                            $frameId = (string) ($frameResult['frameTree']['frame']['id'] ?? '');
                            if ($frameId !== '') $this->taskSessions[$sessionId]['main_frame_id'] = $frameId;
                        }, 3.0);
                    $this->taskCdp->send('Network.enable', [], $sessionId, function ($networkError) use ($responseId, $commandId, $sessionId, $recover): void {
                        if ($networkError !== null) {
                            $this->taskFailTurn($responseId, 'CHATGPT_NETWORK_OBSERVATION_FAILED', '任务页面网络状态监听初始化失败。', $recover);
                            return;
                        }
                        $this->taskCdp->send('Emulation.setDeviceMetricsOverride', [
                            'width' => 1280, 'height' => 720, 'deviceScaleFactor' => 1, 'mobile' => false,
                        ], $sessionId, function ($metricsError) use ($responseId, $commandId, $sessionId, $recover): void {
                            if ($metricsError !== null) {
                                $this->taskFailTurn($responseId, 'CHATGPT_VIEWPORT_FAILED', '任务页面尺寸初始化失败。', $recover);
                                return;
                            }
                            $this->taskCdp->send('Emulation.setFocusEmulationEnabled', ['enabled'=>true], $sessionId,
                                function ($focusError) use ($responseId, $commandId, $sessionId, $recover): void {
                                    if ($focusError !== null) { $this->taskFailTurn($responseId,'CHATGPT_FOCUS_FAILED','任务页面无法保持交互焦点。',$recover); return; }
                                    $this->applyTaskBrowserIdentity($sessionId,
                                        fn() => $this->prepareTaskTemporarySurface($responseId, $commandId, $sessionId, $recover), $recover);
                                }, 3.0);
                        }, 3.0);
                    }, 3.0);
                };
                if ($pageWait <= 0.0) {
                    $pageEnabled(['code'=>-2, 'message'=>'BROWSER_CDP_TIMEOUT']);
                    return;
                }
                $this->taskCdp->send('Page.enable', [], $sessionId, $pageEnabled, $pageWait);
            }, 5.0);
    }

    /**
     * Declare the same RCMAKER identity on every claimed task session, including retained turns
     * and reconnects. CDP overrides end when a session detaches, so fresh-page-only initialization
     * would lose the identity on the next user turn. This changes no document or navigation and
     * does not resend work. Prefer the task's secure document; a fresh about:blank page
     * may reuse matching native metadata or read the secure setup page as a fallback.
     * Every override is verified on the owned task session before an unsent task proceeds.
     * Login/setup pages are never overridden here. Recovery preserves already submitted work.
     *
     * @param string $sessionId Claimed owned task session.
     * @param callable $after Continue the existing surface initialization after this bounded step.
     * @param bool $recover Whether this session reconnects to work already submitted.
     * @param int $attempt Internal bounded retry count; no navigation or submission is repeated.
     */
    private function applyTaskBrowserIdentity(string $sessionId, callable $after, bool $recover = false, int $attempt = 1): void
    {
        if (!isset($this->taskSessions[$sessionId]) || !$this->taskCdp->ready()) return;
        $owner = $this->taskSessions[$sessionId];
        $responseId = (string)$owner['response_id'];
        $current = function () use ($sessionId, $owner): bool {
            $session = $this->taskSessions[$sessionId] ?? [];
            return $this->taskCdp->ready() && ($session['response_id'] ?? null) === $owner['response_id']
                && ($session['owner_incarnation'] ?? null) === $owner['owner_incarnation']
                && ($session['fencing_version'] ?? null) === $owner['fencing_version'];
        };
        $continue = function () use ($current, $after): void {
            if ($current()) $after();
        };
        $failed = function (string $code) use ($sessionId, $responseId, $after, $recover, $attempt, $continue, $current): void {
            if (!$current()) return;
            if ($attempt < 3) {
                Timer::add(0.2, function () use ($current, $sessionId, $after, $recover, $attempt): void {
                    if ($current()) $this->applyTaskBrowserIdentity($sessionId, $after, $recover, $attempt + 1);
                }, null, false);
                return;
            }
            $this->taskSessions[$sessionId]['browser_identity_applied'] = false;
            if (!$this->recordTaskBrowserIdentity($sessionId, false, $code, $attempt)) {
                unset($this->taskSessions[$sessionId]);
                $this->detachTaskSessionRaw($sessionId);
                return;
            }
            // A reconnect cannot cancel or replay previously submitted effects merely
            // because identity initialization is temporarily unreadable. Keep observing it.
            if ($recover) { $continue(); return; }
            $this->taskFailTurn($responseId, 'CHATGPT_BROWSER_IDENTITY_UNVERIFIED',
                '任务页产品标识初始化未能验证，任务尚未发送。');
            if ($current()) {
                unset($this->taskSessions[$sessionId]);
                $this->detachTaskSessionRaw($sessionId);
            }
        };
        $this->taskCdp->send('Browser.getVersion', [], null,
            function ($versionError, $version) use ($sessionId, $continue, $failed, $attempt, $current): void {
                if (!$current()) return;
                $userAgent = (string) ($version['userAgent'] ?? '');
                if ($versionError !== null || $userAgent === '') { $failed('version_unavailable'); return; }
                $apply = function (?array $parameters) use ($sessionId, $userAgent, $continue, $failed, $attempt, $current): void {
                    if (!$current()) return;
                    if ($parameters === null) { $failed('native_metadata_unavailable'); return; }
                    $this->taskBrowserIdentityCache = ['native_user_agent'=>$userAgent, 'parameters'=>$parameters];
                    $this->taskCdp->send('Network.setUserAgentOverride', $parameters, $sessionId,
                        function ($error) use ($sessionId, $parameters, $continue, $failed, $attempt, $current): void {
                            if (!$current()) return;
                            if ($error !== null) { $failed('override_failed'); return; }
                            $expression = '(()=>({user_agent:navigator.userAgent,platform:navigator.platform}))()';
                            $this->taskCdp->send('Runtime.evaluate', ['expression'=>$expression, 'returnByValue'=>true], $sessionId,
                                function ($verifyError, $result) use ($sessionId, $parameters, $continue, $failed, $attempt, $current): void {
                                    if (!$current()) return;
                                    $actual = $result['result']['value'] ?? null;
                                    if ($verifyError !== null || !is_array($actual)
                                        || ($actual['user_agent'] ?? null) !== $parameters['userAgent']
                                        || ($actual['platform'] ?? null) !== $parameters['platform']) {
                                        $failed('verification_failed'); return;
                                    }
                                    $this->taskSessions[$sessionId]['browser_identity_applied'] = true;
                                    if (!$this->recordTaskBrowserIdentity($sessionId, true, 'verified', $attempt)) {
                                        unset($this->taskSessions[$sessionId]);
                                        $this->detachTaskSessionRaw($sessionId);
                                        return;
                                    }
                                    $continue();
                                }, 3.0);
                        }, 3.0);
                };
                if (($this->taskBrowserIdentityCache['native_user_agent'] ?? null) === $userAgent) {
                    $apply($this->taskBrowserIdentityCache['parameters']);
                    return;
                }
                $this->readTaskBrowserIdentity($sessionId, $userAgent, $apply);
            }, 3.0);
    }

    /** Read native hints on the task; only a fresh/insecure page needs the unchanged secure setup fallback. */
    private function readTaskBrowserIdentity(string $sessionId, string $userAgent, callable $after): void
    {
        $expression = <<<'JS'
(async()=>{
  const data=navigator.userAgentData;
  if(!data||typeof data.getHighEntropyValues!=='function')return null;
  const value={...data.toJSON(),...await data.getHighEntropyValues([
    'architecture','bitness','fullVersionList','model','platformVersion','wow64'
  ])};
  return {platform:navigator.platform,metadata:value};
})()
JS;
        $this->taskCdp->send('Runtime.evaluate', ['expression'=>$expression, 'returnByValue'=>true, 'awaitPromise'=>true], $sessionId,
            function ($error, $result) use ($sessionId, $userAgent, $expression, $after): void {
                if (!isset($this->taskSessions[$sessionId])) return;
                $identity = $result['result']['value'] ?? null;
                $parameters = $error === null && is_array($identity)
                    ? BrowserProcess::taskIdentityParameters($userAgent, $identity) : null;
                if ($parameters !== null || !$this->cdp->ready()) { $after($parameters); return; }
                $this->cdp->send('Runtime.evaluate', ['expression'=>$expression, 'returnByValue'=>true, 'awaitPromise'=>true], null,
                    function ($fallbackError, $fallback) use ($sessionId, $userAgent, $after): void {
                        if (!isset($this->taskSessions[$sessionId])) return;
                        $identity = $fallback['result']['value'] ?? null;
                        $after($fallbackError === null && is_array($identity)
                            ? BrowserProcess::taskIdentityParameters($userAgent, $identity) : null);
                    }, 3.0);
            }, 3.0);
    }

    /** Diagnostics are best-effort; return false only for a missing session or authoritative ownership loss. */
    private function recordTaskBrowserIdentity(string $sessionId, bool $verified, string $code, int $attempt): bool
    {
        $session = $this->taskSessions[$sessionId] ?? null;
        if (!is_array($session)) return false;
        try {
            TaskLedger::browserIdentityObserved((string)$session['response_id'], $verified, $code, $attempt,
                (string)$session['owner_incarnation'], (int)$session['fencing_version']);
        } catch (\Throwable $error) {
            $this->taskSessions[$sessionId]['browser_identity_audit_error'] = $error instanceof GatewayError
                ? $error->errorCode : get_class($error);
            if ($error instanceof GatewayError && in_array($error->errorCode, ['response_superseded', 'surface_fence_lost'], true)) {
                return false;
            }
        }
        return true;
    }

    /** Ensure a fresh task owns an isolated Temporary Chat before any prompt state is observed. */
    private function prepareTaskTemporarySurface(string $responseId, int $commandId, string $sessionId, bool $recover): void
    {
        if (!$recover && \app\gateway\model\SteeringContext::isDelta(ResponseJob::execution($responseId))) {
            $this->prepareSteeringSurface($responseId, $commandId, $sessionId, microtime(true) + 30.0);
            return;
        }
        $surface = TaskLedger::execution($responseId)['surface'];
        $retainedDocument = WebModelPage::isTemporaryChatUrl((string)($surface['url'] ?? ''), self::modelUrl());
        $this->evaluateTaskSession($sessionId, WebModelPage::capabilityExpression(),
            function ($error, $capability) use ($responseId, $commandId, $sessionId, $recover, $retainedDocument): void {
                if ($error === null && is_array($capability)) {
                    if (!empty($capability['login_required'])) {
                        $this->taskFailTurn($responseId, 'authentication_required', 'ChatGPT 网页需要重新登录。', $recover);
                        return;
                    }
                    if (!empty($capability['temporary_chat'])
                        && WebModelPage::isTemporaryChatUrl((string) ($capability['url'] ?? ''), self::modelUrl())) {
                        $this->waitTaskSurfaceReady($responseId, $commandId, $sessionId, $recover, microtime(true) + 20.0);
                        return;
                    }
                }
                if ($retainedDocument) {
                    // A slow observation must not navigate/reload an owned conversation.
                    $this->waitTaskSurfaceReady($responseId, $commandId, $sessionId, $recover, microtime(true) + 20.0);
                    return;
                }
                if ($recover) {
                    $this->taskFailTurn(
                        $responseId,
                        'RECOVERY_OUTCOME_UNKNOWN',
                        '恢复任务时原页面已经离开 Temporary Chat；不会导航或自动重发。',
                        true
                    );
                    return;
                }
                $this->taskCdp->send('Page.navigate', ['url' => self::taskModelUrl()], $sessionId,
                    function ($navigateError) use ($responseId, $commandId, $sessionId): void {
                        if ($navigateError !== null) {
                            $this->taskFailTurn($responseId, 'CHATGPT_TEMPORARY_CHAT_NAVIGATION_FAILED', '无法进入 ChatGPT 临时对话。');
                            return;
                        }
                        $this->waitTaskSurfaceReady($responseId, $commandId, $sessionId, false, microtime(true) + 60.0);
                    }, 60.0);
            }, 3.0);
    }

    /**
     * Stop the proven source, observe idle twice, then submit the incremental user message.
     * A slow stop observation retains this document until the existing durable deadline;
     * only positively invalid/lost page evidence permits full-context recovery.
     */
    private function prepareSteeringSurface(string $responseId, int $commandId, string $sessionId,
        float $deadline, bool $clicked = false, ?float $idleSince = null): void
    {
        if (!$this->steeringSessionOwns($responseId, $sessionId)) {
            $this->releaseSteeringPreparation($responseId, $sessionId);
            return;
        }
        $job = ResponseJob::execution($responseId);
        $surface = TaskLedger::execution($responseId)['surface'];
        if (!\app\gateway\model\SteeringContext::retained($job, $surface)) {
            if ($this->recoverSteeringPreparation($responseId, $sessionId)) return;
            $this->taskFailTurn($responseId, 'steering_source_unavailable', '插话原页或压缩代次已变化；未发送新消息。', true);
            $this->releaseSteeringPreparation($responseId, $sessionId);
            return;
        }
        $source = ResponseJob::execution((string) $job['budget']['steering_delta']['witness_response_id']);
        preg_match_all('/(?<![A-Za-z0-9_-])turn_[A-Za-z0-9_-]{32}(?![A-Za-z0-9_-])/', (string) $source['prompt'], $tokens);
        $witnesses = count(array_unique($tokens[0])) === 1 ? [(string) $tokens[0][0]] : [(string) $source['prompt']];
        if (!empty($source['transport_transaction_id'])) $witnesses[] = (string) $source['transport_transaction_id'];
        $expression = WebModelPage::userStopExpression((string) $surface['url'], $witnesses,
            (array) $source['baseline_users'], !$clicked, true, self::acceptedInitialUser($source, $surface));
        $this->evaluateTaskSession($sessionId, $expression,
            function ($error, $state) use ($responseId, $commandId, $sessionId, $deadline, $clicked, $idleSince): void {
                if (!$this->steeringSessionOwns($responseId, $sessionId)) {
                    $this->releaseSteeringPreparation($responseId, $sessionId);
                    return;
                }
                if ($error === null && is_array($state) && !empty($state['owned'])) {
                    $clicked = $clicked || !empty($state['stopped']);
                    if (empty($state['generation_running']) && !empty($state['editor_ready'])) {
                        if ($idleSince !== null && microtime(true) - $idleSince >= 1.0) {
                            $this->startTaskTurn($responseId, $commandId, $sessionId, false);
                            return;
                        }
                        $idleSince ??= microtime(true);
                    } else $idleSince = null;
                } else $idleSince = null;
                if (!empty($state['page_invalid'])) {
                    if ($this->recoverSteeringPreparation($responseId, $sessionId)) return;
                }
                if (microtime(true) >= $deadline) {
                    $ledger = TaskLedger::execution($responseId);
                    $targets = $this->discoverTargetsIfAvailable((int)(BrowserState::state()['debug_port'] ?? 0));
                    if (is_array($targets) && !isset($targets[(string)$ledger['surface']['target_id']])
                        && $this->recoverSteeringPreparation($responseId, $sessionId)) return;
                    if (microtime(true) < (float)$ledger['turn']['deadline_at']) {
                        // Missing DOM evidence is not a missing page. Keep the bounded
                        // original deadline and expose why the stop could not be confirmed.
                        TaskLedger::observationDelayed($responseId, 'steering_stop', (array)$state + [
                            'stop_clicked'=>$clicked, 'observation_failed'=>$error !== null || !is_array($state)]);
                        $deadline = min((float)$ledger['turn']['deadline_at'], microtime(true) + 30.0);
                        Timer::add(0.25, fn() => $this->prepareSteeringSurface($responseId, $commandId, $sessionId,
                            $deadline, $clicked, $idleSince), null, false);
                        return;
                    }
                    $this->taskFailTurn($responseId, 'steering_stop_unconfirmed', '未能确认插话原页已停止且历史仍在；未发送新消息或全量重发。', true);
                    $this->releaseSteeringPreparation($responseId, $sessionId);
                    return;
                }
                Timer::add(0.25, fn() => $this->prepareSteeringSurface($responseId, $commandId, $sessionId,
                    $deadline, $clicked, $idleSince), null, false);
            }, 3.0);
    }

    /** Recover an unsent steering request through full durable context and confirmed old-target closure. */
    private function recoverSteeringPreparation(string $responseId, string $sessionId): bool
    {
        // This new instruction has not been sent. The existing recovery transaction proves
        // that fact and rebuilds its complete durable history before any target is replaced.
        try {
            $repair = \app\gateway\model\ContextRecovery::repairUnsent($responseId, 'CHATGPT_SURFACE_MISSING');
        } catch (GatewayError $error) {
            $this->taskFailTurn($responseId, $error->errorCode, $error->getMessage(), true);
            $this->releaseSteeringPreparation($responseId, $sessionId);
            return true;
        }
        if ($repair === null) return false;
        $this->releaseSteeringPreparation($responseId, $sessionId);
        $generation = ResponseJob::execution($responseId)['generation_id'];
        $resume = function () use ($responseId, $repair, $generation): void {
            $ledger = TaskLedger::execution($responseId);
            if ($ledger['turn']['response_id'] !== $responseId || $ledger['turn']['generation_id'] !== $generation
                || $ledger['turn']['status'] !== 'queued') return;
            $this->prepareTaskSurface($responseId, (int)$repair['command_id'], false);
        };
        if (empty($repair['target_id'])) $resume();
        else $this->taskCdp->send('Target.closeTarget', ['targetId' => $repair['target_id']], null,
            function ($error, $result) use ($responseId, $resume): void {
                if ($error !== null || empty($result['success'])) {
                    $this->taskFailTurn($responseId, 'CHATGPT_REPAIR_CLOSE_FAILED', '原任务页未能关闭，停止恢复。');
                    return;
                }
                $resume();
            }, 3.0);
        return true;
    }

    /** Reject late stop callbacks after another steer, compaction, cancellation or session claim wins. */
    private function steeringSessionOwns(string $responseId, string $sessionId): bool
    {
        $session = $this->taskSessions[$sessionId] ?? null;
        if (!is_array($session) || ($session['response_id'] ?? '') !== $responseId) return false;
        $ledger = TaskLedger::execution($responseId);
        $job = ResponseJob::execution($responseId);
        return $ledger['turn']['response_id'] === $responseId && $ledger['turn']['generation_id'] === $job['generation_id']
            && $ledger['turn']['status'] === 'queued' && empty($ledger['turn']['error_code'])
            && in_array($job['status'], ['queued', 'running'], true) && empty($job['submit_intent_at'])
            && $ledger['surface']['owner_incarnation'] === $session['owner_incarnation']
            && (int) $ledger['surface']['fencing_version'] === (int) $session['fencing_version'];
    }

    /** Release a pre-composer observer without closing its target or detaching a newer surface owner. */
    private function releaseSteeringPreparation(string $responseId, string $sessionId): void
    {
        $session = $this->taskSessions[$sessionId] ?? null;
        if (!is_array($session) || ($session['response_id'] ?? '') !== $responseId) return;
        try {
            TaskLedger::detachSurface((string) $session['surface_id'], (string) $session['owner_incarnation'],
                (int) $session['fencing_version']);
        } catch (GatewayError $error) {
            if ($error->errorCode !== 'surface_fence_lost') throw $error;
        }
        unset($this->taskSessions[$sessionId]);
        $this->detachTaskSessionRaw($sessionId);
    }

    private function waitTaskSurfaceReady(string $responseId, int $commandId, string $sessionId, bool $recover, float $deadline): void
    {
        $this->evaluateTaskSession($sessionId, WebModelPage::capabilityExpression(), function ($error, $capability) use ($responseId, $commandId, $sessionId, $recover, $deadline): void {
            if ($error === null && is_array($capability)) {
                if (!empty($capability['login_required'])) {
                    $this->taskFailTurn($responseId, 'authentication_required', 'ChatGPT 网页需要重新登录。', $recover);
                    return;
                }
                $temporary = !empty($capability['temporary_chat'])
                    && WebModelPage::isTemporaryChatUrl((string) ($capability['url'] ?? ''), self::modelUrl());
                $readyForRecovery = $recover && !empty($capability['on_chatgpt']) && $temporary;
                $readyForFresh = !$recover && !empty($capability['on_chatgpt']) && $temporary
                    && !empty($capability['editor_ready']) && empty($capability['generation_running']);
                if ($readyForRecovery || $readyForFresh) {
                    $this->startTaskTurn($responseId, $commandId, $sessionId, $recover);
                    return;
                }
                if (!empty($capability['on_chatgpt']) && !empty($capability['editor_ready']) && !$temporary) {
                    $this->taskFailTurn(
                        $responseId,
                        $recover ? 'RECOVERY_OUTCOME_UNKNOWN' : 'CHATGPT_TEMPORARY_CHAT_REQUIRED',
                        $recover ? '恢复任务时原页面已经离开 Temporary Chat；不会自动重发。' : '任务页面没有保持在 ChatGPT Temporary Chat。',
                        $recover
                    );
                    return;
                }
            }
            if (microtime(true) >= $deadline) {
                $this->taskFailTurn($responseId, $recover ? 'RECOVERY_OUTCOME_UNKNOWN' : 'CHATGPT_SURFACE_NOT_READY',
                    $recover ? '恢复任务时无法确认原页面状态；不会自动重发。' : '任务 ChatGPT 页面在限定时间内未进入可发送状态。', $recover);
                return;
            }
            Timer::add(0.25, fn() => $this->waitTaskSurfaceReady($responseId, $commandId, $sessionId, $recover, $deadline), null, false);
        }, 2.0);
    }

    private function startTaskTurn(string $responseId, int $commandId, string $sessionId, bool $recover): void
    {
        if ((TaskLedger::execution($responseId)['turn']['error_code'] ?? '') === 'CODEX_USER_STOP_PENDING') {
            $this->detachTaskSessionRaw($sessionId);
            $this->finishCommand($commandId, ['status' => 'user_stop_pending']);
            return;
        }
        try {
            $job = ResponseJob::execution($responseId);
            $toolCapable = !empty($job['mcp_binding_id']);
            $toolSettings = $toolCapable ? GatewaySettings::internal() : [];
            $connectorName = $toolCapable ? ToolBroker::connectorNameForJob($job) : '';
            ResponseJob::start($responseId);
            $ledger = TaskLedger::execution($responseId);
            $continuationState = TaskLedger::continuationState($responseId);
            $surface = $ledger['surface'];
            $session = $this->taskSessions[$sessionId] ?? null;
            if (!is_array($session)) throw new GatewayError('CHATGPT_SURFACE_ATTACH_FAILED', '任务页面控制会话已经失效。', 409);
            $roundPrefix = '';
            $continuation = false;
            if (is_string($job['previous_response_id'] ?? null) && $job['previous_response_id'] !== '') {
                try {
                    $prior = ResponseJob::execution((string) $job['previous_response_id']);
                    // A recovered receipt round has a new page and must send its rebuilt
                    // context. Only the document that owns the original POST can keep polling.
                    if ((string) ($prior['round_kind'] ?? '') === 'tool_call'
                        && !empty($job['submitted_document_nonce'])
                        && $job['submitted_document_nonce'] === $surface['document_nonce']) {
                        $continuation = true;
                        $roundPrefix = (string) ($prior['browser_prefix'] ?? '');
                    }
                } catch (\Throwable) {}
            }
            $this->chatGptTurns[$responseId] = [
                'command_id' => $commandId,
                'response_id' => $responseId,
                'logical_response_id' => $responseId,
                'operation_kind' => (string) ($job['operation_kind'] ?? 'response'),
                'durable_turn_id' => (string) ($job['durable_turn_id'] ?? ''),
                'prompt' => (string) $job['prompt'],
                'attachments' => (array) ($job['attachments'] ?? []),
                'requested_effort' => $job['reasoning_effort'] ?? null,
                // A newly created/about:blank surface must still prove the Temporary Chat
                // root before its first submit. A fenced retained /c/ page already has that
                // history even if the client omitted previous_response_id on a new turn.
                'require_root_before_send' => !WebModelPage::isTemporaryChatUrl((string) ($surface['url'] ?? ''), self::modelUrl())
                    || WebModelPage::isTemporaryChatStartUrl((string) ($surface['url'] ?? ''), self::modelUrl()),
                'session_id' => $sessionId,
                'surface_id' => (string) $surface['id'],
                'target_id' => (string) ($surface['target_id'] ?? ''),
                'owner_incarnation' => (string) $session['owner_incarnation'],
                'fencing_version' => (int) $session['fencing_version'],
                'surface_generation' => (int) $surface['generation'],
                'document_nonce' => (string) $surface['document_nonce'],
                'baseline_users' => (array) ($job['baseline_users'] ?? []),
                'baseline_assistants' => (array) ($job['baseline_assistants'] ?? []),
                'bound_assistant_id' => null,
                'accepted' => $continuation || (int) ($job['accepted_at'] ?? 0) > 0,
                'sent_at' => $continuation
                    ? (float) ((int) ($ledger['turn']['submit_intent_at'] ?? time()))
                    : (float) ((int) ($job['submit_intent_at'] ?? 0)),
                // A resumed multipart compact keeps its durable preparation window;
                // restoring a worker must not clamp it to a new summary-only timeout.
                'deadline' => ($job['operation_kind'] ?? 'response') === 'response'
                    ? min((float) $ledger['turn']['deadline_at'], microtime(true) + (int) ($job['budget']['response_timeout_seconds'] ?? 600))
                    : (float) $ledger['turn']['deadline_at'],
                'emitted' => (string) ($job['output_text'] ?? ''),
                'draft_emitted' => $toolCapable ? ResponseJob::draftText($responseId) : '',
                'continuation_marker' => (string)($continuationState['marker'] ?? ''),
                'continuation_pending' => !empty($continuationState['pending']),
                'continuation_intent_at' => (int)($continuationState['intent_at'] ?? 0),
                'continuation_sending' => false,
                'compaction_phase' => null,
                'round_prefix' => $roundPrefix,
                'tool_capable' => $toolCapable,
                'connector_name' => $connectorName,
                // ChatGPT keeps the conversation page but does not reliably carry
                // Connector tool access into a distinct user message. Reselect the
                // exact Connector for every new turn; same-turn continuation below
                // may reuse it after an actual native receipt.
                'retained_connector' => false,
                'connector_attempt' => 0,
                'connector_preparing' => false,
                'connector_catalog_refresh_used' => false,
                'connector_catalog_refresh_pending' => false,
                'auto_approve_tool_calls' => $toolCapable && !empty($toolSettings['auto_approve_tool_calls']),
                'tool_confirmation_snapshot_next' => false,
                'tool_confirmations_approved' => 0,
                'observed_markdown' => null,
                'observed_content' => false,
                'observed_commentary' => [],
                'emitted_commentary' => [],
                'emitted_commentary_text' => [],
                'artifact_observed_candidates' => [],
                'post_tool_answer_baseline' => $continuation ? $roundPrefix : null,
                'post_tool_answer_missing_since' => 0.0,
                'empty_response_health' => null,
                'tool_boundary_pending_id' => null,
                'tool_boundary_pending_at' => 0.0,
                'tool_completion_signature' => null,
                'tool_completion_revision' => null,
                'tool_completion_since' => 0.0,
                'completion_fence_revision' => null,
                'completion_fence_signature' => null,
                'settle_text' => null,
                'settle_at' => 0.0,
                'poll_errors' => 0,
                'poll_error_since' => 0.0,
                'personalization_state' => 'unchecked',
                'personalization_fallback_used' => false,
                'personalization_original_index' => null,
                'personalization_rollback_attempted' => false,
                'model_capabilities' => null,
                'requested_mode' => null,
                'multipart' => null,
                'transport_stage_sent' => (int) ($job['transport_stage_sent'] ?? 0),
                'transport_stage_acked' => (int) ($job['transport_stage_acked'] ?? 0),
                'transport_transaction_id' => $job['transport_transaction_id'] ?? null,
                'transport_parts_total' => (int) ($job['transport_parts_total'] ?? 0),
                'transport_stage_effort' => $job['transport_stage_effort'] ?? null,
                'transport_final_effort' => $job['transport_final_effort'] ?? null,
                'staging_mode_verified' => null,
                'multipart_mismatch_signature' => null,
                'multipart_mismatch_since' => 0.0,
                'multipart_ack_repair_stage' => 0,
                'security_challenge_active' => !empty($session['security_challenge_active']),
                'security_challenge_path' => $session['security_challenge_path'] ?? null,
            ];
            if (!empty($job['continuation_source_response_id'])
                && !\app\gateway\model\ContextHistory::retainedContext($job, $surface)) {
                $this->taskFailTurn($responseId, 'CHATGPT_SURFACE_MISSING', '续接源页面已变化，需要恢复完整上下文。', $recover);
                return;
            }
            if ($recover) {
                if (($job['operation_kind'] ?? 'response') !== 'response'
                    && !empty($job['previous_response_id'])
                    && ContextCompaction::retainedHandoffState($responseId) !== null) {
                    $received = ContextCompaction::recoverAcceptedRetainedHandoff($responseId);
                    if ($received !== null) {
                        // Consume only the persisted receipt and its exact audited source
                        // answer. No token, prompt or host call is reissued after restart.
                        $this->chatGptTurns[$responseId]['compaction_phase'] = 'waiting';
                        $this->chatGptTurns[$responseId]['compaction_handoff_id'] = $received['handoff_id'];
                        $this->chatGptTurns[$responseId]['compaction_source_answer'] = $received['source_answer'];
                        $this->scheduleTaskPoll($responseId, 0.08);
                        return;
                    }
                    // A pending control token was kept only by the original worker.
                    // Without its receipt a restart cannot authorize another message.
                    $this->taskFailTurn($responseId, 'compaction_handoff_outcome_unknown',
                        '摘要交接已保留或尝试发送，但工作进程重启后无法确认原页面结果；原上下文仍保留。', true);
                    return;
                }
                if ((int) ($job['transport_stage_sent'] ?? 0) > 0 && (int) ($job['submit_intent_at'] ?? 0) === 0) {
                    $this->resumeTaskMultipart($responseId);
                    return;
                }
                $this->scheduleTaskPoll($responseId, 0.08);
                return;
            }
            $this->confirmTaskEditor($responseId);
        } catch (GatewayError $error) {
            $this->taskFailTurn($responseId, $error->errorCode, $error->getMessage(), $recover);
        }
    }

    /** Wait briefly for an unsent temporary chat's composer to settle after navigation. */
    private function confirmTaskEditor(string $responseId, int $attempt = 0): void
    {
        if (!isset($this->chatGptTurns[$responseId])) return;
        $this->evaluateTask($responseId, WebModelPage::baselineExpression(), function ($error, $baseline) use ($responseId, $attempt): void {
            $turn = $this->chatGptTurns[$responseId] ?? null;
            if (!is_array($turn)) return;
            $ready = $error === null && is_array($baseline) && !empty($baseline['temporary_chat'])
                && WebModelPage::isTemporaryChatUrl((string) ($baseline['url'] ?? ''), self::modelUrl())
                && (empty($turn['require_root_before_send'])
                    || WebModelPage::isTemporaryChatStartUrl((string) ($baseline['url'] ?? ''), self::modelUrl()))
                && !empty($baseline['editor_ready']) && empty($baseline['generation_running']);
            if (!$ready) {
                if ($attempt < 5 && empty($turn['sent_at']) && microtime(true) + 1.0 < (float) $turn['deadline']) {
                    Timer::add(1.0, fn() => $this->confirmTaskEditor($responseId, $attempt + 1), null, false);
                    return;
                }
                $this->taskFailTurn($responseId, 'CHATGPT_EDITOR_NOT_READY', 'ChatGPT 编辑器不可用或页面已有生成任务。');
                return;
            }
            $this->chatGptTurns[$responseId]['baseline_users'] = is_array($baseline['user_ids'] ?? null) ? $baseline['user_ids'] : [];
            $this->chatGptTurns[$responseId]['baseline_assistants'] = is_array($baseline['assistant_ids'] ?? null) ? $baseline['assistant_ids'] : [];
            $this->chatGptTurns[$responseId]['bound_assistant_id'] = null;
            $this->prepareTaskModel($responseId, false);
        });
    }

    /** Reconstruct the saved transaction and reconcile the exact in-flight ACK before doing anything else. */
    private function resumeTaskMultipart(string $responseId): void
    {
        $turn = $this->chatGptTurns[$responseId] ?? null;
        if (!is_array($turn)) return;
        $transactionId = is_string($turn['transport_transaction_id'] ?? null) ? (string) $turn['transport_transaction_id'] : '';
        if ($transactionId === '') {
            $this->taskFailTurn($responseId, 'RECOVERY_OUTCOME_UNKNOWN', 'Multipart 恢复缺少事务标识；不会自动重发。', true);
            return;
        }
        try {
            $job = ResponseJob::execution($responseId);
            $plan = ChatGptTransport::multipart((string) $turn['prompt'], $transactionId, (int) $job['transport_parts_total'],
                $job['transport_budget'] ?? $job['budget']);
        } catch (\Throwable $error) {
            $this->taskFailTurn($responseId, 'RECOVERY_OUTCOME_UNKNOWN', 'Multipart 恢复无法重建原事务；不会自动重发。', true);
            return;
        }
        $this->chatGptTurns[$responseId]['multipart'] = $plan;
        $sent = (int) ($turn['transport_stage_sent'] ?? 0);
        $acked = (int) ($turn['transport_stage_acked'] ?? 0);
        if ($sent > $acked) {
            $this->waitMultipartStageAcknowledgement($responseId, $sent, microtime(true) + $this->multipartStageTimeoutSeconds($responseId), true);
            return;
        }
        $this->prepareTaskModel($responseId, true);
    }

    /** Detect account model capabilities, then select the staging/final ChatGPT effort explicitly. */
    private function prepareTaskModel(string $responseId, bool $recover): void
    {
        if (!$this->modelSelectionActive($responseId)) return;
        $this->probeTaskModelControls($responseId, $recover,
            microtime(true) + self::TASK_MODEL_CONTROL_READY_SECONDS, null);
    }

    private function probeTaskModelControls(string $responseId, bool $recover, float $deadline, ?float $absenceSince): void
    {
        if (!$this->modelSelectionActive($responseId)) return;
        $this->evaluateModelSelection($responseId, WebModelPage::modelControlStateExpression(),
            function ($error, $state) use ($responseId, $recover, $deadline, $absenceSince): void {
                if (!$this->modelSelectionActive($responseId)) return;
                if ($error !== null || !is_array($state) || empty($state['ok'])) {
                    if (microtime(true) < $deadline) {
                        Timer::add(0.12, fn() => $this->probeTaskModelControls($responseId, $recover, $deadline, null), null, false);
                        return;
                    }
                    $this->failModelSelection($responseId, 'CHATGPT_MODEL_CONTROL_UNAVAILABLE', '无法读取 ChatGPT 模型控制状态。', $recover);
                    return;
                }
                $count = (int) ($state['effort_control_count'] ?? 0);
                $thinkCount = (int) ($state['think_control_count'] ?? 0);
                if ($count > 1) {
                    $this->failModelSelection($responseId, 'CHATGPT_MODEL_CONTROL_INVALID', 'ChatGPT 同时暴露了多个模型控制器。', $recover);
                    return;
                }
                if ($thinkCount > 1) {
                    $this->failModelSelection($responseId, 'CHATGPT_MODEL_CONTROL_INVALID', 'ChatGPT 同时暴露了多个 Think 控制器。', $recover);
                    return;
                }
                if ($count === 1 && !empty($state['sol_available'])) {
                    $this->openTaskEffortMenu($responseId, $recover, microtime(true) + 4.0);
                    return;
                }
                $since = $absenceSince ?? microtime(true);
                $plan = strtolower((string) ($state['account_plan'] ?? 'unknown'));
                $lunaPlan = in_array($plan, ['free', 'go'], true);
                if (!empty($state['document_ready']) && $lunaPlan && microtime(true) - $since >= 1.5) {
                    $this->applyTaskModelCapabilities($responseId, [
                        'account_plan' => $plan,
                        'sol_available' => false,
                        'extra_high_available' => false,
                        'pro_available' => false,
                        'think_available' => $thinkCount === 1 && is_bool($state['think_pressed'] ?? null),
                    ], $recover);
                    return;
                }
                if (microtime(true) >= $deadline) {
                    $this->failModelSelection(
                        $responseId,
                        'CHATGPT_MODEL_CONTROL_UNAVAILABLE',
                        $lunaPlan
                            ? 'ChatGPT Luna / Think 控制器没有进入稳定状态。'
                            : 'ChatGPT 当前账户的推理档位控制器没有进入稳定状态；不会降级为 Luna / Think。',
                        $recover
                    );
                    return;
                }
                Timer::add(0.12, fn() => $this->probeTaskModelControls($responseId, $recover, $deadline, $since), null, false);
            });
    }

    /** Open the initial capability picker; preserve its deadline and bounded remount retries. */
    private function openTaskEffortMenu(string $responseId, bool $recover, float $deadline, int $reopenAttempts = 0): void
    {
        $this->evaluateModelSelection($responseId, WebModelPage::focusEffortControlExpression(),
            function ($error, $focused) use ($responseId, $recover, $deadline, $reopenAttempts): void {
                if ($error !== null || !is_array($focused) || empty($focused['ok'])) {
                    // The final multipart commit follows two page updates. Its effort
                    // control can briefly be detached while ChatGPT settles the editor.
                    // Focusing is reversible; retry within the existing menu deadline.
                    $this->evaluateModelSelection($responseId, WebModelPage::dismissOptionalSetupDialogExpression(),
                        function ($dismissError, $dismissed) use ($responseId, $recover, $deadline, $reopenAttempts): void {
                            if ($dismissError === null && !empty($dismissed['ok']) && !empty($dismissed['dismissed'])) {
                                Timer::add(0.15, fn() => $this->openTaskEffortMenu($responseId, $recover,
                                    max($deadline, microtime(true) + 4.0), $reopenAttempts), null, false);
                                return;
                            }
                            if (microtime(true) < $deadline) {
                                Timer::add(0.15, fn() => $this->openTaskEffortMenu($responseId, $recover, $deadline, $reopenAttempts), null, false);
                                return;
                            }
                            $this->failModelSelection($responseId, 'CHATGPT_MODEL_CONTROL_UNAVAILABLE',
                                'ChatGPT 模型控制器无法获得焦点；请检查并关闭 ChatGPT 弹窗。', $recover);
                        });
                    return;
                }
                if (!empty($focused['expanded'])) {
                    $this->waitTaskEffortSlider($responseId, $recover, $deadline, $reopenAttempts);
                    return;
                }
                $this->dispatchModelSelectionKey($responseId, 'Enter', function ($keyError) use ($responseId, $recover, $deadline, $reopenAttempts): void {
                    if ($keyError !== null && ($keyError['message'] ?? '') !== 'BROWSER_CDP_TIMEOUT') {
                        $this->failModelSelection($responseId, 'CHATGPT_MODEL_CONTROL_UNAVAILABLE', 'ChatGPT 模型菜单无法打开。', $recover);
                        return;
                    }
                    $this->waitTaskEffortSlider($responseId, $recover, $deadline, $reopenAttempts);
                });
            });
    }

    /** Read initial capabilities; reopen only after independently observing a closed picker. */
    private function waitTaskEffortSlider(string $responseId, bool $recover, float $deadline, int $reopenAttempts = 0): void
    {
        $this->evaluateModelSelection($responseId, WebModelPage::effortSliderExpression(),
            function ($error, $slider) use ($responseId, $recover, $deadline, $reopenAttempts): void {
                if ($error === null && is_array($slider) && !empty($slider['ok'])) {
                    $this->applyTaskModelCapabilities($responseId, ChatGptTransport::modelCapabilities($slider), $recover, $slider);
                    return;
                }
                if (microtime(true) < $deadline) {
                    $this->evaluateModelSelection($responseId, WebModelPage::modelControlStateExpression(),
                        function ($stateError, $state) use ($responseId, $recover, $deadline, $reopenAttempts): void {
                            $closed = $stateError === null && is_array($state)
                                && ($state['effort_menu_expanded'] ?? null) === false;
                            if ($closed && $reopenAttempts < 2 && microtime(true) < $deadline) {
                                Timer::add(0.08, fn() => $this->openTaskEffortMenu(
                                    $responseId, $recover, $deadline, $reopenAttempts + 1), null, false);
                                return;
                            }
                            Timer::add(0.08, fn() => $this->waitTaskEffortSlider(
                                $responseId, $recover, $deadline, $reopenAttempts), null, false);
                        });
                    return;
                }
                $this->failModelSelection($responseId, 'CHATGPT_MODEL_CONTROL_UNAVAILABLE', 'ChatGPT 推理档位滑块没有进入可验证状态。', $recover);
            });
    }

    private function applyTaskModelCapabilities(string $responseId, array $capabilities, bool $recover, ?array $openSlider = null): void
    {
        if (str_starts_with($responseId, 'connector_model_')) {
            if (!$this->modelSelectionActive($responseId)) return;
            $owner = $this->connectorModelSelections[$responseId];
            try {
                $mode = ChatGptTransport::executionMode([], $capabilities, true);
                $after = function () use ($responseId, $owner, $mode): void {
                    if (!$this->modelSelectionActive($responseId)) return;
                    $this->connectorProbeModes[(int) $owner['command_id']] = $mode;
                    unset($this->connectorModelSelections[$responseId]);
                    $this->beginConnectorProbe((int) $owner['command_id'], (string) $owner['connector_name'], 1);
                };
                if (is_int($mode['ui_index'] ?? null)) $this->selectTaskEffort($responseId, $mode, $openSlider, $after, false);
                else $this->setTaskThinkMode($responseId, !empty($mode['think']), $after, false);
            } catch (GatewayError $error) {
                $this->failModelSelection($responseId, $error->errorCode, $error->getMessage());
            }
            return;
        }
        $turn = $this->chatGptTurns[$responseId] ?? null;
        if (!is_array($turn)) return;
        try {
            // The job already passed account admission. A different task's current slider
            // must not replace that account directory or decide the requested family is absent.
            $logicalId = ($turn['compaction_phase'] ?? null) === 'preparing'
                ? (string) ($turn['logical_response_id'] ?? $responseId) : $responseId;
            $job = ResponseJob::execution($logicalId);
            $capabilities = \app\gateway\model\ModelCatalog::taskCapabilities($job, $capabilities);
            $requested = ChatGptTransport::executionMode($job, $capabilities);
            if (is_string($requested['family'] ?? null) && empty($capabilities['sol_available'])) {
                throw new GatewayError('CHATGPT_MODEL_UNAVAILABLE', '当前页面未提供所选 ChatGPT 模型家族，未发送任务。', 409);
            }
            $this->chatGptTurns[$responseId]['model_capabilities'] = $capabilities;
            $this->chatGptTurns[$responseId]['requested_mode'] = $requested;
            if (($turn['compaction_phase'] ?? null) === 'preparing') {
                // A restart may discard the source tab's picker cache. Fresh controls above
                // prove the logical checkpoint's High mode before any handoff text is composed.
                $effective = ChatGptTransport::physicalBudget((array) $job['budget'], $requested, $capabilities);
                ChatGptTransport::assertFinalMessageFits((string) $turn['prompt'], $effective);
                $after = fn() => $this->prepareTaskConnector($responseId, (string) $turn['connector_name'], 1);
                if (is_int($requested['ui_index'] ?? null)) $this->selectTaskEffort($responseId, $requested, $openSlider, $after, false);
                else $this->setTaskThinkMode($responseId, !empty($requested['think']), $after, false);
                return;
            }
            $toolCapable = !empty($turn['tool_capable']);
            $hasContext = str_contains((string) $turn['prompt'], '<codex_context_json>')
                && str_contains((string) $turn['prompt'], '</codex_context_json>');
            $jobBudget = ResponseJob::execution($responseId)['budget'];
            $effectiveBudget = $jobBudget ?: \app\gateway\model\ContextBudget::snapshot('chatgpt-web/current', $requested['effort'], $capabilities);
            $effectiveBudget = ChatGptTransport::physicalBudget($effectiveBudget, $requested, $capabilities);
            $attachmentTokens = AttachmentStore::tokenReserve((array) ($turn['attachments'] ?? []), $effectiveBudget);
            $compact = in_array((string) ($job['operation_kind'] ?? 'response'), ['compact_v1','compact_v2'], true);
            $transportBudget = $compact ? array_replace($effectiveBudget, ['final_attachment_tokens'=>$attachmentTokens]) : null;
            $decisionEvidence = null;
            if (!$hasContext) ChatGptTransport::assertFinalMessageFits((string) $turn['prompt'], $effectiveBudget, $attachmentTokens);
            if ($hasContext && !empty($turn['transport_transaction_id'])) {
                $transportBudget = $job['transport_budget'] ?? $jobBudget;
                $plan = ChatGptTransport::multipart((string) $turn['prompt'], (string) $turn['transport_transaction_id'],
                    (int) $job['transport_parts_total'], $transportBudget);
                ChatGptTransport::assertFinalMessageFits((string) $plan['final_text'], $effectiveBudget, $attachmentTokens);
            } else $plan = $hasContext ? ChatGptTransport::plan(
                    (string) $turn['prompt'],
                    $effectiveBudget,
                    GatewaySettings::internal(), $capabilities,
                    is_string($turn['transport_transaction_id'] ?? null) ? (string) $turn['transport_transaction_id'] : null,
                    $attachmentTokens, $decisionEvidence, $this->isFirstFreeConversation($job, $capabilities)
            ) : null;
            \app\gateway\model\ContextDecision::record($responseId, (string)$turn['prompt'], $effectiveBudget, $plan, $attachmentTokens, $decisionEvidence);
            if (is_array($plan)) {
                // A pinned recovery cannot repartition acknowledged history. Validate only the
                // remaining stages against this family's Medium envelope; final-only recovery
                // must not be blocked by the size of already accepted historical messages.
                $acked = (int) ($job['transport_stage_acked'] ?? 0);
                $remaining = array_slice((array)$plan['stages'], $acked);
                $remainingChars = 0; $remainingTokens = 0;
                foreach ($remaining as $stage) {
                    $remainingChars = max($remainingChars, mb_strlen((string)$stage['text']));
                    $remainingTokens = max($remainingTokens, \app\gateway\model\ContextBudget::tokens((string)$stage['text'], $transportBudget ?? $effectiveBudget));
                }
                $staging = ChatGptTransport::stagingMode($capabilities, $remainingChars,
                    $remainingTokens, (int) $plan['staging_context_tokens'], $requested['family'] ?? null);
                // Upgrade only new Free transactions. Acknowledged legacy stages retain
                // their recorded physical mode so restart recovery cannot change their identity.
                if (($capabilities['account_plan'] ?? '') === 'free' && empty($capabilities['sol_available'])
                    && !empty($job['transport_transaction_id']) && ($job['transport_stage_effort'] ?? '') === 'low') {
                    $staging = ChatGptTransport::requestedMode('low', $capabilities)
                        + ['family'=>null, 'purpose'=>'context_stage'];
                }
                $this->chatGptTurns[$responseId]['staging_mode'] = $staging;
                ResponseJob::configureMultipart(
                    $responseId,
                    (string) $plan['transaction_id'],
                    (int) $plan['total_parts'],
                    (string) $staging['effort'],
                    (string) $requested['effort'],
                    $transportBudget
                );
                $job = ResponseJob::execution($responseId);
                if (in_array((string) ($job['operation_kind'] ?? 'response'), ['compact_v1', 'compact_v2'], true)) {
                    $this->chatGptTurns[$responseId]['deadline'] = (float) TaskLedger::execution($responseId)['turn']['deadline_at'];
                }
                $this->chatGptTurns[$responseId]['multipart'] = $plan;
                $this->chatGptTurns[$responseId]['transport_transaction_id'] = $plan['transaction_id'];
                $this->chatGptTurns[$responseId]['transport_parts_total'] = (int) $plan['total_parts'];
                $this->chatGptTurns[$responseId]['transport_stage_sent'] = (int) ($job['transport_stage_sent'] ?? 0);
                $this->chatGptTurns[$responseId]['transport_stage_acked'] = (int) ($job['transport_stage_acked'] ?? 0);
                $this->chatGptTurns[$responseId]['transport_stage_effort'] = (string) $staging['effort'];
                $acked = (int) ($job['transport_stage_acked'] ?? 0);
                if ($acked < count((array) $plan['stages'])) {
                    $this->selectTaskEffort($responseId, $staging, $openSlider,
                        function () use ($responseId, $staging): void {
                            if (!$this->modelSelectionActive($responseId)) return;
                            $turn = $this->chatGptTurns[$responseId];
                            $this->chatGptTurns[$responseId]['staging_mode_verified'] = [
                                'effort' => (string) $staging['effort'],
                                'family' => $staging['family'] ?? null,
                                'document_nonce' => (string) $turn['document_nonce'],
                                'surface_id' => (string) $turn['surface_id'],
                            ];
                            $this->sendNextMultipartStage($responseId);
                        }, $recover);
                    return;
                }
                $this->selectTaskEffort($responseId, $requested, $openSlider,
                    fn() => $this->refreshTaskFinalBaseline($responseId), $recover);
                return;
            }
            if (!empty($capabilities['sol_available'])) {
                $this->selectTaskEffort($responseId, $requested, $openSlider,
                    fn() => $this->prepareTaskComposer($responseId), $recover);
                return;
            }
            $this->setTaskThinkMode($responseId, !empty($requested['think']),
                fn() => $this->prepareTaskComposer($responseId), $recover);
        } catch (GatewayError $error) {
            $this->failModelSelection($responseId, $error->errorCode, $error->getMessage(), $recover);
        } catch (\Throwable $error) {
            $this->failModelSelection($responseId, 'CHATGPT_MODEL_CONTROL_UNAVAILABLE', 'ChatGPT 模型档位准备失败：' . $error->getMessage(), $recover);
        }
    }

    /**
     * Limit the Free inline exception to the first unsent request of a new durable task.
     * Existing tasks, imported history, recovery and paid accounts retain their pinned planning policy.
     * @param array $job Current internal browser job. @param array $capabilities Verified account state.
     * @return bool False on missing evidence; this optional optimization never weakens admission.
     */
    private function isFirstFreeConversation(array $job, array $capabilities): bool
    {
        if (($capabilities['account_plan'] ?? '') !== 'free'
            || !in_array($job['model'] ?? '', ['chatgpt-web/luna', 'chatgpt-web/think'], true)) return false;
        try {
            $stored = TaskLedger::execution((string) $job['id'])['job'];
            if (!ChatGptTransport::canUseFreeInitialInline($stored, $capabilities)) return false;
            $others = SDB(null, 'medoo', 'sqlite')->table('gateway_response_jobs')->where('task_id', $stored['task_id'])
                ->where('id', '<>', $stored['id'])->count();
            return is_numeric($others) && (int) $others === 0;
        } catch (\Throwable) {
            // Missing optional scope evidence keeps the established transport, never a new task failure.
            return false;
        }
    }

    /** Select a verified Think toggle or move the semantic effort slider to the requested value. */
    private function selectTaskEffort(string $responseId, array $mode, ?array $slider, callable $after, bool $recover): void
    {
        if (!is_int($mode['ui_index'] ?? null)) {
            $this->setTaskThinkMode($responseId, !empty($mode['think']), $after, $recover);
            return;
        }
        if (!is_array($slider) || empty($slider['ok'])) {
            $this->openTaskEffortMenuForSelection($responseId, $mode, $after, $recover);
            return;
        }
        $family = $mode['family'] ?? null;
        if (!is_string($family) || $family === '') {
            $this->moveTaskEffort($responseId, $mode, $slider, $after, $recover);
            return;
        }
        if (ChatGptTransport::familyChecked($slider, $family)) {
            $this->moveTaskEffort($responseId, $mode, $slider, $after, $recover);
            return;
        }
        $this->selectTaskFamily($responseId, $mode, $after, $recover, microtime(true) + 4.0);
    }

    /**
     * Open advanced view once per menu and verify the family before changing effort.
     * Reopening requires an independent closed-menu readback and is bounded; neither
     * a view-toggle acknowledgement nor a family click can authorize task submission.
     */
    private function selectTaskFamily(string $responseId, array $mode, callable $after, bool $recover,
        float $deadline, int $reopenAttempts = 0, bool $viewExpanded = false): void
    {
        $this->evaluateModelSelection($responseId, WebModelPage::selectFamilyExpression((string) $mode['family'], !$viewExpanded),
            function ($error, $selected) use ($responseId, $mode, $after, $recover, $deadline, $reopenAttempts, $viewExpanded): void {
                $viewExpanded = $viewExpanded || ($error === null && is_array($selected) && !empty($selected['view_expanded']));
                if ($error === null && is_array($selected) && !empty($selected['ok'])) {
                    // A click may close the Radix picker asynchronously; read its actual
                    // state before any further focus or relative slider key.
                    Timer::add(0.15, fn() => $this->readTaskEffortForSelection(
                        $responseId, $mode, $after, $recover, microtime(true) + 4.0, true), null, false);
                    return;
                }
                if (microtime(true) >= $deadline) {
                    $this->failModelSelection($responseId, 'CHATGPT_MODEL_FAMILY_UNAVAILABLE',
                        '无法选择指定的 ChatGPT 模型家族，未发送任务。', $recover);
                    return;
                }
                $this->evaluateModelSelection($responseId, WebModelPage::modelControlStateExpression(),
                    function ($stateError, $state) use ($responseId, $mode, $after, $recover, $deadline, $reopenAttempts, $viewExpanded): void {
                        $closed = $stateError === null && is_array($state)
                            && ($state['effort_menu_expanded'] ?? null) === false;
                        if ($closed && $reopenAttempts < 2) {
                            $this->evaluateModelSelection($responseId, WebModelPage::focusEffortControlExpression(),
                                function ($focusError, $focused) use ($responseId, $mode, $after, $recover, $deadline, $reopenAttempts): void {
                                    if ($focusError !== null || !is_array($focused) || empty($focused['ok'])) {
                                        $this->failModelSelection($responseId, 'CHATGPT_MODEL_CONTROL_UNAVAILABLE',
                                            '无法重新打开 ChatGPT 模型菜单。', $recover);
                                        return;
                                    }
                                    $retry = fn() => Timer::add(0.15, fn() => $this->selectTaskFamily(
                                        $responseId, $mode, $after, $recover, $deadline, $reopenAttempts + 1), null, false);
                                    if (!empty($focused['expanded'])) { $retry(); return; }
                                    $this->dispatchModelSelectionKey($responseId, 'Enter',
                                        function ($keyError) use ($responseId, $recover, $retry): void {
                                            if ($keyError !== null && ($keyError['message'] ?? '') !== 'BROWSER_CDP_TIMEOUT') {
                                                $this->failModelSelection($responseId, 'CHATGPT_MODEL_CONTROL_UNAVAILABLE',
                                                    'ChatGPT 模型菜单无法重新打开。', $recover);
                                                return;
                                            }
                                            $retry();
                                        });
                                });
                            return;
                        }
                        Timer::add(0.08, fn() => $this->selectTaskFamily($responseId, $mode, $after,
                            $recover, $deadline, $reopenAttempts, $viewExpanded), null, false);
                    });
            });
    }

    /** Open the effort picker; reopening is bounded and requires a separate closed-state readback. */
    private function openTaskEffortMenuForSelection(string $responseId, array $mode, callable $after, bool $recover, bool $familySelected = false, int $reopenAttempts = 0): void
    {
        $this->evaluateModelSelection($responseId, WebModelPage::focusEffortControlExpression(),
            function ($error, $focused) use ($responseId, $mode, $after, $recover, $familySelected, $reopenAttempts): void {
                if ($error !== null || !is_array($focused) || empty($focused['ok'])) {
                    $this->failModelSelection($responseId, 'CHATGPT_MODEL_CONTROL_UNAVAILABLE', '无法重新打开 ChatGPT 推理档位菜单。', $recover);
                    return;
                }
                $read = fn() => $this->readTaskEffortForSelection($responseId, $mode, $after, $recover, microtime(true) + 4.0, $familySelected, $reopenAttempts);
                if (!empty($focused['expanded'])) { $read(); return; }
                $this->dispatchModelSelectionKey($responseId, 'Enter', function ($keyError) use ($responseId, $recover, $read): void {
                    if ($keyError !== null && ($keyError['message'] ?? '') !== 'BROWSER_CDP_TIMEOUT') {
                        $this->failModelSelection($responseId, 'CHATGPT_MODEL_CONTROL_UNAVAILABLE', '无法重新打开 ChatGPT 推理档位菜单。', $recover);
                        return;
                    }
                    $read();
                });
            });
    }

    /** Read the exact family and effort, recovering a proved closed picker at most twice. */
    private function readTaskEffortForSelection(string $responseId, array $mode, callable $after, bool $recover, float $deadline, bool $familySelected = false, int $reopenAttempts = 0): void
    {
        $this->evaluateModelSelection($responseId, WebModelPage::effortSliderExpression(),
            function ($error, $slider) use ($responseId, $mode, $after, $recover, $deadline, $familySelected, $reopenAttempts): void {
                if ($error === null && is_array($slider) && !empty($slider['ok'])) {
                    if (is_string($mode['family'] ?? null) && !ChatGptTransport::familyChecked($slider, $mode['family'])) {
                        if (!$familySelected) {
                            $this->selectTaskEffort($responseId, $mode, $slider, $after, $recover);
                            return;
                        }
                        if (microtime(true) < $deadline) {
                            Timer::add(0.08, fn() => $this->readTaskEffortForSelection($responseId, $mode, $after, $recover, $deadline, true, $reopenAttempts), null, false);
                            return;
                        }
                        $this->failModelSelection($responseId, 'CHATGPT_MODEL_FAMILY_UNPROVEN', '无法确认指定的 ChatGPT 模型家族，未发送任务。', $recover);
                        return;
                    }
                    $this->moveTaskEffort($responseId, $mode, $slider, $after, $recover);
                    return;
                }
                // Only refocus/reopen after a separate DOM read proves the picker
                // closed. A synchronous focus return is not proof that it stayed open.
                $this->evaluateModelSelection($responseId, WebModelPage::modelControlStateExpression(),
                    function ($stateError, $state) use ($responseId, $mode, $after, $recover, $deadline, $familySelected, $reopenAttempts, $slider): void {
                        $expanded = $stateError === null && is_array($state) ? ($state['effort_menu_expanded'] ?? null) : null;
                        if ($reopenAttempts < 2 && $expanded === false && microtime(true) < $deadline) {
                            $this->openTaskEffortMenuForSelection($responseId, $mode, $after, $recover, $familySelected, $reopenAttempts + 1);
                            return;
                        }
                        if (microtime(true) < $deadline) {
                            Timer::add(0.08, fn() => $this->readTaskEffortForSelection($responseId, $mode, $after, $recover, $deadline, $familySelected, $reopenAttempts), null, false);
                            return;
                        }
                        $sliderCode = is_array($slider) && in_array($slider['code'] ?? null,
                            ['CHATGPT_EFFORT_SLIDER_MISSING', 'CHATGPT_EFFORT_SLIDER_INVALID'], true)
                            ? $slider['code'] : 'UNKNOWN';
                        $menuState = $expanded === true ? 'open' : ($expanded === false ? 'closed' : 'unknown');
                        $this->failModelSelection($responseId, 'CHATGPT_MODEL_CONTROL_UNAVAILABLE',
                            'ChatGPT 推理档位菜单无法读取（' . $sliderCode . ', menu=' . $menuState . '）。', $recover);
                    });
            });
    }

    /** Move only to an explicitly available requested effort; never downgrade a blocked Pro request. */
    private function moveTaskEffort(string $responseId, array $mode, array $slider, callable $after, bool $recover): void
    {
        if (is_string($mode['family'] ?? null) && !ChatGptTransport::familyChecked($slider, $mode['family'])) {
            $this->failModelSelection($responseId, 'CHATGPT_MODEL_FAMILY_DRIFT', 'ChatGPT 模型家族在切换档位时发生变化，未发送任务。', $recover);
            return;
        }
        $availability = ChatGptTransport::availabilityError($slider, $mode);
        if ($availability !== null) {
            $this->failModelSelection($responseId, $availability['code'], $availability['message'], $recover);
            return;
        }
        $target = (int) ($slider['min'] ?? 0) + (int) $mode['ui_index'];
        $current = (int) ($slider['value'] ?? -1);
        if ($current === $target) {
            $this->closeTaskEffortMenu($responseId, $after, $recover);
            return;
        }
        $this->evaluateModelSelection($responseId, WebModelPage::focusEffortSliderOwnerExpression(),
            function ($error, $focused) use ($responseId, $mode, $slider, $after, $recover, $target, $current): void {
                if ($error !== null || !is_array($focused) || empty($focused['ok'])) {
                    $this->failModelSelection($responseId, 'CHATGPT_MODEL_CONTROL_UNAVAILABLE', 'ChatGPT 推理档位滑块无法获得焦点。', $recover);
                    return;
                }
                $key = $target > $current ? 'ArrowRight' : 'ArrowLeft';
                $this->dispatchModelSelectionKey($responseId, $key, function ($keyError) use ($responseId, $mode, $after, $recover, $current, $key): void {
                    if ($keyError !== null && ($keyError['message'] ?? '') !== 'BROWSER_CDP_TIMEOUT') {
                        $this->failModelSelection($responseId, 'CHATGPT_MODEL_CONTROL_UNAVAILABLE', 'ChatGPT 推理档位无法切换。', $recover);
                        return;
                    }
                    // Observe the exact effect of the relative key, including quota-driven range changes.
                    $expected = $current + ($key === 'ArrowRight' ? 1 : -1);
                    Timer::add(0.08, fn() => $this->observeTaskEffortMove($responseId, $mode, $current, $expected,
                        $after, $recover, microtime(true) + 4.0), null, false);
                });
            });
    }
    /** Read back one relative key's exact effect, allowing delayed rendering but never blind replay. */
    private function observeTaskEffortMove(string $responseId, array $mode, int $before, int $expected, callable $after, bool $recover, float $deadline): void
    {
        if (!$this->modelSelectionActive($responseId)) return;
        $this->evaluateModelSelection($responseId, WebModelPage::effortSliderExpression(),
            function ($error, $slider) use ($responseId, $mode, $before, $expected, $after, $recover, $deadline): void {
                if (!$this->modelSelectionActive($responseId)) return;
                if ($error === null && is_array($slider) && !empty($slider['ok'])) {
                    if (!is_string($mode['family'] ?? null) || ChatGptTransport::familyChecked($slider, $mode['family'])) {
                        $availability = ChatGptTransport::availabilityError($slider, $mode);
                        if ($availability !== null) {
                            $this->failModelSelection($responseId, $availability['code'], $availability['message'], $recover);
                            return;
                        }
                    }
                    $actual = (int) ($slider['value'] ?? -99);
                    if ($actual === $expected) {
                        $this->moveTaskEffort($responseId, $mode, $slider, $after, $recover);
                        return;
                    }
                    if ($actual !== $before) {
                        $this->failModelSelection($responseId, 'CHATGPT_MODEL_SELECTION_DRIFT', 'ChatGPT 推理档位发生非预期变化，未发送任务。', $recover);
                        return;
                    }
                }
                if (microtime(true) < $deadline) {
                    Timer::add(0.12, fn() => $this->observeTaskEffortMove($responseId, $mode, $before, $expected, $after, $recover, $deadline), null, false);
                    return;
                }
                $this->failModelSelection($responseId, 'CHATGPT_MODEL_CONTROL_UNAVAILABLE', '未能确认 ChatGPT 推理档位切换结果，未发送任务。', $recover);
            });
    }

    /** Close a proven model menu once; read its rendered state even if the key acknowledgement is lost. */
    private function closeTaskEffortMenu(string $responseId, callable $after, bool $recover): void
    {
        $this->dispatchModelSelectionKey($responseId, 'Escape', function ($error) use ($responseId, $after, $recover): void {
            if ($error !== null && ($error['message'] ?? '') !== 'BROWSER_CDP_TIMEOUT') {
                $this->failModelSelection($responseId, 'CHATGPT_MODEL_CONTROL_UNAVAILABLE', 'ChatGPT 模型菜单无法关闭。', $recover);
                return;
            }
            $this->waitTaskEffortMenuClosed($responseId, $after, $recover, microtime(true) + 4.0);
        });
    }

    /** Continue only after the same page proves the effort menu is closed; no task text is sent here. */
    private function waitTaskEffortMenuClosed(string $responseId, callable $after, bool $recover, float $deadline): void
    {
        if (!$this->modelSelectionActive($responseId)) return;
        $this->evaluateModelSelection($responseId, WebModelPage::modelControlStateExpression(),
            function ($error, $state) use ($responseId, $after, $recover, $deadline): void {
                if (!$this->modelSelectionActive($responseId)) return;
                if ($error === null && is_array($state) && !empty($state['ok']) && ($state['effort_menu_expanded'] ?? null) === false) {
                    Timer::add(0.12, $after, null, false);
                    return;
                }
                if (microtime(true) < $deadline) {
                    Timer::add(0.12, fn() => $this->waitTaskEffortMenuClosed($responseId, $after, $recover, $deadline), null, false);
                    return;
                }
                $this->failModelSelection($responseId, 'CHATGPT_MODEL_CONTROL_UNAVAILABLE', '无法确认 ChatGPT 模型菜单已关闭，未发送任务。', $recover);
            });
    }

    /** Luna/Think uses ChatGPT's own /think command and validates aria-pressed after the command. */
    private function setTaskThinkMode(string $responseId, bool $enabled, callable $after, bool $recover): void
    {
        $this->evaluateModelSelection($responseId, WebModelPage::thinkStateExpression(),
            function ($error, $state) use ($responseId, $enabled, $after, $recover): void {
                if ($error !== null || !is_array($state) || empty($state['ok'])) {
                    $this->failModelSelection($responseId, 'CHATGPT_THINK_STATE_INVALID', '无法读取 Luna / Think 状态。', $recover);
                    return;
                }
                if ((!$enabled && (int) ($state['count'] ?? 0) === 0) || (!empty($state['pressed']) === $enabled && (int) ($state['count'] ?? 0) === 1)) {
                    $after();
                    return;
                }
                // Free's current composer exposes a real, stateful Think button.
                // Activate only that verified button and read its state back; keep
                // the older slash-command flow for legacy pages without a toggle.
                if ((int) ($state['count'] ?? 0) === 1) {
                    $this->activateTaskThinkControl($responseId, $enabled, $after, $recover);
                    return;
                }
                $this->evaluateModelSelection($responseId, WebModelPage::focusAndClearExpression(),
                    function ($clearError, $cleared) use ($responseId, $enabled, $after, $recover): void {
                        if ($clearError !== null || !is_array($cleared) || empty($cleared['ok'])) {
                            $this->failModelSelection($responseId, 'CHATGPT_THINK_CHANGE_FAILED', '无法准备 Think 命令。', $recover);
                            return;
                        }
                        $this->evaluateModelSelection($responseId, WebModelPage::insertPlainTextExpression('/think'),
                            function ($insertError, $inserted) use ($responseId, $enabled, $after, $recover): void {
                                if ($insertError !== null || !is_array($inserted) || empty($inserted['ok'])) {
                                    $this->failModelSelection($responseId, 'CHATGPT_THINK_CHANGE_FAILED', '无法输入 Think 命令。', $recover);
                                    return;
                                }
                                $this->waitTaskThinkMenu($responseId, $enabled, $after, $recover, microtime(true) + 5.0);
                            });
                    });
            });
    }

    private function waitTaskThinkMenu(string $responseId, bool $enabled, callable $after, bool $recover, float $deadline): void
    {
        $this->evaluateModelSelection($responseId, WebModelPage::thinkCommandMenuExpression(),
            function ($error, $menu) use ($responseId, $enabled, $after, $recover, $deadline): void {
                if ($error === null && is_array($menu) && !empty($menu['ok'])) {
                    $activate = function () use ($responseId, $enabled, $after, $recover): void {
                        $this->dispatchModelSelectionKey($responseId, 'Enter', function ($keyError) use ($responseId, $enabled, $after, $recover): void {
                            if ($keyError !== null) {
                                $this->failModelSelection($responseId, 'CHATGPT_THINK_CHANGE_FAILED', 'Think 命令无法执行。', $recover);
                                return;
                            }
                            $this->waitTaskThinkState($responseId, $enabled, $after, $recover, microtime(true) + 5.0);
                        });
                    };
                    if (!empty($menu['highlighted'])) { $activate(); return; }
                    $this->dispatchModelSelectionKey($responseId, 'ArrowDown', function ($keyError) use ($responseId, $recover, $activate): void {
                        if ($keyError !== null) {
                            $this->failModelSelection($responseId, 'CHATGPT_THINK_CHANGE_FAILED', 'Think 命令菜单无法选择。', $recover);
                            return;
                        }
                        $activate();
                    });
                    return;
                }
                if (microtime(true) < $deadline) {
                    Timer::add(0.08, fn() => $this->waitTaskThinkMenu($responseId, $enabled, $after, $recover, $deadline), null, false);
                    return;
                }
                $this->failModelSelection($responseId, 'CHATGPT_THINK_CHANGE_FAILED', 'ChatGPT 没有出现唯一的 Think 命令选项。', $recover);
            });
    }

    /** Activate only the real Think button, retaining any composed prompt, connector and attachments. */
    private function activateTaskThinkControl(string $responseId, bool $enabled, callable $after, bool $recover): void
    {
        $this->evaluateModelSelection($responseId, WebModelPage::focusThinkControlExpression(),
            function ($focusError, $focused) use ($responseId, $enabled, $after, $recover): void {
                if ($focusError !== null || !is_array($focused) || empty($focused['ok'])) {
                    $this->failModelSelection($responseId, 'CHATGPT_THINK_CHANGE_FAILED', '无法聚焦 ChatGPT Think 按钮。', $recover);
                    return;
                }
                if (($focused['pressed'] ?? null) === $enabled) { $after(); return; }
                $this->dispatchModelSelectionKey($responseId, 'Enter',
                    function ($keyError) use ($responseId, $enabled, $after, $recover): void {
                        if ($keyError !== null) {
                            $this->failModelSelection($responseId, 'CHATGPT_THINK_CHANGE_FAILED', '无法切换 ChatGPT Think 按钮。', $recover);
                            return;
                        }
                        $this->waitTaskThinkState($responseId, $enabled, $after, $recover, microtime(true) + 5.0, false);
                    });
            });
    }

    /** Clear only a legacy slash command; a real button toggle never clears existing composer content. */
    private function waitTaskThinkState(string $responseId, bool $enabled, callable $after, bool $recover, float $deadline, bool $clearCommand = true): void
    {
        $this->evaluateModelSelection($responseId, WebModelPage::thinkStateExpression(),
            function ($error, $state) use ($responseId, $enabled, $after, $recover, $deadline, $clearCommand): void {
                if ($error === null && is_array($state) && !empty($state['ok']) && (int) ($state['count'] ?? 0) === 1 && !empty($state['pressed']) === $enabled) {
                    $expression = $clearCommand ? WebModelPage::focusAndClearExpression() : WebModelPage::focusComposerExpression();
                    $this->evaluateModelSelection($responseId, $expression, function () use ($after): void { $after(); });
                    return;
                }
                if (microtime(true) < $deadline) {
                    Timer::add(0.10, fn() => $this->waitTaskThinkState($responseId, $enabled, $after, $recover, $deadline, $clearCommand), null, false);
                    return;
                }
                $this->failModelSelection($responseId, 'CHATGPT_THINK_CHANGE_FAILED', 'ChatGPT 没有确认 Think 状态切换。', $recover);
            });
    }

    /** Refresh the final baseline so staged transport turns can never be mistaken for the task response. */
    private function refreshTaskFinalBaseline(string $responseId): void
    {
        $this->evaluateTask($responseId, WebModelPage::baselineExpression(), function ($error, $baseline) use ($responseId): void {
            if ($error !== null || !is_array($baseline) || !empty($baseline['generation_running']) || empty($baseline['editor_ready'])) {
                $this->taskFailTurn($responseId, 'CHATGPT_MULTIPART_BASELINE_FAILED', 'Multipart 完成后无法建立最终任务基线。');
                return;
            }
            $this->chatGptTurns[$responseId]['baseline_users'] = is_array($baseline['user_ids'] ?? null) ? $baseline['user_ids'] : [];
            $this->chatGptTurns[$responseId]['baseline_assistants'] = is_array($baseline['assistant_ids'] ?? null) ? $baseline['assistant_ids'] : [];
            $this->chatGptTurns[$responseId]['bound_assistant_id'] = null;
            $plan = $this->chatGptTurns[$responseId]['multipart'] ?? null;
            if (!is_array($plan) || !is_string($plan['final_text'] ?? null)) {
                $this->taskFailTurn($responseId, 'CHATGPT_MULTIPART_STATE_INVALID', 'Multipart 最终提交内容缺失。');
                return;
            }
            $this->chatGptTurns[$responseId]['prompt'] = (string) $plan['final_text'];
            $this->prepareTaskComposer($responseId);
        });
    }

    /** Send exactly one inert stage without Connector/tool capability. */
    private function sendNextMultipartStage(string $responseId): void
    {
        $turn = $this->chatGptTurns[$responseId] ?? null;
        $plan = is_array($turn) ? ($turn['multipart'] ?? null) : null;
        if (!is_array($turn) || !is_array($plan)) return;
        $acked = (int) ($turn['transport_stage_acked'] ?? 0);
        $stages = (array) ($plan['stages'] ?? []);
        if ($acked >= count($stages)) {
            $this->prepareTaskModel($responseId, false);
            return;
        }
        $stage = $stages[$acked] ?? null;
        if (!is_array($stage)) {
            $this->taskFailTurn($responseId, 'CHATGPT_MULTIPART_STATE_INVALID', 'Multipart 分段状态缺失。');
            return;
        }
        $text = (string) ($stage['text'] ?? '');
        $index = (int) ($stage['index'] ?? 0);
        $this->clearTaskMultipartComposer($responseId, 'CHATGPT_MULTIPART_EDITOR_FAILED',
            'Multipart 分段发送前无法清空编辑器。',
            function () use ($responseId, $text, $index): void {
                $this->evaluateTask($responseId, WebModelPage::insertPlainTextExpression($text),
                    function ($insertError, $inserted) use ($responseId, $text, $index): void {
                        if ($insertError !== null || !is_array($inserted) || empty($inserted['ok'])) {
                            $detail = is_array($inserted) ? (string) ($inserted['code'] ?? '') : '';
                            $detail = $detail !== '' ? ' [editor ' . $detail . ']' : (is_array($insertError) ? ' [CDP ' . (int) ($insertError['code'] ?? 0) . ']' : '');
                            $this->taskFailTurn($responseId, 'CHATGPT_MULTIPART_EDITOR_FAILED', 'Multipart 分段无法写入编辑器。' . $detail);
                            return;
                        }
                        $this->evaluateTask($responseId, WebModelPage::composerTextExpression(),
                            function ($verifyError, $draft) use ($responseId, $text, $index): void {
                                $actual = is_array($draft) ? str_replace("\u{00A0}", ' ', (string) ($draft['text'] ?? '')) : '';
                                if ($verifyError !== null || $actual !== str_replace("\u{00A0}", ' ', $text)) {
                                    $this->taskFailTurn($responseId, 'CHATGPT_MULTIPART_EDITOR_VERIFY_FAILED', 'Multipart 分段写入后内容校验失败。');
                                    return;
                                }
                                $this->waitMultipartStageSendReady($responseId, $index, microtime(true) + 5.0, $text);
                            }, 10.0);
                    }, 15.0);
            });
    }

    /** A completed staging reply can briefly remount its editor. Never submit until an empty draft is observed. */
    private function clearTaskMultipartComposer(string $responseId, string $code, string $message,
        callable $after, int $attempt = 0, string $detail = ''): void
    {
        if (!isset($this->chatGptTurns[$responseId])) return;
        $this->evaluateTask($responseId, WebModelPage::focusAndClearExpression(),
            function ($error, $cleared) use ($responseId, $code, $message, $after, $attempt, $detail): void {
                if ($error === null && is_array($cleared) && !empty($cleared['ok'])
                    && (string) ($cleared['text'] ?? '') === '') {
                    $after();
                    return;
                }
                $reason = is_array($cleared) ? (string) ($cleared['code'] ?? '') : '';
                if ($reason === '') $reason = is_array($error) ? 'CDP_' . (int) ($error['code'] ?? 0) : 'NOT_EMPTY';
                $detail = $reason . (is_array($cleared) ? '_TEXT_' . mb_strlen((string) ($cleared['text'] ?? ''))
                    . '_PILLS_' . (int) ($cleared['connector_count'] ?? 0) : '');
                if ($attempt < 2) {
                    $this->scheduleMultipartOperation($responseId, fn() => $this->clearTaskMultipartComposer(
                        $responseId, $code, $message, $after, $attempt + 1, $detail), 0.25);
                    return;
                }
                // ChatGPT's controlled editor can reject a DOM selection after a remount.
                // Clear the controlled editor with native Select All / Backspace input.
                $this->evaluateTask($responseId, WebModelPage::focusComposerExpression(),
                    function ($focusError, $focused) use ($responseId, $code, $message, $after, $detail): void {
                        if ($focusError !== null || !is_array($focused) || empty($focused['ok'])) {
                            $this->taskFailTurn($responseId, $code, $message . ' [focus ' . $detail . ']');
                            return;
                        }
                        $this->dispatchTaskKey($responseId, PHP_OS_FAMILY === 'Darwin' ? 'Command+A' : 'Control+A',
                            function ($selectError) use ($responseId, $code, $message, $after, $detail): void {
                                if ($selectError !== null && ($selectError['message'] ?? '') !== 'BROWSER_CDP_TIMEOUT') {
                                    $this->taskFailTurn($responseId, $code, $message . ' [select ' . $detail . ']');
                                    return;
                                }
                                $this->dispatchTaskKey($responseId, 'Backspace',
                                    function ($backspaceError) use ($responseId, $code, $message, $after, $detail): void {
                                        if ($backspaceError !== null && ($backspaceError['message'] ?? '') !== 'BROWSER_CDP_TIMEOUT') {
                                            $this->taskFailTurn($responseId, $code, $message . ' [backspace ' . $detail . ']');
                                            return;
                                        }
                                        $this->scheduleMultipartOperation($responseId, fn() => $this->verifyTaskMultipartComposerCleared(
                                            $responseId, $code, $message, $after, $detail), 0.2);
                                    });
                            });
                    }, 5.0);
            }, 8.0);
    }

    private function verifyTaskMultipartComposerCleared(string $responseId, string $code, string $message,
        callable $after, string $detail): void
    {
        $this->evaluateTask($responseId, WebModelPage::focusAndClearExpression(),
            function ($error, $cleared) use ($responseId, $code, $message, $after, $detail): void {
                if ($error === null && is_array($cleared) && !empty($cleared['ok'])
                    && (string) ($cleared['text'] ?? '') === '') {
                    $after();
                    return;
                }
                $reason = is_array($cleared) ? (string) ($cleared['code'] ?? '') : '';
                if ($reason === '') $reason = is_array($error) ? 'CDP_' . (int) ($error['code'] ?? 0) : 'NOT_EMPTY';
                $this->taskFailTurn($responseId, $code, $message . ' [verify ' . $reason . ', prior ' . $detail . ']');
            }, 8.0);
    }

    private function waitMultipartStageSendReady(string $responseId, int $stage, float $deadline, string $text): void
    {
        $this->evaluateTask($responseId, WebModelPage::sendReadinessExpression(), function ($error, $ready) use ($responseId, $stage, $deadline, $text): void {
            if ($error === null && is_array($ready) && !empty($ready['ready'])) {
                $mode = (array) ($this->chatGptTurns[$responseId]['staging_mode'] ?? []);
                $this->verifyModelSelection($responseId, $mode, function () use ($responseId, $stage, $text, $mode): void {
                    try {
                        ResponseJob::recordTransportSelection($responseId, $mode);
                        ResponseJob::multipartStageIntent($responseId, $stage, $text);
                    } catch (GatewayError $gatewayError) {
                        $this->taskFailTurn($responseId, $gatewayError->errorCode, $gatewayError->getMessage());
                        return;
                    }
                    if (isset($this->chatGptTurns[$responseId])) $this->chatGptTurns[$responseId]['transport_stage_sent'] = $stage;
                    $this->evaluateTask($responseId, WebModelPage::focusSendExpression(), function ($focusError, $focused) use ($responseId, $stage): void {
                        if ($focusError !== null || !is_array($focused) || empty($focused['ok'])) {
                            $this->taskFailTurn($responseId, 'CHATGPT_MULTIPART_SEND_UNKNOWN', 'Multipart 分段发送意图已保存，但发送控件无法确认；不会自动重发。', true);
                            return;
                        }
                        $this->dispatchTaskKey($responseId, 'Enter', function ($keyError) use ($responseId, $stage): void {
                            // Observe the exact page ACK; a lost key ACK never authorizes another Enter.
                            $this->waitMultipartStageAcknowledgement($responseId, $stage, microtime(true) + $this->multipartStageTimeoutSeconds($responseId), false);
                        });
                    });
                });
                return;
            }
            if (microtime(true) < $deadline) {
                $this->scheduleMultipartOperation($responseId,
                    fn() => $this->waitMultipartStageSendReady($responseId, $stage, $deadline, $text), 0.08);
                return;
            }
            $this->taskFailTurn($responseId, 'CHATGPT_MULTIPART_SEND_NOT_READY', 'Multipart 分段发送控件未进入可发送状态。');
        });
    }

    /**
     * Accept only the exact durable stage ACK; one single-field typo may request a correction.
     * Recovery observations never issue correction messages, and no correction repeats payload.
     * An empty current assistant row is still pending, even if earlier rows contain ACKs.
     * Partial ACK text also keeps the original wait; a missing Stop control is not its completion.
     * A released observer or an already acknowledged stage is a stale poll, not a missing transaction.
     */
    private function waitMultipartStageAcknowledgement(string $responseId, int $stage, float $deadline, bool $recover): void
    {
        $turn = $this->chatGptTurns[$responseId] ?? null;
        if (!is_array($turn) || $stage <= (int) ($turn['transport_stage_acked'] ?? 0)) return;
        $plan = is_array($turn) ? ($turn['multipart'] ?? null) : null;
        $entry = is_array($plan) ? (($plan['stages'][$stage - 1] ?? null)) : null;
        if (!is_array($entry) || !is_string($entry['ack'] ?? null)) {
            $this->taskFailTurn($responseId, $recover ? 'RECOVERY_OUTCOME_UNKNOWN' : 'CHATGPT_MULTIPART_STATE_INVALID', 'Multipart ACK 事务状态缺失。', $recover);
            return;
        }
        $this->evaluateTask($responseId, WebModelPage::acknowledgementExpression((string) $entry['ack']),
            function ($error, $state) use ($responseId, $stage, $deadline, $recover, $entry): void {
                if ($error === null && is_array($state) && !empty($state['matched']) && empty($state['generation_running'])) {
                    try {
                        ResponseJob::multipartStageAcknowledged($responseId, $stage);
                        WebMessageAudit::recordIncoming($responseId, 'stage_ack', (string)($state['last_raw'] ?? ''), $stage);
                    }
                    catch (GatewayError $gatewayError) {
                        $this->taskFailTurn($responseId, $gatewayError->errorCode, $gatewayError->getMessage(), $recover);
                        return;
                    }
                    if (isset($this->chatGptTurns[$responseId])) $this->chatGptTurns[$responseId]['transport_stage_acked'] = $stage;
                    $plan = $this->chatGptTurns[$responseId]['multipart'] ?? null;
                    if (is_array($plan) && $stage < count((array) ($plan['stages'] ?? []))) {
                        $turn = $this->chatGptTurns[$responseId];
                        if (ChatGptTransport::canReuseStagingSelection($turn, $recover)) {
                            $this->sendNextMultipartStage($responseId);
                        } else {
                            $this->prepareTaskModel($responseId, $recover);
                        }
                        return;
                    }
                    $this->prepareTaskModel($responseId, $recover);
                    return;
                }
                if (is_array($state) && (int) ($state['count'] ?? 0) > 1) {
                    $this->taskFailTurn($responseId, 'CHATGPT_MULTIPART_ACK_AMBIGUOUS', '同一 Multipart ACK 在页面中出现多次，已停止恢复。', true);
                    return;
                }
                $baselineAssistantCount = count((array) (($this->chatGptTurns[$responseId]['baseline_assistants'] ?? [])));
                $currentAssistantCount = is_array($state) ? (int) ($state['assistant_count'] ?? 0) : 0;
                $candidate = is_array($state) && empty($state['generation_running']) && (int) ($state['last_chars'] ?? 0) > 0
                    && !ChatGptTransport::acknowledgementIncomplete((string) ($state['last_raw'] ?? ''))
                    && $currentAssistantCount >= $baselineAssistantCount + $stage;
                if ($candidate) {
                    if ($this->waitForTaskSubmissionError($responseId)) {
                        $this->scheduleMultipartOperation($responseId,
                            fn() => $this->waitMultipartStageAcknowledgement($responseId, $stage, $deadline, $recover), 0.2);
                        return;
                    }
                    if (($state['failure_code'] ?? null) === 'CHATGPT_MESSAGE_TOO_LARGE') {
                        $this->taskFailTurn($responseId, 'CHATGPT_MESSAGE_TOO_LARGE', 'ChatGPT 明确拒绝了过长的上下文分段，正式执行指令尚未发送。');
                        return;
                    }
                    $signature = implode(':', [
                        $currentAssistantCount, (int) ($state['nonempty_count'] ?? 0), (int) ($state['last_chars'] ?? 0),
                        !empty($state['last_has_prefix']) ? 1 : 0, !empty($state['last_has_transaction']) ? 1 : 0,
                        !empty($state['last_has_part']) ? 1 : 0, !empty($state['last_has_hash']) ? 1 : 0,
                    ]);
                    if (($this->chatGptTurns[$responseId]['multipart_mismatch_signature'] ?? null) !== $signature) {
                        $this->chatGptTurns[$responseId]['multipart_mismatch_signature'] = $signature;
                        $this->chatGptTurns[$responseId]['multipart_mismatch_since'] = microtime(true);
                    } elseif (microtime(true) - (float) ($this->chatGptTurns[$responseId]['multipart_mismatch_since'] ?? 0.0) >= 3.0) {
                        $repairableMismatch = ChatGptTransport::acknowledgementRepairable(
                            (string) $entry['ack'], (string) ($state['last_raw'] ?? ''));
                        $repairStage = (int) ($this->chatGptTurns[$responseId]['multipart_ack_repair_stage'] ?? 0);
                        if (!$recover && $repairableMismatch && $repairStage !== $stage) {
                            $this->chatGptTurns[$responseId]['multipart_ack_repair_stage'] = $stage;
                            $this->chatGptTurns[$responseId]['multipart_mismatch_signature'] = null;
                            $this->chatGptTurns[$responseId]['multipart_mismatch_since'] = 0.0;
                            $this->repairMultipartStageAcknowledgement($responseId, $stage, $deadline);
                            return;
                        }
                        $facts = $this->multipartAckFacts($state);
                        $this->taskFailTurn(
                            $responseId,
                            'CHATGPT_MULTIPART_ACK_MISMATCH',
                            ($recover ? '恢复时发现 Multipart 分段回复与事务 ACK 不匹配；不会自动重发。' : 'ChatGPT 返回了 Multipart 分段回复，但事务 ACK 不匹配。') . $facts,
                            $recover
                        );
                        return;
                    }
                } else {
                    $this->chatGptTurns[$responseId]['multipart_mismatch_signature'] = null;
                    $this->chatGptTurns[$responseId]['multipart_mismatch_since'] = 0.0;
                }
                if (microtime(true) < $deadline) {
                    $this->scheduleMultipartOperation($responseId,
                        fn() => $this->waitMultipartStageAcknowledgement($responseId, $stage, $deadline, $recover), 0.2);
                    return;
                }
                $facts = is_array($state) ? $this->multipartAckFacts($state) : '';
                $this->taskFailTurn(
                    $responseId,
                    'CHATGPT_MULTIPART_ACK_TIMEOUT',
                    ($recover ? '恢复时无法确认已发送 Multipart 分段的精确 ACK；不会自动重发。' : 'ChatGPT 没有返回事务绑定的精确 Multipart ACK。') . $facts,
                    $recover
                );
            });
    }

    /**
     * Ask ChatGPT to correct only one mismatched acknowledgement field once.
     * The staged payload is deliberately not repeated, so a model typo cannot duplicate context.
     */
    private function repairMultipartStageAcknowledgement(string $responseId, int $stage, float $deadline): void
    {
        $turn = $this->chatGptTurns[$responseId] ?? null;
        $plan = is_array($turn) ? ($turn['multipart'] ?? null) : null;
        $entry = is_array($plan) ? ($plan['stages'][$stage - 1] ?? null) : null;
        $ack = is_array($entry) ? trim((string) ($entry['ack'] ?? '')) : '';
        if ($ack === '') {
            $this->taskFailTurn($responseId, 'CHATGPT_MULTIPART_STATE_INVALID', 'Multipart ACK 修正缺少事务状态。');
            return;
        }
        $text = ChatGptTransport::ackRepairText($ack);
        $this->clearTaskMultipartComposer($responseId, 'CHATGPT_MULTIPART_ACK_REPAIR_EDITOR_FAILED',
            'Multipart ACK 修正前无法清空编辑器。',
            function () use ($responseId, $stage, $deadline, $text): void {
                $this->evaluateTask($responseId, WebModelPage::insertPlainTextExpression($text),
                    function ($insertError, $inserted) use ($responseId, $stage, $deadline, $text): void {
                        if ($insertError !== null || !is_array($inserted) || empty($inserted['ok'])) {
                            $this->taskFailTurn($responseId, 'CHATGPT_MULTIPART_ACK_REPAIR_EDITOR_FAILED', 'Multipart ACK 修正内容无法写入编辑器。');
                            return;
                        }
                        $this->evaluateTask($responseId, WebModelPage::composerTextExpression(),
                            function ($verifyError, $draft) use ($responseId, $stage, $deadline, $text): void {
                                $actual = is_array($draft) ? str_replace("\u{00A0}", ' ', (string) ($draft['text'] ?? '')) : '';
                                if ($verifyError !== null || $actual !== str_replace("\u{00A0}", ' ', $text)) {
                                    $this->taskFailTurn($responseId, 'CHATGPT_MULTIPART_ACK_REPAIR_EDITOR_FAILED', 'Multipart ACK 修正内容写入后校验失败。');
                                    return;
                                }
                                $this->waitMultipartAckRepairSendReady($responseId, $stage, $deadline, microtime(true) + 5.0, $text);
                            });
                    });
            });
    }

    private function waitMultipartAckRepairSendReady(string $responseId, int $stage, float $deadline, float $readyDeadline,
        string $text): void
    {
        $this->evaluateTask($responseId, WebModelPage::sendReadinessExpression(),
            function ($error, $ready) use ($responseId, $stage, $deadline, $readyDeadline, $text): void {
                if ($error === null && is_array($ready) && !empty($ready['ready'])) {
                    $mode = (array) ($this->chatGptTurns[$responseId]['staging_mode'] ?? []);
                    $this->verifyModelSelection($responseId, $mode, function () use ($responseId, $stage, $deadline, $text, $mode): void {
                        try {
                            ResponseJob::recordTransportSelection($responseId, $mode);
                            WebMessageAudit::recordOutgoing($responseId, 'stage_ack_repair', $text, $stage);
                        } catch (GatewayError $gatewayError) {
                            $this->taskFailTurn($responseId, $gatewayError->errorCode, $gatewayError->getMessage());
                            return;
                        }
                        $this->evaluateTask($responseId, WebModelPage::focusSendExpression(),
                            function ($focusError, $focused) use ($responseId, $stage, $deadline): void {
                                if ($focusError !== null || !is_array($focused) || empty($focused['ok'])) {
                                    $this->taskFailTurn($responseId, 'CHATGPT_MULTIPART_ACK_REPAIR_SEND_UNKNOWN', 'Multipart ACK 修正发送控件无法确认；不会重发原分段。', true);
                                    return;
                                }
                                $this->dispatchTaskKey($responseId, 'Enter', function ($keyError) use ($responseId, $stage, $deadline): void {
                                    if ($keyError !== null) {
                                        $this->taskFailTurn($responseId, 'CHATGPT_MULTIPART_ACK_REPAIR_SEND_UNKNOWN', 'Multipart ACK 修正发送结果无法确认；不会重发原分段。', true);
                                        return;
                                    }
                                    $this->waitMultipartStageAcknowledgement($responseId, $stage, $deadline, false);
                                });
                            });
                    });
                    return;
                }
                if (microtime(true) < $readyDeadline && microtime(true) < $deadline) {
                    $this->scheduleMultipartOperation($responseId,
                        fn() => $this->waitMultipartAckRepairSendReady($responseId, $stage, $deadline, $readyDeadline, $text), 0.08);
                    return;
                }
                $this->taskFailTurn($responseId, 'CHATGPT_MULTIPART_ACK_REPAIR_SEND_NOT_READY', 'Multipart ACK 修正发送控件未进入可发送状态。');
            });
    }

    /**
     * Schedule multipart work only for the page, transaction and stage that requested it.
     * A size-error body can retire a page while its ACK poll is waiting; response IDs alone
     * are reused by that recovery and cannot fence a delayed poll against the replacement.
     * @param string $responseId Physical observer key. @param callable():void $operation Pending page work.
     * @param float $delay Seconds before observation; no task or preparation deadline is renewed.
     * @return void Released/replaced/completed stages silently discard their old timers.
     */
    private function scheduleMultipartOperation(string $responseId, callable $operation, float $delay): void
    {
        $turn = $this->chatGptTurns[$responseId] ?? null;
        if (!is_array($turn)) return;
        $keys = array_flip(['session_id', 'document_nonce', 'owner_incarnation', 'fencing_version',
            'transport_transaction_id', 'transport_stage_sent', 'transport_stage_acked']);
        $owner = array_intersect_key($turn, $keys);
        Timer::add($delay, function () use ($responseId, $operation, $keys, $owner): void {
            $current = $this->chatGptTurns[$responseId] ?? null;
            if (!is_array($current) || array_intersect_key($current, $keys) !== $owner) return;
            $operation();
        }, null, false);
    }

    /** Keep a running/recovered multipart turn on the timeout snapshot pinned when the job was created. */
    private function multipartStageTimeoutSeconds(string $responseId): int
    {
        try {
            $budget = ResponseJob::execution($responseId)['budget'] ?? [];
            $seconds = (int) ($budget['context_stage_timeout_seconds'] ?? 0);
            if ($seconds > 0) return max(30, min(300, $seconds));
        } catch (\Throwable) {}
        return max(30, min(300, (int) GatewaySettings::internal()['context_stage_timeout_seconds']));
    }

    /** Bounded protocol diagnostics only; never include assistant text or staged context. */
    private function multipartAckFacts(array $state): string
    {
        return ' [assistant_rows=' . (int) ($state['assistant_count'] ?? 0)
            . ', nonempty=' . (int) ($state['nonempty_count'] ?? 0)
            . ', last_chars=' . (int) ($state['last_chars'] ?? 0)
            . ', prefix=' . (!empty($state['last_has_prefix']) ? '1' : '0')
            . ', transaction=' . (!empty($state['last_has_transaction']) ? '1' : '0')
            . ', part=' . (!empty($state['last_has_part']) ? '1' : '0')
            . ', hash=' . (!empty($state['last_has_hash']) ? '1' : '0')
            . ', generating=' . (!empty($state['generation_running']) ? '1' : '0') . ']';
    }

    private function prepareTaskComposer(string $responseId): void
    {
        try {
            $job = ResponseJob::execution($responseId);
        } catch (GatewayError $error) {
            $this->taskFailTurn($responseId, $error->errorCode, $error->getMessage());
            return;
        }
        if (!empty($job['mcp_binding_id'])) {
            $connectorName = trim((string) ($this->chatGptTurns[$responseId]['connector_name'] ?? ''));
            if ($connectorName === '') {
                $this->taskFailTurn($responseId, 'CHATGPT_CONNECTOR_NAME_MISSING', '工具连接器名称未配置。');
                return;
            }
            if (!empty($job['continuation_source_response_id'])) {
                $this->chatGptTurns[$responseId]['retained_page_origin'] = true;
            }
            if (!empty($this->chatGptTurns[$responseId]['retained_connector'])) {
                // A prior actual Connector start proves reusable conversation access.
                $this->prepareRetainedTaskConnector($responseId, $connectorName);
                return;
            }
            $this->chatGptTurns[$responseId]['connector_preparing'] = true;
            $this->prepareTaskPersonalization($responseId, $connectorName);
            return;
        }
        $this->preparePlainTaskComposer($responseId);
    }

    /** Ensure Temporary Chat exposes Connector access before selecting the configured Connector. */
    private function prepareTaskPersonalization(string $responseId, string $connectorName): void
    {
        if (!isset($this->chatGptTurns[$responseId])) return;
        $this->evaluateTask($responseId, WebModelPage::personalizationStateExpression(),
            function ($error, $state) use ($responseId, $connectorName): void {
                if (!isset($this->chatGptTurns[$responseId])) return;
                if ($error !== null || !is_array($state)) {
                    $this->taskFailTurn($responseId, 'CHATGPT_PERSONALIZATION_STATE_UNAVAILABLE', '无法读取 Temporary Chat 个性化状态。');
                    return;
                }
                $name = (string) ($state['state'] ?? 'missing');
                if ($name === 'invalid') {
                    $this->taskFailTurn($responseId, 'CHATGPT_PERSONALIZATION_STATE_INVALID', 'Temporary Chat 出现多个冲突的个性化控件。');
                    return;
                }
                if ($name === 'personalized') {
                    $this->chatGptTurns[$responseId]['personalization_state'] = 'personalized';
                    $this->prepareTaskConnector($responseId, $connectorName, 1);
                    return;
                }
                if ($name === 'unpersonalized') {
                    $this->chatGptTurns[$responseId]['personalization_state'] = 'unpersonalized';
                    $this->openTaskPersonalization($responseId, $connectorName, 'enable');
                    return;
                }
                // ChatGPT occasionally omits the visible label while Connector access is already
                // usable. Prove the Connector first; only then consider the structural fallback.
                $this->chatGptTurns[$responseId]['personalization_state'] = 'unknown';
                $this->prepareTaskConnector($responseId, $connectorName, 1);
            });
    }

    /** Open the exact Temporary Chat personalization control for enable/fallback/rollback. */
    private function openTaskPersonalization(string $responseId, string $connectorName, string $mode): void
    {
        if (!isset($this->chatGptTurns[$responseId])) return;
        $structuralOnly = $mode !== 'enable';
        $this->evaluateTask($responseId, WebModelPage::personalizationOpenExpression($structuralOnly),
            function ($error, $opened) use ($responseId, $connectorName, $mode): void {
                if (!isset($this->chatGptTurns[$responseId])) return;
                if ($error !== null || !is_array($opened) || empty($opened['ok'])) {
                    $code = $mode === 'rollback' ? 'CHATGPT_PERSONALIZATION_ROLLBACK_FAILED' : 'CHATGPT_PERSONALIZATION_CONTROL_MISSING';
                    $message = $mode === 'rollback'
                        ? '无法恢复 Temporary Chat 原个性化状态，已停止任务。'
                        : 'Temporary Chat 没有暴露可验证的个性化控件。';
                    $this->taskFailTurn($responseId, $code, $message);
                    return;
                }
                $this->waitTaskPersonalizationMenu($responseId, $connectorName, $mode, microtime(true) + 3.0);
            });
    }

    /** Wait for one owned personalization menu, then select the required exact choice. */
    private function waitTaskPersonalizationMenu(string $responseId, string $connectorName, string $mode, float $deadline): void
    {
        if (!isset($this->chatGptTurns[$responseId])) return;
        $this->evaluateTask($responseId, WebModelPage::personalizationMenuExpression(),
            function ($error, $menu) use ($responseId, $connectorName, $mode, $deadline): void {
                if (!isset($this->chatGptTurns[$responseId])) return;
                if ($error === null && is_array($menu) && !empty($menu['open'])
                    && (int) ($menu['choice_count'] ?? 0) === 2 && (int) ($menu['checked_index'] ?? -1) >= 0) {
                    $checked = (int) $menu['checked_index'];
                    $target = null;
                    if ($mode === 'fallback') {
                        $this->chatGptTurns[$responseId]['personalization_original_index'] = $checked;
                        $target = $checked === 0 ? 1 : 0;
                    } elseif ($mode === 'rollback') {
                        $target = $this->chatGptTurns[$responseId]['personalization_original_index'];
                        if (!is_int($target) || $target < 0 || $target > 1) {
                            $this->taskFailTurn($responseId, 'CHATGPT_PERSONALIZATION_ROLLBACK_FAILED', 'Temporary Chat 原个性化状态无法确定。');
                            return;
                        }
                    }
                    $this->evaluateTask($responseId, WebModelPage::personalizationSelectExpression($target),
                        function ($selectError, $selected) use ($responseId, $connectorName, $mode): void {
                            if (!isset($this->chatGptTurns[$responseId])) return;
                            if ($selectError !== null || !is_array($selected) || empty($selected['ok'])) {
                                $code = $mode === 'rollback' ? 'CHATGPT_PERSONALIZATION_ROLLBACK_FAILED' : 'CHATGPT_PERSONALIZATION_CHANGE_FAILED';
                                $this->taskFailTurn($responseId, $code, 'Temporary Chat 个性化选项无法切换。');
                                return;
                            }
                            if ($mode === 'enable') {
                                $this->waitTaskPersonalizationEnabled($responseId, $connectorName, microtime(true) + 3.0);
                                return;
                            }
                            if ($mode === 'fallback') {
                                $this->chatGptTurns[$responseId]['personalization_fallback_used'] = true;
                                Timer::add(0.25, fn() => $this->prepareTaskConnector($responseId, $connectorName, 1), null, false);
                                return;
                            }
                            Timer::add(0.25, function () use ($responseId, $connectorName): void {
                                if (!isset($this->chatGptTurns[$responseId])) return;
                                $this->taskFailTurn($responseId, 'CHATGPT_CONNECTOR_UNAVAILABLE', 'Temporary Chat 中没有找到名称完全匹配的工具连接器：' . $connectorName . '。');
                            }, null, false);
                        });
                    return;
                }
                if (microtime(true) < $deadline) {
                    Timer::add(0.08, fn() => $this->waitTaskPersonalizationMenu($responseId, $connectorName, $mode, $deadline), null, false);
                    return;
                }
                $code = $mode === 'rollback' ? 'CHATGPT_PERSONALIZATION_ROLLBACK_FAILED' : 'CHATGPT_PERSONALIZATION_MENU_INVALID';
                $this->taskFailTurn($responseId, $code, 'Temporary Chat 个性化菜单没有提供两个可验证的单选状态。');
            });
    }

    /** Wait until the labeled Temporary Chat control confirms Personalized. */
    private function waitTaskPersonalizationEnabled(string $responseId, string $connectorName, float $deadline): void
    {
        if (!isset($this->chatGptTurns[$responseId])) return;
        $this->evaluateTask($responseId, WebModelPage::personalizationStateExpression(),
            function ($error, $state) use ($responseId, $connectorName, $deadline): void {
                if (!isset($this->chatGptTurns[$responseId])) return;
                if ($error === null && is_array($state) && (string) ($state['state'] ?? '') === 'personalized') {
                    $this->chatGptTurns[$responseId]['personalization_state'] = 'personalized';
                    $this->prepareTaskConnector($responseId, $connectorName, 1);
                    return;
                }
                if (microtime(true) < $deadline) {
                    Timer::add(0.08, fn() => $this->waitTaskPersonalizationEnabled($responseId, $connectorName, $deadline), null, false);
                    return;
                }
                $this->taskFailTurn($responseId, 'CHATGPT_PERSONALIZATION_CHANGE_FAILED', 'Temporary Chat 没有确认已经切换到 Personalized。');
            });
    }

    private function preparePlainTaskComposer(string $responseId): void
    {
        $this->evaluateTask($responseId, WebModelPage::focusAndClearExpression(), function ($error, $cleared) use ($responseId): void {
            if ($error !== null || !is_array($cleared) || empty($cleared['ok']) || (string) ($cleared['text'] ?? '') !== '') {
                $this->taskFailTurn($responseId, 'CHATGPT_EDITOR_CLEAR_FAILED', 'ChatGPT 编辑器无法清空。');
                return;
            }
            $this->insertTaskPrompt($responseId, false);
        });
    }

    /** Select the exact configured connector before any tool-capable ChatGPT turn is sent. */
    private function prepareTaskConnector(string $responseId, string $connectorName, int $attempt): void
    {
        if (!isset($this->chatGptTurns[$responseId])) return;
        if (!empty($this->chatGptTurns[$responseId]['connector_catalog_refresh_pending'])) return;
        $this->chatGptTurns[$responseId]['connector_attempt'] = (int)($this->chatGptTurns[$responseId]['connector_attempt'] ?? 0) + 1;
        $this->evaluateTask($responseId, WebModelPage::connectorSelectedExpression($connectorName),
            function ($error, $selected) use ($responseId, $connectorName, $attempt): void {
                if (!isset($this->chatGptTurns[$responseId])) return;
                if ($error !== null || !is_array($selected)) {
                    $this->taskFailTurn($responseId, 'CHATGPT_CONNECTOR_STATE_UNAVAILABLE', '无法确认 ChatGPT 工具连接器状态。');
                    return;
                }
                if ((int) ($selected['count'] ?? 0) > 1) {
                    $this->taskFailTurn($responseId, 'CHATGPT_CONNECTOR_DUPLICATE', 'ChatGPT 页面出现了重复的同名工具连接器。');
                    return;
                }
                if (!empty($selected['selected'])) {
                    $this->prepareSelectedTaskConnector($responseId, $connectorName, $attempt);
                    return;
                }
                $this->evaluateTask($responseId, WebModelPage::focusAndClearExpression(),
                    function ($clearError, $cleared) use ($responseId, $connectorName, $attempt): void {
                        $turn = $this->chatGptTurns[$responseId] ?? null;
                        if (!is_array($turn)) return;
                        if ($clearError !== null || !is_array($cleared) || empty($cleared['ok'])) {
                            $this->taskFailTurn($responseId, 'CHATGPT_EDITOR_CLEAR_FAILED', '选择工具连接器前无法清空 ChatGPT 编辑器。');
                            return;
                        }
                        $this->typeConnectorMention($this->taskCdp, (string) $turn['session_id'],
                            function ($insertError) use ($responseId, $connectorName, $attempt): void {
                                // A lost CDP acknowledgement may still have opened the menu.
                                // Read the current menu before deciding to clear/retry the draft.
                                $this->waitTaskConnectorMenu($responseId, $connectorName, $attempt, microtime(true) + 2.5);
                            }, 5.0, $connectorName);
                    });
            });
    }

    private function waitTaskConnectorMenu(string $responseId, string $connectorName, int $attempt, float $deadline): void
    {
        if (!isset($this->chatGptTurns[$responseId])) return;
        if (!empty($this->chatGptTurns[$responseId]['connector_catalog_refresh_pending'])) return;
        $this->evaluateTask($responseId, WebModelPage::connectorMenuExpression($connectorName),
            function ($error, $menu) use ($responseId, $connectorName, $attempt, $deadline): void {
                if (!isset($this->chatGptTurns[$responseId])) return;
                if (!empty($this->chatGptTurns[$responseId]['connector_catalog_refresh_pending'])) return;
                if ($error === null && is_array($menu)) {
                    $count = (int) ($menu['exact_count'] ?? 0);
                    if ($count > 1) {
                        $this->taskFailTurn($responseId, 'CHATGPT_CONNECTOR_DUPLICATE', 'ChatGPT 工具连接器菜单出现了重复的同名项目。');
                        return;
                    }
                    if ($count === 1) {
                        $this->activateTaskConnectorChoice($responseId, $connectorName, 0);
                        return;
                    }
                }
                if (microtime(true) < $deadline) {
                    Timer::add(0.1, fn() => $this->waitTaskConnectorMenu($responseId, $connectorName, $attempt, $deadline), null, false);
                    return;
                }
                if ($attempt < 3) {
                    $this->prepareTaskConnector($responseId, $connectorName, $attempt + 1);
                    return;
                }
                $turn = $this->chatGptTurns[$responseId] ?? null;
                if (is_array($turn) && ($turn['compaction_phase'] ?? null) === 'preparing') {
                    $this->taskFailTurn($responseId, 'CHATGPT_CONNECTOR_UNAVAILABLE',
                        '检查点消息无法选中原工具连接器，未发送；原上下文仍保留。', true);
                    return;
                }
                if (is_array($turn) && (!empty($turn['continuation_sending']) || !empty($turn['retained_page_origin']))) {
                    $this->rebuildUnavailableRetainedConnector($responseId);
                    return;
                }
                // A delayed menu callback must never restart preparation after the durable
                // submit boundary, even if the in-memory turn still looks unsent.
                try { $ledger = TaskLedger::execution($responseId); }
                catch (\Throwable) { $ledger = []; }
                if ($ledger === [] || !is_array($turn)
                    || !empty($turn['sent_at']) || !empty($turn['accepted'])
                    || !empty($ledger['job']['submit_intent_at']) || !empty($ledger['job']['accepted_at'])
                    || (string) ($ledger['turn']['status'] ?? '') !== 'queued') {
                    $this->taskFailTurn($responseId, 'CHATGPT_CONNECTOR_STATE_UNAVAILABLE',
                        '连接器菜单回调到达时任务提交状态无法安全确认；不会重发。', true);
                    return;
                }
                if ($this->refreshFreshTaskConnectorCatalog($responseId)) return;
                if (is_array($turn) && (string) ($turn['personalization_state'] ?? '') === 'unknown'
                    && empty($turn['personalization_fallback_used'])) {
                    $this->openTaskPersonalization($responseId, $connectorName, 'fallback');
                    return;
                }
                if (is_array($turn) && !empty($turn['personalization_fallback_used'])
                    && is_int($turn['personalization_original_index'] ?? null)
                    && empty($turn['personalization_rollback_attempted'])) {
                    $this->chatGptTurns[$responseId]['personalization_rollback_attempted'] = true;
                    $this->openTaskPersonalization($responseId, $connectorName, 'rollback');
                    return;
                }
                $this->taskFailTurn($responseId, 'CHATGPT_CONNECTOR_UNAVAILABLE', 'ChatGPT 中没有找到名称完全匹配的工具连接器：' . $connectorName . '。');
            }, 3.0);
    }

    /** A catalog refresh is safe only on this task's untouched, unsent Temporary Chat root. */
    private static function freshTaskConnectorRefreshAllowed(array $turn, array $ledger,
        bool $sameDocument = true, bool $requireRoot = true): bool
    {
        $job = $ledger['job'] ?? null;
        $durableTurn = $ledger['turn'] ?? null;
        $surface = $ledger['surface'] ?? null;
        if (!is_array($job) || !is_array($durableTurn) || !is_array($surface)) return false;
        return !empty($turn['tool_capable']) && !empty($turn['connector_preparing'])
            && !empty($turn['require_root_before_send'])
            && empty($turn['sent_at']) && empty($turn['accepted'])
            && empty($turn['continuation_sending']) && empty($turn['retained_page_origin'])
            && (int) ($turn['transport_stage_sent'] ?? 0) === 0
            && (int) ($turn['transport_stage_acked'] ?? 0) === 0
            && (int) ($job['transport_stage_sent'] ?? 0) === 0
            && (int) ($job['transport_stage_acked'] ?? 0) === 0
            && empty($job['submit_intent_at']) && empty($job['accepted_at'])
            && empty($job['previous_response_id'])
            && empty($job['continuation_source_response_id'])
            && (string) ($durableTurn['status'] ?? '') === 'queued'
            && empty($durableTurn['submit_intent_at'])
            && (string) ($surface['status'] ?? '') === 'ready'
            && (string) ($surface['target_id'] ?? '') === (string) ($turn['target_id'] ?? '')
            && (string) ($surface['session_id'] ?? '') === (string) ($turn['session_id'] ?? '')
            && (string) ($surface['owner_incarnation'] ?? '') === (string) ($turn['owner_incarnation'] ?? '')
            && (int) ($surface['fencing_version'] ?? -1) === (int) ($turn['fencing_version'] ?? -2)
            && (!$sameDocument || (string) ($surface['document_nonce'] ?? '') === (string) ($turn['document_nonce'] ?? ''))
            && (!$requireRoot || WebModelPage::isTemporaryChatStartUrl((string) ($surface['url'] ?? ''), self::modelUrl()));
    }

    /** Reload once before submission, then prove the new document and reselect the model and Connector. */
    private function refreshFreshTaskConnectorCatalog(string $responseId): bool
    {
        $turn = $this->chatGptTurns[$responseId] ?? null;
        if (!is_array($turn) || !empty($turn['connector_catalog_refresh_used'])
            || !empty($turn['connector_catalog_refresh_pending'])) return false;
        try { $ledger = TaskLedger::execution($responseId); }
        catch (\Throwable) { return false; }
        if (!self::freshTaskConnectorRefreshAllowed($turn, $ledger)
            || microtime(true) + 5.0 >= (float) $turn['deadline']) return false;
        $this->chatGptTurns[$responseId]['connector_catalog_refresh_used'] = true;
        $this->chatGptTurns[$responseId]['connector_catalog_refresh_pending'] = true;
        $sessionId = (string) $turn['session_id'];
        $oldNonce = (string) $turn['document_nonce'];
        $deadline = min((float) $turn['deadline'], microtime(true) + 60.0);
        $this->taskCdp->send('Page.reload', ['ignoreCache' => false], $sessionId,
            function ($error) use ($responseId, $sessionId, $oldNonce, $deadline): void {
                if (($this->chatGptTurns[$responseId]['session_id'] ?? null) !== $sessionId) return;
                // A lost acknowledgement can still mean the reload happened. Observe the
                // document nonce and DOM instead of issuing a second navigation.
                if ($error !== null && ($error['message'] ?? '') !== 'BROWSER_CDP_TIMEOUT') {
                    $this->taskFailTurn($responseId, 'CHATGPT_CONNECTOR_CATALOG_REFRESH_FAILED', 'ChatGPT 工具目录页面刷新失败，未发送任务。');
                    return;
                }
                $this->waitTaskConnectorCatalogRefresh($responseId, $oldNonce, $deadline);
            }, 15.0);
        return true;
    }

    private function waitTaskConnectorCatalogRefresh(string $responseId, string $oldNonce, float $deadline): void
    {
        $turn = $this->chatGptTurns[$responseId] ?? null;
        if (!is_array($turn) || empty($turn['connector_catalog_refresh_pending'])) return;
        try { $ledger = TaskLedger::execution($responseId); }
        catch (\Throwable) { $ledger = []; }
        if ($ledger !== [] && !self::freshTaskConnectorRefreshAllowed($turn, $ledger, false, false)) {
            $this->taskFailTurn($responseId, 'CHATGPT_CONNECTOR_CATALOG_REFRESH_UNSAFE', '刷新期间任务页面或提交状态发生变化，已停止发送。', true);
            return;
        }
        $nonce = (string) ($ledger['surface']['document_nonce'] ?? '');
        if ($nonce !== '' && $nonce !== $oldNonce) {
            $this->evaluateTask($responseId, WebModelPage::capabilityExpression(),
                function ($error, $capability) use ($responseId, $nonce, $oldNonce, $deadline): void {
                    $turn = $this->chatGptTurns[$responseId] ?? null;
                    if (!is_array($turn) || empty($turn['connector_catalog_refresh_pending'])) return;
                    if ($error === null && is_array($capability) && !empty($capability['login_required'])) {
                        $this->taskFailTurn($responseId, 'authentication_required', 'ChatGPT 网页需要重新登录。');
                        return;
                    }
                    if ($error === null && is_array($capability)
                        && !empty($capability['temporary_chat']) && !empty($capability['editor_ready'])
                        && empty($capability['generation_running'])
                        && WebModelPage::isTemporaryChatStartUrl((string) ($capability['url'] ?? ''), self::modelUrl())) {
                        try { $current = TaskLedger::execution($responseId); }
                        catch (\Throwable) { $current = []; }
                        if ($current === [] || !self::freshTaskConnectorRefreshAllowed($turn, $current, false)
                            || (string) ($current['surface']['document_nonce'] ?? '') !== $nonce) {
                            $this->taskFailTurn($responseId, 'CHATGPT_CONNECTOR_CATALOG_REFRESH_UNSAFE', '刷新后任务页面所有权或提交状态发生变化，已停止发送。', true);
                            return;
                        }
                        $this->finishTaskConnectorCatalogRefresh($responseId, $nonce);
                        return;
                    }
                    if (microtime(true) < $deadline) {
                        Timer::add(0.25, fn() => $this->waitTaskConnectorCatalogRefresh($responseId, $oldNonce, $deadline), null, false);
                        return;
                    }
                    $this->taskFailTurn($responseId, 'CHATGPT_CONNECTOR_CATALOG_REFRESH_FAILED', '刷新后 Temporary Chat 未恢复可发送状态，未发送任务。');
                }, 3.0);
            return;
        }
        if (microtime(true) < $deadline) {
            Timer::add(0.25, fn() => $this->waitTaskConnectorCatalogRefresh($responseId, $oldNonce, $deadline), null, false);
            return;
        }
        $this->taskFailTurn($responseId, 'CHATGPT_CONNECTOR_CATALOG_REFRESH_FAILED', '刷新后未观察到新的页面文档，未发送任务。');
    }

    /** Replace the old document proof before the ordinary baseline and model selection path. */
    private function finishTaskConnectorCatalogRefresh(string $responseId, string $nonce): void
    {
        if (!isset($this->chatGptTurns[$responseId])) return;
        $this->chatGptTurns[$responseId]['document_nonce'] = $nonce;
        $this->chatGptTurns[$responseId]['connector_catalog_refresh_pending'] = false;
        $this->chatGptTurns[$responseId]['connector_attempt'] = 0;
        $this->chatGptTurns[$responseId]['personalization_state'] = 'unchecked';
        $this->chatGptTurns[$responseId]['personalization_fallback_used'] = false;
        $this->chatGptTurns[$responseId]['personalization_original_index'] = null;
        $this->chatGptTurns[$responseId]['personalization_rollback_attempted'] = false;
        $this->confirmTaskEditor($responseId);
    }

    /** Activate the exact task Connector through the menu's native keyboard owner. */
    private function activateTaskConnectorChoice(string $responseId, string $connectorName, int $step): void
    {
        if (!isset($this->chatGptTurns[$responseId])) return;
        $this->evaluateTask($responseId, WebModelPage::connectorMenuExpression($connectorName),
            function ($error, $menu) use ($responseId, $connectorName, $step): void {
                if (!isset($this->chatGptTurns[$responseId])) return;
                if ($error !== null || !is_array($menu) || (int) ($menu['exact_count'] ?? 0) !== 1) {
                    $this->taskFailTurn($responseId, 'CHATGPT_CONNECTOR_SELECTION_FAILED', 'ChatGPT 工具连接器菜单状态发生变化。');
                    return;
                }
                if (!empty($menu['exact_highlighted'])) {
                    $this->dispatchTaskKey($responseId, 'Enter', function ($keyError) use ($responseId, $connectorName): void {
                        // Enter may have selected the item even if its acknowledgement was lost.
                        $this->waitTaskConnectorSelected($responseId, $connectorName, microtime(true) + 3.0);
                    });
                    return;
                }
                $visibleCount = max(1, (int) ($menu['visible_count'] ?? 0));
                if ($step >= $visibleCount) {
                    $this->taskFailTurn($responseId, 'CHATGPT_CONNECTOR_SELECTION_FAILED', 'ChatGPT 工具连接器菜单无法把键盘高亮移动到目标项。');
                    return;
                }
                $this->evaluateTask($responseId, WebModelPage::focusComposerExpression(),
                    function ($focusError, $focused) use ($responseId, $connectorName, $step): void {
                        if ($focusError !== null || !is_array($focused) || empty($focused['ok'])) {
                            $this->taskFailTurn($responseId, 'CHATGPT_CONNECTOR_SELECTION_FAILED', '工具连接器菜单打开后输入框无法保持键盘焦点。');
                            return;
                        }
                        $this->dispatchTaskKey($responseId, 'ArrowDown', function ($keyError) use ($responseId, $connectorName, $step): void {
                            if ($keyError !== null) {
                                $this->taskFailTurn($responseId, 'CHATGPT_CONNECTOR_SELECTION_FAILED', '工具连接器菜单键盘导航未完成。');
                                return;
                            }
                            Timer::add(0.05, fn() => $this->activateTaskConnectorChoice($responseId, $connectorName, $step + 1), null, false);
                        });
                    });
            });
    }

    private function waitTaskConnectorSelected(string $responseId, string $connectorName, float $deadline): void
    {
        if (!isset($this->chatGptTurns[$responseId])) return;
        $this->evaluateTask($responseId, WebModelPage::connectorSelectedExpression($connectorName),
            function ($error, $selected) use ($responseId, $connectorName, $deadline): void {
                if (!isset($this->chatGptTurns[$responseId])) return;
                if ($error === null && is_array($selected) && (int) ($selected['count'] ?? 0) > 1) {
                    $this->taskFailTurn($responseId, 'CHATGPT_CONNECTOR_DUPLICATE', 'ChatGPT 页面出现了重复的同名工具连接器。');
                    return;
                }
                if ($error === null && is_array($selected) && !empty($selected['selected'])) {
                    $this->prepareSelectedTaskConnector($responseId, $connectorName, 1);
                    return;
                }
                if (microtime(true) < $deadline) {
                    Timer::add(0.1, fn() => $this->waitTaskConnectorSelected($responseId, $connectorName, $deadline), null, false);
                    return;
                }
                $this->taskFailTurn($responseId, 'CHATGPT_CONNECTOR_SELECTION_FAILED', 'ChatGPT 未确认工具连接器已经选中。');
            });
    }

    /** A selected Connector is reusable only when its composer body is provably empty. */
    private function prepareSelectedTaskConnector(string $responseId, string $connectorName, int $attempt): void
    {
        if (!isset($this->chatGptTurns[$responseId])) return;
        $this->evaluateTask($responseId, WebModelPage::promptTextExpression(),
            function ($readError, $draft) use ($responseId, $connectorName, $attempt): void {
                if (!isset($this->chatGptTurns[$responseId])) return;
                if ($readError !== null || !is_array($draft) || empty($draft['ok'])) {
                    $this->taskFailTurn($responseId, 'CHATGPT_EDITOR_STATE_UNAVAILABLE', '无法确认 ChatGPT 工具编辑器是否为空。');
                    return;
                }
                if ((string) ($draft['text'] ?? '') === '') {
                    $this->insertTaskPrompt($responseId, true, $connectorName);
                    return;
                }
                // ChatGPT can preserve an unsent draft across tabs. Delete the entire stale draft
                // (including the old Connector pill), then run normal exact Connector selection again.
                $this->evaluateTask($responseId, WebModelPage::focusAndClearExpression(),
                    function ($clearError, $cleared) use ($responseId, $connectorName, $attempt): void {
                        if (!isset($this->chatGptTurns[$responseId])) return;
                        if ($clearError !== null || !is_array($cleared) || empty($cleared['ok']) || (string) ($cleared['text'] ?? '') !== '') {
                            $this->taskFailTurn($responseId, 'CHATGPT_EDITOR_CLEAR_FAILED', 'ChatGPT 中存在上次遗留草稿，且无法安全清空。');
                            return;
                        }
                        $this->prepareTaskConnector($responseId, $connectorName, $attempt);
                    });
            });
    }

    private function insertTaskPrompt(string $responseId, bool $withConnector, string $connectorName = ''): void
    {
        $turn = $this->chatGptTurns[$responseId] ?? null;
        if (!is_array($turn)) return;
        $this->chatGptTurns[$responseId]['connector_preparing'] = false;
        $prompt = (string) $turn['prompt'];
        $text = $withConnector ? ' ' . $prompt : $prompt;
        $this->evaluateTask($responseId, WebModelPage::insertPlainTextExpression($text),
            function ($insertError, $inserted) use ($responseId, $withConnector, $connectorName, $prompt): void {
                if (!isset($this->chatGptTurns[$responseId])) return;
                if (($insertError !== null && ($insertError['message'] ?? '') !== 'BROWSER_CDP_TIMEOUT')
                    || ($insertError === null && (!is_array($inserted) || empty($inserted['ok'])))) {
                    $this->taskFailTurn($responseId, 'CHATGPT_EDITOR_INPUT_FAILED', 'ChatGPT 编辑器未接受文本输入。');
                    return;
                }
                // A timed-out insert may already have mutated the editor. Read it back without
                // repeating the insertion; only an exact match can proceed to the send control.
                $deadline = min((float) $this->chatGptTurns[$responseId]['deadline'], microtime(true) + 30.0);
                $this->verifyInsertedTaskPrompt($responseId, $withConnector, $connectorName, $prompt, $deadline);
            }, 10.0);
    }

    /** Reconcile a possibly delayed editor write without performing another write. */
    private function verifyInsertedTaskPrompt(string $responseId, bool $withConnector, string $connectorName, string $prompt, float $deadline): void
    {
        if (!isset($this->chatGptTurns[$responseId])) return;
        $expression = $withConnector ? WebModelPage::promptTextExpression() : WebModelPage::composerTextExpression();
        $this->evaluateTask($responseId, $expression, function ($verifyError, $verify) use ($responseId, $withConnector, $connectorName, $prompt, $deadline): void {
            if (!isset($this->chatGptTurns[$responseId])) return;
            $expected = str_replace("\u{00A0}", ' ', $prompt);
            $actual = is_array($verify) ? str_replace("\u{00A0}", ' ', (string) ($verify['text'] ?? '')) : '';
            if ($verifyError !== null || !is_array($verify) || empty($verify['ok']) || $actual !== $expected) {
                if (microtime(true) < $deadline) {
                    Timer::add(0.5, fn() => $this->verifyInsertedTaskPrompt($responseId, $withConnector, $connectorName, $prompt, $deadline), null, false);
                    return;
                }
                $limit = min(strlen($expected), strlen($actual));
                $prefix = 0;
                while ($prefix < $limit && $expected[$prefix] === $actual[$prefix]) $prefix++;
                $this->taskFailTurn(
                    $responseId,
                    'CHATGPT_EDITOR_VERIFY_FAILED',
                    'ChatGPT 编辑器中的文本与待发送内容不一致，已停止发送。'
                        . ' expected_bytes=' . strlen($expected)
                        . ' actual_bytes=' . strlen($actual)
                        . ' common_prefix_bytes=' . $prefix
                );
                return;
            }
            if (!$withConnector) {
                $this->prepareTaskAttachments($responseId);
                return;
            }
            $this->confirmTaskConnector($responseId, $connectorName,
                fn() => $this->prepareTaskAttachments($responseId),
                '输入任务内容后未能确认工具连接器，已停止发送。');
        });
    }

    /**
     * Confirm the exact single Connector after composer rendering, without altering the draft.
     * Transient DOM/CDP reads may settle within 15 seconds; duplicate/conflicting pills fail
     * immediately. Retries retain the original surface/document/owner and never reselect or send.
     * @param callable():void $onSelected Next integrity check after positive identity evidence.
     * @param float|null $deadline Absolute clock, bounded by the current turn's deadline.
     */
    private function confirmTaskConnector(string $responseId, string $connectorName, callable $onSelected,
        string $failureMessage, ?float $deadline = null): void
    {
        $turn = $this->chatGptTurns[$responseId] ?? null;
        if (!is_array($turn)) return;
        $deadline = min((float)$turn['deadline'], $deadline ?? microtime(true) + 15.0);
        $owner = array_intersect_key($turn, array_flip([
            'surface_id', 'target_id', 'session_id', 'document_nonce', 'owner_incarnation', 'fencing_version',
        ]));
        $sameOwner = function () use ($responseId, $owner): bool {
            $current = $this->chatGptTurns[$responseId] ?? null;
            return is_array($current) && array_intersect_key($current, $owner) === $owner;
        };
        $lastFailureCode = 'CHATGPT_CONNECTOR_SELECTION_LOST';
        $lastDetail = '';
        $observe = function () use (&$observe, $responseId, $connectorName, $onSelected,
            $failureMessage, $deadline, $sameOwner, &$lastFailureCode, &$lastDetail): void {
            if (!$sameOwner()) return;
            if (microtime(true) >= $deadline) {
                $this->taskFailTurn($responseId, $lastFailureCode, $failureMessage . $lastDetail);
                return;
            }
            $this->evaluateTask($responseId, WebModelPage::connectorSelectedExpression($connectorName),
                function ($error, $selected) use (&$observe, $responseId, $onSelected,
                    $failureMessage, $deadline, $sameOwner, &$lastFailureCode, &$lastDetail): void {
                    if (!$sameOwner()) return;
                    if ($error === null && is_array($selected)
                        && ((int)($selected['count'] ?? 0) > 1 || (int)($selected['total_count'] ?? 0) > 1
                            || ($selected['code'] ?? null) === 'CHATGPT_CONNECTOR_CONFLICT')) {
                        $this->taskFailTurn($responseId, 'CHATGPT_CONNECTOR_DUPLICATE',
                            'ChatGPT 页面出现了重复或冲突的工具连接器，未发送。');
                        return;
                    }
                    if (microtime(true) < $deadline && $error === null && is_array($selected)
                        && !empty($selected['selected']) && (int)($selected['count'] ?? 0) === 1
                        && (int)($selected['total_count'] ?? 0) === 1) {
                        $onSelected();
                        return;
                    }
                    $unreadable = $error !== null || !is_array($selected);
                    $lastDetail = $unreadable
                        ? ' observation=' . mb_substr((string)($error['message'] ?? 'INVALID_CONNECTOR_STATE'), 0, 160)
                        : ' count=' . (int)($selected['count'] ?? 0) . ' total_count=' . (int)($selected['total_count'] ?? 0);
                    $lastFailureCode = $unreadable
                        ? 'CHATGPT_CONNECTOR_STATE_UNAVAILABLE' : 'CHATGPT_CONNECTOR_SELECTION_LOST';
                    if (microtime(true) + 0.25 < $deadline) {
                        Timer::add(0.25, $observe, null, false);
                        return;
                    }
                    $this->taskFailTurn($responseId, $lastFailureCode, $failureMessage . $lastDetail);
                }, min(3.0, max(0.1, $deadline - microtime(true))));
        };
        $observe();
    }

    /** Attach project-private files only on the final commit message, with one bounded input-discovery deadline. */
    private function prepareTaskAttachments(string $responseId, ?float $deadline = null): void
    {
        $turn = $this->chatGptTurns[$responseId] ?? null;
        if (!is_array($turn)) return;
        $attachments = (array) ($turn['attachments'] ?? []);
        if ($attachments === []) {
            $this->waitTaskSendReady($responseId, min((float) $turn['deadline'], microtime(true) + 60.0));
            return;
        }
        $expected = [];
        $files = [];
        $mimeTypes = [];
        $imageOnly = true;
        foreach ($attachments as $attachment) {
            if (!is_array($attachment) || !is_string($attachment['filename'] ?? null) || !is_string($attachment['path'] ?? null)) {
                $this->taskFailTurn($responseId, 'CHATGPT_ATTACHMENT_INVALID', '任务附件缺少可验证的本地文件信息。');
                return;
            }
            $expected[] = (string) $attachment['filename'];
            $files[] = (string) $attachment['path'];
            $mimeTypes[] = (string) ($attachment['mime_type'] ?? '');
            if (($attachment['kind'] ?? null) !== 'image') $imageOnly = false;
        }
        $deadline ??= min((float) $turn['deadline'], microtime(true) + 20.0);
        $this->evaluateTask($responseId, WebModelPage::attachmentStateExpression($expected, $imageOnly, $mimeTypes),
            function ($stateError, $state) use ($responseId, $expected, $files, $imageOnly, $deadline): void {
                if (!isset($this->chatGptTurns[$responseId])) return;
                if ($stateError === null && is_array($state) && !empty($state['ok'])) {
                    $this->waitTaskSendReady($responseId, min((float)$this->chatGptTurns[$responseId]['deadline'], microtime(true) + 60.0));
                    return;
                }
                // An existing or pending upload must be observed, never assigned again.
                // Duplicate/error chips can have matched_count=0 without an empty tray.
                if ($stateError === null && is_array($state) && ((int) ($state['matched_count'] ?? 0) > 0
                    || !empty($state['duplicate']) || !empty($state['upload_pending']) || !empty($state['upload_error']))) {
                    $this->waitTaskAttachmentsAccepted($responseId, $expected, $imageOnly,
                        $this->taskAttachmentDeadline($responseId));
                    return;
                }
                if ($stateError !== null || !is_array($state) || (int) ($state['input_count'] ?? 0) !== 1
                    || !is_string($state['input_selector'] ?? null) || $state['input_selector'] === '') {
                    if (microtime(true) < $deadline) {
                        Timer::add(0.10, fn() => $this->prepareTaskAttachments($responseId, $deadline), null, false);
                        return;
                    }
                    $this->taskFailTurn($responseId, 'CHATGPT_ATTACHMENT_INPUT_MISSING', 'ChatGPT 没有提供唯一可验证的附件输入控件。'
                        . ' [owned=' . (int) ($state['owned_input_count'] ?? 0)
                        . ', compatible=' . (int) ($state['compatible_input_count'] ?? 0)
                        . ', candidates=' . (int) ($state['input_count'] ?? 0)
                        . ', attachments=' . count($expected) . ']');
                    return;
                }
                $this->setTaskAttachmentFiles($responseId, $files, $expected, $imageOnly, (string) $state['input_selector']);
            });
    }

    /** Resolve the owned file input and assign local paths using native CDP. */
    private function setTaskAttachmentFiles(string $responseId, array $files, array $expected,
        bool $imageOnly, string $selector): void
    {
        $turn = $this->chatGptTurns[$responseId] ?? null;
        if (!is_array($turn)) return;
        $sessionId = (string) $turn['session_id'];
        $this->taskCdp->send('DOM.getDocument', ['depth' => 1, 'pierce' => true], $sessionId,
            function ($documentError, $document) use ($responseId, $sessionId, $files, $expected, $imageOnly, $selector): void {
                $rootId = is_array($document) ? (int) ($document['root']['nodeId'] ?? 0) : 0;
                if ($documentError !== null || $rootId < 1) {
                    $this->taskFailTurn($responseId, 'CHATGPT_ATTACHMENT_INPUT_MISSING', '无法读取 ChatGPT 附件输入节点。');
                    return;
                }
                $this->taskCdp->send('DOM.querySelectorAll', [
                    'nodeId' => $rootId,
                    'selector' => $selector,
                ], $sessionId, function ($queryError, $query) use ($responseId, $sessionId, $files, $expected, $imageOnly): void {
                    $nodes = is_array($query) && is_array($query['nodeIds'] ?? null) ? $query['nodeIds'] : [];
                    $nodeId = count($nodes) === 1 ? (int) $nodes[0] : 0;
                    if ($queryError !== null || $nodeId < 1) {
                        $this->taskFailTurn($responseId, 'CHATGPT_ATTACHMENT_INPUT_MISSING', 'ChatGPT 附件输入节点已经失效。');
                        return;
                    }
                    if (!isset($this->chatGptTurns[$responseId])) return;
                    $deadline = $this->taskAttachmentDeadline($responseId);
                    $this->taskCdp->send('DOM.setFileInputFiles', ['files' => array_values($files), 'nodeId' => $nodeId], $sessionId,
                        function ($setError) use ($responseId, $expected, $imageOnly, $deadline): void {
                            if ($setError !== null && ($setError['message'] ?? '') !== 'BROWSER_CDP_TIMEOUT') {
                                $this->taskFailTurn($responseId, 'CHATGPT_ATTACHMENT_UPLOAD_FAILED', 'ChatGPT 未接受本地附件输入。');
                                return;
                            }
                            if (!isset($this->chatGptTurns[$responseId])) return;
                            $this->waitTaskAttachmentsAccepted($responseId, $expected, $imageOnly, $deadline);
                        }, 10.0);
                }, 5.0);
            }, 5.0);
    }

    /** Start one upload budget per physical turn; callbacks and partial chips never renew it. */
    private function taskAttachmentDeadline(string $responseId): float
    {
        $turn = &$this->chatGptTurns[$responseId];
        if (!isset($turn['attachment_wait_deadline'])) {
            $now = microtime(true);
            $seconds = max(60, min(1800, (int) (Config::get('gateway', 'attachment_upload_timeout_seconds') ?? 600)));
            $turn['attachment_wait_started_at'] = $now;
            $turn['attachment_wait_deadline'] = min((float) $turn['deadline'], $now + $seconds);
            $this->recordTaskAttachmentObservation($responseId, 'attachment_wait', []);
        }
        return min((float) $turn['deadline'], (float) $turn['attachment_wait_deadline']);
    }

    /** Record upload boundaries only, with numeric/boolean facts; never filenames or file contents. */
    private function recordTaskAttachmentObservation(string $responseId, string $phase, array $state): void
    {
        $turn = $this->chatGptTurns[$responseId] ?? null;
        if (!is_array($turn)) return;
        $started = (float) ($turn['attachment_wait_started_at'] ?? microtime(true));
        $facts = array_intersect_key($state, array_flip(['matched_count', 'upload_pending', 'upload_error', 'duplicate']));
        $facts += ['expected_count' => count((array) ($turn['attachments'] ?? [])),
            'elapsed_ms' => (int) round(max(0.0, microtime(true) - $started) * 1000),
            'wait_budget_ms' => (int) round(max(0.0, (float) ($turn['attachment_wait_deadline'] ?? $started) - $started) * 1000)];
        $this->recordTaskTransportObservation($responseId, $phase, $facts);
    }

    /** Wait for exact files and finished upload UI within the same bounded attachment deadline. */
    private function waitTaskAttachmentsAccepted(string $responseId, array $expected, bool $imageOnly, float $deadline): void
    {
        if (!isset($this->chatGptTurns[$responseId])) return;
        $this->evaluateTask($responseId, WebModelPage::attachmentStateExpression($expected, $imageOnly),
            function ($error, $state) use ($responseId, $expected, $imageOnly, $deadline): void {
                if (!isset($this->chatGptTurns[$responseId])) return;
                if ($error === null && is_array($state) && !empty($state['ok'])) {
                    $this->recordTaskAttachmentObservation($responseId, 'attachment_ready', $state);
                    // File chips appear before upload/indexing enables Send. Preserve the
                    // original upload deadline instead of imposing a new five-second cutoff.
                    $this->waitTaskSendReady($responseId, $deadline);
                    return;
                }
                if (microtime(true) < $deadline) {
                    Timer::add(0.10, fn() => $this->waitTaskAttachmentsAccepted($responseId, $expected, $imageOnly, $deadline), null, false);
                    return;
                }
                $alerts = is_array($state['alerts'] ?? null) ? implode(' | ', array_slice($state['alerts'], -3)) : '';
                $this->recordTaskAttachmentObservation($responseId, 'attachment_timeout', $error === null && is_array($state) ? $state : []);
                $message = 'ChatGPT 没有确认全部任务附件。';
                if ($alerts !== '') $message .= ' ' . mb_substr($alerts, 0, 300);
                $this->taskFailTurn($responseId, 'CHATGPT_ATTACHMENT_ACCEPTANCE_FAILED', $message);
            });
    }

    /** Wait within one preparation budget; callbacks and timers stay on their original document and generation. */
    private function waitTaskSendReady(string $responseId, float $deadline, ?array $owner = null): void
    {
        $turn = $this->chatGptTurns[$responseId] ?? null;
        if (!is_array($turn)) return;
        $keys = array_flip(['surface_id', 'target_id', 'session_id', 'document_nonce', 'owner_incarnation',
            'fencing_version', 'logical_response_id', 'transport_transaction_id', 'transport_stage_sent', 'transport_stage_acked']);
        $owner ??= array_intersect_key($turn, $keys);
        if (array_intersect_key($turn, $keys) !== $owner) return;
        $this->evaluateTask($responseId, WebModelPage::sendReadinessExpression(), function ($error, $state) use ($responseId, $deadline, $keys, $owner): void {
            $current = $this->chatGptTurns[$responseId] ?? null;
            if (!is_array($current) || array_intersect_key($current, $keys) !== $owner) return;
            if ($error === null && is_array($state) && !empty($state['ready']) && microtime(true) < $deadline) {
                Timer::add(0.25, fn() => $this->verifySettledTaskSend($responseId, $deadline, $owner), null, false);
                return;
            }
            if (microtime(true) < $deadline) {
                Timer::add(0.25, fn() => $this->waitTaskSendReady($responseId, $deadline, $owner), null, false);
                return;
            }
            $code = is_array($state) ? (string) ($state['code'] ?? 'CHATGPT_SEND_NOT_READY') : 'CHATGPT_SEND_NOT_READY';
            $this->taskFailTurn($responseId, $code, 'ChatGPT 发送控件在限定时间内未进入可点击状态。');
        }, min(15.0, max(0.1, $deadline - microtime(true))));
    }

    /** Re-check the settled composer using the same preparation budget and original page ownership. */
    private function verifySettledTaskSend(string $responseId, float $deadline, ?array $owner = null): void
    {
        $turn = $this->chatGptTurns[$responseId] ?? null;
        if (!is_array($turn)) return;
        $keys = array_flip(['surface_id', 'target_id', 'session_id', 'document_nonce', 'owner_incarnation',
            'fencing_version', 'logical_response_id', 'transport_transaction_id', 'transport_stage_sent', 'transport_stage_acked']);
        $owner ??= array_intersect_key($turn, $keys);
        if (array_intersect_key($turn, $keys) !== $owner) return;
        $this->evaluateTask($responseId, WebModelPage::sendReadinessExpression(),
            function ($readyError, $ready) use ($responseId, $deadline, $keys, $owner): void {
                $turn = $this->chatGptTurns[$responseId] ?? null;
                if (!is_array($turn) || array_intersect_key($turn, $keys) !== $owner) return;
                if ($readyError !== null || !is_array($ready) || empty($ready['ready']) || microtime(true) >= $deadline) {
                    if (microtime(true) < $deadline) {
                        Timer::add(0.25, fn() => $this->verifySettledTaskSend($responseId, $deadline, $owner), null, false);
                        return;
                    }
                    $code = is_array($ready) ? (string) ($ready['code'] ?? 'CHATGPT_SEND_NOT_READY') : 'CHATGPT_SEND_NOT_READY';
                    $this->taskFailTurn($responseId, $code, 'ChatGPT 编辑器状态稳定后发送控件不可用。');
                    return;
                }
                $this->verifyTaskFinalModel($responseId, function () use ($responseId): void {
                    $turn = $this->chatGptTurns[$responseId] ?? null;
                    if (!is_array($turn)) return;
                    if (empty($turn['tool_capable'])) {
                        $this->verifyTaskAttachmentsThenSend($responseId);
                        return;
                    }
                    $connectorName = trim((string) ($turn['connector_name'] ?? ''));
                    if ($connectorName === '') {
                        $this->taskFailTurn($responseId, 'CHATGPT_CONNECTOR_NAME_MISSING', '发送前无法确认工具连接器名称。');
                        return;
                    }
                    $this->verifySettledTaskConnector($responseId, $connectorName);
                });
            }, min(15.0, max(0.1, $deadline - microtime(true))));
    }

    /** Final submission is allowed only if ChatGPT still reports the exact requested effort. */
    private function verifyTaskFinalModel(string $responseId, callable $after): void
    {
        $turn = $this->chatGptTurns[$responseId] ?? null;
        $mode = is_array($turn) ? ($turn['requested_mode'] ?? null) : null;
        if (!is_array($turn) || !is_array($mode)) {
            $this->taskFailTurn($responseId, 'CHATGPT_MODEL_SELECTION_UNPROVEN', '发送前缺少 ChatGPT 模型选择凭据。');
            return;
        }
        $this->verifyModelSelection($responseId, $mode, $after);
    }

    /** Final readback shared by real task sends and the isolated non-Pro connector probe. */
    private function verifyModelSelection(string $responseId, array $mode, callable $after, bool $thinkRestored = false): void
    {
        if (!is_int($mode['ui_index'] ?? null)) {
            $this->evaluateModelSelection($responseId, WebModelPage::thinkStateExpression(),
                function ($error, $state) use ($responseId, $mode, $after, $thinkRestored): void {
                    if ($error !== null || !is_array($state) || empty($state['ok'])) {
                        $this->failModelSelection($responseId, 'CHATGPT_MODEL_SELECTION_UNPROVEN', '发送前无法复核 Luna / Think 状态。');
                        return;
                    }
                    $expected = !empty($mode['think']);
                    $actual = (int) ($state['count'] ?? 0) === 1 ? !empty($state['pressed']) : false;
                    if ($actual !== $expected) {
                        // Free's App mention selection resets Think. Restore only the exact
                        // requested toggle once, then re-read; never clear or downgrade the prompt.
                        if (!$thinkRestored && (int) ($state['count'] ?? 0) === 1 && empty($state['disabled'])) {
                            $this->activateTaskThinkControl($responseId, $expected,
                                fn() => $this->verifyModelSelection($responseId, $mode, $after, true), false);
                            return;
                        }
                        $this->failModelSelection($responseId, 'CHATGPT_MODEL_SELECTION_DRIFT', '发送前 Luna / Think 状态已经变化。');
                        return;
                    }
                    $after();
                });
            return;
        }
        $this->evaluateModelSelection($responseId, WebModelPage::focusEffortControlExpression(),
            function ($error, $focused) use ($responseId, $mode, $after): void {
                if ($error !== null || !is_array($focused) || empty($focused['ok'])) {
                    $this->failModelSelection($responseId, 'CHATGPT_MODEL_SELECTION_UNPROVEN', '发送前无法复核 ChatGPT 推理档位。');
                    return;
                }
                $read = fn() => $this->verifyTaskFinalEffortSlider($responseId, $mode, $after, microtime(true) + 4.0);
                if (!empty($focused['expanded'])) { $read(); return; }
                $this->dispatchModelSelectionKey($responseId, 'Enter', function ($keyError) use ($responseId, $read): void {
                    if ($keyError !== null && ($keyError['message'] ?? '') !== 'BROWSER_CDP_TIMEOUT') {
                        $this->failModelSelection($responseId, 'CHATGPT_MODEL_SELECTION_UNPROVEN', '发送前无法打开 ChatGPT 推理档位菜单。');
                        return;
                    }
                    $read();
                });
            });
    }

    /** Wait for readback to settle within the original bound; restriction is distinct from identity drift. */
    private function verifyTaskFinalEffortSlider(string $responseId, array $mode, callable $after, float $deadline): void
    {
        $this->evaluateModelSelection($responseId, WebModelPage::effortSliderExpression(),
            function ($error, $slider) use ($responseId, $mode, $after, $deadline): void {
                if (!$this->modelSelectionActive($responseId)) return;
                if ($error === null && is_array($slider) && !empty($slider['ok'])) {
                    $familyMatches = !is_string($mode['family'] ?? null)
                        || ChatGptTransport::familyChecked($slider, $mode['family']);
                    $availability = $familyMatches ? ChatGptTransport::availabilityError($slider, $mode) : null;
                    if ($availability !== null) {
                        $this->dispatchModelSelectionKey($responseId, 'Escape', static function (): void {});
                        $this->failModelSelection($responseId, $availability['code'], $availability['message']);
                        return;
                    }
                    $target = (int) ($slider['min'] ?? 0) + (int) $mode['ui_index'];
                    $matches = (int) ($slider['value'] ?? -1) === $target
                        && (!is_string($mode['family'] ?? null)
                            || (ChatGptTransport::familyChecked($slider, $mode['family'])
                                && ChatGptTransport::descriptionMatches($slider, $mode['family'], (string) $mode['effort'])));
                    if ($matches) {
                        $this->closeTaskEffortMenu($responseId, $after, false);
                        return;
                    }
                    // Slider position and its live description can hydrate separately.
                    // Only read again: no key replay, model downgrade, send or deadline extension.
                    if (microtime(true) < $deadline) {
                        Timer::add(0.08, fn() => $this->verifyTaskFinalEffortSlider($responseId, $mode, $after, $deadline), null, false);
                        return;
                    }
                    $this->dispatchModelSelectionKey($responseId, 'Escape', static function (): void {});
                    $detail = implode(' | ', array_filter((array) ($slider['descriptions'] ?? []), 'is_string'));
                    $this->failModelSelection($responseId, 'CHATGPT_MODEL_SELECTION_DRIFT',
                        '发送前 ChatGPT 模型家族、推理档位或模型说明已经变化。'
                        . '网页档位：' . (int) ($slider['value'] ?? -1) . '；说明：' . mb_substr($detail, 0, 240));
                    return;
                }
                if (microtime(true) < $deadline) {
                    Timer::add(0.08, fn() => $this->verifyTaskFinalEffortSlider($responseId, $mode, $after, $deadline), null, false);
                    return;
                }
                $this->failModelSelection($responseId, 'CHATGPT_MODEL_SELECTION_UNPROVEN', '发送前无法读取 ChatGPT 推理档位。');
            });
    }
    /** Re-check Connector identity and prompt integrity after the UI settle window. */
    private function verifySettledTaskConnector(string $responseId, string $connectorName): void
    {
        if (!empty($this->chatGptTurns[$responseId]['retained_connector'])) {
            $job = ResponseJob::execution($responseId);
            $surface = TaskLedger::execution($responseId)['surface'];
            $sameTurn = !empty($this->chatGptTurns[$responseId]['continuation_reuse_connector']);
            $turn = $this->chatGptTurns[$responseId];
            $logicalResponseId = (string)($turn['logical_response_id'] ?? $responseId);
            $sameTurnChecks = [
                'flag'=>$sameTurn,
                'surface'=>$surface['id'] === $turn['surface_id'],
                'document'=>$surface['document_nonce'] === $turn['document_nonce'],
                'busy'=>$surface['status'] === 'busy',
                'submitted'=>$job['submitted_document_nonce'] === $surface['document_nonce'],
                'marker'=>(TaskLedger::continuationState($responseId)['marker'] ?? '') === $turn['continuation_marker'],
                'tools'=>(int)(ToolBroker::completionForResponse($logicalResponseId)['invocation_count'] ?? 0) > 0,
            ];
            $sameTurnProof = !in_array(false, $sameTurnChecks, true);
            if (($turn['compaction_phase'] ?? null) === 'preparing') {
                // Keep the exact Connector binding on the retained source page
                // for the separate control-only checkpoint.
                $compactJob = ResponseJob::execution($logicalResponseId);
                $sourceResponseId = (string)($compactJob['previous_response_id'] ?? '');
                $sourceJob = $sourceResponseId !== '' ? ResponseJob::execution($sourceResponseId) : [];
                try { $boundConnectorName = ToolBroker::connectorNameForJob($compactJob); }
                catch (GatewayError $bindingError) {
                    $this->taskFailTurn($responseId, $bindingError->errorCode, $bindingError->getMessage(), true);
                    return;
                }
                $sameTurnProof = $sameTurn
                    && $surface['id'] === $turn['surface_id']
                    && $surface['document_nonce'] === $turn['document_nonce']
                    && $surface['status'] === 'busy'
                    && ($sourceJob['submitted_document_nonce'] ?? null) === $surface['document_nonce']
                    && ($sourceJob['status'] ?? null) === 'completed'
                    && ($sourceJob['round_kind'] ?? null) === 'tool_call'
                    && !empty($sourceJob['mcp_binding_id'])
                    && $sourceJob['mcp_binding_id'] === $compactJob['mcp_binding_id']
                    && $boundConnectorName === $connectorName;
            }
            if (!$sameTurnProof && !\app\gateway\model\ContextHistory::retainedConnector($job, $surface, $connectorName)) {
                $this->taskFailTurn($responseId, 'CHATGPT_CONNECTOR_SELECTION_LOST', '原任务页面的连接器绑定已失效，未发送。');
                return;
            }
            $controlHandoff = ($turn['compaction_phase'] ?? null) === 'preparing';
            $readPrompt = function () use ($responseId, $controlHandoff): void {
                $expression = $controlHandoff
                    ? WebModelPage::promptTextExpression() : WebModelPage::composerTextExpression();
                $this->evaluateTask($responseId, $expression, function ($error, $draft) use ($responseId): void {
                    $turn = $this->chatGptTurns[$responseId] ?? null;
                    if (!is_array($turn)) return;
                    if ($error !== null || empty($draft['ok']) || str_replace("\u{00A0}", ' ', (string)($draft['text'] ?? ''))
                        !== str_replace("\u{00A0}", ' ', (string)$turn['prompt'])) {
                        $this->taskFailTurn($responseId, 'CHATGPT_EDITOR_VERIFY_FAILED', '原聊天续接正文发生变化，未发送。');
                        return;
                    }
                    $this->verifyTaskAttachmentsThenSend($responseId);
                });
            };
            if ($controlHandoff) {
                $this->confirmTaskConnector($responseId, $connectorName, $readPrompt,
                    '检查点消息未能确认原工具连接器，未发送。');
                return;
            }
            $readPrompt();
            return;
        }
        $this->confirmTaskConnector($responseId, $connectorName,
            function () use ($responseId): void {
                $turn = $this->chatGptTurns[$responseId] ?? null;
                if (!is_array($turn)) return;
                $this->evaluateTask($responseId, WebModelPage::promptTextExpression(),
                    function ($promptError, $draft) use ($responseId): void {
                        $turn = $this->chatGptTurns[$responseId] ?? null;
                        if (!is_array($turn)) return;
                        $expected = str_replace("\u{00A0}", ' ', (string) $turn['prompt']);
                        $actual = is_array($draft)
                            ? str_replace("\u{00A0}", ' ', (string) ($draft['text'] ?? '')) : '';
                        if ($promptError !== null || !is_array($draft) || empty($draft['ok']) || $actual !== $expected) {
                            $this->taskFailTurn($responseId, 'CHATGPT_EDITOR_VERIFY_FAILED', '发送前稳定等待后任务内容已经变化。');
                            return;
                        }
                        $this->verifyTaskAttachmentsThenSend($responseId);
                    });
            }, '发送前稳定等待后未能确认工具连接器，未发送。');
    }

    /** Final file-integrity fence immediately before irreversible submit. */
    private function verifyTaskAttachmentsThenSend(string $responseId): void
    {
        $turn = $this->chatGptTurns[$responseId] ?? null;
        if (!is_array($turn)) return;
        $attachments = (array) ($turn['attachments'] ?? []);
        if ($attachments === []) {
            $this->activateTaskSend($responseId);
            return;
        }
        $expected = array_values(array_map(static fn(array $attachment): string => (string) $attachment['filename'], $attachments));
        $imageOnly = !in_array(false, array_map(static fn(array $attachment): bool =>
            ($attachment['kind'] ?? null) === 'image', $attachments), true);
        $this->evaluateTask($responseId, WebModelPage::attachmentStateExpression($expected, $imageOnly),
            function ($error, $state) use ($responseId): void {
                if ($error !== null || !is_array($state) || empty($state['ok'])) {
                    $this->taskFailTurn($responseId, 'CHATGPT_ATTACHMENT_INTEGRITY_LOST', '发送前附件状态发生变化，已停止发送。');
                    return;
                }
                $this->activateTaskSend($responseId);
            });
    }

    /** Persist submit intent and baseline before the irreversible page click. */
    private function activateTaskSend(string $responseId): void
    {
        $turn = $this->chatGptTurns[$responseId] ?? null;
        if (!is_array($turn)) return;
        $this->evaluateTask($responseId, WebModelPage::capabilityExpression(),
            function ($surfaceError, $surface) use ($responseId): void {
                if (!isset($this->chatGptTurns[$responseId])) return;
                $turn = $this->chatGptTurns[$responseId] ?? null;
                $rootRequired = is_array($turn) && !empty($turn['require_root_before_send'])
                    && empty($turn['accepted']) && empty($turn['continuation_sending'])
                    && (int) ($turn['transport_stage_acked'] ?? 0) === 0;
                if ($surfaceError !== null || !is_array($surface)
                    || empty($surface['temporary_chat'])
                    || !WebModelPage::isTemporaryChatUrl((string) ($surface['url'] ?? ''), self::modelUrl())
                    || ($rootRequired && !WebModelPage::isTemporaryChatStartUrl((string) ($surface['url'] ?? ''), self::modelUrl()))) {
                    $this->taskFailTurn($responseId, 'CHATGPT_TEMPORARY_CHAT_LOST', '发送前任务页面已经离开 Temporary Chat，已停止发送。');
                    return;
                }
                $this->commitTaskSend($responseId);
            });
    }

    /** Persist submit intent, focus ChatGPT's native send control, then activate it with Enter. */
    private function commitTaskSend(string $responseId): void
    {
        $turn = $this->chatGptTurns[$responseId] ?? null;
        if (!is_array($turn)) return;
        try {
            ResponseJob::recordTransportSelection((string) ($turn['logical_response_id'] ?? $responseId),
                (array) ($turn['requested_mode'] ?? []));
            if (!empty($turn['tool_capable'])) {
                $connectorName = (string) ($turn['connector_name'] ?? '');
                if ($connectorName === '') {
                    throw new GatewayError('tool_binding_scope_mismatch', '发送前缺少任务绑定的连接器身份。', 409);
                }
                $currentConnector = hash_equals((string) GatewaySettings::internal()['connector_name'], $connectorName);
                if ($currentConnector) {
                    if (ConnectorState::runtimeCheckPending()) {
                        if (microtime(true) >= (float) $turn['deadline']) {
                            throw new GatewayError('CHATGPT_CONNECTOR_RECOVERY_TIMEOUT', '等待工具连接恢复超时，尚未发送本轮任务。', 504);
                        }
                        Timer::add(0.25, fn() => $this->commitTaskSend($responseId), null, false);
                        return;
                    }
                    ConnectorState::assertRuntimeReady();
                }
                if (($turn['compaction_phase'] ?? null) !== 'preparing') {
                    TaskLedger::connectorPrepared((string)($turn['logical_response_id'] ?? $responseId), !empty($turn['retained_connector']) ? 'retained' : 'mention',
                        (string)$turn['owner_incarnation'], (int)$turn['fencing_version']);
                }
            }
            if (($turn['compaction_phase'] ?? null) === 'preparing') {
                $logicalResponseId = (string) ($turn['logical_response_id'] ?? $responseId);
                if (!empty($turn['compaction_repair_used'])) {
                    if (ContextCompaction::acceptedRetainedHandoffSummary($logicalResponseId) !== null) {
                        $this->chatGptTurns[$responseId]['compaction_phase'] = 'waiting';
                        $this->scheduleTaskPoll($responseId, 0.01);
                        return;
                    }
                    ContextCompaction::assertRetainedHandoffRepairReady($logicalResponseId,
                        (string) ($turn['compaction_handoff_id'] ?? ''));
                    WebMessageAudit::recordOutgoing($logicalResponseId, 'compaction_handoff_repair',
                        (string)$turn['prompt']);
                } else {
                    ContextCompaction::markRetainedHandoffSubmitIntent($logicalResponseId,
                        (string) ($turn['compaction_handoff_id'] ?? ''));
                    WebMessageAudit::recordOutgoing($logicalResponseId, 'compaction_handoff', (string)$turn['prompt']);
                }
            } elseif (empty($turn['continuation_sending'])) {
                TaskLedger::submitIntent(
                    $responseId,
                    (array) $turn['baseline_users'],
                    (array) $turn['baseline_assistants'],
                    (string) $turn['owner_incarnation'],
                    (int) $turn['fencing_version'],
                    (string) $turn['prompt']
                );
            } else {
                WebMessageAudit::recordOutgoing((string)($turn['logical_response_id'] ?? $responseId), 'continuation', (string)$turn['prompt']);
            }
            if (($turn['compaction_phase'] ?? null) === 'preparing'
                || in_array((string) ($turn['operation_kind'] ?? 'response'), ['compact_v1', 'compact_v2'], true)) {
                // The first final send owns a new, pinned generation window. Composer
                // and connector checks must use the same clock as durable polling.
                $logicalResponseId = (string) ($turn['logical_response_id'] ?? $responseId);
                $this->chatGptTurns[$responseId]['deadline'] = (float) TaskLedger::execution($logicalResponseId)['turn']['deadline_at'];
            }
        } catch (GatewayError $error) {
            if (!empty($turn['compaction_repair_used'])
                && $error->errorCode === 'compaction_handoff_unavailable'
                && ContextCompaction::acceptedRetainedHandoffSummary(
                    (string) ($turn['logical_response_id'] ?? $responseId)) !== null) {
                $this->chatGptTurns[$responseId]['compaction_phase'] = 'waiting';
                $this->scheduleTaskPoll($responseId, 0.01);
                return;
            }
            $this->taskFailTurn($responseId, $error->errorCode, $error->getMessage());
            return;
        }
        $this->evaluateTask($responseId, WebModelPage::focusSendExpression(), function ($error, $focused) use ($responseId): void {
            if ($error !== null || !is_array($focused) || empty($focused['ok'])) {
                $code = is_array($focused) ? (string) ($focused['code'] ?? 'CHATGPT_SEND_FOCUS_FAILED') : 'CHATGPT_SEND_FOCUS_FAILED';
                $this->taskFailTurn($responseId, $code, 'ChatGPT 发送按钮无法获得键盘焦点。', true);
                return;
            }
            $turn = $this->chatGptTurns[$responseId] ?? null;
            if (is_array($turn) && !empty($turn['compaction_repair_used'])) {
                $logicalResponseId = (string) ($turn['logical_response_id'] ?? $responseId);
                if (ContextCompaction::acceptedRetainedHandoffSummary($logicalResponseId) !== null) {
                    $this->chatGptTurns[$responseId]['compaction_phase'] = 'waiting';
                    $this->scheduleTaskPoll($responseId, 0.01);
                    return;
                }
            }
            $this->chatGptTurns[$responseId]['sent_at'] = microtime(true);
            $this->chatGptTurns[$responseId]['continuation_sending'] = false;
            if (($this->chatGptTurns[$responseId]['compaction_phase'] ?? null) === 'preparing') {
                $this->chatGptTurns[$responseId]['compaction_phase'] = 'waiting';
            }
            $this->dispatchTaskKey($responseId, 'Enter', function ($keyError) use ($responseId): void {
                if (!isset($this->chatGptTurns[$responseId])) return;
                // An Input ACK can be lost after the page receives Enter. Observe the same
                // durable submit intent; never send a second key or claim acceptance here.
                if ($keyError !== null && ($this->chatGptTurns[$responseId]['compaction_phase'] ?? null) !== 'waiting') {
                    try { TaskLedger::observationDelayed($responseId, 'send_confirmation'); }
                    catch (\Throwable) { /* The original deadline and polling remain authoritative. */ }
                }
                $this->scheduleTaskPoll($responseId, 0.12);
            });
        });
    }

    /**
     * Observe the owned page and deliver durable native calls before settling or recovering it.
     * Explicit failed streams retain active work until receipts drain; UI disconnection alone
     * never authorizes completion or replay of a running generation. Unchanged polls read
     * fresh ledger metadata; full context is loaded only when adopting or settling a round.
     * A replacement browser command retires the old observer even when steering keeps the
     * same page. Only native receipt rounds sharing this physical command may be adopted.
     * @param string $responseId Physical observer key, mapped to the current logical response.
     * @return void
     */
    private function pollTaskTurn(string $responseId): void
    {
        $turn = $this->chatGptTurns[$responseId] ?? null;
        if (!is_array($turn)) return;
        if (!$this->taskCdp->ready()) {
            $this->pendingResumes[$responseId] = true;
            unset($this->chatGptTurns[$responseId]);
            return;
        }
        try {
            $logicalResponseId = (string) ($turn['logical_response_id'] ?? $responseId);
            $ledger = TaskLedger::pollExecution($logicalResponseId);
            if ((string) ($ledger['turn']['response_id'] ?? '') !== $logicalResponseId
                && ((string) ($ledger['turn']['surface_id'] ?? '') !== (string) ($turn['surface_id'] ?? '')
                    || (string) ($ledger['surface']['owner_incarnation'] ?? '') !== (string) ($turn['owner_incarnation'] ?? '')
                    || (int) ($ledger['surface']['fencing_version'] ?? 0) !== (int) ($turn['fencing_version'] ?? 0))) {
                // Same-page steering releases the prior owner before the new command
                // claims the page. This also retires observers without an MCP binding.
                $replacement = ResponseJob::pollState((string) $ledger['turn']['response_id']);
                $oldCommandId = (int) ($turn['command_id'] ?? 0);
                $this->releaseTaskSession($responseId);
                // A reattached receipt observer can still own the same physical command.
                if ($oldCommandId > 0 && (int) ($replacement['command_id'] ?? 0) !== $oldCommandId) {
                    try { $this->finishCommand($oldCommandId, ['status' => 'superseded']); }
                    catch (\Throwable) {}
                }
                return;
            }
            $currentResponse = ToolBroker::currentResponseForTurn((string) ($turn['durable_turn_id'] ?? ''));
            if (is_string($currentResponse) && $currentResponse !== '' && $currentResponse !== (string) ($turn['logical_response_id'] ?? $responseId)) {
                $next = ResponseJob::execution($currentResponse);
                if ((string) ($next['surface_id'] ?? '') !== (string) ($turn['surface_id'] ?? '')
                    || (int) ($next['command_id'] ?? 0) !== (int) ($turn['command_id'] ?? 0)) {
                    // Receipt continuations reuse this command. A new submission,
                    // including one still awaiting enqueue, belongs to its own observer.
                    $oldCommandId = (int) ($turn['command_id'] ?? 0);
                    $this->releaseTaskSession($responseId);
                    if ($oldCommandId > 0 && (int) ($next['command_id'] ?? 0) !== $oldCommandId) {
                        try { $this->finishCommand($oldCommandId, ['status' => 'superseded']); }
                        catch (\Throwable) {}
                    }
                    return;
                }
                if ((string) $next['status'] === 'queued') ResponseJob::start($currentResponse);
                $this->chatGptTurns[$responseId]['logical_response_id'] = $currentResponse;
                $this->chatGptTurns[$responseId]['operation_kind'] = (string) ($next['operation_kind'] ?? 'response');
                $renewedTurn = TaskLedger::pollExecution($currentResponse)['turn'];
                $this->chatGptTurns[$responseId]['deadline'] = (float)$renewedTurn['deadline_at'];
                $this->chatGptTurns[$responseId]['emitted'] = (string) ($next['output_text'] ?? '');
                $this->chatGptTurns[$responseId]['draft_emitted'] = ResponseJob::draftText($currentResponse);
                $this->chatGptTurns[$responseId]['tool_capable'] = !empty($next['mcp_binding_id']);
                $this->chatGptTurns[$responseId]['accepted'] = true;
                $this->chatGptTurns[$responseId]['observed_markdown'] = null;
                $this->chatGptTurns[$responseId]['observed_content'] = false;
                $this->chatGptTurns[$responseId]['empty_response_health'] = null;
                $this->chatGptTurns[$responseId]['tool_boundary_pending_id'] = null;
                $this->chatGptTurns[$responseId]['tool_boundary_pending_at'] = 0.0;
                $this->chatGptTurns[$responseId]['tool_completion_signature'] = null;
                $this->chatGptTurns[$responseId]['tool_completion_revision'] = null;
                $this->chatGptTurns[$responseId]['tool_completion_since'] = 0.0;
                $this->chatGptTurns[$responseId]['completion_fence_revision'] = null;
                $this->chatGptTurns[$responseId]['completion_fence_signature'] = null;
                $this->chatGptTurns[$responseId]['settle_text'] = null;
                $this->chatGptTurns[$responseId]['settle_at'] = 0.0;
                unset($this->chatGptTurns[$responseId]['pending_steering_stop']);
                $turn = $this->chatGptTurns[$responseId];
            }
        } catch (GatewayError $error) {
            $this->taskFailTurn($responseId, $error->errorCode, $error->getMessage(), true);
            return;
        }
        $logicalResponseId = (string) ($turn['logical_response_id'] ?? $responseId);
        try {
            $ledger = TaskLedger::pollExecution($logicalResponseId);
            // Tool receipts and verified same-page continuations each begin a
            // bounded new generation. Use its durable deadline before deciding
            // that the browser has timed out; the worker's cached value may predate
            // a continuation reserved or accepted in this response round.
            $deadline = (float) $ledger['turn']['deadline_at'];
            $this->chatGptTurns[$responseId]['deadline'] = $deadline;
            $surface = $ledger['surface'];
            if ((string) ($ledger['turn']['status'] ?? '') === 'needs_attention') {
                // A timed-out delivered host call has an unknown effect and revokes its
                // Connector capability. No further page polling can resolve that call.
                // Keep the task for user review, but release this browser command.
                try {
                    $terminal = BrowserState::finishUnknownToolCommand(
                        (int) ($turn['command_id'] ?? 0), $responseId);
                } catch (\Throwable) {
                    $this->scheduleTaskPoll($responseId, 1.0);
                    return;
                }
                if ($terminal) {
                    $this->releaseTaskSession($responseId);
                    return;
                }
            }
            if ((string) ($surface['target_id'] ?? '') !== (string) $turn['target_id']
                || (string) $surface['owner_incarnation'] !== (string) $turn['owner_incarnation']
                || (int) $surface['fencing_version'] !== (int) $turn['fencing_version']
                || (string) $surface['document_nonce'] !== (string) $turn['document_nonce']) {
                $this->taskFailTurn($responseId, 'CHATGPT_PAGE_CHANGED', '任务页面文档或所有权已变化，未重复发送消息。', true);
                return;
            }
            $compactionPhase = $this->chatGptTurns[$responseId]['compaction_phase'] ?? null;
            if (in_array($compactionPhase, ['waiting', 'baseline', 'preparing'], true)
                && ContextCompaction::acceptedRetainedHandoffSummary($logicalResponseId) !== null) {
                // The one-shot receipt was accepted within its fenced lifetime. A delayed
                // browser poll may consume that proof after the deadline; it cannot turn
                // an already received checkpoint into an unknown timeout.
                $this->chatGptTurns[$responseId]['compaction_phase'] = 'waiting';
                $this->pollRetainedCompactionHandoff($responseId, $logicalResponseId);
                return;
            }
            if ($compactionPhase === 'baseline'
                && empty($this->chatGptTurns[$responseId]['compaction_repair_used'])
                && ContextCompaction::retainedHandoffState($logicalResponseId) === null) {
                // Source completion begins one bounded composer-readiness phase. Its
                // fixed start, not repeated polls, permits at most 30 seconds before
                // reserving the independently timed checkpoint message.
                $deadline = max($deadline,
                    (float) $this->chatGptTurns[$responseId]['compaction_baseline_since'] + 30.0);
                $this->chatGptTurns[$responseId]['deadline'] = $deadline;
            }
        } catch (\Throwable) {
            $this->taskFailTurn($responseId, 'CHATGPT_STATE_UNAVAILABLE', '任务页面状态暂不可读取。', true);
            return;
        }
        if (microtime(true) >= $deadline) {
            $this->taskFailTurn($responseId, 'CHATGPT_RESPONSE_TIMEOUT', 'ChatGPT 网页生成超过本回合截止时间；结果无法确认且不会自动重发。', true);
            return;
        }
        if (!empty($this->chatGptTurns[$responseId]['response_observation_pending'])) {
            // A slow renderer read stays in flight. Its monitor still adopts native
            // receipts and drains registered tools; do not overlap browser actions
            // or queue another DOM read while that same renderer is stalled.
            try { $this->deliverPendingToolWithoutSnapshot($responseId); }
            catch (GatewayError $error) {
                $this->taskFailTurn($responseId, $error->errorCode, $error->getMessage(), true);
            }
            return;
        }
        if ($compactionPhase === 'waiting') {
            if (self::shouldCheckTaskToolConfirmation($this->chatGptTurns[$responseId])) {
                $this->checkTaskToolConfirmation($responseId);
                return;
            }
            $this->pollRetainedCompactionHandoff($responseId, $logicalResponseId);
            return;
        }
        if ($compactionPhase === 'baseline') {
            $this->observeRetainedCompactionBaseline($responseId, $logicalResponseId);
            return;
        }
        if ($compactionPhase === 'preparing') return;
        if ($compactionPhase === 'stopping_source') {
            $this->stopCompactionSource($responseId, $logicalResponseId);
            return;
        }
        if (ContextCompaction::retainedHandoffState($logicalResponseId) !== null) {
            // The original worker may have died after reserving the one-shot
            // checkpoint. Its plaintext token cannot be recovered or reissued.
            $this->taskFailTurn($responseId, 'compaction_handoff_outcome_unknown',
                '摘要交接的原页面发送结果未知，不能再次提交；原上下文仍保留。', true);
            return;
        }
        try { if ($this->stopForPendingSteering($responseId, $ledger)) return; }
        catch (GatewayError $error) {
            $this->taskFailTurn($responseId, $error->errorCode, $error->getMessage(), true);
            return;
        }
        $toolCapable = !empty($this->chatGptTurns[$responseId]['tool_capable']);
        if ($toolCapable && self::shouldCheckTaskToolConfirmation($this->chatGptTurns[$responseId])) {
            $this->checkTaskToolConfirmation($responseId);
            return;
        }
        $expression = WebModelPage::responseExpression((array) $turn['baseline_users'], (array) $turn['baseline_assistants'],
            (string)($turn['continuation_marker'] ?? ''), (string)($turn['bound_assistant_id'] ?? ''),
            self::hasLegacyCitationPrefix($turn));
        $this->observeTaskResponse($responseId, $expression, function ($error, $snapshot) use ($responseId): void {
            $turn = $this->chatGptTurns[$responseId] ?? null;
            if (!is_array($turn)) return;
            if ($error !== null || !is_array($snapshot)) {
                $this->chatGptTurns[$responseId]['empty_response_health'] = null;
                $this->chatGptTurns[$responseId]['post_receipt_ui_observation'] = null;
                $errors = (int) ($turn['poll_errors'] ?? 0) + 1;
                $this->chatGptTurns[$responseId]['poll_errors'] = $errors;
                if ($errors >= 2) {
                    try {
                        // A registered MCP call is already durable evidence that this
                        // page requested the host tool. A stalled DOM snapshot must
                        // not strand that call until the MCP wait timeout revokes it.
                        if ($this->deliverPendingToolWithoutSnapshot($responseId)) {
                            $this->scheduleTaskPoll($responseId, 0.10);
                            return;
                        }
                    } catch (GatewayError $toolError) {
                        $this->taskFailTurn($responseId, $toolError->errorCode, $toolError->getMessage(), true);
                        return;
                    }
                }
                if ($errors >= 2) {
                    $targets = $this->discoverTargetsIfAvailable((int)(BrowserState::state()['debug_port'] ?? 0));
                    if (is_array($targets) && !isset($targets[(string)$turn['target_id']])) {
                        $logicalId = (string)($turn['logical_response_id'] ?? $responseId);
                        $this->releaseTaskSession($responseId);
                        $this->prepareTaskSurface($logicalId, (int)$turn['command_id'], true);
                        return;
                    }
                }
                $since = (float) ($turn['poll_error_since'] ?? 0.0);
                if ($since <= 0.0) $this->chatGptTurns[$responseId]['poll_error_since'] = $since = microtime(true);
                // Renderer stalls are not proof that a submitted model turn failed. Keep the same
                // fenced page and original durable deadline; HTTP subscribers retain heartbeats.
                if ($errors >= 4 && microtime(true) - $since >= 30.0 && empty($turn['observation_delay_reported'])) {
                    $this->chatGptTurns[$responseId]['observation_delay_reported'] = true;
                    try { TaskLedger::observationDelayed((string) ($turn['logical_response_id'] ?? $responseId)); }
                    catch (\Throwable) { /* Observation remains read-only even if the diagnostic write fails. */ }
                }
                $this->scheduleTaskPoll($responseId, min(5.0, 1.0 + $errors * 0.5));
                return;
            }
            $this->chatGptTurns[$responseId]['poll_errors'] = 0;
            $this->chatGptTurns[$responseId]['poll_error_since'] = 0.0;
            if (!self::sameOrigin((string) ($snapshot['url'] ?? ''), self::modelUrl())) {
                $this->taskFailTurn($responseId, 'CHATGPT_PAGE_CHANGED', '任务页面离开 ChatGPT，结果未知且不会自动重发。', true);
                return;
            }
            $snapshotUrl = (string)($snapshot['url'] ?? '');
            if (!WebModelPage::isTemporaryChatUrl($snapshotUrl, self::modelUrl())) {
                // ChatGPT briefly uses /c/local-chatgpt%3A<id> before the final
                // /c/<id> URL. Keep observing the same fenced page; this transition
                // is neither proof of acceptance nor reason to resend.
                $sinceSend = microtime(true) - (float)($turn['sent_at'] ?? 0.0);
                if ($sinceSend >= 0.0 && $sinceSend < 15.0
                    && WebModelPage::isTemporaryChatNavigationUrl($snapshotUrl, self::modelUrl())) {
                    $this->scheduleTaskPoll($responseId, 0.10);
                    return;
                }
                $this->taskFailTurn($responseId, 'CHATGPT_TEMPORARY_CHAT_LOST', '任务页面已经离开 Temporary Chat，结果未知且不会自动重发。', true);
                return;
            }
            try {
                TaskLedger::observeSurface((string) $turn['surface_id'], (string) $turn['owner_incarnation'], (int) $turn['fencing_version'], (string) ($snapshot['url'] ?? ''));
            } catch (GatewayError $error) {
                $this->taskFailTurn($responseId, $error->errorCode, $error->getMessage(), true);
                return;
            }
            $session = $this->taskSessions[(string)$turn['session_id']] ?? [];
            if (($snapshot['generation_running'] ?? null) === false
                && ($snapshot['send_button_visible'] ?? null) === true
                && (empty($session['submission_request_id']) || (!empty($session['submission_finished'])
                    && !empty($session['submission_failed']) && ($session['submission_canceled'] ?? null) === false))
                && in_array($snapshot['error']['code'] ?? '', ['', 'CHATGPT_STOPPED_THINKING',
                    'CHATGPT_NETWORK_ERROR', 'CHATGPT_MESSAGE_STREAM_ERROR'], true)) {
                $currentId = (string)($turn['logical_response_id'] ?? $responseId);
                try {
                    $current = ResponseJob::pollState($currentId);
                    if (in_array($current['operation_kind'] ?? '', ['compact_v1', 'compact_v2'], true)
                        && !empty($current['previous_response_id']) && !empty($turn['tool_capable'])
                        && $this->compactionSourceTransportClosed($currentId, $session)) {
                        // Closed transport plus a stopped page must not wait for delivery to the old receiver.
                        $this->chatGptTurns[$responseId]['compaction_closed_source'] = true;
                        $this->chatGptTurns[$responseId]['compaction_phase'] = 'stopping_source';
                        $this->stopCompactionSource($responseId, $currentId);
                        return;
                    }
                } catch (GatewayError $stateError) {
                    if ($stateError->errorCode === 'STORAGE_BUSY') $this->scheduleTaskPoll($responseId, 1.0);
                    else $this->taskFailTurn($responseId, $stateError->errorCode, $stateError->getMessage(), true);
                    return;
                }
            }
            if (is_array($snapshot['error'] ?? null)) {
                if (($snapshot['error']['code'] ?? '') === 'CHATGPT_STOPPED_THINKING') {
                    $this->recordStoppedThinkingObservation($responseId, $snapshot);
                }
                $this->chatGptTurns[$responseId]['post_receipt_ui_observation'] = null;
                $networkError = ($snapshot['error']['code'] ?? '') === 'CHATGPT_NETWORK_ERROR';
                $failedMessageStream = $networkError
                    || ($snapshot['error']['code'] ?? '') === 'CHATGPT_MESSAGE_STREAM_ERROR';
                if ($networkError && $this->retryCompactNetworkError($responseId, $snapshot)) return;
                if (!$networkError && in_array($snapshot['error']['code'] ?? '', ['CHATGPT_UPSTREAM_ERROR','CHATGPT_MESSAGE_TOO_LARGE'], true)
                    && $this->waitForTaskSubmissionError($responseId)) {
                    $this->scheduleTaskPoll($responseId, 0.2);
                    return;
                }
                if (!$failedMessageStream) {
                    $this->taskFailTurn($responseId, (string) ($snapshot['error']['code'] ?? 'CHATGPT_UPSTREAM_ERROR'), (string) ($snapshot['error']['message'] ?? 'ChatGPT 页面显示生成错误。'));
                    return;
                }
            }
            $now = microtime(true);
            $acceptedEvidence = !empty($snapshot['new_user']) || !empty($snapshot['response_present']) || !empty($snapshot['generation_running']);
            $logicalResponseId = (string) ($this->chatGptTurns[$responseId]['logical_response_id'] ?? $responseId);
            if (!$turn['accepted'] && $acceptedEvidence) {
                try { ResponseJob::accepted($logicalResponseId); }
                catch (\Throwable) {
                    $this->taskFailTurn($responseId, 'CHATGPT_ACCEPTANCE_SAVE_FAILED', '页面接收证据无法保存。', true);
                    return;
                }
                $this->chatGptTurns[$responseId]['accepted'] = true;
            }
            $sentAt = (float) ($this->chatGptTurns[$responseId]['sent_at'] ?? 0.0);
            if (!$this->chatGptTurns[$responseId]['accepted'] && $sentAt > 0 && $now - $sentAt >= 45.0) {
                $this->taskFailTurn($responseId, 'CHATGPT_SEND_UNCONFIRMED', '已持久化发送意图，但页面没有给出接收证据；结果未知且不会自动重发。', true);
                return;
            }
            $marker = (string)($this->chatGptTurns[$responseId]['continuation_marker'] ?? '');
            if ($marker !== '') {
                if (empty($snapshot['continuation_user_accepted'])) {
                    if (!empty($this->chatGptTurns[$responseId]['continuation_pending'])
                        && $now - (float)($this->chatGptTurns[$responseId]['continuation_intent_at'] ?? $now) >= 20.0) {
                        $this->taskFailTurn($responseId, 'continuation_outcome_unknown',
                            '网页续做消息已尝试发送，但未能确认是否被接收；不会重复提交已有主机操作。', true);
                        return;
                    }
                    $this->scheduleTaskPoll($responseId, 0.1);
                    return;
                }
                if (!empty($this->chatGptTurns[$responseId]['continuation_pending'])) {
                    try { TaskLedger::continuationAccepted($logicalResponseId, $marker); }
                    catch (GatewayError $error) {
                        $this->taskFailTurn($responseId, $error->errorCode, $error->getMessage(), true);
                        return;
                    }
                    $this->chatGptTurns[$responseId]['continuation_pending'] = false;
                }
            }
            $previousAssistantId = (string) ($this->chatGptTurns[$responseId]['bound_assistant_id'] ?? '');
            $boundAssistantId = WebModelPage::assistantIdentityForSnapshot($previousAssistantId, $snapshot);
            $observedAssistantId = (string) ($snapshot['response_id'] ?? '');
            if ($previousAssistantId === '' && $boundAssistantId !== '') {
                // Once this submission exposes a stable assistant identity, later polls
                // must not switch to a newer transient or foreign assistant node.
                $this->chatGptTurns[$responseId]['bound_assistant_id'] = $boundAssistantId;
            }
            if ($boundAssistantId === '' || $observedAssistantId !== $boundAssistantId) {
                // Tool progress can continue without a rendered answer. Keep observing,
                // but never stream or complete from an unbound response snapshot.
                $snapshot['response_present'] = false;
                $snapshot['completion_action_visible'] = false;
                $snapshot['content_present'] = false;
                $snapshot['markdown'] = '';
                $snapshot['markdown_segments'] = [];
                $snapshot['visible_text'] = '';
                $snapshot['response_html'] = '';
                $snapshot['artifact_candidates'] = [];
                $snapshot['commentary_blocks'] = [];
            }
            $rawMarkdown = (string) ($snapshot['markdown'] ?? '');
            if ($boundAssistantId !== '' && $observedAssistantId === $boundAssistantId) {
                $recovering = ($snapshot['transport_recovery_visible'] ?? null) === true;
                $wasRecovering = ($this->chatGptTurns[$responseId]['transport_recovery_visible'] ?? null) === true;
                if ($recovering !== $wasRecovering) {
                    // Native recovery chrome is a transport observation, never assistant progress.
                    // Do not stop active tools, click Retry or re-send context because of this banner.
                    $this->recordTaskTransportObservation($responseId,
                        $recovering ? 'recovery_visible' : 'recovery_cleared',
                        ['generation_running'=>($snapshot['generation_running'] ?? null) === true]);
                    $this->chatGptTurns[$responseId]['transport_recovery_visible'] = $recovering;
                }
            }
            $prefix = (string) ($this->chatGptTurns[$responseId]['round_prefix'] ?? '');
            $rawProjection = WebModelPage::markdownAfterToolBoundary($rawMarkdown, $prefix);
            $aligned = self::alignTaskMarkdownObservation($this->chatGptTurns[$responseId], $snapshot, $now);
            $markdown = $aligned['markdown'];
            $projection = WebModelPage::markdownAfterToolBoundary($markdown, $prefix);
            $roundMarkdown = (string) $projection['markdown'];
            $toolCapable = !empty($this->chatGptTurns[$responseId]['tool_capable']);
            $emitted = (string) ($toolCapable
                ? ($this->chatGptTurns[$responseId]['draft_emitted'] ?? '')
                : ($this->chatGptTurns[$responseId]['emitted'] ?? ''));
            $contentPresent = !empty($snapshot['content_present']);
            $observedArtifacts = (array) ($this->chatGptTurns[$responseId]['artifact_observed_candidates'] ?? []);
            foreach ((array) ($snapshot['artifact_candidates'] ?? []) as $candidate) {
                if (count($observedArtifacts) >= 11) break;
                if (!is_array($candidate)) continue;
                $href = trim((string) ($candidate['href'] ?? ''));
                $filename = trim((string) ($candidate['filename'] ?? ''));
                $kind = trim((string) ($candidate['kind'] ?? 'anchor'));
                if ($filename === '' || ($href === '' && $kind !== 'file_card')) continue;
                $merged = false;
                foreach ($observedArtifacts as $index => $existing) {
                    if (!is_array($existing) || (string) ($existing['filename'] ?? '') !== $filename) continue;
                    $existingHref = trim((string) ($existing['href'] ?? ''));
                    if ($existingHref !== '' && $href !== '' && $existingHref !== $href) continue;
                    if ($existingHref === '' && $href !== '') $observedArtifacts[$index]['href'] = $href;
                    if ($kind === 'file_card') $observedArtifacts[$index]['kind'] = 'file_card';
                    $merged = true;
                    break;
                }
                if ($merged) continue;
                $observedArtifacts[] = ['href'=>$href,'filename'=>$filename,'kind'=>$kind];
                if (count($observedArtifacts) >= 11) break;
            }
            $this->chatGptTurns[$responseId]['artifact_observed_candidates'] = $observedArtifacts;
            try {
                $logicalJob = ResponseJob::pollState($logicalResponseId);
                $activeCompact = ($logicalJob['operation_kind'] ?? 'response') !== 'response'
                    && !empty($logicalJob['previous_response_id']);
                $invocations = ToolBroker::invocationsForResponse($logicalResponseId);
                $nativeToolBoundary = ($logicalJob['operation_kind'] ?? 'response') === 'response'
                    && ($invocations !== [] || ((string) $logicalJob['status'] === 'completed'
                        && (string) $logicalJob['round_kind'] === 'tool_call'));
                $progress = ChatGptTransport::settledCommentary(
                    (array) ($this->chatGptTurns[$responseId]['observed_commentary'] ?? []),
                    (array) ($snapshot['commentary_blocks'] ?? []), $now, $nativeToolBoundary,
                    $toolCapable && ($logicalJob['operation_kind'] ?? 'response') === 'response'
                        && ChatGptTransport::canStreamDraft(ToolBroker::completionForResponse($logicalResponseId))
                );
                $this->chatGptTurns[$responseId]['observed_commentary'] = $progress['observed'];
                $publicSummaries = [];
                foreach ((array) ($snapshot['commentary_blocks'] ?? []) as $block) {
                    if (!is_array($block) || empty($block['public_summary'])) continue;
                    $blockKey = (string) ($block['key'] ?? '');
                    $blockText = (string) ($block['text'] ?? '');
                    if ($blockKey === '' || $blockText === '') continue;
                    $publicSummaries[$blockKey . ':' . substr(hash('sha256', $blockText), 0, 16)] = true;
                }
                foreach ($progress['ready'] as $key => $text) {
                    if (isset($this->chatGptTurns[$responseId]['emitted_commentary'][$key])) continue;
                    if (($logicalJob['operation_kind'] ?? 'response') !== 'response') continue;
                    // Keep settled public blocks across native tool execution. The completed
                    // response is immutable; its next running round can publish these even
                    // when the Web renderer remounts them before Codex returns its receipts.
                    $pending = (array) ($this->chatGptTurns[$responseId]['pending_commentary'] ?? []);
                    if (!isset($pending[$key]) && count($pending) >= 128) continue;
                    $this->chatGptTurns[$responseId]['pending_commentary'][$key] = [
                        'text' => $text, 'reasoning' => isset($publicSummaries[$key]),
                    ];
                }
                foreach ($progress['drafts'] as $sourceKey => $text) {
                    $key = $sourceKey . ':' . substr(hash('sha256', $text), 0, 16);
                    if (isset($this->chatGptTurns[$responseId]['emitted_commentary'][$key])) continue;
                    $pending = (array) ($this->chatGptTurns[$responseId]['pending_commentary'] ?? []);
                    $pendingKey = 'prose:' . $sourceKey;
                    if (!isset($pending[$pendingKey]) && count($pending) >= 128) continue;
                    // A growing source replaces its queued snapshot; it must not enqueue
                    // every intermediate full paragraph while Codex executes a host tool.
                    $this->chatGptTurns[$responseId]['pending_commentary'][$pendingKey] = [
                        'text' => $text, 'reasoning' => false, 'prose_source' => $sourceKey, 'observation_key' => $key,
                    ];
                }
                if ((string) $logicalJob['status'] === 'completed' && (string) $logicalJob['round_kind'] === 'tool_call') {
                    $this->scheduleTaskPoll($responseId, 0.10);
                    return;
                }
                foreach ((array) ($this->chatGptTurns[$responseId]['pending_commentary'] ?? []) as $key => $block) {
                    if ($logicalJob['status'] !== 'running' || ($logicalJob['operation_kind'] ?? 'response') !== 'response') break;
                    $text = $block['text'];
                    $reasoning = $block['reasoning'];
                    $textHash = hash('sha256', ($reasoning ? 'reasoning' : 'commentary') . "\0" . $text);
                    if (!isset($this->chatGptTurns[$responseId]['emitted_commentary_text'][$textHash])) {
                        $accepted = isset($block['prose_source'])
                            ? ResponseJob::appendProgressSnapshot($logicalResponseId, $text, $block['prose_source'])
                            : ($reasoning ? ResponseJob::appendReasoningSummary($logicalResponseId, $text, $key)
                                : ResponseJob::appendCommentary($logicalResponseId, $text, $key));
                        // A concurrent stop or round change must not mark unpublished prose
                        // as delivered. The next valid poll retains its pending observation.
                        if (!$accepted) continue;
                    }
                    $this->chatGptTurns[$responseId]['emitted_commentary'][$key] = true;
                    if (isset($block['observation_key'])) {
                        $this->chatGptTurns[$responseId]['emitted_commentary'][$block['observation_key']] = true;
                    }
                    $this->chatGptTurns[$responseId]['emitted_commentary_text'][$textHash] = true;
                    unset($this->chatGptTurns[$responseId]['pending_commentary'][$key]);
                }
                if ($invocations !== []) {
                    $batchSignature = hash('sha256', json_encode(array_column($invocations, 'id'), JSON_THROW_ON_ERROR));
                    $pendingBoundaryId = $this->chatGptTurns[$responseId]['tool_boundary_pending_id'] ?? null;
                    if (!is_string($pendingBoundaryId) || !hash_equals($pendingBoundaryId, $batchSignature)) {
                        // A tool can be registered while the CDP snapshot above is still in flight.
                        // Wait for a fresh observation and a short quiet window so sibling
                        // calls from the same Web inference can join this Codex batch.
                        $this->chatGptTurns[$responseId]['tool_boundary_pending_id'] = $batchSignature;
                        $this->chatGptTurns[$responseId]['tool_boundary_pending_at'] = $now;
                        $this->chatGptTurns[$responseId]['tool_completion_signature'] = null;
                        $this->chatGptTurns[$responseId]['tool_completion_revision'] = null;
                        $this->chatGptTurns[$responseId]['tool_completion_since'] = 0.0;
                        $this->chatGptTurns[$responseId]['completion_fence_revision'] = null;
                        $this->chatGptTurns[$responseId]['completion_fence_signature'] = null;
                        $this->scheduleTaskPoll($responseId, 0.10);
                        return;
                    }
                    if ($now - (float) ($this->chatGptTurns[$responseId]['tool_boundary_pending_at'] ?? 0.0) < 0.15) {
                        $this->scheduleTaskPoll($responseId, 0.05);
                        return;
                    }
                    // The latest phase may have no following rendered status. Its exact
                    // registered host call supplies a boundary, but still require the same
                    // half-second unchanged-text evidence before sealing the tool response.
                    // This grace is bounded: a continuously changing renderer cannot block tools.
                    $waitingForProse = false;
                    foreach (array_slice((array) ($snapshot['commentary_blocks'] ?? []), -128) as $block) {
                        if (!is_array($block) || empty($block['public_prose']) || !empty($block['complete'])
                            || !empty($block['public_summary']) || !empty($block['public_status'])) continue;
                        $key = (string) ($block['key'] ?? ''); $text = (string) ($block['text'] ?? '');
                        if ($key !== '' && $text !== '' && !isset($this->chatGptTurns[$responseId]['emitted_commentary'][
                            $key . ':' . substr(hash('sha256', $text), 0, 16)])) { $waitingForProse = true; break; }
                    }
                    if ($waitingForProse && $now - (float) $this->chatGptTurns[$responseId]['tool_boundary_pending_at'] < 0.75) {
                        $this->scheduleTaskPoll($responseId, 0.05);
                        return;
                    }
                    // Keep answer-root text visible to Codex even if the tool arrived before the
                    // stable-text poll. It remains provisional commentary, never final-answer text.
                    // Native tool boundaries retain their existing raw, provisional text
                    // contract. They are not irreversible final-answer block commits.
                    $boundarySnapshot = $snapshot;
                    $boundarySnapshot['generation_running'] = false;
                    $boundaryPreview = self::alignTaskMarkdownObservation(
                        $this->chatGptTurns[$responseId], $boundarySnapshot, $now);
                    $boundaryText = $boundaryPreview['consistent']
                        ? (string) WebModelPage::markdownAfterToolBoundary($boundaryPreview['markdown'], $prefix)['markdown']
                        : (string) $rawProjection['markdown'];
                    if (!empty($logicalJob['checkpoint_marker'])) {
                        $boundaryText = \app\gateway\model\LunaCheckpoint::visible(
                            $boundaryText, $logicalJob['checkpoint_marker']);
                    }
                    ResponseJob::completeToolCalls($logicalResponseId, $invocations, $rawMarkdown, $boundaryText);
                    WebMessageAudit::recordIncoming($logicalResponseId, 'tool_boundary', $rawMarkdown);
                    $this->chatGptTurns[$responseId]['tool_boundary_pending_id'] = null;
                    $this->chatGptTurns[$responseId]['tool_boundary_pending_at'] = 0.0;
                    $this->chatGptTurns[$responseId]['round_prefix'] = $rawMarkdown;
                    $this->chatGptTurns[$responseId]['post_tool_answer_baseline'] = $rawMarkdown;
                    unset($this->chatGptTurns[$responseId]['markdown_buffer']);
                    $this->chatGptTurns[$responseId]['post_tool_answer_missing_since'] = 0.0;
                    $this->chatGptTurns[$responseId]['observed_markdown'] = null;
                    $this->chatGptTurns[$responseId]['observed_content'] = false;
                    $this->chatGptTurns[$responseId]['artifact_observed_candidates'] = [];
                    $this->chatGptTurns[$responseId]['tool_completion_signature'] = null;
                    $this->chatGptTurns[$responseId]['tool_completion_revision'] = null;
                    $this->chatGptTurns[$responseId]['tool_completion_since'] = 0.0;
                    $this->chatGptTurns[$responseId]['completion_fence_revision'] = null;
                    $this->chatGptTurns[$responseId]['completion_fence_signature'] = null;
                    $this->scheduleTaskPoll($responseId, 0.10);
                    return;
                }
                $this->chatGptTurns[$responseId]['tool_boundary_pending_id'] = null;
                $this->chatGptTurns[$responseId]['tool_boundary_pending_at'] = 0.0;
            } catch (GatewayError $error) {
                if ($error->errorCode === 'tool_batch_changed') {
                    $this->chatGptTurns[$responseId]['tool_boundary_pending_id'] = null;
                    $this->chatGptTurns[$responseId]['tool_boundary_pending_at'] = 0.0;
                    $this->scheduleTaskPoll($responseId, 0.05);
                    return;
                }
                $this->taskFailTurn($responseId, $error->errorCode, $error->getMessage(), true);
                return;
            }

            // codex_turn_complete carries the final answer itself. The Web page may
            // display no further assistant bubble after that MCP call, so waiting
            // for DOM completion would strand a successfully completed Codex turn.
            if ($toolCapable) {
                $explicitCompletion = ToolBroker::completionForResponse($logicalResponseId);
                if (!$activeCompact && is_array($explicitCompletion) && (string) ($explicitCompletion['status'] ?? '') === 'completed'
                    && is_string($explicitCompletion['final_answer'] ?? null)
                    && (string) $explicitCompletion['final_answer'] !== '') {
                    $this->taskCompleteTurn($responseId, $logicalResponseId,
                        (string) $explicitCompletion['final_answer'],
                        (array) ($this->chatGptTurns[$responseId]['artifact_observed_candidates'] ?? []),
                        (string) $rawProjection['markdown']);
                    return;
                }
                if ($activeCompact) {
                    // Native Retry or a worker reconnect can lose the failed stream's
                    // private observer state. A drained saved source and its exact stopped
                    // DOM answer are stronger evidence; they start only the ordinary
                    // one-shot summary handoff, never fabricate or complete a checkpoint.
                    $completedSource = ChatGptTransport::completedSourceForHandoff($explicitCompletion, $snapshot);
                    if ($completedSource !== null) {
                        $this->beginRetainedCompactionHandoff($responseId, $logicalResponseId, $completedSource);
                        return;
                    }
                }
            }
            if (in_array($snapshot['error']['code'] ?? '', ['CHATGPT_NETWORK_ERROR','CHATGPT_MESSAGE_STREAM_ERROR'], true)) {
                // Deliver an already registered native batch above before considering recovery.
                // A stream alert can remain visible while the same page continues generation.
                // Keep that page and its tools alive; the alert alone cannot authorize replay.
                if (!empty($snapshot['generation_running'])) {
                    $this->chatGptTurns[$responseId]['stopped_stream_observation'] = null;
                    $this->scheduleTaskPoll($responseId, 0.5);
                    return;
                }
                if (!$activeCompact && $this->continueStoppedTaskStream($responseId, $snapshot)) return;
                if (($snapshot['error']['code'] ?? '') === 'CHATGPT_NETWORK_ERROR'
                    && $this->retryTaskNetworkError($responseId, $snapshot)) return;
                // The fresh-page path rotates its token and includes exact confirmed receipts;
                // it cannot regenerate an earlier host mutation with a new model call key.
                $this->recoverFailedMessageStream($responseId, true,
                    (string)$snapshot['error']['code']);
                return;
            }

            if (!$aligned['consistent']) {
                // A transient remount must not fail a live task. Stop publishing text
                // until it settles; registered tools above remain deliverable normally.
                if ($aligned['terminal_error']) {
                    $this->taskFailTurn($responseId, 'response_changed_after_stream',
                        'ChatGPT 修改了已经流式提交的文本，已拒绝伪造一致结果。', true);
                    return;
                }
                $this->scheduleTaskPoll($responseId, 0.10);
                return;
            }

            // A tool-capable answer is provisional commentary. Its last plain paragraph
            // can be visible long before a following block or host call exists; preview it
            // without committing that mutable tail to the strict final-answer ledger.
            $draftMarkdown = $toolCapable && isset($aligned['draft_markdown'])
                ? (string) WebModelPage::markdownAfterToolBoundary($aligned['draft_markdown'], $prefix)['markdown']
                : $roundMarkdown;
            $observed = $this->chatGptTurns[$responseId]['observed_markdown'];
            $stable = WebModelPage::stableMarkdownPrefix(
                is_string($observed) ? $observed : null,
                (bool) $this->chatGptTurns[$responseId]['observed_content'],
                $draftMarkdown,
                $contentPresent && $draftMarkdown !== ''
            );
            // Completion UI and file cards can settle after the stop button disappears.
            // Let the completion transaction append that last suffix after its settle window.
            // Tool-capable text is provisional commentary. A required future host call must
            // not delay visible progress; final-answer and completion guards remain separate.
            $streamCompletion = $toolCapable ? ToolBroker::completionForResponse($logicalResponseId) : null;
            $canStream = $toolCapable ? ChatGptTransport::canStreamDraft($streamCompletion)
                : ChatGptTransport::canStreamAnswer($streamCompletion);
            // Both fresh summaries and retained handoffs are atomic checkpoint output.
            // In-progress Markdown can change as lists/tables finish rendering; none of it
            // has been streamed to Codex and it must not become an immutable text prefix.
            if (($logicalJob['operation_kind'] ?? 'response') === 'response'
                && $canStream && $stable !== null && !empty($snapshot['generation_running'])) {
                if (!empty($logicalJob['checkpoint_marker'])) {
                    $stable = \app\gateway\model\LunaCheckpoint::visible($stable, $logicalJob['checkpoint_marker']);
                }
                if (!str_starts_with($stable, $emitted)) {
                    // A partial DOM remount can temporarily shorten the stable prefix. Wait for
                    // a full observation; the completion fence still verifies the final text.
                    $this->chatGptTurns[$responseId]['observed_markdown'] = $draftMarkdown;
                    $this->chatGptTurns[$responseId]['observed_content'] = $contentPresent;
                    $this->scheduleTaskPoll($responseId, 0.10);
                    return;
                }
                $delta = substr($stable, strlen($emitted));
                if ($delta !== '') {
                    try {
                        if ($toolCapable) ResponseJob::appendDraftText($logicalResponseId, $delta);
                        else ResponseJob::appendText($logicalResponseId, $delta);
                    }
                    catch (\Throwable) {
                        $this->taskFailTurn($responseId, 'CHATGPT_RESPONSE_SAVE_FAILED', 'Responses 增量无法保存。', true);
                        return;
                    }
                    $this->chatGptTurns[$responseId][$toolCapable ? 'draft_emitted' : 'emitted'] = $stable;
                }
            }
            $this->chatGptTurns[$responseId]['observed_markdown'] = $draftMarkdown;
            $this->chatGptTurns[$responseId]['observed_content'] = $contentPresent && $draftMarkdown !== '';

            $hasArtifacts = $observedArtifacts !== [];
            $postToolBaseline = $this->chatGptTurns[$responseId]['post_tool_answer_baseline'] ?? null;
            $sourceTransportSettled = false;
            $sourceNewPostSettled = false;
            $deliveredAt = null;
            $deliveredAtUs = null;
            $deliverySignature = '';
            if ($activeCompact || ($toolCapable && is_string($postToolBaseline))) {
                $sourceId = (string)($logicalJob['previous_response_id'] ?? '');
                try {
                    if ($activeCompact) {
                        $delivery = $sourceId !== '' ? ToolBroker::allResultsDeliveryForResponse($sourceId) : null;
                    } else {
                        $cachedDelivery = $this->chatGptTurns[$responseId]['empty_source_delivery'] ?? null;
                        if (ChatGptTransport::sourceDeliveryLookupRequired($cachedDelivery, $sourceId, $now)) {
                            $cachedDelivery = ['source_id' => $sourceId, 'checked_at' => $now,
                                'delivery' => $sourceId !== '' ? ToolBroker::allResultsDeliveryForResponse($sourceId) : null];
                            $this->chatGptTurns[$responseId]['empty_source_delivery'] = $cachedDelivery;
                        }
                        $delivery = $cachedDelivery['delivery'] ?? null;
                    }
                } catch (GatewayError $deliveryError) {
                    $this->taskFailTurn($responseId, $deliveryError->errorCode, $deliveryError->getMessage(), true);
                    return;
                }
                $session = $this->taskSessions[(string)$turn['session_id']] ?? [];
                // A physical finish is evidence only when that request was seen
                // running after the last host receipt, or a new request began after
                // it. An older stream can finish just after delivery while Web has
                // not yet resumed from the tool result.
                $deliveredAt = is_array($delivery) ? (int)$delivery['latest_at'] : null;
                $deliveredAtUs = is_array($delivery) && is_int($delivery['latest_at_us'])
                    ? $delivery['latest_at_us'] : null;
                $deliverySignature = is_array($delivery)
                    ? $sourceId . ':' . ($delivery['latest_at_us'] ?? $delivery['latest_at']) . ':' . $delivery['count'] : '';
                if ($deliverySignature !== ''
                    && ($this->chatGptTurns[$responseId]['source_receipt_signature'] ?? '') !== $deliverySignature) {
                    $this->chatGptTurns[$responseId]['source_receipt_signature'] = $deliverySignature;
                    $this->chatGptTurns[$responseId]['source_receipt_observed_at'] = microtime(true);
                    $this->chatGptTurns[$responseId]['source_receipt_request_id'] =
                        (string)($session['submission_request_id'] ?? '');
                    $this->chatGptTurns[$responseId]['source_receipt_request_running'] =
                        !empty($session['submission_request_id']) && empty($session['submission_finished']);
                }
                $requestId = (string)($session['submission_request_id'] ?? '');
                $observedRequestId = (string)($this->chatGptTurns[$responseId]['source_receipt_request_id'] ?? '');
                $sourceTransportSettled = $deliveredAt !== null
                    && self::sourceStreamSettledAfterReceipt($session, $deliveredAtUs,
                        $observedRequestId,
                        !empty($this->chatGptTurns[$responseId]['source_receipt_request_running']));
                $sourceNewPostSettled = $sourceTransportSettled && $deliveredAtUs !== null
                    && (float)($session['submission_request_started_at'] ?? 0.0) > $deliveredAtUs / 1000000;
            }
            $retriedSourceFinal = $activeCompact && $this->observeCompactNetworkRetryGeneration(
                $responseId, $snapshot, (string)($logicalJob['previous_response_id'] ?? ''),
                (string)($deliverySignature ?? ''));
            if ($retriedSourceFinal) {
                // Retry can replace the assistant with the same final text. The old tool
                // boundary must not trim that verified new generation to an empty answer.
                $roundMarkdown = $markdown;
                $this->chatGptTurns[$responseId]['observed_markdown'] = $roundMarkdown;
                $this->chatGptTurns[$responseId]['observed_content'] = $roundMarkdown !== '';
            }
            $emptySession = $this->taskSessions[(string) $turn['session_id']] ?? [];
            $stoppedCompactSource = $activeCompact && $toolCapable
                && $deliveredAt !== null && $deliverySignature !== ''
                && ((!empty($emptySession['submission_finished']) && !empty($emptySession['submission_failed']))
                    || empty($emptySession['submission_request_id']));
            if (!$activeCompact || $stoppedCompactSource) {
                $emptyTransportSettled = !empty($emptySession['submission_request_id'])
                    && !empty($emptySession['submission_finished']) && empty($emptySession['submission_failed'])
                    && (!is_string($postToolBaseline) || $sourceTransportSettled);
                // A tool-only answer may return to Send without a final body or Copy action,
                // while its private stream observer still lacks a post-receipt finish event.
                // Exact delivered source receipts plus a drained MCP fence can settle this
                // empty ordinary terminal for bounded recovery. An active checkpoint begins
                // its separate summary-only handoff; it does not complete the user's task.
                $emptyUiReceiptsSettled = $toolCapable && $deliveredAt !== null
                    && $deliverySignature !== '' && ($snapshot['send_button_visible'] ?? null) === true
                    && ($snapshot['generation_running'] ?? null) === false;
                if ($this->observeEmptyResponseSettlement($responseId, $logicalResponseId, $snapshot, [
                    'response_id' => $logicalResponseId,
                    'session_id' => (string) $turn['session_id'],
                    'document_nonce' => (string) ($turn['document_nonce'] ?? ''),
                    'assistant_id' => $boundAssistantId,
                    // Reattachment loses private HTTP IDs. The exact saved source round,
                    // document and delivered receipt signature still bind a new idle witness.
                    'request_id' => !empty($emptySession['submission_request_id'])
                        ? (string) $emptySession['submission_request_id']
                        : (string) (($activeCompact || $emptyUiReceiptsSettled) ? $logicalJob['previous_response_id'] : ''),
                    'request_finished_at' => $emptySession['submission_finished_at'] ?? null,
                    'source_receipt_signature' => $this->chatGptTurns[$responseId]['source_receipt_signature'] ?? '',
                    'transport_settled' => $emptyTransportSettled,
                    'transport_failed' => !empty($emptySession['submission_failed'])
                        && ($emptySession['submission_canceled'] ?? null) !== true,
                    'ui_receipts_settled' => $emptyUiReceiptsSettled,
                    'round_markdown' => $roundMarkdown,
                    'has_artifacts' => $hasArtifacts,
                ], $now, $stoppedCompactSource)) return;
            } else {
                $this->chatGptTurns[$responseId]['empty_response_health'] = null;
            }
            $freshPostToolText = $roundMarkdown !== ''
                && (!$activeCompact
                    ? (!is_string($postToolBaseline) || $markdown !== $postToolBaseline)
                    : ($retriedSourceFinal || ($deliveredAt !== null && is_string($postToolBaseline)
                        && $markdown !== $postToolBaseline)));
            $sourceToolOnlyStable = false;
            if ($activeCompact && $sourceNewPostSettled && $roundMarkdown === ''
                && empty($snapshot['generation_running'])) {
                $candidate = (string)($session['submission_request_id'] ?? '') . ':' . hash('sha256', $markdown);
                if (($this->chatGptTurns[$responseId]['source_tool_only_signature'] ?? null) !== $candidate) {
                    $this->chatGptTurns[$responseId]['source_tool_only_signature'] = $candidate;
                    $this->chatGptTurns[$responseId]['source_tool_only_since'] = $now;
                } else {
                    $sourceToolOnlyStable = $now - (float)($this->chatGptTurns[$responseId]['source_tool_only_since'] ?? $now)
                        >= self::COMPLETION_SETTLE_SECONDS;
                }
            } else {
                $this->chatGptTurns[$responseId]['source_tool_only_signature'] = null;
                $this->chatGptTurns[$responseId]['source_tool_only_since'] = 0.0;
            }
            $terminalSession = $this->taskSessions[(string) $turn['session_id']] ?? [];
            // An early-ended original answer stream can coexist with continuing backend tools.
            // Observe a fresh same-page generation after its latest exact receipt rather than
            // wait forever for the old stream to finish. The normal stable-text and drained
            // transactional MCP fence below remain responsible for actual completion.
            $uiSettlement = ChatGptTransport::postReceiptUiSettlement(
                $this->chatGptTurns[$responseId]['post_receipt_ui_observation'] ?? null, $snapshot, [
                    'response_id'=>$logicalResponseId, 'session_id'=>(string)$turn['session_id'],
                    'document_nonce'=>(string)$turn['document_nonce'],
                    'owner_incarnation'=>(string)$turn['owner_incarnation'],
                    'fencing_version'=>(int)$turn['fencing_version'],
                    'assistant_id'=>(string)($this->chatGptTurns[$responseId]['bound_assistant_id'] ?? ''),
                    'request_id'=>(string)($terminalSession['submission_request_id'] ?? ''),
                    'receipt_signature'=>$deliverySignature, 'receipt_at_us'=>$deliveredAtUs,
                    'post_tool_baseline'=>$postToolBaseline,
                    'source_transport_unsettled'=>$toolCapable && !empty($terminalSession['submission_finished'])
                        && !$sourceTransportSettled,
                ], $now);
            $this->chatGptTurns[$responseId]['post_receipt_ui_observation'] = $uiSettlement['observation'];
            if ($activeCompact && $toolCapable && !empty($snapshot['generation_running']) && empty($snapshot['error'])) {
                // Keep all post-receipt/Retry evidence before Stop attempts. If its
                // message witness cannot be proved, ordinary settlement still works.
                $this->chatGptTurns[$responseId]['compaction_phase'] = 'stopping_source';
                $this->stopCompactionSource($responseId, $logicalResponseId);
                return;
            }
            $answerTransportSettled = !empty($terminalSession['submission_request_id'])
                && !empty($terminalSession['submission_finished']) && empty($terminalSession['submission_failed'])
                && (!is_string($postToolBaseline) || $sourceTransportSettled);
            $rawComplete = empty($snapshot['generation_running']) && (
                ($activeCompact && $sourceTransportSettled
                    && ((is_array($streamCompletion) && ($streamCompletion['status'] ?? null) === 'completed')
                        || $sourceToolOnlyStable))
                || (ChatGptTransport::hasTerminalAnswer($snapshot, $answerTransportSettled || $uiSettlement['settled'])
                    && (!$activeCompact || $freshPostToolText) && ($rawMarkdown !== '' || $hasArtifacts))
            );
            if (!$activeCompact && $toolCapable && is_string($postToolBaseline) && $markdown === $postToolBaseline) {
                $this->chatGptTurns[$responseId]['tool_completion_signature'] = null;
                $this->chatGptTurns[$responseId]['tool_completion_revision'] = null;
                $this->chatGptTurns[$responseId]['tool_completion_since'] = 0.0;
                $this->chatGptTurns[$responseId]['completion_fence_revision'] = null;
                $this->chatGptTurns[$responseId]['completion_fence_signature'] = null;
                if ($rawComplete) {
                    $missingSince = (float) ($this->chatGptTurns[$responseId]['post_tool_answer_missing_since'] ?? 0.0);
                    if ($missingSince <= 0.0) {
                        $this->chatGptTurns[$responseId]['post_tool_answer_missing_since'] = $now;
                    } elseif ($now - $missingSince >= self::TOOL_POST_ANSWER_GRACE_SECONDS) {
                        $this->taskFailTurn($responseId, 'CHATGPT_POST_TOOL_ANSWER_MISSING', 'ChatGPT 工具结果已经返回，但页面没有产生工具后的新回答。', true);
                        return;
                    }
                } else {
                    $this->chatGptTurns[$responseId]['post_tool_answer_missing_since'] = 0.0;
                }
                $this->scheduleTaskPoll($responseId, 0.10);
                return;
            }
            if ($toolCapable && is_string($postToolBaseline)) {
                $this->chatGptTurns[$responseId]['post_tool_answer_missing_since'] = 0.0;
            }

            $complete = $rawComplete && ($roundMarkdown !== '' || $hasArtifacts || $activeCompact);
            if ($complete) {
                $completion = ToolBroker::completionForResponse($logicalResponseId);
                if ($toolCapable && is_array($completion) && (string) $completion['status'] !== 'completed') {
                    if ((string) $completion['status'] === 'awaiting_start'
                        && !empty($this->chatGptTurns[$responseId]['security_challenge_active'])) {
                        $path = (string) ($this->chatGptTurns[$responseId]['security_challenge_path'] ?? '/backend-api/');
                        $this->taskFailTurn(
                            $responseId,
                            'CHATGPT_SECURITY_CHECK_BLOCKED',
                            'ChatGPT 安全检查仍在阻断后端请求，Connector 尚未启动。受影响路径：' . mb_substr($path, 0, 300)
                        );
                        return;
                    }
                    $completionSignature = hash('sha256', $roundMarkdown . "\0" . (string) ($snapshot['response_html'] ?? ''));
                    $activityRevision = (int) ($completion['activity_revision'] ?? -1);
                    $candidateSignature = $this->chatGptTurns[$responseId]['tool_completion_signature'] ?? null;
                    $candidateRevision = $this->chatGptTurns[$responseId]['tool_completion_revision'] ?? null;
                    if (!is_string($candidateSignature)
                        || !is_int($candidateRevision)
                        || !hash_equals($candidateSignature, $completionSignature)
                        || $candidateRevision !== $activityRevision) {
                        $this->chatGptTurns[$responseId]['tool_completion_signature'] = $completionSignature;
                        $this->chatGptTurns[$responseId]['tool_completion_revision'] = $activityRevision;
                        $this->chatGptTurns[$responseId]['tool_completion_since'] = $now;
                        $this->chatGptTurns[$responseId]['completion_fence_revision'] = null;
                        $this->chatGptTurns[$responseId]['completion_fence_signature'] = null;
                        $this->scheduleTaskPoll($responseId, 0.10);
                        return;
                    }
                    if ($now - (float) ($this->chatGptTurns[$responseId]['tool_completion_since'] ?? 0.0) < self::COMPLETION_SETTLE_SECONDS) {
                        $this->scheduleTaskPoll($responseId, 0.10);
                        return;
                    }
                    if (in_array((string) $completion['status'], ['awaiting_start', 'running'], true)
                        && (!empty($completion['requires_tool_call']) || !empty($completion['context_read_pending']))
                        && (int) ($completion['invocation_count'] ?? 0) === 0) {
                        try { TaskLedger::observeNoToolFinal($logicalResponseId, $roundMarkdown); } catch (\Throwable) {}
                        $retryState = $this->retryConnectorNoStart($responseId, $logicalResponseId);
                        if (in_array($retryState, ['retrying', 'waiting', 'failed'], true)) return;
                        $this->taskFailTurn($responseId, !empty($completion['context_read_pending']) ? 'CHATGPT_CONTEXT_READ_REQUIRED' : 'CHATGPT_TOOL_NOT_STARTED',
                            !empty($completion['context_read_pending']) ? '网页模型未核对归档原文，自动重试后仍未完成读取；原始上下文已保留。'
                                : '网页模型结束了回答，但没有执行任务要求的本机操作；自动重试后仍未调用工具。');
                        return;
                    }
                    // A settled Web final is the end of this Web assistant response.
                    // Never manufacture another user message to keep a Codex turn alive;
                    // all genuine tool calls and results above already share this response.
                    $waitFailure = !$activeCompact
                        ? ToolBroker::settledWaitTransportFailureForResponse($logicalResponseId) : null;
                    if ($waitFailure !== null) {
                        // All native effects are known, but a typed failed RPC never
                        // delivered their last result. Preserve the Web model's final
                        // as visible commentary and end the broken transport explicitly;
                        // do not fabricate a delivery receipt or successful completion.
                        if (ToolBroker::failSettledWaitTransportForResponse($logicalResponseId, $roundMarkdown,
                            (string)$turn['owner_incarnation'], (int)$turn['fencing_version'])) {
                            $this->taskFailTurn($responseId, 'CHATGPT_TOOL_RESULT_TRANSPORT_FAILED',
                                '工具等待连接提前结束，真实结果已保留。', false);
                        } else {
                            $this->scheduleTaskPoll($responseId, 0.10);
                        }
                        return;
                    }
                    try {
                        $fenceRevision = $this->chatGptTurns[$responseId]['completion_fence_revision'] ?? null;
                        $fenceSignature = $this->chatGptTurns[$responseId]['completion_fence_signature'] ?? null;
                        if (!is_int($fenceRevision) || !is_string($fenceSignature) || !hash_equals($fenceSignature, $completionSignature)) {
                            $revision = ToolBroker::beginCompletionFenceForResponse($logicalResponseId);
                            if ($revision === null) {
                                $this->chatGptTurns[$responseId]['completion_fence_revision'] = null;
                                $this->chatGptTurns[$responseId]['completion_fence_signature'] = null;
                                $this->scheduleTaskPoll($responseId, 0.05);
                                return;
                            }
                            $this->chatGptTurns[$responseId]['completion_fence_revision'] = $revision;
                            $this->chatGptTurns[$responseId]['completion_fence_signature'] = $completionSignature;
                            $this->scheduleTaskPoll($responseId, 0.05);
                            return;
                        }
                        if (!ToolBroker::commitCompletionFenceForResponse($logicalResponseId, $fenceRevision, $roundMarkdown)) {
                            $this->chatGptTurns[$responseId]['completion_fence_revision'] = null;
                            $this->chatGptTurns[$responseId]['completion_fence_signature'] = null;
                            $this->chatGptTurns[$responseId]['tool_completion_signature'] = null;
                            $this->chatGptTurns[$responseId]['tool_completion_revision'] = null;
                            $this->chatGptTurns[$responseId]['tool_completion_since'] = 0.0;
                            $this->scheduleTaskPoll($responseId, 0.05);
                            return;
                        }
                        $completion = ToolBroker::completionForResponse($logicalResponseId);
                    } catch (GatewayError $error) {
                        $this->taskFailTurn($responseId, $error->errorCode, $error->getMessage(), true);
                        return;
                    }
                }
                $finalText = is_array($completion) && is_string($completion['final_answer'] ?? null)
                    ? (string) $completion['final_answer']
                    : $roundMarkdown;
                // An explicit codex_turn_complete result is the authoritative Codex answer.
                // ChatGPT may render a short acknowledgement after that MCP call; do not
                // wait for that prose to become byte-identical to the tool's final_answer.
                if ($toolCapable && $roundMarkdown !== $finalText
                    && (!is_array($completion) || (string) $completion['status'] !== 'completed')) {
                    $this->chatGptTurns[$responseId]['completion_fence_revision'] = null;
                    $this->chatGptTurns[$responseId]['completion_fence_signature'] = null;
                    $this->chatGptTurns[$responseId]['tool_completion_signature'] = null;
                    $this->chatGptTurns[$responseId]['tool_completion_revision'] = null;
                    $this->chatGptTurns[$responseId]['tool_completion_since'] = 0.0;
                    $this->chatGptTurns[$responseId]['settle_text'] = null;
                    $this->chatGptTurns[$responseId]['settle_at'] = 0.0;
                    $this->scheduleTaskPoll($responseId);
                    return;
                }
                if ($toolCapable && is_array($completion) && (string) $completion['status'] === 'completed') {
                    if ($activeCompact) $this->beginRetainedCompactionHandoff($responseId, $logicalResponseId, $finalText);
                    else $this->taskCompleteTurn($responseId, $logicalResponseId, $finalText,
                        (array) ($this->chatGptTurns[$responseId]['artifact_observed_candidates'] ?? []), (string) $rawProjection['markdown']);
                    return;
                }
                if ($this->chatGptTurns[$responseId]['settle_text'] !== $finalText) {
                    $this->chatGptTurns[$responseId]['settle_text'] = $finalText;
                    $this->chatGptTurns[$responseId]['settle_at'] = $now;
                } elseif ($now - (float) $this->chatGptTurns[$responseId]['settle_at'] >= self::COMPLETION_SETTLE_SECONDS) {
                    if ($activeCompact) $this->beginRetainedCompactionHandoff($responseId, $logicalResponseId, $finalText);
                    else $this->taskCompleteTurn($responseId, $logicalResponseId, $finalText,
                        (array) ($this->chatGptTurns[$responseId]['artifact_observed_candidates'] ?? []), (string) $rawProjection['markdown']);
                    return;
                }
            } else {
                $this->chatGptTurns[$responseId]['completion_fence_revision'] = null;
                $this->chatGptTurns[$responseId]['completion_fence_signature'] = null;
                $this->chatGptTurns[$responseId]['tool_completion_signature'] = null;
                $this->chatGptTurns[$responseId]['tool_completion_revision'] = null;
                $this->chatGptTurns[$responseId]['tool_completion_since'] = 0.0;
                $this->chatGptTurns[$responseId]['settle_text'] = null;
                $this->chatGptTurns[$responseId]['settle_at'] = 0.0;
            }
            $this->scheduleTaskPoll($responseId);
        }, 3.0);
    }

    /**
     * Align only the bound ordinary answer; keep audit text and native control paths unchanged.
     *
     * State belongs to one document, assistant and logical Responses round. A tool boundary
     * may replace its provisional browser text, so recovery requires exact block evidence.
     * Stopped tails are previews until the existing completion fence accepts them, not new
     * immutable commits. Incomplete recovered prefixes keep the legacy strict-prefix path.
     * This pure rule performs no I/O and never changes model selection or sends a message.
     *
     * @param array $turn Observer state, updated only for a correctly bound ordinary answer.
     * @param array $snapshot Fresh scoped adapter observation; its raw Markdown is not modified.
     * @param float $now Observation time in seconds.
     * @return array{markdown:string,consistent:bool,terminal_error:bool,draft_markdown?:string}
     */
    private static function alignTaskMarkdownObservation(array &$turn, array $snapshot, float $now): array
    {
        $raw = (string) ($snapshot['markdown'] ?? '');
        $legacy = ['markdown' => $raw, 'consistent' => true, 'terminal_error' => false];
        $assistant = (string) ($turn['bound_assistant_id'] ?? '');
        if (($turn['operation_kind'] ?? 'response') !== 'response'
            || $assistant === '' || ($snapshot['response_id'] ?? '') !== $assistant
            || !array_key_exists('markdown_segments', $snapshot)) return $legacy;
        $scope = [(string) ($turn['document_nonce'] ?? ''), $assistant,
            (string) ($turn['logical_response_id'] ?? '')];
        $saved = $turn['markdown_buffer'] ?? null;
        $blocks = (array) $snapshot['markdown_segments'];
        $prefix = (string) ($turn['round_prefix'] ?? '');
        $projection = WebModelPage::markdownAfterToolBoundary($raw, $prefix);
        // Provisional prose can end inside a live block. It is not evidence that this
        // block was irreversibly committed, including after a browser-worker restart.
        $emitted = (string) (!empty($turn['tool_capable'])
            ? ($turn['draft_emitted'] ?? '') : ($turn['emitted'] ?? ''));
        if (!is_array($saved) || ($saved['scope'] ?? null) !== $scope
            || ($prefix !== '' && $projection['boundary_replaced'] && $emitted === ''
                && !empty($saved['restored_boundary']))) {
            // Replacement of a prior tool round is allowed only before this round has
            // published text. A partial already-published paragraph cannot be guessed.
            $durable = ($projection['boundary_replaced'] ? '' : $prefix) . $emitted;
            $restored = ChatGptMarkdownBuffer::restore($durable, $blocks, empty($turn['tool_capable']) || $emitted === '');
            if ($restored === null && !empty($turn['tool_capable']) && $emitted !== '') {
                $restored = ChatGptMarkdownBuffer::restore($projection['boundary_replaced'] ? '' : $prefix, $blocks);
            }
            if ($restored === null) return $legacy;
            $saved = ['scope' => $scope, 'state' => $restored,
                'restored_boundary' => $prefix !== '' && !$projection['boundary_replaced'],
                'error_signature' => null, 'error_since' => 0.0];
        }
        $running = !empty($snapshot['generation_running']);
        $result = ChatGptMarkdownBuffer::align($saved['state'], $blocks, $raw, $running, $now);
        if (!$result['consistent']) {
            // Tool-capable drafts are commentary, not an irrevocable final prefix.
            // Preserve the established stopped-draft replacement behavior; the
            // ordinary native completion fence still decides when it can finish.
            if (!empty($turn['tool_capable']) && !$running) return $legacy;
            $signature = hash('sha256', json_encode([$result['error'], $raw], JSON_THROW_ON_ERROR));
            if ($running || ($saved['error_signature'] ?? null) !== $signature) {
                $saved['error_signature'] = $signature;
                $saved['error_since'] = $now;
            }
            $turn['markdown_buffer'] = $saved;
            return ['markdown' => $result['markdown'], 'consistent' => false,
                'terminal_error' => !$running && $now - $saved['error_since'] >= self::COMPLETION_SETTLE_SECONDS];
        }
        if ($running) $saved['state'] = $result['state'];
        $saved['error_signature'] = null;
        $saved['error_since'] = 0.0;
        $turn['markdown_buffer'] = $saved;
        $aligned = ['markdown' => $result['markdown'], 'consistent' => true, 'terminal_error' => false];
        $proseTags = ['p', 'root', 'inline', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6'];
        $proseTail = true;
        foreach ((array) ($saved['state']['latest'] ?? []) as $block) {
            if (!in_array($block['tag'] ?? '', $proseTags, true)) { $proseTail = false; break; }
        }
        foreach ($blocks as $block) {
            if (!empty($block['pending_links'])) { $proseTail = false; break; }
        }
        $tail = $blocks === [] ? null : $blocks[array_key_last($blocks)];
        if (!empty($turn['tool_capable']) && $running && $proseTail && is_array($tail)
            && in_array($tail['tag'] ?? '', $proseTags, true) && ($tail['markdown'] ?? '') !== ''
            && str_ends_with($raw, $tail['markdown'])) {
            // Preview on a copy: preserve committed/virtualized blocks and Markdown
            // separators, while the real ledger continues to buffer unfinished tails.
            // Running adapter text must contain the whole tail, so unresolved citation,
            // ordinal, code, list and table rendering cannot bypass its existing guards.
            $preview = ChatGptMarkdownBuffer::align($saved['state'], $blocks, $raw, false, $now);
            if ($preview['consistent']) $aligned['draft_markdown'] = $preview['markdown'];
        }
        return $aligned;
    }

    /**
     * Resolve only a continuously observed empty terminal after the owned stream and tools settled.
     * A fresh MCP fence is read on every candidate poll and again before resolving the source;
     * it never publishes an empty user final. Confirmed failed streams use bounded recovery;
     * a tool-only empty terminal first uses the existing same-page continuation. Ordinary
     * empty answers without confirmed host work still fail honestly. A reserved checkpoint
     * with exact delivered source receipts starts its separate control-only summary message.
     * @param string $responseId Physical runtime observer key.
     * @param string $logicalResponseId Current native Responses round on the same owned page.
     * @param array $snapshot Fresh, bound response DOM observation.
     * @param array $evidence Current transport/ownership metadata for the pure health rule.
     * @param float $now Observation time in seconds.
     * @param bool $activeCompact Whether this is the stopped failed or reattached checkpoint source.
     * @return bool True after terminal failure or transition into the one-shot summary handoff.
     */
    private function observeEmptyResponseSettlement(string $responseId, string $logicalResponseId,
        array $snapshot, array $evidence, float $now, bool $activeCompact = false): bool
    {
        $evidence['mcp_revision'] = 0;
        $previousHealth = $this->chatGptTurns[$responseId]['empty_response_health'] ?? null;
        if (ChatGptTransport::emptyResponseHealth(null, $snapshot, $evidence, $now)['candidate'] === null) {
            if (is_array($previousHealth)) $this->recordEmptyResponseObservation($responseId,
                'empty_cleared', $snapshot, $evidence, $previousHealth, $now);
            $this->chatGptTurns[$responseId]['empty_response_health'] = null;
            return false;
        }
        $toolCapable = !empty($this->chatGptTurns[$responseId]['tool_capable']);
        if ($toolCapable) {
            try { $evidence['mcp_revision'] = ToolBroker::beginCompletionFenceForResponse($logicalResponseId); }
            catch (GatewayError) { $evidence['mcp_revision'] = null; }
        }
        $health = ChatGptTransport::emptyResponseHealth(
            $this->chatGptTurns[$responseId]['empty_response_health'] ?? null, $snapshot, $evidence, $now
        );
        $this->chatGptTurns[$responseId]['empty_response_health'] = $health['candidate'];
        if ($health['candidate'] === null && is_array($previousHealth)) {
            $this->recordEmptyResponseObservation($responseId, 'empty_cleared', $snapshot, $evidence, $previousHealth, $now);
        }
        if (is_array($health['candidate']) && (!is_array($previousHealth)
            || ($previousHealth['signature'] ?? null) !== $health['candidate']['signature'])) {
            $this->recordEmptyResponseObservation($responseId, 'empty_candidate', $snapshot, $evidence, $health['candidate'], $now);
        }
        if ($health['error_code'] === null) return false;
        if ($toolCapable) {
            try { $freshRevision = ToolBroker::beginCompletionFenceForResponse($logicalResponseId); }
            catch (GatewayError) { $freshRevision = null; }
            if ($freshRevision !== $evidence['mcp_revision']) {
                $this->chatGptTurns[$responseId]['empty_response_health'] = null;
                return false;
            }
        }
        $this->recordEmptyResponseObservation($responseId, 'empty_settlement', $snapshot, $evidence, $health['candidate'], $now);
        if ($activeCompact) {
            // Settle only this source's tool channel through its existing atomic fence.
            // The native compact stays running until a separate real summary receipt;
            // no empty user answer or synthetic checkpoint is emitted here.
            if (!ToolBroker::commitCompletionFenceForResponse($logicalResponseId,
                (int) $evidence['mcp_revision'], '')) {
                $this->chatGptTurns[$responseId]['empty_response_health'] = null;
                return false;
            }
            $this->beginRetainedCompactionHandoff($responseId, $logicalResponseId, '');
            return true;
        }
        if ($health['error_code'] === 'CHATGPT_EMPTY_RESPONSE' && $toolCapable
            && ($evidence['ui_receipts_settled'] ?? null) === true
            && $this->continueStoppedTaskStream($responseId, $snapshot, 'CHATGPT_EMPTY_RESPONSE')) {
            return true;
        }
        if ($health['error_code'] === 'CHATGPT_MESSAGE_STREAM_ERROR') {
            // The original HTTP stream failed while MCP tools continued. A drained empty
            // Send state cannot change that into a successful/empty model final. Reuse the
            // existing bounded recovery, which verifies and carries all confirmed receipts.
            $this->recordEmptyResponseObservation($responseId, 'empty_stream_recovery', $snapshot, $evidence, $health['candidate'], $now);
            if (!$this->retryTaskNetworkError($responseId, $snapshot)) {
                $this->recoverFailedMessageStream($responseId, true, 'CHATGPT_MESSAGE_STREAM_ERROR');
            }
            return true;
        }
        $this->taskFailTurn($responseId, $health['error_code'],
            'ChatGPT 已停止生成并持续显示空回答终态，当前回合没有可确认的正文或产物；已停止等待，不会自动重发。', true);
        return true;
    }

    /** Log one empty-state transition, with transport/UI/fence facts only and no conversation or tool contents. */
    private function recordEmptyResponseObservation(string $responseId, string $state, array $snapshot,
        array $evidence, ?array $candidate, float $now): void
    {
        $turn = $this->chatGptTurns[$responseId] ?? [];
        $session = $this->taskSessions[(string) ($turn['session_id'] ?? '')] ?? [];
        $facts = array_intersect_key($snapshot, array_flip(['generation_running', 'response_present',
            'send_button_visible', 'send_button_disabled', 'completion_action_visible', 'content_present',
            'transport_recovery_visible', 'answer_root_count', 'commentary_root_count']));
        $facts += array_intersect_key($evidence, array_flip(['transport_failed', 'transport_settled', 'ui_receipts_settled']));
        $facts += ['request_finished' => !empty($session['submission_finished']),
            'canceled' => ($session['submission_canceled'] ?? null) === true,
            'fence_drained' => is_int($evidence['mcp_revision'] ?? null),
            'mcp_revision' => $evidence['mcp_revision'] ?? null,
            'source_receipt_present' => (string) ($evidence['source_receipt_signature'] ?? '') !== '',
            'markdown_chars' => mb_strlen((string) ($snapshot['markdown'] ?? '')),
            'visible_chars' => mb_strlen((string) ($snapshot['visible_text'] ?? '')),
            'has_artifacts' => !empty($evidence['has_artifacts']),
            'candidate_age_ms' => (int) round(max(0.0, $now - (float) ($candidate['since'] ?? $now)) * 1000),
            'http_status' => $session['submission_http_status'] ?? null,
            'protocol' => $session['submission_protocol'] ?? null,
            'error_code' => $session['submission_error_code'] ?? null,
            'request_fingerprint' => hash('sha256', (string) ($session['submission_request_id'] ?? '')),
            'response_fingerprint' => hash('sha256', (string) ($snapshot['response_html'] ?? ''))];
        $this->recordTaskTransportObservation($responseId, $state, $facts);
    }

    /**
     * Record an owned stopped-thinking terminal before the existing error handling settles it.
     * Pending counts are diagnostic observations only; they never authorize completion or retry.
     * Storage failures cannot replace the page error or change task execution.
     * @param string $responseId Current physical observer mapped to its logical Responses round.
     * @param array $snapshot Current owned response DOM; only flags and lengths are retained.
     * @return void
     */
    private function recordStoppedThinkingObservation(string $responseId, array $snapshot): void
    {
        $turn = $this->chatGptTurns[$responseId] ?? null;
        if (!is_array($turn)) return;
        $session = $this->taskSessions[(string) ($turn['session_id'] ?? '')] ?? [];
        $facts = array_intersect_key($snapshot, array_flip(['generation_running', 'response_present',
            'send_button_visible', 'send_button_disabled', 'completion_action_visible', 'content_present',
            'transport_recovery_visible', 'stopped_thinking_visible', 'answer_root_count', 'commentary_root_count']));
        $facts += ['page_error_code' => 'CHATGPT_STOPPED_THINKING',
            'markdown_chars' => mb_strlen((string) ($snapshot['markdown'] ?? '')),
            'visible_chars' => mb_strlen((string) ($snapshot['visible_text'] ?? '')),
            'request_finished' => $session['submission_finished'] ?? null,
            'canceled' => $session['submission_canceled'] ?? null,
            'http_status' => $session['submission_http_status'] ?? null,
            'protocol' => $session['submission_protocol'] ?? null,
            'error_code' => $session['submission_error_code'] ?? null,
            'network_retry_available' => !empty($snapshot['network_retry']['available']),
            'request_fingerprint' => hash('sha256', (string) ($session['submission_request_id'] ?? ''))];
        try {
            $job = ResponseJob::execution((string) ($turn['logical_response_id'] ?? $responseId));
            $db = SDB(null, 'medoo', 'sqlite');
            $facts['tool_invocations_pending'] = (int) $db->table('gateway_tool_invocations')
                ->where('task_id', $job['task_id'])->where('status', 'IN', ['registered', 'delivered'])->count();
            $facts['tool_receipts_pending'] = (int) $db->table('gateway_tool_invocations')
                ->where('task_id', $job['task_id'])->where('status', 'completed')->where('result_delivered_at', null)->count();
            $facts['mcp_activities_pending'] = empty($job['mcp_binding_id']) ? 0 : (int) $db->table('gateway_mcp_activities')
                ->where('binding_id', $job['mcp_binding_id'])->where('status', 'active')->count();
        } catch (\Throwable) {
            // Missing counters are unknown, not evidence that work has drained.
        }
        $this->recordTaskTransportObservation($responseId, 'stopped_thinking', $facts);
    }

    /** A pre-receipt stream finish is never evidence of the source's post-tool answer. */
    private static function sourceStreamSettledAfterReceipt(array $session, ?int $deliveredAtUs,
        string $observedRequestId, bool $observedRunning): bool
    {
        $requestId = (string)($session['submission_request_id'] ?? '');
        if ($requestId === '' || empty($session['submission_finished'])
            || !empty($session['submission_failed'])) return false;
        if ($observedRunning && $requestId === $observedRequestId) return true;
        return $deliveredAtUs !== null
            && (float)($session['submission_request_started_at'] ?? 0.0) > $deliveredAtUs / 1000000;
    }

    /**
     * Drain accepted host receipts, stop the exact old generation, then request only a checkpoint.
     * This never reports the interrupted partial answer as completion of the human task.
     * After an owned Stop, confirm idle on the fenced document even if historical DOM groups change.
     * A failed source transport uses read-only idle confirmation and exact native checkpoint receipts.
     * @param string $responseId Physical source observer.
     * @param string $logicalResponseId Its currently reserved compact response.
     * @return void Polling retains the durable deadline and never cancels a host invocation.
     */
    private function stopCompactionSource(string $responseId, string $logicalResponseId): void
    {
        if (!$this->compactionStopOwns($responseId, $logicalResponseId)) return;
        $turn = $this->chatGptTurns[$responseId];
        try {
            $closedSource = (!empty($turn['compaction_closed_source']) && !empty($turn['compaction_stop_clicked']))
                || $this->compactionSourceTransportClosed($logicalResponseId,
                    $this->taskSessions[(string)$turn['session_id']] ?? []);
            $this->chatGptTurns[$responseId]['compaction_closed_source'] = $closedSource;
            $revision = $closedSource
                ? ToolBroker::beginStoppedCompactionFenceForResponse($logicalResponseId,
                    (string)$turn['owner_incarnation'], (int)$turn['fencing_version'])
                : ToolBroker::beginCompletionFenceForResponse($logicalResponseId);
            if ($revision === null) {
                $this->chatGptTurns[$responseId]['compaction_stop_idle_since'] = null;
                $this->scheduleTaskPoll($responseId, 0.2);
                return;
            }
            if (($turn['compaction_stop_revision'] ?? null) !== $revision) {
                $this->chatGptTurns[$responseId]['compaction_stop_idle_since'] = null;
                $this->chatGptTurns[$responseId]['compaction_stop_revision'] = $revision;
            }
            $compact = ResponseJob::execution($logicalResponseId);
            $source = ResponseJob::execution((string)$compact['previous_response_id']);
            preg_match_all('/(?<![A-Za-z0-9_-])turn_[A-Za-z0-9_-]{32}(?![A-Za-z0-9_-])/', (string)$source['prompt'], $tokens);
            $witnesses = count(array_unique($tokens[0])) === 1 ? [$tokens[0][0]] : [(string)$source['prompt']];
            if (!empty($source['transport_transaction_id'])) $witnesses[] = (string)$source['transport_transaction_id'];
            $surface = TaskLedger::execution($logicalResponseId)['surface'];
            // Stop already proved the current user. Rerendered history must not block its idle acknowledgement;
            // a running generation still requires that witness inside userStopExpression, even without a click.
            $stopUnconfirmed = empty($turn['compaction_stop_clicked']);
            $expression = WebModelPage::userStopExpression((string)$surface['url'], $witnesses,
                (array)$turn['baseline_users'], $stopUnconfirmed, !$closedSource && $stopUnconfirmed,
                self::acceptedInitialUser($source, $surface));
        } catch (GatewayError $error) {
            if ($error->errorCode === 'STORAGE_BUSY') {
                $this->scheduleTaskPoll($responseId, 1.0);
                return;
            }
            $this->taskFailTurn($responseId, $error->errorCode, $error->getMessage(), true);
            return;
        }
        $this->evaluateTask($responseId, $expression, function ($error, $state) use ($responseId, $logicalResponseId, $revision): void {
            if (!$this->compactionStopOwns($responseId, $logicalResponseId)) return;
            if ($error !== null || !is_array($state) || empty($state['owned'])) {
                $this->chatGptTurns[$responseId]['compaction_stop_idle_since'] = null;
                $this->chatGptTurns[$responseId]['compaction_closed_source'] = false;
                // No owned Stop action occurred. Keep observing the existing page so
                // its ordinary completion/error recovery remains available; never let
                // a missing witness turn into a permanently latched stopping phase.
                if (empty($this->chatGptTurns[$responseId]['compaction_stop_clicked'])) {
                    $this->chatGptTurns[$responseId]['compaction_phase'] = null;
                }
                $this->scheduleTaskPoll($responseId, 0.25);
                return;
            }
            if (!empty($state['stopped'])) $this->chatGptTurns[$responseId]['compaction_stop_clicked'] = true;
            if (!empty($state['generation_running']) || empty($state['editor_ready'])) {
                $this->chatGptTurns[$responseId]['compaction_stop_idle_since'] = null;
                if (!empty($state['generation_running']) && empty($state['stopped'])
                    && !empty($this->chatGptTurns[$responseId]['compaction_closed_source'])) {
                    $this->chatGptTurns[$responseId]['compaction_closed_source'] = false;
                    $this->chatGptTurns[$responseId]['compaction_phase'] = null;
                }
                $this->scheduleTaskPoll($responseId, 0.25);
                return;
            }
            $idle = $this->chatGptTurns[$responseId]['compaction_stop_idle_since'] ?? null;
            if ($idle === null || microtime(true) - $idle < 1.0) {
                $this->chatGptTurns[$responseId]['compaction_stop_idle_since'] = $idle ?? microtime(true);
                $this->scheduleTaskPoll($responseId, 0.25);
                return;
            }
            $turn = $this->chatGptTurns[$responseId];
            $read = WebModelPage::responseExpression((array)$turn['baseline_users'], (array)$turn['baseline_assistants'],
                '', (string)($turn['bound_assistant_id'] ?? ''), self::hasLegacyCitationPrefix($turn));
            $this->evaluateTask($responseId, $read, function ($readError, $snapshot) use ($responseId, $logicalResponseId, $revision): void {
                if (!$this->compactionStopOwns($responseId, $logicalResponseId)) return;
                $closedSource = !empty($this->chatGptTurns[$responseId]['compaction_closed_source']);
                if ($readError !== null || !is_array($snapshot) || !empty($snapshot['generation_running'])
                    || ($closedSource && ($snapshot['send_button_visible'] ?? null) !== true)) {
                    $this->chatGptTurns[$responseId]['compaction_stop_idle_since'] = null;
                    if ($closedSource && !empty($snapshot['generation_running'])) {
                        $this->chatGptTurns[$responseId]['compaction_closed_source'] = false;
                        $this->chatGptTurns[$responseId]['compaction_phase'] = null;
                    }
                    $this->scheduleTaskPoll($responseId, 0.25);
                    return;
                }
                try {
                    $turn = $this->chatGptTurns[$responseId];
                    $completion = ToolBroker::completionForResponse($logicalResponseId);
                    $answer = ($completion['status'] ?? '') === 'completed' ? (string)$completion['final_answer']
                        : (string)($snapshot['markdown'] ?? $snapshot['visible_text'] ?? '');
                    $committed = $closedSource
                        ? ToolBroker::commitStoppedCompactionFenceForResponse($logicalResponseId, $revision, $answer,
                            (string)$turn['owner_incarnation'], (int)$turn['fencing_version'])
                        : ToolBroker::commitCompletionFenceForResponse($logicalResponseId, $revision, $answer);
                    if (!$committed) {
                        $this->chatGptTurns[$responseId]['compaction_stop_idle_since'] = null;
                        $this->scheduleTaskPoll($responseId, 0.2);
                        return;
                    }
                    $this->beginRetainedCompactionHandoff($responseId, $logicalResponseId, $answer);
                } catch (GatewayError $error) {
                    if ($error->errorCode === 'STORAGE_BUSY') {
                        $this->scheduleTaskPoll($responseId, 1.0);
                        return;
                    }
                    $this->taskFailTurn($responseId, $error->errorCode, $error->getMessage(), true);
                }
            });
        });
    }

    /**
     * Preserve a source's observed transport failure across browser-worker reattachment.
     * A live private HTTP observer takes precedence. A missing observer needs a persisted
     * non-cancelled failure from this exact compact, never age, an old task error or a banner.
     * @param string $responseId Exact compact currently owning the fenced source page.
     * @param array $session Current private HTTP observations, absent after worker replacement.
     * @return bool Whether the source receiver is known closed; DOM idle is verified separately.
     */
    private function compactionSourceTransportClosed(string $responseId, array $session): bool
    {
        if (!empty($session['submission_request_id'])) {
            return !empty($session['submission_finished']) && !empty($session['submission_failed'])
                && ($session['submission_canceled'] ?? null) === false;
        }
        $events = SDB(null, 'medoo', 'sqlite')->table('gateway_task_events')->where('response_id', $responseId)
            ->where('event_type', 'browser_response_transport')->where('data_json','LIKE','%stream_failed%')
            ->order([['id','DESC']])->limit(1)->select('data_json');
        foreach (is_array($events) ? $events : [] as $event) {
            $facts = json_decode((string)$event['data_json'], true);
            if (($facts['state'] ?? '') === 'stream_failed' && ($facts['canceled'] ?? null) === false) return true;
        }
        return false;
    }

    /** Fence asynchronous stop callbacks against newer work, page claims and compaction generations. */
    private function compactionStopOwns(string $responseId, string $logicalResponseId): bool
    {
        $turn = $this->chatGptTurns[$responseId] ?? null;
        if (!is_array($turn) || ($turn['compaction_phase'] ?? null) !== 'stopping_source'
            || ($turn['logical_response_id'] ?? $responseId) !== $logicalResponseId) return false;
        $ledger = TaskLedger::execution($logicalResponseId);
        return $ledger['turn']['response_id'] === $logicalResponseId
            && in_array($ledger['turn']['status'], ['submitted', 'running'], true)
            && $ledger['surface']['target_id'] === $turn['target_id']
            && $ledger['surface']['document_nonce'] === $turn['document_nonce']
            && $ledger['surface']['owner_incarnation'] === $turn['owner_incarnation']
            && (int)$ledger['surface']['fencing_version'] === (int)$turn['fencing_version'];
    }

    /** Keep the stopped Web answer as evidence, then request a separate control-only checkpoint. */
    private function beginRetainedCompactionHandoff(string $responseId, string $logicalResponseId,
        string $sourceAnswer): void
    {
        if (!isset($this->chatGptTurns[$responseId])) return;
        $this->chatGptTurns[$responseId]['compaction_source_answer'] = $sourceAnswer;
        $this->chatGptTurns[$responseId]['compaction_repair_used'] = false;
        $this->chatGptTurns[$responseId]['compaction_baseline_since'] = microtime(true);
        $this->chatGptTurns[$responseId]['compaction_phase'] = 'baseline';
        $this->observeRetainedCompactionBaseline($responseId, $logicalResponseId);
    }

    /** Prove the retained page is ready before reserving an unreissuable control token. */
    private function observeRetainedCompactionBaseline(string $responseId, string $logicalResponseId): void
    {
        if (($this->chatGptTurns[$responseId]['compaction_phase'] ?? null) !== 'baseline') return;
        $this->evaluateTask($responseId, WebModelPage::baselineExpression(),
            function ($error, $baseline) use ($responseId, $logicalResponseId): void {
                $turn = $this->chatGptTurns[$responseId] ?? null;
                if (!is_array($turn) || ($turn['compaction_phase'] ?? null) !== 'baseline') return;
                if (is_array($baseline) && (!empty($baseline['url']))
                    && (empty($baseline['temporary_chat'])
                        || !WebModelPage::isTemporaryChatUrl((string)$baseline['url'], self::modelUrl()))) {
                    $this->taskFailTurn($responseId, 'compaction_handoff_page_unavailable',
                        '源回答已结束，但原临时聊天页面已变化；原上下文仍保留。', true);
                    return;
                }
                if ($error !== null || !is_array($baseline) || empty($baseline['editor_ready'])
                    || !empty($baseline['generation_running'])) {
                    if (microtime(true) - (float)($turn['compaction_baseline_since'] ?? 0.0) >= 30.0) {
                        $this->taskFailTurn($responseId, 'compaction_handoff_page_unavailable',
                            '源回答已结束，但原临时聊天未进入可发送检查点的状态；原上下文仍保留。', true);
                        return;
                    }
                    $this->scheduleTaskPoll($responseId, 0.35);
                    return;
                }
                try {
                    $surface = TaskLedger::execution($logicalResponseId)['surface'];
                    if (($surface['target_id'] ?? null) !== $turn['target_id']
                        || ($surface['document_nonce'] ?? null) !== $turn['document_nonce']
                        || ($surface['owner_incarnation'] ?? null) !== $turn['owner_incarnation']
                        || (int)($surface['fencing_version'] ?? -1) !== (int)$turn['fencing_version']
                        || ($surface['status'] ?? null) !== 'busy') {
                        throw new GatewayError('compaction_handoff_page_changed',
                            '摘要检查点准备期间原任务页面或所有权已变化。', 409);
                    }
                    if (!empty($turn['compaction_repair_used'])) {
                        if (ContextCompaction::acceptedRetainedHandoffSummary($logicalResponseId) !== null) {
                            $this->chatGptTurns[$responseId]['compaction_phase'] = 'waiting';
                            $this->scheduleTaskPoll($responseId, 0.01);
                            return;
                        }
                        ContextCompaction::assertRetainedHandoffRepairReady($logicalResponseId,
                            (string)($turn['compaction_handoff_id'] ?? ''));
                        $handoff = [
                            'handoff_id' => (string)$turn['compaction_handoff_id'],
                            'prompt' => "The previous checkpoint answer ended without submitting its reserved control receipt. "
                                . "Do not repeat any development action. The same one-shot control token remains pending. "
                                . "Use the attached App to submit the factual summary now.\n\n"
                                . (string)$turn['compaction_original_prompt'],
                        ];
                    } else {
                        WebMessageAudit::recordIncoming($logicalResponseId, 'compaction_source_reply',
                            (string)($turn['compaction_source_answer'] ?? ''));
                        $handoff = ContextCompaction::beginRetainedHandoff($logicalResponseId,
                            (string)($turn['compaction_source_answer'] ?? ''));
                    }
                } catch (GatewayError $handoffError) {
                    if (!empty($turn['compaction_repair_used'])
                        && $handoffError->errorCode === 'compaction_handoff_unavailable'
                        && ContextCompaction::acceptedRetainedHandoffSummary($logicalResponseId) !== null) {
                        $this->chatGptTurns[$responseId]['compaction_phase'] = 'waiting';
                        $this->scheduleTaskPoll($responseId, 0.01);
                        return;
                    }
                    $this->taskFailTurn($responseId, $handoffError->errorCode, $handoffError->getMessage(), true);
                    return;
                }
                $this->chatGptTurns[$responseId]['compaction_phase'] = 'preparing';
                $this->chatGptTurns[$responseId]['deadline'] = (float) TaskLedger::execution($logicalResponseId)['turn']['deadline_at'];
                $this->chatGptTurns[$responseId]['compaction_handoff_id'] = (string)$handoff['handoff_id'];
                $this->chatGptTurns[$responseId]['baseline_users'] = is_array($baseline['user_ids'] ?? null)
                    ? $baseline['user_ids'] : [];
                $this->chatGptTurns[$responseId]['baseline_assistants'] = is_array($baseline['assistant_ids'] ?? null)
                    ? $baseline['assistant_ids'] : [];
                $this->chatGptTurns[$responseId]['bound_assistant_id'] = null;
                $this->chatGptTurns[$responseId]['continuation_marker'] = '';
                $this->chatGptTurns[$responseId]['continuation_pending'] = false;
                $this->chatGptTurns[$responseId]['prompt'] = (string)$handoff['prompt'];
                if (empty($turn['compaction_repair_used'])) {
                    $this->chatGptTurns[$responseId]['compaction_original_prompt'] = (string)$handoff['prompt'];
                }
                $this->chatGptTurns[$responseId]['attachments'] = [];
                $this->chatGptTurns[$responseId]['require_root_before_send'] = false;
                $this->chatGptTurns[$responseId]['continuation_sending'] = true;
                $this->chatGptTurns[$responseId]['retained_connector'] = true;
                $this->chatGptTurns[$responseId]['sent_at'] = 0.0;
                $this->chatGptTurns[$responseId]['compaction_prose_without_receipt_since'] = 0.0;
                $this->chatGptTurns[$responseId]['settle_text'] = null;
                $this->chatGptTurns[$responseId]['settle_at'] = 0.0;
                $this->prepareRetainedTaskConnector($responseId,
                    (string)($this->chatGptTurns[$responseId]['connector_name'] ?? ''));
            });
    }

    /**
     * Consume the exact one-shot summary receipt. Transient CDP observations retain the
     * submitted handoff and its deadline; verified page loss still cannot authorize replay.
     */
    private function pollRetainedCompactionHandoff(string $responseId, string $logicalResponseId): void
    {
        $turn = $this->chatGptTurns[$responseId] ?? null;
        if (!is_array($turn)) return;
        $expression = WebModelPage::responseExpression((array)$turn['baseline_users'],
            (array)$turn['baseline_assistants']);
        $this->evaluateTask($responseId, $expression,
            function ($error, $snapshot) use ($responseId, $logicalResponseId): void {
                $turn = $this->chatGptTurns[$responseId] ?? null;
                if (!is_array($turn) || ($turn['compaction_phase'] ?? null) !== 'waiting') return;
                try {
                    $summary = ContextCompaction::acceptedRetainedHandoffSummary($logicalResponseId);
                } catch (GatewayError $ledgerError) {
                    $this->taskFailTurn($responseId, $ledgerError->errorCode, $ledgerError->getMessage(), true);
                    return;
                }
                if ($summary === null && ($error !== null || !is_array($snapshot)
                    || trim((string)($snapshot['url'] ?? '')) === '')) {
                    $errors = (int)($turn['handoff_poll_errors'] ?? 0) + 1;
                    $this->chatGptTurns[$responseId]['handoff_poll_errors'] = $errors;
                    $since = (float)($turn['handoff_poll_error_since'] ?? 0.0);
                    if ($since <= 0.0) {
                        $this->chatGptTurns[$responseId]['handoff_poll_error_since'] = $since = microtime(true);
                    }
                    // A failed renderer read does not end the submitted summary turn. Reobserve
                    // the same fenced page until pollTaskTurn enforces its original durable deadline;
                    // never resend the prompt, rotate the control token, or replay native tools.
                    if ($errors >= 4 && microtime(true) - $since >= 30.0
                        && empty($turn['handoff_observation_delay_reported'])) {
                        $this->chatGptTurns[$responseId]['handoff_observation_delay_reported'] = true;
                        try { TaskLedger::observationDelayed($logicalResponseId); }
                        catch (\Throwable) { /* Diagnostics cannot invalidate a pending summary. */ }
                    }
                    $this->scheduleTaskPoll($responseId, min(5.0, 1.0 + $errors * 0.5));
                    return;
                }
                $this->chatGptTurns[$responseId]['handoff_poll_errors'] = 0;
                $this->chatGptTurns[$responseId]['handoff_poll_error_since'] = 0.0;
                if ($error !== null || !is_array($snapshot)
                    || !WebModelPage::isTemporaryChatUrl((string)($snapshot['url'] ?? ''), self::modelUrl())) {
                    if ($summary === null) {
                        $this->taskFailTurn($responseId, 'compaction_handoff_page_unavailable',
                            '检查点请求后的原页面不可确认；不会重复发送，原上下文仍保留。', true);
                        return;
                    }
                    $snapshot = ['new_user' => false, 'generation_running' => false, 'error' => null];
                }
                if ($summary !== null) {
                    try {
                        WebMessageAudit::markAccepted($logicalResponseId, 'compaction_handoff');
                        if (!empty($turn['compaction_repair_used']) && !empty($snapshot['new_user'])) {
                            WebMessageAudit::markAccepted($logicalResponseId, 'compaction_handoff_repair');
                        }
                        WebMessageAudit::recordIncoming($logicalResponseId, 'compaction_handoff_receipt', $summary);
                    } catch (GatewayError $auditError) {
                        $this->taskFailTurn($responseId, $auditError->errorCode, $auditError->getMessage(), true);
                        return;
                    }
                    if (!empty($snapshot['generation_running'])) {
                        $this->evaluateTask($responseId, WebModelPage::stopGenerationExpression(),
                            function () use ($responseId, $logicalResponseId): void {
                                $this->commitRetainedCompactionHandoff($responseId, $logicalResponseId);
                            });
                        return;
                    }
                    $this->commitRetainedCompactionHandoff($responseId, $logicalResponseId);
                    return;
                }
                if (is_array($snapshot['error'] ?? null)) {
                    if (($snapshot['error']['code'] ?? '') === 'CHATGPT_NETWORK_ERROR'
                        && $this->retryCompactNetworkError($responseId, $snapshot)) return;
                    $this->taskFailTurn($responseId, (string)($snapshot['error']['code'] ?? 'compaction_handoff_page_error'),
                        (string)($snapshot['error']['message'] ?? '检查点网页请求失败。'), true);
                    return;
                }
                if (!empty($snapshot['new_user'])) {
                    try { WebMessageAudit::markAccepted($logicalResponseId,
                        !empty($turn['compaction_repair_used']) ? 'compaction_handoff_repair' : 'compaction_handoff'); }
                    catch (GatewayError $auditError) {
                        $this->taskFailTurn($responseId, $auditError->errorCode, $auditError->getMessage(), true);
                        return;
                    }
                } elseif (microtime(true) - (float)($turn['sent_at'] ?? 0.0) >= 45.0) {
                    $this->taskFailTurn($responseId, 'compaction_handoff_outcome_unknown',
                        '检查点请求未显示接收证据，不能重复发送；原上下文仍保留。', true);
                    return;
                }
                if (!empty($snapshot['new_user']) && empty($snapshot['generation_running'])
                    && !empty($snapshot['completion_action_visible'])) {
                    $since = (float)($turn['compaction_prose_without_receipt_since'] ?? 0.0);
                    if ($since <= 0.0) $this->chatGptTurns[$responseId]['compaction_prose_without_receipt_since'] = microtime(true);
                    elseif (microtime(true) - $since >= self::COMPLETION_SETTLE_SECONDS) {
                        try {
                            WebMessageAudit::recordIncoming($logicalResponseId,
                                !empty($turn['compaction_repair_used']) ? 'compaction_handoff_repair_reply' : 'compaction_handoff_page_reply',
                                (string)(($snapshot['markdown'] ?? '') !== ''
                                    ? $snapshot['markdown'] : ($snapshot['visible_text'] ?? '')));
                            if (empty($turn['compaction_repair_used'])) {
                                ContextCompaction::assertRetainedHandoffRepairReady($logicalResponseId,
                                    (string)($turn['compaction_handoff_id'] ?? ''));
                                $this->chatGptTurns[$responseId]['compaction_repair_used'] = true;
                                $this->chatGptTurns[$responseId]['compaction_baseline_since'] = microtime(true);
                                $this->chatGptTurns[$responseId]['compaction_phase'] = 'baseline';
                                $this->observeRetainedCompactionBaseline($responseId, $logicalResponseId);
                                return;
                            }
                        } catch (GatewayError $repairError) {
                            if ($repairError->errorCode === 'compaction_handoff_unavailable'
                                && ContextCompaction::acceptedRetainedHandoffSummary($logicalResponseId) !== null) {
                                $this->scheduleTaskPoll($responseId, 0.01);
                                return;
                            }
                            if ($repairError->errorCode !== 'compaction_handoff_unavailable') {
                                $this->taskFailTurn($responseId, $repairError->errorCode, $repairError->getMessage(), true);
                                return;
                            }
                        }
                        $this->taskFailTurn($responseId, 'compaction_handoff_missing',
                            '网页已结束检查点回答，但没有提交一次性摘要回执；原上下文仍保留。', true);
                        return;
                    }
                }
                $this->scheduleTaskPoll($responseId, 0.15);
            }, 3.0);
    }

    private function commitRetainedCompactionHandoff(string $responseId, string $logicalResponseId): void
    {
        $turn = $this->chatGptTurns[$responseId] ?? null;
        if (!is_array($turn) || ($turn['compaction_phase'] ?? null) !== 'waiting') return;
        $this->commitTaskCompletion($responseId, $logicalResponseId,
            (string)($turn['compaction_source_answer'] ?? ''));
    }

    /** Always visit the response after a confirmation check, even when the callback ran late. */
    private static function shouldCheckTaskToolConfirmation(array &$turn): bool
    {
        if (empty($turn['tool_confirmation_snapshot_next'])) return true;
        // A slow CDP callback or busy event loop must not skip the response snapshot.
        $turn['tool_confirmation_snapshot_next'] = false;
        return false;
    }

    /** Detect and optionally resolve a ChatGPT one-shot tool confirmation on the exact task surface. */
    private function checkTaskToolConfirmation(string $responseId): void
    {
        $turn = $this->chatGptTurns[$responseId] ?? null;
        if (!is_array($turn)) return;
        $connectorName = trim((string) ($turn['connector_name'] ?? ''));
        if ($connectorName === '') {
            $this->taskFailTurn($responseId, 'CHATGPT_CONNECTOR_NAME_MISSING', '无法识别当前任务的工具连接器名称。');
            return;
        }
        $this->evaluateTask($responseId, WebModelPage::toolConfirmationExpression($connectorName),
            function ($error, $confirmation) use ($responseId, $connectorName): void {
                $turn = $this->chatGptTurns[$responseId] ?? null;
                if (!is_array($turn)) return;
                if ($error !== null || !is_array($confirmation) || empty($confirmation['visible'])) {
                    $this->chatGptTurns[$responseId]['tool_confirmation_snapshot_next'] = true;
                    $this->scheduleTaskPoll($responseId, 0.01);
                    return;
                }
                if ((int) ($confirmation['dialog_count'] ?? 0) !== 1 || (int) ($confirmation['one_shot_count'] ?? 0) !== 1) {
                    $this->taskFailTurn($responseId, 'CHATGPT_TOOL_CONFIRMATION_UNSAFE', 'ChatGPT 工具确认窗口无法唯一识别一次性 Allow 操作，已停止自动操作。');
                    return;
                }
                if (empty($turn['auto_approve_tool_calls'])) {
                    $this->taskFailTurn($responseId, 'CHATGPT_TOOL_CONFIRMATION_REQUIRED', 'ChatGPT 要求一次性工具确认。请在“设置”中开启自动确认一次性工具调用后重试。');
                    return;
                }
                $this->evaluateTask($responseId, WebModelPage::focusToolConfirmationExpression($connectorName),
                    function ($focusError, $focused) use ($responseId): void {
                        if ($focusError !== null || !is_array($focused) || empty($focused['ok'])) {
                            $this->taskFailTurn($responseId, 'CHATGPT_TOOL_CONFIRMATION_UNSAFE', 'ChatGPT 一次性工具确认按钮无法安全获得焦点。');
                            return;
                        }
                        $this->dispatchTaskKey($responseId, 'Enter', function ($keyError) use ($responseId): void {
                            if ($keyError !== null) {
                                $this->taskFailTurn($responseId, 'CHATGPT_TOOL_CONFIRMATION_FAILED', 'ChatGPT 一次性工具确认未完成。');
                                return;
                            }
                            if (!isset($this->chatGptTurns[$responseId])) return;
                            $this->chatGptTurns[$responseId]['tool_confirmations_approved'] = (int) ($this->chatGptTurns[$responseId]['tool_confirmations_approved'] ?? 0) + 1;
                            $this->chatGptTurns[$responseId]['tool_confirmation_snapshot_next'] = true;
                            $this->scheduleTaskPoll($responseId, 0.05);
                        });
                    });
            });
    }

    private function scheduleTaskPoll(string $responseId, float $delay = 0.18): void
    {
        if (isset($this->chatGptTurns[$responseId])) Timer::add($delay, fn() => $this->pollTaskTurn($responseId), null, false);
    }

    /**
     * Prove an intentionally empty baseline from the accepted physical submission, including
     * logical receipt rounds whose own submit/accept fields are empty. Ownership and current
     * generation remain checked by each stop caller; an unsent job alone grants no DOM witness.
     * @param array $job Current logical job or the pinned physical source, raw or decoded.
     * @param array $surface Fenced destination document already verified by the caller.
     * @return bool Whether this exact document durably accepted its first user message.
     */
    private static function acceptedInitialUser(array $job, array $surface): bool
    {
        if (empty($surface['document_nonce']) || ($job['surface_id'] ?? null) !== ($surface['id'] ?? null)
            || ($job['task_id'] ?? null) !== ($surface['task_id'] ?? null)) return false;
        $query = SDB(null, 'medoo', 'sqlite')->table('gateway_response_jobs')
            ->where('task_id', $job['task_id'])->where('surface_id', $surface['id'])
            ->where('submitted_document_nonce', $surface['document_nonce'])
            ->where('submit_intent_at', '<>', null)->where('accepted_at', '<>', null);
        // Read the original JSON: decoded compatibility defaults cannot distinguish []
        // from missing or malformed legacy metadata and must not grant a first-send proof.
        $source = $query->order([['rowid', 'DESC']])->limit(1)
            ->find('id,submit_intent_at,accepted_at,submitted_document_nonce,baseline_users_json');
        if (!is_array($source) || empty($source['submit_intent_at']) || empty($source['accepted_at'])
            || ($source['submitted_document_nonce'] ?? null) !== $surface['document_nonce']) return false;
        if (!empty($job['submit_intent_at']) && !empty($job['accepted_at']) && $source['id'] !== $job['id']) return false;
        $users = json_decode((string)($source['baseline_users_json'] ?? ''));
        return is_array($users) && $users === [];
    }

    /** Keep an already published physical generation byte-compatible across a gateway upgrade. */
    private static function hasLegacyCitationPrefix(array $turn): bool
    {
        foreach (['round_prefix', 'emitted', 'draft_emitted'] as $key) {
            if (preg_match('~!\[[^\]\r\n]*\]\(https://www\.google\.com/s2/favicons\?~', (string)($turn[$key] ?? ''))) return true;
        }
        return false;
    }

    /**
     * Keep one response read alive through renderer stalls without delaying host tools.
     * The normal 3s UI-action timeout would discard valid 4-10s observations forever.
     * After 3s, the existing fenced poll adopts receipts and drains tools while this
     * read waits up to 15s. Session replacement invalidates both the read and monitor.
     * @param callable(?array,mixed):void $callback Same read-only snapshot contract as evaluateTask.
     */
    private function observeTaskResponse(string $responseId, string $expression, callable $callback): void
    {
        $sessionId = (string) $this->chatGptTurns[$responseId]['session_id'];
        $sequence = (int) ($this->chatGptTurns[$responseId]['response_observation_sequence'] ?? 0) + 1;
        $this->chatGptTurns[$responseId]['response_observation_sequence'] = $sequence;
        $this->chatGptTurns[$responseId]['response_observation_pending'] = $sequence;
        $started = microtime(true);
        $monitor = Timer::add(0.25, function () use ($responseId, $sessionId, $sequence, $started, &$monitor): void {
            $turn = $this->chatGptTurns[$responseId] ?? null;
            if (($turn['session_id'] ?? '') !== $sessionId
                || ($turn['response_observation_pending'] ?? null) !== $sequence) {
                Timer::del($monitor);
                return;
            }
            if (microtime(true) - $started >= 3.0) $this->pollTaskTurn($responseId);
        });
        $this->evaluateTask($responseId, $expression, function ($error, $snapshot) use (
            $responseId, $sequence, $monitor, $callback
        ): void {
            Timer::del($monitor);
            if (($this->chatGptTurns[$responseId]['response_observation_pending'] ?? null) !== $sequence) return;
            unset($this->chatGptTurns[$responseId]['response_observation_pending']);
            $callback($error, $snapshot);
        }, 15.0);
    }

    /**
     * Stop Web generation promptly for a queued human update while accepted host calls drain.
     * This keeps the observer and capability alive, delivers already registered calls, and
     * leaves exact results to the existing receipt/steering transaction. It never completes
     * the old answer, revokes tools, or submits the new instruction before that transaction.
     * @param string $responseId Physical source observer, whose prompt was actually submitted.
     * @param array $ledger Fresh logical job and owned surface metadata.
     * @return bool Whether this poll is reserved for draining the pending human update.
     */
    private function stopForPendingSteering(string $responseId, array $ledger): bool
    {
        $turn = $this->chatGptTurns[$responseId] ?? null;
        if (!is_array($turn) || empty($turn['tool_capable'])
            || ($ledger['job']['operation_kind'] ?? 'response') !== 'response'
            || $ledger['turn']['response_id'] !== $ledger['job']['id']) return false;
        $state = $turn['pending_steering_stop'] ?? ['checked_at'=>0.0, 'pending'=>false, 'clicked'=>false];
        $now = microtime(true);
        if ($now - $state['checked_at'] >= 1.0) {
            $updates = TaskLedger::pendingOperationalContextUnlocked($ledger['job']);
            $state['pending'] = false;
            foreach ($updates['messages'] as $message) {
                if (($message['role'] ?? '') === 'user' && !ContextHistory::isOperationalUserMessage($message)) {
                    $state['pending'] = true;
                    break;
                }
            }
            $state['checked_at'] = $now;
            if (!$state['pending']) $state['clicked'] = false;
            $this->chatGptTurns[$responseId]['pending_steering_stop'] = $state;
        }
        if (!$state['pending']) return false;
        // The page's Stop button affects its generator, not the native invocation ledger.
        // Registered calls remain deliverable even after the Web stream disconnects.
        $this->deliverPendingToolWithoutSnapshot($responseId);
        if ($state['clicked']) {
            $this->scheduleTaskPoll($responseId, 0.25);
            return true;
        }
        $physical = ResponseJob::execution($responseId);
        if (empty($physical['submitted_document_nonce'])
            || $physical['submitted_document_nonce'] !== $ledger['surface']['document_nonce']) {
            $this->scheduleTaskPoll($responseId, 0.25);
            return true;
        }
        preg_match_all('/(?<![A-Za-z0-9_-])turn_[A-Za-z0-9_-]{32}(?![A-Za-z0-9_-])/', (string)$physical['prompt'], $tokens);
        $witnesses = count(array_unique($tokens[0])) === 1 ? [$tokens[0][0]] : [(string)$physical['prompt']];
        if (!empty($physical['transport_transaction_id'])) $witnesses[] = (string)$physical['transport_transaction_id'];
        $this->evaluateTask($responseId, WebModelPage::userStopExpression((string)$ledger['surface']['url'], $witnesses,
            (array)$physical['baseline_users'], true, true, self::acceptedInitialUser($physical, $ledger['surface'])),
            function ($error, $snapshot) use ($responseId, $ledger): void {
                $turn = $this->chatGptTurns[$responseId] ?? null;
                if (!is_array($turn)) return;
                if (($turn['logical_response_id'] ?? $responseId) !== $ledger['job']['id']) {
                    $this->scheduleTaskPoll($responseId, 0.25);
                    return;
                }
                try { $fresh = TaskLedger::pollExecution((string)$ledger['job']['id']); }
                catch (GatewayError $failure) {
                    $this->taskFailTurn($responseId, $failure->errorCode, $failure->getMessage(), true);
                    return;
                }
                if ($fresh['turn']['response_id'] !== $ledger['job']['id']
                    || $fresh['turn']['generation_id'] !== $ledger['turn']['generation_id']
                    || $fresh['surface']['document_nonce'] !== $ledger['surface']['document_nonce']
                    || $fresh['surface']['fencing_version'] !== $ledger['surface']['fencing_version']) {
                    // A native receipt can advance the logical round during this CDP call.
                    // Let the normal poll adopt/fence it, without accepting this stale stop.
                    $this->scheduleTaskPoll($responseId, 0.25);
                    return;
                }
                if ($error === null && is_array($snapshot) && !empty($snapshot['owned']) && !empty($snapshot['stopped'])) {
                    $this->chatGptTurns[$responseId]['pending_steering_stop']['clicked'] = true;
                }
                $this->scheduleTaskPoll($responseId, 0.25);
            }, 3.0);
        return true;
    }

    /** Deliver a registered host call during steering or DOM failure, retaining its observed text boundary. */
    private function deliverPendingToolWithoutSnapshot(string $responseId): bool
    {
        $turn = $this->chatGptTurns[$responseId] ?? null;
        if (!is_array($turn) || empty($turn['tool_capable'])) return false;
        $logicalResponseId = (string) ($turn['logical_response_id'] ?? $responseId);
        $job = ResponseJob::execution($logicalResponseId);
        if ((string) $job['status'] !== 'running') return false;
        $invocations = ToolBroker::invocationsForResponse($logicalResponseId);
        if ($invocations === []) return false;
        $boundary = (string) ($turn['round_prefix'] ?? '') . (string) ($turn['observed_markdown'] ?? '');
        ResponseJob::completeToolCalls($logicalResponseId, $invocations, $boundary);
        $this->chatGptTurns[$responseId]['round_prefix'] = $boundary;
        $this->chatGptTurns[$responseId]['post_tool_answer_baseline'] = $boundary;
        $this->chatGptTurns[$responseId]['post_tool_answer_missing_since'] = 0.0;
        $this->chatGptTurns[$responseId]['tool_boundary_pending_id'] = null;
        $this->chatGptTurns[$responseId]['tool_boundary_pending_at'] = 0.0;
        $this->chatGptTurns[$responseId]['observed_markdown'] = null;
        $this->chatGptTurns[$responseId]['observed_content'] = false;
        $this->chatGptTurns[$responseId]['artifact_observed_candidates'] = [];
        return true;
    }

    /** Reuse a proven Connector binding in the retained conversation. */
    private function prepareRetainedTaskConnector(string $responseId, string $connectorName): void
    {
        if (!isset($this->chatGptTurns[$responseId])) return;
        $turn = $this->chatGptTurns[$responseId];
        $this->chatGptTurns[$responseId]['continuation_reuse_connector'] = !empty($turn['continuation_sending']);
        $this->chatGptTurns[$responseId]['retained_connector'] = true;
        if (($turn['compaction_phase'] ?? null) === 'preparing') {
            // A retained page proves the earlier tool results, but ChatGPT may not
            // expose App tools to a new message unless that message selects the App.
            // The one-shot checkpoint is a separate user message, so attach it again.
            $this->chatGptTurns[$responseId]['connector_preparing'] = true;
            $this->prepareTaskModel($responseId, false);
            return;
        }
        $this->chatGptTurns[$responseId]['connector_preparing'] = false;
        // verifySettledTaskConnector checks the physical document, source binding or
        // same-turn tool receipts, and exact prompt before the irreversible send.
        $this->preparePlainTaskComposer($responseId);
    }

    /** The old chat no longer offers its Connector; rebuild only from confirmed native receipts. */
    private function rebuildUnavailableRetainedConnector(string $responseId): void
    {
        $turn = $this->chatGptTurns[$responseId] ?? null;
        if (!is_array($turn)) return;
        $logicalResponseId = (string)($turn['logical_response_id'] ?? $responseId);
        try {
            $job = ResponseJob::execution($logicalResponseId);
            $retry = TaskLedger::prepareConnectorNoStartRetry($logicalResponseId, (string)$job['prompt'],
                (string)$turn['owner_incarnation'], (int)$turn['fencing_version'],
                self::CONNECTOR_NO_START_MAX_RETRIES, true,
                !empty($turn['continuation_sending']) ? 'connector_unavailable_after_receipts' : 'connector_unavailable_retained_page');
        } catch (GatewayError $error) {
            $this->taskFailTurn($responseId, $error->errorCode, $error->getMessage(), true);
            return;
        } catch (\Throwable) {
            $this->taskFailTurn($responseId, 'CHATGPT_CONNECTOR_REBUILD_FAILED', '无法安全重建工具续接页面。', true);
            return;
        }
        if (empty($retry['retry'])) {
            $this->taskFailTurn($responseId, 'CHATGPT_CONNECTOR_UNAVAILABLE',
                '原聊天不再提供工具连接器，且无法从已确认的工具回执安全重建页面。', true);
            return;
        }
        $commandId = (int)($turn['command_id'] ?? 0);
        $this->releaseTaskSession($responseId);
        if ($commandId > 0) {
            Timer::add(0.02, fn() => $this->prepareTaskSurface($logicalResponseId, $commandId, false), null, false);
        }
    }

    /**
     * Recover an explicit failed stream after exact host receipts settle, without replaying them.
     * @param string $responseId Physical page observer.
     * @param bool $waitForPending Let registered calls, undelivered receipts and active MCP work
     * drain at the original deadline before any failed-stream recovery.
     * @param string $errorCode Observed native stream error, used only for the recovery audit cause.
     * @return void
     */
    private function recoverFailedMessageStream(string $responseId, bool $waitForPending = false,
        string $errorCode = 'CHATGPT_NETWORK_ERROR'): void
    {
        $turn = $this->chatGptTurns[$responseId] ?? null;
        if (!is_array($turn)) return;
        $logicalResponseId = (string)($turn['logical_response_id'] ?? $responseId);
        try {
            $job = ResponseJob::execution($logicalResponseId);
            $retry = TaskLedger::prepareConnectorNoStartRetry(
                $logicalResponseId,
                (string)$job['prompt'],
                (string)$turn['owner_incarnation'],
                (int)$turn['fencing_version'],
                self::CONNECTOR_NO_START_MAX_RETRIES,
                true,
                $errorCode === 'CHATGPT_NETWORK_ERROR'
                    ? 'network_error_after_confirmed_receipts' : 'message_stream_error_after_confirmed_receipts'
            );
        } catch (GatewayError $error) {
            $this->taskFailTurn($responseId, $error->errorCode, $error->getMessage(), true);
            return;
        } catch (\Throwable) {
            $this->taskFailTurn($responseId, 'CHATGPT_MESSAGE_STREAM_ERROR',
                'ChatGPT 消息流断开，无法安全重建已确认的工具回执。', true);
            return;
        }
        if (empty($retry['retry'])) {
            if ($waitForPending && empty($job['output_started']) && (string)($job['output_text'] ?? '') === '') {
                $db = SDB(null, 'medoo', 'sqlite');
                $pending = $db->table('gateway_tool_invocations')->where('task_id', (string)$job['task_id'])
                    ->where('status', 'IN', ['registered','delivered'])->count();
                $undelivered = $db->table('gateway_tool_invocations')->where('task_id', (string)$job['task_id'])
                    ->where('status', 'completed')->where('result_delivered_at', null)->count();
                $active = empty($job['mcp_binding_id']) ? 0 : $db->table('gateway_mcp_activities')
                    ->where('binding_id', (string)$job['mcp_binding_id'])->where('status', 'active')->count();
                if (is_numeric($pending) && is_numeric($undelivered) && is_numeric($active)
                    && ((int)$pending + (int)$undelivered + (int)$active) > 0) {
                    $this->scheduleTaskPoll($responseId, 0.5);
                    return;
                }
            }
            $this->taskFailTurn($responseId, 'CHATGPT_MESSAGE_STREAM_ERROR',
                'ChatGPT 消息流断开；当前工具状态或重试次数不允许自动重建。', true);
            return;
        }
        $commandId = (int)($turn['command_id'] ?? 0);
        $this->releaseTaskSession($responseId);
        if ($commandId > 0) {
            Timer::add(0.02, fn() => $this->prepareTaskSurface($logicalResponseId, $commandId, false), null, false);
        }
    }

    /**
     * Prefer a control-only message on the original page after an explicit failed stream
     * or a continuously observed tool-only empty terminal. The page must have stopped
     * and its accepted native work and exact receipts must have drained. A connection banner during
     * generation never enters this path. The ledger's durable marker bounds retries across
     * lost acknowledgements/restarts; unknown effects still prohibit all automatic recovery.
     * @param string $responseId Physical observer, possibly following a newer native receipt round.
     * @param array $snapshot Bound stopped response with Send state and an optional native error.
     * @param string|null $recoveryCause Explicitly settled empty cause, or the snapshot's observed error.
     * @return bool Whether this path owns waiting, preparation or failure; false retains legacy recovery.
     */
    private function continueStoppedTaskStream(string $responseId, array $snapshot, ?string $recoveryCause = null): bool
    {
        $turn = $this->chatGptTurns[$responseId] ?? null;
        if (!is_array($turn) || empty($turn['tool_capable'])
            || ($snapshot['generation_running'] ?? null) !== false
            || ($snapshot['send_button_visible'] ?? null) !== true
            || empty($snapshot['new_user_id']) || empty($snapshot['response_id'])
            || (string)$snapshot['response_id'] !== (string)($turn['bound_assistant_id'] ?? '')) return false;
        $logicalId = (string)($turn['logical_response_id'] ?? $responseId);
        $completion = ToolBroker::completionForResponse($logicalId);
        if ((int)($completion['invocation_count'] ?? 0) < 1) return false;
        $errorCode = $recoveryCause ?? (string)($snapshot['error']['code'] ?? 'CHATGPT_MESSAGE_STREAM_ERROR');
        $failureKey = hash('sha256', json_encode([$snapshot['new_user_id'], $snapshot['response_id'],
            $errorCode, $snapshot['network_retry']['failure_id'] ?? '',
            $snapshot['network_retry']['fingerprint'] ?? ''], JSON_THROW_ON_ERROR));
        $previous = $turn['stopped_stream_observation'] ?? null;
        if (!is_array($previous) || ($previous['signature'] ?? null) !== $failureKey) {
            $this->chatGptTurns[$responseId]['stopped_stream_observation'] = ['signature'=>$failureKey,'since'=>microtime(true)];
            $this->scheduleTaskPoll($responseId, 0.5);
            return true;
        }
        if (microtime(true) - (float)$previous['since'] < self::COMPLETION_SETTLE_SECONDS) {
            $this->scheduleTaskPoll($responseId, 0.5);
            return true;
        }
        $this->evaluateTask($responseId, WebModelPage::baselineExpression(),
            function ($error, $baseline) use ($responseId, $logicalId, $snapshot, $failureKey, $errorCode): void {
                $turn = $this->chatGptTurns[$responseId] ?? null;
                if (!is_array($turn) || (string)($turn['logical_response_id'] ?? $responseId) !== $logicalId) return;
                if ($error !== null || !is_array($baseline) || empty($baseline['editor_ready'])
                    || empty($baseline['temporary_chat'])
                    || !WebModelPage::isTemporaryChatUrl((string)($baseline['url'] ?? ''), self::modelUrl())
                    || !in_array($snapshot['new_user_id'], (array)($baseline['user_ids'] ?? []), true)
                    || !in_array($snapshot['response_id'], (array)($baseline['assistant_ids'] ?? []), true)) {
                    // A genuinely unusable composer retains the existing exact-receipt fallback.
                    $this->recoverFailedMessageStream($responseId, true, $errorCode);
                    return;
                }
                if (!empty($baseline['generation_running'])) {
                    $this->chatGptTurns[$responseId]['stopped_stream_observation'] = null;
                    $this->scheduleTaskPoll($responseId, 0.5);
                    return;
                }
                try {
                    $reservation = TaskLedger::reserveTaskNetworkRetry($logicalId, (string)$turn['owner_incarnation'],
                        (int)$turn['fencing_version'], (string)$turn['document_nonce'], $failureKey, false, true);
                    if (!empty($reservation['waiting'])) { $this->scheduleTaskPoll($responseId, 0.5); return; }
                    if (!empty($reservation['exhausted'])) {
                        $this->taskFailTurn($responseId, 'CHATGPT_NETWORK_RETRY_EXHAUSTED',
                            $errorCode === 'CHATGPT_EMPTY_RESPONSE'
                                ? 'ChatGPT 网页连续停止且未返回最终答复，自动续做已达三次；原上下文和工具回执仍保留。'
                                : 'ChatGPT 网页连续出现网络错误，自动重试已达三次；原上下文和工具回执仍保留。', true);
                        return;
                    }
                    if (empty($reservation['continue'])) {
                        $this->recoverFailedMessageStream($responseId, true, $errorCode);
                        return;
                    }
                    $prompt = ToolBroker::continuationPromptForResponse($logicalId, $reservation['marker'], false, true);
                    ResponseJob::finishDraftText($logicalId);
                    $this->chatGptTurns[$responseId]['baseline_users'] = $baseline['user_ids'];
                    $this->chatGptTurns[$responseId]['baseline_assistants'] = $baseline['assistant_ids'];
                    $this->chatGptTurns[$responseId]['bound_assistant_id'] = '';
                    $this->chatGptTurns[$responseId]['continuation_marker'] = $reservation['marker'];
                    $this->chatGptTurns[$responseId]['continuation_pending'] = true;
                    $this->chatGptTurns[$responseId]['continuation_intent_at'] = time();
                    $this->chatGptTurns[$responseId]['continuation_sending'] = true;
                    $this->chatGptTurns[$responseId]['deadline'] = (float)$reservation['deadline_at'];
                    $this->chatGptTurns[$responseId]['prompt'] = $prompt;
                    $this->chatGptTurns[$responseId]['attachments'] = [];
                    $this->chatGptTurns[$responseId]['require_root_before_send'] = false;
                    $this->chatGptTurns[$responseId]['sent_at'] = 0.0;
                    foreach (['round_prefix','post_tool_answer_baseline','draft_emitted','emitted'] as $key) {
                        $this->chatGptTurns[$responseId][$key] = '';
                    }
                    $this->chatGptTurns[$responseId]['observed_markdown'] = null;
                    $this->chatGptTurns[$responseId]['observed_content'] = false;
                    $this->chatGptTurns[$responseId]['artifact_observed_candidates'] = [];
                    unset($this->chatGptTurns[$responseId]['markdown_buffer']);
                    // A distinct user message must select its App again even on the same page.
                    $this->chatGptTurns[$responseId]['retained_connector'] = false;
                    $this->chatGptTurns[$responseId]['connector_preparing'] = true;
                    $this->prepareTaskConnector($responseId, (string)$turn['connector_name'], 0);
                } catch (GatewayError $failure) {
                    $this->taskFailTurn($responseId, $failure->errorCode, $failure->getMessage(), true);
                }
            });
        return true;
    }

    /**
     * Recover a stopped ordinary network error after host tools and MCP replies drain.
     * Completed tool work uses the existing exact-receipt recovery and retires the old page;
     * only tasks without completed host work may activate native Retry. A recovery banner or
     * running generation never reaches this operation. Missing native controls keep using
     * the same fresh-page fallback.
     * @param string $responseId Physical observer mapped to the current logical response.
     * @param array $snapshot Stopped native network-error card with exact response identities.
     * @return bool Whether this native recovery path handled polling, activation or failure.
     */
    private function retryTaskNetworkError(string $responseId, array $snapshot): bool
    {
        $turn = $this->chatGptTurns[$responseId] ?? null;
        if (!is_array($turn)) return true;
        $descriptor = $snapshot['network_retry'] ?? null;
        if (!is_array($descriptor) || empty($descriptor['failure_id']) || empty($descriptor['fingerprint'])) return false;
        if (!empty($snapshot['generation_running'])) return false;
        $nativeControlAvailable = !empty($descriptor['available']);
        $logicalResponseId = (string)($turn['logical_response_id'] ?? $responseId);
        $failureKey = hash('sha256', (string)$descriptor['failure_id'].'|'.(string)$descriptor['fingerprint']);
        try {
            $job = ResponseJob::execution($logicalResponseId);
            if ($job['operation_kind'] !== 'response') return false;
            $reservation = TaskLedger::reserveTaskNetworkRetry($logicalResponseId,
                (string)$turn['owner_incarnation'], (int)$turn['fencing_version'],
                (string)$turn['document_nonce'], $failureKey, $nativeControlAvailable);
            if (!empty($reservation['recover'])) {
                // Revalidate and rotate atomically; a tool arriving after the decision
                // still blocks recovery, and the old token cannot act on the new page.
                $this->recoverFailedMessageStream($responseId, true);
                return true;
            }
            if (!empty($reservation['exhausted'])) {
                $this->taskFailTurn($responseId, 'CHATGPT_NETWORK_RETRY_EXHAUSTED',
                    'ChatGPT 网页连续出现网络错误，自动重试已达三次；原上下文和工具回执仍保留。', true);
                return true;
            }
            if (empty($reservation['retry'])) {
                if (!empty($reservation['waiting']) || !empty($reservation['attempt'])
                    || (!$nativeControlAvailable && (string)($descriptor['button_label'] ?? '') !== '')) {
                    $this->scheduleTaskPoll($responseId, 0.5);
                    return true;
                }
                return false;
            }
            $descriptor['attempt_key'] = $failureKey;
            $this->evaluateTask($responseId, WebModelPage::clickNetworkRetryExpression($descriptor),
                function ($error, $result) use ($responseId): void {
                    if (!isset($this->chatGptTurns[$responseId])) return;
                    // Persisted intent and the DOM latch survive lost acknowledgements.
                    // Do not re-click, send new context, rotate the token or replay tools.
                    if ($error === null && is_array($result) && !empty($result['clicked'])) {
                        $this->chatGptTurns[$responseId]['bound_assistant_id'] = '';
                        $this->chatGptTurns[$responseId]['observed_markdown'] = null;
                        $this->chatGptTurns[$responseId]['observed_content'] = false;
                        $this->chatGptTurns[$responseId]['settle_text'] = null;
                        $this->chatGptTurns[$responseId]['settle_at'] = 0.0;
                        $this->chatGptTurns[$responseId]['post_receipt_ui_observation'] = null;
                    }
                    $this->scheduleTaskPoll($responseId, 0.5);
                });
            return true;
        } catch (GatewayError $error) {
            $this->taskFailTurn($responseId, $error->errorCode, $error->getMessage(), true);
            return true;
        }
    }

    /**
     * Retry only the stopped native network-error card of a work-fenced active compaction.
     * Durable source/control ownership is checked before clicking, and the DOM is rechecked at
     * activation. No new context, token, host call or summary slot is sent by this operation.
     * @param string $responseId Physical page observer. @param array $snapshot Current DOM evidence.
     * @return bool Whether this is a compact recovery path and polling/failure was handled.
     */
    private function retryCompactNetworkError(string $responseId, array $snapshot): bool
    {
        $turn = $this->chatGptTurns[$responseId] ?? null;
        if (!is_array($turn)) return true;
        $logicalResponseId = (string)($turn['logical_response_id'] ?? $responseId);
        try {
            $job = ResponseJob::execution($logicalResponseId);
            if (!in_array((string)$job['operation_kind'], ['compact_v1','compact_v2'], true)
                || empty($job['previous_response_id'])) return false;
            $descriptor = $snapshot['network_retry'] ?? null;
            $failureKey = is_array($descriptor) && !empty($descriptor['failure_id'])
                && !empty($descriptor['fingerprint'])
                ? hash('sha256', (string)$descriptor['failure_id'].'|'.(string)$descriptor['fingerprint']) : '';
            $previousRetry = $turn['compact_network_retry'] ?? null;
            if (is_array($previousRetry)
                && (string)($previousRetry['failure_key'] ?? '') !== $failureKey) {
                // A later error invalidates the earlier generation proof even when its
                // activation acknowledgement is lost. A disabled button on the same
                // still-pending native retry does not erase that attempt's observation.
                unset($this->chatGptTurns[$responseId]['compact_network_retry']);
            }
            if (is_array($descriptor) && empty($descriptor['available'])
                && (string)($descriptor['button_label'] ?? '') !== '') {
                // The native button is disabled while its own retry request is pending.
                // Observe that UI request at the existing deadline; do not click it again.
                $this->scheduleTaskPoll($responseId, 0.5);
                return true;
            }
            if (!is_array($descriptor) || empty($descriptor['available'])
                || !empty($snapshot['generation_running']) || empty($descriptor['failure_id'])) {
                $this->taskFailTurn($responseId, 'CHATGPT_NETWORK_RETRY_UNAVAILABLE',
                    'ChatGPT 网络错误已识别，但当前页面没有唯一可用的重试控件；原上下文仍保留。', true);
                return true;
            }
            $reservation = ContextCompaction::reserveRetainedNetworkRetry($logicalResponseId,
                (string)($turn['compaction_handoff_id'] ?? ''), (string)$turn['owner_incarnation'],
                (int)$turn['fencing_version'], (string)$turn['document_nonce'], $failureKey);
            if (!empty($reservation['exhausted'])) {
                $this->taskFailTurn($responseId, 'CHATGPT_NETWORK_RETRY_EXHAUSTED',
                    'ChatGPT 网页连续出现网络错误，自动重试已达三次；原上下文和工具回执仍保留。', true);
                return true;
            }
            if (empty($reservation['retry'])) {
                $this->scheduleTaskPoll($responseId, 0.5);
                return true;
            }
            $descriptor['attempt_key'] = $failureKey;
            $retryObservation = null;
            if (empty($turn['compaction_handoff_id'])) {
                $delivery = ToolBroker::allResultsDeliveryForResponse((string)$job['previous_response_id']);
                if (is_array($delivery)) {
                    $retryObservation = [
                        'failure_key' => $failureKey,
                        'logical_response_id' => $logicalResponseId,
                        'source_response_id' => (string)$job['previous_response_id'],
                        'document_nonce' => (string)$turn['document_nonce'],
                        'user_id' => (string)($descriptor['user_id'] ?? ''),
                        'receipt_signature' => (string)$job['previous_response_id'] . ':'
                            . ($delivery['latest_at_us'] ?? $delivery['latest_at']) . ':' . $delivery['count'],
                        'observed_running' => false,
                    ];
                }
            }
            $this->evaluateTask($responseId, WebModelPage::clickNetworkRetryExpression($descriptor),
                function ($error, $result) use ($responseId, $retryObservation): void {
                    if (!isset($this->chatGptTurns[$responseId])) return;
                    // A lost CDP acknowledgement cannot authorize another click of this intent.
                    // The durable reservation and document latch survive subsequent observations.
                    if ($error === null && is_array($result) && !empty($result['clicked'])) {
                        $this->chatGptTurns[$responseId]['compact_network_retry'] = $retryObservation;
                        $this->chatGptTurns[$responseId]['bound_assistant_id'] = '';
                        $this->chatGptTurns[$responseId]['observed_markdown'] = null;
                        $this->chatGptTurns[$responseId]['observed_content'] = false;
                        $this->chatGptTurns[$responseId]['settle_text'] = null;
                        $this->chatGptTurns[$responseId]['settle_at'] = 0.0;
                    }
                    $this->scheduleTaskPoll($responseId, 0.5);
                });
            return true;
        } catch (GatewayError $error) {
            $this->taskFailTurn($responseId, $error->errorCode, $error->getMessage(), true);
            return true;
        }
    }

    /**
     * Prove a native Retry produced a new owned source answer after all receipts settled.
     * An old final alone, a changed user/document or a different receipt batch cannot pass.
     * This observation complements lost stream events; it does not complete a checkpoint
     * or bypass the existing MCP completion fence and one-shot summary handoff.
     * @param string $responseId Physical observer owning the retry.
     * @param array $snapshot Current owned DOM snapshot.
     * @param string $sourceResponseId Exact source host-result response.
     * @param string $receiptSignature Current delivered receipt batch identity.
     * @return bool A running generation was observed after the confirmed retry and is now final.
     */
    private function observeCompactNetworkRetryGeneration(string $responseId, array $snapshot,
        string $sourceResponseId, string $receiptSignature): bool
    {
        $turn = $this->chatGptTurns[$responseId] ?? null;
        $retry = is_array($turn) ? ($turn['compact_network_retry'] ?? null) : null;
        if (!is_array($retry)
            || (string)($retry['logical_response_id'] ?? '') !== (string)($turn['logical_response_id'] ?? $responseId)
            || (string)($retry['source_response_id'] ?? '') !== $sourceResponseId
            || $receiptSignature === '' || (string)($retry['receipt_signature'] ?? '') !== $receiptSignature
            || (string)($retry['document_nonce'] ?? '') !== (string)($turn['document_nonce'] ?? '')
            || (string)($retry['user_id'] ?? '') === ''
            || (string)$retry['user_id'] !== (string)($snapshot['new_user_id'] ?? '')
            || !empty($turn['compaction_handoff_id']) || is_array($snapshot['error'] ?? null)) return false;
        if (!empty($snapshot['generation_running'])) {
            $this->chatGptTurns[$responseId]['compact_network_retry']['observed_running'] = true;
            return false;
        }
        return !empty($retry['observed_running']) && !empty($snapshot['response_present'])
            && !empty($snapshot['completion_action_visible'])
            && (string)($snapshot['response_id'] ?? '') !== ''
            && (string)($snapshot['response_id'] ?? '') === (string)($turn['bound_assistant_id'] ?? '')
            && trim((string)($snapshot['markdown'] ?? '')) !== '';
    }

    /**
     * Bound full-context retransmission after a definitive no-tool final. Fresh checkpoints
     * may retry once; repeated Connector failures must not repeatedly upload every stage.
     * Retained checkpoint handoffs and ordinary task recovery keep their existing limits.
     * The ledger still independently fences active RPCs and all possible host execution.
     */
    private static function connectorNoStartRetryLimit(array $job): int
    {
        return in_array((string) ($job['operation_kind'] ?? ''), ['compact_v1', 'compact_v2'], true)
            && empty($job['previous_response_id']) ? 1 : self::CONNECTOR_NO_START_MAX_RETRIES;
    }

    /** Retry an owned no-start page only while the durable broker can prove no host execution. */
    private function retryConnectorNoStart(string $responseId, string $logicalResponseId): string
    {
        $turn = $this->chatGptTurns[$responseId] ?? null;
        if (!is_array($turn) || $logicalResponseId !== $responseId) return 'exhausted';
        try {
            $job = ResponseJob::execution($logicalResponseId);
            $retry = TaskLedger::prepareConnectorNoStartRetry(
                $logicalResponseId,
                (string) $job['prompt'],
                (string) $turn['owner_incarnation'],
                (int) $turn['fencing_version'],
                self::connectorNoStartRetryLimit($job)
            );
        } catch (GatewayError $error) {
            $this->taskFailTurn($responseId, $error->errorCode, $error->getMessage(), true);
            return 'failed';
        } catch (\Throwable) {
            $this->taskFailTurn($responseId, 'CHATGPT_CONNECTOR_RETRY_FAILED', 'Connector 安全重试状态无法提交。', true);
            return 'failed';
        }
        if (!empty($retry['exhausted'])) return 'exhausted';
        if (empty($retry['retry'])) {
            // A Connector claim may have raced with the completed DOM snapshot. Re-read broker
            // state before deciding completion instead of replaying after a possible tool start.
            $this->scheduleTaskPoll($responseId, 0.05);
            return 'waiting';
        }
        $commandId = (int) ($turn['command_id'] ?? 0);
        $this->releaseTaskSession($responseId);
        Timer::add(0.02, function () use ($responseId, $commandId): void {
            if ($commandId > 0) $this->prepareTaskSurface($responseId, $commandId, false);
        }, null, false);
        return 'retrying';
    }

    private function taskCompleteTurn(string $responseId, string $logicalResponseId, string $markdown,
        array $artifactCandidates = [], ?string $observedMarkdown = null): void
    {
        $turn = $this->chatGptTurns[$responseId] ?? null;
        if (!is_array($turn)) return;
        try { WebMessageAudit::recordIncoming($logicalResponseId, 'page_reply', $observedMarkdown ?? $markdown); }
        catch (GatewayError $error) {
            $this->taskFailTurn($responseId, $error->errorCode, $error->getMessage(), true);
            return;
        }
        if ($artifactCandidates !== []) {
            if (isset($turn['artifact_download_state'])) return;
            $this->beginTaskArtifactDownloads($responseId, $logicalResponseId, $markdown, $artifactCandidates);
            return;
        }
        $this->commitTaskCompletion($responseId, $logicalResponseId, $markdown);
    }

    /** Start a bounded, page-owned artifact download transaction before Responses can complete. */
    private function beginTaskArtifactDownloads(string $responseId, string $logicalResponseId, string $markdown, array $candidates): void
    {
        if (!$this->taskDownloadsReady) {
            $this->taskFailTurn($responseId, 'CHATGPT_ARTIFACT_DOWNLOAD_UNAVAILABLE', '浏览器未启用可验证的产物下载能力，未将网页下载卡片当成成功产物。', true);
            return;
        }
        $clean = [];
        $seen = [];
        foreach ($candidates as $candidate) {
            if (!is_array($candidate)) continue;
            $href = trim((string) ($candidate['href'] ?? ''));
            $filename = trim((string) ($candidate['filename'] ?? ''));
            $kind = trim((string) ($candidate['kind'] ?? 'anchor'));
            if ($filename === '' || ($href === '' && $kind !== 'file_card') || strlen($href) > 4000 || mb_strlen($filename) > 180) continue;
            $key = ($href !== '' ? $href : 'file-card') . "\0" . $filename;
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            $clean[] = ['href' => $href, 'filename' => $filename, 'kind' => $kind];
        }
        if ($clean === []) {
            $this->commitTaskCompletion($responseId, $logicalResponseId, $markdown);
            return;
        }
        if (count($clean) > 10) {
            $this->taskFailTurn($responseId, 'CHATGPT_ARTIFACT_COUNT_UNSAFE', '当前回答包含过多可下载产物，已停止自动下载。', true);
            return;
        }
        if (!isset($this->chatGptTurns[$responseId])) return;
        $this->chatGptTurns[$responseId]['artifact_download_state'] = 'ready';
        $this->chatGptTurns[$responseId]['artifact_candidates'] = $clean;
        $this->chatGptTurns[$responseId]['artifact_download_index'] = 0;
        $this->chatGptTurns[$responseId]['artifact_download_results'] = [];
        $this->chatGptTurns[$responseId]['artifact_final_text'] = $markdown;
        $this->chatGptTurns[$responseId]['artifact_logical_response_id'] = $logicalResponseId;
        $this->startNextTaskArtifactDownload($responseId);
    }

    private function startNextTaskArtifactDownload(string $responseId): void
    {
        $turn = $this->chatGptTurns[$responseId] ?? null;
        if (!is_array($turn)) return;
        $candidates = is_array($turn['artifact_candidates'] ?? null) ? $turn['artifact_candidates'] : [];
        $index = (int) ($turn['artifact_download_index'] ?? 0);
        if ($index >= count($candidates)) {
            $logicalResponseId = (string) ($turn['artifact_logical_response_id'] ?? $responseId);
            $finalText = (string) ($turn['artifact_final_text'] ?? '');
            $artifacts = is_array($turn['artifact_download_results'] ?? null) ? $turn['artifact_download_results'] : [];
            try { $finalText = ArtifactStore::appendMarkdown($finalText, $artifacts); }
            catch (GatewayError $error) { $this->taskFailTurn($responseId, $error->errorCode, $error->getMessage(), true); return; }
            $this->commitTaskCompletion($responseId, $logicalResponseId, $finalText);
            return;
        }
        $candidate = $candidates[$index] ?? null;
        if (!is_array($candidate)) {
            $this->taskFailTurn($responseId, 'CHATGPT_ARTIFACT_LINK_INVALID', '产物下载候选已经失效。', true);
            return;
        }
        $href = (string) ($candidate['href'] ?? '');
        $filename = (string) ($candidate['filename'] ?? '');
        $this->chatGptTurns[$responseId]['artifact_download_state'] = 'focusing';
        $this->chatGptTurns[$responseId]['artifact_expected'] = $candidate;
        $this->evaluateTask($responseId, WebModelPage::focusArtifactDownloadExpression(
            (array) $turn['baseline_users'], (array) $turn['baseline_assistants'], $href, $filename,
            (string) ($turn['bound_assistant_id'] ?? '')
        ), function ($error, $focused) use ($responseId, $index): void {
            if (!isset($this->chatGptTurns[$responseId])) return;
            if ($error !== null || !is_array($focused) || empty($focused['ok'])) {
                $this->taskFailTurn($responseId, 'CHATGPT_ARTIFACT_LINK_MISSING', '回答中的产物下载入口已经变化，未下载也未登记产物。', true);
                return;
            }
            $this->chatGptTurns[$responseId]['artifact_download_state'] = 'await_begin';
            $this->chatGptTurns[$responseId]['artifact_download_guid'] = null;
            $this->dispatchTaskKey($responseId, 'Enter', function ($keyError) use ($responseId, $index): void {
                if ($keyError !== null) {
                    $this->taskFailTurn($responseId, 'CHATGPT_ARTIFACT_DOWNLOAD_START_FAILED', '产物下载入口未能触发浏览器下载。', true);
                    return;
                }
                Timer::add(90.0, fn() => $this->checkTaskArtifactDownloadTimeout($responseId, $index), null, false);
            });
        });
    }

    private function checkTaskArtifactDownloadTimeout(string $responseId, int $index): void
    {
        $turn = $this->chatGptTurns[$responseId] ?? null;
        if (!is_array($turn) || (int) ($turn['artifact_download_index'] ?? -1) !== $index) return;
        if (!in_array((string) ($turn['artifact_download_state'] ?? ''), ['await_begin', 'downloading'], true)) return;
        $guid = (string) ($turn['artifact_download_guid'] ?? '');
        if ($guid !== '') $this->discardTaskDownload($guid);
        $this->taskFailTurn($responseId, 'CHATGPT_ARTIFACT_DOWNLOAD_TIMEOUT', 'ChatGPT 显示了产物入口，但浏览器没有在截止时间内得到完整文件。', true);
    }

    private function onTaskDownloadWillBegin(array $params): void
    {
        $guid = trim((string) ($params['guid'] ?? ''));
        if ($guid === '' || isset($this->taskDownloads[$guid])) return;
        $frameId = (string) ($params['frameId'] ?? '');
        $matches = [];
        foreach ($this->taskSessions as $sessionId => $owner) {
            $responseId = (string) ($owner['response_id'] ?? '');
            $turn = $this->chatGptTurns[$responseId] ?? null;
            if (!is_array($turn) || (string) ($turn['artifact_download_state'] ?? '') !== 'await_begin') continue;
            $mainFrame = (string) ($owner['main_frame_id'] ?? '');
            if ($frameId === '' || $mainFrame === '' || !hash_equals($mainFrame, $frameId)) continue;
            $matches[] = ['session_id' => $sessionId, 'response_id' => $responseId];
        }
        if (count($matches) !== 1) return;
        $responseId = (string) $matches[0]['response_id'];
        $turn = $this->chatGptTurns[$responseId] ?? null;
        if (!is_array($turn)) return;
        $candidate = is_array($turn['artifact_expected'] ?? null) ? $turn['artifact_expected'] : [];
        $suggested = trim((string) ($params['suggestedFilename'] ?? ''));
        if ($suggested === '') $suggested = (string) ($candidate['filename'] ?? 'artifact');
        $logicalResponseId = (string) ($turn['artifact_logical_response_id'] ?? $responseId);
        $this->taskDownloads[$guid] = [
            'response_id' => $responseId,
            'logical_response_id' => $logicalResponseId,
            'source_url' => (string) ($params['url'] ?? ($candidate['href'] ?? '')),
            'suggested_filename' => $suggested,
        ];
        $this->chatGptTurns[$responseId]['artifact_download_state'] = 'downloading';
        $this->chatGptTurns[$responseId]['artifact_download_guid'] = $guid;
    }

    private function onTaskDownloadProgress(array $params): void
    {
        $guid = trim((string) ($params['guid'] ?? ''));
        $entry = $guid !== '' ? ($this->taskDownloads[$guid] ?? null) : null;
        if (!is_array($entry)) return;
        $state = (string) ($params['state'] ?? '');
        if (!in_array($state, ['completed', 'canceled'], true)) return;
        $responseId = (string) $entry['response_id'];
        if ($state === 'canceled') {
            $this->discardTaskDownload($guid);
            $this->taskFailTurn($responseId, 'CHATGPT_ARTIFACT_DOWNLOAD_CANCELED', 'ChatGPT 产物下载被浏览器取消，未登记为成功产物。', true);
            return;
        }
        $sourcePath = ArtifactStore::downloadRoot() . DIRECTORY_SEPARATOR . $guid;
        try {
            $artifact = ArtifactStore::ingestDownload(
                (string) $entry['logical_response_id'],
                (string) $entry['source_url'],
                (string) $entry['suggested_filename'],
                $sourcePath
            );
        } catch (GatewayError $error) {
            $this->discardTaskDownload($guid);
            $this->taskFailTurn($responseId, $error->errorCode, $error->getMessage(), true);
            return;
        } catch (\Throwable) {
            $this->discardTaskDownload($guid);
            $this->taskFailTurn($responseId, 'CHATGPT_ARTIFACT_VERIFY_FAILED', '下载文件无法完成本机校验，未登记为成功产物。', true);
            return;
        }
        $this->discardTaskDownload($guid);
        if (!isset($this->chatGptTurns[$responseId])) return;
        $results = is_array($this->chatGptTurns[$responseId]['artifact_download_results'] ?? null)
            ? $this->chatGptTurns[$responseId]['artifact_download_results'] : [];
        $results[] = $artifact;
        $this->chatGptTurns[$responseId]['artifact_download_results'] = $results;
        $this->chatGptTurns[$responseId]['artifact_download_index'] = (int) ($this->chatGptTurns[$responseId]['artifact_download_index'] ?? 0) + 1;
        $this->chatGptTurns[$responseId]['artifact_download_state'] = 'ready';
        $this->chatGptTurns[$responseId]['artifact_download_guid'] = null;
        $this->chatGptTurns[$responseId]['artifact_expected'] = null;
        $this->startNextTaskArtifactDownload($responseId);
    }

    private function discardTaskDownload(string $guid): void
    {
        unset($this->taskDownloads[$guid]);
        if ($guid === '') return;
        try {
            $root = ArtifactStore::downloadRoot();
            @unlink($root . DIRECTORY_SEPARATOR . $guid);
            @unlink($root . DIRECTORY_SEPARATOR . $guid . '.crdownload');
        } catch (\Throwable) {}
    }

    /** Commit the Web model's own final answer without inserting gateway-generated chat text. */
    private function commitTaskCompletion(string $responseId, string $logicalResponseId, string $markdown): void
    {
        $turn = $this->chatGptTurns[$responseId] ?? null;
        if (!is_array($turn)) return;
        try {
            ResponseJob::complete($logicalResponseId, $markdown);
            $this->recordAdapterCompletionEvidence($logicalResponseId, $turn);
            $this->finishCommand((int) $turn['command_id'], ['response_id' => $logicalResponseId, 'status' => 'completed']);
            $this->releaseTaskSession($responseId);
        } catch (GatewayError $error) {
            $this->taskFailTurn($responseId, $error->errorCode, $error->getMessage(), true);
        } catch (\Throwable) {
            $this->taskFailTurn($responseId, 'CHATGPT_RESPONSE_COMPLETE_FAILED', 'Responses 完成状态无法提交。', true);
        }
    }

    private function recordAdapterCompletionEvidence(string $logicalResponseId, array $turn): void
    {
        try {
            AdapterCapabilities::observe('text', 'verified', ['response_id' => $logicalResponseId]);
            AdapterCapabilities::observe('temporary_chat', 'verified', ['response_id' => $logicalResponseId]);
            if (is_array($turn['requested_mode'] ?? null)) {
                AdapterCapabilities::observe('model_effort', 'verified', [
                    'response_id' => $logicalResponseId,
                    'effort' => (string) ($turn['requested_mode']['effort'] ?? ''),
                ]);
            }
            if (!empty($turn['tool_capable'])) {
                AdapterCapabilities::observe('tool_roundtrip', 'verified', ['response_id' => $logicalResponseId]);
            }
            $hasSkillFile = false;
            foreach ((array) ($turn['attachments'] ?? []) as $attachment) {
                if (!is_array($attachment)) continue;
                $kind = (string) ($attachment['kind'] ?? '');
                if ($kind === 'image') AdapterCapabilities::observe('image_input', 'verified', ['response_id' => $logicalResponseId]);
                if ($kind === 'skill') {
                    $hasSkillFile = true;
                    AdapterCapabilities::observe('skill_file', 'verified', ['response_id' => $logicalResponseId]);
                }
            }
            if (!$hasSkillFile && str_contains((string) ($turn['prompt'] ?? ''), '"origin":"codex_skill"')) {
                AdapterCapabilities::observe('skill_inline', 'verified', ['response_id' => $logicalResponseId]);
            }
            if ((array) ($turn['artifact_download_results'] ?? []) !== []) {
                AdapterCapabilities::observe('artifact_download', 'verified', ['response_id' => $logicalResponseId]);
            }
        } catch (\Throwable) {
        }
    }

    private function taskFailTurn(string $responseId, string $code, string $message, bool $unknown = false): void
    {
        $turn = $this->chatGptTurns[$responseId] ?? null;
        if (!$unknown && is_array($turn) && !empty($turn['connector_preparing'])
            && empty($turn['sent_at']) && empty($turn['accepted'])
            && in_array($code, ['CHATGPT_CONNECTOR_TRIGGER_FAILED','CHATGPT_CONNECTOR_SELECTION_FAILED','CHATGPT_CONNECTOR_STATE_UNAVAILABLE'], true)
            && (int)($turn['connector_attempt'] ?? 0) < 3 && microtime(true) < (float)$turn['deadline']) {
            $attempt = (int)($turn['connector_attempt'] ?? 0) + 1;
            $this->dispatchTaskKey($responseId, 'Escape', function () use ($responseId, $turn, $attempt): void {
                Timer::add(0.15, fn() => $this->prepareTaskConnector($responseId, (string)$turn['connector_name'], $attempt), null, false);
            });
            return;
        }
        $logicalResponseId = is_array($turn) ? (string) ($turn['logical_response_id'] ?? $responseId) : $responseId;
        if (is_array($turn) && $logicalResponseId === $responseId) {
            try {
                $repair = \app\gateway\model\ContextRecovery::repairUnsent($responseId, $code);
                if ($repair !== null) {
                    $this->releaseTaskSession($responseId);
                    $resume = function () use ($responseId, $repair): void { $this->prepareTaskSurface($responseId,(int)$repair['command_id'],false); };
                    if (!empty($repair['target_id'])) {
                        $this->taskCdp->send('Target.closeTarget',['targetId'=>$repair['target_id']],null,
                            function($closeError) use($responseId,$resume): void {
                                if($closeError!==null){$this->taskFailTurn($responseId,'CHATGPT_REPAIR_CLOSE_FAILED','原任务页未能关闭，停止恢复。');return;}
                                $resume();
                            },3.0);
                    } else $resume();
                    return;
                }
            } catch (GatewayError $repairError) { $code=$repairError->errorCode; $message=$repairError->getMessage(); }
        }
        try { ResponseJob::fail($logicalResponseId, $code, $message, $unknown ? true : null); } catch (\Throwable) {}
        if (is_array($turn)) {
            try { $this->failCommand((int) $turn['command_id'], $code, $message); } catch (\Throwable) {}
            $this->releaseTaskSession($responseId);
            return;
        }
        try {
            $job = ResponseJob::execution($responseId);
            $commandId = (int) ($job['command_id'] ?? 0);
            if ($commandId > 0) $this->failCommand($commandId, $code, $message);
        } catch (\Throwable) {}
    }

    private function releaseTaskSession(string $responseId): void
    {
        $turn = $this->chatGptTurns[$responseId] ?? null;
        foreach ($this->taskDownloads as $guid => $download) {
            if ((string) ($download['response_id'] ?? '') === $responseId) $this->discardTaskDownload((string) $guid);
        }
        unset($this->chatGptTurns[$responseId], $this->pendingResumes[$responseId]);
        if (!is_array($turn)) return;
        $sessionId = (string) ($turn['session_id'] ?? '');
        unset($this->taskSessions[$sessionId]);
        try {
            TaskLedger::detachSurface((string) $turn['surface_id'], (string) $turn['owner_incarnation'], (int) $turn['fencing_version']);
        } catch (\Throwable) {}
        if ($sessionId !== '' && $this->taskCdp->ready()) $this->detachTaskSessionRaw($sessionId);
    }

    private function detachTaskSessionRaw(string $sessionId): void
    {
        if (!$this->taskCdp->ready() || $sessionId === '') return;
        $this->taskCdp->send('Target.detachFromTarget', ['sessionId' => $sessionId], null, static function (): void {}, 2.0);
    }

    /** @param callable(?array,mixed):void $callback */
    private function evaluateTask(string $responseId, string $expression, callable $callback, float $timeout = 3.0): void
    {
        $turn = $this->chatGptTurns[$responseId] ?? null;
        if (!is_array($turn)) {
            $callback(['code' => -1, 'message' => 'TASK_SURFACE_MISSING'], null);
            return;
        }
        $sessionId = (string) $turn['session_id'];
        $this->evaluateTaskSession($sessionId, $expression, function ($error, $result) use ($responseId, $sessionId, $callback): void {
            // Late responses from a discarded staging page cannot advance or fail its replacement.
            if (($this->chatGptTurns[$responseId]['session_id'] ?? null) !== $sessionId) return;
            $callback($error, $result);
        }, $timeout);
    }

    /** @param callable(?array,mixed):void $callback */
    private function evaluateTaskSession(string $sessionId, string $expression, callable $callback, float $timeout = 3.0): void
    {
        if (!$this->taskCdp->ready() || $sessionId === '') {
            $callback(['code' => -1, 'message' => 'BROWSER_CDP_DISCONNECTED'], null);
            return;
        }
        $this->taskCdp->send('Runtime.evaluate', ['expression' => $expression, 'returnByValue' => true], $sessionId,
            function ($error, $result) use ($callback): void {
                $callback(is_array($error) ? $error : null, $result['result']['value'] ?? null);
            }, $timeout);
    }

    /**
     * On a DOM failure, briefly allow the current browser POST to finish and inspect its body.
     * Successful replies incur no body reads; no private API is called or replayed here.
     */
    private function waitForTaskSubmissionError(string $responseId): bool
    {
        $sessionId = (string) ($this->chatGptTurns[$responseId]['session_id'] ?? '');
        $owner = $this->taskSessions[$sessionId] ?? [];
        $requestId = (string) ($owner['submission_request_id'] ?? '');
        if ($requestId === '' || !empty($owner['submission_body_checked'])) return false;
        $until = (float) ($owner['submission_error_wait_until'] ?? 0);
        if ($until <= 0) $this->taskSessions[$sessionId]['submission_error_wait_until'] = $until = microtime(true) + 3.0;
        if (microtime(true) >= $until) return false;
        if (!empty($owner['submission_finished']) && empty($owner['submission_body_pending'])) {
            $this->taskSessions[$sessionId]['submission_body_pending'] = true;
            $this->taskCdp->send('Network.getResponseBody', ['requestId'=>$requestId], $sessionId,
                function ($error, $result) use ($responseId, $sessionId, $requestId): void {
                    if (($this->chatGptTurns[$responseId]['session_id'] ?? '') !== $sessionId
                        || ($this->taskSessions[$sessionId]['submission_request_id'] ?? '') !== $requestId) return;
                    $this->taskSessions[$sessionId]['submission_body_checked'] = true;
                    if ($error !== null || !is_array($result)) return;
                    $body = (string) ($result['body'] ?? '');
                    if (!empty($result['base64Encoded'])) $body = base64_decode($body, true) ?: '';
                    $code = WebModelPage::submissionSizeError($body);
                    if ($code !== null) $this->taskFailTurn($responseId, 'CHATGPT_MESSAGE_TOO_LARGE',
                        'ChatGPT 响应流明确拒绝当前单条消息（' . $code . '）；完整上下文已保留。');
                }, 2.0);
        }
        return true;
    }

    /**
     * Persist diagnostic facts under the exact current logical response and document fence.
     * Storage/ownership failures cannot change host work or turn a UI warning into model prose.
     * @param string $responseId Physical observer key, whose logical response can advance with tools.
     * @param string $state Native recovery visibility transition or observed stream failure.
     * @param array $metadata Protocol facts only; the ledger discards all unrecognized fields.
     * @return void No task-lifetime, completion or network-retry state is changed.
     */
    private function recordTaskTransportObservation(string $responseId, string $state, array $metadata): void
    {
        $turn = $this->chatGptTurns[$responseId] ?? null;
        if (!is_array($turn)) return;
        try {
            TaskLedger::browserTransportObserved((string)($turn['logical_response_id'] ?? $responseId),
                $state, $metadata, (string)($turn['owner_incarnation'] ?? ''),
                (int)($turn['fencing_version'] ?? 0), (string)($turn['document_nonce'] ?? ''));
        } catch (\Throwable) {
            // Diagnostic storage is independent of the existing execution/receipt transaction.
        }
    }

    private function onTaskEvent(array $message): void
    {
        $method = (string) ($message['method'] ?? '');
        $params = is_array($message['params'] ?? null) ? $message['params'] : [];
        if ($method === 'Browser.downloadWillBegin') { $this->onTaskDownloadWillBegin($params); return; }
        if ($method === 'Browser.downloadProgress') { $this->onTaskDownloadProgress($params); return; }
        $sessionId = (string) ($message['sessionId'] ?? '');
        if ($sessionId === '' || !isset($this->taskSessions[$sessionId])) return;
        $owner = $this->taskSessions[$sessionId];
        // Passive metrics are deliberately independent of send/retry/completion decisions.
        // They continue through closed tool Responses while this exact physical turn is alive.
        $observedTurn = $this->chatGptTurns[(string) ($owner['response_id'] ?? '')] ?? [];
        if ((float) ($observedTurn['sent_at'] ?? 0) > 0 || (int) ($observedTurn['transport_stage_sent'] ?? 0) > 0) {
            try {
                $trace = $this->taskSessions[$sessionId]['network_trace'] ??= new ResponseStreamTrace();
                $observation = $trace->observe($method, $params, (string) ($owner['main_frame_id'] ?? ''),
                    self::modelUrl(), microtime(true));
                if ($observation !== null) $this->recordTaskTransportObservation((string) ($owner['response_id'] ?? ''),
                    $observation['state'], $observation['metadata']);
            } catch (\Throwable) {
                // Observability cannot interrupt a task, its CDP events or an in-flight tool.
            }
        }
        $owner = $this->taskSessions[$sessionId];
        if ($method === 'Network.requestWillBeSent') {
            $turn = $this->chatGptTurns[(string) ($owner['response_id'] ?? '')] ?? [];
            if (((float) ($turn['sent_at'] ?? 0) > 0 || (int) ($turn['transport_stage_sent'] ?? 0) > 0)
                && WebModelPage::isConversationSubmission($params, (string) ($owner['main_frame_id'] ?? ''), self::modelUrl())) {
                $this->taskSessions[$sessionId] = array_replace($owner, [
                    'submission_request_id'=>(string) ($params['requestId'] ?? ''), 'submission_finished'=>false,
                    'submission_request_bytes'=>is_string($params['request']['postData'] ?? null)
                        ? strlen($params['request']['postData']) : null,
                    'submission_request_started_at'=>microtime(true), 'submission_finished_at'=>0.0,
                    'submission_failed'=>false,
                    'submission_canceled'=>false, 'submission_error_code'=>null, 'submission_http_status'=>null,
                    'submission_protocol'=>null,
                    'submission_body_pending'=>false, 'submission_body_checked'=>false, 'submission_error_wait_until'=>0.0,
                ]);
                $this->observeTaskUserMessagePost($sessionId, $params);
            }
            return;
        }
        if (in_array($method, ['Network.loadingFinished','Network.loadingFailed'], true)) {
            if (($params['requestId'] ?? '') !== '' && ($params['requestId'] ?? '') === ($owner['submission_request_id'] ?? '')) {
                $this->taskSessions[$sessionId]['submission_finished'] = true;
                $this->taskSessions[$sessionId]['submission_finished_at'] = microtime(true);
                $this->taskSessions[$sessionId]['submission_failed'] = $method === 'Network.loadingFailed';
                if ($method === 'Network.loadingFailed') {
                    $this->taskSessions[$sessionId]['submission_canceled'] = ($params['canceled'] ?? null) === true;
                    $this->taskSessions[$sessionId]['submission_error_code'] = $params['errorText'] ?? null;
                    $this->taskSessions[$sessionId]['submission_body_checked'] = true;
                    $this->recordTaskTransportObservation((string)($owner['response_id'] ?? ''), 'stream_failed', [
                        'request_fingerprint'=>hash('sha256', (string)$params['requestId']),
                        'error_code'=>$params['errorText'] ?? null, 'canceled'=>$params['canceled'] ?? null,
                        'protocol'=>$owner['submission_protocol'] ?? null,
                        'http_status'=>$owner['submission_http_status'] ?? null,
                        'elapsed_ms'=>(int)round(max(0.0, microtime(true)
                            - (float)($owner['submission_request_started_at'] ?? microtime(true))) * 1000),
                    ]);
                }
            }
            return;
        }
        if ($method === 'Network.responseReceived') {
            $response = is_array($params['response'] ?? null) ? $params['response'] : [];
            $url = (string) ($response['url'] ?? '');
            if (!WebModelPage::isBackendUrl($url, self::modelUrl())) return;
            if (($params['requestId'] ?? '') !== '' && ($params['requestId'] ?? '') === ($owner['submission_request_id'] ?? '')) {
                $this->taskSessions[$sessionId]['submission_protocol'] = $response['protocol'] ?? null;
                $this->taskSessions[$sessionId]['submission_http_status'] = (int) round((float) ($response['status'] ?? 0));
                $this->recordTaskTransportObservation((string) ($owner['response_id'] ?? ''), 'response_http', [
                    'request_bytes' => $owner['submission_request_bytes'] ?? null,
                    'protocol' => $response['protocol'] ?? null,
                    'http_status' => (int) round((float) ($response['status'] ?? 0)),
                    'request_fingerprint' => hash('sha256', (string) $params['requestId']),
                ]);
            }
            $status = (int) round((float) ($response['status'] ?? 0));
            if (($params['requestId'] ?? '') !== '' && ($params['requestId'] ?? '') === ($owner['submission_request_id'] ?? '')
                && WebModelPage::isBackendMessageTooLarge($url, $status, self::modelUrl())) {
                $responseId = (string) ($owner['response_id'] ?? '');
                if ($responseId !== '') {
                    $this->taskFailTurn(
                        $responseId,
                        'CHATGPT_MESSAGE_TOO_LARGE',
                        'ChatGPT 后端拒绝当前单条消息（HTTP 413）；已停止等待并保留任务上下文。'
                    );
                }
                return;
            }
            if ($status >= 200 && $status < 400) {
                $this->taskSessions[$sessionId]['security_challenge_active'] = false;
                $this->taskSessions[$sessionId]['security_challenge_path'] = null;
                $responseId = (string) ($owner['response_id'] ?? '');
                if ($responseId !== '' && isset($this->chatGptTurns[$responseId])) {
                    $this->chatGptTurns[$responseId]['security_challenge_active'] = false;
                    $this->chatGptTurns[$responseId]['security_challenge_path'] = null;
                }
                return;
            }
            $headers = is_array($response['headers'] ?? null) ? $response['headers'] : [];
            if (!WebModelPage::isBackendSecurityChallenge($url, $status, $headers, self::modelUrl())) return;
            $wasActive = !empty($owner['security_challenge_active']);
            $path = (string) (parse_url($url, PHP_URL_PATH) ?: '/backend-api/');
            $this->taskSessions[$sessionId]['security_challenge_active'] = true;
            $this->taskSessions[$sessionId]['security_challenge_path'] = $path;
            $responseId = (string) ($owner['response_id'] ?? '');
            if ($responseId !== '' && isset($this->chatGptTurns[$responseId])) {
                $this->chatGptTurns[$responseId]['security_challenge_active'] = true;
                $this->chatGptTurns[$responseId]['security_challenge_path'] = $path;
            }
            if (!$wasActive) {
                try {
                    TaskLedger::observeSecurityChallenge(
                        (string) $owner['surface_id'],
                        (string) $owner['owner_incarnation'],
                        (int) $owner['fencing_version'],
                        $responseId,
                        $url
                    );
                } catch (\Throwable) {}
            }
            return;
        }
        if ($method !== 'Page.frameNavigated') return;
        $frame = $params['frame'] ?? null;
        if (!is_array($frame) || !empty($frame['parentId'])) return;
        unset($this->taskSessions[$sessionId]['network_trace']);
        $this->taskSessions[$sessionId]['main_frame_id'] = (string) ($frame['id'] ?? '');
        try {
            TaskLedger::documentChanged(
                (string) $owner['surface_id'],
                (string) $owner['owner_incarnation'],
                (int) $owner['fencing_version'],
                (string) ($frame['url'] ?? '')
            );
        } catch (\Throwable) {}
    }

    /** The page's exact user-message POST can unblock MCP without claiming DOM acceptance. */
    private function observeTaskUserMessagePost(string $sessionId, array $params): void
    {
        $owner = $this->taskSessions[$sessionId] ?? null;
        if (!is_array($owner)) return;
        $responseId = (string) ($owner['response_id'] ?? '');
        $turn = $this->chatGptTurns[$responseId] ?? null;
        $requestId = (string) ($params['requestId'] ?? '');
        if (!is_array($turn) || $requestId === ''
            || (string) ($owner['submission_request_id'] ?? '') !== $requestId
            || (string) ($turn['session_id'] ?? '') !== $sessionId
            || (string) ($turn['surface_id'] ?? '') !== (string) ($owner['surface_id'] ?? '')
            || (string) ($turn['logical_response_id'] ?? $responseId) !== $responseId
            || empty($turn['tool_capable']) || empty($turn['sent_at']) || !empty($turn['accepted'])
            || !empty($turn['continuation_sending']) || !empty($turn['continuation_pending'])
            || !empty($turn['compaction_phase'])
            || parse_url((string) ($params['request']['url'] ?? ''), PHP_URL_PATH) !== '/backend-api/f/conversation') return;
        preg_match_all('/(?<![A-Za-z0-9_-])turn_[A-Za-z0-9_-]{32}(?![A-Za-z0-9_-])/',
            (string) ($turn['prompt'] ?? ''), $tokens);
        if (count($tokens[0]) !== 1) return;
        $currentToken = (string) $tokens[0][0];
        $observe = function (string $postData) use ($sessionId, $responseId, $requestId, $params, $currentToken): void {
            $owner = $this->taskSessions[$sessionId] ?? null;
            $turn = $this->chatGptTurns[$responseId] ?? null;
            if (!is_array($owner) || !is_array($turn)
                || (string) ($owner['submission_request_id'] ?? '') !== $requestId
                || (string) ($turn['session_id'] ?? '') !== $sessionId
                || !empty($turn['continuation_sending']) || !empty($turn['continuation_pending'])
                || !empty($turn['compaction_phase'])
                || !WebModelPage::isTaskUserMessageSubmission($params,
                    (string) ($owner['main_frame_id'] ?? ''), self::modelUrl(), $postData, $currentToken)) return;
            try {
                ToolBroker::markSentByObservedSubmission($responseId,
                    (string) $turn['surface_id'], (string) $turn['target_id'], $sessionId,
                    (string) $turn['document_nonce'], (string) $turn['owner_incarnation'],
                    (int) $turn['fencing_version'], $currentToken);
            } catch (\Throwable) {
                // The DOM observer can still independently confirm the accepted turn.
            }
        };
        $postData = $params['request']['postData'] ?? null;
        if (is_string($postData) && $postData !== '') {
            $observe($postData);
            if (WebModelPage::isTaskUserMessageSubmission($params,
                (string) ($owner['main_frame_id'] ?? ''), self::modelUrl(), $postData, $currentToken)) return;
        }
        $this->taskCdp->send('Network.getRequestPostData', ['requestId' => $requestId], $sessionId,
            function ($error, $result) use ($observe): void {
                if ($error === null && is_string($result['postData'] ?? null)) $observe((string) $result['postData']);
            }, 3.0);
    }

    /**
     * Close only retired task targets and acknowledge closure from a complete physical inventory.
     * Failed requests or untrustworthy inventories preserve ownership for the next cleanup tick.
     * @param array $state Existing private browser state with its owned debug port.
     */
    private function cleanupRetiredTaskSurfaces(array $state): void
    {
        $port = (int) ($state['debug_port'] ?? 0);
        if ($port < 1) return;
        try {
            $live = $this->discoverPhysicalPageIdsIfAvailable($port);
            if ($live === null) return;
            TaskLedger::reconcilePhysicalTargets(array_keys($live));
            $retired = TaskLedger::retiredTargets();
            if ($retired === []) return;
            foreach ($retired as $targetId => $surfaceId) {
                if (isset($live[$targetId])) {
                    $this->devtoolsRequest($port, '/json/close/' . rawurlencode($targetId));
                    $after = $this->discoverPhysicalPageIdsIfAvailable($port);
                    if ($after === null || isset($after[$targetId])) continue;
                }
                TaskLedger::targetClosed($surfaceId);
                $this->clearMonitorScreenshots($surfaceId);
            }
        } catch (\Throwable) {}
    }

    /** Update the embedded document fence without clearing an independent native account observation. */
    private function onEvent(array $message): void
    {
        $method = (string) ($message['method'] ?? '');
        $params = is_array($message['params'] ?? null) ? $message['params'] : [];
        try {
            if ($method === 'Page.frameNavigated') {
                $frame = $params['frame'] ?? null;
                if (is_array($frame) && empty($frame['parentId'])) {
                    $state = BrowserState::state();
                    BrowserState::write([
                        'url' => mb_substr((string) ($frame['url'] ?? ''), 0, 2000),
                        'document_nonce' => bin2hex(random_bytes(12)),
                        ...(NativeBrowserWindow::targetId($state) === null
                            ? ['chatgpt_json' => null, 'chatgpt_observed_at' => 0] : []),
                    ]);
                    $this->refreshTabs();
                }
            } elseif (in_array($method, ['Page.domContentEventFired', 'Page.loadEventFired'], true)) {
                $this->refreshMetadata();
                $this->refreshChatGptCapability();
            }
        } catch (\Throwable) {
        }
    }

    private function finishCommand(int $id, array $result): void
    {
        unset($this->connectorProbeAttempts[$id], $this->connectorProbeGenerations[$id], $this->connectorProbeCdpWaitSince[$id]);
        BrowserState::finish($id, true, $result);
        $this->commandBusy = false;
    }

    /** Persist a command failure and optional sanitized details, then release its worker slot. */
    private function failCommand(int $id, string $code, string $message, array $details = []): void
    {
        unset($this->connectorProbeAttempts[$id], $this->connectorProbeGenerations[$id], $this->connectorProbeCdpWaitSince[$id]);
        BrowserState::finish($id, false, ['code' => $code, 'message' => $message] + $details);
        $this->commandBusy = false;
    }

    /** Retry a pre-ready CDP timeout within the original startup deadline, without relaunching Chrome. */
    private function connectionFailed(string $code, ?array $protocolError = null): void
    {
        if ($this->pendingStartCommand !== null && (int) ($protocolError['code'] ?? 0) === -2
            && microtime(true) < $this->startDeadline) {
            $this->connecting = false;
            $this->switchingTarget = false;
            $this->recordStartupStage('cdp_initialization_retry');
            BrowserState::write(['status'=>'starting', 'last_error'=>null, 'session_id'=>null]);
            $this->cdp->close($code);
            return;
        }
        BrowserState::write(['status' => 'error', 'last_error' => $code, 'session_id' => null]);
        if ($this->pendingStartCommand !== null) {
            $id = $this->pendingStartCommand;
            $this->pendingStartCommand = null;
            $detail = '';
            if ($protocolError !== null) {
                $detail = 'CDP ' . (int) ($protocolError['code'] ?? 0) . ': ' . mb_substr((string) ($protocolError['message'] ?? ''), 0, 160);
                if (!empty($protocolError['detail'])) $detail .= ' · ' . mb_substr((string) $protocolError['detail'], 0, 200);
            }
            BrowserState::finish($id, false, ['code' => $code, 'message' => '浏览器页面控制初始化失败。', 'detail' => trim($detail)]);
            $this->commandBusy = false;
        }
        $this->cdp->close($code);
    }

    /** Map a native key/chord to CDP parameters; Command+A uses macOS's Meta modifier. */
    private static function keyParams(string $key): array
    {
        $map = [
            'Enter' => ['key' => 'Enter', 'code' => 'Enter', 'windowsVirtualKeyCode' => 13],
            'Tab' => ['key' => 'Tab', 'code' => 'Tab', 'windowsVirtualKeyCode' => 9],
            'Backspace' => ['key' => 'Backspace', 'code' => 'Backspace', 'windowsVirtualKeyCode' => 8],
            'Control+A' => ['key' => 'a', 'code' => 'KeyA', 'windowsVirtualKeyCode' => 65, 'modifiers' => 2],
            'Command+A' => ['key' => 'a', 'code' => 'KeyA', 'windowsVirtualKeyCode' => 65, 'modifiers' => 4],
            'Escape' => ['key' => 'Escape', 'code' => 'Escape', 'windowsVirtualKeyCode' => 27],
            'ArrowUp' => ['key' => 'ArrowUp', 'code' => 'ArrowUp', 'windowsVirtualKeyCode' => 38],
            'ArrowDown' => ['key' => 'ArrowDown', 'code' => 'ArrowDown', 'windowsVirtualKeyCode' => 40],
            'ArrowLeft' => ['key' => 'ArrowLeft', 'code' => 'ArrowLeft', 'windowsVirtualKeyCode' => 37],
            'ArrowRight' => ['key' => 'ArrowRight', 'code' => 'ArrowRight', 'windowsVirtualKeyCode' => 39],
        ];
        return $map[$key] ?? ['key' => mb_substr($key, 0, 1), 'text' => mb_substr($key, 0, 1)];
    }

    private static function identity(): string
    {
        return mb_substr((string) (getenv('USERNAME') ?: getenv('USER') ?: 'local-user'), 0, 120);
    }

    private static function modelUrl(): string
    {
        $targets = (array) Config::get('gateway', 'probe_targets');
        $url = trim((string) ($targets['model_site']['url'] ?? 'https://chatgpt.com/'));
        $parts = parse_url($url);
        if (!$parts || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)) return 'https://chatgpt.com/';
        return $url;
    }

    /** Task-owned browser tabs always use the isolated Temporary Chat surface. */
    private static function taskModelUrl(): string
    {
        return WebModelPage::temporaryChatUrl(self::modelUrl());
    }

}
