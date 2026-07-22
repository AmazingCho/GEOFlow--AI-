<?php

namespace App\Services\GeoFlow;

use App\Jobs\ProcessGeoFlowTaskJob;
use App\Models\Article;
use App\Models\Task;
use App\Models\TaskRun;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * GeoFlow 任务调度服务（基于 Laravel Queue + Redis）。
 *
 * 职责边界：
 * 1. 管理任务执行记录（`task_runs`）的状态流转：pending -> running -> completed/failed/cancelled。
 * 2. 负责把可执行任务投递到 Laravel 队列（`geoflow` queue），并在失败时按重试策略再次投递。
 * 3. 同步回写 `tasks` 的运行态字段（最近成功/失败时间、错误信息等），供后台面板展示。
 *
 * 设计说明：
 * - 这里不再依赖 legacy 的 `job_queue` 表，`task_runs.id` 即当前执行链路中的 job 标识。
 * - 重试次数、可执行时间等临时调度信息放在 `task_runs.meta` 中，避免新增表结构。
 */
class JobQueueService
{
    public function __construct(
        private readonly ArticleGenerationTraceSanitizer $articleGenerationTraceSanitizer
    ) {}

    /**
     * 初始化任务调度字段。
     *
     * 仅在字段为空时写入默认值，避免覆盖人工配置：
     * - `next_run_at`: 首次可执行时间
     * - `schedule_enabled`: 调度开关（默认 1）
     * - `max_retry_count`: 最大重试次数（默认 3）
     */
    public function initializeTaskSchedule(int $taskId, int $delaySeconds = 60): void
    {
        DB::transaction(function () use ($taskId, $delaySeconds): void {
            $task = Task::query()
                ->whereKey($taskId)
                ->lockForUpdate()
                ->first(['id', 'next_run_at', 'next_publish_at', 'schedule_enabled', 'max_retry_count', 'publish_interval']);

            if (! $task) {
                return;
            }

            $now = now();
            $updates = ['updated_at' => $now];

            if ($task->next_run_at === null) {
                $updates['next_run_at'] = $now->copy()->addSeconds(max(1, $delaySeconds));
            }

            if ($task->next_publish_at === null) {
                $updates['next_publish_at'] = $now->copy()->addSeconds(max(60, (int) ($task->publish_interval ?? 3600)));
            }

            if ($task->schedule_enabled === null) {
                $updates['schedule_enabled'] = 1;
            }

            if ($task->max_retry_count === null) {
                $updates['max_retry_count'] = 3;
            }

            Task::query()->whereKey($taskId)->update($updates);
        });
    }

    /**
     * 判断任务是否已有未完成执行（pending/running）。
     *
     * 用于保证同一 task 不会被重复入队，避免并发重复生成内容。
     */
    public function hasPendingOrRunningJob(int $taskId): bool
    {
        return TaskRun::query()
            ->where('task_id', $taskId)
            ->whereIn('status', ['pending', 'running'])
            ->exists();
    }

    /**
     * 创建一条执行记录并投递到 Laravel 队列。
     *
     * @param  int  $taskId  任务 ID
     * @param  string  $jobType  业务类型（如 generate_article）
     * @param  array<string,mixed>  $payload  执行上下文参数
     * @param  string|null  $availableAt  可执行时间（为空则立即）
     * @return int|null 返回 `task_runs.id`；若任务不存在或已有进行中执行，则返回 null
     */
    public function enqueueTaskJob(int $taskId, string $jobType = 'generate_article', array $payload = [], ?string $availableAt = null): ?int
    {
        $jobType = $jobType === 'generate_article' ? $jobType : 'generate_article';
        $dispatchToken = $this->freshExecutionToken();
        $payload = $this->articleGenerationTraceSanitizer->sanitizeTaskRunMeta([
            'payload' => $payload,
        ])['payload'] ?? [];
        $run = DB::transaction(function () use ($taskId, $jobType, $payload, $availableAt, $dispatchToken): ?TaskRun {
            $taskRow = Task::query()
                ->whereKey($taskId)
                ->lockForUpdate()
                ->first(['id', 'max_retry_count']);
            if (! $taskRow) {
                return null;
            }

            // 任务级串行保护：事务内复查，避免并发请求重复入队。
            $exists = TaskRun::query()
                ->where('task_id', $taskId)
                ->whereIn('status', ['pending', 'running'])
                ->lockForUpdate()
                ->exists();
            if ($exists) {
                return null;
            }

            $maxAttempts = max(1, (int) ($taskRow->max_retry_count ?? 3));
            $availableAtValue = $availableAt ? Carbon::parse($availableAt) : now();

            // 建立“待执行记录”，作为后续状态流转的唯一主记录。
            return TaskRun::query()->create([
                'task_id' => $taskId,
                'status' => 'pending',
                'meta' => [
                    'job_type' => $jobType,
                    'payload' => $payload,
                    'attempt_count' => 0,
                    'max_attempts' => $maxAttempts,
                    'available_at' => $availableAtValue->toDateTimeString(),
                    'dispatch_token' => $dispatchToken,
                    'dispatch_state' => 'awaiting',
                ],
                'started_at' => $availableAtValue,
                'finished_at' => null,
            ]);
        });
        if (! $run) {
            return null;
        }

        // TaskRun 作为轻量 outbox；派发失败会补偿为 failed，绝不留下无队列载体的 pending 记录。
        $this->dispatchPendingRun((int) $run->id, $run->started_at, $dispatchToken, true);
        $this->broadcastOverviewUpdate();

        return (int) $run->id;
    }

