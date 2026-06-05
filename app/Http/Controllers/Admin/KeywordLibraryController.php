<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Models\Keyword;
use App\Models\KeywordLibrary;
use App\Services\GeoFlow\EntityMaterialLinkService;
use App\Services\GeoFlow\TagService;
use App\Support\AdminWeb;
use App\Support\GeoFlow\CollectionOptions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * 关键词库管理控制器。
 */
class KeywordLibraryController extends Controller
{
    private const DETAIL_PER_PAGE = 50;

    public function __construct(
        private readonly TagService $tagService,
        private readonly EntityMaterialLinkService $entityMaterialLinkService
    ) {}

    /**
     * 列表页。
     */
    public function index(Request $request): View
    {
        $collectionId = $this->selectedCollectionId($request);

        return view('admin.keyword-libraries.index', [
            'pageTitle' => __('admin.keyword_libraries.page_title'),
            'activeMenu' => 'materials',
            'adminSiteName' => AdminWeb::siteName(),
            'libraries' => $this->loadLibraries($collectionId),
            'stats' => $this->loadStats(),
            'collectionOptions' => CollectionOptions::all(),
            'collectionId' => $collectionId,
        ]);
    }

    /**
     * 关键词库详情页。
     */
    public function detail(Request $request, int $libraryId): View|RedirectResponse
    {
        $library = KeywordLibrary::query()->with('collection')->whereKey($libraryId)->firstOrFail();

        $search = trim((string) $request->query('search', ''));
        $tagFilter = trim((string) $request->query('tag', ''));
        $keywords = $this->loadDetailKeywords($libraryId, $search, $tagFilter);
        $usageTotal = $this->loadUsageTotal($libraryId);
        $selectedTagIdsByKeyword = [];
        foreach ($keywords as $keyword) {
            $selectedTagIdsByKeyword[(int) $keyword->id] = $keyword->tags->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        }
        $selectedTagIds = collect($selectedTagIdsByKeyword)->flatten()->map(static fn ($id): int => (int) $id)->unique()->values()->all();

        return view('admin.keyword-libraries.detail', [
            'pageTitle' => (string) $library->name.__('admin.keyword_detail.page_title_suffix'),
            'activeMenu' => 'materials',
            'adminSiteName' => AdminWeb::siteName(),
            'library' => $library,
            'search' => $search,
            'tagFilter' => $tagFilter,
            'keywords' => $keywords,
            'usageTotal' => $usageTotal,
            'collectionOptions' => CollectionOptions::all(),
            'targetLibraryOptions' => $this->targetLibraryOptions($libraryId),
            'tagOptions' => $this->tagService->tagOptionsForIds($selectedTagIds),
        ]);
    }

    /**
     * 在详情页中新增关键词。
     */
    public function storeKeyword(Request $request, int $libraryId): RedirectResponse
    {
        $library = KeywordLibrary::query()->whereKey($libraryId)->firstOrFail();

        $payload = $request->validate([
            'keyword' => ['required', 'string', 'max:200'],
            'tag_ids' => ['nullable', 'array'],
            'tag_ids.*' => ['integer', Rule::exists('tags', 'id')->where('type', 'material')],
        ], [
            'keyword.required' => __('admin.keyword_detail.error.keyword_required'),
        ]);

        $keyword = trim((string) $payload['keyword']);
        if ($keyword === '') {
            return back()->withErrors(__('admin.keyword_detail.error.keyword_required'));
        }

        $exists = Keyword::query()
            ->where('library_id', $libraryId)
            ->where('keyword', $keyword)
            ->exists();
        if ($exists) {
            return back()->withErrors(__('admin.keyword_detail.error.keyword_exists'));
        }

        $createdKeyword = Keyword::query()->create([
            'library_id' => $libraryId,
            'keyword' => $keyword,
            'used_count' => 0,
            'usage_count' => 0,
        ]);
        $this->tagService->syncExisting($createdKeyword, $this->selectedTagIds($request));
        $this->refreshKeywordLibraryCount($libraryId);

        return redirect()->route('admin.keyword-libraries.detail', ['libraryId' => $libraryId])->with('message', __('admin.keyword_detail.message.add_success'));
    }

