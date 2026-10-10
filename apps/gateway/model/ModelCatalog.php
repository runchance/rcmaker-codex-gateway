<?php
declare(strict_types=1);
namespace app\gateway\model;

use app\gateway\database\Schema;
use support\browser\ChatGptTransport;

/** Account capabilities observed in the private browser and Codex-compatible model rows. */
final class ModelCatalog
{
    private const PLAN_LABELS = ['free'=>'Free', 'go'=>'Go', 'plus'=>'Plus', 'pro'=>'Pro',
        'business'=>'Business', 'team'=>'Team', 'enterprise'=>'Enterprise', 'unknown'=>'尚未识别'];

    /**
     * Discover account changes when idle and refresh Pro availability periodically on an isolated page.
     * Failed probes retry at most once a minute; periodic probes never use task/user menus.
     * @param string $plan Whitelisted plan read from the account badge, never inferred from models.
     * @return void
     */
    public static function refreshIfChanged(string $plan): void
    {
        Schema::initialize();
        $plan = isset(self::PLAN_LABELS[$plan]) ? $plan : 'unknown';
        $db = SDB(null, 'medoo', 'sqlite');
        $row = $db->table('gateway_model_catalog')->where('id', 1)->find();
        $caps = json_decode((string) ($row['capabilities_json'] ?? '{}'), true) ?: [];
        $state = BrowserState::state();
        $changed = $plan !== 'unknown' && $plan !== ($caps['account_plan'] ?? 'unknown');
        // Older probes could mistake a missing model selector for a Luna-only account while
        // the account badge was also unknown. Treat that observation as unverified and retry.
        $unprovenLuna = array_key_exists('sol_available', $caps) && empty($caps['sol_available'])
            && !in_array((string) ($caps['account_plan'] ?? 'unknown'), ['free', 'go'], true);
        $unprovenFreeThink = ($caps['account_plan'] ?? '') === 'free' && empty($caps['sol_available'])
            && !array_key_exists('think_available', $caps);
        $stale = (int) ($row['observed_at'] ?? 0) === 0 || (int) ($row['browser_generation'] ?? -1) !== (int) $state['surface_generation']
            || (!empty($caps['sol_available']) && self::families($caps) === []) || $unprovenLuna || $unprovenFreeThink;
        $availabilityExpired = !empty($caps['sol_available']) && (int)($row['observed_at'] ?? 0) < time() - 60;
        if (!$changed && !$stale && empty($caps['refresh_required']) && !$availabilityExpired) return;
        if ((int) ($caps['probe_attempted_at'] ?? 0) > time() - 60) return;
        $availabilityOnly = !$changed && !$stale && empty($caps['refresh_required']) && $availabilityExpired;
        $commands = $db->table('gateway_browser_commands')->where('status', 'IN', ['queued','running']);
        if ($availabilityOnly) $commands->where('action', '<>', 'chatgpt_text');
        if ($commands->find()) return;
        if (!$availabilityOnly && $db->table('gateway_response_jobs')->where('status', 'IN', ['queued','running'])->find()) return;
        BrowserState::enqueue('probe_models', $availabilityOnly ? ['background'=>true] : [], !$availabilityOnly);
        // Refreshing quota evidence must not remove usable routes or change a running turn's pinned budget.
        if (!$availabilityOnly) $caps['refresh_required'] = true;
        $caps['pending_account_plan'] = $plan;
        $caps['probe_attempted_at'] = time();
        $db->table('gateway_model_catalog')->where('id', 1)->update([
            'capabilities_json'=>json_encode($caps, JSON_THROW_ON_ERROR),
        ]);
    }