    /**
     * 领取指定 ID 的 pending 任务执行记录（供 Laravel 队列 Job 执行时使用）。
     *
     * @return array<string,mixed>|null
     */
    public function claimPendingJobById(
        int $jobId,
        string $workerId,
        ?string $dispatchToken = null,
        ?string $executionToken = null
    ): ?array {
        $safeWorkerId = data_get(
            $this->articleGenerationTraceSanitizer->sanitizeTaskRunMeta(['worker_id' => $workerId]),
            'worker_id'
        );
        if (! is_string($safeWorkerId) || $safeWorkerId === '') {
            return null;
        }
        $safeDispatchToken = $dispatchToken === null
            ? null
            : data_get($this->articleGenerationTraceSanitizer->sanitizeTaskRunMeta(['dispatch_token' => $dispatchToken]), 'dispatch_token');
        if ($dispatchToken !== null && (! is_string($safeDispatchToken) || $safeDispatchToken === '')) {
            return null;
        }
        $safeExecutionToken = $executionToken === null
            ? null
            : data_get($this->articleGenerationTraceSanitizer->sanitizeTaskRunMeta(['claim_token' => $executionToken]), 'claim_token');
        if ($executionToken !== null && (! is_string($safeExecutionToken) || $safeExecutionToken === '')) {
            return null;
        }

        $claimedJob = DB::transaction(function () use ($jobId, $safeWorkerId, $safeDispatchToken, $safeExecutionToken): ?array {
            // 使用悲观锁 + 状态条件，确保同一条记录只会被一个 worker 成功 claim。
            $run = TaskRun::query()
                ->with('task:id,status,schedule_enabled,publish_interval')
                ->whereKey($jobId)
                ->where('status', 'pending')
                ->lockForUpdate()
                ->first();

            if (! $run) {
                return null;
            }
            $meta = $this->articleGenerationTraceSanitizer->sanitizeTaskRunMeta(
                $this->normalizeMeta($run->meta)
            );
            $storedDispatchToken = is_string($meta['dispatch_token'] ?? null) ? $meta['dispatch_token'] : null;
            if ($storedDispatchToken !== null && $safeDispatchToken !== $storedDispatchToken) {
                return null;
            }
            $task = $run->task;
            // 任务未激活或调度被关闭时，不允许执行。
            if (! $task || ($task->status ?? 'paused') !== 'active' || (int) ($task->schedule_enabled ?? 1) !== 1) {
                $meta['dispatch_state'] = 'cancelled';
                TaskRun::query()
                    ->whereKey((int) $run->id)
                    ->where('status', 'pending')
                    ->update([
                        'status' => 'cancelled',
                        'finished_at' => now(),
                        'error_message' => '任务未启用，已取消待执行记录',
                        'meta' => $meta,
                    ]);

                return null;
            }

            $availableAt = (string) ($meta['available_at'] ?? '');
            // 尚未到可执行时间，直接跳过（由队列 delay 机制在后续触发）。
            if ($availableAt !== '' && Carbon::parse($availableAt)->greaterThan(now())) {
                $replacementToken = $this->freshExecutionToken();
                $deferredMeta = $this->withoutExecutionOwnership($meta);
                $deferredMeta['dispatch_token'] = $replacementToken;
                $deferredMeta['dispatch_state'] = 'awaiting';
                TaskRun::query()->whereKey($jobId)->where('status', 'pending')->update([
                    'meta' => $this->articleGenerationTraceSanitizer->sanitizeTaskRunMeta($deferredMeta),
                ]);

                return null;
            }

            $claimToken = $safeExecutionToken ?? $this->freshExecutionToken();
            $claimedOwnership = [
                'worker_id' => $safeWorkerId,
                'claim_token' => $claimToken,
                'dispatch_state' => 'claimed',
            ];
            $effectiveDispatchToken = $storedDispatchToken ?? $safeDispatchToken;
            if ($effectiveDispatchToken !== null) {
                $claimedOwnership['dispatch_token'] = $effectiveDispatchToken;
            }
            $claimedMeta = $this->articleGenerationTraceSanitizer->sanitizeTaskRunMeta(
                array_merge($meta, $claimedOwnership)
            );

            $affected = TaskRun::query()
                ->whereKey($jobId)
                ->where('status', 'pending')
                ->update([
                    'status' => 'running',
                    'started_at' => now(),
                    'meta' => $claimedMeta,
                ]);

            if ($affected !== 1) {
                return null;
            }

            // 返回轻量执行上下文，供 ProcessGeoFlowTaskJob 使用。
            $row = $run->getAttributes();
            $row['status'] = 'running';
            $row['worker_id'] = $claimedMeta['worker_id'] ?? null;
            $row['claim_token'] = $claimedMeta['claim_token'] ?? null;
            $row['meta'] = $claimedMeta;
            $row['error_message'] = trim((string) ($row['error_message'] ?? '')) === ''
                ? ''
                : $this->articleGenerationTraceSanitizer->sanitizeErrorMessage((string) $row['error_message']);
            $row['publish_interval'] = (int) ($task->publish_interval ?? 0);
            $row['task_status'] = (string) ($task->status ?? 'paused');

            return $row;
        });

        if (is_array($claimedJob)) {
            $this->broadcastOverviewUpdate();
        }

        return $claimedJob;
    }

