<?php
declare(strict_types=1);
namespace app\gateway\model;

use app\gateway\database\Schema;

/** MCP JSON-RPC contract shared by the tunnel stdio child and deterministic acceptance tests. */
final class McpProtocol
{
    public const PROTOCOL_VERSION = '2025-11-25';
    private const SUPPORTED_VERSIONS = ['2025-11-25', '2025-06-18'];
    private const MAX_WRITE_STDIN_YIELD_MS = 60000;
    private const WRITE_STDIN_YIELD_RESERVE_MS = 30000;
    private const MIN_WRITE_STDIN_YIELD_RESERVE_MS = 5000;
    private const AGENT_WAIT_POLL_MS = 30000;
    // Keep each tunnel command comfortably below its upstream response deadline.
    // Native execution/approval remains owned by the existing durable turn.
    private const HOST_RPC_SLICE_MS = 60000;
    // A page of native tool descriptions can exceed functions.exec's 10k-token default.
    private const NESTED_CATALOG_MAX_OUTPUT_TOKENS = 50000;
    private const AGENT_WAIT_NAMES = [
        'multi_agent_v1__wait_agent', 'multi_agent_v2__wait_agent', 'collaboration__wait_agent',
    ];
    private const AGENT_WAIT_TRANSPORT_RULE = 'ChatGPT Web transport rule: wait for exactly 30 seconds per call, matching the Codex default, then release the MCP channel so spawned Web agents can use their own tools. A wait timeout is not task completion; check agent progress and wait again if needed. Keep the native tool\'s declared arguments.';
    /** Internal stdio receipt only; never serialized into the MCP response. */
    private static ?array $hostReceipt = null;
    /** Absolute deadline for this MCP request, including claim and inventory preparation. */
    private static ?float $rpcDeadlineAt = null;
    /** Stable for one MCP stdio connection, distinct across independent connections. */
    private static ?string $sessionScope = null;

    /** Set the parent stdio connection identity before a tool worker handles its request. */
    public static function setSessionScope(?string $scope): void
    {
        if ($scope !== null && preg_match('/\A[a-f0-9]{32}\z/', $scope) !== 1) {
            throw new \InvalidArgumentException('Invalid MCP session scope');
        }
        self::$sessionScope = $scope;
    }

    /** One-shot metadata for the stdio writer to acknowledge only after its response frame flushes. */
    public static function takeHostReceipt(): ?array
    {
        $receipt = self::$hostReceipt;
        self::$hostReceipt = null;
        return $receipt;
    }

    /**
     * Dispatch one MCP request. Proven retired steering capabilities receive a terminal
     * control notice without regaining host access; unknown capabilities remain errors.
     * @return array<string,mixed>|null Null means JSON-RPC notification: no reply frame.
     */
    public static function handle(array $request): ?array
    {
        self::$hostReceipt = null;
        self::$rpcDeadlineAt = null;
        $startedAt = microtime(true);
        Schema::initialize();
        $jsonrpc = $request['jsonrpc'] ?? null;
        $method = $request['method'] ?? null;
        $hasId = array_key_exists('id', $request);
        $id = $hasId ? $request['id'] : null;
        if ($jsonrpc !== '2.0' || !is_string($method) || $method === '') {
            return self::rpcError($id, -32600, 'Invalid Request');
        }
        $params = $request['params'] ?? [];
        if ($params instanceof \stdClass) $params = get_object_vars($params);
        if (!is_array($params)) return self::rpcError($id, -32602, 'Invalid params');

        if (!$hasId) {
            if ($method === 'notifications/initialized' || $method === 'notifications/cancelled') return null;
            return null;
        }
        self::$rpcDeadlineAt = $startedAt + self::HOST_RPC_SLICE_MS / 1000;
        try {
            return match ($method) {
                'initialize' => self::initialize($id, $params),
                'ping' => self::ok($id, new \stdClass()),
                'tools/list' => self::ok($id, ['tools' => self::tools()]),
                'tools/call' => self::toolCall($id, $params),
                default => self::rpcError($id, -32601, 'Method not found'),
            };
        } catch (GatewayError $error) {
            if ($method === 'tools/call') {
                $arguments = $params['arguments'] ?? [];
                if ($arguments instanceof \stdClass) $arguments = get_object_vars($arguments);
                $token = is_array($arguments) ? ($arguments['turn_token'] ?? null) : null;
                $retired = false;
                if ($error->errorCode === 'mcp_capability_invalid' && is_string($token)) {
                    // An unavailable advisory journal must preserve the original fenced error.
                    try { $retired = ToolBroker::isRetiredSteeringToken($token); }
                    catch (\Throwable) {}
                }
                if ($retired) {
                    $notice = ['ok' => false, 'status' => 'superseded', 'dispatched' => false,
                        'instruction' => 'This Web generation was superseded by a newer user instruction. This request did not start a host action. Stop this generation and do not retry the action or guess another turn_token. The gateway will deliver the new instruction with its own capability. Previously accepted host calls and their exact receipts are preserved.'];
                    return self::ok($id, ['content' => [['type' => 'text', 'text' => json_encode($notice,
                        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)]],
                        'structuredContent' => $notice, 'isError' => false]);
                }
                return self::ok($id, [
                    'content' => [['type' => 'text', 'text' => $error->getMessage()]],
                    'structuredContent' => ['ok' => false, 'code' => $error->errorCode,
                        'message' => $error->getMessage()],
                    'isError' => true,
                ]);
            }
            return self::rpcError($id, -32000, $error->getMessage(), ['code' => $error->errorCode]);
        } catch (\Throwable) {
            if ($method === 'tools/call') {
                return self::ok($id, [
                    'content' => [['type' => 'text', 'text' => '本机工具桥未能完成请求。']],
                    'structuredContent' => ['ok' => false, 'code' => 'MCP_INTERNAL_ERROR'],
                    'isError' => true,
                ]);
            }
            return self::rpcError($id, -32603, 'Internal error');
        } finally {
            // A reusable stdio process must not lend an old RPC's deadline to another call.
            self::$rpcDeadlineAt = null;
        }
    }

    /** Negotiate the existing synchronous tool ABI and support catalog refresh notifications. */
    private static function initialize(mixed $id, array $params): array
    {
        $requested = $params['protocolVersion'] ?? null;
        if (!is_string($requested) || !in_array($requested, self::SUPPORTED_VERSIONS, true)) {
            return self::rpcError($id, -32602, 'Unsupported MCP protocol version', [
                'supported' => self::SUPPORTED_VERSIONS,
            ]);
        }
        return self::ok($id, [
            'protocolVersion' => $requested,
            'capabilities' => ['tools' => ['listChanged' => true]],
            'serverInfo' => ['name' => 'rcmaker-codex-bridge', 'version' => '1.0.0'],
        ]);
    }

    private static function toolCall(mixed $id, array $params): array
    {
        $name = $params['name'] ?? null;
        $arguments = $params['arguments'] ?? [];
        if ($arguments instanceof \stdClass) $arguments = get_object_vars($arguments);
        if (!is_string($name) || !is_array($arguments)) return self::rpcError($id, -32602, 'Invalid tool call');
        $result = match ($name) {
            'codex_read_file' => self::readFileTool($id, $arguments),
            'codex_exec' => self::execTool($id, $arguments),
            'codex_write_stdin' => self::writeStdinTool($id, $arguments),
            'codex_apply_patch' => self::applyPatchTool($id, $arguments),
            'codex_view_image' => self::viewImageTool($id, $arguments),
            'codex_work_update' => self::workUpdateTool($id, $arguments),
            'codex_turn_complete' => self::deprecatedNormalCompleteTool($arguments),
            'codex_tool_inventory' => self::inventoryTool($id, $arguments),
            'codex_context_read' => self::contextReadTool($arguments),
            'codex_tool_call' => self::invokeTool($id, $arguments),
            default => throw new GatewayError('tool_not_found', '未知的 RCMAKER Codex 工具桥操作。', 404),
        };
        if (isset($result['__mcp_result']) && is_array($result['__mcp_result'])) {
            return self::ok($id, $result['__mcp_result']);
        }
        $text = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        return self::ok($id, [
            'content' => [['type' => 'text', 'text' => $text]],
            'structuredContent' => $result,
        ]);
    }

    /** Task-scoped original history lookup; never dispatches a local host operation. */
    private static function contextReadTool(array $arguments): array
    {
        $token = self::turnToken($arguments);
        self::claimToolTurn($token);
        $activity = ToolBroker::beginActivity($token);
        try {
            unset($arguments['turn_token']);
            $result = ToolBroker::readContext($token, $arguments);
            if ($result === null) throw new GatewayError('context_read_unavailable', '本回合没有归档原文读取能力。', 403);
            return $result;
        } finally {
            ToolBroker::completeActivity((string)$activity['binding_id'], (string)$activity['activity_id']);
        }
    }

    /** The Web model declares its task interpretation through a connector report tool call. */
    private static function workUpdateTool(mixed $rpcId, array $arguments): array
    {
        $token = self::turnToken($arguments);
        $phase = $arguments['phase'] ?? null;
        $summary = $arguments['summary'] ?? null;
        $remaining = $arguments['remaining'] ?? null;
        if (!is_string($phase) || !is_string($summary) || !is_array($remaining) || !array_is_list($remaining)) {
            throw new GatewayError('work_update_invalid', '进度工具需要阶段、公开步骤说明和剩余事项数组。', 400);
        }
        self::claimToolTurn($token);
        $activity = ToolBroker::beginActivity($token);
        try {
            return ToolBroker::recordWorkUpdate($token, $phase, $summary, $remaining,
                self::rpcCallKey($token, $rpcId, 'codex_work_update'));
        } finally {
            ToolBroker::completeActivity((string) $activity['binding_id'], (string) $activity['activity_id']);
        }
    }

