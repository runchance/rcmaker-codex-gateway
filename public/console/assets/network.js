/* One form owner, one mutation at a time, and one bounded diagnostic poll. */
layui.use(['form', 'table', 'layer', 'util'], function () {
    'use strict';
    const form = layui.form;
    const layer = layui.layer;
    const byId = id => document.getElementById(id);
    let snapshot = null;
    let dirty = false;
    let busy = false;
    let jobId = null;
    let pollTimer = null;
    let pollStarted = 0;
    let polling = false;
    let disposed = false;
    let editVersion = 0;

    /** Show a safe product message without rendering server text as HTML. */
    function message(text, error) {
        byId('message').textContent = text;
        byId('message').className = 'notice' + (error ? ' error' : '');
        byId('message').hidden = false;
    }

    /** Keep field visibility under Layui's events; hiding never clears edits. */
    function visibility() {
        // form.val() also renders in this Layui release; never replace the clicked switch during its event.
        const manual = form.getValue('network').mode === 'manual';
        byId('proxy-fields').hidden = !manual;
        byId('direct-help').hidden = manual;
        byId('auth-fields').hidden = !byId('authentication').checked;
        byId('password-field').hidden = byId('password-action').value === 'keep';
    }

    /** Derive control availability from known server state, not an optimistic success flag. */
    function controls() {
        byId('dirty').textContent = dirty ? I18n.t('有未保存修改') : snapshot ? I18n.t('表单已载入') : I18n.t('尚未载入');
        byId('dirty').className = 'badge' + (dirty ? ' pending' : '');
        const valid = snapshot && snapshot.saved.mode !== 'unconfigured';
        const selected = snapshot && (byId('test-source').value === 'active' ? snapshot.active : snapshot.saved);
        const states = {
            save: busy || !snapshot,
            apply: busy || !valid || dirty || Number(snapshot.active && snapshot.active.id) === Number(snapshot.saved.id),
            test: busy || !selected || selected.mode === 'unconfigured' || !snapshot.probe_worker_ready || jobId !== null,
            restore: busy || !snapshot || !byId('restore-source').value,
            reload: busy
        };
        Object.keys(states).forEach(function (id) {
            byId(id).disabled = states[id];
            byId(id).classList.toggle('layui-btn-disabled', states[id]);
        });
    }

    /** Mark only actual edits; asynchronous status updates never hydrate the form. */
    function changed() {
        editVersion += 1;
        dirty = true;
        visibility();
        controls();
    }

    /** Render immutable status/history, optionally replacing editable values on explicit reload. */
    function render(value, hydrate) {
        snapshot = value;
        const saved = value.saved;
        if (hydrate) {
            form.val('network', {mode: saved.mode === 'direct' ? 'direct' : 'manual', protocol: 'http', host: saved.host || '', port: saved.port || '', username: saved.username || '', password: '', password_action: saved.has_password ? 'keep' : 'set', authentication: Boolean(saved.username)});
            dirty = false;
            visibility();
        }
        byId('saved-version').textContent = 'r' + saved.id + ' · ' + Gateway.describe(saved);
        byId('active-version').textContent = value.active ? 'r' + value.active.id + ' · ' + Gateway.describe(value.active) : I18n.t('尚未应用');
        byId('continue-setup').hidden = !(value.active && Number(value.active.id) === Number(saved.id) && saved.mode !== 'unconfigured');
        Gateway.consumers(byId('consumers'), value.consumers);
        byId('probe-readiness').textContent = value.probe_worker_ready ? I18n.t('后台检测进程已就绪。表单未保存的修改不会参与检测。') : I18n.t('后台检测进程尚未就绪；仍可保存配置，重新载入设置可重查进程状态。');
        byId('credential-help').textContent = value.credentials.storage === 'project_secretbox' ? I18n.t('使用本应用的私有密钥加密保存，不回显密码。迁移应用时请一并保留 data 中的密钥。') : I18n.t('当前环境无法安全存储代理密码，请使用无认证的本地代理。');
        const previous = byId('restore-source').value;
        byId('restore-source').replaceChildren(new Option(I18n.t('请选择之前的版本'), ''));
        value.history.filter(row => Number(row.id) < Number(saved.id)).forEach(function (row) {
            byId('restore-source').add(new Option('r' + row.id + ' · ' + Gateway.describe(row), row.id));
        });
        byId('restore-source').value = previous;
        form.render('select');
        layui.table.render({
            elem: '#history-table', data: value.history, page: false, limit: 20, size: 'lg',
            cols: [[
                {field: 'id', title: I18n.t('版本'), width: 86, templet: row => 'r' + Number(row.id)},
                {title: I18n.t('连接配置'), minWidth: 180, templet: row => layui.util.escape(Gateway.describe(row))},
                {title: I18n.t('来源'), width: 120, templet: row => row.restored_from ? I18n.t('恢复自 r') + Number(row.restored_from) : I18n.t('保存')},
                {title: I18n.t('时间'), width: 165, templet: row => Number(row.created_at) === 0 ? I18n.t('初始配置') : new Date(Number(row.created_at) * 1000).toLocaleString(I18n.locale, {hour12: false})}
            ]]
        });
        if (value.last_test && jobId === null) {
            result(value.last_test);
            if (['queued', 'running'].includes(value.last_test.status)) startPoll(value.last_test.id);
        }
        controls();
    }

    /** Reload explicitly; callers decide whether replacing unsaved fields is authorized. */
    async function load(hydrate, expectedEdit = editVersion) {
        const value = await Gateway.request('/api/network');
        render(value, hydrate && expectedEdit === editVersion);
    }

    /** Execute a mutation without duplicate submissions or ambiguous post-save failure wording. */
    async function mutate(path, payload, success, hydrate) {
        if (busy) return;
        busy = true;
        controls();
        let acknowledged = false;
        const submittedEdit = editVersion;
        try {
            const outcome = await Gateway.request(path, payload);
            acknowledged = true;
            if (path.endsWith('/save') && submittedEdit === editVersion) byId('password').value = '';
            message(success + ' r' + outcome.revision + (I18n.locale === 'en' ? '.' : '。'));
            await load(hydrate, submittedEdit);
        } catch (error) {
            message((acknowledged ? I18n.t('操作已经保存，但状态刷新失败。请重新载入核对。') : '') + error.message, true);
        } finally {
            busy = false;
            controls();
        }
    }

    /** First-run path: save one immutable revision and immediately make it the app's active network. */
    async function saveAndApply(payload) {
        if (busy) return;
        busy = true;
        controls();
        const submittedEdit = editVersion;
        let savedRevision = null;
        try {
            const saved = await Gateway.request('/api/network/save', payload);
            savedRevision = Number(saved.revision);
            if (submittedEdit === editVersion) byId('password').value = '';
            await Gateway.request('/api/network/apply', {expected_revision: savedRevision});
            message(I18n.t('网络配置 r') + savedRevision + I18n.t(' 已保存并应用，可以继续完成运行准备。'));
            await load(true, submittedEdit);
        } catch (error) {
            message(savedRevision
                ? (I18n.t('配置 r') + savedRevision + I18n.t(' 已保存，但应用失败：') + error.message + I18n.t(' 请检查后点击“应用已保存配置”。'))
                : error.message, true);
            try { await load(false, submittedEdit); } catch (_) {}
        } finally {
            busy = false;
            controls();
        }
    }

    /** Display diagnostic result separately from application state. */
    function result(job) {
        const data = job.result;
        const running = ['queued', 'running'].includes(job.status);
        byId('test-result').textContent = 'r' + job.revision + ' · ' + (running ? I18n.t('正在检测，可继续编辑设置…') : I18n.networkResult(data));
        byId('test-result').className = 'notice' + (!running && job.status === 'failed' ? ' warning' : '');
        const routes = {manual_http_connect: I18n.t('HTTP CONNECT 代理'), explicit_direct: I18n.t('明确直连'), loopback_direct: I18n.t('本机直连')};
        byId('test-detail').textContent = data ? [routes[data.route] || '', data.code, data.elapsed_ms === undefined ? '' : data.elapsed_ms + ' ms', data.http_status ? 'HTTP ' + data.http_status : '', data.connect_status ? 'CONNECT ' + data.connect_status : '', data.curl_code ? 'cURL ' + data.curl_code : ''].filter(Boolean).join(' · ') : '';
    }

    /** Poll only the current persisted job, with no editable-form replacement. */
    async function poll() {
        if (polling || disposed || jobId === null) return;
        if (document.hidden) {
            pollTimer = setTimeout(poll, 1000);
            return;
        }
        polling = true;
        try {
            const job = await Gateway.request('/api/network/tests/' + jobId);
            result(job);
            if (!['queued', 'running'].includes(job.status)) jobId = null;
        } catch (error) {
            byId('test-result').textContent = error.message + I18n.t(' 检测记录已保留，可重新载入查询。');
        } finally {
            polling = false;
            if (Date.now() - pollStarted > 60000 && jobId !== null) {
                jobId = null;
                byId('test-result').textContent = I18n.t('结果尚未确认，已暂停查询。请重新载入核对，不自动重复检测。');
            }
            controls();
            if (jobId !== null && !disposed) pollTimer = setTimeout(poll, 800);
        }
    }

    /** Resume one server-owned diagnostic, including after a page reload. */
    function startPoll(id) {
        clearTimeout(pollTimer);
        jobId = id;
        pollStarted = Date.now();
        controls();
        pollTimer = setTimeout(poll, 300);
    }

    form.on('radio(mode)', changed);
    form.on('switch(authentication)', changed);
    form.on('select(password-action)', changed);
    form.on('select(protocol)', changed);
    form.on('select(test-source)', controls);
    form.on('select(restore-source)', controls);
    byId('network-form').addEventListener('input', changed);
    byId('network-form').addEventListener('submit', event => event.preventDefault());
    form.on('submit(save-network)', function () {
        if (!snapshot || busy) return false;
        const data = form.getValue('network');
        const manual = data.mode === 'manual';
        const auth = manual && byId('authentication').checked;
        const port = manual ? Number(data.port) : 0;
        if (manual && (!data.host.trim() || !Number.isInteger(port) || port < 1 || port > 65535)) {
            message(I18n.t('请填写有效代理主机，以及 1–65535 范围内的整数端口。'), true);
            return false;
        }
        if (auth && (!data.username || (data.password_action === 'set' && !data.password))) {
            message(I18n.t('请填写认证用户名和密码。'), true);
            return false;
        }
        saveAndApply({expected_revision: Number(snapshot.saved.id), mode: data.mode, protocol: 'http', host: manual ? data.host : '', port, username: auth ? data.username : '', password: auth && data.password_action === 'set' ? data.password : '', password_action: auth ? data.password_action : 'clear'});
        return false;
    });
    byId('apply').addEventListener('click', function () {
        if (snapshot) mutate('/api/network/apply', {expected_revision: Number(snapshot.saved.id)}, I18n.t('PHP 检测已应用版本'), false);
    });
    byId('continue-setup').addEventListener('click', () => Gateway.openBrowser());
    byId('reload').addEventListener('click', function () {
        const reload = async function () {
            busy = true;
            controls();
            try { await load(true); message(I18n.t('已重新载入设置。')); }
            catch (error) { message(error.message, true); }
            finally { busy = false; controls(); }
        };
        if (dirty) {
            layer.confirm(I18n.t('重新载入会丢弃表单内未保存的修改。是否继续？'), {title: I18n.t('重新载入设置'), btn: [I18n.t('重新载入'), I18n.t('取消')], closeBtn: 1}, function (index) { layer.close(index); reload(); });
        } else reload();
    });
    byId('restore').addEventListener('click', function () {
        const source = Number(byId('restore-source').value);
        if (!source || !snapshot) return;
        layer.confirm(I18n.t('将 r') + source + I18n.t(' 恢复为新的已保存版本。') + (dirty ? I18n.t('表单内未保存的修改将被替换。') : '') + I18n.t('当前已应用版本保持不变。'), {title: I18n.t('恢复配置'), btn: [I18n.t('恢复'), I18n.t('取消')], closeBtn: 1}, function (index) {
            layer.close(index);
            mutate('/api/network/restore', {expected_revision: Number(snapshot.saved.id), source_revision: source}, I18n.t('已恢复为版本'), true);
        });
    });
    byId('test').addEventListener('click', async function () {
        if (busy || jobId !== null || !snapshot) return;
        const source = byId('test-source').value;
        const revision = source === 'active' ? snapshot.active : snapshot.saved;
        if (!revision) return;
        busy = true;
        controls();
        try {
            const job = await Gateway.request('/api/network/test', {revision: Number(revision.id), target_id: byId('target').value, source});
            result({revision: revision.id, status: 'queued'});
            startPoll(job.id);
        } catch (error) { message(error.message, true); }
        finally { busy = false; controls(); }
    });
    window.addEventListener('pagehide', function () { disposed = true; clearTimeout(pollTimer); });
    window.addEventListener('gateway-language-changed', function () {
        if (snapshot) render(snapshot, false);
    });

    /** Initial loading needs only local assets and local API connectivity. */
    async function initialize() {
        controls();
        try {
            const config = await Gateway.bootstrap();
            config.targets.forEach(target => byId('target').add(new Option(I18n.t(target.label), target.id)));
            form.render('select');
            await load(true);
        } catch (error) { message(error.message, true); controls(); }
    }
    initialize();
});
