<?php
declare(strict_types=1);
namespace app\gateway\model;

use app\gateway\database\Schema;

/** Connector readiness with an initial MCP receipt and account-bound visibility checks after restart. */
final class ConnectorState
{
    public static function snapshot(string $expectedName): array
    {
        Schema::initialize();
        $row = self::row();
        $matches = hash_equals((string) $row['connector_name'], $expectedName);
        $currentProof = (string) $row['runtime_generation'] === ''
            || hash_equals((string) $row['runtime_generation'], (string) ($row['verified_generation'] ?? ''));
        $probePending = $matches
            && is_string($row['probe_token_hash'] ?? null) && (string) $row['probe_token_hash'] !== ''
            && (string) $row['status'] !== 'verified'
            && (int) ($row['probe_expires_at'] ?? 0) >= time();
        return [
            'status' => $matches ? (string) $row['status'] : 'unverified',
            'verified' => $matches && $currentProof && (string) $row['status'] === 'verified' && (int) ($row['verified_at'] ?? 0) > 0,
            'reconnecting' => $matches && (int) $row['recheck_required'] === 1,
            'recheck_queued' => $matches && (int) $row['recheck_required'] === 1 && $row['recheck_command_id'] !== null,
            'probe_pending' => $probePending,
            'verified_at' => $matches && $row['verified_at'] !== null ? (int) $row['verified_at'] : null,
            'last_error' => $matches && is_string($row['last_error']) ? $row['last_error'] : null,
            'updated_at' => (int) $row['updated_at'],
        ];
    }

    /**
     * Fence connector proof when the owned tunnel starts a new runtime. Prior setup is remembered
     * to schedule one visibility check; an initial permission receipt survives only for the same name.
     * Old probe tokens cannot verify the new runtime.
     * @param string $connectorName Current configured connector name.
     * @return void
     * @throws GatewayError When the durable state cannot be updated.
     */
    public static function runtimeStarted(string $connectorName): void
    {
        Schema::initialize();
        Schema::transaction(static function () use ($connectorName): void {
            $row = self::row();
            $sameName = hash_equals((string) $row['connector_name'], $connectorName);
            $recheck = $sameName && ((string) $row['status'] === 'verified'
                || !empty($row['permission_verified_at']) || (int) $row['recheck_required'] === 1 || $row['recheck_command_id'] !== null);
            $updated = SDB(null, 'medoo', 'sqlite')->table('gateway_connector_state')->where('id', 1)->update([
                'connector_name'=>$connectorName, 'runtime_generation'=>bin2hex(random_bytes(16)),
                'status'=>'unverified', 'verified_at'=>null, 'verified_generation'=>null,
                'probe_token_hash'=>null, 'probe_expires_at'=>null, 'probe_started_at'=>null, 'probe_generation'=>null,
                'recheck_required'=>(int) $recheck, 'recheck_command_id'=>null, 'last_error'=>null, 'updated_at'=>time(),
                'permission_verified_at'=>$sameName ? $row['permission_verified_at'] : null,
                'permission_account_key'=>$sameName ? $row['permission_account_key'] : null,
            ]);
            if ($updated === false) throw new GatewayError('STORAGE_UNAVAILABLE', '无法记录工具连接重建状态。', 503);
        });
    }

