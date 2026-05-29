<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\EntityRecord;
use App\Services\GeoFlow\TagService;
use App\Support\AdminWeb;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class EntityController extends Controller
{
    private const PER_PAGE = 20;

    public function __construct(private readonly TagService $tagService) {}

    public function index(Request $request): View
    {
        $search = trim((string) $request->query('search', ''));
        $tag = trim((string) $request->query('tag', ''));

        $query = EntityRecord::query()
            ->with('tags')
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

        return view('admin.entities.index', [
            'pageTitle' => __('admin.entities.page_title'),
            'activeMenu' => 'materials',
            'adminSiteName' => AdminWeb::siteName(),
            'search' => $search,
            'tagFilter' => $tag,
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
            'tagOptions' => $this->tagService->existingTagOptions(),
            'selectedTagIds' => [],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $payload = $this->validateEntity($request);

        $entity = EntityRecord::query()->create($this->normalizeEntityPayload($payload));
        $this->tagService->syncExisting($entity, $this->selectedTagIds($payload));

        return redirect()
            ->route('admin.entities.index')
            ->with('message', __('admin.entities.message.create_success'));
    }

    public function edit(int $entityId): View
    {
        $entity = EntityRecord::query()->with('tags')->whereKey($entityId)->firstOrFail();

        return view('admin.entities.form', [
            'pageTitle' => __('admin.entities.page_title'),
            'activeMenu' => 'materials',
            'adminSiteName' => AdminWeb::siteName(),
            'isEdit' => true,
            'entityId' => (int) $entity->id,
            'entityForm' => [
                'name' => (string) $entity->name,
                'entity_type' => (string) ($entity->entity_type ?? ''),
                'aliases' => (string) ($entity->aliases ?? ''),
                'description' => (string) ($entity->description ?? ''),
                'attributes_json' => (string) ($entity->attributes_json ?? ''),
                'source_url' => (string) ($entity->source_url ?? ''),
            ],
            'tagOptions' => $this->tagService->existingTagOptions(),
            'selectedTagIds' => $this->tagService->selectedTagIdsFor($entity),
        ]);
    }

    public function update(Request $request, int $entityId): RedirectResponse
    {
        $entity = EntityRecord::query()->whereKey($entityId)->firstOrFail();
        $payload = $this->validateEntity($request);

        $entity->update($this->normalizeEntityPayload($payload));
        $this->tagService->syncExisting($entity, $this->selectedTagIds($payload));

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
     * @return array{name:string,entity_type:string,aliases:string,description:string,attributes_json:string,source_url:string}
     */
    private function emptyEntityForm(): array
    {
        return [
            'name' => '',
            'entity_type' => '',
            'aliases' => '',
            'description' => '',
            'attributes_json' => '{}',
            'source_url' => '',
        ];
    }
}
