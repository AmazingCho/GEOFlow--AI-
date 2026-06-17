<?php

namespace App\Services\GeoFlow;

use App\Exceptions\ApiException;
use App\Models\AiModel;
use App\Models\Article;
use App\Models\Author;
use App\Models\CaseRecord;
use App\Models\Category;
use App\Models\CollectionRecord;
use App\Models\EntityRecord;
use App\Models\ImageLibrary;
use App\Models\KnowledgeBase;
use App\Models\Prompt;
use App\Models\Task;
use App\Models\TaskRun;
use App\Models\TaskSchedule;
use App\Models\TitleLibrary;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * 任务生命周期服务。
 *
 * 该服务聚合任务在 API 场景下的完整生命周期能力：
 * - 任务的创建、查询、更新、启停；
 * - 入队前置校验与投递；
 * - 运行记录（task_runs）查询；
 * - 表单输入归一化与业务校验。
 *
 * 约束说明：
 * - 这里只做“生命周期编排”，真正的执行与重试由 JobQueueService/队列 Job 负责；
 * - 对外异常统一抛 ApiException，便于 API 层形成稳定响应契约。
 */
class TaskLifecycleService
{
    /**
     * @param  JobQueueService  $queueService  队列调度服务（负责 task_runs 入队、状态流转、重试）
     */
    public function __construct(
        private JobQueueService $queueService,
        private TaskMonitoringQueryService $taskMonitoringQueryService,
        private TaskRealtimeBroadcastService $taskRealtimeBroadcastService
    ) {}

    /**
     * 分页查询任务列表（含 pending/running 运行计数）。
     *
     * @param  int  $page  页码（最小 1）
     * @param  int  $perPage  每页数量（1~100）
     * @param  array<string,mixed>  $filters  支持 status/search 过滤
     * @return array{
     *     items:list<array<string,mixed>>,
     *     pagination:array{page:int,per_page:int,total:int,total_pages:int}
     * }
     */
    public function listTasks(int $page = 1, int $perPage = 20, array $filters = []): array
    {
        return $this->taskMonitoringQueryService->listTasksPaginated($page, $perPage, $filters);
    }

    /**
     * 创建任务并初始化调度状态。
     *
     * 流程：
     * 1. 归一化并校验输入；
     * 2. 创建 tasks 主记录；
     * 3. 初始化调度字段；
     * 4. 若任务初始为 active，补一条 task_schedules，否则显式关闭 schedule。
     *
     * @param  array<string,mixed>  $data
     * @return array<string,mixed> 新建后的任务详情（getTask 结构）
     */
    public function createTask(array $data): array
    {
        $normalized = $this->normalizeTaskInput($data, false);

        DB::beginTransaction();
        try {
            $task = Task::query()->create([
                'name' => $normalized['name'],
                'collection_id' => $normalized['collection_id'],
                'cross_collection_mode' => $normalized['cross_collection_mode'],
                'title_library_id' => $normalized['title_library_id'],
                'image_library_id' => $normalized['image_library_id'],
                'image_count' => $normalized['image_count'],
                'image_tag_filter' => $normalized['image_tag_filter'],
                'prompt_id' => $normalized['prompt_id'],
                'skill_prompt_id' => $normalized['skill_prompt_id'],
                'ai_model_id' => $normalized['ai_model_id'],
                'need_review' => $normalized['need_review'],
                'publish_interval' => $normalized['publish_interval'],
                'author_id' => $normalized['author_id'],
                'auto_keywords' => $normalized['auto_keywords'],
                'auto_description' => $normalized['auto_description'],
                'draft_limit' => $normalized['draft_limit'],
                'article_limit' => $normalized['article_limit'],
                'is_loop' => $normalized['is_loop'],
                'model_selection_mode' => $normalized['model_selection_mode'],
                'status' => $normalized['status'],
                'publish_scope' => $normalized['publish_scope'],
                'knowledge_base_id' => $normalized['knowledge_base_id'],
                'knowledge_tag_filter' => $normalized['knowledge_tag_filter'],
                'entity_filter' => $normalized['entity_filter'],
                'case_filter' => $normalized['case_filter'],
                'crm_source_type' => $normalized['crm_source_type'],
                'crm_source_id' => $normalized['crm_source_id'],
                'category_mode' => $normalized['category_mode'],
                'fixed_category_id' => $normalized['fixed_category_id'],
            ]);

            $taskId = (int) $task->id;
            $this->queueService->initializeTaskSchedule($taskId);

            if ($normalized['status'] === 'active' && Schema::hasTable('task_schedules')) {
                TaskSchedule::query()->create([
                    'task_id' => $taskId,
                    'next_run_time' => now()->addMinute(),
                ]);
            } else {
                Task::query()->whereKey($taskId)->update([
                    'schedule_enabled' => 0,
                    'next_run_at' => null,
                    'updated_at' => now(),
                ]);
            }

            DB::commit();
            if ($normalized['status'] === 'active' && $this->shouldEnqueueImmediatelyOnCreate()) {
                $jobId = $this->queueService->enqueueTaskJob($taskId, 'generate_article', ['source' => 'task_created']);
                if ($jobId !== null) {
                    Task::query()->whereKey($taskId)->update([
                        'next_run_at' => now()->addSeconds(60),
                        'updated_at' => now(),
                    ]);
                }
            }
            $this->taskRealtimeBroadcastService->broadcastOverview();

            return $this->getTask($taskId);
        } catch (Throwable $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            throw $e;
        }
    }

