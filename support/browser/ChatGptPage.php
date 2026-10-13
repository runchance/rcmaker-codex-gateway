<?php
declare(strict_types=1);
namespace support\browser;

/** ChatGPT DOM adapter expressions. No browser is created here; BrowserRuntime executes these over its owned CDP session. */
final class ChatGptPage
{
    private const COMPOSER = '[data-testid="prompt-textarea"],#prompt-textarea,[contenteditable="true"][data-lexical-editor="true"],[data-chatgpt-composer] [contenteditable="true"][role="textbox"]';
    private const USER = '[data-testid^="conversation-turn-"][data-turn="user"],[data-testid^="conversation-turn-"][data-message-author-role="user"],[data-testid^="conversation-turn-"]:has([data-message-author-role="user"]),[data-content-search-unit-key$=":user"],[data-turn-key]:has([data-user-message-bubble])';
    private const ASSISTANT = '[data-testid^="conversation-turn-"][data-turn="assistant"],[data-testid^="conversation-turn-"][data-message-author-role="assistant"],[data-testid^="conversation-turn-"]:has([data-message-author-role="assistant"]),[data-content-search-unit-key$=":assistant"],[data-turn-key]:has([data-conversation-role="assistant"],[data-chatgpt-agent-turn-start])';
    // The current ChatGPT composer exposes its reasoning picker through an accessible name
    // instead of the former data-tone/data-testid markers. Keep each selector exact so other
    // menus in the composer cannot be mistaken for a model control.
    private const EFFORT_CONTROL = 'button[aria-haspopup="menu"][data-tone="neutral"],button[data-testid="model-switcher-dropdown-button"][aria-haspopup="menu"],button[data-codex-intelligence-trigger="true"][data-composer-navigation-target="reasoning"][aria-haspopup="menu"],button[aria-haspopup="menu"][aria-label="选择 ChatGPT 模型"],button[aria-haspopup="menu"][aria-label="Choose ChatGPT model"]';
    private const EFFORT_MENU = '[data-testid="composer-intelligence-picker-content"],[role="menu"],[role="group"]';
    private const EFFORT_SLIDER_CONTAINER = '[data-model-reasoning-effort-slider],[data-model-picker-power-slider],[role="menuitem"][data-reasoning-slider][aria-label="强度"],[role="menuitem"][data-reasoning-slider][aria-label="Reasoning effort"]';

    /** Build the isolated Temporary Chat URL on the configured ChatGPT origin. */
    public static function temporaryChatUrl(string $modelUrl): string
    {
        $parts = parse_url($modelUrl);
        if (!$parts || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            || trim((string) ($parts['host'] ?? '')) === '') {
            return 'https://chatgpt.com/?temporary-chat=true';
        }
        $origin = strtolower((string) $parts['scheme']) . '://' . (string) $parts['host'];
        if (isset($parts['port'])) $origin .= ':' . (int) $parts['port'];
        return $origin . '/?temporary-chat=true';
    }

    /**
     * Recognize explicit Temporary Chat routes on the configured origin, including SPA local IDs.
     * A local conversation can remain in use while native tools run; elapsed time does not revoke
     * its explicit temporary-chat flag. Ordinary chats and missing/conflicting flags stay rejected.
     * @param string $url Observed page URL.
     * @param string $modelUrl Configured ChatGPT origin.
     * @return bool Whether the exact root or UUID conversation route has one true temporary flag.
     */
    public static function isTemporaryChatUrl(string $url, string $modelUrl): bool
    {
        $actual = parse_url($url);
        $expected = parse_url(self::temporaryChatUrl($modelUrl));
        if (!$actual || !$expected) return false;
        if (strtolower((string) ($actual['scheme'] ?? '')) !== strtolower((string) ($expected['scheme'] ?? ''))
            || strtolower((string) ($actual['host'] ?? '')) !== strtolower((string) ($expected['host'] ?? ''))
            || (int) ($actual['port'] ?? 0) !== (int) ($expected['port'] ?? 0)) {
            return false;
        }
        $path = (string) ($actual['path'] ?? '/');
        if ($path !== '/' && preg_match('~^/c/(?:local-chatgpt%3a)?[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}$~i', $path) !== 1) return false;
        $temporaryValues = [];
        foreach (explode('&', (string) ($actual['query'] ?? '')) as $part) {
            [$key, $value] = array_pad(explode('=', $part, 2), 2, '');
            if (urldecode($key) === 'temporary-chat') $temporaryValues[] = urldecode($value);
        }
        return $temporaryValues === ['true'];
    }

    /** A fresh task must prove the blank Temporary Chat root before its first submit. */
    public static function isTemporaryChatStartUrl(string $url, string $modelUrl): bool
    {
        return (string) (parse_url($url, PHP_URL_PATH) ?: '/') === '/'
            && self::isTemporaryChatUrl($url, $modelUrl);
    }

    /** A post-submit SPA route whose explicit Temporary Chat flag is not confirmed yet. */
    public static function isTemporaryChatNavigationUrl(string $url, string $modelUrl): bool
    {
        $actual = parse_url($url);
        $expected = parse_url(self::temporaryChatUrl($modelUrl));
        if (!$actual || !$expected || self::isTemporaryChatUrl($url, $modelUrl)
            || strtolower((string)($actual['scheme'] ?? '')) !== strtolower((string)($expected['scheme'] ?? ''))
            || strtolower((string)($actual['host'] ?? '')) !== strtolower((string)($expected['host'] ?? ''))
            || (int)($actual['port'] ?? 0) !== (int)($expected['port'] ?? 0)) return false;
        return preg_match('~^/c/(?:local-chatgpt%3a)?[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}$~i',
            (string)($actual['path'] ?? '')) === 1;
    }

    /** True only for ChatGPT backend API traffic on the configured model origin. */
    public static function isBackendUrl(string $url, string $modelUrl): bool
    {
        $actual = parse_url($url);
        $expected = parse_url(self::temporaryChatUrl($modelUrl));
        if (!$actual || !$expected) return false;
        if (strtolower((string) ($actual['scheme'] ?? '')) !== strtolower((string) ($expected['scheme'] ?? ''))
            || strtolower((string) ($actual['host'] ?? '')) !== strtolower((string) ($expected['host'] ?? ''))
            || (int) ($actual['port'] ?? 0) !== (int) ($expected['port'] ?? 0)) {
            return false;
        }
        return str_starts_with((string) ($actual['path'] ?? ''), '/backend-api/');
    }

    /** Match ChatGPT's explicit Cloudflare backend challenge without guessing from page text. */
    public static function isBackendSecurityChallenge(string $url, int $status, array $headers, string $modelUrl): bool
    {
        if ($status !== 403 || !self::isBackendUrl($url, $modelUrl)) return false;
        foreach ($headers as $name => $rawValues) {
            if (strtolower((string) $name) !== 'cf-mitigated') continue;
            $values = is_array($rawValues) ? $rawValues : [$rawValues];
            foreach ($values as $rawValue) {
                foreach (explode(',', (string) $rawValue) as $value) {
                    if (strtolower(trim($value)) === 'challenge') return true;
                }
            }
        }
        return false;
    }

    /** Match the backend's explicit per-message HTTP 413 on conversation submission. */
    public static function isBackendMessageTooLarge(string $url, int $status, string $modelUrl): bool
    {
        if ($status !== 413 || !self::isBackendUrl($url, $modelUrl)) return false;
        $path = (string) (parse_url($url, PHP_URL_PATH) ?: '');
        return in_array($path, ['/backend-api/f/conversation', '/backend-api/conversation'], true);
    }

    /** Only the owned main frame's actual conversation POST can classify this submission. */
    public static function isConversationSubmission(array $params, string $mainFrame, string $modelUrl): bool
    {
        $request = (array) ($params['request'] ?? []);
        $url = (string) ($request['url'] ?? '');
        return $mainFrame !== '' && ($params['frameId'] ?? '') === $mainFrame
            && ($request['method'] ?? '') === 'POST' && self::isBackendUrl($url, $modelUrl)
            && in_array(parse_url($url, PHP_URL_PATH), ['/backend-api/f/conversation','/backend-api/conversation'], true);
    }

    /** A task send is the current main frame's exact new user-message request. */
    public static function isTaskUserMessageSubmission(array $params, string $mainFrame,
        string $modelUrl, string $postData, string $currentToken): bool
    {
        if (!self::isConversationSubmission($params, $mainFrame, $modelUrl)
            || parse_url((string) ($params['request']['url'] ?? ''), PHP_URL_PATH) !== '/backend-api/f/conversation'
            || strlen($postData) > 4194304
            || preg_match('/\Aturn_[A-Za-z0-9_-]{32}\z/D', $currentToken) !== 1) return false;
        $body = json_decode($postData, true, 64);
        if (!is_array($body) || ($body['action'] ?? null) !== 'next') return false;
        $messages = $body['messages'] ?? null;
        if (!is_array($messages) || !array_is_list($messages) || $messages === []) return false;
        $message = $messages[count($messages) - 1];
        if (!is_array($message) || ($message['author']['role'] ?? null) !== 'user') return false;
        $parts = $message['content']['parts'] ?? null;
        return is_array($parts) && array_is_list($parts)
            && count(array_filter($parts, static fn(mixed $part): bool => is_string($part)
                && str_contains($part, $currentToken))) > 0;
    }

    /** Parse top-level upstream errors, including a rejected HTTP-200 SSE; never model-authored text. */
    public static function submissionSizeError(string $body): ?string
    {
        if (strlen($body) > 4194304) return null;
        $errorCode = static function (mixed $value): ?string {
            if (!is_array($value)) return null;
            if (($value['detail']['code'] ?? '') === 'message_length_exceeds_limit') return 'message_length_exceeds_limit';
            if (($value['error_code'] ?? '') === 'input_too_large'
                && ($value['error_reason'] ?? '') === 'last_user_message' && is_string($value['error'] ?? null)) return 'input_too_large/last_user_message';
            return null;
        };
        $code = $errorCode(json_decode($body, true));
        if ($code !== null) return $code;
        foreach (explode("\n", $body) as $line) {
            if (!str_starts_with($line, 'data:')) continue;
            $code = $errorCode(json_decode(trim(substr($line, 5)), true));
            if ($code !== null) return $code;
        }
        return null;
    }

    /**
     * Only text observed in two consecutive content-bearing DOM snapshots is safe to stream.
     * null means there is not enough evidence yet (for example a transient empty Markdown shell).
     */
    public static function stableMarkdownPrefix(?string $previous, bool $previousHasContent, string $current, bool $currentHasContent): ?string
    {
        if ($previous === null || !$previousHasContent || !$currentHasContent) return null;
        $limit = min(strlen($previous), strlen($current));
        $length = 0;
        while ($length < $limit && $previous[$length] === $current[$length]) $length++;
        while ($length > 0 && preg_match('//u', substr($previous, 0, $length)) !== 1) $length--;
        return substr($previous, 0, $length);
    }

    /**
     * Project the current assistant Markdown into the current Responses round after a tool boundary.
     *
     * ChatGPT may either append the post-tool answer after the pre-tool browser snapshot or replace
     * that temporary snapshot entirely. Both are valid UI transitions. The boundary is therefore an
     * observation marker, not an immutable Responses text prefix.
     *
     * @return array{markdown:string,boundary_replaced:bool}
     */
    public static function markdownAfterToolBoundary(string $current, string $boundary): array
    {
        if ($boundary === '') return ['markdown' => $current, 'boundary_replaced' => false];
        if (str_starts_with($current, $boundary)) {
            return ['markdown' => substr($current, strlen($boundary)), 'boundary_replaced' => false];
        }
        return ['markdown' => $current, 'boundary_replaced' => true];
    }

    public static function capabilityExpression(): string
    {
        return self::wrap(<<<'JS'
const composer=visibleOne(COMPOSER);
const form=composer?.closest('form')||null;
const stop=generationStop();
const authHost=location.hostname==='auth.openai.com'||location.hostname==='auth0.openai.com';
const login=authHost||[...document.querySelectorAll('a[href*="/auth/login"],a[href*="auth.openai.com"],button')].some(el=>visible(el)&&/^(log in|sign in|登录|登入|登录或注册|log in or sign up)$/i.test((el.textContent||'').trim()));
let model=form?.querySelector('button[data-testid="model-switcher-dropdown-button"]')||document.querySelector('button[data-testid="model-switcher-dropdown-button"]');
if(model&&!visible(model))model=null;
const temporary=temporaryChatPage(location.pathname,location.search);
return {url:String(location.href||''),on_chatgpt:location.hostname==='chatgpt.com'||location.hostname.endsWith('.chatgpt.com'),temporary_chat:temporary,login_required:login,editor_ready:!!composer,generation_running:!!stop,account_plan:accountPlan(),model_label:model?String(model.textContent||'').trim().slice(0,120):(composer?'当前 ChatGPT 模型':null)};
JS);
    }

