<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ArticleImage;
use App\Models\Image;
use App\Models\ImageLibrary;
use App\Models\Task;
use App\Services\GeoFlow\EntityMaterialLinkService;
use App\Services\GeoFlow\TagService;
use App\Support\AdminWeb;
use App\Support\GeoFlow\CollectionOptions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;
use Illuminate\View\View;

/**
 * 图片库管理控制器。
 */
class ImageLibraryController extends Controller
{
    private const DETAIL_PER_PAGE = 24;

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

        return view('admin.image-libraries.index', [
            'pageTitle' => __('admin.image_libraries.page_title'),
            'activeMenu' => 'materials',
            'adminSiteName' => AdminWeb::siteName(),
            'libraries' => $this->loadLibraries($collectionId),
            'stats' => $this->loadStats(),
            'collectionOptions' => CollectionOptions::all(),
            'collectionId' => $collectionId,
        ]);
    }

    /**
     * 图片库详情页。
     */
    public function detail(Request $request, int $libraryId): View|RedirectResponse
    {
        $library = ImageLibrary::query()->with('collection')->whereKey($libraryId)->firstOrFail();

        $search = trim((string) $request->query('search', ''));
        $tagFilter = trim((string) $request->query('tag', ''));
        $perPage = min(96, max(12, (int) $request->query('per_page', self::DETAIL_PER_PAGE) ?: self::DETAIL_PER_PAGE));
        $images = $this->loadDetailImages($libraryId, $search, $tagFilter, $perPage);
        $selectedTagIdsByImage = [];
        $selectedEntityIdsByImage = [];
        foreach ($images as $image) {
            $selectedTagIdsByImage[(int) $image->id] = collect($image->getRelation('tags'))->pluck('id')->map(static fn ($id): int => (int) $id)->all();
            $selectedEntityIdsByImage[(int) $image->id] = $this->entityMaterialLinkService->selectedEntityIdsFor($image);
        }
        $selectedTagIds = collect($selectedTagIdsByImage)->flatten()->map(static fn ($id): int => (int) $id)->unique()->values()->all();
        $usageTotal = (int) ArticleImage::query()
            ->whereHas('image', function ($query) use ($libraryId): void {
                $query->where('library_id', $libraryId);
            })
            ->count();

        return view('admin.image-libraries.detail', [
            'pageTitle' => (string) $library->name.' - '.__('admin.image_detail.page_title'),
            'activeMenu' => 'materials',
            'adminSiteName' => AdminWeb::siteName(),
            'library' => $library,
            'search' => $search,
            'tagFilter' => $tagFilter,
            'images' => $images,
            'usageTotal' => $usageTotal,
            'totalImages' => Image::query()->where('library_id', $libraryId)->count(),
            'collectionOptions' => CollectionOptions::all(),
            'targetLibraryOptions' => $this->targetLibraryOptions($libraryId),
            'entityOptions' => $this->entityMaterialLinkService->entityOptions((int) ($library->collection_id ?? 0) ?: null),
            'selectedEntityIdsByImage' => $selectedEntityIdsByImage,
            'tagOptions' => $this->tagService->tagOptionsForIds($selectedTagIds),
        ]);
    }

    /**
     * 详情页更新图片库基本信息。
     */
    public function updateFromDetail(Request $request, int $libraryId): RedirectResponse
    {
        $library = ImageLibrary::query()->whereKey($libraryId)->firstOrFail();

        $payload = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string'],
            'collection_id' => ['nullable', 'integer', 'min:1', Rule::exists('collections', 'id')],
        ], [
            'name.required' => __('admin.image_libraries.error.name_required'),
        ]);

        $library->update([
            'collection_id' => $this->normalizeCollectionId($payload),
            'name' => trim((string) $payload['name']),
            'description' => trim((string) ($payload['description'] ?? '')),
        ]);

        return redirect()->route('admin.image-libraries.detail', ['libraryId' => $libraryId])->with('message', __('admin.image_detail.message.update_success'));
    }

    /**
     * 上传多张图片到指定图片库。
     */
    public function uploadImages(Request $request, int $libraryId): RedirectResponse
    {
        $library = ImageLibrary::query()->whereKey($libraryId)->firstOrFail();

        $request->validate([
            'images' => ['required', 'array', 'min:1'],
            'images.*' => [
                'required',
                File::types(['jpg', 'jpeg', 'png', 'gif', 'webp'])->max(10 * 1024),
            ],
            'tag_ids' => ['nullable', 'array'],
            'tag_ids.*' => ['integer', Rule::exists('tags', 'id')->where('type', 'material')],
            'entity_ids' => ['nullable', 'array'],
            'entity_ids.*' => ['integer', Rule::exists('entities', 'id')],
        ], [
            'images.required' => __('admin.image_detail.error.select_images'),
        ]);

        /** @var array<int, UploadedFile> $uploadedFiles */
        $uploadedFiles = $request->file('images', []);
        if ($uploadedFiles === []) {
            return back()->withErrors(__('admin.image_detail.error.select_images'));
        }

        $uploadedCount = 0;
        $skippedCount = 0;
        $uploadErrors = [];
        $tagIds = $this->selectedTagIds($request);
        $entityIds = $this->selectedEntityIds($request);
        $tagsText = $this->tagService->tagTextForIds($tagIds);
        DB::transaction(function () use ($uploadedFiles, $libraryId, $tagIds, $tagsText, $entityIds, &$uploadedCount, &$skippedCount, &$uploadErrors): void {
            foreach ($uploadedFiles as $uploadedFile) {
                try {
                    $stored = $this->storeUploadedImageFile($uploadedFile);
                    $image = Image::query()->create([
                        'library_id' => $libraryId,
                        'filename' => $stored['filename'],
                        'original_name' => $stored['original_name'],
                        'file_name' => $stored['file_name'],
                        'file_path' => $stored['file_path'],
                        'file_size' => $stored['file_size'],
                        'mime_type' => $stored['mime_type'],
                        'width' => $stored['width'],
                        'height' => $stored['height'],
                        'tags' => $tagsText,
                        'used_count' => 0,
                        'usage_count' => 0,
                    ]);
                    $this->tagService->syncExisting($image, $tagIds);
                    $this->entityMaterialLinkService->syncEntities($image, $entityIds);
                    $uploadedCount++;
                } catch (\Throwable $exception) {
                    $skippedCount++;
                    $uploadErrors[] = $exception->getMessage();
                    Log::warning('geoflow.image_upload_failed', [
                        'library_id' => $libraryId,
                        'original_name' => $uploadedFile->getClientOriginalName(),
                        'error' => $exception->getMessage(),
                    ]);
                }
            }

            $this->refreshImageLibraryCount($libraryId);
        });

        if ($uploadedCount <= 0) {
            $firstError = trim((string) ($uploadErrors[0] ?? ''));

            return back()->withErrors($firstError !== ''
                ? __('admin.image_detail.error.upload_failed_detail', ['message' => $firstError])
                : __('admin.image_detail.error.upload_none'));
        }

        $message = __('admin.image_detail.message.upload_success', ['count' => $uploadedCount]);
        if ($skippedCount > 0) {
            $message .= __('admin.image_detail.message.upload_skipped', ['count' => $skippedCount]);
        }

        return redirect()->route('admin.image-libraries.detail', ['libraryId' => $libraryId])->with('message', $message);
    }

    public function updateImageTags(Request $request, int $libraryId, int $imageId): RedirectResponse
    {
        $library = ImageLibrary::query()->whereKey($libraryId)->firstOrFail();
        $image = Image::query()
            ->where('library_id', (int) $library->id)
            ->whereKey($imageId)
            ->firstOrFail();

        $request->validate([
            'tag_ids' => ['nullable', 'array'],
            'tag_ids.*' => ['integer', Rule::exists('tags', 'id')->where('type', 'material')],
        ]);
        $tagIds = $this->selectedTagIds($request);
        $tagsText = $this->tagService->tagTextForIds($tagIds);

        $image->update(['tags' => $tagsText]);
        $this->tagService->syncExisting($image, $tagIds);

        return redirect()
            ->route('admin.image-libraries.detail', [
                'libraryId' => $libraryId,
                'search' => trim((string) $request->input('search', '')),
                'tag' => trim((string) $request->input('tag', '')),
            ])
            ->with('message', '图片标签已更新');
    }

    public function updateImageEntities(Request $request, int $libraryId, int $imageId): RedirectResponse
    {
        $library = ImageLibrary::query()->whereKey($libraryId)->firstOrFail();
        $image = Image::query()
            ->where('library_id', (int) $library->id)
            ->whereKey($imageId)
            ->firstOrFail();

        $request->validate([
            'entity_ids' => ['nullable', 'array'],
            'entity_ids.*' => ['integer', Rule::exists('entities', 'id')],
        ]);

        $this->entityMaterialLinkService->syncEntities($image, $this->selectedEntityIds($request));

        return redirect()
            ->route('admin.image-libraries.detail', [
                'libraryId' => $libraryId,
                'search' => trim((string) $request->input('search', '')),
                'tag' => trim((string) $request->input('tag', '')),
            ])
            ->with('message', __('admin.entities.message.image_links_updated'));
    }

    public function updateImageTitle(Request $request, int $libraryId, int $imageId): RedirectResponse
    {
        $library = ImageLibrary::query()->whereKey($libraryId)->firstOrFail();
        $image = Image::query()
            ->where('library_id', (int) $library->id)
            ->whereKey($imageId)
            ->firstOrFail();

        $payload = $request->validate([
            'title' => ['required', 'string', 'max:255'],
        ]);

        $image->update([
            'original_name' => trim((string) $payload['title']),
        ]);

        return redirect()
            ->route('admin.image-libraries.detail', [
                'libraryId' => $libraryId,
                'search' => trim((string) $request->input('search', '')),
                'tag' => trim((string) $request->input('tag', '')),
            ])
            ->with('message', '图片标题已更新');
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
     * 删除图片（支持单条/批量）。
     */
    public function destroyImages(Request $request, int $libraryId): RedirectResponse
    {
        $library = ImageLibrary::query()->whereKey($libraryId)->firstOrFail();

        $imageIds = $this->selectedImageIds($request);
        if ($imageIds->isEmpty()) {
            return back()->withErrors(__('admin.image_detail.error.select_delete'));
        }

        $filePaths = Image::query()
            ->where('library_id', $libraryId)
            ->whereIn('id', $imageIds->all())
            ->pluck('file_path')
            ->filter()
            ->values()
            ->all();

        ArticleImage::query()
            ->whereIn('image_id', $imageIds->all())
            ->delete();

        $this->tagService->detachTaggables(Image::class, $imageIds->all());
        $deletedCount = Image::query()
            ->where('library_id', $libraryId)
            ->whereIn('id', $imageIds->all())
            ->delete();
        $this->refreshImageLibraryCount($libraryId);
        $cleanupFailed = $this->cleanupFiles($filePaths);

        $message = __('admin.image_detail.message.delete_success', ['count' => $deletedCount]);
        if ($cleanupFailed > 0) {
            $message .= __('admin.image_detail.message.delete_cleanup_partial', ['count' => $cleanupFailed]);
        }

        return redirect()->route('admin.image-libraries.detail', ['libraryId' => $libraryId])->with('message', $message);
    }

    /**
     * 批量移动/复制图片到其他图片库。
     */
    public function organizeImages(Request $request, int $libraryId): RedirectResponse
    {
        $library = ImageLibrary::query()->whereKey($libraryId)->firstOrFail();
        $imageIds = $this->selectedImageIds($request);
        if ($imageIds->isEmpty()) {
            return back()->withErrors(__('admin.image_detail.error.select_delete'));
        }

        $payload = $request->validate([
            'bulk_action' => ['required', Rule::in(['move', 'copy'])],
            'target_library_id' => ['required', 'integer', Rule::exists('image_libraries', 'id')],
        ]);

        $targetLibraryId = (int) $payload['target_library_id'];
        if ($targetLibraryId === (int) $library->id) {
            return back()->withErrors(__('admin.image_detail.error.target_library_required'));
        }

        $sourceImages = Image::query()
            ->with('tags:id')
            ->where('library_id', (int) $library->id)
            ->whereIn('id', $imageIds->all())
            ->get();
        if ($sourceImages->isEmpty()) {
            return back()->withErrors(__('admin.image_detail.error.select_delete'));
        }

        $processedCount = 0;
        DB::transaction(function () use ($sourceImages, $targetLibraryId, $payload, &$processedCount): void {
            if ((string) $payload['bulk_action'] === 'move') {
                Image::query()
                    ->whereIn('id', $sourceImages->pluck('id')->map(static fn ($id): int => (int) $id)->all())
                    ->update(['library_id' => $targetLibraryId]);
                $processedCount = $sourceImages->count();

                return;
            }

            foreach ($sourceImages as $sourceImage) {
                $newImage = Image::query()->create([
                    'library_id' => $targetLibraryId,
                    'filename' => (string) $sourceImage->filename,
                    'original_name' => (string) $sourceImage->original_name,
                    'file_name' => (string) $sourceImage->file_name,
                    'file_path' => (string) $sourceImage->file_path,
                    'file_size' => (int) ($sourceImage->file_size ?? 0),
                    'mime_type' => (string) $sourceImage->mime_type,
                    'width' => (int) ($sourceImage->width ?? 0),
                    'height' => (int) ($sourceImage->height ?? 0),
                    'tags' => (string) ($sourceImage->getAttribute('tags') ?? ''),
                    'used_count' => 0,
                    'usage_count' => 0,
                ]);

                $tagIds = collect($sourceImage->getRelation('tags'))->pluck('id')->map(static fn ($id): int => (int) $id)->all();
                if ($tagIds !== []) {
                    $newImage->tags()->syncWithoutDetaching($tagIds);
                }
                $this->entityMaterialLinkService->syncEntities($newImage, $this->entityMaterialLinkService->selectedEntityIdsFor($sourceImage));
                $processedCount++;
            }
        });

        $this->refreshImageLibraryCount((int) $library->id);
        $this->refreshImageLibraryCount($targetLibraryId);

        $messageKey = (string) $payload['bulk_action'] === 'move'
            ? 'admin.image_detail.message.move_success'
            : 'admin.image_detail.message.copy_success';

        return redirect()->route('admin.image-libraries.detail', ['libraryId' => (int) $library->id])->with('message', __($messageKey, ['count' => $processedCount]));
    }

    /**
     * 创建表单页。
     */
    public function create(): View
    {
        return view('admin.image-libraries.form', [
            'pageTitle' => __('admin.image_libraries.page_title'),
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
     * 创建图片库。
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
            'name.required' => __('admin.image_libraries.error.name_required'),
        ]);

        $library = ImageLibrary::query()->create([
            'collection_id' => $this->normalizeCollectionId($payload),
            'name' => trim((string) $payload['name']),
            'description' => trim((string) ($payload['description'] ?? '')),
            'image_count' => 0,
            'used_task_count' => 0,
        ]);
        $this->entityMaterialLinkService->syncEntities($library, $this->selectedEntityIds($request));

        return redirect()->route('admin.image-libraries.index')->with('message', __('admin.image_libraries.message.create_success'));
    }

    /**
     * 编辑表单页。
     */
    public function edit(int $libraryId): View|RedirectResponse
    {
        $library = ImageLibrary::query()->whereKey($libraryId)->firstOrFail();

        return view('admin.image-libraries.form', [
            'pageTitle' => __('admin.image_libraries.page_title'),
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
     * 更新图片库。
     */
    public function update(Request $request, int $libraryId): RedirectResponse
    {
        $library = ImageLibrary::query()->whereKey($libraryId)->firstOrFail();

        $payload = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string'],
            'collection_id' => ['nullable', 'integer', 'min:1', Rule::exists('collections', 'id')],
            'entity_ids' => ['nullable', 'array'],
            'entity_ids.*' => ['integer', Rule::exists('entities', 'id')],
        ], [
            'name.required' => __('admin.image_libraries.error.name_required'),
        ]);

        $library->update([
            'collection_id' => $this->normalizeCollectionId($payload),
            'name' => trim((string) $payload['name']),
            'description' => trim((string) ($payload['description'] ?? '')),
        ]);
        $this->entityMaterialLinkService->syncEntities($library, $this->selectedEntityIds($request));

        return redirect()
            ->route('admin.image-libraries.edit', ['libraryId' => (int) $library->id])
            ->with('message', __('admin.image_libraries.message.update_success'));
    }

    /**
     * 删除图片库，并尝试删除关联文件。
     */
    public function destroy(Request $request, int $libraryId): RedirectResponse
    {
        $library = ImageLibrary::query()->whereKey($libraryId)->firstOrFail();

        $taskCount = Task::query()->where('image_library_id', $libraryId)->count();
        if ($taskCount > 0) {
            return back()->withErrors(__('admin.image_libraries.error.in_use', ['count' => $taskCount]));
        }

        $imageIds = Image::query()
            ->where('library_id', $libraryId)
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
        $filePaths = Image::query()->where('library_id', $libraryId)->pluck('file_path')->filter()->values()->all();
        $this->tagService->detachTaggables(Image::class, $imageIds);
        Image::query()->where('library_id', $libraryId)->delete();

        $library->delete();
        $cleanupFailed = $this->cleanupFiles($filePaths);

        $message = __('admin.image_libraries.message.delete_success');
        if ($cleanupFailed > 0) {
            $message .= __('admin.image_libraries.message.delete_cleanup_partial', ['count' => $cleanupFailed]);
        }

        return redirect()
            ->route('admin.image-libraries.index', $request->query())
            ->withFragment('material-list')
            ->with('message', $message);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadLibraries(?int $collectionId = null): array
    {
        $query = ImageLibrary::query()
            ->select(['id', 'collection_id', 'name', 'description', 'created_at', 'updated_at'])
            ->with('collection:id,name,status')
            ->withCount('images as actual_count')
            ->withSum('images as total_size', 'file_size')
            ->orderByDesc('created_at');

        if ($collectionId !== null) {
            $query->where('collection_id', $collectionId);
        }

        return $query->get()->map(static function (ImageLibrary $library): array {
            return [
                'id' => (int) $library->id,
                'collection_id' => (int) ($library->collection_id ?? 0),
                'collection_name' => (string) ($library->collection?->name ?? ''),
                'name' => (string) $library->name,
                'description' => (string) ($library->description ?? ''),
                'actual_count' => (int) ($library->actual_count ?? 0),
                'total_size' => (int) ($library->total_size ?? 0),
                'created_at' => $library->created_at?->format('Y-m-d H:i:s'),
                'updated_at' => $library->updated_at?->format('Y-m-d H:i:s'),
            ];
        })->all();
    }

    /**
     * @return array{total_libraries:int,total_images:int,total_size:int,avg_images:float}
     */
    private function loadStats(): array
    {
        $totalLibraries = ImageLibrary::query()->count();
        $totalImages = Image::query()->count();
        $totalSize = (int) (Image::query()->sum('file_size') ?? 0);

        return [
            'total_libraries' => $totalLibraries,
            'total_images' => $totalImages,
            'total_size' => $totalSize,
            'avg_images' => $totalLibraries > 0 ? round($totalImages / $totalLibraries, 1) : 0.0,
        ];
    }

    /**
     * 删除磁盘文件（仅清理本地相对路径）。
     *
     * @param  list<string>  $paths
     */
    private function cleanupFiles(array $paths): int
    {
        $failed = 0;
        foreach ($paths as $path) {
            $path = trim($path);
            if ($path === '') {
                continue;
            }

            /**
             * 新上传文件统一落在 public 磁盘（storage/app/public）；
             * 这里优先按 Laravel Storage 删除，兼容旧路径再做兜底 unlink。
             */
            if (str_starts_with($path, 'storage/')) {
                $relativePublicPath = ltrim(substr($path, strlen('storage/')), '/');
                if ($relativePublicPath === '') {
                    continue;
                }
                if (! Storage::disk('public')->delete($relativePublicPath) && Storage::disk('public')->exists($relativePublicPath)) {
                    $failed++;
                }

                continue;
            }

            if (str_starts_with($path, 'uploads/')) {
                $legacyPublicAbsolutePath = public_path($path);
                if (is_file($legacyPublicAbsolutePath) && ! @unlink($legacyPublicAbsolutePath)) {
                    $failed++;
                }

                continue;
            }

            $legacyAbsolutePath = base_path($path);
            if (is_file($legacyAbsolutePath) && ! @unlink($legacyAbsolutePath)) {
                $failed++;
            }
        }

        return $failed;
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

        if ($collectionId <= 0) {
            $collectionId = (int) \App\Support\AdminWeb::defaultCollectionId();
        }

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
     * @return LengthAwarePaginator<int, Image>
     */
    private function loadDetailImages(int $libraryId, string $search, string $tagFilter, int $perPage): LengthAwarePaginator
    {
        $query = Image::query()
            ->with(['tags' => fn ($query) => $query->orderBy('group_name')->orderBy('name')])
            ->where('library_id', $libraryId)
            ->orderByDesc('created_at');
        if ($search !== '') {
            $query->where(function ($builder) use ($search): void {
                $builder->where('original_name', 'like', '%'.$search.'%')
                    ->orWhere('filename', 'like', '%'.$search.'%')
                    ->orWhere('file_name', 'like', '%'.$search.'%')
                    ->orWhere('tags', 'like', '%'.$search.'%');
            });
        }
        $this->tagService->applyFilter($query, $tagFilter);

        return $query->paginate($perPage)->withQueryString();
    }

    /**
     * 维护图片库图片计数字段，保持首页统计一致。
     */
    private function refreshImageLibraryCount(int $libraryId): void
    {
        $count = Image::query()->where('library_id', $libraryId)->count();
        ImageLibrary::query()->whereKey($libraryId)->update([
            'image_count' => $count,
        ]);
    }

    /**
     * @return Collection<int, int>
     */
    private function selectedImageIds(Request $request): Collection
    {
        /** @var array<int, mixed> $rawIds */
        $rawIds = (array) $request->input('image_ids', []);

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
        return ImageLibrary::query()
            ->with('collection:id,name')
            ->select(['id', 'collection_id', 'name'])
            ->whereKeyNot($currentLibraryId)
            ->orderBy('name')
            ->get()
            ->map(static fn (ImageLibrary $library): array => [
                'id' => (int) $library->id,
                'name' => (string) $library->name,
                'collection_name' => (string) ($library->collection?->name ?? ''),
            ])
            ->all();
    }

    /**
     * @return array{filename:string,file_name:string,original_name:string,file_path:string,file_size:int,mime_type:string,width:int,height:int}
     */
    private function storeUploadedImageFile(UploadedFile $file): array
    {
        $uploadDirectory = 'images/'.date('Y/m');
        $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'jpg');
        $filename = bin2hex(random_bytes(16)).'.'.$extension;
        $directory = 'uploads/'.$uploadDirectory;
        if (! Storage::disk('public')->exists($directory) && ! Storage::disk('public')->makeDirectory($directory)) {
            throw new \RuntimeException('创建图片上传目录失败：storage/app/public/'.$directory);
        }

        $storedRelativePath = Storage::disk('public')->putFileAs($directory, $file, $filename);
        if (! is_string($storedRelativePath) || $storedRelativePath === '') {
            throw new \RuntimeException('保存图片失败');
        }

        if (! Storage::disk('public')->exists($storedRelativePath)) {
            throw new \RuntimeException('图片文件写入后未找到：storage/app/public/'.$storedRelativePath);
        }

        $targetPath = Storage::disk('public')->path($storedRelativePath);
        if (! is_file($targetPath)) {
            throw new \RuntimeException('图片文件路径不可访问：'.$targetPath);
        }

        $fileSize = filesize($targetPath);
        if ($fileSize === false) {
            throw new \RuntimeException('无法读取图片文件大小：'.$targetPath);
        }

        $imageInfo = @getimagesize($targetPath) ?: [0, 0, null, null, 'mime' => (string) $file->getMimeType()];

        return [
            'filename' => $filename,
            'file_name' => $filename,
            'original_name' => (string) $file->getClientOriginalName(),
            'file_path' => 'storage/'.$storedRelativePath,
            'file_size' => (int) $fileSize,
            'mime_type' => (string) ($imageInfo['mime'] ?? $file->getMimeType() ?? ''),
            'width' => (int) ($imageInfo[0] ?? 0),
            'height' => (int) ($imageInfo[1] ?? 0),
        ];
    }
}
