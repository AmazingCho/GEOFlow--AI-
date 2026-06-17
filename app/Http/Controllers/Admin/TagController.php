<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ControlledTagGroup;
use App\Models\Tag;
use App\Services\GeoFlow\TagService;
use App\Support\AdminWeb;
use App\Support\GeoFlow\ControlledTagGroups;
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

    private const STATS_CACHE_KEY = 'admin:material-tags:stats:v1';

    private const SCOPE_RELATIONS = [
        'keywords' => 'keywords',
        'images' => 'images',
        'knowledge' => 'knowledgeBases',
        'entities' => 'entities',
        'cases' => 'caseRecords',
    ];

    public function __construct(
        private readonly TagService $tagService
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
            'controlledTagGroups' => $this->controlledTagGroups(),
            'tags' => $query->paginate($perPage)->withQueryString(),
            'stats' => $this->loadStats(),
        ]);
    }

    public function controlledGroups(): View
    {
        return view('admin.material-tags.controlled-groups', [
            'pageTitle' => __('admin.material_tags.controlled_groups_page_title'),
            'activeMenu' => 'material_tags',
            'adminSiteName' => AdminWeb::siteName(),
            'controlledTagGroups' => $this->controlledTagGroups(),
        ]);
    }

    public function storeControlledGroup(Request $request): RedirectResponse
    {
        $payload = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('controlled_tag_groups', 'name')],
        ], [
            'name.required' => __('admin.material_tags.error_group_name_required'),
            'name.unique' => __('admin.material_tags.error_group_name_duplicate'),
        ]);

        $name = trim((string) $payload['name']);
        if ($name === '') {
            return back()->withErrors(__('admin.material_tags.error_group_name_required'));
        }

        ControlledTagGroup::query()->create([
            'name' => mb_substr($name, 0, 100, 'UTF-8'),
            'sort_order' => ((int) ControlledTagGroup::query()->max('sort_order')) + 10,
        ]);
        ControlledTagGroups::flush();

        return back()->with('message', __('admin.material_tags.controlled_group_created', ['group' => $name]));
    }

    public function updateControlledGroup(Request $request, int $groupId): RedirectResponse
    {
        $group = ControlledTagGroup::query()->whereKey($groupId)->firstOrFail();
        $payload = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('controlled_tag_groups', 'name')->ignore((int) $group->id)],
        ], [
            'name.required' => __('admin.material_tags.error_group_name_required'),
            'name.unique' => __('admin.material_tags.error_group_name_duplicate'),
        ]);

        $name = trim((string) $payload['name']);
        if ($name === '') {
            return back()->withErrors(__('admin.material_tags.error_group_name_required'));
        }

        $group->update(['name' => mb_substr($name, 0, 100, 'UTF-8')]);
        ControlledTagGroups::flush();

        return back()->with('message', __('admin.material_tags.controlled_group_updated', ['group' => $name]));
    }

    public function deleteControlledGroup(int $groupId): RedirectResponse
    {
        $group = ControlledTagGroup::query()->whereKey($groupId)->firstOrFail();
        $name = (string) $group->name;
        $group->delete();
        ControlledTagGroups::flush();

        return back()->with('message', __('admin.material_tags.controlled_group_deleted', ['group' => $name]));
    }

    public function store(Request $request): RedirectResponse
    {
        $controlledGroups = ControlledTagGroups::names();
        $payload = $request->validate([
            'group_name' => ['required', 'string', 'max:100', Rule::in($controlledGroups)],
            'name' => ['required', 'string', 'max:100'],
        ], [
            'group_name.required' => __('admin.material_tags.error_group_name_required'),
            'group_name.in' => __('admin.material_tags.error_group_name_not_allowed'),
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

    public function search(Request $request): JsonResponse
    {
        $query = trim((string) $request->query('q', $request->query('search', '')));
        $limit = min(30, max(5, (int) $request->query('limit', 20)));
        $scope = trim((string) $request->query('scope', ''));
        $group = trim((string) $request->query('group', ''));

        if ($scope === 'images') {
            $items = $this->searchScopedTagOptions($query, $limit + 1, ['images'], 'admin.task_create.option.image_tag_count', $group);

            return response()->json([
                'items' => array_slice($items, 0, $limit),
                'pagination' => ['has_more' => count($items) > $limit],
            ]);
        }

        if ($scope === 'knowledge') {
            $items = $this->searchScopedTagOptions($query, $limit + 1, ['knowledgeBases', 'entities', 'caseRecords'], 'admin.task_create.option.knowledge_tag_count', $group);

            return response()->json([
                'items' => array_slice($items, 0, $limit),
                'pagination' => ['has_more' => count($items) > $limit],
            ]);
        }

        $items = $this->tagService->searchTagOptions($query, 'material', $limit + 1);

        return response()->json([
            'items' => array_slice($items, 0, $limit),
            'pagination' => ['has_more' => count($items) > $limit],
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
                    ->with('entities:id,name')
                    ->orderBy('title')
                    ->limit(50)
                    ->get()
                    ->map(fn ($caseRecord): array => [
                        'label' => (string) $caseRecord->title,
                        'meta' => (string) (($e = $caseRecord->entities->first()) ? $e->name : ''),
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
        $controlledGroups = ControlledTagGroups::names();
        $payload = $request->validate([
            'group_name' => ['required', 'string', 'max:100', Rule::in($controlledGroups)],
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
            'group_name.required' => __('admin.material_tags.error_group_name_required'),
            'group_name.in' => __('admin.material_tags.error_group_name_not_allowed'),
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
        $controlledGroups = ControlledTagGroups::names();
        $payload = $request->validate([
            'tag_ids' => ['required', 'array'],
            'tag_ids.*' => ['integer', Rule::exists('tags', 'id')->where(static fn ($query) => $query->where('type', 'material'))],
            'bulk_action' => ['required', 'string', 'in:delete,move_group'],
            'bulk_group_name' => ['nullable', 'required_if:bulk_action,move_group', 'string', 'max:100', Rule::in($controlledGroups)],
            'delete_confirmation' => ['nullable', 'string'],
        ], [
            'tag_ids.required' => __('admin.material_tags.error_select_tags'),
            'bulk_group_name.required_if' => __('admin.material_tags.error_group_name_required'),
            'bulk_group_name.in' => __('admin.material_tags.error_group_name_not_allowed'),
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
        return Cache::remember(self::STATS_CACHE_KEY, now()->addMinutes(5), fn (): array => [
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
        Cache::forget(self::STATS_CACHE_KEY);
    }

    /**
     * @param  list<string>  $relations
     * @return list<array{id:int,label:string,count:int,meta:string}>
     */
    private function searchScopedTagOptions(string $query, int $limit, array $relations, string $countLabelKey, string $group = ''): array
    {
        if ($group !== '' && ! in_array($group, ControlledTagGroups::names(), true)) {
            return [];
        }

        $builder = Tag::query()
            ->where('type', 'material')
            ->withCount($relations)
            ->where(function ($nested) use ($relations): void {
                foreach ($relations as $relation) {
                    $nested->orWhereHas($relation);
                }
            });

        if ($group !== '') {
            $builder->where('group_name', $group);
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
     * @return list<string>
     */
    private function selectedGroups(Request $request): array
    {
        $groups = $request->query('groups', []);
        if (! is_array($groups)) {
            $groups = [$groups];
        }
        $allowedGroups = ControlledTagGroups::names();

        return collect($groups)
            ->map(static fn ($group): string => trim((string) $group))
            ->filter(static fn (string $group): bool => $group !== '')
            ->filter(static fn (string $group): bool => in_array($group, $allowedGroups, true))
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
        return ControlledTagGroups::names();
    }

    /**
     * @return list<string>
     */
    private function scopeGroupOptions(string $scope): array
    {
        if ($scope === '') {
            return [];
        }

        $allowedGroups = ControlledTagGroups::names();
        if ($allowedGroups === []) {
            return [];
        }

        return Tag::query()
            ->where('type', 'material')
            ->whereIn('group_name', $allowedGroups)
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

    /**
     * @return list<array{id:int,name:string}>
     */
    private function controlledTagGroups(): array
    {
        return ControlledTagGroup::query()
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(static fn (ControlledTagGroup $group): array => [
                'id' => (int) $group->id,
                'name' => (string) $group->name,
            ])
            ->values()
            ->all();
    }
}
