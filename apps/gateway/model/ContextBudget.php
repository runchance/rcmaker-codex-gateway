<?php
declare(strict_types=1);
namespace app\gateway\model;

/** Shared account/effort budget for the catalog, browser transport and native compaction. */
final class ContextBudget
{
    /**
     * Pin one request's budget and transport settings; an existing tool receipt keeps its snapshot.
     * @param array|null $capabilities Observed account controls, never inferred from the tier name.
     * @param array|null $settings Optional complete settings for isolated budget verification.
     * @return array Immutable model capacity and transport limits; multipart never enlarges a message.
     */
    public static function snapshot(string $model, ?string $effort, ?array $capabilities = null, ?array $settings = null): array
    {
        $luna = in_array($model, ['chatgpt-web/luna', 'chatgpt-web/think'], true);
        $small = $luna
            || (is_array($capabilities) && empty($capabilities['sol_available']));
        // Quota exhaustion changes availability, not the account's observed context capacity.
        // Otherwise a previously advertised 3x window shrinks while a native tool is in flight.
        $pro = !$small && \support\browser\ChatGptTransport::proSupported($capabilities ?? []);
        if ($small) {
            $base = $luna ? 28000 : 24000;
            $baseCompact = $luna ? 19808 : 14000;
        } elseif ($pro) {
            $base = $effort === 'max' ? 112193 : 111193;
            // Use the observed Pro-account trigger. The ordinary window
            // still leaves room for compaction
            // before the browser's physical message limit is reached.
            $baseCompact = 95000;
        } else {
            $base = $effort === 'low' ? 41000 : 90000;
            $baseCompact = $effort === 'low' ? 32000 : 80000;
        }
        $settings ??= GatewaySettings::internal();
        // New requests always choose their own part count; the user's only transport choice is its cap.
        $settings['context_transport'] = 'auto';
        $family = self::modelFamily($model, $capabilities ?? []);
        $extensionRequested = !empty($settings['context_extended']);
        $extensionAllowed = $luna ? in_array($effort ?? 'low', ['low', 'medium'], true)
            : ($family !== '6' || $effort !== 'low');
        $extended = ($luna || (!$small && !empty($capabilities['sol_available']))) && $extensionRequested
            && $extensionAllowed
            && $settings['context_transport'] === 'auto' && (int) $settings['context_max_parts'] >= 6;
        $multiplier = $extended ? 3 : 1;
        $context = $base * $multiplier;
        // Bigger Context is one larger transport transaction. Scale Codex's
        // auto-compaction trigger too, while retaining the same per-message cap.
        $compact = $baseCompact * $multiplier;
        $policy = $extended ? 'legacy_threefold' : 'standard_context';
        $reason = $extended ? 'enabled_threefold_transport' : 'standard_window';
        if ($family === '6' && $effort !== 'max') {
            if ($extended) {
                // Measured retention differs between Plus and Pro accounts.
                // More composer stages do not enlarge the model's recall window.
                $context = $pro ? 240000 : 120000;
                $compact = $pro ? 220000 : 110000;
                $policy = $pro ? 'gpt6_pro_reasoning_measured' : 'gpt6_plus_reasoning_measured';
                $reason = 'gpt6_measured_retention_ceiling';
            } elseif (!$extensionAllowed) {
                $policy = $effort === 'low' ? 'gpt6_instant_standard' : 'gpt6_non_pro_standard';
                $reason = $effort === 'low' ? 'instant_uses_standard_context' : 'account_has_no_observed_pro_capacity';
            }
        }
        if ($luna) {
            // Canonical Luna history is maintained by exact-parent rolling checkpoints.
            // Its ordinary 1.05M window is not a one-message browser allowance.
            $context = $extended ? 84000 : 1050000;
            $compact = $extended ? 59424 : 1050000;
            $policy = $extended ? 'luna_staged_transport' : 'luna_rolling_checkpoint';
            $reason = $extended ? 'luna_experimental_staged_input' : 'luna_canonical_history_with_bounded_input';
        }
        $contractReserve = $small && !$luna ? 4000 : 8192;
        // Compaction headroom already reserves future work;
        // input admission counts the actual prompt/platform/images, not a second
        // hypothetical output inside the same measured browser input allowance.
        $outputReserve = $small && !$luna ? 4000 : 0;
        $message = $luna ? ['tokens'=>19808, 'chars'=>500000]
            : ($small ? ['tokens'=>16000, 'chars'=>128000] : self::messageLimits($effort ?? 'high', $capabilities ?? []));
        // Sol stages use Medium; Luna stages use its ordinary Low envelope. Never stage on Pro.
        $stage = $small ? $message : self::messageLimits('medium', $capabilities ?? []);
        $stageFallback = $stage;
        // Live GPT-6 Web + Connector probes accepted 114k ASCII characters and
        // rejected 116k, while the same account accepted larger messages without
        // a Connector. This is a conservative transport target, not a model context
        // limit or a universal Web maximum. Prefer smaller physical messages without
        // clipping/splitting an indivisible record or shrinking the logical window.
        // Receipts and already pinned transactions keep their original snapshots.
        return ['version' => 9, 'context_tokens' => $context, 'compact_tokens' => $compact,
            'soft_message_chars'=>$small ? 0 : 110000,
            'browser_context_tokens'=>$luna ? ($extended ? 84000 : 28000) : $context,
            'base_context_tokens'=>$base, 'base_compact_tokens'=>$baseCompact, 'context_multiplier'=>$multiplier,
            'context_effective_ratio'=>round($context / $base, 6),
            'budget_policy'=>$policy, 'model_family'=>$family, 'budget_reason'=>$reason,
            'extension_requested'=>$extensionRequested, 'extension_allowed'=>$extensionAllowed,
            'observed_pro_capacity'=>$pro,
            'context_extended'=>$extended, 'context_transport'=>$settings['context_transport'],
            'context_max_parts'=>(int) $settings['context_max_parts'],
            'intent_tail_partition'=>true,
            'account_plan'=>$capabilities['account_plan'] ?? 'unknown',
            'sol_available'=>!$small && !empty($capabilities['sol_available']),
            'output_reserve_tokens' => $outputReserve, 'contract_reserve_tokens' => $contractReserve,
            'message_tokens'=>$message['tokens'], 'message_chars'=>$message['chars'],
            'stage_message_tokens'=>$stage['tokens'], 'stage_message_chars'=>$stage['chars'],
            'stage_fallback_message_tokens'=>$stageFallback['tokens'], 'stage_fallback_message_chars'=>$stageFallback['chars'],
            'counter' => 'o200k_base_with_bounded_bpe_chunks', 'model' => $model, 'effort' => $effort,
            'response_timeout_seconds' => (int) $settings['response_timeout_seconds'],
            'context_stage_timeout_seconds' => (int) $settings['context_stage_timeout_seconds'],
            'compaction_timeout_seconds' => (int) $settings['compaction_timeout_seconds']];
    }

