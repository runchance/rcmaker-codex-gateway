<?php
declare(strict_types=1);
namespace support\browser;

/**
 * Reconciles semantic answer blocks into an append-only, per-physical-turn Markdown ledger.
 *
 * This is a pure transport algorithm: its caller owns persistence and Responses delivery.
 * Missing rendered prefixes are harmless; an observed edit to a committed block is not.
 */
final class ChatGptMarkdownBuffer
{
    private const VERSION = 1;
    private const STABILITY_SECONDS = 0.75;

    /**
     * Align one scoped assistant observation with previously committed semantic blocks.
     *
     * Running observations commit only complete blocks that remain stable for 750 ms.
     * The final observation flushes the draft tail. Keep state across native tool batches
     * within one physical assistant response; reset it for a new assistant or document.
     * Metadata absent from an older observation returns the unchanged legacy Markdown,
     * allowing the caller's existing strict character-prefix verification to remain active.
     *
     * @param array $state State returned by the preceding call, or an empty fresh state.
     * @param array $blocks Scoped DOM blocks supplied by ChatGptPage::responseExpression().
     * @param string $currentMarkdown Actual DOM Markdown, retained for the legacy fallback.
     * @param bool $running Whether the owned webpage generation is still running.
     * @param float $now Monotonic observation time in seconds, normally microtime(true).
     * @return array{state:array,markdown:string,consistent:bool,error:?array,semantic:bool}
     */
    public static function align(array $state, array $blocks, string $currentMarkdown,
        bool $running, float $now): array
    {
        if ($blocks === []) {
            // An empty shell is a partial observation, not deletion of the response ledger.
            $hasState = ($state['version'] ?? null) === self::VERSION;
            return ['state' => $state, 'markdown' => $hasState ? (string) $state['markdown'] : $currentMarkdown,
                'consistent' => true, 'error' => null, 'semantic' => $hasState];
        }
        $state = ($state['version'] ?? null) === self::VERSION ? $state : self::initialState();
        $blocks = self::normalizeBlocks($blocks);
        if ($blocks === null) return self::failure($state, ['reason' => 'invalid_block_metadata']);
        $reconciled = self::reconcile($state, $blocks);
        if (isset($reconciled['error'])) return self::failure($state, $reconciled['error']);
        $pending = $reconciled['pending'];
        $candidates = [];
        foreach ($pending as $block) {
            $id = self::candidateId($block);
            $previous = $state['candidates'][$id] ?? null;
            $unchanged = is_array($previous) && self::sameObservation($block, $previous['block']);
            $candidates[$id] = ['block' => $block,
                'changed_at' => $unchanged ? $previous['changed_at'] : $now,
                'streamable_at' => $block['streamable']
                    ? ($unchanged && $previous['streamable_at'] !== null ? $previous['streamable_at'] : $now)
                    : null];
        }
        $committedCount = 0;
        foreach ($pending as $block) {
            $candidate = $candidates[self::candidateId($block)];
            if ($running && (!$block['streamable'] || $candidate['streamable_at'] === null
                || $now - max($candidate['changed_at'], $candidate['streamable_at']) < self::STABILITY_SECONDS)) break;
            self::commit($state, $block);
            unset($candidates[self::candidateId($block)]);
            $committedCount++;
        }
        $state['candidates'] = $candidates;
        $state['latest'] = array_slice($pending, $committedCount);
        return ['state' => $state, 'markdown' => $state['markdown'],
            'consistent' => true, 'error' => null, 'semantic' => true];
    }

    /**
     * Recover a durable prefix only when the current semantic observation proves its exact blocks.
     *
     * A prefix ending inside a block, missing old blocks, or mismatched Markdown returns null;
     * the caller must retain its existing strict-prefix recovery instead of guessing identities.
     *
     * @param string $prefix Exact full physical-turn prefix already delivered or used as a boundary.
     * @param array $blocks Current scoped semantic blocks.
     * @return array|null Recovered per-turn state, or null when evidence is incomplete.
     */
    public static function restore(string $prefix, array $blocks): ?array
    {
        $blocks = self::normalizeBlocks($blocks);
        if ($blocks === null) return null;
        $state = self::initialState();
        if ($prefix === '') return $state;
        foreach ($blocks as $block) {
            self::commit($state, $block);
            if ($state['markdown'] === $prefix) return $state;
            if (!str_starts_with($prefix, $state['markdown'])) return null;
        }
        return null;
    }

    /** Create isolated ledger state without retaining model or user data in static fields. */
    private static function initialState(): array
    {
        return ['version' => self::VERSION, 'committed' => [], 'candidates' => [],
            'latest' => [], 'markdown' => '', 'last_group' => null];
    }

