<?php
declare(strict_types=1);
namespace app\gateway\controller;

use RC\Config;
use RC\Request;
use app\gateway\database\Schema;
use app\gateway\model\BrowserState;
use app\gateway\model\BrowserLoginLifecycle;
use app\gateway\model\GatewayError;
use app\gateway\model\GatewaySettings;
use app\gateway\model\NetworkSettings;
use app\gateway\model\ResponseJob;
use app\gateway\model\TaskLedger;
use app\gateway\model\TunnelRuntime;
use support\browser\BrowserProcess;
use support\browser\BrowserRuntime;
use support\browser\ChromeForTestingRuntime;

/** Local private-browser setup API. Browser I/O stays queued to the dedicated worker. */
final class Browser
{
    /**
     * Read browser setup and workspace state, optionally confirming up to five exact page closes.
     * @param Request $request Local console request; closing_surfaces is an optional comma-separated ID list.
     * @return mixed Current safe browser state and bounded operator-facing projections.
     */
    public function read(Request $request)
    {
        return $this->respond($request, function () use ($request): array {
            $closingSurfaceIds = [];
            $requestedCloses = $request->get('closing_surfaces');
            if ($requestedCloses !== null) {
                if (!is_string($requestedCloses)) {
                    throw new GatewayError('INVALID_BROWSER_SURFACE', '任务页面标识无效。', 400);
                }
                $requestedCloses = $this->text($requestedCloses, 1, 200, '任务页面');
                $closingSurfaceIds = explode(',', $requestedCloses);
                if (count($closingSurfaceIds) > TaskLedger::MAX_SURFACES
                    || count(array_unique($closingSurfaceIds)) !== count($closingSurfaceIds)) {
                    throw new GatewayError('INVALID_BROWSER_SURFACE', '任务页面标识无效。', 400);
                }
                foreach ($closingSurfaceIds as $surfaceId) {
                    if (!preg_match('/\Asurf_[a-f0-9]{24}\z/D', $surfaceId)) {
                        throw new GatewayError('INVALID_BROWSER_SURFACE', '任务页面标识无效。', 400);
                    }
                }
            }
            $choices = [];
            $hasBundled = false;
            foreach (BrowserProcess::detected() as $browser) {
                $id = (string) ($browser['id'] ?? $browser['kind']);
                $choices[] = ['id' => $id, 'label' => $browser['label']];
                if ($id === 'bundled') $hasBundled = true;
            }
            // Keep an explicit bundled choice available before first install so a system-browser host can test/force CfT.
            if (!$hasBundled && (ChromeForTestingRuntime::platform()['cft_platform'] ?? null) !== null) {
                $choices[] = ['id' => 'bundled', 'label' => 'Chrome for Testing（项目私有）'];
            }
            $targets = (array) Config::get('gateway', 'probe_targets');
            $defaultUrl = (string) ($targets['model_site']['url'] ?? 'https://chatgpt.com/');
            $state = BrowserState::snapshot();
            $rawState = BrowserState::state();
            $runtime = ChromeForTestingRuntime::status();
            $monitor = TaskLedger::monitor();
            $monitors = TaskLedger::monitors($closingSurfaceIds);
            $networkState = NetworkSettings::state();
            $saved = NetworkSettings::revision((int) $networkState['saved_revision']);
            $page = ResponseJob::pageSnapshot($rawState);
            $account = 'not_checked';
            if ($state['status'] === 'starting') $account = 'checking';
            elseif ($page['fresh'] && $page['login_required']) $account = 'login_required';
            elseif (ResponseJob::accountReady($rawState, $page)) $account = 'ready';
            elseif ($state['status'] === 'ready') $account = 'checking';
            elseif ($state['setup_completed']) $account = 'needs_check';
            return $state + [
                'browsers' => $choices,
                'default_url' => $defaultUrl,
                'setup' => [
                    'network' => $saved['mode'] === 'unconfigured' ? 'required' : 'ready',
                    'runtime' => $runtime,
                    'account' => $account,
                    'responses' => $account === 'ready' ? 'ready' : 'waiting',
                ],
                'monitor' => $monitor,
                'monitors' => $monitors,
                'close_states' => TaskLedger::monitorClosures($closingSurfaceIds),
                'native_window' => \support\browser\NativeBrowserWindow::snapshot($rawState),
            ];
        });
    }

