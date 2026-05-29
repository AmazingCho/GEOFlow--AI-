<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CaseRecord;
use App\Models\EntityRecord;
use App\Services\GeoFlow\TagService;
use App\Support\AdminWeb;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CaseController extends Controller
{
    private const PER_PAGE = 20;

    public function __construct(private readonly TagService $tagService) {}

    public function index(Request $request): View
    {
        $search = trim((string) $request->query('search', ''));
        $tag = trim((string) $request->query('tag', ''));

        $query = CaseRecord::query()
            ->with(['entity', 'tags'])
            ->orderByDesc('updated_at')
            ->orderByDesc('id');

        if ($search !== '') {
            $query->where(function ($builder) use ($search): void {
                $builder
                    ->where('title', 'like', '%'.$search.'%')
                    ->orWhere('case_type', 'like', '%'.$search.'%')
                    ->orWhere('summary', 'like', '%'.$search.'%')
                    ->orWhere('challenge', 'like', '%'.$search.'%')
                    ->orWhere('solution', 'like', '%'.$search.'%')
                    ->orWhere('result', 'like', '%'.$search.'%')
                    ->orWhereHas('entity', static fn ($entityQuery) => $entityQuery->where('name', 'like', '%'.$search.'%'));
            });
        }

        $this->tagService->applyFilter($query, $tag);

        return view('admin.cases.index', [
            'pageTitle' => __('admin.cases.page_title'),
            'activeMenu' => 'materials',
            'adminSiteName' => AdminWeb::siteName(),
            'search' => $search,
            'tagFilter' => $tag,
            'cases' => $query->paginate(self::PER_PAGE)->withQueryString(),
            'stats' => [
                'total' => CaseRecord::query()->count(),
                'tagged' => CaseRecord::query()->whereHas('tags')->count(),
            ],
        ]);
    }

    public function create(): View
    {
        return view('admin.cases.form', [
            'pageTitle' => __('admin.cases.page_title'),
            'activeMenu' => 'materials',
            'adminSiteName' => AdminWeb::siteName(),
            'isEdit' => false,
            'caseId' => 0,
            'caseForm' => $this->emptyCaseForm(),
            'entityOptions' => $this->entityOptions(),
            'tagOptions' => $this->tagService->existingTagOptions(),
            'selectedTagIds' => [],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $payload = $this->validateCase($request);

        $caseRecord = CaseRecord::query()->create($this->normalizeCasePayload($payload));
        $this->tagService->syncExisting($caseRecord, $this->selectedTagIds($payload));

        return redirect()
            ->route('admin.cases.index')
            ->with('message', __('admin.cases.message.create_success'));
    }

    public function edit(int $caseId): View
    {
        $caseRecord = CaseRecord::query()->with('tags')->whereKey($caseId)->firstOrFail();

        return view('admin.cases.form', [
            'pageTitle' => __('admin.cases.page_title'),
            'activeMenu' => 'materials',
            'adminSiteName' => AdminWeb::siteName(),
            'isEdit' => true,
            'caseId' => (int) $caseRecord->id,
            'caseForm' => [
                'entity_id' => (string) ((int) ($caseRecord->entity_id ?? 0) ?: ''),
                'title' => (string) $caseRecord->title,
                'case_type' => (string) ($caseRecord->case_type ?? ''),
                'summary' => (string) ($caseRecord->summary ?? ''),
                'challenge' => (string) ($caseRecord->challenge ?? ''),
                'solution' => (string) ($caseRecord->solution ?? ''),
                'result' => (string) ($caseRecord->result ?? ''),
                'metrics' => (string) ($caseRecord->metrics ?? ''),
                'source_url' => (string) ($caseRecord->source_url ?? ''),
            ],
            'entityOptions' => $this->entityOptions(),
            'tagOptions' => $this->tagService->existingTagOptions(),
            'selectedTagIds' => $this->tagService->selectedTagIdsFor($caseRecord),
        ]);
    }

    public function update(Request $request, int $caseId): RedirectResponse
    {
        $caseRecord = CaseRecord::query()->whereKey($caseId)->firstOrFail();
        $payload = $this->validateCase($request);

        $caseRecord->update($this->normalizeCasePayload($payload));
        $this->tagService->syncExisting($caseRecord, $this->selectedTagIds($payload));

        return redirect()
            ->route('admin.cases.index')
            ->with('message', __('admin.cases.message.update_success'));
    }

    public function destroy(int $caseId): RedirectResponse
    {
        CaseRecord::query()->whereKey($caseId)->firstOrFail()->delete();

        return redirect()
            ->route('admin.cases.index')
            ->with('message', __('admin.cases.message.delete_success'));
    }

    /**
     * @return array<string, mixed>
     */
    private function validateCase(Request $request): array
    {
        return $request->validate([
            'entity_id' => ['nullable', 'integer', 'min:1', Rule::exists('entities', 'id')],
            'title' => ['required', 'string', 'max:200'],
            'case_type' => ['nullable', 'string', 'max:100'],
            'summary' => ['nullable', 'string', 'max:10000'],
            'challenge' => ['nullable', 'string', 'max:10000'],
            'solution' => ['nullable', 'string', 'max:10000'],
            'result' => ['nullable', 'string', 'max:10000'],
            'metrics' => ['nullable', 'string', 'max:5000'],
            'source_url' => ['nullable', 'string', 'max:500'],
            'tag_ids' => ['nullable', 'array'],
            'tag_ids.*' => [
                'integer',
                Rule::exists('tags', 'id')->where(static fn ($query) => $query->where('type', 'material')),
            ],
        ], [
            'title.required' => __('admin.cases.error.title_required'),
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function normalizeCasePayload(array $payload): array
    {
        return [
            'entity_id' => isset($payload['entity_id']) && (int) $payload['entity_id'] > 0 ? (int) $payload['entity_id'] : null,
            'title' => trim((string) $payload['title']),
            'case_type' => trim((string) ($payload['case_type'] ?? '')),
            'summary' => trim((string) ($payload['summary'] ?? '')),
            'challenge' => trim((string) ($payload['challenge'] ?? '')),
            'solution' => trim((string) ($payload['solution'] ?? '')),
            'result' => trim((string) ($payload['result'] ?? '')),
            'metrics' => trim((string) ($payload['metrics'] ?? '')),
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
     * @return list<array{id:int,name:string}>
     */
    private function entityOptions(): array
    {
        return EntityRecord::query()
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(static fn (EntityRecord $entity): array => [
                'id' => (int) $entity->id,
                'name' => (string) $entity->name,
            ])
            ->all();
    }

    /**
     * @return array{entity_id:string,title:string,case_type:string,summary:string,challenge:string,solution:string,result:string,metrics:string,source_url:string}
     */
    private function emptyCaseForm(): array
    {
        return [
            'entity_id' => '',
            'title' => '',
            'case_type' => '',
            'summary' => '',
            'challenge' => '',
            'solution' => '',
            'result' => '',
            'metrics' => '',
            'source_url' => '',
        ];
    }
}