    /**
     * Called by the sole tunnel owner after /readyz. Reuse the browser command queue; never retry
     * a failed business task, execute a host command, or loop after a failed connectivity check.
     * @return void
     * @throws GatewayError On storage failure.
     */
    public static function scheduleRuntimeCheck(): void
    {
        $row = self::row();
        if ((int) $row['recheck_required'] !== 1) return;
        $db = SDB(null, 'medoo', 'sqlite');
        $browser = BrowserState::state();
        $page = json_decode((string) ($browser['chatgpt_json'] ?? ''), true);
        $browserReady = (string) $browser['status'] === 'ready'
            && (int) $browser['worker_heartbeat'] >= time() - 5
            && (int) ($browser['chatgpt_observed_at'] ?? 0) > (int) $row['updated_at']
            && (int) ($browser['chatgpt_observed_at'] ?? 0) >= time() - BrowserState::CAPABILITY_MAX_AGE_SECONDS
            && is_array($page);
        if ($row['recheck_command_id'] !== null) {
            $command = $db->table('gateway_browser_commands')->where('id', (int) $row['recheck_command_id'])->find();
            if (is_array($command) && in_array($command['status'], ['queued', 'running'], true)) return;
            $result = is_array($command) ? json_decode((string) ($command['result_json'] ?? ''), true) : null;
            if (is_array($result) && self::retryableRuntimeCheckFailure(
                (int) $row['recheck_command_id'], (string) ($result['code'] ?? ''))) {
                // These failures happened before a host action. The isolated
                // ChatGPT editor can also need more time after startup.
                // One fresh-page retry is safe and avoids a manual check after restart.
                if (!$browserReady || time() < (int) $command['finished_at'] + 3) return;
                self::enqueueRuntimeCheck($row, $db, 1);
                return;
            }
            $reason = trim((string) ($result['message'] ?? $row['last_error'] ?? ''));
            self::failed((string) $row['connector_name'], $reason !== '' ? $reason
                : '工具连接恢复检查未完成，请在运行准备中重新检查连接器。', (string) $row['runtime_generation']);
            return;
        }
        if (!$browserReady) return;
        // Wait for an existing manual check instead of starting a second probe.
        $existing = $db->table('gateway_browser_commands')->where('action', 'verify_connector')
            ->where('status', 'IN', ['queued', 'running'])->find();
        if (is_array($existing)) return;
        self::enqueueRuntimeCheck($row, $db, 0);
    }

    /** Queue one bounded probe after the browser worker has fresh page evidence. */
    private static function enqueueRuntimeCheck(array $row, mixed $db, int $attempt): void
    {
        $existing = $db->table('gateway_browser_commands')->where('action', 'verify_connector')
            ->where('status', 'IN', ['queued', 'running'])->find();
        if (is_array($existing)) return;
        try {
            $id = BrowserState::enqueue('verify_connector', [
                'connector_name'=>(string) $row['connector_name'],
                'runtime_generation'=>(string) $row['runtime_generation'],
                'recheck_attempt'=>$attempt,
            ]);
        } catch (GatewayError $error) {
            if (in_array($error->errorCode, ['BROWSER_COMMAND_BUSY', 'BROWSER_WORKER_UNAVAILABLE',
                'BROWSER_NOT_RUNNING', 'BROWSER_AUTOMATION_OWNS_SURFACE'], true)) return;
            throw $error;
        }
        $updated = $db->table('gateway_connector_state')->where('id', 1)
            ->where('runtime_generation', (string) $row['runtime_generation'])->where('recheck_required', 1)
            ->update(['recheck_command_id'=>$id, 'updated_at'=>time()]);
        if (!$updated || $updated->rowCount() !== 1) {
            BrowserState::finish($id, false, ['code'=>'CHATGPT_CONNECTOR_RUNTIME_CHANGED', 'message'=>'工具连接已变化，旧检查已取消。']);
        }
    }

    /** Return whether fresh task sends must wait; existing submitted turns are never replayed. */
    public static function runtimeCheckPending(): bool
    {
        return (int) self::row()['recheck_required'] === 1;
    }

    /**
     * Keep only the current automatic probe's first pre-action failure eligible for one retry.
     * @param int $commandId Browser verification command that failed.
     * @param string $code Failure code reported by the browser worker.
     * @return bool Whether the startup recheck should remain pending for one fresh-page attempt.
     * @throws GatewayError When durable connector state cannot be read.
     */
    public static function retryableRuntimeCheckFailure(int $commandId, string $code): bool
    {
        if (!in_array($code, ['BROWSER_NOT_READY', 'CHATGPT_CONNECTOR_ACTION_NOT_STARTED', 'CHATGPT_CONNECTOR_INPUT_TIMEOUT',
            'CHATGPT_EDITOR_NOT_READY'], true)) return false;
        $row = self::row();
        if ((int) $row['recheck_required'] !== 1 || (int) ($row['recheck_command_id'] ?? 0) !== $commandId) {
            return false;
        }
        $command = SDB(null, 'medoo', 'sqlite')->table('gateway_browser_commands')->where('id', $commandId)->find();
        $payload = is_array($command) ? json_decode((string) ($command['payload_json'] ?? ''), true) : null;
        return is_array($command) && (string) $command['action'] === 'verify_connector'
            && is_array($payload) && (int) ($payload['recheck_attempt'] ?? -1) === 0
            && hash_equals((string) $row['runtime_generation'], (string) ($payload['runtime_generation'] ?? ''));
    }