    /** @return mixed Install/verify the application browser component without opening ChatGPT. */
    public function install(Request $request)
    {
        return $this->respond($request, function () use ($request): array {
            $body = $this->body($request, ['revision']);
            try {
                $revision = (int) validator()->check($body['revision'] ?? 0, ['rule' => 'pint', 'name' => '网络配置版本']);
            } catch (\Throwable) {
                throw new GatewayError('INVALID_BROWSER_INSTALL', '请选择有效的网络配置。');
            }
            NetworkSettings::revision($revision);
            return ['id' => BrowserState::enqueue('install', ['revision' => $revision], false), 'status' => 'queued'];
        }, 202);
    }

    /** @return mixed Open the visible sign-in prerequisite using the application-owned profile. */
    public function login(Request $request)
    {
        return $this->respond($request, function () use ($request): array {
            $body = $this->body($request, ['revision']);
            try {
                $revision = (int) validator()->check($body['revision'] ?? 0, ['rule' => 'pint', 'name' => '网络配置版本']);
            } catch (\Throwable) {
                throw new GatewayError('INVALID_BROWSER_START', '请选择有效的网络配置。');
            }
            NetworkSettings::revision($revision);
            if (BrowserState::profileResetPending()) {
                throw new GatewayError('BROWSER_PROFILE_RESET_PENDING', '浏览器资料正在清空，请等待操作结束。', 409);
            }
            $state = BrowserState::state();
            if ($state['status'] === 'ready') {
                BrowserState::setAutoStart(true);
                // A running private browser may be on Google or the connector settings page.
                // Explicit login means navigate its manual setup tab, never a task-owned target.
                $targets = (array) Config::get('gateway', 'probe_targets');
                $url = $this->url($targets['model_site']['url'] ?? 'https://chatgpt.com/');
                return ['id'=>BrowserState::enqueue('navigate', ['url'=>$url], true), 'status'=>'queued'];
            }
            if ($state['status'] === 'starting') {
                BrowserState::setAutoStart(true);
                $pending = SDB(null, 'medoo', 'sqlite')->table('gateway_browser_commands')->where('action', 'start')
                    ->where('status', 'IN', ['queued', 'running'])->order([['id', 'ASC']])->find('id');
                if ($pending === false) throw new GatewayError('BROWSER_STATE_UNAVAILABLE', '浏览器启动状态暂不可读取。', 503);
                // Return the real launch ID; a reattached worker can instead be observed by
                // the console's read-only readiness wait. Neither path launches another Chrome.
                return ['id'=>is_array($pending) ? (int) $pending['id'] : null, 'status'=>'starting'];
            }
            if ($state['status'] !== 'stopped') {
                throw new GatewayError('BROWSER_RESTART_REQUIRED', '浏览器状态需要恢复，请重试登录检查。', 409);
            }
            $id = BrowserState::enqueue('start', [
                'revision' => $revision,
                'browser' => 'bundled',
            ], false);
            BrowserState::setAutoStart(true);
            return ['id' => $id, 'status' => 'queued'];
        }, 202);
    }

    /** Verify the exact configured ChatGPT connector in the signed-in application browser. */
    public function verifyConnector(Request $request)
    {
        return $this->respond($request, function () use ($request): array {
            $this->body($request, []);
            $settings = GatewaySettings::snapshot();
            $tunnel = TunnelRuntime::snapshot();
            if (!$settings['mcp_enabled'] || !$tunnel['configured'] || $tunnel['status'] !== 'ready') {
                throw new GatewayError('TUNNEL_NOT_READY', '请先完成 Tunnel ID、API Key 并连接工具隧道。', 409);
            }
            $state = BrowserState::snapshot();
            if ($state['status'] !== 'ready') {
                throw new GatewayError('BROWSER_NOT_READY', '请先完成 ChatGPT 登录。', 409);
            }
            return [
                'id' => BrowserState::enqueue('verify_connector', [
                    'connector_name' => (string) $settings['connector_name'],
                ], true),
                'status' => 'queued',
            ];
        }, 202);
    }