    public function claimTokenForDelivery(string $executionToken, int $deliveryAttempt): string
    {
        $safeExecutionToken = data_get(
            $this->articleGenerationTraceSanitizer->sanitizeTaskRunMeta(['claim_token' => $executionToken]),
            'claim_token'
        );
        if (! is_string($safeExecutionToken) || $safeExecutionToken === '') {
            throw new RuntimeException('队列消息执行标识无效');
        }

        $hex = hash('sha256', $safeExecutionToken.':'.max(1, $deliveryAttempt));

        return substr($hex, 0, 8)
            .'-'.substr($hex, 8, 4)
            .'-5'.substr($hex, 13, 3)
            .'-8'.substr($hex, 17, 3)
            .'-'.substr($hex, 20, 12);
    }

    /**
     * 兼容旧常驻 worker 的入口，现已废弃。
     *
     * 当前执行链路为“按 taskRunId 精确投递并 claim”，不再需要全局扫描 claimNext。
     *
     * @deprecated
     *
     * @return null 固定返回 null
     */
    public function claimNextJob(string $workerId): ?array
    {
        return null;
    }

    /**
     * 在业务写入事务内校验并续租执行所有权，防止已被 stale recovery 取代的 worker 落库。
     */
    public function renewExecutionLease(int $jobId, string $claimToken): bool
    {
        return DB::transaction(function () use ($jobId, $claimToken): bool {
            $run = TaskRun::query()
                ->whereKey($jobId)
                ->where('status', 'running')
                ->lockForUpdate()
                ->first();
            if (! $run) {
                return false;
            }
            $meta = $this->articleGenerationTraceSanitizer->sanitizeTaskRunMeta($this->normalizeMeta($run->meta));
            if (! $this->ownsExecution($meta, $claimToken)) {
                return false;
            }

            $run->update(['started_at' => now()]);

            return true;
        });
    }

    /**
     * Persist the business result link inside the caller's article/publish transaction.
     * A stale recovery can then finalize the same run instead of repeating the work.
     */
    public function associateArticleWithExecution(
        int $jobId,
        int $taskId,
        int $articleId,
        ?string $claimToken = null
    ): bool {
        return DB::transaction(function () use ($jobId, $taskId, $articleId, $claimToken): bool {
            $run = TaskRun::query()
                ->whereKey($jobId)
                ->where('task_id', $taskId)
                ->where('status', 'running')
                ->lockForUpdate()
                ->first();
            if (! $run) {
                return false;
            }
            $meta = $this->articleGenerationTraceSanitizer->sanitizeTaskRunMeta($this->normalizeMeta($run->meta));
            if (! $this->ownsExecution($meta, $claimToken)) {
                return false;
            }
            if ($run->article_id !== null && (int) $run->article_id !== $articleId) {
                return false;
            }
            if (! Article::query()
                ->whereKey($articleId)
                ->where('task_id', $taskId)
                ->exists()) {
                return false;
            }

            $run->update(['article_id' => $articleId]);

            return true;
        });
    }

