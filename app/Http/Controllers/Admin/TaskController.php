<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AiModel;
use App\Models\Author;
use App\Models\CaseRecord;
use App\Models\Category;
use App\Models\CrmAfterSalesTicket;
use App\Models\CrmCustomer;
use App\Models\CrmInquiry;
use App\Models\DistributionChannel;
use App\Models\EntityRecord;
use App\Models\ImageLibrary;
use App\Models\KnowledgeBase;
use App\Models\Prompt;
use App\Models\Task;
use App\Models\TitleLibrary;
use App\Services\GeoFlow\DistributionOrchestrator;
use App\Services\GeoFlow\EntityMaterialLinkService;
use App\Services\GeoFlow\TagService;
use App\Services\GeoFlow\TaskLifecycleService;
use App\Services\GeoFlow\TaskMonitoringQueryService;
use App\Support\AdminWeb;
use App\Support\GeoFlow\CollectionOptions;
use App\Support\GeoFlow\ControlledTagGroups;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

/**
 * 任务管理页（按 bak/admin/tasks.php 行为迁移）：
 * - GET 展示任务列表与运行态信息
 * - POST 处理切换状态、删除任务
 * - JSON 接口提供批量启动/停止与状态轮询
 */
class TaskController extends Controller
{
    /**
     * @param  TaskLifecycleService  $taskLifecycleService  任务生命周期服务（创建/启动/停止任务）
     */
    public function __construct(
        private readonly TaskLifecycleService $taskLifecycleService,
        private readonly TaskMonitoringQueryService $taskMonitoringQueryService,
        private readonly DistributionOrchestrator $distributionOrchestrator,
        private readonly EntityMaterialLinkService $entityMaterialLinkService,
        private readonly TagService $tagService,
    ) {}

    /**
     * 任务管理首页：渲染列表与运行面板。
     */
    public function index(): View
    {
        try {
            $overview = $this->taskMonitoringQueryService->buildAdminOverview();
            $tasks = $overview['tasks'];
            $workers = $overview['worker_overview'];
            $queueStats = $overview['queue_overview'];
            $recentJobs = $overview['recent_runs'];
            $error = null;
        } catch (Throwable $e) {
            $tasks = [];
            $workers = [];
            $queueStats = ['pending' => 0, 'running' => 0, 'failed' => 0, 'completed' => 0];
            $recentJobs = [];
            $error = __('admin.tasks.message.query_failed', ['message' => $e->getMessage()]);
        }

        return view('admin.tasks.index', [
            'pageTitle' => __('admin.tasks.page_title'),
            'activeMenu' => 'tasks',
            'adminSiteName' => AdminWeb::siteName(),
            'tasks' => $tasks,
            'workers' => $workers,
            'queueStats' => $queueStats,
            'recentJobs' => $recentJobs,
            'legacyError' => $error,
            'taskI18n' => $this->taskI18n(),
            'taskRealtime' => $this->taskRealtimeConfig(),
        ]);
    }

    /**
     * 切换任务启停状态（active -> stop，paused -> start）。
     */
    public function toggleStatus(Request $request, int $taskId): RedirectResponse
    {
        if ($taskId <= 0) {
            return back()->withErrors(__('admin.tasks.message.status_update_failed'));
        }

        try {
            $currentStatus = (string) $request->input('status', 'paused');
            if ($currentStatus === 'active') {
                $this->taskLifecycleService->stopTask($taskId);

                return back()->with('message', __('admin.tasks.message.paused_stopped'));
            }

            $this->taskLifecycleService->startTask($taskId, false);

            return back()->with('message', __('admin.tasks.message.activated'));
        } catch (Throwable $e) {
            return back()->withErrors(__('admin.tasks.message.status_update_failed'));
        }
    }

    /**
     * 删除单个任务（含关联数据级联清理）。
     */
    public function destroyTask(int $taskId): RedirectResponse
    {
        if ($taskId <= 0) {
            return back()->withErrors(__('admin.tasks.message.status_update_failed'));
        }

        try {
            $this->taskLifecycleService->deleteTask($taskId);

            return back()->with('message', __('admin.tasks.message.delete_success'));
        } catch (Throwable $e) {
            return back()->withErrors(__('admin.tasks.message.delete_failed', ['message' => $e->getMessage()]));
        }
    }

    /**
     * 任务创建页（先接入可用创建链路，后续继续做 1:1 细节对齐）。
     */
    public function create(): View
    {
        $formOptions = $this->loadTaskFormOptions();

        // 创建页选项与 tasks.php 数据口径一致（库/模型/作者/分类）。
        return view('admin.tasks.form', [
            'pageTitle' => __('admin.task_create.page_title'),
            'activeMenu' => 'tasks',
            'adminSiteName' => AdminWeb::siteName(),
            'formOptions' => $formOptions,
            'hasCategories' => ! empty($formOptions['categories']),
            'categoryCreateUrl' => route('admin.categories.create'),
            'isEdit' => false,
            'taskForm' => null,
            'taskId' => null,
        ]);
    }

