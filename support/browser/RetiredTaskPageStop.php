<?php
declare(strict_types=1);
namespace support\browser;

use app\gateway\model\TaskPageRetirement;

/** Drain retired pages on independent CDP sessions before another page may run their task. */
final class RetiredTaskPageStop
{
    /** @var array<string,array> Per-surface observations; durable ownership stays in the ledger. */
    private array $observers = [];

    public function __construct(private CdpClient $cdp, private string $modelUrl,
        private ?\Closure $onClosed = null) {}

    /** Start asynchronous work for this task and report only confirmed physical closure. */
    public function settled(string $taskId): bool
    {
        $this->tick($taskId);
        return TaskPageRetirement::pending($taskId) === [];
    }

    /** Progress each retired page separately; never wait on CDP inside a database transaction. */
    public function tick(?string $taskId = null): void
    {
        if (!$this->cdp->ready()) return;
        foreach (TaskPageRetirement::pending($taskId) as $snapshot) {
            $id = $snapshot['surface_id'];
            if (!TaskPageRetirement::owns($snapshot)) { $this->release($id); continue; }
            if (($this->observers[$id]['snapshot']['snapshot_hash'] ?? null) !== $snapshot['snapshot_hash']) {
                $this->release($id);
                $this->observers[$id] = ['snapshot'=>$snapshot, 'session'=>null, 'busy'=>false,
                    'idle_since'=>null, 'account_verified'=>false, 'verify_only'=>false, 'next_at'=>0.0, 'document_revision'=>0];
            }
            if ($this->observers[$id]['busy'] || microtime(true) < $this->observers[$id]['next_at']) continue;
            $this->observers[$id]['snapshot'] = $snapshot;
            $this->send($id, 'Target.getTargets', [], null, function (array $result) use ($id): void {
                if (!is_array($result['targetInfos'] ?? null)) { $this->retry($id); return; }
                $snapshot = $this->observers[$id]['snapshot'];
                $target = null;
                foreach ($result['targetInfos'] as $candidate) {
                    if (($candidate['targetId'] ?? '') === $snapshot['target_id'] && ($candidate['type'] ?? '') === 'page') {
                        $target = $candidate; break;
                    }
                }
                if ($target === null) {
                    if ($snapshot['state'] === 'stopped') {
                        $this->finishClosed($id, $snapshot);
                    } elseif (!empty($snapshot['empty_page'])) {
                        if (TaskPageRetirement::confirmStopped($snapshot)) $this->finishClosed($id, $snapshot);
                    } else $this->recover($id);
                    return;
                }
                if (!empty($snapshot['empty_page']) && $snapshot['url'] === 'about:blank'
                    && ($target['url'] ?? '') === 'about:blank') {
                    // An unsent blank document cannot have a remote model generation.
                    // Still require exact ownership and verify physical closure next tick.
                    if (!TaskPageRetirement::confirmStopped($snapshot)) { $this->release($id); return; }
                    $this->send($id, 'Target.closeTarget', ['targetId'=>$snapshot['target_id']], null,
                        function (array $unused) use ($id): void { $this->observers[$id]['next_at'] = 0.0; });
                    return;
                }
                $initialSaved = $snapshot['chat_mode'] === 'regular'
                    && (parse_url($snapshot['url'], PHP_URL_PATH) ?: '/') === '/'
                    && !empty($snapshot['initial_binding_marker'])
                    && WebModelPage::regularConversationId((string)($target['url'] ?? ''), $this->modelUrl) !== null;
                // Temporary Chat also acquires /c/<uuid> after its first staged send. This
                // permits inspection of the exact target only; DOM submission witnesses
                // must still authorize Stop, and a different existing UUID is never adopted.
                $initialTemporary = $snapshot['chat_mode'] === 'temporary'
                    && (parse_url($snapshot['url'], PHP_URL_PATH) ?: '/') === '/'
                    && WebModelPage::isTaskChatStartUrl($snapshot['url'], $this->modelUrl, 'temporary')
                    && WebModelPage::isTaskChatUrl((string)($target['url'] ?? ''), $this->modelUrl, 'temporary');
                if (!$this->samePage($snapshot['url'], (string)($target['url'] ?? '')) && !$initialSaved && !$initialTemporary
                    && !(($target['url'] ?? '') === 'about:blank' && !empty($snapshot['recovered_target']))) {
                    $this->retry($id); return;
                }
                $this->attach($id, (string)($target['url'] ?? ''));
            });
        }
    }

