<?php
declare(strict_types=1);
namespace app\gateway\model;

use RC\Config;
use app\gateway\database\Schema;
use support\http\RequestCompression;

/** Recoverable, compare-before-write Codex configuration integration; never manages Codex processes. */
final class CodexIntegration
{
    private const ROUTE_KEYS = [['openai_base_url'], ['model_provider'], ['model_catalog_json'], ['profile']];
    private const COMPRESSION_KEY = ['features', 'enable_request_compression'];
    private const COMPRESSION_UPGRADE = 'compression_default_from:';
    /** Resolve the configured client home, separately from the private gateway runtime. */
    public static function configPath(): string
    {
        $override = (string) Config::get('gateway', 'codex_home');
        if ($override === '') throw new GatewayError('codex_home_missing', '无法定位 Codex 配置目录。', 422);
        if (PHP_OS_FAMILY === 'Windows') $override = str_replace('\\', '/', $override);
        return rtrim($override, '/') . '/config.toml';
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

    /**
     * Detect legacy markers separately from effective routing; never rewrite external edits on a read.
     * @param string|null $path Optional isolated client config path; defaults to the configured Codex home.
     * @return array Status with independent configured (markers) and route_ready (effective route) evidence.
     * @throws GatewayError If the document or durable installation journal cannot be read safely.
     */
    public static function status(?string $path = null): array
    {
        Schema::initialize(); $path = self::canonicalPath($path ?? self::configPath());
        $current = self::readConfig($path);
        $row = self::recoverInterruptedWrite(self::record($path, $current), $current);
        $document = new CodexConfigDocument($current);
        $configured = $document->hasIntegrationMarkers();
        $routeReady = $configured && self::routesToGateway($document);
        $status = $configured ? 'configured' : 'not_installed';
        if ($configured && is_array($row) && in_array($row['state'], ['restart_required', 'client_connected', 'verified'], true)) {
            $status = (string) $row['state'];
        }
        $canRestore = is_array($row) && !in_array($row['state'], ['removed', 'writing'], true) && self::isInstalled($row, $current);
        return ['id' => $row['id'] ?? null, 'state' => $status, 'path' => $path,
            'configured' => $configured, 'route_ready' => $routeReady, 'can_restore' => $canRestore,
            'expected_hash' => hash('sha256', $current), 'catalog_seen_at' => $routeReady ? ($row['catalog_seen_at'] ?? null) : null,
            'response_seen_at' => $routeReady ? ($row['response_seen_at'] ?? null) : null,
            'request_compression' => ['zstd_available'=>RequestCompression::zstdAvailable(),
                'uses_default'=>$document->assignments(['features']) === [] && self::values($document,self::COMPRESSION_KEY) === []],
            'models' => self::publicModels(), 'account' => ModelCatalog::account()];
    }

    /**
     * Upgrade only an existing gateway route to Codex's default-on compression after decoder self-test.
     * Called by the independent maintenance worker, never by status/preview or a model request.
     * Keep the active route/ID, exact backup, unrelated settings and journal restoration ownership.
     * @return array{changed:bool,restart_required:bool} No change for native, missing, complex or unsupported configs.
     * @throws GatewayError On a concurrent edit, unavailable journal or failed atomic replacement.
     */
    public static function upgradeCompressionDefaults(?string $path = null): array
    {
        if (!RequestCompression::zstdAvailable()) return ['changed'=>false,'restart_required'=>false];
        $path = self::canonicalPath($path ?? self::configPath());
        if (!is_file($path)) return ['changed'=>false,'restart_required'=>false];
        $preview = new CodexConfigDocument(self::readConfig($path));
        if (!$preview->hasIntegrationMarkers() || !self::routesToGateway($preview)) return ['changed'=>false,'restart_required'=>false];
        return self::locked($path, static function () use ($path): array {
            $before = self::readConfig($path);
            $row = self::record($path,$before);
            // A compression-only publication has a separate before-file hash: before_text
            // remains the user's recoverable native route, not a second gateway installation.
            if (is_array($row) && $row['state'] === 'writing' && str_starts_with((string)$row['last_error'],self::COMPRESSION_UPGRADE)) {
                $expected = substr($row['last_error'],strlen(self::COMPRESSION_UPGRADE));
                if (hash_equals($expected,hash('sha256',$before))) {
                    self::backup($path,$before);
                    self::assertVersion(self::readConfig($path),$expected);
                    self::atomicWrite($path,$row['after_text']);
                } elseif (!hash_equals($row['after_hash'],hash('sha256',$before))) {
                    throw new GatewayError('codex_config_conflict','Codex 配置在升级期间发生变化，未自动覆盖。',409);
                }
                SDB(null,'medoo','sqlite')->table('gateway_codex_integrations')->where('id',$row['id'])
                    ->update(['state'=>'restart_required','last_error'=>null,'catalog_seen_at'=>null,'response_seen_at'=>null]);
                return ['changed'=>true,'restart_required'=>true];
            }
            $row = self::recoverInterruptedWrite($row,$before);
            $document = new CodexConfigDocument($before);
            if (!$document->hasIntegrationMarkers() || !self::routesToGateway($document)
                || self::values($document,self::COMPRESSION_KEY) === []) return ['changed'=>false,'restart_required'=>false];
            if (is_array($row) && $row['state'] === 'writing') throw new GatewayError('codex_config_conflict','Codex 接入安装尚未确认，未自动覆盖。',409);
            $after = $document->withoutCompressionOverride();
            $owned = is_array($row) && $row['state'] !== 'removed' && self::isInstalled($row,$before);
            $restored = $owned ? self::restorePrevious($row,$before) : null;
            self::backup($path,$before);
            if ($owned) {
                Schema::transaction(static function () use ($row,$before,$after,$restored): void {
                    $changed = SDB(null,'medoo','sqlite')->table('gateway_codex_integrations')->where('id',$row['id'])
                        ->where('after_hash',$row['after_hash'])->update(['before_text'=>$restored,'before_hash'=>hash('sha256',$restored),
                            'after_text'=>$after,'after_hash'=>hash('sha256',$after),'state'=>'writing',
                            'last_error'=>self::COMPRESSION_UPGRADE.hash('sha256',$before)]);
                    if (!$changed || $changed->rowCount() !== 1) throw new GatewayError('codex_config_conflict','Codex 接入记录已变化，未自动覆盖。',409);
                });
            }
            self::assertVersion(self::readConfig($path),hash('sha256',$before));
            self::atomicWrite($path,$after);
            if ($owned) SDB(null,'medoo','sqlite')->table('gateway_codex_integrations')->where('id',$row['id'])
                ->update(['state'=>'restart_required','last_error'=>null,'catalog_seen_at'=>null,'response_seen_at'=>null]);
            return ['changed'=>true,'restart_required'=>true];
        });
    }

    /**
     * Check the current effective route without requiring a local journal for legacy installations.
     * @param CodexConfigDocument $document Current client configuration, already parsed losslessly.
     * @return bool Whether the built-in provider reaches this gateway with its supported wire settings.
     */
    private static function routesToGateway(CodexConfigDocument $document): bool
    {
        $url = self::scalarString(self::values($document, ['openai_base_url']));
        $base = rtrim((string) Config::get('gateway', 'api_origin'), '/') . '/v1';
        if ($url === null || !preg_match('~\A' . preg_quote($base, '~') . '(?:/rcm_[a-f0-9]{24})?/?\z~D', $url)) return false;
        $providers = self::values($document, ['model_provider']);
        if ($providers !== [] && self::scalarString($providers) !== 'openai') return false;
        return $document->assignments(['model_catalog_json']) === [] && $document->assignments(['profile']) === []
            && self::compressionCompatible($document);
    }

    /**
     * Decode one TOML string scalar; duplicate, malformed and non-string values are not ready.
     * @param list<string> $values Normalized assignment values. @return string|null Decoded scalar or null.
     */
    private static function scalarString(array $values): ?string
    {
        if (count($values) !== 1) return null;
        $value = $values[0];
        if (strlen($value) >= 2 && $value[0] === "'" && str_ends_with($value, "'")) return substr($value, 1, -1);
        $decoded = json_decode($value, true);
        return is_string($decoded) ? $decoded : null;
    }

    /** Record actual client contact for its configured route; mere config write is never verified. */
    public static function clientSeen(string $id, string $kind): void
    {
        if ($id === '') return;
        $field = $kind === 'catalog' ? 'catalog_seen_at' : 'response_seen_at';
        Schema::transaction(static function () use ($id, $field): void {
            $db = SDB(null, 'medoo', 'sqlite');
            $row = $db->table('gateway_codex_integrations')->where('id', $id)->find();
            // An old client's contact cannot prove that an in-flight config image was published.
            if (!is_array($row) || in_array($row['state'],['removed','writing'],true)) return;
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
            return ['id'=>$row['id'], 'label'=>$row['label'], 'supports_extended'=>!empty($extended['extension_allowed']),
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
        if (is_array($existing) && !in_array($existing['state'], ['removed', 'writing'], true) && self::isInstalled($existing, $before)
            && self::values(new CodexConfigDocument($before), self::COMPRESSION_KEY) === self::compressionValues()) {
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
        $compression = RequestCompression::zstdAvailable() ? '' : '# RCMAKER COMPRESSION BEGIN ' . $id . "\n" . $key . ' = false'
            . "\n# RCMAKER COMPRESSION END " . $id . "\n";
        $after = self::block($id) . $clean->insert($compression, $scope);
        $replaced = [];
        foreach ($paths as $keyPath) if ($document->assignments($keyPath) !== []) $replaced[] = implode('.', $keyPath);
        return ['id' => $id, 'path' => $path, 'expected_hash' => hash('sha256', $before),
            'changes' => self::block($id) . $compression, 'already_installed' => false,
            'replaced_keys' => $replaced, 'backup_path' => self::backupPath($path, $before), 'after_text' => $after];
    }

    /** Check owned route/compression against the journal; runtime compatibility is a separate readiness decision. */
    private static function isInstalled(array $row, string $current): bool
    {
        $document = new CodexConfigDocument($current);
        if (!$document->hasMarker((string) $row['id'])) return false;
        $expected = new CodexConfigDocument(self::block((string) $row['id']));
        if (self::values($document, ['openai_base_url']) !== self::values($expected, ['openai_base_url'])) return false;
        if ($document->assignments(['model_catalog_json']) || $document->assignments(['profile'])) return false;
        $providers = self::values($document, ['model_provider']);
        if ($providers !== [] && $providers !== ['"openai"'] && $providers !== ["'openai'"]) return false;
        $compression = self::values($document, self::COMPRESSION_KEY);
        return in_array($compression, [[], ['false'], ['true']], true)
            && $compression === self::values(new CodexConfigDocument((string) $row['after_text']), self::COMPRESSION_KEY);
    }

    /** Omit the override only after the running PHP self-test; Codex enables compression by default. */
    private static function compressionValues(): array
    {
        return RequestCompression::zstdAvailable() ? [] : ['false'];
    }

    /** Retain uncompressed legacy clients; compressed configuration requires this runtime's decoder. */
    private static function compressionCompatible(CodexConfigDocument $document): bool
    {
        $values = self::values($document, self::COMPRESSION_KEY);
        if ($document->assignments(['features']) !== []) return false;
        return $values === ['false'] || (in_array($values,[[],['true']],true) && RequestCompression::zstdAvailable());
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
