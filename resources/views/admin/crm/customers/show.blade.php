@extends('admin.layouts.app')

@php
    $customerTypeLabels = \App\Support\GeoFlow\CrmOptions::customerTypes();
    $sourceChannelLabels = \App\Support\GeoFlow\CrmOptions::sourceChannels();
@endphp

@section('content')
    <div class="px-4 sm:px-0">
        <div class="mb-8 flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
            <div>
                <div class="flex items-center gap-3">
                    <a href="{{ route('admin.crm.customers.index') }}" class="text-gray-400 hover:text-gray-600">
                        <i data-lucide="arrow-left" class="h-5 w-5"></i>
                    </a>
                    <h1 class="text-2xl font-bold text-gray-900">{{ $customer->contact_person ?: $customer->company_name }}</h1>
                </div>
                <p class="mt-2 text-sm text-gray-600">{{ $customer->collection?->name ?? '未指定业务容器' }} · {{ $customer->country ?: '未填写国家' }} · 负责人：{{ $customer->owner ?: '未指定' }}</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <a href="{{ route('admin.crm.inquiries.create', ['customer_id' => (int) $customer->id]) }}" class="inline-flex items-center rounded-md border border-blue-200 bg-blue-50 px-4 py-2 text-sm font-medium text-blue-700 hover:bg-blue-100">
                    <i data-lucide="message-square-plus" class="mr-2 h-4 w-4"></i>
                    新增询盘
                </a>
                <a href="{{ route('admin.crm.quotes.create', ['customer_id' => (int) $customer->id, 'collection_id' => (int) ($customer->collection_id ?? 0)]) }}" class="inline-flex items-center rounded-md border border-purple-200 bg-purple-50 px-4 py-2 text-sm font-medium text-purple-700 hover:bg-purple-100">
                    <i data-lucide="file-plus-2" class="mr-2 h-4 w-4"></i>
                    新增报价
                </a>
                <a href="{{ route('admin.crm.customers.edit', ['customerId' => (int) $customer->id]) }}" class="inline-flex items-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                    <i data-lucide="pencil" class="mr-2 h-4 w-4"></i>
                    编辑
                </a>
                <form method="POST" action="{{ route('admin.crm.customers.delete', ['customerId' => (int) $customer->id]) }}" onsubmit="return confirm('删除客户将同时删除其关联的询盘、报价、订单和售后工单。确认删除？')">
                    @csrf
                    <button type="submit" class="inline-flex items-center rounded-md border border-red-200 bg-red-50 px-4 py-2 text-sm font-medium text-red-700 hover:bg-red-100">
                        <i data-lucide="trash-2" class="mr-2 h-4 w-4"></i>
                        删除客户
                    </button>
                </form>
            </div>
        </div>

        @include('admin.crm.partials.nav', ['currentCrmTab' => 'customers'])

        <div class="grid grid-cols-1 gap-6 xl:grid-cols-[minmax(0,1fr)_420px]">
            <div class="space-y-6">
                <section class="rounded-lg bg-white p-6 shadow-sm ring-1 ring-gray-200">
                    <h2 class="text-base font-semibold text-gray-900">基础信息</h2>
                    <dl class="mt-4 grid grid-cols-1 gap-4 text-sm md:grid-cols-2 xl:grid-cols-3">
                        <div><dt class="text-gray-500">公司名</dt><dd class="mt-1 font-medium text-gray-900">{{ $customer->company_name ?: '未填写' }}</dd></div>
                        <div><dt class="text-gray-500">业务容器</dt><dd class="mt-1 font-medium text-gray-900">{{ $customer->collection?->name ?? '未指定' }}</dd></div>
                        <div><dt class="text-gray-500">负责人</dt><dd class="mt-1 font-medium text-gray-900">{{ $customer->owner ?: '未指定' }}</dd></div>
                        <div><dt class="text-gray-500">客户类型</dt><dd class="mt-1 font-medium text-gray-900">{{ $customer->customer_type ? ($customerTypeLabels[$customer->customer_type] ?? $customer->customer_type) : '未填写' }}</dd></div>
                        <div><dt class="text-gray-500">联系电话</dt><dd class="mt-1 font-medium text-gray-900">{{ $customer->phone ?: '未填写' }}</dd></div>
                        <div><dt class="text-gray-500">邮箱</dt><dd class="mt-1 font-medium text-gray-900">{{ $customer->email ?: '未填写' }}</dd></div>
                        <div><dt class="text-gray-500">职位</dt><dd class="mt-1 font-medium text-gray-900">{{ $customer->contact_title ?: '未填写' }}</dd></div>
                        <div><dt class="text-gray-500">行业</dt><dd class="mt-1 font-medium text-gray-900">{{ $customer->industry ?: '未填写' }}</dd></div>
                        <div><dt class="text-gray-500">国家</dt><dd class="mt-1 font-medium text-gray-900">{{ $customer->country ?: '未填写' }}</dd></div>
                        <div><dt class="text-gray-500">地址</dt><dd class="mt-1 font-medium text-gray-900">{{ $customer->address ?: '未填写' }}</dd></div>
                        <div><dt class="text-gray-500">来源渠道</dt><dd class="mt-1 font-medium text-gray-900">{{ $customer->source_channel ? ($sourceChannelLabels[$customer->source_channel] ?? $customer->source_channel) : '未填写' }}</dd></div>
                        <div><dt class="text-gray-500">状态</dt><dd class="mt-1 font-medium text-gray-900">{{ $customer->status ?: '未填写' }}</dd></div>
                        <div>
                            <dt class="text-gray-500">官网</dt>
                            <dd class="mt-1 font-medium text-gray-900">
                                @if ((string) ($customer->website ?? '') !== '')
                                    <a href="{{ $customer->website }}" target="_blank" rel="noopener" class="text-blue-600 hover:text-blue-700">{{ $customer->website }}</a>
                                @else
                                    未填写
                                @endif
                            </dd>
                        </div>
                        <div><dt class="text-gray-500">创建人</dt><dd class="mt-1 font-medium text-gray-900">{{ $customer->created_by ?: '未记录' }}</dd></div>
                        <div><dt class="text-gray-500">更新人</dt><dd class="mt-1 font-medium text-gray-900">{{ $customer->updated_by ?: '未记录' }}</dd></div>
                        <div><dt class="text-gray-500">创建时间</dt><dd class="mt-1 font-medium text-gray-900">{{ $customer->created_at?->format('Y-m-d H:i') ?: '未记录' }}</dd></div>
                        <div><dt class="text-gray-500">更新时间</dt><dd class="mt-1 font-medium text-gray-900">{{ $customer->updated_at?->format('Y-m-d H:i') ?: '未记录' }}</dd></div>
                    </dl>
                    @if ((string) ($customer->notes ?? '') !== '')
                        <div class="mt-5 whitespace-pre-wrap rounded-md border border-gray-200 bg-gray-50 px-4 py-3 text-sm leading-6 text-gray-700">{{ $customer->notes }}</div>
                    @endif
                </section>

                <section class="rounded-lg bg-white p-6 shadow-sm ring-1 ring-gray-200">
                    <h2 class="text-base font-semibold text-gray-900">询盘与报价</h2>
                    <div class="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-2">
                        <div>
                            <h3 class="text-sm font-medium text-gray-700">最近询盘</h3>
                            <div class="mt-3 space-y-2">
                                @forelse ($customer->inquiries as $inquiry)
                                    <a href="{{ route('admin.crm.inquiries.show', ['inquiryId' => (int) $inquiry->id]) }}" class="block rounded-md border border-gray-200 px-4 py-3 text-sm hover:bg-gray-50">
                                        <span class="font-medium text-gray-900">{{ $inquiry->subject }}</span>
                                        <span class="mt-1 block text-xs text-gray-500">{{ $inquiry->status }} · {{ $inquiry->created_at?->format('Y-m-d') }}</span>
                                    </a>
                                @empty
                                    <div class="text-sm text-gray-500">暂无询盘</div>
                                @endforelse
                            </div>
                        </div>
                        <div>
                            <h3 class="text-sm font-medium text-gray-700">最近报价</h3>
                            <div class="mt-3 space-y-2">
                                @forelse ($customer->quotes as $quote)
                                    <a href="{{ route('admin.crm.quotes.show', ['quoteId' => (int) $quote->id]) }}" class="block rounded-md border border-gray-200 px-4 py-3 text-sm hover:bg-gray-50">
                                        <span class="font-medium text-gray-900">{{ $quote->quote_no }}</span>
                                        <span class="mt-1 block text-xs text-gray-500">{{ $quote->currency }} {{ number_format((float) $quote->total_amount, 2) }} · {{ $quote->status }}</span>
                                    </a>
                                @empty
                                    <div class="text-sm text-gray-500">暂无报价</div>
                                @endforelse
                            </div>
                        </div>
                    </div>
                        <div>
                            <h3 class="text-sm font-medium text-gray-700">最近订单</h3>
                            <div class="mt-3 space-y-2">
                                @forelse ($customer->salesOrders as $order)
                                    <a href="{{ route('admin.crm.orders.show', ['orderId' => (int) $order->id]) }}" class="block rounded-md border border-gray-200 px-4 py-3 text-sm hover:bg-gray-50">
                                        <span class="font-medium text-gray-900">{{ $order->order_no }}</span>
                                        <span class="mt-1 block text-xs text-gray-500">{{ $order->currency }} {{ number_format((float) $order->total_amount, 2) }} · {{ $order->order_status }}</span>
                                    </a>
                                @empty
                                    <div class="text-sm text-gray-500">暂无订单</div>
                                @endforelse
                            </div>
                                            </div>
                </section>
            </div>

            <aside class="space-y-6">
                <section class="rounded-lg bg-white p-6 shadow-sm ring-1 ring-gray-200">
                    <h2 class="text-base font-semibold text-gray-900">跟进记录</h2>
                    <form method="POST" action="{{ route('admin.crm.customers.follow-ups.store', ['customerId' => (int) $customer->id]) }}" class="mt-4 space-y-3">
                        @csrf
                        <textarea name="content" required rows="4" placeholder="跟进内容" class="block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500"></textarea>
                        <input type="text" name="next_action" placeholder="下一步动作" class="block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                        <input type="datetime-local" name="next_followup_at" class="block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                        <input type="hidden" name="owner" value="{{ $customer->owner }}">
                        <button type="submit" class="inline-flex w-full items-center justify-center rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800">添加跟进</button>
                    </form>
                    <div class="mt-5 space-y-3">
                        @forelse ($customer->followUps as $followUp)
                            <div class="rounded-md border border-gray-200 px-4 py-3 text-sm">
                                <div class="font-medium text-gray-900">{{ $followUp->content }}</div>
                                @if ((string) ($followUp->next_action ?? '') !== '')
                                    <div class="mt-1 text-gray-500">下一步：{{ $followUp->next_action }}</div>
                                @endif
                                @if ((string) ($followUp->owner ?? '') !== '')
                                    <div class="mt-1 text-xs text-gray-400">负责人：{{ $followUp->owner }}</div>
                                @endif
                            </div>
                        @empty
                            <div class="text-sm text-gray-500">暂无跟进记录</div>
                        @endforelse
                    </div>
                </section>
            </aside>
        </div>
    </div>
@endsection
