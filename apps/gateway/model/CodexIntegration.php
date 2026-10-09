<?php
declare(strict_types=1);
namespace app\gateway\model;

use RC\Config;
use app\gateway\database\Schema;

/** Recoverable, compare-before-write Codex configuration integration; never manages Codex processes. */
final class CodexIntegration
{
    private const ROUTE_KEYS = [['openai_base_url'], ['model_provider'], ['model_catalog_json'], ['profile']];
    private const COMPRESSION_KEY = ['features', 'enable_request_compression'];
    /** Resolve the configured client home, separately from the private gateway runtime. */
    public static function configPath(): string
    {
        $override = (string) Config::get('gateway', 'codex_home');
        if ($override === '') throw new GatewayError('codex_home_missing', '无法定位 Codex 配置目录。', 422);
        return rtrim(str_replace('\\', '/', $override), '/') . '/config.toml';
    }

    /**
     * Preview replacing the active model route; values from existing configuration stay private.
     * @param string|null $path Optional isolated config path for acceptance tests.
     * @return array Public change summary and compare-before-write hash; never writes config.toml.
     * @throws GatewayError If the current document cannot be safely read or edited.
     */
    public static function plan(?string $path = null): array
    {
        $plan = self::prepare($path);
        unset($plan['after_text']);
        return $plan;
    }

    /** Apply exactly the reviewed version under a stable file lock, journaling intent before writing. */
    public static function install(string $expectedHash, ?string $path = null): array
    {
        $path = self::canonicalPath($path ?? self::configPath());
        return self::locked($path, static function () use ($path, $expectedHash): array {
            $plan = self::prepare($path);
            if (!hash_equals($plan['expected_hash'], $expectedHash)) throw new GatewayError('codex_config_conflict', 'Codex 配置在预览后发生变化，请重新预览。', 409);
            if ($plan['already_installed']) return self::status($path);
            $before = self::readConfig($path);
            self::assertVersion($before, $expectedHash);
            $after = $plan['after_text'];
            self::backup($path, $before);
            Schema::transaction(static function () use ($plan, $path, $before, $after): void {
                $db = SDB(null, 'medoo', 'sqlite');
                $old = self::record($path, $before);
                if (is_array($old)) $db->table('gateway_codex_integrations')->where('id', $old['id'])->delete();
                if (!$db->table('gateway_codex_integrations')->add([
                    'id' => $plan['id'], 'config_path' => $path, 'before_text' => $before, 'after_text' => $after,
                    'before_hash' => hash('sha256', $before), 'after_hash' => hash('sha256', $after),
                    'state' => 'writing', 'installed_at' => time(),
                ])) throw new GatewayError('storage_unavailable', '接入记录无法保存。', 503);
            });
            self::assertVersion(self::readConfig($path), $expectedHash);
            self::atomicWrite($path, $after);
            SDB(null, 'medoo', 'sqlite')->table('gateway_codex_integrations')->where('id', $plan['id'])->update(['state' => 'restart_required']);
            return self::status($path) + ['backup_path' => self::backupPath($path, $before)];
        });
    }

    /** Restore displaced routing from the journal and preserve subsequent unrelated settings. */
    public static function remove(string $expectedHash, ?string $path = null): array
    {
        $path = self::canonicalPath($path ?? self::configPath());
        return self::locked($path, static function () use ($path, $expectedHash): array {
            $current = self::readConfig($path);
            $row = self::recoverInterruptedWrite(self::record($path, $current), $current);
            if (!is_array($row)) throw new GatewayError('codex_not_integrated', '此 Codex 配置尚未接入。', 409);
            if ($row['state'] === 'removed') throw new GatewayError('codex_not_integrated', '此 Codex 配置尚未接入。', 409);
            if ($row['state'] === 'writing') throw new GatewayError('codex_config_conflict', 'Codex 接入安装曾中断，且配置已出现外部变化；未自动覆盖。', 409);
            self::assertVersion($current, $expectedHash);
            if (!self::isInstalled($row, $current)) throw new GatewayError('codex_config_conflict', '接入配置已变化。可重新预览并接入，或选择“恢复 Codex 官方路由”。', 409);
            $restored = self::restorePrevious($row, $current);
            self::backup($path, $current);
            self::assertVersion(self::readConfig($path), $expectedHash);
            self::atomicWrite($path, $restored);
            SDB(null, 'medoo', 'sqlite')->table('gateway_codex_integrations')->where('id', $row['id'])->update(['state' => 'removed', 'config_path' => $path]);
            return self::status($path) + ['backup_path' => self::backupPath($path, $current)];
        });
    }