    /** @return mixed Queue browser start using one immutable network revision. */
    public function start(Request $request)
    {
        return $this->respond($request, function () use ($request): array {
            $body = $this->body($request, ['revision', 'browser']);
            try {
                $data = validator()->input($body + ['browser' => 'auto'], [
                    'revision' => ['rule' => 'pint', 'name' => '网络配置版本'],
                    'browser' => ['rule' => 'alpha', 'len' => [1, 12], 'name' => '浏览器'],
                ]);
            } catch (\Throwable) {
                throw new GatewayError('INVALID_BROWSER_START', '请选择有效的网络配置和浏览器。');
            }
            if (!in_array($data['browser'], ['auto', 'bundled'], true)) {
                throw new GatewayError('INVALID_BROWSER_START', '浏览器选择无效。');
            }
            NetworkSettings::revision((int) $data['revision']);
            return ['id' => BrowserState::enqueue('start', [
                'revision' => (int) $data['revision'],
                'browser' => $data['browser'],
            ], false), 'status' => 'queued'];
        }, 202);
    }

    /** @return mixed Queue stop; worker kills only a process whose dedicated profile/CDP markers match. */
    public function stop(Request $request)
    {
        return $this->respond($request, function (): array {
            BrowserState::setAutoStart(false);
            return ['id' => BrowserState::enqueue('stop', [], false), 'status' => 'queued'];
        }, 202);
    }

    /** Queue a full reset of only this application's private Chrome login profiles. */
    public function resetProfile(Request $request)
    {
        return $this->respond($request, function () use ($request): array {
            $body = $this->body($request, ['reminder_id']);
            $payload = [];
            if (array_key_exists('reminder_id', $body)) {
                $payload['login_reminder_id'] = $this->reminderId($body['reminder_id']);
                BrowserLoginLifecycle::pollCodexIdentity();
            }
            return ['id' => BrowserState::enqueue('reset_profile', $payload, false), 'status' => 'queued'];
        }, 202);
    }

    /** Read optional renewal reminders without changing login, model routing or task admission. */
    public function loginReminder(Request $request)
    {
        return $this->respond($request, function (): array {
            $readable = BrowserLoginLifecycle::pollCodexIdentity();
            return BrowserLoginLifecycle::snapshot() + ['codex_identity_readable' => $readable];
        });
    }

    /** Snooze exactly the reminder displayed by the console; never acknowledges the underlying account change. */
    public function snoozeLoginReminder(Request $request)
    {
        return $this->respond($request, function () use ($request): array {
            $body = $this->body($request, ['reminder_id']);
            BrowserLoginLifecycle::snooze($this->reminderId($body['reminder_id'] ?? null));
            return BrowserLoginLifecycle::snapshot();
        });
    }

    /** Validate the opaque confirmation token with the framework's existing input validator. */
    private function reminderId(mixed $value): string
    {
        try { return (string) validator()->check($value, ['rule' => 'alnum', 'len' => [64, 64], 'name' => '登录提醒']); }
        catch (\Throwable) { throw new GatewayError('INVALID_LOGIN_REMINDER', '登录提醒编号无效。', 400); }
    }

    /** @return mixed Transfer manual/automation ownership and invalidate stale commands. */
    public function lease(Request $request)
    {
        return $this->respond($request, function () use ($request): array {
            $body = $this->body($request, ['mode']);
            try {
                $mode = validator()->check($body['mode'] ?? '', ['rule' => 'alpha', 'len' => [1, 16], 'name' => '控制模式']);
            } catch (\Throwable) {
                throw new GatewayError('INVALID_BROWSER_LEASE', '浏览器控制模式无效。');
            }
            return ['surface_generation' => BrowserState::setLease((string) $mode), 'mode' => $mode];
        });
    }

    /** Show/hide only the private Chrome's separate native setup window; old embedded controls remain independent. */
    public function nativeWindow(Request $request)
    {
        return $this->respond($request, function () use ($request): array {
            $body = $this->body($request, ['mode']);
            try { $mode = validator()->check($body['mode'] ?? '', ['rule' => 'alpha', 'len' => [1, 4], 'name' => '原生窗口操作']); }
            catch (\Throwable) { throw new GatewayError('INVALID_BROWSER_ACTION', '原生窗口操作无效。'); }
            if (!in_array($mode, ['show', 'hide'], true)) throw new GatewayError('INVALID_BROWSER_ACTION', '原生窗口操作无效。');
            if (BrowserState::state()['status'] !== 'ready') throw new GatewayError('BROWSER_NOT_READY', '请先打开登录页面，等待私有浏览器就绪。', 409);
            return ['id' => BrowserState::enqueue('native_window', ['mode' => $mode], false), 'status' => 'queued'];
        }, 202);
    }

