@php
    $asCollection = static function ($value) {
        if ($value instanceof \Illuminate\Support\Collection) {
            return $value;
        }

        if ($value instanceof \Illuminate\Database\Eloquent\Collection) {
            return collect($value->all());
        }

        if (is_array($value)) {
            return collect($value);
        }

        return $value ? collect([$value]) : collect();
    };

    $prependModel = static function (\Illuminate\Support\Collection $items, $model): \Illuminate\Support\Collection {
        return ($model ? collect([$model])->merge($items) : $items)
            ->filter()
            ->unique(static fn ($item) => get_class($item).':'.(int) ($item->id ?? 0))
            ->values();
    };

    $chainTitle = $title ?? '单据链路';
    $chainDescription = $description ?? '按客户、询盘、商机、单据、订单和售后查看当前业务推进关系。';
    $customerNode = $chainCustomer ?? null;
    $inquiries = $prependModel($asCollection($chainInquiries ?? null), $chainInquiry ?? null);
    $opportunities = $prependModel($asCollection($chainOpportunities ?? null), $chainOpportunity ?? null);
    $documents = $asCollection($chainQuotes ?? null)->filter()->unique('id')->values();
    $orders = $asCollection($chainOrders ?? null)->filter()->unique('id')->values();
    $tickets = $asCollection($chainTickets ?? null)->filter()->unique('id')->values();
    $displayLimit = (int) ($limit ?? 4);
    $documentTypeLabels = [
        'quotation' => '报价单',
        'proforma_invoice' => '形式发票',
        'invoice' => '正式发票',
        'packing_list' => '装箱单',
        'contract' => '合同',
    ];
    $opportunityStageLabels = \App\Http\Controllers\Admin\CrmOpportunityController::STAGES;
    $badgeClass = static function (?string $status): string {
        return match ((string) $status) {
            'won', 'paid', 'delivered', 'resolved', 'closed', 'done', 'sent' => 'bg-emerald-50 text-emerald-700',
            'lost', 'invalid', 'overdue', 'cancelled' => 'bg-red-50 text-red-700',
            'proposal', 'production', 'in_progress', 'processing' => 'bg-purple-50 text-purple-700',
            'open', 'new', 'draft', 'qualified' => 'bg-blue-50 text-blue-700',
            default => 'bg-gray-100 text-gray-600',
        };
    };
@endphp