    /**
     * 创建任务（对应上游 task-create.php 的提交逻辑）。
     */
    public function store(Request $request): RedirectResponse
    {
        if (! Category::query()->exists()) {
            return redirect()
                ->route('admin.categories.create')
                ->withErrors(__('admin.task_create.error.no_categories_configured'));
        }

        $payload = $this->validateTaskForm($request);
        $this->assertTaskContextInCollection($request, $payload);
        $taskData = $this->buildTaskPayload($request, $payload);

        try {
            $createdTask = $this->taskLifecycleService->createTask($taskData);
            $createdTaskId = (int) ($createdTask['id'] ?? 0);
            if ($createdTaskId) {
                $this->distributionOrchestrator->syncTaskChannels(
                    Task::query()->whereKey((int) $createdTaskId)->firstOrFail(),
                    $this->selectedDistributionChannelIds($request)
                );
            }
        } catch (Throwable $e) {
            // 保留输入并回显服务层错误，便于在页面直接修正。
            return back()->withInput()->withErrors($e->getMessage());
        }

        return redirect()
            ->route('admin.tasks.index')
            ->with('message', __('admin.task_create.message.created'));
    }

    /**
     * 任务编辑页：与创建页共用同一 Blade 模板。
     */
    public function edit(int $taskId): View|RedirectResponse
    {
        try {
            $task = $this->taskLifecycleService->getTask($taskId);
        } catch (Throwable $e) {
            return redirect()->route('admin.tasks.index')->withErrors($e->getMessage());
        }

        $formOptions = $this->loadTaskFormOptions();

        return view('admin.tasks.form', [
            'pageTitle' => __('admin.task_edit.page_title'),
            'activeMenu' => 'tasks',
            'adminSiteName' => AdminWeb::siteName(),
            'formOptions' => $formOptions,
            'hasCategories' => ! empty($formOptions['categories']),
            'categoryCreateUrl' => route('admin.categories.create'),
            'isEdit' => true,
            'taskId' => $taskId,
            'taskForm' => [
                'task_name' => (string) ($task['name'] ?? ''),
                'collection_id' => (string) (($task['collection_id'] ?? '') ?: ''),
                'cross_collection_mode' => (int) ($task['cross_collection_mode'] ?? 0),
                'title_library_id' => (string) ($task['title_library_id'] ?? ''),
                'prompt_id' => (string) ($task['prompt_id'] ?? ''),
                'skill_prompt_id' => (string) (($task['skill_prompt_id'] ?? '') ?: ''),
                'ai_model_id' => (string) ($task['ai_model_id'] ?? ''),
                'author_id' => (string) (($task['author_id'] ?? 0) ?: 0),
                'image_library_id' => (string) (($task['image_library_id'] ?? '') ?: ''),
                'image_count' => (string) ($task['image_count'] ?? 0),
                'image_tag_filter' => (string) ($task['image_tag_filter'] ?? ''),
                'knowledge_base_id' => (string) (($task['knowledge_base_id'] ?? '') ?: ''),
                'knowledge_tag_filter' => (string) ($task['knowledge_tag_filter'] ?? ''),
                'entity_ids' => $this->parseEntityFilter((string) ($task['entity_filter'] ?? '')),
                'case_ids' => $this->parseCaseFilter((string) ($task['case_filter'] ?? '')),
                'crm_source_type' => (string) ($task['crm_source_type'] ?? ''),
                'crm_source_id' => (string) (($task['crm_source_id'] ?? '') ?: ''),
                'fixed_category_id' => (string) (($task['fixed_category_id'] ?? '') ?: ''),
                'status' => (string) ($task['status'] ?? 'active'),
                'article_limit' => (string) ($task['article_limit'] ?? 10),
                'draft_limit' => (string) ($task['draft_limit'] ?? 10),
                'publish_interval' => (string) max(1, (int) (($task['publish_interval'] ?? 3600) / 60)),
                'category_mode' => (string) ($task['category_mode'] ?? 'smart'),
                'model_selection_mode' => (string) ($task['model_selection_mode'] ?? 'fixed'),
                'need_review' => (int) ($task['need_review'] ?? 0),
                'is_loop' => (int) ($task['is_loop'] ?? 1),
                'auto_keywords' => (int) ($task['auto_keywords'] ?? 1),
                'auto_description' => (int) ($task['auto_description'] ?? 1),
                'publish_scope' => (string) ($task['publish_scope'] ?? 'local_and_distribution'),
                'distribution_channel_ids' => $this->taskDistributionChannelIds($taskId),
            ],
        ]);
    }

    /**
     * 更新任务：与创建流程共享同一套字段校验与映射逻辑。
     */
    public function update(Request $request, int $taskId): RedirectResponse
    {
        if (! Category::query()->exists()) {
            return redirect()
                ->route('admin.categories.create')
                ->withErrors(__('admin.task_create.error.no_categories_configured'));
        }

        $payload = $this->validateTaskForm($request);
        $this->assertTaskContextInCollection($request, $payload);
        $taskData = $this->buildTaskPayload($request, $payload);

        try {
            $this->taskLifecycleService->updateTask($taskId, $taskData);
            $task = Task::query()->whereKey($taskId)->firstOrFail();
            $this->distributionOrchestrator->syncTaskChannels($task, $this->selectedDistributionChannelIds($request));
        } catch (Throwable $e) {
            return back()->withInput()->withErrors($e->getMessage());
        }

        return redirect()
            ->route('admin.tasks.index')
            ->with('message', __('admin.task_edit.message.update_success'));
    }