    /** Detect the current account's semantic ChatGPT model controls without inferring from labels. */
    public static function modelControlStateExpression(): string
    {
        $effort = json_encode(self::EFFORT_CONTROL, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        return self::wrap(str_replace('__EFFORT__', $effort, <<<'JS'
const composer=visibleOne(COMPOSER);if(!composer)return {ok:false,code:'CHATGPT_EDITOR_MISSING'};
const form=composer.closest('form');if(!form)return {ok:false,code:'CHATGPT_EDITOR_FORM_MISSING'};
const controls=[...form.querySelectorAll(__EFFORT__)].filter(visible);
// The same semantic toggle is captioned "思考" on a Chinese ChatGPT page.
const thinkNames=new Set(['Think','思考']);
const think=[...form.querySelectorAll('button[aria-pressed]')].filter(el=>visible(el)&&thinkNames.has(String(el.getAttribute('aria-label')||el.innerText||el.textContent||'').trim()));
let thinkPressed=null;
if(think.length===1){const pressed=think[0].getAttribute('aria-pressed');thinkPressed=pressed==='true'?true:pressed==='false'?false:null;}
const expanded=controls.length===1?controls[0].getAttribute('aria-expanded'):null;
return {ok:true,account_plan:accountPlan(),sol_available:controls.length===1,effort_control_count:controls.length,effort_menu_expanded:expanded==='true'?true:expanded==='false'?false:null,think_control_count:think.length,think_pressed:thinkPressed,document_ready:document.readyState==='complete'};
JS));
    }

    /** Focus the single visible Sol effort control so CDP Enter opens its native menu. */
    public static function focusEffortControlExpression(): string
    {
        $effort = json_encode(self::EFFORT_CONTROL, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        return self::wrap(str_replace('__EFFORT__', $effort, <<<'JS'
const composer=visibleOne(COMPOSER);const form=composer?.closest('form');if(!form)return {ok:false,code:'CHATGPT_EDITOR_FORM_MISSING'};
const controls=[...form.querySelectorAll(__EFFORT__)].filter(visible);if(controls.length!==1)return {ok:false,code:'CHATGPT_MODEL_CONTROL_INVALID',count:controls.length};
controls[0].focus();return {ok:document.activeElement===controls[0],expanded:controls[0].getAttribute('aria-expanded')==='true'};
JS));
    }

    /** Dismiss only the optional Google-app setup dialog that appears immediately after sign-in. */
    public static function dismissOptionalSetupDialogExpression(): string
    {
        return self::wrap(<<<'JS'
const dialogs=[...document.querySelectorAll('[role="dialog"][aria-modal="true"]')].filter(visible);
const setup=dialogs.filter(el=>/^(?:选择要安装的 Google 插件|Choose Google apps to install)$/i.test(
    String(el.querySelector('h1,h2,h3')?.textContent||'').replace(/\s+/g,' ').trim()));
if(setup.length===0)return {ok:true,dismissed:false};
if(setup.length!==1)return {ok:false,dismissed:false,code:'CHATGPT_SETUP_DIALOG_AMBIGUOUS'};
const close=[...setup[0].querySelectorAll('button[type="button"]')].filter(el=>visible(el)&&
    /^(?:关闭对话框|Close dialog)$/i.test(String(el.getAttribute('aria-label')||el.textContent||'').replace(/\s+/g,' ').trim()));
if(close.length!==1)return {ok:false,dismissed:false,code:'CHATGPT_SETUP_DIALOG_CLOSE_MISSING'};
close[0].click();return {ok:true,dismissed:true};
JS);
    }

    /** Read model identity and separately scoped availability from the owned effort picker. */
    public static function effortSliderExpression(): string
    {
        $container = json_encode(self::EFFORT_SLIDER_CONTAINER, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        return self::wrap(str_replace('__CONTAINER__', $container, <<<'JS'
const containers=modelEffortContainers(__CONTAINER__);
if(containers.length!==1)return {ok:false,open:false,code:'CHATGPT_EFFORT_SLIDER_MISSING',count:containers.length};
const sliders=containers[0].querySelectorAll('[role="slider"]');if(sliders.length!==1)return {ok:false,open:true,code:'CHATGPT_EFFORT_SLIDER_INVALID'};
const slider=sliders[0];
const parse=value=>/^-?\d+$/.test(String(value??''))?Number(value):null;
const min=parse(slider.getAttribute('aria-valuemin')),max=parse(slider.getAttribute('aria-valuemax')),value=parse(slider.getAttribute('aria-valuenow'));
if(!Number.isSafeInteger(min)||!Number.isSafeInteger(max)||!Number.isSafeInteger(value)||max<min||value<min||value>max||max-min+1<1||max-min+1>5)return {ok:false,open:true,code:'CHATGPT_EFFORT_SLIDER_INVALID'};
// Simple view can hide attached family radios. Pro restrictions, however,
// must come from this picker, never an earlier user or assistant message.
const picker=containers[0].closest('[role="menu"],[data-testid="composer-intelligence-picker-content"],[role="group"]');
const family_options=[...(picker?.querySelectorAll('[role="menuitemradio"]')||[])].map(el=>{
    const label=modelFamilyLabel(el),raw=String(el.textContent||'').replace(/\s+/g,' ').trim();
    return {label:label.slice(0,120),notice:raw.startsWith(label)?raw.slice(label.length).trim().slice(0,240):'',checked:el.getAttribute('aria-checked'),disabled:el.getAttribute('aria-disabled')==='true'};
});
const owner=slider.closest('[role="menuitem"]');
const described=el=>String(el?.getAttribute('aria-describedby')||'').split(/\s+/).filter(Boolean).map(id=>String(document.getElementById(id)?.textContent||'').replace(/\s+/g,' ').trim().slice(0,500)).filter(Boolean);
const descriptions=[...new Set([...described(owner),...described(slider)])];
const proRows=[...(picker?.querySelectorAll('[role="menuitemradio"]')||[])].filter(el=>{
    const label=String(el.getAttribute('aria-label')||el.innerText||el.textContent||'').replace(/\s+/g,' ').trim();
    return modelRendered(el)&&/^Pro(?:$|[\s,，:：(（\[—–-])/i.test(label);
});
const pro=proRows.length===1?proRows[0]:null;
// A quota restriction can remove the fifth slider position while its Pro radio remains.
// Its presence proves identity, not entitlement or an available fifth position.
const pro_supported=max-min+1>=5||family_options.filter(option=>/^Pro(?:$|[\s,，:：(（\[—–-])/i.test(option.label)&&['true','false'].includes(option.checked)).length===1;
const quota_labels=pro?[...new Set([String(pro.innerText||pro.textContent||'').replace(/\s+/g,' ').trim().slice(0,500),...described(pro)])].filter(Boolean):[];
const ticks=[...containers[0].querySelectorAll('[data-selected]')];
const effort_locks=ticks.length===max-min+1?ticks.map(el=>el.getAttribute('data-locked')==='true'?true:el.getAttribute('data-locked')==='false'?false:null):[];
return {ok:true,open:true,account_plan:accountPlan(),min,max,value,option_count:max-min+1,extra_high_available:max-min+1>=4,pro_available:max-min+1>=5,pro_supported,family_options,descriptions,
    value_text:String(slider.getAttribute('aria-valuetext')||'').trim().slice(0,500),selected_disabled:slider.getAttribute('aria-disabled')==='true'||owner?.getAttribute('aria-disabled')==='true',
    pro_disabled:pro?.getAttribute('aria-disabled')==='true',pro_detail:quota_labels.join(' | '),quota_labels,effort_locks};
JS));
    }
    /**
     * Select a family inside the active picker, opening its advanced view once.
     * Omitted aria-hidden means visible by default; modelRendered still checks the
     * actual element and every ancestor for hidden/inert/transition state.
     * @param string $family Exact gateway family identity, independent of effort.
     * @param bool $expandView Permit one reversible view toggle; subsequent polls only read it.
     * @return string CDP expression reporting selection or pending view hydration for readback.
     */
    public static function selectFamilyExpression(string $family, bool $expandView = true): string
    {
        $expected = json_encode($family, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        return self::wrap(str_replace(['__FAMILY__', '__EXPAND_VIEW__'], [$expected, $expandView ? 'true' : 'false'], <<<'JS'
const family=__FAMILY__;
const picker=modelPickerMenu();if(!picker)return {ok:false,code:'CHATGPT_MODEL_MENU_MISSING',count:0};
const radios=[...picker.querySelectorAll('[role="menuitemradio"]')].filter(el=>
    ['true','false'].includes(el.getAttribute('aria-checked'))&&el.getAttribute('aria-disabled')!=='true');
let matches=radios.filter(el=>modelFamilyKey(modelFamilyLabel(el))===family);
if(matches.length===0&&family==='latest'){
    const named=radios.filter(el=>modelFamilyKey(modelFamilyLabel(el))!==null);
    if(named.length>0&&modelFamilyKey(modelFamilyLabel(named[0]))!=='gpt-5.6-sol'){
        const primary=modelFamilyKey(modelFamilyLabel(named[0]));
        matches=named.filter(el=>modelFamilyKey(modelFamilyLabel(el))===primary);
    }
}
if(matches.length!==1)return {ok:false,code:'CHATGPT_MODEL_FAMILY_OPTIONS_INVALID',count:matches.length};
if(matches[0].getAttribute('aria-checked')==='true')return {ok:true,already_selected:true};
if(!modelRendered(matches[0])){
    const views=[...picker.querySelectorAll('[data-model-picker-view]')];
    const view=views.length===1?views[0].getAttribute('data-model-picker-view'):null;
    const triggers=views.length===1&&view==='simple'
        ?[...views[0].querySelectorAll('[data-model-picker-view-toggle="true"]:not([aria-hidden="true"])')].filter(modelRendered)
        :views.length===0?[...picker.querySelectorAll('[role="menuitem"][aria-expanded="false"]:not([aria-hidden="true"])')].filter(modelRendered):[];
    if(triggers.length===1&&__EXPAND_VIEW__){triggers[0].click();return {ok:false,pending:true,view_expanded:true,code:'CHATGPT_MODEL_FAMILY_VIEW_EXPANDING',count:1};}
    return {ok:false,pending:true,code:'CHATGPT_MODEL_FAMILY_VIEW_PENDING',count:1};
}
matches[0].click();return {ok:true,already_selected:false};
JS));
    }

    /** Focus the menu item that owns the effort slider so ArrowLeft/ArrowRight change exactly one step. */
    public static function focusEffortSliderOwnerExpression(): string
    {
        $container = json_encode(self::EFFORT_SLIDER_CONTAINER, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        return self::wrap(str_replace('__CONTAINER__', $container, <<<'JS'
const containers=modelEffortContainers(__CONTAINER__);if(containers.length!==1)return {ok:false,code:'CHATGPT_EFFORT_SLIDER_MISSING'};
const owner=containers[0].closest('[role="menuitem"]')||containers[0].querySelector('[role="menuitem"]')||containers[0];
if(!(owner instanceof HTMLElement))return {ok:false,code:'CHATGPT_EFFORT_OWNER_MISSING'};
if(!owner.hasAttribute('tabindex'))owner.setAttribute('tabindex','-1');owner.focus();return {ok:document.activeElement===owner,code:document.activeElement===owner?null:'CHATGPT_EFFORT_FOCUS_FAILED'};
JS));
    }

    /** Read Luna Think semantic state. Absence means ordinary Luna when no Sol selector exists. */
    public static function thinkStateExpression(): string
    {
        return self::wrap(<<<'JS'
const composer=visibleOne(COMPOSER);const form=composer?.closest('form');if(!form)return {ok:false,code:'CHATGPT_EDITOR_FORM_MISSING'};
const thinkNames=new Set(['Think','思考']);
const controls=[...form.querySelectorAll('button[aria-pressed]')].filter(el=>visible(el)&&thinkNames.has(String(el.getAttribute('aria-label')||el.innerText||el.textContent||'').trim()));
if(controls.length>1)return {ok:false,code:'CHATGPT_THINK_CONTROL_INVALID',count:controls.length};
if(controls.length===0)return {ok:true,count:0,pressed:false};
const pressed=controls[0].getAttribute('aria-pressed');if(pressed!=='true'&&pressed!=='false')return {ok:false,code:'CHATGPT_THINK_STATE_INVALID',count:1};
return {ok:true,count:1,pressed:pressed==='true',disabled:controls[0].disabled||controls[0].getAttribute('aria-disabled')==='true'};
JS);
    }

    /** Focus the exact semantic Think toggle before a native keyboard activation. */
    public static function focusThinkControlExpression(): string
    {
        return self::wrap(<<<'JS'
const composer=visibleOne(COMPOSER);const form=composer?.closest('form');if(!form)return {ok:false,code:'CHATGPT_EDITOR_FORM_MISSING'};
const thinkNames=new Set(['Think','思考']);
const controls=[...form.querySelectorAll('button[aria-pressed]')].filter(el=>visible(el)&&thinkNames.has(String(el.getAttribute('aria-label')||el.innerText||el.textContent||'').trim()));
if(controls.length!==1)return {ok:false,code:'CHATGPT_THINK_CONTROL_INVALID',count:controls.length};
const pressed=controls[0].getAttribute('aria-pressed');if(pressed!=='true'&&pressed!=='false')return {ok:false,code:'CHATGPT_THINK_STATE_INVALID'};
if(controls[0].disabled||controls[0].getAttribute('aria-disabled')==='true')return {ok:false,code:'CHATGPT_THINK_CONTROL_DISABLED'};
controls[0].focus();return {ok:document.activeElement===controls[0],pressed:pressed==='true'};
JS);
    }

    /** Inspect the slash-command popup used by ChatGPT to toggle Luna Think. */
    public static function thinkCommandMenuExpression(): string
    {
        return self::wrap(<<<'JS'
const popups=[...document.querySelectorAll('.popover[aria-busy="false"]')].filter(visible);
const rows=popups.length===1?[...popups[0].querySelectorAll('.__menu-item[tabindex="0"]')].filter(visible):[];
return {ok:popups.length===1&&rows.length===1,popup_count:popups.length,row_count:rows.length,highlighted:rows.length===1&&rows[0].hasAttribute('data-highlighted')};
JS);
    }

    /** Find an exact assistant transport acknowledgement anywhere in this isolated Temporary Chat. */
    public static function acknowledgementExpression(string $ack): string
    {
        $expected = json_encode(trim($ack), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        return self::wrap(str_replace('__ACK__', $expected, <<<'JS'
const expected=__ACK__;const rows=assistantTurns();
const texts=rows.map(el=>{const root=el.querySelector('.markdown,[data-markdown-text-style]')||el.querySelector('[data-message-author-role="assistant"]')||el;return String(root.innerText||root.textContent||'').replace(/\u00a0/g,' ').trim()});
const matches=texts.filter(text=>text===expected);const nonempty=texts.filter(text=>text!=='');const last=nonempty.at(-1)||'';const pieces=expected.split(' ');
const rawRows=rows.map(el=>{const root=el.querySelector('.markdown,[data-markdown-text-style]')||el.querySelector('[data-message-author-role="assistant"]')||el;return String(root.innerText||root.textContent||'')});
const messageTooLarge=/^(?:你提交的消息过长，请编辑后重新发送[。.!]?|The message you submitted was too long[.,]? Please (?:edit|reload the conversation and submit) (?:it|something shorter)(?: and (?:try again|resubmit))?[.!]?)(?:\s*(?:重试|Retry|Try again))?$/i.test(last);
return {ok:true,matched:matches.length===1,count:matches.length,assistant_count:rows.length,nonempty_count:nonempty.length,last_chars:last.length,last_raw:rawRows.filter(text=>text.trim()!=='').at(-1)||'',
failure_code:messageTooLarge?'CHATGPT_MESSAGE_TOO_LARGE':null,
last_has_prefix:last.includes(pieces[0]||'__missing__'),last_has_transaction:last.includes(pieces[1]||'__missing__'),last_has_part:last.includes(pieces[2]||'__missing__'),last_has_hash:last.includes(pieces[3]||'__missing__'),
generation_running:!!generationStop()};
JS));
    }

    public static function baselineExpression(): string
    {
        return self::wrap(<<<'JS'
const composer=visibleOne(COMPOSER);
const ids=(rows,role)=>rows.map((el,i)=>turnId(el,i,role));
return {url:String(location.href||''),temporary_chat:temporaryChatPage(location.pathname,location.search),editor_ready:!!composer,generation_running:!!generationStop(),composer_text:composer?composerText(composer):'',user_ids:ids(userTurns(),'user'),assistant_ids:ids(assistantTurns(),'assistant')};
JS);
    }

    /** Stop only the currently generating answer after a durable internal control receipt. */
    public static function stopGenerationExpression(): string
    {
        return self::wrap(<<<'JS'
const stop=generationStop();
if(!stop)return {running:false,stopped:false};
stop.click();
return {running:true,stopped:true};
JS);
    }

    /** Read the visible Temporary Chat personalization state without mutating it. */
    public static function personalizationStateExpression(): string
    {
        return self::wrap(<<<'JS'
const label=el=>String(el.getAttribute?.('aria-label')||el.innerText||el.textContent||'').replace(/\s+/g,' ').trim();
const buttons=[...document.querySelectorAll('button')].filter(visible);
const personalized=buttons.filter(el=>/^(Personalized|个性化)$/i.test(label(el)));
const unpersonalized=buttons.filter(el=>/^(Unpersonalized|非个性化)$/i.test(label(el)));
const controls=[...document.querySelectorAll('[data-testid="thread-header-right-actions"] [aria-haspopup="menu"],#conversation-header-actions [aria-haspopup="menu"],[data-content-sheet-root] > button[aria-expanded][aria-controls]')].filter(visible);
let state='missing';
if(personalized.length===1&&unpersonalized.length===0)state='personalized';
else if(personalized.length===0&&unpersonalized.length===1)state='unpersonalized';
else if(personalized.length!==0||unpersonalized.length!==0)state='invalid';
return {state,personalized_count:personalized.length,unpersonalized_count:unpersonalized.length,structural_count:controls.length};
JS);
    }

    /** Open the labeled or structural personalization menu used by Temporary Chat. */
    public static function personalizationOpenExpression(bool $structuralOnly = false): string
    {
        $structural = $structuralOnly ? 'true' : 'false';
        return self::wrap(str_replace('__STRUCTURAL__', $structural, <<<'JS'
const label=el=>String(el.getAttribute?.('aria-label')||el.innerText||el.textContent||'').replace(/\s+/g,' ').trim();
const buttons=[...document.querySelectorAll('button')].filter(visible);
const unpersonalized=buttons.filter(el=>/^(Unpersonalized|非个性化)$/i.test(label(el)));
const controls=[...document.querySelectorAll('[data-testid="thread-header-right-actions"] [aria-haspopup="menu"],#conversation-header-actions [aria-haspopup="menu"],[data-content-sheet-root] > button[aria-expanded][aria-controls]')].filter(visible);
let control=null,mode='';
if(!__STRUCTURAL__&&unpersonalized.length===1){control=unpersonalized[0];mode='labeled';}
else if(controls.length===1){control=controls[0];mode='structural';}
if(!control)return {ok:false,code:'CHATGPT_PERSONALIZATION_CONTROL_MISSING',unpersonalized_count:unpersonalized.length,structural_count:controls.length};
control.click();
return {ok:true,mode,menu_id:String(control.getAttribute('aria-controls')||'')};
JS));
    }

    /** Read the currently opened personalization menu and its exact two checked choices. */
    public static function personalizationMenuExpression(): string
    {
        return self::wrap(<<<'JS'
const controls=[...document.querySelectorAll('[data-testid="thread-header-right-actions"] [aria-haspopup="menu"],#conversation-header-actions [aria-haspopup="menu"],[data-content-sheet-root] > button[aria-expanded][aria-controls]')].filter(visible);
const menus=[];
for(const control of controls){const id=String(control.getAttribute('aria-controls')||'');if(!id)continue;const menu=document.getElementById(id);if(menu&&visible(menu))menus.push(menu);}
if(menus.length!==1)return {open:false,menu_count:menus.length,choice_count:0,checked_index:-1,labels:[]};
const choices=[...menus[0].querySelectorAll('[role="menuitemradio"],[role="radio"]')].filter(visible);
const labels=choices.map(el=>String(el.innerText||el.textContent||'').replace(/\s+/g,' ').trim());
const checked=choices.map(el=>el.getAttribute('aria-checked')==='true'||el.getAttribute('data-state')==='checked');
const checkedIndexes=checked.map((value,index)=>value?index:-1).filter(index=>index>=0);
return {open:true,menu_count:1,choice_count:choices.length,checked_index:checkedIndexes.length===1?checkedIndexes[0]:-1,labels};
JS);
    }

    /** Select Personalized by label, or a specific structural choice index when labels are unavailable. */
    public static function personalizationSelectExpression(?int $index = null): string
    {
        $target = $index === null ? 'null' : (string) max(0, $index);
        return self::wrap(str_replace('__INDEX__', $target, <<<'JS'
const controls=[...document.querySelectorAll('[data-testid="thread-header-right-actions"] [aria-haspopup="menu"],#conversation-header-actions [aria-haspopup="menu"],[data-content-sheet-root] > button[aria-expanded][aria-controls]')].filter(visible);
const menus=[];
for(const control of controls){const id=String(control.getAttribute('aria-controls')||'');if(!id)continue;const menu=document.getElementById(id);if(menu&&visible(menu))menus.push(menu);}
if(menus.length!==1)return {ok:false,code:'CHATGPT_PERSONALIZATION_MENU_MISSING'};
const choices=[...menus[0].querySelectorAll('[role="menuitemradio"],[role="radio"]')].filter(visible);
if(choices.length!==2)return {ok:false,code:'CHATGPT_PERSONALIZATION_CHOICES_INVALID',count:choices.length};
const requested=__INDEX__;
let choice=null;
if(requested===null){const exact=choices.filter(el=>/^(Personalized|个性化)$/i.test(String(el.innerText||el.textContent||'').replace(/\s+/g,' ').trim()));if(exact.length!==1)return {ok:false,code:'CHATGPT_PERSONALIZATION_PERSONALIZED_MISSING',count:exact.length};choice=exact[0];}
else if(Number.isInteger(requested)&&requested>=0&&requested<choices.length)choice=choices[requested];
if(!choice)return {ok:false,code:'CHATGPT_PERSONALIZATION_CHOICE_INVALID'};
choice.click();return {ok:true};
JS));
    }

    public static function focusAndClearExpression(): string
    {
        return self::wrap(<<<'JS'
const composer=visibleOne(COMPOSER);
if(!composer)return {ok:false,code:'CHATGPT_EDITOR_MISSING'};
composer.focus();
if(composer instanceof HTMLTextAreaElement||composer instanceof HTMLInputElement){composer.value='';composer.dispatchEvent(new Event('input',{bubbles:true}));}
else{const selection=getSelection();const range=document.createRange();range.selectNodeContents(composer);selection?.removeAllRanges();selection?.addRange(range);document.execCommand('delete',false);selection?.removeAllRanges();composer.dispatchEvent(new InputEvent('input',{bubbles:true,inputType:'deleteContentBackward',data:null}));}
const text=composerText(composer);
const connectorCount=composer.querySelectorAll('[data-id^="plugin:"],[app-mention-path^="app://"],[data-prompt-link-href^="app://"]').length;
return {ok:text===''&&connectorCount===0,text,connector_count:connectorCount};
JS);
    }

    /** Focus the active composer without changing its mention, connector pill, or prompt text. */
    public static function focusComposerExpression(): string
    {
        return self::wrap(<<<'JS'
const composer=visibleOne(COMPOSER);if(!composer)return {ok:false,code:'CHATGPT_EDITOR_MISSING'};
composer.focus();
return {ok:document.activeElement===composer,code:document.activeElement===composer?null:'CHATGPT_EDITOR_FOCUS_FAILED'};
JS);
    }

    public static function composerTextExpression(): string
    {
        return self::wrap(<<<'JS'
const composer=visibleOne(COMPOSER);return {ok:!!composer,text:composer?composerText(composer):''};
JS);
    }

    /**
     * Insert a complete prompt atomically into the focused ChatGPT composer.
     *
     * CDP Input.insertText is treated as live typing by ChatGPT's Lexical editor. Markdown-like
     * transport text can therefore be rewritten into rich nodes before our integrity readback.
     * Connector selection replaces ChatGPT's Lexical composer subtree, so every call reacquires the
     * currently visible composer and deliberately places the caret at its document end. This keeps
     * the selected Connector pill intact and appends the prompt after it instead of trusting the
     * selection/focus left behind by the pre-selection editor.
     */
    public static function insertPlainTextExpression(string $text): string
    {
        $value = json_encode($text, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        return self::wrap(str_replace('__VALUE__', $value, <<<'JS'
const composer=visibleOne(COMPOSER);if(!composer)return {ok:false,code:'CHATGPT_EDITOR_MISSING'};
if(document.activeElement!==composer)composer.focus();
if(document.activeElement!==composer)return {ok:false,code:'CHATGPT_EDITOR_FOCUS_FAILED'};
const selection=getSelection();if(!selection)return {ok:false,code:'CHATGPT_EDITOR_SELECTION_MISSING'};
const range=document.createRange();range.selectNodeContents(composer);range.collapse(false);selection.removeAllRanges();selection.addRange(range);
if(!selection.isCollapsed||!selection.anchorNode||!composer.contains(selection.anchorNode))return {ok:false,code:'CHATGPT_EDITOR_CARET_FAILED'};
const ok=document.execCommand('insertText',false,__VALUE__);
return {ok:!!ok,code:ok?null:'CHATGPT_EDITOR_INSERT_REJECTED'};
JS));
    }

    /** Exact connector pill currently attached to the active composer. */
    public static function connectorSelectedExpression(string $connectorName): string
    {
        $name = json_encode($connectorName, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        return self::wrap(str_replace('__CONNECTOR__', $name, <<<'JS'
const composer=visibleOne(COMPOSER);if(!composer)return {selected:false,count:0,code:'CHATGPT_EDITOR_MISSING'};
const name=__CONNECTOR__;
const pills=[...composer.querySelectorAll('[data-id^="plugin:"],[app-mention-path^="app://"],[data-prompt-link-href^="app://"]')];
const controls=pills.filter(el=>
    visible(el)&&(el.hasAttribute('data-keyword')?String(el.getAttribute('data-keyword')||'')===name:
        String(el.getAttribute('app-mention-display-name')||'')===name
        &&el.getAttribute('app-mention-path')===el.getAttribute('data-prompt-link-href')));
return {selected:controls.length===1&&pills.length===1,count:controls.length,total_count:pills.length,
    code:pills.length>1?'CHATGPT_CONNECTOR_CONFLICT':null};
JS));
    }

    /** All selected connector identities, used to prove setup verification leaves the composer clean. */
    public static function connectorControlsExpression(): string
    {
        return self::wrap(<<<'JS'
const composer=visibleOne(COMPOSER);if(!composer)return {ok:false,keywords:[],text:''};
const keywords=[...composer.querySelectorAll('[data-id^="plugin:"][data-keyword],[app-mention-path^="app://"][app-mention-display-name][data-prompt-link-href^="app://"]')]
    .filter(el=>visible(el)&&(!el.hasAttribute('app-mention-path')||el.getAttribute('app-mention-path')===el.getAttribute('data-prompt-link-href')))
    .map(el=>String(el.getAttribute('data-keyword')||el.getAttribute('app-mention-display-name')||''));
return {ok:true,keywords,text:composerText(composer)};
JS);
    }

    /** Connector rows exposed by mention autocomplete, matched against the full configured name. */
    public static function connectorMenuExpression(string $connectorName): string
    {
        $name = json_encode($connectorName, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        return self::wrap(str_replace('__CONNECTOR__', $name, <<<'JS'
const name=__CONNECTOR__;
const rows=[...document.querySelectorAll('.__menu-item[tabindex="0"],[data-mention-list-scroll-area] button[data-list-navigation-item="true"]')].filter(visible);
const title=el=>String((el.innerText||el.textContent||'').split('\n')[0]||'').replace(/\s+/g,' ').trim();
const titles=rows.map(title);
// The current mention list can render the same Connector name twice inside one button
// (visual label plus a second accessibility label). Accept only that exact repetition.
const exactName=label=>label===name||label===name+' '+name||label===name+name;
const exact=rows.map((row,index)=>({row,index,title:title(row)})).filter(entry=>exactName(entry.title)
    ||[...entry.row.querySelectorAll('*')].some(el=>exactName(String(el.textContent||'').replace(/\s+/g,' ').trim())));
const highlighted=rows.map((row,index)=>row.hasAttribute('data-highlighted')||row.getAttribute('aria-current')==='true'?index:-1).filter(index=>index>=0);
return {open:rows.length>0,visible_count:rows.length,exact_count:exact.length,exact_index:exact.length===1?exact[0].index:-1,
exact_navigation_button:exact.length===1&&exact[0].row.matches('[data-mention-list-scroll-area] button[data-list-navigation-item="true"]'),
exact_highlighted:exact.length===1&&(exact[0].row.hasAttribute('data-highlighted')||exact[0].row.getAttribute('aria-current')==='true'),
highlighted_index:highlighted.length===1?highlighted[0]:-1,highlighted_title:highlighted.length===1?titles[highlighted[0]]:null,titles};
JS));
    }

    /** Activate exactly one matching connector row after connectorMenuExpression proves uniqueness. */
    public static function connectorSelectExpression(string $connectorName): string
    {
        $name = json_encode($connectorName, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        return self::wrap(str_replace('__CONNECTOR__', $name, <<<'JS'
const name=__CONNECTOR__;
const rows=[...document.querySelectorAll('.__menu-item[tabindex="0"],[data-mention-list-scroll-area] button[data-list-navigation-item="true"]')].filter(visible);
const title=el=>String((el.innerText||el.textContent||'').split('\n')[0]||'').replace(/\s+/g,' ').trim();
const exactName=label=>label===name||label===name+' '+name||label===name+name;
const exact=rows.filter(row=>exactName(title(row))
    ||[...row.querySelectorAll('*')].some(el=>exactName(String(el.textContent||'').replace(/\s+/g,' ').trim())));
if(exact.length!==1)return {ok:false,code:exact.length>1?'CHATGPT_CONNECTOR_DUPLICATE':'CHATGPT_CONNECTOR_MISSING',count:exact.length};
exact[0].scrollIntoView({block:'nearest',inline:'nearest'});exact[0].click();return {ok:true};
JS));
    }

    /** Composer text with connector pills removed, used for exact prompt-integrity verification. */
    public static function promptTextExpression(): string
    {
        return self::wrap(<<<'JS'
const composer=visibleOne(COMPOSER);if(!composer)return {ok:false,text:''};
const clone=composer.cloneNode(true);clone.querySelectorAll('[data-id^="plugin:"][data-keyword],[app-mention-path^="app://"][app-mention-display-name][data-prompt-link-href^="app://"],[data-inline-selection-pill-cursor-target]').forEach(el=>el.remove());
const text=('value'in clone?String(clone.value||''):[...clone.childNodes].map(node=>String(node.textContent||'')).join('\n'));
return {ok:true,text:text.replace(/\u00a0/g,' ').replace(/^\s+/,'')};
JS);
    }

    public static function sendReadinessExpression(): string
    {
        return self::wrap(<<<'JS'
const composer=visibleOne(COMPOSER);if(!composer)return {ready:false,code:'CHATGPT_EDITOR_MISSING'};
const buttons=composerSendButtons(composer);
if(buttons.length!==1)return {ready:false,code:buttons.length?'CHATGPT_SEND_BUTTON_AMBIGUOUS':'CHATGPT_SEND_BUTTON_MISSING'};
const button=buttons[0];
if(button.disabled||button.getAttribute('aria-disabled')==='true')return {ready:false,code:'CHATGPT_SEND_DISABLED'};
return {ready:true,code:null};
JS);
    }

    /** Follow the newest visible ChatGPT turn only through its uniquely named scroll control. */
    public static function followLatestMessageExpression(): string
    {
        return <<<'JS'
(()=>{
  const buttons=[...document.querySelectorAll('button[aria-label="滚动到底部"],button[aria-label="Scroll to bottom"]')]
    .filter(button=>{
      if(button.closest('[data-chatgpt-composer]'))return false;
      const style=getComputedStyle(button),rect=button.getBoundingClientRect();
      return style.display!=='none'&&style.visibility!=='hidden'&&Number(style.opacity||1)!==0
        &&rect.width>0&&rect.height>0&&rect.bottom>0&&rect.top<innerHeight;
    });
  if(buttons.length!==1)return {followed:false,reason:buttons.length===0?'absent':'ambiguous'};
  buttons[0].click();
  return {followed:true};
})()
JS;
    }

    /**
     * Select a compatible file input owned by the active composer and verify exact finished uploads.
     * Hidden inputs remain eligible; attachment count, accept types and form ownership must agree.
     * @param list<string> $filenames Exact attachment basenames expected in the composer.
     * @param bool $imageOnly Require decoded image previews instead of document cards.
     * @param list<string> $mimeTypes Optional validated MIME types, in filename order.
     * @return string Read-only DOM expression; names alone never prove upload completion.
     */
    public static function attachmentStateExpression(array $filenames, bool $imageOnly = true, array $mimeTypes = []): string
    {
        $expected = json_encode(array_values($filenames), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $mimes = json_encode(array_values($mimeTypes), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        return self::wrap(str_replace(['__EXPECTED__', '__IMAGE_ONLY__', '__MIME_TYPES__'], [$expected, $imageOnly ? 'true' : 'false', $mimes], <<<'JS'
const expected=__EXPECTED__;
const imageOnly=__IMAGE_ONLY__;
const mimeTypes=__MIME_TYPES__;
const composer=visibleOne(COMPOSER);if(!composer)return {ok:false,code:'CHATGPT_EDITOR_MISSING',input_count:0,matched_count:0,names:[]};
const form=composer.closest('form');if(!form)return {ok:false,code:'CHATGPT_EDITOR_FORM_MISSING',input_count:0,matched_count:0,names:[]};
const selectors=imageOnly
    ? ['input[type="file"][accept="image/*"]','input[data-testid="upload-photos-input"]','input[type="file"][accept*="image/"][accept*="video/"]']
    : ['input[type="file"]:not([accept])','input[type="file"][accept=""]','input[data-testid="upload-photos-input"]'];
const suffix=expected.length>1?'[multiple]':'';
const mimeByExtension={jpg:'image/jpeg',jpeg:'image/jpeg',png:'image/png',gif:'image/gif',webp:'image/webp',avif:'image/avif',svg:'image/svg+xml',bmp:'image/bmp',pdf:'application/pdf',txt:'text/plain',md:'text/markdown',json:'application/json',csv:'text/csv'};
const compatible=input=>{
    if(input.disabled||input.closest('fieldset[disabled]')||(expected.length>1&&!input.hasAttribute('multiple')))return false;
    const accepts=String(input.getAttribute('accept')||'').split(',').map(value=>value.trim().toLowerCase()).filter(Boolean);
    return !accepts.length||expected.every((name,index)=>{
        const lower=String(name).toLowerCase(),extension=lower.split('.').pop();
        const mime=String(mimeTypes[index]||mimeByExtension[extension]||'').toLowerCase();
        return accepts.some(type=>type==='*/*'||(type.startsWith('.')?lower.endsWith(type)
            :type.endsWith('/*')?mime.startsWith(type.slice(0,-1)):mime===type));
    });
};
const ownedInputs=[...document.querySelectorAll('input[type="file"]')].filter(input=>input.form===form);
const eligible=ownedInputs.filter(compatible);
const formSelector=form.id&&document.querySelectorAll('form[id="'+CSS.escape(form.id)+'"]').length===1
    ?'form[id="'+CSS.escape(form.id)+'"]':form.matches('[data-chatgpt-composer]')?'form[data-chatgpt-composer]':null;
const uniqueSelector=(input,selector)=>{
    const scoped=form.contains(input)&&formSelector?formSelector+' '+selector:selector;
    for(const query of [scoped,scoped+':not([disabled])']){
        const all=[...document.querySelectorAll(query)];
        if(all.length===1&&all[0]===input)return query;
    }
    return null;
};
let inputSelector=null,inputCount=0;
for(const selector of selectors){
    const query=selector+suffix;
    const owned=eligible.filter(input=>input.matches(query));if(!owned.length)continue;
    inputCount=Math.max(inputCount,owned.length);
    if(owned.length===1)inputSelector=uniqueSelector(owned[0],query);
    if(inputSelector){inputCount=1;break;}
}
// Accept lists can change without changing the native upload capability. A fallback
// still requires one compatible owned input and a globally unique exact selector.
if(!inputSelector&&eligible.length===1){
    const input=eligible[0],accept=input.getAttribute('accept');
    let query='input[type="file"]'+suffix+(accept===null?':not([accept])':'[accept="'+CSS.escape(accept)+'"]');
    if(!form.contains(input)&&form.id)query+='[form="'+CSS.escape(form.id)+'"]';
    inputSelector=uniqueSelector(input,query);
    if(inputSelector)inputCount=1;
}
if(!inputSelector)inputCount=Math.max(inputCount,eligible.length);
const norm=value=>String(value||'').replace(/\s+/g,' ').trim();
const accessibleName=el=>{
    const aria=norm(el.getAttribute('aria-label'));if(aria)return aria;
    const labelled=norm(el.getAttribute('aria-labelledby'));
    if(labelled){const text=labelled.split(/\s+/).map(id=>document.getElementById(id)?.textContent||'').join(' ');if(norm(text))return norm(text);}
    const title=norm(el.getAttribute('title'));if(title)return title;
    return norm(el.textContent);
};
const groups=[...form.querySelectorAll('[role="group"]')].filter(visible);
// Current ChatGPT uploads use named cards rather than groups. For image-only
// turns require a decoded preview; file cards may have no image element.
// In either case the card must belong to the composer attachment tray.
const attachmentTray=form.querySelector('[data-composer-attachments]');
const attachmentCards=attachmentTray?[...attachmentTray.querySelectorAll('[role="button"][aria-label]')].filter(visible).filter(row=>{
    const name=norm(row.getAttribute('aria-label'));
    if(!expected.includes(name))return false;
    if(!imageOnly)return true;
    return [...row.querySelectorAll('img[alt]')].some(img=>norm(img.getAttribute('alt'))===name&&img.complete&&img.naturalWidth>0);
}):[];
const proofs=[...groups,...attachmentCards];
const names=proofs.map(accessibleName).filter(Boolean);
const counts=expected.map(name=>names.filter(actual=>actual===name).length);
const matched=counts.filter(count=>count===1).length;
const duplicate=counts.some(count=>count>1);
const pending=proofs.filter(row=>expected.includes(accessibleName(row))).some(row=>
    [...row.querySelectorAll('[aria-busy="true"],[role="progressbar"],[class*="animate-spin"]')].some(visible))
    ||!!attachmentTray&&[...attachmentTray.querySelectorAll('[aria-busy="true"],[role="progressbar"],[class*="animate-spin"]')].some(visible);
const uploadError=!!attachmentTray&&[...attachmentTray.querySelectorAll('[role="alert"],[aria-invalid="true"],[data-upload-error="true"]')].some(visible);
const alerts=[...document.querySelectorAll('[role="alert"]')].filter(visible).map(el=>norm(el.innerText||el.textContent)).filter(Boolean).slice(-5);
return {ok:matched===expected.length&&!duplicate&&!pending&&!uploadError,input_count:inputCount,input_selector:inputSelector,
    owned_input_count:ownedInputs.length,compatible_input_count:eligible.length,
    matched_count:matched,expected_count:expected.length,duplicate,upload_pending:pending,upload_error:uploadError,names:names.slice(-20),alerts};
JS));
    }

    /** Focus the exact active send button so CDP Enter follows ChatGPT's native keyboard path. */
    public static function focusSendExpression(): string
    {
        return self::wrap(<<<'JS'
const composer=visibleOne(COMPOSER);if(!composer)return {ok:false,code:'CHATGPT_EDITOR_MISSING'};
const buttons=composerSendButtons(composer);
if(buttons.length!==1)return {ok:false,code:buttons.length?'CHATGPT_SEND_BUTTON_AMBIGUOUS':'CHATGPT_SEND_BUTTON_MISSING'};
const button=buttons[0];
if(button.disabled||button.getAttribute('aria-disabled')==='true')return {ok:false,code:'CHATGPT_SEND_DISABLED'};
button.focus();return {ok:document.activeElement===button,code:document.activeElement===button?null:'CHATGPT_SEND_FOCUS_FAILED'};
JS);
    }

    public static function sendExpression(): string
    {
        return self::wrap(<<<'JS'
const composer=visibleOne(COMPOSER);if(!composer)return {ok:false,code:'CHATGPT_EDITOR_MISSING'};
const buttons=composerSendButtons(composer);
if(buttons.length!==1)return {ok:false,code:buttons.length?'CHATGPT_SEND_BUTTON_AMBIGUOUS':'CHATGPT_SEND_BUTTON_MISSING'};
const button=buttons[0];
if(button.disabled||button.getAttribute('aria-disabled')==='true')return {ok:false,code:'CHATGPT_SEND_DISABLED'};
button.click();return {ok:true};
JS);
    }

    /** Inspect only a one-shot ChatGPT approval dialog for the exact configured Connector. */
    public static function toolConfirmationExpression(string $connectorName): string
    {
        $name = json_encode($connectorName, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        return self::wrap(str_replace('__CONNECTOR__', $name, <<<'JS'
const name=__CONNECTOR__;
const norm=value=>String(value||'').replace(/\s+/g,' ').trim();
const text=el=>norm(el.innerText||el.textContent||'');
const matchesTitle=value=>value.includes(`Allow ChatGPT to use ${name}?`)
    ||value.includes(`允许 ChatGPT 使用 ${name} 吗？`)
    ||value.includes(`允许 ChatGPT 使用 ${name} 吗?`)
    ||value.includes(`允许 ChatGPT 使用 ${name}？`);
const dialogs=[...document.querySelectorAll('[role="dialog"],[data-testid="tool-approval-card"]')]
    .filter(el=>visible(el)&&matchesTitle(text(el)));
if(dialogs.length!==1)return {visible:dialogs.length>0,dialog_count:dialogs.length,one_shot_count:0,deny_count:0,persistent_count:0};
const buttons=[...dialogs[0].querySelectorAll('button')].filter(visible);
const labels=buttons.map(button=>norm(button.getAttribute('aria-label')||button.innerText||button.textContent||''));
const oneShot=labels.filter(label=>/^(Allow(?: once)?|Allow for this time|允许|允许一次|仅允许一次|本次允许)$/i.test(label));
const deny=labels.filter(label=>/^(Deny|拒绝|不允许)$/i.test(label));
const persistent=labels.filter(label=>/^(Always allow|始终允许|总是允许|一直允许)$/i.test(label));
return {visible:true,dialog_count:1,one_shot_count:oneShot.length,deny_count:deny.length,persistent_count:persistent.length,labels};
JS));
    }

    /** Focus the exact current-action approval button; activation is performed through native CDP Enter. */
    public static function focusToolConfirmationExpression(string $connectorName): string
    {
        $name = json_encode($connectorName, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        return self::wrap(str_replace('__CONNECTOR__', $name, <<<'JS'
const name=__CONNECTOR__;
const norm=value=>String(value||'').replace(/\s+/g,' ').trim();
const text=el=>norm(el.innerText||el.textContent||'');
const matchesTitle=value=>value.includes(`Allow ChatGPT to use ${name}?`)
    ||value.includes(`允许 ChatGPT 使用 ${name} 吗？`)
    ||value.includes(`允许 ChatGPT 使用 ${name} 吗?`)
    ||value.includes(`允许 ChatGPT 使用 ${name}？`);
const dialogs=[...document.querySelectorAll('[role="dialog"],[data-testid="tool-approval-card"]')]
    .filter(el=>visible(el)&&matchesTitle(text(el)));
if(dialogs.length!==1)return {ok:false,code:dialogs.length>1?'CHATGPT_TOOL_CONFIRMATION_AMBIGUOUS':'CHATGPT_TOOL_CONFIRMATION_MISSING',dialog_count:dialogs.length};
const buttons=[...dialogs[0].querySelectorAll('button')].filter(visible);
const exact=buttons.filter(button=>/^(Allow(?: once)?|Allow for this time|允许|允许一次|仅允许一次|本次允许)$/i.test(norm(button.getAttribute('aria-label')||button.innerText||button.textContent||'')));
if(exact.length!==1)return {ok:false,code:'CHATGPT_TOOL_CONFIRMATION_ACTION_UNSAFE',count:exact.length};
exact[0].focus();
return {ok:document.activeElement===exact[0],code:document.activeElement===exact[0]?null:'CHATGPT_TOOL_CONFIRMATION_FOCUS_FAILED'};
JS));
    }

    /**
     * A DOM turn ID is safe to bind only when it is not a positional selector fallback.
     * @param string $identity Assistant ID returned by the DOM probe.
     * @return bool Whether the ID can anchor later observations.
     */
    public static function isStableAssistantId(string $identity): bool
    {
        return $identity !== '' && $identity !== 'group:assistant:'
            && preg_match('/^(?:conversation-turn-\d+|turn-\d+)$/D', $identity) !== 1;
    }

    /**
     * Keep the assistant ID first bound to this submission; a later node cannot replace it.
     * @param string $boundIdentity Previously bound ID, or empty before the first assistant appears.
     * @param array $snapshot Current response DOM observation.
     * @return string Bound ID, or empty while no stable assistant is visible.
     */
    public static function assistantIdentityForSnapshot(string $boundIdentity, array $snapshot): string
    {
        if ($boundIdentity !== '') return $boundIdentity;
        $candidate = (string) ($snapshot['response_id'] ?? '');
        return !empty($snapshot['response_present']) && self::isStableAssistantId($candidate) ? $candidate : '';
    }

    /**
     * Observe only the bound assistant after its stable identity has been recorded.
     * Native stream errors are scoped UI chrome; quoted transcript alerts remain content.
     * Active native recovery chrome is returned only as transport_recovery_visible metadata.
     * @param list<string> $baselineUserIds User IDs captured before submission.
     * @param list<string> $baselineAssistantIds Assistant IDs captured before submission.
     * @param string $continuationMarker Optional exact marker for a same-turn continuation.
     * @param string $boundResponseId Stable assistant ID from the first accepted observation.
     * @return string CDP expression returning the scoped response snapshot.
     */
    public static function responseExpression(array $baselineUserIds, array $baselineAssistantIds,
        string $continuationMarker = '', string $boundResponseId = ''): string
    {
        $users = json_encode(array_values($baselineUserIds), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $assistants = json_encode(array_values($baselineAssistantIds), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $userCount = count($baselineUserIds);
        $assistantCount = count($baselineAssistantIds);
        $marker = json_encode($continuationMarker, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $bound = json_encode($boundResponseId, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $body = <<<'JS'
const beforeUsers=new Set(__USERS__);const beforeAssistants=new Set(__ASSISTANTS__);
const beforeUserCount=__USER_COUNT__;const beforeAssistantCount=__ASSISTANT_COUNT__;
const expectedContinuationMarker=__MARKER__,boundResponseId=__BOUND__;
const users=userTurns().map((el,i)=>({el,id:turnId(el,i,'user')}));
const assistants=assistantTurns().map((el,i)=>({el,id:turnId(el,i,'assistant')}));
// Long Temporary Chats can virtualize the oldest turn while appending the newest one. In that
// transition the visible cardinality stays constant (for example u1,u2 -> u2,u3), so counts alone
// cannot prove submission. Prefer ChatGPT's stable turn identities when available, and use count
// growth only for weak fallback identities such as conversation-turn-N / turn-N.
const strong=id=>typeof id==='string'&&id!==''&&id!=='group:assistant:'&&id!=='group:user:'
    &&!/^conversation-turn-\d+$/.test(id)&&!/^turn-\d+$/.test(id);
const identityUsers=users.filter(entry=>strong(entry.id)&&!beforeUsers.has(entry.id));
const identityAssistants=assistants.filter(entry=>strong(entry.id)&&!beforeAssistants.has(entry.id));
const continuationUser=expectedContinuationMarker?users.findLast(entry=>String(entry.el.innerText||entry.el.textContent||'').includes(expectedContinuationMarker)):null;
const after=(left,right)=>Boolean(left&&right&&(left.compareDocumentPosition(right)&Node.DOCUMENT_POSITION_FOLLOWING));
const lastUser=users.at(-1)||null;
const countGrowthUser=users.length>beforeUserCount&&lastUser&&!beforeUsers.has(lastUser.id)?lastUser:null;
const uniqueUser=identityUsers.length===1?identityUsers[0]:null;
const baselineAssistant=assistants.filter(entry=>beforeAssistants.has(entry.id)).at(-1)||null;
const baselineVisible=users.some(entry=>beforeUsers.has(entry.id))||!!baselineAssistant;
const reorderedAgent=uniqueUser&&identityAssistants.length===1
    &&identityAssistants[0].el.closest('[data-content-search-turn-key]')?.querySelector('[data-chatgpt-agent-turn-start]')
    &&after(identityAssistants[0].el,uniqueUser.el);
// A changed ID alone can be an old turn remount. With stable visible cardinality,
// require an old anchor or the agent renderer's known assistant-before-user order.
const identityUser=uniqueUser&&((baselineVisible&&(!baselineAssistant||after(baselineAssistant.el,uniqueUser.el)))||reorderedAgent)
    ?uniqueUser:null;
const newUser=continuationUser||countGrowthUser||identityUser;
const answerCandidates=newUser?assistants.filter(entry=>after(newUser.el,entry.el)&&!beforeAssistants.has(entry.id)):[];
// The current ChatGPT renderer can virtualize the original user bubble after
// Connector tool results while keeping the new assistant message visible.
// A stable assistant identity absent from the pre-submit baseline is still
// sufficient on this fenced task page; never pick an old baseline answer.
// Agent pages can place the new assistant search unit before the user's search
// unit in DOM order, even though both have stable fresh identities. The exact
// baseline still fences out historical answers; accept that new assistant when
// the current user was observed, or when the user bubble was virtualized away.
const sameGroupResponseId=newUser?.id.startsWith('group:user:')
    ?'group:assistant:'+newUser.id.slice('group:user:'.length):null;
const sameGroupAnswers=sameGroupResponseId?assistants.filter(entry=>entry.id===sameGroupResponseId&&!beforeAssistants.has(entry.id)):[];
const unboundAnswer=sameGroupResponseId?(sameGroupAnswers.length===1?sameGroupAnswers[0]:null)
    :answerCandidates.length===1?answerCandidates[0]
        :((newUser||users.length===0)&&identityAssistants.length===1?identityAssistants[0]:null);
const boundMatches=boundResponseId?assistants.filter(entry=>entry.id===boundResponseId):[];
// ChatGPT can wrap an existing fallback agent turn in its persistent data-turn-key
// when generation settles. Keep the original bound identity only when its exact
// inner anchor survives in one fresh group belonging to this accepted user.
// A missing anchor, duplicate alias or another user's group is never a rebind.
const promotedAliases=boundResponseId&&/^fallback-turn-\d+$/.test(boundResponseId)&&boundMatches.length===0
    ?assistants.filter(entry=>entry.id.startsWith('group:assistant:')&&!beforeAssistants.has(entry.id)
        &&[...entry.el.querySelectorAll('[data-content-search-turn-key]')]
            .filter(node=>node.getAttribute('data-content-search-turn-key')===boundResponseId).length===1):[];
const promoted=promotedAliases.length===1&&promotedAliases[0].id===sameGroupResponseId
    ?{...promotedAliases[0],id:boundResponseId}:null;
const answer=boundResponseId?(boundMatches.length===1?boundMatches[0]:promoted):unboundAnswer;
const running=!!generationStop();
// An empty composer normally disables Send after generation. Its visible semantic
// identity, not enabledness, is the idle-state witness; Stop always wins above.
const endComposer=visibleOne(COMPOSER);
const endSend=endComposer?composerSendButtons(endComposer):[];
const sendButtonVisible=endSend.length===1&&endSend[0].getAttribute('data-testid')!=='stop-button'
    &&!/^(?:Stop|Stop generating|停止|停止生成)$/i.test(String(endSend[0].getAttribute('aria-label')||'').trim());
let markdown='';let markdownSegments=[];let visibleText='';let responseHtml='';let completion=false;let responsePresent=false;let contentPresent=false;let answerRootCount=0;let commentaryRootCount=0;let artifactCandidates=[];let commentaryBlocks=[];let stoppedThinkingVisible=false;let transportRecoveryVisible=false;
if(answer){
    responsePresent=true;
    // This localized native recovery panel is transport chrome, not model-authored progress.
    // It can coexist with active backend tools; its presence never stops or retries this turn.
    // Agent Activity precedes the final assistant search unit. Both belong to the same
    // response, and newer DIL responses may lack the legacy .markdown class.
    const rootSelector='.markdown,[data-markdown-text-style],[data-message-author-role="assistant"] .puik-root.not-markdown > [class*="_DilResponseRoot"]';
    const activityContainers=[...answer.el.querySelectorAll('[data-chatgpt-agent-turn-start]')]
        .map(marker=>marker.parentElement).filter(Boolean);
    const allMarkdown=[...answer.el.querySelectorAll(rootSelector)]
        .filter(candidate=>!candidate.parentElement?.closest(rootSelector))
        .filter(candidate=>{
            if(!answer.el.hasAttribute('data-content-search-turn-key')&&!answer.el.hasAttribute('data-turn-key'))return true;
            const unit=candidate.closest('[data-content-search-unit-key]');
            return unit?(unit.matches('[data-content-search-unit-key$=":assistant"]')
                ||[...unit.children].some(child=>child.getAttribute('data-conversation-role')==='assistant'))
                :candidate.closest('[data-conversation-role="assistant"]')!==null
                    ||activityContainers.some(container=>container.contains(candidate));
        });
    const statuses=[...answer.el.querySelectorAll('[data-streaming-response-status]')];
    const firstStatus=statuses[0]||null;
    const activityRoots=allMarkdown.filter(candidate=>candidate.getAttribute('data-markdown-text-tone')==='tertiary'
        && !candidate.closest('[data-content-search-unit-key]')
        && activityContainers.some(container=>container.contains(candidate)));
    const commentary=allMarkdown.filter(candidate=>activityRoots.includes(candidate)
        ||(!candidate.closest('[data-content-search-unit-key]')
            && activityContainers.some(container=>container.contains(candidate)))
        ||candidate.closest('[data-streaming-response-status]')!==null
        ||candidate.closest('[data-testid^="cot-v5"]')!==null
        ||candidate.closest('.loading-shimmer')!==null
        ||candidate.closest('.aria-busy')!==null
        ||(firstStatus!==null&&Boolean(candidate.compareDocumentPosition(firstStatus)&Node.DOCUMENT_POSITION_FOLLOWING)));
    let roots=allMarkdown.filter(candidate=>!commentary.includes(candidate));
    const stoppedAnswerRoots=roots;
    // While generation is running ChatGPT may mount a transient assistant shell containing only
    // status text such as image-analysis progress. It can persist across several polls and then be
    // replaced wholesale by the real answer, so it must never become an irrevocable text prefix.
    if(allMarkdown.length===0&&!running){
        const fallback=answer.el.querySelector('[data-message-author-role="assistant"],[data-conversation-role="assistant"]')
            ||(answer.el.hasAttribute('data-turn-key')?null:answer.el);
        if(fallback)roots=[fallback];
    }
    answerRootCount=roots.length;commentaryRootCount=commentary.length;
    // A cot-v5 Markdown block can be a public reasoning summary when ChatGPT actually
    // renders it. Check the whole ancestor chain for all progress text so hidden or
    // collapsed content is never relayed merely because its own box has dimensions.
    const publiclyVisible=root=>{
        if(!visible(root))return false;
        const box=root.getBoundingClientRect();
        for(let node=root;node&&answer.el.contains(node);node=node.parentElement){
            if(node.hasAttribute('hidden')||node.hasAttribute('inert')||node.getAttribute('aria-hidden')==='true')return false;
            const style=getComputedStyle(node);
            if(style.display==='none'||style.visibility==='hidden'||Number(style.opacity||1)===0)return false;
            if(node!==root&&/(?:hidden|clip)/.test(style.overflowX+' '+style.overflowY)){
                const clip=node.getBoundingClientRect();
                if(box.right<=clip.left||box.left>=clip.right||box.bottom<=clip.top||box.top>=clip.bottom)return false;
            }
        }
        return true;
    };
    const notHidden=root=>{
        for(let node=root;node&&answer.el.contains(node);node=node.parentElement){
            if(node.hasAttribute('hidden')||node.hasAttribute('inert')||node.getAttribute('aria-hidden')==='true')return false;
            const style=getComputedStyle(node);
            if(style.display==='none'||style.visibility==='hidden'||Number(style.opacity||1)===0)return false;
        }
        return true;
    };
    transportRecoveryVisible=[...answer.el.querySelectorAll('.text-chatgpt-recovery')]
        .some(node=>nativeRecoveryPanel(node)!==null&&publiclyVisible(node));
    roots=roots.filter(notHidden);
    answerRootCount=roots.length;
    // Forward only rendered public progress prose, never hidden chain-of-thought panels,
    // loading animations, action buttons or the final answer itself.
    commentaryBlocks=commentary.map((root,index)=>{
        const publicSummary=activityRoots.includes(root)||root.closest('[data-testid^="cot-v5"]')!==null;
        if(!publiclyVisible(root)||nativeRecoveryPanel(root)||root.closest('.loading-shimmer,.aria-busy'))return null;
        const anchor=root.closest('[data-item-anchor]')||root;
        const followingSibling=(()=>{for(let sibling=anchor.nextElementSibling;sibling;sibling=sibling.nextElementSibling){if(visible(sibling)&&String(sibling.innerText||sibling.textContent||'').trim())return true;}return false;})();
        // A following public status closes this prose even when no later answer paragraph exists yet.
        // Nested, hidden and native recovery chrome cannot settle model-authored progress.
        const followingStatus=statuses.some(next=>!root.contains(next)&&!next.contains(root)
            &&publiclyVisible(next)&&!nativeRecoveryPanel(next)&&after(root,next));
        const complete=!running||followingSibling||followingStatus||allMarkdown.some(next=>next!==root&&after(root,next));
        const text=(complete||activityRoots.includes(root))?toMarkdown(root,false):'';
        return text?{key:answer.id+':commentary:'+index,text,complete,public_summary:publicSummary,
            public_status:activityRoots.includes(root)}:null;
    }).filter(Boolean);
    // ChatGPT renders short, user-visible activity labels separately from Markdown. Keep
    // only text shown in the status chrome; the nested Markdown and private COT panels
    // are handled separately and must never be mistaken for a stable final answer.
    const statusBlocks=statuses.filter(node=>publiclyVisible(node)&&!nativeRecoveryPanel(node)).slice(0,16).map((node,index)=>{
        const copy=withoutNativeRecovery(node).cloneNode(true);
        copy.querySelectorAll('.markdown,[data-testid^="cot-v5"],button,svg,pre,code,.loading-shimmer,[aria-hidden="true"]').forEach(child=>child.remove());
        const label=String(copy.innerText||copy.textContent||'').replace(/\s+/g,' ').trim();
        if(!label||label.length>160)return null;
        return {key:answer.id+':status:'+index,text:label,complete:!running,public_status:true};
    }).filter(Boolean);
    // Action counters and current thought labels often live in a row anchor or its
    // screen-reader label rather than in the status container's own text node.
    const overlaps=(node,group)=>group.some(root=>node===root||node.contains(root)||root.contains(node));
    const statusRows=new Set();
    for(const candidate of answer.el.querySelectorAll('button,[role="status"],[aria-busy="true"],[data-testid*="cot"],[data-testid*="reason"],[data-testid*="thought"]')){
        if(!publiclyVisible(candidate)||nativeRecoveryPanel(candidate)||overlaps(candidate,roots)||overlaps(candidate,commentary))continue;
        const row=candidate.closest('button')||candidate.closest('[data-item-anchor]')||candidate;
        if(!publiclyVisible(row)||nativeRecoveryPanel(row)||overlaps(row,roots)||overlaps(row,commentary))continue;
        if(row.matches('button')&&!row.closest('[data-streaming-response-status]'))continue;
        statusRows.add(row);
        if(statusRows.size>=32)break;
    }
    [...statusRows].forEach((row,index)=>{
        const aria=String(row.getAttribute('aria-label')||'').trim();
        const accessible=[...row.querySelectorAll('.sr-only')].map(el=>String(el.textContent||'').replace(/\s+/g,' ').trim()).find(Boolean)||'';
        const label=(aria||accessible||transcriptVisibleText(row)).replace(/\s+/g,' ').trim();
        if(label&&label.length<=160)statusBlocks.push({key:answer.id+':activity:'+index,text:label,complete:!running,public_status:true});
    });
    commentaryBlocks.push(...statusBlocks);
    visibleText=roots.map(root=>transcriptVisibleText(root).replace(/\u00a0/g,' ').trim()).filter(Boolean).join('\n\n');
    markdown=roots.map(root=>toMarkdown(root,running)).filter(Boolean).join('\n\n');
    markdownSegments=semanticMarkdownSegments(roots,running,answer.id);
    responseHtml=roots.map(root=>String(withoutNativeRecovery(root).innerHTML||'')).join('');
    const pushArtifact=(href,filename,kind)=>{const existing=artifactCandidates.find(item=>item.filename===filename&&(item.href===href||item.href===''||href===''));if(existing){if(existing.href===''&&href!=='')existing.href=href;if(kind==='file_card')existing.kind='file_card';return;}artifactCandidates.push({href,filename,kind});};
    for(const anchor of roots.flatMap(root=>[...root.querySelectorAll('a')]).filter(visible)){
        const raw=String(anchor.getAttribute('href')||'').trim();
        const download=String(anchor.getAttribute('download')||'').trim();
        if(!artifactAnchor(anchor)||raw==='')continue;
        let href=raw;let base='';
        try{const url=new URL(raw,location.href);href=url.href;base=decodeURIComponent(url.pathname.split('/').filter(Boolean).at(-1)||'');}catch(_error){try{base=decodeURIComponent(raw.split(/[?#]/)[0].split('/').filter(Boolean).at(-1)||'');}catch(_ignored){}}
        const label=String(anchor.innerText||anchor.textContent||'').replace(/\s+/g,' ').trim();
        let filename=download||((base.includes('.')&&base.length<=180)?base:'')||((label&&label.length<=180)?label:'')||base||'artifact';
        filename=filename.replace(/[\r\n\t]/g,' ').trim().slice(0,180)||'artifact';
        pushArtifact(href,filename,'anchor');
    }
    const artifactRows=[...answer.el.querySelectorAll('*')].filter(row=>String(row.className||'').split(/\s+/).includes('group/artifact-row'));
    for(const row of artifactRows){
        const openers=[...row.querySelectorAll('button')].filter(button=>visible(button)&&String(button.className||'').split(/\s+/).includes('group/open-file'));
        if(openers.length!==1)continue;
        let filename=String(openers[0].getAttribute('aria-label')||'').replace(/[\r\n\t]/g,' ').trim();
        if(filename===''||filename.length>180)continue;
        pushArtifact('',filename,'file_card');
    }
    contentPresent=visibleText!==''||(markdown!==''&&roots.some(hasNonTextContent));
    const rendered=roots.length>0?roots[roots.length-1]:null;
    // The modern action bar may follow the answer search unit, but remains inside
    // the same stable assistant turn container. Never climb into another turn's controls.
    const actionHost=answer.el.closest('[data-turn-key],[data-content-search-turn-key],[data-turn-id],[data-turn-id-container]')||answer.el;
    const copies=[...actionHost.querySelectorAll('button[data-testid="copy-turn-action-button"],.turn-action-controls button')]
        .filter(button=>visible(button)&&!rendered?.contains(button)&&rendered!==null&&after(rendered,button));
    // Search-unit answers can have a plain Copy control in the immediately following sibling.
    // Keep that fallback local so another turn's action bar cannot complete this answer.
    const adjacent=answer.el.matches('[data-content-search-unit-key$=":assistant"]')?answer.el.nextElementSibling:null;
    const adjacentCopies=adjacent&&!adjacent.matches('[data-content-search-unit-key],[data-content-search-turn-key],[data-turn-key],[data-testid^="conversation-turn-"]')
        &&!adjacent.querySelector('[data-content-search-unit-key],[data-content-search-turn-key],[data-turn-key],[data-testid^="conversation-turn-"],.markdown,[data-markdown-text-style]')
        ?[...adjacent.querySelectorAll('button[aria-label]')].filter(button=>visible(button)
            &&/^(?:Copy|复制|複製)$/i.test(String(button.getAttribute('aria-label')||'').trim())):[];
    completion=rendered!==null&&(copies.length>0||adjacentCopies.length===1);
    // ChatGPT's terminal status must belong to this bound assistant turn. A quoted phrase in
    // the answer or public commentary is content, not evidence that generation stopped.
    // Match exact terminal labels from observed ChatGPT locale resources.
    const stoppedLabels=new Set([
        'ማሰብ ቆሟል','توقّف التفكير','Спря да мисли','চিন্তা থেমেছে','Razmišljanje prekinuto',
        'Pensament interromput','Přemýšlení zastaveno','Tænkning stoppet','Denken gestoppt',
        'Διακόπηκε η σκέψη','Stopped thinking','Dejó de pensar','Mõtlemine peatati',
        'فرایند فکر کردن متوقف شد','Ajattelu pysähtyi','Réflexion interrompue',
        'વિચાર કરવાનું બંધ થયું','सोचना बंद हो गया','Razmišljanje je zaustavljeno',
        'Gondolkodás leállítva','Մտածելու գործընթացը դադարեցվեց','Berhenti berpikir',
        'Hætti að hugsa','Elaborazione interrotta','思考を停止しました','ფიქრი შეწყდა',
        'Ойлану тоқтатылды','ಯೋಚನೆ ನಿಂತಿತು','생각 중지됨','Nustota mąstyti',
        'Pārtrauca domāt','Престана да размислува','ചിന്തിക്കുന്നത് നിർത്തി',
        'Бодохоо больсон','विचार करणे थांबवले','Berhenti berfikir',
        'စဉ်းစားမှု ရပ်သွားသည်','Sluttet å tenke','Het nadenken is gestopt',
        'ਸੋਚਣਾ ਰੁਕ ਗਿਆ','Przerwano myślenie','Pensamento interrompido',
        'Analiza s-a oprit','Рассуждение остановлено','Premýšľanie sa zastavilo',
        'Razmišljanje ustavljeno','Fikirka waa la joojiyay','Ndaloi së menduari',
        'Размишљање је прекинуто','Slutade att tänka','Imeacha kufikiri',
        'யோசனை நிறுத்தப்பட்டது','ఆలోచించడం ఆగింది','หยุดคิดแล้ว','Huminto sa pag-iisip',
        'Düşünme durdu','Обдумування зупинено','سوچنا بند کر دیا','Đã ngừng suy nghĩ',
        '已停止思考','已中斷思考','无法思考','無法思考'
    ]);
    const isStoppedLabel=value=>stoppedLabels.has(String(value||'').replace(/\s+/g,' ').trim());
    const userContentSelector='[data-user-message-bubble],[data-message-author-role="user"],[data-conversation-role="user"],[data-content-search-unit-key$=":user"]';
    const userContentRoots=[...answer.el.querySelectorAll(userContentSelector)];
    // This status can be painted in a background page without layout dimensions. Follow the
    // ancestor rendering chain instead of requiring a nonzero bounding box.
    const statusRendered=candidate=>{
        if(!candidate.isConnected)return false;
        for(let node=candidate;node;node=node.parentElement){
            const style=getComputedStyle(node);
            if(node.hidden||node.hasAttribute('inert')||node.getAttribute('aria-hidden')==='true'
                ||style.display==='none'||style.visibility==='hidden'||Number(style.opacity||1)===0)return false;
        }
        return true;
    };
    const isStoppedStatus=candidate=>{
        if(overlaps(candidate,stoppedAnswerRoots)||overlaps(candidate,commentary)||candidate.closest('pre,code,blockquote'))return false;
        // A grouped turn also contains its user's bubble; user-authored labels are never UI evidence.
        if(overlaps(candidate,userContentRoots)||candidate.closest(userContentSelector))return false;
        return statusRendered(candidate);
    };
    stoppedThinkingVisible=[...answer.el.querySelectorAll('[aria-label]')]
        .some(candidate=>isStoppedLabel(candidate.getAttribute('aria-label'))&&isStoppedStatus(candidate));
    if(!stoppedThinkingVisible){
        const walker=document.createTreeWalker(answer.el,NodeFilter.SHOW_TEXT);
        for(let node=walker.nextNode();node;node=walker.nextNode()){
            if(isStoppedLabel(node.textContent)&&node.parentElement&&isStoppedStatus(node.parentElement)){
                stoppedThinkingVisible=true;break;
            }
        }
    }
}
const pageText=String(document.body?.innerText||'').slice(-12000);
let error=null;
// A stream can fail after real Codex tool receipts without producing an assistant
// answer. ChatGPT renders that failure as an alert inside the current turn, not
// inside answer.el; otherwise the gateway waits for the full generation deadline.
const currentTurn=(newUser?.el||answer?.el)?.closest('[data-content-search-turn-key],[data-turn-key],[data-turn-id],[data-testid^="conversation-turn-"]')||null;
const streamErrorAlert=[...document.querySelectorAll('[role="alert"]')].find(candidate=>{
    const label=String(candidate.innerText||candidate.textContent||'').replace(/\s+/g,' ').trim();
    if(!/^(?:消息流中的错误|訊息串流發生錯誤|Error in message stream|Error in the message stream)(?:\s|$)/i.test(label)
        ||!visible(candidate))return false;
    // Quoted errors, embedded alerts and a user's bubble remain transcript content.
    // Only native chrome outside those roots can invalidate this owned page stream.
    if(candidate.closest('.markdown,[data-markdown-text-style],pre,code,blockquote,[data-user-message-bubble],[data-message-author-role="user"],[data-conversation-role="user"],[data-content-search-unit-key$=":user"]'))return false;
    if(currentTurn)return currentTurn.contains(candidate);
    return newUser?after(newUser.el,candidate):false;
});
const networkCard=networkErrorCard(newUser,answer);
const networkRetry=networkCard?.descriptor||null;
if(networkCard)error={code:'CHATGPT_NETWORK_ERROR',message:'ChatGPT 当前回答出现网络错误，页面提供重试。'};
else if(stoppedThinkingVisible)error={code:'CHATGPT_STOPPED_THINKING',message:'ChatGPT 已停止思考，当前回答不能确认为完成。'};
else if(/your session has expired|你的工作階段已過期|您的工作階段已過期|你的会话已过期|您的会话已过期/i.test(pageText))error={code:'CHATGPT_SESSION_EXPIRED',message:'ChatGPT 网页会话已过期。'};
else if(streamErrorAlert)error={code:'CHATGPT_MESSAGE_STREAM_ERROR',message:'ChatGPT 当前回答的消息流已断开，页面提供重试；原页面不能继续确认本回合。'};
else if(answer?.el.querySelector('[data-testid="regenerate-thread-error-button"]')&&/你提交的消息过长|你提交的訊息過長|the message you submitted was too long|message is too long|input is too long/i.test(visibleText))error={code:'CHATGPT_MESSAGE_TOO_LARGE',message:'ChatGPT 拒绝了当前消息：消息过长。请压缩上下文后继续。'};
else if(answer?.el.querySelector('[data-testid="regenerate-thread-error-button"]')||/something went wrong[\s\S]{0,300}help\.openai\.com/i.test(pageText))error={code:'CHATGPT_UPSTREAM_ERROR',message:'ChatGPT 页面显示生成错误。'};
return {url:String(location.href||''),temporary_chat:temporaryChatPage(location.pathname,location.search),new_user:!!newUser,new_user_id:newUser?.id||null,continuation_user_accepted:!!continuationUser,response_present:responsePresent,response_id:answer?.id||null,generation_running:running,send_button_visible:sendButtonVisible,send_button_disabled:sendButtonVisible?!!endSend[0].disabled:null,completion_action_visible:completion,stopped_thinking_visible:stoppedThinkingVisible,transport_recovery_visible:transportRecoveryVisible,network_retry:networkRetry,content_present:contentPresent,markdown,markdown_segments:markdownSegments,visible_text:visibleText,response_html:responseHtml,artifact_candidates:artifactCandidates,commentary_blocks:commentaryBlocks,answer_root_count:answerRootCount,commentary_root_count:commentaryRootCount,baseline_user_count:beforeUserCount,baseline_assistant_count:beforeAssistantCount,user_count:users.length,assistant_count:assistants.length,identity_new_user_count:identityUsers.length,identity_new_assistant_count:identityAssistants.length,answer_candidate_count:answerCandidates.length,error};
JS;
        return self::wrap(str_replace(
            ['__USERS__', '__ASSISTANTS__', '__USER_COUNT__', '__ASSISTANT_COUNT__', '__MARKER__', '__BOUND__'],
            [$users, $assistants, (string) $userCount, (string) $assistantCount, $marker, $bound],
            $body
        ));
    }

    /**
     * Activate only the exact native network-error control already observed for this response.
     * The caller must first reserve its durable retry intent; this recheck rejects a changed,
     * hidden, ambiguous, disabled or already-running page without submitting composer text.
     * @param array $expected Snapshot network_retry descriptor plus its 64-hex attempt_key.
     * @return string CDP expression returning clicked/code; a document-local latch also fences
     * duplicate evaluation of the same durable intent when its acknowledgement is lost.
     */
    public static function clickNetworkRetryExpression(array $expected): string
    {
        $expectedJson = json_encode($expected, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        return self::wrap(str_replace('__EXPECTED__', $expectedJson, <<<'JS'
const expected=__EXPECTED__;
if(!/^[a-f0-9]{64}$/.test(String(expected.attempt_key||'')))return {clicked:false,code:'invalid_intent'};
if(generationStop())return {clicked:false,code:'generation_running'};
const users=userTurns().map((el,i)=>({el,id:turnId(el,i,'user')}));
const assistants=assistantTurns().map((el,i)=>({el,id:turnId(el,i,'assistant')}));
const user=expected.user_id?users.find(entry=>entry.id===expected.user_id):null;
const answer=expected.response_id?assistants.find(entry=>entry.id===expected.response_id):null;
if((expected.user_id&&!user)||(expected.response_id&&!answer))return {clicked:false,code:'response_changed'};
if((user&&users.at(-1)?.id!==user.id)||(answer&&assistants.at(-1)?.id!==answer.id))return {clicked:false,code:'response_changed'};
const card=networkErrorCard(user,answer);
if(!card||card.descriptor.fingerprint!==expected.fingerprint
    ||card.descriptor.failure_id!==expected.failure_id)return {clicked:false,code:'error_changed'};
if(!card.descriptor.available)return {clicked:false,code:'retry_unavailable'};
const key=Symbol.for('rcmaker.network.retry.intents');
const intents=window[key]||(window[key]=new Set());
if(intents.has(expected.attempt_key))return {clicked:false,code:'already_attempted'};
intents.add(expected.attempt_key);
card.button.click();
return {clicked:true,code:null};
JS));
    }

    /**
     * Focus one exact artifact anchor or named download control in the current assistant answer.
     * Baseline turn IDs exclude historical answers; ambiguous matches fail without activating UI.
     * @param list<string> $baselineUserIds
     * @param list<string> $baselineAssistantIds
     * @param string $href Observed URL, or empty for a file card without a public href.
     * @param string $filename Exact observed artifact name.
     * @param string $boundResponseId Stable assistant identity bound to the submitted message.
     * @return string CDP expression returning ok/code/count/kind; activation uses native keyboard input.
     */
    public static function focusArtifactDownloadExpression(array $baselineUserIds, array $baselineAssistantIds,
        string $href, string $filename, string $boundResponseId = ''): string
    {
        $users = json_encode(array_values($baselineUserIds), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $assistants = json_encode(array_values($baselineAssistantIds), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $expectedHref = json_encode($href, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $expectedFilename = json_encode($filename, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $bound = json_encode($boundResponseId, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        return self::wrap(str_replace(
            ['__USERS__', '__ASSISTANTS__', '__HREF__', '__FILENAME__', '__BOUND__'],
            [$users, $assistants, $expectedHref, $expectedFilename, $bound],
            <<<'JS'
const beforeUsers=new Set(__USERS__);const beforeAssistants=new Set(__ASSISTANTS__);const expectedHref=__HREF__,expectedFilename=__FILENAME__,boundResponseId=__BOUND__;
const users=userTurns().map((el,i)=>({el,id:turnId(el,i,'user')}));
const assistants=assistantTurns().map((el,i)=>({el,id:turnId(el,i,'assistant')}));
const strong=id=>typeof id==='string'&&id!==''&&id!=='group:assistant:'&&id!=='group:user:'
    &&!/^conversation-turn-\d+$/.test(id)&&!/^turn-\d+$/.test(id);
const identityUsers=users.filter(entry=>strong(entry.id)&&!beforeUsers.has(entry.id));
const after=(left,right)=>Boolean(left&&right&&(left.compareDocumentPosition(right)&Node.DOCUMENT_POSITION_FOLLOWING));
const identityAssistants=assistants.filter(entry=>strong(entry.id)&&!beforeAssistants.has(entry.id));
const lastUser=users.at(-1)||null;
const countGrowthUser=users.length>beforeUsers.size&&lastUser&&!beforeUsers.has(lastUser.id)?lastUser:null;
const uniqueUser=identityUsers.length===1?identityUsers[0]:null;
const baselineAssistant=assistants.filter(entry=>beforeAssistants.has(entry.id)).at(-1)||null;
const baselineVisible=users.some(entry=>beforeUsers.has(entry.id))||!!baselineAssistant;
const reorderedAgent=uniqueUser&&identityAssistants.length===1
    &&identityAssistants[0].el.closest('[data-content-search-turn-key]')?.querySelector('[data-chatgpt-agent-turn-start]')
    &&after(identityAssistants[0].el,uniqueUser.el);
const identityUser=uniqueUser&&((baselineVisible&&(!baselineAssistant||after(baselineAssistant.el,uniqueUser.el)))||reorderedAgent)
    ?uniqueUser:null;
const newUser=countGrowthUser||identityUser;
const boundMatches=boundResponseId?assistants.filter(entry=>entry.id===boundResponseId):[];
const answerCandidates=newUser?assistants.filter(entry=>after(newUser.el,entry.el)&&!beforeAssistants.has(entry.id)):[];
const sameGroupResponseId=newUser?.id.startsWith('group:user:')
    ?'group:assistant:'+newUser.id.slice('group:user:'.length):null;
const sameGroupAnswers=sameGroupResponseId?assistants.filter(entry=>entry.id===sameGroupResponseId&&!beforeAssistants.has(entry.id)):[];
const answer=boundResponseId?(boundMatches.length===1?boundMatches[0]:null)
    :sameGroupResponseId?(sameGroupAnswers.length===1?sameGroupAnswers[0]:null)
    :answerCandidates.length===1?answerCandidates[0]
        :(!newUser&&users.length===0&&identityAssistants.length===1?identityAssistants[0]:null);
if(!answer)return {ok:false,code:'CHATGPT_ARTIFACT_RESPONSE_MISSING',count:0};
const allMarkdown=[...answer.el.querySelectorAll('.markdown,[data-markdown-text-style]')]
    .filter(candidate=>{
        if(!answer.el.hasAttribute('data-turn-key'))return true;
        const unit=candidate.closest('[data-content-search-unit-key]');
        return unit?(unit.matches('[data-content-search-unit-key$=":assistant"]')
            ||[...unit.children].some(child=>child.getAttribute('data-conversation-role')==='assistant'))
            :candidate.closest('[data-conversation-role="assistant"]')!==null;
    });
const statuses=[...answer.el.querySelectorAll('[data-streaming-response-status]')];
const firstStatus=statuses[0]||null;
const commentary=allMarkdown.filter(candidate=>candidate.closest('[data-streaming-response-status]')!==null
    ||candidate.closest('[data-testid^="cot-v5"]')!==null
    ||candidate.closest('.loading-shimmer')!==null
    ||candidate.closest('.aria-busy')!==null
    ||(firstStatus!==null&&Boolean(candidate.compareDocumentPosition(firstStatus)&Node.DOCUMENT_POSITION_FOLLOWING)));
let roots=allMarkdown.filter(candidate=>!commentary.includes(candidate));
if(allMarkdown.length===0){
    const fallback=answer.el.querySelector('[data-message-author-role="assistant"],[data-conversation-role="assistant"]')
        ||(answer.el.hasAttribute('data-turn-key')?null:answer.el);
    if(fallback)roots=[fallback];
}
const candidates=[];
for(const anchor of roots.flatMap(root=>[...root.querySelectorAll('a')]).filter(visible)){
    const raw=String(anchor.getAttribute('href')||'').trim();const download=String(anchor.getAttribute('download')||'').trim();
    if(!artifactAnchor(anchor)||raw==='')continue;
    let normalized=raw;let base='';
    try{const url=new URL(raw,location.href);normalized=url.href;base=decodeURIComponent(url.pathname.split('/').filter(Boolean).at(-1)||'');}catch(_error){try{base=decodeURIComponent(raw.split(/[?#]/)[0].split('/').filter(Boolean).at(-1)||'');}catch(_ignored){}}
    const label=String(anchor.innerText||anchor.textContent||'').replace(/\s+/g,' ').trim();
    let suggested=download||((base.includes('.')&&base.length<=180)?base:'')||((label&&label.length<=180)?label:'')||base||'artifact';
    suggested=suggested.replace(/[\r\n\t]/g,' ').trim().slice(0,180)||'artifact';
    if(normalized===expectedHref&&suggested===expectedFilename)candidates.push(anchor);
}
if(candidates.length>1)return {ok:false,code:'CHATGPT_ARTIFACT_LINK_AMBIGUOUS',count:candidates.length};
let target=candidates[0]||null;let kind=target?'anchor':null;
if(!target){
    const rowTargets=[];
    for(const row of [...answer.el.querySelectorAll('*')].filter(node=>String(node.className||'').split(/\s+/).includes('group/artifact-row'))){
        const buttons=[...row.querySelectorAll('button')].filter(visible);
        const openers=buttons.filter(button=>String(button.className||'').split(/\s+/).includes('group/open-file')&&String(button.getAttribute('aria-label')||'').trim()===expectedFilename);
        if(openers.length!==1)continue;
        const actions=buttons.filter(button=>button!==openers[0]&&/^(?:download(?: file)?|下载(?:文件)?|下載(?:檔案|文件)?)$/i.test(String(button.getAttribute('aria-label')||'').trim()));
        if(actions.length===1)rowTargets.push(actions[0]);
    }
    if(rowTargets.length===1){target=rowTargets[0];kind='artifact_row_download';}
    else if(rowTargets.length>1)return {ok:false,code:'CHATGPT_ARTIFACT_LINK_AMBIGUOUS',count:rowTargets.length};
}
if(!target){
    const cards=roots.flatMap(root=>[...root.querySelectorAll('button')]).filter(button=>visible(button)&&button.querySelector('[data-testid="library-file-icon"]'))
        .filter(button=>{const aria=String(button.getAttribute('aria-label')||'').replace(/\s+/g,' ').trim();const label=String(button.innerText||button.textContent||'').replace(/\s+/g,' ').trim();return aria===expectedFilename||label===expectedFilename||aria.endsWith(expectedFilename)||label.endsWith(expectedFilename);});
    if(cards.length!==1)return {ok:false,code:cards.length>1?'CHATGPT_ARTIFACT_LINK_AMBIGUOUS':'CHATGPT_ARTIFACT_LINK_MISSING',count:cards.length};
    target=cards[0];kind='inline_file_card';
}
target.focus();
return {ok:document.activeElement===target,code:document.activeElement===target?null:'CHATGPT_ARTIFACT_LINK_FOCUS_FAILED',count:1,kind};
JS
        ));
    }

    private static function wrap(string $body): string
    {
        $composer = json_encode(self::COMPOSER, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $user = json_encode(self::USER, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $assistant = json_encode(self::ASSISTANT, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $effortControl = json_encode(self::EFFORT_CONTROL, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        return '(function(){const COMPOSER=' . $composer . ',USER=' . $user . ',ASSISTANT=' . $assistant . ',MODEL_CONTROL=' . $effortControl . ';'
            . <<<'JS'
const visible=el=>{if(!el)return false;const s=getComputedStyle(el),r=el.getBoundingClientRect();return s.display!=='none'&&s.visibility!=='hidden'&&Number(s.opacity||1)!==0&&r.width>0&&r.height>0};
const visibleOne=selector=>[...document.querySelectorAll(selector)].find(visible)||null;
// Model controls can remain laid out while their advanced panel is inert. Keep
// this stricter check local to model selection; streaming extraction is unchanged.
const modelRendered=el=>{
    if(!visible(el))return false;
    for(let node=el;node;node=node.parentElement){
        if(node.matches('[hidden],[inert],[aria-hidden="true"],[role="menu"][data-state="closed"],[data-testid="composer-intelligence-picker-content"][data-state="closed"]'))return false;
        const style=getComputedStyle(node);
        if(style.display==='none'||style.visibility==='hidden'||Number(style.opacity||1)===0)return false;
    }
    return true;
};
// Accessible family captions belong to this picker, not its tooltip or conversation.
const modelFamilyLabel=el=>{
    const norm=text=>String(text||'').replace(/\s+/g,' ').trim();
    const accessible=norm(el.getAttribute('aria-label'));
    if(accessible&&modelFamilyKey(accessible)!==null)return accessible;
    const full=norm(el.textContent);
    if(modelFamilyKey(full)!==null)return full;
    // Retirement/availability badges are separate descendants of the radio's title.
    // Take an actual title node, rather than stripping guessed dates or warning languages.
    const titles=[];
    const visit=node=>{
        if(node.nodeType===Node.TEXT_NODE){const text=norm(node.nodeValue||node.textContent);if(modelFamilyKey(text)!==null)titles.push(text);return;}
        if(node.nodeType!==Node.ELEMENT_NODE)return;
        const text=norm(node.textContent);
        if(modelFamilyKey(text)!==null){titles.push(text);return;}
        for(const child of node.childNodes)visit(child);
    };
    for(const child of el.childNodes)visit(child);
    return [...new Set(titles)].length===1?titles[0]:(accessible||full);
};
const modelFamilyKey=label=>{
    if(/^(?:Latest|最新)$/i.test(label))return 'latest';
    // GPT-6's website family radio may show only "6". Preserve its exact caption.
    const parts=/^(?:GPT[-\s]?)?(\d+(?:\.\d+)?)(?:\s+([A-Za-z]+(?:\s+[A-Za-z]+)*))?$/i.exec(label);
    if(!parts)return null;
    const suffix=String(parts[2]||'').toLowerCase();
    if(/\b(?:pro|instant|medium|high|leaving|retiring|deprecated)\b/i.test(suffix))return null;
    return 'gpt-'+parts[1]+(suffix?'-'+suffix.replace(/ /g,'-'):'');
};
// During rollout the same physical slider can carry both old and new wrappers.
// Deduplicate only that DOM identity, preserving ambiguity for separate sliders.
const modelEffortContainers=selector=>{
    const candidates=[...document.querySelectorAll(selector)].filter(modelRendered);
    return candidates.filter(outer=>!candidates.some(inner=>{
        if(outer===inner||!outer.contains(inner))return false;
        const a=outer.querySelectorAll('[role="slider"]'),b=inner.querySelectorAll('[role="slider"]');
        return a.length===1&&b.length===1&&a[0]===b[0];
    }));
};
const modelPickerMenu=()=>{
    const composer=visibleOne(COMPOSER),form=composer?.closest('form');
    const controls=[...(form?.querySelectorAll(MODEL_CONTROL)||[])].filter(modelRendered);
    if(controls.length!==1)return null;
    const owned=document.getElementById(controls[0].getAttribute('aria-controls')||'');
    if(owned&&modelRendered(owned)&&owned.matches('[role="menu"],[role="group"],[data-testid="composer-intelligence-picker-content"]'))return owned;
    const marker='[data-model-picker-view],[data-model-reasoning-effort-slider],[data-model-picker-power-slider],[data-reasoning-slider]';
    const menus=[...document.querySelectorAll('[role="menu"],[data-testid="composer-intelligence-picker-content"],[role="group"]')]
        .filter(el=>modelRendered(el)&&el.querySelector(marker));
    const innermost=menus.filter(el=>!menus.some(other=>other!==el&&el.contains(other)));
    return innermost.length===1?innermost[0]:null;
};
const generationStop=()=>{
    const legacy=visibleOne('[data-testid="stop-button"]');if(legacy)return legacy;
    const composer=visibleOne(COMPOSER),form=composer?.closest('form');if(!form)return null;
    return [...form.querySelectorAll('button[aria-label]')].filter(visible).find(button=>
        /^(?:Stop|Stop generating|停止|停止生成)$/.test(String(button.getAttribute('aria-label')||'').trim()))||null;
};
const temporaryChatPage=(path,search)=>{
    const flags=new URLSearchParams(search||'').getAll('temporary-chat');
    return flags.length===1&&flags[0]==='true'
        &&(path==='/'||/^\/c\/(?:local-chatgpt%3a)?[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}$/i.test(path));
};
const composerSendButtons=editor=>{
    const form=editor.closest('form');
    if(!form)return [];
    const legacy=[...form.querySelectorAll('[data-testid="send-button"]')].filter(visible);
    if(legacy.length)return legacy;
    return [...form.querySelectorAll('button[type="submit"]')].filter(visible);
};
// The account plan is read only from a plan badge inside the profile-menu button.
// New ChatGPT renders this button by accessible name and uses a text-xs badge;
// older builds use accounts-profile-button and text-caption-regular. Never infer
// a plan from a person's name, an upgrade offer, or conversation content.
// Collapsing the sidebar leaves its hydrated profile and badge in the DOM while
// the visible avatar has no badge. Visibility is therefore not account evidence.
// Ignore disabled loading placeholders; conflicting hydrated badges stay unknown.
const accountPlan=()=>{
    const profiles=document.querySelectorAll('[data-testid="accounts-profile-button"],button[aria-label="打开个人资料菜单"],button[aria-label="Open profile menu"],button[aria-label="Open account menu"]');
    const names={'free':'free','免费':'free','免费版':'free','go':'go','plus':'plus','pro':'pro','business':'business','team':'team','enterprise':'enterprise'};
    const plans=new Set();
    for(const profile of profiles){
        if(profile.disabled||profile.getAttribute('aria-disabled')==='true')continue;
        for(const badge of profile.querySelectorAll('.text-caption-regular.text-token-text-tertiary,.text-xs')){
            const plan=names[String(badge.textContent||'').trim().toLowerCase()];
            if(plan)plans.add(plan);
        }
    }
    return plans.size===1?[...plans][0]:'unknown';
};
const userTurns=()=>[...document.querySelectorAll(USER)]
    .filter(el=>el.hasAttribute('data-turn-key')||!el.closest('[data-turn-key]'));
const assistantTurns=()=>{
    const agent=[...document.querySelectorAll('[data-content-search-turn-key]')]
        .filter(el=>!el.closest('[data-turn-key]')
            &&el.querySelector('[data-chatgpt-agent-turn-start],[data-markdown-text-style="assistant-message"]'));
    const native=[...document.querySelectorAll(ASSISTANT)]
        .filter(el=>(el.hasAttribute('data-turn-key')||!el.closest('[data-turn-key]'))
            &&!agent.some(container=>container.contains(el)));
    return [...native,...agent].sort((left,right)=>left===right?0:
        (left.compareDocumentPosition(right)&Node.DOCUMENT_POSITION_FOLLOWING)?-1:1);
};
const turnId=(el,index,role)=>el.hasAttribute('data-turn-key')
    ?'group:'+role+':'+el.getAttribute('data-turn-key')
    :el.getAttribute('data-turn-id')||el.closest('[data-turn-id-container]')?.getAttribute('data-turn-id-container')
    ||el.querySelector('[data-chatgpt-selection-message-id]')?.getAttribute('data-chatgpt-selection-message-id')
    ||el.closest('[data-chatgpt-search-message-ids]')?.getAttribute('data-chatgpt-search-message-ids')?.split(/\s+/)[0]
    ||el.getAttribute('data-testid')||el.getAttribute('data-content-search-unit-key')
    ||el.getAttribute('data-content-search-turn-key')||('turn-'+index);
// Network-error cards are native alert chrome, never prose in an assistant answer or user bubble.
// The current stopped-generation error component is identified by its owned alert shell
// and sole non-navigation retry action, independently of its localized timeout/error text.
// Keep the old renderer's complete labels as a compatibility fallback, never as prose tests.
// Keep the observed latest response identity, including grouped agent turns, as the UI boundary.
const networkErrorCard=(user,answer)=>{
    const stateKey=Symbol.for('rcmaker.network.error.observation');
    const state=window[stateKey]||(window[stateKey]={card:null,sequence:0,failureId:''});
    if(generationStop()||(!user&&!answer)){state.card=null;return null;}
    const anchor=user?.el||answer?.el;
    const turnSelector='[data-turn-key],[data-content-search-turn-key],[data-turn-id],[data-testid^="conversation-turn-"]';
    const owned=new Set([user?.el?.closest(turnSelector),answer?.el?.closest(turnSelector)].filter(Boolean));
    const userSelector='[data-user-message-bubble],[data-message-author-role="user"],[data-conversation-role="user"],[data-content-search-unit-key$=":user"]';
    const rendered=el=>{
        if(!visible(el))return false;
        for(let node=el;node;node=node.parentElement){
            const style=getComputedStyle(node);
            if(node.hidden||node.hasAttribute('inert')||node.getAttribute('aria-hidden')==='true'
                ||style.display==='none'||style.visibility==='hidden'||Number(style.opacity||1)===0)return false;
        }
        return true;
    };
    const norm=value=>String(value||'').replace(/\s+/g,' ').trim();
    const candidates=[...document.querySelectorAll('[role="alert"]')].filter(card=>{
        if(!rendered(card)||card.closest('.markdown,[data-markdown-text-style],pre,code,blockquote,'+userSelector))return false;
        const owner=card.closest(turnSelector);
        if(owner&&!owned.has(owner)){
            // Agent pages group the current user/assistant under a stable data-turn-key,
            // with one fallback search turn nested inside it. Keep the exact outer group
            // and unique inner turn together; a foreign or historical nested group fails.
            const group=card.closest('[data-turn-key]');
            const inner=group?[...group.querySelectorAll('[data-content-search-turn-key]')]
                .filter(node=>node.closest('[data-turn-key]')===group):[];
            if(!group||!owned.has(group)||inner.length!==1||inner[0]!==owner)return false;
        }
        if(!owner&&(!card.closest('main')||!(anchor.compareDocumentPosition(card)&Node.DOCUMENT_POSITION_FOLLOWING)))return false;
        return true;
    }).map(card=>{
        const copy=card.cloneNode(true);copy.querySelectorAll('button,svg').forEach(child=>child.remove());
        const label=norm(copy.textContent);
        const buttons=[...card.querySelectorAll('button')].filter(rendered);
        const nativeRetryShell=String(card.tagName||'').toUpperCase()==='ASIDE'
            &&card.getAttribute('role')==='alert'&&card.classList.contains('text-danger')
            &&card.classList.contains('border-text-danger/20')&&buttons.length===1
            &&buttons[0].getAttribute('type')==='button'
            &&!card.querySelector('a[href],input,select,textarea,[contenteditable="true"]');
        const legacyLabel=/^(?:A network error occurred[.!]?\s*Please check your connection and try again[.!]?|Network error[.!]?|发生网络错误[。！]?\s*请检查(?:你的|您的)?(?:网络)?连接(?:，|,)?(?:然后)?(?:重试|再试一次)[。！]?|网络错误[。！]?(?:\s*请检查(?:你的|您的)?(?:网络)?连接(?:，|,)?(?:然后)?(?:重试|再试一次)[。！]?)?)$/i.test(label);
        if(!nativeRetryShell&&!legacyLabel)return null;
        const button=buttons.length===1&&(nativeRetryShell||/^(?:Retry|Try again|重试|重試|再试一次|再試一次)$/i.test(
            norm(buttons[0].getAttribute('aria-label')||buttons[0].textContent)))?buttons[0]:null;
        const buttonLabel=button?norm(button.getAttribute('aria-label')||button.textContent):'';
        const descriptor={user_id:user?.id||'',response_id:answer?.id||'',label,button_label:buttonLabel,
            available:!!button&&!button.disabled&&button.getAttribute('aria-disabled')!=='true'};
        descriptor.fingerprint=JSON.stringify([descriptor.user_id,descriptor.response_id,label,buttonLabel]);
        return {card,button,descriptor};
    }).filter(Boolean);
    if(candidates.length!==1){state.card=null;return null;}
    const observed=candidates[0];
    // A card survives repeated polls and worker reconnects. A genuinely new failure follows
    // a running/no-error snapshot or a new card node; it receives a separate bounded intent.
    if(state.card!==observed.card){
        state.card=observed.card;state.failureId=String(Date.now())+':'+String(++state.sequence);
    }
    observed.descriptor.failure_id=state.failureId;
    return observed;
};
const composerText=el=>('value'in el?String(el.value||''):[...el.childNodes].map(node=>String(node.textContent||'')).join('\n')).replace(/\u00a0/g,' ');
const artifactAnchor=el=>{if(!el||String(el.tagName||'').toLowerCase()!=='a')return false;const raw=String(el.getAttribute('href')||'').trim(),download=String(el.getAttribute('download')||'').trim();return raw!==''&&(download!==''||/^sandbox:\/+(?:mnt\/data)\//i.test(raw)||/\/mnt\/data\//i.test(raw)||/\/backend-api\/(?:files|estuary\/content)(?:\/|\?|$)/i.test(raw));};
// Recognize the observed native recovery shell by structure, independent of its language.
// Quoted markup in model/user text remains transcript content, even with matching classes.
const nativeRecoveryPanel=el=>{
    if(el?.nodeType!==Node.ELEMENT_NODE)return null;
    const panel=el.closest('[role="status"]');
    if(!panel||!panel.querySelector('.text-chatgpt-recovery')
        ||panel.closest('.markdown,[data-markdown-text-style],pre,code,blockquote,[data-user-message-bubble],[data-message-author-role="user"],[data-conversation-role="user"],[data-content-search-unit-key$=":user"]'))return null;
    return panel;
};
// Clone only roots containing platform recovery chrome; never alter the live conversation DOM.
const withoutNativeRecovery=root=>{
    if(nativeRecoveryPanel(root))return root.cloneNode(false);
    const panels=[...root.querySelectorAll('.text-chatgpt-recovery')].map(nativeRecoveryPanel).filter(Boolean);
    if(panels.length===0)return root;
    const copy=root.cloneNode(true);
    [...copy.querySelectorAll('.text-chatgpt-recovery')].map(nativeRecoveryPanel).filter(Boolean).forEach(panel=>panel.remove());
    return copy;
};
const ignored=el=>['BUTTON','SCRIPT','STYLE','SVG','SOURCE'].includes(el.nodeName)
    ||nativeRecoveryPanel(el)!==null
    ||el.hasAttribute?.('data-footnote-backref')
    ||el.getAttribute?.('aria-hidden')==='true'||el.hasAttribute?.('hidden')||el.hasAttribute?.('inert')
    ||(el.nodeType===Node.ELEMENT_NODE&&getComputedStyle(el).display==='none')
    ||(el.nodeType===Node.ELEMENT_NODE&&getComputedStyle(el).visibility==='hidden')
    ||(el.nodeType===Node.ELEMENT_NODE&&Number(getComputedStyle(el).opacity||1)===0);
// Preserve the live renderer's visibility rules when removing recovery chrome from a fallback
// container. Detached clone.textContent would also include its hidden/private descendants.
const transcriptVisibleText=root=>{
    if(nativeRecoveryPanel(root))return '';
    if(![...root.querySelectorAll('.text-chatgpt-recovery')].some(node=>nativeRecoveryPanel(node)))return String(root.innerText||'');
    const read=node=>{
        if(node.nodeType===Node.TEXT_NODE)return String(node.nodeValue||'');
        if(node.nodeType!==Node.ELEMENT_NODE||ignored(node))return '';
        if(node.tagName==='BR')return '\n';
        const text=[...node.childNodes].map(read).join('');
        return /^(?:DIV|P|LI|UL|OL|H[1-6]|PRE|BLOCKQUOTE|TABLE|TR)$/.test(node.tagName)?'\n'+text+'\n':text;
    };
    return read(root).replace(/\n{3,}/g,'\n\n').trim();
};
let markdownRunning=false;
const children=el=>[...el.childNodes].filter(n=>n.nodeType===Node.TEXT_NODE?String(n.nodeValue||'').length>0:(n.nodeType===Node.ELEMENT_NODE&&!ignored(n)));
const inline=(el,tail)=>{const cs=children(el);let out='';for(let i=0;i<cs.length;i++)out+=md(cs[i],tail&&i===cs.length-1);return out};
// Escape rendered prose, not authored code or math: literal Markdown punctuation is visible text.
const markdownLiteral=(value,inTable=false)=>{
    let text=String(value).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
        .replace(/[\\`*_~\[\]$]/g,'\\$&')
        .replace(/(^|\n)([ \t]*)(#{1,6}(?=\s|$)|[+-](?=\s)|-{3,}(?=[ \t]*(?:\n|$))|=+(?=[ \t]*(?:\n|$)))/g,'$1$2\\$3')
        .replace(/(^|\n)([ \t]*)(\d+)([.)])(?=\s)/g,'$1$2$3\\$4');
    return inTable?text:text.replace(/\|/g,'\\|');
};
const markdownTarget=value=>String(value).replace(/[\s<>\\()]/g,char=>char==='('?'%28':char===')'?'%29':encodeURIComponent(char));
const markdownTitle=el=>{const title=el.getAttribute('title');return title?' "'+String(title).replace(/\\/g,'\\\\').replace(/"/g,'\\"').replace(/[\r\n]/g,' ')+'"':'';};
// Blank lines are meaningful in code. Normalize block spacing only outside fenced regions,
// including fences nested in lists/quotes and an unfinished streaming code block.
const normalizeMarkdown=value=>{
    const lines=String(value).split('\n'),out=[];let fence=null,empty=0;
    for(const line of lines){
        const match=line.replace(/^(?:[ \t]*>[ \t]?)+/,'').trimStart().match(/^(`{3,}|~{3,})(.*)$/);
        const wasFenced=fence!==null;
        if(match){
            if(fence&&match[1][0]===fence[0]&&match[1].length>=fence.length&&match[2].trim()==='')fence=null;
            else if(!fence)fence=match[1];
        }
        if(!wasFenced&&!fence&&line.trim()===''){if(++empty>1)continue;}else empty=0;
        out.push(line);
    }
    return out.join('\n');
};
const fencedMarkdown=(value,language,tail)=>{
    const code=String(value);let length=3;
    for(const match of code.matchAll(/`+/g))length=Math.max(length,match[0].length+1);
    const fence='`'.repeat(length);
    return '\n\n'+fence+language+'\n'+code+(tail?'':'\n'+fence+'\n\n');
};
const inlineCode=(el,tail)=>{
    let value=String(el.textContent||'').replace(/[\r\n]+/g,' ');
    if(markdownRunning&&tail)value=value.replace(/[A-Za-z0-9_$]+$/,'');
    let length=1;for(const match of value.matchAll(/`+/g))length=Math.max(length,match[0].length+1);
    const fence='`'.repeat(length),pad=/^`|`$/.test(value)||(/^ | $/.test(value)&&/[^ ]/.test(value))?' ':'';
    return fence+pad+value+(tail?'':pad+fence);
};
const listNumbers=(list,items)=>{
    const parse=value=>/^-?\d+$/.test(String(value||''))&&Number.isSafeInteger(Number(value))?Number(value):null;
    let current=parse(list.getAttribute('start'))??1;
    return items.map(item=>{current=parse(item.getAttribute('value'))??current;return current++;});
};
const imageTarget=el=>{const src=String(el.getAttribute('src')||'').trim();return /^https?:\/\//i.test(src)&&!/\/backend-api\//i.test(src)?src:'';};
// Image/divider-only answers have no innerText. Require an actual visible content node;
// empty emphasis/code shells must not become completion evidence merely from delimiters.
const hasNonTextContent=root=>[root,...root.querySelectorAll('img'),...root.querySelectorAll('hr')].some(node=>{
    if(!['img','hr'].includes(node.tagName.toLowerCase()))return false;
    for(let parent=node;parent;parent=parent.parentElement){if(ignored(parent)||artifactAnchor(parent))return false;if(parent===root)break;}
    return true;
});
// KaTeX retains the author's TeX in MathML annotations. Read it only from a visible formula
// container so the visual/math-accessibility copies do not appear twice in the response.
const mathSource=el=>{
    const classes=[...el.classList];
    if(el.tagName.toLowerCase()!=='math'&&!classes.includes('katex')&&!classes.includes('katex-display'))return null;
    const annotation=[...el.querySelectorAll('annotation')].find(node=>node.getAttribute('encoding')==='application/x-tex');
    const value=String(annotation?.textContent||'').trim();
    return value?{value,display:classes.includes('katex-display')||el.getAttribute('display')==='block'}:null;
};
const footnoteLabel=value=>{let label=String(value);try{label=decodeURIComponent(label);}catch(_error){}return label.replace(/^#?user-content-fn-/,'').replace(/[\\\[\]\r\n]/g,'_');};
const listItem=(el,ordered,ordinal,tail)=>{
    // ChatGPT wraps code cards in several block containers. Collapse their repeated blank
    // boundaries before indenting, or the indentation prevents the final cleanup from seeing them.
    const value=normalizeMarkdown(inline(el,tail)).trim();
    const marker=ordered?String(ordinal)+'. ':'- ';
    const checkbox=[...el.querySelectorAll('input')].find(input=>input.getAttribute('type')==='checkbox'&&input.closest('li')===el&&!ignored(input));
    return marker+(checkbox?(checkbox.checked?'[x] ':'[ ] '):'')+value.replace(/\n/g,'\n'+' '.repeat(marker.length));
};
// HTML tables need explicit row/column boundaries; recursive cell text loses that structure.
// Keep one physical Markdown line per row, retaining inline formatting inside each cell.
const markdownTable=el=>{
    const rows=[...el.querySelectorAll('tr')].filter(row=>{
        if(row.closest('table')!==el)return false;
        for(let parent=row;parent&&parent!==el;parent=parent.parentElement)if(ignored(parent))return false;
        return true;
    }).map(row=>[...row.children].filter(cell=>['td','th'].includes(cell.tagName.toLowerCase())&&!ignored(cell)))
        .filter(cells=>cells.length>0);
    if(rows.length===0)return '';
    const width=Math.max(...rows.map(cells=>cells.length));
    const cellValue=cell=>inline(cell,false).trim().replace(/\r\n?/g,'\n').replace(/[ \t]*\n(?:[ \t]*\n)*[ \t]*/g,'<br>')
        .replace(/(\\*)\|/g,(_,slashes)=>slashes+(slashes.length%2===0?'\\':'\\\\')+'|');
    const line=values=>'| '+Array.from({length:width},(_,index)=>values[index]||'').join(' | ')+' |';
    const alignment=Array.from({length:width},(_,index)=>{
        const cell=rows[0][index];
        const align=cell?String(cell.getAttribute('align')||getComputedStyle(cell).textAlign||'').toLowerCase():'';
        return align==='center'?':---:':align==='right'?'---:':align==='left'?':---':'---';
    });
    const values=rows.map(cells=>line(cells.map(cellValue)));
    values.splice(1,0,line(alignment));
    const caption=[...el.children].find(child=>child.tagName.toLowerCase()==='caption'&&!ignored(child));
    return '\n\n'+(caption?inline(caption,false).trim()+'\n\n':'')+values.join('\n')+'\n\n';
};
const md=(node,tail=false)=>{
    if(node.nodeType===Node.TEXT_NODE){
        const text=String(node.nodeValue||'').replace(/\u00a0/g,' ');
        const inTable=!!node.parentElement?.closest('td,th');
        if(!markdownRunning||!tail||node.parentElement?.closest('pre,code,a'))return markdownLiteral(text,inTable);
        // A trailing raw link/autolink can hydrate into a download card and disappear from
        // Markdown. Hold that ambiguous suffix, rather than buffering the whole paragraph/list.
        const marker=/(?:^|[^\\])(!?\[|`+|\*+|<(?=[A-Za-z/])|https?:\/\/)/.exec(text);
        return markdownLiteral(marker?text.slice(0,marker.index+marker[0].length-marker[1].length):text,inTable);
    }
    if(node.nodeType!==Node.ELEMENT_NODE)return '';
    const el=node;
    if(ignored(el))return '';
    const tag=el.tagName.toLowerCase();
    if(tag==='br')return '  \n';
    if(tag==='hr')return '\n\n---\n\n';
    const formula=mathSource(el);
    if(formula)return formula.display?'\n\n$$\n'+formula.value+'\n$$\n\n':'$'+formula.value+'$';
    if(tag==='img'){
        if(markdownRunning&&tail)return '';
        const alt=markdownLiteral(String(el.getAttribute('alt')||''),!!el.closest('td,th')),src=imageTarget(el);
        return src?'!['+alt+']('+markdownTarget(src)+markdownTitle(el)+')':alt;
    }
    // A growing table can add columns and replace its header. Commit it only once the block
    // settles, as the semantic streaming ledger already does for the current draft tail.
    if(tag==='table')return markdownRunning&&tail?'':markdownTable(el);
    if(tag==='pre'){
        const code=el.querySelector('code')||el;
        const m=String(code.className||'').match(/(?:^|\s)language-([^\s]+)/);
        return fencedMarkdown(code.textContent,m?m[1]:'',tail);
    }
    // The streaming renderer can replace a partially typed identifier (e.g. q_ -> quantity).
    // Keep completed code words flowing, but hold the last identifier until its boundary settles.
    // Current ChatGPT code cards render a block-level CodeContent element without <pre>.
    // Treat that renderer as a fenced block so multiline snippets remain valid Markdown.
    if(tag==='code'&&[...el.classList].some(name=>name.startsWith('CodeContent-'))){
        return fencedMarkdown(el.textContent,'',tail);
    }
    if(tag==='code')return inlineCode(el,tail);
    if(tag==='strong'||tag==='b')return '**'+inline(el,tail)+(tail?'':'**');
    if(tag==='em'||tag==='i')return '*'+inline(el,tail)+(tail?'':'*');
    if(tag==='del'||tag==='s'||tag==='strike')return '~~'+inline(el,tail)+(tail?'':'~~');
    if(tag==='sup'&&[...el.querySelectorAll('a')].some(anchor=>anchor.hasAttribute('data-footnote-ref')))return inline(el,tail);
    if(tag==='sub'||tag==='sup'||tag==='u'||tag==='mark'){const value=inline(el,tail);return value?'<'+tag+'>'+value+(tail?'':'</'+tag+'>'):'';}
    if(tag==='a'){
        if(artifactAnchor(el))return '';
        if(markdownRunning&&tail)return '';
        const href=String(el.getAttribute('href')||'');
        if(el.hasAttribute('data-footnote-ref')&&/^#user-content-fn-/.test(href))return '[^'+footnoteLabel(href)+']';
        return href?'['+inline(el,tail)+']('+markdownTarget(href)+markdownTitle(el)+')':inline(el,tail);
    }
    if(/^h[1-6]$/.test(tag))return '#'.repeat(Number(tag[1]))+' '+inline(el,tail)+'\n\n';
    if(tag==='blockquote')return normalizeMarkdown(inline(el,tail)).trim().split('\n').map(v=>v?'> '+v:'>').join('\n')+'\n\n';
    if(tag==='section'&&el.hasAttribute('data-footnotes')){
        return '\n\n'+[...el.querySelectorAll('li')].filter(item=>/^user-content-fn-/.test(String(item.getAttribute('id')||'')))
            .map(item=>'[^'+footnoteLabel(item.getAttribute('id'))+']: '+normalizeMarkdown(inline(item,false)).trim().replace(/\n/g,'\n    ')).join('\n\n')+'\n\n';
    }
    if(tag==='ul'||tag==='ol'){
        const items=[...el.children].filter(c=>c.tagName.toLowerCase()==='li');
        // A nested list may follow text directly inside <li>; it still starts a new Markdown line.
        const boundary=el.closest('li')?'\n':'';
        const numbers=listNumbers(el,items);
        // Markdown renderers ignore later explicit numbers within one list. Split a reset
        // into a new list so HTML <li value> retains its visible numbering too.
        return boundary+items.map((li,i)=>(i?(tag==='ol'&&numbers[i]!==numbers[i-1]+1?'\n\n<!-- -->\n\n':'\n'):'')
            +listItem(li,tag==='ol',numbers[i],tail&&i===items.length-1)).join('')+'\n\n';
    }
    if(tag==='li')return inline(el,tail);
    const content=inline(el,tail);
    return ['p','div','section','article'].includes(tag)?content+'\n\n':content;
};
const toMarkdown=(root,running)=>{
    markdownRunning=!!running;
    // data-start/data-end and *_streamingTextBlock mark the growing source block, often a
    // whole multi-item list. Its visible prefix must stream while it is still growing.
    // md keeps unfinished delimiters open and buffers only ambiguous link/card tails.
    let value=normalizeMarkdown(md(root,!!running)).trim();
    // The current renderer exposes a zero-padded ordinal (001, 008, ...) before
    // hydrating the next list item. That placeholder is replaced by "8. text"
    // rather than appended, so it cannot be committed to an append-only stream.
    if(running)value=value.replace(/(?:^|\n)[ \t]*\d{1,6}[.)]?[ \t]*$/u,'').trimEnd();
    markdownRunning=false;
    return value;
};
// Source positions and original node identities survive file-card moves and root merging.
// Never use visible indices: removal of a preview would reassign its identity to the next paragraph.
const semanticMarkdownSegments=(roots,running,responseId)=>{
    const registry=globalThis.__RCMAKER_MARKDOWN_NODES__??={document,next:0,epoch:Date.now()+':'+Math.random().toString(36).slice(2),ids:new WeakMap()};
    const key=node=>{let id=registry.ids.get(node);if(id===undefined){id=registry.next++;registry.ids.set(node,id);}return responseId+':dom:'+registry.epoch+':'+id;};
    const blockTags=new Set(['address','article','aside','blockquote','div','dl','fieldset','figcaption','figure','footer','form','h1','h2','h3','h4','h5','h6','header','hr','li','main','nav','ol','p','pre','section','table','ul']);
    const range=el=>{
        const start=el.getAttribute?.('data-start'),end=el.getAttribute?.('data-end');
        if(start===null||start===undefined||end===null||end===undefined||String(start).trim()===''||String(end).trim()==='')return {};
        const source_start=Number(start),source_end=Number(end);
        return Number.isSafeInteger(source_start)&&Number.isSafeInteger(source_end)&&source_start>=0&&source_end>=source_start?{source_start,source_end}:{};
    };
    const text=node=>{
        if(node.nodeType===Node.TEXT_NODE)return String(node.nodeValue||'').replace(/\u00a0/g,' ');
        if(node.nodeType!==Node.ELEMENT_NODE||ignored(node)||node.tagName?.toLowerCase()==='a'&&artifactAnchor(node))return '';
        if(node.tagName?.toLowerCase()==='br')return '\n';
        if(node.tagName?.toLowerCase()==='img')return String(node.getAttribute('alt')||'');
        const formula=mathSource(node);if(formula)return formula.value;
        const value=children(node).map(text).join('');
        return blockTags.has(String(node.tagName||'').toLowerCase())?'\n'+value+'\n':value;
    };
    const linkState=node=>{
        const anchors=[...(node.tagName?.toLowerCase()==='a'?[node]:[]),...node.querySelectorAll('a')]
            .filter(anchor=>{
                if(artifactAnchor(anchor)||!String(anchor.textContent||'').trim())return false;
                for(let parent=anchor;parent;parent=parent.parentElement){if(ignored(parent))return false;if(parent===node)break;}
                return true;
            });
        const images=[...(node.tagName?.toLowerCase()==='img'?[node]:[]),...node.querySelectorAll('img')].filter(image=>{
            if(!imageTarget(image))return false;
            for(let parent=image;parent;parent=parent.parentElement){if(ignored(parent)||artifactAnchor(parent))return false;if(parent===node)break;}
            return true;
        });
        return {pending_links:anchors.some(anchor=>!String(anchor.getAttribute('href')||'').trim()),
            link_targets:[...anchors.map(anchor=>String(anchor.getAttribute('href')||'')).filter(Boolean),...images.map(imageTarget)]};
    };
    const segments=[];
    const push=(node,tag,markdown,group=null)=>{
        segments.push({key:key(node),tag,markdown,text:text(node).replace(/\n{3,}/g,'\n\n').trim(),
            ...range(node),...linkState(node),...(group?{group}:{}),streamable:false});
    };
    const flatten=root=>{
        if(nativeRecoveryPanel(root))return;
        // Only layout containers may be flattened. A root list/quote/math block owns its
        // delimiters too; flattening its children would drop them from the streamed answer.
        if(!['div','section','article'].includes(root.tagName.toLowerCase())||root.hasAttribute('data-footnotes')||mathSource(root)){
            push(root,root.tagName.toLowerCase(),toMarkdown(root,false));return;
        }
        const nodes=children(root);
        if(!nodes.some(node=>node.nodeType===Node.ELEMENT_NODE&&blockTags.has(node.tagName.toLowerCase()))){
            push(root,'root',toMarkdown(root,false));return;
        }
        let inline=[];
        const flush=()=>{
            if(inline.length===0)return;
            markdownRunning=false;
            const value=normalizeMarkdown(inline.map(node=>md(node,false)).join('')).trim();
            if(value){
                const first=inline[0],links=inline.filter(node=>node.nodeType===Node.ELEMENT_NODE).map(linkState);
                const ranges=inline.filter(node=>node.nodeType===Node.ELEMENT_NODE)
                    .flatMap(node=>[node,...node.querySelectorAll('[data-start][data-end]')]).map(range)
                    .filter(item=>'source_start' in item);
                segments.push({key:key(first),tag:'inline',markdown:value,text:inline.map(text).join('').trim(),
                    pending_links:links.some(item=>item.pending_links),link_targets:links.flatMap(item=>item.link_targets),
                    ...(ranges.length?{source_start:Math.min(...ranges.map(item=>item.source_start)),source_end:Math.max(...ranges.map(item=>item.source_end))}:{}),streamable:false});
            }
            inline=[];
        };
        for(const node of nodes){
            if(node.nodeType!==Node.ELEMENT_NODE||!blockTags.has(node.tagName.toLowerCase())){inline.push(node);continue;}
            flush();const tag=node.tagName.toLowerCase();
            const wrapper=['div','section','article'].includes(tag)&&!node.hasAttribute('data-footnotes')&&!mathSource(node)&&!('source_start' in range(node))
                &&children(node).some(child=>child.nodeType===Node.ELEMENT_NODE&&blockTags.has(child.tagName.toLowerCase()));
            if(wrapper){flatten(node);continue;}
            const items=tag==='ul'||tag==='ol'?[...node.children].filter(child=>child.tagName.toLowerCase()==='li'):[];
            if(items.length){
                const group=key(node)+':list';
                const numbers=listNumbers(node,items);
                let reset=0;
                items.forEach((item,index)=>{
                    markdownRunning=false;
                    const newList=tag==='ol'&&index>0&&numbers[index]!==numbers[index-1]+1;
                    if(newList)reset++;
                    push(item,tag+':item',(newList?'<!-- -->\n\n':'')+listItem(item,tag==='ol',numbers[index],false).trim(),group+(reset?':'+reset:''));
                });
            }else push(node,tag,toMarkdown(node,false));
        }
        flush();
    };
    roots.forEach(flatten);
    return segments.map((segment,index)=>({...segment,streamable:index<segments.length-1&&!segment.pending_links}));
};
JS
            . $body . '})()';
    }
}
