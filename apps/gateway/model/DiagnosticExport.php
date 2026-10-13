<?php
declare(strict_types=1);
namespace app\gateway\model;

use RC\Config;

/**
 * Collects a bounded, read-only support bundle without loading conversations or credentials.
 * The console owns this workflow; it never invokes browser, model, recovery or task mutations.
 */
final class DiagnosticExport
{
    private const FORMAT_VERSION = 1;
    private const MAX_BYTES = 4194304;
    private const MAX_JSON_BYTES = 4096;
    private const MAX_LOG_BYTES = 65536;
    private const MAX_SECONDS = 3.0;

    /** Fixed projections only. Large payload/secret columns must never be added to these lists. */
    private const TABLES = [
        'tasks' => ['gateway_tasks', 60, 'id,thread_id,status,current_epoch_id,created_at,updated_at,cancelled_at'],
        'turns' => ['gateway_task_turns', 240, 'id,task_id,epoch_id,surface_id,client_turn_id,request_hash,response_id,status,fencing_version,deadline_at,submit_intent_at,submitted_at,committed_at,retired_at,error_code,created_at,updated_at'],
        'responses' => ['gateway_response_jobs', 240, 'id,task_id,epoch_id,durable_turn_id,surface_id,model,reasoning_effort,operation_kind,round_kind,round_no,status,request_hash,previous_response_id,rolling_source_response_id,continuation_source_response_id,compact_id,command_id,input_tokens,output_started,sequence_next,terminal_type,transport_parts_total,transport_stage_sent,transport_stage_acked,transport_stage_effort,transport_final_effort,repair_count,accepted_at,submit_intent_at,created_at,started_at,finished_at,error_json,budget_json,transport_decision_json,physical_selection_json'],
        'tools' => ['gateway_tool_invocations', 400, 'id,task_id,turn_id,response_id,binding_id,call_id,wire_name,request_hash,result_hash,status,delivery_count,created_at,updated_at,delivered_at,completed_at,result_delivered_at,result_delivered_at_us'],
        'bindings' => ['gateway_mcp_bindings', 100, 'id,task_id,turn_id,current_response_id,status,activity_revision,completion_revision,required_tool_count,sent_at,started_at,expires_at,created_at,updated_at,revoked_at'],
        'checkpoints' => ['gateway_context_checkpoints', 100, 'id,task_id,source_epoch_id,source_response_id,request_hash,response_id,protocol,state,created_at,committed_at,consumed_at'],
        'handoffs' => ['gateway_compaction_handoffs', 100, 'compact_response_id,checkpoint_id,task_id,source_response_id,state,created_at,expires_at,submit_intent_at,accepted_at,consumed_at'],
        'surfaces' => ['gateway_browser_surfaces', 80, 'id,task_id,epoch_id,status,generation,fencing_version,last_observed_at,created_at,updated_at,retired_at'],
        'web_messages' => ['gateway_web_messages', 240, 'id,task_id,turn_id,response_id,direction,kind,stage_no,status,byte_count,created_at,updated_at'],
        'browser' => ['gateway_browser_state', 1, 'id,status,browser_kind,surface_generation,lease_mode,viewport_width,viewport_height,screenshot_sequence,worker_heartbeat,chatgpt_observed_at,setup_completed,updated_at'],
        'tunnel' => ['gateway_tunnel_state', 1, 'id,status,network_revision,binary_version,updated_at'],
        'connector' => ['gateway_connector_state', 1, 'id,status,verified_at,updated_at'],
        'network' => ['gateway_network_state', 1, 'id,saved_revision,applied_php_revision,last_apply_revision,probe_heartbeat'],
        'network_tests' => ['gateway_network_tests', 20, 'id,revision,target_id,status,created_at,started_at,finished_at,result_json'],
        'settings' => ['gateway_settings', 1, 'id,mcp_enabled,mcp_invoke_timeout_ms,wait_poll_interval_ms,max_tool_calls_per_turn,tool_result_max_bytes,context_max_parts,context_transport,context_extended,response_timeout_seconds,context_stage_timeout_seconds,compaction_timeout_seconds,ui_locale,updated_at'],
        'catalog' => ['gateway_model_catalog', 1, 'id,observed_at,browser_generation,native_verified_at'],
        'schema' => ['gateway_schema', 100, 'version'],
        // High-volume timeline evidence comes last so it cannot crowd out recovery/health state.
        'events' => ['gateway_task_events', 1200, 'id,task_id,turn_id,response_id,surface_id,event_type,data_json,created_at'],
    ];

