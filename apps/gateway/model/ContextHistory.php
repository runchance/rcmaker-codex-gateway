<?php
declare(strict_types=1);
namespace app\gateway\model;

/** Canonical readable Codex history, exact-prefix deltas and task-scoped tool-result continuation. */
final class ContextHistory
{
    /** Preserve roles, tools and selected skills; mark provider-private agent history as unavailable. */
    public static function compile(mixed $input, string $instructions, ?array &$attachments = null, string $skillTransport = 'inline'): array
    {
        $attachments ??= [];
        $context = ['version' => 3, 'system' => $instructions === '' ? [] : [$instructions], 'messages' => []];
        if (is_string($input)) { $context['messages'][] = ['role' => 'user', 'content' => $input]; return $context; }
        if (!is_array($input) || $input === []) throw new GatewayError('invalid_request_error', 'input 必须是非空字符串或消息数组。', 400);
        $currentBaseInstruction = null;
        $atCurrentPreamble = true;
        foreach ($input as $item) {
            if ($item instanceof \stdClass) $item = get_object_vars($item);
            if (!is_array($item)) throw new GatewayError('invalid_request_error', '历史记录必须是对象。', 400);
            $type = (string) ($item['type'] ?? 'message');
            if ($type === 'additional_tools') continue;
            if ($type === 'compaction_trigger') { $atCurrentPreamble = false; continue; }
            if ($type === 'compaction') {
                $replayed = self::compile(ContextCompaction::replay((string)($item['encrypted_content'] ?? '')), '', $attachments, $skillTransport);
                // A checkpoint replaces the conversation it summarizes. Keeping the old
                // prefix beside its summary doubles stale instructions and receipts in the
                // next Web page, even though Codex has already compacted that prefix. In
                // Responses Lite, the current model.base_instructions developer item is
                // prepended before that history and must survive the replacement.
                $context['messages'] = $replayed['messages'];
                if ($currentBaseInstruction !== null
                    && !in_array($currentBaseInstruction, $context['messages'], true)) {
                    array_unshift($context['messages'], $currentBaseInstruction);
                }
                $atCurrentPreamble = false;
                continue;
            }
            $leadingItem = $atCurrentPreamble;
            $atCurrentPreamble = false;
            if ($type === 'reasoning') {
                // Private native reasoning is not readable task context. Preserve its public summary.
                if (!empty($item['summary'])) $context['messages'][] = ['type' => 'reasoning_summary', 'summary' => $item['summary']];
                continue;
            }
            if ($type === 'web_search_call') {
                // Hosted search has no paired result payload for a routed Web model. Do not
                // replay its call as if it were a usable search result. Its pending
                // reasoning also belongs to that omitted call, not the next tool call.
                while ($context['messages'] !== []
                    && ($context['messages'][array_key_last($context['messages'])]['type'] ?? null) === 'reasoning_summary') {
                    array_pop($context['messages']);
                }
                continue;
            }
            if ($type === 'message') {
                $role = (string) ($item['role'] ?? '');
                if (!in_array($role, ['system', 'developer', 'user', 'assistant'], true)) throw new GatewayError('invalid_request_error', '历史消息角色不受支持。', 400);
                $selectedSkill = $role === 'user' && self::selectedSkillProvenance($item);
                $content = self::messageContent($item['content'] ?? '', $role, $selectedSkill, $skillTransport, $attachments);
                if ($role === 'system') {
                    if (!is_string($content)) throw new GatewayError('unsupported_content', 'system 消息只能包含可读文本。', 400);
                    $context['system'][] = $content;
                } else {
                    $message = ['role' => $role, 'content' => $content];
                    // Native user item IDs distinguish steering instructions with identical text.
                    if ($role === 'user' && is_string($item['id'] ?? null) && $item['id'] !== '') {
                        $message['id'] = $item['id'];
                    }
                    $metadata = $item['internal_chat_message_metadata_passthrough'] ?? null;
                    if ($metadata instanceof \stdClass) $metadata = get_object_vars($metadata);
                    if ($leadingItem && $role === 'developer' && is_array($metadata)
                        && ($metadata['content_item_kinds'] ?? null) === ['model.base_instructions']) {
                        $currentBaseInstruction = $message;
                    }
                    // Codex distinguishes working commentary from final answers. Preserve that
                    // provenance instead of teaching a resumed Web model that every update ended a task.
                    if ($role === 'assistant' && in_array($item['phase'] ?? null, ['commentary', 'final_answer'], true)) {
                        $message['phase'] = $item['phase'];
                    }
                    if ($selectedSkill) $message['origin'] = 'codex_skill';
                    // XML environment records retain their existing canonical representation.
                    // Preserve native provenance only when the metadata identifies a non-XML form.
                    if ($role === 'user' && is_array($metadata)
                        && in_array('environments.environment_context', (array) ($metadata['content_item_kinds'] ?? []), true)
                        && !self::isOperationalUserMessage($message)) $message['origin'] = 'codex_environment';
                    $context['messages'][] = $message;
                }
                continue;
            }
            if ($type === 'agent_message') {
                $content = $item['content'] ?? '';
                if (!is_string($content) && !is_array($content)) {
                    throw new GatewayError('invalid_request_error', 'agent_message.content 格式无效。', 400);
                }
                $message = ['role' => 'agent_message', 'content' => self::agentMessageContent($content, $attachments)];
                foreach (['author', 'recipient'] as $field) {
                    if (!array_key_exists($field, $item)) continue;
                    if (!is_string($item[$field])) throw new GatewayError('invalid_request_error', 'agent_message.' . $field . ' 必须是字符串。', 400);
                    $message[$field] = $item[$field];
                }
                $context['messages'][] = $message;
                continue;
            }
            if (ResponsesProtocol::isCodexDelegation($item)) {
                // This is Codex's current cross-thread delivery, not a host tool
                // receipt. Keep it as an instruction for the durable turn; the
                // browser projection restores the transport's tool_result role
                // only for send_message_to_thread. create_thread remains a user
                // instruction in this transport.
                $context['messages'][] = [
                    'role' => 'user',
                    'content' => (string) $item['output'],
                    'origin' => 'codex_delegation',
                    ...(($item['name'] ?? null) === 'send_message_to_thread'
                        ? ['delegation_tool' => 'send_message_to_thread'] : []),
                ];
                continue;
            }
            if (in_array($type, ['function_call', 'custom_tool_call', 'function_call_output', 'custom_tool_call_output',
                'tool_search_call', 'tool_search_output', 'compaction_summary', 'context_compaction'], true)) {
                unset($item['id'], $item['status']);
                // Server-side/native encrypted collaboration cannot be decoded or sent as if readable.
                if (!empty($item['encrypted_content'])) throw new GatewayError('opaque_context_unsupported', '此历史含无法读取的加密内容，请用可读上下文继续。', 400);
                if (in_array($type, ['function_call_output', 'custom_tool_call_output'], true)) {
                    // Codex view_image returns output content items. Treat its image as a real
                    // visual attachment, never as base64 text in the model context budget.
                    $parts = $item['content_parts'] ?? (is_array($item['output'] ?? null) ? $item['output'] : null);
                    if (is_array($parts)) $item['output'] = self::toolOutputContent($parts, $attachments);
                    unset($item['content_parts']);
                }
                $context['messages'][] = $item;
                continue;
            }
            throw new GatewayError('unsupported_history_item', '暂不支持的历史记录类型：' . $type, 400);
        }
        // Codex can repeat the same project instructions after a checkpoint. Preserve
        // the current copy without sending the stale identical copy again.
        $context['messages'] = self::dedupeProjectInstructions($context['messages']);
        // Physical file limits are applied after compilation; retain full refs for prefix identity.
        return $context;
    }

    /** Match native tool-result content semantics while retaining images for browser recovery. */
    private static function toolOutputContent(array $content, array &$attachments): string|array
    {
        $parts = [];
        $hasImage = false;
        foreach ($content as $part) {
            if ($part instanceof \stdClass) $part = get_object_vars($part);
            if (!is_array($part)) continue;
            $type = (string) ($part['type'] ?? '');
            if (in_array($type, ['output_text', 'input_text', 'text'], true) && is_string($part['text'] ?? null)) {
                $parts[] = ['type' => 'text', 'text' => $part['text']];
            } elseif ($type === 'refusal' && is_string($part['refusal'] ?? null)) {
                $parts[] = ['type' => 'text', 'text' => '[refusal: ' . $part['refusal'] . ']'];
            } elseif ($type === 'encrypted_content') {
                $parts[] = ['type' => 'text', 'text' => '[encrypted content omitted]'];
            } elseif ($type === 'input_image' && is_string($part['image_url'] ?? null)) {
                $attachment = AttachmentStore::image($part['image_url'],
                    is_string($part['detail'] ?? null) ? $part['detail'] : null);
                self::appendAttachment($attachments, $attachment);
                $parts[] = ['type' => 'image_attachment', 'attachment_ref' => $attachment['id'],
                    'filename' => $attachment['filename'],
                    ...(is_string($part['detail'] ?? null) ? ['detail' => $part['detail'] === 'original' ? 'high' : $part['detail']] : [])];
                $hasImage = true;
            } elseif ($type === 'image_attachment' && is_string($part['attachment_ref'] ?? null)) {
                // A verified checkpoint may already contain the stable image reference.
                $parts[] = $part;
                $hasImage = true;
            } else {
                // Preserve unfamiliar native content instead of silently dropping a tool result.
                $parts[] = ['type' => 'text', 'text' => self::canonical($part)];
            }
        }
        return $hasImage ? $parts : implode('', array_column($parts, 'text'));
    }

