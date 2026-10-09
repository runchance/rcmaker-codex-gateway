<?php
declare(strict_types=1);
namespace app\gateway\model;

use app\gateway\database\Schema;
use support\browser\adapter\WebModelAdapterInterface;
use support\browser\adapter\WebModelAdapterRegistry;

/** Evidence-backed, versioned capability matrix for replaceable Web-model adapters. */
final class AdapterCapabilities
{
    public const STALE_AFTER_SECONDS = 2592000;

    /** @param class-string<WebModelAdapterInterface>|null $adapter */
    public static function matrix(?string $adapter = null): array
    {
        Schema::initialize();
        $adapter ??= WebModelAdapterRegistry::current();
        self::declare($adapter);
        $rows = SDB(null, 'medoo', 'sqlite')->table('gateway_adapter_capabilities')
            ->where('adapter_id', $adapter::id())->where('adapter_version', $adapter::version())
            ->order([['capability', 'ASC']])->select();
        if ($rows === false) throw new GatewayError('storage_unavailable', 'Adapter 能力矩阵暂不可读取。', 503);
        $capabilities = [];
        foreach ($rows as $row) {
            $status = (string) ($row['status'] ?? 'unknown');
            $observedAt = (int) ($row['observed_at'] ?? 0);
            if (in_array($status, ['verified', 'unavailable'], true) && $observedAt > 0
                && time() - $observedAt > self::STALE_AFTER_SECONDS) $status = 'stale';
            try { $evidence = json_decode((string) ($row['evidence_json'] ?? '{}'), true, 32, JSON_THROW_ON_ERROR); }
            catch (\Throwable) { $evidence = []; }
            $capabilities[(string) $row['capability']] = [
                'status' => $status,
                'observed_at' => $observedAt,
                'evidence' => is_array($evidence) ? $evidence : [],
            ];
        }
        return [
            'adapter_id' => $adapter::id(),
            'adapter_version' => $adapter::version(),
            'provider' => $adapter::provider(),
            'capabilities' => $capabilities,
        ];
    }

    /** @param class-string<WebModelAdapterInterface>|null $adapter */
    public static function observe(string $capability, string $status, array $evidence = [], ?string $adapter = null): void
    {
        Schema::initialize();
        $adapter ??= WebModelAdapterRegistry::current();
        self::declare($adapter);
        if (!in_array($capability, $adapter::declaredCapabilities(), true)) {
            throw new GatewayError('adapter_capability_unknown', 'Adapter 未声明该能力：' . $capability, 500);
        }
        if (!in_array($status, ['verified', 'unavailable', 'unknown', 'stale'], true)) {
            throw new GatewayError('adapter_capability_status_invalid', 'Adapter 能力状态无效。', 500);
        }
        $payload = json_encode($evidence, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $updated = SDB(null, 'medoo', 'sqlite')->table('gateway_adapter_capabilities')
            ->where('adapter_id', $adapter::id())->where('adapter_version', $adapter::version())
            ->where('capability', $capability)->update([
                'status'=>$status, 'evidence_json'=>$payload, 'observed_at'=>$status === 'unknown' ? 0 : time(),
            ]);
        if ($updated === false) throw new GatewayError('storage_unavailable', 'Adapter 能力证据无法保存。', 503);
    }

    /** @param class-string<WebModelAdapterInterface> $adapter */
    private static function declare(string $adapter): void
    {
        $db = SDB(null, 'medoo', 'sqlite');
        foreach ($adapter::declaredCapabilities() as $capability) {
            $existing = $db->table('gateway_adapter_capabilities')
                ->where('adapter_id', $adapter::id())->where('adapter_version', $adapter::version())
                ->where('capability', $capability)->find();
            if (is_array($existing)) continue;
            if (!$db->table('gateway_adapter_capabilities')->add([
                'adapter_id'=>$adapter::id(), 'adapter_version'=>$adapter::version(), 'capability'=>$capability,
                'status'=>'unknown', 'evidence_json'=>'{}', 'observed_at'=>0,
            ])) throw new GatewayError('storage_unavailable', 'Adapter 能力矩阵无法初始化。', 503);
        }
    }
}