    /** Cached Connector catalogs may still offer the old completion action. It cannot close a normal Web turn. */
    private static function deprecatedNormalCompleteTool(array $arguments): array
    {
        $token = self::turnToken($arguments);
        self::claimToolTurn($token);
        return ['accepted'=>false, 'turn_closed'=>false,
            'message'=>'This normal Codex Web turn finishes through the ordinary assistant answer. Continue any needed work, then write that answer after the last required tool result.'];
    }

    /** Discover current tools; an exact bound name needs no native registry execution. */
    private static function inventoryTool(mixed $rpcId, array $arguments): array
    {
        $token = self::turnToken($arguments);
        $exact = array_key_exists('exact', $arguments) ? $arguments['exact'] : false;
        if (!is_bool($exact) || ($exact && (!is_string($arguments['query'] ?? null)
            || trim($arguments['query']) === ''))) {
            throw new GatewayError('tool_arguments_invalid', 'exact must be a boolean; exact lookup requires a nonempty query.', 400);
        }
        if (str_starts_with($token, 'probe_')) {
            $query = isset($arguments['query']) && is_string($arguments['query']) ? trim($arguments['query']) : '';
            if ($query !== 'RCMAKER_PERMISSION_PROBE') {
                throw new GatewayError('mcp_probe_tool_invalid', '连接器权限探针操作无效。', 409);
            }
            $probe = ConnectorState::acceptProbe($token);
            return [
                'ok' => true,
                'tools' => [],
                'total' => 0,
                'next_offset' => null,
                'reachability_verified' => (bool) ($probe['started'] ?? false),
                'permission_verified' => false,
                'duplicate' => (bool) ($probe['duplicate'] ?? false),
            ];
        }
        self::claimToolTurn($token);
        $activity = ToolBroker::beginActivity($token);
        try {
            $inventory = ToolBroker::inventorySnapshot($token);
            $outer = $inventory['tools'];
            $query = isset($arguments['query']) && is_string($arguments['query']) ? trim($arguments['query']) : '';
            if (!$exact) $query = mb_strtolower($query);
            $search = self::inventorySearchPlan($query);
            $tools = $exact
                ? array_values(array_filter($outer, static fn(array $tool): bool => $tool['wire_name'] === $query))
                : self::searchInventoryTools($outer, $search);
            $offset = isset($arguments['offset']) && is_int($arguments['offset']) ? max(0, min(100000, $arguments['offset'])) : 0;
            $limit = isset($arguments['limit']) && is_int($arguments['limit']) ? max(1, min(50, $arguments['limit'])) : 20;
            $includeSchema = !array_key_exists('include_schema', $arguments) || $arguments['include_schema'] === true;
            $total = count($tools);
            $page = array_map(
                static fn(array $tool): array => self::inventoryPageEntry($tool, $includeSchema),
                array_slice($tools, $offset, $limit)
            );
            // Reuse this capability's snapshot, not a process-wide catalog or another
            // binding. A bound exact match is authoritative even on later pages.
            $gateway = $exact && $total > 0 ? null : self::execGatewayIn($outer);
            if ($gateway !== null) {
                $excluded = $inventory['bound_wire_names'];
                $nestedOffset = max(0, $offset - $total);
                $nestedLimit = max(0, $limit - count($page));
                $callKey = self::rpcCallKey($token, $rpcId, 'codex_tool_inventory:nested');
                // Identify this catalog within native status headers, while keeping exact
                // RPC retries on the same generated program and idempotency key.
                $marker = 'rcmaker-tool-catalog:' . substr(hash_hmac('sha256', $callKey, $token), 0, 32) . ':';
                $program = self::nestedCatalogProgram($query, $nestedOffset, $nestedLimit, $excluded, $marker, $exact);
                $native = self::invokeResolvedTool($token, $gateway, $callKey, null, $program);
                if (!empty($native['paused'])) {
                    // Recovery, a queued user update or checkpoint deliberately prevents new discovery.
                    // Its control notice is not a malformed native catalog or a lost link.
                    return $native + ['available_tools'=>$page];
                }
                if (!empty($native['pending'])) {
                    // This is a live discovery invocation, not an invalid catalog. Its
                    // eventual native page contains the actual deferred names/descriptions.
                    $native['available_tools'] = $page;
                    $native['instruction'] .= ' This invocation is native tool discovery: its eventual result lists tool names and descriptions; use each returned name as wire_name. Already available tools are supplied separately.';
                    return $native;
                }
                $catalog = self::nestedCatalogFromHostResult($native, $marker);
                if (!self::nestedCatalogPageIsComplete($catalog, $nestedOffset, $nestedLimit)) {
                    throw new GatewayError('nested_inventory_invalid', 'Codex 内层工具目录返回无效。', 502);
                }
                $total += $catalog['total'];
                foreach ($catalog['tools'] as $entry) {
                    if (!is_array($entry) || !is_string($entry['name'] ?? null)
                        || !preg_match('/\A[A-Za-z0-9_$]+\z/', $entry['name'])
                        || in_array($entry['name'], $excluded, true)) {
                        throw new GatewayError('nested_inventory_invalid', 'Codex 内层工具目录包含无效工具。', 502);
                    }
                    $tool = [
                        'wire_name'=>$entry['name'], 'name'=>$entry['name'], 'namespace'=>null,
                        'kind'=>'gateway', 'description'=>self::gatewayToolDescription(
                            $entry['name'], (string)($entry['description'] ?? '')),
                    ];
                    if ($includeSchema) $tool['parameters'] = [
                        'type'=>'object', 'additionalProperties'=>true,
                        'description'=>"Pass the exact structured arguments declared in this tool's description. For a declared freeform tool, use codex_tool_call.input instead.",
                    ];
                    $page[] = $tool;
                }
            }
            $discovery = $exact && $total > 0 ? [] : self::discoveryToolsForQuery($outer, $query, $includeSchema);
            return [
                'tools' => $page,
                'total' => $total,
                'next_offset' => $offset + count($page) < $total ? $offset + count($page) : null,
            ] + ($discovery !== [] ? ['discovery_tools'=>$discovery] : []);
        } finally {
            ToolBroker::completeActivity((string) $activity['binding_id'], (string) $activity['activity_id']);
        }
    }

    /** Preserve one-term exact search while expanding multi-term and Chinese browser queries. */
    private static function inventorySearchPlan(string $query): array
    {
        $phrase = mb_strtolower(trim($query));
        preg_match_all('/[a-z0-9_]+/u', $phrase, $matches);
        $terms = [];
        foreach ($matches[0] as $word) {
            $terms[] = match ($word) {
                'tabs' => 'tab', 'browsers' => 'browser', 'pages' => 'page',
                'screenshots' => 'screenshot', 'windows' => 'window',
                default => $word,
            };
        }
        foreach ([
            '浏览器'=>'browser', '标签页'=>'tab', '谷歌'=>'chrome', '网页'=>'browser',
            '页面'=>'page', '截图'=>'screenshot', '截屏'=>'screenshot',
            '读取'=>'read', '查看'=>'view', '窗口'=>'window', '桌面'=>'desktop',
        ] as $word=>$alias) {
            if (str_contains($phrase, $word)) $terms[] = $alias;
        }
        $terms = array_values(array_unique($terms));
        $expanded = count($terms) > 1 || preg_match('/\p{Han}/u', $phrase) === 1;
        $browserIntent = preg_match('/\b(?:browser|browsers|chrome|edge|tab|tabs)\b/u', $phrase) === 1
            || str_contains($phrase, '浏览器') || str_contains($phrase, '标签页')
            || str_contains($phrase, '网页') || str_contains($phrase, '谷歌');
        return ['phrase'=>$phrase, 'terms'=>$terms, 'expanded'=>$expanded,
            'browser_intent'=>$browserIntent];
    }

    /** Score a supplied native descriptor without granting any capability absent from it. */
    private static function inventoryMatchScore(array $search, string $name, string $description): int
    {
        $phrase = (string) $search['phrase'];
        if ($phrase === '') return 1;
        $name = mb_strtolower($name);
        $description = mb_strtolower($description);
        $exact = str_contains($name . "\n" . $description, $phrase);
        if (empty($search['expanded'])) return $exact ? 10000 : 0;
        $score = $exact ? 10000 : 0;
        foreach ($search['terms'] as $term) {
            if (str_contains($name, $term)) $score += 8;
            elseif (str_contains($description, $term)) $score += 2;
        }
        if (!empty($search['browser_intent'])
            && ($name === 'mcp__cua_repl__js' || str_contains($description, 'cua.gettab'))) {
            $score += 2000;
        }
        return $score;
    }

    /** Rank exact and related host matches before slicing the requested inventory page. */
    private static function searchInventoryTools(array $tools, array $search): array
    {
        $matches = [];
        foreach ($tools as $index=>$tool) {
            if (!is_array($tool)) continue;
            $score = self::inventoryMatchScore($search, (string) ($tool['wire_name'] ?? ''),
                (string) ($tool['description'] ?? ''));
            if ($score > 0) $matches[] = ['tool'=>$tool, 'score'=>$score, 'index'=>$index];
        }
        usort($matches, static fn(array $a, array $b): int =>
            $b['score'] <=> $a['score'] ?: $a['index'] <=> $b['index']);
        return array_column($matches, 'tool');
    }

    /** Advertise deferred native discovery alongside any exact inventory matches. */
    private static function discoveryToolsForQuery(array $outer, string $query, bool $includeSchema): array
    {
        if ($query === '') return [];
        $discovery = [];
        foreach ($outer as $tool) {
            if (!is_array($tool) || empty($tool['tool_search'])) continue;
            $discovery[] = self::inventoryPageEntry($tool, $includeSchema);
        }
        return $discovery;
    }

