<?php
declare(strict_types=1);
namespace app\gateway\controller;

use RC\Request;
use app\gateway\database\Schema;
use app\gateway\model\GatewayError;
use app\gateway\model\ResponseJob;
use app\gateway\model\TaskLedger;
use app\gateway\model\WebMessageAudit;
use app\gateway\model\TaskMaintenance;

/** Read-only durable task inspection plus explicit local cancellation. */
final class Tasks
{
    /** @return mixed Status-filtered durable task summaries for the local console. */
    public function index(Request $request)
    {
        return $this->respond($request, function () use ($request): array {
            try {
                $page = (int) validator()->check($request->get('page', 1), ['rule' => 'pint', 'name' => '页码']);
                $pageSize = (int) validator()->check($request->get('page_size', 20), ['rule' => 'pint', 'name' => '每页数量']);
                $status = (string) validator()->check($request->get('status', 'active'), ['rule' => 'string', 'name' => '任务状态']);
            } catch (\Throwable) {
                throw new GatewayError('invalid_pagination', '页码或每页数量无效。', 400);
            }
            if ($pageSize < 5 || $pageSize > 100) {
                throw new GatewayError('invalid_pagination', '每页数量必须在 5 到 100 之间。', 400);
            }
            if (!in_array($status, ['active', 'needs_attention', 'completed', 'cancelled'], true)) {
                throw new GatewayError('invalid_task_status', '任务状态筛选无效。', 400);
            }
            return TaskLedger::listTasks($page, $pageSize, $status);
        });
    }

    /** @return mixed Task epochs, turns, surfaces and bounded timeline. */
    public function detail(Request $request, $id)
    {
        return $this->respond($request, fn(): array => TaskLedger::detail($this->taskId($id)));
    }

    /** Return one unabridged browser text message only after task-scoped lookup. */
    public function webMessage(Request $request, $id, $message)
    {
        return $this->respond($request, function () use ($id, $message): array {
            $taskId = $this->taskId($id);
            $messageId = filter_var($message, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($messageId === false) throw new GatewayError('invalid_web_message_id', '网页消息编号无效。', 400);
            return WebMessageAudit::textForTask($taskId, (int)$messageId);
        });
    }

    /** @return mixed Persist cancellation first; the browser worker closes retired task targets asynchronously. */
    public function cancel(Request $request, $id)
    {
        return $this->respond($request, function () use ($id): array {
            $taskId = $this->taskId($id);
            $result = ResponseJob::cancelTask($taskId);
            return [
                'task_id' => $taskId,
                'cancelled' => true,
                'pending_surface_cleanup' => count($result['target_ids'] ?? []),
            ];
        });
    }

    /** Delete one idle task asynchronously; working tasks are rejected by the transactional domain check. */
    public function delete(Request $request, $id)
    {
        return $this->respond($request, fn(): array => TaskMaintenance::request($this->taskId($id)), 202);
    }

    /** Snapshot all non-working tasks for one cleanup, preserving tasks admitted later. */
    public function clear(Request $request)
    {
        return $this->respond($request, static fn(): array => TaskMaintenance::request(), 202);
    }

    /** Read bounded cleanup progress and real/reusable database bytes. */
    public function cleanup(Request $request)
    {
        return $this->respond($request, static fn(): array => TaskMaintenance::snapshot());
    }

    /** Validate the route token before domain lookup. */
    private function taskId(mixed $value): string
    {
        try {
            $value = (string) validator()->check($value, [
                'rule' => 'alnum',
                'options' => ['attach' => ['_']],
                'len' => [12, 40],
                'name' => '任务编号',
            ]);
        } catch (\Throwable) {
            throw new GatewayError('invalid_task_id', '任务编号无效。', 400);
        }
        if (!str_starts_with($value, 'task_')) {
            throw new GatewayError('invalid_task_id', '任务编号无效。', 400);
        }
        return $value;
    }

    /** Normalize the console API envelope without exposing exception details. */
    private function respond(Request $request, callable $operation, int $successStatus = 200)
    {
        try {
            Schema::initialize();
            return $request->json(['code' => 0, 'data' => $operation()])->withStatus($successStatus);
        } catch (GatewayError $error) {
            return $request->json(['code' => $error->errorCode, 'message' => $error->getMessage()])->withStatus($error->httpStatus);
        } catch (\Throwable) {
            return $request->json(['code' => 'TASK_SERVICE_UNAVAILABLE', 'message' => '任务账本暂不可用，请检查本地服务状态。'])->withStatus(503);
        }
    }
}