    /** Rebuild the exact browser projection from a durable native content-item receipt. */
    public static function toolOutputFromWire(string $wire): string|array|null
    {
        $content = json_decode($wire, true);
        if (!is_array($content) || !array_is_list($content)) return null;
        $attachments = [];
        return self::toolOutputContent($content, $attachments);
    }

    /** Remove only byte-identical Codex-supplied AGENTS instructions, keeping the latest. */
    private static function dedupeProjectInstructions(array $messages): array
    {
        $seen = [];
        for ($index = count($messages) - 1; $index >= 0; $index--) {
            $message = $messages[$index];
            if (($message['role'] ?? '') !== 'user') continue;
            $content = $message['content'] ?? null;
            if (is_string($content)) $value = $content;
            elseif (is_array($content) && count($content) === 1
                && is_array($content[0]) && in_array($content[0]['type'] ?? '', ['text','input_text'], true)
                && is_string($content[0]['text'] ?? null)) $value = $content[0]['text'];
            else continue;
            if (!str_starts_with($value, '# AGENTS.md instructions for ')) continue;
            $hash = hash('sha256', $value);
            if (isset($seen[$hash])) unset($messages[$index]);
            else $seen[$hash] = true;
        }
        return array_values($messages);
    }

    /** Preserve mixed text/image order while replacing image bytes with stable private refs. */
    private static function messageContent(mixed $content, string $role, bool $selectedSkill, string $skillTransport, array &$attachments): mixed
    {
        if ($selectedSkill) {
            $text = self::text($content);
            if ($skillTransport !== 'file') return $text;
            $attachment = AttachmentStore::selectedSkill($text);
            self::appendAttachment($attachments, $attachment);
            return [[
                'type' => 'skill_attachment',
                'attachment_ref' => $attachment['id'],
                'filename' => $attachment['filename'],
            ]];
        }
        if (is_string($content)) return $content;
        if (!is_array($content)) throw new GatewayError('invalid_request_error', '消息正文格式无效。', 400);
        $structured = [];
        $textOnly = [];
        $hasAttachment = false;
        foreach ($content as $part) {
            if ($part instanceof \stdClass) $part = get_object_vars($part);
            if (!is_array($part)) throw new GatewayError('unsupported_content', '消息内容块格式无效。', 400);
            $type = (string) ($part['type'] ?? '');
            if (in_array($type, ['input_text', 'output_text', 'text'], true) && is_string($part['text'] ?? null)) {
                $textOnly[] = (string) $part['text'];
                $structured[] = ['type' => 'text', 'text' => (string) $part['text']];
                continue;
            }
            if (in_array($type, ['input_image', 'image'], true)) {
                if (!in_array($role, ['user', 'developer', 'agent_message'], true)) {
                    throw new GatewayError('unsupported_content', '当前仅支持用户、开发者或子代理消息中的输入图片。', 400);
                }
                if (array_key_exists('file_id', $part)) {
                    throw new GatewayError('unresolved_file_id', 'file_id 不能在本机解析；请由 Codex 提供内联 image_url。', 400);
                }
                $url = $part['image_url'] ?? null;
                if (!is_string($url) || $url === '') throw new GatewayError('invalid_image', 'input_image 缺少 image_url。', 400);
                $detail = is_string($part['detail'] ?? null) ? (string) $part['detail'] : null;
                $attachment = AttachmentStore::image($url, $detail);
                self::appendAttachment($attachments, $attachment);
                $structured[] = [
                    'type' => 'image_attachment',
                    'attachment_ref' => $attachment['id'],
                    'filename' => $attachment['filename'],
                    ...($detail !== null && $detail !== '' ? ['detail' => strtolower($detail === 'original' ? 'high' : $detail)] : []),
                ];
                $hasAttachment = true;
                continue;
            }
            throw new GatewayError('unsupported_content', '暂不支持的消息内容块：' . ($type !== '' ? $type : 'unknown'), 400);
        }
        return $hasAttachment ? $structured : implode("\n", $textOnly);
    }

    private static function selectedSkillProvenance(array $message): bool
    {
        $metadata = $message['internal_chat_message_metadata_passthrough'] ?? null;
        if ($metadata instanceof \stdClass) $metadata = get_object_vars($metadata);
        if (!is_array($metadata)) return false;
        $kinds = $metadata['content_item_kinds'] ?? null;
        return is_array($kinds) && count($kinds) === 1 && array_values($kinds)[0] === 'skills.selected_skill_instructions';
    }

    private static function appendAttachment(array &$attachments, array $attachment): void
    {
        $id = (string) ($attachment['id'] ?? '');
        foreach ($attachments as $existing) {
            if (is_array($existing) && (string) ($existing['id'] ?? '') === $id) return;
        }
        $attachments[] = $attachment;
    }

    /**
     * Convert text-only message blocks; reject attachments and unsupported parts without altering them.
     * @param mixed $content A string or ordered input_text/output_text/text blocks.
     * @return string Readable text with block boundaries preserved as newlines.
     * @throws GatewayError For an invalid body, attachment, or unsupported content block.
     */
    public static function text(mixed $content): string
    {
        if (is_string($content)) return $content;
        if (!is_array($content)) throw new GatewayError('invalid_request_error', '消息正文格式无效。', 400);
        $parts = [];
        foreach ($content as $part) {
            if ($part instanceof \stdClass) $part = get_object_vars($part);
            if (!is_array($part) || !in_array($part['type'] ?? '', ['input_text', 'output_text', 'text'], true) || !is_string($part['text'] ?? null)) {
                throw new GatewayError('unsupported_content', '此文本转换仅支持可读文本块；附件与其他内容块必须保留在结构化消息中。', 400);
            }
            $parts[] = $part['text'];
        }
        return implode("\n", $parts);
    }