    /** Safe nested diagnostic fields; arbitrary event/exception text is deliberately excluded. */
    private const DETAIL_FIELDS = [
        'at', 'pid', 'event', 'endpoint', 'method', 'tool', 'tool_name', 'tool_names', 'wire_name',
        'phase', 'code', 'error_code', 'error_type', 'error_class', 'class', 'file', 'line',
        'http_status', 'http_code', 'status_code', 'curl_code', 'exit_code', 'errno', 'error',
        'status', 'state', 'type', 'kind', 'mode', 'reason', 'protocol', 'version',
        'task_id', 'turn_id', 'response_id', 'source_response_id', 'checkpoint_id', 'surface_id',
        'binding_id', 'call_id', 'epoch_id', 'source_epoch_id', 'current_response_id', 'client_turn_id',
        'request_hash', 'source_request_hash', 'result_hash', 'completion_hash',
        'binding_status', 'binding_expires_at', 'binding_current_response_id', 'binding_turn_id',
        'tool_count', 'argument_count', 'input_bytes', 'output_bytes', 'bytes', 'event_count',
        'capability_format_valid', 'capability_length', 'content_length', 'duration_ms', 'elapsed_ms',
        'sent', 'accepted', 'completed', 'terminal_seen', 'downstream_open', 'upstream_status',
        'response_completed', 'response_failed', 'cancelled', 'retry_count', 'retryable', 'attempt',
        'stage_sent', 'stage_acked', 'parts', 'total_parts', 'stage_no', 'stage', 'count',
        'prompt_tokens', 'prompt_chars', 'attachment_tokens', 'input_tokens', 'output_tokens',
        'message_tokens', 'message_chars', 'context_tokens', 'compact_tokens', 'base_compact_tokens',
        'soft_inline_tokens', 'context_multiplier', 'context_effective_ratio', 'budget_policy',
        'budget_reason', 'model', 'model_family', 'family', 'effort', 'reasoning_effort',
        'selected_model', 'selected_effort', 'execution_effort', 'transport_effort',
        'extension_requested', 'extension_allowed', 'compaction_capacity_effort',
        'operation_kind', 'network_revision', 'generation', 'fencing_version', 'heartbeat',
        'created_at', 'updated_at', 'started_at', 'finished_at', 'deadline_at', 'expires_at',
        'success', 'ok', 'ready', 'reachable', 'tls_verified', 'timed_out', 'connect_ms', 'total_ms',
        'canceled', 'generation_running', 'response_present', 'send_button_visible', 'send_button_disabled',
        'completion_action_visible', 'content_present', 'transport_recovery_visible', 'transport_failed',
        'transport_settled', 'ui_receipts_settled', 'request_finished', 'fence_drained',
        'source_receipt_present', 'has_artifacts', 'answer_root_count', 'commentary_root_count',
        'markdown_chars', 'visible_chars', 'candidate_age_ms', 'mcp_revision',
        'request_fingerprint', 'response_fingerprint', 'proxy_connect_status', 'curl_result',
        'request_bytes', 'body_bytes', 'stream_started', 'terminal_type', 'done_seen',
        'pause_count', 'resume_count', 'queue_peak', 'client_open', 'abort_reason',
        'flushed', 'is_error', 'tool_error_code', 'result_delivered_at', 'result_delivered_at_us',
        'page_error_code', 'stopped_thinking_visible', 'network_retry_available',
        'tool_invocations_pending', 'tool_receipts_pending', 'mcp_activities_pending',
    ];