    /**
     * 处理成功完成：回写执行记录 + 任务最近成功状态。
     *
     * @param  array<string,mixed>  $meta  执行产物元数据（如模型信息、trace 信息等）
     */
    public function completeJob(
        int $jobId,
        int $taskId,
        ?int $articleId,
        int $durationMs,
        array $meta = [],
        ?string $claimToken = null
    ): bool {
        $resultMeta = $this->articleGenerationTraceSanitizer->sanitizeTaskRunMeta($meta);
        $persistedMeta = DB::transaction(function () use ($jobId, $taskId, $articleId, $durationMs, $resultMeta, $claimToken): ?array {
            $this->lockTaskRow($taskId);
            $run = TaskRun::query()
                ->whereKey($jobId)
                ->where('task_id', $taskId)
                ->where('status', 'running')
                ->lockForUpdate()
                ->first();
            if (! $run) {
                return null;
            }
            $existingMeta = $this->articleGenerationTraceSanitizer->sanitizeTaskRunMeta($this->normalizeMeta($run->meta));
            if (! $this->ownsExecution($existingMeta, $claimToken)) {
                return null;
            }
            if ($run->article_id !== null
                && $articleId !== null
                && (int) $run->article_id !== $articleId) {
                return null;
            }
            $resolvedArticleId = $articleId ?? ($run->article_id !== null ? (int) $run->article_id : null);
            $queueAudit = array_intersect_key($existingMeta, array_fill_keys([
                'job_type', 'payload', 'attempt_count', 'max_attempts', 'available_at',
                'dispatched_at', 'dispatch_state', 'dispatch_token', 'claim_token', 'worker_id', 'legacy_claim',
            ], true));
            $mergedMeta = $this->articleGenerationTraceSanitizer->sanitizeTaskRunMeta(array_merge(
                $existingMeta,
                $resultMeta,
                $queueAudit,
                ['dispatch_state' => 'completed']
            ));

            $run->update([
                'status' => 'completed',
                'finished_at' => now(),
                'article_id' => $resolvedArticleId,
                'duration_ms' => $durationMs,
                'meta' => $mergedMeta,
                'error_message' => '',
            ]);

            Task::query()->whereKey($taskId)->update([
                'last_run_at' => now(),
                'last_success_at' => now(),
                'last_error_at' => null,
                'last_error_message' => '',
                'updated_at' => now(),
            ]);

            return $mergedMeta;
        });
        if ($persistedMeta === null) {
            return false;
        }

        $this->broadcastOverviewUpdate();
        $this->enqueueFollowUpGenerationIfNeeded($taskId, $persistedMeta);

        return true;
    }

    /**
     * 处理执行失败：根据重试策略决定“重新排队”或“最终失败”。
     *
     * 策略：
     * - attempt_count < max_attempts: 状态重置为 pending，写入下次 available_at，并再次 dispatch；
     * - 否则：状态置为 failed，结束本次执行生命周期。
     */
    public function failJob(
        int $jobId,
        int $taskId,
        string $errorMessage,
        int $durationMs,
        int $retryDelaySeconds = 60,
        ?string $claimToken = null,
        bool $retryable = true,
        array $failureMeta = []
    ): bool {
        $errorMessage = $this->articleGenerationTraceSanitizer->sanitizeErrorMessage($errorMessage);
        $failureMeta = $this->articleGenerationTraceSanitizer->sanitizeTaskRunMeta($failureMeta);
        $transition = DB::transaction(function () use ($jobId, $taskId, $errorMessage, $durationMs, $retryDelaySeconds, $claimToken, $retryable, $failureMeta): ?array {
            $this->lockTaskRow($taskId);
            $run = TaskRun::query()
                ->whereKey($jobId)
                ->where('task_id', $taskId)
                ->whereIn('status', ['pending', 'running'])
                ->lockForUpdate()
                ->first();
            if (! $run) {
                return null;
            }
            $runMeta = $this->articleGenerationTraceSanitizer->sanitizeTaskRunMeta($this->normalizeMeta($run->meta));
            if (! $this->ownsFailureTransition($run, $runMeta, $claimToken)) {
                return null;
            }
            if ($run->article_id !== null) {
                $this->finalizeCommittedRun($run, $runMeta);

                return ['should_retry' => false, 'committed' => true];
            }

            $attemptCount = (int) ($runMeta['attempt_count'] ?? 0) + 1;
            $maxAttempts = max(1, (int) ($runMeta['max_attempts'] ?? 3));
            $shouldRetry = $retryable && $attemptCount < $maxAttempts;
            $nextAvailableAt = now()->addSeconds(max(1, $retryDelaySeconds));
            $newMeta = array_merge($runMeta, [
                'attempt_count' => $attemptCount,
                'max_attempts' => $maxAttempts,
                'last_error' => $errorMessage,
                'available_at' => $shouldRetry ? $nextAvailableAt->toDateTimeString() : ($runMeta['available_at'] ?? ''),
                'dispatch_state' => $shouldRetry ? 'awaiting' : 'failed',
            ], $failureMeta);
            $dispatchToken = null;
            if ($shouldRetry) {
                $dispatchToken = $this->freshExecutionToken();
                $newMeta = $this->withoutExecutionOwnership($newMeta);
                $newMeta['dispatch_token'] = $dispatchToken;
                $newMeta['dispatch_state'] = 'awaiting';
            }
            $newMeta = $this->articleGenerationTraceSanitizer->sanitizeTaskRunMeta($newMeta);

            $run->update([
                'status' => $shouldRetry ? 'pending' : 'failed',
                'error_message' => $errorMessage,
                'duration_ms' => $durationMs,
                'finished_at' => $shouldRetry ? null : now(),
                'meta' => $newMeta,
            ]);
            Task::query()->whereKey($taskId)->update([
                'last_run_at' => now(),
                'last_error_at' => now(),
                'last_error_message' => $errorMessage,
                'updated_at' => now(),
            ]);

            return [
                'should_retry' => $shouldRetry,
                'available_at' => $nextAvailableAt,
                'dispatch_token' => $dispatchToken,
            ];
        });
        if ($transition === null) {
            return false;
        }
        if (($transition['should_retry'] ?? false) === true) {
            $this->dispatchPendingRun(
                $jobId,
                $transition['available_at'],
                (string) ($transition['dispatch_token'] ?? ''),
                true
            );
        }

        $this->broadcastOverviewUpdate();

        return true;
    }

