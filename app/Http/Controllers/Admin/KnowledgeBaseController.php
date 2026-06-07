<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\SyncKnowledgeBaseChunksJob;
use App\Models\AiModel;
use App\Models\CollectionRecord;
use App\Models\EntityRecord;
use App\Models\KnowledgeBase;
use App\Models\KnowledgeChunk;
use App\Models\Tag;
use App\Models\Task;
use App\Services\GeoFlow\EntityMaterialLinkService;
use App\Services\GeoFlow\MaterialFormAnalysisService;
use App\Services\GeoFlow\TagService;
use App\Support\AdminWeb;
use App\Support\GeoFlow\CollectionOptions;
use App\Support\GeoFlow\ControlledTagGroups;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Rules\File;
use Illuminate\View\View;

/**
 * 知识库管理控制器。
 */
class KnowledgeBaseController extends Controller
{
    public function __construct(
        private readonly TagService $tagService,
        private readonly EntityMaterialLinkService $entityMaterialLinkService,
        private readonly MaterialFormAnalysisService $materialFormAnalysisService,
    ) {}

    /**
     * 列表页。
     */
    public function index(Request $request): View
    {
        $tagFilter = trim((string) $request->query('tag', ''));
        $search = trim((string) $request->query('search', ''));
        $savedView = $this->selectedSavedView($request);
        $collectionId = $this->selectedCollectionId($request);
        $knowledgePurpose = $this->selectedKnowledgePurpose($request);
        $knowledgeType = $this->selectedKnowledgeType($request);
        $knowledgeRole = $this->selectedKnowledgeRole($request);
        $importance = $this->selectedImportance($request);
        $entityId = $this->selectedEntityId($request);
        $tagGroup = $this->selectedTagGroup($request);
        $status = $this->selectedStatus($request);

        if ($knowledgePurpose !== null) {
            $purpose = $this->knowledgePurposeDefinition($knowledgePurpose);
            $knowledgeType = $purpose['type'];
            $knowledgeRole = $purpose['role'];
            $importance = $purpose['importance'];
        }

        $this->applySavedViewFilters($savedView, $tagFilter, $knowledgeType, $knowledgeRole, $status);

        return view('admin.knowledge-bases.index', [
            'pageTitle' => __('admin.knowledge_bases.page_title'),
            'activeMenu' => 'materials',
            'adminSiteName' => AdminWeb::siteName(),
            'knowledgeBases' => $this->loadKnowledgeBases(
                $tagFilter,
                $search,
                $collectionId,
                $knowledgeType,
                $knowledgeRole,
                $importance,
                $entityId,
                $tagGroup,
                $status
            ),
            'stats' => $this->loadStats(),
            'hasDefaultEmbeddingModel' => $this->hasDefaultEmbeddingModel(),
            'search' => $search,
            'savedView' => $savedView,
            'tagFilter' => $tagFilter,
            'tagGroup' => $tagGroup,
            'collectionId' => $collectionId,
            'knowledgeType' => $knowledgeType,
            'knowledgeRole' => $knowledgeRole,
            'importance' => $importance,
            'knowledgePurpose' => $knowledgePurpose,
            'entityId' => $entityId,
            'status' => $status,
            'collectionOptions' => CollectionOptions::all(),
            'knowledgeTypeOptions' => $this->knowledgeTypeOptions(),
            'knowledgeRoleOptions' => $this->knowledgeRoleOptions(),
            'importanceOptions' => $this->importanceOptions(),
            'knowledgePurposeOptions' => $this->knowledgePurposeOptions(),
            'entityOptions' => $this->entityMaterialLinkService->entityOptions($collectionId),
            'savedViewOptions' => $this->savedViewOptions(),
            'statusOptions' => $this->knowledgeStatusOptions(),
            'tagGroupOptions' => $this->tagGroupOptions(),
        ]);
    }

    /**
     * 创建表单页。
     */
    public function create(): View
    {
        return view('admin.knowledge-bases.form', [
            'pageTitle' => __('admin.knowledge_bases.page_title'),
            'activeMenu' => 'materials',
            'adminSiteName' => AdminWeb::siteName(),
            'isEdit' => false,
            'knowledgeBaseId' => 0,
            'knowledgeForm' => $this->emptyForm(),
            'collectionOptions' => CollectionOptions::all(true),
            'knowledgeTypeOptions' => $this->knowledgeTypeOptions(),
            'knowledgeRoleOptions' => $this->knowledgeRoleOptions(),
            'importanceOptions' => $this->importanceOptions(),
            'statusOptions' => $this->knowledgeStatusOptions(),
            'entityOptions' => $this->entityMaterialLinkService->entityOptions(),
            'selectedEntityIds' => [],
            'entityRelationType' => 'supporting_reference',
            'entityRelationTypesById' => [],
            'knowledgeRelationTypeOptions' => $this->entityMaterialLinkService->knowledgeRelationTypeOptions(),
            'aiModelOptions' => $this->materialFormAnalysisService->modelOptions(),
            'tagsText' => '',
            'selectedTagIds' => [],
            'tagOptions' => [],
        ]);
    }

    public function analyze(Request $request): \Illuminate\Http\JsonResponse
    {
        $payload = $request->validate([
            'content' => ['required', 'string', 'max:50000'],
            'title' => ['nullable', 'string', 'max:200'],
            'source_url' => ['nullable', 'string', 'max:500'],
            'ai_model_id' => ['nullable', 'integer', 'min:0'],
            'analysis_instructions' => ['nullable', 'string', 'max:4000'],
        ]);

        $text = trim(implode("\n\n", array_filter([
            (string) ($payload['title'] ?? ''),
            (string) ($payload['source_url'] ?? ''),
            (string) $payload['content'],
        ], static fn (string $value): bool => trim($value) !== '')));

        return response()->json([
            'fields' => $this->materialFormAnalysisService->analyzeKnowledge(
                $text,
                (int) ($payload['ai_model_id'] ?? 0),
                array_merge($this->knowledgeClassificationContext(), [
                    'raw_content' => (string) $payload['content'],
                ]),
                (string) ($payload['analysis_instructions'] ?? '')
            ),
        ]);
    }

    /**
     * 知识库详情页，展示切块与向量状态。
     */
    public function detail(int $knowledgeBaseId): View|RedirectResponse
    {
        $knowledgeBase = KnowledgeBase::query()->with('collection')->whereKey($knowledgeBaseId)->firstOrFail();
        $selectedTagIds = $this->tagService->selectedTagIdsFor($knowledgeBase);

        return view('admin.knowledge-bases.detail', [
            'pageTitle' => __('admin.knowledge_detail.page_title'),
            'activeMenu' => 'materials',
            'adminSiteName' => AdminWeb::siteName(),
            'knowledgeBase' => $knowledgeBase,
            'collectionOptions' => CollectionOptions::all(),
            'knowledgeTypeOptions' => $this->knowledgeTypeOptions(),
            'knowledgeRoleOptions' => $this->knowledgeRoleOptions(),
            'importanceOptions' => $this->importanceOptions(),
            'statusOptions' => $this->knowledgeStatusOptions(),
            'entityOptions' => $this->entityMaterialLinkService->entityOptions((int) ($knowledgeBase->collection_id ?? 0) ?: null),
            'selectedEntityIds' => $this->entityMaterialLinkService->selectedEntityIdsFor($knowledgeBase),
            'entityRelationType' => $this->entityMaterialLinkService->selectedKnowledgeRelationTypeFor($knowledgeBase),
            'entityRelationTypesById' => $this->entityMaterialLinkService->selectedKnowledgeRelationTypesFor($knowledgeBase),
            'knowledgeRelationTypeOptions' => $this->entityMaterialLinkService->knowledgeRelationTypeOptions(),
            'aiModelOptions' => $this->materialFormAnalysisService->modelOptions(),
            'tagsText' => $this->tagService->tagTextFor($knowledgeBase),
            'selectedTagIds' => $selectedTagIds,
            'tagOptions' => $this->tagService->tagOptionsForIds($selectedTagIds),
            'relatedTasks' => $this->loadRelatedTasks($knowledgeBaseId),
            'chunkStats' => $this->loadChunkStats($knowledgeBaseId),
            'chunkPreviewRows' => $this->loadChunkPreviewRows($knowledgeBaseId),
        ]);
    }

