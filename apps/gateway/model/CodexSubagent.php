<?php
declare(strict_types=1);
namespace app\gateway\model;

use RC\Config;

/** Native session evidence and Web-only child policy; root tasks and ordinary forks keep their own choices. */
final class CodexSubagent
{
    /** Bounded immutable metadata cache; incomplete headers are retried, never inferred from conversation text. */
    private static array $scopes = [];

    /**
     * Read only the exact native session header, not task instructions or its parent history.
     * UUIDv7 dates bound normal lookup to three day folders; older UUIDs use the existing
     * literal session layout once per cached lookup. A parent_thread_id alone is a fork, not proof.
     * @param ?string $threadId Native thread UUID.
     * @return ?array Verified child/parent/path/depth, or null without unambiguous native evidence.
     */
    public static function scope(?string $threadId): ?array
    {
        if ($threadId === null || !CodexCancellation::uuid($threadId)) return null;
        $home = trim((string) Config::get('gateway', 'codex_home'));
        $root = $home === '' ? false : realpath(rtrim($home, '/\\') . '/sessions');
        if ($root === false) return null;
        $key = $root . '|' . $threadId;
        $known = self::$scopes[$key] ?? null;
        if (is_array($known) && $known['verified']) return $known['scope'];
        if (count(self::$scopes) >= 128) array_shift(self::$scopes);
        self::$scopes[$key] = ['at'=>microtime(true), 'verified'=>false, 'scope'=>null];
        $literal = strtr($root, ['['=>'[[]', '*'=>'[*]', '?'=>'[?]']);
        $directories = ['*/*/*'];
        if ($threadId[14] === '7') {
            $seconds = intdiv((int) hexdec(substr(str_replace('-', '', $threadId), 0, 12)), 1000);
            $directories = array_unique(array_map(static fn(int $offset): string => gmdate('Y/m/d', $seconds + $offset), [-86400,0,86400]));
        }
        $paths = [];
        foreach ($directories as $directory) {
            foreach (glob($literal . '/' . $directory . '/rollout-*-' . $threadId . '.jsonl', GLOB_NOESCAPE) ?: [] as $path) $paths[] = $path;
        }
        if (count($paths) !== 1) return null;
        $path = realpath($paths[0]);
        $boundary = str_replace('\\', '/', $root) . '/';
        $candidate = $path === false ? '' : str_replace('\\', '/', $path);
        if (PHP_OS_FAMILY === 'Windows') { $boundary = strtolower($boundary); $candidate = strtolower($candidate); }
        if ($path === false || !str_starts_with($candidate, $boundary)) return null;
        $stream = @fopen($path, 'rb');
        if (!is_resource($stream)) return null;
        try { $line = fgets($stream, 65536); }
        finally { fclose($stream); }
        $record = is_string($line) && str_ends_with($line, "\n") ? json_decode($line, true, 32) : null;
        $meta = $record['payload'] ?? null;
        if (($record['type'] ?? '') !== 'session_meta' || !is_array($meta) || ($meta['id'] ?? null) !== $threadId) return null;
        $source = is_array($meta['source'] ?? null) ? $meta['source'] : [];
        $subagent = is_array($source['subagent'] ?? null) ? $source['subagent'] : [];
        $spawn = $subagent['thread_spawn'] ?? null;
        $scope = null;
        if (is_array($spawn) && is_string($spawn['parent_thread_id'] ?? null)
            && CodexCancellation::uuid($spawn['parent_thread_id']) && $spawn['parent_thread_id'] !== $threadId
            && (!isset($meta['parent_thread_id']) || $meta['parent_thread_id'] === $spawn['parent_thread_id'])
            && is_int($spawn['depth'] ?? null) && $spawn['depth'] > 0 && $spawn['depth'] <= 32
            && is_string($spawn['agent_path'] ?? null) && str_starts_with($spawn['agent_path'], '/root/')
            && strlen($spawn['agent_path']) <= 512) {
            $scope = ['thread_id'=>$threadId, 'parent_thread_id'=>$spawn['parent_thread_id'],
                'agent_path'=>$spawn['agent_path'], 'depth'=>$spawn['depth']];
        }
        self::$scopes[$key] = ['at'=>microtime(true), 'verified'=>true, 'scope'=>$scope];
        return $scope;
    }

    /**
     * Prove legacy active child-page identities before entering the writer transaction.
     * Pre-policy stores had at most five retained pages. Inspect that bounded set plus
     * five current primary pages; never scan response history or read session files under a write lock.
     * @return list<array> Immutable native scope paired with the exact existing task owner.
     * @throws GatewayError When the page metadata cannot be read.
     */
    public static function legacyPageEvidence(): array
    {
        $rows = SDB(null, 'medoo', 'sqlite')->table('gateway_browser_surfaces AS s')
            ->join('gateway_tasks AS t', 't.id', 's.task_id')->where('s.status', '<>', 'retired')
            ->where('t.native_parent_thread_id', null)->limit(TaskLedger::MAX_SURFACES * 2)
            ->select('t.id,t.thread_id,t.client_id,t.branch_id,t.owner_key');
        if (!is_array($rows)) throw new GatewayError('storage_unavailable', 'Subagent page identity is unavailable.', 503);
        $evidence = [];
        foreach ($rows as $row) {
            $scope = self::scope($row['thread_id']);
            if ($scope !== null) $evidence[] = ['task'=>$row, 'scope'=>$scope];
        }
        return $evidence;
    }