    /**
     * 主动取消执行（如管理员手动停止任务）。
     */
    public function cancelJob(
        int $jobId,
        int $taskId,
        string $reason = '管理员手动停止',
        ?string $claimToken = null
    ): bool {
        $reason = $this->articleGenerationTraceSanitizer->sanitizeErrorMessage($reason);
        $cancelled = DB::transaction(function () use ($jobId, $taskId, $reason, $claimToken): bool {
            $this->lockTaskRow($taskId);
            $run = TaskRun::query()
                ->whereKey($jobId)
                ->where('task_id', $taskId)
                ->whereIn('status', ['pending', 'running'])
                ->lockForUpdate()
                ->first();
            if (! $run) {
                return false;
            }
            $runMeta = $this->articleGenerationTraceSanitizer->sanitizeTaskRunMeta($this->normalizeMeta($run->meta));
            if ($claimToken !== null && ! $this->ownsExecution($runMeta, $claimToken)) {
                return false;
            }
            if ($run->article_id !== null) {
                $this->finalizeCommittedRun($run, $runMeta);

                return true;
            }
            $runMeta['dispatch_state'] = 'cancelled';
            $run->update([
                'status' => 'cancelled',
                'finished_at' => now(),
                'error_message' => $reason,
                'duration_ms' => 0,
                'meta' => $this->articleGenerationTraceSanitizer->sanitizeTaskRunMeta($runMeta),
            ]);

            Task::query()->whereKey($taskId)->update([
                'last_run_at' => now(),
                'last_error_at' => now(),
                'last_error_message' => $reason,
                'updated_at' => now(),
            ]);

            return true;
        });
        if (! $cancelled) {
            return false;
        }

        $this->broadcastOverviewUpdate();

        return true;
    }