    /** Present one host tool in the public inventory format used by the Web transport. */
    private static function inventoryPageEntry(array $tool, bool $includeSchema): array
    {
        $wireName = (string) ($tool['wire_name'] ?? '');
        $isExecGateway = !empty($tool['freeform']) && ($tool['name'] ?? null) === 'exec'
            && in_array($tool['namespace'] ?? null, [null, 'functions'], true);
        $description = (string) ($tool['description'] ?? '');
        if (self::isAgentWaitTool($wireName)) {
            $description .= "\n\n" . self::AGENT_WAIT_TRANSPORT_RULE;
        } elseif ($isExecGateway) {
            $description .= "\n\n" . self::AGENT_WAIT_TRANSPORT_RULE
                . ' This rule is enforced for wait_agent calls made inside exec; recursive raw exec is unavailable.';
        }
        $entry = [
            'wire_name' => $wireName,
            'name' => (string) ($tool['name'] ?? ''),
            'namespace' => is_string($tool['namespace'] ?? null) ? $tool['namespace'] : null,
            'description' => $description,
            'kind' => !empty($tool['freeform']) ? 'freeform'
                : (!empty($tool['tool_search']) ? 'tool_search' : 'function'),
        ];
        if ($includeSchema) $entry['parameters'] = self::agentWaitParameters($wireName,
            $tool['parameters'] ?? new \stdClass());
        return $entry;
    }

    /** Identify the native collaboration waits that share this MCP transport. */
    private static function isAgentWaitTool(string $wireName): bool
    {
        return in_array($wireName, self::AGENT_WAIT_NAMES, true);
    }

    /** Add the same polling rule when a tool is discovered inside the Codex exec gateway. */
    private static function gatewayToolDescription(string $wireName, string $description): string
    {
        return self::isAgentWaitTool($wireName)
            ? $description . "\n\n" . self::AGENT_WAIT_TRANSPORT_RULE : $description;
    }

    /** Expose the transport interval without changing any other native wait arguments. */
    private static function agentWaitParameters(string $wireName, mixed $parameters): mixed
    {
        if (!self::isAgentWaitTool($wireName)) return $parameters;
        $parameters = is_array($parameters) ? $parameters : [];
        $properties = is_array($parameters['properties'] ?? null) ? $parameters['properties'] : [];
        $timeout = is_array($properties['timeout_ms'] ?? null) ? $properties['timeout_ms'] : [];
        unset($timeout['default']);
        $properties['timeout_ms'] = $timeout + ['type'=>'number'];
        $properties['timeout_ms']['type'] = 'number';
        $properties['timeout_ms']['const'] = self::AGENT_WAIT_POLL_MS;
        $properties['timeout_ms']['minimum'] = self::AGENT_WAIT_POLL_MS;
        $properties['timeout_ms']['maximum'] = self::AGENT_WAIT_POLL_MS;
        $properties['timeout_ms']['description'] = 'Required transport-safe polling interval. Use exactly 30000; a timed-out wait does not mean the agents have finished.';
        $parameters['properties'] = $properties;
        $required = is_array($parameters['required'] ?? null) ? $parameters['required'] : [];
        $parameters['required'] = array_values(array_unique([...$required, 'timeout_ms']));
        return $parameters;
    }

    /** Reject a wait that could occupy the shared MCP channel past its safe poll interval. */
    private static function assertAgentWaitArguments(string $wireName, mixed $toolArguments, mixed $input): void
    {
        if (!self::isAgentWaitTool($wireName)) return;
        if ($input !== null || !is_array($toolArguments) || ($toolArguments !== [] && array_is_list($toolArguments))
            || ($toolArguments['timeout_ms'] ?? null) !== self::AGENT_WAIT_POLL_MS) {
            throw new GatewayError('agent_wait_timeout_invalid',
                'ChatGPT Web wait_agent requires structured arguments and timeout_ms=30000 so the shared MCP channel remains available to spawned Web agents.', 400);
        }
    }

    private static function invokeTool(mixed $rpcId, array $arguments): array
    {
        $token = self::turnToken($arguments);
        $wireName = $arguments['wire_name'] ?? null;
        if (!is_string($wireName) || !preg_match('/\A[A-Za-z0-9_$\.:-]{1,1000}\z/', $wireName)) {
            throw new GatewayError('tool_name_invalid', 'wire_name 格式无效。');
        }
        if (ToolBroker::isBridgeToolName($wireName)) {
            throw new GatewayError('recursive_tool_denied', '工具桥不能递归调用自身。', 400);
        }
        if (in_array($wireName, ['codex.control.wait_result', 'codex.control.wait_code_mode'], true)) {
            // wait_result remains receipt-only; wait_code_mode may reserve only an
            // exact source-owned native wait. Retired checks remain in ToolBroker.
            $control = $arguments['arguments'] ?? null;
            if ($control instanceof \stdClass) $control = get_object_vars($control);
            if (array_key_exists('input', $arguments) || !is_array($control)
                || array_keys($control) !== ['call_id'] || !is_string($control['call_id'])
                || preg_match('/\Acall_[A-Za-z0-9_-]{20,128}\z/', $control['call_id']) !== 1) {
                throw new GatewayError('tool_wait_arguments_invalid', '结果等待需要本回合已发起调用的准确 call_id。', 400);
            }
            if ($wireName === 'codex.control.wait_code_mode') {
                // This separate control follows only the native receipt's owned cell.
                // It never accepts a caller-selected cell or reissues the original exec.
                $wait = ToolBroker::resumeCodeModeWait($token, $control['call_id']);
                if (is_array($wait)) return !empty($wait['paused']) ? $wait
                    : self::waitHostToolResult($token, (string) $wait['call_id']);
            }
            return self::waitHostToolResult($token, $control['call_id']);
        }
        if ($wireName === 'codex.control.compaction_handoff') {
            // This reserved operation is absent from inventory and has no normal turn binding.
            // Its one-shot credential can only submit a checkpoint, never call a host tool.
            $control = $arguments['arguments'] ?? null;
            if ($control instanceof \stdClass) $control = get_object_vars($control);
            if (array_key_exists('input', $arguments) || !is_array($control)
                || !is_string($control['handoff_id'] ?? null)
                || !is_string($control['summary'] ?? null)) {
                throw new GatewayError('compaction_handoff_invalid', '摘要交接参数无效。', 400);
            }
            return ContextCompaction::acceptRetainedHandoff($token, $control['handoff_id'], $control['summary']);
        }
        if (str_starts_with($token, 'probe_')) {
            if ($wireName !== 'rcmaker_permission_probe') {
                throw new GatewayError('mcp_probe_tool_invalid', '连接器权限探针操作无效。', 409);
            }
            $probe = ConnectorState::acceptPermissionProbe($token);
            return [
                'ok' => true,
                'call_id' => 'probe_permission',
                'output' => 'RCMAKER_PERMISSION_READY',
                'permission_verified' => (bool) ($probe['permission_verified'] ?? false),
                'duplicate' => (bool) ($probe['duplicate'] ?? false),
            ];
        }
        if ($wireName === 'rcmaker.work_update') {
            $work = $arguments['arguments'] ?? null;
            if (!is_array($work) || ($work !== [] && array_is_list($work))) {
                throw new GatewayError('work_update_invalid', 'rcmaker.work_update 需要对象参数。', 400);
            }
            return self::workUpdateTool($rpcId, ['turn_token'=>$token] + $work);
        }
        if ($wireName === 'rcmaker.read_file') {
            $read = $arguments['arguments'] ?? null;
            if (!is_array($read) || ($read !== [] && array_is_list($read))) {
                throw new GatewayError('read_file_arguments_invalid', 'rcmaker.read_file 需要对象参数。', 400);
            }
            return self::readFileTool($rpcId, ['turn_token'=>$token] + $read);
        }
        if ($wireName === 'rcmaker.turn_complete') {
            $completion = $arguments['arguments'] ?? null;
            if (!is_array($completion) || ($completion !== [] && array_is_list($completion))) {
                throw new GatewayError('completion_invalid', 'rcmaker.turn_complete 需要对象参数。', 400);
            }
            return self::deprecatedNormalCompleteTool(['turn_token'=>$token] + $completion);
        }
        self::claimToolTurn($token);
        $callKey = self::rpcCallKey($token, $rpcId, 'codex_tool_call:' . $wireName);
        $outer = ToolBroker::inventory($token);
        $outerWireNames = array_column($outer, 'wire_name');
        if (!in_array($wireName, $outerWireNames, true)) {
            $gateway = self::execGateway($token);
            if ($gateway === null || !preg_match('/\A[A-Za-z0-9_$]+\z/', $wireName)) {
                throw new GatewayError('native_tool_unavailable', '当前 Codex 回合没有提供工具 ' . $wireName . '。', 409);
            }
            $nestedArguments = $arguments['arguments'] ?? [];
            $nestedInput = $arguments['input'] ?? null;
            if (!is_array($nestedArguments) || ($nestedArguments !== [] && array_is_list($nestedArguments))
                || ($nestedInput !== null && (!is_string($nestedInput) || $nestedArguments !== []))) {
                throw new GatewayError('tool_arguments_invalid', '内层工具需要对象参数，或单独的 freeform input。', 400);
            }
            self::assertAgentWaitArguments($wireName, $nestedArguments, $nestedInput);
            $program = self::nestedGatewayProgram([$wireName],
                $nestedInput !== null ? ['patch'=>$nestedInput] : $nestedArguments, $nestedInput !== null);
            return self::invokeResolvedTool($token, $gateway, $callKey, null, $program);
        }
        $activity = ToolBroker::beginActivity($token);
        try {
            if ($wireName===TaskContext::WIRE_NAME) {
                $context=ToolBroker::readContext($token,$arguments['arguments']??[]);
                if ($context!==null) {
                    return $context;
                }
            }
            $toolArguments = $arguments['arguments'] ?? null;
            $toolInput = isset($arguments['input']) && is_string($arguments['input']) ? $arguments['input'] : null;
            self::assertAgentWaitArguments($wireName, $toolArguments, $toolInput);
            $outerTool = self::findNativeToolIn($outer, [$wireName]);
            if ($outerTool !== null && !empty($outerTool['freeform'])
                && ($outerTool['name'] ?? null) === 'exec'
                && in_array($outerTool['namespace'] ?? null, [null, 'functions'], true)
                && $toolInput !== null) {
                $toolInput = self::transportBoundRawExecProgram($toolInput, $wireName);
            }
            $invocation = ToolBroker::registerInvocation(
                $token,
                $wireName,
                $callKey,
                $toolArguments,
                $toolInput
            );
            return !empty($invocation['paused']) ? $invocation : self::waitHostToolResult($token, (string) $invocation['call_id']);
        } finally {
            ToolBroker::completeActivity((string) $activity['binding_id'], (string) $activity['activity_id']);
        }
    }