    /** Navigation invalidates account and stable-idle evidence in the independent observer. */
    public function onEvent(array $event): void
    {
        if (!in_array($event['method'] ?? '', ['Page.frameNavigated','Page.navigatedWithinDocument'], true)
            || !empty($event['params']['frame']['parentId'])) return;
        foreach ($this->observers as $id => $observer) {
            if (($observer['session'] ?? null) !== ($event['sessionId'] ?? '')) continue;
            $this->observers[$id]['idle_since'] = null;
            $this->observers[$id]['account_verified'] = false;
            $this->observers[$id]['verify_only'] = true;
            $this->observers[$id]['document_revision']++;
        }
    }

    /** A lost saved page is reopened only at its own URL; a missing temporary page stays unconfirmed. */
    private function recover(string $id): void
    {
        $snapshot = $this->observers[$id]['snapshot'];
        if ($snapshot['chat_mode'] !== 'regular' || empty($snapshot['account_key'])
            || !WebModelPage::regularConversationId((string)$snapshot['url'], $this->modelUrl)) {
            $this->retry($id); return;
        }
        $this->send($id, 'Target.createTarget', ['url'=>'about:blank', 'background'=>true], null,
            function (array $result) use ($id, $snapshot): void {
                $target = (string)($result['targetId'] ?? '');
                if ($target === '') { $this->retry($id); return; }
                $claimed = TaskPageRetirement::claimRecoveryTarget($snapshot, $target);
                if ($claimed === null) { $this->release($id); return; }
                $this->release($id);
                // The persisted target is claimed before navigation. A restart can resume
                // the blank target without creating a second copy of the saved chat.
            });
    }

    /** Attach without borrowing any active task's observer or changing its browser focus. */
    private function attach(string $id, string $url): void
    {
        if (!empty($this->observers[$id]['session'])) { $this->inspect($id, $url); return; }
        $this->send($id, 'Target.attachToTarget', ['targetId'=>$this->observers[$id]['snapshot']['target_id'], 'flatten'=>true], null,
            function (array $result) use ($id, $url): void {
                $session = (string)($result['sessionId'] ?? '');
                if ($session === '') { $this->retry($id); return; }
                $this->observers[$id]['session'] = $session;
                $this->send($id, 'Page.enable', [], $session, fn(array $unused) => $this->inspect($id, $url));
            });
    }

    /** Verify the old account, then run the language-independent owned Stop/readback action. */
    private function inspect(string $id, string $url): void
    {
        $snapshot = $this->observers[$id]['snapshot'];
        $session = $this->observers[$id]['session'];
        if ($url === 'about:blank' && !empty($snapshot['recovered_target'])) {
            $this->send($id, 'Page.navigate', ['url'=>$snapshot['url']], $session,
                function (array $unused) use ($id): void { $this->retry($id, 1.0); });
            return;
        }
        if (!empty($snapshot['account_key']) && !$this->observers[$id]['account_verified']) {
            $this->evaluate($id, WebModelPage::accountFingerprintExpression(), function (array $value) use ($id, $snapshot, $url): void {
                if (empty($value['verified']) || !is_string($value['account_key'] ?? null)
                    || !hash_equals($snapshot['account_key'], $value['account_key'])) { $this->retry($id, 5.0); return; }
                $this->observers[$id]['account_verified'] = true;
                $this->inspect($id, $url);
            });
            return;
        }
        $verifyOnly = $this->observers[$id]['verify_only'];
        $this->evaluate($id, WebModelPage::retiredStopExpression($snapshot,
            !$verifyOnly, (bool)$this->observers[$id]['account_verified']),
            function (array $value) use ($id, $snapshot, $verifyOnly): void {
                if (empty($value['owned'])) { $this->retry($id); return; }
                $this->observers[$id]['verify_only'] = false;
                if (($value['generation_running'] ?? null) !== false || empty($value['editor_ready'])
                    || !empty($value['confirmation_pending'])) {
                    $this->observers[$id]['idle_since'] = null;
                    $this->observers[$id]['next_at'] = microtime(true) + 0.5;
                    return;
                }
                $since = $this->observers[$id]['idle_since'];
                if ($since === null) { $this->observers[$id]['idle_since'] = microtime(true); return; }
                if (microtime(true) - $since < 1.0) return;
                if (!TaskPageRetirement::confirmStopped($snapshot)) { $this->release($id); return; }
                $this->send($id, 'Target.closeTarget', ['targetId'=>$snapshot['target_id']], null,
                    function (array $unused) use ($id): void {
                        // Even a successful close reply is not a physical inventory.
                        // The next tick verifies disappearance before releasing the task.
                        $this->observers[$id]['next_at'] = 0.0;
                    });
            });
    }