    /**
     * 恢复超时未完成的 running 记录。
     *
     * 兜底场景：worker 异常退出、超时杀进程、心跳抛错等导致 `handle()` 未回写完成态。
     * 处理方式：将仍卡在 running 的记录回退为 pending，并立即重新投递队列，避免「面板显示待执行但 Redis 里已无对应 Job」。
     *
     * @return int 成功回退并重新投递的记录数
     */
    public function recoverStaleJobs(int $timeoutSeconds = 600): int
    {
        $threshold = now()->subSeconds(max(60, $timeoutSeconds));
        $runningCandidateIds = TaskRun::query()
            ->where('status', 'running')
            ->where(function ($query) use ($threshold): void {
                $query->where('started_at', '<', $threshold)
                    ->orWhere(function ($legacy) use ($threshold): void {
                        $legacy->whereNull('started_at')->where('created_at', '<', $threshold);
                    });
            })
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
        $pendingCandidateIds = TaskRun::query()
            ->where('status', 'pending')
            ->where(function ($query) use ($threshold): void {
                $query->where('created_at', '<', $threshold)
                    ->orWhere('started_at', '<=', now());
            })
            ->orderBy('id')
            ->get(['id', 'meta'])
            ->map(static fn (TaskRun $run): int => (int) $run->id)
            ->all();
        $candidateIds = array_values(array_unique(array_merge($runningCandidateIds, $pendingCandidateIds)));

        $recovered = 0;
        foreach ($candidateIds as $jobId) {
            $candidateTaskId = (int) (TaskRun::query()->whereKey($jobId)->value('task_id') ?? 0);
            $transition = DB::transaction(function () use ($jobId, $candidateTaskId, $threshold): ?array {
                $task = $candidateTaskId > 0
                    ? $this->lockTaskRow($candidateTaskId, ['id', 'status', 'schedule_enabled', 'deleted_at'])
                    : null;
                /** @var TaskRun|null $run */
                $run = TaskRun::query()
                    ->whereKey($jobId)
                    ->when($candidateTaskId > 0, fn ($query) => $query->where('task_id', $candidateTaskId))
                    ->whereIn('status', ['pending', 'running'])
                    ->lockForUpdate()
                    ->first();
                if (! $run) {
                    return null;
                }
                $safeMeta = $this->articleGenerationTraceSanitizer->sanitizeTaskRunMeta($this->normalizeMeta($run->meta));
                $isStaleRunning = $run->status === 'running'
                    && ($run->started_at?->lt($threshold)
                        || ($run->started_at === null && $run->created_at?->lt($threshold)));
                $availableAt = isset($safeMeta['available_at'])
                    ? Carbon::parse((string) $safeMeta['available_at'])
                    : ($run->started_at ?? now());
                $isDue = ! $availableAt->greaterThan(now());
                $dispatchState = (string) ($safeMeta['dispatch_state'] ?? '');
                $dispatchedAt = isset($safeMeta['dispatched_at'])
                    ? Carbon::parse((string) $safeMeta['dispatched_at'])
                    : $run->created_at;
                $isDueAwaiting = $run->status === 'pending'
                    && $dispatchState === 'awaiting'
                    && $isDue;
                $dispatchTimeoutStartedAt = $dispatchedAt;
                if ($dispatchTimeoutStartedAt === null || $availableAt->greaterThan($dispatchTimeoutStartedAt)) {
                    $dispatchTimeoutStartedAt = $availableAt;
                }
                $isStaleDispatched = $run->status === 'pending'
                    && in_array($dispatchState, ['', 'dispatched'], true)
                    && $isDue
                    && $dispatchTimeoutStartedAt->lt($threshold);
                if (! $isStaleRunning && ! $isDueAwaiting && ! $isStaleDispatched) {
                    return null;
                }

                if ($isStaleRunning && $run->article_id !== null) {
                    $safeMeta['dispatch_state'] = 'completed';
                    $run->update([
                        'status' => 'completed',
                        'finished_at' => now(),
                        'error_message' => '',
                        'meta' => $this->articleGenerationTraceSanitizer->sanitizeTaskRunMeta($safeMeta),
                    ]);
                    Task::query()->whereKey((int) $run->task_id)->update([
                        'last_run_at' => now(),
                        'last_success_at' => now(),
                        'last_error_at' => null,
                        'last_error_message' => '',
                        'updated_at' => now(),
                    ]);

                    return ['finalized' => true];
                }

                if (! $task || $task->trashed() || ($task->status ?? 'paused') !== 'active' || (int) ($task->schedule_enabled ?? 1) !== 1) {
                    $safeMeta['dispatch_state'] = 'cancelled';
                    $run->update([
                        'status' => 'cancelled',
                        'finished_at' => now(),
                        'error_message' => '任务未启用，已取消超时执行记录',
                        'meta' => $this->articleGenerationTraceSanitizer->sanitizeTaskRunMeta($safeMeta),
                    ]);

                    return ['cancelled' => true];
                }

                $consumesAttempt = $isStaleRunning || $isStaleDispatched;
                $attemptCount = (int) ($safeMeta['attempt_count'] ?? 0) + ($consumesAttempt ? 1 : 0);
                $maxAttempts = max(1, (int) ($safeMeta['max_attempts'] ?? 3));
                if ($consumesAttempt && $attemptCount >= $maxAttempts) {
                    $safeMeta = $this->withoutExecutionOwnership($safeMeta);
                    $safeMeta['attempt_count'] = $attemptCount;
                    $safeMeta['max_attempts'] = $maxAttempts;
                    $safeMeta['dispatch_state'] = 'failed';
                    $error = $this->articleGenerationTraceSanitizer->sanitizeErrorMessage('队列执行超时且已达到最大尝试次数');
                    $run->update([
                        'status' => 'failed',
                        'finished_at' => now(),
                        'error_message' => $error,
                        'meta' => $this->articleGenerationTraceSanitizer->sanitizeTaskRunMeta($safeMeta),
                    ]);
                    Task::query()->whereKey((int) $run->task_id)->update([
                        'last_run_at' => now(),
                        'last_error_at' => now(),
                        'last_error_message' => $error,
                        'updated_at' => now(),
                    ]);

                    return ['failed' => true];
                }

                $dispatchToken = $this->freshExecutionToken();
                $recoveryAvailableAt = now();
                $safeMeta = $this->withoutExecutionOwnership($safeMeta);
                $safeMeta['attempt_count'] = $attemptCount;
                $safeMeta['max_attempts'] = $maxAttempts;
                $safeMeta['dispatch_token'] = $dispatchToken;
                $safeMeta['dispatch_state'] = 'awaiting';
                $safeMeta['available_at'] = $recoveryAvailableAt->toDateTimeString();
                $run->update([
                    'status' => 'pending',
                    'finished_at' => null,
                    'error_message' => '',
                    'meta' => $this->articleGenerationTraceSanitizer->sanitizeTaskRunMeta($safeMeta),
                ]);

                return [
                    'cancelled' => false,
                    'dispatch_token' => $dispatchToken,
                    'available_at' => $recoveryAvailableAt,
                ];
            });

            if (is_array($transition)
                && ($transition['cancelled'] ?? false) === false
                && ($transition['failed'] ?? false) === false
                && ($transition['finalized'] ?? false) === false) {
                $dispatched = $this->dispatchPendingRun(
                    $jobId,
                    $transition['available_at'] ?? null,
                    (string) $transition['dispatch_token'],
                    false
                );
                if ($dispatched) {
                    $recovered++;
                }
            }
        }

        return $recovered;
    }

