<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AiModel;
use App\Models\Keyword;
use App\Models\KeywordLibrary;
use App\Models\Task;
use App\Models\Title;
use App\Models\TitleLibrary;
use App\Services\GeoFlow\TagService;
use App\Services\GeoFlow\TitleAiGenerationService;
use App\Support\AdminWeb;
use App\Support\GeoFlow\CollectionOptions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * 标题库管理控制器。
 */
class TitleLibraryController extends Controller
{
    private const DETAIL_PER_PAGE = 20;

    public function __construct(
        private TitleAiGenerationService $titleAiGenerationService,
        private readonly TagService $tagService
    ) {}

    /**
     * 列表页。
     */
    public function index(Request $request): View
    {
        $collectionId = $this->selectedCollectionId($request);

        return view('admin.title-libraries.index', [
            'pageTitle' => __('admin.title_libraries.page_title'),
            'activeMenu' => 'materials',
            'adminSiteName' => AdminWeb::siteName(),
            'libraries' => $this->loadLibraries($collectionId),
            'stats' => $this->loadStats(),
            'collectionOptions' => CollectionOptions::all(),
            'collectionId' => $collectionId,
        ]);
    }

    /**
     * 标题库详情页。
     */
    public function detail(Request $request, int $libraryId): View|RedirectResponse
    {
        $library = TitleLibrary::query()->with('collection')->whereKey($libraryId)->firstOrFail();

        $titles = $this->loadDetailTitles($libraryId, '');
        $usageTotal = (int) (Title::query()->where('library_id', $libraryId)->sum('used_count') ?? 0);

        return view('admin.title-libraries.detail', [
            'pageTitle' => (string) $library->name.__('admin.title_detail.page_title_suffix'),
            'activeMenu' => 'materials',
            'adminSiteName' => AdminWeb::siteName(),
            'library' => $library,
            'titles' => $titles,
            'usageTotal' => $usageTotal,
            'targetLibraryOptions' => $this->targetLibraryOptions($libraryId),
        ]);
    }

    /**
     * AI 生成标题页。
     */
    public function aiGenerate(int $libraryId): View|RedirectResponse
    {
        $library = TitleLibrary::query()->whereKey($libraryId)->firstOrFail();

        $keywordLibraries = KeywordLibrary::query()
            ->select(['id', 'name'])
            ->withCount(['keywords as keyword_count'])
            ->orderByDesc('created_at')
            ->get();
        $aiModels = AiModel::query()
            ->select(['id', 'name', 'model_id'])
            ->where('status', 'active')
            ->whereRaw("COALESCE(NULLIF(model_type, ''), 'chat') = 'chat'")
            ->orderBy('name')
            ->get();

        return view('admin.title-libraries.ai-generate', [
            'pageTitle' => __('admin.title_ai_generate.page_title'),
            'activeMenu' => 'materials',
            'adminSiteName' => AdminWeb::siteName(),
            'library' => $library,
            'keywordLibraries' => $keywordLibraries,
            'aiModels' => $aiModels,
        ]);
    }