    /**
     * 仅在真正异步队列下创建后立即投递，避免 sync 队列阻塞后台表单请求。
     */
    private function shouldEnqueueImmediatelyOnCreate(): bool
    {
        return (string) config('queue.default') !== 'sync';
    }

    /**
     * 获取单任务详情（含任务运行摘要与文章统计摘要）。
     *
     * @return array<string,mixed>
     *
     * @throws ApiException 当任务不存在时抛出 404
     */
    public function getTask(int $taskId): array
    {
        try {
            return $this->taskMonitoringQueryService->getTaskMonitoringDetail($taskId);
        } catch (ModelNotFoundException) {
            throw new ApiException('task_not_found', '任务不存在', 404);
        }
    }

    /**
     * 更新任务配置（支持局部更新）。
     *
     * 特殊规则：
     * - status 单独处理：active -> activateTask，paused -> pauseTask；
     * - 其余字段只更新传入字段。
     *
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    public function updateTask(int $taskId, array $data): array
    {
        $this->ensureTaskExists($taskId);
        $normalized = $this->normalizeTaskInput($data, true);
        if (empty($normalized)) {
            throw new ApiException('validation_failed', '没有可更新的字段', 422);
        }

        $status = $normalized['status'] ?? null;
        unset($normalized['status']);

        DB::beginTransaction();
        try {
            if (! empty($normalized)) {
                Task::query()->whereKey($taskId)->update($normalized);
            }

            if ($status === 'active') {
                $this->activateTask($taskId, false);
            } elseif ($status === 'paused') {
                $this->pauseTask($taskId, '任务已暂停');
            }

            DB::commit();
            $this->taskRealtimeBroadcastService->broadcastOverview();

            return $this->getTask($taskId);
        } catch (Throwable $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            throw $e;
        }
    }

    /**
     * 删除任务。
     *
     * 任务删除在业务上等同“进入回收站”：
     * - 取消未执行的队列记录并关闭调度；
     * - 清理任务调度/素材临时关系；
     * - 保留已生成文章和 articles.task_id，用于后续追踪生成来源。
     *
     * @return array{id:int,name:string,deleted:bool}
     */
    public function deleteTask(int $taskId): array
    {
        $task = Task::query()->whereKey($taskId)->first(['id', 'name']);
        if (! $task) {
            throw new ApiException('task_not_found', '任务不存在', 404);
        }

        $taskName = (string) $task->name;

        DB::transaction(function () use ($taskId): void {
            $this->pauseTask($taskId, '任务已删除进入回收站');

            foreach (['article_queue', 'task_materials', 'task_schedules'] as $table) {
                if (Schema::hasTable($table)) {
                    DB::table($table)->where('task_id', $taskId)->delete();
                }
            }

            Task::query()->whereKey($taskId)->first()?->delete();
        });

        $this->taskRealtimeBroadcastService->broadcastOverview();

        return [
            'id' => $taskId,
            'name' => $taskName,
            'deleted' => true,
        ];
    }