    /** Return the internal runtime fence used by asynchronous browser checks. */
    public static function runtimeGeneration(): string
    {
        return (string) self::row()['runtime_generation'];
    }

    /** Configured MCP wait in seconds, bounded for one no-host-effect connectivity attempt. */
    public static function probeTimeoutSeconds(): int
    {
        return max(10, min(90, (int) ceil((int) GatewaySettings::internal()['mcp_invoke_timeout_ms'] / 1000)));
    }

    /** @throws GatewayError Unless the current runtime passed its initial probe or a qualified visibility check. */
    public static function assertRuntimeReady(): void
    {
        $row = self::row();
        if ((string) $row['runtime_generation'] !== '' && ((string) $row['status'] !== 'verified'
            || !hash_equals((string) $row['runtime_generation'], (string) ($row['verified_generation'] ?? '')))) {
            throw new GatewayError('CHATGPT_CONNECTOR_NOT_READY', '工具连接尚未恢复，请在运行准备中检查连接器。', 503);
        }
    }

    /**
     * Retain an actual permission receipt with the freshly observed browser account for later startups.
     * @param string $connectorName Exact checked name. @param string $generation Current runtime fence.
     * @param string $accountKey SHA-256 account fingerprint, never a credential.
     * @return void
     * @throws GatewayError If the probe receipt or current configuration changed before persistence.
     */
    public static function rememberPermissionProof(string $connectorName, string $generation, string $accountKey): void
    {
        Schema::transaction(static function () use ($connectorName, $generation, $accountKey): void {
            $row = self::row();
            if ($accountKey === '' || $row['connector_name'] !== $connectorName || $row['runtime_generation'] !== $generation
                || $row['status'] !== 'verified' || $row['verified_generation'] !== $generation
                || empty($row['probe_token_hash']) || empty($row['probe_started_at'])) {
                throw new GatewayError('CHATGPT_CONNECTOR_RUNTIME_CHANGED', '工具连接已变化，旧检查已取消。', 409);
            }
            $updated = SDB(null, 'medoo', 'sqlite')->table('gateway_connector_state')->where('id', 1)
                ->where('runtime_generation', $generation)->update([
                    'permission_verified_at'=>(int) $row['verified_at'], 'permission_account_key'=>$accountKey,
                ]);
            if (!$updated || $updated->rowCount() !== 1) throw new GatewayError('STORAGE_UNAVAILABLE', '连接器检查状态无法保存。', 503);
        });
    }

    /**
     * Accept one exact visible connector only after this account's initial permission check succeeded.
     * The browser caller must freshly observe a unique selected name; this does not claim a new MCP round trip.
     * @param string $connectorName Exact visible name. @param string $generation Current runtime fence.
     * @param string $accountKey Fresh browser account fingerprint.
     * @return bool False requires an initial probe; true makes this runtime ready without a model message.
     * @throws GatewayError When configuration changes or persistence fails.
     */
    public static function acceptVisibleConnector(string $connectorName, string $generation, string $accountKey): bool
    {
        return Schema::transaction(static function () use ($connectorName, $generation, $accountKey): bool {
            $row = self::row();
            if ($row['connector_name'] !== $connectorName || $row['runtime_generation'] !== $generation
                || GatewaySettings::internal()['connector_name'] !== $connectorName) {
                throw new GatewayError('CHATGPT_CONNECTOR_RUNTIME_CHANGED', '工具连接已变化，旧检查已取消。', 409);
            }
            if (empty($row['permission_verified_at']) || $accountKey === ''
                || !hash_equals((string) ($row['permission_account_key'] ?? ''), $accountKey)) return false;
            $updated = SDB(null, 'medoo', 'sqlite')->table('gateway_connector_state')->where('id', 1)
                ->where('runtime_generation', $generation)->update([
                    'status'=>'verified', 'verified_at'=>time(), 'verified_generation'=>$generation,
                    'recheck_required'=>0, 'recheck_command_id'=>null, 'last_error'=>null,
                    'probe_token_hash'=>null, 'probe_expires_at'=>null, 'probe_started_at'=>null, 'probe_generation'=>null,
                    'updated_at'=>time(),
                ]);
            if (!$updated || $updated->rowCount() !== 1) throw new GatewayError('STORAGE_UNAVAILABLE', '连接器检查状态无法保存。', 503);
            return true;
        });
    }