    /** Public status contains no existing config contents or native credentials. */
    public static function status(?string $path = null): array
    {
        Schema::initialize(); $path = self::canonicalPath($path ?? self::configPath());
        $current = self::readConfig($path);
        $row = self::recoverInterruptedWrite(self::record($path, $current), $current);
        $status = is_array($row) ? (string) $row['state'] : 'not_installed';
        $document = new CodexConfigDocument($current);
        if ($status === 'removed' || !is_array($row) || !$document->hasMarker((string) $row['id'])) $status = 'not_installed';
        elseif (!self::isInstalled($row, $current) || $status === 'writing') $status = 'config_conflict';
        $active = !in_array($status, ['not_installed', 'config_conflict'], true);
        return ['id' => $row['id'] ?? null, 'state' => $status, 'path' => $path,
            'expected_hash' => hash('sha256', $current), 'catalog_seen_at' => $active ? ($row['catalog_seen_at'] ?? null) : null,
            'response_seen_at' => $active ? ($row['response_seen_at'] ?? null) : null, 'models' => self::publicModels(), 'account' => ModelCatalog::account()];
    }

    /** Record actual client contact for its configured route; mere config write is never verified. */
    public static function clientSeen(string $id, string $kind): void
    {
        if ($id === '') return;
        $field = $kind === 'catalog' ? 'catalog_seen_at' : 'response_seen_at';
        Schema::transaction(static function () use ($id, $field): void {
            $db = SDB(null, 'medoo', 'sqlite');
            $row = $db->table('gateway_codex_integrations')->where('id', $id)->find();
            if (!is_array($row) || $row['state'] === 'removed') return;
            $row[$field] = time();
            $state = !empty($row['catalog_seen_at']) && !empty($row['response_seen_at']) ? 'verified' : 'client_connected';
            $db->table('gateway_codex_integrations')->where('id', $id)->update([$field => $row[$field], 'state' => $state]);
        });
    }

    /** Preview the native catalog's window-compatible model choices without private browser metadata. */
    private static function publicModels(): array
    {
        $settings = GatewaySettings::internal();
        return array_map(static function (array $row) use ($settings): array {
            $budget = ModelCatalog::catalogBudget($row, $settings);
            $standard = ModelCatalog::catalogBudget($row, array_replace($settings, ['context_extended'=>0]));
            $extended = ModelCatalog::catalogBudget($row, array_replace($settings,
                ['context_extended'=>1, 'context_max_parts'=>6]));
            return ['id'=>$row['id'], 'label'=>$row['label'], 'supports_extended'=>!empty($row['capabilities']['sol_available']),
                'notice'=>$row['notice'] ?? '', 'website_label'=>$row['website_label'] ?? $row['label'], 'efforts'=>$row['efforts'],
                'context_tokens'=>$budget['context_tokens'], 'compact_tokens'=>$budget['compact_tokens'],
                'base_context_tokens'=>$budget['base_context_tokens'], 'base_compact_tokens'=>$budget['base_compact_tokens'],
                'budget_options'=>[
                    'standard'=>['context_tokens'=>$standard['context_tokens'], 'compact_tokens'=>$standard['compact_tokens']],
                    'extended'=>['context_tokens'=>$extended['context_tokens'], 'compact_tokens'=>$extended['compact_tokens']],
                ]];
        }, ModelCatalog::catalogRoutes());
    }

    /** Give slash/dot aliases of the same existing file one journal and lock identity. */
    private static function canonicalPath(string $path): string
    {
        $resolved = realpath($path);
        if ($resolved === false) {
            $parent = realpath(dirname($path));
            $resolved = $parent === false ? $path : $parent . DIRECTORY_SEPARATOR . basename($path);
        }
        return PHP_OS_FAMILY === 'Windows' ? str_replace('\\', '/', $resolved) : $resolved;
    }

