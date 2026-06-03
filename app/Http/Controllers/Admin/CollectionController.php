<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CollectionRecord;
use App\Support\AdminWeb;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CollectionController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim((string) $request->query('search', ''));

        $query = CollectionRecord::query()
            ->withCount([
                'knowledgeBases',
                'entities',
                'cases',
                'keywordLibraries',
                'titleLibraries',
                'imageLibraries',
            ])
            ->orderBy('sort_order')
            ->orderBy('name');

        if ($search !== '') {
            $query->where(function ($builder) use ($search): void {
                $builder
                    ->where('name', 'like', '%'.$search.'%')
                    ->orWhere('slug', 'like', '%'.$search.'%')
                    ->orWhere('description', 'like', '%'.$search.'%');
            });
        }

        $collections = $query->paginate(20)->withQueryString();

        return view('admin.collections.index', [
            'pageTitle' => __('admin.collections.page_title'),
            'activeMenu' => 'collections',
            'adminSiteName' => AdminWeb::siteName(),
            'search' => $search,
            'collections' => $collections,
            'stats' => [
                'total' => CollectionRecord::query()->count(),
                'active' => CollectionRecord::query()->where('status', 'active')->count(),
                'used' => CollectionRecord::query()
                    ->where(function ($builder): void {
                        $builder
                            ->whereHas('knowledgeBases')
                            ->orWhereHas('entities')
                            ->orWhereHas('cases')
                            ->orWhereHas('keywordLibraries')
                            ->orWhereHas('titleLibraries')
                            ->orWhereHas('imageLibraries');
                    })
                    ->count(),
            ],
        ]);
    }

    public function create(): View
    {
        return view('admin.collections.form', [
            'pageTitle' => __('admin.collections.create_title'),
            'activeMenu' => 'collections',
            'adminSiteName' => AdminWeb::siteName(),
            'isEdit' => false,
            'collectionId' => 0,
            'collectionForm' => [
                'name' => '',
                'slug' => '',
                'description' => '',
                'status' => 'active',
                'sort_order' => 0,
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $payload = $this->validateCollection($request);

        CollectionRecord::query()->create($this->normalizePayload($payload));

        return redirect()
            ->route('admin.collections.index')
            ->with('message', __('admin.collections.message.create_success'));
    }

    public function edit(int $collectionId): View
    {
        $collection = CollectionRecord::query()->whereKey($collectionId)->firstOrFail();

        return view('admin.collections.form', [
            'pageTitle' => __('admin.collections.edit_title'),
            'activeMenu' => 'collections',
            'adminSiteName' => AdminWeb::siteName(),
            'isEdit' => true,
            'collectionId' => (int) $collection->id,
            'collectionForm' => [
                'name' => (string) $collection->name,
                'slug' => (string) $collection->slug,
                'description' => (string) ($collection->description ?? ''),
                'status' => (string) $collection->status,
                'sort_order' => (int) $collection->sort_order,
            ],
        ]);
    }

    public function update(Request $request, int $collectionId): RedirectResponse
    {
        $collection = CollectionRecord::query()->whereKey($collectionId)->firstOrFail();
        $payload = $this->validateCollection($request, $collectionId);

        $collection->update($this->normalizePayload($payload, $collectionId));

        return redirect()
            ->route('admin.collections.index')
            ->with('message', __('admin.collections.message.update_success'));
    }

    public function toggle(int $collectionId): RedirectResponse
    {
        $collection = CollectionRecord::query()->whereKey($collectionId)->firstOrFail();
        $collection->update([
            'status' => $collection->isActive() ? 'inactive' : 'active',
        ]);

        return back()->with('message', __('admin.collections.message.status_success'));
    }

    public function destroy(int $collectionId): RedirectResponse
    {
        $collection = CollectionRecord::query()
            ->withCount([
                'knowledgeBases',
                'entities',
                'cases',
                'keywordLibraries',
                'titleLibraries',
                'imageLibraries',
            ])
            ->whereKey($collectionId)
            ->firstOrFail();

        $usageCount = $this->usageCount($collection);
        if ($usageCount > 0) {
            return back()->withErrors(__('admin.collections.error.delete_in_use', ['count' => $usageCount]));
        }

        $collection->delete();

        return redirect()
            ->route('admin.collections.index')
            ->with('message', __('admin.collections.message.delete_success'));
    }

    /**
     * @return array<string, mixed>
     */
    private function validateCollection(Request $request, ?int $ignoreId = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'slug' => [
                'nullable',
                'string',
                'max:160',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('collections', 'slug')->ignore($ignoreId),
            ],
            'description' => ['nullable', 'string', 'max:5000'],
            'status' => ['required', 'in:active,inactive'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:999999'],
        ], [
            'name.required' => __('admin.collections.error.name_required'),
            'slug.regex' => __('admin.collections.error.slug_invalid'),
            'slug.unique' => __('admin.collections.error.slug_exists'),
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function normalizePayload(array $payload, ?int $ignoreId = null): array
    {
        $name = trim((string) $payload['name']);
        $slug = trim((string) ($payload['slug'] ?? ''));
        if ($slug === '') {
            $slug = Str::slug($name);
        }
        if ($slug === '') {
            $slug = 'collection-'.Str::lower(Str::random(8));
        }
        $slug = $this->uniqueSlug($slug, $ignoreId);

        return [
            'name' => $name,
            'slug' => $slug,
            'description' => trim((string) ($payload['description'] ?? '')),
            'status' => (string) ($payload['status'] ?? 'active'),
            'sort_order' => max(0, (int) ($payload['sort_order'] ?? 0)),
        ];
    }

    private function usageCount(CollectionRecord $collection): int
    {
        return (int) ($collection->knowledge_bases_count ?? 0)
            + (int) ($collection->entities_count ?? 0)
            + (int) ($collection->cases_count ?? 0)
            + (int) ($collection->keyword_libraries_count ?? 0)
            + (int) ($collection->title_libraries_count ?? 0)
            + (int) ($collection->image_libraries_count ?? 0);
    }

    private function uniqueSlug(string $slug, ?int $ignoreId = null): string
    {
        $baseSlug = $slug;
        $counter = 2;

        while (CollectionRecord::query()
            ->where('slug', $slug)
            ->when($ignoreId !== null, static fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists()) {
            $slug = $baseSlug.'-'.$counter;
            $counter++;
        }

        return $slug;
    }
}