    /**
     * A browser reload can invalidate the observed model generation while an unrelated
     * connector check still owns the browser queue. Let that check finish, then refresh
     * the model controls before rejecting a request for a previously selected Web model.
     * This runs before ResponseJob opens its reservation transaction.
     */
    public static function awaitCurrentObservation(string $model): void
    {
        if ($model === ResponsesProtocol::MODEL_ID || !str_starts_with($model, 'chatgpt-web/')) return;
        // Connector verification after a browser reload can occupy the single browser
        // queue for over a minute. A stale observation is pending, not proof that the
        // selected model disappeared from the account; return a retryable status after
        // one bounded request wait rather than a false unsupported-model error.
        $deadline = microtime(true) + 25.0;
        do {
            foreach (self::routes() as $route) {
                if ($route['id'] === $model) return;
            }
            // A complete current family observation can decide support. Hydrating empty
            // radios are still pending; pre-family legacy observations retain compatibility.
            $caps = self::observedCapabilities();
            if ($caps !== null && (empty($caps['sol_available']) || self::families($caps) !== []
                || (self::identity($model, null)['family'] ?? null) === null)) return;
            self::refreshIfChanged('unknown');
            usleep(250000);
        } while (microtime(true) < $deadline);
        throw new GatewayError('model_catalog_refresh_pending',
            '当前 ChatGPT 账号的模型检测尚未完成，请稍后自动重试。', 503);
    }

    /** Return the browser account tier and discovery state, without account identity or credentials. */
    public static function account(): array
    {
        Schema::initialize();
        $row = SDB(null, 'medoo', 'sqlite')->table('gateway_model_catalog')->where('id', 1)->find();
        $caps = json_decode((string) ($row['capabilities_json'] ?? '{}'), true) ?: [];
        $current = (int) ($row['browser_generation'] ?? -1) === (int) BrowserState::state()['surface_generation'];
        $refreshing = !empty($caps['refresh_required']);
        $plan = $refreshing ? ($caps['pending_account_plan'] ?? 'unknown') : ($current ? ($caps['account_plan'] ?? 'unknown') : 'unknown');
        if (!isset(self::PLAN_LABELS[$plan])) $plan = 'unknown';
        return ['plan'=>$plan, 'label'=>self::PLAN_LABELS[$plan], 'source'=>$plan === 'unknown' ? null : 'chatgpt_account_badge',
            'refreshing'=>$refreshing, 'observed_at'=>$current ? (int) ($row['observed_at'] ?? 0) : null];
    }

    /**
     * Carry an existing account observation across a UI-only target/lease change in the same
     * owned browser. A new browser already has another generation, so its stale catalog stays invalid.
     * @param int $previous The surface generation before the same-browser UI change.
     * @param int $current The newly published surface generation.
     * @return void
     */
    public static function advanceSurfaceGeneration(int $previous, int $current): void
    {
        SDB(null, 'medoo', 'sqlite')->table('gateway_model_catalog')->where('id', 1)
            ->where('browser_generation', $previous)->where('observed_at', '>', 0)->update(['browser_generation'=>$current]);
    }

    /** Discard capabilities discovered under the Chrome account whose profiles were cleared. */
    public static function resetAfterProfileClear(): void
    {
        GatewayUsage::resetAccount();
        $updated = SDB(null, 'medoo', 'sqlite')->table('gateway_model_catalog')->where('id', 1)->update([
            'capabilities_json' => '{}', 'observed_at' => 0, 'browser_generation' => 0,
        ]);
        if ($updated === false) throw new GatewayError('STORAGE_UNAVAILABLE', '模型账号状态无法重置。', 503);
    }

    /** Save structural observations; the caller must have read the real private account controls. */
    public static function observe(array $capabilities): void
    {
        Schema::initialize();
        $state = BrowserState::state();
        $plan = (string) ($capabilities['account_plan'] ?? 'unknown');
        $capabilities['account_plan'] = isset(self::PLAN_LABELS[$plan]) ? $plan : 'unknown';
        SDB(null, 'medoo', 'sqlite')->table('gateway_model_catalog')->where('id', 1)->update([
            'capabilities_json' => json_encode($capabilities, JSON_THROW_ON_ERROR),
            'observed_at' => time(), 'browser_generation' => (int) $state['surface_generation'],
        ]);
    }

