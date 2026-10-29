<?php
declare(strict_types=1);
namespace app\gateway\model;

/** Recover an opaque child's initial assignment from its own verified parent's issued spawn input. */
final class NativeAgentAssignment
{
    /**
     * Project only the initial NEW_TASK record at browser dispatch preparation. The canonical
     * native archive stays byte-for-byte unchanged for receipt, restart and checkpoint matching.
     * Parent tool inputs are readable evidence, not a replay or a claim that a pending spawn finished.
     * @param array $spec Reserved request whose native child scope was independently verified.
     * @param string $prompt Final browser prompt including its existing execution contracts.
     * @param string $transport Physical context selected by the existing history planner.
     * @param array $budget Pinned model budget; only source identity metadata is added.
     * @param int $inputTokens Previously counted logical input usage.
     * @param int $contextTokens Previously counted logical context before attachment reserves.
     * @param int $attachmentTokens Original logical attachment reserve.
     * @param ?string $budgetContext Exact selected logical source already counted by admission.
     * @return array Final prompt/context/budget/usage, without changing the native archive or tools.
     * @throws GatewayError If the missing initial assignment has no unique owned source or cannot fit.
     */
    public static function projectUnlocked(array $spec, string $prompt, string $transport, array $budget,
        int $inputTokens, int $contextTokens, int $attachmentTokens, ?string $budgetContext = null): array
    {
        $unchanged = [$prompt, $transport, $budget, $inputTokens];
        $scope = $budget['native_subagent'] ?? null;
        if (!is_array($scope) || ($scope['thread_id'] ?? '') !== ($spec['thread_id'] ?? null)
            || ContextHistory::canonical(CodexSubagent::scope($spec['thread_id'] ?? null))
                !== ContextHistory::canonical(array_intersect_key($scope,
                    array_flip(['thread_id','parent_thread_id','agent_path','depth'])))) return $unchanged;
        $db = SDB(null, 'medoo', 'sqlite');
        $ownTask = 'task_' . substr((string) $spec['task_key'], 0, 24);
        $prior = $db->table('gateway_response_jobs')->where('task_id', $ownTask)
            ->order([['rowid','DESC']])->limit(1)->find('budget_json');
        $known = is_array($prior) ? json_decode((string) $prior['budget_json'], true, 64) : null;
        $pinned = $known['native_subagent']['assignment'] ?? null;
        if (is_array($pinned)) {
            // An exact-prefix/receipt round may omit the initial record physically. Keep
            // its read-only source identity so a later full page recovery can re-project it.
            $budget['native_subagent']['assignment'] = $pinned;
            $unchanged[2] = $budget;
        }
        $physical = json_decode($transport, true, 128, JSON_THROW_ON_ERROR);
        $records = [];
        $parentScope = CodexSubagent::scope((string) $scope['parent_thread_id']);
        $parentPath = $parentScope['agent_path'] ?? '/root';
        foreach ((array) ($physical['messages'] ?? []) as $record) {
            if (self::initialRecord($record, $scope, $parentPath)) $records[] = $record;
        }
        if ($records === []) return $unchanged;
        if (count($records) !== 1) self::unavailable();
        $original = reset($records);
        $source = self::sourceUnlocked($spec, $scope, $parentPath, hash('sha256', ContextHistory::canonical($original)), $pinned);
        $replacement = $original;
        $content = (string) $original['content'];
        $boundary = ContextHistory::OPAQUE_AGENT_NOTICE;
        if (substr_count($content, $boundary) !== 1) self::unavailable();
        $replacement['content'] = str_replace($boundary, $source['message'], $content);
        $originalCanonical = ContextHistory::canonical($original);
        $project = static function (array $context) use ($originalCanonical, $replacement): array {
            foreach ((array) ($context['messages'] ?? []) as $index => $message) {
                if (($message['role'] ?? '') === 'agent_message' && ContextHistory::canonical($message) === $originalCanonical) {
                    $context['messages'][$index] = $replacement;
                }
            }
            return $context;
        };
        $physical = $project($physical);
        // Work on the role-labelled envelope in place; do not regenerate or discard tool,
        // steering, checkpoint or task-binding contracts outside that envelope.
        $open = '<codex_context_json>';
        $start = strpos($prompt, $open);
        if ($start !== false) {
            $envelope = \support\browser\ChatGptTransport::contextEnvelope($prompt);
            $at = $envelope['start'] + strlen($open);
            $prompt = substr($prompt, 0, $at) . "\n" . ContextHistory::canonical($project($envelope['context']))
                . "\n" . substr($prompt, $envelope['end']);
        }
        // Count the replacement in the same logical source used by admission. Never append
        // an unbudgeted assignment after accepting a nearly full imported/checkpoint window.
        $logical = json_decode($budgetContext ?? (string) ($spec['full_context_json'] ?? $spec['context_json']), true, 128, JSON_THROW_ON_ERROR);
        $projectedTokens = max($contextTokens,
            ContextBudget::tokens(ContextHistory::canonical($project($logical)), $budget),
            ContextBudget::tokens(ContextHistory::canonical($physical), $budget));
        if (($spec['operation_kind'] ?? 'response') === 'response' && empty($spec['tool_outputs'])) {
            ContextBudget::validateTokens($projectedTokens, $budget, $attachmentTokens);
        }
        $inputTokens = ContextBudget::inputTokensFromCount($projectedTokens, $budget, $attachmentTokens);
        $budget['native_subagent']['assignment'] = [
            'task_id'=>$source['task_id'], 'call_id'=>$source['call_id'],
            'message_sha256'=>hash('sha256', $source['message']),
            'record_sha256'=>hash('sha256', ContextHistory::canonical($original)),
        ];
        return [$prompt, ContextHistory::canonical($physical), $budget, $inputTokens];
    }

