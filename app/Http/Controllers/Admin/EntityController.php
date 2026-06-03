<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\EntityRecord;
use App\Services\GeoFlow\EntityMaterialLinkService;
use App\Services\GeoFlow\MaterialFormAnalysisService;
use App\Services\GeoFlow\TagService;
use App\Support\AdminWeb;
use App\Support\GeoFlow\CollectionOptions;
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
            'collectionOptions' => CollectionOptions::all(true),
            'tagOptions' => [],
            'selectedTagIds' => [],
            'materialOptions' => $this->entityMaterialLinkService->materialOptions(),
            'selectedMaterialIds' => [],
            'knowledgeRelationType' => 'supporting_reference',
            'knowledgeRelationTypeOptions' => $this->entityMaterialLinkService->knowledgeRelationTypeOptions(),
            'aiModelOptions' => $this->materialFormAnalysisService->modelOptions(),
        ]);
    }

    public function analyze(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'content' => ['required', 'string', 'max:20000'],
            'ai_model_id' => ['nullable', 'integer', 'min:0'],
        ]);

        return response()->json([
            'fields' => $this->materialFormAnalysisService->analyzeEntity(
                (string) $payload['content'],
                (int) ($payload['ai_model_id'] ?? 0)
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
            $this->selectedKnowledgeRelationType($payload)
        );

        return redirect()
            ->route('admin.entities.index')
            ->with('message', __('admin.entities.message.create_success'));
    }

    public function edit(int $entityId): View
    {
        $entity = EntityRecord::query()->with('tags')->whereKey($entityId)->firstOrFail();
        $selectedTagIds = $this->tagService->selectedTagIdsFor($entity);

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
            ],
            'tagOptions' => $this->tagService->tagOptionsForIds($selectedTagIds),
            'selectedTagIds' => $selectedTagIds,
            'materialOptions' => $this->entityMaterialLinkService->materialOptions((int) ($entity->collection_id ?? 0) ?: null),
            'selectedMaterialIds' => $this->entityMaterialLinkService->selectedMaterialIdsForEntity($entity),
            'knowledgeRelationType' => $this->entityMaterialLinkService->selectedKnowledgeRelationTypeForEntity($entity),
            'knowledgeRelationTypeOptions' => $this->entityMaterialLinkService->knowledgeRelationTypeOptions(),
            'aiModelOptions' => $this->materialFormAnalysisService->modelOptions(),
            'collectionOptions' => CollectionOptions::all(),
        ]);
    }

    public function update(Request $request, int $entityId): RedirectResponse
    {
        $entity = EntityRecord::query()->whereKey($entityId)->firstOrFail();
        $payload = $this->validateEntity($request);

        $entity->update($this->normalizeEntityPayload($payload));
        $this->tagService->syncExisting($entity, $this->selectedTagIds($payload));
        $this->entityMaterialLinkService->syncMaterialsForEntity(
            $entity,
            $this->selectedMaterialIds($payload),
            $this->selectedKnowledgeRelationType($payload)
        );

        return redirect()
            ->route('admin.entities.index')
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
    private function validateEntity(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'collection_id' => ['nullable', 'integer', 'min:1', Rule::exists('collections', 'id')],
            'entity_type' => ['nullable', 'string', 'max:80'],
            'aliases' => ['nullable', 'string', 'max:2000'],
            'description' => ['nullable', 'string', 'max:10000'],
            'attributes_json' => ['nullable', 'string', 'max:10000'],
            'source_url' => ['nullable', 'string', 'max:500'],
            'tag_ids' => ['nullable', 'array'],
            'tag_ids.*' => [
                'integer',
                Rule::exists('tags', 'id')->where(static fn ($query) => $query->where('type', 'material')),
            ],
            'knowledge_base_ids' => ['nullable', 'array'],
            'knowledge_base_ids.*' => ['integer', Rule::exists('knowledge_bases', 'id')],
            'knowledge_relation_type' => ['nullable', 'string', Rule::in(EntityMaterialLinkService::KNOWLEDGE_RELATION_TYPES)],
            'keyword_library_ids' => ['nullable', 'array'],
            'keyword_library_ids.*' => ['integer', Rule::exists('keyword_libraries', 'id')],
            'title_library_ids' => ['nullable', 'array'],
            'title_library_ids.*' => ['integer', Rule::exists('title_libraries', 'id')],
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

        return [
            'name' => trim((string) $payload['name']),
            'collection_id' => $this->normalizeCollectionId($payload),
            'entity_type' => trim((string) ($payload['entity_type'] ?? '')),
            'aliases' => trim((string) ($payload['aliases'] ?? '')),
            'description' => trim((string) ($payload['description'] ?? '')),
            'attributes_json' => $attributesJson === '' ? '{}' : $attributesJson,
            'source_url' => trim((string) ($payload['source_url'] ?? '')),
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
     * @return array{collection_id:string,name:string,entity_type:string,aliases:string,description:string,attributes_json:string,source_url:string}
     */
    private function emptyEntityForm(): array
    {
        return [
            'collection_id' => '',
            'name' => '',
            'entity_type' => '',
            'aliases' => '',
            'description' => '',
            'attributes_json' => '{}',
            'source_url' => '',
        ];
    }

    private function selectedCollectionId(Request $request): ?int
    {
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
}