    /** Issue a short-lived probe; optional generation fences an asynchronous browser operation. */
    public static function beginProbe(string $connectorName, ?string $expectedGeneration = null): string
    {
        Schema::initialize();
        $token = 'probe_' . rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
        $now = time();
        $generation = $expectedGeneration ?? self::runtimeGeneration();
        $updated = SDB(null, 'medoo', 'sqlite')->table('gateway_connector_state')->where('id', 1)
            ->where('runtime_generation', $generation)->update([
            'connector_name' => $connectorName,
            'status' => 'unverified',
            'verified_at' => null,
            'last_error' => null,
            'probe_token_hash' => hash('sha256', $token),
            // Keep the token valid through input insertion, sending and the configured wait.
            'probe_expires_at' => $now + self::probeTimeoutSeconds() + 15,
            'probe_started_at' => null,
            'probe_generation' => $generation,
            'updated_at' => $now,
        ]);
        if (!$updated || $updated->rowCount() !== 1) throw new GatewayError('CHATGPT_CONNECTOR_RUNTIME_CHANGED', '连接器状态发生变化，运行时探针未保存。', 409);
        return $token;
    }

    /** Accept only the low-risk start half of a probe issued by this local database. */
    public static function acceptProbe(string $token): array
    {
        if (!preg_match('/\Aprobe_[A-Za-z0-9_-]{32}\z/', $token)) {
            throw new GatewayError('mcp_probe_invalid', '连接器运行时探针无效。', 404);
        }
        Schema::initialize();
        return Schema::transaction(function () use ($token): array {
            $row = self::row();
            $expected = is_string($row['probe_token_hash'] ?? null) ? (string) $row['probe_token_hash'] : '';
            if ($expected === '' || !hash_equals($expected, hash('sha256', $token))) {
                throw new GatewayError('mcp_probe_invalid', '连接器运行时探针无效。', 404);
            }
            if (!hash_equals((string) $row['runtime_generation'], (string) ($row['probe_generation'] ?? ''))) {
                throw new GatewayError('mcp_probe_expired', '工具连接已重建，旧探针不能确认当前连接。', 409);
            }
            if ((int) ($row['probe_expires_at'] ?? 0) < time()) {
                throw new GatewayError('mcp_probe_expired', '连接器运行时探针已经过期。', 409);
            }
            $duplicate = ($row['probe_started_at'] ?? null) !== null;
            if (!$duplicate) {
                $now = time();
                $updated = SDB(null, 'medoo', 'sqlite')->table('gateway_connector_state')
                    ->where('id', 1)->where('probe_token_hash', $expected)->update([
                        'status' => 'unverified',
                        'verified_at' => null,
                        'probe_started_at' => $now,
                        'last_error' => null,
                        'updated_at' => $now,
                    ]);
                if ($updated === false) throw new GatewayError('STORAGE_UNAVAILABLE', '连接器运行时探针回执无法保存。', 503);
            }
            GatewayUsage::probeAccepted($token);
            return ['started' => true, 'duplicate' => $duplicate];
        });
    }

    /** Verify runtime reachability and command-class action permission through a no-side-effect probe. */
    public static function acceptPermissionProbe(string $token): array
    {
        if (!preg_match('/\Aprobe_[A-Za-z0-9_-]{32}\z/', $token)) {
            throw new GatewayError('mcp_probe_invalid', '连接器运行时探针无效。', 404);
        }
        Schema::initialize();
        return Schema::transaction(function () use ($token): array {
            $row = self::row();
            $expected = is_string($row['probe_token_hash'] ?? null) ? (string) $row['probe_token_hash'] : '';
            if ($expected === '' || !hash_equals($expected, hash('sha256', $token))) {
                throw new GatewayError('mcp_probe_invalid', '连接器运行时探针无效。', 404);
            }
            if (!hash_equals((string) $row['runtime_generation'], (string) ($row['probe_generation'] ?? ''))) {
                throw new GatewayError('mcp_probe_expired', '工具连接已重建，旧探针不能确认当前连接。', 409);
            }
            if ((int) ($row['probe_expires_at'] ?? 0) < time()) {
                throw new GatewayError('mcp_probe_expired', '连接器运行时探针已经过期。', 409);
            }
            $duplicate = (string) $row['status'] === 'verified' && ($row['verified_at'] ?? null) !== null;
            if (!$duplicate) {
                $now = time();
                $updated = SDB(null, 'medoo', 'sqlite')->table('gateway_connector_state')
                    ->where('id', 1)->where('probe_token_hash', $expected)->update([
                        'status' => 'verified',
                        'verified_at' => $now,
                        'verified_generation' => (string) $row['runtime_generation'],
                        'recheck_required' => 0,
                        'probe_started_at' => $row['probe_started_at'] ?? $now,
                        'last_error' => null,
                        'updated_at' => $now,
                    ]);
                if ($updated === false) throw new GatewayError('STORAGE_UNAVAILABLE', '连接器权限探针回执无法保存。', 503);
            }
            GatewayUsage::probeAccepted($token);
            return ['ok' => true, 'permission_verified' => true, 'duplicate' => $duplicate];
        });
    }

