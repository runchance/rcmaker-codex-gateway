<?php
declare(strict_types=1);
namespace support\browser;

use app\gateway\model\BrowserState;
use app\gateway\model\GatewayError;
use app\gateway\model\ModelCatalog;
use Workerman\Timer;

/** Bounded no-message account discovery on its own page, independent of active task/user controls. */
final class BackgroundModelProbe
{
    private ?string $target = null;
    private ?string $session = null;
    private bool $finished = false;
    private bool $opened = false;
    private float $deadline;
    private ?array $inventory = null;
    private array $familyCapabilities = [];
    private ?string $selectingFamily = null;
    private bool $viewExpanded = false;
    private ?string $expectedFamily = null;
    private array $lastObservation = [];

    /** Return bounded picker-only diagnostic metadata; never includes chat, account identity or credentials. */
    public function diagnostics(): array
    {
        return $this->lastObservation + ['expected_family'=>$this->expectedFamily,
            'selecting_family'=>$this->selectingFamily, 'captured_families'=>array_keys($this->familyCapabilities)];
    }

    /** @param CdpClient $client Private browser connection. @param array $browser Pinned incarnation. @param callable $done Receives (?GatewayError,array). */
    public function __construct(private CdpClient $client, private array $browser, private $done)
    {
        $this->deadline = microtime(true) + 45;
    }

    /** Create an isolated setup page without sending a model prompt or attaching host capabilities. */
    public function start(string $url): void
    {
        $this->client->send('Target.createTarget', NativeBrowserWindow::backgroundPageParameters(
            ['url'=>'about:blank','background'=>true], $this->browser), null, function ($error, $result) use ($url): void {
                $this->target = is_string($result['targetId'] ?? null) ? $result['targetId'] : null;
                if ($error !== null || $this->target === null) { $this->finish(); return; }
                $this->client->send('Target.attachToTarget', ['targetId'=>$this->target,'flatten'=>true], null,
                    function ($error, $attached) use ($url): void {
                        $this->session = is_string($attached['sessionId'] ?? null) ? $attached['sessionId'] : null;
                        if ($error !== null || $this->session === null) { $this->finish(); return; }
                        $this->client->send('Emulation.setFocusEmulationEnabled', ['enabled'=>true], $this->session,
                            function ($error) use ($url): void {
                                if ($error !== null) { $this->finish(); return; }
                                $this->client->send('Page.navigate', ['url'=>$url], $this->session, function ($error): void {
                                    if ($error !== null) { $this->finish(); return; }
                                    $this->poll();
                                }, 5.0);
                            }, 3.0);
                    }, 5.0);
            }, 5.0);
    }

    /** Retry control hydration within the pinned deadline, always on the isolated session. */
    private function poll(): void
    {
        if ($this->finished) return;
        if (microtime(true) >= $this->deadline || !$this->sameBrowser()) { $this->finish(); return; }
        if ($this->selectingFamily !== null) { $this->selectFamily(); return; }
        $expression = $this->opened ? WebModelPage::effortSliderExpression() : WebModelPage::modelControlStateExpression();
        $this->evaluate($expression, function ($error, $value): void {
            $this->lastObservation = ['phase'=>$this->opened ? 'slider' : 'control', 'cdp_error'=>$error['message'] ?? null,
                'state'=>is_array($value) ? array_intersect_key($value, array_flip(['ok','code','sol_available',
                    'effort_control_count','effort_menu_expanded','min','max','value','family_options'])) : null];
            if ($error !== null || !is_array($value) || empty($value['ok'])) {
                if ($this->opened) {
                    // Family selection can close the picker after its synchronous click ACK.
                    // Reopen only after a separate read proves it closed; never assume it stayed open.
                    $this->evaluate(WebModelPage::modelControlStateExpression(), function ($controlError, $control): void {
                        $this->lastObservation['control'] = is_array($control) ? array_intersect_key($control,
                            array_flip(['ok','effort_menu_expanded','effort_control_count','sol_available'])) : null;
                        if ($controlError === null && ($control['effort_menu_expanded'] ?? null) === false) $this->opened = false;
                        $this->later();
                    });
                } else $this->later();
                return;
            }
            if ($this->opened) {
                $capabilities = ChatGptTransport::modelCapabilities($value);
                if (ModelCatalog::families($capabilities) === []) { $this->later(); return; }
                $this->captureFamily($capabilities); return;
            }
            if (!empty($value['sol_available']) && (int)($value['effort_control_count'] ?? 0) === 1) {
                $this->evaluate(WebModelPage::focusEffortControlExpression(), function ($error, $focused): void {
                    if ($error !== null || empty($focused['ok'])) {
                        // Preserve first-login handling from the existing manual discovery flow.
                        // Only its exact optional setup dialog on this probe can be dismissed.
                        $this->evaluate(WebModelPage::dismissOptionalSetupDialogExpression(), fn() => $this->later());
                        return;
                    }
                    $this->opened = true;
                    if (!empty($focused['expanded'])) { $this->later(); return; }
                    $this->client->send('Input.dispatchKeyEvent', ['type'=>'keyDown','key'=>'Enter','code'=>'Enter','windowsVirtualKeyCode'=>13,'text'=>"\r"],
                        $this->session, function ($error): void {
                            if ($error !== null) { $this->finish(); return; }
                            $this->client->send('Input.dispatchKeyEvent', ['type'=>'keyUp','key'=>'Enter','code'=>'Enter','windowsVirtualKeyCode'=>13],
                                $this->session, fn() => $this->later(), 3.0);
                        }, 3.0);
                });
                return;
            }
            if (empty($value['sol_available']) && in_array($value['account_plan'] ?? '', ['free','go'], true)) {
                $this->finish(['account_plan'=>$value['account_plan'],'sol_available'=>false,'pro_available'=>false,
                    'think_available'=>(int)($value['think_control_count'] ?? 0) === 1
                        && is_bool($value['think_pressed'] ?? null)]); return;
            }
            $this->later();
        });
    }

