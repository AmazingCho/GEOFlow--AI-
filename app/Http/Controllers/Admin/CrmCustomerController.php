<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CrmCustomer;
use App\Models\CrmFollowUp;
use App\Models\CrmCustomerContact;
use App\Models\CrmInquiry;
use App\Services\GeoFlow\CrmActivityService;
use App\Support\AdminWeb;
use App\Support\GeoFlow\CollectionOptions;
use App\Support\GeoFlow\CrmOptions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CrmCustomerController extends Controller
{
    private const PER_PAGE = 20;

    public function index(Request $request): View
    {
        $search = trim((string) $request->query('search', ''));
        $status = trim((string) $request->query('status', ''));
        $collectionId = $this->selectedCollectionId($request);

        $query = CrmCustomer::query()
            ->with('collection')
            ->withCount(['inquiries', 'quotes', 'followUps'])
            ->orderByDesc('updated_at')
            ->orderByDesc('id');

        if ($search !== '') {
            $query->where(function ($builder) use ($search): void {
                $builder
                    ->where('company_name', 'like', '%'.$search.'%')
                    ->orWhere('contact_person', 'like', '%'.$search.'%')
                    ->orWhere('phone', 'like', '%'.$search.'%')
                    ->orWhere('email', 'like', '%'.$search.'%')
                    ->orWhere('tax_number', 'like', '%'.$search.'%')
                    ->orWhere('country', 'like', '%'.$search.'%')
                    ->orWhere('industry', 'like', '%'.$search.'%')
                    ->orWhere('website', 'like', '%'.$search.'%')
                    ->orWhere('owner', 'like', '%'.$search.'%');
            });
        }

        if ($status !== '') {
            $query->where('status', $status);
        }

        if ($collectionId !== null) {
            $query->where('collection_id', $collectionId);
        }

        return view('admin.crm.customers.index', [
            'pageTitle' => '客户管理',
            'activeMenu' => 'crm',
            'adminSiteName' => AdminWeb::siteName(),
            'customers' => $query->paginate(self::PER_PAGE)->withQueryString(),
            'search' => $search,
            'status' => $status,
            'collectionId' => $collectionId,
            'collectionOptions' => CollectionOptions::all(),
            'stats' => [
                'total' => CrmCustomer::query()->count(),
                'active' => CrmCustomer::query()->where('status', 'active')->count(),
                'inquiries' => \App\Models\CrmInquiry::query()->count(),
            ],
        ]);
    }

    public function create(): View
    {
        return view('admin.crm.customers.form', $this->formData([
            'pageTitle' => '新增客户',
            'isEdit' => false,
            'customerId' => 0,
            'customerForm' => $this->emptyCustomerForm(),
            'collectionOptions' => CollectionOptions::all(true),
        ]));
    }

    public function store(Request $request): RedirectResponse
    {
        $payload = $this->validateCustomer($request);
        $customer = CrmCustomer::query()->create($this->normalizeCustomerPayload($payload, true));
        $this->syncPrimaryContact($customer, $payload);

        return redirect()
            ->route('admin.crm.customers.show', ['customerId' => (int) $customer->id])
            ->with('message', '客户已创建');
    }

    public function show(int $customerId): View
    {
        $customer = CrmCustomer::query()
            ->with([
                'collection', 'contacts',
                'opportunities' => fn ($query) => $query->with(['sourceInquiry', 'owner', 'primaryContact', 'quotes.salesOrders.tickets'])->orderByDesc('updated_at')->limit(20),
                'crmTasks' => fn ($query) => $query->with(['assignee', 'inquiry', 'opportunity'])->orderByRaw('due_at IS NULL')->orderBy('due_at')->latest('id')->limit(20),
                'followUps' => fn ($query) => $query->with(['inquiry', 'opportunity', 'task'])->orderByDesc('created_at')->limit(30),
                'inquiries' => fn ($query) => $query->with('opportunities')->orderByDesc('created_at')->limit(20),
                'quotes' => fn ($query) => $query->with(['inquiry', 'opportunity', 'salesOrders.tickets'])->orderByDesc('created_at')->limit(20),
                'salesOrders' => fn ($query) => $query->with(['quote', 'tickets'])->orderByDesc('created_at')->limit(10),
                'afterSalesTickets' => fn ($query) => $query->with(['order', 'entity'])->orderByDesc('created_at')->limit(10),
            ])
            ->whereKey($customerId)
            ->firstOrFail();

        return view('admin.crm.customers.show', [
            'pageTitle' => '客户详情',
            'activeMenu' => 'crm',
            'adminSiteName' => AdminWeb::siteName(),
            'customer' => $customer,
        ]);
    }

    public function edit(int $customerId): View
    {
        $customer = CrmCustomer::query()->whereKey($customerId)->firstOrFail();

        return view('admin.crm.customers.form', $this->formData([
            'pageTitle' => '编辑客户',
            'isEdit' => true,
            'customerId' => (int) $customer->id,
            'customerForm' => [
                'collection_id' => (string) ((int) ($customer->collection_id ?? 0) ?: ''),
                'contact_person' => (string) ($customer->contact_person ?? ''),
                'company_name' => (string) $customer->company_name,
                'customer_type' => (string) ($customer->customer_type ?? ''),
                'country' => (string) ($customer->country ?? ''),
                'address' => (string) ($customer->address ?? ''),
                'website' => (string) ($customer->website ?? ''),
                'industry' => (string) ($customer->industry ?? ''),
                'source_channel' => (string) ($customer->source_channel ?? ''),
                'phone' => (string) ($customer->phone ?? ''),
                'email' => (string) ($customer->email ?? ''),
                'tax_number' => (string) ($customer->tax_number ?? ''),
                'contact_title' => (string) ($customer->contact_title ?? ''),
                'owner' => (string) ($customer->owner ?? ''),
                'status' => (string) ($customer->status ?? 'active'),
                'notes' => (string) ($customer->notes ?? ''),
            ],
            'collectionOptions' => CollectionOptions::all(),
        ]));
    }

    public function update(Request $request, int $customerId): RedirectResponse
    {
        $customer = CrmCustomer::query()->whereKey($customerId)->firstOrFail();
        $payload = $this->validateCustomer($request);
        $customer->update($this->normalizeCustomerPayload($payload, false));
        $this->syncPrimaryContact($customer, $payload);

        return redirect()
            ->route('admin.crm.customers.edit', ['customerId' => (int) $customer->id])
            ->with('message', '客户已更新');
    }

    public function destroy(int $customerId): RedirectResponse
    {
        CrmCustomer::query()->whereKey($customerId)->firstOrFail()->delete();

        return redirect()
            ->route('admin.crm.customers.index')
            ->with('message', '客户已归档，询盘、单据、订单与售后记录均已保留');
    }

    public function storeFollowUp(Request $request, int $customerId, CrmActivityService $activityService): RedirectResponse
    {
        $customer = CrmCustomer::query()->whereKey($customerId)->firstOrFail();
        $payload = $request->validate($activityService->rules() + [
            'inquiry_id' => ['nullable', 'integer', Rule::exists('crm_inquiries', 'id')->where('customer_id', $customerId)],
        ]);
        $inquiry = isset($payload['inquiry_id']) ? CrmInquiry::query()->find((int) $payload['inquiry_id']) : null;
        $opportunity = $inquiry?->opportunities()->oldest('id')->first();
        $result = $activityService->record($customer, $inquiry, $opportunity, $payload, auth('admin')->user());

        return back()->with('message', $result['task'] ? '活动已记录，并创建下一步待办' : '活动记录已添加');
    }

    /**
     * @return array<string, mixed>
     */
    private function validateCustomer(Request $request): array
    {
        return $request->validate([
            'collection_id' => ['nullable', 'integer', 'min:1', Rule::exists('collections', 'id')],
            'company_name' => ['nullable', 'string', 'max:200'],
            'contact_person' => ['required', 'string', 'max:200'],
            'customer_type' => ['nullable', 'string', 'max:80'],
            'country' => ['nullable', 'string', 'max:100'],
            'address' => ['nullable', 'string', 'max:120'],
            'website' => ['nullable', 'string', 'max:500'],
            'industry' => ['nullable', 'string', 'max:160'],
            'source_channel' => ['nullable', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:120'],
            'email' => ['nullable', 'string', 'max:200'],
            'tax_number' => ['nullable', 'string', 'max:120'],
            'contact_title' => ['nullable', 'string', 'max:160'],
            'owner' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', 'string', Rule::in(['active', 'lead', 'inactive', 'blocked'])],
            'notes' => ['nullable', 'string', 'max:20000'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function normalizeCustomerPayload(array $payload, bool $isCreate): array
    {
        $adminName = $this->adminName();
        $data = [
            'collection_id' => isset($payload['collection_id']) && (int) $payload['collection_id'] > 0 ? (int) $payload['collection_id'] : null,
            'company_name' => trim((string) $payload['company_name']),
            'contact_person' => trim((string) ($payload['contact_person'] ?? '')),
            'customer_type' => trim((string) ($payload['customer_type'] ?? '')),
            'country' => trim((string) ($payload['country'] ?? '')),
            'address' => trim((string) ($payload['address'] ?? '')),
            'website' => trim((string) ($payload['website'] ?? '')),
            'industry' => trim((string) ($payload['industry'] ?? '')),
            'source_channel' => trim((string) ($payload['source_channel'] ?? '')),
            'phone' => trim((string) ($payload['phone'] ?? '')),
            'email' => trim((string) ($payload['email'] ?? '')),
            'tax_number' => trim((string) ($payload['tax_number'] ?? '')),
            'contact_title' => trim((string) ($payload['contact_title'] ?? '')),
            'owner' => trim((string) ($payload['owner'] ?? '')),
            'status' => (string) ($payload['status'] ?? 'active'),
            'notes' => trim((string) ($payload['notes'] ?? '')),
            'updated_by' => $adminName,
        ];

        if ($isCreate) {
            $data['created_by'] = $adminName;
        }

        return $data;
    }

    /**
     * @return array<string, string>
     */
    private function emptyCustomerForm(): array
    {
        return [
            'collection_id' => '',
            'company_name' => '',
            'contact_person' => '',
            'customer_type' => '',
            'country' => '',
            'address' => '',
            'website' => '',
            'industry' => '',
            'source_channel' => '',
            'phone' => '',
            'email' => '',
            'tax_number' => '',
            'contact_title' => '',
            'owner' => '',
            'status' => 'active',
            'notes' => '',
        ];
    }

    /**
     * @param  array<string, mixed>  $base
     * @return array<string, mixed>
     */
    private function formData(array $base): array
    {
        return $base + [
            'activeMenu' => 'crm',
            'adminSiteName' => AdminWeb::siteName(),
            'customerTypeOptions' => CrmOptions::customerTypes(),
            'sourceChannelOptions' => CrmOptions::sourceChannels(),
            'countryOptions' => CrmOptions::countries(),
            'employeeOptions' => CrmOptions::employeeOptions(),
        ];
    }

    private function selectedCollectionId(Request $request): ?int
    {
        if (!$request->has('collection_id')) {
            return \App\Support\AdminWeb::defaultCollectionId();
        }
        $value = (int) $request->query('collection_id', 0);
        return $value > 0 ? $value : null;
    }

    private function adminName(): string
    {
        $admin = auth('admin')->user();

        return trim((string) ($admin?->display_name ?: $admin?->username ?: ''));
    }

    private function syncPrimaryContact(CrmCustomer $customer, array $payload): void
    {
        $name = trim((string) ($payload['contact_person'] ?? ''));
        if ($name === '') return;
        $contact = $customer->contacts()->where('is_primary', true)->first() ?: new CrmCustomerContact(['customer_id'=>$customer->id,'is_primary'=>true]);
        $contact->fill(['name'=>$name,'title'=>trim((string) ($payload['contact_title'] ?? '')),'phone'=>trim((string) ($payload['phone'] ?? '')),'email'=>trim((string) ($payload['email'] ?? '')),'status'=>'active'])->save();
    }
}
