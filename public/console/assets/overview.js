(function () {
    'use strict';
    const byId = id => document.getElementById(id);
    const sleep = ms => new Promise(resolve => setTimeout(resolve, ms));
    let snapshot = null, reading = false, busy = false, activeLayer = null;
    let usageSnapshot = null;
    let accountResetPending = false, accountResetting = false;
    let screenshotBusy = false, screenshotTimer = null, stateTimer = null, networkMode = 'direct';
    let lastScreenshotSequence = 0, screenshotObjectUrl = null;
    let surfaceInteractionUntil = 0;
    let surfaceActionRevision = 0;
    let codexPreview = null, codexStatus = null, codexUiRevision = 0;
    // A model probe may wait up to 180 seconds in the existing browser queue,
    // then spend 45 seconds reading the website. This is a UI wait, not a task deadline.
    const MODEL_PROBE_WAIT_MS = 240000;

    async function codexAction(button, action) {
        if (busy) return;
        codexUiRevision++;
        busy = true; loading(button, true, I18n.t('正在处理…'));
        try { await action(); } catch (e) { progress('codex-progress', e.message, 'error'); }
        finally { busy = false; loading(button, false); }
    }
    /** Show observed website names and each website notice once, without translating or interpreting model captions. */
    function modelListText(models) {
        const notices = new Map();
        models.forEach(model => {
            if (model.notice) notices.set(model.family || model.id, (model.website_label || model.label) + ': ' + model.notice);
        });
        return models.map(model => model.label).join(', ')
            + (notices.size ? ' · ' + [...notices.values()].join(' · ') : '');
    }
    function renderCodexDetails() {
        if (!codexStatus) return;
        const account = codexStatus.account || {};
        const accountText = account.plan && account.plan !== 'unknown' ? I18n.t('当前 ChatGPT 账号：') + I18n.t(account.label) + '. ' : I18n.t('正在识别 ChatGPT 账号类型。');
        byId('codex-models').textContent = accountText + (account.refreshing ? I18n.t('正在自动刷新可用模型…') : (codexStatus.models.length ? I18n.t('可用模型：') + modelListText(codexStatus.models) : I18n.t('尚未检测可用模型。')));
        if (codexStatus.models.some(model => Array.isArray(model.efforts) && model.efforts.includes('max'))) {
            byId('codex-models').textContent += ' ' + I18n.t('网页 Pro 对应 Codex 的 Ultra 档；选择 Ultra 即可使用网页 Pro，无需开启 Max。');
        }
        byId('codex-remove').disabled = ['not_installed','removed','config_conflict'].includes(codexStatus.state);
    }
    function renderCodexStatus() {
        if (!codexStatus) return;
        const states = {not_installed: I18n.t('尚未接入 Codex。'), writing: I18n.t('配置正在保存。'), restart_required: I18n.t('配置已保存。请完全退出 Codex 后再打开。'),
            client_connected: I18n.t('Codex 已读取模型目录。选择一个 Web 模型完成一次对话后，即可确认接入成功。'),
            verified: I18n.t('Codex 已使用 Web 模型完成真实调用，接入成功。'), removed: I18n.t('接入已移除，请完全退出 Codex 后再打开。'), config_conflict: I18n.t('接入配置已变化，点击“预览接入配置”即可重新接入，也可恢复 Codex 官方路由。')};
        progress('codex-progress', states[codexStatus.state] || I18n.t('正在检查接入状态…'));
        byId('codex-restart-notice').hidden = !['restart_required','removed'].includes(codexStatus.state);
        renderCodexDetails();
    }
    /** Prominent installation guidance; acknowledging the dialog never claims that Codex was restarted. */
    function showCodexRestart() {
        byId('codex-restart-notice').hidden = false;
        const content = document.createElement('div'); content.className = 'codex-restart-dialog';
        const title = document.createElement('h2'); title.textContent = I18n.t('下一步必须完全重启 Codex');
        const instructions = document.createElement('p'); instructions.textContent = I18n.t('请保存当前工作，完全退出 Codex（包括后台进程），然后重新打开。关闭聊天标签或新建会话不能替代重启。');
        const note = document.createElement('p'); note.textContent = I18n.t('保持 RCMAKER 运行。重启后核对模型目录，再检查接入状态。');
        content.append(title, instructions, note);
        layui.layer.open({type:1, title:I18n.t('配置已保存，接入尚需重启'),
            area:[Math.min(560,window.innerWidth-32)+'px','auto'], maxHeight:Math.max(240,window.innerHeight-64),
            content:content.outerHTML, btn:[I18n.t('我知道了')], yes:index=>layui.layer.close(index), shadeClose:false});
    }
    async function readCodexStatus(apply = true) {
        const revision = codexUiRevision;
        const status = await Gateway.request('/api/codex');
        if (revision !== codexUiRevision) return status;
        codexStatus = status;
        if (apply) renderCodexStatus();
        return codexStatus;
    }

    /** Render cached usage in the selected language without requesting or invoking a model. */
    function renderUsage(usage) {
        byId('usage-account').textContent = I18n.t('当前 ChatGPT 账号：')
            + (usage.account.verified_plan_type === 'prolite' ? 'Pro Light' : I18n.t(usage.account.label));
        byId('usage-submissions').textContent = Number(usage.totals.submissions).toLocaleString();
        byId('usage-pro').textContent = Number(usage.totals.pro_submissions).toLocaleString();
        byId('usage-remaining').textContent = usage.pro_remaining_estimate === null ? I18n.t('未提供') : String(usage.pro_remaining_estimate);
        byId('usage-tokens').textContent = Number(usage.totals.input_tokens + usage.totals.output_tokens).toLocaleString();
        byId('usage-models').textContent = I18n.t('可用模型：') + modelListText(usage.models);
        const purposes = {task:I18n.t('真实任务'), context_stage:I18n.t('历史分段'),
            compaction:I18n.t('摘要与压缩'), connector_probe:I18n.t('连接检测')};
        byId('usage-breakdown').textContent = (usage.by_purpose || []).map(row =>
            (purposes[row.purpose] || row.purpose) + ': ' + Number(row.submissions).toLocaleString()).join(' · ');
        byId('usage-notice').textContent = usage.scope === 'verified_account'
            ? I18n.t('已按检测到的账号区分用量；预计余额仅根据本机最近七天记录，不包含其他设备。')
            : I18n.t('尚未核验账号身份，显示本机安装记录，不估算账号余额。点击“检测可用模型”更新账号信息。');
        if (usage.historical_model_inferred) byId('usage-notice').textContent += ' '
            + I18n.t('旧记录未保存实际发送档位，部分历史用量按当时任务设置估算。');
    }

    /** Usage refresh never probes a model, opens a page, or consumes a ChatGPT request. */
    async function refreshUsage() {
        const button = byId('usage-refresh');
        if (button.disabled) return;
        button.disabled = true;
        try {
            const usage = await Gateway.request('/api/codex/usage');
            usageSnapshot = usage;
            renderUsage(usage);
        } catch (error) { byId('usage-notice').textContent = error.message; }
        finally { button.disabled = false; }
    }

    function badge(id, text, tone) {
        const el = byId(id); el.textContent = text; el.className = 'badge' + (tone ? ' ' + tone : '');
    }
    function error(message) {
        byId('error').textContent = message || ''; byId('error').hidden = !message;
    }
    function progress(id, message, tone) {
        const el = byId(id); el.textContent = message; el.className = 'notice setup-layer-status' + (tone ? ' ' + tone : '');
    }
    function loading(button, value, label) {
        if (!button) return;
        if (!button.dataset.label) button.dataset.label = button.textContent;
        button.disabled = value;
        button.innerHTML = value ? '<i class="layui-icon layui-icon-loading layui-anim layui-anim-rotate layui-anim-loop"></i>' + (label || I18n.t('处理中…')) : I18n.t(button.dataset.label);
    }
    function layerArea(wide) {
        const width = Math.min(wide ? 1120 : 720, Math.max(320, window.innerWidth * .92));
        const height = Math.min(wide ? 820 : 620, Math.max(300, window.innerHeight * .86));
        return [Math.round(width) + 'px', Math.round(height) + 'px'];
    }
    function openLayer(name, title, wide) {
        activeLayer = name;
        layui.use('layer', () => layui.layer.open({
            type: 1, title, area: layerArea(wide), resize: true, shadeClose: false,
            content: layui.$('#' + name + '-layer'),
            success: () => { if (name === 'account' || name === 'tools') startScreenshotLoop(); },
            end: () => { if (activeLayer === name) activeLayer = null; stopScreenshotLoop(); refresh(); }
        }));
    }
    /** Locale redraw updates product copy while preserving active setup controls and operation ownership. */
    function render(state, labelsOnly = false) {
        snapshot = state;
        const network = state.network, tunnel = state.settings.tunnel || {}, connector = state.settings.connector || {};
        const accountChecking = state.accountStatus === 'checking';
        const profileResetRequired = Boolean(state.browser.profile_reset_required);
        badge('network-status', state.networkTested ? I18n.t('已完成') : I18n.t('待设置'), state.networkTested ? 'good' : 'pending');
        byId('network-detail').textContent = state.networkTested ? Gateway.describe(network.saved) + I18n.t(' · 网络连接已确认。') : I18n.t('选择直连，或填写代理 IP 与端口；网络连接确认后即可继续。');
        byId('use-direct').hidden = state.networkTested; byId('open-network').textContent = state.networkTested ? I18n.t('修改网络') : I18n.t('设置网络');
        badge('runtime-status', state.runtimeReady ? I18n.t('已完成') : (state.networkTested ? I18n.t('待准备') : I18n.t('等待网络')), state.runtimeReady ? 'good' : (state.networkTested ? 'pending' : ''));
        byId('runtime-detail').textContent = state.runtimeReady ? I18n.t('运行组件已准备完成，并保存在本应用目录中。') : (state.networkTested ? I18n.t('点击后自动识别电脑并准备所需组件。') : I18n.t('先完成第 1 步。'));
        if (!labelsOnly) byId('open-runtime').disabled = !state.networkTested;
        badge('account-status', profileResetRequired ? I18n.t('清理未完成') : (state.modelReady ? I18n.t('已完成') : (state.accountReady ? I18n.t('检查连接') : (accountChecking ? I18n.t('正在检查') : (state.runtimeReady ? I18n.t('需要登录') : I18n.t('等待'))))), !profileResetRequired && state.modelReady ? 'good' : (state.runtimeReady ? 'pending' : ''));
        byId('account-detail').textContent = profileResetRequired
            ? I18n.t('上次清空内置 Chrome 登录资料未完成。请打开登录窗口并继续清空，完成前不会使用残留资料。')
            : state.modelReady
            ? I18n.t('ChatGPT 已登录') + (codexStatus?.account?.plan && codexStatus.account.plan !== 'unknown' ? ' (' + I18n.t(codexStatus.account.label) + ')' : '') + I18n.t('，模型连接检查通过；会员变化后会自动刷新模型。')
            : (state.accountReady ? (state.modelError ? I18n.error(state.modelError, state.modelErrorCode) : I18n.t('ChatGPT 已登录，正在检查模型连接。')) : (accountChecking ? I18n.t('正在确认当前 ChatGPT 页面和登录状态…') : (state.runtimeReady ? I18n.t('在弹出窗口内完成一次 ChatGPT 登录，系统随后自动检查模型连接。') : I18n.t('先完成第 2 步。'))));
        if (!labelsOnly) {
            byId('open-login').disabled = !state.runtimeReady;
            byId('account-start').disabled = busy || profileResetRequired;
            byId('account-reload').disabled = busy || state.browser.status !== 'ready';
            byId('account-reset').disabled = busy || accountResetPending;
        }
        if (!accountResetting) byId('account-reset').textContent = profileResetRequired ? I18n.t('继续清空内置 Chrome 登录资料') : I18n.t('清空内置 Chrome 登录资料');
        badge('tools-status', state.toolsReady ? I18n.t('已连接') : (state.modelReady ? I18n.t('待配置') : I18n.t('等待')), state.toolsReady ? 'good' : (state.modelReady ? 'pending' : ''));
        byId('tools-detail').textContent = state.toolsReady ? I18n.t('工具隧道与 ChatGPT 连接器均已检查通过。') : (state.modelReady ? (tunnel.status === 'ready' ? (connector.reconnecting ? (connector.recheck_queued ? I18n.t('工具隧道重建后，正在自动复检连接器。') : I18n.t('工具隧道重建后，正在等待浏览器准备自动复检连接器。')) : (connector.last_error ? I18n.error(connector.last_error, connector.error_code || 'CONNECTOR_NOT_VERIFIED') : I18n.t('工具隧道已连接，继续创建并检查连接器。'))) : I18n.t('按引导创建 Tunnel 和普通 API Key，再创建 ChatGPT Connector。')) : I18n.t('先完成第 3 步。'));
        if (!labelsOnly) {
            byId('open-tools').disabled = profileResetRequired || !state.modelReady;
            renderToolsGuide(state);
        }
        const clientVerified = !profileResetRequired && state.ready && codexStatus && codexStatus.state === 'verified';
        badge('overall-status', profileResetRequired ? I18n.t('浏览器资料清理未完成') : (clientVerified ? I18n.t('Codex 接入已验证') : (state.ready ? I18n.t('网关已就绪 · 待确认 Codex 接入') : I18n.t('尚未准备完成'))), clientVerified ? 'good' : 'pending');
        byId('ready-banner').hidden = !clientVerified;
        byId('ready-detail').textContent = profileResetRequired ? I18n.t('上次清空内置 Chrome 登录资料未完成，请在第 3 步继续清空。') : (clientVerified ? I18n.t('网关已就绪，且已收到 Codex 的真实调用。') : (state.ready ? I18n.t('网页和工具已准备完成。请在第 4 步完成接入配置，完全退出并重新打开 Codex，再选择 Web 模型发起一次任务。') : I18n.t('按 1 → 4 的顺序完成即可；已经完成的步骤以后会自动跳过。')));
        const next = byId('next-action');
        if (!state.networkTested) { next.textContent = I18n.t('第 1 步：设置网络'); next.dataset.action = 'network'; }
        else if (!state.runtimeReady) { next.textContent = I18n.t('第 2 步：准备运行组件'); next.dataset.action = 'runtime'; }
        else if (profileResetRequired) { next.textContent = I18n.t('第 3 步：继续清空登录资料'); next.dataset.action = 'account'; }
        else if (!state.accountReady) { next.textContent = accountChecking ? I18n.t('第 3 步：检查登录状态') : I18n.t('第 3 步：登录 ChatGPT'); next.dataset.action = 'account'; }
        else if (!state.modelReady) { next.textContent = I18n.t('第 3 步：检查模型连接'); next.dataset.action = 'account'; }
        else if (!state.toolsReady) { next.textContent = I18n.t('第 4 步：连接 Codex 工具'); next.dataset.action = 'tools'; }
        else if (!clientVerified) { next.textContent = I18n.t('第 4 步：完成 Codex 接入'); next.dataset.action = 'tools'; }
        else { next.textContent = I18n.t('打开浏览器工作区'); next.dataset.action = 'browser'; }
    }
    function renderToolsGuide(state) {
        if (!state || !state.settings) return;
        const settings = state.settings, tunnel = settings.tunnel || {}, connector = settings.connector || {};
        const tunnelSaved = Boolean((settings.tunnel_id || '').trim());
        const tunnelReady = tunnel.status === 'ready';
        const keyReady = settings.runtime_key_configured === true || Number(settings.runtime_key_configured || 0) === 1;
        const connectorReady = connector.verified === true || Number(connector.verified || 0) === 1;
        badge('tools-tunnel-guide-status', tunnelReady ? I18n.t('已连接') : (tunnelSaved ? I18n.t('已保存') : I18n.t('待创建')), tunnelReady ? 'good' : 'pending');
        badge('tools-key-guide-status', keyReady ? I18n.t('已保存') : I18n.t('待创建'), keyReady ? 'good' : 'pending');
        const connectorStatus = connectorReady ? I18n.t('已通过') : (connector.reconnecting
            ? (connector.recheck_queued ? I18n.t('自动复检中') : I18n.t('等待自动复检'))
            : (connector.status === 'error' ? I18n.t('检查失败') : (tunnelReady ? I18n.t('待检查') : I18n.t('等待'))));
        badge('tools-connector-guide-status', connectorStatus, connectorReady ? 'good' : 'pending');
        byId('setup-connector-name').textContent = settings.connector_name || 'RCMAKER Codex';
        byId('tools-open-connector').disabled = !tunnelReady;
        byId('tools-copy-connector').disabled = !tunnelReady;
        byId('tools-verify').disabled = !tunnelReady;
    }
    async function refresh(options) {
        const passive = Boolean(options && options.passive);
        if (reading) return snapshot;
        reading = true;
        if (!passive) byId('refresh').disabled = true;
        try {
            const state = await Gateway.prerequisites();
            await readCodexStatus(!passive);
            snapshot = state;
            // Background polling may keep data fresh, but an open setup layer belongs to the
            // user. Never let a timer repaint progress, buttons or form state underneath them.
            if (passive) return state;
            render(state);
            if (activeLayer === 'account' && state.accountReady) {
                progress('account-progress', state.modelReady ? I18n.t('登录和模型连接检查均已通过。') : (state.modelError ? I18n.error(state.modelError, state.modelErrorCode) : I18n.t('ChatGPT 已登录，正在检查模型连接…')), state.modelReady ? '' : 'warning');
            }
            if (activeLayer === 'tools' && state.toolsReady) progress('tools-progress', I18n.t('工具连接已全部完成，可以关闭此窗口。'));
            error('');
            return state;
        }
        catch (e) { if (!passive) error(e.message); return snapshot; }
        finally { reading = false; if (!passive) byId('refresh').disabled = false; }
    }

    /** Observe the same queued command; its backend failure is authoritative, and polling never resubmits it. */
    async function waitBrowserCommand(id, timeoutMs, pollIntervalMs = 100) {
        const deadline = Date.now() + timeoutMs;
        while (Date.now() < deadline) {
            const row = await Gateway.request('/api/browser/commands/' + id);
            if (row.status === 'succeeded') return row.result || {};
            if (row.status === 'failed') throw new Error(row.result ? I18n.error(row.result.message || I18n.t('浏览器操作失败。'), row.result.code) : I18n.t('浏览器操作失败。'));
            await sleep(pollIntervalMs);
        }
        throw new Error(I18n.t('操作等待超时，请稍后重试。'));
    }
    /** Enqueue once, with a command-specific UI wait and optional polling cadence; other callers keep the existing default. */
    async function browserCommand(path, body, timeoutMs, pollIntervalMs = 100) {
        const queued = await Gateway.request(path, body || {});
        if (queued && queued.id) return waitBrowserCommand(queued.id, timeoutMs, pollIntervalMs);
        if (path === '/api/browser/login' && queued && queued.status === 'starting') {
            // A worker reattachment may have no live start command. Observe it without issuing
            // another login/navigation operation or announcing the page ready prematurely.
            const deadline = Date.now() + timeoutMs;
            while (Date.now() < deadline) {
                const state = await Gateway.request('/api/browser');
                if (state.status === 'ready' && state.worker_ready && Number(state.screenshot_sequence) > 0) return state;
                if (state.status === 'stopped' || state.status === 'error') {
                    throw new Error(I18n.error(state.last_error || I18n.t('浏览器操作失败。'), state.last_error));
                }
                await sleep(500);
            }
            throw new Error(I18n.t('操作等待超时，请稍后重试。'));
        }
        return queued || {};
    }
    async function waitNetworkTest(id) {
        const deadline = Date.now() + 60000;
        while (Date.now() < deadline) {
            const job = await Gateway.request('/api/network/tests/' + id);
            if (job.status === 'succeeded') return job;
            if (job.status === 'failed') throw new Error(job.result ? I18n.networkResult(job.result) : I18n.t('ChatGPT 连接测试失败。'));
            await sleep(600);
        }
        throw new Error(I18n.t('ChatGPT 连接测试等待超时。'));
    }
    function selectNetwork(mode) {
        networkMode = mode; byId('proxy-fields').hidden = mode !== 'manual';
        byId('network-direct').className = 'layui-btn ' + (mode === 'direct' ? '' : 'layui-btn-primary');
        byId('network-proxy').className = 'layui-btn ' + (mode === 'manual' ? '' : 'layui-btn-primary');
        progress('network-progress', mode === 'direct' ? I18n.t('将直接测试 ChatGPT 连接。') : I18n.t('填写代理 IP 和端口后保存并测试。'));
    }
    function prepareNetworkLayer(preferred) {
        const row = snapshot && snapshot.network && snapshot.network.saved;
        byId('proxy-host').value = row && row.mode === 'manual' ? (row.host || '') : '';
        byId('proxy-port').value = row && row.mode === 'manual' ? (row.port || '') : '';
        selectNetwork(preferred || (row && row.mode === 'manual' ? 'manual' : 'direct'));
        openLayer('network', I18n.t('网络连接'), false);
    }
    async function saveAndTestNetwork() {
        if (busy || !snapshot) return;
        const button = byId('network-confirm'), host = byId('proxy-host').value.trim(), port = Number(byId('proxy-port').value);
        if (networkMode === 'manual' && (!host || !Number.isInteger(port) || port < 1 || port > 65535)) {
            progress('network-progress', I18n.t('请填写有效的代理 IP 和 1–65535 范围内的端口。'), 'warning'); return;
        }
        busy = true; loading(button, true, I18n.t('正在连接…')); progress('network-progress', I18n.t('正在保存网络设置…'));
        try {
            const saved = await Gateway.request('/api/network/save', {
                expected_revision: Number(snapshot.network.saved.id), mode: networkMode, protocol: 'http',
                host: networkMode === 'manual' ? host : '', port: networkMode === 'manual' ? port : 0,
                username: '', password: '', password_action: 'clear'
            });
            progress('network-progress', I18n.t('正在应用设置…'));
            await Gateway.request('/api/network/apply', {expected_revision: Number(saved.revision)});
            progress('network-progress', I18n.t('正在测试 https://chatgpt.com，请稍候…'));
            const test = await Gateway.request('/api/network/test', {revision: Number(saved.revision), target_id: 'model_site', source: 'active'});
            await waitNetworkTest(test.id); progress('network-progress', I18n.t('连接成功，网络准备已完成。')); await refresh();
        } catch (e) { progress('network-progress', e.message, 'warning'); await refresh(); }
        finally { busy = false; loading(button, false); }
    }
    async function prepareRuntime() {
        if (busy || !snapshot || !snapshot.networkTested) return;
        const button = byId('runtime-confirm'); busy = true; loading(button, true, I18n.t('正在准备…'));
        try {
            let state = await Gateway.prerequisites();
            if (!(state.browser.setup && state.browser.setup.runtime && state.browser.setup.runtime.installed)) {
                progress('runtime-progress', I18n.t('正在准备浏览器组件，首次运行可能需要一些时间…'));
                await browserCommand('/api/browser/install', {revision: Number(state.network.saved.id)}, 600000);
            }
            state = await Gateway.prerequisites();
            if (!(state.settings.tunnel && state.settings.tunnel.binary_installed)) {
                progress('runtime-progress', I18n.t('正在准备工具连接组件…'));
                await Gateway.request('/api/settings/tunnel/prepare', {}, 180000);
            }
            progress('runtime-progress', I18n.t('运行组件已准备完成。')); await refresh();
        } catch (e) { progress('runtime-progress', e.message, 'warning'); }
        finally { busy = false; loading(button, false); }
    }

    function surfacePrefix() {
        return activeLayer === 'tools' && !byId('tools-surface-panel').hidden ? 'tools' : (activeLayer === 'account' ? 'account' : null);
    }
    async function refreshScreenshot() {
        const prefix = surfacePrefix();
        if (!prefix || screenshotBusy || document.hidden) return;
        screenshotBusy = true;
        try {
            const result = await Gateway.binary('/api/browser/screenshot');
            if (result.sequence > 0 && result.sequence <= lastScreenshotSequence) return;
            const url = URL.createObjectURL(result.blob);
            const previous = screenshotObjectUrl;
            screenshotObjectUrl = url;
            if (result.sequence > 0) lastScreenshotSequence = result.sequence;
            const image = byId(prefix + '-surface');
            image.onload = () => { if (previous) URL.revokeObjectURL(previous); };
            image.src = url; image.hidden = false;
            byId(prefix + '-surface-empty').hidden = true;
        } catch (_) {
        } finally { screenshotBusy = false; }
    }
    function startScreenshotLoop() {
        stopScreenshotLoop(); lastScreenshotSequence = 0; refreshScreenshot();
        screenshotTimer = setInterval(() => {
            refreshScreenshot();
        }, activeLayer === 'tools' ? 1600 : 900);
        stateTimer = setInterval(() => {
            if (!busy && Date.now() >= surfaceInteractionUntil) refresh({passive: true});
        }, 10000);
    }
    function stopScreenshotLoop() {
        surfaceActionRevision++;
        if (screenshotTimer) clearInterval(screenshotTimer);
        if (stateTimer) clearInterval(stateTimer);
        screenshotTimer = null; stateTimer = null; lastScreenshotSequence = 0;
        if (screenshotObjectUrl) URL.revokeObjectURL(screenshotObjectUrl);
        screenshotObjectUrl = null;
    }
    /** Optional native-window controls share the private browser, without replacing the old setup action handlers. */
    async function nativeBrowser(mode, prefix) {
        if (busy || accountResetPending || accountResetting || !snapshot || !snapshot.runtimeReady) return;
        const button = byId(prefix + '-native-' + (mode === 'show' ? 'open' : 'hide'));
        busy = true; loading(button, true, I18n.t('正在处理…'));
        try {
            if (mode === 'show' && snapshot.browser.status !== 'ready') {
                await browserCommand('/api/browser/login', {revision: Number(snapshot.network.saved.id)}, 300000);
            }
            await browserCommand('/api/browser/native-window', {mode}, 60000);
            progress(prefix === 'account' ? 'account-progress' : 'tools-progress', mode === 'show'
                ? I18n.t('原生浏览器已打开，请在独立 Chrome 窗口中操作。')
                : I18n.t('原生浏览器已收起，登录资料和其他任务页面保持保留。'));
        } catch (error) { progress(prefix === 'account' ? 'account-progress' : 'tools-progress', error.message, 'warning'); }
        finally { busy = false; loading(button, false); }
    }

    async function startLogin() {
        if (busy || accountResetPending || !snapshot || !snapshot.runtimeReady) return;
        if (snapshot.browser.profile_reset_required) {
            progress('account-progress', I18n.t('上次清空未完成，请先继续清空内置 Chrome 登录资料。'), 'warning');
            return;
        }
        const button = byId('account-start'); busy = true; loading(button, true, I18n.t('正在打开…'));
        progress('account-progress', I18n.t('正在打开项目自己的 ChatGPT 登录页面…'));
        try {
            await browserCommand('/api/browser/login', {revision: Number(snapshot.network.saved.id)}, 300000);
            progress('account-progress', I18n.t('页面已打开，请在下方完成登录。')); await refreshScreenshot(); await refresh();
        } catch (e) { progress('account-progress', e.message, 'warning'); }
        finally { busy = false; loading(button, false); }
    }
    function setAccountControlsDisabled(disabled) {
        ['account-start', 'account-reload', 'account-reset', 'account-text', 'account-send', 'account-enter'].forEach(id => {
            byId(id).disabled = disabled;
        });
        byId('account-surface-wrap').style.pointerEvents = disabled ? 'none' : '';
        if (!disabled && snapshot) {
            byId('account-start').disabled = !snapshot.runtimeReady || Boolean(snapshot.browser.profile_reset_required);
            byId('account-reload').disabled = snapshot.browser.status !== 'ready';
        }
    }
    async function resetBrowserProfile() {
        if (busy) { accountResetPending = false; byId('account-reset').disabled = false; return; }
        const button = byId('account-reset');
        button.dataset.label = button.textContent;
        busy = true; accountResetting = true;
        setAccountControlsDisabled(true); loading(button, true, I18n.t('正在清空…'));
        stopScreenshotLoop();
        progress('account-progress', I18n.t('正在关闭 RCMAKER 自有 Chrome 并清空登录资料；请等待操作完成…'));
        try {
            await browserCommand('/api/browser/profile/reset', {}, 600000);
            const image = byId('account-surface');
            image.removeAttribute('src'); image.hidden = true;
            byId('account-surface-empty').hidden = false;
            byId('account-surface-empty').querySelector('strong').textContent = I18n.t('当前未登录');
            byId('account-surface-empty').querySelector('span').textContent = I18n.t('点击“打开登录页面”重新登录。');
            byId('account-text').value = '';
            await refresh();
            progress('account-progress', I18n.t('内置 Chrome 登录资料已清空，当前未登录。请手动点击“打开登录页面”重新登录。'));
        } catch (e) {
            await refresh();
            progress('account-progress', e.message, 'warning');
        } finally {
            busy = false; accountResetting = false; accountResetPending = false;
            loading(button, false); setAccountControlsDisabled(false);
            if (snapshot) button.textContent = snapshot.browser.profile_reset_required ? I18n.t('继续清空内置 Chrome 登录资料') : I18n.t('清空内置 Chrome 登录资料');
            if (activeLayer === 'account') startScreenshotLoop();
        }
    }
    function confirmBrowserProfileReset() {
        if (busy || accountResetPending) return;
        accountResetPending = true;
        byId('account-reset').disabled = true;
        layui.use('layer', () => {
            let confirmed = false;
            layui.layer.confirm(
                I18n.t('这会关闭 RCMAKER 自有 Chrome，清除当前与旧版 RCMAKER 自有的全部 Chrome Profiles，以及其中的 ChatGPT、Google 登录记录。网关任务记录、Tunnel 配置、系统 Chrome 和参考桥会保留。完成后需手动重新打开登录页面。是否继续？'),
                {title: I18n.t('清空内置 Chrome 登录资料'), btn: [I18n.t('清空登录资料'), I18n.t('取消')], closeBtn: 1,
                    end: () => { if (!confirmed) { accountResetPending = false; byId('account-reset').disabled = false; } }},
                index => { confirmed = true; layui.layer.close(index); resetBrowserProfile(); }
            );
        });
    }
    function pointFor(prefix, event) {
        const image = byId(prefix + '-surface'), rect = image.getBoundingClientRect();
        const sourceWidth = image.naturalWidth || 1280, sourceHeight = image.naturalHeight || 720;
        if (!rect.width || !rect.height) return null;
        const scale = Math.min(rect.width / sourceWidth, rect.height / sourceHeight);
        const shownWidth = sourceWidth * scale, shownHeight = sourceHeight * scale;
        const left = rect.left + (rect.width - shownWidth) / 2, top = rect.top + (rect.height - shownHeight) / 2;
        if (event.clientX < left || event.clientX > left + shownWidth || event.clientY < top || event.clientY > top + shownHeight) return null;
        return {x: (event.clientX - left) / scale, y: (event.clientY - top) / scale};
    }
    /** Wait only until the browser worker publishes a frame newer than the completed input. */
    async function waitForNewScreenshot(sequence, prefix, revision) {
        const deadline = Date.now() + 2500;
        while (!document.hidden && surfacePrefix() === prefix && surfaceActionRevision === revision && Date.now() < deadline) {
            await refreshScreenshot();
            if (lastScreenshotSequence > sequence) return;
            await sleep(200);
        }
    }
    async function browserAction(payload) {
        const prefix = surfacePrefix(), revision = ++surfaceActionRevision;
        const previousSequence = lastScreenshotSequence;
        surfaceInteractionUntil = Date.now() + (activeLayer === 'tools' ? 15000 : 8000);
        const queued = await Gateway.request('/api/browser/action', payload);
        if (queued && queued.id) await waitBrowserCommand(queued.id, 20000);
        // Input acknowledgement and image refresh have separate lifetimes. A delayed frame
        // must not hold the next click/key for the full screenshot settlement window.
        waitForNewScreenshot(previousSequence, prefix, revision).catch(() => {});
    }
    async function safeBrowserAction(payload) {
        if (accountResetting) return;
        try {
            await browserAction(payload);
        } catch (e) {
            progress(activeLayer === 'tools' ? 'tools-progress' : 'account-progress', e.message, 'warning');
        }
    }
    function bindSurface(prefix) {
        const holdInteraction = () => {
            surfaceInteractionUntil = Math.max(surfaceInteractionUntil, Date.now() + (prefix === 'tools' ? 15000 : 8000));
        };
        byId(prefix + '-surface-wrap').addEventListener('pointerdown', holdInteraction);
        byId(prefix + '-text').addEventListener('focus', holdInteraction);
        byId(prefix + '-surface').addEventListener('click', async event => {
            const point = pointFor(prefix, event); if (point) await safeBrowserAction({action: 'click', x: point.x, y: point.y});
        });
        byId(prefix + '-surface-wrap').addEventListener('wheel', event => {
            event.preventDefault(); safeBrowserAction({action: 'scroll', delta_y: Math.max(-2500, Math.min(2500, event.deltaY)), x: 640, y: 360});
        }, {passive: false});
        byId(prefix + '-send').addEventListener('click', async () => {
            const input = byId(prefix + '-text'); if (!input.value) return;
            const value = input.value; input.value = ''; await safeBrowserAction({action: 'text', text: value});
        });
        byId(prefix + '-enter').addEventListener('click', () => safeBrowserAction({action: 'key', key: 'Enter'}));
        byId(prefix + '-text').addEventListener('keydown', event => {
            if (event.key === 'Enter') { event.preventDefault(); byId(prefix + '-send').click(); }
        });
    }

    async function connectTools() {
        if (busy || !snapshot || !snapshot.modelReady) return;
        const button = byId('tools-connect'), tunnelId = byId('setup-tunnel-id').value.trim(), apiKey = byId('setup-api-key').value;
        const suffix = byId('setup-connector-suffix').value.trim();
        if (!suffix || /[\x00-\x1f\x7f]/.test(suffix)) { progress('tools-progress', I18n.t('请输入有效的连接器名称。'), 'warning'); return; }
        if (!tunnelId) { progress('tools-progress', I18n.t('请输入 Tunnel ID。'), 'warning'); return; }
        if (!apiKey && !snapshot.settings.runtime_key_configured) { progress('tools-progress', I18n.t('请输入 API Key。'), 'warning'); return; }
        busy = true; loading(button, true, I18n.t('正在连接…'));
        try {
            const current = snapshot.settings;
            progress('tools-progress', I18n.t('正在安全保存连接信息…'));
            await Gateway.request('/api/settings', {
                expected_updated_at: Number(current.updated_at || 0), mcp_enabled: 1,
                connector_name: 'RCMAKER ' + suffix,
                mcp_invoke_timeout_ms: Number(current.mcp_invoke_timeout_ms || 90000),
                wait_poll_interval_ms: Number(current.wait_poll_interval_ms || 250),
                max_tool_calls_per_turn: Number(current.max_tool_calls_per_turn ?? 0),
                tool_result_max_bytes: Number(current.tool_result_max_bytes || 2097152),
                tunnel_id: tunnelId, runtime_key: apiKey, runtime_key_action: apiKey ? 'set' : 'keep'
            });
            byId('setup-api-key').value = '';
            progress('tools-progress', I18n.t('正在连接工具隧道…'));
            await Gateway.request('/api/settings/tunnel/connect', {}, 150000);
            const tunnelDeadline = Date.now() + 90000;
            while (true) {
                const tunnel = await Gateway.request('/api/settings/tunnel');
                if (tunnel.status === 'ready') break;
                if (tunnel.status === 'error') throw new Error(I18n.t('工具隧道连接失败，请检查代理、Tunnel ID 和 API Key。'));
                if (Date.now() >= tunnelDeadline) throw new Error(I18n.t('工具隧道还在连接，请稍后再次检查。'));
                await new Promise(resolve => setTimeout(resolve, 1000));
            }
            progress('tools-progress', I18n.t('工具隧道已连接。继续第 3 项：打开 ChatGPT Plugins，按页面说明创建 Connector。'));
            await refresh();
        } catch (e) { progress('tools-progress', e.message, 'warning'); await refresh(); }
        finally { busy = false; loading(button, false); }
    }
    async function openToolPage(buttonId, url, openingText, readyText, requireTunnel) {
        if (busy) return;
        const button = byId(buttonId); busy = true; loading(button, true, I18n.t('正在打开…'));
        try {
            const state = await Gateway.prerequisites();
            if (requireTunnel && !(state.settings.tunnel && state.settings.tunnel.status === 'ready')) throw new Error(I18n.t('请先完成 Tunnel ID 和 API Key 连接。'));
            if (!state.accountReady) throw new Error(I18n.t('请先完成 ChatGPT 登录。'));
            progress('tools-progress', openingText);
            byId('tools-surface-panel').hidden = false;
            await browserAction({action: 'navigate', url: url});
            progress('tools-progress', readyText); await refreshScreenshot();
        } catch (e) { progress('tools-progress', e.message, 'warning'); }
        finally { busy = false; loading(button, false); }
    }
    async function openConnector() {
        return openToolPage(
            'tools-open-connector',
            'https://chatgpt.com/plugins',
            I18n.t('正在通过项目代理打开 ChatGPT Connector 设置…'),
            I18n.t('请按第 3 项清单创建并保存 Connector，完成后点击“检查连接器”。'),
            true
        );
    }
    async function copyConnectorName() {
        const value = (byId('setup-connector-name').textContent || '').trim();
        if (!value) return;
        try {
            if (navigator.clipboard && navigator.clipboard.writeText) await navigator.clipboard.writeText(value);
            else {
                const input = document.createElement('textarea'); input.value = value; input.style.position = 'fixed'; input.style.opacity = '0';
                document.body.appendChild(input); input.select(); document.execCommand('copy'); input.remove();
            }
            progress('tools-progress', I18n.t('连接器名称已复制：') + value);
        } catch (_) { progress('tools-progress', I18n.t('复制失败，请手动复制连接器名称：') + value, 'warning'); }
    }
    async function verifyConnector() {
        if (busy) return;
        const button = byId('tools-verify'); busy = true; loading(button, true, I18n.t('正在检查…'));
        progress('tools-progress', I18n.t('正在检查当前 ChatGPT 连接器…'));
        try {
            await browserCommand('/api/browser/connector/verify', {}, 45000);
            const state = await refresh();
            if (!state || !state.toolsReady) throw new Error(I18n.t('连接器尚未通过检查，请确认已经创建并保存。'));
            progress('tools-progress', I18n.t('连接器检查通过，运行准备已全部完成。'));
        } catch (e) { progress('tools-progress', e.message, 'warning'); await refresh(); }
        finally { busy = false; loading(button, false); }
    }

    byId('refresh').addEventListener('click', refresh);
    byId('usage-refresh').addEventListener('click', refreshUsage);
    window.addEventListener('gateway-language-changed', () => {
        if (snapshot) render(snapshot, true);
        if (snapshot && activeLayer === 'account' && !busy && snapshot.accountReady && !snapshot.modelReady && snapshot.modelError) {
            progress('account-progress', I18n.error(snapshot.modelError, snapshot.modelErrorCode), 'warning');
        }
        if (codexStatus) renderCodexDetails();
        if (usageSnapshot) renderUsage(usageSnapshot);
    });
    byId('use-direct').addEventListener('click', () => prepareNetworkLayer('direct'));
    byId('open-network').addEventListener('click', () => prepareNetworkLayer());
    byId('network-direct').addEventListener('click', () => selectNetwork('direct'));
    byId('network-proxy').addEventListener('click', () => selectNetwork('manual'));
    byId('network-confirm').addEventListener('click', saveAndTestNetwork);
    byId('open-runtime').addEventListener('click', () => { progress('runtime-progress', snapshot && snapshot.runtimeReady ? I18n.t('运行组件已经准备完成。') : I18n.t('等待开始。')); openLayer('runtime', I18n.t('准备运行组件'), false); });
    byId('runtime-confirm').addEventListener('click', prepareRuntime);
    byId('open-login').addEventListener('click', () => {
        progress('account-progress', snapshot && snapshot.modelReady ? I18n.t('当前 ChatGPT 登录和模型连接均正常。') : (snapshot && snapshot.accountReady ? (snapshot.modelError ? I18n.error(snapshot.modelError, snapshot.modelErrorCode) : I18n.t('已登录，正在检查模型连接…')) : I18n.t('点击“打开登录页面”开始。')));
        openLayer('account', I18n.t('登录 ChatGPT'), true);
    });
    byId('account-start').addEventListener('click', startLogin);
    byId('account-native-open').addEventListener('click', () => nativeBrowser('show', 'account'));
    byId('account-native-hide').addEventListener('click', () => nativeBrowser('hide', 'account'));
    byId('account-reload').addEventListener('click', () => safeBrowserAction({action: 'reload'}));
    byId('account-reset').addEventListener('click', confirmBrowserProfileReset);
    byId('open-tools').addEventListener('click', () => {
        if (snapshot) {
            byId('setup-tunnel-id').value = snapshot.settings.tunnel_id || '';
            byId('setup-api-key').value = '';
            byId('setup-connector-suffix').value = (snapshot.settings.connector_name || 'RCMAKER Codex').replace(/^RCMAKER\s+/i, '');
        }
        byId('tools-surface-panel').hidden = true;
        if (snapshot) renderToolsGuide(snapshot);
        progress('tools-progress', snapshot && snapshot.toolsReady ? I18n.t('工具连接已经完成。') : I18n.t('从上到下完成 1 → 3；每一步都有直达入口和操作说明。'));
        openLayer('tools', I18n.t('连接 Codex 工具'), true);
        readCodexStatus().catch(e => progress('codex-progress', e.message, 'error'));
    });
    byId('tools-connect').addEventListener('click', connectTools);
    // Native links open in the console user's browser. No private-Chrome command, login
    // prerequisite, async popup or tunnel credential enters these administrator pages.
    byId('tools-open-tunnel-admin').addEventListener('click', () =>
        progress('tools-progress', I18n.t('创建 Tunnel 后复制 Tunnel ID，粘贴到下面输入框。')));
    byId('tools-open-key-admin').addEventListener('click', () =>
        progress('tools-progress', I18n.t('创建普通 API Key 后粘贴到下面输入框。')));
    byId('tools-open-connector').addEventListener('click', openConnector);
    byId('tools-native-open').addEventListener('click', () => nativeBrowser('show', 'tools'));
    byId('tools-native-hide').addEventListener('click', () => nativeBrowser('hide', 'tools'));
    byId('tools-surface-refresh').addEventListener('click', refreshScreenshot);
    byId('tools-copy-connector').addEventListener('click', copyConnectorName);
    byId('tools-verify').addEventListener('click', verifyConnector);
    byId('codex-probe').addEventListener('click', function () { codexAction(this, async () => {
        // The existing busy guard pauses passive updates for this operation only.
        // Its isolated probe must not extend the user's surface interaction pause after completion.
        progress('codex-progress', I18n.t('正在读取当前 ChatGPT 账号的模型选项…'));
        const result = await browserCommand('/api/codex/probe', {}, MODEL_PROBE_WAIT_MS, 500);
        await readCodexStatus(false);
        renderCodexDetails();
        const models = Array.isArray(result.models) ? result.models : (codexStatus && Array.isArray(codexStatus.models) ? codexStatus.models : []);
        progress('codex-progress', models.length ? I18n.t('模型检测完成，已识别 ') + models.length + I18n.t(' 个可用模型。') : I18n.t('模型检测完成，当前账号未识别到可注入的 Web 模型。'), models.length ? '' : 'warning');
    }); });
    async function previewCodex(native) {
        codexPreview = null;
        byId('codex-install').disabled = true;
        codexPreview = await Gateway.request(native ? '/api/codex/native-preview' : '/api/codex/preview');
        const replaced = codexPreview.replaced_keys || [];
        byId('codex-diff').textContent = I18n.t('配置位置：') + codexPreview.path
            + (replaced.length ? I18n.t('\n将') + (native ? I18n.t('清除') : I18n.t('替换')) + I18n.t('以下设置：') + replaced.join(', ') : '')
            + I18n.t('\n备份位置（保存时生成）：') + codexPreview.backup_path + I18n.t('\n变更内容：\n') + codexPreview.changes;
        byId('codex-diff').hidden = false; byId('codex-install').disabled = codexPreview.already_installed;
        byId('codex-install').textContent = native ? I18n.t('保存并恢复官方路由') : I18n.t('保存接入配置');
        progress('codex-progress', codexPreview.already_installed ? I18n.t('已保存接入配置，请检查 Codex 接入状态。')
            : (native ? I18n.t('点击“保存并恢复官方路由”，然后完全退出 Codex 再打开。') : I18n.t('保存时会备份原配置并替换已有模型路由，然后完全退出 Codex 再打开。')));
    }
    byId('codex-preview').addEventListener('click', function () { codexAction(this, () => previewCodex(false)); });
    byId('codex-native').addEventListener('click', function () { codexAction(this, () => previewCodex(true)); });
    byId('codex-install').addEventListener('click', function () { codexAction(this, async () => {
        if (!codexPreview) return;
        const native = codexPreview.action === 'native';
        const result = await Gateway.request(native ? '/api/codex/native-restore' : '/api/codex/install', {expected_hash: codexPreview.expected_hash});
        codexPreview = null; byId('codex-install').disabled = true; await readCodexStatus();
        progress('codex-progress', (native ? I18n.t('已恢复 Codex 官方路由。') : I18n.t('接入配置已保存。')) + I18n.t('请完全退出 Codex 后再打开。') + (result.backup_path ? I18n.t(' 原配置已备份。') : ''));
        showCodexRestart();
    }); });
    byId('codex-refresh').addEventListener('click', function () { codexAction(this, readCodexStatus); });
    byId('codex-restart-check').addEventListener('click', function () { codexAction(this, readCodexStatus); });
    byId('codex-remove').addEventListener('click', function () { codexAction(this, async () => {
        if (!codexStatus) return;
        await Gateway.request('/api/codex/remove', {expected_hash: codexStatus.expected_hash});
        codexPreview = null; byId('codex-install').disabled = true; await readCodexStatus();
        progress('codex-progress', I18n.t('已恢复接入前配置。请完全退出 Codex 后再打开；接入前若使用其他桥接，当前也会恢复该桥接。'));
        showCodexRestart();
    }); });
    byId('next-action').addEventListener('click', function () {
        if (this.dataset.action === 'network') prepareNetworkLayer();
        else if (this.dataset.action === 'runtime') byId('open-runtime').click();
        else if (this.dataset.action === 'account') byId('open-login').click();
        else if (this.dataset.action === 'tools') byId('open-tools').click();
        else Gateway.openBrowser();
    });
    bindSurface('account'); bindSurface('tools');
    document.addEventListener('visibilitychange', () => { if (!document.hidden) refresh(); });
    window.addEventListener('pagehide', stopScreenshotLoop);
    refresh();
    refreshUsage();
})();