    private static function execTool(mixed $rpcId, array $arguments): array
    {
        $token = self::turnToken($arguments);
        $cmd = self::requiredString($arguments, 'cmd', 100000);
        if (str_starts_with($token, 'probe_')) {
            if ($cmd !== 'RCMAKER_PERMISSION_PROBE') {
                throw new GatewayError('mcp_probe_tool_invalid', '连接器权限探针操作无效。', 409);
            }
            $probe = ConnectorState::acceptPermissionProbe($token);
            return [
                'ok' => true,
                'call_id' => 'probe_permission',
                'output' => 'RCMAKER_PERMISSION_READY',
                'permission_verified' => (bool) ($probe['permission_verified'] ?? false),
                'duplicate' => (bool) ($probe['duplicate'] ?? false),
            ];
        }
        $payload = ['cmd' => $cmd];
        if (array_key_exists('workdir', $arguments)) $payload['workdir'] = self::optionalString($arguments, 'workdir', 16384);
        if (array_key_exists('yield_time_ms', $arguments)) $payload['yield_time_ms'] = self::optionalInt($arguments, 'yield_time_ms', 250, 30000);
        if (array_key_exists('max_output_tokens', $arguments)) $payload['max_output_tokens'] = self::optionalInt($arguments, 'max_output_tokens', 1, 1000000);
        if (array_key_exists('tty', $arguments)) {
            if (!is_bool($arguments['tty'])) throw new GatewayError('tool_arguments_invalid', 'tty 必须是布尔值。');
            $payload['tty'] = $arguments['tty'];
        }
        if (array_key_exists('sandbox_permissions', $arguments)) {
            $sandboxPermissions = $arguments['sandbox_permissions'];
            if (!is_string($sandboxPermissions) || !in_array($sandboxPermissions, ['use_default', 'require_escalated'], true)) {
                throw new GatewayError('tool_arguments_invalid', 'sandbox_permissions 只能是 use_default 或 require_escalated。');
            }
            $payload['sandbox_permissions'] = $sandboxPermissions;
        }
        if (array_key_exists('justification', $arguments)) {
            $payload['justification'] = self::optionalString($arguments, 'justification', 100000);
        }
        if (array_key_exists('prefix_rule', $arguments)) {
            $prefixRule = $arguments['prefix_rule'];
            if (!is_array($prefixRule)) throw new GatewayError('tool_arguments_invalid', 'prefix_rule 必须是字符串数组。');
            $validated = [];
            foreach ($prefixRule as $part) {
                if (!is_string($part) || $part === '' || strlen($part) > 16384) {
                    throw new GatewayError('tool_arguments_invalid', 'prefix_rule 必须是非空字符串数组。');
                }
                $validated[] = $part;
            }
            $payload['prefix_rule'] = $validated;
        }
        return self::invokeNativeTool($rpcId, 'codex_exec', $token, ['exec_command', 'shell_command'], $payload);
    }

    private static function writeStdinTool(mixed $rpcId, array $arguments): array
    {
        $token = self::turnToken($arguments);
        $sessionId = $arguments['session_id'] ?? null;
        if (!is_int($sessionId) || $sessionId < 0) throw new GatewayError('tool_arguments_invalid', 'session_id 必须是非负整数。');
        $payload = ['session_id' => $sessionId];
        if (array_key_exists('chars', $arguments)) $payload['chars'] = self::optionalString($arguments, 'chars', 1000000);
        if (array_key_exists('yield_time_ms', $arguments)) {
            $requestedYield = self::optionalInt($arguments, 'yield_time_ms', 250, 300000);
            $payload['yield_time_ms'] = min($requestedYield, self::writeStdinYieldMaxMs());
        }
        if (array_key_exists('max_output_tokens', $arguments)) $payload['max_output_tokens'] = self::optionalInt($arguments, 'max_output_tokens', 1, 1000000);
        return self::invokeNativeTool($rpcId, 'codex_write_stdin', $token, ['write_stdin'], $payload);
    }

    private static function applyPatchTool(mixed $rpcId, array $arguments): array
    {
        $token = self::turnToken($arguments);
        $patch = self::requiredString($arguments, 'patch', 5000000);
        self::claimToolTurn($token);
        if (self::findNativeTool($token, ['apply_patch']) === null && ($gateway = self::execGateway($token)) !== null) {
            return self::invokeResolvedTool($token, $gateway, self::rpcCallKey($token, $rpcId, 'codex_apply_patch'),
                null, self::nestedGatewayProgram(['apply_patch'], ['patch' => $patch], true));
        }
        $tool = self::nativeTool($token, 'codex_apply_patch', ['apply_patch']);
        $callKey = self::rpcCallKey($token, $rpcId, 'codex_apply_patch');
        return self::invokeResolvedTool($token, $tool, $callKey,
            !empty($tool['freeform']) ? null : self::patchArguments($tool, $patch),
            !empty($tool['freeform']) ? $patch : null
        );
    }

    private static function viewImageTool(mixed $rpcId, array $arguments): array
    {
        $token = self::turnToken($arguments);
        $payload = ['path' => self::requiredString($arguments, 'path', 16384)];
        if (array_key_exists('detail', $arguments)) {
            $detail = $arguments['detail'];
            if (!is_string($detail) || !in_array($detail, ['high', 'original'], true)) {
                throw new GatewayError('tool_arguments_invalid', 'detail 只能是 high 或 original。');
            }
            $payload['detail'] = $detail;
        }
        return self::invokeNativeTool($rpcId, 'codex_view_image', $token, ['view_image'], $payload);
    }

    private static function invokeNativeTool(mixed $rpcId, string $bridgeName, string $token, array $aliases, array $payload): array
    {
        self::claimToolTurn($token);
        if (self::findNativeTool($token, $aliases) === null && ($gateway = self::execGateway($token)) !== null) {
            $program = $bridgeName === 'codex_exec'
                ? self::nestedGatewayProgram(['exec_command', 'shell_command'], $payload, false)
                : self::nestedGatewayProgram($aliases, $payload, false);
            return self::invokeResolvedTool($token, $gateway, self::rpcCallKey($token, $rpcId, $bridgeName), null, $program);
        }
        $tool = self::nativeTool($token, $bridgeName, $aliases);
        $permissionKeys = ['sandbox_permissions', 'justification', 'prefix_rule'];
        $requestedPermissions = array_intersect_key($payload, array_flip($permissionKeys));
        if ($requestedPermissions !== []) {
            $schema = $tool['parameters'] ?? null;
            $properties = is_array($schema) && is_array($schema['properties'] ?? null) ? $schema['properties'] : null;
            foreach (array_keys($requestedPermissions) as $key) {
                if ($properties === null || !array_key_exists($key, $properties)) {
                    throw new GatewayError(
                        'native_tool_argument_unsupported',
                        '当前宿主命令工具不支持 ' . $key . '，不能静默丢弃该审批参数。',
                        409
                    );
                }
            }
        }
        if ((string) ($tool['name'] ?? '') === 'shell_command' || (string) ($tool['wire_name'] ?? '') === 'shell_command') {
            $shell = ['command' => (string) ($payload['cmd'] ?? '')];
            if (isset($payload['workdir'])) $shell['workdir'] = $payload['workdir'];
            if (isset($payload['yield_time_ms'])) $shell['timeout_ms'] = $payload['yield_time_ms'];
            foreach ($permissionKeys as $key) {
                if (array_key_exists($key, $payload)) $shell[$key] = $payload[$key];
            }
            $payload = $shell;
        }
        $payload = self::supportedArguments($tool, $payload);
        return self::invokeResolvedTool($token, $tool, self::rpcCallKey($token, $rpcId, $bridgeName), $payload, null);
    }

    /** Read a bounded UTF-8 workspace file through the same native Codex sandbox, without a model-authored shell command. */
    private static function readFileTool(mixed $rpcId, array $arguments): array
    {
        $token = self::turnToken($arguments);
        $path = self::requiredString($arguments, 'path', 16384);
        $offset = isset($arguments['offset']) ? self::optionalInt($arguments, 'offset', 0, 10000000) : 0;
        $limit = isset($arguments['limit']) ? self::optionalInt($arguments, 'limit', 1, 30000) : 12000;
        return self::invokeNativeTool($rpcId, 'codex_read_file', $token,
            ['exec_command', 'shell_command'], [
                'cmd' => self::readFileCommand($path, $offset, $limit),
                'max_output_tokens' => min(12000, max(2000, (int)ceil($limit / 2))),
            ]);
    }