    /** @return mixed Queue one bounded manual interaction against the current page generation. */
    public function action(Request $request)
    {
        return $this->respond($request, function () use ($request): array {
            $body = $this->body($request, ['action', 'url', 'x', 'y', 'delta_x', 'delta_y', 'text', 'key', 'target_id']);
            try {
                $action = validator()->check($body['action'] ?? '', ['rule' => 'alnum', 'options' => ['attach' => ['_']], 'len' => [1, 20], 'name' => '浏览器操作']);
            } catch (\Throwable) {
                throw new GatewayError('INVALID_BROWSER_ACTION', '浏览器操作无效。');
            }
            $payload = match ($action) {
                'navigate' => ['url' => $this->url($body['url'] ?? '')],
                'click' => ['x' => $this->number($body['x'] ?? null, 0, 1280, '横坐标'), 'y' => $this->number($body['y'] ?? null, 0, 720, '纵坐标')],
                'scroll' => [
                    'x' => $this->number($body['x'] ?? 640, 0, 1280, '横坐标'),
                    'y' => $this->number($body['y'] ?? 360, 0, 720, '纵坐标'),
                    'delta_x' => $this->number($body['delta_x'] ?? 0, -4000, 4000, '水平滚动'),
                    'delta_y' => $this->number($body['delta_y'] ?? 0, -4000, 4000, '垂直滚动'),
                ],
                'text' => ['text' => $this->text($body['text'] ?? '', 1, 4000, '输入文字')],
                'key' => ['key' => $this->key($body['key'] ?? '')],
                'new_tab' => ['url' => isset($body['url']) && $body['url'] !== '' ? $this->url($body['url']) : 'about:blank'],
                'switch_tab' => ['target_id' => $this->token($body['target_id'] ?? '', '标签页')],
                'close_tab' => ['target_id' => $this->token($body['target_id'] ?? '', '标签页')],
                'reload' => [],
                default => throw new GatewayError('INVALID_BROWSER_ACTION', '浏览器操作不受支持。'),
            };
            return ['id' => BrowserState::enqueue((string) $action, $payload, !in_array($action, ['new_tab', 'switch_tab', 'close_tab'], true)), 'status' => 'queued'];
        }, 202);
    }

    /** @return mixed One persisted command result. */
    public function command(Request $request, $id)
    {
        return $this->respond($request, function () use ($request, $id): array {
            try {
                $id = (int) validator()->check($id, ['rule' => 'pint', 'name' => '操作编号']);
            } catch (\Throwable) {
                throw new GatewayError('INVALID_INPUT', '浏览器操作编号无效。');
            }
            $rows = $request->AF([
                'type' => 'list', 'table' => 'gateway_browser_commands', 'where' => ['id', '=', $id], 'limit' => 1,
                'db' => $request->SDB('medoo', 'sqlite'), 'name' => '浏览器操作',
                'fields' => 'id,action,status,result_json,created_at,started_at,finished_at',
            ])->handle();
            if (!is_array($rows)) throw new GatewayError('BROWSER_STATE_UNAVAILABLE', '浏览器操作结果暂不可读取。', 503);
            $row = $rows[0] ?? null;
            if ($row === null) throw new GatewayError('BROWSER_COMMAND_NOT_FOUND', '浏览器操作记录不存在。', 404);
            $row['result'] = $row['result_json'] === null ? null : json_decode($row['result_json'], true, 32, JSON_THROW_ON_ERROR);
            unset($row['result_json']);
            return $row;
        });
    }

