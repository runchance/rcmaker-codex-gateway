<?php
declare(strict_types=1);
namespace app\gateway\model;

use RC\Config;
use app\gateway\database\Schema;

/** A bounded SQLite-backed network diagnostic. HEAD confirms the TLS route, never model or login readiness. */
final class NetworkProbe
{
    /** Capture the selected saved or actual applied pointer; stale selections cannot use a different revision silently. @return int @throws GatewayError */
    public static function enqueue(int $revision, string $targetId, string $source = 'saved'): int
    {
        $targets = Config::get('gateway', 'probe_targets');
        if (!isset($targets[$targetId])) {
            throw new GatewayError('INVALID_TARGET', '不支持此诊断目标。');
        }
        if (!in_array($source, ['saved', 'active'], true)) {
            throw new GatewayError('INVALID_SOURCE', '请选择已保存或已应用的配置。');
        }
        return Schema::transaction(function () use ($revision, $targetId, $source): int {
            $state = NetworkSettings::state();
            $selected = $source === 'active' ? $state['applied_php_revision'] : $state['saved_revision'];
            if ($selected === null || (int) $selected !== $revision) {
                throw new GatewayError('REVISION_CONFLICT', '所选版本已变化或尚未应用，请重新载入核对。', 409);
            }
            NetworkSettings::usable(NetworkSettings::revision($revision));
            if ((int) $state['probe_heartbeat'] < time() - 15) {
                throw new GatewayError('PROBE_WORKER_UNAVAILABLE', '网络检测进程暂不可用，请检查服务状态后重试。', 503);
            }
            $db = SDB(null, 'medoo', 'sqlite');
            $pending = $db->table('gateway_network_tests')->where('status', 'IN', ['queued', 'running'])->count();
            if ($pending === false || $pending === null) {
                throw new GatewayError('STORAGE_UNAVAILABLE', '无法检查检测队列。', 503);
            }
            if ($pending > 0) {
                throw new GatewayError('PROBE_BUSY', '已有检测正在进行，请等待结果后重试。', 409);
            }
            if (!$db->table('gateway_network_tests')->add(['revision' => $revision, 'target_id' => $targetId, 'status' => 'queued', 'created_at' => time()])) {
                throw new GatewayError('STORAGE_UNAVAILABLE', '无法保存检测请求。', 503);
            }
            return (int) $db->id();
        });
    }