    /** Validate trusted adapter metadata without interpreting user text as a DOM identity. */
    private static function normalizeBlocks(array $blocks): ?array
    {
        $normalized = [];
        foreach ($blocks as $block) {
            if (!is_array($block) || !is_string($block['key'] ?? null) || $block['key'] === ''
                || !is_string($block['tag'] ?? null) || !is_string($block['markdown'] ?? null)
                || !is_string($block['text'] ?? null) || !is_array($block['link_targets'] ?? [])
                || (isset($block['group']) && !is_string($block['group']))) return null;
            $links = $block['link_targets'] ?? [];
            foreach ($links as $link) if (!is_string($link)) return null;
            $item = ['key' => $block['key'], 'tag' => $block['tag'], 'markdown' => $block['markdown'],
                'text' => $block['text'], 'link_targets' => array_values($links),
                'streamable' => !empty($block['streamable']), 'group' => $block['group'] ?? null];
            if (isset($block['source_start'], $block['source_end'])) {
                if (!is_int($block['source_start']) || !is_int($block['source_end'])
                    || $block['source_start'] < 0 || $block['source_end'] < $block['source_start']) return null;
                $item['source_start'] = $block['source_start'];
                $item['source_end'] = $block['source_end'];
            }
            $normalized[] = $item;
        }
        return $normalized;
    }

    /** Reconcile visible blocks while retaining committed blocks virtualized out of the DOM. */
    private static function reconcile(array $state, array $blocks): array
    {
        $committed = $state['committed'];
        if ($committed === []) return ['pending' => $blocks];
        $pending = [];
        $highest = -1;
        $matched = [];
        $groups = [];
        $previousStart = null;
        $lastRanged = null;
        $indexes = self::alignmentIndexes($committed, $state['latest']);
        foreach ($committed as $index => $block) if (isset($block['source_end'])) $lastRanged = $index;
        foreach ($blocks as $observedIndex => $block) {
            if (isset($block['source_start'])) {
                if ($previousStart !== null && $block['source_start'] <= $previousStart) {
                    return ['error' => ['reason' => 'non_monotonic_source_ranges', 'observed_index' => $observedIndex]];
                }
                $previousStart = $block['source_start'];
            }
            $match = self::committedIndex($state, $block, $highest, $matched, $indexes);
            if (is_array($match)) return ['error' => $match];
            if ($match !== null) {
                $old = $committed[$match];
                if ($pending !== [] || $match <= $highest || $old['text'] !== $block['text']) {
                    return ['error' => self::diagnostic($pending !== [] || $match <= $highest
                        ? 'block_order_changed' : 'text_changed', $block, $old, $observedIndex, $match)];
                }
                if ($old['link_targets'] !== $block['link_targets']) {
                    return ['error' => self::diagnostic('link_target_changed', $block, $old, $observedIndex, $match)];
                }
                $highest = $match;
                $matched[$match] = true;
                if ($block['group'] !== null && $old['group'] !== null) $groups[$block['group']] = $old['group'];
                continue;
            }
            if (isset($block['source_start']) && $lastRanged !== null) {
                if ($block['source_start'] <= $committed[$lastRanged]['source_end']) {
                    return ['error' => self::diagnostic('source_range_overlap', $block,
                        $committed[$lastRanged], $observedIndex, $lastRanged)];
                }
            } elseif ($highest !== count($committed) - 1 && $pending === [] && !self::matchesLatest($state, $block, $indexes)) {
                return ['error' => ['reason' => 'unanchored_tail', 'observed_index' => $observedIndex]];
            }
            if ($block['group'] !== null) {
                // Replacing a list wrapper gives it a new DOM key, while its already
                // committed or known pending items still prove this is the same list.
                if (!isset($groups[$block['group']])) {
                    $previousGroup = self::latestGroup($state, $block);
                    if ($previousGroup !== null) $groups[$block['group']] = $previousGroup;
                }
                $block['group'] = $groups[$block['group']] ?? $block['group'];
            }
            $pending[] = $block;
        }
        return ['pending' => $pending];
    }

    /** Prefer source identity, then actual DOM identity, then a unique ordered semantic match. */
    private static function committedIndex(array $state, array $block, int $after, array $matched, array $indexes): int|array|null
    {
        $exact = self::exactIndex($indexes['committed'], $block);
        if ($exact !== null) return $exact;
        if (isset($block['source_start']) || trim($block['text']) === '') return null;
        $semantic = self::semanticIndices($state['committed'], $indexes['committed'], $block);
        $remaining = array_values(array_filter($semantic, static fn(int $index): bool => $index > $after));
        if (count($remaining) === 1) return $remaining[0];
        if (count($remaining) > 1) {
            if ($after >= 0 && $remaining[0] === $after + 1) return $remaining[0];
            return ['reason' => 'ambiguous_repeated_text'];
        }
        foreach ($semantic as $index) {
            if (!isset($matched[$index]) && !self::matchesLatest($state, $block, $indexes)) return $index;
        }
        return null;
    }