    /**
     * Validate a console selection independently of transient account availability.
     * @param string $model Empty follows the requested child family without Pro; explicit values are Web IDs.
     * @param string $effort Supported non-Pro wire effort. @return void
     * @throws GatewayError For a native/Pro model or a reasoning selection incompatible with its identity.
     */
    public static function validateSelection(string $model, string $effort): void
    {
        if (!in_array($effort, ['low','medium','high','xhigh'], true)
            || ($model !== '' && !str_starts_with($model, 'chatgpt-web/'))) {
            throw new GatewayError('INVALID_SETTINGS', 'Choose a non-Pro Web model and a supported subagent reasoning level.', 400);
        }
        if ($model === '') return;
        $route = ModelCatalog::identity($model, $effort);
        if ($route['effort'] !== $effort || $route['effort'] === 'max') {
            throw new GatewayError('INVALID_SETTINGS', 'The subagent model does not support that non-Pro reasoning level.', 400);
        }
    }

    /**
     * Select only verified Web children. Repeat at reservation; exact issued receipts and
     * active generations keep their original model until settled, including legacy Pro children.
     * New generations use the console choice, and carry a durable private scope marker.
     * @param array $spec Parsed Web request; its wire hash and host tool environment are immutable.
     * @return array Scoped effective model/budget, or the identical root/fork request.
     * @throws GatewayError On unavailable task state or invalid child configuration.
     */
    public static function prepare(array $spec): array
    {
        $scope = self::scope($spec['thread_id'] ?? null);
        if ($scope === null) return $spec;
        $db = SDB(null, 'medoo', 'sqlite');
        $taskId = 'task_' . substr((string) $spec['task_key'], 0, 24);
        $link = $db->table('gateway_task_conversations')->where('base_task_id', $taskId)->find();
        if (is_array($link)) $taskId = (string) $link['current_task_id'];
        $task = $db->table('gateway_tasks')->where('id', $taskId)->find();
        if (is_array($task) && ($task['thread_id'] !== $scope['thread_id']
            || $task['client_id'] !== $spec['client_id'] || $task['branch_id'] !== $spec['branch_id'])) return $spec;
        $settings = GatewaySettings::internal();
        $effort = (string) $settings['subagent_reasoning_effort'];
        $model = (string) $settings['subagent_web_model'];
        self::validateSelection($model, $effort);
        if ($model === '') {
            $family = ModelCatalog::identity($spec['model'], $spec['reasoning_effort'] ?? null)['family'] ?? 'latest';
            $model = 'chatgpt-web/model/' . $family;
        }
        $source = null;
        $outputs = (array) ($spec['tool_outputs'] ?? []);
        if ($outputs !== []) {
            foreach ($outputs as $output) {
                $call = $db->table('gateway_tool_invocations')->where('task_id', $taskId)
                    ->where('call_id', (string) $output['call_id'])->where('status', 'IN', ['registered','delivered'])->find('response_id');
                if (is_array($call) && !empty($call['response_id'])) {
                    $source = $db->table('gateway_response_jobs')->where('id', $call['response_id'])->where('task_id', $taskId)
                        ->find('model,reasoning_effort,status');
                    break;
                }
            }
        }
        if (!is_array($source) && is_array($task)) {
            $last = ResponseJob::latestForTaskUnlocked($taskId, true);
            $pending = $db->table('gateway_tool_invocations')->where('task_id', $taskId)
                ->where('status', 'IN', ['registered','delivered','unknown'])->count();
            if (!is_numeric($pending)) throw new GatewayError('storage_unavailable', 'Subagent tool state is unavailable.', 503);
            if (is_array($last) && (in_array($last['status'], ['queued','running'], true) || (int) $pending > 0)) $source = $last;
        }
        $deferred = is_array($source);
        if ($deferred) { $model = (string) $source['model']; $effort = $source['reasoning_effort']; }
        $route = ModelCatalog::identity($model, $effort);
        $budget = ContextBudget::snapshot($model, $route['effort'], $route['capabilities']);
        if (isset($spec['budget']['output_control'])) $budget['output_control'] = $spec['budget']['output_control'];
        if (isset($spec['budget']['reasoning_configuration'])) {
            $budget['reasoning_configuration'] = $spec['budget']['reasoning_configuration'];
            $budget['reasoning_configuration']['model'] = $model;
            $budget['reasoning_configuration']['effort'] = $route['effort'];
        }
        $budget['native_subagent'] = $scope + ['deferred'=>$deferred];
        $spec['model'] = $model;
        $spec['reasoning_effort'] = $route['effort'];
        $spec['budget'] = $budget;
        // Conversation routing may rotate the task key; the owning ledger re-pins its surface later.
        $spec['browser_lease_key'] = hash('sha256', json_encode([
            'namespace'=>'rcmaker-chatgpt-web-v1', 'owner'=>$spec['browser_owner_key'], 'model'=>$model,
            'reasoning'=>$route['effort'], 'compaction'=>$spec['compaction_epoch'],
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        return $spec;
    }
}