    /**
     * 从回收站恢复任务。
     *
     * 恢复后保持暂停状态，避免恢复动作立即触发新的文章生成。
     *
     * @return array{id:int,name:string,restored:bool}
     */
    public function restoreTask(int $taskId): array
    {
        $task = Task::onlyTrashed()->whereKey($taskId)->first(['id', 'name']);
        if (! $task) {
            throw new ApiException('task_not_found', '任务不存在或未在回收站', 404);
        }

        DB::transaction(function () use ($task): void {
            $task->restore();
            Task::query()->whereKey((int) $task->id)->update([
                'status' => 'paused',
                'schedule_enabled' => 0,
                'next_run_at' => null,
                'updated_at' => now(),
            ]);
        });

        $this->taskRealtimeBroadcastService->broadcastOverview();

        return [
            'id' => (int) $task->id,
            'name' => (string) $task->name,
            'restored' => true,
        ];
    }

    /**
     * 启动任务。
     *
     * @param  bool  $enqueueNow  是否立即投递一条执行任务（手动启动场景）
     * @return array<string,mixed>
     */
    public function startTask(int $taskId, bool $enqueueNow = false): array
    {
        $this->ensureTaskExists($taskId);
        DB::beginTransaction();
        try {
            // 手动“立即执行”场景下，不把 next_run_at 强行置为 now，
            // 避免与手动入队叠加导致一次点击触发两次执行。
            $this->activateTask($taskId, ! $enqueueNow);
            $jobId = null;
            if ($enqueueNow) {
                $jobId = $this->queueService->enqueueTaskJob($taskId, 'generate_article', ['source' => 'api_manual_start']);
                if ($jobId !== null) {
                    Task::query()->whereKey($taskId)->update([
                        'next_run_at' => now()->addSeconds(60),
                        'updated_at' => now(),
                    ]);
                }
            }
            DB::commit();
            $task = $this->getTask($taskId);
            if ($jobId !== null) {
                $task['started_job_id'] = $jobId;
            }
            $this->taskRealtimeBroadcastService->broadcastOverview();

            return $task;
        } catch (Throwable $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            throw $e;
        }
    }

    /**
     * 停止任务。
     *
     * 行为：
     * - 关闭任务调度开关；
     * - 将当前任务下 pending 的执行记录批量标记 cancelled；
     * - 返回取消数量与当前 running 数量。
     *
     * @return array<string,mixed>
     */
    public function stopTask(int $taskId): array
    {
        $this->ensureTaskExists($taskId);
        DB::beginTransaction();
        try {
            $cancelledJobs = $this->pauseTask($taskId, '任务已暂停');
            $runningJobs = TaskRun::query()
                ->where('task_id', $taskId)
                ->where('status', 'running')
                ->count();
            DB::commit();
            $task = $this->getTask($taskId);
            $task['cancelled_jobs'] = $cancelledJobs;
            $task['running_jobs'] = $runningJobs;
            $this->taskRealtimeBroadcastService->broadcastOverview();

            return $task;
        } catch (Throwable $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            throw $e;
        }
    }

