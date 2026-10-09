<?php
declare(strict_types=1);
namespace app\gateway\controller;

use RC\Request;
use app\gateway\database\Schema;
use app\gateway\model\BrowserState;
use app\gateway\model\CodexIntegration;
use app\gateway\model\GatewayError;

/** Run-preparation actions for account discovery and explicit Codex configuration integration. */
final class Codex
{
    /** Read status without changing a client configuration. */
    public function status(Request $request) { return $this->respond($request, static fn() => CodexIntegration::status()); }
    /** Account and installation usage without opening a browser or consuming a model call. */
    public function usage(Request $request) { return $this->respond($request, static fn() => \app\gateway\model\GatewayUsage::snapshot()); }

    /** Preview only the managed insertion; never expose unrelated user configuration contents. */
    public function preview(Request $request) { return $this->respond($request, static fn() => CodexIntegration::plan()); }

    /** Preview clearing model-route overrides; existing credentials never enter the response. */
    public function nativePreview(Request $request) { return $this->respond($request, static fn() => CodexIntegration::nativePlan()); }

    /** Apply the explicitly reviewed return to the built-in Codex route. */
    public function nativeRestore(Request $request) { return $this->respond($request, fn() => CodexIntegration::restoreNative($this->hash($request))); }

    /** Queue a structural account-model observation in the existing browser owner process. */
    public function probe(Request $request) { return $this->respond($request, static fn() => ['id' => BrowserState::enqueue('probe_models', [], true)]); }

    /** Apply the exact preview version chosen by the local console. */
    public function install(Request $request) { return $this->respond($request, fn() => CodexIntegration::install($this->hash($request))); }

    /** Restore only owned routing assignments, preserving changes from other software. */
    public function remove(Request $request) { return $this->respond($request, fn() => CodexIntegration::remove($this->hash($request))); }

    /** Validate the config version token through the framework input component. */
    private function hash(Request $request): string
    {
        try {
            $body = json_decode((string) $request->rawBody(), true, 8, JSON_THROW_ON_ERROR);
            return (string) validator()->check($body['expected_hash'] ?? '', ['rule' => 'alnum', 'len' => [64, 64], 'name' => '配置版本']);
        } catch (\Throwable) { throw new GatewayError('invalid_config_version', '请先预览 Codex 配置变化。', 400); }
    }

    /** Keep framework response/error semantics shared with other local preparation actions. */
    private function respond(Request $request, callable $operation)
    {
        try { Schema::initialize(); return $request->json(['code' => 0, 'data' => $operation()]); }
        catch (GatewayError $error) { return $request->json(['code' => $error->errorCode, 'message' => $error->getMessage()])->withStatus($error->httpStatus); }
        catch (\Throwable) { return $request->json(['code' => 'codex_setup_failed', 'message' => 'Codex 接入操作失败，请检查配置目录权限。'])->withStatus(503); }
    }
}
