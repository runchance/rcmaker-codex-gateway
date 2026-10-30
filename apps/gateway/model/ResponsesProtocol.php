<?php
declare(strict_types=1);
namespace app\gateway\model;

/** Readable native Codex Responses contract and local browser/tool execution identity. */
final class ResponsesProtocol
{
    public const MODEL_ID = 'chatgpt-web/current';
    // Request compression is negotiated from the running PHP decoder. This limit applies to
    // both wire and decoded bytes, including legacy uncompressed clients with long image/tool
    // histories. Per-attachment and per-tool limits still apply below.
    public const MAX_BODY_BYTES = 256 * 1024 * 1024;

    /**
     * Answer the desktop's explicitly app-generated composer prediction locally.
     * This is not task continuation or intent classification: the native request
     * declares that the human did not write it and permits a null suggestion.
     * Returning that empty suggestion avoids uploading a finished task again or
     * spending the user's selected Pro quota on an invisible background feature.
     * @param string $raw Decoded native JSON after HTTP turn metadata was merged.
     * @return ?array{stream:bool,response:array,events:array} Null for every ordinary request.
     */
    public static function localComposerSuggestion(string $raw): ?array
    {
        if ($raw === '' || strlen($raw) > self::MAX_BODY_BYTES) return null;
        try { $body = json_decode($raw, false, 64, JSON_THROW_ON_ERROR); }
        catch (\Throwable) { return null; }
        if (!$body instanceof \stdClass || !is_string($body->model ?? null)
            || !str_starts_with($body->model, 'chatgpt-web/') || !is_array($body->input ?? null)
            || $body->input === [] || (isset($body->stream) && !is_bool($body->stream))) return null;
        try { $metadata = self::turnMetadata($body->client_metadata ?? null); }
        catch (GatewayError) { return null; }
        if (empty($metadata['thread_id']) || empty($metadata['turn_id'])
            || !in_array($metadata['client_id'] ?? 'codex', ['codex'], true)) return null;
        $lastIndex = array_key_last($body->input);
        $controls = [];
        // Environment/effort controls are not user messages. Defer their normal validation
        // until the explicit app contract is proven, avoiding extra tool-catalog work on real turns.
        while ($lastIndex >= 0 && in_array($body->input[$lastIndex]->type ?? null, ['configuration_update','additional_tools'], true)) {
            $controls[] = $body->input[$lastIndex];
            $lastIndex--;
        }
        if ($lastIndex < 0) return null;
        $last = $body->input[$lastIndex];
        if (!$last instanceof \stdClass || ($last->role ?? null) !== 'user'
            || (isset($last->type) && $last->type !== 'message')) return null;
        if (is_string($last->content ?? null)) $text = $last->content;
        else {
            if (!is_array($last->content ?? null) || count($last->content) !== 1) return null;
            $part = $last->content[0];
            if (!$part instanceof \stdClass || ($part->type ?? null) !== 'input_text'
                || !is_string($part->text ?? null)) return null;
            $text = $part->text;
        }
        if (!str_starts_with($text,
            "This is an app-generated request for a suggested next user message. The user did not write this message.\n\n")
            || !str_contains($text, "Your suggestion will appear in the user's empty message composer. They can accept it, edit it, or ignore it; it will not be sent automatically.")
            || !preg_match('/Return (?:only )?JSON matching this schema:\s*(\{[^\n]+\})\.\s*$/D', $text, $match)) return null;
        $schema = json_decode($match[1], true);
        if (!is_array($schema) || ($schema['type'] ?? null) !== 'object'
            || ($schema['required'] ?? null) !== ['suggestion'] || ($schema['additionalProperties'] ?? null) !== false
            || !is_array($schema['properties'] ?? null)
            || array_keys($schema['properties'] ?? []) !== ['suggestion']
            || !is_array($schema['properties']['suggestion'])
            // Codex emits either nullable-string spelling. Match only equivalent unconstrained
            // shapes so a contract that forbids null still follows normal model handling.
            || !in_array($schema['properties']['suggestion'], [
                ['anyOf'=>[['type'=>'string'], ['type'=>'null']]],
                ['anyOf'=>[['type'=>'null'], ['type'=>'string']]],
                ['type'=>['string','null']],
                ['type'=>['null','string']],
            ], true)) return null;
        try {
            foreach ($controls as $control) {
                if ($control->type === 'configuration_update') self::configurationEffort(get_object_vars($control));
                else {
                    if (!is_array($control->tools ?? null)) return null;
                    self::mergeToolInventories([$control->tools]);
                }
            }
        } catch (\Throwable) { return null; }
        $key = substr(hash('sha256', $raw), 0, 24);
        $responseId = 'resp_' . $key;
        $itemId = 'msg_' . $key;
        $answer = '{"suggestion":null}';
        $events = array_merge(self::initialEvents($responseId), self::openOutputEvents($itemId),
            [self::deltaEvent($itemId, $answer)], self::completionEvents($responseId, $itemId, $answer));
        foreach ($events as $sequence => &$event) {
            $event['sequence_number'] = $sequence;
            if (isset($event['response'])) {
                $event['response']['model'] = $body->model;
                $event['response']['usage'] = ['input_tokens'=>0, 'output_tokens'=>0, 'total_tokens'=>0];
            }
        }
        unset($event);
        return ['stream'=>($body->stream ?? false) === true,
            'response'=>$events[array_key_last($events)]['response'], 'events'=>$events];
    }

