/* Product UI localization. User messages, audit bodies and editable contracts are never translated. */
(function () {
    'use strict';
    const catalog = window.GatewayTranslations || {};
    const reverse = new Map(Object.entries(catalog).map(([key, value]) => [value, key]));
    const originals = new WeakMap();
    let locale = 'en';
    try { locale = localStorage.getItem('gateway.locale') === 'zh-CN' ? 'zh-CN' : 'en'; } catch (_) {}
    let observer;
    let pending = false;
    let settingsVersion = 0;
    const skipped = 'script,style,textarea,pre,code,[data-user-content],[data-i18n-skip]';
    const productAttributes = ['placeholder', 'title', 'alt', 'aria-label', 'menu-title', 'pear-title', 'lay-tips', 'lay-text'];

    /** Translate a fixed product phrase; dynamic/user values remain separate arguments at call sites. */
    function t(text) {
        const value = String(text ?? '');
        const key = reverse.get(value) || value;
        // Layui pagination combines its own labels with numbers. These are product controls,
        // not message content (which translateDOM excludes through data-user-content).
        if (locale === 'en') {
            const count = /^共\s*([\d,]+)\s*条$/.exec(value);
            if (count) return count[1] + ' items';
            const size = /^([\d,]+)\s*条\/页$/.exec(value);
            if (size) return size[1] + ' / page';
        }
        return locale === 'en' ? (catalog[key] || value) : key;
    }
    /** Keep service error codes visible; technical originals remain attached to the Error object. */
    function error(text, code) {
        const translated = t(text);
        if (locale !== 'en' || translated !== String(text) || !/[\u4e00-\u9fff]/.test(translated)) return translated;
        const descriptions = {
            SETTINGS_CONFLICT: 'Settings changed in another page. Reload before saving.',
            INVALID_SETTINGS: 'Check the setting values, required fields and connector name.',
            CONNECTION_IN_USE: 'Wait for running tasks to finish before changing connection credentials.',
            NETWORK_NOT_APPLIED: 'Apply a network configuration first.',
            BROWSER_NOT_READY: 'The private browser is not ready yet.',
            CONNECTOR_NOT_VERIFIED: 'Create and verify the matching ChatGPT connector first.',
            STORAGE_UNAVAILABLE: 'Local storage is unavailable. Check permissions, disk space and file locks.'
        };
        const identity = String(code || 'LOCAL_SERVICE_ERROR');
        return descriptions[identity] || 'Could not complete the operation (' + identity + '). Check the task timeline or local diagnostics for details.';
    }
    /** Format owned probe results from stable codes; saved HTTP results keep their original outcome. */
    function networkResult(result) {
        if (!result) return t('检测已结束，暂没有可显示的结果。');
        const messages = {
            TARGET_REACHABLE: '网络连接已确认；模型登录与工具能力仍需在内置浏览器中验证。',
            PROXY_AUTH_FAILED: '代理认证失败，请检查用户名和密码。',
            PROXY_DNS_FAILED: '无法解析代理服务器地址。',
            TARGET_DNS_FAILED: '无法解析检测目标地址。',
            PROXY_CONNECT_FAILED: '无法建立连接，请检查地址、端口及代理是否启动。',
            TARGET_CONNECT_FAILED: '无法建立连接，请检查地址、端口及代理是否启动。',
            PROBE_TIMEOUT: '连接或响应超时，已结束本次检测。',
            TARGET_TLS_FAILED: 'TLS 握手或证书验证失败；未关闭证书校验。',
            NETWORK_FAILED: result.stage === 'http' ? '未收到目标的 HTTP 响应，请重新检测。' : '网络检测失败，请结合错误代码检查通路。',
            PROBE_INTERRUPTED: '检测进程重启，上次执行结果未确认，请重新发起检测。',
            PROBE_RESULT_UNCONFIRMED: '上次检测结果未能确认，请重新检测。',
            PROBE_EXPIRED: '检测请求等待过久，请重新发起。',
            PROBE_FAILED: '检测未能完成，请检查服务状态。',
            PROBE_TARGET_CONFIG: '服务器诊断目标配置不正确。'
        };
        const status = Number(result.code === 'PROXY_TUNNEL_REJECTED' ? result.connect_status : result.http_status);
        const http = Number.isInteger(status) && status >= 100 && status <= 599 ? String(status) : '—';
        if (result.code === 'PROXY_TUNNEL_REJECTED') return t('代理返回 HTTP ') + http + t('，未能建立目标隧道。');
        if (result.code === 'TARGET_HTTP_REJECTED') return t('目标已返回 HTTP ') + http + t('；网络可达，但不代表模型可用。');
        return messages[result.code] ? t(messages[result.code]) : error(result.message || t('检测未能完成，请检查服务状态。'), result.code);
    }
    function translateText(node) {
        if (!node.parentElement || node.parentElement.closest(skipped) || !node.data.trim()) return;
        const cached = originals.get(node);
        const source = cached && node.data === cached.rendered ? cached.source : node.data;
        const content = source.trim();
        const translated = t(content);
        const rendered = source.replace(content, translated);
        originals.set(node, {source, rendered});
        if (node.data !== rendered) node.data = rendered;
    }
    /** Layui stores switch labels as one pipe-delimited attribute; keep each product label localized. */
    function translateAttribute(value, attribute) {
        if (attribute !== 'lay-text') return t(value);
        const translated = t(value);
        return translated !== value ? translated : value.split('|').map(t).join('|');
    }
    /** Translate only mounted product labels without replacing DOM nodes or disturbing scroll/focus. */
    function translateDOM(root) {
        if (observer) observer.disconnect();
        const walker = document.createTreeWalker(root || document.body, NodeFilter.SHOW_TEXT);
        while (walker.nextNode()) translateText(walker.currentNode);
        (root || document).querySelectorAll(productAttributes.map(attribute => '[' + attribute + ']').join(',') + ',option').forEach(element => {
            if (element.closest(skipped)) return;
            productAttributes.forEach(attribute => {
                if (!element.hasAttribute(attribute)) return;
                const originalKey = 'i18n' + attribute.replace(/-([a-z])/g, (_, letter) => letter.toUpperCase());
                const prior = element.dataset[originalKey];
                const current = element.getAttribute(attribute);
                const source = prior && current === translateAttribute(prior, attribute) ? prior : (reverse.get(current) || current);
                element.dataset[originalKey] = source;
                const translated = translateAttribute(source, attribute);
                if (current !== translated) element.setAttribute(attribute, translated);
            });
        });
        document.documentElement.lang = locale;
        document.title = t(document.title);
        if (observer) observer.observe(document.body, {subtree: true, childList: true, characterData: true, attributes: true, attributeFilter: productAttributes});
    }
    function setLocale(value, broadcast = true, version = settingsVersion) {
        if (!['en', 'zh-CN'].includes(value)) return;
        settingsVersion = Math.max(settingsVersion, Number(version) || 0);
        locale = value;
        try { localStorage.setItem('gateway.locale', value); } catch (_) {}
        translateDOM();
        document.querySelectorAll('[data-language-choice]').forEach(select => { select.value = value; });
        window.dispatchEvent(new CustomEvent('gateway-language-changed', {detail: value}));
        if (broadcast) {
            const host = window.parent === window ? window : window.parent;
            if (host.I18n && host !== window) host.I18n.setLocale(value, false, settingsVersion);
            host.document.querySelectorAll('iframe').forEach(frame => {
                try { if (frame.contentWindow !== window && frame.contentWindow.I18n) frame.contentWindow.I18n.setLocale(value, false, settingsVersion); } catch (_) {}
            });
        }
    }
    /** A delayed background poll cannot undo a newer saved language selection. */
    function applySettings(settings) {
        const version = Number(settings && settings.updated_at) || 0;
        if (!settings || !settings.ui_locale || version < settingsVersion) return;
        settingsVersion = version;
        if (settings.ui_locale !== locale) setLocale(settings.ui_locale, true, version);
    }
    /** Persist only locale, with the existing optimistic settings version and API boundary. */
    async function saveLocale(value) {
        const current = await window.Gateway.request('/api/settings');
        const result = await window.Gateway.request('/api/settings/locale', {
            ui_locale: value, expected_updated_at: Number(current.updated_at)
        });
        applySettings(result);
        const host = window.parent === window ? window : window.parent;
        const targets = new Set([window, host]);
        host.document.querySelectorAll('iframe').forEach(frame => { try { targets.add(frame.contentWindow); } catch (_) {} });
        targets.forEach(target => { try { target.dispatchEvent(new CustomEvent('gateway-locale-saved', {
            detail: {updated_at: Number(result.updated_at), ui_locale: result.ui_locale}
        })); } catch (_) {} });
        return result;
    }
    window.I18n = {t, error, networkResult, translateDOM, setLocale, applySettings, saveLocale, get locale() { return locale; }};
    function start() {
        translateDOM();
        observer = new MutationObserver(() => {
            if (pending) return;
            pending = true;
            requestAnimationFrame(() => { pending = false; translateDOM(); });
        });
        observer.observe(document.body, {subtree: true, childList: true, characterData: true, attributes: true, attributeFilter: productAttributes});
        window.addEventListener('storage', event => { if (event.key === 'gateway.locale') setLocale(event.newValue, false); });
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start, {once: true});
    else start();
})();
