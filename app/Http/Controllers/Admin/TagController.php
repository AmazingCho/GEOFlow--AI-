<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Tag;
use App\Services\GeoFlow\TagService;
use App\Support\AdminWeb;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

    public function __construct(private readonly TagService $tagService) {}

    public function index(Request $request): View
    {
        $search = trim((string) $request->query('search', ''));
        $selectedGroups = $this->selectedGroups($request);
        $perPage = $this->perPage($request);
        $scope = $this->scope($request);

        $query = Tag::query()
            ->withCount(['keywords', 'images', 'knowledgeBases', 'entities', 'caseRecords'])
            ->with([
                'keywords' => fn ($query) => $query->with('library:id,name')->orderBy('keyword'),
                'images' => fn ($query) => $query->with('library:id,name')->orderByDesc('created_at'),
                'knowledgeBases' => fn ($query) => $query->orderBy('name'),
                'entities' => fn ($query) => $query->orderBy('name'),
                'caseRecords' => fn ($query) => $query->with('entity:id,name')->orderBy('title'),
            ])
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

        return redirect()
            ->route('admin.material-tags.index')
            ->with('message', __($tag->wasRecentlyCreated ? 'admin.material_tags.message_created' : 'admin.material_tags.message_exists', [
                'tag' => $tag->displayName(),
            ]));
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
        return [
            'total' => Tag::query()->count(),
            'keyword_links' => (int) Tag::query()->withCount('keywords')->get()->sum('keywords_count'),
            'image_links' => (int) Tag::query()->withCount('images')->get()->sum('images_count'),
            'knowledge_links' => (int) Tag::query()->withCount('knowledgeBases')->get()->sum('knowledge_bases_count'),
            'entity_links' => (int) Tag::query()->withCount('entities')->get()->sum('entities_count'),
            'case_links' => (int) Tag::query()->withCount('caseRecords')->get()->sum('case_records_count'),
        ];
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