    /** An observed undelivered draft can anchor the next tail after its committed prefix disappears. */
    private static function matchesLatest(array $state, array $block, array $indexes): bool
    {
        if (self::exactIndex($indexes['latest'], $block) !== null) return true;
        if (isset($block['source_start']) || trim($block['text']) === '') return false;
        return count(self::semanticIndices($state['latest'], $indexes['latest'], $block)) === 1;
    }

    /** Build request-local lookup tables once, keeping repeated long-answer polls linear in the normal case. */
    private static function alignmentIndexes(array $committed, array $latest): array
    {
        $result = [];
        foreach (['committed' => $committed, 'latest' => $latest] as $name => $blocks) {
            $index = ['key' => [], 'unranged_key' => [], 'source' => [], 'semantic' => []];
            foreach ($blocks as $position => $block) {
                $index['key'][$block['key']] ??= $position;
                if (isset($block['source_start'])) {
                    $index['source'][$block['source_start'] . ':' . $block['tag']] ??= $position;
                } else $index['unranged_key'][$block['key']] ??= $position;
                $index['semantic'][self::semanticKey($block)][] = $position;
            }
            $result[$name] = $index;
        }
        return $result;
    }

    /** Reproduce ordered exact matching when ranged and un-ranged observations share a DOM key. */
    private static function exactIndex(array $index, array $block): ?int
    {
        if (!isset($block['source_start'])) return $index['key'][$block['key']] ?? null;
        $source = $index['source'][$block['source_start'] . ':' . $block['tag']] ?? null;
        $unrangedKey = $index['unranged_key'][$block['key']] ?? null;
        return $source === null ? $unrangedKey : ($unrangedKey === null ? $source : min($source, $unrangedKey));
    }

    /** Hash only the lookup key; compare full semantic content to preserve correctness under collisions. */
    private static function semanticIndices(array $blocks, array $index, array $block): array
    {
        $matches = [];
        foreach ($index['semantic'][self::semanticKey($block)] ?? [] as $position) {
            if ($blocks[$position]['tag'] === $block['tag'] && $blocks[$position]['text'] === $block['text']) $matches[] = $position;
        }
        return $matches;
    }

    /** Use a bounded map key without duplicating large paragraph strings in the index. */
    private static function semanticKey(array $block): string
    {
        return hash('sha256', $block['tag'] . "\0" . $block['text']);
    }

    /** Preserve one-list separators when an observed draft survives a remounted list wrapper. */
    private static function latestGroup(array $state, array $block): ?string
    {
        $matches = array_filter($state['latest'], static function (array $old) use ($block): bool {
            return isset($block['source_start'], $old['source_start'])
                ? $block['source_start'] === $old['source_start'] && $block['tag'] === $old['tag']
                : $block['key'] === $old['key'] || ($block['tag'] === $old['tag'] && $block['text'] !== ''
                    && $block['text'] === $old['text']);
        });
        return count($matches) === 1 ? array_values($matches)[0]['group'] : null;
    }

    /** Source ranges survive reparenting; a node key distinguishes repeated un-ranged paragraphs. */
    private static function candidateId(array $block): string
    {
        return isset($block['source_start']) ? 'source:' . $block['source_start'] . ':' . $block['tag']
            : 'key:' . $block['key'];
    }

    /** Cosmetic HTML hydration does not restart the semantic stability window. */
    private static function sameObservation(array $left, array $right): bool
    {
        return $left['markdown'] === $right['markdown'] && $left['text'] === $right['text']
            && $left['link_targets'] === $right['link_targets'] && $left['group'] === $right['group']
            && ($left['source_start'] ?? null) === ($right['source_start'] ?? null)
            && ($left['source_end'] ?? null) === ($right['source_end'] ?? null);
    }

    /** Commit one block once, preserving adjacent list-item formatting and empty artifact identity. */
    private static function commit(array &$state, array $block): void
    {
        $state['committed'][] = $block;
        if ($block['markdown'] === '') return;
        $separator = $state['markdown'] === '' ? ''
            : ($block['group'] !== null && $block['group'] === $state['last_group'] ? "\n" : "\n\n");
        $state['markdown'] .= $separator . $block['markdown'];
        $state['last_group'] = $block['group'];
    }

    /** Diagnostic metadata excludes user/model text while distinguishing genuine protocol changes. */
    private static function diagnostic(string $reason, array $block, array $old, int $observed, int $committed): array
    {
        return ['reason' => $reason, 'observed_index' => $observed, 'committed_index' => $committed,
            'observed_tag' => $block['tag'], 'committed_tag' => $old['tag'],
            'observed_text_chars' => mb_strlen($block['text']), 'committed_text_chars' => mb_strlen($old['text']),
            'observed_start' => $block['source_start'] ?? null, 'committed_start' => $old['source_start'] ?? null];
    }

    /** Keep the prior ledger untouched on contradictory observations so a transient redraw may settle. */
    private static function failure(array $state, array $error): array
    {
        return ['state' => $state, 'markdown' => $state['markdown'],
            'consistent' => false, 'error' => $error, 'semantic' => true];
    }
}
