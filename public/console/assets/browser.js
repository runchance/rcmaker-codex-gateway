(function () {
    'use strict';
    const byId = id => document.getElementById(id);
    const labels = {queued: I18n.t('准备页面'), submit_intent: I18n.t('准备发送'), submitted: I18n.t('已发送'), running: I18n.t('生成中'),
        committed: I18n.t('可继续'), ready: I18n.t('可继续'), busy: I18n.t('生成中'), attaching: I18n.t('连接页面'), reserved: I18n.t('等待页面'),
        needs_attention: I18n.t('需要确认')};
    const workingTurnStatuses = new Set(['queued', 'submit_intent', 'submitted', 'running']);
    let reading = false;
    let screenshotReading = false;
    let disposed = false;
    let selectedSurfaceId = null;
    let selectionIsManual = false;
    let lastState = null;
    let lastTabsSignature = '';
    let lastScreenshotSequence = 0;
    let screenshotObjectUrl = null;
    let closingSurfaceId = null;
    function setError(message) {
        byId('error').textContent = message || '';
        byId('error').hidden = !message;
    }
    function setBadge(id, text, tone) {
        const el = byId(id);
        el.textContent = text;
        el.className = 'badge' + (tone ? ' ' + tone : '');
    }
    function taskPages(state) {
        const pages = Array.isArray(state.monitors)
            ? state.monitors.filter(row => row && row.surface_id).slice(0, 5)
            : (state.monitor && state.monitor.active && state.monitor.surface_id ? [state.monitor] : []);
        return pages.sort((left, right) => Number(isWorkingPage(right)) - Number(isWorkingPage(left)));
    }
    function isWorkingPage(page) {
        return workingTurnStatuses.has(page.turn_status) && page.surface_status !== 'needs_attention';
    }
    function pageStatusLabel(page) {
        return I18n.t(labels[page.surface_status === 'needs_attention' ? page.surface_status : page.turn_status]
            || labels[page.surface_status] || I18n.t('等待中'));
    }
    function clearScreenshot() {
        const image = byId('automation-surface');
        image.onload = null;
        image.onerror = null;
        image.removeAttribute('src');
        image.hidden = true;
        byId('automation-empty').hidden = false;
        lastScreenshotSequence = 0;
        if (screenshotObjectUrl) URL.revokeObjectURL(screenshotObjectUrl);
        screenshotObjectUrl = null;
    }
    function pageTitle(page) {
        // monitors.title is the backend's sanitized user-prompt projection, not Chrome's title.
        return page.conversation_title || page.title || page.latest_user || I18n.t('任务页面');
    }
    async function closePage(page) {
        if (closingSurfaceId || !page.closable) return;
        closingSurfaceId = page.surface_id;
        const confirmed = await new Promise(resolve => layui.use('layer', () => {
            let accepted = false;
            layui.layer.confirm(I18n.t('关闭这个已完成回合的页面？任务记录会保留，下次继续时会重新建立页面。'),
                {title: I18n.t('关闭'), btn: [I18n.t('关闭'), I18n.t('取消')], closeBtn: 1, end: () => resolve(accepted)},
                index => { accepted = true; layui.layer.close(index); });
        }));
        if (!confirmed) { closingSurfaceId = null; return; }
        try {
            await Gateway.request('/api/browser/monitor/close', {surface_id: page.surface_id});
            if (selectedSurfaceId === page.surface_id) {
                selectedSurfaceId = null;
                selectionIsManual = false;
            }
            clearScreenshot();
            await refresh();
        } catch (error) {
            setError(error.message);
        } finally {
            closingSurfaceId = null;
        }
    }
    function renderTabs(pages) {
        const signature = JSON.stringify(pages.map(page => [page.surface_id, pageTitle(page),
            page.turn_status, page.surface_status, page.closable])) + '|' + selectedSurfaceId;
        if (signature === lastTabsSignature) return;
        lastTabsSignature = signature;
        const tabs = byId('automation-tabs');
        tabs.replaceChildren();
        for (const page of pages) {
            const wrapper = document.createElement('div');
            wrapper.className = 'browser-tab' + (page.surface_id === selectedSurfaceId ? ' active' : '');
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'browser-tab-select';
            button.setAttribute('role', 'tab');
            button.setAttribute('aria-selected', page.surface_id === selectedSurfaceId ? 'true' : 'false');
            button.title = pageTitle(page);
            button.dataset.userContent = 'true';
            button.textContent = (isWorkingPage(page) ? I18n.t('活动中') : I18n.t('非活动')) + ' · '
                + pageTitle(page) + ' · ' + pageStatusLabel(page);
            button.addEventListener('click', () => {
                if (selectedSurfaceId === page.surface_id) return;
                selectedSurfaceId = page.surface_id;
                selectionIsManual = true;
                clearScreenshot();
                if (lastState) {
                    render(lastState);
                    refreshScreenshot(lastState);
                }
            });
            wrapper.appendChild(button);
            if (page.closable) {
                const close = document.createElement('button');
                close.type = 'button';
                close.className = 'browser-tab-close';
                close.title = I18n.t('关闭空闲页面，保留任务记录；下次继续会重建页面');
                close.setAttribute('aria-label', I18n.t('关闭') + pageTitle(page) + I18n.t('的空闲页面'));
                close.textContent = '×';
                close.disabled = closingSurfaceId === page.surface_id;
                close.addEventListener('click', () => closePage(page));
                wrapper.appendChild(close);
            }
            tabs.appendChild(wrapper);
        }
    }
    function render(state) {
        lastState = state;
        const pages = taskPages(state);
        const previous = selectedSurfaceId;
        const selectedPage = pages.find(page => page.surface_id === selectedSurfaceId);
        if (!selectedPage || (!selectionIsManual && !isWorkingPage(selectedPage)
            && pages.some(isWorkingPage))) {
            if (!selectedPage) selectionIsManual = false;
            selectedSurfaceId = pages.length ? pages[0].surface_id : null;
        }
        if (previous !== selectedSurfaceId) clearScreenshot();
        renderTabs(pages);
        byId('automation-panel').hidden = pages.length === 0;
        byId('automation-idle').hidden = pages.length > 0;
        if (!pages.length) return;
        const page = pages.find(row => row.surface_id === selectedSurfaceId);
        setBadge('automation-status', (isWorkingPage(page) ? I18n.t('活动中') : I18n.t('非活动')) + ' · ' + pageStatusLabel(page),
            page.surface_status === 'needs_attention' ? 'warning' : isWorkingPage(page) ? 'good' : '');
        byId('automation-task').textContent = I18n.t('任务 ') + String(page.task_id || '').slice(-12);
        byId('automation-title').textContent = pageTitle(page);
    }
    async function refreshScreenshot(state) {
        const surfaceId = selectedSurfaceId;
        if (screenshotReading || disposed || document.hidden || !surfaceId
            || !taskPages(state).some(page => page.surface_id === surfaceId)) return;
        screenshotReading = true;
        try {
            const result = await Gateway.binary('/api/browser/monitor/screenshot?surface_id=' + encodeURIComponent(surfaceId));
            if (selectedSurfaceId !== surfaceId || result.surfaceId !== surfaceId) return;
            if (result.sequence > 0 && result.sequence <= lastScreenshotSequence) return;
            if (result.blob.type && !result.blob.type.startsWith('image/')) throw new Error(I18n.t('自动化页面画面格式无效。'));
            const url = URL.createObjectURL(result.blob);
            const previous = screenshotObjectUrl;
            screenshotObjectUrl = url;
            if (result.sequence > 0) lastScreenshotSequence = result.sequence;
            const image = byId('automation-surface');
            image.onload = () => { if (previous) URL.revokeObjectURL(previous); };
            image.onerror = () => {
                URL.revokeObjectURL(url);
                if (screenshotObjectUrl === url) screenshotObjectUrl = null;
            };
            image.src = url;
            image.hidden = false;
            byId('automation-empty').hidden = true;
        } catch (e) {
            if (selectedSurfaceId !== surfaceId) return;
            if (e.code === 'NOT_READY') clearScreenshot();
            byId('automation-empty').querySelector('strong').textContent = I18n.t('任务页面正在准备');
            byId('automation-empty').querySelector('span').textContent = I18n.t('画面就绪后会自动显示，不影响任务继续执行。');
        } finally {
            screenshotReading = false;
        }
    }
    async function refresh() {
        if (disposed || reading || document.hidden) return;
        reading = true;
        byId('refresh').disabled = true;
        try {
            const ready = await Gateway.ensureReady({quietAfterAdmission: true});
            if (!ready) return;
            const state = await Gateway.request('/api/browser');
            render(state);
            setError('');
            refreshScreenshot(state);
        } catch (e) {
            setError(e.message);
        } finally {
            reading = false;
            byId('refresh').disabled = false;
        }
    }
    byId('refresh').addEventListener('click', refresh);
    window.addEventListener('gateway-language-changed', () => {
        lastTabsSignature = '';
        if (lastState) render(lastState);
    });
    document.addEventListener('visibilitychange', () => { if (!document.hidden) refresh(); });
    window.addEventListener('pagehide', () => { disposed = true; clearScreenshot(); });
    setInterval(refresh, 3000);
    setInterval(() => { if (lastState) refreshScreenshot(lastState); }, 1300);
    refresh();
})();
