<?php
declare(strict_types=1);
namespace app\gateway\controller;

use RC\Request;
use app\gateway\database\Schema;
use app\gateway\model\ArtifactStore;
use app\gateway\model\GatewayError;

/** Loopback-only verified artifact delivery. */
final class Artifacts
{
    public function download(Request $request, $id)
    {
        try {
            Schema::initialize();
            $artifact = ArtifactStore::get((string) $id);
            return $request->download((string) $artifact['path'], (string) $artifact['filename']);
        } catch (GatewayError $error) {
            return $request->json(['code' => $error->errorCode, 'message' => $error->getMessage()])->withStatus($error->httpStatus);
        } catch (\Throwable) {
            return $request->json(['code' => 'ARTIFACT_UNAVAILABLE', 'message' => '产物暂不可用，请稍后重试。'])->withStatus(503);
        }
    }
}
