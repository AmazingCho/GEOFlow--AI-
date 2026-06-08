<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CrmCustomer;
use App\Models\CrmFollowUp;
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

        return redirect()
            ->route('admin.crm.customers.show', ['customerId' => (int) $customer->id])
            ->with('message', '客户已创建');
    }

    public function show(int $customerId): View
    {
        $customer = CrmCustomer::query()
            ->with([
                'collection',
                'followUps' => fn ($query) => $query->with('inquiry')->orderByDesc('created_at')->limit(30),
                'inquiries' => fn ($query) => $query->orderByDesc('created_at')->limit(20),
                'quotes' => fn ($query) => $query->orderByDesc('created_at')->limit(20),
                'salesOrders' => fn ($query) => $query->orderByDesc('created_at')->limit(10),
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

        return redirect()
            ->route('admin.crm.customers.edit', ['customerId' => (int) $customer->id])
            ->with('message', '客户已更新');
    }

    public function destroy(int $customerId): RedirectResponse
    {
        CrmCustomer::query()->whereKey($customerId)->firstOrFail()->delete();

        return redirect()
            ->route('admin.crm.customers.index')
            ->with('message', '客户已删除');
    }

    public function storeFollowUp(Request $request, int $customerId): RedirectResponse
    {
        $customer = CrmCustomer::query()->whereKey($customerId)->firstOrFail();
        $payload = $request->validate([
            'inquiry_id' => ['nullable', 'integer', Rule::exists('crm_inquiries', 'id')->where('customer_id', $customerId)],
            'followup_type' => ['nullable', 'string', 'max:80'],
            'content' => ['required', 'string', 'max:10000'],
            'next_action' => ['nullable', 'string', 'max:5000'],
            'next_followup_at' => ['nullable', 'date'],
            'owner' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', 'string', Rule::in(['open', 'done', 'paused'])],
        ]);

        CrmFollowUp::query()->create([
            'customer_id' => (int) $customer->id,
            'inquiry_id' => isset($payload['inquiry_id']) && (int) $payload['inquiry_id'] > 0 ? (int) $payload['inquiry_id'] : null,
            'followup_type' => trim((string) ($payload['followup_type'] ?? '')),
            'content' => trim((string) $payload['content']),
            'next_action' => trim((string) ($payload['next_action'] ?? '')),
            'next_followup_at' => $payload['next_followup_at'] ?? null,
            'owner' => trim((string) ($payload['owner'] ?? '')),
            'status' => (string) ($payload['status'] ?? 'open'),
        ]);

        return back()->with('message', '跟进记录已添加');
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
}
