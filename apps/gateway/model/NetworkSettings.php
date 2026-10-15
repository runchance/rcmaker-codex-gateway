<?php
declare(strict_types=1);
namespace app\gateway\model;

use RC\Config;
use RC\Request;
use app\gateway\database\Schema;
use app\gateway\model\BrowserState;

/** Versioned network settings. Immutable revisions and applied pointers change atomically; network I/O stays outside transactions. */
final class NetworkSettings
{
    /** Read a coherent pointer and its immutable settings plus a bounded history. @param Request $request Current request only. @return array */
    public static function snapshot(Request $request): array
    {
        $state = self::state();
        $saved = self::revision((int) $state['saved_revision']);
        $history = $request->AF([
            'type' => 'list', 'table' => 'gateway_network_revisions', 'name' => '网络配置',
            'db' => $request->SDB('medoo', 'sqlite'), 'order' => [['id', 'DESC']], 'limit' => 20,
            'fields' => 'id,mode,protocol,host,port,username,browser_timezone,restored_from,created_at',
        ])->handle();
        $tests = $request->AF([
            'type' => 'list', 'table' => 'gateway_network_tests', 'name' => '最近检测',
            'db' => $request->SDB('medoo', 'sqlite'), 'order' => [['id', 'DESC']], 'limit' => 1,
        ])->handle();
        if (!is_array($history) || !is_array($tests)) {
            throw new GatewayError('STORAGE_UNAVAILABLE', '暂时无法读取配置历史或检测结果。', 503);
        }
        $lastTest = $tests[0] ?? null;
        if ($lastTest !== null) {
            $lastTest['result'] = $lastTest['result_json'] === null ? null : json_decode($lastTest['result_json'], true, 32, JSON_THROW_ON_ERROR);
            unset($lastTest['result_json']);
        }
        $active = $state['applied_php_revision'] === null ? null : self::revision((int) $state['applied_php_revision']);
        $status = $active === null ? 'not_applied' : ((int) $active['id'] === (int) $saved['id'] ? 'applied' : 'pending');
        if ((int) ($state['last_apply_revision'] ?? 0) === (int) $saved['id'] && $state['last_apply_error'] !== null) {
            $status = 'apply_failed';
        }
        $browserState = BrowserState::state();
        $browserRevision = $browserState['network_revision'] === null ? null : (int) $browserState['network_revision'];
        $browserStatus = 'not_applied';
        if ($browserState['status'] === 'ready' && $browserRevision !== null) {
            $browserStatus = $browserRevision === (int) $saved['id'] ? 'applied' : 'pending';
        } elseif ($browserState['status'] === 'error' && $browserRevision !== null) {
            $browserStatus = 'apply_failed';
        }
        try {
            $tunnel = TunnelRuntime::snapshot();
            $tunnelRevision = $tunnel['network_revision'];
            $tunnelStatus = match ((string) $tunnel['status']) {
                'ready' => $tunnelRevision === (int) $saved['id'] ? 'applied' : 'pending',
                'error' => 'apply_failed',
                'starting' => 'pending',
                default => 'not_applied',
            };
            $tunnelError = $tunnel['last_error'];
        } catch (\Throwable) {
            $tunnelRevision = null;
            $tunnelStatus = 'apply_failed';
            $tunnelError = 'TUNNEL_STATUS_UNAVAILABLE';
        }
        return [
            'saved' => self::publicRevision($saved),
            'browser_timezones' => \DateTimeZone::listIdentifiers(),
            'active' => $active === null ? null : self::publicRevision($active),
            'history' => $history,
            'last_test' => $lastTest,
            'credentials' => ['storage' => ProxyCredential::available() ? 'project_secretbox' : 'unavailable', 'password_returned' => false],
            'consumers' => [
                ['id' => 'php', 'label' => 'PHP 出站检测', 'status' => $status, 'applied_revision' => $state['applied_php_revision'], 'error' => $state['last_apply_error']],
                ['id' => 'browser', 'label' => '受控浏览器', 'status' => $browserStatus, 'applied_revision' => $browserRevision, 'error' => $browserState['last_error']],
                ['id' => 'tunnel', 'label' => '工具隧道', 'status' => $tunnelStatus, 'applied_revision' => $tunnelRevision, 'error' => $tunnelError],
                // Runtime downloads use NetworkTransport with this exact applied immutable revision.
                // They have no long-lived child process of their own, so their state follows the
                // PHP outbound pointer instead of maintaining a second revision pointer.
                ['id' => 'download', 'label' => '下载与更新', 'status' => $status, 'applied_revision' => $state['applied_php_revision'], 'error' => $state['last_apply_error']],
            ],
            'probe_worker_ready' => (int) $state['probe_heartbeat'] >= time() - 15,
        ];
    }