    /** Build a bounded read for the native host shell; optional OS injection is only for isolated tests. */
    private static function readFileCommand(string $path, int $offset, int $limit, ?string $osFamily = null): string
    {
        $osFamily ??= PHP_OS_FAMILY;
        $literal = self::relativePathLiteral($path, 'read_file_path_invalid', $osFamily);
        if ($osFamily !== 'Windows') {
            return self::unixReadFileCommand($literal, $offset, $limit);
        }
        return '$p=Join-Path (Get-Location).Path ' . $literal . '; '
            . '$item=Get-Item -LiteralPath $p -ErrorAction Stop; '
            . 'if ($item.PSIsContainer -or $item.Length -gt 1048576) { throw "File is a directory or exceeds 1 MiB" }; '
            . '$content=Get-Content -LiteralPath $p -Raw -Encoding UTF8 -ErrorAction Stop; '
            . '$start=[Math]::Min(' . $offset . ',$content.Length); '
            . '$length=[Math]::Min(' . $limit . ',$content.Length-$start); '
            . 'Write-Output ("chars="+$content.Length+" offset="+$start+" next="+($start+$length)); '
            . 'Write-Output $content.Substring($start,$length)';
    }

    /**
     * Use POSIX utilities supplied by Linux/macOS, retaining exact newlines and UTF-8 character boundaries.
     * The generated command is executed by Codex's native command tool under its normal approval rules.
     * @param string $literal Already validated, single-quoted POSIX path literal.
     * @param int $offset Character offset. @param int $limit Maximum characters to return.
     * @return string Read-only shell program; no Python, Node, PHP or GNU-specific utilities required.
     */
    private static function unixReadFileCommand(string $literal, int $offset, int $limit): string
    {
        $program = <<<'AWK'
function invalid() { print "File is not valid UTF-8 text" > "/dev/stderr"; exit 1 }
{ content = content $0 "\n" }
END {
    content = substr(content, 1, bytes)
    if (substr(content, 1, 3) == "\357\273\277") content = substr(content, 4)
    total = 0; result = ""
    for (i = 1; i <= length(content); i += width) {
        lead = substr(content, i, 1); width = 1
        if (lead ~ /^[\302-\337]$/) width = 2
        else if (lead ~ /^[\340-\357]$/) width = 3
        else if (lead ~ /^[\360-\364]$/) width = 4
        else if (lead !~ /^[\001-\177]$/) invalid()
        chunk = substr(content, i, width)
        if (length(chunk) != width) invalid()
        for (j = 2; j <= width; j++) if (substr(chunk, j, 1) !~ /^[\200-\277]$/) invalid()
        second = substr(chunk, 2, 1)
        if (lead == "\340" && second !~ /^[\240-\277]$/) invalid()
        if (lead == "\355" && second !~ /^[\200-\237]$/) invalid()
        if (lead == "\360" && second !~ /^[\220-\277]$/) invalid()
        if (lead == "\364" && second !~ /^[\200-\217]$/) invalid()
        if (total >= offset && total < offset + limit) result = result chunk
        total++
    }
    start = offset < total ? offset : total
    count = limit < total - start ? limit : total - start
    printf "chars=%d offset=%d next=%d\n", total, start, start + count
    printf "%s\n", result
}
AWK;
        return 'p=' . $literal . '; '
            . 'if [ ! -f "$p" ]; then printf "%s\n" "File is not a regular file" >&2; exit 1; fi; '
            . 'bytes=$(LC_ALL=C wc -c < "$p") || exit 1; '
            . 'if [ "$bytes" -gt 1048576 ]; then printf "%s\n" "File exceeds 1 MiB" >&2; exit 1; fi; '
            . 'LC_ALL=C awk -v BINMODE=3 -v bytes="$bytes" -v offset=' . $offset . ' -v limit=' . $limit . " '" . $program . "' < \"\$p\"";
    }

    /** Validate a project-relative path and quote it for the current platform's native shell. */
    private static function relativePathLiteral(string $path, string $errorCode, string $osFamily = PHP_OS_FAMILY): string
    {
        if (str_starts_with($path, '/') || str_starts_with($path, '\\')
            || preg_match('/\A[A-Za-z]:/', $path) === 1 || preg_match('/[\x00-\x1f]/', $path) === 1
            || ($osFamily === 'Windows' && preg_match('/[<>:"|?*]/', $path) === 1)) {
            throw new GatewayError($errorCode, '文件路径必须是当前项目内的相对路径。', 400);
        }
        $segments = $osFamily === 'Windows' ? (preg_split('~[\\\\/]~', $path) ?: []) : explode('/', $path);
        if ($segments === [] || in_array('', $segments, true) || in_array('.', $segments, true)
            || in_array('..', $segments, true)) {
            throw new GatewayError($errorCode, '文件路径不能包含空段或目录回退。', 400);
        }
        return "'" . str_replace("'", $osFamily === 'Windows' ? "''" : "'\"'\"'", $path) . "'";
    }

    /**
     * Wait for the independent browser send observation before allowing any host effect.
     * CDP can acknowledge the posted message after the Connector arrives. The bounded grace
     * covers its 30-second observation-delay window, but never exceeds the configured timeout.
     * Revocation and expiry are checked on every poll by ToolBroker::start().
     */
    private static function claimToolTurn(string $token): void
    {
        $timeoutMs = min(35000, (int) GatewaySettings::internal()['mcp_invoke_timeout_ms']);
        $deadline = microtime(true) + $timeoutMs / 1000;
        do {
            $turn = ToolBroker::start($token);
            if (!empty($turn['sent']) && !empty($turn['started']) && (string) ($turn['status'] ?? '') === 'running') return;
            usleep(50000);
        } while (microtime(true) < $deadline);
        throw new GatewayError('mcp_capability_not_ready', 'ChatGPT 页面发送尚未与本机工具能力完成绑定。', 409);
    }

    private static function invokeResolvedTool(string $token, array $tool, string $callKey, ?array $arguments, ?string $input): array
    {
        $activity = ToolBroker::beginActivity($token);
        try {
            $invocation = ToolBroker::registerInvocation(
                $token,
                (string) $tool['wire_name'],
                $callKey,
                $arguments,
                $input
            );
            return !empty($invocation['paused']) ? $invocation : self::waitHostToolResult($token, (string) $invocation['call_id']);
        } finally {
            ToolBroker::completeActivity((string) $activity['binding_id'], (string) $activity['activity_id']);
        }
    }

    /**
     * Observe one already-issued native call within the current tunnel RPC's lifetime.
     * A poll ending first is successful transport progress, not native failure or completion.
     * The pending envelope never carries a receipt acknowledgement or a plaintext capability.
     */
    private static function waitHostToolResult(string $token, string $callId): array
    {
        $remainingMs = self::$rpcDeadlineAt === null ? self::HOST_RPC_SLICE_MS
            : max(0, min(self::HOST_RPC_SLICE_MS, (int) floor((self::$rpcDeadlineAt - microtime(true)) * 1000)));
        try {
            $result = ToolBroker::waitResult($token, $callId,
                (int) GatewaySettings::internal()['mcp_invoke_timeout_ms'], true, $remainingMs);
        } catch (GatewayError $error) {
            // The RPC has failed, but its already-issued native call can still settle.
            // Preserve the typed failure so a stopped Web final cannot wait forever
            // for delivery through this closed RPC. Never mark its result delivered.
            try { ToolBroker::recordWaitTransportFailure($token, $callId, $error->errorCode); }
            catch (\Throwable) { /* Preserve the original transport error. */ }
            throw $error;
        }
        if (!empty($result['pending'])) {
            // Even a receipt settling while stdout is flushed must not be acknowledged
            // by this pending frame: the model has not received its real output yet.
            self::$hostReceipt = null;
            return ['ok' => true, 'pending' => true, 'call_id' => $callId,
                'wire_name' => (string) $result['wire_name'],
                'resume' => ['tool' => 'codex_tool_call', 'wire_name' => 'codex.control.wait_result',
                    'arguments' => ['call_id' => $callId]],
                'instruction' => 'The existing native call is still pending execution or user approval. This is not a tool failure, approval denial, or task completion. Keep the same turn_token and call codex_tool_call with wire_name="codex.control.wait_result" and arguments={"call_id":"' . $callId . '"} until its real result settles. This only waits for the issued call; do not resend the original action. Continue the task after reading its actual result; do not begin a final answer while it is pending.'];
        }
        self::$hostReceipt = ['token' => $token, 'call_id' => $callId, 'settled' => true];
        $native = self::hostToolResultForToken($token, $result);
        $first = $native['__mcp_result']['content'][0] ?? [];
        if (($first['type'] ?? '') === 'text' && str_starts_with((string) ($first['text'] ?? ''), 'Script running with cell ID ')
            && ToolBroker::codeModeContinuation($token, $callId) !== null) {
            // Keep all native output blocks exact and acknowledge only this flushed receipt.
            // Outer exec completion means its yield arrived, not that the script finished.
            $pending = ['ok'=>true, 'pending'=>true, 'kind'=>'code_mode_cell', 'call_id'=>$callId,
                'resume'=>['tool'=>'codex_tool_call', 'wire_name'=>'codex.control.wait_code_mode',
                    'arguments'=>['call_id'=>$callId]],
                'instruction'=>'The native receipt above yielded a still-running script; its task results are not final. With the same turn_token, call codex_tool_call using the exact resume wire_name and arguments above to collect this script. Repeat the returned continuation until it settles. Do not rerun exec or its batch, infer success, or give a final answer while required results are pending. This control uses the exact native cell already owned by this task. Honor newer user instructions before starting any new work.'];
            $native['__mcp_result']['content'][] = ['type'=>'text', 'text'=>json_encode($pending,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)];
            $native['__mcp_result']['structuredContent'] = $pending;
        }
        return $native;
    }