    /** Record each family's own range, then inspect the next family without sending a conversation. */
    private function captureFamily(array $capabilities): void
    {
        $options = ChatGptTransport::familyOptions($capabilities);
        $selected = array_keys(array_filter($options, static fn(array $row): bool => $row['checked'] === 'true'));
        if (count($selected) !== 1 || ($this->expectedFamily !== null && $selected[0] !== $this->expectedFamily)) {
            $this->later(); return;
        }
        $keys = array_keys($options);
        if ($this->inventory !== null && array_keys(ChatGptTransport::familyOptions($this->inventory)) !== $keys) {
            $this->finish(); return; // A changing menu is not a complete multi-family observation.
        }
        $this->inventory ??= $capabilities;
        $this->familyCapabilities[$selected[0]] = array_intersect_key($capabilities, array_flip([
            'min','max','value','option_count','extra_high_available','pro_available','pro_supported',
            'descriptions','value_text','selected_disabled','pro_disabled','pro_detail','quota_labels','effort_locks',
        ]));
        $remaining = array_values(array_diff($keys, array_keys($this->familyCapabilities)));
        if ($remaining === []) {
            $original = array_keys(array_filter(ChatGptTransport::familyOptions($this->inventory),
                static fn(array $row): bool => $row['checked'] === 'true'))[0];
            if ($selected[0] !== $original) {
                // Restore the original family after discovery, including its verified readback.
                $this->selectingFamily = $original; $this->viewExpanded = false; $this->selectFamily(); return;
            }
            $this->inventory = array_replace($this->inventory, $this->familyCapabilities[$original]);
            $this->inventory['family_capabilities'] = $this->familyCapabilities;
            $this->finish($this->inventory); return;
        }
        $this->selectingFamily = $remaining[0]; $this->viewExpanded = false;
        $this->selectFamily();
    }

    /** Select only an observed family on this disposable page; independently verify it on the next slider read. */
    private function selectFamily(): void
    {
        $family = $this->selectingFamily;
        $this->evaluate(WebModelPage::selectFamilyExpression($family, !$this->viewExpanded),
            function ($error, $value) use ($family): void {
                $this->lastObservation = ['phase'=>'family_selection', 'cdp_error'=>$error['message'] ?? null, 'state'=>$value];
                if ($error !== null || !is_array($value)) { $this->finish(); return; }
                $this->viewExpanded = $this->viewExpanded || !empty($value['view_expanded']);
                if (!empty($value['ok'])) {
                    $this->expectedFamily = $family; $this->selectingFamily = null; $this->opened = false;
                } elseif (empty($value['pending'])) { $this->finish(); return; }
                $this->later();
            });
    }

    /** Run one expression on the probe only; no ordinary or retained session is inspected. */
    private function evaluate(string $expression, callable $done): void
    {
        if (!$this->sameBrowser()) { $this->finish(); return; }
        $this->client->send('Runtime.evaluate', ['expression'=>$expression,'returnByValue'=>true], $this->session,
            static fn($error,$result) => $done($error,$result['result']['value'] ?? null), 3.0);
    }

    /** Yield to long-task/MCP processing rather than blocking the browser worker. */
    private function later(): void
    {
        if (!$this->finished) Timer::add(0.3, fn() => $this->poll(), null, false);
    }

    /** Compare browser incarnation evidence, not a cached account name or quota estimate. */
    private function sameBrowser(): bool
    {
        $current = BrowserState::state();
        return ($current['launch_nonce'] ?? null) === ($this->browser['launch_nonce'] ?? null)
            && ($current['browser_pid'] ?? null) === ($this->browser['browser_pid'] ?? null);
    }

    /** Close only this probe's page; failure preserves the last valid catalog and never replays a send. */
    private function finish(?array $capabilities = null): void
    {
        if ($this->finished) return;
        $this->finished = true;
        $sameBrowser = $this->sameBrowser();
        if ($sameBrowser && $this->target !== null) {
            $this->client->send('Target.closeTarget', ['targetId'=>$this->target], null, static function (): void {}, 3.0);
        }
        ($this->done)($sameBrowser && $capabilities !== null ? null
            : new GatewayError('MODEL_PROBE_FAILED','后台模型检测暂未完成，保留原模型和额度记录。',503), $capabilities ?? []);
    }
}
