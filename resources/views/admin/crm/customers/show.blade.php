@extends('admin.layouts.app')

@php
    $customerTypeLabels = \App\Support\GeoFlow\CrmOptions::customerTypes();
    $sourceChannelLabels = \App\Support\GeoFlow\CrmOptions::sourceChannels();
    $opportunityStageLabels = \App\Http\Controllers\Admin\CrmOpportunityController::STAGES;
    $inputClass = 'rounded-md border border-gray-300 px-3 py-2 text-sm outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500';
    $openTaskCount = $customer->crmTasks->filter(static fn ($task) => (string) $task->status !== 'done')->count();
    $openOpportunityCount = $customer->opportunities->filter(static fn ($opportunity) => ! in_array((string) $opportunity->stage, ['won', 'lost'], true))->count();
    $openTicketCount = $customer->afterSalesTickets->filter(static fn ($ticket) => ! in_array((string) $ticket->status, ['resolved', 'closed'], true))->count();
    $quoteTotal = $customer->quotes->sum(static fn ($quote) => (float) ($quote->grand_total ?: $quote->total_amount));
    $customerTitle = $customer->company_name ?: $customer->contact_person;
@endphp

@section('content')
    <div class="px-4 sm:px-0">
        <div class="mb-8 flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
            <div>
                <div class="flex items-center gap-3">
                    <a href="{{ route('admin.crm.customers.index') }}" class="text-gray-400 hover:text-gray-600">
                        <i data-lucide="arrow-left" class="h-5 w-5"></i>
                    </a>
                    <h1 class="text-2xl font-bold text-gray-900">{{ $customerTitle }}</h1>
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
                    新增单据
                </a>
                <a href="{{ route('admin.crm.customers.edit', ['customerId' => (int) $customer->id]) }}" class="inline-flex items-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                    <i data-lucide="pencil" class="mr-2 h-4 w-4"></i>
                    编辑
                </a>
                <form method="POST" action="{{ route('admin.crm.customers.delete', ['customerId' => (int) $customer->id]) }}" onsubmit="return confirm('归档客户不会删除其询盘、单据、订单和售后记录。确认归档？')">
                    @csrf
                    <button type="submit" class="inline-flex items-center rounded-md border border-red-200 bg-red-50 px-4 py-2 text-sm font-medium text-red-700 hover:bg-red-100">
                        <i data-lucide="trash-2" class="mr-2 h-4 w-4"></i>
                        归档客户
                    </button>
                </form>
            </div>
        </div>

        @include('admin.crm.partials.nav', ['currentCrmTab' => 'customers'])

        <section class="mb-6 rounded-lg bg-white p-5 shadow-sm ring-1 ring-gray-200">
            <div class="grid gap-4 md:grid-cols-3 xl:grid-cols-6">
                <div>
                    <div class="text-xs font-medium text-gray-500">询盘</div>
                    <div class="mt-1 text-2xl font-semibold text-gray-900">{{ $customer->inquiries->count() }}</div>
                </div>
                <div>
                    <div class="text-xs font-medium text-gray-500">活动中商机</div>
                    <div class="mt-1 text-2xl font-semibold text-gray-900">{{ $openOpportunityCount }}</div>
                </div>
                <div>
                    <div class="text-xs font-medium text-gray-500">单据金额</div>
                    <div class="mt-1 text-lg font-semibold text-gray-900">USD {{ number_format((float) $quoteTotal, 2) }}</div>
                </div>
                <div>
                    <div class="text-xs font-medium text-gray-500">订单</div>
                    <div class="mt-1 text-2xl font-semibold text-gray-900">{{ $customer->salesOrders->count() }}</div>
                </div>
                <div>
                    <div class="text-xs font-medium text-gray-500">待处理售后</div>
                    <div class="mt-1 text-2xl font-semibold text-gray-900">{{ $openTicketCount }}</div>
                </div>
                <div>
                    <div class="text-xs font-medium text-gray-500">未完成待办</div>
                    <div class="mt-1 text-2xl font-semibold text-gray-900">{{ $openTaskCount }}</div>
                </div>
            </div>
        </section>

        @php
            $customerChainOrders = $customer->salesOrders
                ->merge($customer->quotes->flatMap(static fn ($quote) => $quote->salesOrders))
                ->unique('id')
                ->values();
            $customerChainTickets = $customer->afterSalesTickets
                ->merge($customerChainOrders->flatMap(static fn ($order) => $order->tickets))
                ->unique('id')
                ->values();
        @endphp
        <div class="mb-6">
            @include('admin.crm.partials._document-chain', [
                'title' => '客户单据链路',
                'description' => '汇总这个客户从询盘、商机、单据到订单和售后的当前业务关系。',
                'chainCustomer' => $customer,
                'chainInquiries' => $customer->inquiries,
                'chainOpportunities' => $customer->opportunities,
                'chainQuotes' => $customer->quotes,
                'chainOrders' => $customerChainOrders,
                'chainTickets' => $customerChainTickets,
            ])
        </div>

        <div class="grid gap-6 xl:grid-cols-[minmax(0,1.35fr)_minmax(360px,0.65fr)]">
            <div class="space-y-6">
                <section class="rounded-lg bg-white p-6 shadow-sm ring-1 ring-gray-200">
                    <div class="flex items-center justify-between gap-3">
                        <h2 class="text-base font-semibold text-gray-900">基础信息</h2>
                        <span class="rounded-full bg-gray-100 px-3 py-1 text-xs font-medium text-gray-600">{{ $customer->status ?: '未填写状态' }}</span>
                    </div>
                    <dl class="mt-4 grid grid-cols-1 gap-4 text-sm md:grid-cols-2 xl:grid-cols-3">
                        <div><dt class="text-gray-500">公司名</dt><dd class="mt-1 font-medium text-gray-900">{{ $customer->company_name ?: '未填写' }}</dd></div>
                        <div><dt class="text-gray-500">主联系人</dt><dd class="mt-1 font-medium text-gray-900">{{ $customer->contact_person ?: '未填写' }}</dd></div>
                        <div><dt class="text-gray-500">职位</dt><dd class="mt-1 font-medium text-gray-900">{{ $customer->contact_title ?: '未填写' }}</dd></div>
                        <div><dt class="text-gray-500">联系电话</dt><dd class="mt-1 font-medium text-gray-900">{{ $customer->phone ?: '未填写' }}</dd></div>
                        <div><dt class="text-gray-500">邮箱</dt><dd class="mt-1 font-medium text-gray-900">{{ $customer->email ?: '未填写' }}</dd></div>
                        <div><dt class="text-gray-500">税号</dt><dd class="mt-1 font-medium text-gray-900">{{ $customer->tax_number ?: '未填写' }}</dd></div>
                        <div><dt class="text-gray-500">客户类型</dt><dd class="mt-1 font-medium text-gray-900">{{ $customer->customer_type ? ($customerTypeLabels[$customer->customer_type] ?? $customer->customer_type) : '未填写' }}</dd></div>
                        <div><dt class="text-gray-500">国家</dt><dd class="mt-1 font-medium text-gray-900">{{ $customer->country ?: '未填写' }}</dd></div>
                        <div><dt class="text-gray-500">行业</dt><dd class="mt-1 font-medium text-gray-900">{{ $customer->industry ?: '未填写' }}</dd></div>
                        <div><dt class="text-gray-500">业务容器</dt><dd class="mt-1 font-medium text-gray-900">{{ $customer->collection?->name ?? '未指定' }}</dd></div>
                        <div><dt class="text-gray-500">来源渠道</dt><dd class="mt-1 font-medium text-gray-900">{{ $customer->source_channel ? ($sourceChannelLabels[$customer->source_channel] ?? $customer->source_channel) : '未填写' }}</dd></div>
                        <div><dt class="text-gray-500">负责人</dt><dd class="mt-1 font-medium text-gray-900">{{ $customer->owner ?: '未指定' }}</dd></div>
                        <div class="md:col-span-2"><dt class="text-gray-500">地址</dt><dd class="mt-1 font-medium text-gray-900">{{ $customer->address ?: '未填写' }}</dd></div>
                        <div>
                            <dt class="text-gray-500">官网</dt>
                            <dd class="mt-1 font-medium text-gray-900">
                                @if ((string) ($customer->website ?? '') !== '')
                                    <a href="{{ \Illuminate\Support\Str::startsWith($customer->website, ['http://','https://']) ? $customer->website : 'https://'.$customer->website }}" target="_blank" rel="noopener" class="text-blue-600 hover:text-blue-700">{{ $customer->website }}</a>
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
                    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <h2 class="text-base font-semibold text-gray-900">销售链条</h2>
                            <p class="mt-1 text-sm text-gray-500">从询盘、商机、单据到订单和售后，集中查看这个客户的业务进展。</p>
                        </div>
                        <a href="{{ route('admin.crm.opportunities.create') }}" class="inline-flex w-fit items-center rounded-md border border-emerald-200 bg-emerald-50 px-3 py-1.5 text-xs font-medium text-emerald-700 hover:bg-emerald-100">
                            <i data-lucide="briefcase-business" class="mr-1.5 h-4 w-4"></i>
                            新增商机
                        </a>
                    </div>

                    <div class="mt-5 grid gap-6 lg:grid-cols-2">
                        <div>
                            <div class="flex items-center justify-between border-b border-gray-100 pb-2">
                                <h3 class="text-sm font-semibold text-gray-800">商机</h3>
                                <span class="text-xs text-gray-500">{{ $customer->opportunities->count() }} 条</span>
                            </div>
                            <div class="divide-y divide-gray-100">
                                @forelse ($customer->opportunities as $opportunity)
                                    <a href="{{ route('admin.crm.opportunities.edit', ['opportunityId' => (int) $opportunity->id]) }}" class="block py-3 text-sm hover:bg-gray-50">
                                        <div class="flex items-center justify-between gap-3">
                                            <span class="font-medium text-gray-900">{{ $opportunity->name }}</span>
                                            <span class="shrink-0 rounded-full bg-emerald-50 px-2 py-0.5 text-xs text-emerald-700">{{ $opportunityStageLabels[$opportunity->stage] ?? $opportunity->stage }}</span>
                                        </div>
                                        <div class="mt-1 text-xs text-gray-500">{{ $opportunity->currency ?: 'USD' }} {{ number_format((float) $opportunity->amount, 2) }} · {{ (int) $opportunity->probability }}%@if($opportunity->sourceInquiry) · 来源：{{ $opportunity->sourceInquiry->subject }}@endif</div>
                                    </a>
                                @empty
                                    <div class="py-4 text-sm text-gray-500">暂无商机</div>
                                @endforelse
                            </div>
                        </div>

                        <div>
                            <div class="flex items-center justify-between border-b border-gray-100 pb-2">
                                <h3 class="text-sm font-semibold text-gray-800">询盘</h3>
                                <span class="text-xs text-gray-500">{{ $customer->inquiries->count() }} 条</span>
                            </div>
                            <div class="divide-y divide-gray-100">
                                @forelse ($customer->inquiries as $inquiry)
                                    <a href="{{ route('admin.crm.inquiries.show', ['inquiryId' => (int) $inquiry->id]) }}" class="block py-3 text-sm hover:bg-gray-50">
                                        <div class="font-medium text-gray-900">{{ $inquiry->subject }}</div>
                                        <div class="mt-1 text-xs text-gray-500">{{ $inquiry->status }} · {{ $inquiry->priority }} · {{ $inquiry->created_at?->format('Y-m-d') }}</div>
                                    </a>
                                @empty
                                    <div class="py-4 text-sm text-gray-500">暂无询盘</div>
                                @endforelse
                            </div>
                        </div>

                        <div>
                            <div class="flex items-center justify-between border-b border-gray-100 pb-2">
                                <h3 class="text-sm font-semibold text-gray-800">单据</h3>
                                <span class="text-xs text-gray-500">{{ $customer->quotes->count() }} 份</span>
                            </div>
                            <div class="divide-y divide-gray-100">
                                @forelse ($customer->quotes as $quote)
                                    <a href="{{ route('admin.crm.quotes.show', ['quoteId' => (int) $quote->id]) }}" class="block py-3 text-sm hover:bg-gray-50">
                                        <div class="flex items-center justify-between gap-3">
                                            <span class="font-medium text-gray-900">{{ $quote->quote_no ?: $quote->title }}</span>
                                            <span class="shrink-0 text-xs text-gray-500">{{ $quote->document_type ?: 'quotation' }}</span>
                                        </div>
                                        <div class="mt-1 text-xs text-gray-500">{{ $quote->currency ?: 'USD' }} {{ number_format((float) ($quote->grand_total ?: $quote->total_amount), 2) }} · {{ $quote->status ?: 'draft' }}@if($quote->opportunity) · 商机：{{ $quote->opportunity->name }}@endif</div>
                                    </a>
                                @empty
                                    <div class="py-4 text-sm text-gray-500">暂无单据</div>
                                @endforelse
                            </div>
                        </div>

                        <div>
                            <div class="flex items-center justify-between border-b border-gray-100 pb-2">
                                <h3 class="text-sm font-semibold text-gray-800">订单</h3>
                                <span class="text-xs text-gray-500">{{ $customer->salesOrders->count() }} 条</span>
                            </div>
                            <div class="divide-y divide-gray-100">
                                @forelse ($customer->salesOrders as $order)
                                    <a href="{{ route('admin.crm.orders.show', ['orderId' => (int) $order->id]) }}" class="block py-3 text-sm hover:bg-gray-50">
                                        <div class="font-medium text-gray-900">{{ $order->order_no ?: $order->title }}</div>
                                        <div class="mt-1 text-xs text-gray-500">{{ $order->currency ?: 'USD' }} {{ number_format((float) $order->total_amount, 2) }} · {{ $order->order_status ?: 'open' }}</div>
                                    </a>
                                @empty
                                    <div class="py-4 text-sm text-gray-500">暂无订单</div>
                                @endforelse
                            </div>
                        </div>

                        <div class="lg:col-span-2">
                            <div class="flex items-center justify-between border-b border-gray-100 pb-2">
                                <h3 class="text-sm font-semibold text-gray-800">售后工单</h3>
                                <span class="text-xs text-gray-500">{{ $customer->afterSalesTickets->count() }} 条</span>
                            </div>
                            <div class="divide-y divide-gray-100">
                                @forelse ($customer->afterSalesTickets as $ticket)
                                    <a href="{{ route('admin.crm.tickets.show', ['ticketId' => (int) $ticket->id]) }}" class="block py-3 text-sm hover:bg-gray-50">
                                        <div class="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
                                            <span class="font-medium text-gray-900">{{ $ticket->title }}</span>
                                            <span class="text-xs text-gray-500">{{ $ticket->priority ?: 'normal' }} · {{ $ticket->status ?: 'open' }}</span>
                                        </div>
                                        <div class="mt-1 text-xs text-gray-500">@if($ticket->order)订单：{{ $ticket->order->order_no }}@else 未关联订单 @endif @if($ticket->entity) · {{ $ticket->entity->name }}@endif</div>
                                    </a>
                                @empty
                                    <div class="py-4 text-sm text-gray-500">暂无售后工单</div>
                                @endforelse
                            </div>
                        </div>
                    </div>
                </section>

                <section class="rounded-lg bg-white p-6 shadow-sm ring-1 ring-gray-200">
                    <div class="flex items-center justify-between gap-3">
                        <div>
                            <h2 class="text-base font-semibold text-gray-900">客户联系人</h2>
                            <p class="mt-1 text-sm text-gray-500">同一家公司可维护多个外部联系人，主联系人用于单据默认信息。</p>
                        </div>
                        <span class="text-sm text-gray-500">{{ $customer->contacts->count() }} 人</span>
                    </div>
                    <div class="mt-4 grid gap-4 lg:grid-cols-2">
                        @foreach($customer->contacts as $contact)
                            <div class="rounded-md border border-gray-200 p-4">
                                <div class="flex items-start justify-between gap-3">
                                    <div>
                                        <div class="font-semibold text-gray-900">{{ $contact->name }} @if($contact->is_primary)<span class="ml-1 rounded-full bg-blue-50 px-2 py-0.5 text-xs text-blue-700">主联系人</span>@endif</div>
                                        <div class="mt-1 text-sm text-gray-500">{{ $contact->title ?: '未填写职位' }}{{ $contact->department ? ' · '.$contact->department : '' }}</div>
                                    </div>
                                    @unless($contact->is_primary)
                                        <form method="POST" action="{{ route('admin.crm.customers.contacts.primary',['customerId'=>$customer->id,'contactId'=>$contact->id]) }}">
                                            @csrf
                                            <button class="text-xs font-medium text-blue-600">设为主联系人</button>
                                        </form>
                                    @endunless
                                </div>
                                <div class="mt-3 space-y-1 text-sm text-gray-600">
                                    <div>{{ $contact->phone ?: '未填写电话' }}</div>
                                    <div>{{ $contact->email ?: '未填写邮箱' }}</div>
                                </div>
                                <details class="mt-3 border-t border-gray-100 pt-3">
                                    <summary class="cursor-pointer text-xs font-medium text-gray-500 hover:text-gray-700">编辑联系人</summary>
                                    <form method="POST" action="{{ route('admin.crm.customers.contacts.update',['customerId'=>$customer->id,'contactId'=>$contact->id]) }}" class="mt-3 grid gap-2 sm:grid-cols-2">
                                        @csrf
                                        @method('PUT')
                                        <input name="name" required value="{{ $contact->name }}" class="{{ $inputClass }}">
                                        <input name="title" value="{{ $contact->title }}" placeholder="职位" class="{{ $inputClass }}">
                                        <input name="department" value="{{ $contact->department }}" placeholder="部门" class="{{ $inputClass }}">
                                        <input name="phone" value="{{ $contact->phone }}" placeholder="电话" class="{{ $inputClass }}">
                                        <input type="email" name="email" value="{{ $contact->email }}" placeholder="邮箱" class="{{ $inputClass }}">
                                        <div class="sm:col-span-2"><button class="rounded-md bg-gray-900 px-3 py-2 text-xs font-semibold text-white">保存</button></div>
                                    </form>
                                    <form method="POST" action="{{ route('admin.crm.customers.contacts.delete',['customerId'=>$customer->id,'contactId'=>$contact->id]) }}" onsubmit="return confirm('确认归档此联系人？')" class="mt-2">
                                        @csrf
                                        <button class="rounded-md border border-red-200 px-3 py-2 text-xs font-semibold text-red-700">归档联系人</button>
                                    </form>
                                </details>
                            </div>
                        @endforeach
                    </div>
                    <details class="mt-5 rounded-md border border-gray-200">
                        <summary class="cursor-pointer px-4 py-3 text-sm font-semibold text-gray-700">添加联系人</summary>
                        <form method="POST" action="{{ route('admin.crm.customers.contacts.store',['customerId'=>$customer->id]) }}" class="grid gap-3 border-t border-gray-100 p-4 md:grid-cols-2">
                            @csrf
                            <input name="name" required placeholder="姓名 *" class="{{ $inputClass }}">
                            <input name="title" placeholder="职位" class="{{ $inputClass }}">
                            <input name="department" placeholder="部门" class="{{ $inputClass }}">
                            <input name="phone" placeholder="电话" class="{{ $inputClass }}">
                            <input type="email" name="email" placeholder="邮箱" class="{{ $inputClass }}">
                            <label class="flex items-center gap-2 text-sm text-gray-700"><input type="checkbox" name="is_primary" value="1" class="rounded border-gray-300">设为主联系人</label>
                            <div class="md:col-span-2"><button class="rounded-md bg-gray-900 px-4 py-2 text-sm font-semibold text-white">添加联系人</button></div>
                        </form>
                    </details>
                </section>
            </div>

            <aside class="space-y-6">
                <section class="rounded-lg bg-white p-6 shadow-sm ring-1 ring-gray-200">
                    <h2 class="text-base font-semibold text-gray-900"><i data-lucide="message-square-text" class="mr-2 inline-block h-4 w-4 text-gray-500"></i>活动记录</h2>
                    <p class="mt-1 text-sm text-gray-500">这里记录已经发生的沟通；未来要做的事请放到待办。</p>
                    <form method="POST" action="{{ route('admin.crm.customers.follow-ups.store', ['customerId' => (int) $customer->id]) }}" class="mt-4 space-y-3">
                        @csrf
                        @include('admin.crm.partials._markdown-editor', ['fieldName' => 'content', 'rows' => 4, 'placeholder' => '跟进内容（支持 Markdown）'])
                        <div class="grid gap-3 sm:grid-cols-2">
                            @include('admin.crm.partials._activity-type-select')
                            <input type="text" name="followup_type" maxlength="80" placeholder="补充备注（可选）" class="block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500">
                        </div>
                        @if ($customer->inquiries->isNotEmpty())
                            <select name="inquiry_id" class="block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500">
                                <option value="">不关联询盘</option>
                                @foreach ($customer->inquiries as $inquiryOption)
                                    <option value="{{ (int) $inquiryOption->id }}">{{ $inquiryOption->subject }} · {{ $inquiryOption->created_at?->format('Y-m-d') }}</option>
                                @endforeach
                            </select>
                        @endif
                        @include('admin.crm.partials._activity-next-task-fields')
                        <input type="hidden" name="owner" value="{{ $customer->owner }}">
                        <button type="submit" class="inline-flex w-full items-center justify-center rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800">记录活动</button>
                    </form>
                    <div class="mt-5 space-y-3">
                        @forelse ($customer->followUps as $followUp)
                            @include('admin.crm.partials._follow-up-item', ['followUp' => $followUp, 'showInquiryLink' => true, 'editable' => true])
                        @empty
                            <div class="text-sm text-gray-500">暂无活动记录</div>
                        @endforelse
                    </div>
                </section>

                <section class="rounded-lg bg-white p-6 shadow-sm ring-1 ring-gray-200">
                    <div class="flex items-center justify-between gap-3">
                        <div>
                            <h2 class="text-base font-semibold text-gray-900">客户待办</h2>
                            <p class="mt-1 text-sm text-gray-500">安排回访、发送资料、准备方案等未来动作。</p>
                        </div>
                        <span class="rounded-full bg-gray-100 px-2.5 py-1 text-xs text-gray-500">{{ $openTaskCount }} 个未完成</span>
                    </div>
                    <div class="mt-4">@include('admin.crm.partials.task-form',['customer_id'=>$customer->id])</div>
                    <div class="mt-5 divide-y divide-gray-100 rounded-md border border-gray-200">
                        @forelse($customer->crmTasks as $task)
                            @include('admin.crm.partials.task-row',['task'=>$task])
                        @empty
                            <div class="p-8 text-center text-sm text-gray-500">暂无待办</div>
                        @endforelse
                    </div>
                </section>
            </aside>
        </div>
    </div>
@endsection