    /** Return one visible model per observed website family, with its actual available effort slider. */
    public static function routes(?array $capabilities = null): array
    {
        $capabilities ??= self::observedCapabilities();
        if (!is_array($capabilities) || !array_key_exists('sol_available', $capabilities) || !empty($capabilities['refresh_required'])) return [];
        if (empty($capabilities['sol_available'])) return self::splitFamilyRoutes($capabilities);
        $routes = [];
        foreach (ChatGptTransport::familyOptions($capabilities) as $family=>$option) {
            $familyCaps = ChatGptTransport::familyCapabilities($capabilities, $family);
            $available = ChatGptTransport::availableEfforts($familyCaps);
            if ($available === []) continue;
            $routes[] = ['id'=>'chatgpt-web/model/' . $family, 'label'=>$option['label'] . ' (Web)',
                'website_label'=>$option['label'], 'notice'=>$option['notice'],
                'effort'=>in_array('high', $available, true) ? 'high' : $available[0],
                'efforts'=>$available, 'family'=>$family, 'capabilities'=>$familyCaps];
        }
        return $routes;
    }

    /** Keep the former Instant/grouped/Pro wire rows solely for hidden task and receipt compatibility. */
    private static function splitFamilyRoutes(array $capabilities): array
    {
        if (!is_array($capabilities) || !array_key_exists('sol_available', $capabilities) || !empty($capabilities['refresh_required'])) return [];
        if (empty($capabilities['sol_available'])) {
            // Absence of a Sol selector alone is not proof of a Luna-only account. In
            // particular, a changed ChatGPT DOM must never publish the wrong model family.
            if (!in_array((string) ($capabilities['account_plan'] ?? 'unknown'), ['free', 'go'], true)) return [];
            $luna = self::legacyRoutes($capabilities);
            foreach ($luna as &$route) $route['legacy'] = false;
            unset($route);
            return $luna;
        }
        $routes = [];
        foreach (ChatGptTransport::familyOptions($capabilities) as $family=>$option) {
            $familyCaps = ChatGptTransport::familyCapabilities($capabilities, $family);
            $available = ChatGptTransport::availableEfforts($familyCaps);
            $caption = $option['label'];
            $routeMetadata = ['website_label'=>$caption, 'notice'=>$option['notice']];
            // Instant has a smaller context envelope, so it retains its separate native row.
            if (in_array('low', $available, true)) $routes[] = [
                'id'=>'chatgpt-web/' . $family . '-instant', 'label'=>$caption . ' Instant (Web)',
                'effort'=>'low', 'efforts'=>['low'], 'family'=>$family, 'capabilities'=>$familyCaps] + $routeMetadata;
            $levels = array_values(array_diff($available, ['low', ...($family === 'gpt-5.6-sol' ? ['max'] : [])]));
            if ($levels !== []) $routes[] = ['id'=>'chatgpt-web/' . $family, 'label'=>$caption . ' (Web)',
                'effort'=>in_array('high', $levels, true) ? 'high' : $levels[0],
                'efforts'=>$levels, 'family'=>$family, 'capabilities'=>$familyCaps] + $routeMetadata;
            if (in_array('max', $available, true)) $routes[] = [
                'id'=>'chatgpt-web/' . $family . '-pro', 'label'=>$caption . ' Pro (Web)',
                'effort'=>'max', 'efforts'=>['max'], 'family'=>$family, 'capabilities'=>$familyCaps] + $routeMetadata;
        }
        return $routes;
    }

    /** Read only a current browser observation; a stale prior login cannot authorize a route. */
    private static function observedCapabilities(): ?array
    {
        Schema::initialize();
        $row = SDB(null, 'medoo', 'sqlite')->table('gateway_model_catalog')->where('id', 1)->find();
        $state = BrowserState::state();
        if (!is_array($row) || (int) $row['observed_at'] <= 0
            || (int) $row['browser_generation'] !== (int) $state['surface_generation']) return null;
        $caps = json_decode((string) $row['capabilities_json'], true);
        return is_array($caps) && empty($caps['refresh_required']) ? $caps : null;
    }

