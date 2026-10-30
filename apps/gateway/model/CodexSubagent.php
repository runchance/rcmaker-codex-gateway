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
     * Project native collaboration spawn/followup guidance exposed by a Web binding.
     * Console selection is pinned for independent native spawns and checked again by prepare();
     * optional native-model overrides cannot bypass the Web-only child policy.
     * The raw host capability snapshot and unrelated connectors remain unchanged.
     * @param array $tool One normalized bound host descriptor. @return array Visible descriptor.
     */
    public static function hostTool(array $tool): array
    {
        if (($tool['wire_name'] ?? '') === 'collaboration__followup_task'
            && ($tool['namespace'] ?? '') === 'collaboration' && ($tool['name'] ?? '') === 'followup_task'
            && ($tool['type'] ?? '') === 'function' && empty($tool['freeform'])) {
            $tool['description'] = ($tool['description'] ?? '')
                . "\nReusing an agent does not change its original model. If a native-model child's initial assignment failed with invalid_encrypted_content before work started, "
                . 'create a fresh child inheriting this Web transport for that unstarted assignment instead of retrying the same failed native agent. '
                . 'Healthy Web children remain reusable; preserve completed work and exact receipts. '
                . 'Reusing an inherited-model child also keeps its native context-window metadata. If a new assignment is rejected for context_length_exceeded before Web submission and that child cannot compact, preserve completed results and delegate only the remaining work to a fresh independent child with fork_turns=none; do not repeat completed operations. '
                . 'A gateway capacity_limited notice means no new assignment was issued; wait for existing children or continue independent work yourself. Do not wait for an unissued assignment or retry rapidly.';
            return $tool;
        }
        if (!self::isHostSpawn($tool)) return $tool;
        unset($tool['parameters']['properties']['model'], $tool['parameters']['properties']['reasoning_effort']);
        $tool['description'] = ($tool['description'] ?? '') . "\nRCMAKER manages this Web task's child model and reasoning level in the console. "
            . 'Omit model and reasoning_effort: RCMAKER pins independent new agents to the console-selected Web model in both the native runtime and gateway. '
            . 'Full-history forks retain native inheritance and do not accept overrides. '
            . 'Keep the authorized assignment and fork_turns unchanged. '
            . 'The console sets a gateway-wide running-child limit. A capacity_limited notice means no child was created; '
            . 'wait for existing children or continue independent work yourself, without blocking on an uncreated descendant or retrying rapidly. '
            . 'If native collaboration reports its agent thread limit, no new agent started; reuse a known idle agent instead of repeatedly spawning another name.';
        return $tool;
    }

    /**
     * Pin an independent new Web child to the native catalog row matching its actual budget.
     * Full-history forks retain inheritance. Exact issued calls retain their pinned arguments.
     * No task text, history choice, native identity, other host tool or existing call is rewritten.
     * @param array $tool Exact bound descriptor. @param array $arguments Validated function object.
     * @return array Effective spawn arguments before assigning its durable native call identity.
     */
    public static function hostArguments(array $tool, array $arguments, array $binding = [], ?array $existing = null): array
    {
        if (!self::isHostSpawn($tool)) return $arguments;
        unset($arguments['model'], $arguments['reasoning_effort']);
        if (is_array($existing)) {
            $pinned = json_decode((string)$existing['arguments_json'], true);
            if (is_array($pinned)) {
                $plain = $pinned;
                unset($plain['model'], $plain['reasoning_effort']);
                if (ContextHistory::canonical($plain) === ContextHistory::canonical($arguments)) return $pinned;
            }
        }
        if (($arguments['fork_turns'] ?? 'all') !== 'none') return $arguments;
        $parameters = $tool['parameters'] ?? [];
        // The visible catalog deliberately hides overrides. Check support against
        // this exact binding's immutable raw descriptor before adding native fields.
        if (!isset($parameters['properties']['model'], $parameters['properties']['reasoning_effort'])) {
            $environment = json_decode((string)($binding['tool_environment_json'] ?? ''), true);
            foreach ((array)($environment['tools'] ?? []) as $raw) {
                if (is_array($raw) && self::isHostSpawn($raw) && $raw['wire_name'] === $tool['wire_name']) {
                    $parameters = $raw['parameters'] ?? []; break;
                }
            }
        }
        if (!isset($parameters['properties']['model'], $parameters['properties']['reasoning_effort'])) return $arguments;
        $settings = GatewaySettings::internal();
        $model = (string)$settings['subagent_web_model'];
        $effort = (string)$settings['subagent_reasoning_effort'];
        self::validateSelection($model, $effort);
        if ($model === '') {
            $parent = empty($binding['current_response_id']) ? null : SDB(null, 'medoo', 'sqlite')
                ->table('gateway_response_jobs')->where('id', $binding['current_response_id'])->find('model');
            $family = is_array($parent) ? (ModelCatalog::identity($parent['model'], null)['family'] ?? 'latest') : 'latest';
            $model = 'chatgpt-web/model/' . $family;
        }
        $identity = ModelCatalog::identity($model, $effort);
        $effort = $identity['effort'];
        $family = $identity['family'] ?? 'latest';
        $route = in_array($model, ['chatgpt-web/luna','chatgpt-web/think'], true)
            ? ['id'=>$model] : (method_exists(ModelCatalog::class, 'childRuntimeRow')
                ? ModelCatalog::childRuntimeRow($family, $effort) : null);
        if (is_array($route)) {
            // Family-wide rows advertise their largest effort's context. The dedicated
            // Thinking/Instant row keeps native compaction aligned with this child's budget.
            $arguments['model'] = $route['id'];
            $arguments['reasoning_effort'] = $effort;
            return $arguments;
        }
        // Older/unprobed catalogs do not prove a dedicated family row. Preserve their
        // existing inheritance instead of inventing a model ID or rejecting a valid spawn.
        return $arguments;
    }

    /** Match the actual native collaboration capability, never a tool sharing its display name. */
    private static function isHostSpawn(array $tool): bool
    {
        return ($tool['wire_name'] ?? '') === 'collaboration__spawn_agent'
            && ($tool['namespace'] ?? '') === 'collaboration' && ($tool['name'] ?? '') === 'spawn_agent'
            && ($tool['type'] ?? '') === 'function' && empty($tool['freeform']);
    }

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
        self::$scopes[$key] = ['at'=>microtime(true), 'verified'=>true, 'scope'=>$scope, 'path'=>$path];
        return $scope;
    }

    /** Return only an already verified child's exact session path for bounded metadata evidence reads. */
    public static function sessionPath(string $threadId): ?string
    {
        if (self::scope($threadId) === null) return null;
        $root = realpath(rtrim((string)Config::get('gateway', 'codex_home'), '/\\') . '/sessions');
        $entry = $root === false ? null : (self::$scopes[$root . '|' . $threadId] ?? null);
        return is_string($entry['path'] ?? null) ? $entry['path'] : null;
    }

    /**
     * Read bounded native lifecycle metadata outside a database writer. Completion is
     * evidence for this exact native turn, never proof that an uncertain Web turn stopped.
     */
    public static function terminalTurn(string $threadId): ?array
    {
        $path = self::sessionPath($threadId);
        $stream = $path === null ? false : @fopen($path, 'rb');
        if (!is_resource($stream)) return null;
        try {
            $header = json_decode((string)fgets($stream, 262145), true);
            if (($header['payload']['id'] ?? null) !== $threadId) return null;
            $offset = max(0, fstat($stream)['size'] - 1048576);
            fseek($stream, $offset);
            if ($offset > 0) fgets($stream);
            $turn = null;
            while (($line = fgets($stream, 65537)) !== false) {
                if (!str_ends_with($line, "\n")) {
                    while (!feof($stream) && !str_ends_with($line, "\n")) $line = (string)fgets($stream, 65537);
                    continue;
                }
                if (!str_contains($line, '"event_msg"')
                    || (!str_contains($line, '"task_started"') && !str_contains($line, '"task_complete"'))) continue;
                $row = json_decode($line, true);
                $event = $row['payload'] ?? null;
                if (($row['type'] ?? '') !== 'event_msg' || !is_array($event)) continue;
                if (!is_string($row['timestamp'] ?? null) || $row['timestamp'] === '') continue;
                try {
                    $date = new \DateTimeImmutable((string)($row['timestamp'] ?? ''));
                    $at = (int)$date->format('U');
                    $atUs = (int)$date->format('Uu');
                } catch (\Throwable) { continue; }
                if (($event['type'] ?? '') === 'task_started') {
                    $turn = is_string($event['turn_id'] ?? null) && CodexCancellation::uuid($event['turn_id'])
                        ? ['native_turn_id'=>$event['turn_id'], 'started_at'=>$at, 'started_at_us'=>$atUs] : null;
                } elseif (($event['type'] ?? '') === 'task_complete' && $turn !== null
                    && (!isset($event['turn_id']) || $event['turn_id'] === $turn['native_turn_id'])) {
                    $turn['finished_at'] = $at;
                    $turn['finished_at_us'] = $atUs;
                }
            }
            return isset($turn['finished_at']) ? $turn : null;
        } finally { fclose($stream); }
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
        // A running worker can still hold the older companion class during a source upgrade.
        return method_exists(NativeAgentAssignment::class, 'prepareEvidence') ? NativeAgentAssignment::prepareEvidence($spec) : $spec;
    }
}