    /**
     * A native compaction is a read-only transport transaction, not a new normal
     * response window. Carry its complete source through six bounded Web messages
     * without changing the catalog's ordinary context or auto-compact trigger.
     */
    public static function forCompactionTransport(array $budget): array
    {
        if ((int) ($budget['version'] ?? 0) >= 8
            && in_array((string) ($budget['model'] ?? ''), ['chatgpt-web/luna', 'chatgpt-web/think'], true)) {
            $budget['context_tokens'] = $budget['browser_context_tokens'] = 84000;
            $budget['compact_tokens'] = 59424;
            $budget['context_multiplier'] = 3;
            $budget['context_effective_ratio'] = 3.0;
            $budget['context_extended'] = true;
            $budget['budget_policy'] = 'luna_staged_transport';
            $budget['budget_reason'] = 'luna_experimental_staged_input';
            $budget['context_transport'] = 'auto';
            $budget['context_max_parts'] = $budget['context_min_parts'] = 6;
            $budget['compaction_transport'] = true;
            return $budget;
        }
        if ((int) ($budget['version'] ?? 0) < 5
            || empty($budget['sol_available'])
            || in_array((string) ($budget['model'] ?? ''), ['chatgpt-web/luna', 'chatgpt-web/think'], true)) {
            return $budget;
        }
        $base = (int) ($budget['base_context_tokens'] ?? 0);
        if ($base <= 24000) return $budget;
        if ((int) ($budget['version'] ?? 0) >= 7 && ($budget['model_family'] ?? null) === '6') {
            $budget = self::compactionCapacity($budget);
        } else {
            $budget['context_tokens'] = $base * 3;
            if (array_key_exists('browser_context_tokens', $budget)) $budget['browser_context_tokens'] = $budget['context_tokens'];
            $budget['context_multiplier'] = 3;
            $budget['context_extended'] = true;
            if ((int) ($budget['version'] ?? 0) >= 7) {
                $budget['context_effective_ratio'] = 3.0;
                $budget['budget_policy'] = 'legacy_threefold';
                $budget['budget_reason'] = 'enabled_threefold_transport';
            }
        }
        $budget['context_transport'] = 'auto';
        $budget['context_max_parts'] = 6;
        $budget['context_min_parts'] = 6;
        // Staging owns a separate preparation deadline in TaskLedger. Preserve
        // the pinned timeout for the final summary's actual generation.
        $budget['compaction_transport'] = true;
        return $budget;
    }