    /**
     * 手动入队单个任务执行。
     *
     * 入队前会校验任务是否启用（status=active 且 schedule_enabled=1）。
     *
     * @param  string  $jobType  业务任务类型
     * @param  array<string,mixed>  $payload  任务执行载荷
     * @return array{task_id:int,job_id:int,status:string}
     *
     * @throws ApiException 任务不存在、任务未启用、或已有进行中任务时抛出
     */
    public function enqueueTask(int $taskId, string $jobType = 'generate_article', array $payload = []): array
    {
        $task = Task::query()->find($taskId, ['id', 'status', 'schedule_enabled']);
        if (! $task) {
            throw new ApiException('task_not_found', '任务不存在', 404);
        }

        if (($task->status ?? 'paused') !== 'active' || (int) ($task->schedule_enabled ?? 1) !== 1) {
            throw new ApiException('task_not_active', '任务未启用，无法入队', 409);
        }

        $jobId = $this->queueService->enqueueTaskJob($taskId, $jobType, $payload);
        if ($jobId === null) {
            throw new ApiException('job_already_exists', '任务已处于排队或执行中', 409);
        }

        return [
            'task_id' => $taskId,
            'job_id' => $jobId,
            'status' => 'pending',
        ];
    }

    /**
     * 查询任务下的执行记录（task_runs）。
     *
     * @param  string|null  $status  可选状态过滤
     * @param  int  $limit  返回数量上限（1~100）
     * @return array{items:list<array<string,mixed>>}
     */
    public function listTaskJobs(int $taskId, ?string $status = null, int $limit = 20): array
    {
        $this->ensureTaskExists($taskId);
        $limit = max(1, min(100, $limit));

        $q = TaskRun::query()
            ->where('task_id', $taskId)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit);

        if ($status !== null && $status !== '') {
            $q->where('status', $status);
        }