    /**
     * Find the journal, including an older noncanonical spelling of this exact file.
     * A copied managed block is not ownership proof for a different config path.
     */
    private static function record(string $path, string $current): ?array
    {
        $db = SDB(null, 'medoo', 'sqlite');
        $row = $db->table('gateway_codex_integrations')->where('config_path', $path)->find();
        if (is_array($row)) return $row;
        if (!preg_match('/^# RCMAKER BEGIN (rcm_[a-f0-9]{24})\r?$/m', $current, $match)) return null;
        $row = $db->table('gateway_codex_integrations')->where('id', $match[1])->find();
        return is_array($row) && self::canonicalPath((string) $row['config_path']) === $path ? $row : null;
    }

    /**
     * Reconcile a crash between the durable journal write and atomic config replacement.
     * An exact before/after image is safe to classify. Any third state remains a conflict so an
     * external edit or partial write is never overwritten automatically.
     */
    private static function recoverInterruptedWrite(?array $row, string $current): ?array
    {
        if (!is_array($row) || (string) ($row['state'] ?? '') !== 'writing') return $row;
        $currentHash = hash('sha256', $current);
        if (hash_equals((string) $row['before_hash'], $currentHash) && hash_equals((string) $row['before_text'], $current)) {
            SDB(null, 'medoo', 'sqlite')->table('gateway_codex_integrations')->where('id', $row['id'])
                ->where('state', 'writing')->update(['state' => 'removed', 'last_error' => 'interrupted_before_publish']);
            $row['state'] = 'removed';
            $row['last_error'] = 'interrupted_before_publish';
            return $row;
        }
        if (hash_equals((string) $row['after_hash'], $currentHash) && hash_equals((string) $row['after_text'], $current)) {
            SDB(null, 'medoo', 'sqlite')->table('gateway_codex_integrations')->where('id', $row['id'])
                ->where('state', 'writing')->update(['state' => 'restart_required', 'last_error' => null]);
            $row['state'] = 'restart_required';
            $row['last_error'] = null;
        }
        return $row;
    }

    /** Root assignment must precede every existing TOML table. */
    private static function block(string $id): string
    {
        $url = rtrim((string) Config::get('gateway', 'api_origin'), '/') . '/v1/' . $id;
        return '# RCMAKER BEGIN ' . $id . "\nopenai_base_url = " . json_encode($url, JSON_UNESCAPED_SLASHES)
            . "\n# RCMAKER END " . $id . "\n";
    }

    /** Build the private after-image; public previews expose key names, never old values. */
    private static function prepare(?string $path): array
    {
        Schema::initialize();
        $path = self::canonicalPath($path ?? self::configPath());
        $before = self::readConfig($path);
        $existing = self::recoverInterruptedWrite(self::record($path, $before), $before);
        if (is_array($existing) && !in_array($existing['state'], ['removed', 'writing'], true) && self::isInstalled($existing, $before)) {
            return ['id' => $existing['id'], 'path' => $path, 'expected_hash' => hash('sha256', $before),
                'changes' => self::block($existing['id']), 'already_installed' => true, 'replaced_keys' => [],
                'backup_path' => self::backupPath($path, $existing['before_text']), 'after_text' => $before];
        }
        $document = new CodexConfigDocument($before);
        if ($document->assignments(['features']) !== []) {
            throw new GatewayError('codex_config_complex', '请将 features = {...} 改写为 [features] 配置节后重新预览，其他配置无需删除。', 422);
        }
        $id = 'rcm_' . substr(hash('sha256', $path . "\0" . $before . "\0" . ($existing['id'] ?? '')), 0, 24);
        $paths = array_merge(self::ROUTE_KEYS, [self::COMPRESSION_KEY]);
        $clean = new CodexConfigDocument($document->without($paths));
        $scope = $clean->hasTable(['features']) ? ['features'] : [];
        $key = $scope ? 'enable_request_compression' : 'features.enable_request_compression';
        $compression = '# RCMAKER COMPRESSION BEGIN ' . $id . "\n" . $key . " = false\n# RCMAKER COMPRESSION END " . $id . "\n";
        $after = self::block($id) . $clean->insert($compression, $scope);
        $replaced = [];
        foreach ($paths as $keyPath) if ($document->assignments($keyPath) !== []) $replaced[] = implode('.', $keyPath);
        return ['id' => $id, 'path' => $path, 'expected_hash' => hash('sha256', $before),
            'changes' => self::block($id) . $compression, 'already_installed' => false,
            'replaced_keys' => $replaced, 'backup_path' => self::backupPath($path, $before), 'after_text' => $after];
    }