    /**
     * 将 task_runs 执行记录投递到 Laravel 队列。
     */
    private function dispatchLaravelQueueJob(int $taskRunId, mixed $availableAt = null, ?string $dispatchToken = null): void
    {
        $dispatch = ProcessGeoFlowTaskJob::dispatch(
            $taskRunId,
            $dispatchToken,
            $this->freshExecutionToken()
        )->onQueue('geoflow');

        if ($availableAt instanceof Carbon) {
            $dispatch->delay($availableAt);

            return;
        }

        if (is_string($availableAt) && trim($availableAt) !== '') {
            try {
                $dispatch->delay(Carbon::parse($availableAt));
            } catch (Throwable) {
                // ignore invalid datetime
            }
        }
    }

    private function dispatchPendingRun(
        int $taskRunId,
        mixed $availableAt,
        string $dispatchToken,
        bool $throwOnFailure
    ): bool {
        try {
            $this->dispatchLaravelQueueJob($taskRunId, $availableAt, $dispatchToken);
        } catch (Throwable $exception) {
            $safeError = $this->articleGenerationTraceSanitizer->sanitizeErrorMessage($exception->getMessage());
            $candidateTaskId = (int) (TaskRun::query()->whereKey($taskRunId)->value('task_id') ?? 0);
            DB::transaction(function () use ($taskRunId, $candidateTaskId, $dispatchToken, $safeError): void {
                if ($candidateTaskId > 0) {
                    $this->lockTaskRow($candidateTaskId);
                }
                $run = TaskRun::query()
                    ->whereKey($taskRunId)
                    ->when($candidateTaskId > 0, fn ($query) => $query->where('task_id', $candidateTaskId))
                    ->where('status', 'pending')
                    ->lockForUpdate()
                    ->first();
                if (! $run) {
                    return;
                }
                $meta = $this->articleGenerationTraceSanitizer->sanitizeTaskRunMeta($this->normalizeMeta($run->meta));
                if (($meta['dispatch_token'] ?? null) !== $dispatchToken) {
                    return;
                }
                $meta['dispatch_state'] = 'failed';
                $meta['last_error'] = $safeError;
                $run->update([
                    'status' => 'failed',
                    'finished_at' => now(),
                    'error_message' => $safeError,
                    'meta' => $this->articleGenerationTraceSanitizer->sanitizeTaskRunMeta($meta),
                ]);
                Task::query()->whereKey((int) $run->task_id)->update([
                    'last_error_at' => now(),
                    'last_error_message' => $safeError,
                    'updated_at' => now(),
                ]);
            });

            if ($throwOnFailure) {
                throw new RuntimeException($safeError);
            }

            return false;
        }

        $candidateTaskId = (int) (TaskRun::query()->whereKey($taskRunId)->value('task_id') ?? 0);
        DB::transaction(function () use ($taskRunId, $candidateTaskId, $dispatchToken): void {
            if ($candidateTaskId > 0) {
                $this->lockTaskRow($candidateTaskId);
            }
            $run = TaskRun::query()
                ->whereKey($taskRunId)
                ->when($candidateTaskId > 0, fn ($query) => $query->where('task_id', $candidateTaskId))
                ->where('status', 'pending')
                ->lockForUpdate()
                ->first();
            if (! $run) {
                return;
            }
            $meta = $this->articleGenerationTraceSanitizer->sanitizeTaskRunMeta($this->normalizeMeta($run->meta));
            if (($meta['dispatch_token'] ?? null) !== $dispatchToken) {
                return;
            }
            $meta['dispatch_state'] = 'dispatched';
            $meta['dispatched_at'] = now()->toDateTimeString();
            $run->update(['meta' => $this->articleGenerationTraceSanitizer->sanitizeTaskRunMeta($meta)]);
        });

        return true;
    }

