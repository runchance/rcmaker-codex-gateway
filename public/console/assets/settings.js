(function () {
    'use strict';
    const byId = id => document.getElementById(id);
    let current = null;
    let busy = false;
    let models = [];
    let disposed = false;
    let modelReading = false;
    let savedNoticeTimer = null;
    const savedForms = {general: null, contracts: null};
    const generalFields = ['pro-weekly-budget', 'subagent-model', 'subagent-effort', 'subagent-concurrency', 'context-parts', 'skill-transport',
        'context-extended', 'official-handoff-summary', 'steering-full-context', 'response-timeout', 'stage-timeout',
        'compact-timeout', 'invoke-timeout', 'wait-poll', 'max-tools', 'auto-approve-tools', 'auto-cleanup-inactive-tasks'];
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
    /** Compare actual field values so keyboard, Layui controls and template resets share one draft boundary. */
    function formSnapshot(section) {
        const fields = section === 'contracts' ? contracts.map(contract => contract.input) : generalFields;
        return JSON.stringify(fields.map(id => [byId(id).value, Boolean(byId(id).checked)]));
    }
    /** Only a confirmed reload may replace a pre-existing draft; changes made during a request always survive. */
    function hasDraft() {
        return Object.keys(savedForms).some(section => savedForms[section] !== null && savedForms[section] !== formSnapshot(section));
    }
    /** Keep the saved model visible during account refreshes; never replace a user's unavailable choice silently. */
    function renderSubagentModels(model, effort) {
        const selector = byId('subagent-model');
        selector.replaceChildren(new Option(I18n.t('跟随主任务家族（不继承 Pro）'), ''));
        models.filter(row => (row.efforts || []).some(level => ['low', 'medium', 'high', 'xhigh'].includes(level)))
            .forEach(row => selector.add(new Option(I18n.t(row.label || row.id), row.id)));
        if (model && !Array.from(selector.options).some(option => option.value === model)) selector.add(new Option(model, model));
        selector.value = model || '';
        renderSubagentEfforts(effort);
    }
    /** Offer only this model's declared non-Pro levels; an old selection remains explicit until changed. */
    function renderSubagentEfforts(effort) {
        const model = models.find(row => row.id === byId('subagent-model').value);
        const supported = model ? (model.efforts || []) : ['low', 'medium', 'high', 'xhigh'];
        const selector = byId('subagent-effort');
        selector.replaceChildren();
        const labels = {low: 'Low', medium: 'Medium', high: 'High', xhigh: 'Extra High'};
        supported.filter(level => Object.hasOwn(labels, level)).forEach(level => selector.add(new Option(I18n.t(labels[level]), level)));
        if (!Array.from(selector.options).some(option => option.value === effort)) selector.add(new Option(effort, effort));
        selector.value = effort;
    }
    function render(data, section = 'all') {
        current = data;
        if (section === 'all' || section === 'general') {
        byId('ui-locale').value = data.ui_locale || 'en';
        byId('pro-weekly-budget').value = data.pro_weekly_budget || 0;
        renderSubagentModels(data.subagent_web_model || '', data.subagent_reasoning_effort || 'xhigh');
        byId('subagent-concurrency').value = Number(data.subagent_max_concurrency ?? 4);
        byId('subagent-concurrency').disabled = !Object.hasOwn(data, 'subagent_max_concurrency');
        byId('context-parts').value = String(data.context_max_parts || 6);
        byId('skill-transport').checked = data.skill_transport === 'file';
        byId('context-extended').checked = !!data.context_extended;
        byId('official-handoff-summary').checked = !!data.official_handoff_summary;
        byId('steering-full-context').checked = !!data.steering_full_context;
        byId('auto-cleanup-inactive-tasks').checked = data.auto_cleanup_inactive_tasks !== false && Number(data.auto_cleanup_inactive_tasks ?? 1) !== 0;
        byId('response-timeout').value = data.response_timeout_seconds || 14400;
        byId('stage-timeout').value = data.context_stage_timeout_seconds || 180;
        byId('compact-timeout').value = data.compaction_timeout_seconds || 14400;
        byId('invoke-timeout').value = Math.round(Number(data.mcp_invoke_timeout_ms || 90000) / 1000);
        byId('wait-poll').value = Number(data.wait_poll_interval_ms || 250);
        byId('max-tools').value = Number(data.max_tool_calls_per_turn ?? 0);
        byId('auto-approve-tools').checked = data.auto_approve_tool_calls !== false && Number(data.auto_approve_tool_calls) !== 0;
        savedForms.general = formSnapshot('general');
        }
        if (section === 'all' || section === 'contracts') {
        contracts.forEach(contract => {
            byId(contract.input).value = data[contract.field];
            contractStatus(contract);
        });
        savedForms.contracts = formSnapshot('contracts');
        }
        if (section !== 'none') layui.form.render();
        renderBudgets();
    }
    function renderBudgets() {
        const extended = byId('context-extended').checked;
        const sixParts = Number(byId('context-parts').value) >= 6;
        const container = byId('context-budget-detail'); container.replaceChildren();
        const heading = document.createElement('p'); heading.className = 'muted';
        heading.textContent = I18n.t(models.length ? '当前账号预算预览（保存后生效）：' : '完成账号模型检测后，这里显示对应的上下文预算。');
        container.append(heading);
        if (!models.length) return;
        const wrap = document.createElement('div'); wrap.className = 'table-wrap';
        const table = document.createElement('table'); table.className = 'layui-table context-budget-table';
        const head = document.createElement('thead'), row = document.createElement('tr');
        ['模型', '历史容量（tokens）', '压缩阈值（tokens）'].forEach(label => {
            const cell = document.createElement('th'); cell.scope = 'col'; cell.textContent = I18n.t(label); row.append(cell);
        });
        head.append(row); table.append(head);
        const body = document.createElement('tbody');
        models.forEach(model => {
            // Preserve the server's account and window policy, including capped or unextended choices.
            const budget = model.budget_options?.[extended && sixParts ? 'extended' : 'standard'] || model;
            const line = document.createElement('tr');
            [model.label, Number(budget.context_tokens).toLocaleString(I18n.locale), Number(budget.compact_tokens).toLocaleString(I18n.locale)]
                .forEach(value => { const cell = document.createElement('td'); cell.textContent = value; line.append(cell); });
            body.append(line);
        });
        table.append(body); wrap.append(table); container.append(wrap);
        const note = document.createElement('p'); note.className = 'help';
        note.textContent = I18n.t('Codex 可能提前压缩。'); container.append(note);
        if (extended && !sixParts) {
            const guidance = document.createElement('p'); guidance.className = 'notice warning';
            guidance.textContent = I18n.t('。启用 3× 历史需将最大分段数设为 6。').replace(/^[。.\s]+/, '');
            container.append(guidance);
        }
    }
    async function load() {
        if (busy) return;
        busy = true; byId('reload').disabled = true;
        const submitted = {general: formSnapshot('general'), contracts: formSnapshot('contracts')};
        try {
            const data = await Gateway.request('/api/settings');
            try { models = (await Gateway.request('/api/codex')).models || []; } catch (_) { models = []; }
            if (disposed) return;
            render(data, 'none');
            Object.keys(submitted).forEach(section => {
                if (submitted[section] === formSnapshot(section)) render(data, section);
                else if (savedForms[section] === null) savedForms[section] = submitted[section];
            });
            contracts.forEach(contractStatus);
            setError('');
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
        const submitted = formSnapshot(section);
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
                subagent_web_model: byId('subagent-model').value,
                subagent_reasoning_effort: byId('subagent-effort').value,
                subagent_max_concurrency: Number(byId('subagent-concurrency').value),
                context_transport: 'auto',
                context_max_parts: Number(byId('context-parts').value),
                skill_transport: byId('skill-transport').checked ? 'file' : 'inline',
                context_extended: byId('context-extended').checked ? 1 : 0,
                official_handoff_summary: byId('official-handoff-summary').checked ? 1 : 0,
                steering_full_context: byId('steering-full-context').checked ? 1 : 0,
                auto_cleanup_inactive_tasks: byId('auto-cleanup-inactive-tasks').checked ? 1 : 0,
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
            // Source assets can refresh before the owning PHP worker has reloaded after upgrade.
            if (!Object.hasOwn(current, 'subagent_max_concurrency')) delete payload.subagent_max_concurrency;
            const data = await Gateway.request('/api/settings', payload);
            if (disposed) return;
            const newerDraft = submitted !== formSnapshot(section);
            render(data, newerDraft ? 'none' : section);
            if (newerDraft) savedForms[section] = submitted;
            I18n.setLocale(data.ui_locale || 'en'); setError(''); byId('saved').hidden = newerDraft;
            clearTimeout(savedNoticeTimer);
            savedNoticeTimer = setTimeout(() => { byId('saved').hidden = true; }, 2500);
            Gateway.notifyStateChange('settings');
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
    byId('reload').addEventListener('click', () => {
        if (busy) return;
        if (!hasDraft()) { load(); return; }
        layui.layer.confirm(I18n.t('重新载入会丢弃表单内未保存的修改。是否继续？'), {
            title: I18n.t('重新载入设置'), btn: [I18n.t('重新载入'), I18n.t('取消')], closeBtn: 1
        }, index => { layui.layer.close(index); load(); });
    });
    window.addEventListener('gateway-locale-saved', event => {
        if (!current) return;
        current.updated_at = Math.max(Number(current.updated_at), Number(event.detail.updated_at));
        current.ui_locale = event.detail.ui_locale;
        byId('ui-locale').value = current.ui_locale;
        layui.form.render('select');
    });
    window.addEventListener('gateway-language-changed', () => {
        if (!current) return;
        // Layui copies option labels into readonly inputs; rebuild these labels without changing drafts.
        renderSubagentModels(byId('subagent-model').value, byId('subagent-effort').value);
        layui.form.render('select');
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
    layui.form.on('select(subagent-model)', () => {
        const model = models.find(row => row.id === byId('subagent-model').value);
        const supported = (model?.efforts || ['low', 'medium', 'high', 'xhigh']).filter(level => ['low', 'medium', 'high', 'xhigh'].includes(level));
        const currentEffort = byId('subagent-effort').value;
        renderSubagentEfforts(supported.includes(currentEffort) ? currentEffort : (supported.includes('xhigh') ? 'xhigh' : (supported[0] || 'xhigh')));
        layui.form.render('select');
    });
    byId('context-extended').addEventListener('change', renderBudgets);
    byId('context-parts').addEventListener('change', renderBudgets);
    /** Returning to Settings refreshes derived account budgets, never the editable preference or contract fields. */
    Gateway.onPageActivate(async () => {
        if (disposed || busy || modelReading || !current || !Gateway.automaticRefreshAllowed()) return;
        modelReading = true;
        try {
            const result = await Gateway.request('/api/codex');
            if (disposed || !Gateway.automaticRefreshAllowed()) return;
            models = result.models || [];
            renderBudgets();
        } catch (_) { /* Retain the last verified budget while the account service reconnects. */ }
        finally { modelReading = false; }
    });
    window.addEventListener('pagehide', () => { disposed = true; clearTimeout(savedNoticeTimer); });
    window.addEventListener('pageshow', () => { disposed = false; });
    load();
})();
