<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\AiIntakeDraft;
use App\Services\GeoFlow\AiIntakeDraftService;
use App\Support\AdminWeb;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

class AssistantIntakeDraftController extends Controller
{
    public function __construct(
        private readonly AiIntakeDraftService $drafts
    ) {}

    public function index(Request $request): View
    {
        $status = trim((string) $request->query('status', ''));
        $risk = trim((string) $request->query('risk', ''));
        $source = trim((string) $request->query('source', ''));

        $query = AiIntakeDraft::query()
            ->with(['collection', 'actions'])
            ->withCount([
                'actions',
                'actions as high_risk_actions_count' => fn ($q) => $q->where('risk_level', 'high'),
                'actions as medium_risk_actions_count' => fn ($q) => $q->where('risk_level', 'medium'),
            ])
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if ($status !== '') {
            $query->where('status', $status);
        }
        if ($source !== '') {
            $query->where('source', $source);
        }
        if (in_array($risk, ['low', 'medium', 'high'], true)) {
            $query->whereHas('actions', fn ($q) => $q->where('risk_level', $risk));
        }

        return view('admin.assistant-intake-drafts.index', [
            'pageTitle' => 'AI 录入草稿箱',
            'activeMenu' => 'admin_users',
            'adminSiteName' => AdminWeb::siteName(),
            'drafts' => $query->paginate(20)->withQueryString(),
            'status' => $status,
            'risk' => $risk,
            'source' => $source,
        ]);
    }

    public function show(int $draftId): View
    {
        $draft = AiIntakeDraft::query()
            ->with(['collection', 'actions', 'createdBy', 'reviewedBy'])
            ->whereKey($draftId)
            ->firstOrFail();

        return view('admin.assistant-intake-drafts.show', [
            'pageTitle' => 'AI 录入草稿详情',
            'activeMenu' => 'admin_users',
            'adminSiteName' => AdminWeb::siteName(),
            'draft' => $draft,
            'draftSummary' => $this->drafts->draftSummary($draft),
            'actions' => $this->drafts->actionSummaries($draft),
        ]);
    }

    public function apply(int $draftId): RedirectResponse
    {
        $draft = AiIntakeDraft::query()->with('actions')->whereKey($draftId)->firstOrFail();
        $admin = auth('admin')->user();
        if (! $admin) {
            return redirect()->route('admin.login');
        }

        try {
            $this->drafts->applyDraft($draft, $admin);

            return redirect()
                ->route('admin.assistant-intake-drafts.show', ['draftId' => $draftId])
                ->with('message', 'AI 录入草稿已应用');
        } catch (ApiException $exception) {
            return back()->withErrors($exception->getMessage());
        } catch (Throwable $exception) {
            return back()->withErrors('应用草稿失败：'.$exception->getMessage());
        }
    }

    public function reject(Request $request, int $draftId): RedirectResponse
    {
        $payload = $request->validate([
            'rejected_reason' => ['nullable', 'string', 'max:1000'],
        ]);
        $draft = AiIntakeDraft::query()->whereKey($draftId)->firstOrFail();
        $admin = auth('admin')->user();
        if (! $admin) {
            return redirect()->route('admin.login');
        }

        try {
            $this->drafts->rejectDraft($draft, $admin, (string) ($payload['rejected_reason'] ?? ''));

            return redirect()
                ->route('admin.assistant-intake-drafts.show', ['draftId' => $draftId])
                ->with('message', 'AI 录入草稿已拒绝');
        } catch (ApiException $exception) {
            return back()->withErrors($exception->getMessage());
        } catch (Throwable $exception) {
            return back()->withErrors('拒绝草稿失败：'.$exception->getMessage());
        }
    }
}
