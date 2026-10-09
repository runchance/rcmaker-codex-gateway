(function () {
    'use strict';
    const byId = id => document.getElementById(id);
    let current = null;
    let busy = false;
    let models = [];
    const contracts = [
        { field: 'web_initial_contract_template', input: 'web-initial-contract', reset: 'reset-web-initial', status: 'web-initial-status' },
        { field: 'web_resume_contract_template', input: 'web-resume-contract', reset: 'reset-web-resume', status: 'web-resume-status' },
        { field: 'web_readonly_contract_template', input: 'web-readonly-contract', reset: 'reset-web-readonly', status: 'web-readonly-status' },
        { field: 'web_compact_contract_template', input: 'web-compact-contract', reset: 'reset-web-compact', status: 'web-compact-status' }
    ];
    function setError(message) { byId('error').textContent = message || ''; byId('error').hidden = !message; }
    function contractStatus(contract) {
        if (!current) return;
        const entered = byId(contract.input).value;
        const fallback = current.web_contract_defaults[contract.field];
        const effective = entered === '' ? fallback : entered;
        const saved = effective === current[contract.field];
        const mode = effective === fallback ? I18n.t('默认模板') : I18n.t('自定义模板');
        byId(contract.status).textContent = (saved ? I18n.t('当前已保存：') : I18n.t('待保存：')) + mode
            + (entered === '' ? I18n.t('（空白内容会恢复默认）') : '');
    }
    function contractPayload() {
        return Object.fromEntries(contracts.map(contract => {
            const entered = byId(contract.input).value;
            return [contract.field, entered === current.web_contract_defaults[contract.field] ? '' : entered];
        }));
    }
    function render(data, section = 'all') {
        current = data;
        if (section !== 'contracts') {
        byId('ui-locale').value = data.ui_locale || 'en';
        byId('pro-weekly-budget').value = data.pro_weekly_budget || 0;
        byId('context-parts').value = String(data.context_max_parts || 6);
        byId('skill-transport').checked = data.skill_transport === 'file';
        byId('context-extended').checked = !!data.context_extended;
        byId('official-handoff-summary').checked = !!data.official_handoff_summary;
        byId('response-timeout').value = data.response_timeout_seconds || 14400;
        byId('stage-timeout').value = data.context_stage_timeout_seconds || 180;
        byId('compact-timeout').value = data.compaction_timeout_seconds || 14400;
        byId('invoke-timeout').value = Math.round(Number(data.mcp_invoke_timeout_ms || 90000) / 1000);
        byId('wait-poll').value = Number(data.wait_poll_interval_ms || 250);
        byId('max-tools').value = Number(data.max_tool_calls_per_turn ?? 0);
        byId('auto-approve-tools').checked = data.auto_approve_tool_calls !== false && Number(data.auto_approve_tool_calls) !== 0;
        }
        if (section !== 'general') {
        contracts.forEach(contract => {
            byId(contract.input).value = data[contract.field];
            contractStatus(contract);
        });
        }
        layui.form.render();
        renderBudgets();
    }
    function renderBudgets() {
        const extended = byId('context-extended').checked;
        const sixParts = Number(byId('context-parts').value) >= 6;
        byId('context-budget-detail').textContent = models.length ? I18n.t('当前账号预算预览（保存后生效）：') + models.map(model => {
            // Use the server's account/family/effort policy, including capped or unextended windows.
            // Older workers supply their saved budget; never invent a larger capacity in the UI.
            const budget = model.budget_options?.[extended && sixParts ? 'extended' : 'standard'] || model;
            return I18n.t(model.label) + I18n.t('：可接入历史 ') + Number(budget.context_tokens).toLocaleString(I18n.locale) + ' tokens, '
                + Number(budget.compact_tokens).toLocaleString(I18n.locale) + I18n.t(' tokens 网关压缩阈值（Codex 可能提前压缩）');
        }).join(I18n.locale === 'en' ? '; ' : '；') + (extended && !sixParts ? I18n.t('。启用 3× 历史需将最大分段数设为 6。') : '')
            : I18n.t('完成账号模型检测后，这里显示对应的上下文预算。');
    }
    async function load() {
        if (busy) return;
        busy = true; byId('reload').disabled = true;
        try {
            const data = await Gateway.request('/api/settings');
            try { models = (await Gateway.request('/api/codex')).models || []; } catch (_) { models = []; }
            render(data); setError('');
        } catch (e) { setError(e.message); }
        finally { busy = false; byId('reload').disabled = false; }
    }
    async function save(event, section = 'general') {
        event.preventDefault();
        if (busy || !current) return;
        if (section === 'general' && byId('context-extended').checked && Number(byId('context-parts').value) < 6) {
            setError(I18n.t('启用 3× 历史需将最大分段数设为 6。'));
            return;
        }
        const button = byId(section === 'general' ? 'save' : 'save-contracts');
        busy = true; button.disabled = true; byId('saved').hidden = true;
        button.textContent = I18n.t('正在保存…');
        try {
            const payload = {
                expected_updated_at: Number(current.updated_at || 0),
                mcp_enabled: current.mcp_enabled ? 1 : 0,
                connector_name: current.connector_name,
                mcp_invoke_timeout_ms: Number(current.mcp_invoke_timeout_ms),
                wait_poll_interval_ms: Number(current.wait_poll_interval_ms),
                max_tool_calls_per_turn: Number(current.max_tool_calls_per_turn),
                tool_result_max_bytes: Number(current.tool_result_max_bytes || 2097152),
                tunnel_id: current.tunnel_id || '', runtime_key: '', runtime_key_action: 'keep'
            };
            if (section === 'contracts') Object.assign(payload, contractPayload());
            else Object.assign(payload, {
                ui_locale: byId('ui-locale').value,
                pro_weekly_budget: Number(byId('pro-weekly-budget').value),
                context_transport: 'auto',
                context_max_parts: Number(byId('context-parts').value),
                skill_transport: byId('skill-transport').checked ? 'file' : 'inline',
                context_extended: byId('context-extended').checked ? 1 : 0,
                official_handoff_summary: byId('official-handoff-summary').checked ? 1 : 0,
                response_timeout_seconds: Number(byId('response-timeout').value),
                context_stage_timeout_seconds: Number(byId('stage-timeout').value),
                compaction_timeout_seconds: Number(byId('compact-timeout').value),
                mcp_enabled: current.mcp_enabled ? 1 : 0,
                auto_approve_tool_calls: byId('auto-approve-tools').checked ? 1 : 0,
                connector_name: current.connector_name,
                mcp_invoke_timeout_ms: Math.round(Number(byId('invoke-timeout').value) * 1000),
                wait_poll_interval_ms: Math.round(Number(byId('wait-poll').value)),
                max_tool_calls_per_turn: Math.round(Number(byId('max-tools').value)),
                // Round-trip the retired field so this page also works before the PHP worker reloads.
                tool_result_max_bytes: Number(current.tool_result_max_bytes || 2097152),
                tunnel_id: current.tunnel_id || '', runtime_key: '', runtime_key_action: 'keep'
            });
            const data = await Gateway.request('/api/settings', payload);
            render(data, section); I18n.setLocale(data.ui_locale || 'en'); setError(''); byId('saved').hidden = false;
            setTimeout(() => { byId('saved').hidden = true; }, 2500);
        } catch (e) { setError(e.message); }
        finally { busy = false; button.disabled = false; button.textContent = section === 'general' ? I18n.t('保存日常设置') : I18n.t('保存模型契约'); }
    }
    byId('settings-form').addEventListener('submit', save);
    byId('contracts-form').addEventListener('submit', event => save(event, 'contracts'));
    function selectSection(section) {
        ['general', 'contracts'].forEach(name => {
            const selected = name === section;
            byId('settings-' + name).hidden = !selected;
            const tab = byId('settings-' + name + '-tab');
            tab.setAttribute('aria-selected', String(selected));
            tab.classList.toggle('layui-btn-primary', !selected);
        });
    }
    ['general', 'contracts'].forEach(section => byId('settings-' + section + '-tab').addEventListener('click', () => selectSection(section)));
    layui.form.on('select(ui-locale)', async data => {
        if (busy) return;
        busy = true;
        try {
            const saved = await I18n.saveLocale(data.value);
            if (saved) current = saved;
            setError('');
        } catch (error) { setError(error.message); byId('ui-locale').value = I18n.locale; layui.form.render('select'); }
        finally { busy = false; }
    });
    byId('reload').addEventListener('click', load);
    window.addEventListener('gateway-locale-saved', event => {
        if (!current) return;
        current.updated_at = Math.max(Number(current.updated_at), Number(event.detail.updated_at));
        current.ui_locale = event.detail.ui_locale;
        byId('ui-locale').value = current.ui_locale;
        layui.form.render('select');
    });
    window.addEventListener('gateway-language-changed', () => {
        if (!current) return;
        renderBudgets();
        contracts.forEach(contractStatus);
    });
    contracts.forEach(contract => {
        byId(contract.input).addEventListener('input', () => contractStatus(contract));
        byId(contract.reset).addEventListener('click', () => {
            if (!current || busy) return;
            byId(contract.input).value = current.web_contract_defaults[contract.field];
            contractStatus(contract);
        });
    });
    layui.form.on('checkbox(context-budget)', renderBudgets);
    byId('context-extended').addEventListener('change', renderBudgets);
    byId('context-parts').addEventListener('change', renderBudgets);
    load();
})();