    /**
     * 执行 AI 标题生成（当前使用可控模板生成，保证流程稳定）。
     */
    public function generateWithAi(Request $request, int $libraryId): RedirectResponse
    {
        $library = TitleLibrary::query()->whereKey($libraryId)->firstOrFail();

        $payload = $request->validate([
            'keyword_library_id' => ['required', 'integer'],
            'ai_model_id' => [
                'required',
                'integer',
                Rule::exists('ai_models', 'id')->where(static function ($query): void {
                    $query->where('status', 'active')
                        ->whereRaw("COALESCE(NULLIF(model_type, ''), 'chat') = 'chat'");
                }),
            ],
            'title_count' => ['required', 'integer', 'min:1', 'max:50'],
            'title_style' => ['required', 'in:professional,attractive,seo,creative,question'],
            'custom_prompt' => ['nullable', 'string'],
        ], [
            'keyword_library_id.required' => __('admin.title_ai_generate.error.keyword_library_required'),
            'ai_model_id.required' => __('admin.title_ai_generate.error.ai_model_required'),
            'ai_model_id.exists' => __('admin.title_ai_generate.error.ai_model_required'),
            'title_count.min' => __('admin.title_ai_generate.error.invalid_count'),
            'title_count.max' => __('admin.title_ai_generate.error.invalid_count'),
        ]);

        $keywordLibrary = KeywordLibrary::query()->whereKey((int) $payload['keyword_library_id'])->firstOrFail();

        $aiModel = AiModel::query()
            ->whereKey((int) $payload['ai_model_id'])
            ->where('status', 'active')
            ->whereRaw("COALESCE(NULLIF(model_type, ''), 'chat') = 'chat'")
            ->firstOrFail();

        /** @var Collection<int, Keyword> $keywords */
        $keywords = Keyword::query()
            ->with(['tags' => fn ($query) => $query->orderBy('group_name')->orderBy('name')])
            ->where('library_id', (int) $payload['keyword_library_id'])
            ->inRandomOrder()
            ->limit((int) config('geoflow.title_ai_keyword_sample_limit', 10))
            ->get()
            ->filter(static fn (Keyword $keyword): bool => trim((string) $keyword->keyword) !== '')
            ->values();
        if ($keywords->isEmpty()) {
            return back()->withErrors(__('admin.title_ai_generate.error.no_keywords'));
        }

        $keywordContexts = $this->buildKeywordContexts($keywords);
        $generationResult = $this->titleAiGenerationService->generateTitles(
            $aiModel,
            $keywordContexts,
            (int) $payload['title_count'],
            (string) $payload['title_style'],
            trim((string) ($payload['custom_prompt'] ?? ''))
        );
        $generatedItems = $generationResult['items'] ?? collect($generationResult['titles'])
            ->map(static fn (string $title): array => ['title' => $title, 'keyword' => ''])
            ->all();

        $savedCount = 0;
        $duplicateCount = 0;
        DB::transaction(function () use ($generatedItems, $keywordContexts, $libraryId, &$savedCount, &$duplicateCount): void {
            foreach ($generatedItems as $item) {
                $titleText = is_array($item) ? (string) ($item['title'] ?? '') : (string) $item;
                $title = $this->normalizeGeneratedTitle($titleText);
                if ($title === '' || mb_strlen($title, 'UTF-8') > 500) {
                    continue;
                }

                $exists = Title::query()
                    ->where('library_id', $libraryId)
                    ->where('title', $title)
                    ->exists();
                if ($exists) {
                    $duplicateCount++;

                    continue;
                }

                Title::query()->create([
                    'library_id' => $libraryId,
                    'title' => $title,
                    'keyword' => $this->resolveGeneratedItemKeyword(is_array($item) ? (string) ($item['keyword'] ?? '') : '', $title, $keywordContexts),
                    'is_ai_generated' => true,
                    'used_count' => 0,
                    'usage_count' => 0,
                ]);
                $savedCount++;
            }

            $this->refreshTitleLibraryCount($libraryId);
        });

        $message = __('admin.title_ai_generate.message.completed', ['count' => $savedCount]);
        if ($duplicateCount > 0) {
            $message .= __('admin.title_ai_generate.message.duplicates', ['count' => $duplicateCount]);
        }
        if (($generationResult['fallback_used'] ?? false) === true) {
            $message .= '（AI服务不可用，已使用模板兜底）';
        }

        return redirect()->route('admin.title-libraries.detail', ['libraryId' => $libraryId])->with('message', $message);
    }

    /**
     * @param  Collection<int, Keyword>  $keywords
     * @return list<array{keyword:string,tags:array<string,string>,tag_labels:list<string>}>
     */
    private function buildKeywordContexts(Collection $keywords): array
    {
        return $keywords
            ->map(static function (Keyword $keyword): array {
                $tags = [];
                $tagLabels = [];
                foreach ($keyword->tags as $tag) {
                    $groupName = trim((string) ($tag->group_name ?? ''));
                    $tagName = trim((string) ($tag->name ?? ''));
                    if ($tagName === '') {
                        continue;
                    }
                    $tagLabels[] = $groupName !== '' ? $groupName.':'.$tagName : $tagName;
                    if ($groupName !== '') {
                        $tags[$groupName] = $tagName;
                    }
                }

                return [
                    'keyword' => trim((string) $keyword->keyword),
                    'tags' => $tags,
                    'tag_labels' => $tagLabels,
                ];
            })
            ->filter(static fn (array $context): bool => $context['keyword'] !== '')
            ->values()
            ->all();
    }