    /** Only the native initial-task wrapper with a missing private block is eligible; replies/followups stay intact. */
    private static function initialRecord(mixed $record, array $scope, string $parentPath): bool
    {
        return is_array($record) && ($record['role'] ?? '') === 'agent_message'
            && ($record['author'] ?? '') === $parentPath && ($record['recipient'] ?? '') === $scope['agent_path']
            && is_string($record['content'] ?? null)
            && str_starts_with($record['content'], "Message Type: NEW_TASK\nTask name: " . $scope['agent_path']
                . "\nSender: " . $parentPath . "\nPayload:\n")
            && str_contains($record['content'], ContextHistory::OPAQUE_AGENT_NOTICE);
    }

    /**
     * Match the exact native parent and unique issued spawn, using indexed task/call lookups.
     * A completed result must name this child; a delivered call may still be executing when
     * its child makes its first request. Neither case mutates or acknowledges the parent call.
     */
    private static function sourceUnlocked(array $spec, array $scope, string $parentPath, string $recordHash, mixed $pinned): array
    {
        $db = SDB(null, 'medoo', 'sqlite');
        if (is_array($pinned) && ($pinned['record_sha256'] ?? '') === $recordHash) {
            $task = $db->table('gateway_tasks')->where('id', (string) ($pinned['task_id'] ?? ''))->find('id,client_id,thread_id,branch_id');
            if (is_array($task) && $task['thread_id'] === $scope['parent_thread_id']
                && $task['client_id'] === $spec['client_id'] && $task['branch_id'] === $spec['branch_id']) {
                $call = $db->table('gateway_tool_invocations')->where('task_id', $task['id'])
                    ->where('call_id', (string) ($pinned['call_id'] ?? ''))->where('wire_name', 'collaboration__spawn_agent')->find();
                $message = is_array($call) ? self::message($call, $scope, $parentPath) : null;
                if ($message !== null && hash_equals((string) ($pinned['message_sha256'] ?? ''), hash('sha256', $message))) {
                    return ['task_id'=>$task['id'], 'call_id'=>$call['call_id'], 'message'=>$message];
                }
            }
            self::unavailable();
        }
        $owner = hash('sha256', json_encode(['kind'=>'thread','id'=>$scope['parent_thread_id']], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $base = 'task_' . substr(hash('sha256', json_encode(['client'=>$spec['client_id'], 'owner'=>$owner,
            'branch'=>$spec['branch_id']], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)), 0, 24);
        $link = $db->table('gateway_task_conversations')->where('base_task_id', $base)->find();
        $tasks = array_unique([$base, is_array($link) ? (string) $link['current_task_id'] : $base]);
        $matches = [];
        foreach ($tasks as $taskId) {
            $task = $db->table('gateway_tasks')->where('id', $taskId)->find('id,client_id,thread_id,branch_id');
            if (!is_array($task) || $task['thread_id'] !== $scope['parent_thread_id']
                || $task['client_id'] !== $spec['client_id'] || $task['branch_id'] !== $spec['branch_id']) continue;
            $calls = $db->table('gateway_tool_invocations')->where('task_id', $taskId)
                ->where('wire_name', 'collaboration__spawn_agent')->order([['rowid','DESC']])->limit(129)
                ->select('task_id,call_id,status,arguments_json,result_json');
            if (!is_array($calls) || count($calls) > 128) self::unavailable();
            foreach ($calls as $call) {
                $message = self::message($call, $scope, $parentPath);
                if ($message !== null) $matches[] = ['task_id'=>$taskId, 'call_id'=>$call['call_id'], 'message'=>$message];
            }
        }
        if (count($matches) !== 1) self::unavailable();
        return $matches[0];
    }

    /** Read an issued parent input; completed native errors or another child's result cannot prove this assignment. */
    private static function message(array $call, array $scope, string $parentPath): ?string
    {
        $arguments = json_decode((string) $call['arguments_json'], true, 32);
        if (!is_array($arguments) || !is_string($arguments['task_name'] ?? null)
            || $parentPath . '/' . $arguments['task_name'] !== $scope['agent_path']
            || !is_string($arguments['message'] ?? null) || trim($arguments['message']) === ''
            || !in_array($call['status'], ['delivered','completed'], true)) return null;
        if ($call['status'] === 'completed') {
            $result = json_decode((string) $call['result_json'], true, 32);
            $output = $result['output'] ?? null;
            if (is_string($output)) $output = json_decode($output, true, 32);
            if (!is_array($output) || ($output['task_name'] ?? '') !== $scope['agent_path']) return null;
        }
        return $arguments['message'];
    }

    /** Fail before any browser submission instead of running an agent with no actual assignment. */
    private static function unavailable(): never
    {
        throw new GatewayError('native_agent_assignment_unavailable',
            'The encrypted subagent assignment has no unique verified parent tool input. Provide a readable assignment; no Web message was sent.', 400);
    }
}
