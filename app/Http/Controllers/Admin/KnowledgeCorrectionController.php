<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\KnowledgeChunkVersion;
use App\Models\KnowledgeCorrection;
use App\Services\GeoFlow\KnowledgeCorrectionService;
use App\Support\AdminWeb;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;
use Throwable;

class KnowledgeCorrectionController extends Controller
{
    public function __construct(private readonly KnowledgeCorrectionService $correctionService) {}

    public function index(Request $request): View
    {
        $status = trim((string) $request->query('status', ''));
        $knowledgeBaseId = (int) $request->query('knowledge_base_id', 0);
        $articleId = (int) $request->query('article_id', 0);

        $query = KnowledgeCorrection::query()
            ->with(['knowledgeBase:id,name', 'article:id,title', 'chunk:id,chunk_index', 'reportedBy:id,username,display_name'])
            ->when(in_array($status, KnowledgeCorrection::STATUSES, true), fn ($builder) => $builder->where('status', $status))
            ->when($knowledgeBaseId > 0, fn ($builder) => $builder->where('knowledge_base_id', $knowledgeBaseId))
            ->when($articleId > 0, fn ($builder) => $builder->where('article_id', $articleId))
            ->orderByDesc('id');

        return view('admin.knowledge-corrections.index', [
            'pageTitle' => __('admin.knowledge_corrections.page_title'),
            'activeMenu' => 'materials',
            'adminSiteName' => AdminWeb::siteName(),
            'corrections' => $query->paginate(20)->withQueryString(),
            'filters' => [
                'status' => in_array($status, KnowledgeCorrection::STATUSES, true) ? $status : '',
                'knowledge_base_id' => $knowledgeBaseId,
                'article_id' => $articleId,
            ],
            'statusOptions' => $this->statusOptions(),
        ]);
    }

    public function show(int $correctionId): View
    {
        $correction = KnowledgeCorrection::query()
            ->with([
                'knowledgeBase:id,name',
                'article:id,title',
                'chunk:id,knowledge_base_id,chunk_index,chunk_title,section_path,content',
                'reportedBy:id,username,display_name',
                'reviewedBy:id,username,display_name',
                'aiModel:id,name',
                'versions' => fn ($query) => $query->orderByDesc('version_no'),
            ])
            ->whereKey($correctionId)
            ->firstOrFail();

        return view('admin.knowledge-corrections.show', [
            'pageTitle' => __('admin.knowledge_corrections.page_title'),
            'activeMenu' => 'materials',
            'adminSiteName' => AdminWeb::siteName(),
            'correction' => $correction,
            'statusOptions' => $this->statusOptions(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $payload = $request->validate([
            'source_type' => ['required', 'string', Rule::in(['knowledge_base', 'article'])],
            'error_description' => ['required', 'string', 'max:5000'],
            'selected_article_text' => ['nullable', 'string', 'max:12000'],
            'article_id' => ['nullable', 'integer', 'min:1', Rule::exists('articles', 'id')],
            'knowledge_base_id' => ['nullable', 'integer', 'min:1', Rule::exists('knowledge_bases', 'id')],
            'knowledge_chunk_id' => ['nullable', 'integer', 'min:1', Rule::exists('knowledge_chunks', 'id')],
            'ai_model_id' => ['nullable', 'integer', 'min:0'],
        ]);

        if ($payload['source_type'] === 'knowledge_base' && empty($payload['knowledge_base_id'])) {
            return back()->withInput()->withErrors(__('admin.knowledge_corrections.error.knowledge_base_required'));
        }
        if ($payload['source_type'] === 'article' && empty($payload['article_id'])) {
            return back()->withInput()->withErrors(__('admin.knowledge_corrections.error.article_required'));
        }

        try {
            $payload['reported_by_admin_id'] = (int) (Auth::guard('admin')->id() ?? 0);
            $correction = $this->correctionService->createProposal($payload);
        } catch (Throwable $exception) {
            report($exception);

            return back()->withInput()->withErrors($exception->getMessage());
        }

        return redirect()
            ->route('admin.knowledge-corrections.show', ['correctionId' => (int) $correction->id])
            ->with('message', __('admin.knowledge_corrections.message.created'));
    }

    public function approve(Request $request, int $correctionId): RedirectResponse
    {
        $payload = $request->validate([
            'review_note' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $this->correctionService->approve(
                $this->findCorrection($correctionId),
                (int) (Auth::guard('admin')->id() ?? 0),
                (string) ($payload['review_note'] ?? '')
            );
        } catch (Throwable $exception) {
            return back()->withErrors($this->messageFromException($exception));
        }

        return back()->with('message', __('admin.knowledge_corrections.message.approved'));
    }

    public function reject(Request $request, int $correctionId): RedirectResponse
    {
        $payload = $request->validate([
            'review_note' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $this->correctionService->reject(
                $this->findCorrection($correctionId),
                (int) (Auth::guard('admin')->id() ?? 0),
                (string) ($payload['review_note'] ?? '')
            );
        } catch (Throwable $exception) {
            return back()->withErrors($this->messageFromException($exception));
        }

        return back()->with('message', __('admin.knowledge_corrections.message.rejected'));
    }

    public function apply(Request $request, int $correctionId): RedirectResponse
    {
        $payload = $request->validate([
            'review_note' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $correction = $this->correctionService->apply(
                $this->findCorrection($correctionId),
                (int) (Auth::guard('admin')->id() ?? 0),
                (string) ($payload['review_note'] ?? '')
            );
        } catch (Throwable $exception) {
            return back()->withErrors($this->messageFromException($exception));
        }

        return redirect()
            ->route('admin.knowledge-corrections.show', ['correctionId' => (int) $correction->id])
            ->with('message', __('admin.knowledge_corrections.message.applied'));
    }

    public function rollback(Request $request, int $correctionId, int $versionId): RedirectResponse
    {
        $payload = $request->validate([
            'review_note' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $version = KnowledgeChunkVersion::query()
                ->where('knowledge_correction_id', $correctionId)
                ->whereKey($versionId)
                ->firstOrFail();
            $correction = $this->correctionService->rollback(
                $version,
                (int) (Auth::guard('admin')->id() ?? 0),
                (string) ($payload['review_note'] ?? '')
            );
        } catch (Throwable $exception) {
            return back()->withErrors($this->messageFromException($exception));
        }

        return redirect()
            ->route('admin.knowledge-corrections.show', ['correctionId' => (int) $correction->id])
            ->with('message', __('admin.knowledge_corrections.message.rolled_back'));
    }

    /**
     * @return list<array{value:string,label:string}>
     */
    private function statusOptions(): array
    {
        return collect(KnowledgeCorrection::STATUSES)
            ->map(static fn (string $status): array => [
                'value' => $status,
                'label' => __('admin.knowledge_corrections.status.'.$status),
            ])
            ->all();
    }

    private function findCorrection(int $correctionId): KnowledgeCorrection
    {
        return KnowledgeCorrection::query()->whereKey($correctionId)->firstOrFail();
    }

    private function messageFromException(Throwable $exception): string
    {
        if ($exception instanceof RuntimeException) {
            return $exception->getMessage();
        }

        report($exception);

        return __('admin.knowledge_corrections.error.operation_failed', ['message' => $exception->getMessage()]);
    }
}
