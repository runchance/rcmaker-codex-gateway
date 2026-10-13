/* Account-change and two-day reminders never clear Chrome until the user confirms this exact reminder. */
(function () {
    'use strict';
    try { if (window.parent !== window && window.parent.GatewayLoginReminder) return; } catch (_) {}
    let state = null, reading = false, clearing = false, dialog = null, autoShown = null, timer = null;
    let banner;
    const sleep = ms => new Promise(resolve => setTimeout(resolve, ms));
    function textFor(reason) {
        return I18n.t(reason === 'codex_account_changed'
            ? 'Codex 账号已变化，建议重新登录 ChatGPT。'
            : 'ChatGPT 已连续登录两天，建议重新登录。');
    }
    function render() {
        if (!banner) {
            banner = document.createElement('aside');
            banner.className = 'notice warning gateway-login-reminder';
            banner.setAttribute('role', 'status');
            const description = document.createElement('p');
            const button = document.createElement('button');
            button.type = 'button'; button.className = 'layui-btn layui-btn-sm';
            button.addEventListener('click', () => showConfirmation());
            banner.append(description, button); document.body.append(banner);
        }
        banner.hidden = !state || !state.reason || Boolean(window.GatewaySetup?.isOpen());
        banner.querySelector('p').textContent = state && state.reason ? textFor(state.reason) : '';
        const button = banner.querySelector('button');
        button.textContent = I18n.t(clearing ? '正在清空…' : '重新登录');
        button.disabled = clearing;
    }
    async function snooze(reminderId) {
        try {
            await Gateway.request('/api/browser/login-reminder/snooze', {reminder_id: reminderId});
            await read();
        } catch (error) { layui.layer.msg(error.message); }
    }
    async function clearConfirmed(reminderId) {
        clearing = true; render();
        let progress;
        try {
            // Server verifies the current reminder and existing idle-task guard atomically.
            const command = await Gateway.request('/api/browser/profile/reset', {reminder_id: reminderId});
            if (Gateway.notifyStateChange) Gateway.notifyStateChange('profile-reset-started');
            progress = layui.layer.msg(I18n.t('正在关闭内置 Chrome 并清空登录资料，请等待…'), {time: 0, icon: 16, shade: 0.1});
            const deadline = Date.now() + 600000;
            while (true) {
                const result = await Gateway.request('/api/browser/commands/' + command.id);
                if (result.status === 'succeeded') break;
                if (result.status === 'failed') throw new Error(I18n.error(result.result?.message || '浏览器操作失败。', result.result?.code));
                if (Date.now() >= deadline) throw new Error(I18n.t('操作等待超时，请稍后重试。'));
                await sleep(1000);
            }
            state = null; render();
            // Open the relevant saved setup step immediately, rather than leaving an old ready dashboard behind a second confirmation.
            if (Gateway.notifyStateChange) Gateway.notifyStateChange('profile-reset');
            if (window.GatewaySetup) window.GatewaySetup.open('account');
            else {
                Gateway.openOverview();
                window.dispatchEvent(new CustomEvent('gateway-profile-reset'));
            }
        } catch (error) {
            layui.layer.msg(error.message, {time: 6000});
        } finally {
            if (progress !== undefined) layui.layer.close(progress);
            clearing = false; render(); await read();
        }
    }
    /** A reminder may wait; it must not interrupt copying or editing inside the active Pear iframe. */
    function reminderInteractionAllowed() {
        if (document.getElementById && document.getElementById('gateway-workspace')?.inert) return false;
        if (Gateway.automaticRefreshAllowed && !Gateway.automaticRefreshAllowed()) return false;
        for (const frame of document.querySelectorAll ? document.querySelectorAll('iframe') : []) {
            try {
                const page = frame.contentWindow?.Gateway;
                if (page?.isPageActive() && page.automaticRefreshAllowed && !page.automaticRefreshAllowed()) return false;
            } catch (_) { /* Only same-origin console frames contribute an interaction guard. */ }
        }
        return true;
    }
    function showConfirmation() {
        if (!state || !state.reason || dialog !== null || clearing) return;
        const reminder = {id:state.reminder_id, reason:state.reason};
        autoShown = reminder.id;
        dialog = -1;
        layui.use('layer', () => {
            let confirmed = false, postponed = false;
            dialog = layui.layer.confirm(textFor(reminder.reason) + '\n\n'
                + I18n.t('确认后清空 RCMAKER 私有浏览器的登录资料，并引导重新登录。任务和设置保留；请先结束正在运行的任务。'),
                {title:I18n.t('重新登录 ChatGPT'), shadeClose:false,
                    area:[Math.min(520,window.innerWidth-32)+'px','auto'], maxHeight:Math.max(240,window.innerHeight-48),
                    btn:[I18n.t('确认清空并重新登录'),I18n.t('稍后提醒')],
                    end:()=>{dialog=null; if(!confirmed&&!postponed) snooze(reminder.id);}},
                index=>{confirmed=true; layui.layer.close(index); clearConfirmed(reminder.id);},
                ()=>{postponed=true; snooze(reminder.id);});
        });
    }
    async function read() {
        if (reading || document.hidden) return;
        reading = true;
        try {
            state = await Gateway.request('/api/browser/login-reminder'); render();
            if (!state.show_popup) autoShown = null;
            if (state.show_popup && autoShown !== state.reminder_id && !clearing && dialog === null
                && !window.GatewaySetup?.isOpen()
                && reminderInteractionAllowed()
                && !document.querySelector('.layui-layer')) showConfirmation();
        } catch (_) { /* Optional reminders never interrupt an active task or setup flow. */ }
        finally { reading = false; }
    }
    window.GatewayLoginReminder = {refresh:read, confirm:showConfirmation};
    function start() { read(); timer=setInterval(read,15000); }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded',start,{once:true}); else start();
    document.addEventListener('visibilitychange',()=>{if(!document.hidden) read();});
    window.addEventListener('gateway-language-changed',render);
    window.addEventListener('pagehide',()=>{if(timer) clearInterval(timer);});
})();