    /** @return mixed Latest in-memory-style screenshot file, guarded by the same local middleware. */
    public function screenshot(Request $request)
    {
        try {
            Schema::initialize();
            BrowserRuntime::noteScreenshotObserver();
            $state = BrowserState::state();
            $path = BrowserRuntime::screenshotPath();
            if ($state['status'] !== 'ready' || (int) $state['screenshot_sequence'] < 1 || !is_file($path)) {
                return $request->json(['code' => 'BROWSER_SCREENSHOT_PENDING', 'message' => '页面画面尚未就绪。'])->withStatus(404);
            }
            $bytes = @file_get_contents($path);
            if ($bytes === false || strlen($bytes) > 6 * 1024 * 1024) {
                return $request->json(['code' => 'BROWSER_SCREENSHOT_UNAVAILABLE', 'message' => '页面画面暂不可读取。'])->withStatus(503);
            }
            return $request->response($bytes, 200, [
                'Content-Type' => 'image/jpeg',
                'Content-Length' => (string) strlen($bytes),
                'X-Browser-Sequence' => (string) $state['screenshot_sequence'],
            ]);
        } catch (\Throwable) {
            return $request->json(['code' => 'BROWSER_SCREENSHOT_UNAVAILABLE', 'message' => '页面画面暂不可读取。'])->withStatus(503);
        }
    }

    /** Screenshot the task-owned ChatGPT surface currently being viewed in the console. */
    public function monitorScreenshot(Request $request)
    {
        try {
            Schema::initialize();
            $browser = BrowserState::snapshot();
            if ($browser['status'] !== 'ready' || !$browser['worker_ready']) {
                return $request->json(['code' => 'BROWSER_MONITOR_PENDING', 'message' => '受控浏览器尚未就绪。'])->withStatus(404);
            }
            $requested = $request->get('surface_id');
            if ($requested === null || $requested === '') {
                // Keep the original no-parameter endpoint bound to the latest active turn.
                $monitor = TaskLedger::monitor();
                if (empty($monitor['active'])) {
                    return $request->json(['code' => 'BROWSER_MONITOR_PENDING', 'message' => '当前没有可监看的自动化页面。'])->withStatus(404);
                }
                $surfaceId = (string) $monitor['surface_id'];
            } else {
                try {
                    $surfaceId = (string) validator()->check($requested, [
                        'rule' => 'alnum', 'options' => ['attach' => ['_']], 'len' => 29, 'name' => '任务页面',
                    ]);
                } catch (\Throwable) {
                    return $request->json(['code' => 'INVALID_BROWSER_SURFACE', 'message' => '任务页面标识无效。'])->withStatus(400);
                }
                if (!preg_match('/\Asurf_[a-f0-9]{24}\z/D', $surfaceId)) {
                    return $request->json(['code' => 'INVALID_BROWSER_SURFACE', 'message' => '任务页面标识无效。'])->withStatus(400);
                }
            }
            $surface = TaskLedger::monitorSurfaceTarget($surfaceId);
            if (!is_array($surface) || $surface['target_id'] === '') {
                return $request->json(['code' => 'BROWSER_MONITOR_PENDING', 'message' => '当前没有可监看的自动化页面。'])->withStatus(404);
            }
            if ($requested === null || $requested === '') BrowserRuntime::noteMonitorObserver();
            else BrowserRuntime::noteMonitorObserver($surfaceId);
            $path = BrowserRuntime::monitorScreenshotPath($surfaceId, (int) $surface['generation']);
            if (!is_file($path)) {
                return $request->json(['code' => 'BROWSER_MONITOR_PENDING', 'message' => '任务页面画面尚未就绪。'])->withStatus(404);
            }
            clearstatcache(true, $path);
            $sequence = @filemtime($path);
            if (!is_int($sequence) || $sequence < time() - 30) {
                return $request->json(['code' => 'BROWSER_MONITOR_PENDING', 'message' => '任务页面画面正在更新。'])->withStatus(404);
            }
            $bytes = @file_get_contents($path);
            if ($bytes === false || strlen($bytes) > 6 * 1024 * 1024) {
                return $request->json(['code' => 'BROWSER_MONITOR_UNAVAILABLE', 'message' => '自动化页面画面暂不可读取。'])->withStatus(503);
            }
            $current = TaskLedger::monitorSurfaceTarget($surfaceId);
            if (!is_array($current) || $current['target_id'] !== $surface['target_id']
                || (int) $current['generation'] !== (int) $surface['generation']) {
                return $request->json(['code' => 'BROWSER_MONITOR_PENDING', 'message' => '任务页面画面正在切换。'])->withStatus(404);
            }
            return $request->response($bytes, 200, [
                'Content-Type' => 'image/jpeg',
                'Content-Length' => (string) strlen($bytes),
                'Cache-Control' => 'no-store',
                'X-Browser-Surface' => $surfaceId,
                'X-Browser-Sequence' => (string) $sequence,
            ]);
        } catch (\Throwable) {
            return $request->json(['code' => 'BROWSER_MONITOR_UNAVAILABLE', 'message' => '自动化页面画面暂不可读取。'])->withStatus(503);
        }
    }