    /** Read probe state without exposing the token/hash to the console. */
    public static function probeStatus(string $connectorName, string $token): array
    {
        Schema::initialize();
        $row = self::row();
        $matches = hash_equals((string) $row['connector_name'], $connectorName);
        $hashMatches = is_string($row['probe_token_hash'] ?? null)
            && hash_equals((string) $row['probe_token_hash'], hash('sha256', $token));
        return [
            'accepted' => $matches && $hashMatches && ($row['probe_started_at'] ?? null) !== null && (string) $row['status'] === 'verified',
            'started' => $matches && $hashMatches && ($row['probe_started_at'] ?? null) !== null,
            'expired' => !$matches || !$hashMatches || (int) ($row['probe_expires_at'] ?? 0) < time(),
        ];
    }

    /** Save a terminal check failure, rejecting stale asynchronous callbacks when fenced. */
    public static function failed(string $connectorName, string $message, ?string $expectedGeneration = null): void
    {
        self::write($connectorName, 'error', null, mb_substr($message, 0, 500), true, $expectedGeneration);
    }

    /** Clear an old probe without clearing an in-progress runtime recheck. */
    public static function invalidate(string $connectorName, ?string $expectedGeneration = null): void
    {
        self::write($connectorName, 'unverified', null, null, false, $expectedGeneration);
    }

    /** Forget connector proof from the previous ChatGPT account and fence its asynchronous callbacks. */
    public static function resetAfterProfileClear(string $connectorName): void
    {
        $updated = SDB(null, 'medoo', 'sqlite')->table('gateway_connector_state')->where('id', 1)->update([
            'connector_name' => $connectorName,
            'status' => 'unverified',
            'verified_at' => null,
            'runtime_generation' => bin2hex(random_bytes(16)),
            'verified_generation' => null,
            'probe_generation' => null,
            'probe_token_hash' => null,
            'probe_expires_at' => null,
            'probe_started_at' => null,
            'recheck_required' => 0,
            'recheck_command_id' => null,
            'permission_verified_at' => null,
            'permission_account_key' => null,
            'last_error' => null,
            'updated_at' => time(),
        ]);
        if ($updated === false) throw new GatewayError('STORAGE_UNAVAILABLE', '连接器验证状态无法重置。', 503);
    }

    private static function write(string $connectorName, string $status, ?int $verifiedAt, ?string $lastError, bool $stopRecheck = false, ?string $expectedGeneration = null): void
    {
        Schema::initialize();
        $values = [
            'connector_name' => $connectorName,
            'status' => $status,
            'verified_at' => $verifiedAt,
            'last_error' => $lastError,
            'probe_token_hash' => null,
            'probe_expires_at' => null,
            'probe_started_at' => null,
            'probe_generation' => null,
            'verified_generation' => null,
            'updated_at' => time(),
        ];
        if ($stopRecheck) $values['recheck_required'] = 0;
        $query = SDB(null, 'medoo', 'sqlite')->table('gateway_connector_state')->where('id', 1);
        if ($expectedGeneration !== null) $query->where('runtime_generation', $expectedGeneration);
        $updated = $query->update($values);
        if ($updated === false) throw new GatewayError('STORAGE_UNAVAILABLE', '连接器检查状态无法保存。', 503);
        if ($expectedGeneration !== null && $updated->rowCount() !== 1) {
            throw new GatewayError('CHATGPT_CONNECTOR_RUNTIME_CHANGED', '工具连接已变化，旧检查未覆盖当前状态。', 409);
        }
    }

    private static function row(): array
    {
        $row = SDB(null, 'medoo', 'sqlite')->table('gateway_connector_state')->where('id', 1)->find();
        if (!is_array($row)) throw new GatewayError('STORAGE_UNAVAILABLE', '连接器检查状态暂不可读取。', 503);
        return $row;
    }
}