    /** Upgrade only an oversized checkpoint prompt; normal response limits never change. */
    public static function forCompactionPrompt(array $budget, string $prompt, int $attachmentTokens = 0,
        ?string $sourceContext = null): array
    {
        // Checkpoints execute on this family's non-Pro High mode, even when
        // the user's task used Instant or Pro. Do not carry Pro's larger model
        // window into the summary. This never edits the supplied source bytes.
        if ((int) ($budget['version'] ?? 0) >= 7 && ($budget['model_family'] ?? null) === '6') {
            $budget = self::compactionCapacity($budget);
        }
        if (!empty($budget['compaction_transport'])) return $budget;
        $reserve = (int) ($budget['contract_reserve_tokens'] ?? 0)
            + (int) ($budget['output_reserve_tokens'] ?? 0) + max(0, $attachmentTokens);
        $sourceTokens = $sourceContext === null ? 0 : self::tokens($sourceContext, $budget);
        if (max(self::tokens($prompt, $budget), $sourceTokens) + $reserve <= (int) ($budget['base_context_tokens'] ?? 0)) return $budget;
        return self::forCompactionTransport($budget);
    }

    /**
     * Resolve capacity only from the exact request identity or owned family captions.
     * Saved Latest aliases follow the observed first family; an unversioned or
     * ambiguous picker never becomes GPT-6 merely because it is the newest release.
     * @return string|null Observed numeric family, or null when its version is unproven.
     */
    private static function modelFamily(string $model, array $capabilities): ?string
    {
        $slug = str_starts_with($model, 'chatgpt-web/') ? substr($model, strlen('chatgpt-web/')) : '';
        if (str_starts_with($slug, 'model/')) $slug = substr($slug, strlen('model/'));
        if (preg_match('/^gpt-(\d+(?:\.\d+)?)(?:-[a-z]+)*$/', $slug, $matched) === 1) return $matched[1];
        $option = null;
        if (in_array($slug, ['latest', 'latest-instant', 'latest-pro'], true)) {
            $option = \support\browser\ChatGptTransport::familyOption($capabilities, 'latest');
        } elseif (in_array($slug, ['instant', 'medium', 'high', 'extra-high', 'pro', 'current'], true)) {
            $checked = array_filter(\support\browser\ChatGptTransport::familyOptions($capabilities),
                static fn(array $candidate): bool => $candidate['checked'] === 'true');
            if (count($checked) === 1) $option = reset($checked);
        }
        $key = is_array($option) ? \support\browser\ChatGptTransport::familyKey($option['label']) : null;
        return is_string($key) && preg_match('/^gpt-(\d+(?:\.\d+)?)(?:-|$)/', $key, $matched) === 1
            ? $matched[1] : null;
    }

