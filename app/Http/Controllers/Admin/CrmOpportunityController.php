<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\CrmCustomer;
use App\Models\CrmInquiry;
use App\Models\CrmOpportunity;
use App\Support\AdminWeb;
use App\Support\GeoFlow\CollectionOptions;
use App\Support\GeoFlow\CrmOptions;
use App\Services\GeoFlow\OpportunityConversionService;
use App\Services\GeoFlow\CrmActivityService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class CrmOpportunityController extends Controller
{
    public const STAGES = [
        'qualified' => '已确认',
        'discovery' => '需求梳理',
        'solution' => '方案制定',
        'proposal' => '报价方案',
        'negotiation' => '商务谈判',
        'won' => '赢单',
        'lost' => '输单',
    ];

    public function index(Request $request): View
    {
        $collectionId = (int) $request->query('collection_id', 0);
        $archived = $request->query('view') === 'archived';
        $query = ($archived ? CrmOpportunity::onlyTrashed() : CrmOpportunity::query())
            ->with(['customer', 'owner', 'primaryContact', 'sourceInquiry'])
            ->withCount(['tasks', 'activities', 'quotes'])
            ->latest('updated_at');

        if ($collectionId > 0) {
            $query->where('collection_id', $collectionId);
        }
        $rows = $query->get();

        return view('admin.crm.opportunities.index', [
            'pageTitle' => '商机管道',
            'activeMenu' => 'crm',
            'adminSiteName' => AdminWeb::siteName(),
            'opportunities' => $archived ? collect() : $rows->groupBy('stage'),
            'archivedOpportunities' => $archived ? $rows : collect(),
            'stages' => self::STAGES,
            'archived' => $archived,
            'collectionId' => $collectionId,
            'collectionOptions' => CollectionOptions::all(),
        ]);
    }

    public function kanban(Request $request): View
    {
        $collectionId = (int) $request->query('collection_id', 0);
        $rows = CrmOpportunity::query()
            ->with([
                'customer',
                'sourceInquiry',
                'tasks' => static fn ($query) => $query
                    ->where('status', '<>', 'done')
                    ->orderByRaw('due_at IS NULL')
                    ->orderBy('due_at')
                    ->latest('id'),
            ])
            ->when($collectionId > 0, static fn ($query) => $query->where('collection_id', $collectionId))
            ->latest('updated_at')
            ->get();

        return view('admin.crm.opportunities.kanban', [
            'pageTitle' => '商机看板',
            'activeMenu' => 'crm',
            'adminSiteName' => AdminWeb::siteName(),
            'opportunities' => $rows->groupBy('stage'),
            'stages' => self::STAGES,
            'collectionId' => $collectionId,
            'collectionOptions' => CollectionOptions::all(),
            'summary' => [
                'total' => $rows->count(),
                'open' => $rows->filter(static fn (CrmOpportunity $opportunity): bool => ! in_array((string) $opportunity->stage, ['won', 'lost'], true))->count(),
                'amount' => $rows->sum(static fn (CrmOpportunity $opportunity): float => (float) ($opportunity->amount ?? 0)),
                'open_tasks' => $rows->sum(static fn (CrmOpportunity $opportunity): int => $opportunity->tasks->count()),
            ],
        ]);
    }

    public function create(Request $request): View
    {
        $inquiryId = (int) $request->query('inquiry_id', 0);
        $inquiry = $inquiryId > 0 ? $this->inquiryForOpportunity($inquiryId) : null;

        return view('admin.crm.opportunities.form', $this->formData(null, $inquiry));
    }

    public function store(Request $request, OpportunityConversionService $conversionService): RedirectResponse
    {
        $data = $this->validated($request);
        if ($data['source_inquiry_id']) {
            $inquiry = CrmInquiry::query()->findOrFail((int) $data['source_inquiry_id']);
            $result = $conversionService->convert($inquiry, $data, auth('admin')->user());
            $opportunity = $result['opportunity'];
        } else {
            $opportunity = CrmOpportunity::query()->create($data);
        }

        return redirect()
            ->route('admin.crm.opportunities.edit', ['opportunityId' => (int) $opportunity->id])
            ->with('message', '商机已创建');
    }

    public function storeFromInquiry(int $inquiryId, OpportunityConversionService $conversionService): RedirectResponse
    {
        $inquiry = $this->inquiryForOpportunity($inquiryId);
        $result = $conversionService->convert($inquiry, $this->payloadFromInquiry($inquiry), auth('admin')->user());
        $opportunity = $result['opportunity'];
        $message = $result['created']
            ? sprintf('已创建商机，并关联 %d 个未完成待办、%d 份已有单据、%d 条活动记录', $result['linked_tasks'], $result['linked_documents'], $result['linked_activities'])
            : '该询盘已存在关联商机';

        return redirect()
            ->route('admin.crm.opportunities.edit', ['opportunityId' => (int) $opportunity->id])
            ->with('message', $message);
    }

    public function edit(int $opportunityId): View
    {
        $opportunity = CrmOpportunity::query()
            ->with([
                'customer.contacts',
                'sourceInquiry.customer',
                'sourceInquiry.entities',
                'sourceInquiry.knowledgeBases',
                'sourceInquiry.cases',
                'sourceInquiry.quotes.salesOrders.tickets',
                'sourceInquiry.salesOrders.tickets',
                'tasks' => static fn ($query) => $query
                    ->orderByRaw('due_at IS NULL')
                    ->orderBy('due_at')
                    ->latest('id'),
                'tasks.assignee',
                'quotes.inquiry',
                'quotes.salesOrders.tickets',
                'activities' => static fn ($query) => $query->with(['inquiry', 'opportunity', 'task'])->latest('created_at'),
            ])
            ->findOrFail($opportunityId);

        return view('admin.crm.opportunities.form', $this->formData($opportunity, null));
    }

    public function update(Request $request, int $opportunityId): RedirectResponse
    {
        $opportunity = CrmOpportunity::query()->findOrFail($opportunityId);
        $data = $this->validated($request, $opportunity);
        $data['won_at'] = $data['stage'] === 'won' ? ($opportunity->won_at ?: now()) : null;
        $data['lost_at'] = $data['stage'] === 'lost' ? ($opportunity->lost_at ?: now()) : null;
        $opportunity->update($data);
        $this->markSourceInquiryConverted($opportunity);

        return back()->with('message', '商机已更新');
    }

    public function destroy(int $opportunityId): RedirectResponse
    {
        $opportunity = CrmOpportunity::query()
            ->withCount(['tasks', 'activities', 'quotes'])
            ->findOrFail($opportunityId);
        $summary = sprintf(
            '关联数据已保留：%d 个待办、%d 条活动、%d 份单据。',
            (int) $opportunity->tasks_count,
            (int) $opportunity->activities_count,
            (int) $opportunity->quotes_count,
        );
        $opportunity->delete();

        return redirect()
            ->route('admin.crm.opportunities.index')
            ->with('message', '商机已归档。'.$summary);
    }

    public function restore(int $opportunityId): RedirectResponse
    {
        $opportunity = CrmOpportunity::onlyTrashed()->findOrFail($opportunityId);
        if ($opportunity->source_inquiry_id && CrmOpportunity::query()
            ->where('source_inquiry_id', $opportunity->source_inquiry_id)
            ->exists()) {
            return back()->withErrors([
                'source_inquiry_id' => '该来源询盘已有活动商机，无法恢复此归档商机。',
            ]);
        }

        $opportunity->restore();

        return redirect()
            ->route('admin.crm.opportunities.edit', ['opportunityId' => (int) $opportunity->id])
            ->with('message', '商机已恢复，原有关联数据保持不变');
    }

    public function storeActivity(Request $request, int $opportunityId, CrmActivityService $activityService): RedirectResponse
    {
        $opportunity = CrmOpportunity::query()->with(['customer', 'sourceInquiry'])->findOrFail($opportunityId);
        $payload = $request->validate($activityService->rules());
        $result = $activityService->record(
            $opportunity->customer,
            $opportunity->sourceInquiry,
            $opportunity,
            $payload,
            auth('admin')->user(),
        );

        return back()->with('message', $result['task'] ? '活动已记录，并创建下一步待办' : '活动记录已添加');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?CrmOpportunity $opportunity = null): array
    {
        $data = $request->validate([
            'source_mode' => ['nullable', Rule::in(['inquiry', 'direct'])],
            'collection_id' => ['nullable', 'integer', 'exists:collections,id'],
            'customer_id' => ['required', 'integer', 'exists:crm_customers,id'],
            'primary_contact_id' => [
                'nullable',
                'integer',
                Rule::exists('crm_customer_contacts', 'id')->where('customer_id', (int) $request->input('customer_id')),
            ],
            'source_inquiry_id' => ['nullable', 'integer', 'exists:crm_inquiries,id'],
            'owner_admin_id' => ['nullable', 'integer', 'exists:admins,id'],
            'name' => ['required', 'string', 'max:200'],
            'stage' => ['required', Rule::in(array_keys(self::STAGES))],
            'amount' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'max:10'],
            'probability' => ['nullable', 'integer', 'between:0,100'],
            'expected_close_date' => ['nullable', 'date'],
            'competitor' => ['nullable', 'string', 'max:200'],
            'lost_reason' => [
                Rule::requiredIf(fn () => $request->input('stage') === 'lost'),
                'nullable',
                'string',
                'max:5000',
            ],
            'notes' => ['nullable', 'string', 'max:20000'],
        ]);

        foreach (['collection_id', 'primary_contact_id', 'source_inquiry_id', 'owner_admin_id'] as $key) {
            $data[$key] = (int) ($data[$key] ?? 0) ?: null;
        }
        unset($data['source_mode']);

        $sourceMode = (string) $request->input(
            'source_mode',
            $data['source_inquiry_id'] ? 'inquiry' : 'direct'
        );
        if ($sourceMode === 'direct') {
            $data['source_inquiry_id'] = null;
        } else {
            $this->validateSourceInquiry($data, $opportunity);
        }
        $data['name'] = trim((string) $data['name']);
        $data['amount'] = (float) ($data['amount'] ?? 0);
        $data['currency'] = strtoupper(trim((string) ($data['currency'] ?? 'USD'))) ?: 'USD';
        $data['probability'] = (int) ($data['probability'] ?? 20);
        $data['competitor'] = trim((string) ($data['competitor'] ?? ''));

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function formData(?CrmOpportunity $opportunity, ?CrmInquiry $inquiry): array
    {
        $sourceInquiry = $opportunity?->sourceInquiry ?: $inquiry;
        $customerId = (int) ($opportunity?->customer_id ?: $sourceInquiry?->customer_id ?: 0);

        return [
            'pageTitle' => $opportunity ? '编辑商机' : '新增商机',
            'activeMenu' => 'crm',
            'adminSiteName' => AdminWeb::siteName(),
            'opportunity' => $opportunity,
            'inquiry' => $inquiry,
            'sourceInquiry' => $sourceInquiry,
            'stages' => self::STAGES,
            'customers' => CrmCustomer::query()->with('contacts')->orderBy('company_name')->get(),
            'selectedCustomerId' => $customerId,
            'collectionOptions' => CollectionOptions::all(true),
            'employeeOptions' => CrmOptions::employeeOptionsById(),
            'sourceInquiryOptions' => $this->sourceInquiryOptions($opportunity),
            'sourceMode' => old('source_mode', $sourceInquiry ? 'inquiry' : 'direct'),
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function validateSourceInquiry(array $data, ?CrmOpportunity $opportunity): void
    {
        $sourceInquiryId = (int) ($data['source_inquiry_id'] ?? 0);
        if ($sourceInquiryId <= 0) {
            throw ValidationException::withMessages([
                'source_inquiry_id' => '请选择来源询盘，或改为“无来源直接创建”。',
            ]);
        }

        $inquiry = CrmInquiry::query()->findOrFail($sourceInquiryId);
        if ((int) ($inquiry->customer_id ?? 0) !== (int) ($data['customer_id'] ?? 0)) {
            throw ValidationException::withMessages([
                'source_inquiry_id' => '来源询盘必须属于当前选择的客户。',
            ]);
        }
        if ($inquiry->collection_id && (int) $inquiry->collection_id !== (int) ($data['collection_id'] ?? 0)) {
            throw ValidationException::withMessages([
                'collection_id' => '商机业务容器必须与来源询盘一致。',
            ]);
        }

        $duplicate = CrmOpportunity::query()
            ->where('source_inquiry_id', $sourceInquiryId)
            ->when($opportunity, static fn ($query) => $query->where('id', '<>', $opportunity->id))
            ->first();
        if ($duplicate) {
            throw ValidationException::withMessages([
                'source_inquiry_id' => '该询盘已有活动商机“'.$duplicate->name.'”，请直接打开已有商机。',
            ]);
        }
    }

    /** @return list<array<string, mixed>> */
    private function sourceInquiryOptions(?CrmOpportunity $opportunity): array
    {
        return CrmInquiry::query()
            ->with(['customer', 'opportunities:id,source_inquiry_id,name'])
            ->whereNotNull('customer_id')
            ->latest('updated_at')
            ->limit(200)
            ->get()
            ->map(static function (CrmInquiry $inquiry) use ($opportunity): array {
                $existing = $inquiry->opportunities->first();
                $isCurrent = (int) ($opportunity?->source_inquiry_id ?? 0) === (int) $inquiry->id;

                return [
                    'id' => (int) $inquiry->id,
                    'label' => (string) $inquiry->subject,
                    'customer' => (string) ($inquiry->customer?->company_name ?: $inquiry->customer?->contact_person ?: '未命名客户'),
                    'customer_id' => (int) $inquiry->customer_id,
                    'collection_id' => (int) ($inquiry->collection_id ?? 0),
                    'disabled' => (bool) ($existing && ! $isCurrent),
                    'existing_opportunity' => $existing?->name,
                ];
            })
            ->values()
            ->all();
    }

    private function inquiryForOpportunity(int $inquiryId): CrmInquiry
    {
        return CrmInquiry::query()
            ->with(['customer.contacts', 'entities', 'knowledgeBases', 'cases', 'opportunities'])
            ->findOrFail($inquiryId);
    }

    /**
     * @return array<string, mixed>
     */
    private function payloadFromInquiry(CrmInquiry $inquiry): array
    {
        return [
            'collection_id' => (int) ($inquiry->collection_id ?? 0) ?: null,
            'customer_id' => (int) $inquiry->customer_id,
            'primary_contact_id' => $this->primaryContactId($inquiry),
            'source_inquiry_id' => (int) $inquiry->id,
            'owner_admin_id' => $this->ownerAdminIdFromInquiry($inquiry),
            'name' => (string) $inquiry->subject,
            'stage' => 'qualified',
            'amount' => 0,
            'currency' => 'USD',
            'probability' => 20,
            'notes' => $this->opportunityNotesFromInquiry($inquiry),
        ];
    }

    private function markSourceInquiryConverted(CrmOpportunity $opportunity): void
    {
        if ((int) ($opportunity->source_inquiry_id ?? 0) <= 0) {
            return;
        }

        CrmInquiry::query()
            ->whereKey((int) $opportunity->source_inquiry_id)
            ->whereNotIn('status', ['quoted', 'won', 'lost', 'closed'])
            ->update(['status' => 'converted']);
    }

    private function ownerAdminIdFromInquiry(CrmInquiry $inquiry): ?int
    {
        $assignedTo = trim((string) ($inquiry->assigned_to ?? ''));
        if ($assignedTo === '') {
            return null;
        }

        $id = Admin::query()
            ->where('display_name', $assignedTo)
            ->orWhere('username', $assignedTo)
            ->orWhere('email', $assignedTo)
            ->value('id');

        return $id ? (int) $id : null;
    }

    private function primaryContactId(CrmInquiry $inquiry): ?int
    {
        $contacts = $inquiry->customer?->contacts;
        if (! $contacts || $contacts->isEmpty()) {
            return null;
        }

        return (int) ($contacts->firstWhere('is_primary', true)?->id ?: $contacts->first()?->id) ?: null;
    }

    private function opportunityNotesFromInquiry(CrmInquiry $inquiry): string
    {
        $parts = [];
        foreach ([
            '需求摘要' => $inquiry->customer_need_summary,
            '产品兴趣' => $inquiry->product_interest,
            '建议回复要点' => $inquiry->suggested_reply_points,
        ] as $label => $value) {
            $text = trim((string) $value);
            if ($text !== '') {
                $parts[] = $label.":\n".$text;
            }
        }

        return implode("\n\n", $parts);
    }
}
