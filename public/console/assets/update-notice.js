/* Passive release notices: cached local reads, no dialogs, automatic navigation or task changes. */
(function () {
    'use strict';
    if (window.parent !== window) return;
    const badge = document.getElementById('gateway-update-notice');
    if (!badge) return;
    const link = badge.querySelector('a');
    const label = badge.querySelector('[data-update-label]');
    const dismiss = badge.querySelector('button');
    const storageKey = 'gateway.dismissedRelease';
    const interval = 60000;
    let state = null, dismissed = '', reading = false, stopped = false, timer = null, nextRead = 0;
    try { dismissed = localStorage.getItem(storageKey) || ''; } catch (_) {}

    /** Update only notice text and visibility; leave the current page, focus and selection intact. */
    function render() {
        const version = state?.latest_version;
        const valid = typeof version === 'string' && /^v(?:0|[1-9]\d{0,7})\.(?:0|[1-9]\d{0,7})\.(?:0|[1-9]\d{0,7})$/.test(version);
        const url = valid ? 'https://github.com/runchance/rcmaker-codex-gateway/releases/tag/' + version : '';
        const visible = state?.available === true && valid && state.release_url === url && dismissed !== version;
        badge.hidden = !visible;
        if (!visible) return;
        const text = I18n.t('新版可用') + ' ' + version;
        if (label.textContent !== text) label.textContent = text;
        link.href = url;
        link.title = I18n.t('空闲时查看更新说明');
        dismiss.title = I18n.t('忽略此版本提醒');
        dismiss.setAttribute('aria-label', I18n.t('忽略此版本提醒'));
    }

    /** Schedule lightweight local cache reads only while the console is visible. */
    function schedule() {
        clearTimeout(timer);
        if (!stopped && !document.hidden) timer = setTimeout(read, Math.max(1000, nextRead - Date.now()));
    }

    /** Network errors remain silent; this endpoint never starts a remote check. */
    async function read() {
        if (stopped || document.hidden || reading) return;
        if (Date.now() < nextRead) { schedule(); return; }
        reading = true;
        nextRead = Date.now() + interval;
        try {
            state = await Gateway.request('/api/updates', undefined, 5000);
            if (!stopped) render();
        } catch (_) {
            // Offline/older servers must not produce alerts or affect other console requests.
        } finally {
            reading = false;
            schedule();
        }
    }

    dismiss.addEventListener('click', function () {
        dismissed = state?.latest_version || '';
        try { localStorage.setItem(storageKey, dismissed); } catch (_) {}
        // Move focus out of the hidden notice only when the user explicitly dismisses it.
        if (badge.contains(document.activeElement)) document.getElementById('console-language')?.focus();
        render();
    });
    window.addEventListener('storage', function (event) {
        if (event.key === storageKey) { dismissed = event.newValue || ''; render(); }
    });
    window.addEventListener('gateway-language-changed', render);
    document.addEventListener('visibilitychange', schedule);
    window.addEventListener('pagehide', function () { stopped = true; clearTimeout(timer); });
    window.addEventListener('pageshow', function () { stopped = false; schedule(); });
    nextRead = Date.now() + 5000;
    schedule();
})();