    /** Check the actual root route and compression, not merely a historic SQLite record. */
    private static function isInstalled(array $row, string $current): bool
    {
        $document = new CodexConfigDocument($current);
        if (!$document->hasMarker((string) $row['id'])) return false;
        $expected = new CodexConfigDocument(self::block((string) $row['id']));
        if (self::values($document, ['openai_base_url']) !== self::values($expected, ['openai_base_url'])) return false;
        if ($document->assignments(['model_catalog_json']) || $document->assignments(['profile'])) return false;
        $providers = self::values($document, ['model_provider']);
        if ($providers !== [] && $providers !== ['"openai"'] && $providers !== ["'openai'"]) return false;
        return self::values($document, self::COMPRESSION_KEY) === ['false'];
    }

    /** Return normalized scalar representations for the small set of managed routing keys. */
    private static function values(CodexConfigDocument $document, array $path): array
    {
        return array_map(static function (array $entry): string {
            $value = $entry['value'];
            if (preg_match('/^("(?:[^"\\\\]|\\\\.)*"|\'[^\']*\'|true|false)[ \t]*(?:#[^\r\n]*)?$/', $value, $match)) return $match[1];
            return $value;
        }, $document->assignments($path));
    }

    /** Reverse only changed assignments; unrelated post-install edits remain byte-for-byte intact. */
    private static function restorePrevious(array $row, string $current): string
    {
        if ($current === $row['after_text']) return $row['before_text'];
        $before = new CodexConfigDocument($row['before_text']);
        $after = new CodexConfigDocument($row['after_text']);
        $document = new CodexConfigDocument($current);
        $paths = [];
        $restore = [];
        foreach (array_merge(self::ROUTE_KEYS, [self::COMPRESSION_KEY]) as $keyPath) {
            if ($before->assignments($keyPath) === [] && $after->assignments($keyPath) === []) continue;
            if (self::values($document, $keyPath) !== self::values($after, $keyPath)) {
                throw new GatewayError('codex_config_conflict', '路由设置在接入后发生变化。请重新预览接入，或选择“恢复 Codex 官方路由”。', 409);
            }
            $paths[] = $keyPath;
            foreach ($before->assignments($keyPath) as $entry) $restore[] = $entry;
        }
        $text = $document->without($paths);
        // Inserting in reverse retains the original order of assignments within each scope.
        foreach (array_reverse($restore) as $entry) {
            $text = (new CodexConfigDocument($text))->insert(rtrim($entry['text'], "\r\n") . "\n", $entry['scope']);
        }
        return $text;
    }

    /**
     * Preview returning to Codex defaults, independently of which bridge last wrote the route.
     * @param string|null $path Optional isolated config path for tests.
     * @return array Key-only preview, backup location and current version hash.
     * @throws GatewayError On an unreadable or malformed file.
     */
    public static function nativePlan(?string $path = null): array
    {
        $path = self::canonicalPath($path ?? self::configPath());
        $before = self::readConfig($path);
        $paths = self::nativeKeys(new CodexConfigDocument($before));
        return ['path' => $path, 'expected_hash' => hash('sha256', $before), 'action' => 'native',
            'replaced_keys' => array_map(static fn(array $key) => implode('.', $key), $paths),
            'changes' => '清除模型路由覆盖，使用 Codex 内置 OpenAI 提供商。Web 默认模型会被清除；原生默认模型、官方语音地址、MCP、项目和权限设置会保留。',
            'already_installed' => false, 'backup_path' => self::backupPath($path, $before)];
    }