    /** @param array<string,mixed> $meta */
    private function ownsExecution(array $meta, ?string $claimToken): bool
    {
        $storedToken = is_string($meta['claim_token'] ?? null) ? $meta['claim_token'] : null;
        if ($storedToken === null) {
            return $claimToken === null;
        }
        $safeToken = data_get(
            $this->articleGenerationTraceSanitizer->sanitizeTaskRunMeta(['claim_token' => $claimToken]),
            'claim_token'
        );

        return is_string($safeToken) && hash_equals($storedToken, $safeToken);
    }

    /** @param array<string,mixed> $meta */
    private function finalizeCommittedRun(TaskRun $run, array $meta): void
    {
        $meta['dispatch_state'] = 'completed';
        $run->update([
            'status' => 'completed',
            'finished_at' => now(),
            'error_message' => '',
            'meta' => $this->articleGenerationTraceSanitizer->sanitizeTaskRunMeta($meta),
        ]);
        Task::query()->whereKey((int) $run->task_id)->update([
            'last_run_at' => now(),
            'last_success_at' => now(),
            'last_error_at' => null,
            'last_error_message' => '',
            'updated_at' => now(),
        ]);
    }

    /** @param array<string,mixed> $meta */
    private function ownsFailureTransition(TaskRun $run, array $meta, ?string $token): bool
    {
        if (($run->status ?? '') === 'running') {
            return $this->ownsExecution($meta, $token);
        }

        $storedToken = is_string($meta['dispatch_token'] ?? null) ? $meta['dispatch_token'] : null;
        if ($storedToken === null) {
            return $token === null;
        }
        $safeToken = data_get(
            $this->articleGenerationTraceSanitizer->sanitizeTaskRunMeta(['dispatch_token' => $token]),
            'dispatch_token'
        );

        return is_string($safeToken) && hash_equals($storedToken, $safeToken);
    }

    /** @param array<string,mixed> $meta @return array<string,mixed> */
    private function withoutExecutionOwnership(array $meta): array
    {
        unset($meta['worker_id'], $meta['claim_token'], $meta['dispatched_at'], $meta['legacy_claim']);

        return $meta;
    }

    private function freshExecutionToken(): string
    {
        return (string) Str::uuid();
    }

    /** @param list<string> $columns */
    private function lockTaskRow(int $taskId, array $columns = ['id']): ?Task
    {
        return Task::withTrashed()
            ->whereKey($taskId)
            ->lockForUpdate()
            ->first($columns);
    }

    /**
     * 草稿生成成功后立即串行补投下一轮生成，使“生成草稿”和“按间隔发布”解耦。
     *
     * 发布动作不在这里补投：发布节奏由 next_publish_at + geoflow:schedule-tasks 控制。
     *
     * @param  array<string,mixed>  $meta
     */
    private function enqueueFollowUpGenerationIfNeeded(int $taskId, array $meta): void
    {
        if (($meta['action'] ?? '') !== 'generate_draft') {
            return;
        }

        if ((string) config('queue.default') === 'sync') {
            return;
        }

        $task = Task::query()
            ->whereKey($taskId)
            ->first(['id', 'status', 'schedule_enabled', 'created_count', 'article_limit', 'draft_limit']);
        if (! $task || ($task->status ?? 'paused') !== 'active' || (int) ($task->schedule_enabled ?? 1) !== 1) {
            return;
        }

        $articleLimit = max(1, (int) ($task->article_limit ?? $task->draft_limit ?? 10));
        if ((int) ($task->created_count ?? 0) >= $articleLimit) {
            return;
        }

        $draftLimit = max(1, (int) ($task->draft_limit ?? 10));
        $draftCount = DB::table('articles')
            ->where('task_id', $taskId)
            ->where('status', 'draft')
            ->whereNull('deleted_at')
            ->count();
        if ($draftCount >= $draftLimit) {
            return;
        }

        $this->enqueueTaskJob($taskId, 'generate_article', [
            'source' => 'follow_up_generation',
        ]);
    }

    /**
     * 统一把 meta 解析为数组，屏蔽历史字符串/空值等差异。
     *
     * @return array<string,mixed>
     */
    private function normalizeMeta(mixed $meta): array
    {
        if (is_array($meta)) {
            return $meta;
        }

        if (is_string($meta) && trim($meta) !== '') {
            $decoded = json_decode($meta, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }

    /**
     * 广播最新任务面板快照（失败不影响主流程）。
     */
    private function broadcastOverviewUpdate(): void
    {
        try {
            app(TaskRealtimeBroadcastService::class)->broadcastOverview();
        } catch (Throwable) {
            // ignore
        }
    }
}
