<?php
declare(strict_types=1);
namespace app\gateway\model;

use RC\Cli\Banner;
use RC\Config;

/** Read-only, per-start console progress for the existing browser and tunnel preparation flow. */
final class StartupProgress
{
    private int $startedAt;
    private bool $announced = false;
    private bool $finished = false;
    /** @var array<int,string> Last displayed state of each step; never stores account or task data. */
    private array $displayed = [];

    /** @param int|null $startedAt Start time used to reject login/connector proof from a prior run. */
    public function __construct(?int $startedAt = null)
    {
        $this->startedAt = $startedAt ?? time();
    }

    /**
     * Observe existing local state without issuing browser, model, tunnel or Codex operations.
     * @return array Framework Banner line descriptors; empty after readiness or unchanged polls.
     */
    public function poll(): array
    {
        if ($this->finished) return [];
        $locale = GatewayPresentation::locale();
        try {
            $network = NetworkSettings::state();
            $saved = NetworkSettings::revision((int) $network['saved_revision']);
            $browser = BrowserState::state();
            $page = ResponseJob::pageSnapshot($browser);
            $tunnel = TunnelRuntime::snapshot();
            $settings = GatewaySettings::snapshot();
            $connector = ConnectorState::snapshot((string) $settings['connector_name']);
            return $this->advance([
                'locale' => $locale,
                'network_configured' => ($saved['mode'] ?? 'unconfigured') !== 'unconfigured',
                'network_ready' => (int) ($network['applied_php_revision'] ?? 0) === (int) $saved['id'],
                'network_error' => !empty($network['last_apply_error']),
                'browser_status' => (string) $browser['status'],
                'browser_ready' => (string) $browser['status'] === 'ready' && BrowserState::isWorkerReady($browser)
                    && (int) ($browser['network_revision'] ?? 0) === (int) $saved['id'],
                'browser_error' => !empty($browser['last_error']),
                'account_ready' => ResponseJob::accountReady($browser, $page)
                    && (int) $page['observed_at'] >= $this->startedAt,
                'login_required' => !empty($page['fresh']) && !empty($page['login_required']),
                'model_count' => count(ModelCatalog::routes()),
                'tunnel_configured' => (bool) $tunnel['configured'],
                'tunnel_status' => (string) $tunnel['status'],
                'tunnel_ready' => $tunnel['status'] === 'ready'
                    && (int) ($tunnel['network_revision'] ?? 0) === (int) $saved['id']
                    && (int) $tunnel['updated_at'] >= $this->startedAt,
                'tunnel_error' => !empty($tunnel['last_error']),
                'connector_verified' => (bool) $connector['verified']
                    && (int) ($connector['verified_at'] ?? 0) >= $this->startedAt,
                'connector_pending' => $connector['reconnecting'] || $connector['recheck_queued'] || $connector['probe_pending'],
                'connector_error' => !empty($connector['last_error']),
            ]);
        } catch (\Throwable) {
            // Startup failures remain visible in the existing console; do not leak exception data
            // or let presentation interfere with the browser worker's execution/recovery loop.
            return $this->advance(['locale' => $locale, 'state_unavailable' => true]);
        }
    }

    /**
     * Project a safe startup snapshot into ordered, deduplicated progress lines.
     * @param array $state Whitelisted booleans/status/count from poll(); no prompts or credentials.
     * @return array Banner line descriptors. A new instance starts a new reporting sequence.
     */
    public function advance(array $state): array
    {
        if ($this->finished) return [];
        $zh = ($state['locale'] ?? 'en') === 'zh-CN';
        $lines = [];
        if (!$this->announced) {
            $this->announced = true;
            $lines[] = ['type' => 'blank'];
            $lines[] = ['text' => $zh ? '正在恢复网关运行环境，请稍候…' : 'Restoring the gateway environment. Please wait…', 'color' => 'bright_cyan'];
        }
        if (!empty($state['state_unavailable'])) {
            $this->append($lines, 0, 'unavailable', $zh,
                '正在等待启动状态；如持续等待，请打开控制台查看运行准备。',
                'Waiting for startup status. If this persists, open Runtime preparation in the console.', 'yellow');
            return $lines;
        }
        unset($this->displayed[0]);
        $steps = [
            [!empty($state['network_configured']) && !empty($state['network_ready']), 'network',
                '网络配置已应用。', 'Network configuration applied.'],
            [!empty($state['browser_ready']), 'browser', '内置浏览器已连接。', 'Built-in browser connected.'],
            [!empty($state['account_ready']) && empty($state['login_required']), 'account', 'ChatGPT 登录状态已确认。', 'ChatGPT sign-in confirmed.'],
            [(int) ($state['model_count'] ?? 0) > 0, 'models',
                sprintf('Web 模型与可用推理档位已就绪（%d 个模型）。', (int) ($state['model_count'] ?? 0)),
                sprintf('Web models and available reasoning levels ready (%d models).', (int) ($state['model_count'] ?? 0))],
            [!empty($state['tunnel_configured']) && !empty($state['tunnel_ready']) && !empty($state['connector_verified']) && empty($state['connector_pending']),
                'tools', '工具隧道与 RCMAKER 连接器已就绪。', 'Tool tunnel and RCMAKER connector ready.'],
        ];
        foreach ($steps as $index => [$ready, $name, $completeZh, $completeEn]) {
            $step = $index + 1;
            if ($ready) {
                $this->append($lines, $step, 'complete', $zh, $completeZh, $completeEn, 'green');
                continue;
            }
            [$code, $waitingZh, $waitingEn] = self::waiting($name, $state);
            $this->append($lines, $step, $code, $zh, $waitingZh, $waitingEn, 'yellow');
            return $lines;
        }
        $this->finished = true;
        $lines[] = ['type' => 'blank'];
        $lines[] = ['text' => $zh ? '准备就绪。请启动 Codex；如果已经打开，请完整退出后重新启动。'
            : 'Ready. Start Codex; if it is already open, fully quit and restart it.', 'color' => 'bright_green', 'bold' => true];
        return $lines;
    }