    /**
     * Return a bounded support snapshot. Missing/corrupt storage becomes an explicit warning.
     * @param int $hours One of 1, 24, 72, 168; singleton health snapshots ignore the time filter.
     * @param ?string $taskId Optional validated task ID; global health/logs remain included.
     * @return array<string,mixed> Safe structured evidence, not a database backup.
     * @throws GatewayError For an unsupported time window.
     */
    public static function collect(int $hours = 24, ?string $taskId = null): array
    {
        if (!in_array($hours, [1, 24, 72, 168], true)) {
            throw new GatewayError('DIAGNOSTICS_INVALID_RANGE', 'Unsupported diagnostic time range.', 400);
        }
        $started = microtime(true);
        $now = time();
        $since = $now - $hours * 3600;
        $bundle = [
            'manifest' => [
                'format' => 'rcmaker-diagnostics', 'version' => self::FORMAT_VERSION,
                'created_at' => gmdate('c', $now), 'timezone' => date_default_timezone_get(),
                'utc_offset' => date('P', $now), 'timestamps' => 'Unix seconds unless an ISO-8601 string is present',
                'since' => gmdate('c', $since), 'hours' => $hours, 'task_id' => $taskId,
                'sampling' => 'Newest bounded candidates per table, then time filtered; not a transactional snapshot.',
                'max_uncompressed_bytes' => self::MAX_BYTES,
                'excluded' => ['database files', 'credentials', 'cookies', 'capability tokens', 'profiles',
                    'conversation text', 'contracts', 'tool arguments/results', 'summaries', 'attachments',
                    'URLs', 'absolute paths', 'unrecognized free-form log text'],
                'sections' => [], 'warnings' => [],
            ],
            'environment' => self::environment(), 'records' => [], 'logs' => [],
        ];
        // Reserve room for metadata and file logs even when task evidence reaches its cap.
        $remaining = self::MAX_BYTES - 131072;
        $database = (string) Config::get('gateway', 'database_file');
        if (!is_file($database)) {
            $bundle['manifest']['warnings'][] = 'database_missing';
        } else {
            foreach (self::TABLES as $name => $spec) {
                if (microtime(true) - $started > self::MAX_SECONDS || $remaining < 524288) {
                    $bundle['manifest']['warnings'][] = 'collection_budget_reached';
                    break;
                }
                try {
                    [$rows, $meta] = self::table($spec, $since, $taskId);
                    $kept = [];
                    foreach (array_reverse($rows) as $row) {
                        $bytes = strlen(self::encode($row)) + 1;
                        if ($bytes > $remaining - 524288) { $meta['limited'] = true; break; }
                        $remaining -= $bytes;
                        $kept[] = $row;
                    }
                    $meta['exported'] = count($kept);
                    $bundle['records'][$name] = array_reverse($kept);
                    $bundle['manifest']['sections'][$name] = $meta;
                } catch (\Throwable $error) {
                    // Never export an SQL exception message, which may embed values or paths.
                    $bundle['manifest']['sections'][$name] = ['available' => false, 'error_class' => get_class($error)];
                    $bundle['manifest']['warnings'][] = $name . '_unavailable';
                }
            }
        }
        // File evidence is independent of the database so damaged/missing databases remain diagnosable.
        foreach (self::logFiles() as $label => $path) {
            if ($remaining < 8192) { $bundle['manifest']['warnings'][] = 'log_budget_reached'; break; }
            [$entries, $meta] = self::logTail($path, $since, min(self::MAX_LOG_BYTES, $remaining));
            $bundle['logs'][$label] = ['metadata' => $meta, 'entries' => $entries];
            $remaining -= strlen(self::encode($bundle['logs'][$label]));
        }
        $bundle['manifest']['collection_ms'] = (int) round((microtime(true) - $started) * 1000);
        return $bundle;
    }

