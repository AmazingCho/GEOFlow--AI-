<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Services\Api\IdempotencyService;
use App\Services\GeoFlow\TaskLifecycleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * API v1 任务（tasks）生命周期：列表、创建、详情、更新、启停、入队、子 Job 列表。
 *
 * 读接口需 tasks:read，写接口需 tasks:write。部分写操作支持 X-Idempotency-Key 幂等。
 */
class TaskController extends BaseApiController
{
    /**
     * 分页列出任务（新契约含 task_progress / queue_overview）。
     *
     * 查询参数：page、per_page、status、search（按名称模糊）。
     */
    public function index(Request $request, TaskLifecycleService $tasks): JsonResponse
    {
        $statusQuery = $request->query('status');
        $searchQuery = $request->query('search');

        $data = $tasks->listTasks(
            $request->integer('page', 1),
            $request->integer('per_page', 20),
            [
                'status' => is_string($statusQuery) ? trim($statusQuery) : null,
                'search' => is_string($searchQuery) ? trim($searchQuery) : null,
            ]
        );

        return $this->success($request, $data);
    }

    /**
     * 创建任务；成功 HTTP 201。
     *
     * 幂等键：POST /tasks（请求头 X-Idempotency-Key 可选）。
     */
    public function store(Request $request, TaskLifecycleService $tasks): JsonResponse
    {
        $cached = IdempotencyService::maybeReplayJson($request, 'POST /tasks');
        if ($cached !== null) {
            return $cached;
        }

        return $this->success($request, $tasks->createTask($request->all()), 201, 'POST /tasks');
    }

    /**
     * 任务详情（双层视图：业务进度 + 队列监控摘要）。
     */
    public function show(Request $request, int $task, TaskLifecycleService $tasks): JsonResponse
    {
        return $this->success($request, $tasks->getTask($task));
    }

    /**
     * 部分更新任务字段。
     *
     * 幂等键：PATCH /tasks/{id}
     */
    public function update(Request $request, int $task, TaskLifecycleService $tasks): JsonResponse
    {
        $cached = IdempotencyService::maybeReplayJson($request, 'PATCH /tasks/{id}');
        if ($cached !== null) {
            return $cached;
        }

        return $this->success($request, $tasks->updateTask($task, $request->all()), 200, 'PATCH /tasks/{id}');
    }

    /**
     * 删除任务。幂等键：DELETE /tasks/{id}
     */
    public function destroy(Request $request, int $task, TaskLifecycleService $tasks): JsonResponse
    {
        $cached = IdempotencyService::maybeReplayJson($request, 'DELETE /tasks/{id}');
        if ($cached !== null) {
            return $cached;
        }

        return $this->success($request, $tasks->deleteTask($task), 200, 'DELETE /tasks/{id}');
    }

    /**
     * 激活任务并可选择立即入队一条生成任务。
     *
     * 请求体可选 enqueue_now（布尔）。幂等键：POST /tasks/{id}/start
     */
    public function start(Request $request, int $task, TaskLifecycleService $tasks): JsonResponse
    {
        $cached = IdempotencyService::maybeReplayJson($request, 'POST /tasks/{id}/start');
        if ($cached !== null) {
            return $cached;
        }

        $enqueueNow = ! empty($request->input('enqueue_now'));

        return $this->success($request, $tasks->startTask($task, $enqueueNow), 200, 'POST /tasks/{id}/start');
    }

    /**
     * 暂停任务并取消待处理 Job。
     *
     * 幂等键：POST /tasks/{id}/stop
     */
    public function stop(Request $request, int $task, TaskLifecycleService $tasks): JsonResponse
    {
        $cached = IdempotencyService::maybeReplayJson($request, 'POST /tasks/{id}/stop');
        if ($cached !== null) {
            return $cached;
        }

        return $this->success($request, $tasks->stopTask($task), 200, 'POST /tasks/{id}/stop');
    }

    /**
     * 向队列投递一条 Job；成功 HTTP 201。
     *
     * 请求体仅接受受控的任务类型与关联元数据。幂等键：POST /tasks/{id}/enqueue
     */
    public function enqueue(Request $request, int $task, TaskLifecycleService $tasks): JsonResponse
    {
        $cached = IdempotencyService::maybeReplayJson($request, 'POST /tasks/{id}/enqueue');
        if ($cached !== null) {
            return $cached;
        }

        $allowedFields = ['job_type', 'source', 'safe_mode', 'trigger', 'request_id', 'client_reference'];
        $unknownFields = array_values(array_diff(array_keys($request->all()), $allowedFields));
        if ($unknownFields !== []) {
            throw new ApiException('validation_failed', '参数校验失败', 422, [
                'field_errors' => ['payload' => '包含不受支持的字段：'.implode(', ', $unknownFields)],
            ]);
        }

        $validator = Validator::make($request->all(), [
            'job_type' => ['sometimes', 'string', 'in:generate_article'],
            'source' => ['sometimes', 'string', 'regex:/\A[a-z0-9_:-]{1,80}\z/'],
            'safe_mode' => ['sometimes', 'string', 'regex:/\A[a-z0-9_:-]{1,80}\z/'],
            'trigger' => ['sometimes', 'string', 'regex:/\A[a-z0-9_:-]{1,80}\z/'],
            'request_id' => ['sometimes', 'string', 'regex:/\A[a-z0-9_:-]{1,80}\z/'],
            'client_reference' => ['sometimes', 'string', 'regex:/\A[a-z0-9_:-]{1,80}\z/'],
        ]);
        if ($validator->fails()) {
            $fieldErrors = collect($validator->errors()->messages())
                ->map(static fn (array $messages): string => (string) ($messages[0] ?? '字段无效'))
                ->all();
            throw new ApiException('validation_failed', '参数校验失败', 422, [
                'field_errors' => $fieldErrors,
            ]);
        }

        $body = $validator->validated();
        $jobType = trim((string) ($body['job_type'] ?? 'generate_article'));
        $payload = $body;
        unset($payload['job_type']);

        return $this->success($request, $tasks->enqueueTask($task, $jobType, $payload), 201, 'POST /tasks/{id}/enqueue');
    }

    /**
     * 列出某任务下的执行记录（task_runs）。
     *
     * 查询参数：status（可选）、limit（默认 20，最大 100）。
     */
    public function jobs(Request $request, int $task, TaskLifecycleService $tasks): JsonResponse
    {
        $status = $request->query('status');
        $statusStr = is_string($status) ? trim($status) : '';

        return $this->success($request, $tasks->listTaskJobs(
            $task,
            $statusStr !== '' ? $statusStr : null,
            $request->integer('limit', 20)
        ));
    }
}
