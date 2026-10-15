/* Small shared API boundary for both pages. No credentials are cached or logged. */
(function () {
    'use strict';
    let bootstrapPromise;
    let modelProbe = {at: 0, ready: false, error: ''};
    let admittedState = null;
    let lastInteractionAt = 0;
    let pointerHeld = false;

    /** Pear keeps inactive documents alive; document.hidden alone cannot identify the active tab. */
    function isPageActive() {
        if (document.hidden) return false;
        try {
            if (window.parent !== window) {
                if (window.parent.document.hidden) return false;
                const frame = window.frameElement;
                if (!frame || frame.closest('[inert]')) return false;
                const tab = frame.closest('.layui-tab-item');
                if (tab && !tab.classList.contains('layui-show')) return false;
            }
        } catch (_) { return false; }
        return true;
    }

    /** Defer automatic DOM commits during interaction. Explicit actions never use this guard. */
    function automaticRefreshAllowed(options) {
        if (!isPageActive() || pointerHeld || Date.now() - lastInteractionAt < 850) return false;
        const selection = window.getSelection && window.getSelection();
        if (selection && !selection.isCollapsed && String(selection).length) return false;
        const focused = document.activeElement;
        if (focused && focused.matches && focused.matches('input,textarea,select,[contenteditable="true"],[role="textbox"]')) return false;
        if ([...document.querySelectorAll('.layui-form-selected,.layui-dropdown,.layui-laydate')]
            .some(node => node.getClientRects().length > 0)) return false;
        if (!(options && options.allowLayers) && document.querySelector('.layui-layer')) return false;
        return true;
    }

    /** Refresh state on activation without reloading an iframe or destroying unsaved input. */
    function onPageActivate(callback) {
        let lastActivation = 0;
        let disposed = false;
        const activate = () => {
            if (disposed || !isPageActive() || Date.now() - lastActivation < 250) return;
            lastActivation = Date.now();
            Promise.resolve().then(() => { if (!disposed && isPageActive()) return callback(); }).catch(() => {});
        };
        window.addEventListener('gateway:page-activate', activate);
        window.addEventListener('focus', activate);
        document.addEventListener('visibilitychange', activate);
        const dispose = () => {
            disposed = true;
            window.removeEventListener('gateway:page-activate', activate);
            window.removeEventListener('focus', activate);
            document.removeEventListener('visibilitychange', activate);
            window.removeEventListener('pagehide', hide);
            window.removeEventListener('pageshow', show);
        };
        const hide = event => { if (!event.persisted) dispose(); };
        const show = event => { if (event.persisted) activate(); };
        window.addEventListener('pagehide', hide);
        window.addEventListener('pageshow', show);
        return dispose;
    }

    /** Publish configuration changes as a refresh hint, never as readiness proof. */
    function notifyStateChange(reason) {
        let host = window;
        try { if (window.parent !== window && window.parent.Gateway) host = window.parent; } catch (_) {}
        const targets = new Set([window, host]);
        host.document.querySelectorAll('iframe').forEach(frame => {
            try { if (frame.contentWindow.Gateway) targets.add(frame.contentWindow); } catch (_) {}
        });
        targets.forEach(target => target.dispatchEvent(new target.CustomEvent('gateway:state-change', {detail: {reason}})));
    }

    // Selection, scrollbar dragging and wheel scrolling must remain stable across background updates.
    const interacted = () => { lastInteractionAt = Date.now(); };
    ['wheel', 'scroll', 'keydown', 'input'].forEach(type => document.addEventListener(type, interacted, {capture: true, passive: true}));
    document.addEventListener('pointerdown', () => { pointerHeld = true; interacted(); }, {capture: true, passive: true});
    document.addEventListener('pointerup', () => { pointerHeld = false; interacted(); }, {capture: true, passive: true});
    document.addEventListener('pointercancel', () => { pointerHeld = false; }, {passive: true});
    window.addEventListener('blur', () => { pointerHeld = false; });

    /** Fetch bounded JSON; failures never echo request bodies or upstream content. */
    async function jsonFetch(url, options, timeoutMs) {
        const controller = new AbortController();
        const timeout = setTimeout(() => controller.abort(), timeoutMs || 12000);
        try {
            const response = await fetch(url, Object.assign({cache: 'no-store', credentials: 'omit'}, options, {signal: controller.signal}));
            const payload = await response.json();
            if (!response.ok || (payload.code !== undefined && payload.code !== 0)) {
                const original = payload.message || (payload.error && payload.error.message) || I18n.t('本地服务未能完成操作。');
                const error = new Error(I18n.error(original, payload.code));
                error.originalMessage = original;
                error.code = payload.code || 'HTTP_ERROR';
                throw error;
            }
            return payload;
        } catch (error) {
            if (error.code) throw error;
            throw new Error(I18n.t('暂时无法取得本地服务响应。保存结果可能尚未确认，请重新载入设置核对。'));
        } finally {
            clearTimeout(timeout);
        }
    }

    /** Discover the separately bound API from the local static application's middleware. */
    async function bootstrap() {
        if (!bootstrapPromise) {
            bootstrapPromise = jsonFetch('/gateway-bootstrap.json').then(function (value) {
                const origin = new URL(value.api_origin);
                if (origin.protocol !== 'http:' || origin.hostname !== '127.0.0.1' || origin.origin !== value.api_origin) {
                    throw new Error(I18n.t('本机接口地址配置无效。'));
                }
                return value;
            }).catch(function (error) {
                bootstrapPromise = null;
                throw error;
            });
        }
        return bootstrapPromise;
    }

    /** Send one local request; mutation payloads exist only for the request lifetime. */
    async function request(path, body, timeoutMs) {
        const config = await bootstrap();
        const responsesApi = path.startsWith('/v1/');
        const headers = responsesApi ? {} : {'X-Gateway-Request': 'console'};
        const options = {method: body === undefined ? 'GET' : 'POST', headers};
        if (body !== undefined) {
            options.headers['Content-Type'] = 'application/json';
            options.body = JSON.stringify(body);
        }
        const data = (await jsonFetch(config.api_origin + path, options, timeoutMs)).data;
        if (path === '/api/settings' && data) I18n.applySettings(data);
        return data;
    }

    /** Fetch a local binary response with the same origin/header boundary as JSON APIs. */
    async function binary(path, options) {
        const config = await bootstrap();
        const controller = new AbortController();
        const timeout = setTimeout(() => controller.abort(), options && options.timeoutMs || 8000);
        try {
            const response = await fetch(config.api_origin + path, {
                method: 'GET', cache: 'no-store', credentials: 'omit',
                headers: {'X-Gateway-Request': 'console'}, signal: controller.signal
            });
            if (!response.ok) {
                let message = I18n.t(options && options.errorMessage || '本地页面画面暂不可用。');
                try { message = (await response.json()).message || message; } catch (_) {}
                const error = new Error(I18n.error(message, response.status === 404 ? 'NOT_READY' : 'HTTP_ERROR'));
                error.originalMessage = message;
                error.code = response.status === 404 ? 'NOT_READY' : 'HTTP_ERROR';
                throw error;
            }
            return {
                blob: await response.blob(),
                sequence: Number(response.headers.get('X-Browser-Sequence') || 0),
                surfaceId: response.headers.get('X-Browser-Surface') || ''
            };
        } finally {
            clearTimeout(timeout);
        }
    }

    /** Format a safe nonsecret revision summary for ordinary UI text. */
    function describe(row) {
        if (!row || row.mode === 'unconfigured') return I18n.t('尚未配置');
        if (row.mode === 'direct') return I18n.t('明确直连');
        return I18n.t('HTTP 代理 · ') + row.host + ':' + row.port;
    }

    function prerequisiteState(network, browser, settings, model) {
        const profileResetRequired = Boolean(browser && browser.profile_reset_required);
        const saved = network && network.saved;
        const active = network && network.active;
        const lastTest = network && network.last_test;
        const networkApplied = !!(saved && saved.mode !== 'unconfigured' && active && Number(active.id) === Number(saved.id));
        const networkTested = !!(networkApplied && lastTest && lastTest.target_id === 'model_site'
            && Number(lastTest.revision) === Number(saved.id) && lastTest.status === 'succeeded');
        const savedRevision = saved ? Number(saved.id) : 0;
        const tunnel = settings && settings.tunnel ? settings.tunnel : {};
        const connector = settings && settings.connector ? settings.connector : {};
        const runtimeReady = !!(browser && browser.setup && browser.setup.runtime && browser.setup.runtime.installed && tunnel.binary_installed);
        const browserBound = !!(browser && browser.status === 'ready' && savedRevision > 0 && Number(browser.network_revision) === savedRevision);
        const accountStatus = browser && browser.setup ? (browser.setup.account || 'not_checked') : 'not_checked';
        const accountReady = !!(!profileResetRequired && browserBound && accountStatus === 'ready');
        const modelReady = !!(!profileResetRequired && model && model.ready);
        const tunnelBound = !!(savedRevision > 0 && Number(tunnel.network_revision) === savedRevision);
        const toolsReady = !!(!profileResetRequired && settings && settings.mcp_enabled && tunnel.configured && tunnel.status === 'ready' && tunnelBound && connector.verified);
        return {
            ready: networkTested && runtimeReady && accountReady && modelReady && toolsReady,
            networkTested, runtimeReady, accountStatus, accountReady, modelReady, toolsReady,
            modelError: model && model.error ? model.error : '',
            modelErrorCode: model && model.errorCode ? model.errorCode : ''
        };
    }

    async function modelConnection(accountReady) {
        if (!accountReady) {
            modelProbe = {at: 0, ready: false, error: ''};
            return modelProbe;
        }
        if (Date.now() - modelProbe.at < 5000) return modelProbe;
        try {
            const models = await request('/v1/models', undefined, 8000);
            modelProbe = {at: Date.now(), ready: Array.isArray(models) && models.length > 0, error: ''};
            if (!modelProbe.ready) modelProbe.error = I18n.t('模型目录暂未返回可用模型。');
        } catch (error) {
            modelProbe = {at: Date.now(), ready: false, error: error.originalMessage || error.message || I18n.t('模型连接检查失败。'), errorCode: error.code || ''};
        }
        return modelProbe;
    }

    async function prerequisites() {
        const values = await Promise.all([request('/api/network'), request('/api/browser'), request('/api/settings')]);
        const accountReady = !!(values[1] && !values[1].profile_reset_required && values[1].setup && values[1].setup.account === 'ready');
        const model = await modelConnection(accountReady);
        const state = prerequisiteState(values[0], values[1], values[2], model);
        state.network = values[0];
        state.browser = values[1];
        state.settings = values[2];
        if (!state.networkTested) state.message = I18n.t('先完成网络连接。');
        else if (!state.runtimeReady) state.message = I18n.t('先准备运行组件。');
        else if (state.browser.profile_reset_required) state.message = I18n.t('内置 Chrome 登录资料清理未完成，请到首页第 3 步继续清空。');
        else if (!state.accountReady) state.message = state.accountStatus === 'checking' ? I18n.t('正在确认 ChatGPT 登录状态…') : I18n.t('先完成 ChatGPT 登录。');
        else if (!state.modelReady) state.message = I18n.t('ChatGPT 已登录，但模型连接尚未通过。');
        else if (!state.toolsReady) state.message = I18n.t('先完成 Codex 工具连接。');
        else state.message = I18n.t('运行准备已完成。');
        return state;
    }

    function removePrerequisiteMask() {
        const mask = document.getElementById('gateway-prerequisite-mask');
        if (mask) mask.remove();
    }

    function showPrerequisiteMask(state) {
        const existing = document.getElementById('gateway-prerequisite-mask');
        if (existing) {
            existing.querySelector('p').textContent = state && state.message ? state.message : I18n.t('完成运行准备后，这个页面会自动可用。');
            return;
        }
        const mask = document.createElement('div');
        mask.id = 'gateway-prerequisite-mask';
        mask.className = 'prerequisite-mask';
        const card = document.createElement('div');
        card.className = 'prerequisite-card';
        const icon = document.createElement('i');
        icon.className = 'layui-icon layui-icon-tips';
        const title = document.createElement('h2');
        title.textContent = I18n.t('请先完成运行准备');
        const detail = document.createElement('p');
        detail.className = 'muted';
        detail.textContent = state && state.message ? state.message : I18n.t('完成运行准备后，这个页面会自动可用。');
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'layui-btn';
        button.textContent = I18n.t('前往运行准备');
        button.addEventListener('click', openOverview);
        card.append(icon, title, detail, button);
        mask.append(card);
        document.body.append(mask);
    }

    /** After admission, health checks update a notice without obscuring tasks or stealing focus. */
    function showPrerequisiteNotice(state) {
        let notice = document.getElementById('gateway-prerequisite-notice');
        if (!notice) {
            notice = document.createElement('div');
            notice.id = 'gateway-prerequisite-notice';
            notice.className = 'notice warning prerequisite-notice';
            notice.setAttribute('role', 'status');
            const message = document.createElement('span');
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'layui-btn layui-btn-primary layui-btn-sm';
            button.textContent = I18n.t('查看运行准备');
            button.addEventListener('click', openOverview);
            notice.append(message, button);
            document.body.prepend(notice);
        }
        const message = state && state.message ? state.message : I18n.t('正在检查运行状态…');
        const text = notice.querySelector('span');
        if (text.textContent !== message) text.textContent = message;
    }

    /** Gate initial preparation; a monitoring page may suppress post-admission health banners. */
    async function ensureReady(options) {
        const quietAfterAdmission = Boolean(options && options.quietAfterAdmission);
        try {
            const state = await prerequisites();
            if (state.ready) {
                admittedState = state;
                removePrerequisiteMask();
                const notice = document.getElementById('gateway-prerequisite-notice');
                if (notice) notice.remove();
                return state;
            }
            if (admittedState) {
                removePrerequisiteMask();
                if (quietAfterAdmission) {
                    const notice = document.getElementById('gateway-prerequisite-notice');
                    if (notice) notice.remove();
                } else showPrerequisiteNotice(state);
                // This grants access to the local console only. APIs still enforce live readiness.
                return state;
            }
            showPrerequisiteMask(state);
        } catch (_) {
            if (admittedState) {
                const state = Object.assign({}, admittedState, {ready: false, message: I18n.t('暂时无法读取运行状态，已打开的页面会保留。')});
                if (!quietAfterAdmission) showPrerequisiteNotice(state);
                return state;
            }
            showPrerequisiteMask({message: I18n.t('暂时无法读取运行准备状态，请回到运行准备页面检查。')});
        }
        return null;
    }

    /** Update status rows using text nodes, never upstream HTML. */
    function consumers(element, rows) {
        const labels = {not_integrated: I18n.t('未接入'), not_applied: I18n.t('未应用'), applied: I18n.t('已应用'), pending: I18n.t('有待应用配置'), apply_failed: I18n.t('应用失败')};
        element.replaceChildren();
        rows.forEach(function (row) {
            const wrapper = document.createElement('div');
            wrapper.className = 'consumer';
            const title = document.createElement('span');
            title.textContent = I18n.t(row.label);
            const state = document.createElement('span');
            state.className = 'badge ' + (row.status === 'applied' ? 'good' : row.status === 'apply_failed' ? 'bad' : row.status === 'pending' ? 'pending' : '');
            state.textContent = (labels[row.status] || row.status) + (row.applied_revision ? ' · r' + row.applied_revision : '');
            wrapper.append(title, state);
            element.append(wrapper);
        });
    }

    /** Reuse the shell's existing Pear tab; direct page use still works. */
    function openOverview() {
        let shell = window.PearAdmin;
        try { if (!shell && window.parent !== window) shell = window.parent.PearAdmin; } catch (_) {}
        if (shell) {
            shell.instances.tabPage.changePage({id: 'overview', title: I18n.t('运行准备'), type: '_iframe', url: 'pages/overview.html', close: false});
            shell.instances.menu.selectItem('overview');
        } else {
            window.location.href = location.pathname.includes('/pages/') ? 'overview.html' : 'pages/overview.html';
        }
    }

    /** Reuse the shell's existing Pear tab; direct page use still works. */
    function openNetwork() {
        if (window.parent !== window && window.parent.PearAdmin) {
            window.parent.PearAdmin.instances.tabPage.changePage({id: 'network', title: I18n.t('网络与代理'), type: '_iframe', url: 'pages/network.html', close: true});
            window.parent.PearAdmin.instances.menu.selectItem('network');
        } else {
            window.location.href = 'network.html';
        }
    }

    /** Open the browser workbench in the existing Pear shell. */
    function openBrowser() {
        if (window.parent !== window && window.parent.PearAdmin) {
            window.parent.PearAdmin.instances.tabPage.changePage({id: 'browser', title: I18n.t('浏览器工作区'), type: '_iframe', url: 'pages/browser.html', close: true});
            window.parent.PearAdmin.instances.menu.selectItem('browser');
        } else {
            window.location.href = 'browser.html';
        }
    }

    function openSettings() {
        if (window.parent !== window && window.parent.PearAdmin) {
            window.parent.PearAdmin.instances.tabPage.changePage({id: 'settings', title: I18n.t('设置'), type: '_iframe', url: 'pages/settings.html', close: true});
            window.parent.PearAdmin.instances.menu.selectItem('settings');
        } else {
            window.location.href = 'settings.html';
        }
    }

    function openTasks() {
        if (window.parent !== window && window.parent.PearAdmin) {
            window.parent.PearAdmin.instances.tabPage.changePage({id: 'tasks', title: I18n.t('任务中心'), type: '_iframe', url: 'pages/tasks.html', close: true});
            window.parent.PearAdmin.instances.menu.selectItem('tasks');
        } else {
            window.location.href = 'tasks.html';
        }
    }

    /** Open support exports even when browser/model readiness is unavailable. */
    function openDiagnostics() {
        if (window.parent !== window && window.parent.PearAdmin) {
            window.parent.PearAdmin.instances.tabPage.changePage({id: 'diagnostics', title: I18n.t('运行日志'), type: '_iframe', url: 'pages/diagnostics.html', close: true});
            window.parent.PearAdmin.instances.menu.selectItem('diagnostics');
        } else {
            window.location.href = 'diagnostics.html';
        }
    }

    /** Populate a searchable Layui timezone selector only when its owning form explicitly hydrates. */
    function browserTimezones(select, zones, selected, filter) {
        const options = [new Option(I18n.t('自动跟随代理出口（推荐）'), 'auto'), new Option(I18n.t('使用电脑时区'), 'system')];
        const common = {'Asia/Tokyo': I18n.t('东京'), 'Asia/Shanghai': I18n.t('上海'), 'Asia/Hong_Kong': I18n.t('香港'),
            'Asia/Singapore': I18n.t('新加坡'), 'America/New_York': I18n.t('纽约'), 'America/Los_Angeles': I18n.t('洛杉矶'), 'Europe/London': I18n.t('伦敦')};
        const available = new Set(zones || []);
        // Preserve a saved legacy IANA alias even when it is absent from the current primary catalog.
        if (selected && !['auto', 'system'].includes(selected)) available.add(selected);
        const ordered = [...Object.keys(common).filter(zone => available.has(zone)), ...[...available].filter(zone => !common[zone])];
        ordered.forEach(zone => options.push(new Option(common[zone] ? common[zone] + ' · ' + zone : zone, zone)));
        select.replaceChildren(...options);
        select.value = selected || 'auto';
        layui.form.render('select', filter);
    }

    /** Observe an already reserved restart; a timeout never resubmits it or reports false success. */
    async function waitBrowserRestart(commandId) {
        if (!commandId) return;
        const deadline = Date.now() + 240000;
        while (Date.now() < deadline) {
            const command = await request('/api/browser/commands/' + commandId);
            if (command.status === 'succeeded') return command.result;
            if (command.status === 'failed') throw new Error(I18n.error(command.result?.message || I18n.t('浏览器操作失败。'), command.result?.code));
            await new Promise(resolve => setTimeout(resolve, 500));
        }
        throw new Error(I18n.t('设置已应用，浏览器重新初始化仍在进行。请稍后刷新查看，不会重复重启。'));
    }

    window.Gateway = {bootstrap, request, binary, describe, consumers, prerequisiteState, prerequisites, ensureReady, openOverview, openNetwork, openBrowser, openSettings, openTasks, openDiagnostics, browserTimezones, waitBrowserRestart,
        isPageActive, automaticRefreshAllowed, onPageActivate, notifyStateChange};
})();
