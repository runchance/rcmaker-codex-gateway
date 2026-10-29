(() => {
    'use strict';

    const byId = id => document.getElementById(id);
    const diagnosticsButton = byId('open-diagnostics');
    if (diagnosticsButton) diagnosticsButton.addEventListener('click', () => Gateway.openDiagnostics());
    const state = {
        tasks: [], status: 'active', page: 1, pageSize: 20, total: 0, pages: 1,
        counts: {total: 0, active: 0, needs_attention: 0, completed: 0, cancelled: 0},
        reading: false, detailReading: false, mutationBusy: false,
        modalTaskId: null, modalIndex: null, modalBody: null, modalSignature: null,
        modalWorking: null,
        modalInteractionUntil: 0,
        disposed: false, timer: null, sequence: 0, listSignature: null
    };
    const taskLabels = {active: I18n.t('可继续'), completed: I18n.t('已完成'), cancelled: I18n.t('已取消'), needs_attention: I18n.t('需要确认')};
    const taskStatuses = ['active', 'needs_attention', 'completed', 'cancelled'];
    const emptyLabels = {
        active: I18n.t('还没有可继续的任务。'), needs_attention: I18n.t('没有需要确认的任务。'),
        completed: I18n.t('还没有已完成的任务。'), cancelled: I18n.t('还没有已取消的任务。')
    };
    const turnLabels = {
        queued: I18n.t('尚未发送'), submit_intent: I18n.t('准备发送'), submitted: I18n.t('页面已接收'), running: I18n.t('生成中'),
        committed: I18n.t('已确认完成'), failed: I18n.t('失败'), cancelled: I18n.t('已取消'), needs_attention: I18n.t('结果未知')
    };
    const surfaceLabels = {
        reserved: I18n.t('已预留'), attaching: I18n.t('连接中'), ready: I18n.t('待命'), busy: I18n.t('工作中'),
        needs_attention: I18n.t('需要确认'), retired: I18n.t('已退休')
    };
    const eventLabels = {
        browser_response_transport: I18n.t('网页回答流状态'),
        regular_stream_recovery_intent: I18n.t('普通对话异常恢复已排队'),
        browser_observation_delayed: I18n.t('页面暂时繁忙，等待原请求结果'),
        surface_reserved: I18n.t('已为任务预留页面'), response_round_queued: I18n.t('Response Round 已登记'),
        tool_result_round_queued: I18n.t('工具结果 Round 已登记'), submit_intent: I18n.t('发送意图已持久化'),
        page_submitted: I18n.t('页面已确认接收'), response_running: I18n.t('模型开始生成'),
        turn_committed: I18n.t('结果已持久化并确认完成'), turn_failed: I18n.t('回合失败'), turn_cancelled: I18n.t('回合已取消'),
        codex_user_stop_requested: I18n.t('收到 Codex 手动停止，正在确认网页停止'),
        codex_user_stop_confirmed: I18n.t('手动停止已确认，保留标签与历史'),
        codex_user_stop_waiting_tools: I18n.t('已收到停止，等待已接受的工具调用完成'),
        codex_user_stop_superseded: I18n.t('已收到续发需求，保留工具调用并等待衔接'),
        native_context_request_joined: I18n.t('新需求已登记，等待当前工具回执'),
        user_steering_queued: I18n.t('正在衔接新的用户需求'),
        checkpoint_page_retained: I18n.t('Codex 压缩完成，继续使用原网页'),
        surface_retired: I18n.t('任务页面已退休'), recovery_unsent: I18n.t('恢复确认：此前尚未发送'),
        recovery_reconcile: I18n.t('恢复对账：仅核对已有页面，不重发'), recovery_submitted: I18n.t('恢复确认：页面已接收'),
        recovery_running: I18n.t('恢复确认：已有输出'), recovery_committed: I18n.t('恢复确认：最终结果已持久化'),
        recovery_failed: I18n.t('恢复确认：此前已失败'), recovery_outcome_unknown: I18n.t('恢复发现结果未知'),
        outcome_unknown: I18n.t('执行结果未知，已停止自动重发'), tool_registered: I18n.t('MCP 工具调用已登记'),
        tool_delivered: I18n.t('工具调用已交给 Codex'), tool_result_received: I18n.t('Codex 工具结果已回传'),
        mcp_capability_created: I18n.t('已创建本回合 MCP 能力'), mcp_completion_committed: I18n.t('MCP 完成栅栏已提交'),
        compaction_source_transport_retired: I18n.t('已结束停止网页的旧工具连接'),
        mcp_capability_revoked: I18n.t('MCP 能力已撤销'),
        original_context_read: I18n.t('已查询本任务的原始历史'),
        task_conversation_restarted: I18n.t('已在新任务继续此 Codex 对话'),
        task_restarted_from: I18n.t('从旧任务继续此 Codex 对话'),
        gateway_failure_recorded: I18n.t('已记录本任务的网关错误'),
        gateway_failure_streak_reset: I18n.t('本任务已成功恢复，连续错误计数已清零')
    };

    /** Poll small cleanup metadata; progress never reloads or replaces the open task transcript. */
    function renderCleanup(result) {
        const format = bytes => (Number(bytes || 0) / (1024 * 1024)).toLocaleString(I18n.locale, {maximumFractionDigits: 1}) + ' MB';
        byId('task-storage-size').textContent = I18n.t('任务数据库：') + format(result.database_bytes)
            + ' · ' + I18n.t('可复用空间：') + format(result.reusable_bytes);
        const job = result.job;
        const labels = {queued: I18n.t('清理已登记'), deleting: I18n.t('正在删除任务及释放网页标签'),
            waiting_idle: I18n.t('清理正在等待资源释放。'),
            compacting: I18n.t('正在完成清理，释放的数据库空间将供后续复用'), completed: I18n.t('清理完成')};
        byId('task-cleanup-progress').textContent = job ? (labels[job.status] || I18n.t('等待清理'))
            + ' · ' + I18n.t('已删除：') + job.deleted_count + ' · ' + I18n.t('跳过工作中任务：') + job.skipped_count : '';
        byId('clear-idle-tasks').disabled = state.mutationBusy || !!(job && ['queued','deleting','compacting'].includes(job.status));
        if (job && job.status === 'waiting_idle' && Number(job.pending_count) > 0) {
            byId('task-cleanup-progress').textContent = I18n.t('清理正在等待资源释放。') + ' · ' + I18n.t('已删除：') + job.deleted_count;
            byId('clear-idle-tasks').disabled = true;
        }
        if (job && job.error_code && job.error_code !== 'CHECKPOINT_WAIT') {
            byId('task-cleanup-progress').textContent += ' · ' + I18n.t('整理暂未完成，系统将自动重试：') + job.error_code;
        }
    }

    /** One explicit deletion action, with a snapshot scope and server-side work protection. */
    function deleteTasks(task = null) {
        if (state.mutationBusy) return;
        const content = document.createElement('div'); content.className = 'task-delete-confirm';
        const target = document.createElement('strong'); target.dataset.userContent = 'true';
        target.textContent = task ? taskName(task) : I18n.t('当前所有非工作中的任务');
        const explanation = document.createElement('p');
        explanation.textContent = I18n.t('永久删除任务、上下文、网页原文、工具回执和可以清理的私有附件副本。工作中及等待审批的任务会保留；仍被有效检查点引用的附件和轻量校验凭证会保留，保护已有会话的压缩续接。项目源文件、登录资料和设置不受影响。');
        content.append(target, explanation);
        layui.layer.confirm(content.outerHTML, {title: I18n.t('确认删除任务记录'),
            btn: [I18n.t('删除记录'), I18n.t('保留')], area: [Math.min(520, window.innerWidth-32)+'px','auto']}, async index => {
            layui.layer.close(index);
            state.mutationBusy = true;
            ++state.sequence;
            byId('clear-idle-tasks').disabled = true;
            try {
                await Gateway.request(task ? '/api/tasks/' + encodeURIComponent(task.id) + '/delete' : '/api/tasks/clear', {});
                if (state.modalIndex !== null && (task ? state.modalTaskId === task.id : state.modalWorking === false)) layui.layer.close(state.modalIndex);
                state.page = 1;
                layui.layer.msg(I18n.t('清理已登记，正在移除关联记录。'));
            } catch (error) { setError(error.message); }
            finally { state.mutationBusy = false; await refresh(true); }
        });
    }

    function setError(message) {
        byId('error').textContent = message || '';
        byId('error').hidden = !message;
    }
    function formatTime(value) {
        const seconds = Number(value || 0);
        if (!seconds) return '—';
        return new Intl.DateTimeFormat(I18n.locale, {
            year: 'numeric', month: '2-digit', day: '2-digit',
            hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false
        }).format(new Date(seconds * 1000));
    }
    function tone(status) {
        if (['completed', 'committed', 'ready', 'active'].includes(status)) return 'good';
        if (['needs_attention', 'submit_intent', 'submitted', 'running', 'busy', 'attaching', 'reserved'].includes(status)) return 'pending';
        if (status === 'failed') return 'bad';
        return '';
    }
    function badge(text, status) {
        const node = document.createElement('span');
        node.className = 'badge ' + tone(status);
        node.textContent = I18n.t(text);
        return node;
    }
    function secondary(text) {
        const node = document.createElement('div');
        node.className = 'status-detail technical';
        node.textContent = I18n.t(text || '—');
        return node;
    }
    function taskName(task) {
        if (task.is_subagent && task.agent_path) return task.agent_path.split('/').pop();
        return task.conversation_title || task.latest_user || I18n.t('任务');
    }

    /** Pair the existing action label with a decorative Layui icon without changing its accessible name. */
    function actionLabel(button, iconName, text) {
        const icon = document.createElement('i');
        icon.className = 'layui-icon layui-icon-' + iconName;
        icon.setAttribute('aria-hidden', 'true');
        const label = document.createElement('span');
        label.textContent = text;
        button.append(icon, label);
    }

    function renderMetrics() {
        byId('metric-total').textContent = String(state.counts.total || 0);
        byId('metric-active').textContent = String(state.counts.active || 0);
        byId('metric-attention').textContent = String(state.counts.needs_attention || 0);
        byId('unknown-notice').hidden = !(state.counts.needs_attention > 0);
        taskStatuses.forEach(status => {
            byId('tab-count-' + status).textContent = String(state.counts[status] || 0);
        });
    }

    function renderList() {
        const body = byId('task-list');
        body.replaceChildren();
        byId('task-empty').hidden = state.total !== 0;
        byId('task-empty').textContent = I18n.t(emptyLabels[state.status]);
        state.tasks.forEach(task => {
            const row = document.createElement('tr');
            const identity = document.createElement('td');
            const title = document.createElement('strong');
            title.className = 'task-conversation-title';
            title.dataset.userContent = 'true';
            title.textContent = task.is_subagent ? taskName(task) : (task.conversation_title || task.latest_user || taskName(task));
            if (task.latest_user && task.latest_user !== task.conversation_title) {
                const latest = document.createElement('div');
                latest.className = 'task-latest-user muted';
                latest.dataset.userContent = 'true';
                latest.textContent = I18n.t('最近：') + task.latest_user;
                identity.append(title, latest);
            } else {
                identity.append(title);
            }
            const identityMeta = document.createElement('div');
            identityMeta.className = 'task-identity-meta';
            if (task.is_subagent) identityMeta.append(badge('子智能体', 'active'));
            identityMeta.append(badge(task.conversation_kind === 'continued' ? I18n.t('继续对话')
                : (task.conversation_kind === 'new' ? I18n.t('新对话') : I18n.t('对话状态未知')), task.conversation_kind));
            const thread = document.createElement('span');
            thread.className = 'status-detail technical';
            thread.textContent = task.thread_id ? ('Codex ' + String(task.thread_id).slice(0, 18) + I18n.t(' · 分支 ') + task.branch_id)
                : (I18n.t('独立请求 · ') + task.id);
            thread.title = task.thread_id ? String(task.thread_id) : task.id;
            identityMeta.append(thread);
            if (task.restarted_from_task_id) identityMeta.append(secondary(I18n.t('续开自 ') + task.restarted_from_task_id));
            if (task.continued_in_task_id) identityMeta.append(secondary(I18n.t('已续开到 ') + task.continued_in_task_id));
            identity.append(identityMeta);

            const phase = document.createElement('td');
            phase.append(badge(taskLabels[task.status] || task.status, task.status));
            phase.append(secondary(task.turn_status ? (turnLabels[task.turn_status] || task.turn_status) : I18n.t('等待首个回合')));
            if (Number(task.consecutive_gateway_errors) > 0) {
                phase.append(secondary(I18n.t('本任务连续网关错误：') + task.consecutive_gateway_errors + ' / 3'));
            }
            if (task.gateway_rebuild_pending) phase.append(badge(I18n.t('下次续发将重建任务页面'), 'needs_attention'));

            const page = document.createElement('td');
            const pageStatus = task.surface_status || '—';
            page.append(badge(surfaceLabels[pageStatus] || pageStatus, pageStatus));
            page.append(secondary(task.epoch_no ? (I18n.t('阶段 ') + task.epoch_no) : I18n.t('尚未建立阶段')));

            const updated = document.createElement('td');
            updated.textContent = formatTime(task.updated_at);

            const actions = document.createElement('td');
            const view = document.createElement('button');
            view.type = 'button';
            view.className = 'layui-btn layui-btn-primary layui-btn-sm';
            actionLabel(view, 'time', I18n.t('查看时间线'));
            view.addEventListener('click', () => openTimeline(task.id, view));
            actions.append(view);
            const remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'layui-btn layui-btn-danger layui-btn-sm';
            actionLabel(remove, 'delete', I18n.t('删除'));
            remove.disabled = task.can_delete !== true || state.mutationBusy;
            remove.title = task.cleanup_pending ? I18n.t('任务记录正在清理') : task.can_delete === true
                ? I18n.t('删除任务及其关联记录') : I18n.t('工作中或等待审批的任务不能删除');
            remove.addEventListener('click', () => deleteTasks(task));
            actions.append(remove);
            row.append(identity, phase, page, updated, actions);
            body.append(row);
        });
    }

    function renderPagination() {
        layui.use('laypage', function () {
            layui.laypage.render({
                elem: 'task-pagination', count: state.total, curr: state.page, limit: state.pageSize,
                limits: [10, 20, 50, 100], layout: ['count', 'prev', 'page', 'next', 'limit', 'skip'],
                prev: I18n.t('上一页'), next: I18n.t('下一页'),
                countText: I18n.locale === 'en' ? ['', ' items'] : ['共 ', ' 条'],
                skipText: [I18n.t('到第'), I18n.t('页'), I18n.t('确定')],
                limitTemplet: limit => I18n.locale === 'en' ? limit + ' / page' : limit + ' 条/页',
                jump: function (obj, first) {
                    if (first) return;
                    state.page = obj.curr;
                    state.pageSize = obj.limit;
                    refresh(true);
                }
            });
        });
    }

    function compactCard(titleText, statusText, statusCode, lines) {
        const card = document.createElement('div');
        card.className = 'task-stack-item';
        const top = document.createElement('div'); top.className = 'task-stack-title';
        const title = document.createElement('strong'); title.textContent = titleText;
        top.append(title, badge(statusText, statusCode));
        card.append(top);
        lines.forEach(line => {
            const p = document.createElement('div');
            p.className = 'status-detail';
            p.textContent = line;
            card.append(p);
        });
        return card;
    }

    /** Render transport observations as diagnostics, separately from task or model completion. */
    function eventDetail(event) {
        const data = event.data || {};
        if (event.type === 'browser_observation_delayed' && ['rate_limit', 'saved_chat_load'].includes(data.phase)) {
            return I18n.t('ChatGPT 暂时限流，正在等待自动恢复。')
                + (Number.isFinite(data.retry_after_seconds) ? ' · ' + I18n.t('下次检查至少等待') + ' ' + data.retry_after_seconds + 's' : '');
        }
        if (event.type === 'browser_response_transport') {
            const labels = {
                recovery_visible: '网页正在自动恢复', recovery_cleared: '网页恢复提示已消失',
                stream_failed: data.canceled === true ? '浏览器已关闭这条回答流' : '网页回答流发生中断',
                response_http: '网页回答流已收到 HTTP 响应',
                stream_metrics: '网页连接传输统计',
                background_failed: '网页后台恢复连接失败',
                background_finished: '网页后台恢复连接已返回',
                attachment_wait: '正在等待附件上传',
                attachment_ready: '全部附件已确认',
                attachment_timeout: '附件确认等待超时',
                empty_candidate: '正在核验空回答终态',
                empty_cleared: '空回答终态条件已解除',
                empty_settlement: '已核验回答流与工具状态',
                empty_stream_recovery: '回答流中断，正在保留回执并恢复',
                stopped_thinking: '网页已停止思考，未确认完成'
            };
            const parts = [I18n.t(labels[data.state] || data.state || '')];
            if (data.page_error_code) parts.push(data.page_error_code);
            if (data.error_code) parts.push(data.error_code);
            if (data.protocol) parts.push({h2: 'HTTP/2', h3: 'HTTP/3'}[data.protocol] || data.protocol);
            if (Number.isInteger(data.http_status)) parts.push('HTTP ' + data.http_status);
            if (typeof data.fence_drained === 'boolean') parts.push(I18n.t(data.fence_drained
                ? '工具和 MCP 回执已排空' : '工具或 MCP 回执尚未排空'));
            if (Number.isFinite(data.elapsed_ms)) parts.push((data.elapsed_ms / 1000).toFixed(1) + 's');
            if (data.endpoint) parts.push(data.endpoint);
            if (Number.isFinite(data.received_bytes)) parts.push((data.received_bytes / 1024).toFixed(1) + ' KiB');
            if (Number.isInteger(data.expected_count)) parts.push(I18n.t('已确认附件') + ' '
                + (Number.isInteger(data.matched_count) ? data.matched_count : '?') + '/' + data.expected_count);
            if (data.upload_pending === true) parts.push(I18n.t('仍在上传或处理'));
            if (data.upload_error === true) parts.push(I18n.t('网页报告附件错误'));
            if (data.duplicate === true) parts.push(I18n.t('发现重复附件'));
            return parts.filter(Boolean).join(' · ');
        }
        if (event.type === 'mcp_completion_committed' && Number(data.revision) === 0) {
            return I18n.t('本轮没有连接器工具请求到达网关；网页回复中的工具错误尚无本地调用回执。');
        }
        const parts = [];
        if (data.round_no !== undefined) parts.push('Round ' + data.round_no);
        if (data.wire_name) parts.push(I18n.t('工具：') + data.wire_name);
        if (data.reason) parts.push(I18n.t('原因：') + data.reason);
        if (data.old_task_id) parts.push(I18n.t('旧任务：') + data.old_task_id);
        if (data.new_task_id) parts.push(I18n.t('新任务：') + data.new_task_id);
        if (data.code) parts.push(I18n.t('代码：') + data.code);
        if (data.state) parts.push(I18n.t('状态：') + I18n.t(turnLabels[data.state] || data.state));
        if (data.call_id) parts.push('Call ID：' + data.call_id);
        if (data.revision !== undefined) parts.push('Revision ' + data.revision);
        return parts.join(' · ');
    }

    function buildConversation(conversation) {
        const card = document.createElement('section');
        card.className = 'task-detail-card task-conversation-card';
        const heading = document.createElement('div');
        heading.className = 'task-conversation-heading';
        const title = document.createElement('h3'); title.textContent = I18n.t('网页端交互');
        const note = document.createElement('span');
        note.className = 'muted';
        note.textContent = conversation.limited ? I18n.t('仅显示最近的回合与回复') : I18n.t('用户消息、网页 AI 回复与工具调用');
        heading.append(title, note);
        card.append(heading);
        const turns = Array.isArray(conversation.turns) ? conversation.turns : [];
        if (!turns.length) {
            card.append(secondary(I18n.t('还没有可展示的网页端交互。')));
            return card;
        }
        turns.forEach((turn, index) => {
            const group = document.createElement('details');
            group.className = 'task-conversation-turn';
            group.dataset.turnId = turn.turn_id;
            group.open = index === 0;
            const summary = document.createElement('summary');
            const label = document.createElement('strong');
            label.textContent = I18n.t('回合 ') + (turns.length - index) + ' · ' + formatTime(turn.created_at);
            const status = badge(turnLabels[turn.status] || turn.status, turn.status);
            summary.append(label, status);
            group.append(summary);
            const messages = document.createElement('div');
            messages.className = 'task-conversation-messages';
            if (turn.user && turn.user.text) {
                const message = document.createElement('div');
                message.className = 'task-chat-item user';
                const role = document.createElement('strong'); role.textContent = I18n.t('Codex 用户');
                const text = document.createElement('div'); text.className = 'task-chat-text';
                text.dataset.userContent = 'true';
                text.textContent = (turn.user.truncated ? I18n.t('…（前段省略）\n') : '') + turn.user.text;
                message.append(role, text);
                messages.append(message);
            }
            (turn.entries || []).forEach(entry => {
                const message = document.createElement('div');
                message.className = 'task-chat-item ' + (entry.kind === 'tool' ? 'tool' : 'assistant');
                const role = document.createElement('strong');
                role.textContent = entry.kind === 'tool' ? I18n.t('工具调用 · ') + entry.name
                    : (entry.phase === 'commentary' ? I18n.t('网页 AI · 进展') : I18n.t('网页 AI · 回复'));
                const meta = document.createElement('span');
                meta.className = 'muted';
                meta.textContent = 'Round ' + entry.round_no + ' · ' + formatTime(entry.created_at)
                    + (entry.status === 'in_progress' ? I18n.t(' · 生成中') : '');
                const top = document.createElement('div'); top.className = 'task-chat-head';
                top.append(role, meta);
                message.append(top);
                if (entry.kind !== 'tool') {
                    const text = document.createElement('div'); text.className = 'task-chat-text';
                    text.dataset.userContent = 'true';
                    text.textContent = entry.text.text + (entry.text.truncated ? I18n.t('\n…（后段省略）') : '');
                    message.append(text);
                }
                messages.append(message);
            });
            if (!messages.childElementCount) messages.append(secondary(I18n.t('等待网页端输出…')));
            group.append(messages);
            card.append(group);
        });
        return card;
    }

    const webMessageKinds = {
        stage: I18n.t('发送 · 上下文分段'), stage_ack_repair: I18n.t('发送 · 分段确认修正'),
        commit: I18n.t('发送 · 完整契约与任务'), continuation: I18n.t('发送 · 同页续接'),
        stage_ack: I18n.t('接收 · 分段确认'), tool_boundary: I18n.t('接收 · 工具调用前回复'),
        page_reply: I18n.t('接收 · 网页最终回复')
    };

    function buildWebMessages(entries) {
        const card = document.createElement('section');
        card.className = 'task-detail-card task-web-audit-card';
        const heading = document.createElement('div'); heading.className = 'task-conversation-heading';
        const title = document.createElement('h3'); title.textContent = I18n.t('完整网页消息');
        const note = document.createElement('span'); note.className = 'muted';
        note.textContent = I18n.t('逐条保存实际发送意图与网页观察文本；展开后加载全文，不截断。');
        heading.append(title, note); card.append(heading);
        if (!entries.length) {
            card.append(secondary(I18n.t('尚无原文记录。此项从启用后开始采集，旧回合不会伪装成已核对的发送原文。')));
            return card;
        }
        entries.slice().reverse().forEach(entry => {
            const item = document.createElement('details');
            item.className = 'task-web-audit-message';
            item.dataset.auditId = String(entry.id);
            const summary = document.createElement('summary');
            const label = document.createElement('strong');
            label.textContent = I18n.t(webMessageKinds[entry.kind]) || (entry.direction === 'outbound' ? I18n.t('发送') : I18n.t('接收')) + ' · ' + entry.kind;
            if (entry.stage_no) label.textContent += ' ' + entry.stage_no;
            const meta = document.createElement('span'); meta.className = 'muted';
            meta.textContent = formatTime(entry.created_at) + ' · ' + entry.byte_count.toLocaleString(I18n.locale) + I18n.t(' 字节')
                + (entry.status === 'intent' ? I18n.t(' · 接收待确认') : (entry.status === 'accepted' ? I18n.t(' · 已确认接收') : I18n.t(' · 已观察')));
            summary.append(label, meta); item.append(summary);
            const body = document.createElement('div'); body.className = 'task-web-audit-body';
            body.append(secondary(I18n.t('展开后读取完整原文…')));
            item.append(body); card.append(item);
        });
        return card;
    }

    async function loadWebMessage(item) {
        if (item.dataset.loaded || item.dataset.loading || !state.modalTaskId) return;
        item.dataset.loading = '1';
        const body = item.querySelector('.task-web-audit-body');
        body.replaceChildren(secondary(I18n.t('正在读取完整原文…')));
        try {
            const result = await Gateway.request('/api/tasks/' + encodeURIComponent(state.modalTaskId)
                + '/web-messages/' + encodeURIComponent(item.dataset.auditId));
            if (!item.isConnected || state.modalTaskId === null) return;
            const toolbar = document.createElement('div'); toolbar.className = 'task-web-audit-actions';
            const copy = document.createElement('button'); copy.type = 'button'; copy.className = 'layui-btn layui-btn-primary layui-btn-sm';
            copy.textContent = I18n.t('复制全文'); copy.dataset.auditAction = 'copy';
            const download = document.createElement('button'); download.type = 'button'; download.className = 'layui-btn layui-btn-primary layui-btn-sm';
            download.textContent = I18n.t('下载 TXT'); download.dataset.auditAction = 'download';
            const hash = document.createElement('span'); hash.className = 'status-detail technical';
            hash.textContent = 'SHA-256 ' + result.sha256;
            toolbar.append(copy, download, hash);
            const pre = document.createElement('pre'); pre.className = 'task-web-audit-text'; pre.textContent = result.text;
            body.replaceChildren(toolbar, pre);
            item.dataset.loaded = '1';
        } catch (error) {
            body.replaceChildren(secondary(I18n.t('原文读取失败：') + error.message));
        } finally {
            delete item.dataset.loading;
        }
    }

    /** Show recorded transport decisions separately from the model's ordinary conversation. */
    function buildContextDecisions(rows) {
        const card = document.createElement('section'); card.className = 'task-detail-card';
        const title = document.createElement('h3'); title.textContent = I18n.t('上下文发送与压缩决策'); card.append(title);
        card.append(secondary(I18n.t('Token 为本机传输估算；3X 扩大历史接入预算，不改变模型本身的记忆窗口。')));
        const reasons = {
            inline: I18n.t('符合单条发送预算'),
            soft_inline_threshold: I18n.t('达到历史分段优化阈值'),
            editor_preference: I18n.t('2 段可以容纳，为降低长文本输入卡顿选择 6 段'),
            partition_limits: I18n.t('完整记录或上下文总预算需要更多分段'),
            message_limit: I18n.t('超过单条消息输入预算'),
            compaction_transport: I18n.t('压缩交接需要传输完整原始上下文'),
            pinned_recovery: I18n.t('恢复原回合已确定的发送方案')
        };
        const budgetPolicies = {
            legacy_threefold: I18n.t('沿用该模型的 3× 历史预算'),
            standard_context: I18n.t('使用该模型的标准窗口'),
            gpt6_pro_reasoning_measured: I18n.t('GPT-6 推理档使用实测窗口上限'),
            gpt6_plus_reasoning_measured: I18n.t('GPT-6 Plus 推理档使用实测窗口上限'),
            luna_staged_transport: I18n.t('Luna 使用有界分段输入，原始历史保留'),
            luna_rolling_checkpoint: I18n.t('Luna 使用滚动摘要续接，单条输入独立限额'),
            gpt6_instant_standard: I18n.t('GPT-6 Instant 使用标准窗口'),
            gpt6_non_pro_standard: I18n.t('GPT-6 当前账号使用标准窗口')
        };
        if (!rows.length) card.append(secondary(I18n.t('还没有上下文决策记录。')));
        rows.forEach((row, index) => {
            const decision = row.decision;
            const entry = document.createElement('details'); entry.className = 'task-context-decision';
            entry.dataset.contextDecisionId = row.id;
            entry.open = index === 0;
            const summary = document.createElement('summary');
            summary.textContent = formatTime(row.created_at) + ' · ' + I18n.t(row.operation_kind === 'response' ? '任务发送' : '上下文压缩');
            entry.append(summary);
            if (!decision) entry.append(secondary(I18n.t('历史记录未保存具体决策，以下只展示已记录的预算。')));
            else entry.append(secondary(reasons[decision.reason] || I18n.t('按已保存预算传输')));
            const number = value => Number(value || 0).toLocaleString(I18n.locale);
            entry.append(secondary(I18n.t('输入估算 Token：') + number(row.input_tokens)
                + ' · ' + I18n.t('历史接入倍数：') + number(row.context_multiplier) + '×'));
            if (decision) {
                entry.append(secondary(I18n.t('发送正文 Token：') + number(decision.prompt_tokens)
                    + ' · ' + I18n.t('正文字符：') + number(decision.prompt_chars)));
                entry.append(secondary(I18n.t('计划段数：') + number(decision.parts)
                    + ' · ' + I18n.t('历史段已发 / 已确认：') + number(row.transport_stage_sent) + ' / ' + number(row.transport_stage_acked)));
                entry.append(secondary(I18n.t('单条消息 Token 上限：') + number(decision.message_tokens)
                    + ' · ' + I18n.t('分段优化阈值：') + number(decision.soft_inline_tokens)));
            }
            entry.append(secondary(I18n.t('历史接入预算 / 压缩阈值：') + number(row.logical_context_tokens) + ' / ' + number(row.logical_compact_tokens)));
            if (Object.hasOwn(budgetPolicies, row.budget_policy)) {
                entry.append(secondary(I18n.t('预算依据：') + budgetPolicies[row.budget_policy]));
                if (row.model_family) entry.append(secondary(I18n.t('模型家族：') + row.model_family));
                if (typeof row.extension_requested === 'boolean' && typeof row.extension_allowed === 'boolean') {
                    entry.append(secondary(I18n.t('扩展请求 / 实际可用：')
                        + I18n.t(row.extension_requested ? '已开启' : '未开启') + ' / '
                        + I18n.t(row.extension_allowed ? '可用' : '不可用')));
                }
                if (Number.isFinite(row.context_effective_ratio) && row.context_effective_ratio > 0) {
                    entry.append(secondary(I18n.t('有效窗口相对基础预算：')
                        + row.context_effective_ratio.toLocaleString(I18n.locale, {maximumFractionDigits: 2}) + '×'));
                }
                if (row.compaction_capacity_effort === 'high') {
                    entry.append(secondary(I18n.t('摘要按同模型的高档容量计算，不使用 Pro 档。')));
                }
            }
            card.append(entry);
        });
        return card;
    }

    function buildDetail(detail) {
        const root = document.createElement('div');
        root.className = 'task-modal-body';
        const task = detail.task;
        state.modalWorking = task.working === true;

        const heading = document.createElement('div');
        heading.className = 'panel-title task-modal-heading';
        const headText = document.createElement('div');
        const title = document.createElement('h2'); title.dataset.userContent = 'true'; title.textContent = taskName(task);
        const sub = document.createElement('p');
        sub.className = 'muted task-panel-help';
        sub.textContent = I18n.t('分支 ') + task.branch_id + ' · ' + task.id;
        headText.append(title, sub);
        const actions = document.createElement('div');
        actions.className = 'actions task-heading-actions';
        actions.append(badge(taskLabels[task.status] || task.status, task.status));
        if (!['cancelled', 'completed'].includes(task.status)) {
            const cancel = document.createElement('button');
            cancel.type = 'button';
            cancel.className = 'layui-btn layui-btn-danger layui-btn-sm';
            cancel.textContent = I18n.t('取消任务');
            cancel.disabled = state.mutationBusy;
            cancel.addEventListener('click', () => cancelTask(task));
            actions.append(cancel);
        }
        heading.append(headText, actions);
        root.append(heading);
        root.append(buildContextDecisions(Array.isArray(detail.context_decisions) ? detail.context_decisions : []));

        if (task.status === 'needs_attention') {
            const warning = document.createElement('div');
            warning.className = 'notice warning';
            warning.textContent = I18n.t('本轮有工具结果未确认，已停止自动重发。回到原 Codex 会话发送新消息时，网关会建立新任务并要求核对现状；只有不再继续时才取消旧任务。');
            root.append(warning);
        }

        const summary = document.createElement('dl');
        summary.className = 'state-grid task-summary';
        [
            [I18n.t('客户端'), task.client_id], ['Thread', task.thread_id || I18n.t('未提供')],
            ...(task.is_subagent ? [[I18n.t('父线程'), task.parent_thread_id], [I18n.t('子智能体'), task.agent_path]] : []),
            [I18n.t('网页对话模式'), I18n.t(task.web_chat_mode === 'regular' ? '普通对话（保存到 ChatGPT 历史）' : '临时对话（默认）')],
            [I18n.t('网页对话标识'), task.web_conversation_id || '—'],
            [I18n.t('当前阶段'), task.current_epoch_id ? (I18n.t('阶段 ') + (task.epoch_no || '—')) : I18n.t('尚未建立')],
            [I18n.t('创建时间'), formatTime(task.created_at)], [I18n.t('更新时间'), formatTime(task.updated_at)],
            [I18n.t('取消时间'), task.cancelled_at ? formatTime(task.cancelled_at) : '—']
        ].forEach(([label, value]) => {
            const dt = document.createElement('dt'); dt.textContent = label;
            const dd = document.createElement('dd'); dd.textContent = String(value);
            summary.append(dt, dd);
        });
        root.append(buildConversation(detail.conversation || {turns: [], limited: false}));
        root.append(buildWebMessages(Array.isArray(detail.web_messages) ? detail.web_messages : []));
        root.append(summary);

        const grid = document.createElement('div');
        grid.className = 'task-detail-grid';
        const epochs = document.createElement('div');
        epochs.className = 'task-detail-card';
        const epochsTitle = document.createElement('h3'); epochsTitle.textContent = I18n.t('阶段与页面');
        const epochStack = document.createElement('div'); epochStack.className = 'task-stack';
        if (!detail.epochs.length) epochStack.append(secondary(I18n.t('还没有阶段记录。')));
        detail.epochs.forEach(epoch => {
            const surface = detail.surfaces.find(row => row.epoch_id === epoch.id);
            const pageStatus = surface ? I18n.t(surfaceLabels[surface.status] || surface.status) : I18n.t('无页面');
            epochStack.append(compactCard(
                I18n.t('阶段 ') + epoch.epoch_no,
                epoch.status === 'active' ? I18n.t('当前') : (epoch.status === 'needs_attention' ? I18n.t('需要确认') : I18n.t('已退休')),
                epoch.status,
                [
                    I18n.t('模型：') + epoch.model + (epoch.reasoning_effort ? I18n.t(' · 推理 ') + epoch.reasoning_effort : ''),
                    I18n.t('压缩阶段：') + epoch.compaction_epoch,
                    I18n.t('页面：') + pageStatus + (surface && surface.target_bound ? I18n.t(' · 已绑定任务页面') : I18n.t(' · 未绑定页面')),
                    I18n.t('建立：') + formatTime(epoch.created_at) + (epoch.retired_at ? I18n.t(' · 退休：') + formatTime(epoch.retired_at) : '')
                ]
            ));
        });
        epochs.append(epochsTitle, epochStack);

        const turns = document.createElement('div');
        turns.className = 'task-detail-card';
        const turnsTitle = document.createElement('h3'); turnsTitle.textContent = I18n.t('回合');
        const turnStack = document.createElement('div'); turnStack.className = 'task-stack';
        if (!detail.turns.length) turnStack.append(secondary(I18n.t('还没有回合记录。')));
        detail.turns.slice().reverse().forEach(turn => {
            turnStack.append(compactCard(
                turn.client_turn_id || turn.id,
                turnLabels[turn.status] || turn.status,
                turn.status,
                [
                    'Response：' + (turn.response_id || I18n.t('尚未绑定')),
                    I18n.t('发送意图：') + (turn.submit_intent_at ? formatTime(turn.submit_intent_at) : I18n.t('未发生')),
                    I18n.t('页面接收：') + (turn.submitted_at ? formatTime(turn.submitted_at) : I18n.t('未确认')),
                    I18n.t('完成：') + (turn.committed_at ? formatTime(turn.committed_at) : I18n.t('未确认')) + (turn.error_code ? ' · ' + turn.error_code : '')
                ]
            ));
        });
        turns.append(turnsTitle, turnStack);
        grid.append(epochs, turns);
        root.append(grid);

        const timelineCard = document.createElement('div');
        timelineCard.className = 'task-detail-card task-timeline-card';
        const timelineTitle = document.createElement('h3'); timelineTitle.textContent = I18n.t('执行时间线');
        const timeline = document.createElement('div'); timeline.className = 'task-timeline';
        if (!detail.events.length) timeline.append(secondary(I18n.t('还没有时间线事件。')));
        detail.events.slice().reverse().forEach(event => {
            const row = document.createElement('div'); row.className = 'task-timeline-item';
            const dot = document.createElement('span'); dot.className = 'task-timeline-dot';
            const content = document.createElement('div');
            const top = document.createElement('div'); top.className = 'task-timeline-title';
            const eventTitle = document.createElement('strong');
            eventTitle.textContent = event.type === 'browser_observation_delayed'
                && ['rate_limit', 'saved_chat_load'].includes(event.data?.phase)
                ? I18n.t('限流等待中，任务已保留') : I18n.t(eventLabels[event.type] || event.type);
            const time = document.createElement('span'); time.className = 'muted'; time.textContent = formatTime(event.created_at);
            top.append(eventTitle, time);
            content.append(top);
            const detailText = eventDetail(event);
            if (detailText) content.append(secondary(detailText));
            if (event.response_id) content.append(secondary('Response ' + event.response_id));
            row.append(dot, content);
            timeline.append(row);
        });
        timelineCard.append(timelineTitle, timeline);
        root.append(timelineCard);
        return root;
    }

    function modalArea() {
        return [
            Math.round(Math.min(1100, window.innerWidth * .92)) + 'px',
            Math.round(Math.min(820, window.innerHeight * .86)) + 'px'
        ];
    }

    function modalMessage(message, retryTaskId) {
        const root = document.createElement('div');
        root.className = 'task-modal-body task-modal-message';
        const card = document.createElement('div');
        card.className = 'task-modal-message-card';
        const icon = document.createElement('i');
        icon.className = retryTaskId
            ? 'layui-icon layui-icon-close-fill task-modal-error-icon'
            : 'layui-icon layui-icon-loading layui-anim layui-anim-rotate layui-anim-loop';
        const text = document.createElement('div');
        text.className = 'status-detail';
        text.textContent = message;
        card.append(icon, text);
        if (retryTaskId) {
            const retry = document.createElement('button');
            retry.type = 'button';
            retry.className = 'layui-btn layui-btn-primary layui-btn-sm';
            retry.textContent = I18n.t('重试');
            retry.addEventListener('click', () => readDetail(retryTaskId, true, retry));
            card.append(retry);
        }
        root.append(card);
        return root;
    }

    function userReadingModal() {
        if (!state.modalBody || Date.now() < state.modalInteractionUntil) return true;
        if (state.modalBody.querySelector('.task-web-audit-message[open]')) return true;
        const selection = window.getSelection();
        return !!(selection && !selection.isCollapsed && state.modalBody.contains(selection.anchorNode));
    }

    function watchModalInteraction(body) {
        body.addEventListener('scroll', () => { state.modalInteractionUntil = Date.now() + 3000; }, {passive: true});
        body.addEventListener('wheel', () => { state.modalInteractionUntil = Date.now() + 3000; }, {passive: true});
        body.addEventListener('click', event => {
            state.modalInteractionUntil = Date.now() + 2000;
            const item = event.target.closest('.task-web-audit-message');
            if (!item) return;
            const action = event.target.closest('[data-audit-action]');
            if (action) {
                const text = item.querySelector('.task-web-audit-text')?.textContent;
                if (text === undefined) return;
                if (action.dataset.auditAction === 'copy') {
                    navigator.clipboard.writeText(text).then(() => layui.layer.msg(I18n.t('原文已复制')))
                        .catch(() => layui.layer.msg(I18n.t('复制失败，请选中文本复制')));
                } else {
                    const url = URL.createObjectURL(new Blob([text], {type: 'text/plain;charset=utf-8'}));
                    const link = document.createElement('a'); link.href = url;
                    link.download = 'web-message-' + item.dataset.auditId + '.txt'; link.click();
                    setTimeout(() => URL.revokeObjectURL(url), 1000);
                }
                return;
            }
            if (event.target.closest('summary')) setTimeout(() => { if (item.open) loadWebMessage(item); }, 0);
        });
    }

    async function readDetail(taskId, replaceExisting, trigger) {
        if (state.detailReading || state.mutationBusy) return;
        if (!trigger && (!Gateway.automaticRefreshAllowed({allowLayers: true}) || userReadingModal())) return;
        state.detailReading = true;
        const triggerContent = trigger ? Array.from(trigger.childNodes) : [];
        if (trigger) {
            trigger.disabled = true;
            trigger.dataset.label = trigger.textContent;
            trigger.innerHTML = '<i class="layui-icon layui-icon-loading layui-anim layui-anim-rotate layui-anim-loop"></i> ' + I18n.t('打开中…');
        }
        try {
            const detail = await Gateway.request('/api/tasks/' + encodeURIComponent(taskId));
            if (state.disposed || state.modalTaskId !== taskId) return;
            const signature = JSON.stringify(detail);
            if (signature === state.modalSignature) return;
            if (!trigger && replaceExisting && (!Gateway.automaticRefreshAllowed({allowLayers: true}) || userReadingModal())) return;
            const body = buildDetail(detail);
            if (replaceExisting && state.modalBody && state.modalBody.isConnected) {
                const oldBody = state.modalBody;
                const scrollTop = oldBody.scrollTop;
                const openTurns = new Map(Array.from(oldBody.querySelectorAll('.task-conversation-turn'))
                    .map(turn => [turn.dataset.turnId, turn.open]));
                body.querySelectorAll('.task-conversation-turn').forEach(turn => {
                    if (openTurns.has(turn.dataset.turnId)) turn.open = openTurns.get(turn.dataset.turnId);
                });
                const openDecisions = new Map(Array.from(oldBody.querySelectorAll('.task-context-decision'))
                    .map(entry => [entry.dataset.contextDecisionId, entry.open]));
                body.querySelectorAll('.task-context-decision').forEach(entry => {
                    if (openDecisions.has(entry.dataset.contextDecisionId)) entry.open = openDecisions.get(entry.dataset.contextDecisionId);
                });
                state.modalBody.replaceWith(body);
                state.modalBody = body;
                body.scrollTop = scrollTop;
                watchModalInteraction(body);
                state.modalSignature = signature;
                return;
            }
            if (replaceExisting) return;
            layui.use('layer', function () {
                state.modalIndex = layui.layer.open({
                    type: 1,
                    title: I18n.t('任务时间线'),
                    skin: 'task-layer',
                    area: [Math.round(Math.min(1100, window.innerWidth * .92)) + 'px', Math.round(Math.min(820, window.innerHeight * .86)) + 'px'],
                    resize: true,
                    content: body.outerHTML,
                    success: function (layero) {
                        state.modalBody = layero.find('.task-modal-body')[0] || null;
                        if (state.modalBody) watchModalInteraction(state.modalBody);
                        state.modalSignature = signature;
                    },
                    end: function () {
                        state.modalTaskId = null;
                        state.modalIndex = null;
                        state.modalBody = null;
                        state.modalSignature = null;
                    }
                });
            });
        } catch (error) {
            if (!state.disposed && state.modalTaskId === taskId && (trigger || Gateway.automaticRefreshAllowed({allowLayers: true}))) setError(error.message);
            if (trigger && replaceExisting && state.modalBody && state.modalBody.isConnected && state.modalTaskId === taskId) {
                const failed = modalMessage(error.message || I18n.t('任务时间线读取失败。'), taskId);
                state.modalBody.replaceWith(failed);
                state.modalBody = failed;
            }
        } finally {
            state.detailReading = false;
            if (trigger && trigger.isConnected) {
                trigger.disabled = false;
                if (triggerContent.length) trigger.replaceChildren(...triggerContent);
                else trigger.textContent = trigger.dataset.label || I18n.t('查看时间线');
            }
        }
    }

    function openTimeline(taskId, trigger) {
        if (state.detailReading || state.mutationBusy) return;
        layui.use('layer', function () {
            if (state.modalIndex !== null) layui.layer.close(state.modalIndex);
            state.modalTaskId = taskId;
            state.modalSignature = null;
            state.modalInteractionUntil = 0;
            const loadingBody = modalMessage(I18n.t('正在读取任务时间线…'), null);
            state.modalIndex = layui.layer.open({
                type: 1,
                title: I18n.t('任务时间线'),
                skin: 'task-layer',
                area: modalArea(),
                resize: true,
                content: loadingBody.outerHTML,
                success: function (layero) {
                    state.modalBody = layero.find('.task-modal-body')[0] || null;
                    readDetail(taskId, true, trigger);
                },
                end: function () {
                    state.modalTaskId = null;
                    state.modalIndex = null;
                    state.modalBody = null;
                    state.modalSignature = null;
                }
            });
        });
    }

    function cancelTask(task) {
        if (state.mutationBusy) return;
        layui.use('layer', function () {
            layui.layer.confirm(
                I18n.t('取消后，未完成回合会停止，任务页面会退休并由浏览器进程自动关闭。已确认的历史记录会保留。'),
                {title: I18n.t('取消这个任务？'), btn: [I18n.t('确认取消'), I18n.t('返回')]},
                async function (index) {
                    layui.layer.close(index);
                    state.mutationBusy = true;
                    ++state.sequence;
                    try {
                        const result = await Gateway.request('/api/tasks/' + encodeURIComponent(task.id) + '/cancel', {});
                        layui.layer.msg(result.pending_surface_cleanup > 0 ? I18n.t('取消已登记，任务页面正在自动清理。') : I18n.t('任务已取消。'));
                        if (state.modalIndex !== null) layui.layer.close(state.modalIndex);
                    } catch (error) {
                        setError(error.message);
                    } finally {
                        state.mutationBusy = false;
                        await refresh(true);
                    }
                }
            );
        });
    }

    async function refresh(manual) {
        if (state.disposed || state.mutationBusy) return;
        if (!manual && (state.reading || !Gateway.automaticRefreshAllowed({allowLayers: true}))) return;
        state.reading = true;
        const sequence = ++state.sequence;
        const status = state.status;
        try {
            const result = await Gateway.request('/api/tasks?status=' + encodeURIComponent(status)
                + '&page=' + state.page + '&page_size=' + state.pageSize);
            if (state.disposed || sequence !== state.sequence || status !== state.status) return;
            const cleanup = await Gateway.request('/api/tasks/cleanup');
            if (state.disposed || sequence !== state.sequence || status !== state.status) return;
            if (state.modalTaskId) readDetail(state.modalTaskId, true);
            if (!manual && !Gateway.automaticRefreshAllowed()) return;
            state.tasks = Array.isArray(result.items) ? result.items : [];
            state.total = Number(result.total || 0);
            state.pages = Number(result.pages || 1);
            state.counts = result.counts || state.counts;
            renderCleanup(cleanup);
            if (state.page > state.pages) {
                state.page = state.pages;
                state.reading = false;
                return refresh(manual);
            }
            renderMetrics();
            const listSignature = JSON.stringify([state.status, state.page, state.pageSize, state.total, state.pages, state.tasks]);
            if (listSignature !== state.listSignature) {
                renderList();
                renderPagination();
                state.listSignature = listSignature;
            }
            setError('');
        } catch (error) {
            if (!state.disposed && sequence === state.sequence && (manual || Gateway.automaticRefreshAllowed())) setError(error.message);
        } finally {
            if (sequence === state.sequence) state.reading = false;
        }
    }

    function schedule() {
        if (state.timer) clearInterval(state.timer);
        state.timer = setInterval(() => refresh(false), 2000);
    }

    byId('refresh').addEventListener('click', () => refresh(true));
    byId('clear-idle-tasks').addEventListener('click', () => deleteTasks());
    layui.use('element', function () {
        layui.element.on('tab(task-status-tabs)', function (data) {
            const status = taskStatuses[data.index];
            if (!status || status === state.status) return;
            state.status = status;
            state.page = 1;
            refresh(true);
        });
    });
    Gateway.onPageActivate(() => {
        byId('follow-status').textContent = I18n.t('自动跟随');
        byId('follow-status').className = 'badge good';
        refresh(false);
    });
    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) return;
        byId('follow-status').textContent = I18n.t('已暂停');
        byId('follow-status').className = 'badge';
    });
    window.addEventListener('pagehide', () => {
        state.disposed = true;
        if (state.timer) clearInterval(state.timer);
        ++state.sequence;
        state.reading = false;
    });
    window.addEventListener('pageshow', () => {
        if (!state.disposed) return;
        state.disposed = false;
        schedule();
        refresh(true);
    });
    window.addEventListener('gateway-language-changed', () => {
        renderMetrics();
        renderList();
        renderPagination();
    });

    refresh(true);
    schedule();
})();
