/* Pear owns the tabs; this shell restores known routes and coordinates the setup overlay. */
layui.use(['admin', 'layer'], function () {
    'use strict';
    const workspace = document.getElementById('gateway-workspace');
    const startup = document.getElementById('gateway-startup');
    const retry = document.getElementById('gateway-startup-retry');
    const routes = {overview: '运行准备', browser: '浏览器工作区', tasks: '任务中心', settings: '设置', diagnostics: '运行日志', network: '网络与代理'};
    const tabKey = 'content-pear-tab-page-data';
    const currentKey = tabKey + '-current';
    const workspaceKey = 'gateway.workspace.v1';
    const recoveryKey = 'gateway.setup.relogin';
    let setupLayer = null, setupFrame = null, checking = false, activeFrame = null, observer = null;

    function stored(key) {
        try { return localStorage.getItem(key); } catch (_) { return null; }
    }
    function store(key, value) {
        try { if (value === null) localStorage.removeItem(key); else localStorage.setItem(key, value); } catch (_) {}
    }

    /** Restore only bundled routes. Persisted strings never become arbitrary URLs or HTML titles. */
    function restoreTabs() {
        let saved = null;
        try { saved = JSON.parse(stored(workspaceKey)); } catch (_) {}
        if (!saved) {
            try { saved = {tabs: JSON.parse(sessionStorage.getItem(tabKey)), active: sessionStorage.getItem(currentKey)}; } catch (_) {}
        }
        const ids = ['overview'];
        for (const tab of Array.isArray(saved?.tabs) ? saved.tabs : []) {
            const id = typeof tab === 'string' ? tab : tab?.id;
            if (Object.hasOwn(routes, id) && !ids.includes(id)) ids.push(id);
        }
        const tabs = ids.map(id => ({id, title: I18n.t(routes[id]), url: 'pages/' + id + '.html', type: '_iframe', close: id !== 'overview'}));
        try {
            sessionStorage.setItem(tabKey, JSON.stringify(tabs));
            sessionStorage.setItem(currentKey, ids.includes(saved?.active) ? saved.active : 'overview');
        } catch (_) { /* Pear still opens its default tab when storage is unavailable. */ }
    }

    /** Pear's session state supplies order and active page; only route IDs are saved long term. */
    function rememberTabs() {
        const tabs = [...document.querySelectorAll('#content .layui-tab-title > li[lay-id]')]
            .map(node => node.getAttribute('lay-id')).filter(id => Object.hasOwn(routes, id));
        const active = document.querySelector('#content .layui-tab-title > li.layui-this')?.getAttribute('lay-id');
        if (tabs.length) store(workspaceKey, JSON.stringify({tabs, active: tabs.includes(active) ? active : 'overview'}));
    }

    /** Emit activation instead of reloading documents, preserving forms, selection and scroll position. */
    function activateCurrent(force) {
        const frame = document.querySelector('#content .layui-tab-item.layui-show > iframe');
        if (!frame || setupLayer !== null || document.hidden) return;
        if (!force && frame === activeFrame) return;
        activeFrame = frame;
        try { frame.contentWindow.dispatchEvent(new frame.contentWindow.CustomEvent('gateway:page-activate')); } catch (_) {}
    }

    function lockWorkspace(locked) {
        workspace.inert = locked;
        document.querySelector('.pear-collapsed-pe').inert = locked;
        if (locked) workspace.setAttribute('aria-hidden', 'true');
        else workspace.removeAttribute('aria-hidden');
    }

    /** Codex configuration proves only the last setup step, independently of gateway readiness. */
    function installed(codex) {
        return Gateway.codexConfigured(codex);
    }

    /** Open one mandatory wizard. Opening it never changes tasks, accounts or configuration. */
    function openSetup(reason) {
        if (reason === 'account') store(recoveryKey, 'pending');
        lockWorkspace(true);
        if (setupLayer !== null) return;
        setupLayer = -1;
        setupLayer = layui.layer.open({
            type: 2, title: I18n.t('初始化 RCMAKER'), skin: 'gateway-setup-layer',
            area: [Math.min(920, window.innerWidth - 32) + 'px', Math.max(280, window.innerHeight - 48) + 'px'],
            maxmin: false, resize: false, closeBtn: 0, shadeClose: false, shade: 0.45,
            content: 'pages/overview.html?setup=1',
            success: layer => {
                setupFrame = layer[0].querySelector('iframe');
                layer[0].setAttribute('role', 'dialog');
                layer[0].setAttribute('aria-modal', 'true');
                layer[0].setAttribute('aria-label', I18n.t('初始化 RCMAKER'));
                setupFrame.title = I18n.t('初始化 RCMAKER');
                setupFrame.addEventListener('load', () => setupFrame?.focus(), {once: true});
                startup.hidden = true;
            },
            end: () => { setupLayer = null; setupFrame = null; }
        });
    }

    /** Require current server evidence before removing the initialization barrier. */
    async function completeSetup() {
        if (checking) return;
        checking = true;
        try {
            const [state, codex] = await Promise.all([Gateway.prerequisites(), Gateway.request('/api/codex')]);
            if (!state.ready || !installed(codex)) {
                layui.layer.msg(state.ready ? I18n.t('请先完成 Codex 接入。') : state.message);
                return;
            }
            store(recoveryKey, null);
            if (setupLayer !== null) layui.layer.close(setupLayer);
            setupLayer = null; setupFrame = null;
            startup.hidden = true;
            lockWorkspace(false);
            Gateway.notifyStateChange('setup-complete');
            activateCurrent(true);
        } catch (error) { layui.layer.msg(error.message); }
        finally { checking = false; }
    }

    /** Resume incomplete preparation; a saved Codex route alone must never bypass earlier steps. */
    async function refreshSetup() {
        if (checking || setupLayer !== null) return;
        checking = true;
        try {
            const [codex, state] = await Promise.all([Gateway.request('/api/codex'), Gateway.prerequisites()]);
            if (state.ready && installed(codex) && !stored(recoveryKey) && !state.browser?.profile_reset_required) {
                startup.hidden = true; lockWorkspace(false); activateCurrent(true);
            } else openSetup(stored(recoveryKey) ? 'account' : 'initial');
        } catch (error) {
            startup.hidden = false;
            startup.querySelector('p').textContent = error.message;
            retry.hidden = false;
        } finally { checking = false; }
    }

    window.GatewaySetup = {open: openSetup, refresh: refreshSetup, isOpen: () => setupLayer !== null};
    window.addEventListener('message', event => {
        if (event.origin !== location.origin || event.source !== setupFrame?.contentWindow) return;
        if (event.data?.type === 'gateway-setup-complete') completeSetup();
    });
    window.addEventListener('gateway:state-change', event => {
        if (event.detail?.reason === 'profile-reset-started') store(recoveryKey, 'pending');
        if (event.detail?.reason === 'profile-reset') openSetup('account');
    });
    retry.addEventListener('click', () => { retry.hidden = true; refreshSetup(); });

    const language = document.getElementById('console-language');
    language.value = I18n.locale;
    language.addEventListener('change', async () => {
        language.disabled = true;
        try { await I18n.saveLocale(language.value); }
        catch (error) { language.value = I18n.locale; layui.layer.msg(error.message); }
        finally { language.disabled = false; }
    });

    /** Load saved language before Pear creates its initial tab labels. */
    async function start() {
        try { I18n.applySettings(await Gateway.request('/api/settings')); }
        catch (_) { /* Existing local locale remains useful while the service becomes ready. */ }
        restoreTabs();
        const response = await fetch('config/console.json', {cache: 'no-store'});
        if (!response.ok) throw new Error(I18n.t('控制台资源加载失败，请重试。'));
        const configuration = await response.json();
        configuration.tab.index.title = I18n.t(configuration.tab.index.title);
        layui.admin.render(configuration);
        observer = new MutationObserver(() => { rememberTabs(); activateCurrent(false); });
        observer.observe(document.getElementById('content'), {childList: true, subtree: true, attributes: true, attributeFilter: ['class']});
        document.getElementById('content').addEventListener('load', () => activateCurrent(true), true);
        window.addEventListener('focus', () => activateCurrent(true));
        document.addEventListener('visibilitychange', () => activateCurrent(true));
        window.addEventListener('pagehide', event => { rememberTabs(); if (!event.persisted) observer.disconnect(); });
        window.addEventListener('pageshow', event => { if (event.persisted) activateCurrent(true); });
        await refreshSetup();
    }
    start().catch(error => {
        startup.querySelector('p').textContent = error.message;
        retry.hidden = false;
        retry.onclick = () => location.reload();
    });
});
