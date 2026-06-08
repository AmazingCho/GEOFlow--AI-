<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\EntityRecord;
use App\Services\GeoFlow\EntityMaterialLinkService;
use App\Services\GeoFlow\MaterialFormAnalysisService;
use App\Services\GeoFlow\TagService;
use App\Support\AdminWeb;
use App\Support\GeoFlow\CollectionOptions;
use App\Support\GeoFlow\EntityTypes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class EntityController extends Controller
{
    private const PER_PAGE = 20;

    public function __construct(
        private readonly TagService $tagService,
        private readonly EntityMaterialLinkService $entityMaterialLinkService,
        private readonly MaterialFormAnalysisService $materialFormAnalysisService
    ) {}

    public function index(Request $request): View
    {
        $search = trim((string) $request->query('search', ''));
        $tag = trim((string) $request->query('tag', ''));
        $collectionId = $this->selectedCollectionId($request);

        $query = EntityRecord::query()
            ->with(['collection', 'tags'])
            ->withCount('cases')
            ->orderByDesc('updated_at')
            ->orderByDesc('id');

        if ($search !== '') {
            $query->where(function ($builder) use ($search): void {
                $builder
                    ->where('name', 'like', '%'.$search.'%')
                    ->orWhere('entity_type', 'like', '%'.$search.'%')
                    ->orWhere('aliases', 'like', '%'.$search.'%')
                    ->orWhere('description', 'like', '%'.$search.'%');
            });
        }

        $this->tagService->applyFilter($query, $tag);

        if ($collectionId !== null) {
            $query->where('collection_id', $collectionId);
        }

        return view('admin.entities.index', [
            'pageTitle' => __('admin.entities.page_title'),
            'activeMenu' => 'materials',
            'adminSiteName' => AdminWeb::siteName(),
            'search' => $search,
            'tagFilter' => $tag,
            'collectionId' => $collectionId,
            'collectionOptions' => CollectionOptions::all(),
            'entities' => $query->paginate(self::PER_PAGE)->withQueryString(),
            'stats' => [
                'total' => EntityRecord::query()->count(),
                'tagged' => EntityRecord::query()->whereHas('tags')->count(),
            ],
        ]);
    }

    public function create(): View
    {
        return view('admin.entities.form', [
            'pageTitle' => __('admin.entities.page_title'),
            'activeMenu' => 'materials',
            'adminSiteName' => AdminWeb::siteName(),
            'isEdit' => false,
            'entityId' => 0,
            'entityForm' => $this->emptyEntityForm(),
            'entityTypeOptions' => EntityTypes::options(),
            'linkableEntityTypes' => EntityTypes::linkableValues(),
            'collectionOptions' => CollectionOptions::all(true),
            'tagOptions' => [],
            'selectedTagIds' => [],
            'materialOptions' => $this->entityMaterialLinkService->materialOptions(),
            'selectedMaterialIds' => [],
            'knowledgeRelationType' => 'supporting_reference',
            'knowledgeRelationTypesById' => [],
            'knowledgeRelationTypeOptions' => $this->entityMaterialLinkService->knowledgeRelationTypeOptions(),
            'aiModelOptions' => $this->materialFormAnalysisService->modelOptions(),
        ]);
    }

    public function analyze(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'content' => ['required', 'string', 'max:20000'],
            'ai_model_id' => ['nullable', 'integer', 'min:0'],
            'analysis_instructions' => ['nullable', 'string', 'max:4000'],
        ]);

        return response()->json([
            'fields' => $this->materialFormAnalysisService->analyzeEntity(
                (string) $payload['content'],
                (int) ($payload['ai_model_id'] ?? 0),
                (string) ($payload['analysis_instructions'] ?? '')
            ),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $payload = $this->validateEntity($request);

        $entity = EntityRecord::query()->create($this->normalizeEntityPayload($payload));
        $this->tagService->syncExisting($entity, $this->selectedTagIds($payload));
        $this->entityMaterialLinkService->syncMaterialsForEntity(
            $entity,
            $this->selectedMaterialIds($payload),
            $this->selectedKnowledgeRelationType($payload),
            $this->selectedKnowledgeRelationTypesById($payload)
        );

        return redirect()
            ->route('admin.entities.index')
            ->with('message', __('admin.entities.message.create_success'));
    }

    public function edit(int $entityId): View
    {
        $entity = EntityRecord::query()->with('tags')->whereKey($entityId)->firstOrFail();
        $selectedTagIds = $this->tagService->selectedTagIdsFor($entity);
        $currentEntityType = (string) ($entity->entity_type ?? '');

        return view('admin.entities.form', [
            'pageTitle' => __('admin.entities.page_title'),
            'activeMenu' => 'materials',
            'adminSiteName' => AdminWeb::siteName(),
            'isEdit' => true,
            'entityId' => (int) $entity->id,
            'entityForm' => [
                'name' => (string) $entity->name,
                'collection_id' => (string) ((int) ($entity->collection_id ?? 0) ?: ''),
                'entity_type' => (string) ($entity->entity_type ?? ''),
                'aliases' => (string) ($entity->aliases ?? ''),
                'description' => (string) ($entity->description ?? ''),
                'attributes_json' => (string) ($entity->attributes_json ?? ''),
                'source_url' => (string) ($entity->source_url ?? ''),
                'canonical_url' => (string) ($entity->canonical_url ?? ''),
                'link_anchor_text' => (string) ($entity->link_anchor_text ?? ''),
                'link_policy' => (string) ($entity->link_policy ?? EntityTypes::defaultLinkPolicyFor($currentEntityType)),
            ],
            'entityTypeOptions' => EntityTypes::options($currentEntityType),
            'linkableEntityTypes' => EntityTypes::linkableValues(),
            'tagOptions' => $this->tagService->tagOptionsForIds($selectedTagIds),
            'selectedTagIds' => $selectedTagIds,
            'materialOptions' => array_merge(
                $this->entityMaterialLinkService->materialOptions((int) ($entity->collection_id ?? 0) ?: null),
                ['case_ids' => $this->caseOptions((int) ($entity->collection_id ?? 0) ?: null)]
            ),
            'selectedMaterialIds' => array_merge(
                $this->entityMaterialLinkService->selectedMaterialIdsForEntity($entity),
                ['case_ids' => $entity->relatedCases->pluck('id')->map(fn ($id) => (int) $id)->all()]
            ),
            'knowledgeRelationType' => $this->entityMaterialLinkService->selectedKnowledgeRelationTypeForEntity($entity),
            'knowledgeRelationTypesById' => $this->entityMaterialLinkService->selectedKnowledgeRelationTypesForEntity($entity),
            'knowledgeRelationTypeOptions' => $this->entityMaterialLinkService->knowledgeRelationTypeOptions(),
            'aiModelOptions' => $this->materialFormAnalysisService->modelOptions(),
            'collectionOptions' => CollectionOptions::all(),
            'entityRelationService' => app(\App\Services\GeoFlow\EntityRelationService::class),
            'caseOptions' => $this->caseOptions((int) ($entity->collection_id ?? 0) ?: null),
            'selectedCaseIds' => $entity->relatedCases->pluck('id')->map(fn ($id) => (int) $id)->all(),
            'entityOptionsForRelation' => $this->entityOptionsForRelation((int) ($entity->collection_id ?? 0) ?: null, (int) $entity->id),
        ]);
    }

    public function update(Request $request, int $entityId): RedirectResponse
    {
        $entity = EntityRecord::query()->whereKey($entityId)->firstOrFail();
        $payload = $this->validateEntity($request, $entity);

        $entity->update($this->normalizeEntityPayload($payload));

        $relations = json_decode((string) $request->input('entity_relations', '[]'), true);
        if (is_array($relations) && $relations !== []) {
            app(\App\Services\GeoFlow\EntityRelationService::class)->syncRelations((int) $entity->id, $relations);
        }
        $this->tagService->syncExisting($entity, $this->selectedTagIds($payload));

        if (isset($payload['case_ids']) && is_array($payload['case_ids'])) {
            $entity->relatedCases()->sync(array_map('intval', $payload['case_ids']));
        }
        $this->entityMaterialLinkService->syncMaterialsForEntity(
            $entity,
            $this->selectedMaterialIds($payload),
            $this->selectedKnowledgeRelationType($payload),
            $this->selectedKnowledgeRelationTypesById($payload)
        );

        return redirect()
            ->route('admin.entities.edit', ['entityId' => (int) $entity->id])
            ->with('message', __('admin.entities.message.update_success'));
    }

    public function destroy(int $entityId): RedirectResponse
    {
        EntityRecord::query()->whereKey($entityId)->firstOrFail()->delete();

        return redirect()
            ->route('admin.entities.index')
            ->with('message', __('admin.entities.message.delete_success'));
    }

    /**
     * @return array<string, mixed>
     */
    private function validateEntity(Request $request, ?EntityRecord $entity = null): array
    {
        $allowedEntityTypes = EntityTypes::values();
        $currentEntityType = trim((string) ($entity?->entity_type ?? ''));
        if ($currentEntityType !== '' && ! in_array($currentEntityType, $allowedEntityTypes, true)) {
            $allowedEntityTypes[] = $currentEntityType;
        }

        return $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'collection_id' => ['nullable', 'integer', 'min:1', Rule::exists('collections', 'id')],
            'entity_type' => ['required', 'string', 'max:80', Rule::in($allowedEntityTypes)],
            'aliases' => ['nullable', 'string', 'max:2000'],
            'description' => ['nullable', 'string', 'max:10000'],
            'attributes_json' => ['nullable', 'string', 'max:10000'],
            'source_url' => ['nullable', 'string', 'max:500'],
            'canonical_url' => ['nullable', 'string', 'max:500'],
            'link_anchor_text' => ['nullable', 'string', 'max:160'],
            'link_policy' => ['nullable', 'string', Rule::in([EntityTypes::LINK_POLICY_SUGGEST, EntityTypes::LINK_POLICY_DISABLED])],
            'case_ids' => ['nullable', 'array'],
            'case_ids.*' => ['integer', 'min:1', \Illuminate\Validation\Rule::exists('case_records', 'id')],
            'tag_ids' => ['nullable', 'array'],
            'tag_ids.*' => [
                'integer',
                Rule::exists('tags', 'id')->where(static fn ($query) => $query->where('type', 'material')),
            ],
            'knowledge_base_ids' => ['nullable', 'array'],
            'knowledge_base_ids.*' => ['integer', Rule::exists('knowledge_bases', 'id')],
            'knowledge_relation_type' => ['nullable', 'string', Rule::in(EntityMaterialLinkService::KNOWLEDGE_RELATION_TYPES)],
            'knowledge_relation_types' => ['nullable', 'array'],
            'knowledge_relation_types.*' => ['nullable', 'string', Rule::in(EntityMaterialLinkService::KNOWLEDGE_RELATION_TYPES)],
            'keyword_library_ids' => ['nullable', 'array'],
            'keyword_library_ids.*' => ['integer', Rule::exists('keyword_libraries', 'id')],
            'image_library_ids' => ['nullable', 'array'],
            'image_library_ids.*' => ['integer', Rule::exists('image_libraries', 'id')],
            'image_ids' => ['nullable', 'array'],
            'image_ids.*' => ['integer', Rule::exists('images', 'id')],
        ], [
            'name.required' => __('admin.entities.error.name_required'),
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function normalizeEntityPayload(array $payload): array
    {
        $attributesJson = trim((string) ($payload['attributes_json'] ?? ''));

        $entityType = trim((string) ($payload['entity_type'] ?? ''));
        if (! EntityTypes::isControlled($entityType)) {
            $entityType = EntityTypes::GENERAL;
        }
        $linkPolicy = EntityTypes::normalizeLinkPolicy((string) ($payload['link_policy'] ?? ''), $entityType);
        $isLinkable = EntityTypes::isLinkable($entityType);

        return [
            'name' => trim((string) $payload['name']),
            'collection_id' => $this->normalizeCollectionId($payload),
            'entity_type' => $entityType,
            'aliases' => trim((string) ($payload['aliases'] ?? '')),
            'description' => trim((string) ($payload['description'] ?? '')),
            'attributes_json' => $attributesJson === '' ? '{}' : $attributesJson,
            'source_url' => trim((string) ($payload['source_url'] ?? '')),
            'canonical_url' => $isLinkable ? trim((string) ($payload['canonical_url'] ?? '')) : '',
            'link_anchor_text' => $isLinkable ? trim((string) ($payload['link_anchor_text'] ?? '')) : '',
            'link_policy' => $linkPolicy,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<int>
     */
    private function selectedTagIds(array $payload): array
    {
        $tagIds = $payload['tag_ids'] ?? [];
        if (! is_array($tagIds)) {
            return [];
        }

        return array_values(array_unique(array_map('intval', $tagIds)));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string,list<int>>
     */
    private function selectedMaterialIds(array $payload): array
    {
        $result = [];
        foreach (array_keys($this->entityMaterialLinkService->materialClassMap()) as $key) {
            $result[$key] = collect($payload[$key] ?? [])
                ->map(static fn (mixed $id): int => (int) $id)
                ->filter(static fn (int $id): bool => $id > 0)
                ->unique()
                ->values()
                ->all();
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function selectedKnowledgeRelationType(array $payload): string
    {
        return $this->entityMaterialLinkService->normalizeKnowledgeRelationType((string) ($payload['knowledge_relation_type'] ?? ''));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<int,string>
     */
    private function selectedKnowledgeRelationTypesById(array $payload): array
    {
        $relationTypes = $payload['knowledge_relation_types'] ?? [];
        if (! is_array($relationTypes)) {
            $relationTypes = [];
        }

        $result = [];
        foreach (($this->selectedMaterialIds($payload)['knowledge_base_ids'] ?? []) as $knowledgeBaseId) {
            $result[(int) $knowledgeBaseId] = $this->entityMaterialLinkService->normalizeKnowledgeRelationType(
                (string) ($relationTypes[(string) $knowledgeBaseId] ?? $relationTypes[(int) $knowledgeBaseId] ?? $payload['knowledge_relation_type'] ?? '')
            );
        }

        return $result;
    }

    /**
     * @return array{collection_id:string,name:string,entity_type:string,aliases:string,description:string,attributes_json:string,source_url:string,canonical_url:string,link_anchor_text:string,link_policy:string}
     */
    private function emptyEntityForm(): array
    {
        return [
            'collection_id' => '',
            'name' => '',
            'entity_type' => EntityTypes::GENERAL,
            'aliases' => '',
            'description' => '',
            'attributes_json' => '{}',
            'source_url' => '',
            'canonical_url' => '',
            'link_anchor_text' => '',
            'link_policy' => EntityTypes::LINK_POLICY_DISABLED,
        ];
    }

    private function selectedCollectionId(Request $request): ?int
    {
        if (!$request->has('collection_id')) {
            return \App\Support\AdminWeb::defaultCollectionId();
        }

        $collectionId = (int) $request->query('collection_id', 0);
        return $collectionId > 0 ? $collectionId : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function normalizeCollectionId(array $payload): ?int
    {
        $collectionId = (int) ($payload['collection_id'] ?? 0);

        return $collectionId > 0 ? $collectionId : null;
    }

    // ---------- Entity-to-Entity Relations ----------

    public function searchJson(Request $request): \Illuminate\Http\JsonResponse
    {
        $q = trim((string) $request->query('q', ''));
        $collectionId = $this->selectedCollectionId($request);
        $query = EntityRecord::query()->select(['id', 'name', 'entity_type'])->orderBy('name');
        if ($q !== '') {
            $query->where(function ($builder) use ($q): void {
                $builder->where('name', 'like', '%'.$q.'%')->orWhere('aliases', 'like', '%'.$q.'%');
            });
        }
        if ($collectionId !== null) { $query->where('collection_id', $collectionId); }
        return response()->json($query->limit(30)->get());
    }

    public function relations(int $entityId): \Illuminate\Http\JsonResponse
    {
        $entity = EntityRecord::query()->whereKey($entityId)->firstOrFail();
        return response()->json(app(\App\Services\GeoFlow\EntityRelationService::class)->relatedEntities((int) $entity->id));
    }

    private function caseOptions(?int $collectionId = null): array
    {
        return \App\Models\CaseRecord::query()
            ->select(['id', 'title', 'collection_id'])
            ->when($collectionId !== null && $collectionId > 0, fn ($q) => $q->where('collection_id', $collectionId))
            ->orderBy('title')
            ->limit(500)
            ->get()
            ->map(fn (\App\Models\CaseRecord $c): array => [
                'id' => (int) $c->id,
                'label' => (string) $c->title,
                'collection_id' => (int) ($c->collection_id ?? 0),
            ])
            ->all();
    }

        private function entityOptionsForRelation(?int $collectionId, int $excludeEntityId): array
    {
        return EntityRecord::query()
            ->select(['id', 'name', 'entity_type'])
            ->whereKeyNot($excludeEntityId)
            ->when($collectionId !== null && $collectionId > 0, fn ($q) => $q->where('collection_id', $collectionId))
            ->orderBy('name')->limit(500)->get()
            ->map(fn (EntityRecord $e): array => ['id' => (int) $e->id, 'name' => (string) $e->name, 'entity_type' => (string) $e->entity_type])
            ->all();
    }

    private function adminName(): string
    {
        $admin = auth('admin')->user();

        return trim((string) ($admin?->display_name ?: $admin?->username ?: ''));
    }
}
