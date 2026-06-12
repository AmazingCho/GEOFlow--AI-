<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CrmCustomer;
use App\Models\CrmQuote;
use App\Models\CrmSalesOrder;
use App\Models\CrmSalesOrderItem;
use App\Support\AdminWeb;
use App\Support\GeoFlow\CollectionOptions;
use App\Support\GeoFlow\CrmOptions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CrmSalesOrderController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim((string) $request->query('search', ''));
        $status = trim((string) $request->query('order_status', ''));
        $collectionId = (int) $request->query('collection_id', 0);

        $query = CrmSalesOrder::query()
            ->with(['collection', 'customer', 'quote'])
            ->withCount(['items', 'tickets'])
            ->orderByDesc('updated_at')
            ->orderByDesc('id');

        if ($search !== '') {
            $query->where(function ($builder) use ($search): void {
                $builder
                    ->where('order_no', 'like', '%'.$search.'%')
                    ->orWhere('title', 'like', '%'.$search.'%')
                    ->orWhereHas('customer', static fn ($q) => $q->where('company_name', 'like', '%'.$search.'%'));
            });
        }
        if ($status !== '') {
            $query->where('order_status', $status);
        }
        if ($collectionId > 0) {
            $query->where('collection_id', $collectionId);
        }

        return view('admin.crm.orders.index', [
            'pageTitle' => '订单管理',
            'activeMenu' => 'crm',
            'adminSiteName' => AdminWeb::siteName(),
            'orders' => $query->paginate(20)->withQueryString(),
            'search' => $search,
            'orderStatus' => $status,
            'collectionId' => $collectionId > 0 ? $collectionId : null,
            'collectionOptions' => CollectionOptions::all(),
        ]);
    }

    public function fromQuote(int $quoteId): RedirectResponse
    {
        $quote = CrmQuote::query()->with(['customer', 'items'])->whereKey($quoteId)->firstOrFail();
        $order = CrmSalesOrder::query()->create([
            'collection_id' => (int) ($quote->collection_id ?? 0) ?: null,
            'customer_id' => (int) $quote->customer_id,
            'owner' => (string) ($quote->owner ?: $quote->customer?->owner ?: ''),
            'inquiry_id' => (int) ($quote->inquiry_id ?? 0) ?: null,
            'quote_id' => (int) $quote->id,
            'order_no' => $this->generateOrderNo(),
            'title' => (string) $quote->title,
            'currency' => (string) $quote->currency,
            'total_amount' => (float) $quote->grand_total > 0 ? (float) $quote->grand_total : (float) $quote->total_amount,
            'payment_status' => 'pending',
            'production_status' => 'not_started',
            'delivery_status' => 'pending',
            'order_status' => 'open',
            'notes' => (string) ($quote->notes ?? ''),
        ]);

        foreach ($quote->items as $item) {
            $order->items()->create([
                'entity_id' => (int) ($item->entity_id ?? 0) ?: null,
                'item_name' => (string) $item->item_name,
                'description' => (string) ($item->description ?? ''),
                'quantity' => (float) $item->quantity,
                'unit' => (string) ($item->unit ?? ''),
                'unit_price' => (float) $item->unit_price,
                'amount' => (float) $item->amount,
                'sort_order' => (int) $item->sort_order,
            ]);
        }

        return redirect()
            ->route('admin.crm.orders.show', ['orderId' => (int) $order->id])
            ->with('message', '已从报价生成订单');
    }

    public function show(int $orderId): View
    {
        $order = CrmSalesOrder::query()
            ->with(['collection', 'customer', 'quote', 'inquiry.customer.followUps.inquiry', 'items.entity', 'tickets'])
            ->whereKey($orderId)
            ->firstOrFail();

        return view('admin.crm.orders.show', [
            'pageTitle' => '订单详情',
            'activeMenu' => 'crm',
            'adminSiteName' => AdminWeb::siteName(),
            'order' => $order,
        ]);
    }

    public function edit(int $orderId): View
    {
        $order = CrmSalesOrder::query()->whereKey($orderId)->firstOrFail();

        return view('admin.crm.orders.form', [
            'pageTitle' => '编辑订单',
            'activeMenu' => 'crm',
            'adminSiteName' => AdminWeb::siteName(),
            'order' => $order,
            'collectionOptions' => CollectionOptions::all(),
            'customerOptions' => CrmCustomer::query()
                ->with('collection')
                ->orderBy('company_name')
                ->limit(300)
                ->get()
                ->map(static fn (CrmCustomer $customer): array => [
                    'id' => (int) $customer->id,
                    'label' => trim((string) ($customer->contact_person ?? '')) !== '' ? (string) $customer->contact_person : (string) $customer->company_name,
                    'meta' => (string) ($customer->collection?->name ?? ''),
                    'collection_id' => (int) ($customer->collection_id ?? 0),
                ])
                ->all(),
            'employeeOptions' => CrmOptions::employeeOptions(),
        ]);
    }

    public function update(Request $request, int $orderId): RedirectResponse
    {
        $order = CrmSalesOrder::query()->whereKey($orderId)->firstOrFail();
        $payload = $request->validate([
            'customer_id' => ['nullable', 'integer', 'min:1', Rule::exists('crm_customers', 'id')],
            'collection_id' => ['nullable', 'integer', 'min:1', Rule::exists('collections', 'id')],
            'title' => ['required', 'string', 'max:200'],
            'owner' => ['nullable', 'string', 'max:120'],
            'payment_status' => ['required', 'string', Rule::in(['pending', 'partial', 'paid', 'refunded'])],
            'production_status' => ['required', 'string', Rule::in(['not_started', 'in_progress', 'paused', 'completed'])],
            'delivery_status' => ['required', 'string', Rule::in(['pending', 'ready', 'shipped', 'delivered'])],
            'order_status' => ['required', 'string', Rule::in(['open', 'confirmed', 'in_progress', 'completed', 'cancelled'])],
            'notes' => ['nullable', 'string', 'max:20000'],
        ]);

        $order->update([
            'customer_id' => isset($payload['customer_id']) && (int) $payload['customer_id'] > 0 ? (int) $payload['customer_id'] : null,
            'collection_id' => isset($payload['collection_id']) && (int) $payload['collection_id'] > 0 ? (int) $payload['collection_id'] : null,
            'title' => trim((string) $payload['title']),
            'owner' => trim((string) ($payload['owner'] ?? '')),
            'payment_status' => (string) $payload['payment_status'],
            'production_status' => (string) $payload['production_status'],
            'delivery_status' => (string) $payload['delivery_status'],
            'order_status' => (string) $payload['order_status'],
            'notes' => trim((string) ($payload['notes'] ?? '')),
        ]);

        return redirect()
            ->route('admin.crm.orders.edit', ['orderId' => (int) $order->id])
            ->with('message', '订单已更新');
    }

    public function destroy(int $orderId): RedirectResponse
    {
        CrmSalesOrder::query()->whereKey($orderId)->firstOrFail()->delete();

        return redirect()->route('admin.crm.orders.index')->with('message', '订单已归档');
    }

    private function generateOrderNo(): string
    {
        return 'SO-'.date('Ymd-His').'-'.Str::upper(Str::random(4));
    }
}