    /** Release one completed task page without cancelling the durable Codex conversation. */
    public function closeTaskPage(Request $request)
    {
        return $this->respond($request, function () use ($request): array {
            $body = $this->body($request, ['surface_id']);
            $surfaceId = $body['surface_id'] ?? null;
            if (!is_string($surfaceId) || !preg_match('/\Asurf_[a-f0-9]{24}\z/D', $surfaceId)) {
                throw new GatewayError('INVALID_BROWSER_SURFACE', '任务页面标识无效。', 400);
            }
            return TaskLedger::retireIdleTaskSurface($surfaceId);
        });
    }

    /** Decode only object JSON and reject undeclared fields. */
    private function body(Request $request, array $allowed): array
    {
        try {
            $object = json_decode((string) $request->rawBody(), false, 16, JSON_THROW_ON_ERROR);
            if (!$object instanceof \stdClass) throw new \RuntimeException('Object required');
            $data = get_object_vars($object);
            if (array_diff(array_keys($data), $allowed)) throw new GatewayError('INVALID_FIELD', '请求包含不支持的字段。');
            return $data;
        } catch (GatewayError $error) {
            throw $error;
        } catch (\Throwable) {
            throw new GatewayError('INVALID_JSON', '请求正文必须是有效的 JSON 对象。');
        }
    }

    private function url(mixed $value): string
    {
        $value = $this->text($value, 1, 2048, '网址');
        try {
            validator()->check($value, ['rule' => 'url', 'name' => '网址']);
        } catch (\Throwable) {
            throw new GatewayError('INVALID_BROWSER_URL', '请输入 http 或 https 网址。');
        }
        $parts = parse_url($value);
        if (!$parts || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true) || isset($parts['user']) || isset($parts['pass'])) {
            throw new GatewayError('INVALID_BROWSER_URL', '请输入不含账号密码的 http 或 https 网址。');
        }
        return $value;
    }

    private function number(mixed $value, float $min, float $max, string $name): float
    {
        try {
            $number = (float) validator()->check($value, ['rule' => 'float', 'range' => [$min, $max], 'name' => $name]);
        } catch (\Throwable) {
            throw new GatewayError('INVALID_BROWSER_ACTION', $name . '超出允许范围。');
        }
        return $number;
    }

    private function text(mixed $value, int $min, int $max, string $name): string
    {
        try {
            return (string) validator()->check($value, ['rule' => 'string', 'len' => [$min, $max], 'name' => $name]);
        } catch (\Throwable) {
            throw new GatewayError('INVALID_BROWSER_ACTION', $name . '长度无效。');
        }
    }

    private function key(mixed $value): string
    {
        $value = $this->text($value, 1, 20, '按键');
        $allowed = ['Enter', 'Tab', 'Backspace', 'Escape', 'ArrowUp', 'ArrowDown', 'ArrowLeft', 'ArrowRight'];
        if (!in_array($value, $allowed, true) && mb_strlen($value) !== 1) throw new GatewayError('INVALID_BROWSER_ACTION', '按键不受支持。');
        return $value;
    }

    private function token(mixed $value, string $name): string
    {
        try {
            return (string) validator()->check($value, ['rule' => 'alnum', 'len' => [1, 128], 'name' => $name]);
        } catch (\Throwable) {
            throw new GatewayError('INVALID_BROWSER_ACTION', $name . '无效。');
        }
    }

    private function respond(Request $request, callable $operation, int $successStatus = 200)
    {
        try {
            Schema::initialize();
            return $request->json(['code' => 0, 'data' => $operation()])->withStatus($successStatus);
        } catch (GatewayError $error) {
            return $request->json(['code' => $error->errorCode, 'message' => $error->getMessage()])->withStatus($error->httpStatus);
        } catch (\Throwable) {
            return $request->json(['code' => 'BROWSER_SERVICE_UNAVAILABLE', 'message' => '浏览器工作区暂不可用，请检查本地服务状态。'])->withStatus(503);
        }
    }
}