    /** Deterministic JSON independent of object key order, preserving arrays and empty object types. */
    public static function canonical(mixed $value): string
    {
        $sort = static function (mixed $item) use (&$sort): mixed {
            if ($item instanceof \stdClass) { $values = get_object_vars($item); ksort($values); foreach ($values as &$value) $value = $sort($value); return (object) $values; }
            if (!is_array($item)) return $item;
            if (!array_is_list($item)) ksort($item);
            foreach ($item as &$value) $value = $sort($value);
            return $item;
        };
        return json_encode($sort($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * Compare native history without the bookkeeping Codex stamps after receiving a tool item.
     * Gateway-built steering rounds do not yet have those turn/time markers. Ignore only the
     * top-level metadata of native tool records; message identity and tool payloads stay exact.
     */
    public static function nativeToolHistoryCanonical(array $messages): string
    {
        foreach ($messages as $index => $message) {
            if (is_array($message) && in_array($message['type'] ?? null,
                ['function_call', 'custom_tool_call', 'function_call_output', 'custom_tool_call_output',
                    'tool_search_call', 'tool_search_output'], true)) {
                unset($messages[$index]['internal_chat_message_metadata_passthrough']);
            }
        }
        return self::canonical($messages);
    }

    /**
     * Prove an active compact retains the source history across native wire formats.
     * A freshly loaded Lite catalog moves the exact classic base instruction to a
     * prompt-only leading developer message and omits image display detail hints.
     * Neither change may excuse missing records, changed tool arguments or image bytes.
     *
     * @param array $source Durable source context before the current pending tool results.
     * @param array $requested Compiled compact context supplied by the native client.
     * @param bool $responsesLite Whether the native request includes the Lite tools preamble.
     * @param bool $requireComplete Require the complete transcript and unchanged system instructions
     *     apart from the exact base instruction moved to the Lite preamble.
     * @return bool Every source record survives in order with the same task/tool content.
     */
    public static function matchesCompactionSource(array $source, array $requested, bool $responsesLite,
        bool $requireComplete = false): bool
    {
        $known = (array) ($source['messages'] ?? []);
        $records = (array) ($requested['messages'] ?? []);
        $system = (array) ($source['system'] ?? []);
        $base = $source['system'][0] ?? null;
        $first = $records[0] ?? null;
        if ($responsesLite && is_string($base) && $base !== ''
            && ($first['role'] ?? null) === 'developer' && ($first['content'] ?? null) === $base
            && self::canonical($known[0] ?? null) !== self::canonical($first)) {
            // Only the byte-identical instruction already present in source.system is a
            // transport preamble. Other developer messages remain part of exact history.
            array_shift($records);
            array_shift($system);
        }
        if ($requireComplete && self::canonical($system) !== self::canonical((array) ($requested['system'] ?? []))) return false;
        if (count($records) < count($known) || ($requireComplete && count($records) !== count($known))) return false;
        foreach ($known as $index => $record) {
            $candidate = $records[$index];
            if ($responsesLite) $record = self::sourceWithLiteImageHints($record, $candidate);
            if (self::nativeToolHistoryCanonical([$record]) !== self::nativeToolHistoryCanonical([$candidate])) {
                return false;
            }
        }
        return true;
    }

    /**
     * Align only image content-part display hints removed by Codex's Lite serializer.
     * Attachment identity and every other field stay exact; tool arguments and text are untouched.
     */
    private static function sourceWithLiteImageHints(array $source, array $requested): array
    {
        $field = match ($source['type'] ?? '') {
            'function_call_output', 'custom_tool_call_output' => 'output',
            default => in_array($source['role'] ?? '', ['user', 'developer', 'assistant'], true) ? 'content' : null,
        };
        if ($field === null || !is_array($source[$field] ?? null) || !is_array($requested[$field] ?? null)) return $source;
        foreach ($source[$field] as $index => $part) {
            $other = $requested[$field][$index] ?? null;
            if (!is_array($part) || !is_array($other)
                || ($part['type'] ?? null) !== 'image_attachment' || ($other['type'] ?? null) !== 'image_attachment'
                || !is_string($part['attachment_ref'] ?? null) || $part['attachment_ref'] !== ($other['attachment_ref'] ?? null)
                || !is_string($part['filename'] ?? null) || $part['filename'] !== ($other['filename'] ?? null)
                || !in_array($part['detail'] ?? null, ['auto', 'low', 'high'], true)
                || array_key_exists('detail', $other)) continue;
            unset($source[$field][$index]['detail']);
        }
        return $source;
    }

    /**
     * Present native tool history with explicit assistant/tool-result roles in Web prompts.
     * The durable context and call IDs remain unchanged; unknown record shapes stay verbatim.
     *
     * @param array $context Canonical readable task context after attachment transport.
     * @return array Equivalent browser-only context with role-labelled native tool records.
     */
    public static function semanticWebContext(array $context): array
    {
        $names = [];
        $projected = [];
        $pendingReasoning = [];
        foreach ((array)($context['messages'] ?? []) as $message) {
            if (!is_array($message)) { $projected[] = $message; continue; }
            if (($message['role'] ?? null) === 'user'
                && ($message['origin'] ?? null) === 'codex_delegation'
                && ($message['delegation_tool'] ?? null) === 'send_message_to_thread') {
                // Project this native no-call_id function output as
                // an unpaired tool result. Its instruction identity is retained
                // in the durable context, but Web must see the original role.
                $projected[] = ['role'=>'tool_result', 'tool_name'=>'',
                    'is_error'=>false, 'content'=>$message['content']];
                $pendingReasoning = [];
                continue;
            }
            $type = (string)($message['type'] ?? '');
            if ($type === 'reasoning_summary') {
                $summary = $message['summary'] ?? [];
                $text = is_array($summary) ? implode('', array_map(
                    static fn(mixed $part): string => is_array($part) ? (string)($part['text'] ?? '') : '', $summary)) : '';
                if ($text !== '') $pendingReasoning[] = ['type'=>'thinking_summary', 'text'=>$text];
                continue;
            }
            if (in_array($type, ['function_call', 'custom_tool_call', 'tool_search_call'], true)
                && is_string($message['call_id'] ?? ($message['id'] ?? null))
                && ($type === 'tool_search_call' || (is_string($message['name'] ?? null) && $message['name'] !== ''))) {
                $callId = (string)($message['call_id'] ?? $message['id']);
                if ($callId === '') { $projected[] = $message; continue; }
                $name = $type === 'tool_search_call' ? 'tool_search' : $message['name'];
                $names[$callId] = ['name'=>$name, 'namespace'=>(string)($message['namespace'] ?? '')];
                $arguments = $type === 'custom_tool_call'
                    ? ['input'=>(string)($message['input'] ?? '')] : ($message['arguments'] ?? []);
                if (is_string($arguments)) {
                    $decoded = json_decode($arguments);
                    $arguments = $decoded instanceof \stdClass ? $decoded : new \stdClass();
                }
                if (!is_array($arguments) && !($arguments instanceof \stdClass)) $arguments = new \stdClass();
                if (is_array($arguments) && array_is_list($arguments)) $arguments = new \stdClass();
                $call = ['type'=>'tool_call', 'id'=>$callId, 'name'=>$name,
                    'arguments'=>$arguments];
                if (is_string($message['namespace'] ?? null) && $message['namespace'] !== '') {
                    $call['namespace'] = $message['namespace'];
                }
                $last = count($projected) - 1;
                if ($last >= 0 && ($projected[$last]['role'] ?? null) === 'assistant'
                    && is_array($projected[$last]['content'] ?? null)) {
                    array_push($projected[$last]['content'], ...$pendingReasoning);
                    $projected[$last]['content'][] = $call;
                } else {
                    $projected[] = ['role'=>'assistant', 'content'=>[...$pendingReasoning, $call]];
                }
                $pendingReasoning = [];
                continue;
            }
            if (in_array($type, ['function_call_output', 'custom_tool_call_output', 'tool_search_output'], true)
                && is_string($message['call_id'] ?? null) && isset($names[$message['call_id']])) {
                $result = ['role'=>'tool_result', 'tool_call_id'=>$message['call_id'],
                    'tool_name'=>$names[$message['call_id']]['name'],
                    ...($names[$message['call_id']]['namespace'] !== ''
                        ? ['tool_namespace'=>$names[$message['call_id']]['namespace']] : []),
                    'is_error'=>false,
                    'content'=>$type === 'tool_search_output'
                        ? self::canonical(['status'=>$message['status'] ?? 'completed', 'tools'=>$message['tools'] ?? []])
                        : ($message['output'] ?? '')];
                $projected[] = $result;
                $pendingReasoning = [];
                continue;
            }
            if ($pendingReasoning !== [] && ($message['role'] ?? null) === 'assistant') {
                $content = $message['content'] ?? '';
                $message['content'] = [...$pendingReasoning,
                    ...(is_array($content) ? $content : [['type'=>'text', 'text'=>(string)$content]])];
                $pendingReasoning = [];
            } elseif (($message['role'] ?? null) !== 'assistant') {
                $pendingReasoning = [];
            }
            $projected[] = $message;
        }
        $context['messages'] = $projected;
        return $context;
    }

    /** Hash a native tool receipt using the same wire encoding as toolOutputs(). */
    public static function toolReceiptHash(mixed $output): string
    {
        $text = is_string($output) ? $output
            : json_encode($output, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        return hash('sha256', $text);
    }

    /** Native environment data is not a human instruction, even though Codex uses role=user. */
    public static function isOperationalUserMessage(array $message): bool
    {
        if (($message['role'] ?? '') !== 'user') return false;
        if (($message['origin'] ?? '') === 'codex_environment') return true;
        $metadata = $message['internal_chat_message_metadata_passthrough'] ?? null;
        if ($metadata instanceof \stdClass) $metadata = get_object_vars($metadata);
        if (is_array($metadata) && in_array('environments.environment_context',
            (array) ($metadata['content_item_kinds'] ?? []), true)) return true;
        $content = $message['content'] ?? '';
        if (is_array($content)) {
            $parts = [];
            foreach ($content as $part) {
                if (!is_array($part) || !in_array($part['type'] ?? '', ['text', 'input_text', 'output_text'], true)
                    || !is_string($part['text'] ?? null)) return false;
                $parts[] = $part['text'];
            }
            $content = implode("\n", $parts);
        }
        // Only a complete standalone envelope qualifies. Quotes, code fences and human
        // requests discussing this XML remain ordinary human messages.
        return is_string($content)
            && preg_match('/\A\s*<environment_context>(?:(?!<\/?environment_context>).)*<\/environment_context>\s*\z/sD', $content) === 1;
    }

    /** Exact field/value identity independent of JSON object-key order. */
    private static function containsRecord(array $records, array $record): bool
    {
        $canonical = self::canonical($record);
        foreach ($records as $known) if (self::canonical($known) === $canonical) return true;
        return false;
    }

    /**
     * Called inside the existing short reservation transaction. Infer native continuation by a
     * locally issued call_id, and send only an exact matching history suffix on the same epoch.
     * A changed prefix rotates the lease before submitting, so edits cannot contaminate old pages.
     */
    public static function prepareUnlocked(array $spec): array
    {
        $db = SDB(null, 'medoo', 'sqlite');
        $taskId = 'task_' . substr((string) $spec['task_key'], 0, 24);
        $context = json_decode((string) $spec['context_json'], true, 128, JSON_THROW_ON_ERROR);
        $previous = (string) ($spec['previous_response_id'] ?? '');
        if ($previous !== '') {
            $parent = $db->table('gateway_response_jobs')->where('id', $previous)->find();
            if (!is_array($parent)) {
                $roles = array_column($context['messages'], 'role');
                // A decoded checkpoint replaces the earlier assistant transcript. It is a complete
                // readable history even when only its retained user text and summary remain.
                $hasCheckpoint = false;
                if (($spec['compaction_epoch'] ?? 'base') !== 'base') {
                    $summaryPrefix = ContextCompaction::SUMMARY_PREFIX . "\n";
                    foreach ($context['messages'] as $message) {
                        $content = $message['content'] ?? null;
                        if (($message['role'] ?? null) === 'user' && is_string($content)
                            && str_starts_with($content, $summaryPrefix)
                            && trim(substr($content, strlen($summaryPrefix))) !== '') {
                            $hasCheckpoint = true;
                            break;
                        }
                    }
                }
                if (!empty($spec['input_is_delta']) || !in_array('user', $roles, true)
                    || (!in_array('assistant', $roles, true) && !$hasCheckpoint)) {
                    throw new GatewayError('previous_response_not_found', '本地没有该续接点；切换 Web 模型需要客户端提供可读的完整对话历史。', 400);
                }
                // A foreign handle is not authority to fetch another provider's history.
                // The explicitly supplied readable transcript is sufficient for a new local epoch.
                $spec['previous_response_id'] = null;
                $previous = '';
            }
        }
        $outputs = (array) ($spec['tool_outputs'] ?? []);
        if ($outputs === [] && ($spec['operation_kind'] ?? 'response') === 'response') {
            $steeringJoin = self::activeToolBoundarySteeringUnlocked($spec, $taskId);
            if ($steeringJoin !== null) {
                $spec['operational_context_source_response_id'] = (string) $steeringJoin['source']['id'];
                $spec['operational_context_updates'] = $steeringJoin['updates'];
                $spec['steering_context_join'] = true;
                return $spec;
            }
            $mailbox = self::activeMailboxContextUpdateUnlocked($spec, $taskId);
            if ($mailbox !== null) {
                if ($mailbox['restart']) {
                    $complete = json_decode((string) ($spec['full_context_json'] ?? $spec['context_json']), true, 128, JSON_THROW_ON_ERROR);
                    $pending = TaskLedger::pendingOperationalContextUnlocked($mailbox['source']);
                    foreach ($pending['messages'] as $message) {
                        if (!self::containsRecord($complete['messages'], $message)) $complete['messages'][] = $message;
                    }
                    unset($complete['continuation']);
                    $spec['mailbox_context_restart'] = true;
                    $spec['mailbox_context_messages'] = $mailbox['updates']['messages'];
                    $spec['operational_context_event_ids'] = $pending['event_ids'];
                    $spec['previous_response_id'] = (string) $mailbox['source']['id'];
                    $spec['turn_id'] = $mailbox['client_turn_id'];
                    $spec['context_json'] = $spec['full_context_json'] = $spec['transport_context_json'] = self::canonical($complete);
                    $spec['rolling_source_response_id'] = null;
                    $spec['continuation_source_response_id'] = null;
                    $spec['prompt'] = self::plainPrompt($complete);
                    return $spec;
                }
                $spec['operational_context_source_response_id'] = (string) $mailbox['source']['id'];
                $spec['operational_context_updates'] = $mailbox['updates'];
                $spec['mailbox_context_join'] = true;
                return $spec;
            }
            $spec = self::prepareActiveUserSteeringUnlocked($spec, $context, $taskId);
            if (!empty($spec['steering_restart']) || !empty($spec['operational_context_source_response_id'])) return $spec;
        }
        if ($outputs !== [] && $previous === '') {
            $pending = null; $results = [];
            foreach ($outputs as $output) {
                $call = $db->table('gateway_tool_invocations')->where('task_id', $taskId)->where('call_id', $output['call_id'])->find();
                if (!is_array($call) || !in_array($call['status'], ['registered', 'delivered'], true)) continue;
                if ($pending !== null && $pending['response_id'] !== $call['response_id']) throw new GatewayError('tool_result_scope_mismatch', '同一请求混入不同回合的工具结果。', 409);
                $pending = $call; $results[] = $output;
            }
            if ($pending !== null) {
                $prior = $db->table('gateway_response_jobs')->where('id', $pending['response_id'])->find();
                if (!is_array($prior)) throw new GatewayError('tool_output_without_call', '工具调用的宿主回合缺失。', 409);
                $spec['previous_response_id'] = $previous = (string) $prior['id'];
                $spec['turn_id'] = $prior['turn_id'];
                $spec['tool_outputs'] = $results;
            } else {
                // Outputs supplied inside imported full history are data, not fresh tool receipts.
                $onlyResults = count($context['messages']) === count($outputs);
                if ($onlyResults) throw new GatewayError('tool_output_without_previous_response', '未找到属于当前任务的待回传工具调用。', 400);
                $spec['tool_outputs'] = [];
            }
        }
        if (($spec['tool_outputs'] ?? []) !== []) {
            $prior = $db->table('gateway_response_jobs')->where('id', $previous)->find();
            if (is_array($prior) && $prior['task_id'] === $taskId && $prior['round_kind'] === 'tool_call') {
                if ($prior['model'] !== $spec['model'] || $prior['reasoning_effort'] !== ($spec['reasoning_effort'] ?? null)) {
                    throw new GatewayError('tool_result_scope_mismatch', '工具回执必须使用发起该调用时的模型和档位。', 409);
                }
                // Codex retains its original metadata turn_id after auto-compaction;
                // the exact locally issued call belongs to the new ledger turn.
                if (str_starts_with((string) ($prior['turn_id'] ?? ''), 'postcompact_')) {
                    $spec['turn_id'] = (string) $prior['turn_id'];
                }
                // A receipt belongs to an already executing physical turn, including its budget.
                // A temporary catalog refresh or subscription upgrade cannot discard that receipt.
                $spec['budget'] = json_decode((string) $prior['budget_json'], true, 32, JSON_THROW_ON_ERROR);
                if (in_array($spec['operation_kind'] ?? 'response', ['compact_v1', 'compact_v2'], true)) {
                    // The separate summary operation pins the current administrative wait;
                    // every other limit remains the source turn's exact snapshot.
                    $spec['budget']['compaction_timeout_seconds'] = (int) GatewaySettings::internal()['compaction_timeout_seconds'];
                }
                // Tool results extend the actual browser context, which may already be compacted.
                // Revalidating the entire canonical transcript here would undo rolling compaction.
                $response = json_decode((string) $prior['response_json'], true, 128, JSON_THROW_ON_ERROR);
                $round = self::compile(array_merge($response['output'] ?? [], $spec['tool_outputs']), '');
                $priorFull = json_decode((string) $prior['context_json'], true, 128, JSON_THROW_ON_ERROR);
                $steering = self::toolRoundSteering($context, $priorFull, $spec['tool_outputs']);
                $contextUpdates = self::toolRoundContextUpdates($context, $priorFull, $spec['tool_outputs']);
                $pending = TaskLedger::pendingOperationalContextUnlocked($prior);
                if ($pending['messages'] !== []) {
                    $contextUpdates = $contextUpdates ?: ['version' => 3, 'system' => [], 'messages' => []];
                    $queued = [];
                    $queuedSteering = [];
                    foreach ($pending['messages'] as $message) {
                        // Human revisions queued at tool publication still require the ordinary
                        // steering restart after the exact host receipt, even for delta clients.
                        if (($message['role'] ?? '') === 'user' && !self::isOperationalUserMessage($message)) {
                            if (!self::containsRecord($priorFull['messages'], $message)) $queuedSteering[] = $message;
                            continue;
                        }
                        if (!self::containsRecord($priorFull['messages'], $message)
                            && !self::containsRecord($contextUpdates['messages'], $message)) $queued[] = $message;
                    }
                    $steering = array_merge($queuedSteering, array_values(array_filter($steering,
                        static fn(array $message): bool => !self::containsRecord($queuedSteering, $message))));
                    $contextUpdates['messages'] = array_merge($queued, $contextUpdates['messages']);
                    $spec['operational_context_event_ids'] = $pending['event_ids'];
                }
                $spec['steering_messages'] = $steering;
                $spec['tool_context_updates'] = $contextUpdates;
                $round['messages'] = array_merge($round['messages'], (array) ($contextUpdates['messages'] ?? []), $steering);
                $transport = json_decode((string) ($prior['transport_context_json'] ?? $prior['context_json']), true, 128, JSON_THROW_ON_ERROR);
                $transport['messages'] = array_merge($transport['messages'], $round['messages']);
                $transport['system'] = array_merge((array) ($transport['system'] ?? []), (array) ($contextUpdates['system'] ?? []));
                $spec['transport_context_json'] = self::canonical($transport);
                $spec['rolling_source_response_id'] = $prior['rolling_source_response_id'] ?? null;
                // Delta-only clients still get a complete canonical record for the next user turn.
                $receiptDelta = array_reduce($context['messages'], static fn(bool $delta, array $record): bool => $delta
                    && (in_array($record['type'] ?? '', ['function_call_output', 'custom_tool_call_output', 'tool_search_output'], true)
                        || in_array($record['role'] ?? '', ['developer', 'agent_message'], true)
                        || self::isOperationalUserMessage($record)), true);
                if ($receiptDelta || $steering !== [] || $pending['messages'] !== []) {
                    $priorFull['messages'] = array_merge($priorFull['messages'], $round['messages']);
                    $priorFull['system'] = array_merge((array) ($priorFull['system'] ?? []), (array) ($contextUpdates['system'] ?? []));
                    $spec['full_context_json'] = self::canonical($priorFull);
                }
            }
            return $spec;
        }
        $last = $db->table('gateway_response_jobs')->where('task_id', $taskId)->where('status', 'completed')
            ->where('round_kind', 'text')->order([['rowid', 'DESC']])->limit(1)->find();
        $task = $db->table('gateway_tasks')->where('id', $taskId)->find();
        if (is_array($task) && in_array($task['status'], ['cancelled', 'needs_attention'], true)) {
            // A failed/cancelled turn can contain the newest readable user context. Its
            // output is not trusted, but dropping that context would strand delta clients.
            $terminalLast = $db->table('gateway_response_jobs')->where('task_id', $taskId)
                ->order([['rowid', 'DESC']])->limit(1)->find();
            if (is_array($terminalLast) && !empty($terminalLast['context_json'])) $last = $terminalLast;
        }
        if ($previous !== '') {
            $prior = $db->table('gateway_response_jobs')->where('id', $previous)->find();
            if (is_array($prior) && (string) ($prior['task_id'] ?? '') === $taskId) $last = $prior;
        }
        if (!is_array($last) || empty($last['context_json']) || ($spec['operation_kind'] ?? 'response') !== 'response') return $spec;
        $priorContext = json_decode((string) $last['context_json'], true, 128, JSON_THROW_ON_ERROR);
        $priorRecords = (array) ($priorContext['messages'] ?? []);
        $records = (array) ($context['messages'] ?? []);
        if (($previous !== '' || !empty($spec['input_is_delta'])) && count($records) <= 1 && ($records[0]['role'] ?? '') === 'user') {
            $answer = ($last['status'] ?? '') === 'completed' && ($last['round_kind'] ?? '') === 'text'
                && (string) ($last['output_text'] ?? '') !== ''
                ? [['role' => 'assistant', 'content' => (string) $last['output_text']]] : [];
            $records = array_merge($priorRecords, $answer, $records);
            $context['messages'] = $records;
        }
        $pending = TaskLedger::pendingOperationalContextUnlocked($last);
        $queued = array_values(array_filter($pending['messages'],
            static fn(array $message): bool => !self::containsRecord($records, $message)));
        if ($queued !== []) {
            // These updates have not reached the old page. Carry them immediately before
            // the next human input so an exact-prefix followup cannot skip them with the old answer.
            $at = count($records);
            while ($at > 0 && ($records[$at - 1]['role'] ?? '') === 'user') $at--;
            array_splice($records, $at, 0, $queued);
            $context['messages'] = $records;
        }
        $spec['operational_context_event_ids'] = $pending['event_ids'];
        $sameSystem = self::canonical($context['system']) === self::canonical($priorContext['system'] ?? []);
        $samePrefix = count($records) >= count($priorRecords)
            && self::canonical(array_slice($records, 0, count($priorRecords))) === self::canonical($priorRecords);
        $sameLease = (string) $last['model'] === (string) $spec['model']
            && ($last['reasoning_effort'] ?? null) === ($spec['reasoning_effort'] ?? null)
            && (string) ($last['compaction_epoch'] ?? 'base') === (string) $spec['compaction_epoch'];
        $surface = $db->table('gateway_browser_surfaces')->where('id', (string) ($last['surface_id'] ?? ''))->find();
        $task = $db->table('gateway_tasks')->where('id', $taskId)->find();
        $usable = is_array($surface) && !in_array($surface['status'], ['retired', 'needs_attention'], true) && !empty($surface['target_id'])
            && $surface['task_id'] === $taskId && $surface['epoch_id'] === ($task['current_epoch_id'] ?? null)
            && $surface['epoch_id'] === $last['epoch_id']
            && !empty($last['submitted_document_nonce']) && $last['submitted_document_nonce'] === $surface['document_nonce'];
        // Older tool-result rounds could persist a newly parsed lease while still using the
        // original page. The page ledger, not that derived job field, owns the physical lease.
        if ($sameLease && $sameSystem && $samePrefix && $usable) {
            $spec['browser_lease_key'] = (string) $surface['lease_key'];
            $root = $db->table('gateway_response_jobs')->where('durable_turn_id', $last['durable_turn_id'])
                ->order([['round_no', 'ASC']])->limit(1)->find();
            if (is_array($root) && preg_match('/<codex_context_json>\s*(.*)\s*<\/codex_context_json>/s', $root['prompt'], $match)) {
                $physical = json_decode($match[1], true);
                // A retained page still contains the old browser-only tool envelope.
                // Rebuild it once so corrected tool arguments and receipts reach the model.
                if (is_array($physical) && self::hasLegacyWebToolEnvelope($physical)) $usable = false;
                // A legacy delta has no destination proof. Rebuild once instead of inheriting
                // a page that may have been created with only "continue" as its entire history.
                if (($physical['continuation']['mode'] ?? '') === 'exact_prefix'
                    && ($physical['continuation']['source_surface_id'] ?? '') !== $surface['id']) $usable = false;
            }
        }
        $spec['full_context_json'] = self::canonical($context);
        // Delta-only Responses clients need the reconstructed history in the physical prompt
        // too whenever the old page cannot be reused, not merely in the durable audit column.
        $spec['context_json'] = $spec['full_context_json'];
        $spec['prompt'] = self::plainPrompt($context);
        if ($sameSystem && $samePrefix && $sameLease && $usable) {
            // The retained ChatGPT page already has the previous answer. Codex may replay
            // commentary and final as separate assistant records; omit through the last one.
            $suffixStart = count($priorRecords);
            for ($index = count($records) - 1; $index >= $suffixStart; $index--) {
                if (($records[$index]['role'] ?? null) === 'assistant') {
                    $suffixStart = $index + 1;
                    break;
                }
            }
            $suffix = array_slice($records, $suffixStart);
            if ($suffix === []) throw new GatewayError('empty_context_delta', '请求没有新的用户输入或工具结果。', 400);
            $context['messages'] = $suffix;
            $context['system'] = [];
            $context['continuation'] = ['mode' => 'exact_prefix', 'previous_context_sha256' => hash('sha256', self::canonical($priorContext)),
                'source_surface_id' => $surface['id'], 'source_epoch_id' => $surface['epoch_id'],
                'source_response_id' => $last['id'], 'source_document_nonce' => $surface['document_nonce'],
                'current_tool_environment_sha256' => $spec['tool_environment_hash']];
            $spec['context_json'] = self::canonical($context);
            $spec['prompt'] = self::plainPrompt($context);
        } elseif (!$sameSystem || !$samePrefix || !$usable) {
            $spec['browser_lease_key'] = hash('sha256', $spec['browser_lease_key'] . '|history|' . hash('sha256', self::canonical($context)));
        }
        return $spec;
    }

    private static function hasLegacyWebToolEnvelope(array $context): bool
    {
        foreach ((array)($context['messages'] ?? []) as $message) {
            if (!is_array($message)) continue;
            if (($message['role'] ?? null) === 'tool_result' && !array_key_exists('is_error', $message)) return true;
            if (($message['role'] ?? null) !== 'assistant' || !is_array($message['content'] ?? null)) continue;
            foreach ($message['content'] as $part) {
                if (is_array($part) && ($part['type'] ?? null) === 'tool_call'
                    && is_string($part['arguments'] ?? null)) return true;
            }
        }
        return false;
    }

    /**
     * Distinguish old transcript outputs from pending host receipts before routing a new user item.
     * The caller owns the reservation transaction; the incoming key still identifies the original
     * conversation, so cancelled/restarted conversations must resolve their current task first.
     * @param array $spec Normalized request with the native conversation key and trailing outputs.
     * @return array Request retaining current-task receipts or demoting settled history only.
     */
    public static function demoteHistoricalToolOutputsUnlocked(array $spec): array
    {
        $outputs = (array) ($spec['tool_outputs'] ?? []);
        if ($outputs === []) return $spec;
        $context = json_decode((string) $spec['context_json'], true, 128, JSON_THROW_ON_ERROR);
        $last = $context['messages'][array_key_last($context['messages'])] ?? null;
        if (($last['role'] ?? '') !== 'user') return $spec;
        $taskId = 'task_' . substr((string) $spec['task_key'], 0, 24);
        $db = SDB(null, 'medoo', 'sqlite');
        $link = $db->table('gateway_task_conversations')->where('base_task_id', $taskId)->find();
        if (is_array($link)) {
            // Match TaskLedger's routing without rewriting the request key twice.
            $taskId = 'task_' . substr((string) $link['current_task_key'], 0, 24);
        }
        foreach ($outputs as $output) {
            $call = $db->table('gateway_tool_invocations')->where('task_id', $taskId)
                ->where('call_id', (string) ($output['call_id'] ?? ''))->find();
            if (is_array($call) && in_array($call['status'], ['registered', 'delivered'], true)) return $spec;
            if (is_array($call) && $call['status'] === 'unknown') {
                $task = $db->table('gateway_tasks')->where('id', $taskId)->find();
                // Keep candidates privately for strict timeout reconciliation, while preserving
                // the existing historical-output demotion for other new user conversations.
                if (is_array($task) && $task['status'] === 'needs_attention') $spec['late_tool_outputs'] = $outputs;
            }
        }
        $spec['tool_outputs'] = [];
        return $spec;
    }

    /**
     * Extract new human instructions accompanying the exact pending native receipt batch.
     * Codex can put steering before, between or after parallel receipts, and can refresh its
     * developer preamble without changing the human instruction lineage. Preserve every new
     * instruction in order, while replayed user IDs and operational context remain history.
     */
    private static function toolRoundSteering(array $context, array $prior, array $outputs): array
    {
        $records = (array) ($context['messages'] ?? []);
        $known = (array) ($prior['messages'] ?? []);
        $prefix = count($records) >= count($known)
            && self::canonical(array_slice($records, 0, count($known))) === self::canonical($known);
        $ids = array_fill_keys(array_column($outputs, 'call_id'), true);
        $last = null;
        foreach ($records as $index => $record) {
            if (in_array($record['type'] ?? '', ['function_call_output', 'custom_tool_call_output', 'tool_search_output'], true)
                && isset($ids[$record['call_id'] ?? ''])) {
                $last = $index;
            }
        }
        if ($last === null) return [];

        $knownIds = [];
        $knownContent = [];
        foreach ($known as $record) {
            if (($record['role'] ?? '') !== 'user' || self::isOperationalUserMessage($record)) continue;
            if (is_string($record['id'] ?? null) && $record['id'] !== '') $knownIds[$record['id']] = true;
            unset($record['id']);
            $knownContent[self::canonical($record)] = true;
        }
        $humanPrefixEnd = self::sharedHumanPrefixEnd($records, $known);
        $deltaOnly = array_reduce($records, static fn(bool $ok, array $record): bool => $ok
            && (in_array($record['role'] ?? '', ['user', 'developer', 'agent_message'], true)
                || in_array($record['type'] ?? '', ['function_call_output', 'custom_tool_call_output', 'tool_search_output'], true)), true);
        $steering = [];
        foreach ($records as $index => $record) {
            if (($record['role'] ?? '') !== 'user' || self::isOperationalUserMessage($record)) continue;
            $id = (string) ($record['id'] ?? '');
            if ($id !== '' && isset($knownIds[$id])) continue;
            $contentIdentity = $record;
            unset($contentIdentity['id']);
            // Without a native ID an old repeated message is not proof of a new instruction.
            // Identical text with a genuinely new ID, however, is a new human revision.
            if ($id === '' && isset($knownContent[self::canonical($contentIdentity)])) continue;
            $provedSuffix = ($prefix && $index >= count($known))
                || ($humanPrefixEnd !== null && $index > $humanPrefixEnd);
            if ($provedSuffix || $deltaOnly || $index > $last) $steering[] = $record;
        }
        return $steering;
    }

    /**
     * Prove the same ordered human history despite refreshed non-human preamble/tool records.
     * Returns the last shared human record's index, or null if that lineage is not established.
     * Native IDs must agree when both sides supply them; omitted IDs require exact readable
     * content. This proof neither invents missing history nor treats environment input as human.
     */
    private static function sharedHumanPrefixEnd(array $records, array $known): ?int
    {
        $knownUsers = array_values(array_filter($known, static fn(array $record): bool =>
            ($record['role'] ?? '') === 'user' && !self::isOperationalUserMessage($record)));
        if ($knownUsers === []) return null;
        $matched = 0;
        foreach ($records as $index => $record) {
            if (($record['role'] ?? '') !== 'user' || self::isOperationalUserMessage($record)) continue;
            $expected = $knownUsers[$matched];
            $recordId = (string) ($record['id'] ?? '');
            $expectedId = (string) ($expected['id'] ?? '');
            if ($recordId !== '' && $expectedId !== '' && $recordId !== $expectedId) return null;
            unset($record['id'], $expected['id']);
            if (self::canonical($record) !== self::canonical($expected)) return null;
            if (++$matched === count($knownUsers)) return $index;
        }
        return null;
    }

    /**
     * Carry hook/mailbox context accompanying the exact pending receipt batch.
     * Records retain their roles and must never become human user steering.
     * @param array $context Newly compiled native request.
     * @param array $prior Durable source context before the receipt batch.
     * @param array $outputs Exact pending receipts selected for the source response.
     * @return array Empty array or a role-preserving version-3 context update.
     */
    private static function toolRoundContextUpdates(array $context, array $prior, array $outputs): array
    {
        $ids = array_fill_keys(array_column($outputs, 'call_id'), true);
        $afterReceipt = false;
        $messages = [];
        $known = (array) ($prior['messages'] ?? []);
        foreach ((array) ($context['messages'] ?? []) as $record) {
            if (in_array($record['type'] ?? '', ['function_call_output', 'custom_tool_call_output', 'tool_search_output'], true)
                && isset($ids[$record['call_id'] ?? ''])) {
                $afterReceipt = true;
                continue;
            }
            if (($afterReceipt && in_array($record['role'] ?? '', ['developer', 'agent_message'], true))
                || (self::isOperationalUserMessage($record) && !self::containsRecord($known, $record))) {
                $messages[] = $record;
            }
        }
        $system = (array) ($context['system'] ?? []);
        $knownSystem = (array) ($prior['system'] ?? []);
        if (self::canonical(array_slice($system, 0, count($knownSystem))) === self::canonical($knownSystem)) {
            $system = array_slice($system, count($knownSystem));
        }
        return $messages === [] && $system === [] ? []
            : ['version' => 3, 'system' => $system, 'messages' => $messages];
    }

    /**
     * Prove a native mailbox delivery extends the exact source and select receipt joining or restart.
     * Only unchanged durable history and that source's completed public commentary may precede
     * new agent records. A completed tool-call response still owns its pending host batch, so a
     * mailbox arriving at that publication boundary rejoins the already issued calls.
     * @param array $spec Compiled complete native request; no fresh tool receipt is permitted.
     * @param ?string $taskId Routed task ID inside reservation, or null for a readiness check.
     * @return ?array{source:array,updates:array,restart:bool,client_turn_id:string} Proven source,
     *     role-preserving updates and whether no pending host execution requires a new model round.
     * @throws GatewayError If durable source evidence cannot be decoded or read.
     */
    public static function activeMailboxContextUpdateUnlocked(array $spec, ?string $taskId = null): ?array
    {
        return self::activeNativeContextUpdateUnlocked($spec, $taskId, false);
    }

    /**
     * Prove a new human revision racing an already published tool batch. Rejoin its exact
     * response until native receipts arrive; do not cancel, duplicate or infer host effects.
     * @param array $spec Full native history with no fresh host receipt.
     * @param ?string $taskId Already routed task ID, or null during readiness checks.
     * @return ?array Verified source and role-preserving updates, or null for any mismatch.
     * @throws GatewayError If durable source evidence is unreadable.
     */
    public static function activeToolBoundarySteeringUnlocked(array $spec, ?string $taskId = null): ?array
    {
        return self::activeNativeContextUpdateUnlocked($spec, $taskId, true);
    }

    /** Shared exact-source proof; human joins are restricted to a pending published tool batch. */
    private static function activeNativeContextUpdateUnlocked(array $spec, ?string $taskId, bool $humanSteering): ?array
    {
        if (($spec['operation_kind'] ?? 'response') !== 'response'
            || (array) ($spec['tool_outputs'] ?? []) !== [] || empty($spec['turn_id'])) return null;
        $incoming = json_decode((string) ($spec['full_context_json'] ?? $spec['context_json']), true, 128, JSON_THROW_ON_ERROR);
        $records = (array) ($incoming['messages'] ?? []);
        if ($humanSteering) {
            $last = $records[array_key_last($records)] ?? [];
            if (($last['role'] ?? '') !== 'user' || self::isOperationalUserMessage($last)) return null;
        } elseif (!self::containsAgentMessage($incoming)) return null;
        $db = SDB(null, 'medoo', 'sqlite');
        $owner = $spec['browser_owner_key'] ?? null;
        $link = null;
        if ($taskId === null) {
            $taskId = 'task_' . substr((string) $spec['task_key'], 0, 24);
            $link = $db->table('gateway_task_conversations')->where('base_task_id', $taskId)->find();
            if (is_array($link)) {
                $taskId = (string) $link['current_task_id'];
                $owner = $link['current_owner_key'];
            }
        }
        $task = $db->table('gateway_tasks')->where('id', $taskId)->find();
        if (!is_array($task) || $task['status'] !== 'active' || $task['owner_key'] !== $owner
            || $task['client_id'] !== ($spec['client_id'] ?? 'responses')
            || $task['thread_id'] !== ($spec['thread_id'] ?? null)
            || $task['branch_id'] !== ($spec['branch_id'] ?? 'main')) return null;
        $turn = $db->table('gateway_task_turns')->where('task_id', $taskId)
            ->where('status', 'IN', ['queued', 'submit_intent', 'submitted', 'running'])->find();
        if ($humanSteering && !is_array($turn)) return null;
        if (!is_array($turn)) {
            $lineage = $spec;
            $lineage['task_key'] = (string) ($link['current_task_key'] ?? $spec['task_key']);
            $lineage['previous_response_id'] = null;
            $lineage['context_json'] = self::canonical($incoming);
            $lineage = ContextCompaction::resumeCommittedTurnUnlocked($lineage);
            $turn = $db->table('gateway_task_turns')->where('task_id', $taskId)
                ->where('client_turn_id', (string) $lineage['turn_id'])->where('status', 'committed')->find();
        }
        if (!is_array($turn)) return null;
        if ((string) $turn['client_turn_id'] !== (string) $spec['turn_id']) {
            if (!str_starts_with((string) $turn['client_turn_id'], 'postcompact_')) return null;
            // A derived active turn must belong to this original native turn's verified checkpoint.
            $lineage = $spec;
            $lineage['task_key'] = (string) ($link['current_task_key'] ?? $spec['task_key']);
            $lineage['previous_response_id'] = null;
            $lineage['context_json'] = self::canonical($incoming);
            $lineage = ContextCompaction::resumeCommittedTurnUnlocked($lineage);
            if ((string) $lineage['turn_id'] !== (string) $turn['client_turn_id']) return null;
        }
        $sourceId = (string) ($turn['response_id'] ?? '');
        $previous = (string) ($spec['previous_response_id'] ?? '');
        if ($sourceId === '' || ($previous !== '' && $previous !== $sourceId)) return null;
        $source = $db->table('gateway_response_jobs')->where('id', $sourceId)->find();
        if (!is_array($source) || $source['task_id'] !== $taskId
            || $source['durable_turn_id'] !== $turn['id'] || $source['generation_id'] !== $turn['generation_id']
            || ($source['operation_kind'] ?? 'response') !== 'response'
            || $source['model'] !== $spec['model']
            || ($source['reasoning_effort'] ?? null) !== ($spec['reasoning_effort'] ?? null)
            || (string) ($source['compaction_epoch'] ?? 'base') !== (string) ($spec['compaction_epoch'] ?? 'base')
            || (string) ($source['tool_environment_hash'] ?? '') !== (string) ($spec['tool_environment_hash'] ?? '')) return null;
        $sourceBudget = json_decode((string) $source['budget_json'], true, 128, JSON_THROW_ON_ERROR);
        if (self::canonical((array) ($sourceBudget['output_control'] ?? []))
            !== self::canonical((array) ($spec['budget']['output_control'] ?? []))) return null;
        $running = in_array((string) $source['status'], ['queued', 'running'], true)
            && (string) $source['round_kind'] === 'text';
        $toolBoundary = (string) $source['status'] === 'completed' && (string) $source['round_kind'] === 'tool_call';
        if ($humanSteering && !$toolBoundary) return null;
        if ($humanSteering) {
            $binding = $db->table('gateway_mcp_bindings')->where('id', (string) ($source['mcp_binding_id'] ?? ''))->find();
            if (!is_array($binding) || $binding['status'] !== 'running' || $binding['task_id'] !== $taskId
                || $binding['turn_id'] !== $turn['id'] || $binding['current_response_id'] !== $sourceId) return null;
        }
        $completedText = (string) $turn['status'] === 'committed'
            && (string) $source['status'] === 'completed' && (string) $source['round_kind'] === 'text';
        if (!$running && !$toolBoundary && !$completedText) return null;
        $pending = $db->table('gateway_tool_invocations')->where('task_id', $taskId)
            ->where('turn_id', (string) $turn['id'])->where('binding_id', (string) ($source['mcp_binding_id'] ?? ''))
            ->where('status', 'IN', ['registered', 'delivered'])->count();
        $unknown = $db->table('gateway_tool_invocations')->where('task_id', $taskId)
            ->where('turn_id', (string) $turn['id'])->where('status', 'unknown')->count();
        $activities = $db->table('gateway_mcp_activities')->where('binding_id', (string) ($source['mcp_binding_id'] ?? ''))
            ->where('status', 'active')->count();
        if (!is_numeric($pending) || !is_numeric($unknown) || !is_numeric($activities)
            || (int) $unknown !== 0 || ($toolBoundary && (int) $pending === 0)) return null;
        if ($completedText) {
            $last = $db->table('gateway_response_jobs')->where('task_id', $taskId)
                ->order([['rowid', 'DESC']])->limit(1)->find();
            if (!is_array($last) || (string) $last['id'] !== $sourceId
                || (int) $pending !== 0 || (int) $activities !== 0) return null;
            if (!empty($source['mcp_binding_id'])) {
                $binding = $db->table('gateway_mcp_bindings')->where('id', (string) $source['mcp_binding_id'])->find();
                if (!is_array($binding) || $binding['status'] !== 'completed'
                    || (string) $binding['current_response_id'] !== $sourceId) return null;
            }
        }
        $prior = json_decode((string) $source['context_json'], true, 128, JSON_THROW_ON_ERROR);
        $known = (array) ($prior['messages'] ?? []);
        $base = $prior['system'][0] ?? null;
        $lite = !empty($spec['native_responses_lite']);
        $offset = $lite && is_string($base) && $base !== ''
            && ($records[0]['role'] ?? '') === 'developer' && ($records[0]['content'] ?? null) === $base
            && self::canonical($known[0] ?? null) !== self::canonical($records[0] ?? null) ? 1 : 0;
        $prefix = $incoming;
        $prefix['messages'] = array_slice($records, 0, count($known) + $offset);
        $priorTop = $prior;
        $incomingTop = $incoming;
        unset($priorTop['messages'], $priorTop['system'], $incomingTop['messages'], $incomingTop['system']);
        if (self::canonical($priorTop) !== self::canonical($incomingTop)
            || !self::matchesCompactionSource($prior, $prefix, $lite, true)) return null;
        $response = json_decode((string) ($source['response_json'] ?? '{}'), true, 128, JSON_THROW_ON_ERROR);
        $commentary = [];
        foreach ((array) ($response['output'] ?? []) as $item) {
            if (($item['type'] ?? '') === 'message' && ($item['role'] ?? '') === 'assistant'
                && ($item['phase'] ?? '') === 'commentary' && ($item['status'] ?? '') === 'completed') {
                $commentary[] = self::compile([$item], '')['messages'][0];
            }
        }
        $commentaryIndex = 0;
        $updates = [];
        $hasMailbox = false;
        $hasHuman = false;
        $knownUserIds = [];
        foreach ($known as $record) {
            if (($record['role'] ?? '') === 'user' && !empty($record['id'])) $knownUserIds[(string) $record['id']] = true;
        }
        foreach (array_slice($records, count($known) + $offset) as $record) {
            if (($record['role'] ?? '') === 'assistant') {
                if (!isset($commentary[$commentaryIndex])
                    || self::canonical($record) !== self::canonical($commentary[$commentaryIndex++])) return null;
            } elseif (($record['role'] ?? '') === 'agent_message') {
                $updates[] = $record;
                $hasMailbox = true;
            } elseif (self::isOperationalUserMessage($record)) {
                $updates[] = $record;
            } elseif ($humanSteering && ($record['role'] ?? '') === 'user') {
                $id = (string) ($record['id'] ?? '');
                if (($id !== '' && isset($knownUserIds[$id])) || self::containsRecord($known, $record)
                    || self::containsRecord($updates, $record)) return null;
                if ($id !== '') $knownUserIds[$id] = true;
                $updates[] = $record;
                $hasHuman = true;
            } else {
                return null;
            }
        }
        if ($humanSteering ? !$hasHuman : (!$hasMailbox || $commentaryIndex === 0)) return null;
        return ['source' => $source, 'restart' => (int) $pending === 0,
            'client_turn_id' => (string) $turn['client_turn_id'],
            'updates' => ['version' => 3, 'system' => [], 'messages' => $updates]];
    }

    /** A new native user item can supersede an active Web generation without a tool receipt. */
    private static function prepareActiveUserSteeringUnlocked(array $spec, array $incoming, string $taskId): array
    {
        $clientTurnId = (string) ($spec['turn_id'] ?? '');
        if ($clientTurnId === '') return $spec;
        $db = SDB(null, 'medoo', 'sqlite');
        $turn = $db->table('gateway_task_turns')->where('task_id', $taskId)
            ->where('client_turn_id', $clientTurnId)->find();
        if (!is_array($turn) || !in_array((string) ($turn['status'] ?? ''),
            ['queued', 'submit_intent', 'submitted', 'running'], true)) {
            // Native Codex keeps its original metadata turn_id after an automatic
            // checkpoint while this gateway may own a derived postcompact turn.
            $active = $db->table('gateway_task_turns')->where('task_id', $taskId)
                ->where('status', 'IN', ['queued', 'submit_intent', 'submitted', 'running'])->find();
            if (is_array($active) && str_starts_with((string) ($active['client_turn_id'] ?? ''), 'postcompact_')) {
                $turn = $active;
            }
        }
        if (!is_array($turn) || !in_array((string) $turn['status'],
            ['queued', 'submit_intent', 'submitted', 'running'], true)) return $spec;
        $sourceId = (string) ($turn['response_id'] ?? '');
        $previous = (string) ($spec['previous_response_id'] ?? '');
        if ($sourceId === '' || ($previous !== '' && $previous !== $sourceId)) return $spec;
        $source = $db->table('gateway_response_jobs')->where('id', $sourceId)->find();
        if (!is_array($source) || !in_array((string) ($source['status'] ?? ''), ['queued', 'running'], true)
            || (string) ($source['round_kind'] ?? '') !== 'text'
            || (string) ($source['task_id'] ?? '') !== $taskId
            || (string) ($source['model'] ?? '') !== (string) ($spec['model'] ?? '')
            || ($source['reasoning_effort'] ?? null) !== ($spec['reasoning_effort'] ?? null)) return $spec;
        $prior = json_decode((string) $source['context_json'], true, 128, JSON_THROW_ON_ERROR);
        $known = (array) ($prior['messages'] ?? []);
        $records = (array) ($incoming['messages'] ?? []);
        $samePrefix = count($records) > count($known)
            && self::canonical((array) ($incoming['system'] ?? [])) === self::canonical((array) ($prior['system'] ?? []))
            && self::canonical(array_slice($records, 0, count($known))) === self::canonical($known);
        if ($samePrefix) {
            $context = $incoming;
            $tail = array_slice($records, count($known));
        } elseif (count($records) === 1 && ($records[0]['role'] ?? '') === 'user'
            && ($previous === $sourceId || !empty($spec['input_is_delta'])
                || (is_string($records[0]['id'] ?? null) && $records[0]['id'] !== ''))) {
            // A delta has no historical system/messages; rebuild from the durable source.
            $context = $prior;
            $context['messages'] = array_merge($known, $records);
            $tail = $records;
        } else {
            return $spec;
        }
        $newUsers = [];
        $operational = [];
        for ($index = count($tail) - 1; $index >= 0; $index--) {
            if (($tail[$index]['role'] ?? '') !== 'user') break;
            if (self::isOperationalUserMessage($tail[$index])) {
                array_unshift($operational, $tail[$index]);
                continue;
            }
            array_unshift($newUsers, $tail[$index]);
        }
        if ($newUsers === []) {
            $task = $db->table('gateway_tasks')->where('id', $taskId)->find();
            if ($operational === [] || count($operational) !== count($tail)
                || !is_array($task) || $task['status'] !== 'active'
                || $task['client_id'] !== ($spec['client_id'] ?? 'responses')
                || $task['thread_id'] !== ($spec['thread_id'] ?? null)
                || $task['branch_id'] !== ($spec['branch_id'] ?? 'main')
                || $task['owner_key'] !== ($spec['browser_owner_key'] ?? null)
                || (!empty($spec['tool_environment_provided'])
                    && ($spec['tool_environment_hash'] ?? null) !== ($source['tool_environment_hash'] ?? null))) return $spec;
            $spec['operational_context_source_response_id'] = $sourceId;
            $spec['operational_context_updates'] = ['version' => 3, 'system' => [], 'messages' => $operational];
            return $spec;
        }
        // An unchanged native item is a retry, not another steering instruction.
        $knownIds = [];
        foreach ($known as $record) {
            if (($record['role'] ?? '') === 'user' && is_string($record['id'] ?? null)
                && $record['id'] !== '') $knownIds[$record['id']] = true;
        }
        foreach ($newUsers as $record) {
            $id = (string) ($record['id'] ?? '');
            if ($id !== '' && isset($knownIds[$id])) return $spec;
        }
        if (!$samePrefix && !isset($newUsers[0]['id'])) {
            $lastKnownUser = null;
            foreach ($known as $record) if (($record['role'] ?? '') === 'user') $lastKnownUser = $record;
            if ($lastKnownUser !== null && self::canonical($lastKnownUser) === self::canonical($newUsers[0])) {
                return $spec;
            }
        }
        $pending = TaskLedger::pendingOperationalContextUnlocked($source);
        $queued = array_values(array_filter($pending['messages'],
            static fn(array $message): bool => !self::containsRecord($context['messages'], $message)));
        if ($queued !== []) array_splice($context['messages'], count($known), 0, $queued);
        $spec['operational_context_event_ids'] = $pending['event_ids'];
        unset($context['continuation']);
        $full = self::canonical($context);
        $spec['steering_restart'] = true;
        $spec['steering_messages'] = $newUsers;
        $spec['turn_id'] = (string) $turn['client_turn_id'];
        $spec['previous_response_id'] = $sourceId;
        $spec['budget'] = json_decode((string) $source['budget_json'], true, 32, JSON_THROW_ON_ERROR);
        $spec['context_json'] = $spec['full_context_json'] = $spec['transport_context_json'] = $full;
        $spec['rolling_source_response_id'] = null;
        $spec['continuation_source_response_id'] = null;
        $spec['prompt'] = self::plainPrompt($context);
        return $spec;
    }

    /**
     * Bind transport to the page actually reserved in the same transaction. A tool receipt
     * inherits that page's lease; a delta can never seed a different, empty temporary chat.
     * @param array $spec Normalized request, retaining its complete recovery context.
     * @param array $reservation TaskLedger reservation made by the current transaction.
     * @return array Request with the confirmed lease and appropriate physical context.
     * @throws GatewayError If the reserved page or physical context epoch is inconsistent.
     */
    public static function bindReservedSurfaceUnlocked(array $spec, array $reservation): array
    {
        $surface = SDB(null, 'medoo', 'sqlite')->table('gateway_browser_surfaces')->where('id', $reservation['surface_id'])->find();
        if (!is_array($surface) || $surface['task_id'] !== 'task_' . substr((string) $spec['task_key'], 0, 24)
            || $surface['task_id'] !== $reservation['task_id'] || $surface['epoch_id'] !== $reservation['epoch_id']) {
            throw new GatewayError('task_surface_missing', '上下文续接的任务页面记录不一致。', 503);
        }
        $spec['browser_lease_key'] = (string) $surface['lease_key'];
        if (!empty($spec['tool_outputs'])) {
            $epoch = SDB(null, 'medoo', 'sqlite')->table('gateway_task_epochs')->where('id', $surface['epoch_id'])->find();
            if (!is_array($epoch) || $epoch['task_id'] !== $surface['task_id']) {
                throw new GatewayError('task_ledger_incomplete', '工具回执的上下文代次无法读取。', 503);
            }
            // A delta receipt may contain no checkpoint item; parsing it as "base" must not
            // reset the physical conversation's already established compaction identity.
            $spec['compaction_epoch'] = (string) $epoch['compaction_epoch'];
            return $spec;
        }
        $context = json_decode((string) $spec['context_json'], true, 128, JSON_THROW_ON_ERROR);
        $continuation = $context['continuation'] ?? [];
        if (($continuation['mode'] ?? '') !== 'exact_prefix') return $spec;
        if (($continuation['source_surface_id'] ?? '') === $surface['id']
            && ($continuation['source_epoch_id'] ?? '') === $surface['epoch_id'] && !empty($surface['target_id'])
            && ($continuation['source_document_nonce'] ?? '') === $surface['document_nonce']) {
            $spec['continuation_source_response_id'] = $continuation['source_response_id'];
            return $spec;
        }
        $spec['continuation_source_response_id'] = null;
        $spec['context_json'] = (string) $spec['full_context_json'];
        $spec['transport_context_json'] = $spec['context_json'];
        $spec['prompt'] = ($spec['operation_kind'] ?? 'response') === 'response'
            ? self::plainPrompt(json_decode($spec['context_json'], true, 128, JSON_THROW_ON_ERROR))
            : ContextCompaction::prompt($spec['context_json']);
        return $spec;
    }

    /**
     * Prove a previously submitted Connector belongs to this exact retained document.
     * Composer pills disappear after submission; their absence does not revoke the chat binding.
     * @param array $job Current durable job with its final physical prompt.
     * @param array $surface Current owned page, after attachment.
     * @param string $connectorName Current turn's persisted exact Connector identity.
     * @return bool False for fresh/rebuilt documents, changed identity, or incomplete source turns.
     */
    public static function retainedConnector(array $job, array $surface, string $connectorName): bool
    {
        if (empty($job['mcp_binding_id']) || !self::retainedContext($job, $surface)) return false;
        $db = SDB(null, 'medoo', 'sqlite');
        $source = $db->table('gateway_response_jobs')->where('id', $job['continuation_source_response_id'])->find();
        if (empty($source['mcp_binding_id'])) return false;
        $binding = $db->table('gateway_mcp_bindings')->where('id', $source['mcp_binding_id'])->find();
        // A completed prose-only turn proves the page, but not that ChatGPT ever
        // loaded this Connector. Reselect it for the next tool-capable user turn.
        return is_array($binding) && $binding['status'] === 'completed' && $binding['sent_at'] !== null
            && $binding['started_at'] !== null
            && $binding['connector_name'] === $connectorName && $binding['task_id'] === $job['task_id'];
    }

    /**
     * Verify the physical source before using a reserved delta, including after CDP reattachment.
     * The persisted send identity survives tool receipts; a rebuilt/reloaded document cannot
     * borrow proof from an older completed job that merely shares the same surface row.
     * @param array $job Current execution row containing its selected continuation source.
     * @param array $surface Current physical document, not a pre-attachment snapshot.
     * @return bool True only for a completed source in this task/epoch/document.
     */
    public static function retainedContext(array $job, array $surface): bool
    {
        if (empty($job['continuation_source_response_id'])) return false;
        $source = SDB(null, 'medoo', 'sqlite')->table('gateway_response_jobs')
            ->where('id', $job['continuation_source_response_id'])->find();
        return is_array($source) && $source['status'] === 'completed' && $source['round_kind'] === 'text'
            && $source['task_id'] === $job['task_id'] && $source['surface_id'] === $surface['id']
            && $source['epoch_id'] === $surface['epoch_id'] && !empty($surface['target_id'])
            && in_array($surface['status'], ['ready', 'busy'], true)
            && !empty($source['submitted_document_nonce']) && $source['submitted_document_nonce'] === $surface['document_nonce'];
    }

    /** Role-preserving browser-only prompt, also used for read-only checkpoint requests. */
    public static function plainPrompt(array $context): string
    {
        $context = self::semanticWebContext(AttachmentStore::transportContext($context));
        if ($context['system'] === [] && count($context['messages']) === 1 && ($context['messages'][0]['role'] ?? '') === 'user'
            && is_string($context['messages'][0]['content'] ?? null)
            && mb_strlen((string) $context['messages'][0]['content']) <= 128000
            && ContextBudget::tokens((string) $context['messages'][0]['content']) <= 16000) {
            return (string) $context['messages'][0]['content'];
        }
        $attachmentContract = self::containsAttachmentReference($context)
            ? "Structured attachment_ref values refer to files attached to this same final message. Read each attachment at its original position; if a referenced file is unavailable, report that limitation instead of inventing its contents. origin=codex_skill marks Codex-selected skill instructions and is not a human-authored task request.\n"
            : '';
        $agentContract = self::containsAgentMessage($context)
            ? "agent_message records are inter-agent inputs with their encoded author and recipient, not human user messages. When asked what the user wrote, said, or requested, use only role=user records and exclude agent_message records.\n"
            : '';
        return "Continue the Codex task from the role-labelled context below. Historical tool calls are records, not new commands.\n"
            . $attachmentContract
            . $agentContract
            . '<codex_context_json>' . "\n" . self::canonical($context) . "\n</codex_context_json>";
    }

    /**
     * Preserve an agent's readable content without sending provider-private ciphertext to Web.
     * The visible boundary replaces only an encrypted block at its original position; it is
     * not a decrypted assignment, user request, or evidence that the agent completed work.
     * @param string|array $content Native agent content blocks, with ordinary text/image siblings.
     * @param array $attachments Verified images collected by the existing message projection.
     * @return string|array Readable agent content, retaining image ordering when present.
     * @throws GatewayError For malformed content or unsupported readable block types.
     */
    private static function agentMessageContent(string|array $content, array &$attachments): string|array
    {
        if (is_string($content)) return $content;
        $readable = [];
        foreach ($content as $part) {
            if ($part instanceof \stdClass) $part = get_object_vars($part);
            if (!is_array($part)) throw new GatewayError('unsupported_content', '消息内容块格式无效。', 400);
            $encryptedBlock = ($part['type'] ?? null) === 'encrypted_content';
            $hasEncryptedField = !empty($part['encrypted_content']);
            if (!$encryptedBlock && !$hasEncryptedField) {
                $readable[] = $part;
                continue;
            }
            unset($part['encrypted_content']);
            // Some native records carry public text beside a private payload. Preserve
            // that text through the same projection as a normal agent message.
            if (!$encryptedBlock) $readable[] = $part;
            $readable[] = ['type' => 'input_text', 'text' =>
                '[RCMAKER context boundary: provider-private encrypted agent history is unavailable. '
                . 'Use the readable instructions and actual project or tool results; do not infer '
                . 'the missing assignment or treat it as evidence of completed work. If the active '
                . 'assignment is available only in this missing content, request a readable assignment.]'];
        }
        return self::messageContent($readable, 'agent_message', false, 'inline', $attachments);
    }

    private static function containsAgentMessage(array $context): bool
    {
        foreach ((array) ($context['messages'] ?? []) as $message) {
            if (is_array($message) && ($message['role'] ?? null) === 'agent_message') return true;
        }
        return false;
    }

    private static function containsAttachmentReference(mixed $value): bool
    {
        if (!is_array($value)) return false;
        if (is_string($value['attachment_ref'] ?? null)) return true;
        foreach ($value as $item) if (self::containsAttachmentReference($item)) return true;
        return false;
    }
}