    /**
     * 任务监控快照接口：返回任务状态与队列面板数据。
     */
    public function healthCheck(Request $request): JsonResponse
    {
        try {
            $overview = $this->taskMonitoringQueryService->buildAdminOverview();

            return response()->json([
                'success' => true,
                'tasks' => $overview['tasks'],
                'queue_overview' => $overview['queue_overview'],
                'worker_overview' => $overview['worker_overview'],
                'recent_runs' => $overview['recent_runs'],
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * 兼容旧接口：批量启动/停止单任务。
     */
    public function batchAction(Request $request): JsonResponse
    {
        // 批量接口仅允许 start/stop 两个动作，避免无效写入。
        $payload = $request->validate([
            'task_id' => ['required', 'integer', 'min:1'],
            'action' => ['required', 'string', 'in:start,stop'],
        ]);

        try {
            $taskId = (int) $payload['task_id'];
            $result = $payload['action'] === 'start'
                ? $this->taskLifecycleService->startTask($taskId, true)
                : $this->taskLifecycleService->stopTask($taskId);

            return response()->json([
                'success' => true,
                'message' => 'ok',
                'data' => $result,
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loadTasks(): array
    {
        return $this->taskMonitoringQueryService->buildTaskSnapshot();
    }

    /**
     * @return array{0: list<array<string, mixed>>, 1: array<string,int>, 2: list<array<string,mixed>>}
     */
    private function loadRuntimePanels(): array
    {
        $overview = $this->taskMonitoringQueryService->buildAdminOverview();

        return [
            $overview['worker_overview'],
            $overview['queue_overview'],
            $overview['recent_runs'],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function taskI18n(): array
    {
        // 将页面所需文案统一下发给前端脚本，避免 JS 内硬编码文本。
        return [
            'stopBatch' => __('admin.tasks.action.stop_batch'),
            'startBatch' => __('admin.tasks.action.start_batch'),
            'createdOfLimitLabel' => __('admin.tasks.label.created_of_limit', ['created' => '__CREATED__', 'limit' => '__LIMIT__']),
            'draftArticlesLabel' => __('admin.tasks.label.draft_articles', ['count' => '__COUNT__']),
            'createdArticlesLabel' => __('admin.tasks.label.created_articles', ['count' => '__COUNT__']),
            'publishedArticlesLabel' => __('admin.tasks.label.published_articles', ['count' => '__COUNT__']),
            'loopTimesLabel' => __('admin.tasks.label.loop_times', ['count' => '__COUNT__']),
            'secondsSuffix' => __('admin.common.seconds'),
            'minutesSuffix' => __('admin.common.minutes'),
            'hoursSuffix' => __('admin.common.hours'),
            'daysSuffix' => __('admin.common.days'),
            'completed' => __('admin.tasks.status.completed'),
            'waiting' => __('admin.tasks.status.waiting'),
            'waitingPublish' => __('admin.tasks.status.waiting_publish'),
            'draftPoolFull' => __('admin.tasks.status.draft_pool_full'),
            'limitReached' => __('admin.tasks.status.limit_reached'),
            'queued' => __('admin.tasks.status.pending'),
            'running' => __('admin.tasks.status.running'),
            'nextRunAt' => __('admin.tasks.label.next_run_at', ['time' => '__TIME__']),
            'publishIntervalMinutes' => __('admin.tasks.label.publish_interval_minutes', ['count' => '__COUNT__']),
            'retryingWithAttempts' => __('admin.tasks.label.retrying_with_attempts', ['current' => '__CURRENT__', 'max' => '__MAX__']),
            'pendingRunning' => __('admin.tasks.label.pending_running', ['pending' => '__PENDING__', 'running' => '__RUNNING__']),
            'estimated' => __('admin.tasks.label.estimated', ['time' => '__TIME__']),
            'latestReason' => __('admin.tasks.label.latest_reason', ['message' => '__MESSAGE__']),
            'emptyContent' => __('admin.tasks.failure.empty_content'),
            'emptyContentDetail' => __('admin.tasks.failure.empty_content_detail'),
            'contentTooShort' => __('admin.tasks.failure.content_too_short'),
            'contentTooShortDetail' => __('admin.tasks.failure.content_too_short_detail'),
            'titleExhausted' => __('admin.tasks.failure.title_exhausted'),
            'titleExhaustedDetail' => __('admin.tasks.failure.title_exhausted_detail'),
            'taskPaused' => __('admin.tasks.failure.paused'),
            'taskPausedDetail' => __('admin.tasks.failure.paused_detail'),
            'modelTimeout' => __('admin.tasks.failure.model_timeout'),
            'modelTimeoutDetail' => __('admin.tasks.failure.model_timeout_detail', ['seconds' => '__SECONDS__']),
            'recentFailed' => __('admin.tasks.failure.recent_failed'),
            'syncFailed' => __('admin.tasks.message.status_update_failed'),
            'confirmStart' => __('admin.tasks.confirm.start', ['name' => '__NAME__']),
            'confirmStop' => __('admin.tasks.confirm.stop', ['name' => '__NAME__']),
            'starting' => __('admin.tasks.action.starting'),
            'stopping' => __('admin.tasks.action.stopping'),
            'startFailed' => __('admin.tasks.message.start_failed', ['message' => '__MESSAGE__']),
            'stopFailed' => __('admin.tasks.message.stop_failed', ['message' => '__MESSAGE__']),
            'requestFailed' => __('admin.tasks.message.request_failed', ['message' => '__MESSAGE__']),
            'taskQueued' => __('admin.tasks.message.task_queued', ['name' => '__NAME__']),
            'taskStopped' => __('admin.tasks.message.task_stopped', ['name' => '__NAME__']),
            'enabledStatus' => __('admin.tasks.status.enabled'),
            'disabledStatus' => __('admin.tasks.status.disabled'),
            'noRunnable' => __('admin.tasks.message.no_runnable'),
            'confirmRunAll' => __('admin.tasks.confirm.run_all'),
            'bulkSubmitted' => __('admin.tasks.message.bulk_submitted', ['success' => '__SUCCESS__', 'total' => '__TOTAL__']),
            'bulkSubmittedPartial' => __('admin.tasks.message.bulk_submitted_partial', ['success' => '__SUCCESS__', 'total' => '__TOTAL__']),
            'activating' => __('admin.tasks.action.activating'),
            'pausing' => __('admin.tasks.action.pausing'),
            'confirmActivate' => __('admin.tasks.confirm.activate'),
            'confirmPause' => __('admin.tasks.confirm.pause'),
        ];
    }

    /**
     * @return array{enabled:bool,key:string,host:string,port:int,scheme:string}
     */
    private function taskRealtimeConfig(): array
    {
        $reverbApp = config('reverb.apps.apps.0', []);
        $host = (string) (config('reverb.servers.reverb.hostname') ?: config('app.url'));
        $parsedHost = parse_url($host, PHP_URL_HOST);

        return [
            'enabled' => (string) config('broadcasting.default') === 'reverb',
            'key' => (string) ($reverbApp['key'] ?? ''),
            'host' => $parsedHost ? (string) $parsedHost : (string) $host,
            'port' => (int) (config('reverb.apps.apps.0.options.port') ?: 443),
            'scheme' => (string) (config('reverb.apps.apps.0.options.scheme') ?: 'https'),
        ];
    }

    /**
     * @return array{
     *     titleLibraries: list<array{id:int,name:string}>,
     *     prompts: list<array{id:int,name:string}>,
     *     skillPrompts: list<array{id:int,name:string}>,
     *     aiModels: list<array{id:int,name:string}>,
     *     imageLibraries: list<array{id:int,name:string,count:int}>,
     *     imageTags: list<array{id:int,label:string,count:int}>,
     *     knowledgeBases: list<array{id:int,name:string}>,
     *     caseOptions: list<array{id:int,label:string}>,
     *     knowledgeTags: list<array{id:int,label:string,count:int}>,
     *     authors: list<array{id:int,name:string}>,
     *     categories: list<array{id:int,name:string}>,
     *     distributionChannels: list<array{id:int,name:string,domain:string}>
     * }
     */
    private function loadTaskFormOptions(): array
    {
        // 直接附带标题数，避免 Blade 层再次查询。
        $titleLibraries = TitleLibrary::query()
            ->select(['id', 'collection_id', 'name'])
            ->selectRaw('(SELECT COUNT(*) FROM titles WHERE titles.library_id = title_libraries.id) AS title_count')
            ->orderByDesc('id')
            ->get()
            ->map(static fn (TitleLibrary $row): array => [
                'id' => (int) $row->id,
                'collection_id' => (int) ($row->collection_id ?? 0),
                'name' => (string) $row->name,
                'count' => (int) ($row->title_count ?? 0),
            ])
            ->all();

        $prompts = Prompt::query()
            ->select(['id', 'name'])
            ->where('type', 'content')
            ->orderByDesc('id')
            ->get()
            ->map(static fn (Prompt $row): array => ['id' => (int) $row->id, 'name' => (string) $row->name])
            ->all();

        $skillPrompts = Prompt::query()
            ->select(['id', 'name'])
            ->where('type', 'skill')
            ->orderBy('name')
            ->get()
            ->map(static fn (Prompt $row): array => ['id' => (int) $row->id, 'name' => (string) $row->name])
            ->all();

        $aiModels = AiModel::query()
            ->select(['id', 'name'])
            ->where('status', 'active')
            ->where(function ($query): void {
                $query->whereNull('model_type')
                    ->orWhere('model_type', '')
                    ->orWhere('model_type', 'chat');
            })
            ->orderBy('failover_priority')
            ->orderByDesc('id')
            ->get()
            ->map(static fn (AiModel $row): array => ['id' => (int) $row->id, 'name' => (string) $row->name])
            ->all();

        $imageLibraryEntityIds = DB::table('entity_material_links')
            ->where('linkable_type', ImageLibrary::class)
            ->select(['linkable_id', 'entity_id'])
            ->get()
            ->groupBy('linkable_id')
            ->map(static fn ($rows): array => collect($rows)->pluck('entity_id')->map(static fn ($id): int => (int) $id)->unique()->values()->all())
            ->all();

        // 兼容上游展示：图库名称 + 图片数量。
        $imageLibraries = ImageLibrary::query()
            ->select(['id', 'collection_id', 'name'])
            ->selectRaw('(SELECT COUNT(*) FROM images WHERE images.library_id = image_libraries.id) AS image_count')
            ->orderBy('name')
            ->get()
            ->map(static fn (ImageLibrary $row): array => [
                'id' => (int) $row->id,
                'collection_id' => (int) ($row->collection_id ?? 0),
                'name' => (string) $row->name,
                'count' => (int) ($row->image_count ?? 0),
                'entity_ids' => $imageLibraryEntityIds[(int) $row->id] ?? [],
            ])
            ->all();

        $knowledgeBases = KnowledgeBase::query()
            ->select(['id', 'collection_id', 'name'])
            ->orderBy('name')
            ->get()
            ->map(static fn (KnowledgeBase $row): array => [
                'id' => (int) $row->id,
                'collection_id' => (int) ($row->collection_id ?? 0),
                'name' => (string) $row->name,
            ])
            ->all();

        $caseOptions = CaseRecord::query()
            ->with('entities:id,name')
            ->select(['id', 'entity_id', 'collection_id', 'title', 'case_type'])
            ->orderByDesc('id')
            ->limit(500)
            ->get()
            ->map(static fn (CaseRecord $row): array => [
                'id' => (int) $row->id,
                'label' => trim((string) $row->title.(($e = $row->entities->first()) ? ' / '.$e->name : '').($row->case_type ? ' / '.$row->case_type : '')),
                'collection_id' => (int) ($row->collection_id ?? 0),
            ])
            ->filter(static fn (array $row): bool => $row['label'] !== '')
            ->values()
            ->all();

        $imageTags = [];
        $knowledgeTags = [];

        $authors = Author::query()
            ->select(['id', 'name'])
            ->orderBy('name')
            ->get()
            ->map(static fn (Author $row): array => ['id' => (int) $row->id, 'name' => (string) $row->name])
            ->all();

        $categories = Category::query()
            ->select(['id', 'name'])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(static fn (Category $row): array => ['id' => (int) $row->id, 'name' => (string) $row->name])
            ->all();

        $distributionChannels = DistributionChannel::query()
            ->select(['id', 'name', 'domain'])
            ->where('status', 'active')
            ->orderBy('name')
            ->get()
            ->map(static fn (DistributionChannel $row): array => [
                'id' => (int) $row->id,
                'name' => (string) $row->name,
                'domain' => (string) $row->domain,
            ])
            ->all();

        return [
            'collections' => CollectionOptions::all(true),
            'controlledTagGroups' => ControlledTagGroups::names(),
            'entityOptions' => $this->entityMaterialLinkService->entityOptions(),
            'titleLibraries' => $titleLibraries,
            'prompts' => $prompts,
            'skillPrompts' => $skillPrompts,
            'aiModels' => $aiModels,
            'imageLibraries' => $imageLibraries,
            'imageTags' => $imageTags,
            'knowledgeBases' => $knowledgeBases,
            'caseOptions' => $caseOptions,
            'crmSourceOptions' => $this->crmSourceOptions(),
            'knowledgeTags' => $knowledgeTags,
            'authors' => $authors,
            'categories' => $categories,
            'distributionChannels' => $distributionChannels,
        ];
    }

    /**
     * @return array{
     *     task_name: string,
     *     title_library_id: int,
     *     prompt_id: int,
     *     skill_prompt_id: int|null,
     *     ai_model_id: int,
     *     author_id: int|null,
     *     image_library_id: int|null,
     *     image_count: int|null,
     *     image_tag_filter: string|null,
     *     knowledge_base_id: int|null,
     *     knowledge_tag_filter: string|null,
     *     fixed_category_id: int|null,
     *     status: string,
     *     article_limit: int|null,
     *     draft_limit: int|null,
     *     publish_interval: int|null,
     *     category_mode: string|null,
     *     model_selection_mode: string|null
     * }
     */
    private function validateTaskForm(Request $request): array
    {
        $rules = [
            'task_name' => ['required', 'string', 'max:200'],
            'collection_id' => ['nullable', 'integer', 'min:1', Rule::exists('collections', 'id')],
            'cross_collection_mode' => ['nullable', 'string'],
            'entity_ids' => ['nullable', 'array'],
            'entity_ids.*' => ['integer', 'min:1', Rule::exists('entities', 'id')],
            'case_ids' => ['nullable', 'array'],
            'case_ids.*' => ['integer', 'min:1', Rule::exists('case_records', 'id')],
            'crm_source_type' => ['nullable', 'string', Rule::in(['', 'customer', 'inquiry', 'ticket'])],
            'crm_source_id' => ['nullable', 'integer', 'min:1'],
            'title_library_id' => ['required', 'integer', 'min:1'],
            'prompt_id' => ['required', 'integer', 'min:1', Rule::exists('prompts', 'id')->where('type', 'content')],
            'skill_prompt_id' => ['nullable', 'integer', 'min:1', Rule::exists('prompts', 'id')->where('type', 'skill')],
            'ai_model_id' => ['required', 'integer', 'min:1'],
            'author_id' => ['nullable', 'integer', 'min:0'],
            'image_library_id' => ['nullable', 'integer', 'min:1'],
            'image_count' => ['nullable', 'integer', 'min:0', 'max:5'],
            'image_tag_filters' => ['nullable', 'array'],
            'image_tag_filters.*' => ['string', 'max:220'],
            'image_tag_filter_present' => ['nullable', 'string'],
            'knowledge_base_id' => ['nullable', 'integer', 'min:1'],
            'knowledge_tag_filters' => ['nullable', 'array'],
            'knowledge_tag_filters.*' => ['string', 'max:220'],
            'knowledge_tag_filter_present' => ['nullable', 'string'],
            'fixed_category_id' => ['nullable', 'integer', 'min:1'],
            'status' => ['required', 'string', 'in:active,paused'],
            'article_limit' => ['nullable', 'integer', 'min:1', 'max:99999'],
            'draft_limit' => ['nullable', 'integer', 'min:1', 'max:9999'],
            'publish_interval' => ['nullable', 'integer', 'min:1'],
            'category_mode' => ['nullable', 'string', 'in:smart,fixed,random'],
            'model_selection_mode' => ['nullable', 'string', 'in:fixed,smart_failover'],
            'publish_scope' => ['nullable', 'string', 'in:local_and_distribution,distribution_only,local_only'],
            'distribution_channel_ids' => ['nullable', 'array'],
            'distribution_channel_ids.*' => ['integer', 'min:1'],
        ];

        foreach (ControlledTagGroups::names() as $groupName) {
            $fieldName = $this->controlledTagFieldName($groupName);
            $rules[$fieldName] = ['nullable', 'array'];
            $rules[$fieldName.'.*'] = ['string', 'max:220'];
        }

        $payload = $request->validate($rules);
        $this->assertCrmSourceExists($payload);

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, int|string|null>
     */
    private function buildTaskPayload(Request $request, array $payload): array
    {
        $categoryMode = (string) ($payload['category_mode'] ?? 'smart');
        if ($categoryMode === 'random') {
            $categoryMode = 'smart';
        }

        return [
            'name' => (string) $payload['task_name'],
            'collection_id' => isset($payload['collection_id']) ? (int) $payload['collection_id'] : null,
            'cross_collection_mode' => $request->boolean('cross_collection_mode') ? 1 : 0,
            'title_library_id' => (int) $payload['title_library_id'],
            'image_library_id' => isset($payload['image_library_id']) ? (int) $payload['image_library_id'] : null,
            'image_count' => (int) ($payload['image_count'] ?? 0),
            'image_tag_filter' => $this->normalizeTagLabelFilters($request, 'image_tag_filters'),
            'prompt_id' => (int) $payload['prompt_id'],
            'skill_prompt_id' => isset($payload['skill_prompt_id']) ? (int) $payload['skill_prompt_id'] : null,
            'ai_model_id' => (int) $payload['ai_model_id'],
            'author_id' => isset($payload['author_id']) && (int) $payload['author_id'] > 0 ? (int) $payload['author_id'] : null,
            'knowledge_base_id' => isset($payload['knowledge_base_id']) ? (int) $payload['knowledge_base_id'] : null,
            'knowledge_tag_filter' => $this->normalizeKnowledgeTagFilters($request),
            'entity_filter' => implode(',', $this->selectedEntityIds($request)),
            'case_filter' => implode(',', $this->selectedCaseIds($request)),
            'crm_source_type' => $this->normalizeCrmSourceType($payload),
            'crm_source_id' => $this->normalizeCrmSourceId($payload),
            'fixed_category_id' => isset($payload['fixed_category_id']) ? (int) $payload['fixed_category_id'] : null,
            'status' => (string) $payload['status'],
            'publish_scope' => (string) ($payload['publish_scope'] ?? 'local_and_distribution'),
            'article_limit' => (int) ($payload['article_limit'] ?? 10),
            'draft_limit' => (int) ($payload['draft_limit'] ?? 10),
            'publish_interval' => max(1, (int) ($payload['publish_interval'] ?? 60)) * 60,
            'need_review' => $request->boolean('need_review') ? 1 : 0,
            'is_loop' => $request->boolean('is_loop') ? 1 : 0,
            'category_mode' => $categoryMode,
            'model_selection_mode' => (string) ($payload['model_selection_mode'] ?? 'fixed'),
            'auto_keywords' => $request->boolean('auto_keywords') ? 1 : 0,
            'auto_description' => $request->boolean('auto_description') ? 1 : 0,
        ];
    }

    /**
     * @return list<int>
     */
    private function selectedDistributionChannelIds(Request $request): array
    {
        if ((string) $request->input('publish_scope', 'local_and_distribution') === 'local_only') {
            return [];
        }

        return collect($request->input('distribution_channel_ids', []))
            ->map(static fn ($id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return list<int>
     */
    private function selectedEntityIds(Request $request): array
    {
        return collect($request->input('entity_ids', []))
            ->map(static fn ($id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return list<int>
     */
    private function selectedCaseIds(Request $request): array
    {
        return collect($request->input('case_ids', []))
            ->map(static fn ($id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function assertTaskContextInCollection(Request $request, array $payload): void
    {
        if ($request->boolean('cross_collection_mode')) {
            return;
        }

        $collectionId = (int) ($payload['collection_id'] ?? 0);
        if ($collectionId <= 0) {
            return;
        }

        $entityIds = $this->selectedEntityIds($request);
        if ($entityIds !== []) {
            $invalidEntityExists = EntityRecord::query()
                ->whereIn('id', $entityIds)
                ->where('collection_id', '<>', $collectionId)
                ->exists();
            $missingEntityCollectionExists = EntityRecord::query()
                ->whereIn('id', $entityIds)
                ->whereNull('collection_id')
                ->exists();
            if ($invalidEntityExists || $missingEntityCollectionExists) {
                throw ValidationException::withMessages([
                    'entity_ids' => __('admin.task_create.error.entity_collection_mismatch'),
                ]);
            }
        }

        $this->assertSelectedMaterialInCollection(
            TitleLibrary::class,
            (int) ($payload['title_library_id'] ?? 0),
            $collectionId,
            'title_library_id',
            __('admin.task_create.error.title_library_collection_mismatch')
        );

        $this->assertSelectedMaterialInCollection(
            KnowledgeBase::class,
            (int) ($payload['knowledge_base_id'] ?? 0),
            $collectionId,
            'knowledge_base_id',
            __('admin.task_create.error.knowledge_base_collection_mismatch')
        );

        $this->assertSelectedMaterialInCollection(
            ImageLibrary::class,
            (int) ($payload['image_library_id'] ?? 0),
            $collectionId,
            'image_library_id',
            __('admin.task_create.error.image_library_collection_mismatch')
        );

        $caseIds = $this->selectedCaseIds($request);
        if ($caseIds !== []) {
            $invalidCaseExists = CaseRecord::query()
                ->whereIn('id', $caseIds)
                ->where('collection_id', '<>', $collectionId)
                ->exists();
            $missingCaseCollectionExists = CaseRecord::query()
                ->whereIn('id', $caseIds)
                ->whereNull('collection_id')
                ->exists();
            if ($invalidCaseExists || $missingCaseCollectionExists) {
                throw ValidationException::withMessages([
                    'case_ids' => __('admin.task_create.error.case_collection_mismatch'),
                ]);
            }
        }

        $crmSourceType = $this->normalizeCrmSourceType($payload);
        $crmSourceId = $this->normalizeCrmSourceId($payload);
        if ($crmSourceType !== '' && $crmSourceId !== null) {
            $sourceCollectionId = $this->crmSourceCollectionId($crmSourceType, $crmSourceId);
            if ($sourceCollectionId !== null && $sourceCollectionId !== $collectionId) {
                throw ValidationException::withMessages([
                    'crm_source_id' => '选择的 CRM 来源不属于当前 Collection。',
                ]);
            }
        }
    }

    /**
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $modelClass
     */
    private function assertSelectedMaterialInCollection(string $modelClass, int $modelId, int $collectionId, string $field, string $message): void
    {
        if ($modelId <= 0) {
            return;
        }

        $materialCollectionId = $modelClass::query()
            ->whereKey($modelId)
            ->value('collection_id');
        if ($materialCollectionId !== null && (int) $materialCollectionId !== $collectionId) {
            throw ValidationException::withMessages([
                $field => $message,
            ]);
        }
    }

    /**
     * @return list<int>
     */
    private function parseEntityFilter(string $entityFilter): array
    {
        return collect(preg_split('/\s*,\s*/u', trim($entityFilter), -1, PREG_SPLIT_NO_EMPTY) ?: [])
            ->map(static fn ($id): int => (int) $id)
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

    private function normalizeKnowledgeTagFilters(Request $request): string
    {
        $fields = [
            'knowledge_tag_filters',
        ];
        foreach (ControlledTagGroups::names() as $groupName) {
            $fields[] = $this->controlledTagFieldName($groupName);
        }
        $labels = [];
        foreach ($fields as $fieldName) {
            $rawFilters = $request->input($fieldName, []);
            if (! is_array($rawFilters)) {
                continue;
            }
            foreach ($rawFilters as $value) {
                $label = trim((string) $value);
                if ($label !== '') {
                    $labels[] = $label;
                }
            }
        }

        $tagText = collect($labels)
            ->unique(static fn (string $value): string => mb_strtolower($value, 'UTF-8'))
            ->implode(', ');

        return $this->tagService->normalizeTagText($tagText);
    }

    private function controlledTagFieldName(string $groupName): string
    {
        return match ($groupName) {
            'Product Line' => 'product_line_tag_filters',
            'Product Model' => 'product_model_tag_filters',
            'Industry' => 'industry_tag_filters',
            'Topic' => 'topic_tag_filters',
            'Content Type' => 'content_type_tag_filters',
            'Audience' => 'audience_tag_filters',
            'Intent' => 'intent_tag_filters',
            default => 'controlled_tag_filters_'.Str::slug($groupName, '_'),
        };
    }

    private function normalizeTagLabelFilters(Request $request, string $fieldName): string
    {
        $rawFilters = $request->input($fieldName, []);
        if (! is_array($rawFilters)) {
            $rawFilters = [];
        }

        $tagText = collect($rawFilters)
            ->map(static fn (mixed $value): string => trim((string) $value))
            ->filter(static fn (string $value): bool => $value !== '')
            ->unique(static fn (string $value): string => mb_strtolower($value, 'UTF-8'))
            ->implode(', ');

        return $this->tagService->normalizeTagText($tagText);
    }

    /**
     * @return list<array{id:int,type:string,label:string,meta:string,collection_id:int}>
     */
    private function crmSourceOptions(): array
    {
        $customers = CrmCustomer::query()
            ->with('collection')
            ->orderByDesc('updated_at')
            ->limit(200)
            ->get(['id', 'collection_id', 'company_name', 'country', 'status'])
            ->map(static fn (CrmCustomer $customer): array => [
                'id' => (int) $customer->id,
                'type' => 'customer',
                'label' => (string) $customer->company_name,
                'meta' => trim('客户 '.(string) ($customer->country ?? '').' '.(string) ($customer->status ?? '').' '.(string) ($customer->collection?->name ?? '')),
                'collection_id' => (int) ($customer->collection_id ?? 0),
            ]);

        $inquiries = CrmInquiry::query()
            ->with('collection')
            ->orderByDesc('updated_at')
            ->limit(200)
            ->get(['id', 'collection_id', 'subject', 'priority', 'status'])
            ->map(static fn (CrmInquiry $inquiry): array => [
                'id' => (int) $inquiry->id,
                'type' => 'inquiry',
                'label' => (string) $inquiry->subject,
                'meta' => trim('询盘 '.(string) ($inquiry->priority ?? '').' '.(string) ($inquiry->status ?? '').' '.(string) ($inquiry->collection?->name ?? '')),
                'collection_id' => (int) ($inquiry->collection_id ?? 0),
            ]);

        $tickets = CrmAfterSalesTicket::query()
            ->with('collection')
            ->orderByDesc('updated_at')
            ->limit(200)
            ->get(['id', 'collection_id', 'title', 'priority', 'status'])
            ->map(static fn (CrmAfterSalesTicket $ticket): array => [
                'id' => (int) $ticket->id,
                'type' => 'ticket',
                'label' => (string) $ticket->title,
                'meta' => trim('售后 '.(string) ($ticket->priority ?? '').' '.(string) ($ticket->status ?? '').' '.(string) ($ticket->collection?->name ?? '')),
                'collection_id' => (int) ($ticket->collection_id ?? 0),
            ]);

        return $customers->concat($inquiries)->concat($tickets)->values()->all();
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function assertCrmSourceExists(array $payload): void
    {
        $sourceType = $this->normalizeCrmSourceType($payload);
        $sourceId = $this->normalizeCrmSourceId($payload);
        if ($sourceType === '') {
            return;
        }
        if ($sourceId === null) {
            throw ValidationException::withMessages([
                'crm_source_id' => '请选择 CRM 来源记录。',
            ]);
        }
        if ($this->crmSourceCollectionId($sourceType, $sourceId) === null) {
            throw ValidationException::withMessages([
                'crm_source_id' => '选择的 CRM 来源不存在。',
            ]);
        }
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function normalizeCrmSourceType(array $payload): string
    {
        $sourceType = trim((string) ($payload['crm_source_type'] ?? ''));

        return in_array($sourceType, ['customer', 'inquiry', 'ticket'], true) ? $sourceType : '';
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function normalizeCrmSourceId(array $payload): ?int
    {
        $sourceType = $this->normalizeCrmSourceType($payload);
        $sourceId = (int) ($payload['crm_source_id'] ?? 0);

        return $sourceType !== '' && $sourceId > 0 ? $sourceId : null;
    }

    private function crmSourceCollectionId(string $sourceType, int $sourceId): ?int
    {
        $modelClass = match ($sourceType) {
            'customer' => CrmCustomer::class,
            'inquiry' => CrmInquiry::class,
            'ticket' => CrmAfterSalesTicket::class,
            default => null,
        };
        if ($modelClass === null || $sourceId <= 0) {
            return null;
        }

        $record = $modelClass::query()->whereKey($sourceId)->first(['id', 'collection_id']);
        if (! $record) {
            return null;
        }

        return (int) ($record->collection_id ?? 0) ?: null;
    }

    /**
     * @return list<int>
     */
    private function taskDistributionChannelIds(int $taskId): array
    {
        $task = Task::query()->whereKey($taskId)->first();
        if (! $task) {
            return [];
        }

        return $task->distributionChannels()
            ->pluck('distribution_channels.id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }
}
