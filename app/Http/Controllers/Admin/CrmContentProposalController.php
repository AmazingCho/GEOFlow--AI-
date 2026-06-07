<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CaseRecord;
use App\Models\CrmAfterSalesTicket;
use App\Models\CrmContentProposal;
use App\Models\CrmInquiry;
use App\Models\KnowledgeBase;
use App\Models\Title;
use App\Models\TitleLibrary;
use App\Support\AdminWeb;
use App\Support\GeoFlow\CollectionOptions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CrmContentProposalController extends Controller
{
    public function index(Request $request): View
    {
        $type = trim((string) $request->query('proposal_type', ''));
        $status = trim((string) $request->query('status', ''));
        $collectionId = (int) $request->query('collection_id', 0);

        $query = CrmContentProposal::query()
            ->with('collection')
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if ($type !== '') {
            $query->where('proposal_type', $type);
        }
        if ($status !== '') {
            $query->where('status', $status);
        }
        if ($collectionId > 0) {
            $query->where('collection_id', $collectionId);
        }

        return view('admin.crm.proposals.index', [
            'pageTitle' => '内容候选草稿',
            'activeMenu' => 'crm',
            'adminSiteName' => AdminWeb::siteName(),
            'proposals' => $query->paginate(20)->withQueryString(),
            'proposalType' => $type,
            'status' => $status,
            'collectionId' => $collectionId > 0 ? $collectionId : null,
            'collectionOptions' => CollectionOptions::all(),
            'titleLibraries' => TitleLibrary::query()->orderBy('name')->get(['id', 'name', 'collection_id']),
        ]);
    }

    public function createFromInquiry(Request $request, int $inquiryId): RedirectResponse
    {
        $payload = $request->validate([
            'proposal_type' => ['required', Rule::in(['title_suggestion', 'faq_draft'])],
        ]);
        $inquiry = CrmInquiry::query()->with(['customer', 'entities'])->whereKey($inquiryId)->firstOrFail();
        $proposal = $this->proposalFromInquiry($inquiry, (string) $payload['proposal_type']);

        return redirect()
            ->route('admin.crm.proposals.index', ['proposal_type' => $proposal->proposal_type])
            ->with('message', '内容候选草稿已生成，等待人工确认');
    }

    public function createFromTicket(Request $request, int $ticketId): RedirectResponse
    {
        $payload = $request->validate([
            'proposal_type' => ['required', Rule::in(['faq_draft', 'case_draft'])],
        ]);
        $ticket = CrmAfterSalesTicket::query()->with(['customer', 'entity'])->whereKey($ticketId)->firstOrFail();
        $proposal = $this->proposalFromTicket($ticket, (string) $payload['proposal_type']);

        return redirect()
            ->route('admin.crm.proposals.index', ['proposal_type' => $proposal->proposal_type])
            ->with('message', '内容候选草稿已生成，等待人工确认');
    }

    public function apply(Request $request, int $proposalId): RedirectResponse
    {
        $proposal = CrmContentProposal::query()->whereKey($proposalId)->firstOrFail();
        if ((string) $proposal->status === 'applied') {
            return back()->with('message', '该候选草稿已应用');
        }

        if ((string) $proposal->proposal_type === 'title_suggestion') {
            $payload = $request->validate([
                'title_library_id' => ['required', 'integer', 'min:1', Rule::exists('title_libraries', 'id')],
            ]);
            $target = Title::query()->create([
                'library_id' => (int) $payload['title_library_id'],
                'title' => (string) $proposal->title,
                'keyword' => '',
                'is_ai_generated' => true,
                'used_count' => 0,
                'usage_count' => 0,
            ]);
            TitleLibrary::query()->whereKey((int) $payload['title_library_id'])->increment('title_count');
            $this->markApplied($proposal, Title::class, (int) $target->id);

            return back()->with('message', '标题候选已写入标题库');
        }

        if ((string) $proposal->proposal_type === 'faq_draft') {
            $target = KnowledgeBase::query()->create([
                'collection_id' => (int) ($proposal->collection_id ?? 0) ?: null,
                'name' => (string) $proposal->title,
                'description' => 'CRM 内容候选确认入库',
                'summary' => (string) $proposal->title,
                'content' => (string) $proposal->content,
                'character_count' => mb_strlen((string) $proposal->content, 'UTF-8'),
                'file_type' => 'markdown',
                'knowledge_type' => 'faq',
                'knowledge_role' => 'supporting_context',
                'importance' => 3,
                'status' => 'active',
                'file_path' => '',
                'word_count' => str_word_count(strip_tags((string) $proposal->content)),
                'usage_count' => 0,
            ]);
            $this->markApplied($proposal, KnowledgeBase::class, (int) $target->id);

            return back()->with('message', 'FAQ 候选已写入知识库');
        }

        if ((string) $proposal->proposal_type === 'case_draft') {
            $metadata = json_decode((string) ($proposal->metadata_json ?? ''), true);
            $target = CaseRecord::query()->create([
                'collection_id' => (int) ($proposal->collection_id ?? 0) ?: null,
                'entity_id' => (int) ($metadata['entity_id'] ?? 0) ?: null,
                'title' => (string) $proposal->title,
                'case_type' => 'troubleshooting_case',
                'summary' => (string) $proposal->content,
                'challenge' => (string) ($metadata['challenge'] ?? ''),
                'solution' => (string) ($metadata['solution'] ?? ''),
                'result' => (string) ($metadata['result'] ?? ''),
                'metrics' => '',
                'source_url' => '',
                'usage_count' => 0,
            ]);
            $this->markApplied($proposal, CaseRecord::class, (int) $target->id);

            return back()->with('message', 'Case 候选已写入 Case DB');
        }

        return back()->withErrors('未知候选类型');
    }

    public function reject(int $proposalId): RedirectResponse
    {
        CrmContentProposal::query()->whereKey($proposalId)->firstOrFail()->update(['status' => 'rejected']);

        return back()->with('message', '候选草稿已拒绝');
    }

    private function proposalFromInquiry(CrmInquiry $inquiry, string $type): CrmContentProposal
    {
        if ($type === 'title_suggestion') {
            $title = trim((string) ($inquiry->product_interest ?: $inquiry->subject));
            $title = $title !== '' ? 'How to Choose '.$title : 'Customer Inquiry Topic';
            $content = "来源询盘：".$inquiry->subject."\n\n需求摘要：".(string) ($inquiry->customer_need_summary ?: $inquiry->raw_message);
        } else {
            $title = 'FAQ - '.$inquiry->subject;
            $content = "## Customer Question\n\n".(string) ($inquiry->raw_message ?: $inquiry->customer_need_summary)."\n\n## Draft Answer Points\n\n".(string) $inquiry->suggested_reply_points."\n\n## Missing Information\n\n".(string) $inquiry->missing_information_questions;
        }

        return CrmContentProposal::query()->create([
            'collection_id' => (int) ($inquiry->collection_id ?? 0) ?: null,
            'source_type' => CrmInquiry::class,
            'source_id' => (int) $inquiry->id,
            'proposal_type' => $type,
            'title' => mb_substr($title, 0, 240, 'UTF-8'),
            'content' => $content,
            'metadata_json' => json_encode(['customer_id' => (int) ($inquiry->customer_id ?? 0)], JSON_UNESCAPED_UNICODE),
            'status' => 'pending',
        ]);
    }

    private function proposalFromTicket(CrmAfterSalesTicket $ticket, string $type): CrmContentProposal
    {
        if ($type === 'case_draft') {
            $title = 'Case - '.$ticket->title;
            $content = "Problem: ".(string) $ticket->issue_description."\n\nResolution: ".(string) $ticket->resolution;
            $metadata = [
                'entity_id' => (int) ($ticket->entity_id ?? 0),
                'challenge' => (string) $ticket->issue_description,
                'solution' => (string) $ticket->resolution,
                'result' => (string) $ticket->reply_points,
            ];
        } else {
            $title = 'FAQ - '.$ticket->title;
            $content = "## Issue\n\n".(string) $ticket->issue_description."\n\n## Reply Points\n\n".(string) $ticket->reply_points."\n\n## Resolution\n\n".(string) $ticket->resolution;
            $metadata = ['entity_id' => (int) ($ticket->entity_id ?? 0)];
        }

        return CrmContentProposal::query()->create([
            'collection_id' => (int) ($ticket->collection_id ?? 0) ?: null,
            'source_type' => CrmAfterSalesTicket::class,
            'source_id' => (int) $ticket->id,
            'proposal_type' => $type,
            'title' => mb_substr($title, 0, 240, 'UTF-8'),
            'content' => $content,
            'metadata_json' => json_encode($metadata, JSON_UNESCAPED_UNICODE),
            'status' => 'pending',
        ]);
    }

    private function markApplied(CrmContentProposal $proposal, string $targetType, int $targetId): void
    {
        $proposal->update([
            'status' => 'applied',
            'applied_target_type' => $targetType,
            'applied_target_id' => $targetId,
            'applied_at' => now(),
        ]);
    }
}