    /** Keep pre-family saved task identities executable with their original effort binding. */
    private static function legacyRoutes(array $capabilities): array
    {
        $efforts = !empty($capabilities['sol_available']) ? ['low','medium','high'] : ['low','medium'];
        if (!empty($capabilities['sol_available']) && !empty($capabilities['extra_high_available'])) $efforts[] = 'xhigh';
        if (!empty($capabilities['sol_available'])
            && in_array('max', ChatGptTransport::availableEfforts($capabilities), true)) $efforts[] = 'max';
        $routes = [];
        foreach ($efforts as $effort) {
            $slug = !empty($capabilities['sol_available'])
                ? ['low'=>'instant','medium'=>'medium','high'=>'high','xhigh'=>'extra-high','max'=>'pro'][$effort]
                : ($effort === 'low' ? 'luna' : 'think');
            $routes[] = ['id'=>'chatgpt-web/' . $slug, 'label'=>'ChatGPT Web · ' . ChatGptTransport::requestedMode($effort, $capabilities, true)['label'],
                'effort'=>$effort, 'efforts'=>[$effort], 'family'=>null, 'legacy'=>true, 'capabilities'=>$capabilities];
        }
        if (!empty($capabilities['sol_available'])) {
            foreach (self::splitFamilyRoutes($capabilities) as $route) {
                $route['legacy'] = true; $routes[] = $route;
            }
        }
        // Saved Latest tasks follow the same first website family after its caption becomes versioned.
        // New selections use the actual versioned key; compatibility rows stay hidden in Codex.
        $options = ChatGptTransport::familyOptions($capabilities);
        if (!empty($capabilities['sol_available']) && !isset($options['latest']) && ChatGptTransport::familyOption($capabilities, 'latest') !== null) {
            $primary = array_key_first($options);
            foreach (self::splitFamilyRoutes($capabilities) as $route) {
                if ($route['family'] !== $primary) continue;
                $route['id'] = str_replace('chatgpt-web/' . $primary, 'chatgpt-web/latest', $route['id']);
                $route['family'] = 'latest'; $route['legacy'] = true; $routes[] = $route;
            }
            foreach (self::routes($capabilities) as $route) {
                if ($route['family'] !== $primary) continue;
                $route['id'] = 'chatgpt-web/model/latest';
                $route['family'] = 'latest'; $route['legacy'] = true; $routes[] = $route;
            }
        }
        return $routes;
    }

    /** Parse exact observed family radio labels, including collapsed controls; effort evidence is separate. */
    public static function families(array $capabilities): array
    {
        return array_map(static fn(array $option): string => $option['checked'], ChatGptTransport::familyOptions($capabilities));
    }

    /** Keep the selected family while adapting a stale Codex effort from the previous model. */
    private static function compatibleEffort(?string $effort, array $supported, string $default): string
    {
        $selected = $effort === 'ultra' ? 'max' : ($effort ?? $default);
        if (in_array($selected, $supported, true)) return $selected;
        $rank = ['low'=>0, 'medium'=>1, 'high'=>2, 'xhigh'=>3, 'max'=>4];
        if (!isset($rank[$selected]) || $supported === []) {
            throw new GatewayError('model_effort_conflict', '所选 Web 模型与推理档位不一致，请重新选择模型。', 400);
        }
        usort($supported, static fn(string $a, string $b): int =>
            abs($rank[$a] - $rank[$selected]) <=> abs($rank[$b] - $rank[$selected]));
        return $supported[0];
    }