    /**
     * 详情页更新知识库内容并同步 chunk。
     */
    public function updateFromDetail(Request $request, int $knowledgeBaseId): RedirectResponse
    {
        $knowledgeBase = KnowledgeBase::query()->whereKey($knowledgeBaseId)->firstOrFail();

        $payload = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string'],
            'summary' => ['nullable', 'string'],
            'source_url' => ['nullable', 'string', 'max:500'],
            'content' => ['required', 'string'],
            'file_type' => ['required', 'in:markdown,word,text'],
            'knowledge_type' => ['nullable', 'string', Rule::in(KnowledgeBase::KNOWLEDGE_TYPES)],
            'knowledge_role' => ['nullable', 'string', Rule::in(KnowledgeBase::KNOWLEDGE_ROLES)],
            'importance' => ['nullable', 'integer', 'min:1', 'max:5'],
            'status' => ['nullable', 'string', Rule::in(['active', 'inactive'])],
            'collection_id' => ['nullable', 'integer', 'min:1', Rule::exists('collections', 'id')],
            'tag_ids' => ['nullable', 'array'],
            'tag_ids.*' => ['integer', Rule::exists('tags', 'id')->where('type', 'material')],
            'entity_ids' => ['nullable', 'array'],
            'entity_ids.*' => ['integer', Rule::exists('entities', 'id')],
            'entity_relation_type' => ['nullable', 'string', Rule::in(EntityMaterialLinkService::KNOWLEDGE_RELATION_TYPES)],
            'entity_relation_types' => ['nullable', 'array'],
            'entity_relation_types.*' => ['string', Rule::in(EntityMaterialLinkService::KNOWLEDGE_RELATION_TYPES)],
        ], [
            'name.required' => __('admin.knowledge_bases.error.name_required'),
            'content.required' => __('admin.knowledge_bases.error.content_required'),
        ]);

        $content = trim((string) $payload['content']);
        $metadata = $this->knowledgeMetadataFromPayload($payload);
        $tagIds = $this->requestHasTagSelection($request) ? $this->selectedTagIds($request) : null;
        $knowledgeBase->update([
            'collection_id' => $this->normalizeCollectionId($payload),
            'name' => trim((string) $payload['name']),
            'description' => trim((string) ($payload['description'] ?? '')),
            'summary' => trim((string) ($payload['summary'] ?? '')),
            'source_url' => trim((string) ($payload['source_url'] ?? '')),
            'content' => $content,
            'file_type' => (string) $payload['file_type'],
            'knowledge_type' => $metadata['knowledge_type'],
            'knowledge_role' => $metadata['knowledge_role'],
            'importance' => $metadata['importance'],
            'status' => $this->normalizeStatus((string) ($payload['status'] ?? '')),
            'character_count' => mb_strlen($content, 'UTF-8'),
            'word_count' => mb_strlen(strip_tags($content), 'UTF-8'),
        ]);
        if ($tagIds !== null) {
            $this->tagService->syncExisting($knowledgeBase, $tagIds);
        }
        $this->entityMaterialLinkService->syncEntities(
            $knowledgeBase,
            $this->selectedEntityIds($request),
            $this->selectedKnowledgeRelationTypeFromPayload($payload),
            $this->selectedEntityRelationTypesById($payload)
        );

        return $this->redirectAfterChunkSync(
            $knowledgeBase,
            $content,
            'admin.knowledge-bases.detail',
            ['knowledgeBaseId' => $knowledgeBaseId],
            'update_success'
        );
    }

    /**
     * 上传知识文档并写入知识库。
     */
    public function uploadFile(Request $request): RedirectResponse
    {
        return $this->createKnowledgeBaseFromRequest($request, 'upload_success', 'upload_error');
    }

    /**
     * 创建知识库并同步 chunks。
     */
    public function store(Request $request): RedirectResponse
    {
        return $this->createKnowledgeBaseFromRequest($request, 'create_success', 'create_error');
    }

    /**
     * 编辑表单页。
     */
    public function edit(int $knowledgeBaseId): View|RedirectResponse
    {
        $knowledgeBase = KnowledgeBase::query()->whereKey($knowledgeBaseId)->firstOrFail();
        $selectedTagIds = $this->tagService->selectedTagIdsFor($knowledgeBase);

        return view('admin.knowledge-bases.form', [
            'pageTitle' => __('admin.knowledge_bases.page_title'),
            'activeMenu' => 'materials',
            'adminSiteName' => AdminWeb::siteName(),
            'isEdit' => true,
            'knowledgeBaseId' => (int) $knowledgeBase->id,
            'knowledgeForm' => [
                'name' => (string) $knowledgeBase->name,
                'description' => (string) ($knowledgeBase->description ?? ''),
                'summary' => (string) ($knowledgeBase->summary ?? ''),
                'source_url' => (string) ($knowledgeBase->source_url ?? ''),
                'content' => (string) ($knowledgeBase->content ?? ''),
                'file_type' => (string) ($knowledgeBase->file_type ?? 'markdown'),
                'knowledge_type' => $this->normalizeKnowledgeType((string) ($knowledgeBase->knowledge_type ?? '')),
                'knowledge_role' => $this->normalizeKnowledgeRole((string) ($knowledgeBase->knowledge_role ?? '')),
                'importance' => (string) $this->normalizeImportance($knowledgeBase->importance ?? null),
                'status' => $this->normalizeStatus((string) ($knowledgeBase->status ?? '')),
                'collection_id' => (string) ((int) ($knowledgeBase->collection_id ?? 0) ?: ''),
            ],
            'chunkCount' => (int) $knowledgeBase->chunks()->count(),
            'collectionOptions' => CollectionOptions::all(),
            'knowledgeTypeOptions' => $this->knowledgeTypeOptions(),
            'knowledgeRoleOptions' => $this->knowledgeRoleOptions(),
            'importanceOptions' => $this->importanceOptions(),
            'statusOptions' => $this->knowledgeStatusOptions(),
            'entityOptions' => $this->entityMaterialLinkService->entityOptions((int) ($knowledgeBase->collection_id ?? 0) ?: null),
            'selectedEntityIds' => $this->entityMaterialLinkService->selectedEntityIdsFor($knowledgeBase),
            'entityRelationType' => $this->entityMaterialLinkService->selectedKnowledgeRelationTypeFor($knowledgeBase),
            'entityRelationTypesById' => $this->entityMaterialLinkService->selectedKnowledgeRelationTypesFor($knowledgeBase),
            'knowledgeRelationTypeOptions' => $this->entityMaterialLinkService->knowledgeRelationTypeOptions(),
            'aiModelOptions' => $this->materialFormAnalysisService->modelOptions(),
            'tagsText' => $this->tagService->tagTextFor($knowledgeBase),
            'selectedTagIds' => $selectedTagIds,
            'tagOptions' => $this->tagService->tagOptionsForIds($selectedTagIds),
        ]);
    }

    /**
     * 更新知识库并重建 chunks。
     */
    public function update(Request $request, int $knowledgeBaseId): RedirectResponse
    {
        $knowledgeBase = KnowledgeBase::query()->whereKey($knowledgeBaseId)->firstOrFail();

        $payload = $this->validateKnowledgeForm($request);
        $content = trim((string) $payload['content']);
        $metadata = $this->knowledgeMetadataFromPayload($payload);

        $tagIds = $this->requestHasTagSelection($request) ? $this->selectedTagIds($request) : null;
        $knowledgeBase->update([
            'collection_id' => $this->normalizeCollectionId($payload),
            'name' => trim((string) $payload['name']),
            'description' => trim((string) ($payload['description'] ?? '')),
            'summary' => trim((string) ($payload['summary'] ?? '')),
            'source_url' => trim((string) ($payload['source_url'] ?? '')),
            'content' => $content,
            'file_type' => (string) $payload['file_type'],
            'knowledge_type' => $metadata['knowledge_type'],
            'knowledge_role' => $metadata['knowledge_role'],
            'importance' => $metadata['importance'],
            'status' => $this->normalizeStatus((string) ($payload['status'] ?? '')),
            'character_count' => mb_strlen($content, 'UTF-8'),
            'word_count' => mb_strlen(strip_tags($content), 'UTF-8'),
        ]);
        if ($tagIds !== null) {
            $this->tagService->syncExisting($knowledgeBase, $tagIds);
        }
        $this->entityMaterialLinkService->syncEntities(
            $knowledgeBase,
            $this->selectedEntityIds($request),
            $this->selectedKnowledgeRelationTypeFromPayload($payload),
            $this->selectedEntityRelationTypesById($payload)
        );

        return $this->redirectAfterChunkSync(
            $knowledgeBase,
            $content,
            'admin.knowledge-bases.detail',
            ['knowledgeBaseId' => (int) $knowledgeBase->id],
            'update_success'
        );
    }

    /**
     * 删除知识库（存在任务引用时阻止）。
     */
    public function destroy(Request $request, int $knowledgeBaseId): RedirectResponse
    {
        $knowledgeBase = KnowledgeBase::query()->whereKey($knowledgeBaseId)->firstOrFail();

        $taskCount = Task::query()->where('knowledge_base_id', $knowledgeBaseId)->count();
        if ($taskCount > 0) {
            return back()->withErrors(__('admin.knowledge_bases.error.in_use', ['count' => $taskCount]));
        }

        $filePath = (string) ($knowledgeBase->file_path ?? '');
        $knowledgeBase->delete();
        $this->cleanupKnowledgeFile($filePath);

        return redirect()
            ->route('admin.knowledge-bases.index', $request->query())
            ->withFragment('material-list')
            ->with('message', __('admin.knowledge_bases.message.delete_success'));
    }

    public function refreshChunks(int $knowledgeBaseId): RedirectResponse
    {
        $knowledgeBase = KnowledgeBase::query()->whereKey($knowledgeBaseId)->firstOrFail();
        $content = trim((string) ($knowledgeBase->content ?? ''));

        if ($content === '') {
            return redirect()
                ->route('admin.knowledge-bases.index')
                ->withErrors(__('admin.knowledge_bases.error.content_required'));
        }

        if (! $this->hasDefaultEmbeddingModel()) {
            return redirect()
                ->route('admin.knowledge-bases.index')
                ->withErrors(__('admin.knowledge_bases.error.embedding_required'));
        }

        try {
            SyncKnowledgeBaseChunksJob::dispatch((int) $knowledgeBase->id, true)->onQueue('geoflow');

            return redirect()
                ->route('admin.knowledge-bases.index')
                ->with('message', __('admin.knowledge_bases.message.chunk_sync_queued'));
        } catch (\Throwable $exception) {
            report($exception);

            return redirect()
                ->route('admin.knowledge-bases.index')
                ->withErrors(__('admin.knowledge_bases.message.chunks_refresh_error', [
                    'message' => $exception->getMessage(),
                ]));
        }
    }

    public function updateTags(Request $request, int $knowledgeBaseId): RedirectResponse
    {
        $knowledgeBase = KnowledgeBase::query()->whereKey($knowledgeBaseId)->firstOrFail();

        $request->validate([
            'tag_ids' => ['nullable', 'array'],
            'tag_ids.*' => ['integer', Rule::exists('tags', 'id')->where('type', 'material')],
        ]);

        $this->tagService->syncExisting($knowledgeBase, $this->selectedTagIds($request));

        return back()->with('message', __('admin.knowledge_bases.message.tags_updated'));
    }

    public function bulkUpdate(Request $request): RedirectResponse
    {
        $payload = $request->validate([
            'knowledge_ids' => ['required', 'array', 'min:1'],
            'knowledge_ids.*' => ['integer', Rule::exists('knowledge_bases', 'id')],
            'bulk_action' => ['required', 'string', Rule::in([
                'assign_collection',
                'add_tags',
                'assign_purpose',
                'assign_role',
                'link_entity',
                'archive',
                'set_status',
            ])],
            'collection_id' => ['nullable', 'integer', 'min:1', Rule::exists('collections', 'id')],
            'knowledge_purpose' => ['nullable', 'string', Rule::in(array_column($this->knowledgePurposeOptions(), 'value'))],
            'knowledge_role' => ['nullable', 'string', Rule::in(KnowledgeBase::KNOWLEDGE_ROLES)],
            'status' => ['nullable', 'string', Rule::in(['active', 'inactive'])],
            'entity_ids' => ['nullable', 'array'],
            'entity_ids.*' => ['integer', Rule::exists('entities', 'id')],
            'entity_relation_type' => ['nullable', 'string', Rule::in(EntityMaterialLinkService::KNOWLEDGE_RELATION_TYPES)],
            'bulk_tag_ids' => ['nullable', 'array'],
            'bulk_tag_ids.*' => ['integer', Rule::exists('tags', 'id')->where('type', 'material')],
        ]);

        $ids = collect($payload['knowledge_ids'] ?? [])
            ->map(static fn (mixed $id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();
        $action = (string) $payload['bulk_action'];

        if ($ids === []) {
            return back()->withErrors(__('admin.knowledge_bases.bulk.errors.no_selection'));
        }

        if ($action === 'assign_collection') {
            KnowledgeBase::query()
                ->whereIn('id', $ids)
                ->update(['collection_id' => $this->normalizeCollectionId($payload)]);
        } elseif ($action === 'assign_role') {
            KnowledgeBase::query()
                ->whereIn('id', $ids)
                ->update(['knowledge_role' => $this->normalizeKnowledgeRole((string) ($payload['knowledge_role'] ?? ''))]);
        } elseif ($action === 'assign_purpose') {
            $purpose = $this->knowledgePurposeDefinition((string) ($payload['knowledge_purpose'] ?? ''));
            KnowledgeBase::query()
                ->whereIn('id', $ids)
                ->update([
                    'knowledge_type' => $purpose['type'],
                    'knowledge_role' => $purpose['role'],
                    'importance' => $purpose['importance'],
                ]);
        } elseif ($action === 'set_status') {
            KnowledgeBase::query()
                ->whereIn('id', $ids)
                ->update(['status' => $this->normalizeStatus((string) ($payload['status'] ?? ''))]);
        } elseif ($action === 'archive') {
            KnowledgeBase::query()
                ->whereIn('id', $ids)
                ->update([
                    'knowledge_type' => 'reference',
                    'knowledge_role' => 'archive',
                    'importance' => 1,
                    'status' => 'inactive',
                ]);
        } elseif ($action === 'add_tags') {
            $tagIds = $this->selectedBulkTagIds($payload);
            if ($tagIds === []) {
                return back()->withErrors(__('admin.knowledge_bases.bulk.errors.no_tags'));
            }

            KnowledgeBase::query()
                ->whereIn('id', $ids)
                ->get()
                ->each(fn (KnowledgeBase $knowledgeBase) => $knowledgeBase->tags()->syncWithoutDetaching($tagIds));
        } elseif ($action === 'link_entity') {
            $entityIds = $this->selectedEntityIdsFromPayload($payload);
            if ($entityIds === []) {
                return back()->withErrors(__('admin.knowledge_bases.bulk.errors.no_entities'));
            }

            $this->appendEntityLinks(
                $ids,
                $entityIds,
                $this->selectedKnowledgeRelationTypeFromPayload($payload)
            );
        }

        return back()->with('message', __('admin.knowledge_bases.message.bulk_updated'));
    }

    /**
     * @return LengthAwarePaginator<int, array<string,mixed>>
     */
    private function loadKnowledgeBases(
        string $tagFilter,
        string $search,
        ?int $collectionId = null,
        ?string $knowledgeType = null,
        ?string $knowledgeRole = null,
        ?int $importance = null,
        ?int $entityId = null,
        ?string $tagGroup = null,
        ?string $status = null
    ): LengthAwarePaginator
    {
        $query = KnowledgeBase::query()
            ->select([
                'id',
                'collection_id',
                'name',
                'description',
                'source_url',
                'file_type',
                'knowledge_type',
                'knowledge_role',
                'importance',
                'status',
                'word_count',
                'usage_count',
                'created_at',
                'updated_at',
            ])
            ->with('collection:id,name,status')
            ->with(['tags' => fn ($query) => $query->orderBy('group_name')->orderBy('name')])
            ->with(['linkedEntities' => fn ($query) => $query->select('entities.id', 'entities.name', 'entities.entity_type')->orderBy('name')])
            ->withCount('chunks as chunk_count')
            ->withCount([
                'chunks as vectorized_chunk_count' => fn ($query) => $query
                    ->whereNotNull('embedding_model_id')
                    ->where('embedding_dimensions', '>', 0),
            ])
            ->orderByDesc('created_at');

        if ($search !== '') {
            $query->where(function ($builder) use ($search): void {
                $builder
                    ->where('name', 'like', '%'.$search.'%')
                    ->orWhere('description', 'like', '%'.$search.'%')
                    ->orWhere('content', 'like', '%'.$search.'%')
                    ->orWhere('source_url', 'like', '%'.$search.'%');
            });
        }

        $this->tagService->applyFilter($query, $tagFilter);

        if ($tagGroup !== null) {
            $query->whereHas('tags', fn ($tagQuery) => $tagQuery->where('group_name', $tagGroup));
        }

        if ($collectionId !== null) {
            $query->where('collection_id', $collectionId);
        }

        if ($knowledgeType !== null) {
            $query->where('knowledge_type', $knowledgeType);
        }

        if ($knowledgeRole !== null) {
            $query->where('knowledge_role', $knowledgeRole);
        }

        if ($importance !== null) {
            $query->where('importance', $importance);
        }

        if ($entityId !== null) {
            $query->whereHas('linkedEntities', fn ($entityQuery) => $entityQuery->where('entities.id', $entityId));
        }

        if ($status !== null) {
            $query->where('status', $status);
        }

        return $query->paginate(20)->withQueryString()->through(function (KnowledgeBase $knowledgeBase): array {
            $type = $this->normalizeKnowledgeType((string) ($knowledgeBase->knowledge_type ?? ''));
            $role = $this->normalizeKnowledgeRole((string) ($knowledgeBase->knowledge_role ?? ''));
            $importance = $this->normalizeImportance($knowledgeBase->importance ?? null);
            $purpose = $this->knowledgePurposeForMetadata($type, $role, $importance);

            return [
                'id' => (int) $knowledgeBase->id,
                'collection_id' => (int) ($knowledgeBase->collection_id ?? 0),
                'collection_name' => (string) ($knowledgeBase->collection?->name ?? ''),
                'name' => (string) $knowledgeBase->name,
                'description' => (string) ($knowledgeBase->description ?? ''),
                'file_type' => (string) ($knowledgeBase->file_type ?? 'markdown'),
                'knowledge_type' => $type,
                'knowledge_type_label' => $this->knowledgeTypeLabel($type),
                'knowledge_role' => $role,
                'knowledge_role_label' => $this->knowledgeRoleLabel($role),
                'knowledge_role_instruction' => $this->knowledgeRoleInstruction($role),
                'importance' => $importance,
                'knowledge_purpose' => $purpose,
                'knowledge_purpose_label' => $purpose === 'custom'
                    ? __('admin.knowledge_bases.knowledge_purpose.custom')
                    : __('admin.knowledge_bases.knowledge_purpose.'.$purpose),
                'status' => $this->normalizeStatus((string) ($knowledgeBase->status ?? '')),
                'status_label' => $this->knowledgeStatusLabel((string) ($knowledgeBase->status ?? '')),
                'source_url' => (string) ($knowledgeBase->source_url ?? ''),
                'word_count' => (int) ($knowledgeBase->word_count ?? 0),
                'usage_count' => (int) ($knowledgeBase->usage_count ?? 0),
                'chunk_count' => (int) ($knowledgeBase->chunk_count ?? 0),
                'vectorized_chunk_count' => (int) ($knowledgeBase->vectorized_chunk_count ?? 0),
                'tags' => $knowledgeBase->tags
                    ->map(static fn ($tag): string => $tag->displayName())
                    ->filter(static fn (string $label): bool => $label !== '')
                    ->values()
                    ->all(),
                'linked_entities' => $knowledgeBase->linkedEntities
                    ->map(static fn (EntityRecord $entity): string => trim(implode(' / ', array_filter([
                        (string) $entity->name,
                        (string) ($entity->entity_type ?? ''),
                    ]))))
                    ->filter(static fn (string $label): bool => $label !== '')
                    ->values()
                    ->all(),
                'created_at' => $knowledgeBase->created_at?->format('Y-m-d H:i:s'),
                'updated_at' => $knowledgeBase->updated_at?->format('Y-m-d H:i:s'),
            ];
        });
    }

    /**
     * 判断是否存在可用的 embedding 模型，用于知识库列表按钮引导。
     */
    private function hasDefaultEmbeddingModel(): bool
    {
        return AiModel::query()
            ->where('status', 'active')
            ->whereRaw("COALESCE(NULLIF(model_type, ''), 'chat') = 'embedding'")
            ->exists();
    }

    /**
     * @return array{total_knowledge:int,total_words:int,markdown_count:int,word_count:int}
     */
    private function loadStats(): array
    {
        return [
            'total_knowledge' => KnowledgeBase::query()->count(),
            'total_words' => (int) (KnowledgeBase::query()->sum('word_count') ?? 0),
            'markdown_count' => KnowledgeBase::query()->where('file_type', 'markdown')->count(),
            'word_count' => KnowledgeBase::query()->where('file_type', 'word')->count(),
        ];
    }

    /**
     * 校验知识库表单。
     *
     * @return array{name:string,description:?string,content:string,file_type:string}
     */
    private function validateKnowledgeForm(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string'],
            'summary' => ['nullable', 'string'],
            'source_url' => ['nullable', 'string', 'max:500'],
            'content' => ['required', 'string'],
            'file_type' => ['required', 'in:markdown,word,text'],
            'knowledge_type' => ['nullable', 'string', Rule::in(KnowledgeBase::KNOWLEDGE_TYPES)],
            'knowledge_role' => ['nullable', 'string', Rule::in(KnowledgeBase::KNOWLEDGE_ROLES)],
            'importance' => ['nullable', 'integer', 'min:1', 'max:5'],
            'status' => ['nullable', 'string', Rule::in(['active', 'inactive'])],
            'collection_id' => ['nullable', 'integer', 'min:1', Rule::exists('collections', 'id')],
            'tag_ids' => ['nullable', 'array'],
            'tag_ids.*' => ['integer', Rule::exists('tags', 'id')->where('type', 'material')],
            'entity_ids' => ['nullable', 'array'],
            'entity_ids.*' => ['integer', Rule::exists('entities', 'id')],
            'entity_relation_type' => ['nullable', 'string', Rule::in(EntityMaterialLinkService::KNOWLEDGE_RELATION_TYPES)],
            'entity_relation_types' => ['nullable', 'array'],
            'entity_relation_types.*' => ['string', Rule::in(EntityMaterialLinkService::KNOWLEDGE_RELATION_TYPES)],
        ], [
            'name.required' => __('admin.knowledge_bases.error.name_required'),
            'content.required' => __('admin.knowledge_bases.error.content_required'),
        ]);
    }

    /**
     * 校验统一导入表单。
     *
     * @return array{name:?string,description:?string,content:?string,file_type:?string}
     */
    private function validateKnowledgeImportForm(Request $request): array
    {
        return $request->validate([
            'name' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string'],
            'summary' => ['nullable', 'string'],
            'source_url' => ['nullable', 'string', 'max:500'],
            'content' => ['nullable', 'string'],
            'file_type' => ['nullable', 'in:markdown,word,text'],
            'knowledge_type' => ['nullable', 'string', Rule::in(KnowledgeBase::KNOWLEDGE_TYPES)],
            'knowledge_role' => ['nullable', 'string', Rule::in(KnowledgeBase::KNOWLEDGE_ROLES)],
            'importance' => ['nullable', 'integer', 'min:1', 'max:5'],
            'status' => ['nullable', 'string', Rule::in(['active', 'inactive'])],
            'collection_id' => ['nullable', 'integer', 'min:1', Rule::exists('collections', 'id')],
            'import_action' => ['nullable', 'in:save,save_and_chunk'],
            'knowledge_file' => ['nullable', File::types(['txt', 'md', 'docx'])->max(50 * 1024)],
            'knowledge_files' => ['nullable', 'array', 'max:10'],
            'knowledge_files.*' => ['file', File::types(['txt', 'md', 'docx'])->max(50 * 1024)],
            'tag_ids' => ['nullable', 'array'],
            'tag_ids.*' => ['integer', Rule::exists('tags', 'id')->where('type', 'material')],
            'entity_ids' => ['nullable', 'array'],
            'entity_ids.*' => ['integer', Rule::exists('entities', 'id')],
            'entity_relation_type' => ['nullable', 'string', Rule::in(EntityMaterialLinkService::KNOWLEDGE_RELATION_TYPES)],
            'entity_relation_types' => ['nullable', 'array'],
            'entity_relation_types.*' => ['string', Rule::in(EntityMaterialLinkService::KNOWLEDGE_RELATION_TYPES)],
        ], [
            'knowledge_file.mimes' => __('admin.knowledge_bases.error.file_type_invalid'),
            'knowledge_file.max' => __('admin.knowledge_bases.error.file_too_large'),
            'knowledge_files.max' => __('admin.knowledge_bases.error.files_limit'),
            'knowledge_files.*.mimes' => __('admin.knowledge_bases.error.file_type_invalid'),
            'knowledge_files.*.max' => __('admin.knowledge_bases.error.file_too_large'),
        ]);
    }

    /**
     * @return array{name:string,description:string,content:string,file_type:string}
     */
    private function emptyForm(): array
    {
        return [
            'name' => '',
            'description' => '',
            'summary' => '',
            'source_url' => '',
            'content' => '',
            'file_type' => 'markdown',
            'knowledge_type' => 'reference',
            'knowledge_role' => 'supporting_context',
            'importance' => '3',
            'status' => 'active',
            'collection_id' => '',
        ];
    }

    private function createKnowledgeBaseFromRequest(Request $request, string $successMessageKey, string $errorMessageKey): RedirectResponse
    {
        $payload = $this->validateKnowledgeImportForm($request);
        $storedPaths = [];
        $tagIds = $this->selectedTagIds($request);

        try {
            $manualContent = $this->normalizeKnowledgeText((string) ($payload['content'] ?? ''));
            $uploadedFiles = $this->uploadedKnowledgeFiles($request);

            if (count($uploadedFiles) > 10) {
                throw ValidationException::withMessages([
                    'knowledge_files' => __('admin.knowledge_bases.error.files_limit'),
                ]);
            }

            if ($manualContent === '' && $uploadedFiles === []) {
                throw ValidationException::withMessages([
                    'content' => __('admin.knowledge_bases.error.content_required'),
                    'knowledge_files' => __('admin.knowledge_bases.error.file_required'),
                ]);
            }

            $parsedFiles = $this->parseUploadedKnowledgeFiles($uploadedFiles, $storedPaths);
            $content = $this->mergeKnowledgeSources($manualContent, $parsedFiles);
            if ($content === '') {
                throw ValidationException::withMessages([
                    'content' => __('admin.knowledge_bases.error.content_required'),
                ]);
            }

            $knowledgeName = trim((string) ($payload['name'] ?? ''));
            if ($knowledgeName === '') {
                $knowledgeName = $this->inferKnowledgeName($uploadedFiles);
            }
            if ($knowledgeName === '') {
                $knowledgeName = $this->inferKnowledgeNameFromContent($manualContent);
            }
            if ($knowledgeName === '') {
                throw ValidationException::withMessages([
                    'name' => __('admin.knowledge_bases.error.name_required'),
                ]);
            }

            $fileType = $this->resolveKnowledgeFileType(
                (string) ($payload['file_type'] ?? 'markdown'),
                $manualContent,
                $parsedFiles
            );
            $encodedFilePath = $this->encodeKnowledgeFilePaths($storedPaths);
            $metadata = $this->knowledgeMetadataFromPayload($payload);

            $knowledgeBase = DB::transaction(function () use ($knowledgeName, $payload, $content, $fileType, $encodedFilePath, $tagIds, $metadata): KnowledgeBase {
                $knowledgeBase = KnowledgeBase::query()->create([
                    'name' => $knowledgeName,
                    'collection_id' => $this->normalizeCollectionId($payload),
                    'description' => trim((string) ($payload['description'] ?? '')),
                    'summary' => trim((string) ($payload['summary'] ?? '')),
                    'source_url' => trim((string) ($payload['source_url'] ?? '')),
                    'content' => $content,
                    'file_type' => $fileType,
                    'knowledge_type' => $metadata['knowledge_type'],
                    'knowledge_role' => $metadata['knowledge_role'],
                    'importance' => $metadata['importance'],
                    'status' => $this->normalizeStatus((string) ($payload['status'] ?? '')),
                    'character_count' => mb_strlen($content, 'UTF-8'),
                    'word_count' => mb_strlen(strip_tags($content), 'UTF-8'),
                    'usage_count' => 0,
                    'used_task_count' => 0,
                    'file_path' => $encodedFilePath,
                ]);
                $this->tagService->syncExisting($knowledgeBase, $tagIds);
                $this->entityMaterialLinkService->syncEntities(
                    $knowledgeBase,
                    $this->selectedEntityIdsFromPayload($payload),
                    $this->selectedKnowledgeRelationTypeFromPayload($payload),
                    $this->selectedEntityRelationTypesById($payload)
                );

                return $knowledgeBase;
            });

            if (($payload['import_action'] ?? 'save_and_chunk') === 'save') {
                return redirect()
                    ->route('admin.knowledge-bases.index')
                    ->with('message', __('admin.knowledge_bases.message.create_saved'));
            }

            return $this->redirectAfterChunkSync(
                $knowledgeBase,
                $content,
                'admin.knowledge-bases.index',
                [],
                $successMessageKey
            );
        } catch (ValidationException $exception) {
            $this->cleanupKnowledgeFiles($storedPaths);

            throw $exception;
        } catch (\Throwable $exception) {
            $this->cleanupKnowledgeFiles($storedPaths);

            return back()
                ->withInput($request->except(['knowledge_file', 'knowledge_files']))
                ->withErrors(__('admin.knowledge_bases.message.'.$errorMessageKey, ['message' => $exception->getMessage()]));
        }
    }

    /**
     * 保存知识库后再执行切片同步，避免外部模型调用占用数据库事务。
     *
     * @param  array<string, mixed>  $routeParameters
     */
    private function redirectAfterChunkSync(KnowledgeBase $knowledgeBase, string $content, string $routeName, array $routeParameters, string $successMessageKey): RedirectResponse
    {
        try {
            SyncKnowledgeBaseChunksJob::dispatch((int) $knowledgeBase->id, false)->onQueue('geoflow');

            return redirect()
                ->route($routeName, $routeParameters)
                ->with('message', __('admin.knowledge_bases.message.chunk_sync_queued'));
        } catch (\Throwable $exception) {
            return redirect()
                ->route($routeName, $routeParameters)
                ->withErrors([
                    'chunk_sync' => __('admin.knowledge_bases.message.chunk_sync_deferred', [
                        'message' => $exception->getMessage(),
                    ]),
                ]);
        }
    }

    /**
     * @return list<int>
     */
    private function selectedTagIds(Request $request): array
    {
        return collect($request->input('tag_ids', []))
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
        return $this->selectedEntityIdsFromPayload($request->all());
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<int>
     */
    private function selectedEntityIdsFromPayload(array $payload): array
    {
        return collect($payload['entity_ids'] ?? [])
            ->map(static fn ($id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<int>
     */
    private function selectedBulkTagIds(array $payload): array
    {
        return collect($payload['bulk_tag_ids'] ?? [])
            ->map(static fn ($id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function selectedKnowledgeRelationTypeFromPayload(array $payload): string
    {
        return $this->entityMaterialLinkService->normalizeKnowledgeRelationType((string) ($payload['entity_relation_type'] ?? ''));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<int,string>
     */
    private function selectedEntityRelationTypesById(array $payload): array
    {
        $relationTypes = $payload['entity_relation_types'] ?? [];
        if (! is_array($relationTypes)) {
            $relationTypes = [];
        }

        $result = [];
        foreach ($this->selectedEntityIdsFromPayload($payload) as $entityId) {
            $result[(int) $entityId] = $this->entityMaterialLinkService->normalizeKnowledgeRelationType(
                (string) ($relationTypes[(string) $entityId] ?? $relationTypes[(int) $entityId] ?? $payload['entity_relation_type'] ?? '')
            );
        }

        return $result;
    }

    /**
     * @param  list<int>  $knowledgeBaseIds
     * @param  list<int>  $entityIds
     */
    private function appendEntityLinks(array $knowledgeBaseIds, array $entityIds, string $relationType): void
    {
        $knowledgeBaseIds = KnowledgeBase::query()
            ->whereIn('id', $knowledgeBaseIds)
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
        $entityIds = EntityRecord::query()
            ->whereIn('id', $entityIds)
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
        $relationType = $this->entityMaterialLinkService->normalizeKnowledgeRelationType($relationType);
        $now = now();

        foreach ($knowledgeBaseIds as $knowledgeBaseId) {
            foreach ($entityIds as $entityId) {
                DB::table('entity_material_links')->insertOrIgnore([
                    'entity_id' => $entityId,
                    'linkable_type' => KnowledgeBase::class,
                    'linkable_id' => $knowledgeBaseId,
                    'link_role' => $relationType,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    /**
     * @return array{collections:list<array{id:int,name:string}>,entities:list<array{id:int,name:string}>,tags:list<array{id:int,group_name:string,name:string}>}
     */
    private function knowledgeClassificationContext(): array
    {
        return [
            'collections' => CollectionRecord::query()
                ->where('status', 'active')
                ->orderBy('sort_order')
                ->orderBy('name')
                ->limit(200)
                ->get(['id', 'name'])
                ->map(static fn (CollectionRecord $collection): array => [
                    'id' => (int) $collection->id,
                    'name' => (string) $collection->name,
                ])
                ->all(),
            'entities' => EntityRecord::query()
                ->orderBy('name')
                ->limit(500)
                ->get(['id', 'name'])
                ->map(static fn (EntityRecord $entity): array => [
                    'id' => (int) $entity->id,
                    'name' => (string) $entity->name,
                ])
                ->all(),
            'tags' => Tag::query()
                ->where('type', 'material')
                ->orderBy('group_name')
                ->orderBy('name')
                ->limit(1000)
                ->get(['id', 'group_name', 'name'])
                ->map(static fn (Tag $tag): array => [
                    'id' => (int) $tag->id,
                    'group_name' => (string) ($tag->group_name ?? ''),
                    'name' => (string) $tag->name,
                ])
                ->all(),
        ];
    }

    private function requestHasTagSelection(Request $request): bool
    {
        return $request->has('tag_ids') || $request->has('tag_ids_present');
    }

    private function selectedCollectionId(Request $request): ?int
    {
        $collectionId = (int) $request->query('collection_id', 0);

        if ($collectionId <= 0) {
            $collectionId = (int) \App\Support\AdminWeb::defaultCollectionId();
        }

        return $collectionId > 0 ? $collectionId : null;
    }

    private function selectedKnowledgeType(Request $request): ?string
    {
        $value = trim((string) $request->query('knowledge_type', ''));

        return in_array($value, KnowledgeBase::KNOWLEDGE_TYPES, true) ? $value : null;
    }

    private function selectedKnowledgePurpose(Request $request): ?string
    {
        $value = trim((string) $request->query('knowledge_purpose', ''));
        $allowed = array_column($this->knowledgePurposeOptions(), 'value');

        return in_array($value, $allowed, true) ? $value : null;
    }

    private function selectedKnowledgeRole(Request $request): ?string
    {
        $value = trim((string) $request->query('knowledge_role', ''));

        return in_array($value, KnowledgeBase::KNOWLEDGE_ROLES, true) ? $value : null;
    }

    private function selectedImportance(Request $request): ?int
    {
        $importance = (int) $request->query('importance', 0);

        return $importance >= 1 && $importance <= 5 ? $importance : null;
    }

    private function selectedEntityId(Request $request): ?int
    {
        $entityId = (int) $request->query('entity_id', 0);

        return $entityId > 0 ? $entityId : null;
    }

    private function selectedTagGroup(Request $request): ?string
    {
        $tagGroup = trim((string) $request->query('tag_group', ''));

        return $tagGroup !== '' && in_array($tagGroup, ControlledTagGroups::names(), true) ? $tagGroup : null;
    }

    private function selectedStatus(Request $request): ?string
    {
        $status = trim((string) $request->query('status', ''));

        return in_array($status, ['active', 'inactive'], true) ? $status : null;
    }

    private function selectedSavedView(Request $request): ?string
    {
        $view = trim((string) $request->query('view', ''));
        $allowed = array_column($this->savedViewOptions(), 'value');

        return in_array($view, $allowed, true) ? $view : null;
    }

    private function applySavedViewFilters(?string $savedView, string &$tagFilter, ?string &$knowledgeType, ?string &$knowledgeRole, ?string &$status): void
    {
        if ($savedView === null) {
            return;
        }

        match ($savedView) {
            'product_manuals' => $knowledgeType = 'product_manual',
            'faq' => $knowledgeType = 'faq',
            'competitor_research' => $knowledgeType = 'competitor_analysis',
            'troubleshooting' => $knowledgeType = 'troubleshooting',
            'archive' => $knowledgeRole = 'archive',
            default => null,
        };

        if ($savedView === 'archive') {
            $status = null;
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function normalizeCollectionId(array $payload): ?int
    {
        $collectionId = (int) ($payload['collection_id'] ?? 0);

        return $collectionId > 0 ? $collectionId : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{knowledge_type:string,knowledge_role:string,importance:int}
     */
    private function knowledgeMetadataFromPayload(array $payload): array
    {
        return [
            'knowledge_type' => $this->normalizeKnowledgeType((string) ($payload['knowledge_type'] ?? '')),
            'knowledge_role' => $this->normalizeKnowledgeRole((string) ($payload['knowledge_role'] ?? '')),
            'importance' => $this->normalizeImportance($payload['importance'] ?? null),
        ];
    }

    /**
     * @return array{value:string,type:string,role:string,importance:int}
     */
    private function knowledgePurposeDefinition(string $purpose): array
    {
        foreach ($this->knowledgePurposeDefinitions() as $definition) {
            if ($definition['value'] === $purpose) {
                return $definition;
            }
        }

        return $this->knowledgePurposeDefinitions()[0];
    }

    private function knowledgePurposeForMetadata(string $type, string $role, int $importance): string
    {
        foreach ($this->knowledgePurposeDefinitions() as $definition) {
            if ($definition['type'] === $type && $definition['role'] === $role && $definition['importance'] === $importance) {
                return $definition['value'];
            }
        }

        return 'custom';
    }

    /**
     * @return list<array{value:string,type:string,role:string,importance:int}>
     */
    private function knowledgePurposeDefinitions(): array
    {
        return [
            ['value' => 'reference', 'type' => 'reference', 'role' => 'supporting_context', 'importance' => 3],
            ['value' => 'product_manual', 'type' => 'product_manual', 'role' => 'primary_source', 'importance' => 5],
            ['value' => 'technical_spec', 'type' => 'technical_spec', 'role' => 'primary_source', 'importance' => 5],
            ['value' => 'faq', 'type' => 'faq', 'role' => 'supporting_context', 'importance' => 4],
            ['value' => 'troubleshooting', 'type' => 'troubleshooting', 'role' => 'supporting_context', 'importance' => 4],
            ['value' => 'competitor_analysis', 'type' => 'competitor_analysis', 'role' => 'comparison_reference', 'importance' => 3],
            ['value' => 'policy', 'type' => 'policy', 'role' => 'constraint', 'importance' => 5],
            ['value' => 'marketing_copy', 'type' => 'marketing_copy', 'role' => 'style_reference', 'importance' => 2],
            ['value' => 'archive', 'type' => 'reference', 'role' => 'archive', 'importance' => 1],
            ['value' => 'other', 'type' => 'other', 'role' => 'supporting_context', 'importance' => 3],
        ];
    }

    private function normalizeKnowledgeType(string $value): string
    {
        $value = trim($value);

        return in_array($value, KnowledgeBase::KNOWLEDGE_TYPES, true) ? $value : 'reference';
    }

    private function normalizeKnowledgeRole(string $value): string
    {
        $value = trim($value);

        return in_array($value, KnowledgeBase::KNOWLEDGE_ROLES, true) ? $value : 'supporting_context';
    }

    private function normalizeImportance(mixed $value): int
    {
        $importance = (int) $value;

        return min(5, max(1, $importance > 0 ? $importance : 3));
    }

    private function normalizeStatus(string $value): string
    {
        $value = trim($value);

        return in_array($value, ['active', 'inactive'], true) ? $value : 'active';
    }

    /**
     * @return list<array{value:string,label:string}>
     */
    private function knowledgeStatusOptions(): array
    {
        return [
            ['value' => 'active', 'label' => __('admin.knowledge_bases.status.active')],
            ['value' => 'inactive', 'label' => __('admin.knowledge_bases.status.inactive')],
        ];
    }

    private function knowledgeStatusLabel(string $status): string
    {
        return (string) __('admin.knowledge_bases.status.'.$this->normalizeStatus($status));
    }

    /**
     * @return list<array{value:string,label:string}>
     */
    private function savedViewOptions(): array
    {
        return [
            ['value' => 'product_manuals', 'label' => __('admin.knowledge_bases.saved_views.product_manuals')],
            ['value' => 'faq', 'label' => __('admin.knowledge_bases.saved_views.faq')],
            ['value' => 'competitor_research', 'label' => __('admin.knowledge_bases.saved_views.competitor_research')],
            ['value' => 'troubleshooting', 'label' => __('admin.knowledge_bases.saved_views.troubleshooting')],
            ['value' => 'archive', 'label' => __('admin.knowledge_bases.saved_views.archive')],
        ];
    }

    /**
     * @return list<string>
     */
    private function tagGroupOptions(): array
    {
        return ControlledTagGroups::names();
    }

    /**
     * @return list<array{value:string,label:string}>
     */
    private function knowledgePurposeOptions(): array
    {
        return array_map(static fn (array $definition): array => [
            'value' => $definition['value'],
            'label' => __('admin.knowledge_bases.knowledge_purpose.'.$definition['value']),
        ], $this->knowledgePurposeDefinitions());
    }

    /**
     * @return list<array{value:string,label:string}>
     */
    private function knowledgeTypeOptions(): array
    {
        return array_map(fn (string $type): array => [
            'value' => $type,
            'label' => $this->knowledgeTypeLabel($type),
        ], KnowledgeBase::KNOWLEDGE_TYPES);
    }

    /**
     * @return list<array{value:string,label:string,instruction:string}>
     */
    private function knowledgeRoleOptions(): array
    {
        return array_map(fn (string $role): array => [
            'value' => $role,
            'label' => $this->knowledgeRoleLabel($role),
            'instruction' => $this->knowledgeRoleInstruction($role),
        ], KnowledgeBase::KNOWLEDGE_ROLES);
    }

    /**
     * @return list<array{value:int,label:string}>
     */
    private function importanceOptions(): array
    {
        return array_map(fn (int $importance): array => [
            'value' => $importance,
            'label' => __('admin.knowledge_bases.importance.option_'.$importance),
        ], [1, 2, 3, 4, 5]);
    }

    private function knowledgeTypeLabel(string $type): string
    {
        return (string) __('admin.knowledge_bases.knowledge_type.'.$this->normalizeKnowledgeType($type));
    }

    private function knowledgeRoleLabel(string $role): string
    {
        return (string) __('admin.knowledge_bases.knowledge_role.'.$this->normalizeKnowledgeRole($role));
    }

    private function knowledgeRoleInstruction(string $role): string
    {
        return (string) __('admin.knowledge_bases.knowledge_role_help.'.$this->normalizeKnowledgeRole($role));
    }

    /**
     * @return array{chunk_count:int,vectorized_count:int}
     */
    private function loadChunkStats(int $knowledgeBaseId): array
    {
        $chunkCount = KnowledgeChunk::query()
            ->where('knowledge_base_id', $knowledgeBaseId)
            ->count();
        $vectorizedCount = KnowledgeChunk::query()
            ->where('knowledge_base_id', $knowledgeBaseId)
            ->whereNotNull('embedding_model_id')
            ->where('embedding_dimensions', '>', 0)
            ->count();

        return ['chunk_count' => $chunkCount, 'vectorized_count' => $vectorizedCount];
    }

    /**
     * @return EloquentCollection<int, Task>
     */
    private function loadRelatedTasks(int $knowledgeBaseId): EloquentCollection
    {
        return Task::query()
            ->select(['id', 'name', 'status', 'updated_at'])
            ->where('knowledge_base_id', $knowledgeBaseId)
            ->orderByDesc('updated_at')
            ->limit(5)
            ->get();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function loadChunkPreviewRows(int $knowledgeBaseId): Collection
    {
        return KnowledgeChunk::query()
            ->select([
                'chunk_index',
                'content',
                'chunk_title',
                'section_path',
                'chunk_strategy',
                'token_count',
                'embedding_model_id',
                'embedding_dimensions',
                'embedding_provider',
            ])
            ->where('knowledge_base_id', $knowledgeBaseId)
            ->orderBy('chunk_index')
            ->limit(20)
            ->get()
            ->map(static function (KnowledgeChunk $chunk): array {
                $preview = mb_substr(trim((string) $chunk->content), 0, 160, 'UTF-8');

                return [
                    'chunk_index' => (int) $chunk->chunk_index,
                    'content_length' => mb_strlen((string) $chunk->content, 'UTF-8'),
                    'token_count' => (int) ($chunk->token_count ?? 0),
                    'embedding_model_id' => $chunk->embedding_model_id !== null ? (int) $chunk->embedding_model_id : null,
                    'embedding_dimensions' => (int) ($chunk->embedding_dimensions ?? 0),
                    'embedding_provider' => (string) ($chunk->embedding_provider ?? ''),
                    'chunk_title' => (string) ($chunk->chunk_title ?? ''),
                    'section_path' => (string) ($chunk->section_path ?? ''),
                    'chunk_strategy' => (string) ($chunk->chunk_strategy ?? 'structured_rule'),
                    'content_preview' => $preview,
                ];
            });
    }

    /**
     * 保存上传知识文件到本地路径。
     */
    private function storeUploadedKnowledgeFile(UploadedFile $file): string
    {
        $relativeDirectory = 'uploads/knowledge';
        $extension = strtolower($file->getClientOriginalExtension() ?: 'txt');
        $filename = uniqid('', true).'.'.$extension;
        $relativePath = Storage::disk('local')->putFileAs($relativeDirectory, $file, $filename);
        if (! is_string($relativePath) || $relativePath === '') {
            throw new \RuntimeException(__('admin.knowledge_bases.message.upload_failed'));
        }

        return $relativePath;
    }

    /**
     * @return array<int, UploadedFile>
     */
    private function uploadedKnowledgeFiles(Request $request): array
    {
        $files = [];

        /** @var UploadedFile|null $legacyFile */
        $legacyFile = $request->file('knowledge_file');
        if ($legacyFile instanceof UploadedFile) {
            $files[] = $legacyFile;
        }

        $multiFiles = $request->file('knowledge_files', []);
        if (is_array($multiFiles)) {
            foreach ($multiFiles as $file) {
                if ($file instanceof UploadedFile) {
                    $files[] = $file;
                }
            }
        }

        return $files;
    }

    /**
     * @param  array<int, UploadedFile>  $uploadedFiles
     * @param  array<int, string>  $storedPaths
     * @return array<int, array{content:string,file_type:string,original_name:string}>
     */
    private function parseUploadedKnowledgeFiles(array $uploadedFiles, array &$storedPaths): array
    {
        $parsedFiles = [];

        foreach ($uploadedFiles as $uploadedFile) {
            $storedRelativePath = $this->storeUploadedKnowledgeFile($uploadedFile);
            $storedPaths[] = $storedRelativePath;
            $parsed = $this->parseUploadedKnowledgeFile(
                Storage::disk('local')->path($storedRelativePath),
                $uploadedFile->getClientOriginalName()
            );

            $parsedFiles[] = [
                'content' => $parsed['content'],
                'file_type' => $parsed['file_type'],
                'original_name' => (string) $uploadedFile->getClientOriginalName(),
            ];
        }

        return $parsedFiles;
    }

    /**
     * @param  array<int, array{content:string,file_type:string,original_name:string}>  $parsedFiles
     */
    private function mergeKnowledgeSources(string $manualContent, array $parsedFiles): string
    {
        if ($manualContent !== '' && $parsedFiles === []) {
            return $manualContent;
        }

        $blocks = [];
        if ($manualContent !== '') {
            $blocks[] = "# 手动输入内容\n\n".$manualContent;
        }

        foreach ($parsedFiles as $parsedFile) {
            $fileName = trim((string) $parsedFile['original_name']);
            $blocks[] = '# 文件：'.$fileName."\n\n".trim((string) $parsedFile['content']);
        }

        return $this->normalizeKnowledgeText(implode("\n\n---\n\n", $blocks));
    }

    /**
     * @param  array<int, UploadedFile>  $uploadedFiles
     */
    private function inferKnowledgeName(array $uploadedFiles): string
    {
        if ($uploadedFiles === []) {
            return '';
        }

        $firstName = pathinfo((string) $uploadedFiles[0]->getClientOriginalName(), PATHINFO_FILENAME);
        $firstName = trim($firstName);
        if (count($uploadedFiles) === 1) {
            return $firstName;
        }

        return $firstName === ''
            ? __('admin.knowledge_bases.imported_multi_file_name', ['count' => count($uploadedFiles)])
            : __('admin.knowledge_bases.imported_multi_file_name_with_first', [
                'name' => $firstName,
                'count' => count($uploadedFiles),
            ]);
    }

    private function inferKnowledgeNameFromContent(string $content): string
    {
        $lines = preg_split('/\R/u', $content) ?: [];
        foreach ($lines as $line) {
            $candidate = trim((string) $line);
            if ($candidate === '') {
                continue;
            }

            $candidate = preg_replace('/^#{1,6}\s*/u', '', $candidate) ?? $candidate;
            $candidate = preg_replace('/^[-*+]\s+/u', '', $candidate) ?? $candidate;
            $candidate = trim(strip_tags($candidate));
            $candidate = trim($candidate, " \t\n\r\0\x0B#*_`>");

            if ($candidate !== '') {
                return mb_substr($candidate, 0, 60, 'UTF-8');
            }
        }

        return '';
    }

    /**
     * @param  array<int, array{content:string,file_type:string,original_name:string}>  $parsedFiles
     */
    private function resolveKnowledgeFileType(string $requestedType, string $manualContent, array $parsedFiles): string
    {
        if ($parsedFiles === []) {
            return in_array($requestedType, ['markdown', 'word', 'text'], true) ? $requestedType : 'markdown';
        }

        if ($manualContent !== '' || count($parsedFiles) > 1) {
            return 'markdown';
        }

        $fileType = (string) ($parsedFiles[0]['file_type'] ?? 'markdown');

        return in_array($fileType, ['markdown', 'word', 'text'], true) ? $fileType : 'markdown';
    }

    /**
     * @param  array<int, string>  $storedPaths
     */
    private function encodeKnowledgeFilePaths(array $storedPaths): string
    {
        if ($storedPaths === []) {
            return '';
        }

        if (count($storedPaths) === 1) {
            return (string) $storedPaths[0];
        }

        return (string) json_encode(array_values($storedPaths), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @return array{content:string,file_type:string}
     */
    private function parseUploadedKnowledgeFile(string $absolutePath, string $originalName): array
    {
        $extension = strtolower((string) pathinfo($originalName, PATHINFO_EXTENSION));
        if ($extension === 'txt' || $extension === 'md') {
            $raw = @file_get_contents($absolutePath);
            if ($raw === false) {
                throw new \RuntimeException(__('admin.knowledge_bases.message.upload_failed'));
            }

            $content = $this->normalizeKnowledgeText($this->convertUploadedTextToUtf8($raw));
            if ($content === '') {
                throw new \RuntimeException(__('admin.knowledge_bases.error.content_required'));
            }

            return [
                'content' => $content,
                'file_type' => $extension === 'md' ? 'markdown' : 'text',
            ];
        }

        if ($extension === 'docx') {
            $content = $this->extractDocxContent($absolutePath);
            if ($content === '') {
                throw new \RuntimeException(__('admin.knowledge_bases.error.file_type_invalid'));
            }

            return [
                'content' => $content,
                'file_type' => 'word',
            ];
        }

        throw new \RuntimeException(__('admin.knowledge_bases.error.file_type_invalid'));
    }

    /**
     * 清理上传失败或删除后的知识文件。
     */
    private function cleanupKnowledgeFile(string $relativePath): void
    {
        $this->cleanupKnowledgeFiles($this->decodeKnowledgeFilePaths($relativePath));
    }

    /**
     * @return array<int, string>
     */
    private function decodeKnowledgeFilePaths(string $storedValue): array
    {
        $storedValue = trim($storedValue);
        if ($storedValue === '') {
            return [];
        }

        $decoded = json_decode($storedValue, true);
        if (is_array($decoded)) {
            return array_values(array_filter($decoded, static fn ($path): bool => is_string($path) && trim($path) !== ''));
        }

        return [$storedValue];
    }

    /**
     * @param  array<int, string>  $relativePaths
     */
    private function cleanupKnowledgeFiles(array $relativePaths): void
    {
        foreach ($relativePaths as $relativePath) {
            $this->deleteKnowledgeFilePath($relativePath);
        }
    }

    private function deleteKnowledgeFilePath(string $relativePath): void
    {
        $relativePath = trim($relativePath);
        if ($relativePath === '') {
            return;
        }

        // 兼容新旧两种路径：优先 Laravel local 磁盘，相对旧数据再回退到项目根目录删除。
        if (Storage::disk('local')->exists($relativePath)) {
            Storage::disk('local')->delete($relativePath);

            return;
        }

        $absolutePath = base_path(ltrim($relativePath, '/'));
        if (is_file($absolutePath)) {
            @unlink($absolutePath);
        }
    }

    /**
     * 将文本转换为 UTF-8，兼容上传文件编码差异。
     */
    private function convertUploadedTextToUtf8(string $text): string
    {
        if ($text === '') {
            return '';
        }

        $detectedEncoding = mb_detect_encoding($text, ['UTF-8', 'GB18030', 'GBK', 'BIG5', 'UTF-16LE', 'UTF-16BE'], true);
        if (! $detectedEncoding || strtoupper($detectedEncoding) === 'UTF-8') {
            return $text;
        }

        $converted = @mb_convert_encoding($text, 'UTF-8', $detectedEncoding);

        return $converted === false ? $text : $converted;
    }

    /**
     * 统一知识文本换行与空白，提升分块稳定性。
     */
    private function normalizeKnowledgeText(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace("/\n{3,}/u", "\n\n", $text);
        $text = preg_replace('/[ \t]{2,}/u', ' ', (string) $text);

        return trim((string) $text);
    }

    /**
     * 从 docx 提取正文（优先 ZipArchive，失败时降级为空字符串）。
     */
    private function extractDocxContent(string $absolutePath): string
    {
        if (! class_exists('ZipArchive')) {
            return '';
        }

        $zip = new \ZipArchive;
        if ($zip->open($absolutePath) !== true) {
            return '';
        }

        $xmlContent = $zip->getFromName('word/document.xml');
        $zip->close();
        if (! is_string($xmlContent) || $xmlContent === '') {
            return '';
        }

        $dom = new \DOMDocument;
        $loaded = @$dom->loadXML($xmlContent, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        if (! $loaded) {
            return '';
        }

        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');

        $parts = [];
        $nodes = $xpath->query('//w:t');
        if ($nodes !== false) {
            foreach ($nodes as $node) {
                $value = trim((string) $node->textContent);
                if ($value !== '') {
                    $parts[] = $value;
                }
            }
        }

        return $this->normalizeKnowledgeText(implode("\n", $parts));
    }
}
