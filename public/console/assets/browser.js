(function () {
    'use strict';
    const byId = id => document.getElementById(id);
    const labels = {queued: I18n.t('准备页面'), submit_intent: I18n.t('准备发送'), submitted: I18n.t('已发送'), running: I18n.t('生成中'),
        committed: I18n.t('可继续'), ready: I18n.t('可继续'), busy: I18n.t('生成中'), attaching: I18n.t('连接页面'), reserved: I18n.t('等待页面'),
        needs_attention: I18n.t('需要确认')};
    const workingTurnStatuses = new Set(['queued', 'submit_intent', 'submitted', 'running']);
    const closeRejections = new Set(['surface_not_closable', 'INVALID_BROWSER_SURFACE', 'INVALID_FIELD', 'INVALID_JSON']);
    let reading = false;
    let readSequence = 0;
    let refreshFailures = 0;
    let nextRefreshAt = 0;
    let refreshTimer = null;
    let screenshotTimer = null;
    let screenshotReading = false;
    let disposed = false;
    let selectedSurfaceId = null;
    let selectionIsManual = false;
    let lastState = null;
    let lastTabsSignature = '';
    let lastScreenshotSequence = 0;
    let screenshotObjectUrl = null;
    let closingSurfaceId = null;
    const pendingClosures = new Map();
    let closeVersion = 0;
    let closeError = '';
    let closeErrorSurfaceId = null;
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
            ? state.monitors.filter(row => row && row.surface_id)
            : (state.monitor && state.monitor.active && state.monitor.surface_id ? [state.monitor] : []);
        // Closing pages retain physical ownership until the worker confirms disappearance.
        // Their bounded projection must not hide any of the five ordinary task pages.
        const closing = new Map();
        for (const [surfaceId, pending] of pendingClosures) {
            closing.set(surfaceId, pages.find(page => page.surface_id === surfaceId) || pending.page);
        }
        for (const page of pages) if (isClosingPage(page) && !closing.has(page.surface_id)) closing.set(page.surface_id, page);
        return pages.filter(page => !isClosingPage(page)).slice(0, 5)
            .sort((left, right) => Number(isWorkingPage(right)) - Number(isWorkingPage(left)))
            .concat(Array.from(closing.values()).slice(0, 5));
    }
    function isClosingPage(page) {
        return page.closing === true || pendingClosures.has(page.surface_id);
    }
    function isWorkingPage(page) {
        return !isClosingPage(page) && workingTurnStatuses.has(page.turn_status)
            && page.surface_status !== 'needs_attention';
    }
    function pageStatusLabel(page) {
        if (isClosingPage(page)) return I18n.t('正在关闭');
        return I18n.t(labels[page.surface_status === 'needs_attention' ? page.surface_status : page.turn_status]
            || labels[page.surface_status] || I18n.t('等待中'));
    }
    /** Explain the authoritative close guard without cancelling work or inferring permission. */
    function closeReason(page) {
        if (pendingClosures.get(page.surface_id)?.phase === 'unknown') {
            return I18n.t('关闭结果暂未确认，正在核对页面状态；不会重复发送关闭请求。');
        }
        if (isClosingPage(page)) return I18n.t('正在关闭页面；确认 Chrome 页面已消失后会从列表移除。');
        const reasons = {
            page_needs_attention: '此页面仍有待确认的执行结果，请先在任务中心处理。',
            page_active: '任务正在工作或准备发送，完成后才能关闭页面。',
            page_inactive_epoch: '此页面不属于当前任务回合，暂不能关闭。',
            page_incomplete: '此页面的任务尚未完整结束，暂不能关闭。',
            page_tools_pending: '任务仍有工具调用或回执等待完成，暂不能关闭页面。'
        };
        if (page.close_blocker_code) return I18n.t(reasons[page.close_blocker_code] || '此页面暂不能关闭。');
        if (page.closable && pendingClosures.size >= 5) {
            return I18n.t('正在等待已请求关闭的页面，完成后可继续关闭其他页面。');
        }
        return page.closable ? I18n.t('关闭空闲页面，保留任务记录；下次继续会重建页面')
            : I18n.t('此页面暂不能关闭。');
    }
    function clearScreenshot() {
        const image = byId('automation-surface');
        image.onload = null;
        image.onerror = null;
        image.removeAttribute('src');
        image.hidden = true;
        byId('automation-empty').hidden = false;
        byId('automation-empty').querySelector('strong').textContent = I18n.t('任务页面正在准备');
        byId('automation-empty').querySelector('span').textContent = I18n.t('页面画面出现后会持续自动更新。');
        lastScreenshotSequence = 0;
        if (screenshotObjectUrl) URL.revokeObjectURL(screenshotObjectUrl);
        screenshotObjectUrl = null;
    }
    function pageTitle(page) {
        // monitors.title is the backend's sanitized user-prompt projection, not Chrome's title.
        return page.conversation_title || page.title || page.latest_user || I18n.t('任务页面');
    }
    /** Only an exact close-state read started after the POST can confirm physical closure. */
    function applyCloseStates(state, requested) {
        if (!state.close_states || typeof state.close_states !== 'object') return;
        for (const [surfaceId, observed] of requested) {
            const pending = pendingClosures.get(surfaceId);
            if (!pending || pending.phase === 'sending' || observed.phase !== pending.phase
                || observed.version !== pending.version) continue;
            const status = state.close_states[surfaceId];
            if (status !== 'closed' && status !== 'closing') continue;
            if (status === 'closed') pendingClosures.delete(surfaceId);
            else if (pending.phase === 'unknown') {
                pending.phase = 'accepted';
                pending.version = ++closeVersion;
            }
            if (closeErrorSurfaceId === surfaceId) {
                closeError = '';
                closeErrorSurfaceId = null;
            }
        }
    }
    async function closePage(page) {
        if (closingSurfaceId || pendingClosures.size >= 5 || !page.closable || page.close_blocker_code || isClosingPage(page)) return;
        closingSurfaceId = page.surface_id;
        if (lastState) render(lastState);
        const confirmed = await new Promise(resolve => layui.use('layer', () => {
            let accepted = false;
            layui.layer.confirm(I18n.t('关闭这个已完成回合的页面？任务记录会保留，下次继续时会重新建立页面。'),
                {title: I18n.t('关闭'), btn: [I18n.t('关闭'), I18n.t('取消')], closeBtn: 1, end: () => resolve(accepted)},
                index => { accepted = true; layui.layer.close(index); });
        }));
        if (!confirmed) {
            closingSurfaceId = null;
            if (lastState) render(lastState);
            return;
        }
        pendingClosures.set(page.surface_id, {page: {...page}, phase: 'sending', version: ++closeVersion});
        closeError = '';
        closeErrorSurfaceId = null;
        setError('');
        if (lastState) render(lastState);
        try {
            const receipt = await Gateway.request('/api/browser/monitor/close', {surface_id: page.surface_id});
            if (receipt?.closing === false) pendingClosures.delete(page.surface_id);
            else {
                const pending = pendingClosures.get(page.surface_id);
                if (pending) { pending.phase = 'accepted'; pending.version = ++closeVersion; }
            }
            await refresh(true);
        } catch (error) {
            const rejected = closeRejections.has(error.code);
            if (rejected) pendingClosures.delete(page.surface_id);
            else {
                const pending = pendingClosures.get(page.surface_id);
                if (pending) { pending.phase = 'unknown'; pending.version = ++closeVersion; }
            }
            closeError = error.message;
            closeErrorSurfaceId = page.surface_id;
            if (!rejected) closeError += '\n' + I18n.t('关闭结果暂未确认，正在核对页面状态；不会重复发送关闭请求。');
            setError(closeError);
        } finally {
            closingSurfaceId = null;
            if (lastState) render(lastState);
        }
    }
    function renderTabs(pages) {
        const signature = JSON.stringify(pages.map(page => [page.surface_id, pageTitle(page),
            page.turn_status, page.surface_status, page.closable, page.close_blocker_code, isClosingPage(page), pendingClosures.get(page.surface_id)?.phase]))
            + '|' + selectedSurfaceId + '|' + closingSurfaceId + '|' + pendingClosures.size;
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
            button.disabled = isClosingPage(page);
            button.setAttribute('aria-disabled', button.disabled ? 'true' : 'false');
            button.title = pageTitle(page);
            button.dataset.userContent = 'true';
            button.textContent = (isWorkingPage(page) ? I18n.t('活动中') : I18n.t('非活动')) + ' · '
                + pageTitle(page) + ' · ' + pageStatusLabel(page);
            button.addEventListener('click', () => {
                if (isClosingPage(page) || selectedSurfaceId === page.surface_id) return;
                selectedSurfaceId = page.surface_id;
                selectionIsManual = true;
                clearScreenshot();
                if (lastState) {
                    render(lastState);
                    refreshScreenshot(lastState, true);
                }
            });
            wrapper.appendChild(button);
            const close = document.createElement('button');
            close.type = 'button';
            close.className = 'browser-tab-close';
            close.title = closeReason(page);
            close.setAttribute('aria-label', I18n.t('关闭') + pageTitle(page) + I18n.t('的空闲页面'));
            close.textContent = '×';
            close.disabled = !page.closable || !!page.close_blocker_code || isClosingPage(page)
                || closingSurfaceId === page.surface_id || pendingClosures.size >= 5;
            close.addEventListener('click', () => closePage(page));
            wrapper.appendChild(close);
            tabs.appendChild(wrapper);
        }
    }
    function render(state) {
        lastState = state;
        const pages = taskPages(state);
        const selectable = pages.filter(page => !isClosingPage(page));
        const previous = selectedSurfaceId;
        const selectedPage = selectable.find(page => page.surface_id === selectedSurfaceId);
        if (!selectedPage || (!selectionIsManual && !isWorkingPage(selectedPage)
            && selectable.some(isWorkingPage))) {
            if (!selectedPage) selectionIsManual = false;
            selectedSurfaceId = selectable.length ? selectable[0].surface_id : null;
        }
        if (previous !== selectedSurfaceId) clearScreenshot();
        renderTabs(pages);
        byId('automation-panel').hidden = pages.length === 0;
        byId('automation-idle').hidden = pages.length > 0;
        if (!pages.length) return;
        if (!selectable.length) {
            clearScreenshot();
            setBadge('automation-status', I18n.t('正在关闭'), 'warning');
            byId('automation-task').textContent = I18n.t('任务 ') + String(pages[0].task_id || '').slice(-12);
            byId('automation-title').textContent = pageTitle(pages[0]);
            byId('automation-empty').querySelector('strong').textContent = I18n.t('正在关闭');
            byId('automation-empty').querySelector('span').textContent = I18n.t('正在关闭页面；确认 Chrome 页面已消失后会从列表移除。');
            return;
        }
        const page = pages.find(row => row.surface_id === selectedSurfaceId);
        setBadge('automation-status', (isWorkingPage(page) ? I18n.t('活动中') : I18n.t('非活动')) + ' · ' + pageStatusLabel(page),
            page.surface_status === 'needs_attention' ? 'warning' : isWorkingPage(page) ? 'good' : '');
        byId('automation-task').textContent = I18n.t('任务 ') + String(page.task_id || '').slice(-12);
        byId('automation-title').textContent = pageTitle(page);
    }
    /** Skip hidden or actively manipulated views, including responses that arrive after a tab switch. */
    async function refreshScreenshot(state, manual = false) {
        const surfaceId = selectedSurfaceId;
        if (screenshotReading || disposed || !Gateway.isPageActive() || !surfaceId
            || (!manual && !Gateway.automaticRefreshAllowed())
            || !taskPages(state).some(page => page.surface_id === surfaceId && !isClosingPage(page))) return;
        screenshotReading = true;
        try {
            const result = await Gateway.binary('/api/browser/monitor/screenshot?surface_id=' + encodeURIComponent(surfaceId));
            if (disposed || !Gateway.isPageActive() || (!manual && !Gateway.automaticRefreshAllowed())
                || selectedSurfaceId !== surfaceId || result.surfaceId !== surfaceId
                || !lastState || !taskPages(lastState).some(page => page.surface_id === surfaceId && !isClosingPage(page))) return;
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
            if (disposed || !Gateway.isPageActive() || selectedSurfaceId !== surfaceId
                || (!manual && !Gateway.automaticRefreshAllowed())) return;
            if (e.code === 'NOT_READY') clearScreenshot();
            byId('automation-empty').querySelector('strong').textContent = I18n.t('任务页面正在准备');
            byId('automation-empty').querySelector('span').textContent = I18n.t('画面就绪后会自动显示，不影响任务继续执行。');
        } finally {
            screenshotReading = false;
        }
    }
    /** Read live workspace state once per poll after admission; retain frames and back off failed reads. */
    async function refresh(manual = false) {
        if (disposed || !Gateway.isPageActive() || (!manual && (reading || Date.now() < nextRefreshAt
            || !Gateway.automaticRefreshAllowed()))) return;
        const sequence = ++readSequence;
        reading = true;
        if (manual) byId('refresh').disabled = true;
        try {
            // Entry and explicit refresh check setup. Following an admitted workspace only needs its live state;
            // API operations still enforce their own current readiness and page ownership.
            if ((manual || !lastState) && !await Gateway.ensureReady({quietAfterAdmission: true})) return;
            if (disposed || sequence !== readSequence || !Gateway.isPageActive()) return;
            const requested = new Map(Array.from(pendingClosures, ([surfaceId, pending]) =>
                [surfaceId, {phase: pending.phase, version: pending.version}]));
            const ids = Array.from(requested.keys());
            const state = await Gateway.request('/api/browser' + (ids.length
                ? '?closing_surfaces=' + encodeURIComponent(ids.join(',')) : ''), undefined, 30000);
            if (disposed || sequence !== readSequence || !Gateway.isPageActive()
                || (!manual && !Gateway.automaticRefreshAllowed())) return;
            refreshFailures = 0;
            nextRefreshAt = 0;
            applyCloseStates(state, requested);
            render(state);
            setError(closeError);
            refreshScreenshot(state, manual);
        } catch (e) {
            if (!disposed && sequence === readSequence && (manual || Gateway.automaticRefreshAllowed())) {
                nextRefreshAt = Date.now() + Math.min(15000, 3000 * 2 ** Math.min(refreshFailures++, 3));
                const unconfirmed = e.code === 'LOCAL_REQUEST_TIMEOUT' || e.code === 'LOCAL_REQUEST_UNCONFIRMED';
                setError(closeError || (unconfirmed
                    ? I18n.t('工作区状态刷新暂未完成，正在自动重试；已显示的画面会保留。') : e.message));
            }
        } finally {
            if (sequence === readSequence) {
                reading = false;
                byId('refresh').disabled = false;
            }
        }
    }
    byId('refresh').addEventListener('click', () => refresh(true));
    window.addEventListener('gateway-language-changed', () => {
        lastTabsSignature = '';
        if (lastState) render(lastState);
    });
    /** A cached Pear tab keeps its selected task and resumes current state without reloading its document. */
    function startFollowing() {
        clearInterval(refreshTimer);
        clearInterval(screenshotTimer);
        refreshTimer = setInterval(() => refresh(false), 3000);
        screenshotTimer = setInterval(() => { if (lastState) refreshScreenshot(lastState); }, 1300);
    }
    Gateway.onPageActivate(() => refresh(false));
    window.addEventListener('pagehide', () => {
        disposed = true;
        ++readSequence;
        reading = false;
        clearInterval(refreshTimer);
        clearInterval(screenshotTimer);
        clearScreenshot();
    });
    window.addEventListener('pageshow', () => {
        if (!disposed) return;
        disposed = false;
        startFollowing();
        refresh(true);
    });
    startFollowing();
    refresh(true);
})();