    /**
     * Make an in-memory ZIP (JSON fallback without ext-zip); remove its private temporary file.
     * @param array<string,mixed> $bundle Output of collect().
     * @return array{body:string,content_type:string,filename:string}
     * @throws GatewayError If encoding, local storage or archive creation fails.
     */
    public static function archive(array $bundle): array
    {
        $json = self::encode($bundle);
        if (strlen($json) > self::MAX_BYTES) {
            throw new GatewayError('DIAGNOSTICS_TOO_LARGE', 'Diagnostic export exceeded its size limit.', 503);
        }
        $name = 'rcmaker-diagnostics-' . gmdate('Ymd-His');
        if (!class_exists(\ZipArchive::class)) {
            return ['body' => $json, 'content_type' => 'application/json', 'filename' => $name . '.json'];
        }
        $root = (string) Config::get('gateway', 'data_directory');
        $directory = $root . '/diagnostics/tmp';
        if (is_link($root . '/diagnostics') || is_link($directory)
            || (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory))) {
            throw new GatewayError('DIAGNOSTICS_STORAGE_UNAVAILABLE', 'Cannot create the diagnostic export.', 503);
        }
        $canonicalRoot = realpath($root);
        $canonicalDirectory = realpath($directory);
        if ($canonicalRoot === false || $canonicalDirectory === false
            || !str_starts_with($canonicalDirectory, $canonicalRoot . DIRECTORY_SEPARATOR)) {
            throw new GatewayError('DIAGNOSTICS_STORAGE_UNAVAILABLE', 'Cannot create the diagnostic export.', 503);
        }
        $path = @tempnam($directory, 'diag-');
        if ($path === false || realpath(dirname($path)) !== realpath($directory)) {
            if (is_string($path)) @unlink($path);
            throw new GatewayError('DIAGNOSTICS_STORAGE_UNAVAILABLE', 'Cannot create the diagnostic export.', 503);
        }
        @chmod($path, 0600);
        $zip = new \ZipArchive();
        $opened = false;
        try {
            if ($zip->open($path, \ZipArchive::OVERWRITE) !== true) throw new \RuntimeException('zip_open');
            $opened = true;
            $readme = "RCMAKER diagnostic bundle / RCMAKER 运行诊断包\n\n"
                . "Start with manifest.json, then correlate task_id / turn_id / response_id across records.json and logs.json.\n"
                . "先看 manifest.json，再按任务、回合和响应编号关联 records.json 与 logs.json。\n\n"
                . "This is a bounded, read-only sample, not a backup or a transactional snapshot.\n"
                . "这是有大小上限的只读采样，不是备份，也不是同一事务内的一致性快照。\n"
                . "Check section limits, warnings, missing files and collection timestamps before drawing conclusions.\n"
                . "请留意采样上限、缺失项和时间；运行中的任务可能在采集期间继续变化。\n\n"
                . "Raw conversations, tool arguments/results, summaries, attachments, credentials and browser profiles are excluded.\n"
                . "不包含对话正文、工具参数或返回正文、摘要正文、附件、凭据及浏览器登录资料。\n"
                . "Logs contain only recognized structural fields and error signatures. Unknown free text is omitted.\n"
                . "文件日志仅保留可识别的结构字段与错误特征，未知自由文本已省略。\n"
                . "The file is downloaded locally; nothing is automatically uploaded. Review before sharing publicly.\n"
                . "文件仅下载到本机，不会自动上传；公开分享前请先检查内容。\n";
            if (!$zip->addFromString('README.txt', $readme)) throw new \RuntimeException('zip_write');
            foreach (['manifest', 'environment', 'records', 'logs'] as $section) {
                if (!$zip->addFromString($section . '.json', self::encode($bundle[$section] ?? []))) {
                    throw new \RuntimeException('zip_write');
                }
            }
            $closed = $zip->close();
            $opened = false;
            if (!$closed) throw new \RuntimeException('zip_close');
            $body = file_get_contents($path);
            if (!is_string($body) || $body === '' || strlen($body) > self::MAX_BYTES) throw new \RuntimeException('zip_read');
            return ['body' => $body, 'content_type' => 'application/zip', 'filename' => $name . '.zip'];
        } catch (\Throwable) {
            throw new GatewayError('DIAGNOSTICS_ARCHIVE_FAILED', 'Cannot create the diagnostic archive.', 503);
        } finally {
            if ($opened) $zip->close();
            @unlink($path);
        }
    }

    /** Read system facts only; never environment variables, phpinfo, hostnames or account identifiers. */
    private static function environment(): array
    {
        $extensions = [];
        foreach (['pdo_sqlite', 'sqlite3', 'curl', 'zip', 'openssl', 'mbstring', 'sodium'] as $extension) {
            $extensions[$extension] = extension_loaded($extension);
        }
        $release = (array) Config::get('release');
        $identity = [];
        foreach (['tag', 'commit', 'php', 'encrypted', 'platform', 'arch'] as $key) {
            $identity[$key] = self::scalar($release[$key] ?? null);
        }
        $database = (string) Config::get('gateway', 'database_file');
        $sizes = [];
        foreach (['main' => '', 'wal' => '-wal', 'shm' => '-shm'] as $key => $suffix) {
            $sizes[$key] = is_file($database . $suffix) ? (int) @filesize($database . $suffix) : null;
        }
        return [
            'release' => $identity, 'php' => PHP_VERSION, 'os' => PHP_OS_FAMILY,
            'arch' => php_uname('m'), 'sapi' => PHP_SAPI, 'packaged' => is_phar(),
            'extensions' => $extensions, 'memory_limit' => ini_get('memory_limit'),
            'database_bytes' => $sizes,
        ];
    }

    /**
     * SQLite-specific bounded column projection uses the framework-managed DB connection.
     * AutoForm/SDB cannot cap individual TEXT/BLOB reads or inspect old schema columns. Each
     * statement finishes before file work; no transaction, write lock, migration or recovery runs.
     */
    private static function table(array $spec, int $since, ?string $taskId): array
    {
        [$table, $limit, $fields] = $spec;
        $db = DB('medoo', 'sqlite');
        $columns = $db->query('PRAGMA table_info("' . $table . '")')->fetchAll(\PDO::FETCH_ASSOC);
        if ($columns === []) throw new \RuntimeException('table_missing');
        $available = array_column($columns, 'name');
        $selected = array_values(array_intersect(explode(',', $fields), $available));
        $expressions = [];
        foreach ($selected as $field) {
            $cap = str_ends_with($field, '_json') ? self::MAX_JSON_BYTES : 512;
            $expressions[] = 'CASE WHEN typeof("' . $field . '") IN (\'integer\',\'real\',\'null\') THEN "'
                . $field . '" ELSE substr(CAST("' . $field . '" AS BLOB),1,' . ($cap + 1) . ') END AS "' . $field . '"';
        }
        if ($expressions === []) throw new \RuntimeException('columns_missing');
        $where = '';
        $params = [];
        if ($taskId !== null && ($table === 'gateway_tasks' || in_array('task_id', $available, true))) {
            $where = ' WHERE "' . ($table === 'gateway_tasks' ? 'id' : 'task_id') . '"=:task';
            $params[':task'] = $taskId;
        }
        // Bound candidates BEFORE time filtering; an empty time range must not scan a huge ledger.
        $rows = $db->query('SELECT ' . implode(',', $expressions) . ' FROM "' . $table . '"'
            . $where . ' ORDER BY rowid DESC LIMIT ' . ($limit + 1), $params)->fetchAll(\PDO::FETCH_ASSOC);
        $meta = ['available' => true, 'candidate_limit' => $limit, 'limited' => count($rows) > $limit,
            'missing_columns' => array_values(array_diff(explode(',', $fields), $selected)), 'outside_window' => 0];
        $output = [];
        foreach (array_slice($rows, 0, $limit) as $row) {
            $at = max((int) ($row['created_at'] ?? 0), (int) ($row['updated_at'] ?? 0),
                (int) ($row['finished_at'] ?? 0), (int) ($row['consumed_at'] ?? 0));
            if ($limit > 1 && $at > 0 && $at < $since) { $meta['outside_window']++; continue; }
            $safe = [];
            foreach ($row as $key => $value) {
                if (str_ends_with($key, '_json')) {
                    $safe[substr($key, 0, -5)] = self::details($value);
                } else {
                    $safe[$key] = self::scalar($value);
                }
            }
            $output[] = $safe;
        }
        return [array_reverse($output), $meta];
    }

    /** Project known structural JSON recursively; never return raw text when parsing fails. */
    private static function details(mixed $json, int $depth = 0): array
    {
        if ($depth > 4) return ['omitted' => 'depth_limit'];
        if (is_string($json)) {
            if (strlen($json) > self::MAX_JSON_BYTES) return ['omitted' => 'byte_limit'];
            try { $json = json_decode($json, true, 16, JSON_THROW_ON_ERROR); }
            catch (\Throwable) { return ['omitted' => 'unreadable_json']; }
        }
        if (!is_array($json)) return [];
        $safe = [];
        $omitted = 0;
        foreach ($json as $key => $value) {
            if (!in_array($key, self::DETAIL_FIELDS, true)) { $omitted++; continue; }
            $safe[$key] = is_array($value) ? self::details($value, $depth + 1) : self::scalar($value);
        }
        if ($omitted) $safe['omitted_fields'] = $omitted;
        return $safe;
    }

    /** Keep machine labels/IDs and numeric facts, not arbitrary text, paths, URLs or secret formats. */
    private static function scalar(mixed $value): mixed
    {
        if ($value === null || is_bool($value) || is_int($value) || is_float($value)) return $value;
        if (!is_string($value) || strlen($value) > 512) return '[omitted]';
        if ($value === '') return '';
        if (preg_match('~(?:https?://|[A-Za-z]:[\\\\/]|^/|^sk-|^Bearer|@)~i', $value)) return '[omitted]';
        return preg_match('~\A[A-Za-z0-9_.:/,+\\\\-]{1,512}\z~D', $value) ? $value : '[omitted]';
    }

    /** Fixed runtime log paths only; never traverse profiles, attachments or arbitrary user paths. */
    private static function logFiles(): array
    {
        $tunnel = rtrim((string) Config::get('gateway', 'tunnel_runtime_root'), '/\\') . '/logs';
        $browser = rtrim((string) Config::get('gateway', 'browser_log_dir'), '/\\');
        $runtime = runtime_path() . '/logs';
        return [
            'mcp' => $tunnel . '/mcp-events.jsonl', 'mcp_previous' => $tunnel . '/mcp-events.jsonl.1',
            'native' => $tunnel . '/native-events.jsonl', 'native_previous' => $tunnel . '/native-events.jsonl.1',
            'api' => $runtime . '/RC_GATEWAY_API.log', 'api_legacy' => $runtime . '/RC_GETWAY_API.log',
            'browser_runtime' => $browser . '/runtime-errors.log', 'browser_stderr' => $browser . '/stderr.log',
            'chrome' => $browser . '/chrome.log',
        ];
    }

    /** Read only a byte-bounded tail, excluding a cut first/last record and symlinked files. */
    private static function logTail(string $path, int $since, int $maxBytes): array
    {
        $meta = ['available' => false, 'tail_limit_bytes' => $maxBytes];
        if (!is_file($path) || is_link($path)) return [[], $meta];
        $file = @fopen($path, 'rb');
        if ($file === false) return [[], $meta + ['error' => 'unreadable']];
        try {
            $stat = fstat($file);
            $size = (int) ($stat['size'] ?? 0);
            $offset = max(0, $size - $maxBytes);
            if (fseek($file, $offset) !== 0) return [[], $meta + ['error' => 'seek_failed']];
            $text = (string) fread($file, min($size, $maxBytes));
        } finally { fclose($file); }
        $lines = explode("\n", $text);
        if ($offset > 0) array_shift($lines);
        // Files are concurrently appended; never interpret an incomplete trailing JSON/text line.
        array_pop($lines);
        $entries = [];
        $skipped = 0;
        $outputBytes = 0;
        $outputLimited = false;
        foreach (array_reverse($lines) as $line) {
            if (strlen($line) > self::MAX_JSON_BYTES) { $skipped++; continue; }
            $decoded = json_decode(trim($line), true);
            if (is_array($decoded)) {
                if (isset($decoded['at']) && is_numeric($decoded['at']) && (int) $decoded['at'] < $since) continue;
                $entry = self::details($decoded);
            } else {
                $entry = self::logSignals($line);
                if (isset($entry['at']) && $entry['at'] < $since) continue;
            }
            if ($entry === [] || array_keys($entry) === ['omitted_fields']) { $skipped++; continue; }
            $bytes = strlen(self::encode($entry)) + 1;
            if ($outputBytes + $bytes > $maxBytes - 1024) { $outputLimited = true; break; }
            $outputBytes += $bytes;
            $entries[] = $entry;
        }
        $meta = ['available' => true, 'file_bytes' => $size, 'modified_at' => (int) ($stat['mtime'] ?? 0),
            'tail_limit_bytes' => $maxBytes, 'tail_limited' => $offset > 0,
            'output_limited' => $outputLimited,
            'exported' => count($entries), 'unrecognized_or_large_lines' => $skipped,
            'free_text' => 'omitted'];
        return [array_reverse($entries), $meta];
    }

    /** Extract known error signatures from free-form runtime output instead of exporting raw lines. */
    private static function logSignals(string $line): array
    {
        $signals = [];
        foreach ([
            'sqlite_busy' => '/database (?:is )?locked|SQLITE_BUSY|SQLITE_LOCKED/i',
            'stream_interrupted' => '/stream (?:disconnected|closed)|connection.*(?:interrupted|reset)|连接已中断/i',
            'timeout' => '/timed?\s*out|timeout|超时/i',
            'process_exit' => '/stopped unexpectedly|child exited|process.*exit/i',
            'php_error' => '/PHP (?:Fatal error|Warning|Parse error|Deprecated)/i',
            'permission_denied' => '/permission denied|access denied|拒绝访问/i',
        ] as $code => $pattern) {
            if (preg_match($pattern, $line)) $signals[] = $code;
        }
        if (preg_match('/\b(?:HTTP(?:\/\d(?:\.\d)?)?|status)\s*[:=]?\s*([45]\d\d)\b/i', $line, $match)) {
            $signals[] = 'http_' . $match[1];
        }
        $entry = $signals === [] ? [] : ['signals' => $signals];
        foreach (['phase', 'class', 'file', 'line', 'code', 'exitcode'] as $field) {
            if (preg_match('/\b' . $field . '=([A-Za-z0-9_\\\\.\-]{1,100})(?:\s|$)/', $line, $match)) {
                $entry[$field] = self::scalar($match[1]);
            }
        }
        if ($entry !== [] && preg_match('/\b(20\d\d-\d\d-\d\d[T ]\d\d:\d\d:\d\d(?:[+-]\d\d:\d\d|Z)?)/', $line, $match)) {
            $at = strtotime($match[1]);
            if ($at !== false) $entry['at'] = $at;
        }
        return $entry;
    }

    /** Compact UTF-8 JSON keeps the same byte accounting on disk and in memory. */
    private static function encode(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
    }
}
