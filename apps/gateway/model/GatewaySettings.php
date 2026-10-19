<?php
declare(strict_types=1);
namespace app\gateway\model;

use app\gateway\database\Schema;

/** Strongly typed, user-editable Step 06 settings persisted in SQLite. */
final class GatewaySettings
{
    /** @return array Public preferences, including the persisted inactive-task cleanup switch; never exposes secrets. */
    public static function snapshot(): array
    {
        Schema::initialize();
        $row = self::row();
        return [
            'mcp_enabled' => (bool) $row['mcp_enabled'],
            'auto_approve_tool_calls' => (bool) $row['auto_approve_tool_calls'],
            'connector_name' => (string) $row['connector_name'],
            'web_chat_mode' => (string) ($row['web_chat_mode'] ?? 'temporary'),
            'web_chat_fallback_at' => (int) ($row['web_chat_fallback_at'] ?? 0),
            'ui_locale' => (string) ($row['ui_locale'] ?: \RC\Config::get('gateway', 'default_locale')),
            'pro_weekly_budget' => (int) ($row['pro_weekly_budget'] ?? 0),
            'mcp_invoke_timeout_ms' => (int) $row['mcp_invoke_timeout_ms'],
            'wait_poll_interval_ms' => (int) $row['wait_poll_interval_ms'],
            'max_tool_calls_per_turn' => (int) $row['max_tool_calls_per_turn'],
            // Legacy settings/export compatibility only; native receipts are never capped here.
            'tool_result_max_bytes' => (int) $row['tool_result_max_bytes'],
            'tunnel_id' => (string) $row['tunnel_id'],
            'runtime_key_configured' => is_string($row['protected_runtime_key']) && $row['protected_runtime_key'] !== '',
            'runtime_secret_storage' => RuntimeCredential::available() ? 'project_secretbox' : 'unavailable',
            'updated_at' => (int) $row['updated_at'],
            'context_transport' => (string) $row['context_transport'],
            'context_max_parts' => (int) $row['context_max_parts'],
            'context_extended' => (bool) $row['context_extended'],
            'response_timeout_seconds' => (int) $row['response_timeout_seconds'],
            'context_stage_timeout_seconds' => (int) $row['context_stage_timeout_seconds'],
            'compaction_timeout_seconds' => (int) $row['compaction_timeout_seconds'],
            'skill_transport' => (string) $row['skill_transport'],
            'official_handoff_summary' => (bool) $row['official_handoff_summary'],
            'steering_full_context' => (bool) $row['steering_full_context'],
            'auto_cleanup_inactive_tasks' => (bool) $row['auto_cleanup_inactive_tasks'],
            'web_initial_contract_template' => self::effectiveContract($row, 'web_initial_contract_template'),
            'web_resume_contract_template' => self::effectiveContract($row, 'web_resume_contract_template'),
            'web_readonly_contract_template' => self::effectiveContract($row, 'web_readonly_contract_template'),
            'web_compact_contract_template' => self::effectiveContract($row, 'web_compact_contract_template'),
            'web_contract_defaults' => WebContract::defaults(),
        ];
    }

    /** Internal settings. Never return protected_runtime_key over HTTP. */
    public static function internal(): array
    {
        Schema::initialize();
        $row = self::row();
        $row['web_initial_contract_template'] = self::effectiveContract($row, 'web_initial_contract_template');
        $row['web_resume_contract_template'] = self::effectiveContract($row, 'web_resume_contract_template');
        $row['web_readonly_contract_template'] = self::effectiveContract($row, 'web_readonly_contract_template');
        $row['web_compact_contract_template'] = self::effectiveContract($row, 'web_compact_contract_template');
        return $row;
    }