        return ['items' => $q->get()->map(fn (TaskRun $run) => $run->getAttributes())->all()];
    }

    /**
     * 查询单条执行记录详情（对外保持 job 语义）。
     *
     * 注意：当前 job_id 即 task_runs.id。
     *
     * @return array<string,mixed>
     *
     * @throws ApiException 当执行记录不存在时抛出 404
     */
    public function getJob(int $jobId): array
    {
        $run = TaskRun::query()->find($jobId);
        if (! $run) {
            throw new ApiException('job_not_found', 'Job 不存在', 404);
        }
        $meta = is_array($run->meta) ? $run->meta : [];
        $payload = is_array($meta['payload'] ?? null) ? $meta['payload'] : [];

        return [
            'id' => (int) $run->id,
            'task_id' => (int) $run->task_id,
            'job_type' => (string) ($meta['job_type'] ?? 'generate_article'),
            'status' => (string) $run->status,
            'attempt_count' => (int) ($meta['attempt_count'] ?? 0),
            'max_attempts' => (int) ($meta['max_attempts'] ?? 0),
            'worker_id' => is_string($meta['worker_id'] ?? null) ? $meta['worker_id'] : null,
            'claimed_at' => $run->started_at?->format('Y-m-d H:i:s'),
            'finished_at' => $run->finished_at?->format('Y-m-d H:i:s'),
            'error_message' => $run->error_message ?? '',
            'payload' => $payload,
            'task_run_summary' => [
                'article_id' => $run->article_id !== null ? (int) $run->article_id : null,
                'duration_ms' => (int) ($run->duration_ms ?? 0),
                'status' => $run->status ?? null,
                'error_message' => $run->error_message ?? '',
                'meta' => $meta,
            ],
        ];
    }

    /**
     * 归一化并校验任务输入。
     *
     * - create 场景：补默认值并强校验必填；
     * - update 场景：仅处理传入字段。
     *
     * @param  array<string,mixed>  $data
     * @param  bool  $isUpdate  true=更新，false=创建
     * @return array<string,mixed>
     *
     * @throws ApiException 字段校验失败时抛 422，并附带 field_errors
     */
    private function normalizeTaskInput(array $data, bool $isUpdate): array
    {
        $output = [];
        $fieldErrors = [];

        if (array_key_exists('name', $data)) {
            $name = trim((string) $data['name']);
            if ($name === '') {
                $fieldErrors['name'] = '任务名称不能为空';
            } else {
                $output['name'] = $name;
            }
        } elseif (! $isUpdate) {
            $fieldErrors['name'] = '任务名称不能为空';
        }

        $referenceMap = [
            'collection_id' => ['model' => CollectionRecord::class, 'message' => '请选择 Collection', 'required' => false],
            'title_library_id' => ['model' => TitleLibrary::class, 'message' => '选择的标题库不存在', 'required' => ! $isUpdate],
            'image_library_id' => ['model' => ImageLibrary::class, 'message' => '选择的图片库不存在', 'required' => false],
            'prompt_id' => ['model' => Prompt::class, 'message' => '选择的内容提示词不存在', 'required' => ! $isUpdate, 'prompt_content' => true],
            'skill_prompt_id' => ['model' => Prompt::class, 'message' => '选择的 Skill Prompt 不存在', 'required' => false, 'prompt_skill' => true],
            'ai_model_id' => ['model' => AiModel::class, 'message' => '选择的AI模型不存在或未激活', 'required' => ! $isUpdate, 'ai_active_chat' => true],
            'author_id' => ['model' => Author::class, 'message' => '选择的作者不存在', 'required' => false],
            'knowledge_base_id' => ['model' => KnowledgeBase::class, 'message' => '选择的知识库不存在', 'required' => false],
            'fixed_category_id' => ['model' => Category::class, 'message' => '固定分类不存在', 'required' => false],
        ];

        foreach ($referenceMap as $field => $config) {
            if (! array_key_exists($field, $data)) {
                if (! $isUpdate && $config['required']) {
                    $fieldErrors[$field] = '缺少必填字段';
                } elseif (! $isUpdate) {
                    $output[$field] = null;
                }

                continue;
            }

            $value = $data[$field];
            if ($value === null || $value === '' || (int) $value <= 0) {
                $output[$field] = null;
                if (! $isUpdate && $config['required']) {
                    $fieldErrors[$field] = '缺少必填字段';
                }

                continue;
            }

            $id = (int) $value;
            $modelClass = $config['model'];
            $exists = false;
            // prompt 与 ai_model 的校验规则与普通外键不同，这里单独处理业务约束。
            if (! empty($config['prompt_content'])) {
                $exists = Prompt::query()->whereKey($id)->where('type', 'content')->exists();
            } elseif (! empty($config['prompt_skill'])) {
                $exists = Prompt::query()->whereKey($id)->where('type', 'skill')->exists();
            } elseif (! empty($config['ai_active_chat'])) {
                $exists = AiModel::query()
                    ->whereKey($id)
                    ->where('status', 'active')
                    ->where(function ($q) {
                        $q->whereNull('model_type')
                            ->orWhere('model_type', '')
                            ->orWhere('model_type', 'chat');
                    })
                    ->exists();
            } else {
                $exists = $modelClass::query()->whereKey($id)->exists();
            }

            if (! $exists) {
                $fieldErrors[$field] = $config['message'];
            } else {
                $output[$field] = $id;
            }
        }

        $flagFields = ['need_review', 'auto_keywords', 'auto_description', 'is_loop'];
        foreach ($flagFields as $field) {
            if (array_key_exists($field, $data)) {
                $output[$field] = $this->toFlag($data[$field]);
            } elseif (! $isUpdate) {
                $output[$field] = in_array($field, ['need_review', 'auto_keywords', 'auto_description'], true) ? 1 : 0;
            }
        }

        if (array_key_exists('image_count', $data)) {
            $output['image_count'] = max(0, (int) $data['image_count']);
        } elseif (! $isUpdate) {
            $output['image_count'] = 0;
        }

        if (array_key_exists('publish_interval', $data)) {
            $output['publish_interval'] = max(60, (int) $data['publish_interval']);
        } elseif (! $isUpdate) {
            $output['publish_interval'] = 3600;
        }

        if (array_key_exists('draft_limit', $data)) {
            $output['draft_limit'] = max(1, (int) $data['draft_limit']);
        } elseif (! $isUpdate) {
            $output['draft_limit'] = 10;
        }

        if (array_key_exists('article_limit', $data)) {
            $output['article_limit'] = max(1, (int) $data['article_limit']);
        } elseif (! $isUpdate) {
            $output['article_limit'] = max(10, (int) ($output['draft_limit'] ?? 10));
        }

        if (isset($output['article_limit'], $output['draft_limit']) && $output['draft_limit'] > $output['article_limit']) {
            $output['draft_limit'] = $output['article_limit'];
        }

        if (array_key_exists('category_mode', $data)) {
            $categoryMode = trim((string) $data['category_mode']);
            if (! in_array($categoryMode, ['smart', 'fixed'], true)) {
                $fieldErrors['category_mode'] = '分类模式无效';
            } else {
                $output['category_mode'] = $categoryMode;
            }
        } elseif (! $isUpdate) {
            $output['category_mode'] = 'smart';
        }

        if (array_key_exists('model_selection_mode', $data)) {
            $modelSelectionMode = trim((string) $data['model_selection_mode']);
            if (! in_array($modelSelectionMode, ['fixed', 'smart_failover'], true)) {
                $fieldErrors['model_selection_mode'] = '模型选择模式无效';
            } else {
                $output['model_selection_mode'] = $modelSelectionMode;
            }
        } elseif (! $isUpdate) {
            $output['model_selection_mode'] = 'fixed';
        }

        if (array_key_exists('status', $data)) {
            $status = trim((string) $data['status']);
            if (! in_array($status, ['active', 'paused'], true)) {
                $fieldErrors['status'] = '任务状态无效';
            } else {
                $output['status'] = $status;
            }
        } elseif (! $isUpdate) {
            $output['status'] = 'active';
        }

        if (array_key_exists('publish_scope', $data)) {
            $publishScope = trim((string) $data['publish_scope']);
            if (! in_array($publishScope, ['local_and_distribution', 'distribution_only', 'local_only'], true)) {
                $fieldErrors['publish_scope'] = '发布范围无效';
            } else {
                $output['publish_scope'] = $publishScope;
            }
        } elseif (! $isUpdate) {
            $output['publish_scope'] = 'local_and_distribution';
        }

        if (array_key_exists('knowledge_tag_filter', $data)) {
            $output['knowledge_tag_filter'] = mb_substr(trim((string) $data['knowledge_tag_filter']), 0, 1000, 'UTF-8');
        } elseif (! $isUpdate) {
            $output['knowledge_tag_filter'] = '';
        }

        if (array_key_exists('cross_collection_mode', $data)) {
            $output['cross_collection_mode'] = $this->toFlag($data['cross_collection_mode']);
        } elseif (! $isUpdate) {
            $output['cross_collection_mode'] = 0;
        }

        if (array_key_exists('entity_filter', $data)) {
            $entityIds = $this->parseEntityFilter((string) $data['entity_filter']);
            if ($entityIds !== []) {
                $existingCount = EntityRecord::query()->whereIn('id', $entityIds)->count();
                if ($existingCount !== count($entityIds)) {
                    $fieldErrors['entity_filter'] = '选择的实体不存在';
                }
            }
            $output['entity_filter'] = implode(',', $entityIds);
        } elseif (! $isUpdate) {
            $output['entity_filter'] = '';
        }

        if (array_key_exists('case_filter', $data)) {
            $caseIds = $this->parseCaseFilter((string) $data['case_filter']);
            if ($caseIds !== []) {
                $existingCount = CaseRecord::query()->whereIn('id', $caseIds)->count();
                if ($existingCount !== count($caseIds)) {
                    $fieldErrors['case_filter'] = '选择的案例不存在';
                }
            }
            $output['case_filter'] = implode(',', $caseIds);
        } elseif (! $isUpdate) {
            $output['case_filter'] = '';
        }

        if (array_key_exists('crm_source_type', $data) || array_key_exists('crm_source_id', $data)) {
            $sourceType = trim((string) ($data['crm_source_type'] ?? ''));
            $sourceId = (int) ($data['crm_source_id'] ?? 0);
            if ($sourceType === '') {
                $output['crm_source_type'] = '';
                $output['crm_source_id'] = null;
            } elseif (! in_array($sourceType, ['customer', 'inquiry', 'ticket'], true)) {
                $fieldErrors['crm_source_type'] = 'CRM 来源类型无效';
            } elseif ($sourceId <= 0) {
                $fieldErrors['crm_source_id'] = '请选择 CRM 来源记录';
            } else {
                $output['crm_source_type'] = $sourceType;
                $output['crm_source_id'] = $sourceId;
            }
        } elseif (! $isUpdate) {
            $output['crm_source_type'] = '';
            $output['crm_source_id'] = null;
        }

        if (array_key_exists('image_tag_filter', $data)) {
            $output['image_tag_filter'] = mb_substr(trim((string) $data['image_tag_filter']), 0, 1000, 'UTF-8');
        } elseif (! $isUpdate) {
            $output['image_tag_filter'] = '';
        }

        $effectiveCategoryMode = $output['category_mode'] ?? (($data['category_mode'] ?? 'smart') ?: 'smart');
        if ($effectiveCategoryMode === 'fixed') {
            $fixedCategoryId = $output['fixed_category_id'] ?? null;
            if ($fixedCategoryId === null || $fixedCategoryId <= 0) {
                $fieldErrors['fixed_category_id'] = '固定分类模式下必须选择一个分类';
            }
        }

        if (! empty($fieldErrors)) {
            throw new ApiException('validation_failed', '参数校验失败', 422, [
                'field_errors' => $fieldErrors,
            ]);
        }

        return $output;
    }

    /**
     * 激活任务并确保调度配置就绪。
     *
     * @param  bool  $resetNextRun  true 时 next_run_at 立即置为 now（手动启动场景）
     */
    private function activateTask(int $taskId, bool $resetNextRun): void
    {
        $task = Task::query()->whereKey($taskId)->first(['id', 'next_run_at']);
        $updates = [
            'status' => 'active',
            'schedule_enabled' => 1,
            'updated_at' => now(),
        ];

        if ($resetNextRun || $task?->next_run_at === null) {
            $updates['next_run_at'] = now();
        }

        Task::query()->whereKey($taskId)->update($updates);
        $this->queueService->initializeTaskSchedule($taskId);
    }

    /**
     * 暂停任务并取消未开始执行（pending）的记录。
     *
     * @return int 被取消的 pending 记录数
     */
    private function pauseTask(int $taskId, string $reason): int
    {
        Task::query()->whereKey($taskId)->update([
            'status' => 'paused',
            'schedule_enabled' => 0,
            'next_run_at' => null,
            'updated_at' => now(),
        ]);

        return TaskRun::query()
            ->where('task_id', $taskId)
            ->where('status', 'pending')
            ->update([
                'status' => 'cancelled',
                'finished_at' => now(),
                'error_message' => $reason,
            ]);
    }

    /**
     * 断言任务存在，否则抛 404。
     *
     * @throws ApiException
     */
    private function ensureTaskExists(int $taskId): void
    {
        if (! Task::query()->whereKey($taskId)->exists()) {
            throw new ApiException('task_not_found', '任务不存在', 404);
        }
    }

    /**
     * 将混合输入（bool/int/string）归一化为 0/1 标记位。
     */
    private function toFlag(mixed $value): int
    {
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }

        if (is_numeric($value)) {
            return (int) $value > 0 ? 1 : 0;
        }

        $value = strtolower(trim((string) $value));

        return in_array($value, ['1', 'true', 'yes', 'on'], true) ? 1 : 0;
    }

    /**
     * @return list<int>
     */
    private function parseEntityFilter(string $entityFilter): array
    {
        return collect(preg_split('/\s*,\s*/u', trim($entityFilter), -1, PREG_SPLIT_NO_EMPTY) ?: [])
            ->map(static fn (mixed $id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return list<int>
     */
    private function parseCaseFilter(string $caseFilter): array
    {
        return collect(preg_split('/\s*,\s*/u', trim($caseFilter), -1, PREG_SPLIT_NO_EMPTY) ?: [])
            ->map(static fn ($id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();
    }
}