    public function updateKeywordTags(Request $request, int $libraryId, int $keywordId): RedirectResponse
    {
        $library = KeywordLibrary::query()->whereKey($libraryId)->firstOrFail();
        $keyword = Keyword::query()
            ->where('library_id', (int) $library->id)
            ->whereKey($keywordId)
            ->firstOrFail();

        $payload = $request->validate([
            'tag_ids' => ['nullable', 'array'],
            'tag_ids.*' => ['integer', Rule::exists('tags', 'id')->where('type', 'material')],
        ]);

        $this->tagService->syncExisting($keyword, $this->selectedTagIds($request));

        return redirect()
            ->route('admin.keyword-libraries.detail', [
                'libraryId' => $libraryId,
                'search' => trim((string) $request->input('search', '')),
                'tag' => trim((string) $request->input('tag', '')),
            ])
            ->with('message', '关键词标签已更新');
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
        return collect($request->input('entity_ids', []))
            ->map(static fn ($id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * 在详情页中删除关键词（支持单条/批量）。
     */
    public function destroyKeywords(Request $request, int $libraryId): RedirectResponse
    {
        $library = KeywordLibrary::query()->whereKey($libraryId)->firstOrFail();

        $keywordIds = $this->selectedKeywordIds($request);

        if ($keywordIds->isEmpty()) {
            return back()->withErrors(__('admin.keyword_detail.error.select_required'));
        }

        $this->tagService->detachTaggables(Keyword::class, $keywordIds->all());
        $deletedCount = Keyword::query()
            ->where('library_id', $libraryId)
            ->whereIn('id', $keywordIds->all())
            ->delete();
        $this->refreshKeywordLibraryCount($libraryId);

        return redirect()->route('admin.keyword-libraries.detail', ['libraryId' => $libraryId])->with(
            'message',
            __('admin.keyword_detail.message.delete_success', ['count' => $deletedCount])
        );
    }

    /**
     * 批量移动/复制关键词到其他关键词库。
     */
    public function organizeKeywords(Request $request, int $libraryId): RedirectResponse
    {
        $library = KeywordLibrary::query()->whereKey($libraryId)->firstOrFail();
        $keywordIds = $this->selectedKeywordIds($request);
        if ($keywordIds->isEmpty()) {
            return back()->withErrors(__('admin.keyword_detail.error.select_required'));
        }

        $payload = $request->validate([
            'bulk_action' => ['required', Rule::in(['move', 'copy'])],
            'target_library_id' => ['required', 'integer', Rule::exists('keyword_libraries', 'id')],
        ]);

        $targetLibraryId = (int) $payload['target_library_id'];
        if ($targetLibraryId === (int) $library->id) {
            return back()->withErrors(__('admin.keyword_detail.error.target_library_required'));
        }

        $sourceKeywords = Keyword::query()
            ->with('tags:id')
            ->where('library_id', (int) $library->id)
            ->whereIn('id', $keywordIds->all())
            ->get();
        if ($sourceKeywords->isEmpty()) {
            return back()->withErrors(__('admin.keyword_detail.error.select_required'));
        }

        $processedCount = 0;
        $duplicateCount = 0;
        DB::transaction(function () use ($sourceKeywords, $targetLibraryId, $payload, &$processedCount, &$duplicateCount): void {
            foreach ($sourceKeywords as $sourceKeyword) {
                $keywordText = trim((string) $sourceKeyword->keyword);
                if ($keywordText === '') {
                    continue;
                }

                $targetKeyword = Keyword::query()
                    ->where('library_id', $targetLibraryId)
                    ->where('keyword', $keywordText)
                    ->first();
                if (! $targetKeyword) {
                    $targetKeyword = Keyword::query()->create([
                        'library_id' => $targetLibraryId,
                        'keyword' => $keywordText,
                        'used_count' => (int) ($sourceKeyword->used_count ?? 0),
                        'usage_count' => (int) ($sourceKeyword->usage_count ?? 0),
                    ]);
                } else {
                    $duplicateCount++;
                }

                $tagIds = $sourceKeyword->tags->pluck('id')->map(static fn ($id): int => (int) $id)->all();
                if ($tagIds !== []) {
                    $targetKeyword->tags()->syncWithoutDetaching($tagIds);
                }

                if ((string) $payload['bulk_action'] === 'move') {
                    $sourceKeyword->delete();
                }

                $processedCount++;
            }

            $this->refreshKeywordLibraryCount($targetLibraryId);
        });
        $this->refreshKeywordLibraryCount((int) $library->id);

        $messageKey = (string) $payload['bulk_action'] === 'move'
            ? 'admin.keyword_detail.message.move_success'
            : 'admin.keyword_detail.message.copy_success';
        $message = __($messageKey, ['count' => $processedCount]);
        if ($duplicateCount > 0) {
            $message .= __('admin.keyword_detail.message.organize_duplicates', ['count' => $duplicateCount]);
        }

        return redirect()->route('admin.keyword-libraries.detail', ['libraryId' => (int) $library->id])->with('message', $message);
    }

    /**
     * 在详情页中更新关键词库基础信息。
     */
    public function updateFromDetail(Request $request, int $libraryId): RedirectResponse
    {
        $library = KeywordLibrary::query()->whereKey($libraryId)->firstOrFail();

        $payload = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string'],
            'collection_id' => ['nullable', 'integer', 'min:1', Rule::exists('collections', 'id')],
        ], [
            'name.required' => __('admin.keyword_detail.error.library_name_required'),
        ]);

        $library->update([
            'collection_id' => $this->normalizeCollectionId($payload),
            'name' => trim((string) $payload['name']),
            'description' => trim((string) ($payload['description'] ?? '')),
        ]);

        return redirect()->route('admin.keyword-libraries.detail', ['libraryId' => $libraryId])->with('message', __('admin.keyword_detail.message.update_success'));
    }

    /**
     * 在详情页中导入关键词（逐行 + 逗号分隔）。
     */
    public function importKeywords(Request $request, int $libraryId): RedirectResponse
    {
        $library = KeywordLibrary::query()->whereKey($libraryId)->firstOrFail();

        $payload = $request->validate([
            'keywords_text' => ['required', 'string'],
        ], [
            'keywords_text.required' => __('admin.keyword_libraries.error.keywords_required'),
        ]);

        $keywords = $this->parseKeywordImportText((string) $payload['keywords_text']);
        if ($keywords->isEmpty()) {
            return back()->withErrors(__('admin.keyword_libraries.error.keywords_required'));
        }

        $importedCount = 0;
        $duplicateCount = 0;

        DB::transaction(function () use ($keywords, $libraryId, &$importedCount, &$duplicateCount): void {
            foreach ($keywords as $keyword) {
                $exists = Keyword::query()
                    ->where('library_id', $libraryId)
                    ->where('keyword', $keyword)
                    ->exists();
                if ($exists) {
                    $duplicateCount++;

                    continue;
                }

                Keyword::query()->create([
                    'library_id' => $libraryId,
                    'keyword' => $keyword,
                    'used_count' => 0,
                    'usage_count' => 0,
                ]);
                $importedCount++;
            }

            $this->refreshKeywordLibraryCount($libraryId);
        });

        $message = __('admin.keyword_libraries.message.import_success', ['count' => $importedCount]);
        if ($duplicateCount > 0) {
            $message .= __('admin.keyword_libraries.message.import_skip', ['count' => $duplicateCount]);
        }

        return redirect()->route('admin.keyword-libraries.detail', ['libraryId' => $libraryId])->with('message', $message);
    }

    /**
     * 创建表单页。
     */
    public function create(): View
    {
        return view('admin.keyword-libraries.form', [
            'pageTitle' => __('admin.keyword_libraries.page_title'),
            'activeMenu' => 'materials',
            'adminSiteName' => AdminWeb::siteName(),
            'isEdit' => false,
            'libraryId' => 0,
            'libraryForm' => $this->emptyForm(),
            'collectionOptions' => CollectionOptions::all(true),
            'entityOptions' => $this->entityMaterialLinkService->entityOptions(),
            'selectedEntityIds' => [],
        ]);
    }

    /**
     * 创建关键词库。
     */
    public function store(Request $request): RedirectResponse
    {
        $payload = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string'],
            'collection_id' => ['nullable', 'integer', 'min:1', Rule::exists('collections', 'id')],
            'entity_ids' => ['nullable', 'array'],
            'entity_ids.*' => ['integer', Rule::exists('entities', 'id')],
        ], [
            'name.required' => __('admin.keyword_libraries.error.name_required'),
        ]);