    /**
     * Validate and atomically save preferences using the existing optimistic revision guard.
     * @param array $input Supported settings; omitted newer preferences keep their saved values.
     * @return array Updated public preferences. @throws GatewayError On validation or revision conflict.
     */
    public static function save(#[\SensitiveParameter] array $input): array
    {
        $allowed = [
            'ui_locale', 'pro_weekly_budget', 'auto_cleanup_inactive_tasks', 'web_chat_mode',
            'expected_updated_at', 'mcp_enabled', 'auto_approve_tool_calls', 'connector_name', 'mcp_invoke_timeout_ms',
            'wait_poll_interval_ms', 'max_tool_calls_per_turn', 'tool_result_max_bytes',
            'tunnel_id', 'runtime_key', 'runtime_key_action',
            'context_transport', 'context_max_parts', 'context_extended', 'response_timeout_seconds',
            'context_stage_timeout_seconds', 'compaction_timeout_seconds',
            'skill_transport', 'official_handoff_summary', 'steering_full_context',
            'web_initial_contract_template', 'web_resume_contract_template',
            'web_readonly_contract_template', 'web_compact_contract_template',
        ];
        if (array_diff(array_keys($input), $allowed)) {
            throw new GatewayError('INVALID_FIELD', '设置包含不支持的字段。');
        }
        $current = self::row();
        $input['ui_locale'] ??= $current['ui_locale'] ?: \RC\Config::get('gateway', 'default_locale');
        $input['pro_weekly_budget'] ??= $current['pro_weekly_budget'] ?? 0;
        $input['auto_cleanup_inactive_tasks'] ??= $current['auto_cleanup_inactive_tasks'];
        $input['web_chat_mode'] ??= $current['web_chat_mode'] ?? 'temporary';
        foreach (['context_transport', 'context_max_parts', 'context_extended', 'response_timeout_seconds',
            'context_stage_timeout_seconds', 'compaction_timeout_seconds', 'skill_transport',
            'official_handoff_summary', 'steering_full_context', 'web_initial_contract_template', 'web_resume_contract_template',
            'web_readonly_contract_template', 'web_compact_contract_template'] as $field) {
            $input[$field] ??= $current[$field];
        }
        $input += [
            'runtime_key' => '',
            'runtime_key_action' => 'keep',
            'tunnel_id' => '',
            'auto_approve_tool_calls' => (int) $current['auto_approve_tool_calls'],
            // New consoles omit this retired control; old clients can still round-trip it.
            'tool_result_max_bytes' => (int) $current['tool_result_max_bytes'],
        ];
        try {
            $data = validator()->input($input, [
                'ui_locale' => ['rule' => 'string', 'len' => [2, 5], 'name' => 'Language'],
                'web_chat_mode' => ['rule' => 'string', 'len' => [7, 9], 'name' => 'Web chat mode'],
                'pro_weekly_budget' => ['rule' => 'nnint', 'range' => [0, 100000], 'name' => 'Weekly Pro tracking budget'],
                'auto_cleanup_inactive_tasks' => ['rule' => 'nnint', 'range' => [0, 1], 'name' => 'Inactive task cleanup'],
                'expected_updated_at' => ['rule' => 'nnint', 'name' => '设置版本'],
                'mcp_enabled' => ['rule' => 'nnint', 'range' => [0, 1], 'name' => 'MCP 开关'],
                'auto_approve_tool_calls' => ['rule' => 'nnint', 'range' => [0, 1], 'name' => '一次性工具确认'],
                'connector_name' => ['rule' => 'string', 'len' => [1, 80], 'name' => '连接器名称'],
                'mcp_invoke_timeout_ms' => ['rule' => 'pint', 'range' => [10000, 110000], 'name' => '工具超时'],
                'wait_poll_interval_ms' => ['rule' => 'pint', 'range' => [50, 5000], 'name' => '等待轮询'],
                'max_tool_calls_per_turn' => ['rule' => 'nnint', 'range' => [0, 4096], 'name' => '单回合工具上限'],
                'tool_result_max_bytes' => ['rule' => 'pint', 'range' => [65536, 8388608], 'name' => '工具结果上限'],
                'tunnel_id' => ['rule' => 'string', 'len' => [0, 80], 'required' => false, 'name' => 'Tunnel ID'],
                'runtime_key' => ['rule' => 'string', 'len' => [0, 65536], 'required' => false, 'name' => 'Runtime Key'],
                'runtime_key_action' => ['rule' => 'string', 'len' => [3, 8], 'name' => '密钥操作'],
                'context_transport' => ['rule' => 'string', 'len' => [4, 6], 'name' => '上下文传输'],
                'context_max_parts' => ['rule' => 'pint', 'range' => [1, 6], 'name' => '最大分段数'],
                'context_extended' => ['rule' => 'nnint', 'range' => [0, 1], 'name' => '扩展上下文实验'],
                'response_timeout_seconds' => ['rule' => 'pint', 'range' => [60, 86400], 'name' => '模型生成超时'],
                'context_stage_timeout_seconds' => ['rule' => 'pint', 'range' => [30, 300], 'name' => '分段确认超时'],
                'compaction_timeout_seconds' => ['rule' => 'pint', 'range' => [30, 86400], 'name' => '上下文压缩超时'],
                'skill_transport' => ['rule' => 'string', 'len' => [4, 6], 'name' => '技能传输方式'],
                'official_handoff_summary' => ['rule' => 'nnint', 'range' => [0, 1], 'name' => '官方交接摘要'],
                'steering_full_context' => ['rule' => 'nnint', 'range' => [0, 1], 'name' => '插话全量重发'],
                'web_initial_contract_template' => ['rule' => 'string', 'len' => [0, 32768], 'required' => false, 'name' => '首轮 Web 契约'],
                'web_resume_contract_template' => ['rule' => 'string', 'len' => [0, 32768], 'required' => false, 'name' => '后续 Web 契约'],
                'web_readonly_contract_template' => ['rule' => 'string', 'len' => [0, 32768], 'required' => false, 'name' => '无宿主工具 Web 契约'],
                'web_compact_contract_template' => ['rule' => 'string', 'len' => [0, 32768], 'required' => false, 'name' => '只读压缩 Web 契约'],
            ]);
        } catch (\Throwable) {
            throw new GatewayError('INVALID_SETTINGS', '请检查工具配置的数值范围和字段长度。');
        }
        $data += ['tunnel_id' => '', 'runtime_key' => ''];
        if (!in_array($data['ui_locale'], ['en', 'zh-CN'], true)) throw new GatewayError('INVALID_SETTINGS', 'Unsupported language.');
        if (!in_array($data['web_chat_mode'], ['temporary', 'regular'], true)) throw new GatewayError('INVALID_SETTINGS', 'Unsupported Web chat mode.');
        if (!in_array($data['context_transport'], ['auto', 'single'], true)) throw new GatewayError('INVALID_SETTINGS', '上下文传输请选择自动或单条。');
        if (!in_array($data['skill_transport'], ['inline', 'file'], true)) throw new GatewayError('INVALID_SETTINGS', '技能传输请选择正文或文件。');
        if ((int) $data['context_extended'] !== 0 && ($data['context_transport'] !== 'auto' || (int) $data['context_max_parts'] < 6)) {
            throw new GatewayError('INVALID_SETTINGS', '3× 上下文需要自动分段，且最大分段数必须为 6。');
        }
        $data['connector_name'] = trim((string) $data['connector_name']);
        $data['tunnel_id'] = trim((string) $data['tunnel_id']);
        if ($data['connector_name'] === '' || preg_match('/[\x00-\x1F\x7F]/u', $data['connector_name'])) {
            throw new GatewayError('INVALID_SETTINGS', '连接器名称不能为空或包含控制字符。');
        }
        if ($data['tunnel_id'] !== '' && !preg_match('/\Atunnel_[a-f0-9]{32}\z/', $data['tunnel_id'])) {
            throw new GatewayError('INVALID_SETTINGS', 'Tunnel ID 格式不正确。');
        }
        if (!in_array($data['runtime_key_action'], ['keep', 'set', 'clear'], true)) {
            throw new GatewayError('INVALID_SETTINGS', '密钥操作不受支持。');
        }
        foreach (['web_initial_contract_template' => '首轮 Web 契约',
            'web_resume_contract_template' => '后续 Web 契约',
            'web_readonly_contract_template' => '无宿主工具 Web 契约',
            'web_compact_contract_template' => '只读压缩 Web 契约'] as $field => $label) {
            $data[$field] = self::validatedContract((string) $data[$field], $field, $label);
        }

        if ((int) $data['expected_updated_at'] !== (int) $current['updated_at']) {
            throw new GatewayError('SETTINGS_CONFLICT', '设置已在其他页面更新，请重新载入。', 409);
        }
        if ($data['connector_name'] !== $current['connector_name']
            && !str_starts_with($data['connector_name'], 'RCMAKER ')) {
            throw new GatewayError('INVALID_SETTINGS', 'Connector names must start with RCMAKER followed by your custom name.');
        }
        $runtimeKeyAction = (string) $data['runtime_key_action'];
        $invalidateConnector = (string) $current['connector_name'] !== (string) $data['connector_name']
            || (string) ($current['web_chat_mode'] ?? 'temporary') !== $data['web_chat_mode']
            || (string) $current['tunnel_id'] !== (string) $data['tunnel_id']
            || $runtimeKeyAction !== 'keep';
        $connectionChanged = $invalidateConnector || (int) $current['mcp_enabled'] !== (int) $data['mcp_enabled'];
        $protected = $current['protected_runtime_key'];
        if ($data['runtime_key_action'] === 'set') {
            if ((string) $data['runtime_key'] === '') throw new GatewayError('RUNTIME_KEY_REQUIRED', '请输入 Runtime Key。');
            $protected = RuntimeCredential::protect((string) $data['runtime_key']);
        } elseif ($data['runtime_key_action'] === 'clear') {
            $protected = null;
        }
        unset($data['runtime_key'], $data['runtime_key_action'], $data['expected_updated_at']);
        $data['mcp_enabled'] = (int) $data['mcp_enabled'];
        $data['auto_approve_tool_calls'] = (int) $data['auto_approve_tool_calls'];
        foreach (['mcp_invoke_timeout_ms', 'wait_poll_interval_ms', 'max_tool_calls_per_turn', 'tool_result_max_bytes',
            'context_max_parts', 'context_extended', 'response_timeout_seconds', 'context_stage_timeout_seconds',
            'compaction_timeout_seconds', 'official_handoff_summary', 'steering_full_context', 'pro_weekly_budget',
            'auto_cleanup_inactive_tasks'] as $field) {
            $data[$field] = (int) $data[$field];
        }
        $data['protected_runtime_key'] = $protected;
        if (($current['web_chat_mode'] ?? 'temporary') !== $data['web_chat_mode']) $data['web_chat_fallback_at'] = 0;
        $data['updated_at'] = max(time(), (int) $current['updated_at'] + 1);

        Schema::transaction(function () use ($current, $data, $invalidateConnector, $connectionChanged): void {
            $db = SDB(null, 'medoo', 'sqlite');
            if ($connectionChanged && $db->table('gateway_task_turns')
                ->where('status', 'IN', ['queued', 'submit_intent', 'submitted', 'running'])->count() > 0) {
                throw new GatewayError('CONNECTION_IN_USE', 'Wait for running tasks to finish before changing the connector or tunnel credentials.', 409);
            }
            $updated = $db->table('gateway_settings')
                ->where('id', 1)->where('updated_at', (int) $current['updated_at'])->update($data);
            if (!$updated || $updated->rowCount() !== 1) {
                throw new GatewayError('SETTINGS_CONFLICT', '设置已发生变化，请重新载入。', 409);
            }
            if ($invalidateConnector) {
                $connector = $db->table('gateway_connector_state')->where('id', 1)->update([
                    'connector_name' => (string) $data['connector_name'],
                    'status' => 'unverified',
                    'verified_at' => null,
                    'runtime_generation' => bin2hex(random_bytes(16)),
                    'verified_generation' => null,
                    'probe_generation' => null,
                    'probe_token_hash' => null,
                    'probe_expires_at' => null,
                    'probe_started_at' => null,
                    'recheck_required' => 0,
                    'recheck_command_id' => null,
                    'last_error' => null,
                    'updated_at' => time(),
                ]);
                if ($connector === false) throw new GatewayError('STORAGE_UNAVAILABLE', '连接器检查状态无法重置。', 503);
            }
            if ($connectionChanged) {
                // Saving valid credentials activates the existing owner worker immediately.
                // No process or network work runs inside the HTTP transaction.
                $running = (int) $data['mcp_enabled'] === 1 && $data['tunnel_id'] !== ''
                    && is_string($data['protected_runtime_key']) && $data['protected_runtime_key'] !== '';
                $db->table('gateway_tunnel_state')->where('id', 1)->update(['desired_running' => (int) $running]);
            }
        });
        if (($current['ui_locale'] ?? '') !== $data['ui_locale']) {
            // The settings transaction has committed. A secondary banner mirror failure must
            // not falsely report that the language save failed or interfere with running tools.
            try { GatewayPresentation::publishLocale($data['ui_locale']); }
            catch (\Throwable) { error_log('[gateway-presentation] language saved; startup mirror unavailable'); }
        }
        return self::snapshot();
    }

    /**
     * Persist ordinary mode only after its real MCP probe succeeds and the original settings/runtime still match.
     * Admitted turns keep their pinned mode; later task migration uses the normal response admission path.
     * @param string $connectorName Exact configured connector tested by the probe.
     * @param string $runtimeGeneration Runtime generation that accepted its real MCP permission probe.
     * @param int $expectedRevision Settings version captured before trying the ordinary page.
     * @return void Credentials, runtime generation and existing tool tokens are unchanged.
     * @throws GatewayError If the probe proof or settings version is no longer current, or storage fails.
     */
    public static function acceptRegularChatFallback(string $connectorName, string $runtimeGeneration, int $expectedRevision): void
    {
        Schema::transaction(static function () use ($connectorName, $runtimeGeneration, $expectedRevision): void {
            $current = self::row();
            $connector = SDB(null, 'medoo', 'sqlite')->table('gateway_connector_state')->where('id', 1)->find();
            if (($current['web_chat_mode'] ?? 'temporary') !== 'temporary' || (int) $current['updated_at'] !== $expectedRevision
                || $current['connector_name'] !== $connectorName || ($connector['status'] ?? '') !== 'verified'
                || ($connector['runtime_generation'] ?? '') !== $runtimeGeneration
                || ($connector['verified_generation'] ?? '') !== $runtimeGeneration) {
                throw new GatewayError('CHATGPT_CONNECTOR_CONFIG_CHANGED', 'Connector settings changed while checking chat mode.', 409);
            }
            $updated = SDB(null, 'medoo', 'sqlite')->table('gateway_settings')->where('id', 1)
                ->where('updated_at', $expectedRevision)->where('web_chat_mode', 'temporary')->update([
                    'web_chat_mode' => 'regular', 'web_chat_fallback_at' => time(), 'updated_at' => max(time(), $expectedRevision + 1),
                ]);
            if (!$updated || $updated->rowCount() !== 1) throw new GatewayError('SETTINGS_CONFLICT', 'Settings changed; reload before checking the connector again.', 409);
        });
    }

    public static function revealRuntimeKey(): string
    {
        $row = self::internal();
        if (!is_string($row['protected_runtime_key']) || $row['protected_runtime_key'] === '') {
            throw new GatewayError('RUNTIME_KEY_REQUIRED', '尚未配置工具隧道 Runtime Key。', 422);
        }
        return RuntimeCredential::reveal($row['protected_runtime_key']);
    }

    private static function row(): array
    {
        $row = SDB(null, 'medoo', 'sqlite')->table('gateway_settings')->where('id', 1)->find();
        if (!is_array($row)) throw new GatewayError('STORAGE_UNAVAILABLE', '工具设置暂不可读取。', 503);
        return $row;
    }

    /** Resolve an empty stored override to the current code default for API readers and the Web broker. */
    private static function effectiveContract(array $row, string $field): string
    {
        $saved = (string) ($row[$field] ?? '');
        return $saved !== '' ? $saved : WebContract::defaults()[$field];
    }

    /** Validate a user-owned template before persisting it; empty input restores the code default. */
    private static function validatedContract(string $template, string $field, string $label): string
    {
        if ($template === '') return '';
        if (strlen($template) > 32768 || str_contains($template, "\0")) {
            throw new GatewayError('INVALID_SETTINGS', $label . '不能超过 32 KiB 或包含空字符。');
        }
        preg_match_all('/\{\{(.*?)\}\}/s', $template, $matches);
        $allowed = ['context_json', 'latest_user_prompt', 'turn_token', 'connector_name', 'output_control',
            'checkpoint_instructions', 'answer_instruction', 'context_only_notice', 'archive_requirement',
            'read_context_notice', 'model_display_label', 'skill_contract'];
        foreach ($matches[1] as $variable) {
            if (!in_array($variable, $allowed, true)) {
                throw new GatewayError('INVALID_SETTINGS', $label . '包含未知变量 {{' . $variable . '}}。');
            }
        }
        $literal = preg_replace('/\{\{.*?\}\}/s', '', $template);
        if (str_contains($literal, '{{') || str_contains($literal, '}}')) {
            throw new GatewayError('INVALID_SETTINGS', $label . '包含不完整的变量占位符。');
        }
        $requiredVariables = in_array($field, ['web_readonly_contract_template', 'web_compact_contract_template'], true)
            ? ['context_json'] : ['context_json', 'turn_token'];
        if (in_array($field, ['web_readonly_contract_template', 'web_compact_contract_template'], true)
            && in_array('turn_token', $matches[1], true)) {
            throw new GatewayError('INVALID_SETTINGS', $label . '没有本机工具令牌，不能包含 {{turn_token}}。');
        }
        foreach ($requiredVariables as $required) {
            if (!in_array($required, $matches[1], true)) {
                throw new GatewayError('INVALID_SETTINGS', $label . '必须包含 {{' . $required . '}}。');
            }
        }
        return $template === WebContract::defaults()[$field] ? '' : $template;
    }
}
