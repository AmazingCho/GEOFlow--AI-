<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CaseRecord;
use App\Models\CrmAfterSalesTicket;
use App\Models\CrmCustomer;
use App\Models\CrmSalesOrder;
use App\Models\EntityRecord;
use App\Models\KnowledgeBase;
use App\Services\GeoFlow\CrmInquiryAnalysisService;
use App\Support\AdminWeb;
use App\Support\GeoFlow\CollectionOptions;
use App\Support\GeoFlow\CrmOptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CrmAfterSalesTicketController extends Controller
{
    public function __construct(private readonly CrmInquiryAnalysisService $analysisService) {}

    public function index(Request $request): View
    {
        $search = trim((string) $request->query('search', ''));
        $status = trim((string) $request->query('status', ''));
        $priority = trim((string) $request->query('priority', ''));
        $collectionId = (int) $request->query('collection_id', 0);

        $query = CrmAfterSalesTicket::query()
            ->with(['collection', 'customer', 'order', 'entity'])
            ->withCount(['knowledgeBases', 'cases'])
            ->orderByDesc('updated_at')
            ->orderByDesc('id');

        if ($search !== '') {
            $query->where(function ($builder) use ($search): void {
                $builder
                    ->where('title', 'like', '%'.$search.'%')
                    ->orWhere('issue_description', 'like', '%'.$search.'%')
                    ->orWhereHas('customer', static fn ($q) => $q->where('company_name', 'like', '%'.$search.'%'));
            });
        }
        if ($status !== '') {
            $query->where('status', $status);
        }
        if ($priority !== '') {
            $query->where('priority', $priority);
        }
        if ($collectionId > 0) {
            $query->where('collection_id', $collectionId);
        }

        return view('admin.crm.tickets.index', [
            'pageTitle' => '售后工单',
            'activeMenu' => 'crm',
            'adminSiteName' => AdminWeb::siteName(),
            'tickets' => $query->paginate(20)->withQueryString(),
            'search' => $search,
            'status' => $status,
            'priority' => $priority,
            'collectionId' => $collectionId > 0 ? $collectionId : null,
            'collectionOptions' => CollectionOptions::all(),
        ]);
    }

    public function create(Request $request): View
    {
        $order = null;
        $orderId = (int) $request->query('order_id', 0);
        if ($orderId > 0) {
            $order = CrmSalesOrder::query()->with(['customer', 'items.entity'])->whereKey($orderId)->first();
        }

        $collectionId = (int) ($request->query('collection_id', 0) ?: ($order?->collection_id ?? 0));
        $customerId = (int) ($request->query('customer_id', 0) ?: ($order?->customer_id ?? 0));

        return view('admin.crm.tickets.form', $this->formData([
            'pageTitle' => '新增售后工单',
            'isEdit' => false,
            'ticketId' => 0,
            'ticketForm' => [
                'collection_id' => $collectionId > 0 ? (string) $collectionId : '',
                'customer_id' => $customerId > 0 ? (string) $customerId : '',
                'owner' => (string) ($order?->owner ?: $order?->customer?->owner ?: ''),
                'order_id' => $order ? (string) $order->id : '',
                'entity_id' => (string) ((int) ($order?->items->first()?->entity_id ?? 0) ?: ''),
                'title' => '',
                'issue_description' => '',
                'issue_type' => '',
                'priority' => 'normal',
                'status' => 'open',
                'reply_points' => '',
                'missing_information_questions' => '',
                'resolution' => '',
                'notes' => '',
            ],
            'selectedKnowledgeBaseIds' => [],
            'selectedCaseRecordIds' => [],
        ], $collectionId > 0 ? $collectionId : null));
    }

    public function store(Request $request): RedirectResponse
    {
        $payload = $this->validateTicket($request);
        $ticket = CrmAfterSalesTicket::query()->create($this->normalizeTicketPayload($payload));
        $this->syncRelations($ticket, $payload);

        return redirect()->route('admin.crm.tickets.show', ['ticketId' => (int) $ticket->id])->with('message', '售后工单已创建');
    }

    public function show(int $ticketId): View
    {
        $ticket = CrmAfterSalesTicket::query()
            ->with(['collection', 'customer', 'order.inquiry.customer.followUps.inquiry', 'entity', 'knowledgeBases', 'cases'])
            ->whereKey($ticketId)
            ->firstOrFail();

        return view('admin.crm.tickets.show', [
            'pageTitle' => '售后工单详情',
            'activeMenu' => 'crm',
            'adminSiteName' => AdminWeb::siteName(),
            'ticket' => $ticket,
            'aiModelOptions' => $this->analysisService->modelOptions(),
        ]);
    }

    public function edit(int $ticketId): View
    {
        $ticket = CrmAfterSalesTicket::query()->with(['knowledgeBases', 'cases'])->whereKey($ticketId)->firstOrFail();

        return view('admin.crm.tickets.form', $this->formData([
            'pageTitle' => '编辑售后工单',
            'isEdit' => true,
            'ticketId' => (int) $ticket->id,
            'ticketForm' => [
                'collection_id' => (string) ((int) ($ticket->collection_id ?? 0) ?: ''),
                'customer_id' => (string) ((int) ($ticket->customer_id ?? 0) ?: ''),
                'owner' => (string) ($ticket->owner ?? ''),
                'order_id' => (string) ((int) ($ticket->order_id ?? 0) ?: ''),
                'entity_id' => (string) ((int) ($ticket->entity_id ?? 0) ?: ''),
                'title' => (string) $ticket->title,
                'issue_description' => (string) ($ticket->issue_description ?? ''),
                'issue_type' => (string) ($ticket->issue_type ?? ''),
                'priority' => (string) ($ticket->priority ?? 'normal'),
                'status' => (string) ($ticket->status ?? 'open'),
                'reply_points' => (string) ($ticket->reply_points ?? ''),
                'missing_information_questions' => (string) ($ticket->missing_information_questions ?? ''),
                'resolution' => (string) ($ticket->resolution ?? ''),
                'notes' => (string) ($ticket->notes ?? ''),
            ],
            'selectedKnowledgeBaseIds' => $ticket->knowledgeBases->pluck('id')->map(fn ($id): int => (int) $id)->all(),
            'selectedCaseRecordIds' => $ticket->cases->pluck('id')->map(fn ($id): int => (int) $id)->all(),
        ], (int) ($ticket->collection_id ?? 0) ?: null));
    }

    public function update(Request $request, int $ticketId): RedirectResponse
    {
        $ticket = CrmAfterSalesTicket::query()->whereKey($ticketId)->firstOrFail();
        $payload = $this->validateTicket($request);
        $ticket->update($this->normalizeTicketPayload($payload));
        $this->syncRelations($ticket, $payload);

        return redirect()->route('admin.crm.tickets.edit', ['ticketId' => (int) $ticket->id])->with('message', '售后工单已更新');
    }

    public function destroy(int $ticketId): RedirectResponse
    {
        CrmAfterSalesTicket::query()->whereKey($ticketId)->firstOrFail()->delete();

        return redirect()->route('admin.crm.tickets.index')->with('message', '售后工单已归档');
    }

    public function analyze(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'content' => ['required', 'string', 'max:50000'],
            'collection_id' => ['nullable', 'integer', 'min:1'],
            'ai_model_id' => ['nullable', 'integer', 'min:0'],
        ]);
        $fields = $this->analysisService->analyze(
            (string) $payload['content'],
            isset($payload['collection_id']) && (int) $payload['collection_id'] > 0 ? (int) $payload['collection_id'] : null,
            (int) ($payload['ai_model_id'] ?? 0)
        );

        return response()->json(['fields' => [
            'entity_id' => (int) ($fields['entity_ids'][0] ?? 0) ?: null,
            'knowledge_base_ids' => $fields['knowledge_base_ids'] ?? [],
            'case_record_ids' => $fields['case_record_ids'] ?? [],
            'reply_points' => $fields['suggested_reply_points'] ?? '',
            'missing_information_questions' => $fields['missing_information_questions'] ?? '',
            'priority' => ($fields['urgency_level'] ?? '') === 'high' ? 'high' : 'normal',
        ]]);
    }

    /**
     * @param  array<string,mixed>  $base
     * @return array<string,mixed>
     */
    private function formData(array $base, ?int $collectionId): array
    {
        return $base + [
            'activeMenu' => 'crm',
            'adminSiteName' => AdminWeb::siteName(),
            'collectionOptions' => CollectionOptions::all(true),
            'customerOptions' => CrmCustomer::query()->with('collection')->orderBy('company_name')->limit(300)->get()->map(static fn (CrmCustomer $c): array => ['id' => (int) $c->id, 'label' => trim((string) ($c->contact_person ?? '')) !== '' ? (string) $c->contact_person : (string) $c->company_name, 'meta' => (string) ($c->collection?->name ?? ''), 'collection_id' => (int) ($c->collection_id ?? 0)])->all(),
            'orderOptions' => CrmSalesOrder::query()->with('customer')->orderByDesc('id')->limit(300)->get(),
            'entityOptions' => EntityRecord::query()->when($collectionId, static fn ($q) => $q->where('collection_id', $collectionId))->orderBy('name')->get(['id', 'name', 'entity_type', 'collection_id'])->map(static fn ($e): array => ['id' => (int) $e->id, 'label' => (string) $e->name, 'meta' => (string) $e->entity_type, 'collection_id' => (int) ($e->collection_id ?? 0)])->all(),
            'knowledgeBaseOptions' => KnowledgeBase::query()->when($collectionId, static fn ($q) => $q->where('collection_id', $collectionId))->orderBy('name')->get(['id', 'name', 'knowledge_type', 'collection_id'])->map(static fn ($kb): array => ['id' => (int) $kb->id, 'label' => (string) $kb->name, 'meta' => (string) $kb->knowledge_type, 'collection_id' => (int) ($kb->collection_id ?? 0)])->all(),
            'caseOptions' => CaseRecord::query()->when($collectionId, static fn ($q) => $q->where('collection_id', $collectionId))->orderBy('title')->get(['id', 'title', 'case_type', 'collection_id'])->map(static fn ($case): array => ['id' => (int) $case->id, 'label' => (string) $case->title, 'meta' => (string) $case->case_type, 'collection_id' => (int) ($case->collection_id ?? 0)])->all(),
            'employeeOptions' => CrmOptions::employeeOptions(),
            'aiModelOptions' => $this->analysisService->modelOptions(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function validateTicket(Request $request): array
    {
        return $request->validate([
            'collection_id' => ['nullable', 'integer', 'min:1', Rule::exists('collections', 'id')],
            'customer_id' => ['required', 'integer', 'min:1', Rule::exists('crm_customers', 'id')],
            'owner' => ['nullable', 'string', 'max:120'],
            'order_id' => ['nullable', 'integer', 'min:1', Rule::exists('crm_sales_orders', 'id')],
            'entity_id' => ['nullable', 'integer', 'min:1', Rule::exists('entities', 'id')],
            'title' => ['required', 'string', 'max:200'],
            'issue_description' => ['nullable', 'string', 'max:50000'],
            'issue_type' => ['nullable', 'string', 'max:100'],
            'priority' => ['required', 'string', Rule::in(['low', 'normal', 'high', 'urgent'])],
            'status' => ['required', 'string', Rule::in(['open', 'waiting_customer', 'in_progress', 'resolved', 'closed'])],
            'reply_points' => ['nullable', 'string', 'max:20000'],
            'missing_information_questions' => ['nullable', 'string', 'max:20000'],
            'resolution' => ['nullable', 'string', 'max:20000'],
            'notes' => ['nullable', 'string', 'max:20000'],
            'knowledge_base_ids' => ['nullable', 'array'],
            'knowledge_base_ids.*' => ['integer', Rule::exists('knowledge_bases', 'id')],
            'case_record_ids' => ['nullable', 'array'],
            'case_record_ids.*' => ['integer', Rule::exists('case_records', 'id')],
        ]);
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function normalizeTicketPayload(array $payload): array
    {
        return [
            'collection_id' => $this->nullableId($payload['collection_id'] ?? null),
            'customer_id' => (int) $payload['customer_id'],
            'owner' => trim((string) ($payload['owner'] ?? '')),
            'order_id' => $this->nullableId($payload['order_id'] ?? null),
            'entity_id' => $this->nullableId($payload['entity_id'] ?? null),
            'title' => trim((string) $payload['title']),
            'issue_description' => trim((string) ($payload['issue_description'] ?? '')),
            'issue_type' => trim((string) ($payload['issue_type'] ?? '')),
            'priority' => (string) $payload['priority'],
            'status' => (string) $payload['status'],
            'reply_points' => trim((string) ($payload['reply_points'] ?? '')),
            'missing_information_questions' => trim((string) ($payload['missing_information_questions'] ?? '')),
            'resolution' => trim((string) ($payload['resolution'] ?? '')),
            'resolved_at' => in_array((string) ($payload['status'] ?? ''), ['resolved', 'closed'], true) ? now() : null,
            'notes' => trim((string) ($payload['notes'] ?? '')),
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function syncRelations(CrmAfterSalesTicket $ticket, array $payload): void
    {
        $ticket->knowledgeBases()->sync($this->ids($payload['knowledge_base_ids'] ?? []));
        $ticket->cases()->sync($this->ids($payload['case_record_ids'] ?? []));
    }

    /**
     * @return list<int>
     */
    private function ids(mixed $ids): array
    {
        return collect(is_array($ids) ? $ids : [])->map(static fn ($id): int => (int) $id)->filter(static fn (int $id): bool => $id > 0)->unique()->values()->all();
    }

    private function nullableId(mixed $value): ?int
    {
        $id = (int) $value;

        return $id > 0 ? $id : null;
    }
}