        $library = KeywordLibrary::query()->create([
            'collection_id' => $this->normalizeCollectionId($payload),
            'name' => trim((string) $payload['name']),
            'description' => trim((string) ($payload['description'] ?? '')),
            'keyword_count' => 0,
        ]);
        $this->entityMaterialLinkService->syncEntities($library, $this->selectedEntityIds($request));

        return redirect()->route('admin.keyword-libraries.index')->with('message', __('admin.keyword_libraries.message.create_success'));
    }

    /**
     * 编辑表单页。
     */
    public function edit(int $libraryId): View|RedirectResponse
    {
        $library = KeywordLibrary::query()->whereKey($libraryId)->firstOrFail();

        return view('admin.keyword-libraries.form', [
            'pageTitle' => __('admin.keyword_libraries.page_title'),
            'activeMenu' => 'materials',
            'adminSiteName' => AdminWeb::siteName(),
            'isEdit' => true,
            'libraryId' => (int) $library->id,
            'libraryForm' => [
                'collection_id' => (string) ((int) ($library->collection_id ?? 0) ?: ''),
                'name' => (string) $library->name,
                'description' => (string) ($library->description ?? ''),
            ],
            'collectionOptions' => CollectionOptions::all(),
            'entityOptions' => $this->entityMaterialLinkService->entityOptions((int) ($library->collection_id ?? 0) ?: null),
            'selectedEntityIds' => $this->entityMaterialLinkService->selectedEntityIdsFor($library),
        ]);
    }

    /**
     * 更新关键词库。
     */
    public function update(Request $request, int $libraryId): RedirectResponse
    {
        $library = KeywordLibrary::query()->whereKey($libraryId)->firstOrFail();

        $payload = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string'],
            'collection_id' => ['nullable', 'integer', 'min:1', Rule::exists('collections', 'id')],
            'entity_ids' => ['nullable', 'array'],
            'entity_ids.*' => ['integer', Rule::exists('entities', 'id')],
        ], [
            'name.required' => __('admin.keyword_libraries.error.name_required'),
        ]);

        $library->update([
            'collection_id' => $this->normalizeCollectionId($payload),
            'name' => trim((string) $payload['name']),
            'description' => trim((string) ($payload['description'] ?? '')),
        ]);
        $this->entityMaterialLinkService->syncEntities($library, $this->selectedEntityIds($request));

        return redirect()
            ->route('admin.keyword-libraries.edit', ['libraryId' => (int) $library->id])
            ->with('message', __('admin.keyword_libraries.message.update_success'));
    }

    /**
     * 删除关键词库（包含词条）。
     */
    public function destroy(Request $request, int $libraryId): RedirectResponse
    {
        $library = KeywordLibrary::query()->whereKey($libraryId)->firstOrFail();

        $keywordIds = Keyword::query()
            ->where('library_id', $libraryId)
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
        $this->tagService->detachTaggables(Keyword::class, $keywordIds);
        Keyword::query()->where('library_id', $libraryId)->delete();
        $library->delete();

        return redirect()
            ->route('admin.keyword-libraries.index', $request->query())
            ->withFragment('material-list')
            ->with('message', __('admin.keyword_libraries.message.delete_success'));
    }

    /**
     * @return array<int, array{id:int,name:string,description:string,collection_name:string,collection_id:int,actual_count:int,created_at:?string,updated_at:?string}>
     */
    private function loadLibraries(?int $collectionId = null): array
    {
        $query = KeywordLibrary::query()
            ->select(['id', 'collection_id', 'name', 'description', 'created_at', 'updated_at'])
            ->with('collection:id,name,status')
            ->withCount('keywords as actual_count')
            ->orderByDesc('created_at');

        if ($collectionId !== null) {
            $query->where('collection_id', $collectionId);
        }

        return $query->get()->map(static function (KeywordLibrary $library): array {
            return [
                'id' => (int) $library->id,
                'collection_id' => (int) ($library->collection_id ?? 0),
                'collection_name' => (string) ($library->collection?->name ?? ''),
                'name' => (string) $library->name,
                'description' => (string) ($library->description ?? ''),
                'actual_count' => (int) ($library->actual_count ?? 0),
                'created_at' => $library->created_at?->format('Y-m-d H:i:s'),
                'updated_at' => $library->updated_at?->format('Y-m-d H:i:s'),
            ];
        })->all();
    }

    /**
     * @return array{total_libraries:int,total_keywords:int,avg_keywords:float}
     */
    private function loadStats(): array
    {
        $totalLibraries = KeywordLibrary::query()->count();
        $totalKeywords = Keyword::query()->count();

        return [
            'total_libraries' => $totalLibraries,
            'total_keywords' => $totalKeywords,
            'avg_keywords' => $totalLibraries > 0 ? round($totalKeywords / $totalLibraries, 1) : 0.0,
        ];
    }

    /**
     * @return array{name:string,description:string}
     */
    private function emptyForm(): array
    {
        return [
            'collection_id' => '',
            'name' => '',
            'description' => '',
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

    /**
     * @return LengthAwarePaginator<int, Keyword>
     */
    private function loadDetailKeywords(int $libraryId, string $search, string $tagFilter): LengthAwarePaginator
    {
        $query = Keyword::query()
            ->with(['tags' => fn ($query) => $query->orderBy('group_name')->orderBy('name')])
            ->where('library_id', $libraryId)
            ->orderByDesc('created_at');
        if ($search !== '') {
            $query->where('keyword', 'like', '%'.$search.'%');
        }
        $this->tagService->applyFilter($query, $tagFilter);

        return $query->paginate(self::DETAIL_PER_PAGE)->withQueryString();
    }

    /**
     * @return Collection<int, string>
     */
    private function parseKeywordImportText(string $keywordsText): Collection
    {
        return collect(preg_split('/\R/u', $keywordsText) ?: [])
            ->flatMap(static function (string $line): array {
                return array_map('trim', explode(',', $line));
            })
            ->map(static fn (string $keyword): string => trim($keyword))
            ->filter(static fn (string $keyword): bool => $keyword !== '')
            ->unique()
            ->values();
    }

    /**
     * 维护关键词库缓存计数，避免列表统计偏差。
     */
    private function refreshKeywordLibraryCount(int $libraryId): void
    {
        $count = Keyword::query()->where('library_id', $libraryId)->count();
        KeywordLibrary::query()->whereKey($libraryId)->update([
            'keyword_count' => $count,
        ]);
    }

    /**
     * @return Collection<int, int>
     */
    private function selectedKeywordIds(Request $request): Collection
    {
        /** @var array<int, mixed> $rawIds */
        $rawIds = (array) $request->input('keyword_ids', []);

        return collect($rawIds)
            ->map(static fn (mixed $id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->values();
    }

    /**
     * @return list<array{id:int,name:string,collection_name:string}>
     */
    private function targetLibraryOptions(int $currentLibraryId): array
    {
        return KeywordLibrary::query()
            ->with('collection:id,name')
            ->select(['id', 'collection_id', 'name'])
            ->whereKeyNot($currentLibraryId)
            ->orderBy('name')
            ->get()
            ->map(static fn (KeywordLibrary $library): array => [
                'id' => (int) $library->id,
                'name' => (string) $library->name,
                'collection_name' => (string) ($library->collection?->name ?? ''),
            ])
            ->all();
    }

    /**
     * 按 legacy 页面口径统计关键词总使用次数。
     *
     * 统计规则与 bak/admin/keyword-library-detail.php 一致：
     * 通过文章表 original_keyword 与关键词库中的 keyword 进行匹配计数。
     */
    private function loadUsageTotal(int $libraryId): int
    {
        if (! Schema::hasColumn('articles', 'original_keyword')) {
            return 0;
        }

        return (int) Article::query()
            ->whereIn('original_keyword', function ($query) use ($libraryId): void {
                $query->select('keyword')
                    ->from('keywords')
                    ->where('library_id', $libraryId);
            })
            ->count();
    }
}
