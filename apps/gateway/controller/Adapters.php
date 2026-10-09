<?php
declare(strict_types=1);
namespace app\gateway\controller;

use RC\Request;
use app\gateway\database\Schema;
use app\gateway\model\AdapterCapabilities;
use app\gateway\model\GatewayError;

/** Local diagnostic API for the versioned Web-model adapter capability matrix. */
final class Adapters
{
    public function index(Request $request)
    {
        try {
            Schema::initialize();
            return $request->json(['code' => 0, 'data' => AdapterCapabilities::matrix()]);
        } catch (GatewayError $error) {
            return $request->json(['code' => $error->errorCode, 'message' => $error->getMessage()])->withStatus($error->httpStatus);
        } catch (\Throwable) {
            return $request->json(['code' => 'STORAGE_UNAVAILABLE', 'message' => 'Adapter 能力状态暂不可读取。'])->withStatus(503);
        }
    }
}