    /** Mark interrupted running calls unconfirmed. Queued calls have not executed and remain eligible until their deadline. */
    public static function recover(): void
    {
        Schema::transaction(function (): void {
            $result = SDB(null, 'medoo', 'sqlite')->table('gateway_network_tests')->where('status', 'running')->update([
                'status' => 'failed', 'finished_at' => time(),
                'result_json' => json_encode(['ok' => false, 'code' => 'PROBE_INTERRUPTED', 'message' => '检测进程重启，上次执行结果未确认，请重新发起检测。', 'origin' => 'gateway', 'stage' => 'worker'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ]);
            if (!$result) {
                throw new GatewayError('STORAGE_UNAVAILABLE', '检测状态恢复失败。', 503);
            }
        });
    }

    /** Execute at most one queued diagnostic, recording terminal state after network I/O. @return void @throws \Throwable */
    public static function tick(): void
    {
        Schema::initialize();
        $job = Schema::transaction(function (): ?array {
            NetworkSettings::writeState(['probe_heartbeat' => time()]);
            $db = SDB(null, 'medoo', 'sqlite');
            // A prior result commit may have failed under disk/lock pressure. Do not replay its network call.
            $expired = $db->table('gateway_network_tests')->where('status', 'running')->where('started_at', '<', time() - 30)->update([
                'status' => 'failed', 'finished_at' => time(),
                'result_json' => json_encode(['ok' => false, 'code' => 'PROBE_RESULT_UNCONFIRMED', 'message' => '上次检测结果未能确认，请重新检测。', 'origin' => 'gateway', 'stage' => 'persistence'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ]);
            if (!$expired) {
                throw new GatewayError('STORAGE_UNAVAILABLE', '无法恢复检测记录。', 503);
            }
            $row = $db->table('gateway_network_tests')->where('status', 'queued')->order([['id', 'ASC']])->find();
            if ($row === false) {
                throw new GatewayError('STORAGE_UNAVAILABLE', '无法读取检测队列。', 503);
            }
            if (!$row) {
                return null;
            }
            $claimed = $db->table('gateway_network_tests')->where('id', $row['id'])->where('status', 'queued')->update(['status' => 'running', 'started_at' => time()]);
            if (!$claimed || $claimed->rowCount() !== 1) {
                throw new GatewayError('STORAGE_UNAVAILABLE', '无法认领检测请求。', 503);
            }
            return $row;
        });
        if ($job === null) {
            return;
        }
        try {
            if ((int) $job['created_at'] < time() - Config::get('gateway', 'probe_queue_timeout_seconds')) {
                throw new GatewayError('PROBE_EXPIRED', '检测请求等待过久，请重新发起。');
            }
            $result = self::perform(NetworkSettings::revision((int) $job['revision']), $job['target_id']);
        } catch (GatewayError $error) {
            $result = ['ok' => false, 'code' => $error->errorCode, 'message' => $error->getMessage(), 'origin' => 'gateway', 'stage' => 'preflight'];
        } catch (\Throwable $error) {
            $result = ['ok' => false, 'code' => 'PROBE_FAILED', 'message' => '检测未能完成，请检查服务状态。', 'origin' => 'gateway', 'stage' => 'probe'];
        }
        Schema::transaction(function () use ($job, $result): void {
            $db = SDB(null, 'medoo', 'sqlite');
            $committed = $db->table('gateway_network_tests')->where('id', $job['id'])->where('status', 'running')->update([
                'status' => !empty($result['ok']) ? 'succeeded' : 'failed',
                'finished_at' => time(), 'result_json' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ]);
            if (!$committed || $committed->rowCount() !== 1) {
                throw new GatewayError('STORAGE_UNAVAILABLE', '检测结果保存失败。', 503);
            }
            NetworkSettings::writeState(['probe_heartbeat' => time()]);
            // Keep bounded diagnostic history; settings revisions are never silently deleted.
            $cutoff = max(0, (int) $job['id'] - 100);
            $db->table('gateway_network_tests')->where('id', '<', $cutoff)->where('status', 'IN', ['succeeded', 'failed'])->delete();
        });
    }

    /**
     * Resolve an immutable revision into a framework Curl HEAD call.
     * Any target HTTP response confirms the network route; target acceptance remains separate
     * metadata. Proxy CONNECT rejection, transport errors and missing responses still fail.
     * @param array $revision Immutable network settings; protected credentials stay private.
     * @param string $targetId Deployment-owned target ID, never a caller-supplied URL.
     * @return array Safe diagnostic only; never raw response, headers or model readiness.
     * @throws GatewayError
     */
    public static function perform(array $revision, string $targetId): array
    {
        NetworkSettings::usable($revision);
        $targets = Config::get('gateway', 'probe_targets');
        $url = $targets[$targetId]['url'] ?? '';
        $parts = parse_url($url);
        if (!$parts || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            throw new GatewayError('PROBE_TARGET_CONFIG', '服务器诊断目标配置不正确。', 503);
        }
        $client = curl();
        try {
            $route = NetworkTransport::configure($client, $revision, $url, (int) Config::get('gateway', 'probe_timeout_seconds'), 4, false);
            $loopback = $route['loopback'];
            $viaProxy = $route['via_proxy'];
            $started = microtime(true);
            $client->head($url);
            $errno = (int) $client->curlErrorCode;
            $http = (int) $client->httpStatusCode;
            $connect = (int) $client->getInfo(CURLINFO_HTTP_CONNECTCODE);
            $tunnelEstablished = !$viaProxy || ($connect >= 200 && $connect < 300);
            $tlsVerified = $errno === 0 && $http > 0 && $tunnelEstablished;
            $result = [
                'revision' => (int) $revision['id'], 'consumer' => 'php', 'target' => $targetId,
                'route' => $viaProxy ? 'manual_http_connect' : ($loopback ? 'loopback_direct' : 'explicit_direct'),
                'elapsed_ms' => (int) round((microtime(true) - $started) * 1000),
                'http_status' => $http, 'connect_status' => $connect, 'curl_code' => $errno,
                'model_ready' => false, 'network_reachable' => $tlsVerified,
                'tls_verified' => $tlsVerified, 'target_http_accepted' => $tlsVerified && $http >= 200 && $http < 400,
            ];
            if ($connect === 407 || ($viaProxy && !$tunnelEstablished && $http === 407)) {
                return $result + ['ok' => false, 'code' => 'PROXY_AUTH_FAILED', 'message' => '代理认证失败，请检查用户名和密码。', 'origin' => 'proxy', 'stage' => 'authentication'];
            }
            if ($viaProxy && $connect > 0 && ($connect < 200 || $connect >= 300)) {
                return $result + ['ok' => false, 'code' => 'PROXY_TUNNEL_REJECTED', 'message' => '代理返回 HTTP ' . $connect . '，未能建立目标隧道。', 'origin' => 'proxy', 'stage' => 'connect'];
            }
            if ($errno !== 0) {
                [$code, $message, $origin, $stage] = match ($errno) {
                    5 => ['PROXY_DNS_FAILED', '无法解析代理服务器地址。', 'proxy', 'dns'],
                    6 => ['TARGET_DNS_FAILED', '无法解析检测目标地址。', 'target', 'dns'],
                    7 => [$viaProxy ? 'PROXY_CONNECT_FAILED' : 'TARGET_CONNECT_FAILED', '无法建立连接，请检查地址、端口及代理是否启动。', $viaProxy ? 'proxy' : 'target', 'connect'],
                    28 => ['PROBE_TIMEOUT', '连接或响应超时，已结束本次检测。', 'unknown', 'timeout'],
                    35, 51, 58, 60, 77 => ['TARGET_TLS_FAILED', 'TLS 握手或证书验证失败；未关闭证书校验。', 'tls', 'tls'],
                    default => ['NETWORK_FAILED', '网络检测失败，请结合错误代码检查通路。', 'unknown', 'network'],
                };
                return $result + ['ok' => false, 'code' => $code, 'message' => $message, 'origin' => $origin, 'stage' => $stage];
            }
            if (!$tlsVerified) {
                return $result + ['ok' => false, 'code' => 'NETWORK_FAILED', 'message' => '未收到目标的 HTTP 响应，请重新检测。', 'origin' => 'target', 'stage' => 'http'];
            }
            return $result + [
                'ok' => true, 'code' => 'TARGET_REACHABLE',
                'message' => '网络连接已确认；模型登录与工具能力仍需在内置浏览器中验证。',
                'origin' => 'target', 'stage' => 'http',
            ];
        } finally {
            $client->close();
        }
    }
}