    /** Preserve the native host result and separately forward role-preserving Codex updates. */
    private static function hostToolResultForToken(string $token, array $result): array
    {
        $output = (string) ($result['output'] ?? '');
        // A native command or patch can return a nonzero status while its tool call
        // still completed normally. Preserve that receipt as data for the Web model;
        // MCP isError is reserved for an actual bridge/transport failure.
        return self::hostToolResult($output, (array) ($result['steering_messages'] ?? []),
            is_string($result['compaction_instruction'] ?? null) ? $result['compaction_instruction'] : null,
            !empty($result['output_is_content_parts']), (array) ($result['tool_context_updates'] ?? []));
    }

    /** Keep the host result exact; carry user steering and non-user context in separate envelopes. */
    private static function hostToolResult(string $output, array $steering = [], ?string $scope = null,
        bool $outputIsContentParts = false, array $contextUpdates = []): array
    {
        $content = self::hostContentBlocks($output, $outputIsContentParts);
        $text = implode("\n", array_values(array_map(
            static fn(array $block): string => $block['text'],
            array_filter($content, static fn(array $block): bool => ($block['type'] ?? null) === 'text')
        )));
        $result = ['content' => $content];
        if ($scope !== null) {
            $result['content'][] = ['type' => 'text', 'text' => $scope];
        }
        if ($steering !== []) {
            $result['content'][] = [
                'type' => 'text',
                'text' => "<codex_user_steering>\n"
                    . json_encode($steering, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
                    . "\n</codex_user_steering>",
            ];
        }
        if ($contextUpdates !== []) {
            $result['content'][] = [
                'type' => 'text',
                'text' => "New Codex context accompanies this tool result. Preserve these message roles and their original instruction priority.\n"
                    . "<codex_context_update>\n"
                    . ContextHistory::canonical($contextUpdates)
                    . "\n</codex_context_update>",
            ];
        }
        try {
            // Parse the normalized text, including a native multi-block
            // receipt joined into one text block when no image is present.
            $structured = json_decode($text, false, 128, JSON_THROW_ON_ERROR);
            if ($structured instanceof \stdClass || is_array($structured)) $result['structuredContent'] = $structured;
        } catch (\Throwable) {
        }
        return ['__mcp_result' => $result];
    }

    /** Resolve exact native aliases. Another connector's bridge operation is never a host fallback. */
    private static function nativeTool(string $token, string $bridgeName, array $aliases): array
    {
        $tools = ToolBroker::inventory($token);
        $found = self::findNativeToolIn($tools, $aliases);
        if ($found !== null) return $found;
        $available = array_slice(array_values(array_filter(array_map(
            static fn($tool): string => is_array($tool) ? (string) ($tool['wire_name'] ?? '') : '', $tools),
            static fn(string $name): bool => $name !== '')), 0, 12);
        throw new GatewayError('native_tool_unavailable', '当前 Codex 回合没有提供 ' . $bridgeName
            . ' 对应的宿主工具。这不是权限拒绝。当前可用 wire_name：' . implode(', ', $available)
            . '。请用 codex_tool_inventory 查看参数，再用 codex_tool_call 调用实际工具并继续任务；不要因为快捷工具缺失就结束。', 409);
    }

    private static function findNativeTool(string $token, array $aliases): ?array
    {
        return self::findNativeToolIn(ToolBroker::inventory($token), $aliases);
    }

    private static function findNativeToolIn(array $tools, array $aliases): ?array
    {
        foreach ($aliases as $alias) {
            foreach ($tools as $tool) {
                if (!is_array($tool)) continue;
                if ((string) ($tool['wire_name'] ?? '') === $alias || (string) ($tool['name'] ?? '') === $alias) return $tool;
            }
        }
        return null;
    }

    /** The current Codex harness may expose all native tools only inside functions.exec. */
    private static function execGateway(string $token): ?array
    {
        return self::execGatewayIn(ToolBroker::inventory($token));
    }

    /** Select only an advertised native exec from the already authorized request snapshot. */
    private static function execGatewayIn(array $tools): ?array
    {
        foreach ($tools as $tool) {
            if (!is_array($tool) || empty($tool['freeform'])) continue;
            if (($tool['name'] ?? null) === 'exec'
                && in_array($tool['namespace'] ?? null, [null, 'functions'], true)) return $tool;
        }
        return null;
    }

    /** Invoke a verified nested native tool with an outer budget for command receipts. */
    private static function nestedGatewayProgram(array $candidates, array $payload, bool $patch): string
    {
        $names = json_encode($candidates, JSON_THROW_ON_ERROR);
        $arguments = json_encode($patch ? (string) ($payload['patch'] ?? '')
            : ($payload === [] ? new \stdClass() : $payload), JSON_THROW_ON_ERROR);
        $shell = $candidates === ['exec_command', 'shell_command'] ? json_encode([
            'command' => (string) ($payload['cmd'] ?? ''),
            ...array_intersect_key($payload, array_flip(['workdir', 'sandbox_permissions', 'justification', 'prefix_rule'])),
            ...(isset($payload['yield_time_ms']) ? ['timeout_ms' => $payload['yield_time_ms']] : []),
        ], JSON_THROW_ON_ERROR) : $arguments;
        $command = $candidates === ['exec_command', 'shell_command'];
        $requestedOutputTokens = is_int($payload['max_output_tokens'] ?? null)
            ? $payload['max_output_tokens'] : 10000;
        // text(result) JSON-escapes command receipts, which can expand quoted output.
        $outerOutputTokens = min(1000000, max(10000, $requestedOutputTokens * 2 + 512));
        return implode("\n", [
            ...($command ? ['// @exec: {"max_output_tokens": ' . $outerOutputTokens . '}'] : []),
            'if (!Array.isArray(ALL_TOOLS)) throw new Error("Native nested tool registry is unavailable");',
            'const candidates = ' . $names . ';',
            'const present = candidates.filter(name => ALL_TOOLS.some(tool => tool?.name === name));',
            'if (present.length !== 1) throw new Error("Expected exactly one native tool; found " + (present.join(", ") || "none"));',
            'const name = present[0];',
            'if (typeof tools[name] !== "function") throw new Error("Native tool is listed but unavailable: " + name);',
            'const args = name === "shell_command" ? ' . $shell . ' : ' . $arguments . ';',
            'const result = await tools[name](args);',
            'const emit = value => {',
            '  if (Array.isArray(value)) { for (const item of value) emit(item); return; }',
            '  if (value && typeof value === "object") {',
            '    if (value.type === "image") { image(value); return; }',
            '    if (value.type === "audio") { audio(value); return; }',
            '    if (value.type === "text" && typeof value.text === "string") { text(value.text); return; }',
            '    if (typeof value.image_url === "string" && typeof value.output_hint === "string") { generatedImage(value); return; }',
            '    if (typeof value.image_url === "string") { image(value.image_url, value.detail ?? "auto"); return; }',
            '    if (typeof value.audio_url === "string") { audio(value.audio_url); return; }',
            '    if (Array.isArray(value.content)) { for (const item of value.content) emit(item); return; }',
            '  }',
            '  text(value);',
            '};',
            'emit(result);',
        ]);
    }

    /** Preserve exec hints, bound agent waits and exclude recursive bridges from both nested discovery and invocation. */
    private static function transportBoundRawExecProgram(string $input, string $blockedExecName): string
    {
        $pragma = '';
        if (preg_match('/\A([\t ]*\/\/ @exec:[^\r\n]*)(?:\r\n|\n|\r|\z)/', $input, $match) === 1) {
            $pragma = $match[1] . "\n";
            $input = substr($input, strlen($match[0]));
        }
        $waitNames = json_encode(self::AGENT_WAIT_NAMES, JSON_THROW_ON_ERROR);
        $blockedName = json_encode($blockedExecName, JSON_THROW_ON_ERROR);
        $pollMs = self::AGENT_WAIT_POLL_MS;
        return $pragma . implode("\n", [
            'await (async (tools, ALL_TOOLS) => {',
            $input,
            '})(...(() => {',
            '  const source = tools;',
            self::bridgeRegistryGuardProgram(),
            '  const waitNames = new Set(' . $waitNames . ');',
            '  const blockedExecName = ' . $blockedName . ';',
            '  const pollMs = ' . $pollMs . ';',
            '  const registryNames = new Set(Reflect.ownKeys(source).filter(name => !isBridge(name)));',
            '  const registry = typeof ALL_TOOLS !== "undefined" && Array.isArray(ALL_TOOLS) ? ALL_TOOLS.filter(tool => !isBridge(tool?.name)) : [];',
            '  if (typeof ALL_TOOLS !== "undefined" && Array.isArray(ALL_TOOLS)) {',
            '    for (const tool of registry) if (typeof tool?.name === "string") registryNames.add(tool.name);',
            '  }',
            '  const wrappers = new Map();',
            '  const expose = name => {',
            '    if (isBridge(name)) return async () => { throw new Error("Recursive Codex bridge calls are unavailable inside ChatGPT Web exec; use the native host tools for this task"); };',
            '    if (wrappers.has(name)) return wrappers.get(name);',
            '    const value = Reflect.get(source, name, source);',
            '    let exposed = value;',
            '    if (typeof value === "function" && name === blockedExecName) {',
            '      exposed = () => { throw new Error("Nested raw exec is unavailable inside ChatGPT Web exec"); };',
            '    } else if (typeof value === "function" && typeof name === "string" && waitNames.has(name)) {',
            '      exposed = args => {',
            '        if (!args || typeof args !== "object" || Array.isArray(args) || args.timeout_ms !== pollMs) {',
            '          throw new Error("ChatGPT Web wait_agent requires timeout_ms=" + pollMs + " so the shared MCP channel remains available to spawned Web agents");',
            '        }',
            '        return Reflect.apply(value, source, [args]);',
            '      };',
            '    } else if (typeof value === "function") {',
            '      exposed = (...args) => Reflect.apply(value, source, args);',
            '    }',
            '    wrappers.set(name, exposed);',
            '    return exposed;',
            '  };',
            '  const scopedTools = new Proxy(Object.create(null), {',
            '    get: (_target, name) => expose(name),',
            '    has: (_target, name) => !isBridge(name) && (registryNames.has(name) || Reflect.has(source, name)),',
            '    ownKeys: () => [...registryNames],',
            '    getOwnPropertyDescriptor: (_target, name) =>',
            '      !isBridge(name) && (registryNames.has(name) || Reflect.has(source, name))',
            '        ? { configurable: true, enumerable: true, writable: false, value: expose(name) }',
            '        : undefined,',
            '    set: () => false,',
            '    defineProperty: () => false,',
            '    deleteProperty: () => false,',
            '    setPrototypeOf: () => false,',
            '    getPrototypeOf: () => null,',
            '    preventExtensions: () => false,',
            '  });',
            '  return [scopedTools, Object.freeze(registry)];',
            '})());',
        ]);
    }

    /** One bridge-name predicate for raw exec and deferred registry discovery; no connector-specific names. */
    private static function bridgeRegistryGuardProgram(): string
    {
        return 'const bridgeNames = ' . json_encode(ToolBroker::bridgeToolNames(), JSON_THROW_ON_ERROR) . ';'
            . 'const isBridge = name => typeof name === "string" && (name.startsWith("mcp__rcmaker_codex__")'
            . ' || bridgeNames.some(bridge => { const normalized = name.replace(/[.:\/ -]/g, "_");'
            . ' return normalized === bridge || normalized.endsWith("_" + bridge); }));';
    }

    /** Emit one identified registry page; exact names never match descriptions or similar tools. */
    private static function nestedCatalogProgram(string $query, int $offset, int $limit, array $excluded, string $marker, bool $exact = false): string
    {
        $search = json_encode(self::inventorySearchPlan($query), JSON_THROW_ON_ERROR);
        $excludedJson = json_encode($excluded, JSON_THROW_ON_ERROR);
        return implode("\n", [
            '// @exec: {"max_output_tokens": ' . self::NESTED_CATALOG_MAX_OUTPUT_TOKENS . '}',
            'if (!Array.isArray(ALL_TOOLS)) throw new Error("Native nested tool registry is unavailable");',
            'const excluded = new Set(' . $excludedJson . ');',
            self::bridgeRegistryGuardProgram(),
            'const search = ' . $search . ';',
            'const exactName = ' . json_encode($exact ? $query : null, JSON_THROW_ON_ERROR) . ';',
            'const matchScore = tool => {',
            '  if (exactName !== null) return tool.name === exactName ? 10000 : 0;',
            '  const name = tool.name.toLowerCase();',
            '  const description = tool.description.toLowerCase();',
            '  if (!search.phrase) return 1;',
            '  const exact = (name + "\\n" + description).includes(search.phrase);',
            '  if (!search.expanded) return exact ? 10000 : 0;',
            '  let score = exact ? 10000 : 0;',
            '  for (const term of search.terms) {',
            '    if (name.includes(term)) score += 8;',
            '    else if (description.includes(term)) score += 2;',
            '  }',
            '  if (search.browser_intent && (name === "mcp__cua_repl__js"',
            '      || description.includes("cua.gettab"))) score += 2000;',
            '  return score;',
            '};',
            'const matches = ALL_TOOLS.filter(tool => typeof tool?.name === "string"',
            '  && /^[A-Za-z0-9_$]+$/.test(tool.name) && !excluded.has(tool.name) && !isBridge(tool.name))',
            '  .map((tool, index) => ({name:tool.name,',
            '    description:typeof tool.description === "string" ? tool.description : "", index}))',
            '  .map(tool => ({...tool, score:matchScore(tool)}))',
            '  .filter(tool => tool.score > 0)',
            '  .sort((a,b) => b.score - a.score || a.index - b.index)',
            '  .map(({name,description}) => ({name,description}));',
            'text(' . json_encode($marker, JSON_THROW_ON_ERROR) . ' + JSON.stringify({tools:matches.slice(' . $offset . ',' . ($offset + $limit) . '), total:matches.length}));',
        ]);
    }

    /** Decode exactly one identified catalog from native text or encoded content parts. */
    private static function nestedCatalogFromHostResult(array $native, string $marker): ?array
    {
        $blocks = $native['__mcp_result']['content'] ?? null;
        if (!is_array($blocks) || $marker === '' || !empty($native['__mcp_result']['isError'])) return null;
        $records = [];
        foreach ($blocks as $block) {
            if (!is_array($block) || ($block['type'] ?? null) !== 'text' || !is_string($block['text'] ?? null)) continue;
            $texts = [$block['text']];
            // Older hosts encode content parts as a JSON list; newer hosts may add
            // timing headers around that list. Never interpret arbitrary JSON objects.
            $candidates = array_merge([$block['text']], preg_split('/\r?\n/', $block['text']) ?: []);
            foreach (array_unique($candidates) as $candidate) {
                $decoded = json_decode($candidate, true);
                if (!is_array($decoded) || !array_is_list($decoded)) continue;
                foreach ($decoded as $part) {
                    if (is_array($part) && in_array(($part['type'] ?? null), ['input_text', 'output_text', 'text'], true)
                        && is_string($part['text'] ?? null)) $texts[] = $part['text'];
                }
            }
            foreach ($texts as $text) {
                foreach (preg_split('/\r?\n/', $text) ?: [] as $line) {
                    if (str_starts_with($line, $marker)) $records[] = substr($line, strlen($marker));
                }
            }
        }
        if (count($records) !== 1) return null;
        $page = json_decode($records[0], true);
        return is_array($page) && is_int($page['total'] ?? null) && is_array($page['tools'] ?? null) ? $page : null;
    }

    /** Refuse a valid-looking catalog that silently lost entries at the host output boundary. */
    private static function nestedCatalogPageIsComplete(?array $catalog, int $offset, int $limit): bool
    {
        if (!is_array($catalog) || !is_int($catalog['total'] ?? null)
            || $catalog['total'] < 0 || !is_array($catalog['tools'] ?? null)
            || !array_is_list($catalog['tools'])) return false;
        return count($catalog['tools']) === min($limit, max(0, $catalog['total'] - $offset));
    }

    /** Preserve text and images from a native Codex tool result for this same Web response. */
    private static function hostContentBlocks(string $output, bool $outputIsContentParts): array
    {
        if (!$outputIsContentParts) return [['type'=>'text', 'text'=>$output]];
        $decoded = json_decode($output, true);
        if (!is_array($decoded) || !array_is_list($decoded) || $decoded === []) {
            return [['type'=>'text', 'text'=>$output]];
        }
        $blocks = [];
        $hasImage = false;
        foreach ($decoded as $part) {
            if (!is_array($part)) return [['type'=>'text', 'text'=>$output]];
            $type = (string)($part['type'] ?? '');
            if (in_array($type, ['input_text', 'output_text', 'text'], true)
                && is_string($part['text'] ?? null)) {
                $blocks[] = ['type'=>'text', 'text'=>$part['text']];
                continue;
            }
            if ($type === 'refusal' && is_string($part['refusal'] ?? null)) {
                $blocks[] = ['type'=>'text', 'text'=>'[refusal: ' . $part['refusal'] . ']'];
                continue;
            }
            if ($type === 'encrypted_content') {
                $blocks[] = ['type'=>'text', 'text'=>'[encrypted content omitted]'];
                continue;
            }
            if (in_array($type, ['image', 'input_image'], true)) {
                if (is_string($part['data'] ?? null) && is_string($part['mimeType'] ?? null)
                    && preg_match('~\Aimage/[A-Za-z0-9.+-]+\z~', $part['mimeType']) === 1
                    && base64_decode($part['data'], true) !== false) {
                    $blocks[] = ['type'=>'image', 'data'=>$part['data'], 'mimeType'=>$part['mimeType']];
                    $hasImage = true;
                    continue;
                }
                $url = $part['imageUrl'] ?? $part['image_url'] ?? null;
                if (is_string($url) && preg_match('~\Adata:(image/[A-Za-z0-9.+-]+);base64,([A-Za-z0-9+/=]+)\z~', $url, $match) === 1
                    && base64_decode($match[2], true) !== false) {
                    $blocks[] = ['type'=>'image', 'data'=>$match[2], 'mimeType'=>$match[1]];
                    $hasImage = true;
                    continue;
                }
                if (is_string($url) && preg_match('~\Ahttps?://~i', $url) === 1) {
                    $blocks[] = ['type'=>'resource_link', 'uri'=>$url, 'name'=>'Codex tool image', 'mimeType'=>'image/*'];
                    $hasImage = true;
                    continue;
                }
            }
            // Preserve unrecognized or malformed native content exactly, without guessing.
            return [['type'=>'text', 'text'=>$output]];
        }
        if (!$hasImage) {
            return [['type'=>'text', 'text'=>implode('', array_column($blocks, 'text'))]];
        }
        return $blocks;
    }

    private static function supportedArguments(array $tool, array $arguments): array
    {
        $schema = $tool['parameters'] ?? null;
        $properties = is_array($schema) && is_array($schema['properties'] ?? null) ? $schema['properties'] : null;
        if ($properties === null || (($schema['additionalProperties'] ?? true) !== false)) return $arguments;
        return array_intersect_key($arguments, $properties);
    }

    private static function patchArguments(array $tool, string $patch): array
    {
        $properties = is_array($tool['parameters']['properties'] ?? null) ? $tool['parameters']['properties'] : [];
        if (array_key_exists('patch', $properties)) return ['patch' => $patch];
        if (array_key_exists('input', $properties)) return ['input' => $patch];
        return ['patch' => $patch];
    }

    private static function rpcCallKey(string $token, mixed $rpcId, string $name): string
    {
        // JSON-RPC IDs are scoped to one stdio connection. Distinct connections can both use 0
        // for sibling calls in the same Responses round, while an exact replay on one connection
        // must retain its original idempotency key.
        $responseId = ToolBroker::currentResponseForToken($token);
        $encoded = json_encode($rpcId, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        return 'mcp-' . substr(hash('sha256', $responseId . "\n" . (self::$sessionScope ?? 'unscoped')
            . "\n" . $name . "\n" . $encoded), 0, 48);
    }

    private static function requiredString(array $arguments, string $name, int $max): string
    {
        $value = $arguments[$name] ?? null;
        if (!is_string($value) || $value === '' || strlen($value) > $max) {
            throw new GatewayError('tool_arguments_invalid', $name . ' 缺失或长度无效。');
        }
        return $value;
    }

    private static function optionalString(array $arguments, string $name, int $max): string
    {
        $value = $arguments[$name] ?? null;
        if (!is_string($value) || strlen($value) > $max) throw new GatewayError('tool_arguments_invalid', $name . ' 格式无效。');
        return $value;
    }

    private static function optionalInt(array $arguments, string $name, int $min, int $max): int
    {
        $value = $arguments[$name] ?? null;
        if (!is_int($value) || $value < $min || $value > $max) throw new GatewayError('tool_arguments_invalid', $name . ' 超出允许范围。');
        return $value;
    }

    /** Keep a native session poll shorter than its configured MCP host-result wait. */
    private static function writeStdinYieldMaxMs(): int
    {
        $timeoutMs = (int) GatewaySettings::internal()['mcp_invoke_timeout_ms'];
        $reserveMs = min(self::WRITE_STDIN_YIELD_RESERVE_MS,
            max(self::MIN_WRITE_STDIN_YIELD_RESERVE_MS, intdiv($timeoutMs, 2)));
        return min(self::MAX_WRITE_STDIN_YIELD_MS,
            max(250, $timeoutMs - $reserveMs));
    }

    private static function turnToken(array $arguments): string
    {
        $token = $arguments['turn_token'] ?? null;
        if (!is_string($token) || $token === '') throw new GatewayError('turn_token_required', '缺少本回合 turn_token。');
        return $token;
    }

    /** Public ABI mirrors the installed Automatic/Full synchronous native tool contract. */
    private static function tools(): array
    {
        $turnToken = ['type' => 'string', 'minLength' => 20, 'maxLength' => 256];
        $tools = [
            [
                'name' => 'codex_exec',
                'title' => 'Run a native Codex command',
                'description' => 'Invoke the command tool advertised by the current outer Codex harness. A long-running command returns its native session_id.',
                'inputSchema' => [
                    '$schema' => 'http://json-schema.org/draft-07/schema#', 'type' => 'object',
                    'properties' => [
                        'turn_token' => $turnToken,
                        'cmd' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 100000],
                        'workdir' => ['type' => 'string', 'maxLength' => 16384],
                        'yield_time_ms' => ['type' => 'integer', 'minimum' => 250, 'maximum' => 30000],
                        'max_output_tokens' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 1000000],
                        'tty' => ['type' => 'boolean'],
                        'sandbox_permissions' => [
                            'type' => 'string',
                            'enum' => ['use_default', 'require_escalated'],
                            'description' => 'Native Codex sandbox request, only when the current command tool supports it. Codex decides whether to approve.',
                        ],
                        'justification' => [
                            'type' => 'string',
                            'description' => 'Approval question for a native require_escalated request; omit otherwise.',
                        ],
                        'prefix_rule' => [
                            'type' => 'array',
                            'items' => ['type' => 'string'],
                            'description' => 'Optional native approval prefix for require_escalated; Codex owns its approval and persistence.',
                        ],
                    ],
                    'required' => ['turn_token', 'cmd'],
                ],
                'annotations' => [
                    'readOnlyHint' => false,
                    'destructiveHint' => true,
                    'idempotentHint' => false,
                    'openWorldHint' => true,
                ],
            ],
            [
                'name' => 'codex_write_stdin',
                'title' => 'Continue a native Codex command session',
                'description' => 'Write characters to, or poll, a session_id returned by codex_exec.',
                'inputSchema' => [
                    '$schema' => 'http://json-schema.org/draft-07/schema#', 'type' => 'object',
                    'properties' => [
                        'turn_token' => $turnToken,
                        'session_id' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 9007199254740991],
                        'chars' => ['type' => 'string', 'maxLength' => 1000000],
                        'yield_time_ms' => ['type' => 'integer', 'minimum' => 250, 'maximum' => 300000],
                        'max_output_tokens' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 1000000],
                    ],
                    'required' => ['turn_token', 'session_id'],
                ],
                'annotations' => [
                    'readOnlyHint' => false,
                    'destructiveHint' => true,
                    'idempotentHint' => false,
                    'openWorldHint' => true,
                ],
            ],
            [
                'name' => 'codex_apply_patch',
                'title' => 'Apply a native Codex patch',
                'description' => 'Invoke the outer Codex apply_patch tool, producing a native file-change item in the Codex task.',
                'inputSchema' => [
                    '$schema' => 'http://json-schema.org/draft-07/schema#', 'type' => 'object',
                    'properties' => [
                        'turn_token' => $turnToken,
                        'patch' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 5000000],
                    ],
                    'required' => ['turn_token', 'patch'],
                ],
                'annotations' => [
                    'readOnlyHint' => false,
                    'destructiveHint' => true,
                    'idempotentHint' => false,
                    'openWorldHint' => false,
                ],
            ],
            [
                'name' => 'codex_view_image',
                'title' => 'View an image through native Codex',
                'description' => 'Invoke the outer Codex view_image tool and return its multimodal result to this same ChatGPT response.',
                'inputSchema' => [
                    '$schema' => 'http://json-schema.org/draft-07/schema#', 'type' => 'object',
                    'properties' => [
                        'turn_token' => $turnToken,
                        'path' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 16384],
                        'detail' => ['type' => 'string', 'enum' => ['high', 'original']],
                    ],
                    'required' => ['turn_token', 'path'],
                ],
                'annotations' => [
                    'readOnlyHint' => true,
                    'destructiveHint' => false,
                    'idempotentHint' => true,
                    'openWorldHint' => false,
                ],
            ],
            [
                'name' => 'codex_tool_inventory',
                'title' => 'Discover tools from the current Codex harness',
                'description' => 'Search the tool registry supplied to the current outer Codex turn, including configured MCP/app tools. '
                    . 'For a known wire_name, set query to that name and exact=true: an already bound tool returns its current schema without a native discovery call. '
                    . 'Otherwise discovery still searches the current native nested registry. Reuse discovered definitions within this turn unless the runtime reports a capability change.',
                'inputSchema' => [
                    '$schema' => 'http://json-schema.org/draft-07/schema#', 'type' => 'object',
                    'properties' => [
                        'turn_token' => $turnToken,
                        'query' => ['type' => 'string', 'maxLength' => 500],
                        'exact' => ['type' => 'boolean', 'default' => false,
                            'description' => 'Match query as a case-sensitive wire_name, not descriptive text. Requires a nonempty query. Default false preserves keyword search.'],
                        'offset' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100000, 'default' => 0],
                        'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50, 'default' => 20],
                        'include_schema' => ['type' => 'boolean', 'default' => true],
                    ],
                    'required' => ['turn_token'],
                ],
                'annotations' => [
                    'readOnlyHint' => true,
                    'destructiveHint' => false,
                    'idempotentHint' => true,
                    'openWorldHint' => false,
                ],
            ],
            [
                'name' => 'codex_tool_call',
                'title' => 'Call any tool from the current Codex harness',
                'description' => 'Invoke an exact wire_name returned by codex_tool_inventory. The outer Codex runtime performs the call, approvals, and UI lifecycle. '
                    . 'When an issued call returns pending, use the reserved codex.control.wait_result operation with arguments {call_id} and the same turn_token to wait for that exact result. It never dispatches the action again. '
                    . 'A pending context-compaction request can also provide the reserved codex.control.compaction_handoff operation, which is not listed by inventory. '
                    . 'Use only that request\'s issued control token and arguments {handoff_id, summary}. This operation submits the conversation summary to the pending Codex task; it does not execute commands, access files, or invoke other tools.',
                'inputSchema' => [
                    '$schema' => 'http://json-schema.org/draft-07/schema#', 'type' => 'object',
                    'properties' => [
                        'turn_token' => $turnToken,
                        'wire_name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 1000],
                        'arguments' => ['type' => 'object', 'propertyNames' => ['type' => 'string'],
                            'additionalProperties' => new \stdClass(), 'default' => new \stdClass()],
                        'input' => ['type' => 'string', 'maxLength' => 5000000],
                    ],
                    'required' => ['turn_token', 'wire_name'],
                ],
                'annotations' => [
                    'readOnlyHint' => false,
                    'destructiveHint' => true,
                    'idempotentHint' => false,
                    'openWorldHint' => true,
                ],
            ],
        ];
        foreach ($tools as &$tool) {
            // This is MCP asynchronous task negotiation, not the duration of a Codex project.
            $tool['execution'] = ['taskSupport' => 'forbidden'];
        }
        unset($tool);
        return $tools;
    }

    private static function ok(mixed $id, mixed $result): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    private static function rpcError(mixed $id, int $code, string $message, ?array $data = null): array
    {
        $error = ['code' => $code, 'message' => $message];
        if ($data !== null) $error['data'] = $data;
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => $error];
    }
}
