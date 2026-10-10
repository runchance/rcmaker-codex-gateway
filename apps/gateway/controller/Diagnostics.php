<?php
declare(strict_types=1);
namespace app\gateway\controller;

use RC\Request;
use app\gateway\model\DiagnosticExport;
use app\gateway\model\GatewayError;

/** Local-console-only download, protected by the existing GatewayLocal middleware. */
final class Diagnostics
{
    /**
     * Download a bounded ZIP/JSON snapshot without changing active tasks or requiring model readiness.
     * @param Request $request Validated local console request; hours and task_id are optional.
     * @return mixed Framework binary response, or the established safe JSON error envelope.
     */
    public function export(Request $request)
    {
        try {
            try {
                $hours = (int) validator()->check($request->get('hours', 24), ['rule' => 'pint', 'name' => 'hours']);
                $task = $request->get('task_id', '');
                $taskId = null;
                if ($task !== '') {
                    $taskId = (string) validator()->check($task, [
                        'rule' => 'alnum', 'options' => ['attach' => ['_']], 'len' => [12, 80], 'name' => 'task_id',
                    ]);
                    if (!str_starts_with($taskId, 'task_')) throw new \RuntimeException('invalid_task');
                }
            } catch (\Throwable) {
                throw new GatewayError('DIAGNOSTICS_INVALID_RANGE', 'Invalid diagnostic range or task.', 400);
            }
            $archive = DiagnosticExport::archive(DiagnosticExport::collect($hours, $taskId));
            return $request->response($archive['body'], 200, [
                'Content-Type' => $archive['content_type'],
                'Content-Disposition' => 'attachment; filename="' . $archive['filename'] . '"',
                'Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff',
            ]);
        } catch (GatewayError $error) {
            return $request->json(['code' => $error->errorCode, 'message' => $error->getMessage()])->withStatus($error->httpStatus);
        } catch (\Throwable) {
            return $request->json(['code' => 'DIAGNOSTICS_UNAVAILABLE', 'message' => 'Diagnostic export is temporarily unavailable.'])->withStatus(503);
        }
    }
}
