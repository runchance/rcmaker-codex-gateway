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
        $small = in_array($model, ['chatgpt-web/luna', 'chatgpt-web/think'], true)
            || (is_array($capabilities) && empty($capabilities['sol_available']));
        // Quota exhaustion changes availability, not the account's observed context capacity.
        // Otherwise a previously advertised 3x window shrinks while a native tool is in flight.
        $pro = !$small && \support\browser\ChatGptTransport::proSupported($capabilities ?? []);
        if ($small) {
            $base = 24000;
            $baseCompact = 14000;
        } elseif ($pro) {
            $base = $effort === 'max' ? 112193 : 111193;
            // Match the observed Pro-account trigger used by the reference
            // adapter. The ordinary window still leaves room for compaction
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
        $extensionAllowed = $family !== '6' || $effort === 'max'
            || ($pro && in_array($effort ?? 'high', ['medium', 'high', 'xhigh'], true));
        $extended = !$small && !empty($capabilities['sol_available']) && $extensionRequested
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
                // Reference 6.1.6 measured retention for GPT-6 Sol on Pro. Six
                // composer stages do not make the model's working window 3x.
                $context = 240000;
                $compact = 220000;
                $policy = 'gpt6_pro_reasoning_measured';
                $reason = 'gpt6_measured_retention_ceiling';
            } elseif (!$extensionAllowed) {
                $policy = $effort === 'low' ? 'gpt6_instant_standard' : 'gpt6_non_pro_standard';
                $reason = $effort === 'low' ? 'instant_uses_standard_context' : 'account_has_no_observed_pro_capacity';
            }
        }
        $contractReserve = $small ? 4000 : 8192;
        $outputReserve = $small ? 4000 : min(8000, $base - $baseCompact - 2000);
        $message = $small ? ['tokens'=>16000, 'chars'=>128000] : self::messageLimits($effort ?? 'high', $capabilities ?? []);
        // Every inert stage uses Medium; partition within that envelope instead of upgrading to Pro.
        $stage = $small ? $message : self::messageLimits('medium', $capabilities ?? []);
        $stageFallback = $stage;
        // Version 7 resolves model capacity. Existing persisted snapshots keep
        // their original limits throughout host-tool continuations and recovery.
        return ['version' => 7, 'context_tokens' => $context, 'compact_tokens' => $compact,
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
            'counter' => 'o200k_base_with_long_atom_upper_bound', 'model' => $model, 'effort' => $effort,
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
        $sourceTokens = $sourceContext === null ? 0 : self::tokens($sourceContext);
        if (max(self::tokens($prompt), $sourceTokens) + $reserve <= (int) ($budget['base_context_tokens'] ?? 0)) return $budget;
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
        $context = $pro ? 240000 : 90000;
        $compact = $pro ? 220000 : 80000;
        $budget['base_context_tokens'] = $base;
        $budget['base_compact_tokens'] = $pro ? 95000 : 80000;
        $budget['context_tokens'] = $context;
        $budget['compact_tokens'] = $compact;
        $budget['context_multiplier'] = $pro ? 3 : 1;
        $budget['context_extended'] = $pro;
        $budget['context_effective_ratio'] = round($context / $base, 6);
        $budget['budget_policy'] = $pro ? 'gpt6_pro_reasoning_measured' : 'gpt6_non_pro_standard';
        $budget['budget_reason'] = 'summary_uses_non_pro_high_capacity';
        $budget['extension_allowed'] = $pro;
        $budget['compaction_capacity_effort'] = 'high';
        return $budget;
    }

    /** Return the reference-derived single-message envelope; 3x never changes these limits. */
    public static function messageLimits(string $effort, array $capabilities): array
    {
        if (\support\browser\ChatGptTransport::proSupported($capabilities)) {
            return ['tokens'=>$effort === 'max' ? 104000 : 103000,
                'chars'=>match ($effort) { 'low'=>545000, 'max'=>1635000, default=>500000 }];
        }
        // Plus Medium/High currently reject final /f/conversation requests near 290k chars
        // with HTTP 413. Keep the model context window at 90k (or 270k for 3x), but pin the
        // physical message envelope conservatively below that backend request boundary.
        return ['tokens'=>($effort === 'low' ? 41000 : 90000) - 8192 - 1,
            'chars'=>$effort === 'low' ? 211256 : 240000];
    }

    /** Share the pinned tokenizer and bounded long-atom fallback across every budget consumer. */
    public static function tokens(string $text): int
    {
        return WebTokenizer::count($text);
    }

    /** Include the platform reserve in reported usage so the native trigger precedes rejection. */
    public static function inputTokens(string $text, array $budget, int $attachmentTokens = 0): int
    {
        return self::tokens($text) + max(0, $attachmentTokens)
            + ((int) ($budget['version'] ?? 1) >= 2 ? (int) $budget['contract_reserve_tokens'] : 0);
    }

    /** Reject oversized full histories before a browser submission or SSE response begins. */
    public static function validate(string $context, array $budget, int $attachmentTokens = 0): int
    {
        $count = self::tokens($context) + max(0, $attachmentTokens);
        if ($count + $budget['output_reserve_tokens'] + $budget['contract_reserve_tokens'] > $budget['context_tokens']) {
            throw new GatewayError('context_length_exceeded', '当前上下文超过所选账号、模型和扩展设置的预算，请先压缩上下文再继续。', 400);
        }
        return $count;
    }
}