    /** @return string Console output using the framework's color, newline and sanitization rules. */
    public static function render(array $lines): string
    {
        if ($lines === []) return '';
        return Banner::render([], [], ['color' => Config::get('banner', 'color') ?? 'auto', 'width' => 100, 'lines' => $lines]);
    }

    /** Append only state transitions; stable waiting steps never flood the shell. */
    private function append(array &$lines, int $step, string $code, bool $zh, string $textZh, string $textEn, string $color): void
    {
        $key = ($zh ? 'zh' : 'en') . ':' . $code;
        if (($this->displayed[$step] ?? '') === $key) return;
        $this->displayed[$step] = $key;
        $prefix = $step > 0 ? '[' . $step . '/5] ' : '';
        $lines[] = ['text' => $prefix . ($zh ? $textZh : $textEn), 'color' => $color];
    }

    /** Choose actionable, localized status text without copying private upstream errors. */
    private static function waiting(string $step, array $state): array
    {
        if ($step === 'network') {
            if (empty($state['network_configured'])) return ['setup', '等待网络配置：请打开控制台完成“网络与代理”。', 'Waiting for network setup. Open Network & proxy in the console.'];
            if (!empty($state['network_error'])) return ['error', '网络配置尚未应用，请在控制台查看并重新应用。', 'Network configuration was not applied. Review and apply it in the console.'];
            return ['checking', '正在加载并应用网络配置…', 'Loading and applying network configuration…'];
        }
        if ($step === 'browser') {
            if (!empty($state['browser_error']) || ($state['browser_status'] ?? '') === 'error') return ['error', '浏览器尚未就绪，请在“运行准备”中检查浏览器组件。', 'Browser is not ready. Check the browser component in Runtime preparation.'];
            if (($state['browser_status'] ?? '') === 'stopped') return ['stopped', '等待内置浏览器启动；可在“运行准备”中安装或启动浏览器。', 'Waiting for the built-in browser. Install or start it in Runtime preparation.'];
            return ['checking', '正在启动并连接内置浏览器…', 'Starting and connecting the built-in browser…'];
        }
        if ($step === 'account') {
            if (!empty($state['login_required'])) return ['login', '等待登录 ChatGPT：请在“运行准备”中打开登录页面。', 'Waiting for ChatGPT sign-in. Open the sign-in page in Runtime preparation.'];
            return ['checking', '正在校验 ChatGPT 登录账号…', 'Verifying the signed-in ChatGPT account…'];
        }
        if ($step === 'models') return ['checking', '正在同步 Web 模型与可用推理档位…', 'Syncing Web models and available reasoning levels…'];
        if (empty($state['tunnel_configured'])) return ['setup', '等待工具连接配置：请填写 API Key、Tunnel ID，并创建 RCMAKER 连接器。', 'Waiting for tool setup. Save the API key and Tunnel ID, then create the RCMAKER connector.'];
        if (!empty($state['tunnel_error'])) return ['tunnel_error', '工具隧道暂不可用，请在“连接 Codex 工具”中查看状态。', 'Tool tunnel is unavailable. Review its status in Connect Codex tools.'];
        if (($state['tunnel_status'] ?? '') === 'stopped') return ['tunnel_stopped', '工具隧道尚未启动，请在“连接 Codex 工具”中启动连接。', 'Tool tunnel is stopped. Start the connection in Connect Codex tools.'];
        if (empty($state['tunnel_ready'])) return ['tunnel', '正在连接工具隧道…', 'Connecting the tool tunnel…'];
        if (!empty($state['connector_error']) && empty($state['connector_pending'])) return ['connector_error', '连接器检查未通过，请在“连接 Codex 工具”中查看并重新检查。', 'Connector check failed. Review and recheck it in Connect Codex tools.'];
        if (empty($state['connector_pending'])) return ['connector_setup', '等待 RCMAKER 连接器验证，请在“连接 Codex 工具”中点击“检查连接器”。', 'Waiting for RCMAKER connector verification. Click Check connector in Connect Codex tools.'];
        return ['connector', '正在检查 RCMAKER 连接器与本机工具连接…', 'Checking the RCMAKER connector and local tool connection…'];
    }
}