    /**
     * Recheck an explicitly app-generated suggestion after supported native wire encodings are normalized.
     * The same identity, composer contract and nullable-schema proof remains mandatory; receipts and
     * compaction are never intercepted. Only the final text is projected, not the archived conversation.
     * @param array $spec Validated Responses context and native identity. @return ?array Local reply or null.
     */
    public static function localComposerSuggestionFromContext(array $spec): ?array
    {
        if (($spec['operation_kind'] ?? 'response') !== 'response' || (array)($spec['tool_outputs'] ?? []) !== []) return null;
        $contextJson = $spec['context_json'] ?? null;
        if (!is_string($contextJson) || !str_contains($contextJson,
            'This is an app-generated request for a suggested next user message.')) return null;
        try { $context = json_decode($contextJson, true, 128, JSON_THROW_ON_ERROR); }
        catch (\Throwable) { return null; }
        $messages = $context['messages'] ?? [];
        $last = is_array($messages) && $messages !== [] ? $messages[array_key_last($messages)] : null;
        if (!is_array($last) || ($last['role'] ?? '') !== 'user' || !is_string($last['content'] ?? null)) return null;
        // Avoid copying ordinary task histories or formatting arbitrary messages into this special path.
        if (!str_starts_with($last['content'], 'This is an app-generated request for a suggested next user message.')) return null;
        return self::localComposerSuggestion(json_encode([
            'model'=>$spec['model'] ?? '', 'stream'=>$spec['stream'] ?? false,
            'input'=>[['role'=>'user', 'content'=>$last['content']]],
            'client_metadata'=>['x-codex-turn-metadata'=>[
                'client_id'=>$spec['client_id'] ?? '', 'thread_id'=>$spec['thread_id'] ?? null, 'turn_id'=>$spec['turn_id'] ?? null]],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /** Parse supported Codex wire fields while preserving history, host-tool permissions and browser identity. */
    public static function parse(string $raw): array
    {
        return TaskMaintenance::withAdmission(static fn(): array => self::parseAdmitted($raw));
    }

    /** Parse under maintenance admission; route native effort controls while preserving task records and the original request hash. */
    private static function parseAdmitted(string $raw): array
    {
        if ($raw === '' || strlen($raw) > self::MAX_BODY_BYTES) {
            throw new GatewayError('invalid_request_error', '请求正文为空或超过网关请求大小限制。', 400);
        }
        try {
            $object = json_decode($raw, false, 64, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw new GatewayError('invalid_json', '请求正文必须是有效的 JSON 对象。', 400);
        }
        if (!$object instanceof \stdClass) {
            throw new GatewayError('invalid_request_error', '请求正文必须是 JSON 对象。', 400);
        }
        $data = get_object_vars($object);
        $allowed = [
            'model', 'input', 'instructions', 'stream', 'client_metadata', 'reasoning',
            'prompt_cache_key', 'previous_response_id', 'tools', 'tool_choice', 'parallel_tool_calls',
            'store', 'include', 'text', 'metadata', 'service_tier', 'max_output_tokens', 'stream_options', 'access_programs',
        ];
        $unknown = array_values(array_diff(array_keys($data), $allowed));
        if ($unknown !== []) {
            throw new GatewayError('unsupported_parameter', '当前 Web 模型适配尚不支持参数：' . implode(', ', $unknown), 400);
        }
        self::validateAccessPrograms($data['access_programs'] ?? null);
        if (!isset($data['model']) || !is_string($data['model']) || !str_starts_with($data['model'], 'chatgpt-web/')) {
            throw new GatewayError('model_not_supported', '此请求需要有效的 Web 模型标识。', 400);
        }
        if (!array_key_exists('input', $data)) {
            throw new GatewayError('invalid_request_error', '缺少 input。', 400);
        }
        if (isset($data['instructions']) && !is_string($data['instructions'])) {
            throw new GatewayError('invalid_request_error', 'instructions 必须是字符串。', 400);
        }
        if (isset($data['stream']) && !is_bool($data['stream'])) {
            throw new GatewayError('invalid_request_error', 'stream 必须是布尔值。', 400);
        }
        if (isset($data['parallel_tool_calls']) && !is_bool($data['parallel_tool_calls'])) {
            throw new GatewayError('invalid_request_error', 'parallel_tool_calls 必须是布尔值。', 400);
        }
        $metadata = self::turnMetadata($data['client_metadata'] ?? null);
        $requestReasoning = self::reasoningEffort($data['reasoning'] ?? null);
        $configuration = self::inputReasoningConfiguration($data['input']);
        $reasoning = $configuration['effort'] ?? $requestReasoning;
        $route = ModelCatalog::identity($data['model'], $reasoning);
        $reasoning = $route['effort'];
        $budget = ContextBudget::snapshot($data['model'], $reasoning, $route['capabilities']);
        if ($configuration['effort'] !== null) {
            $budget['reasoning_configuration'] = ['model'=>$data['model'],
                'effort'=>$configuration['effort'], 'request_effort'=>$requestReasoning];
        }
        $outputControl = self::textControls($data['text'] ?? null);
        if ($outputControl !== []) $budget['output_control'] = $outputControl;
        $promptCacheKey = self::optionalIdentity($data['prompt_cache_key'] ?? null, 'prompt_cache_key');
        // Recent Codex sends the current tool inventory as an input control item.
        // It belongs to the executable environment, never to user-authored history.
        $toolInventories = [$data['tools'] ?? []];
        $toolsProvided = array_key_exists('tools', $data);
        $nativeResponsesLite = false;
        if (is_array($data['input'])) foreach ($data['input'] as $item) {
            if ($item instanceof \stdClass && ($item->type ?? '') === 'additional_tools') {
                $nativeResponsesLite = true;
                $toolsProvided = true;
                if (!is_array($item->tools ?? null)) throw new GatewayError('invalid_request_error', 'additional_tools.tools 必须是数组。', 400);
                $toolInventories[] = $item->tools;
            }
            if ($item instanceof \stdClass && ($item->type ?? '') === 'tool_search_output'
                && is_array($item->tools ?? null)) {
                // Discovered client tools remain callable even when Codex omits them from
                // the next request's top-level tools list.
                $toolsProvided = true;
                $toolInventories[] = $item->tools;
            }
        }
        $tools = self::mergeToolInventories($toolInventories);
        $toolOutputs = self::toolOutputs($data['input']);
        $attachments = [];
        $settings = GatewaySettings::internal();
        $context = self::retireTransportHandles(ContextHistory::compile(
            $data['input'],
            isset($data['instructions']) ? (string) $data['instructions'] : '',
            $attachments,
            (string) ($settings['skill_transport'] ?? 'inline')
        ));
        $contextJson = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $attachments = AttachmentStore::selectForContext($attachments, $contextJson);
        $prompt = ContextHistory::plainPrompt($context);
        // Native receipts return directly to MCP, and complete histories can span multiple
        // physical messages. The transport planner owns each actual editor envelope.
        if ($prompt === '' && $toolOutputs === []) {
            throw new GatewayError('invalid_request_error', '输入为空。', 400);
        }
        $toolEnvironment = self::toolEnvironment($metadata, $tools);
        $toolEnvironmentJson = json_encode($toolEnvironment, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $toolEnvironmentHash = hash('sha256', $toolEnvironmentJson);
        $requestHash = hash('sha256', ContextHistory::canonical($object));
        $threadId = self::optionalIdentity($metadata['thread_id'] ?? null, 'thread_id');
        $turnId = self::optionalIdentity($metadata['turn_id'] ?? null, 'turn_id');
        $clientId = self::optionalIdentity($metadata['client_id'] ?? null, 'client_id') ?? (($threadId !== null || $turnId !== null) ? 'codex' : 'responses');
        $branchId = self::optionalIdentity($metadata['branch_id'] ?? null, 'branch_id') ?? 'main';
        $previousResponseId = self::optionalIdentity($data['previous_response_id'] ?? null, 'previous_response_id');
        $owner = $threadId !== null
            ? ['kind' => 'thread', 'id' => $threadId]
            : ($promptCacheKey !== null
                ? ['kind' => 'prompt_cache', 'id' => $promptCacheKey]
                : ($turnId !== null ? ['kind' => 'turn', 'id' => $turnId] : ['kind' => 'legacy', 'id' => 'shared']));
        $ownerKey = hash('sha256', json_encode($owner, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $compactionEpoch = self::compactionEpoch($data['input']);
        $leaseKey = hash('sha256', json_encode([
            'namespace' => 'rcmaker-chatgpt-web-v1',
            'owner' => $ownerKey,
            'model' => $data['model'],
            'reasoning' => $reasoning,
            'compaction' => $compactionEpoch,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        return [
            'model' => $data['model'],
            'prompt' => $prompt,
            'context_json' => $contextJson,
            'full_context_json' => $contextJson,
            // Lite rebuilds prompt-only base instructions and removes image detail hints.
            // Keep that wire-mode evidence outside the user-facing task context.
            'native_responses_lite' => $nativeResponsesLite,
            'budget' => $budget,
            'operation_kind' => is_array($data['input']) && (($data['input'][array_key_last($data['input'])]->type ?? null) === 'compaction_trigger') ? 'compact_v2' : 'response',
            'stream' => (bool) ($data['stream'] ?? false),
            'request_hash' => $requestHash,
            'thread_id' => $threadId,
            'turn_id' => $turnId,
            'reasoning_effort' => $reasoning,
            'request_reasoning_effort' => $requestReasoning,
            'reasoning_configuration_reset' => $configuration['reset'],
            'compaction_epoch' => $compactionEpoch,
            'browser_owner_key' => $ownerKey,
            'browser_lease_key' => $leaseKey,
            'client_id' => $clientId,
            'branch_id' => $branchId,
            'task_key' => hash('sha256', json_encode(['client' => $clientId, 'owner' => $ownerKey, 'branch' => $branchId], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
            'previous_response_id' => $previousResponseId,
            'tools' => $tools,
            'tools_provided' => $toolsProvided,
            'tool_environment_provided' => array_key_exists('tool_environment', $metadata),
            'tool_outputs' => $toolOutputs,
            'tool_environment' => $toolEnvironment,
            'tool_environment_json' => $toolEnvironmentJson,
            'tool_environment_hash' => $toolEnvironmentHash,
            'tool_choice' => self::toolChoice($data['tool_choice'] ?? null),
            'parallel_tool_calls' => (bool) ($data['parallel_tool_calls'] ?? true),
            'parallel_tool_calls_provided' => array_key_exists('parallel_tool_calls', $data),
            'input_is_delta' => is_string($data['input']),
            'attachments' => $attachments,
        ];
    }

    /**
     * Accept Codex's ordinary-access selector without treating it as text or host-tool authority.
     * Native enhanced access programs require the native backend and must not be silently downgraded.
     * @throws GatewayError On malformed selectors or a nonstandard native-only program.
     */
    private static function validateAccessPrograms(mixed $programs): void
    {
        if ($programs === null) return;
        if (!$programs instanceof \stdClass || array_diff(array_keys(get_object_vars($programs)), ['cyber']) !== []) {
            throw new GatewayError('invalid_request_error', 'access_programs 必须是只包含 cyber 选择的对象。', 400);
        }
        if (get_object_vars($programs) === []) return;
        // The framework string validator casts before checking; reject non-scalar JSON first.
        if (!is_string($programs->cyber ?? null)) {
            throw new GatewayError('invalid_request_error', 'access_programs.cyber 必须是有效的字符串。', 400);
        }
        try {
            $cyber = validator()->check($programs->cyber, ['rule' => 'string', 'len' => [1, 32], 'name' => 'access_programs.cyber']);
        } catch (\Throwable) {
            throw new GatewayError('invalid_request_error', 'access_programs.cyber 必须是有效的字符串。', 400);
        }
        if ($cyber !== 'standard') {
            throw new GatewayError('unsupported_access_program', '当前 Web 模型支持 standard 普通访问模式；此原生专用访问模式请使用 Codex 原生模型，或切换为 standard 后重试。', 400);
        }
    }

    public static function error(string $message, string $code, ?string $param = null, string $type = 'invalid_request_error'): array
    {
        return ['error' => ['message' => $message, 'type' => $type, 'param' => $param, 'code' => $code]];
    }

    /**
     * Keep physical Web message limits distinct from the logical context-window limit.
     * Codex replaces context_length_exceeded with a start-a-new-thread instruction;
     * one rejected upload, including an inert stage, does not establish that condition.
     */
    public static function clientFailure(array $body, int $status = 502): array
    {
        if (in_array($body['error']['code'] ?? '', [
            'CHATGPT_MESSAGE_TOO_LARGE', 'CHATGPT_CONTEXT_TOO_LARGE', 'context_length_exceeded',
        ], true)) {
            $body['error']['type'] = 'invalid_request_error';
            $status = 400;
        }
        return ['status' => $status, 'body' => $body];
    }

    public static function response(string $responseId, string $itemId, string $status, string $text, ?bool $endTurn = null): array
    {
        $output = [];
        if ($text !== '') {
            $output[] = self::messageItem($itemId, $status === 'completed' ? 'completed' : 'in_progress', $text);
        }
        $response = [
            'id' => $responseId,
            'object' => 'response',
            'created_at' => time(),
            'status' => $status,
            'model' => self::MODEL_ID,
            'output' => $output,
            'usage' => null,
        ];
        if ($endTurn !== null) $response['end_turn'] = $endTurn;
        return $response;
    }

    public static function responseWithOutput(string $responseId, string $status, array $output, ?bool $endTurn = null): array
    {
        $response = [
            'id' => $responseId,
            'object' => 'response',
            'created_at' => time(),
            'status' => $status,
            'model' => self::MODEL_ID,
            'output' => array_values($output),
            'usage' => null,
        ];
        if ($endTurn !== null) $response['end_turn'] = $endTurn;
        return $response;
    }

    public static function functionCallItem(
        string $itemId,
        string $callId,
        string $name,
        string $arguments,
        ?string $namespace = null
    ): array {
        $item = [
            'type' => 'function_call',
            'id' => $itemId,
            'call_id' => $callId,
            'name' => $name,
            'arguments' => $arguments === '' ? '{}' : $arguments,
            'status' => 'completed',
        ];
        if ($namespace !== null && $namespace !== '') $item['namespace'] = $namespace;
        return $item;
    }

    public static function customToolCallItem(
        string $itemId,
        string $callId,
        string $name,
        string $input
    ): array {
        return [
            'type' => 'custom_tool_call',
            'id' => $itemId,
            'call_id' => $callId,
            'name' => $name,
            'input' => $input,
            'status' => 'completed',
        ];
    }

    /** Build one native tool item from a verified broker invocation. */
    public static function brokerToolItem(array $invocation, string $itemId): array
    {
        $callId = (string) $invocation['call_id'];
        $name = (string) $invocation['name'];
        if (!empty($invocation['tool_search'])) {
            $arguments = json_decode((string) ($invocation['arguments'] ?? '{}'), true);
            return ['type'=>'tool_search_call', 'id'=>$itemId, 'call_id'=>$callId,
                'execution'=>'client', 'arguments'=>is_array($arguments) && !array_is_list($arguments) ? $arguments : new \stdClass(),
                'status'=>'completed'];
        }
        if (!empty($invocation['freeform'])) {
            return self::customToolCallItem($itemId, $callId, $name, (string) ($invocation['input'] ?? ''));
        }
        $namespace = is_string($invocation['namespace'] ?? null) ? $invocation['namespace'] : null;
        if ($namespace === 'functions') $namespace = null;
        return self::functionCallItem($itemId, $callId, $name, (string) ($invocation['arguments'] ?? '{}'), $namespace);
    }

    /**
     * @return array<int,array> One tool item's SSE events, without a response terminal.
     * The caller appends one response.completed after every item in the batch.
     */
    public static function brokerToolItemEvents(array $invocation, string $itemId, int $outputIndex): array
    {
        $item = self::brokerToolItem($invocation, $itemId);
        $pending = $item;
        $pending['status'] = 'in_progress';
        $events = [];
        if ($item['type'] === 'tool_search_call') {
            $pending['arguments'] = new \stdClass();
            $events[] = ['type'=>'response.output_item.added', 'output_index'=>$outputIndex, 'item'=>$pending];
        } elseif ($item['type'] === 'custom_tool_call') {
            $pending['input'] = '';
            $events[] = ['type' => 'response.output_item.added', 'output_index' => $outputIndex, 'item' => $pending];
            if ($item['input'] !== '') {
                $events[] = ['type' => 'response.custom_tool_call_input.delta', 'item_id' => $itemId,
                    'output_index' => $outputIndex, 'delta' => $item['input']];
            }
            $events[] = ['type' => 'response.custom_tool_call_input.done', 'item_id' => $itemId,
                'output_index' => $outputIndex, 'input' => $item['input']];
        } else {
            $pending['arguments'] = '';
            $events[] = ['type' => 'response.output_item.added', 'output_index' => $outputIndex, 'item' => $pending];
            $events[] = ['type' => 'response.function_call_arguments.done', 'item_id' => $itemId,
                'output_index' => $outputIndex, 'arguments' => $item['arguments']];
        }
        $events[] = ['type' => 'response.output_item.done', 'output_index' => $outputIndex, 'item' => $item];
        return $events;
    }

    /** Assistant text carries its native Codex phase; observed progress is separate from the answer. */
    public static function messageItem(string $itemId, string $status, string $text, string $phase = 'final_answer'): array
    {
        return [
            'id' => $itemId,
            'type' => 'message',
            'role' => 'assistant',
            'phase' => $phase,
            'status' => $status,
            'content' => $text === '' ? [] : [['type' => 'output_text', 'text' => $text, 'annotations' => []]],
        ];
    }

    /** One ChatGPT-rendered public summary; it carries no fabricated encrypted reasoning. */
    public static function reasoningSummaryItem(string $itemId, string $text): array
    {
        return [
            'id' => $itemId,
            'type' => 'reasoning',
            'summary' => [['type' => 'summary_text', 'text' => $text]],
        ];
    }

    /** @return array<int,array> Native Responses SSE for one settled public reasoning summary. */
    public static function reasoningSummaryEvents(string $itemId, string $text, int $outputIndex): array
    {
        $part = ['type' => 'summary_text', 'text' => $text];
        return [
            ['type' => 'response.output_item.added', 'output_index' => $outputIndex,
                'item' => ['id' => $itemId, 'type' => 'reasoning', 'summary' => []]],
            ['type' => 'response.reasoning_summary_part.added', 'item_id' => $itemId,
                'output_index' => $outputIndex, 'summary_index' => 0,
                'part' => ['type' => 'summary_text', 'text' => '']],
            ['type' => 'response.reasoning_summary_text.delta', 'item_id' => $itemId,
                'output_index' => $outputIndex, 'summary_index' => 0, 'delta' => $text],
            ['type' => 'response.reasoning_summary_text.done', 'item_id' => $itemId,
                'output_index' => $outputIndex, 'summary_index' => 0, 'text' => $text],
            ['type' => 'response.reasoning_summary_part.done', 'item_id' => $itemId,
                'output_index' => $outputIndex, 'summary_index' => 0, 'part' => $part],
            ['type' => 'response.output_item.done', 'output_index' => $outputIndex,
                'item' => self::reasoningSummaryItem($itemId, $text)],
        ];
    }

    /** @return array<int,array> */
    public static function initialEvents(string $responseId): array
    {
        return [
            ['type' => 'response.created', 'response' => self::response($responseId, '', 'in_progress', '')],
            ['type' => 'response.in_progress', 'response' => self::response($responseId, '', 'in_progress', '')],
        ];
    }

    /** @return array<int,array> */
    public static function openOutputEvents(string $itemId, int $outputIndex = 0, string $phase = 'final_answer'): array
    {
        return [
            ['type' => 'response.output_item.added', 'output_index' => $outputIndex, 'item' => self::messageItem($itemId, 'in_progress', '', $phase)],
            ['type' => 'response.content_part.added', 'item_id' => $itemId, 'output_index' => $outputIndex, 'content_index' => 0, 'part' => ['type' => 'output_text', 'text' => '', 'annotations' => []]],
        ];
    }

    public static function deltaEvent(string $itemId, string $delta, int $outputIndex = 0): array
    {
        return ['type' => 'response.output_text.delta', 'item_id' => $itemId, 'output_index' => $outputIndex, 'content_index' => 0, 'delta' => $delta, 'logprobs' => []];
    }

    /** @return array<int,array> */
    public static function completionEvents(string $responseId, string $itemId, string $text, int $outputIndex = 0, string $phase = 'final_answer'): array
    {
        $item = self::messageItem($itemId, 'completed', $text, $phase);
        $response = self::response($responseId, $itemId, 'completed', $text, true);
        $response['output'] = [$item];
        return [
            ['type' => 'response.output_text.done', 'item_id' => $itemId, 'output_index' => $outputIndex, 'content_index' => 0, 'text' => $text, 'logprobs' => []],
            ['type' => 'response.content_part.done', 'item_id' => $itemId, 'output_index' => $outputIndex, 'content_index' => 0, 'part' => ['type' => 'output_text', 'text' => $text, 'annotations' => []]],
            ['type' => 'response.output_item.done', 'output_index' => $outputIndex, 'item' => $item],
            ['type' => 'response.completed', 'response' => $response],
        ];
    }

    /** Finish any streamed text prelude, emit one exact function_call and end this Responses round. */
    public static function functionCallEvents(
        string $responseId,
        string $messageItemId,
        string $toolItemId,
        string $callId,
        string $name,
        string $arguments,
        string $text,
        ?string $namespace = null
    ): array {
        // Preserve the bridge's existing wire normalization for the built-in group.
        // Native dispatch also depends on Codex's tool router and sandbox readiness.
        if ($namespace === 'functions') $namespace = null;
        $events = [];
        $output = [];
        $outputIndex = 0;
        if ($text !== '') {
            $message = self::messageItem($messageItemId, 'completed', $text);
            $events[] = ['type' => 'response.output_text.done', 'item_id' => $messageItemId, 'output_index' => 0, 'content_index' => 0, 'text' => $text, 'logprobs' => []];
            $events[] = ['type' => 'response.content_part.done', 'item_id' => $messageItemId, 'output_index' => 0, 'content_index' => 0, 'part' => ['type' => 'output_text', 'text' => $text, 'annotations' => []]];
            $events[] = ['type' => 'response.output_item.done', 'output_index' => 0, 'item' => $message];
            $output[] = $message;
            $outputIndex = 1;
        }
        $pending = self::functionCallItem($toolItemId, $callId, $name, '', $namespace);
        $pending['arguments'] = '';
        $pending['status'] = 'in_progress';
        $events[] = ['type' => 'response.output_item.added', 'output_index' => $outputIndex, 'item' => $pending];
        $events[] = [
            'type' => 'response.function_call_arguments.done',
            'item_id' => $toolItemId,
            'output_index' => $outputIndex,
            'arguments' => $arguments === '' ? '{}' : $arguments,
        ];
        $tool = self::functionCallItem($toolItemId, $callId, $name, $arguments, $namespace);
        $events[] = ['type' => 'response.output_item.done', 'output_index' => $outputIndex, 'item' => $tool];
        $output[] = $tool;
        $events[] = ['type' => 'response.completed', 'response' => self::responseWithOutput($responseId, 'completed', $output, false)];
        return $events;
    }

    /** @return array<int,array> Native Responses events for a freeform/custom Codex host tool. */
    public static function customToolCallEvents(
        string $responseId,
        string $messageItemId,
        string $toolItemId,
        string $callId,
        string $name,
        string $input,
        string $text
    ): array {
        $events = [];
        $output = [];
        $outputIndex = 0;
        if ($text !== '') {
            $message = self::messageItem($messageItemId, 'completed', $text);
            $events[] = ['type' => 'response.output_text.done', 'item_id' => $messageItemId, 'output_index' => 0, 'content_index' => 0, 'text' => $text, 'logprobs' => []];
            $events[] = ['type' => 'response.content_part.done', 'item_id' => $messageItemId, 'output_index' => 0, 'content_index' => 0, 'part' => ['type' => 'output_text', 'text' => $text, 'annotations' => []]];
            $events[] = ['type' => 'response.output_item.done', 'output_index' => 0, 'item' => $message];
            $output[] = $message;
            $outputIndex = 1;
        }
        $pending = self::customToolCallItem($toolItemId, $callId, $name, '');
        $pending['status'] = 'in_progress';
        $events[] = ['type' => 'response.output_item.added', 'output_index' => $outputIndex, 'item' => $pending];
        if ($input !== '') {
            $events[] = [
                'type' => 'response.custom_tool_call_input.delta',
                'item_id' => $toolItemId,
                'output_index' => $outputIndex,
                'delta' => $input,
            ];
        }
        $events[] = [
            'type' => 'response.custom_tool_call_input.done',
            'item_id' => $toolItemId,
            'output_index' => $outputIndex,
            'input' => $input,
        ];
        $tool = self::customToolCallItem($toolItemId, $callId, $name, $input);
        $events[] = ['type' => 'response.output_item.done', 'output_index' => $outputIndex, 'item' => $tool];
        $output[] = $tool;
        $events[] = ['type' => 'response.completed', 'response' => self::responseWithOutput($responseId, 'completed', $output, false)];
        return $events;
    }

    public static function failedEvent(string $responseId, string $itemId, string $message, string $code): array
    {
        $response = self::response($responseId, $itemId, 'failed', '');
        $response['error'] = self::clientFailure(self::error($message, $code, null, 'server_error'))['body']['error'];
        return ['type' => 'response.failed', 'response' => $response];
    }

    /** Decode the Codex-owned lifecycle metadata without interpreting user-authored message text. */
    /** Preserve the native final-answer controls as data in the durable Web budget. */
    private static function textControls(mixed $text): array
    {
        if (!$text instanceof \stdClass) return [];
        $control = [];
        if (in_array($text->verbosity ?? null, ['low', 'medium', 'high'], true)) {
            $control['verbosity'] = $text->verbosity;
        }
        $format = $text->format ?? null;
        if ($format instanceof \stdClass && ($format->type ?? null) === 'json_schema'
            && is_string($format->name ?? null) && $format->name !== ''
            && property_exists($format, 'schema')) {
            $control['output_format'] = [
                'name' => $format->name,
                'strict' => ($format->strict ?? false) === true,
                'schema_json' => json_encode($format->schema,
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            ];
        }
        return $control;
    }

    private static function turnMetadata(mixed $clientMetadata): array
    {
        if ($clientMetadata === null) return [];
        if ($clientMetadata instanceof \stdClass) $clientMetadata = get_object_vars($clientMetadata);
        if (!is_array($clientMetadata)) {
            throw new GatewayError('invalid_request_error', 'client_metadata 必须是对象。', 400);
        }
        $raw = $clientMetadata['x-codex-turn-metadata'] ?? null;
        if ($raw === null) return [];
        if (is_string($raw)) {
            if (strlen($raw) > 16384) throw new GatewayError('invalid_request_error', 'Codex 任务元数据过大。', 400);
            try { $raw = json_decode($raw, true, 32, JSON_THROW_ON_ERROR); }
            catch (\Throwable) { throw new GatewayError('invalid_request_error', 'Codex 任务元数据不是有效 JSON。', 400); }
        } elseif ($raw instanceof \stdClass) {
            $raw = get_object_vars($raw);
        }
        if (!is_array($raw)) throw new GatewayError('invalid_request_error', 'Codex 任务元数据格式无效。', 400);
        return $raw;
    }

    private static function reasoningEffort(mixed $reasoning): ?string
    {
        if ($reasoning === null) return null;
        if ($reasoning instanceof \stdClass) $reasoning = get_object_vars($reasoning);
        if (!is_array($reasoning)) throw new GatewayError('invalid_request_error', 'reasoning 必须是对象。', 400);
        $effort = $reasoning['effort'] ?? null;
        if ($effort === null) return null;
        if (!is_string($effort) || !preg_match('/\A[a-z0-9_-]{1,32}\z/i', $effort)) {
            throw new GatewayError('invalid_request_error', 'reasoning.effort 格式无效。', 400);
        }
        return strtolower($effort === 'ultra' ? 'max' : $effort);
    }

    /**
     * Validate an execution control, never chat text or authority to change a tool environment.
     * Empty/null reasoning is a no-op. Agent-scoped controls require native routing, which this
     * single Web response cannot emulate. Keep wire aliases consistent with request reasoning.
     * @param array $item Native configuration_update in object/array-normalized form.
     * @return ?string The selected wire effort, or null when no effort was changed.
     * @throws GatewayError For malformed, unknown or agent-scoped configuration fields.
     */
    public static function configurationEffort(array $item): ?string
    {
        if (array_diff(array_keys($item), ['type', 'id', 'reasoning', 'agent']) !== []
            || (isset($item['id']) && !is_string($item['id']))) {
            throw new GatewayError('invalid_request_error', 'Invalid configuration_update fields.', 400);
        }
        if (isset($item['agent'])) {
            throw new GatewayError('unsupported_parameter', 'Agent-scoped configuration_update requires the native model route.', 400);
        }
        $reasoning = $item['reasoning'] ?? null;
        if ($reasoning === null) return null;
        if (is_array($reasoning) && array_is_list($reasoning)) {
            throw new GatewayError('invalid_request_error', 'configuration_update.reasoning must be an object.', 400);
        }
        if ($reasoning instanceof \stdClass) $reasoning = get_object_vars($reasoning);
        if (!is_array($reasoning) || ($reasoning !== [] && array_is_list($reasoning))
            || array_diff(array_keys($reasoning), ['effort']) !== []) {
            throw new GatewayError('invalid_request_error', 'configuration_update.reasoning must contain only effort.', 400);
        }
        $effort = $reasoning['effort'] ?? null;
        if ($effort === null) return null;
        if (!is_string($effort)) {
            throw new GatewayError('invalid_request_error', 'configuration_update.reasoning.effort must be a string.', 400);
        }
        try {
            $effort = validator()->check($effort, ['rule'=>'string', 'len'=>[1,32], 'name'=>'configuration_update.reasoning.effort']);
        } catch (\Throwable) {
            throw new GatewayError('invalid_request_error', 'Invalid configuration_update reasoning effort.', 400);
        }
        return self::reasoningEffort(['effort'=>$effort]);
    }

    /**
     * Resolve ordered effort updates outside the browser transcript. A checkpoint replaces
     * the preceding conversation, so only a fresh update after it controls the next sample.
     * @param mixed $input Original native input; its canonical request hash remains untouched.
     * @return array{effort:?string,reset:bool} Latest update and checkpoint inheritance barrier.
     */
    private static function inputReasoningConfiguration(mixed $input): array
    {
        $state = ['effort'=>null, 'reset'=>false];
        if (!is_array($input)) return $state;
        foreach ($input as $item) {
            if ($item instanceof \stdClass) $item = get_object_vars($item);
            if (!is_array($item)) continue;
            if (in_array($item['type'] ?? '', ['compaction', 'compaction_summary', 'context_compaction'], true)) {
                $state = ['effort'=>null, 'reset'=>true];
            } elseif (($item['type'] ?? 'message') === 'message') {
                try { $content = ContextHistory::text($item['content'] ?? ''); }
                catch (GatewayError) { $content = ''; }
                if (str_starts_with($content, ContextCompaction::SUMMARY_PREFIX)) {
                    $state = ['effort'=>null, 'reset'=>true];
                }
            } elseif (($item['type'] ?? '') === 'configuration_update') {
                $effort = self::configurationEffort($item);
                if ($effort !== null) $state['effort'] = $effort;
            }
        }
        return $state;
    }

    /**
     * Carry an earlier update only through an exact task-owned delta/receipt continuation.
     * A new model, explicit request effort change or checkpoint starts its own selection.
     * Callers prove delta shape; reservation rechecks the same task-owned source under its transaction.
     * @param array $spec Fresh parsed request, before its tool budget is pinned.
     * @param array $source Exact owned response row supplying the omitted configuration.
     * @return array Spec with effective route, budget and lease selected consistently.
     */
    public static function inheritReasoningConfiguration(array $spec, array $source): array
    {
        if (isset($spec['budget']['reasoning_configuration']) || !empty($spec['reasoning_configuration_reset'])
            || $source['model'] !== $spec['model']
            || ($source['task_id'] ?? null) !== 'task_' . substr((string) $spec['task_key'], 0, 24)) return $spec;
        $budget = json_decode((string) $source['budget_json'], true, 32, JSON_THROW_ON_ERROR);
        $configuration = $budget['reasoning_configuration'] ?? null;
        if (!is_array($configuration) || !is_string($configuration['effort'] ?? null)
            || ($configuration['model'] ?? null) !== $spec['model']
            || ($configuration['request_effort'] ?? null) !== ($spec['request_reasoning_effort'] ?? null)) return $spec;
        $route = ModelCatalog::identity($spec['model'], $configuration['effort']);
        $budget = ContextBudget::snapshot($spec['model'], $route['effort'], $route['capabilities']);
        if (isset($spec['budget']['output_control'])) $budget['output_control'] = $spec['budget']['output_control'];
        if (isset($spec['budget']['native_subagent'])) $budget['native_subagent'] = $spec['budget']['native_subagent'];
        $budget['reasoning_configuration'] = $configuration;
        $spec['budget'] = $budget;
        $spec['reasoning_effort'] = $route['effort'];
        $spec['browser_lease_key'] = hash('sha256', json_encode([
            'namespace'=>'rcmaker-chatgpt-web-v1', 'owner'=>$spec['browser_owner_key'], 'model'=>$spec['model'],
            'reasoning'=>$route['effort'], 'compaction'=>$spec['compaction_epoch'],
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        return $spec;
    }

    private static function optionalIdentity(mixed $value, string $name): ?string
    {
        if ($value === null) return null;
        if (!is_string($value)) throw new GatewayError('invalid_request_error', $name . ' 必须是字符串。', 400);
        $value = trim($value);
        if ($value === '' || strlen($value) > 512) {
            throw new GatewayError('invalid_request_error', $name . ' 格式无效。', 400);
        }
        return $value;
    }

    /** Stable marker for a future task surface epoch; ordinary Step 04 requests use "base". */
    private static function compactionEpoch(mixed $input): string
    {
        if (!is_array($input)) return 'base';
        for ($index = count($input) - 1; $index >= 0; $index--) {
            $item = $input[$index];
            if ($item instanceof \stdClass) $item = get_object_vars($item);
            if (!is_array($item)) continue;
            if (($item['type'] ?? 'message') === 'message') {
                try { $content = ContextHistory::text($item['content'] ?? ''); }
                catch (GatewayError) { $content = ''; }
                if (str_starts_with($content, ContextCompaction::SUMMARY_PREFIX)) return hash('sha256', $content);
            }
            $type = $item['type'] ?? null;
            if (!in_array($type, ['compaction', 'compaction_summary', 'context_compaction'], true)) continue;
            return hash('sha256', json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        }
        return 'base';
    }

    /**
     * Apply request-owned native tool updates in order, including incremental Lite namespaces.
     * A later control item updates each exact tool identity, not the entire namespace: unchanged
     * members remain callable while new exec/wait definitions and refreshed schemas take effect.
     * Keep existing duplicate handling within each inventory and reject ambiguous wire aliases
     * across updates, so namespace encoding cannot silently change a tool's native identity.
     * @param array<int,mixed> $inventories Top-level tools followed by native input control items.
     * @return array<int,array<string,mixed>> Validated tools for this request only.
     * @throws GatewayError If any inventory is malformed or two identities share a wire name.
     */
    private static function mergeToolInventories(array $inventories): array
    {
        $tools = [];
        $positions = [];
        foreach ($inventories as $inventory) {
            if ($inventory === null) continue;
            if (!is_array($inventory)) throw new GatewayError('invalid_request_error', 'tools 必须是数组。', 400);
            $definitions = [];
            $definitionNames = [];
            foreach ($inventory as $definition) {
                $entry = $definition instanceof \stdClass ? get_object_vars($definition) : $definition;
                if (!is_array($entry)) { $definitions[] = $definition; continue; }
                $key = (string)($entry['type'] ?? 'function') . ':' . (string)($entry['name'] ?? 'tool_search');
                if (isset($definitionNames[$key])) continue;
                $definitionNames[$key] = true;
                $definitions[] = $definition;
            }
            foreach (self::tools($definitions) as $tool) {
                $wireName = $tool['wire_name'];
                if (isset($positions[$wireName])) {
                    $prior = $tools[$positions[$wireName]];
                    if ($prior['name'] !== $tool['name'] || $prior['namespace'] !== $tool['namespace']) {
                        throw new GatewayError('invalid_request_error', 'tools 中存在重复工具名称。', 400);
                    }
                    $tools[$positions[$wireName]] = $tool;
                } else {
                    $positions[$wireName] = count($tools);
                    $tools[] = $tool;
                }
            }
        }
        return $tools;
    }

    /**
     * Normalize the complete current Codex tool inventory without an account-specific count limit.
     * The request envelope is already bounded by MAX_BODY_BYTES; each supported tool still
     * retains its namespace, schema and duplicate-name validation.
     * @return array<int,array<string,mixed>>
     */
    private static function tools(mixed $raw): array
    {
        if ($raw === null) return [];
        if (!is_array($raw)) throw new GatewayError('invalid_request_error', 'tools 必须是数组。', 400);
        $tools = [];
        $wireNames = [];
        foreach ($raw as $entry) {
            if ($entry instanceof \stdClass) $entry = get_object_vars($entry);
            if (!is_array($entry)) throw new GatewayError('invalid_request_error', 'tool 定义必须是对象。', 400);
            $type = (string) ($entry['type'] ?? 'function');
            if ($type === 'tool_search') {
                // Codex executes discovery on the client and expects a tool_search_call item.
                // Keep it in the same durable registry as other client-executed tools.
                $entry['name'] = 'tool_search';
                $entry['parameters'] ??= ['type'=>'object', 'properties'=>[
                    'query'=>['type'=>'string'], 'limit'=>['type'=>'number']], 'required'=>['query']];
                self::appendTool($tools, $wireNames, $entry, $type, null);
                continue;
            }
            if ($type === 'namespace') {
                $namespaceName = $entry['name'] ?? null;
                $innerTools = $entry['tools'] ?? null;
                if (!is_string($namespaceName) || !preg_match('/\A[A-Za-z0-9_.:-]{1,128}\z/', $namespaceName)
                    || !is_array($innerTools)) {
                    throw new GatewayError('invalid_request_error', 'namespace tool 定义无效。', 400);
                }
                $namespace = $namespaceName;
                foreach ($innerTools as $inner) {
                    if ($inner instanceof \stdClass) $inner = get_object_vars($inner);
                    if (!is_array($inner) || !is_string($inner['name'] ?? null)) continue;
                    $innerType = (string) ($inner['type'] ?? 'function');
                    if ($innerType !== 'function' && !($namespaceName === 'functions' && $innerType === 'custom')) continue;
                    self::appendTool($tools, $wireNames, $inner, $innerType, $namespace);
                }
                continue;
            }
            if (!in_array($type, ['function', 'custom'], true)) {
                // Named client-executed native tools (for example computer use) use the
                // ordinary function-call relay. OpenAI-hosted tools never do.
                if (!is_string($entry['name'] ?? null)
                    || in_array($type, ['web_search', 'image_generation'], true)) continue;
                $type = 'function';
            }
            $namespace = $entry['namespace'] ?? null;
            if ($namespace !== null && (!is_string($namespace) || !preg_match('/\A[A-Za-z0-9_.:-]{1,128}\z/', $namespace))) {
                throw new GatewayError('invalid_request_error', 'tool.namespace 格式无效。', 400);
            }
            self::appendTool($tools, $wireNames, $entry, $type, is_string($namespace) && $namespace !== '' ? $namespace : null);
        }
        return $tools;
    }

    /** Normalize only tool specs returned by an acknowledged Codex discovery result. */
    public static function discoveredToolsFromOutputs(array $outputs): array
    {
        $definitions = [];
        foreach ($outputs as $output) {
            if (($output['type'] ?? '') !== 'tool_search_output') continue;
            $record = json_decode((string)($output['output'] ?? ''), true);
            if (!is_array($record) || !in_array(($record['status'] ?? ''), ['completed','success'], true)
                || !is_array($record['tools'] ?? null)) continue;
            foreach ($record['tools'] as $tool) $definitions[] = $tool;
        }
        return self::tools($definitions);
    }

    /** Store one native tool with normalized namespace and description metadata. */
    private static function appendTool(array &$tools, array &$wireNames, array $entry, string $type, ?string $namespace): void
    {
        $name = $entry['name'] ?? null;
        if (!is_string($name) || !preg_match('/\A[A-Za-z0-9_.:-]{1,128}\z/', $name)) {
            throw new GatewayError('invalid_request_error', 'tool.name 格式无效。', 400);
        }
        // Codex's built-in functions group is implicit in the Web tool inventory.
        $namespace = $namespace === 'functions' ? null : $namespace;
        // Other connector namespaces retain a prefix to prevent bridge name collisions.
        $wireName = $namespace !== null && $namespace !== 'functions' ? $namespace . '__' . $name : $name;
        if (isset($wireNames[$wireName])) throw new GatewayError('invalid_request_error', 'tools 中存在重复工具名称。', 400);
        $wireNames[$wireName] = true;
        if ($type === 'custom') {
            // Declare the model-visible freeform schema explicitly. Codex's native
            // custom spec has no JSON parameters, but the Web model needs an explicit raw
            // input field to avoid sending a JSON object as the apply_patch body.
            $parameters = [
                'type' => 'object',
                'properties' => ['input' => [
                    'type' => 'string',
                    'description' => 'Raw tool input. For apply_patch, begin exactly with `*** Begin Patch` (no trailing `***`), then use its standard patch envelope.',
                ]],
                'required' => ['input'],
            ];
        } else {
            $parameters = $entry['parameters'] ?? ['type' => 'object', 'properties' => new \stdClass()];
            if ($parameters instanceof \stdClass) $parameters = get_object_vars($parameters);
            if (!is_array($parameters)) throw new GatewayError('invalid_request_error', 'tool.parameters 必须是对象。', 400);
        }
        $tools[] = [
            'type' => $type,
            'name' => $name,
            'namespace' => $namespace,
            'wire_name' => $wireName,
            'description' => is_string($entry['description'] ?? null) ? $entry['description'] : '',
            'parameters' => $parameters,
            'freeform' => $type === 'custom',
            'tool_search' => $type === 'tool_search',
        ];
    }

    /**
     * Extract the complete current host-result batch across interleaved native steering.
     * Human input does not end a parallel receipt batch; the preceding assistant/call or
     * compaction record remains the boundary. Task ownership and issued call IDs are then
     * verified by ContextHistory and ToolBroker before any result can be accepted.
     * Execution-only configuration updates do not split that native receipt batch.
     */
    private static function toolOutputs(mixed $input): array
    {
        if (!is_array($input)) return [];
        $outputs = [];
        $afterOutputs = true;
        $last = $input[array_key_last($input)] ?? null;
        $lastType = $last instanceof \stdClass ? ($last->type ?? null)
            : (is_array($last) ? ($last['type'] ?? null) : null);
        $terminalCompaction = $lastType === 'compaction_trigger';
        foreach (array_reverse($input) as $item) {
            if ($item instanceof \stdClass) $item = get_object_vars($item);
            if (!is_array($item)) break;
            $type = $item['type'] ?? 'message';
            if (in_array($type, ['additional_tools', 'configuration_update'], true)) continue;
            // Native hooks and the agent mailbox can append context after each
            // result, including between results in a parallel batch. compile()
            // preserves their roles; they are not receipt boundaries.
            if (($type === 'message' && in_array($item['role'] ?? null, ['system', 'developer'], true))
                || $type === 'agent_message'
                || ($type === 'message' && ContextHistory::isOperationalUserMessage($item))) continue;
            // Native v2 compaction appends its trigger after the current host-result batch.
            if ($terminalCompaction && $afterOutputs && $type === 'compaction_trigger') continue;
            // A steering item can precede, follow, or separate parallel results. Stopping
            // here would acknowledge only the last call and strand the remaining batch.
            if ($type === 'message' && ($item['role'] ?? null) === 'user') continue;
            // Codex delivers created-thread and cross-thread work as a codex_app
            // function output without a call_id. It is a new instruction, not the
            // result of a pending host call.
            if (self::isCodexDelegation($item)) break;
            if (!in_array($type, ['function_call_output', 'custom_tool_call_output', 'tool_search_output'], true)) break;
            $afterOutputs = false;
            $callId = $item['call_id'] ?? null;
            if (!is_string($callId) || !preg_match('/\A[A-Za-z0-9_.:-]{1,256}\z/', $callId)) {
                throw new GatewayError('invalid_request_error', 'function_call_output.call_id 格式无效。', 400);
            }
            $contentParts = null;
            if ($type === 'tool_search_output') {
                $output = json_encode(['status'=>$item['status'] ?? 'completed',
                    'tools'=>$item['tools'] ?? []], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            } else {
                if (!array_key_exists('output', $item)) throw new GatewayError('invalid_request_error', 'function_call_output 缺少 output。', 400);
                // Keep native content items available to the context compiler. The exact wire
                // output stays a string for the broker's receipt hash and idempotent delivery.
                $contentParts = is_array($item['output']) ? $item['output'] : null;
                $output = is_string($item['output'])
                    ? $item['output']
                    : json_encode($item['output'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            }
            $outputs[] = ['call_id' => $callId, 'output' => $output, 'type' => $type,
                ...($contentParts !== null ? ['content_parts' => $contentParts] : [])];
        }
        return array_reverse($outputs);
    }

    public static function isCodexDelegation(array $item): bool
    {
        return ($item['type'] ?? null) === 'function_call_output'
            && ($item['namespace'] ?? null) === 'codex_app'
            && in_array($item['name'] ?? null, ['create_thread', 'send_message_to_thread'], true)
            && !isset($item['call_id'])
            && is_string($item['output'] ?? null)
            && str_starts_with((string) $item['output'], '<codex_delegation>')
            && str_ends_with(trim((string) $item['output']), '</codex_delegation>');
    }

    private static function toolEnvironment(array $metadata, array $tools): array
    {
        $raw = $metadata['tool_environment'] ?? [];
        if ($raw instanceof \stdClass) $raw = get_object_vars($raw);
        if (!is_array($raw)) $raw = [];
        $environment = [];
        foreach (['cwd', 'sandbox_policy'] as $field) {
            if (isset($raw[$field]) && (is_string($raw[$field]) || is_array($raw[$field]))) $environment[$field] = $raw[$field];
        }
        foreach (['roots', 'writable_roots'] as $field) {
            if (isset($raw[$field]) && is_array($raw[$field])) {
                $environment[$field] = array_values(array_filter($raw[$field], static fn($v): bool => is_string($v) && strlen($v) <= 4096));
            }
        }
        $environment['tools'] = $tools;
        return $environment;
    }

    /** Normalize native tool selection to the transport's optional choice format. */
    private static function toolChoice(mixed $choice): string|array|null
    {
        if ($choice === null) return null;
        if (is_string($choice) && in_array($choice, ['auto', 'none', 'required'], true)) return $choice;
        if ($choice instanceof \stdClass) $choice = get_object_vars($choice);
        if (is_array($choice) && !array_is_list($choice)) {
            $type = $choice['type'] ?? null;
            if (in_array($type, ['function', 'custom'], true)
                && is_string($choice['name'] ?? null) && $choice['name'] !== '') {
                return ['name' => $choice['name']];
            }
            if (in_array($type, ['web_search_preview', 'file_search', 'computer_use_preview',
                'code_interpreter', 'image_generation', 'mcp'], true)) return 'auto';
            if ($type === 'allowed_tools' && in_array($choice['mode'] ?? null, ['auto', 'required'], true)
                && is_array($choice['tools'] ?? null) && array_is_list($choice['tools'])) {
                $names = [];
                foreach ($choice['tools'] as $tool) {
                    if ($tool instanceof \stdClass) $tool = get_object_vars($tool);
                    if (!is_array($tool) || array_is_list($tool) || !is_string($tool['type'] ?? null)
                        || (array_key_exists('name', $tool) && !is_string($tool['name']))) {
                        throw new GatewayError('invalid_request_error', 'tool_choice.allowed_tools 格式无效。', 400);
                    }
                    $name = is_string($tool['name'] ?? null) && $tool['name'] !== ''
                        ? $tool['name']
                        : (in_array($tool['type'], ['web_search', 'web_search_preview'], true) ? 'web_search'
                            : ($tool['type'] === 'tool_search' ? 'tool_search' : null));
                    if ($name !== null) $names[$name] = true;
                }
                return $names === [] ? 'none' : ['allowedTools' => array_keys($names), 'mode' => $choice['mode']];
            }
        }
        throw new GatewayError('invalid_request_error', 'tool_choice 格式无效。', 400);
    }

    private static function compilePrompt(mixed $input, string $instructions): string
    {
        $sections = [];
        if ($instructions !== '') {
            $sections[] = "[instructions]\n" . $instructions;
        }
        if (is_string($input)) {
            if ($instructions === '') return $input;
            $sections[] = "[user]\n" . $input;
            return implode("\n\n", $sections);
        }
        if (!is_array($input) || $input === []) {
            throw new GatewayError('invalid_request_error', 'input 必须是非空字符串或受支持的消息数组。', 400);
        }
        foreach ($input as $message) {
            if ($message instanceof \stdClass) $message = get_object_vars($message);
            if (!is_array($message)) {
                throw new GatewayError('invalid_request_error', 'input 消息必须是对象。', 400);
            }
            $type = $message['type'] ?? 'message';
            if (in_array($type, [
                'function_call', 'function_call_output', 'custom_tool_call', 'custom_tool_call_output',
                'tool_search_call', 'reasoning', 'compaction', 'compaction_summary', 'context_compaction',
            ], true)) {
                continue;
            }
            $unknown = array_diff(array_keys($message), ['type', 'role', 'content']);
            if ($unknown !== [] || $type !== 'message') {
                throw new GatewayError('unsupported_parameter', '当前只支持 Responses message 文本输入。', 400);
            }
            $role = $message['role'] ?? null;
            if (!is_string($role) || !in_array($role, ['system', 'developer', 'user', 'assistant'], true)) {
                throw new GatewayError('invalid_request_error', 'input 消息 role 不受支持。', 400);
            }
            $content = self::messageText($message['content'] ?? null);
            if ($content === '') continue;
            $sections[] = '[' . $role . "]\n" . $content;
        }
        return implode("\n\n", $sections);
    }

    /** Preserve the original instruction/message roles for the ChatGPT transport contract. */
    private static function compileContext(mixed $input, string $instructions): array
    {
        $system = [];
        $messages = [];
        if ($instructions !== '') $system[] = $instructions;
        if (is_string($input)) {
            if ($input !== '') $messages[] = ['role' => 'user', 'content' => $input];
            return ['version' => 3, 'system' => $system, 'messages' => $messages];
        }
        if (!is_array($input)) return ['version' => 3, 'system' => $system, 'messages' => $messages];
        foreach ($input as $message) {
            if ($message instanceof \stdClass) $message = get_object_vars($message);
            if (!is_array($message)) continue;
            $type = $message['type'] ?? 'message';
            if (in_array($type, [
                'function_call', 'function_call_output', 'custom_tool_call', 'custom_tool_call_output',
                'tool_search_call', 'reasoning', 'compaction', 'compaction_summary', 'context_compaction',
            ], true)) {
                continue;
            }
            if ($type !== 'message') continue;
            $role = $message['role'] ?? null;
            if (!is_string($role) || !in_array($role, ['system', 'developer', 'user', 'assistant'], true)) continue;
            $content = self::messageText($message['content'] ?? null);
            if ($content === '') continue;
            if ($role === 'system') {
                $system[] = $content;
            } elseif ($role === 'assistant') {
                $messages[] = ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => $content]]];
            } else {
                $messages[] = ['role' => $role, 'content' => $content];
            }
        }
        return ['version' => 3, 'system' => $system, 'messages' => $messages];
    }

    /**
     * Retire capability handles replayed inside accumulated task text so a later ChatGPT turn can
     * only bind through the fresh turn_token carried by the outer transport contract.
     * Checkpoint identity proofs must apply this same normalization to the trusted stored summary.
     * @param mixed $value Task text or recursively compiled context; unrelated values stay unchanged.
     * @return mixed Context with historical transport handles retired, without changing stored evidence.
     */
    public static function retireTransportHandles(mixed $value): mixed
    {
        if (is_string($value)) {
            return preg_replace_callback(
                '/(?<![A-Za-z0-9_-])(turn|request|binding)_[A-Za-z0-9_-]{32}(?![A-Za-z0-9_-])/',
                static fn(array $match): string => '[retired ' . $match[1] . ' handle]',
                $value
            ) ?? $value;
        }
        if (!is_array($value)) return $value;
        foreach ($value as $key => $item) $value[$key] = self::retireTransportHandles($item);
        return $value;
    }

    private static function messageText(mixed $content): string
    {
        if (is_string($content)) return $content;
        if (!is_array($content) || $content === []) return '';
        $parts = [];
        foreach ($content as $part) {
            if ($part instanceof \stdClass) $part = get_object_vars($part);
            if (!is_array($part) || array_diff(array_keys($part), ['type', 'text']) !== [] || ($part['type'] ?? null) !== 'input_text' || !is_string($part['text'] ?? null)) {
                throw new GatewayError('unsupported_parameter', '当前只支持 input_text 内容块。', 400);
            }
            $parts[] = $part['text'];
        }
        return implode("\n", $parts);
    }
}
