@extends('admin.layouts.app')

@section('content')
    <div class="px-4 sm:px-0">
        <div class="mb-8">
            <h1 class="text-2xl font-bold text-gray-900">订单管理</h1>
            <p class="mt-1 text-sm text-gray-600">轻量跟踪报价转订单后的付款、生产和交付状态，不做库存或财务核算。</p>
        </div>
        @include('admin.crm.partials.nav', ['currentCrmTab' => 'orders'])

        <form method="GET" action="{{ route('admin.crm.orders.index') }}" class="mb-6 rounded-lg border border-gray-200 bg-white px-5 py-4 shadow-sm">
            <div class="grid grid-cols-1 gap-3 lg:grid-cols-[30%_180px_auto] lg:items-end">
                <div><label class="mb-2 block text-sm font-medium text-gray-700">搜索</label><input type="text" name="search" value="{{ $search }}" class="block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" placeholder="订单号、标题、客户"></div>
                @include('admin.partials.collection-select', ['selectedId' => (string) ($collectionId ?? ''), 'collectionOptions' => $collectionOptions ?? [], 'label' => '业务容器', 'help' => '', 'emptyLabel' => '全部业务容器', 'class' => 'block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500'])
                <div>
                    <label class="mb-2 block text-sm font-medium text-gray-700">订单状态</label>
                    <select name="order_status" class="block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                        <option value="">全部</option>
                        @foreach (['open' => '打开', 'confirmed' => '已确认', 'in_progress' => '进行中', 'completed' => '已完成', 'cancelled' => '已取消'] as $value => $label)
                            <option value="{{ $value }}" @selected($orderStatus === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="flex gap-2"><button class="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">筛选</button><a href="{{ route('admin.crm.orders.index') }}" class="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">清除</a></div>
            </div>
        </form>

        <div class="overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
            <div class="border-b border-gray-200 px-6 py-4"><h3 class="text-base font-semibold text-gray-900">订单列表 <span class="text-sm text-gray-500">({{ $orders->total() }})</span></h3></div>
            @if ($orders->isEmpty())
                <div class="px-6 py-10 text-center text-sm text-gray-500">暂无订单，可从报价详情页转换生成。</div>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50"><tr><th class="px-6 py-3 text-left text-xs font-medium uppercase text-gray-500">订单</th><th class="px-6 py-3 text-left text-xs font-medium uppercase text-gray-500">状态</th><th class="px-6 py-3 text-left text-xs font-medium uppercase text-gray-500">金额</th><th class="px-6 py-3 text-right text-xs font-medium uppercase text-gray-500">操作</th></tr></thead>
                        <tbody class="divide-y divide-gray-200 bg-white">
                            @foreach ($orders as $order)
                                <tr>
                                    <td class="px-6 py-4"><div class="font-semibold text-gray-900">{{ $order->order_no }}</div><div class="mt-1 text-sm text-gray-600">{{ $order->title }}</div><div class="mt-1 text-xs text-gray-500">{{ $order->customer?->contact_person ?: $order->customer?->company_name ?? '未关联客户' }} · {{ $order->collection?->name ?? '未指定' }} · 负责人：{{ $order->owner ?: '未指定' }}</div></td>
                                    <td class="px-6 py-4 text-sm text-gray-600">订单：{{ $order->order_status }}<br>生产：{{ $order->production_status }}<br>交付：{{ $order->delivery_status }}</td>
                                    <td class="px-6 py-4 font-semibold text-gray-900">{{ $order->currency }} {{ number_format((float) $order->total_amount, 2) }}</td>
                                    <td class="px-6 py-4 text-right"><div class="flex justify-end gap-2"><a href="{{ route('admin.crm.orders.show', ['orderId' => (int) $order->id]) }}" class="rounded border border-gray-300 bg-white px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50">查看</a><a href="{{ route('admin.crm.orders.edit', ['orderId' => (int) $order->id]) }}" class="rounded border border-gray-300 bg-white px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50">编辑</a><a href="{{ route('admin.crm.tickets.create', ['order_id' => (int) $order->id]) }}" class="rounded border border-orange-200 bg-orange-50 px-3 py-1.5 text-xs font-medium text-orange-700 hover:bg-orange-100">售后</a><form method="POST" action="{{ route('admin.crm.orders.delete', ['orderId' => (int) $order->id]) }}" onsubmit="return confirm('确认归档此订单？')" style="display:inline">@csrf<button type="submit" class="rounded border border-red-200 bg-red-50 px-3 py-1.5 text-xs font-medium text-red-700 hover:bg-red-100">归档</button></form></div></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="flex flex-col gap-3 border-t border-gray-200 px-6 py-4 lg:flex-row lg:items-center lg:justify-between"><div class="text-sm text-gray-600">显示第 {{ $orders->firstItem() ?? 0 }} - {{ $orders->lastItem() ?? 0 }} 条，共 {{ $orders->total() }} 条</div>{{ $orders->links() }}</div>
            @endif
        </div>
    </div>
@endsection