    /**
     * Pin GPT-6 summary work to the already established non-Pro High route.
     * Multipart carries a bounded working view when necessary; ContextWindow's
     * original-context reader retains oversized source records verbatim.
     * @param array $budget Version 7 snapshot with proven GPT-6 family evidence.
     * @return array Same deadlines/message bounds and logical task identity, with High capacity.
     */
    private static function compactionCapacity(array $budget): array
    {
        $pro = !empty($budget['observed_pro_capacity']);
        $base = $pro ? 111193 : 90000;
        $plusExtended = (int) ($budget['version'] ?? 0) >= 8;
        $extended = $pro || $plusExtended;
        $context = $pro ? 240000 : ($plusExtended ? 120000 : 90000);
        $compact = $pro ? 220000 : ($plusExtended ? 110000 : 80000);
        $budget['base_context_tokens'] = $base;
        $budget['base_compact_tokens'] = $pro ? 95000 : 80000;
        $budget['context_tokens'] = $context;
        $budget['compact_tokens'] = $compact;
        if (array_key_exists('browser_context_tokens', $budget)) $budget['browser_context_tokens'] = $context;
        $budget['context_multiplier'] = $extended ? 3 : 1;
        $budget['context_extended'] = $extended;
        $budget['context_effective_ratio'] = round($context / $base, 6);
        $budget['budget_policy'] = $pro ? 'gpt6_pro_reasoning_measured'
            : ($plusExtended ? 'gpt6_plus_reasoning_measured' : 'gpt6_non_pro_standard');
        $budget['budget_reason'] = 'summary_uses_non_pro_high_capacity';
        $budget['extension_allowed'] = $extended;
        $budget['compaction_capacity_effort'] = 'high';
        return $budget;
    }

    /** Return the measured single-message envelope; 3x never changes these limits. */
    public static function messageLimits(string $effort, array $capabilities): array
    {
        if (\support\browser\ChatGptTransport::proSupported($capabilities)) {
            return ['tokens'=>$effort === 'max' ? 104000 : 103000,
                'chars'=>match ($effort) { 'low'=>545000, 'max'=>1635000, default=>500000 }];
        }
        // Plus GPT-6 uses a conservative 500k-character input envelope independent
        // of its token ceiling, shared with Pro reasoning. Pinned older jobs
        // retain their smaller envelopes.
        return ['tokens'=>($effort === 'low' ? 41000 : 90000) - 8192 - 1,
            'chars'=>$effort === 'low' ? 211256 : 500000];
    }

    /** Share the pinned counter; absent/old snapshots retain legacy long-atom upper bounds. */
    public static function tokens(string $text, ?array $budget = null): int
    {
        return WebTokenizer::count($text, ($budget['counter'] ?? null) === 'o200k_base_with_bounded_bpe_chunks');
    }

    /** Include the platform reserve in reported usage so the native trigger precedes rejection. */
    public static function inputTokens(string $text, array $budget, int $attachmentTokens = 0): int
    {
        return self::inputTokensFromCount(self::tokens($text, $budget), $budget, $attachmentTokens);
    }

    /** Reuse an exact count made for the same immutable context and pinned budget. */
    public static function inputTokensFromCount(int $contextTokens, array $budget, int $attachmentTokens = 0): int
    {
        return max(0, $contextTokens) + max(0, $attachmentTokens)
            + ((int) ($budget['version'] ?? 1) >= 2 ? (int) $budget['contract_reserve_tokens'] : 0);
    }

    /** Reject oversized full histories before a browser submission or SSE response begins. */
    public static function validate(string $context, array $budget, int $attachmentTokens = 0): int
    {
        return self::validateTokens(self::tokens($context, $budget), $budget, $attachmentTokens);
    }

    /** Apply the unchanged capacity rule to an exact count already used for input reporting. */
    public static function validateTokens(int $contextTokens, array $budget, int $attachmentTokens = 0): int
    {
        $count = max(0, $contextTokens) + max(0, $attachmentTokens);
        $capacity = min((int)$budget['context_tokens'], (int)($budget['browser_context_tokens'] ?? $budget['context_tokens']));
        if ($count + $budget['output_reserve_tokens'] + $budget['contract_reserve_tokens'] > $capacity) {
            throw new GatewayError('context_length_exceeded', '当前上下文超过所选账号、模型和扩展设置的预算，请先压缩上下文再继续。', 400);
        }
        return $count;
    }
}
