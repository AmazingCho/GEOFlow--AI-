@extends('admin.layouts.app')

@section('content')
    <div class="px-4 sm:px-0">
        <div class="mb-8 flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
            <div><a href="{{ route('admin.crm.orders.index') }}" class="text-sm font-medium text-gray-500 hover:text-gray-700">返回订单列表</a><h1 class="mt-2 text-2xl font-bold text-gray-900">{{ $order->order_no }}</h1><p class="mt-1 text-sm text-gray-600">{{ $order->title }} · {{ $order->order_status }}</p></div>
            <div class="flex gap-2"><a href="{{ route('admin.crm.tickets.create', ['order_id' => (int) $order->id]) }}" class="rounded-md border border-orange-200 bg-orange-50 px-4 py-2 text-sm font-medium text-orange-700 hover:bg-orange-100">新增售后工单</a><a href="{{ route('admin.crm.orders.edit', ['orderId' => (int) $order->id]) }}" class="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">编辑订单</a><form method="POST" action="{{ route('admin.crm.orders.delete', ['orderId' => (int) $order->id]) }}" onsubmit="return confirm('确认删除此订单？')" style="display:inline">@csrf<button type="submit" class="rounded-md border border-red-200 bg-red-50 px-4 py-2 text-sm font-medium text-red-700 hover:bg-red-100">删除订单</button></form></div>
        </div>
        @include('admin.crm.partials.nav', ['currentCrmTab' => 'orders'])
        <div class="grid grid-cols-1 gap-6 xl:grid-cols-[minmax(0,1fr)_360px]">
            <section class="rounded-lg bg-white p-6 shadow-sm ring-1 ring-gray-200">
                <h2 class="text-base font-semibold text-gray-900">订单明细</h2>
                <div class="mt-4 overflow-x-auto"><table class="min-w-full divide-y divide-gray-200 text-sm"><thead class="bg-gray-50"><tr><th class="px-4 py-3 text-left">项目</th><th class="px-4 py-3 text-left">Entity</th><th class="px-4 py-3 text-right">数量</th><th class="px-4 py-3 text-right">金额</th></tr></thead><tbody class="divide-y divide-gray-200">@foreach($order->items as $item)<tr><td class="px-4 py-3"><div class="font-medium text-gray-900">{{ $item->item_name }}</div><div class="text-xs text-gray-500">{{ $item->description }}</div></td><td class="px-4 py-3">{{ $item->entity?->name ?? '未关联' }}</td><td class="px-4 py-3 text-right">{{ number_format((float) $item->quantity, 2) }} {{ $item->unit }}</td><td class="px-4 py-3 text-right font-semibold">{{ $order->currency }} {{ number_format((float) $item->amount, 2) }}</td></tr>@endforeach</tbody></table></div>
            </section>
            <aside class="space-y-6">
                <section class="rounded-lg bg-white p-6 shadow-sm ring-1 ring-gray-200"><h2 class="text-base font-semibold text-gray-900">状态</h2><dl class="mt-4 space-y-3 text-sm"><div><dt class="text-gray-500">客户</dt><dd class="font-medium text-gray-900">{{ $order->customer?->company_name ?? '-' }}</dd></div><div><dt class="text-gray-500">负责人</dt><dd>{{ $order->owner ?: '未指定' }}</dd></div><div><dt class="text-gray-500">付款</dt><dd>{{ $order->payment_status }}</dd></div><div><dt class="text-gray-500">生产</dt><dd>{{ $order->production_status }}</dd></div><div><dt class="text-gray-500">交付</dt><dd>{{ $order->delivery_status }}</dd></div><div><dt class="text-gray-500">合计</dt><dd class="font-semibold">{{ $order->currency }} {{ number_format((float) $order->total_amount, 2) }}</dd></div></dl></section>
                <section class="rounded-lg bg-white p-6 shadow-sm ring-1 ring-gray-200"><h2 class="text-base font-semibold text-gray-900">售后工单</h2><div class="mt-4 space-y-2">@forelse($order->tickets as $ticket)<a href="{{ route('admin.crm.tickets.show', ['ticketId' => (int) $ticket->id]) }}" class="block rounded-md border border-gray-200 px-3 py-2 text-sm hover:bg-gray-50">{{ $ticket->title }} · {{ $ticket->status }}</a>@empty<div class="text-sm text-gray-500">暂无工单</div>@endforelse</div></section>
            </aside>
        </div>
    </div>
@endsection
