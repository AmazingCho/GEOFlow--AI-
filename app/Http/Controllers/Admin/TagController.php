<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Tag;
use App\Services\GeoFlow\TagRecommendationService;
use App\Services\GeoFlow\TagService;
use App\Support\AdminWeb;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class TagController extends Controller
{
    private const DEFAULT_PER_PAGE = 20;

    private const PER_PAGE_OPTIONS = [20, 40, 80, 120];

    private const SCOPE_RELATIONS = [
        'keywords' => 'keywords',
        'images' => 'images',
        'knowledge' => 'knowledgeBases',
        'entities' => 'entities',
        'cases' => 'caseRecords',
    ];

    public function __construct(
        private readonly TagService $tagService,
        private readonly TagRecommendationService $tagRecommendationService
    ) {}

    public function index(Request $request): View
    {
        $search = trim((string) $request->query('search', ''));
        $selectedGroups = $this->selectedGroups($request);
        $perPage = $this->perPage($request);
        $scope = $this->scope($request);

        $query = Tag::query()
            ->where('type', 'material')
            ->withCount(['keywords', 'images', 'knowledgeBases', 'entities', 'caseRecords'])
            ->orderBy('group_name')
            ->orderBy('name');

        if ($scope !== '') {
            $query->whereHas(self::SCOPE_RELATIONS[$scope]);
        }

        if ($search !== '') {
            $query->where(function ($builder) use ($search): void {
                $builder
                    ->where('name', 'like', '%'.$search.'%')
                    ->orWhere('group_name', 'like', '%'.$search.'%');
            });
        }

        if ($selectedGroups !== []) {
            $query->whereIn('group_name', $selectedGroups);
        }

        return view('admin.material-tags.index', [
            'pageTitle' => __('admin.material_tags.page_title'),
            'activeMenu' => 'material_tags',
            'adminSiteName' => AdminWeb::siteName(),
            'search' => $search,
            'selectedGroups' => $selectedGroups,
            'groupOptions' => $this->groupOptions(),
            'scope' => $scope,
            'scopeLabels' => $this->scopeLabels(),
            'scopeGroups' => $this->scopeGroupOptions($scope),
            'perPage' => $perPage,
            'perPageOptions' => self::PER_PAGE_OPTIONS,
            'tags' => $query->paginate($perPage)->withQueryString(),
            'stats' => $this->loadStats(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $payload = $request->validate([
            'group_name' => ['nullable', 'string', 'max:100'],
            'name' => ['required', 'string', 'max:100'],
        ], [
            'name.required' => __('admin.material_tags.error_name_required'),
        ]);

        $groupName = trim((string) ($payload['group_name'] ?? ''));
        $name = trim((string) $payload['name']);
        if ($name === '') {
            return back()->withInput()->withErrors(__('admin.material_tags.error_name_required'));
        }

        $tag = $this->tagService->firstOrCreateTag($groupName, $name);
        $this->flushStatsCache();

        return redirect()
            ->route('admin.material-tags.index')
            ->with('message', __($tag->wasRecentlyCreated ? 'admin.material_tags.message_created' : 'admin.material_tags.message_exists', [
                'tag' => $tag->displayName(),
            ]));
    }

    public function recommendations(Request $request): JsonResponse
    {
        $text = trim((string) $request->query('text', ''));
        $selectedIds = collect((array) $request->query('selected_ids', []))
            ->map(static fn ($id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();

        return response()->json([
            'items' => $this->tagRecommendationService->recommendForText($text, $selectedIds, 6),
        ]);
    }

    public function search(Request $request): JsonResponse
    {
        $query = trim((string) $request->query('q', $request->query('search', '')));
        $limit = min(30, max(5, (int) $request->query('limit', 20)));
        $scope = trim((string) $request->query('scope', ''));
        $industryLabels = $this->industryNamesFromLabels((array) $request->query('industry_labels', []));

        if ($scope === 'industry') {
            return response()->json([
                'items' => $this->searchIndustryTagOptions($query, $limit),
            ]);
        }

        if ($scope === 'images') {
            return response()->json([
                'items' => $this->searchScopedTagOptions($query, $limit, ['images'], 'admin.task_create.option.image_tag_count', $industryLabels),
            ]);
        }

        if ($scope === 'knowledge') {
            return response()->json([
                'items' => $this->searchScopedTagOptions($query, $limit, ['knowledgeBases', 'entities', 'caseRecords'], 'admin.task_create.option.knowledge_tag_count', $industryLabels),
            ]);
        }

        return response()->json([
            'items' => $this->tagService->searchTagOptions($query, 'material', $limit),
        ]);
    }

    public function references(int $tagId): JsonResponse
    {
        $tag = Tag::query()->where('type', 'material')->whereKey($tagId)->firstOrFail();
        $tagLabel = $tag->displayName();

        return response()->json([
            'tag' => [
                'id' => (int) $tag->id,
                'label' => $tagLabel,
            ],
            'sections' => [
                'keywords' => $tag->keywords()
                    ->with('library:id,name')
                    ->orderBy('keyword')
                    ->limit(50)
                    ->get()
                    ->map(fn ($keyword): array => [
                        'label' => (string) $keyword->keyword,
                        'meta' => (string) ($keyword->library?->name ?? ''),
                        'href' => route('admin.keyword-libraries.detail', ['libraryId' => (int) $keyword->library_id, 'tag' => $tagLabel]),
                    ])
                    ->values()
                    ->all(),
                'images' => $tag->images()
                    ->with('library:id,name')
                    ->orderByDesc('images.created_at')
                    ->limit(50)
                    ->get()
                    ->map(fn ($image): array => [
                        'label' => (string) ($image->original_name ?: $image->filename),
                        'meta' => (string) ($image->library?->name ?? ''),
                        'href' => route('admin.image-libraries.detail', ['libraryId' => (int) $image->library_id, 'tag' => $tagLabel]),
                    ])
                    ->values()
                    ->all(),
                'knowledge' => $tag->knowledgeBases()
                    ->orderBy('name')
                    ->limit(50)
                    ->get()
                    ->map(fn ($knowledgeBase): array => [
                        'label' => (string) $knowledgeBase->name,
                        'meta' => '',
                        'href' => route('admin.knowledge-bases.detail', ['knowledgeBaseId' => (int) $knowledgeBase->id]),
                    ])
                    ->values()
                    ->all(),
                'entities' => $tag->entities()
                    ->orderBy('name')
                    ->limit(50)
                    ->get()
                    ->map(fn ($entity): array => [
                        'label' => (string) $entity->name,
                        'meta' => (string) ($entity->entity_type ?? ''),
                        'href' => route('admin.entities.index', ['tag' => $tagLabel]),
                    ])
                    ->values()
                    ->all(),
                'cases' => $tag->caseRecords()
                    ->with('entity:id,name')
                    ->orderBy('title')
                    ->limit(50)
                    ->get()
                    ->map(fn ($caseRecord): array => [
                        'label' => (string) $caseRecord->title,
                        'meta' => (string) ($caseRecord->entity?->name ?? ''),
                        'href' => route('admin.cases.index', ['tag' => $tagLabel]),
                    ])
                    ->values()
                    ->all(),
            ],
        ]);
    }

    public function update(Request $request, int $tagId): RedirectResponse
    {
        $tag = Tag::query()->where('type', 'material')->whereKey($tagId)->firstOrFail();
        $payload = $request->validate([
            'group_name' => ['nullable', 'string', 'max:100'],
            'name' => [
                'required',
                'string',
                'max:100',
                Rule::unique('tags', 'name')
                    ->where(static fn ($query) => $query
                        ->where('type', 'material')
                        ->where('group_name', trim((string) $request->input('group_name', ''))))
                    ->ignore((int) $tag->id),
            ],
        ], [
            'name.required' => __('admin.material_tags.error_name_required'),
            'name.unique' => __('admin.material_tags.error_duplicate'),
        ]);

        $imageIds = $this->tagService->imageIdsForTags([(int) $tag->id]);
        $groupName = trim((string) ($payload['group_name'] ?? ''));
        $name = trim((string) $payload['name']);
        if ($name === '') {
            return back()->withInput()->withErrors(__('admin.material_tags.error_name_required'));
        }

        $tag->update([
            'group_name' => mb_substr($groupName, 0, 100, 'UTF-8'),
            'name' => mb_substr($name, 0, 100, 'UTF-8'),
            'slug' => $this->buildSlug('material', $groupName, $name),
        ]);
        $this->tagService->refreshLegacyImageTagText($imageIds);
        $this->flushStatsCache();

        return back()->with('message', __('admin.material_tags.message_updated', [
            'tag' => $tag->fresh()?->displayName() ?? $name,
        ]));
    }

    public function destroy(Request $request, int $tagId): RedirectResponse
    {
        if (trim((string) $request->input('delete_confirmation', '')) !== __('admin.material_tags.delete_confirmation_text')) {
            return back()->withErrors(__('admin.material_tags.error_delete_confirmation'));
        }

        $tag = Tag::query()->where('type', 'material')->whereKey($tagId)->firstOrFail();
        $imageIds = $this->tagService->imageIdsForTags([(int) $tag->id]);
        $label = $tag->displayName();
        $tag->delete();
        $this->tagService->refreshLegacyImageTagText($imageIds);
        $this->flushStatsCache();

        return back()->with('message', __('admin.material_tags.message_deleted', ['tag' => $label]));
    }

    public function bulk(Request $request): RedirectResponse
    {
        $payload = $request->validate([
            'tag_ids' => ['required', 'array'],
            'tag_ids.*' => ['integer', Rule::exists('tags', 'id')->where(static fn ($query) => $query->where('type', 'material'))],
            'bulk_action' => ['required', 'string', 'in:delete,move_group'],
            'bulk_group_name' => ['nullable', 'string', 'max:100'],
            'delete_confirmation' => ['nullable', 'string'],
        ], [
            'tag_ids.required' => __('admin.material_tags.error_select_tags'),
        ]);

        $tagIds = array_values(array_unique(array_map('intval', $payload['tag_ids'] ?? [])));
        if ($tagIds === []) {
            return back()->withErrors(__('admin.material_tags.error_select_tags'));
        }

        $imageIds = $this->tagService->imageIdsForTags($tagIds);
        if ((string) $payload['bulk_action'] === 'delete') {
            if (trim((string) ($payload['delete_confirmation'] ?? '')) !== __('admin.material_tags.delete_confirmation_text')) {
                return back()->withErrors(__('admin.material_tags.error_delete_confirmation'));
            }

            $deleted = Tag::query()->where('type', 'material')->whereIn('id', $tagIds)->delete();
            $this->tagService->refreshLegacyImageTagText($imageIds);
            $this->flushStatsCache();

            return back()->with('message', __('admin.material_tags.message_bulk_deleted', ['count' => (int) $deleted]));
        }

        $groupName = trim((string) ($payload['bulk_group_name'] ?? ''));
        $names = Tag::query()
            ->where('type', 'material')
            ->whereIn('id', $tagIds)
            ->pluck('name')
            ->map(static fn ($name): string => (string) $name)
            ->all();
        $hasConflict = Tag::query()
            ->where('type', 'material')
            ->where('group_name', $groupName)
            ->whereIn('name', $names)
            ->whereNotIn('id', $tagIds)
            ->exists();
        if ($hasConflict) {
            return back()->withErrors(__('admin.material_tags.error_bulk_move_conflict'));
        }

        $updated = 0;
        Tag::query()
            ->where('type', 'material')
            ->whereIn('id', $tagIds)
            ->get(['id', 'type', 'group_name', 'name', 'slug'])
            ->each(function (Tag $tag) use ($groupName, &$updated): void {
                $tag->update([
                    'group_name' => $groupName,
                    'slug' => $this->buildSlug((string) $tag->type, $groupName, (string) $tag->name),
                ]);
                $updated++;
            });
        $this->tagService->refreshLegacyImageTagText($imageIds);
        $this->flushStatsCache();

        return back()->with('message', __('admin.material_tags.message_bulk_moved', [
            'count' => $updated,
            'group' => $groupName !== '' ? $groupName : __('admin.material_tags.ungrouped'),
        ]));
    }

    /**
     * @return array{total:int,keyword_links:int,image_links:int,knowledge_links:int,entity_links:int,case_links:int}
     */
    private function loadStats(): array
    {
        return Cache::remember('admin.material_tags.stats', now()->addMinutes(5), fn (): array => [
            'total' => Tag::query()->where('type', 'material')->count(),
            'keyword_links' => (int) Tag::query()->where('type', 'material')->withCount('keywords')->get()->sum('keywords_count'),
            'image_links' => (int) Tag::query()->where('type', 'material')->withCount('images')->get()->sum('images_count'),
            'knowledge_links' => (int) Tag::query()->where('type', 'material')->withCount('knowledgeBases')->get()->sum('knowledge_bases_count'),
            'entity_links' => (int) Tag::query()->where('type', 'material')->withCount('entities')->get()->sum('entities_count'),
            'case_links' => (int) Tag::query()->where('type', 'material')->withCount('caseRecords')->get()->sum('case_records_count'),
        ]);
    }

    private function flushStatsCache(): void
    {
        Cache::forget('admin.material_tags.stats');
    }

    /**
     * @param  list<string>  $relations
     * @return list<array{id:int,label:string,count:int,meta:string}>
     */
    private function searchScopedTagOptions(string $query, int $limit, array $relations, string $countLabelKey, array $industryNames = []): array
    {
        $builder = Tag::query()
            ->where('type', 'material')
            ->withCount($relations)
            ->where(function ($nested) use ($relations): void {
                foreach ($relations as $relation) {
                    $nested->orWhereHas($relation);
                }
            });

        if ($industryNames !== []) {
            $builder->where(function ($nested) use ($relations, $industryNames): void {
                foreach ($relations as $relation) {
                    $nested->orWhereHas($relation, function ($relationQuery) use ($industryNames): void {
                        $relationQuery->whereHas('tags', function ($tagQuery) use ($industryNames): void {
                            $tagQuery
                                ->where('type', 'material')
                                ->where('group_name', '行业领域')
                                ->whereIn('name', $industryNames);
                        });
                    });
                }
            });
        }

        if ($query !== '') {
            $builder->where(function ($nested) use ($query): void {
                $nested
                    ->where('name', 'like', '%'.$query.'%')
                    ->orWhere('group_name', 'like', '%'.$query.'%');
            });
        }

        return $builder
            ->orderBy('group_name')
            ->orderBy('name')
            ->limit($limit)
            ->get(['id', 'group_name', 'name'])
            ->map(function (Tag $tag) use ($relations, $countLabelKey): array {
                $count = collect($relations)
                    ->sum(static fn (string $relation): int => (int) ($tag->{Str::snake($relation).'_count'} ?? 0));

                return [
                    'id' => (int) $tag->id,
                    'label' => $tag->displayName(),
                    'count' => $count,
                    'meta' => __($countLabelKey, ['count' => $count]),
                ];
            })
            ->filter(static fn (array $tag): bool => $tag['label'] !== '')
            ->values()
            ->all();
    }

    /**
     * @return list<array{id:int,label:string,count:int,meta:string}>
     */
    private function searchIndustryTagOptions(string $query, int $limit): array
    {
        $builder = Tag::query()
            ->where('type', 'material')
            ->where('group_name', '行业领域')
            ->withCount(['keywords', 'images', 'knowledgeBases', 'entities', 'caseRecords']);

        if ($query !== '') {
            $builder->where('name', 'like', '%'.$query.'%');
        }

        return $builder
            ->orderBy('name')
            ->limit($limit)
            ->get(['id', 'group_name', 'name'])
            ->map(static function (Tag $tag): array {
                $count = (int) ($tag->keywords_count ?? 0)
                    + (int) ($tag->images_count ?? 0)
                    + (int) ($tag->knowledge_bases_count ?? 0)
                    + (int) ($tag->entities_count ?? 0)
                    + (int) ($tag->case_records_count ?? 0);

                return [
                    'id' => (int) $tag->id,
                    'label' => $tag->displayName(),
                    'count' => $count,
                    'meta' => __('admin.task_create.option.industry_tag_count', ['count' => $count]),
                ];
            })
            ->filter(static fn (array $tag): bool => $tag['label'] !== '')
            ->values()
            ->all();
    }

    /**
     * @param  array<int, mixed>  $labels
     * @return list<string>
     */
    private function industryNamesFromLabels(array $labels): array
    {
        return collect($labels)
            ->map(static function ($label): string {
                $label = trim((string) $label);
                if (str_starts_with($label, '行业领域:')) {
                    return trim(str_replace('行业领域:', '', $label));
                }

                return $label;
            })
            ->filter(static fn (string $label): bool => $label !== '')
            ->unique(static fn (string $label): string => mb_strtolower($label, 'UTF-8'))
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    private function selectedGroups(Request $request): array
    {
        $groups = $request->query('groups', []);
        if (! is_array($groups)) {
            $groups = [$groups];
        }

        return collect($groups)
            ->map(static fn ($group): string => trim((string) $group))
            ->filter(static fn (string $group): bool => $group !== '')
            ->unique(static fn (string $group): string => mb_strtolower($group, 'UTF-8'))
            ->values()
            ->all();
    }

    private function perPage(Request $request): int
    {
        $perPage = (int) $request->query('per_page', self::DEFAULT_PER_PAGE);

        return in_array($perPage, self::PER_PAGE_OPTIONS, true) ? $perPage : self::DEFAULT_PER_PAGE;
    }

    private function scope(Request $request): string
    {
        $scope = trim((string) $request->query('scope', ''));

        return array_key_exists($scope, self::SCOPE_RELATIONS) ? $scope : '';
    }

    /**
     * @return list<string>
     */
    private function groupOptions(): array
    {
        return Tag::query()
            ->where('type', 'material')
            ->where('group_name', '<>', '')
            ->distinct()
            ->orderBy('group_name')
            ->pluck('group_name')
            ->map(static fn ($group): string => (string) $group)
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    private function scopeGroupOptions(string $scope): array
    {
        if ($scope === '') {
            return [];
        }

        return Tag::query()
            ->where('type', 'material')
            ->where('group_name', '<>', '')
            ->whereHas(self::SCOPE_RELATIONS[$scope])
            ->distinct()
            ->orderBy('group_name')
            ->pluck('group_name')
            ->map(static fn ($group): string => (string) $group)
            ->values()
            ->all();
    }

    /**
     * @return array<string, string>
     */
    private function scopeLabels(): array
    {
        return [
            'keywords' => __('admin.material_tags.stat_keywords'),
            'images' => __('admin.material_tags.stat_images'),
            'knowledge' => __('admin.material_tags.stat_knowledge'),
            'entities' => __('admin.material_tags.stat_entities'),
            'cases' => __('admin.material_tags.stat_cases'),
        ];
    }

    private function buildSlug(string $type, string $groupName, string $name): string
    {
        $base = trim($type.' '.$groupName.' '.$name);
        $slug = Str::slug($base);

        return $slug !== '' ? mb_substr($slug, 0, 160, 'UTF-8') : 'tag-'.substr(sha1($base), 0, 16);
    }
}
