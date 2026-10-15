<?php
declare(strict_types=1);
namespace app\gateway\controller;

use RC\Request;
use app\gateway\model\ReleaseUpdates;

/** Read-only update notices for the local console. No request can trigger an upstream check. */
final class Updates
{
    /** @param Request $request Local console request. @return mixed Framework JSON response. */
    public function status(Request $request)
    {
        return $request->json(['code' => 0, 'data' => ReleaseUpdates::snapshot()]);
    }
}