    /** Resolve a fixed model without silently choosing a different account/model capability. */
    public static function resolve(string $model, ?string $effort): array
    {
        $effort = self::webProEffort($model, $effort);
        if ($model === ResponsesProtocol::MODEL_ID) return ['id' => $model, 'effort' => $effort, 'capabilities' => null];
        $routes = self::routes();
        $capabilities = self::observedCapabilities();
        if (is_array($capabilities) && !empty($capabilities['sol_available'])) {
            $routes = array_merge($routes, self::legacyRoutes($capabilities));
        }
        foreach ($routes as $route) {
            if ($route['id'] !== $model) continue;
            if (in_array($effort, ['max','ultra'], true) && !in_array('max', $route['efforts'], true)
                && self::identity($model, $effort)['effort'] === 'max') {
                throw new GatewayError('CHATGPT_MODEL_UNAVAILABLE', 'ChatGPT 网页当前未提供所选 Pro 档位，请选择当前可用档位。', 409);
            }
            $route['effort'] = self::compatibleEffort($effort, $route['efforts'], $route['effort']);
            return $route;
        }
        if ($capabilities === null) {
            throw new GatewayError('model_catalog_refresh_pending',
                '当前 ChatGPT 账号的模型检测尚未完成，请稍后自动重试。', 503);
        }
        $identity = self::identity($model, $effort);
        if (!empty($capabilities['sol_available']) && self::families($capabilities) === []
            && is_string($identity['family'] ?? null)) {
            throw new GatewayError('model_catalog_refresh_pending',
                '当前 ChatGPT 模型家族选项尚未稳定，请稍后自动重试。', 503);
        }
        if (($identity['effort'] ?? null) === 'max'
            && (($identity['family'] ?? null) === null
                ? !empty($capabilities['sol_available'])
                : ChatGptTransport::familyOption($capabilities, $identity['family']) !== null)) {
            throw new GatewayError('CHATGPT_MODEL_UNAVAILABLE',
                'ChatGPT 账号模型检测已完成，但网页当前未提供所选 Pro 档位；请查看网页额度或可用状态。', 409);
        }
        throw new GatewayError('model_not_supported', '当前 ChatGPT 账号检测未提供所选模型家族。', 400);
    }

    /**
     * Admit a checkpoint using its non-Pro physical route while preserving the logical task identity.
     * @return array Logical effort and current observed capabilities for the existing budget snapshot.
     * @throws GatewayError If the current account cannot provide the auxiliary mode.
     */
    public static function resolveForOperation(string $model, ?string $effort, string $operation): array
    {
        if ($model === ResponsesProtocol::MODEL_ID || !in_array($operation, ['compact_v1','compact_v2'], true)) {
            return self::resolve($model, $effort);
        }
        $caps = self::observedCapabilities();
        if ($caps === null) throw new GatewayError('model_catalog_refresh_pending',
            '当前 ChatGPT 账号的模型检测尚未完成，请稍后自动重试。', 503);
        $identity = self::identity($model, $effort);
        $mode = ChatGptTransport::executionMode(['model'=>$model, 'reasoning_effort'=>$effort, 'operation_kind'=>$operation], $caps);
        $physical = self::resolve(ChatGptTransport::physicalModel($mode), $mode['effort']);
        return ['id'=>$model, 'effort'=>$identity['effort'], 'capabilities'=>$physical['capabilities']];
    }