    /**
     * @param  list<array{keyword:string,tags:array<string,string>,tag_labels:list<string>}>  $keywordContexts
     */
    private function resolveGeneratedItemKeyword(string $reportedKeyword, string $title, array $keywordContexts): string
    {
        foreach ($keywordContexts as $context) {
            if ($reportedKeyword !== '' && $reportedKeyword === $context['keyword']) {
                return $context['keyword'];
            }
        }
        foreach ($keywordContexts as $context) {
            if (mb_stripos($title, $context['keyword'], 0, 'UTF-8') !== false) {
                return $context['keyword'];
            }
        }

        return (string) ($keywordContexts[0]['keyword'] ?? '');
    }

    /**
     * 在详情页中新增标题。
     */
    public function storeTitle(Request $request, int $libraryId): RedirectResponse
    {
        $library = TitleLibrary::query()->whereKey($libraryId)->firstOrFail();

        $payload = $request->validate([
            'title' => ['required', 'string', 'max:500'],
            'keyword' => ['nullable', 'string', 'max:200'],
        ], [
            'title.required' => __('admin.title_detail.error.title_required'),
        ]);

        $title = trim((string) $payload['title']);
        if ($title === '') {
            return back()->withErrors(__('admin.title_detail.error.title_required'));
        }

        $exists = Title::query()
            ->where('library_id', $libraryId)
            ->where('title', $title)
            ->exists();
        if ($exists) {
            return back()->withErrors(__('admin.title_detail.error.title_exists'));
        }

        Title::query()->create([
            'library_id' => $libraryId,
            'title' => $title,
            'keyword' => trim((string) ($payload['keyword'] ?? '')),
            'is_ai_generated' => false,
            'used_count' => 0,
            'usage_count' => 0,
        ]);
        $this->refreshTitleLibraryCount($libraryId);

        return redirect()->route('admin.title-libraries.detail', ['libraryId' => $libraryId])->with('message', __('admin.title_detail.message.add_success'));
    }

    /**
     * 更新单条标题及其关联关键词。
     */
    public function updateTitle(Request $request, int $libraryId, int $titleId): RedirectResponse
    {
        $library = TitleLibrary::query()->whereKey($libraryId)->firstOrFail();
        $titleRow = Title::query()
            ->where('library_id', (int) $library->id)
            ->whereKey($titleId)
            ->firstOrFail();

        $payload = $request->validate([
            'title' => ['required', 'string', 'max:500'],
            'keyword' => ['nullable', 'string', 'max:200'],
        ], [
            'title.required' => __('admin.title_detail.error.title_required'),
        ]);

        $title = trim((string) $payload['title']);
        if ($title === '') {
            return back()->withErrors(__('admin.title_detail.error.title_required'));
        }

        $exists = Title::query()
            ->where('library_id', (int) $library->id)
            ->where('title', $title)
            ->whereKeyNot((int) $titleRow->id)
            ->exists();
        if ($exists) {
            return back()->withErrors(__('admin.title_detail.error.title_exists'));
        }

        $titleRow->update([
            'title' => $title,
            'keyword' => trim((string) ($payload['keyword'] ?? '')),
        ]);

        return redirect()
            ->route('admin.title-libraries.detail', ['libraryId' => (int) $library->id])
            ->with('message', __('admin.title_detail.message.update_success'));
    }

    /**
     * 删除标题（支持单条/批量）。
     */
    public function destroyTitles(Request $request, int $libraryId): RedirectResponse
    {
        $library = TitleLibrary::query()->whereKey($libraryId)->firstOrFail();

        $titleIds = $this->selectedTitleIds($request);
        if ($titleIds->isEmpty()) {
            return back()->withErrors(__('admin.title_detail.error.content_required'));
        }

        $deletedCount = Title::query()
            ->where('library_id', $libraryId)
            ->whereIn('id', $titleIds->all())
            ->delete();
        $this->refreshTitleLibraryCount($libraryId);

        return redirect()->route('admin.title-libraries.detail', ['libraryId' => $libraryId])->with(
            'message',
            __('admin.title_detail.message.delete_success', ['count' => $deletedCount])
        );
    }