    /** Save a new revision with optimistic concurrency. @param array $input Validated below; password never persisted in cleartext. @return int @throws GatewayError */
    public static function save(#[\SensitiveParameter] array $input): int
    {
        $data = self::validate($input);
        $current = self::revision((int) self::state()['saved_revision']);
        $protected = null;
        if ($data['mode'] === 'manual' && $data['password_action'] === 'set') {
            // OS calls must finish before obtaining the SQLite writer lock.
            $protected = ProxyCredential::protect($data['password']);
        } elseif ($data['mode'] === 'manual' && $data['password_action'] === 'keep' && $current['protected_password'] !== null) {
            foreach (['protocol', 'host', 'port', 'username'] as $field) {
                if ((string) $data[$field] !== (string) $current[$field]) {
                    throw new GatewayError('CREDENTIAL_REENTRY_REQUIRED', '更换代理地址或用户名后，请重新输入密码。');
                }
            }
            $protected = $current['protected_password'];
        }
        if ($data['username'] !== '' && $protected === null) {
            throw new GatewayError('CREDENTIAL_REQUIRED', '填写代理用户名后，需要同时提供密码。');
        }
        $row = array_intersect_key($data, array_flip(['mode', 'protocol', 'host', 'port', 'username', 'browser_timezone']));
        // Older console clients must not silently erase a timezone chosen in a newer client.
        $row['browser_timezone'] ??= $current['browser_timezone'];
        $row['protected_password'] = $protected;
        $row['restored_from'] = null;
        return Schema::transaction(function () use ($data, $row): int {
            self::expectRevision($data['expected_revision']);
            return self::insertRevision($row);
        });
    }

    /** Apply the outbound revision and atomically reserve an idle browser restart when requested by the console.
     * @param int $expectedRevision Saved immutable revision.
     * @param bool $restartBrowser Reinitialize a running private browser using the same revision.
     * @param int|null $browserCommandId Receives the durable command to observe; null if no browser is running.
     * @return int Applied revision. @throws GatewayError Busy browsers leave the applied pointer unchanged.
     */
    public static function apply(int $expectedRevision, bool $restartBrowser = false, ?int &$browserCommandId = null): int
    {
        self::expectRevision($expectedRevision);
        $revision = self::revision($expectedRevision);
        try {
            self::usable($revision);
            if ($revision['protected_password'] !== null) {
                $secret = ProxyCredential::reveal($revision['protected_password']);
                unset($secret);
            }
        } catch (GatewayError $error) {
            Schema::transaction(function () use ($expectedRevision, $error): void {
                self::expectRevision($expectedRevision);
                self::writeState(['last_apply_revision' => $expectedRevision, 'last_apply_error' => $error->errorCode]);
            });
            throw $error;
        }
        return Schema::transaction(function () use ($expectedRevision, $restartBrowser, &$browserCommandId): int {
            self::expectRevision($expectedRevision);
            $browserCommandId = $restartBrowser ? BrowserState::restartForNetworkUnlocked($expectedRevision) : null;
            self::writeState(['applied_php_revision' => $expectedRevision, 'last_apply_revision' => $expectedRevision, 'last_apply_error' => null]);
            return $expectedRevision;
        });
    }

    /** Restore an earlier snapshot as a new saved revision; applying remains a separate action. @return int @throws GatewayError */
    public static function restore(int $expectedRevision, int $sourceRevision): int
    {
        return Schema::transaction(function () use ($expectedRevision, $sourceRevision): int {
            self::expectRevision($expectedRevision);
            if ($sourceRevision >= $expectedRevision) {
                throw new GatewayError('INVALID_RESTORE', '请选择比当前配置更早的版本。');
            }
            $row = self::revision($sourceRevision);
            unset($row['id'], $row['created_at']);
            $row['restored_from'] = $sourceRevision;
            return self::insertRevision($row);
        });
    }

    /** Read the aggregate pointer for a transactional workflow, rather than a separate CRUD screen. @return array @throws GatewayError */
    public static function state(): array
    {
        $db = SDB(null, 'medoo', 'sqlite');
        $row = $db->table('gateway_network_state')->where('id', 1)->find();
        if (!is_array($row)) {
            throw new GatewayError('STORAGE_UNAVAILABLE', '网络配置暂不可读取。', 503);
        }
        return $row;
    }

    /** Internal revision lookup used by the aggregate/worker. Never return this row directly over HTTP. @return array @throws GatewayError */
    public static function revision(int $id): array
    {
        $db = SDB(null, 'medoo', 'sqlite');
        $row = $db->table('gateway_network_revisions')->where('id', $id)->find();
        if ($row === false) {
            throw new GatewayError('STORAGE_UNAVAILABLE', '网络配置暂不可读取。', 503);
        }
        if (!is_array($row)) {
            throw new GatewayError('REVISION_NOT_FOUND', '指定配置版本不存在。', 404);
        }
        return $row;
    }

    /** Require the caller's observed saved version before a mutation. @throws GatewayError */
    public static function expectRevision(int $expected): void
    {
        if ((int) self::state()['saved_revision'] !== $expected) {
            throw new GatewayError('REVISION_CONFLICT', '配置已在其他页面更新，请重新载入后操作。', 409);
        }
    }

    /** Validate a saved record before assigning it to an implemented consumer. @throws GatewayError */
    public static function usable(array $row): void
    {
        if ($row['mode'] === 'unconfigured') {
            throw new GatewayError('NETWORK_UNCONFIGURED', '请先选择手动代理或明确选择直连。', 422);
        }
        if ($row['mode'] === 'manual' && $row['protocol'] !== 'http') {
            throw new GatewayError('PROXY_UNSUPPORTED', '本阶段 PHP 出站仅开放 HTTP CONNECT 代理。', 422);
        }
    }

    /** Update the singleton inside the owning transaction. @throws GatewayError */
    public static function writeState(array $values): void
    {
        $result = SDB(null, 'medoo', 'sqlite')->table('gateway_network_state')->where('id', 1)->update($values);
        if (!$result || $result->rowCount() !== 1) {
            throw new GatewayError('STORAGE_UNAVAILABLE', '网络配置写入失败，原配置已保留。', 503);
        }
    }

    /** Remove protected material and expose only password presence. @return array */
    private static function publicRevision(array $row): array
    {
        $row['has_password'] = $row['protected_password'] !== null;
        unset($row['protected_password']);
        return $row;
    }

    /** Insert a revision and publish its pointer within one writer transaction. @return int @throws GatewayError */
    private static function insertRevision(array $row): int
    {
        $db = SDB(null, 'medoo', 'sqlite');
        $count = $db->table('gateway_network_revisions')->count();
        if ($count === false || $count === null) {
            throw new GatewayError('STORAGE_UNAVAILABLE', '无法读取配置历史数量。', 503);
        }
        if ($count >= Config::get('gateway', 'max_revisions')) {
            throw new GatewayError('REVISION_LIMIT', '配置历史已达上限，暂不能新增；已有配置仍可使用。', 409);
        }
        $row['created_at'] = time();
        if (!$db->table('gateway_network_revisions')->add($row)) {
            throw new GatewayError('STORAGE_UNAVAILABLE', '保存失败，原配置已保留。', 503);
        }
        $id = (int) $db->id();
        if ($id < 1) {
            throw new GatewayError('STORAGE_UNAVAILABLE', '保存未取得有效版本，原配置已保留。', 503);
        }
        self::writeState(['saved_revision' => $id]);
        return $id;
    }

    /** Validate the request using RC Validator, then enforce protocol/authentication relationships. @return array @throws GatewayError */
    private static function validate(#[\SensitiveParameter] array $input): array
    {
        $fields = ['expected_revision', 'mode', 'protocol', 'host', 'port', 'username', 'password', 'password_action', 'browser_timezone'];
        if (array_diff(array_keys($input), $fields)) {
            throw new GatewayError('INVALID_FIELD', '请求包含不支持的设置字段。');
        }
        $input += ['protocol' => 'http', 'host' => '', 'port' => 0, 'username' => '', 'password' => '', 'password_action' => 'keep'];
        try {
            $data = validator()->input($input, [
                'expected_revision' => ['rule' => 'pint', 'name' => '配置版本'],
                'mode' => ['rule' => 'string', 'len' => [1, 16], 'name' => '网络模式'],
                'protocol' => ['rule' => 'string', 'len' => [1, 16], 'name' => '代理协议'],
                'host' => ['rule' => 'string', 'len' => [0, 253], 'required' => false, 'name' => '代理地址'],
                'port' => ['rule' => 'nnint', 'range' => [0, 65535], 'name' => '端口'],
                'username' => ['rule' => 'string', 'len' => [0, 128], 'required' => false, 'name' => '用户名'],
                'password' => ['rule' => 'string', 'len' => [0, 256], 'required' => false, 'name' => '密码'],
                'password_action' => ['rule' => 'string', 'len' => [1, 8], 'name' => '密码操作'],
                'browser_timezone' => ['rule' => 'string', 'len' => [1, 80], 'required' => false, 'name' => '浏览器时区'],
            ]);
        } catch (\Throwable $error) {
            throw new GatewayError('INVALID_SETTINGS', '请检查配置版本、地址、端口和各字段长度。');
        }
        // Validator omits optional null values. Normalize them before applying protocol relationships.
        $data += ['host' => '', 'username' => '', 'password' => ''];
        if (array_key_exists('browser_timezone', $input)
            && (!isset($data['browser_timezone']) || (!in_array($data['browser_timezone'], ['auto', 'system'], true)
                && !\support\browser\BrowserTimezone::valid($data['browser_timezone'])))) {
            throw new GatewayError('BROWSER_TIMEZONE_INVALID', '请选择有效的浏览器时区。');
        }
        $data['expected_revision'] = (int) $data['expected_revision'];
        $data['port'] = (int) $data['port'];
        $data['host'] = trim((string) $data['host']);
        $data['username'] = (string) $data['username'];
        $data['password'] = (string) $data['password'];
        if (!in_array($data['mode'], ['manual', 'direct'], true) || !in_array($data['password_action'], ['keep', 'set', 'clear'], true)) {
            throw new GatewayError('INVALID_SETTINGS', '网络模式或密码操作不受支持。');
        }
        if ($data['protocol'] !== 'http') {
            throw new GatewayError('PROXY_UNSUPPORTED', '本阶段只开放 HTTP CONNECT；HTTPS 代理、SOCKS5 与系统代理尚未接入。', 422);
        }
        if ($data['mode'] === 'direct') {
            return array_replace($data, ['host' => '', 'port' => 0, 'username' => '', 'password' => '', 'password_action' => 'clear']);
        }
        if (str_starts_with($data['host'], '[') && str_ends_with($data['host'], ']')) {
            $data['host'] = substr($data['host'], 1, -1);
        }
        $validHost = false;
        foreach (['ip', 'ipv6'] as $hostRule) {
            if (validator()->check($data['host'], ['rule' => $hostRule], static function (): void {}) !== false) {
                $validHost = true;
                break;
            }
        }
        if (!$validHost) {
            // Validator's domain rule does not enforce hostname label grammar; add that protocol constraint.
            $validHost = validator()->check($data['host'], ['rule' => 'domain'], static function (): void {}) !== false
                && preg_match('/\A(?=.{1,253}\z)[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?(?:\.[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)*\.?\z/', $data['host']) === 1;
        }
        if ($data['host'] === '' || $data['port'] < 1 || !$validHost) {
            throw new GatewayError('INVALID_PROXY_ADDRESS', '请输入有效的主机名或 IP，以及 1–65535 范围内的端口。');
        }
        if (preg_match('/[\x00-\x20\x7f\/@?#]/', $data['host']) || preg_match('/[\x00-\x1f\x7f:]/', $data['username']) || str_contains($data['password'], "\0") || strlen($data['password']) > 1024) {
            throw new GatewayError('INVALID_SETTINGS', '代理字段包含不支持的字符。');
        }
        if ($data['password_action'] === 'set' && ($data['password'] === '' || $data['username'] === '')) {
            throw new GatewayError('CREDENTIAL_REQUIRED', '认证代理需要同时填写用户名和密码。');
        }
        if ($data['password_action'] === 'clear') {
            $data['username'] = '';
        }
        return $data;
    }
}