    /** Parse stable wire identity before replay lookup; fresh execution still requires resolve(). */
    public static function identity(string $model, ?string $effort): array
    {
        $effort = self::webProEffort($model, $effort);
        if ($model === ResponsesProtocol::MODEL_ID) return ['effort' => $effort, 'capabilities' => null];
        $efforts = ['luna'=>'low', 'think'=>'medium', 'instant'=>'low', 'medium'=>'medium', 'high'=>'high', 'extra-high'=>'xhigh', 'pro'=>'max'];
        $slug = str_starts_with($model, 'chatgpt-web/') ? substr($model, strlen('chatgpt-web/')) : '';
        if (str_starts_with($slug, 'model/')) {
            $family = substr($slug, strlen('model/'));
            if ($family !== 'latest' && preg_match('/^gpt-\d+(?:\.\d+)?(?:-[a-z]+)*$/', $family) !== 1) {
                throw new GatewayError('model_not_supported', '未知的 Web 模型标识。', 400);
            }
            return ['effort'=>self::compatibleEffort($effort, ['low','medium','high','xhigh','max'], 'high'),
                'family'=>$family, 'capabilities'=>null];
        }
        $expected = $efforts[$slug] ?? ['gpt-5.6-sol-instant'=>'low','gpt-5.6-sol'=>'high','gpt-5.6-sol-pro'=>'max',
            'latest-instant'=>'low','latest'=>'high','latest-pro'=>'max'][$slug] ?? null;
        $namedFamily = preg_replace('/-(?:instant|pro)$/', '', $slug);
        if ($expected === null && is_string($namedFamily)
            && preg_match('/^gpt-\d+(?:\.\d+)?(?:-[a-z]+)*$/', $namedFamily) === 1) {
            $expected = str_ends_with($slug, '-pro') ? 'max' : (str_ends_with($slug, '-instant') ? 'low' : 'high');
        }
        if ($expected === null) throw new GatewayError('model_not_supported', '未知的 Web 模型标识。', 400);
        $supported = match ($slug) {
            'gpt-5.6-sol'=>['medium','high','xhigh'],
            'latest'=>['medium','high','xhigh','max'],
            default=>$slug === $namedFamily && str_starts_with($slug, 'gpt-') ? ['medium','high','xhigh','max'] : [$expected],
        };
        $selected = self::compatibleEffort($effort, $supported, $expected);
        $family = str_starts_with($slug, 'gpt-5.6-sol') ? 'gpt-5.6-sol'
            : (str_starts_with($slug, 'latest') ? 'latest' : (str_starts_with($slug, 'gpt-') ? $namedFamily : null));
        return ['effort' => $selected, 'family' => $family, 'capabilities' => null];
    }

    /**
     * Accept the earlier unified catalog's literal Pro input as its existing logical max.
     * The visible Ultra choice uses this internal alias before reaching the Web gateway.
     * Legacy hidden rows keep their historical identity and effort normalization unchanged.
     */
    private static function webProEffort(string $model, ?string $effort): ?string
    {
        return str_starts_with($model, 'chatgpt-web/model/') && is_string($effort)
            && strcasecmp($effort, 'Pro') === 0 ? 'max' : $effort;
    }

    /**
     * Project runtime families into fixed Instant/Pro and window-compatible Thinking choices.
     * A verified Free account advertises only its actual Think control, keeping ordinary
     * Luna available to existing task identities without injecting a second native model.
     * Separate existing wire identities protect saved unified Low/Max choices from a different window.
     * Instant remains separate even when its window matches Thinking because the grouped legacy wire
     * identity does not accept Low. The console and native catalog consume this same pure projection.
     * @param array|null $capabilities Optional observed family controls for isolated verification.
     * @return array Visible catalog routes; no runtime route, task or account observation is changed.
     */
    public static function catalogRoutes(?array $capabilities = null): array
    {
        $capabilities ??= self::observedCapabilities();
        if (is_array($capabilities) && ($capabilities['account_plan'] ?? '') === 'free'
            && array_key_exists('sol_available', $capabilities) && empty($capabilities['sol_available'])) {
            if (empty($capabilities['think_available'])) return [];
            foreach (self::routes($capabilities) as $route) {
                if ($route['id'] !== 'chatgpt-web/think') continue;
                $route['label'] = 'GPT-Free.Thinging.RC(Web)';
                $route['website_label'] = 'Think';
                return [$route];
            }
            return [];
        }
        $routes = [];
        foreach (self::routes($capabilities) as $route) {
            if (!str_starts_with($route['id'], 'chatgpt-web/model/')) {
                $routes[] = $route;
                continue;
            }
            foreach (['-instant'=>['low'], ''=>['medium','high','xhigh'], '-pro'=>['max']] as $suffix=>$profile) {
                $levels = array_values(array_intersect($profile, $route['efforts']));
                if ($levels === []) continue;
                $choice = $route;
                $choice['efforts'] = $levels;
                $choice['effort'] = in_array('high', $levels, true) ? 'high' : $levels[0];
                $choice['id'] = 'chatgpt-web/' . $route['family'] . $suffix;
                $choice['label'] = $route['website_label'] . ' · '
                    . ($suffix === '-instant' ? 'Instant' : ($suffix === '-pro' ? 'Pro' : 'Thinking')) . ' · RC (Web)';
                $routes[] = $choice;
            }
        }
        return $routes;
    }