    /** Evaluate one bounded action; exceptions and uncertain replies never authorize a new page. */
    private function evaluate(string $id, string $expression, callable $after): void
    {
        $this->send($id, 'Runtime.evaluate', ['expression'=>$expression, 'returnByValue'=>true, 'awaitPromise'=>true],
            $this->observers[$id]['session'], function (array $result) use ($id, $after): void {
                $value = $result['result']['value'] ?? null;
                if (!empty($result['exceptionDetails']) || !is_array($value)) { $this->retry($id); return; }
                $after($value);
            }, 20.0);
    }

    /** Fence both ends of every asynchronous request so stale callbacks cannot stop another task. */
    private function send(string $id, string $method, array $params, ?string $session, callable $after, float $timeout = 15.0): void
    {
        $snapshot = $this->observers[$id]['snapshot'] ?? null;
        if (!is_array($snapshot) || !TaskPageRetirement::owns($snapshot)) { $this->release($id); return; }
        $revision = $this->observers[$id]['document_revision'];
        $this->observers[$id]['busy'] = true;
        $this->cdp->send($method, $params, $session, function ($error, $result) use ($id, $snapshot, $revision, $after): void {
            if (($this->observers[$id]['snapshot']['snapshot_hash'] ?? null) !== $snapshot['snapshot_hash']) return;
            $this->observers[$id]['busy'] = false;
            try {
                if (!TaskPageRetirement::owns($snapshot)) { $this->release($id); return; }
                if ($this->observers[$id]['document_revision'] !== $revision) { $this->retry($id); return; }
                if ($error !== null) {
                    // A disconnect can invalidate only this observer's flattened session.
                    // Reattach and read back; a lost action reply is never a Stop receipt.
                    $oldSession = $this->observers[$id]['session'];
                    $this->observers[$id]['session'] = null;
                    $this->observers[$id]['account_verified'] = false;
                    if ($oldSession && $this->cdp->ready()) $this->cdp->send('Target.detachFromTarget',
                        ['sessionId'=>$oldSession], null, static function (): void {}, 5.0);
                    $this->retry($id, 2.0); return;
                }
                $after((array)$result);
            } catch (\Throwable) { $this->retry($id, 2.0); }
        }, $timeout);
    }

    /** Reset only evidence, retaining the task's durable obligation through transient failures. */
    private function retry(string $id, float $delay = 1.0): void
    {
        if (!isset($this->observers[$id])) return;
        $this->observers[$id]['idle_since'] = null;
        $this->observers[$id]['verify_only'] = true;
        $this->observers[$id]['next_at'] = microtime(true) + $delay;
    }

    /** Publish confirmed closure before clearing the old page's disposable monitor frames. */
    private function finishClosed(string $id, array $snapshot): void
    {
        if (!TaskPageRetirement::closed($snapshot)) return;
        $this->release($id);
        if ($this->onClosed !== null) {
            try { ($this->onClosed)((string)($snapshot['origin_surface_id'] ?? $id)); }
            catch (\Throwable) { /* Screenshot cleanup cannot undo a durable Stop receipt. */ }
        }
    }

    /** Detach this observer only; never close the page while stopping is unconfirmed. */
    private function release(string $id): void
    {
        $session = $this->observers[$id]['session'] ?? null;
        unset($this->observers[$id]);
        if ($session && $this->cdp->ready()) $this->cdp->send('Target.detachFromTarget', ['sessionId'=>$session], null, static function (): void {}, 5.0);
    }

    /** Exact saved-page identity, with temporary flags kept significant. */
    private function samePage(string $expected, string $actual): bool
    {
        $a = parse_url($expected); $b = parse_url($actual);
        $saved = WebModelPage::regularConversationId($expected, $this->modelUrl);
        if ($saved !== null) return $saved === WebModelPage::regularConversationId($actual, $this->modelUrl);
        return is_array($a) && is_array($b) && ($a['scheme'] ?? '') === ($b['scheme'] ?? '')
            && ($a['host'] ?? '') === ($b['host'] ?? '') && ($a['port'] ?? null) === ($b['port'] ?? null)
            && ($a['path'] ?? '/') === ($b['path'] ?? '/') && ($a['query'] ?? '') === ($b['query'] ?? '');
    }
}
