/* The shipped Pear shell owns navigation and keeps unsaved iframe state in memory. */
layui.use(['admin'], function () {
    'use strict';
    layui.admin.setConfigurationPath('config/console.json');
    layui.admin.render();
    const language = document.getElementById('console-language');
    language.value = I18n.locale;
    language.addEventListener('change', async () => {
        language.disabled = true;
        try { await I18n.saveLocale(language.value); }
        catch (error) {
            language.value = I18n.locale;
            layui.use('layer', () => layui.layer.msg(I18n.t(error.message)));
        } finally { language.disabled = false; }
    });
    Gateway.request('/api/settings').then(settings => I18n.applySettings(settings))
        .catch(() => Gateway.bootstrap().then(config => I18n.setLocale(config.default_locale || 'en')).catch(() => {}));
});