    /**
     * 批量移动/复制标题到其他标题库。
     */
    public function organizeTitles(Request $request, int $libraryId): RedirectResponse
    {
        $library = TitleLibrary::query()->whereKey($libraryId)->firstOrFail();
        $titleIds = $this->selectedTitleIds($request);
        if ($titleIds->isEmpty()) {
            return back()->withErrors(__('admin.title_detail.error.content_required'));
        }

        $payload = $request->validate([
            'bulk_action' => ['required', Rule::in(['move', 'copy'])],
            'target_library_id' => ['required', 'integer', Rule::exists('title_libraries', 'id')],
        ]);

        $targetLibraryId = (int) $payload['target_library_id'];
        if ($targetLibraryId === (int) $library->id) {
            return back()->withErrors(__('admin.title_detail.error.target_library_required'));
        }

        $sourceTitles = Title::query()
            ->where('library_id', (int) $library->id)
            ->whereIn('id', $titleIds->all())
            ->get();
        if ($sourceTitles->isEmpty()) {
            return back()->withErrors(__('admin.title_detail.error.content_required'));
        }

        $processedCount = 0;
        $duplicateCount = 0;
        DB::transaction(function () use ($sourceTitles, $targetLibraryId, $payload, &$processedCount, &$duplicateCount): void {
            foreach ($sourceTitles as $sourceTitle) {
                $titleText = trim((string) $sourceTitle->title);
                if ($titleText === '') {
                    continue;
                }

                $targetTitle = Title::query()
                    ->where('library_id', $targetLibraryId)
                    ->where('title', $titleText)
                    ->first();
                if (! $targetTitle) {
                    Title::query()->create([
                        'library_id' => $targetLibraryId,
                        'title' => $titleText,
                        'keyword' => (string) ($sourceTitle->keyword ?? ''),
                        'is_ai_generated' => (bool) ($sourceTitle->is_ai_generated ?? false),
                        'used_count' => (int) ($sourceTitle->used_count ?? 0),
                        'usage_count' => (int) ($sourceTitle->usage_count ?? 0),
                    ]);
                } else {
                    $duplicateCount++;
                }

                if ((string) $payload['bulk_action'] === 'move') {
                    $sourceTitle->delete();
                }

                $processedCount++;
            }

            $this->refreshTitleLibraryCount($targetLibraryId);
        });
        $this->refreshTitleLibraryCount((int) $library->id);

        $messageKey = (string) $payload['bulk_action'] === 'move'
            ? 'admin.title_detail.message.move_success'
            : 'admin.title_detail.message.copy_success';
        $message = __($messageKey, ['count' => $processedCount]);
        if ($duplicateCount > 0) {
            $message .= __('admin.title_detail.message.organize_duplicates', ['count' => $duplicateCount]);
        }

        return redirect()->route('admin.title-libraries.detail', ['libraryId' => (int) $library->id])->with('message', $message);
    }

    /**
     * 批量导入标题（支持“标题|关键词”格式）。
     */
    public function importTitles(Request $request, int $libraryId): RedirectResponse
    {
        $library = TitleLibrary::query()->whereKey($libraryId)->firstOrFail();

        $payload = $request->validate([
            'titles_text' => ['required', 'string'],
        ], [
            'titles_text.required' => __('admin.title_detail.error.content_required'),
        ]);

        /** @var Collection<int, array{title:string,keyword:string}> $entries */
        $entries = $this->parseTitleImportText((string) $payload['titles_text']);
        if ($entries->isEmpty()) {
            return back()->withErrors(__('admin.title_detail.error.content_required'));
        }

        $importedCount = 0;
        $duplicateCount = 0;
        DB::transaction(function () use ($entries, $libraryId, &$importedCount, &$duplicateCount): void {
            foreach ($entries as $entry) {
                $exists = Title::query()
                    ->where('library_id', $libraryId)
                    ->where('title', $entry['title'])
                    ->exists();
                if ($exists) {
                    $duplicateCount++;

                    continue;
                }

                Title::query()->create([
                    'library_id' => $libraryId,
                    'title' => $entry['title'],
                    'keyword' => $entry['keyword'],
                    'is_ai_generated' => false,
                    'used_count' => 0,
                    'usage_count' => 0,
                ]);
                $importedCount++;
            }

            $this->refreshTitleLibraryCount($libraryId);
        });

        $message = __('admin.title_detail.message.import_success', ['count' => $importedCount]);
        if ($duplicateCount > 0) {
            $message .= __('admin.title_detail.message.import_skip', ['count' => $duplicateCount]);
        }

        return redirect()->route('admin.title-libraries.detail', ['libraryId' => $libraryId])->with('message', $message);
    }

    /**
     * 创建表单页。
     */
    public function create(): View
    {
        return view('admin.title-libraries.form', [
            'pageTitle' => __('admin.title_libraries.page_title'),
            'activeMenu' => 'materials',
            'adminSiteName' => AdminWeb::siteName(),
            'isEdit' => false,
            'libraryId' => 0,
            'libraryForm' => $this->emptyForm(),
            'collectionOptions' => CollectionOptions::all(true),
        ]);
    }

