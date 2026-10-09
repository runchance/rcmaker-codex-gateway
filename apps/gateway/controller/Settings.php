<?php
declare(strict_types=1);
namespace app\gateway\controller;

use RC\Request;
use app\gateway\database\Schema;
use app\gateway\model\GatewayError;
use app\gateway\model\GatewaySettings;
use app\gateway\model\ConnectorState;
use app\gateway\model\TunnelRuntime;

/** User-editable local product settings persisted in SQLite. */
final class Settings
{
    public function read(Request $request)
    {
        return $this->respond($request, function (): array {
            $settings = GatewaySettings::snapshot();
            return $settings + [
                'tunnel' => TunnelRuntime::snapshot(),
                'connector' => ConnectorState::snapshot((string) $settings['connector_name']),
            ];
        });
    }

    public function save(Request $request)
    {
        return $this->respond($request, function () use ($request): array {
            $settings = GatewaySettings::save($this->body($request));
            return $settings + [
                'tunnel' => TunnelRuntime::snapshot(),
                'connector' => ConnectorState::snapshot((string) $settings['connector_name']),
            ];
        });
    }

    public function tunnelStatus(Request $request)
    {
        return $this->respond($request, fn(): array => TunnelRuntime::refresh());
    }

    /** Save only language; leave settings and model contracts open in other tabs untouched. */
    public function locale(Request $request)
    {
        return $this->respond($request, function () use ($request): array {
            $input = $this->body($request);
            if (array_diff(array_keys($input), ['ui_locale', 'expected_updated_at'])) {
                throw new GatewayError('INVALID_FIELD', 'Unsupported language preference field.');
            }
            $current = GatewaySettings::snapshot();
            $fields = ['mcp_enabled', 'connector_name', 'mcp_invoke_timeout_ms', 'wait_poll_interval_ms',
                'max_tool_calls_per_turn', 'tool_result_max_bytes', 'tunnel_id'];
            $base = array_intersect_key($current, array_flip($fields));
            $base['mcp_enabled'] = (int) $base['mcp_enabled'];
            return GatewaySettings::save($input + $base);
        });
    }

    public function tunnelPrepare(Request $request)
    {
        return $this->respond($request, fn(): array => TunnelRuntime::prepare());
    }

    public function tunnelConnect(Request $request)
    {
        return $this->respond($request, fn(): array => TunnelRuntime::requestConnect());
    }

    public function tunnelStop(Request $request)
    {
        return $this->respond($request, fn(): array => TunnelRuntime::stop());
    }

    private function body(Request $request): array
    {
        try {
            $object = json_decode((string) $request->rawBody(), false, 16, JSON_THROW_ON_ERROR);
            if (!$object instanceof \stdClass) throw new \RuntimeException('Object required');
            return get_object_vars($object);
        } catch (\Throwable) {
            throw new GatewayError('INVALID_JSON', '请求正文必须是有效的 JSON 对象。');
        }
    }

    private function respond(Request $request, callable $operation)
    {
        try {
            Schema::initialize();
            return $request->json(['code' => 0, 'data' => $operation()]);
        } catch (GatewayError $error) {
            return $request->json(['code' => $error->errorCode, 'message' => $error->getMessage()])->withStatus($error->httpStatus);
        } catch (\Throwable) {
            return $request->json(['code' => 'SETTINGS_UNAVAILABLE', 'message' => '设置暂不可用，请检查本地数据目录和服务状态。'])->withStatus(503);
        }
    }
}