    /**
     * Publish window-compatible Web choices while preserving native rows and unknown fields.
     * @param array $catalog Genuine authenticated native catalog.
     * @return array Catalog with visible window groups and hidden historical wire identities.
     */
    public static function augment(array $catalog): array
    {
        if (!is_array($catalog['models'] ?? null)) throw new GatewayError('native_catalog_invalid', 'Codex 原生模型目录无效。', 502);
        $native = array_values(array_filter($catalog['models'], static fn($row): bool => is_array($row)
            && !str_starts_with((string) ($row['slug'] ?? ''), 'chatgpt-web/')));
        $template = null;
        foreach ($native as $row) {
            if (($row['visibility'] ?? '') === 'list' && is_array($row['supported_reasoning_levels'] ?? null)) {
                $template = $row; break;
            }
        }
        if ($template === null) throw new GatewayError('native_catalog_invalid', 'Codex 原生目录缺少可用模板。', 502);
        $web = [];
        $settings = GatewaySettings::internal();
        $caps = self::observedCapabilities();
        $routes = self::catalogRoutes($caps);
        $freeAccount = is_array($caps) && ($caps['account_plan'] ?? '') === 'free'
            && array_key_exists('sol_available', $caps) && empty($caps['sol_available']);
        if (is_array($caps) && (!empty($caps['sol_available']) || $freeAccount)) {
            $knownIds = array_fill_keys(array_column($routes, 'id'), true);
            $compatibility = self::routes($caps);
            foreach ($compatibility as &$route) $route['legacy'] = true;
            unset($route);
            $hiddenRoutes = !empty($caps['sol_available'])
                ? array_merge($compatibility, self::legacyRoutes($caps)) : $compatibility;
            foreach ($hiddenRoutes as $route) {
                if (isset($knownIds[$route['id']])) continue;
                $routes[] = $route;
                $knownIds[$route['id']] = true;
            }
        }
        foreach ($routes as $route) {
            $budget = self::catalogBudget($route, $settings);
            $unifiedFamily = str_starts_with($route['id'], 'chatgpt-web/model/');
            $proChoice = 'ultra';
            $codexEffort = $route['effort'] === 'max' ? $proChoice : $route['effort'];
            $supportedLevels = array_map(static fn(string $level): array => [
                'effort'=>$level === 'max' ? $proChoice : $level,
                'description'=>$route['label'] . ' · ' . ($level === 'max' && $unifiedFamily ? 'Pro' : $level),
            ], $route['efforts']);
            $fixedPro = empty($route['legacy']) && str_ends_with($route['id'], '-pro');
            if ($fixedPro) {
                // A fixed Pro identity resolves standard High to its sole logical Max route.
                // Use a native standard effort so desktop Ultra normalization cannot select XHigh.
                $codexEffort = 'high';
                $supportedLevels = [['effort'=>'high', 'description'=>$route['label']]];
            }
            if ($unifiedFamily) {
                // Native Codex resolves Ultra only through an alias present in this list.
                // Retain the hidden unified row and its alias after quota exhaustion so a saved
                // Ultra request reaches gateway admission as Pro instead of becoming xhigh.
                $supportedLevels[] = ['effort'=>'Pro',
                    'description'=>'Internal wire alias for Ultra; website Pro availability is checked by RCMAKER.'];
            }
            $row = array_replace($template, [
                'slug' => $route['id'], 'display_name' => $route['label'],
                'description' => '通过本机 RCMAKER 使用已登录的 ChatGPT 网页账号'
                    . (!empty($route['notice']) ? ' · ' . $route['notice'] : ''),
                'visibility' => !empty($route['legacy']) ? 'hide' : 'list', 'supported_in_api' => true,
                'input_modalities' => ['text', 'image'],
                'tool_mode' => null, 'upgrade' => null, 'default_reasoning_level' => $codexEffort,
                'supported_reasoning_levels' => $supportedLevels,
                'context_window' => $budget['context_tokens'], 'max_context_window' => $budget['context_tokens'],
                'effective_context_window_percent' => (int) round($budget['compact_tokens'] / $budget['context_tokens'] * 100),
                'auto_compact_token_limit' => $budget['compact_tokens'],
                'additional_speed_tiers' => [], 'service_tiers' => [], 'default_service_tier' => null,
                // The Web bridge uses the reference connector's Compatibility V1 subagent
                // surface. Inheriting a native V2 template makes Codex inject different
                // developer instructions into the same task before it reaches this gateway.
                'multi_agent_version' => 'v1',
                // Preserve the native template's Responses Lite transport choice. Codex then
                // sends base instructions as a developer input item and tools as additional_tools.
                'supports_parallel_tool_calls' => true,
            ]);
            if ($unifiedFamily) {
                // This supported hidden alias prevents native Ultra normalization to xhigh.
                // The hidden unified row keeps its old native Ultra normalization.
                $row['multi_agent_reasoning_effort'] = 'Pro';
            } else {
                // The native template's Ultra mapping must not escape into a fixed Web identity.
                $row['multi_agent_reasoning_effort'] = null;
            }
            // A native model template's special program entitlements do not belong to a Web model.
            unset($row['comp_hash'], $row['availability_nux'], $row['model_specialty'], $row['available_access_programs']);
            $web[] = $row;
        }
        $catalog['models'] = array_merge($native, $web);
        return $catalog;
    }