<section class="rounded-lg bg-white p-5 shadow-sm ring-1 ring-gray-200">
    <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <h2 class="text-base font-semibold text-gray-900">{{ $chainTitle }}</h2>
            <p class="mt-1 text-sm leading-6 text-gray-500">{{ $chainDescription }}</p>
        </div>
        <span class="inline-flex w-fit items-center rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-700">
            {{ $documents->count() }} 份单据 · {{ $orders->count() }} 个订单 · {{ $tickets->count() }} 个售后
        </span>
    </div>

    <div class="mt-5 grid gap-3 md:grid-cols-2 2xl:grid-cols-6">
        <div class="rounded-lg border border-gray-200 bg-gray-50 p-4">
            <div class="flex items-center justify-between gap-2">
                <h3 class="text-xs font-semibold uppercase tracking-wide text-gray-500">客户</h3>
                <i data-lucide="building-2" class="h-4 w-4 text-gray-400"></i>
            </div>
            @if($customerNode)
                <a href="{{ route('admin.crm.customers.show', ['customerId' => (int) $customerNode->id]) }}" class="mt-3 block text-sm font-semibold leading-5 text-gray-900 hover:text-blue-600">
                    {{ $customerNode->company_name ?: $customerNode->contact_person ?: '未命名客户' }}
                </a>
                <div class="mt-1 text-xs text-gray-500">{{ $customerNode->country ?: '未填写国家' }} · {{ $customerNode->owner ?: '未指定负责人' }}</div>
            @else
                <div class="mt-3 rounded-md border border-dashed border-gray-300 px-3 py-4 text-sm text-gray-500">未关联客户</div>
            @endif
        </div>

        <div class="rounded-lg border border-gray-200 bg-gray-50 p-4">
            <div class="flex items-center justify-between gap-2">
                <h3 class="text-xs font-semibold uppercase tracking-wide text-gray-500">询盘</h3>
                <span class="rounded-full bg-white px-2 py-0.5 text-xs text-gray-500">{{ $inquiries->count() }}</span>
            </div>
            <div class="mt-3 space-y-2">
                @forelse($inquiries->take($displayLimit) as $inquiryNode)
                    <a href="{{ route('admin.crm.inquiries.show', ['inquiryId' => (int) $inquiryNode->id]) }}" class="block rounded-md border border-gray-200 bg-white px-3 py-2 text-sm hover:border-blue-200 hover:bg-blue-50">
                        <div class="font-medium text-gray-900">{{ $inquiryNode->subject ?: '未命名询盘' }}</div>
                        <span class="mt-1 inline-flex rounded-full px-2 py-0.5 text-xs font-medium {{ $badgeClass($inquiryNode->status ?? '') }}">{{ $inquiryNode->status ?: 'unknown' }}</span>
                    </a>
                @empty
                    <div class="rounded-md border border-dashed border-gray-300 px-3 py-4 text-sm text-gray-500">暂无询盘</div>
                @endforelse
                @if($inquiries->count() > $displayLimit)
                    <div class="text-xs text-gray-500">另有 {{ $inquiries->count() - $displayLimit }} 条未展开</div>
                @endif
            </div>
        </div>

        <div class="rounded-lg border border-gray-200 bg-gray-50 p-4">
            <div class="flex items-center justify-between gap-2">
                <h3 class="text-xs font-semibold uppercase tracking-wide text-gray-500">商机</h3>
                <span class="rounded-full bg-white px-2 py-0.5 text-xs text-gray-500">{{ $opportunities->count() }}</span>
            </div>
            <div class="mt-3 space-y-2">
                @forelse($opportunities->take($displayLimit) as $opportunityNode)
                    <a href="{{ route('admin.crm.opportunities.edit', ['opportunityId' => (int) $opportunityNode->id]) }}" class="block rounded-md border border-gray-200 bg-white px-3 py-2 text-sm hover:border-emerald-200 hover:bg-emerald-50">
                        <div class="font-medium text-gray-900">{{ $opportunityNode->name ?: '未命名商机' }}</div>
                        <div class="mt-1 flex flex-wrap gap-1">
                            <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium {{ $badgeClass($opportunityNode->stage ?? '') }}">{{ $opportunityStageLabels[$opportunityNode->stage] ?? ($opportunityNode->stage ?: 'unknown') }}</span>
                            <span class="inline-flex rounded-full bg-white px-2 py-0.5 text-xs font-medium text-gray-500">{{ (int) ($opportunityNode->probability ?? 0) }}%</span>
                        </div>
                    </a>
                @empty
                    <div class="rounded-md border border-dashed border-gray-300 px-3 py-4 text-sm text-gray-500">暂无商机</div>
                @endforelse
                @if($opportunities->count() > $displayLimit)
                    <div class="text-xs text-gray-500">另有 {{ $opportunities->count() - $displayLimit }} 条未展开</div>
                @endif
            </div>
        </div>

        <div class="rounded-lg border border-gray-200 bg-gray-50 p-4">
            <div class="flex items-center justify-between gap-2">
                <h3 class="text-xs font-semibold uppercase tracking-wide text-gray-500">单据</h3>
                <span class="rounded-full bg-white px-2 py-0.5 text-xs text-gray-500">{{ $documents->count() }}</span>
            </div>
            <div class="mt-3 space-y-2">
                @forelse($documents->take($displayLimit) as $quoteNode)
                    @php($quoteAmount = (float) ($quoteNode->grand_total ?: $quoteNode->total_amount ?: 0))
                    <a href="{{ route('admin.crm.quotes.show', ['quoteId' => (int) $quoteNode->id]) }}" class="block rounded-md border border-gray-200 bg-white px-3 py-2 text-sm hover:border-purple-200 hover:bg-purple-50">
                        <div class="font-medium text-gray-900">{{ $quoteNode->quote_no ?: $quoteNode->title ?: '未生成单号' }}</div>
                        <div class="mt-1 text-xs text-gray-500">{{ $documentTypeLabels[$quoteNode->document_type] ?? ($quoteNode->document_type ?: '报价单') }} · {{ $quoteNode->currency ?: 'USD' }} {{ number_format($quoteAmount, 2) }}</div>
                        <span class="mt-1 inline-flex rounded-full px-2 py-0.5 text-xs font-medium {{ $badgeClass($quoteNode->status ?? '') }}">{{ $quoteNode->status ?: 'draft' }}</span>
                    </a>
                @empty
                    <div class="rounded-md border border-dashed border-gray-300 px-3 py-4 text-sm text-gray-500">暂无单据</div>
                @endforelse
                @if($documents->count() > $displayLimit)
                    <div class="text-xs text-gray-500">另有 {{ $documents->count() - $displayLimit }} 份未展开</div>
                @endif
            </div>
        </div>

        <div class="rounded-lg border border-gray-200 bg-gray-50 p-4">
            <div class="flex items-center justify-between gap-2">
                <h3 class="text-xs font-semibold uppercase tracking-wide text-gray-500">订单</h3>
                <span class="rounded-full bg-white px-2 py-0.5 text-xs text-gray-500">{{ $orders->count() }}</span>
            </div>
            <div class="mt-3 space-y-2">
                @forelse($orders->take($displayLimit) as $orderNode)
                    <a href="{{ route('admin.crm.orders.show', ['orderId' => (int) $orderNode->id]) }}" class="block rounded-md border border-gray-200 bg-white px-3 py-2 text-sm hover:border-blue-200 hover:bg-blue-50">
                        <div class="font-medium text-gray-900">{{ $orderNode->order_no ?: $orderNode->title ?: '未生成订单号' }}</div>
                        <div class="mt-1 text-xs text-gray-500">{{ $orderNode->currency ?: 'USD' }} {{ number_format((float) ($orderNode->total_amount ?? 0), 2) }}</div>
                        <span class="mt-1 inline-flex rounded-full px-2 py-0.5 text-xs font-medium {{ $badgeClass($orderNode->order_status ?? '') }}">{{ $orderNode->order_status ?: 'open' }}</span>
                    </a>
                @empty
                    <div class="rounded-md border border-dashed border-gray-300 px-3 py-4 text-sm text-gray-500">暂无订单</div>
                @endforelse
                @if($orders->count() > $displayLimit)
                    <div class="text-xs text-gray-500">另有 {{ $orders->count() - $displayLimit }} 个未展开</div>
                @endif
            </div>
        </div>

        <div class="rounded-lg border border-gray-200 bg-gray-50 p-4">
            <div class="flex items-center justify-between gap-2">
                <h3 class="text-xs font-semibold uppercase tracking-wide text-gray-500">售后</h3>
                <span class="rounded-full bg-white px-2 py-0.5 text-xs text-gray-500">{{ $tickets->count() }}</span>
            </div>
            <div class="mt-3 space-y-2">
                @forelse($tickets->take($displayLimit) as $ticketNode)
                    <a href="{{ route('admin.crm.tickets.show', ['ticketId' => (int) $ticketNode->id]) }}" class="block rounded-md border border-gray-200 bg-white px-3 py-2 text-sm hover:border-orange-200 hover:bg-orange-50">
                        <div class="font-medium text-gray-900">{{ $ticketNode->title ?: '未命名工单' }}</div>
                        <div class="mt-1 text-xs text-gray-500">@if($ticketNode->order)订单：{{ $ticketNode->order->order_no ?: $ticketNode->order->title }}@else 未关联订单 @endif</div>
                        <span class="mt-1 inline-flex rounded-full px-2 py-0.5 text-xs font-medium {{ $badgeClass($ticketNode->status ?? '') }}">{{ $ticketNode->status ?: 'open' }}</span>
                    </a>
                @empty
                    <div class="rounded-md border border-dashed border-gray-300 px-3 py-4 text-sm text-gray-500">暂无售后</div>
                @endforelse
                @if($tickets->count() > $displayLimit)
                    <div class="text-xs text-gray-500">另有 {{ $tickets->count() - $displayLimit }} 个未展开</div>
                @endif
            </div>
        </div>
    </div>
</section>
