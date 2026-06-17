<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\KnowledgeBase;
use App\Models\KnowledgeGovernanceProposal;
use App\Services\GeoFlow\KnowledgeGovernanceProposalService;
use App\Support\AdminWeb;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;
use Throwable;

class KnowledgeGovernanceProposalController extends Controller
{
    public function __construct(private readonly KnowledgeGovernanceProposalService $proposalService) {}

    public function store(Request $request): RedirectResponse
    {
        $payload = $request->validate([
            'proposal_type' => ['required', 'string', Rule::in([
                KnowledgeGovernanceProposal::TYPE_DUPLICATE_ARCHIVE,
                KnowledgeGovernanceProposal::TYPE_CONFLICT_REVIEW,
            ])],
            'issue_payload' => ['required', 'string', 'max:60000'],
            'admin_note' => ['nullable', 'string', 'max:2000'],
        ]);

        $issuePayload = json_decode((string) $payload['issue_payload'], true);
        if (! is_array($issuePayload)) {
            return back()->withErrors(__('admin.knowledge_governance_proposals.error.invalid_payload'));
        }

        try {
            $proposal = $this->proposalService->create(
                (string) $payload['proposal_type'],
                $issuePayload,
                Auth::guard('admin')->user(),
                (string) ($payload['admin_note'] ?? '')
            );
        } catch (Throwable $exception) {
            return back()->withErrors($this->messageFromException($exception));
        }

        return redirect()
            ->route('admin.knowledge-governance-proposals.show', ['proposalId' => (int) $proposal->id])
            ->with('message', __('admin.knowledge_governance_proposals.message.created'));
    }

    public function show(int $proposalId): View
    {
        $proposal = KnowledgeGovernanceProposal::query()
            ->with(['collection:id,name', 'primaryKnowledgeBase:id,name,status,collection_id,source_url,summary,content', 'createdBy:id,username,display_name', 'appliedBy:id,username,display_name', 'rolledBackBy:id,username,display_name'])
            ->whereKey($proposalId)
            ->firstOrFail();

        $knowledgeBaseIds = collect([(int) ($proposal->primary_knowledge_base_id ?? 0)])
            ->merge((array) ($proposal->related_knowledge_base_ids ?? []))
            ->map(static fn (mixed $id): int => (int) $id)
            ->filter()
            ->unique()
            ->values()
            ->all();

        return view('admin.knowledge-bases.governance-proposal', [
            'pageTitle' => __('admin.knowledge_governance_proposals.page_title'),
            'activeMenu' => 'materials',
            'adminSiteName' => AdminWeb::siteName(),
            'proposal' => $proposal,
            'knowledgeBases' => KnowledgeBase::query()
                ->whereIn('id', $knowledgeBaseIds)
                ->with('collection:id,name')
                ->orderByRaw($this->orderByIdsSql($knowledgeBaseIds))
                ->get(),
            'beforeSnapshot' => $this->beforeSnapshot($proposal),
        ]);
    }

    public function reject(Request $request, int $proposalId): RedirectResponse
    {
        $payload = $request->validate([
            'admin_note' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $proposal = $this->proposalService->reject(
                $this->findProposal($proposalId),
                Auth::guard('admin')->user(),
                (string) ($payload['admin_note'] ?? '')
            );
        } catch (Throwable $exception) {
            return back()->withErrors($this->messageFromException($exception));
        }

        return redirect()
            ->route('admin.knowledge-governance-proposals.show', ['proposalId' => (int) $proposal->id])
            ->with('message', __('admin.knowledge_governance_proposals.message.rejected'));
    }

    public function apply(Request $request, int $proposalId): RedirectResponse
    {
        $proposal = $this->findProposal($proposalId);
        $rules = [
            'admin_note' => ['nullable', 'string', 'max:2000'],
        ];
        if ((string) $proposal->proposal_type === KnowledgeGovernanceProposal::TYPE_DUPLICATE_ARCHIVE) {
            $rules['apply_confirmation'] = ['required', 'string', Rule::in([__('admin.knowledge_governance_proposals.apply_confirmation_text')])];
        }
        $payload = $request->validate($rules, [
            'apply_confirmation.required' => __('admin.knowledge_governance_proposals.error.confirmation_required'),
            'apply_confirmation.in' => __('admin.knowledge_governance_proposals.error.confirmation_required'),
        ]);

        try {
            $proposal = $this->proposalService->apply(
                $proposal,
                Auth::guard('admin')->user(),
                (string) ($payload['admin_note'] ?? '')
            );
        } catch (Throwable $exception) {
            return back()->withErrors($this->messageFromException($exception));
        }

        return redirect()
            ->route('admin.knowledge-governance-proposals.show', ['proposalId' => (int) $proposal->id])
            ->with('message', __('admin.knowledge_governance_proposals.message.applied'));
    }

    public function rollback(Request $request, int $proposalId): RedirectResponse
    {
        $payload = $request->validate([
            'admin_note' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $proposal = $this->proposalService->rollback(
                $this->findProposal($proposalId),
                Auth::guard('admin')->user(),
                (string) ($payload['admin_note'] ?? '')
            );
        } catch (Throwable $exception) {
            return back()->withErrors($this->messageFromException($exception));
        }

        return redirect()
            ->route('admin.knowledge-governance-proposals.show', ['proposalId' => (int) $proposal->id])
            ->with('message', __('admin.knowledge_governance_proposals.message.rolled_back'));
    }

    private function findProposal(int $proposalId): KnowledgeGovernanceProposal
    {
        return KnowledgeGovernanceProposal::query()->whereKey($proposalId)->firstOrFail();
    }

    private function messageFromException(Throwable $exception): string
    {
        if ($exception instanceof RuntimeException) {
            return $exception->getMessage();
        }

        report($exception);

        return __('admin.knowledge_governance_proposals.error.operation_failed', ['message' => $exception->getMessage()]);
    }

    /**
     * @param  list<int>  $ids
     */
    private function orderByIdsSql(array $ids): string
    {
        if ($ids === []) {
            return 'id asc';
        }

        return 'CASE id '.implode(' ', array_map(
            static fn (int $id, int $index): string => 'WHEN '.$id.' THEN '.$index,
            $ids,
            array_keys($ids)
        )).' END';
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function beforeSnapshot(KnowledgeGovernanceProposal $proposal): array
    {
        $decoded = json_decode((string) ($proposal->before_content_snapshot ?? ''), true);
        if (! is_array($decoded)) {
            return [];
        }

        $snapshot = [];
        foreach ($decoded as $id => $value) {
            if (is_array($value)) {
                $snapshot[(int) $id] = $value;
            }
        }

        return $snapshot;
    }
}