    /**
     * Fixed catalog profiles use their actual effort budget; old unified rows retain the minimum window.
     * Thinking profile efforts share their real budget. The hidden unified identity still admits saved
     * Low/Max inputs, so its metadata must remain conservative instead of adopting Thinking's window.
     * @param array $route Observed route with capabilities and available efforts.
     * @param array|null $settings Optional settings used by the console preview and isolated acceptance.
     * @return array Catalog metadata, never a running turn's budget replacement.
     */
    public static function catalogBudget(array $route, ?array $settings = null): array
    {
        $budget = ContextBudget::snapshot($route['id'], $route['effort'], $route['capabilities'], $settings);
        if (!str_starts_with($route['id'], 'chatgpt-web/model/')) return $budget;
        foreach ($route['efforts'] as $effort) {
            $candidate = ContextBudget::snapshot($route['id'], $effort, $route['capabilities'], $settings);
            foreach (['context_tokens','compact_tokens','base_context_tokens','base_compact_tokens'] as $key) {
                $budget[$key] = min($budget[$key], $candidate[$key]);
            }
        }
        return $budget;
    }

    /**
     * Combine a task's admitted family evidence with a partial picker read without overwriting the account catalog.
     * @param array $job Existing logical task identity; no caller may infer a different named family.
     * @param array $picker Current page controls, which may initially show another family.
     * @return array Capabilities used for planning; actual selection always requires fresh picker readback.
     */
    public static function taskCapabilities(array $job, array $picker): array
    {
        if (empty($picker['sol_available'])) return $picker;
        $identity = self::identity((string)$job['model'], $job['reasoning_effort'] ?? null);
        $family = $identity['family'] ?? null;
        if (!is_string($family) || ChatGptTransport::familyChecked($picker, $family)) return $picker;
        $catalog = self::observedCapabilities();
        if (is_array($catalog) && ChatGptTransport::familyOption($catalog, $family) !== null) {
            $scoped = ChatGptTransport::familyCapabilities($catalog, $family);
            $scoped['family_options'] = $picker['family_options'] ?? [];
            return $scoped;
        }
        return ChatGptTransport::familyCapabilities($picker, $family);
    }
}