    /**
     * 创建标题库。
     */
    public function store(Request $request): RedirectResponse
    {
        $payload = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string'],
            'collection_id' => ['nullable', 'integer', 'min:1', Rule::exists('collections', 'id')],
        ], [
            'name.required' => __('admin.title_libraries.error.name_required'),
        ]);

        $library = TitleLibrary::query()->create([
            'collection_id' => $this->normalizeCollectionId($payload),
            'name' => trim((string) $payload['name']),
            'description' => trim((string) ($payload['description'] ?? '')),
            'title_count' => 0,
            'generation_type' => 'manual',
            'generation_rounds' => 1,
            'is_ai_generated' => 0,
        ]);

        return redirect()->route('admin.title-libraries.index')->with('message', __('admin.title_libraries.message.create_success'));
    }

    /**
     * 编辑表单页。
     */
    public function edit(int $libraryId): View|RedirectResponse
    {
        $library = TitleLibrary::query()->whereKey($libraryId)->firstOrFail();
        $selectedTagIds = $this->tagService->selectedTagIdsFor($library);

        return view('admin.title-libraries.form', [
            'pageTitle' => __('admin.title_libraries.page_title'),
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
            'selectedTagIds' => $selectedTagIds,
            'tagOptions' => $this->tagService->tagOptionsForIds($selectedTagIds),
        ]);
    }

    /**
     * 更新标题库。
     */
    public function update(Request $request, int $libraryId): RedirectResponse
    {
        $library = TitleLibrary::query()->whereKey($libraryId)->firstOrFail();

        $payload = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string'],
            'collection_id' => ['nullable', 'integer', 'min:1', Rule::exists('collections', 'id')],
            'tag_ids' => ['nullable', 'array'],
            'tag_ids.*' => ['integer', Rule::exists('tags', 'id')->where('type', 'material')],
        ], [
            'name.required' => __('admin.title_libraries.error.name_required'),
        ]);

        $library->update([
            'collection_id' => $this->normalizeCollectionId($payload),
            'name' => trim((string) $payload['name']),
            'description' => trim((string) ($payload['description'] ?? '')),
        ]);
        DB::table('entity_material_links')
            ->where('linkable_type', TitleLibrary::class)
            ->where('linkable_id', (int) $library->id)
            ->delete();
        if ($this->requestHasTagSelection($request)) {
            $this->tagService->syncExisting($library, $this->selectedTagIds($request));
        }

        return redirect()->route('admin.title-libraries.index')->with('message', __('admin.title_libraries.message.update_success'));
    }

    /**
     * 删除标题库（存在任务引用时阻止删除）。
     */
    public function destroy(int $libraryId): RedirectResponse
    {
        $library = TitleLibrary::query()->whereKey($libraryId)->firstOrFail();

        $taskCount = Task::query()->where('title_library_id', $libraryId)->count();
        if ($taskCount > 0) {
            return back()->withErrors(__('admin.title_libraries.error.delete_blocked', ['tasks' => $this->buildTaskDeleteBlockHint($libraryId, $taskCount)]));
        }

        Title::query()->where('library_id', $libraryId)->delete();
        $library->delete();

        return redirect()->route('admin.title-libraries.index')->with('message', __('admin.title_libraries.message.delete_success'));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadLibraries(?int $collectionId = null): array
    {
        $query = TitleLibrary::query()
            ->select(['id', 'collection_id', 'name', 'description', 'created_at', 'updated_at'])
            ->with('collection:id,name,status')
            ->withCount([
                'titles as actual_count',
                'titles as ai_count' => fn ($builder) => $builder->where('is_ai_generated', true),
            ])
            ->orderByDesc('created_at');

        if ($collectionId !== null) {
            $query->where('collection_id', $collectionId);
        }

        return $query->get()->map(static function (TitleLibrary $library): array {
            return [
                'id' => (int) $library->id,
                'collection_id' => (int) ($library->collection_id ?? 0),
                'collection_name' => (string) ($library->collection?->name ?? ''),
                'name' => (string) $library->name,
                'description' => (string) ($library->description ?? ''),
                'actual_count' => (int) ($library->actual_count ?? 0),
                'ai_count' => (int) ($library->ai_count ?? 0),
                'created_at' => $library->created_at?->format('Y-m-d H:i:s'),
                'updated_at' => $library->updated_at?->format('Y-m-d H:i:s'),
            ];
        })->all();
    }

    /**
     * @return array{total_libraries:int,total_titles:int,ai_titles:int,avg_titles:float}
     */
    private function loadStats(): array
    {
        $totalLibraries = TitleLibrary::query()->count();
        $totalTitles = Title::query()->count();
        $aiTitles = Title::query()->where('is_ai_generated', true)->count();

        return [
            'total_libraries' => $totalLibraries,
            'total_titles' => $totalTitles,
            'ai_titles' => $aiTitles,
            'avg_titles' => $totalLibraries > 0 ? round($totalTitles / $totalLibraries, 1) : 0.0,
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

    private function requestHasTagSelection(Request $request): bool
    {
        return $request->has('tag_ids') || $request->has('tag_ids_present');
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
     * @return LengthAwarePaginator<int, Title>
     */
    private function loadDetailTitles(int $libraryId, string $search): LengthAwarePaginator
    {
        $query = Title::query()
            ->where('library_id', $libraryId)
            ->orderByDesc('created_at');
        if ($search !== '') {
            $query->where('title', 'like', '%'.$search.'%');
        }

        return $query->paginate(self::DETAIL_PER_PAGE)->withQueryString();
    }

    /**
     * @return Collection<int, array{title:string,keyword:string}>
     */
    private function parseTitleImportText(string $titlesText): Collection
    {
        return collect(preg_split('/\R/u', $titlesText) ?: [])
            ->map(static function (string $line): array {
                $line = trim($line);
                if ($line === '') {
                    return ['title' => '', 'keyword' => ''];
                }

                if (str_contains($line, '|')) {
                    [$title, $keyword] = array_pad(explode('|', $line, 2), 2, '');

                    return [
                        'title' => trim((string) $title),
                        'keyword' => trim((string) $keyword),
                    ];
                }

                return ['title' => $line, 'keyword' => ''];
            })
            ->filter(static fn (array $entry): bool => $entry['title'] !== '')
            ->unique(static fn (array $entry): string => $entry['title'])
            ->values();
    }

    /**
     * 清理 AI 输出中的序号与空白，避免脏数据入库。
     */
    private function normalizeGeneratedTitle(string $title): string
    {
        $cleaned = preg_replace('/^\d+[\.\)\-、\s]*/u', '', trim($title));

        return trim((string) $cleaned);
    }

    /**
     * 维护标题库缓存计数，确保列表统计准确。
     */
    private function refreshTitleLibraryCount(int $libraryId): void
    {
        $count = Title::query()->where('library_id', $libraryId)->count();
        TitleLibrary::query()->whereKey($libraryId)->update([
            'title_count' => $count,
        ]);
    }

    /**
     * @return Collection<int, int>
     */
    private function selectedTitleIds(Request $request): Collection
    {
        /** @var array<int, mixed> $rawIds */
        $rawIds = (array) $request->input('title_ids', []);

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
        return TitleLibrary::query()
            ->with('collection:id,name')
            ->select(['id', 'collection_id', 'name'])
            ->whereKeyNot($currentLibraryId)
            ->orderBy('name')
            ->get()
            ->map(static fn (TitleLibrary $library): array => [
                'id' => (int) $library->id,
                'name' => (string) $library->name,
                'collection_name' => (string) ($library->collection?->name ?? ''),
            ])
            ->all();
    }

    /**
     * 生成与 legacy 页面一致的删除阻断提示。
     */
    private function buildTaskDeleteBlockHint(int $libraryId, int $taskCount): string
    {
        $tasks = Task::query()
            ->where('title_library_id', $libraryId)
            ->select(['id', 'name'])
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit(3)
            ->get();

        $taskPreview = $tasks
            ->map(static fn (Task $task): string => '#'.((int) $task->id).' '.trim((string) ($task->name ?? '')))
            ->filter(static fn (string $name): bool => $name !== '#0')
            ->implode('、');
        if ($taskPreview === '') {
            $taskPreview = __('admin.title_libraries.error.delete_more_tasks', ['count' => $taskCount]);
        }

        if ($taskCount > $tasks->count()) {
            $taskPreview .= __('admin.title_libraries.error.delete_more_tasks', ['count' => $taskCount]);
        }

        return $taskPreview;
    }
}
