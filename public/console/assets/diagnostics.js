/* Local support download. No readiness gate, polling, uploads or task mutations. */
(() => {
    'use strict';
    const byId = id => document.getElementById(id);
    let busy = false;
    let disposed = false;
    let objectUrl = null;
    let objectUrlOwner = window;
    let revokeTimer = null;

    /** Revoke only this page's download URL; never revoke before the browser has consumed it. */
    function releaseDownload() {
        if (revokeTimer) clearTimeout(revokeTimer);
        if (objectUrl) objectUrlOwner.URL.revokeObjectURL(objectUrl);
        revokeTimer = null;
        objectUrl = null;
    }

    /** The same-origin Pear shell owns downloads in embedded desktop browsers. */
    function downloadHost() {
        try {
            if (window.parent !== window && window.parent.location.origin === window.location.origin) return window.parent;
        } catch (_) { /* A standalone/cross-origin embedding keeps its own download boundary. */ }
        return window;
    }

    /** Download with immediate pending/error feedback and one request per click sequence. */
    async function download(event) {
        event.preventDefault();
        if (busy || disposed) return;
        busy = true;
        const button = byId('download-diagnostics');
        const range = byId('diagnostics-hours');
        const errorBox = byId('diagnostics-error');
        const result = byId('diagnostics-result');
        button.disabled = true;
        button.classList.add('layui-btn-disabled');
        range.disabled = true;
        layui.form.render('select');
        errorBox.hidden = true;
        result.hidden = true;
        byId('diagnostics-progress').textContent = I18n.t('正在采集并打包运行日志…');
        try {
            const response = await Gateway.binary('/api/diagnostics/export?hours=' + encodeURIComponent(range.value), {
                timeoutMs: 30000, errorMessage: '运行日志导出失败，请稍后重试。'
            });
            if (disposed) return;
            const type = response.blob.type.split(';')[0];
            if (!['application/zip', 'application/json'].includes(type) || !response.blob.size) {
                throw new Error(I18n.t('运行日志导出失败，请稍后重试。'));
            }
            releaseDownload();
            objectUrlOwner = downloadHost();
            objectUrl = objectUrlOwner.URL.createObjectURL(response.blob);
            const filename = 'rcmaker-diagnostics-' + new Date().toISOString().replace(/[-:]/g, '').slice(0, 15)
                + (type === 'application/zip' ? '.zip' : '.json');
            const link = objectUrlOwner.document.createElement('a');
            link.href = objectUrl;
            link.download = filename;
            objectUrlOwner.document.body.append(link);
            link.click();
            link.remove();
            revokeTimer = setTimeout(releaseDownload, 60000);
            const label = document.createElement('span');
            label.textContent = I18n.t('诊断包已生成并开始下载：');
            const detail = document.createElement('span');
            detail.dataset.i18nSkip = '';
            detail.textContent = filename + ' · ' + (response.blob.size / 1024).toFixed(1) + ' KiB';
            result.replaceChildren(label, detail);
            result.hidden = false;
        } catch (error) {
            if (disposed) return;
            errorBox.textContent = error.name === 'AbortError'
                ? I18n.t('导出等待超时，请稍后重试；正在运行的任务不受影响。')
                : (error.message || I18n.t('运行日志导出失败，请稍后重试。'));
            errorBox.hidden = false;
        } finally {
            busy = false;
            if (!disposed) {
                button.disabled = false;
                button.classList.remove('layui-btn-disabled');
                range.disabled = false;
                layui.form.render('select');
                byId('diagnostics-progress').textContent = '';
            }
        }
    }

    layui.use(['form'], () => {
        layui.form.render('select');
        byId('diagnostics-form').addEventListener('submit', download);
        window.addEventListener('gateway-language-changed', () => layui.form.render('select'));
    });
    window.addEventListener('pagehide', () => { disposed = true; releaseDownload(); });
    window.addEventListener('pageshow', () => { disposed = false; });
})();
