<?php
declare(strict_types=1);
namespace app\gateway\controller;

use RC\Request;
use app\gateway\database\Schema;
use app\gateway\model\BrowserState;
use app\gateway\model\GatewayError;
use app\gateway\model\NetworkProbe;
use app\gateway\model\NetworkSettings;

/** Thin console endpoints: validation, domain calls and safe framework JSON responses. No model/browser connection is performed here. */
final class Network
{
    /** @param Request $request Current request. @return mixed Local readiness response, without an external request. */
    public function status(Request $request)
    {
        return $this->respond($request, fn() => [
            'storage' => 'sqlite', 'schema_version' => 10, 'console_login_required' => false,
            'probe_worker_ready' => (int) NetworkSettings::state()['probe_heartbeat'] >= time() - 15,
            'browser_worker_ready' => (int) BrowserState::state()['worker_heartbeat'] >= time() - 5,
        ]);
    }

    /** @param Request $request Current request. @return mixed Saved/active revision and bounded history. */
    public function read(Request $request)
    {
        return $this->respond($request, fn() => NetworkSettings::snapshot($request));
    }

    /** @param Request $request Current request. @return mixed New saved revision, not automatically applied. */
    public function save(Request $request)
    {
        return $this->respond($request, fn() => ['revision' => NetworkSettings::save($this->body($request))]);
    }

    /** @param Request $request Current request. @return mixed Applied revision and optional browser reinitialization command. */
    public function apply(Request $request)
    {
        return $this->respond($request, function () use ($request): array {
            $data = $this->ids($request, ['expected_revision']);
            $commandId = null;
            $revision = NetworkSettings::apply($data['expected_revision'], true, $commandId);
            return ['revision' => $revision, 'browser_command_id' => $commandId];
        });
    }

    /** @param Request $request Current request. @return mixed New revision copied from a historical snapshot. */
    public function restore(Request $request)
    {
        return $this->respond($request, function () use ($request): array {
            $data = $this->ids($request, ['expected_revision', 'source_revision']);
            return ['revision' => NetworkSettings::restore($data['expected_revision'], $data['source_revision'])];
        });
    }

    /** @param Request $request Current request. @return mixed Persisted diagnostic job ID; no HTTP handler waits on the remote target. */
    public function test(Request $request)
    {
        return $this->respond($request, function () use ($request): array {
            $body = $this->body($request);
            if (array_diff(array_keys($body), ['revision', 'target_id', 'source'])) {
                throw new GatewayError('INVALID_FIELD', '检测请求包含不支持的字段。');
            }
            try {
                $data = validator()->input($body + ['source' => 'saved'], [
                    'revision' => ['rule' => 'pint', 'name' => '配置版本'],
                    'target_id' => ['rule' => 'alnum', 'options' => ['attach' => ['_']], 'len' => [1, 40], 'name' => '检测目标'],
                    'source' => ['rule' => 'alpha', 'len' => [1, 8], 'name' => '检测版本来源'],
                ]);
            } catch (\Throwable $error) {
                throw new GatewayError('INVALID_INPUT', '请检查配置版本和检测目标。');
            }
            return ['id' => NetworkProbe::enqueue((int) $data['revision'], $data['target_id'], $data['source']), 'status' => 'queued'];
        }, 202);
    }

    /** @param Request $request Current request. @param string $id Route-bound job ID. @return mixed Safe, persisted diagnostic result. */
    public function testResult(Request $request, $id)
    {
        return $this->respond($request, function () use ($request, $id): array {
            try {
                $id = validator()->check($id, ['rule' => 'pint', 'name' => '检测编号']);
            } catch (\Throwable $error) {
                throw new GatewayError('INVALID_INPUT', '检测编号不正确。');
            }
            $rows = $request->AF([
                'type' => 'list', 'table' => 'gateway_network_tests', 'where' => ['id', '=', (int) $id], 'limit' => 1,
                'db' => $request->SDB('medoo', 'sqlite'), 'name' => '检测结果',
            ])->handle();
            if (!is_array($rows)) {
                throw new GatewayError('STORAGE_UNAVAILABLE', '检测结果暂不可读取。', 503);
            }
            $row = $rows[0] ?? null;
            if ($row === null) {
                throw new GatewayError('PROBE_NOT_FOUND', '检测记录不存在或已按保留策略清理。', 404);
            }
            $row['result'] = $row['result_json'] === null ? null : json_decode($row['result_json'], true, 32, JSON_THROW_ON_ERROR);
            unset($row['result_json']);
            return $row;
        });
    }

    /** @param Request $request Current request. @return mixed Normally answered by the source-checking middleware. */
    public function preflight(Request $request)
    {
        return $request->response('', 204);
    }

    /** Decode only an object-shaped JSON body through the framework Request. @return array @throws GatewayError */
    private function body(Request $request): array
    {
        try {
            $object = json_decode((string) $request->rawBody(), false, 16, JSON_THROW_ON_ERROR);
            if (!$object instanceof \stdClass) {
                throw new \RuntimeException('Object required');
            }
            return get_object_vars($object);
        } catch (\Throwable $error) {
            throw new GatewayError('INVALID_JSON', '请求正文必须是有效的 JSON 对象。');
        }
    }

    /** Validate an exact set of positive identifier fields using RC Validator. @return array<string,int> @throws GatewayError */
    private function ids(Request $request, array $names): array
    {
        $body = $this->body($request);
        if (array_diff(array_keys($body), $names)) {
            throw new GatewayError('INVALID_FIELD', '请求包含不支持的字段。');
        }
        try {
            $rules = array_fill_keys($names, ['rule' => 'pint', 'name' => '配置版本']);
            return array_map('intval', validator()->input($body, $rules));
        } catch (\Throwable $error) {
            throw new GatewayError('INVALID_INPUT', '请提供有效的配置版本。');
        }
    }

    /** Own the synchronous request lifetime and sanitize unexpected storage failures. @return mixed Framework response. */
    private function respond(Request $request, callable $operation, int $successStatus = 200)
    {
        try {
            Schema::initialize();
            return $request->json(['code' => 0, 'data' => $operation()])->withStatus($successStatus);
        } catch (GatewayError $error) {
            return $request->json(['code' => $error->errorCode, 'message' => $error->getMessage()])->withStatus($error->httpStatus);
        } catch (\Throwable $error) {
            return $request->json(['code' => 'STORAGE_UNAVAILABLE', 'message' => '操作未完成，请检查本地数据目录、磁盘空间和服务状态。'])->withStatus(503);
        }
    }
}