    /**
     * Save the reviewed return to native routing, backing up the full current file first.
     * @param string $expectedHash SHA-256 from nativePlan; stale previews are rejected.
     * @param string|null $path Optional isolated config path for tests.
     * @return array Current status plus explicit restart guidance and backup path.
     * @throws GatewayError On a stale preview, write failure or unsupported TOML.
     */
    public static function restoreNative(string $expectedHash, ?string $path = null): array
    {
        Schema::initialize();
        $path = self::canonicalPath($path ?? self::configPath());
        return self::locked($path, static function () use ($path, $expectedHash): array {
            $before = self::readConfig($path);
            self::assertVersion($before, $expectedHash);
            $document = new CodexConfigDocument($before);
            $after = $document->without(self::nativeKeys($document));
            self::backup($path, $before);
            self::assertVersion(self::readConfig($path), $expectedHash);
            self::atomicWrite($path, $after);
            $row = self::record($path, $before);
            if (is_array($row)) SDB(null, 'medoo', 'sqlite')->table('gateway_codex_integrations')->where('id', $row['id'])->update(['state' => 'removed']);
            return self::status($path) + ['native_restored' => true, 'restart_required' => true, 'backup_path' => self::backupPath($path, $before)];
        });
    }

    /** Clear active route selectors and an unavailable Web default, leaving native model choices intact. */
    private static function nativeKeys(CodexConfigDocument $document): array
    {
        $paths = self::ROUTE_KEYS;
        $models = self::values($document, ['model']);
        if (count($models) === 1 && preg_match('/^["\']chatgpt-web\//', $models[0])) {
            $paths[] = ['model'];
            $paths[] = ['model_reasoning_effort'];
        }
        return array_values(array_filter($paths, static fn(array $key) => $document->assignments($key) !== []));
    }

    /** Read failures must never be mistaken for a user intentionally deleting the file. */
    private static function readConfig(string $path): string
    {
        if (!file_exists($path)) return '';
        $text = is_file($path) ? file_get_contents($path) : false;
        if (!is_string($text) || strlen($text) > 2097152) throw new GatewayError('codex_config_unreadable', 'Codex 配置不可读取或过大。', 422);
        return $text;
    }

    /** Only a concurrent change after preview requires a new preview, not manual conflict resolution. */
    private static function assertVersion(string $text, string $expectedHash): void
    {
        if (!hash_equals(hash('sha256', $text), $expectedHash)) throw new GatewayError('codex_config_conflict', 'Codex 配置在预览后发生变化，请重新预览。', 409);
    }

    /** Immutable content-addressed backups live with persistent project data, never public assets. */
    private static function backupPath(string $path, string $text): string
    {
        return \RC\Config::get('gateway', 'data_directory') . '/backups/codex/' . hash('sha256', $path) . '/' . hash('sha256', $text) . '.toml';
    }

    /** Persist and verify the pre-write image before replacing any user configuration. */
    private static function backup(string $path, string $text): void
    {
        $backup = self::backupPath($path, $text);
        if (!is_dir(dirname($backup)) && !mkdir(dirname($backup), 0700, true) && !is_dir(dirname($backup))) {
            throw new GatewayError('codex_config_unwritable', '无法保存 Codex 配置备份，未修改原文件。', 422);
        }
        if (!is_file($backup)) self::atomicWrite($backup, $text);
        if (file_get_contents($backup) !== $text) throw new GatewayError('codex_config_unwritable', 'Codex 配置备份校验失败，未修改原文件。', 422);
    }

    /** Run a bounded compare/write transaction on this one config file, never a whole Codex directory. */
    private static function locked(string $path, callable $operation): array
    {
        if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0700, true) && !is_dir(dirname($path))) throw new GatewayError('codex_config_unwritable', '无法创建 Codex 配置目录。', 422);
        $lock = fopen($path . '.rcmaker.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) fclose($lock);
            throw new GatewayError('codex_config_busy', 'Codex 配置正在修改，请稍后重试。', 409);
        }
        try { return $operation(); } finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    /** Publish within the same directory; a failed rename leaves the original config intact. */
    private static function atomicWrite(string $path, string $text): void
    {
        $temporary = $path . '.rcmaker-' . bin2hex(random_bytes(5));
        try {
            if (file_put_contents($temporary, $text, LOCK_EX) !== strlen($text)) throw new \RuntimeException('write');
            @chmod($temporary, 0600);
            if (!rename($temporary, $path)) throw new \RuntimeException('rename');
        } catch (\Throwable) { throw new GatewayError('codex_config_unwritable', 'Codex 配置未能保存，原文件已保留。', 422); }
        finally { if (is_file($temporary)) @unlink($temporary); }
    }
}
