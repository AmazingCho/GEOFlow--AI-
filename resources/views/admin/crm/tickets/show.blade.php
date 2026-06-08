@extends('admin.layouts.app')

@section('content')
    <div class="px-4 sm:px-0">
        <div class="mb-8 flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
            <div>
                <div class="flex items-center gap-3">
                    <a href="{{ route('admin.crm.tickets.index') }}" class="text-gray-400 hover:text-gray-600">
                        <i data-lucide="arrow-left" class="h-5 w-5"></i>
                    </a>
                    <h1 class="text-2xl font-bold text-gray-900">{{ $ticket->title }}</h1>
                </div>
                <p class="mt-2 text-sm text-gray-600">{{ $ticket->collection?->name ?? '未指定业务容器' }} · {{ $ticket->status }} · {{ $ticket->priority }}</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <form method="POST" action="{{ route('admin.crm.proposals.from-ticket', ['ticketId' => (int) $ticket->id]) }}">
                    @csrf
                    <input type="hidden" name="proposal_type" value="faq_draft">
                    <button type="submit" class="inline-flex items-center rounded-md border border-orange-200 bg-orange-50 px-4 py-2 text-sm font-medium text-orange-700 hover:bg-orange-100">
                        <i data-lucide="file-question" class="mr-2 h-4 w-4"></i>
                        生成 FAQ 草稿
                    </button>
                </form>
                <form method="POST" action="{{ route('admin.crm.proposals.from-ticket', ['ticketId' => (int) $ticket->id]) }}">
                    @csrf
                    <input type="hidden" name="proposal_type" value="case_draft">
                    <button type="submit" class="inline-flex items-center rounded-md border border-emerald-200 bg-emerald-50 px-4 py-2 text-sm font-medium text-emerald-700 hover:bg-emerald-100">
                        <i data-lucide="briefcase-business" class="mr-2 h-4 w-4"></i>
                        生成 Case 草稿
                    </button>
                </form>
                                <a href="{{ route('admin.crm.tickets.edit', ['ticketId' => (int) $ticket->id]) }}" class="inline-flex items-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                    <i data-lucide="pencil" class="mr-2 h-4 w-4"></i>
                    编辑
                </a>
                <form method="POST" action="{{ route('admin.crm.tickets.delete', ['ticketId' => (int) $ticket->id]) }}" onsubmit="return confirm('确认删除此售后工单？')">
                    @csrf
                    <button type="submit" class="inline-flex items-center rounded-md border border-red-200 bg-red-50 px-4 py-2 text-sm font-medium text-red-700 hover:bg-red-100">
                        <i data-lucide="trash-2" class="mr-2 h-4 w-4"></i>
                        删除
                    </button>
                </form>
            </div>
        </div>

        @include('admin.crm.partials.nav', ['currentCrmTab' => 'tickets'])

        <div class="grid grid-cols-1 gap-6 xl:grid-cols-[minmax(0,1fr)_380px]">
            <div class="space-y-6">
                <section class="rounded-lg bg-white p-6 shadow-sm ring-1 ring-gray-200">
                    <h2 class="text-base font-semibold text-gray-900">问题与处理</h2>
                    <div class="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-2">
                        <div class="rounded-md border border-gray-200 bg-gray-50 px-4 py-3">
                            <div class="text-xs font-medium uppercase tracking-wider text-gray-500">问题描述</div>
                            <div class="mt-2 whitespace-pre-wrap text-sm leading-6 text-gray-700">{{ $ticket->issue_description ?: '暂无' }}</div>
                        </div>
                        <div class="rounded-md border border-gray-200 bg-gray-50 px-4 py-3">
                            <div class="text-xs font-medium uppercase tracking-wider text-gray-500">建议回复要点</div>
                            <div class="mt-2 whitespace-pre-wrap text-sm leading-6 text-gray-700">{{ $ticket->reply_points ?: '暂无' }}</div>
                        </div>
                        <div class="rounded-md border border-gray-200 bg-gray-50 px-4 py-3">
                            <div class="text-xs font-medium uppercase tracking-wider text-gray-500">需补充问题</div>
                            <div class="mt-2 whitespace-pre-wrap text-sm leading-6 text-gray-700">{{ $ticket->missing_information_questions ?: '暂无' }}</div>
                        </div>
                        <div class="rounded-md border border-gray-200 bg-gray-50 px-4 py-3">
                            <div class="text-xs font-medium uppercase tracking-wider text-gray-500">解决方案</div>
                            <div class="mt-2 whitespace-pre-wrap text-sm leading-6 text-gray-700">{{ $ticket->resolution ?: '暂无' }}</div>
                        </div>
                    </div>
                </section>

                <section class="rounded-lg bg-white p-6 shadow-sm ring-1 ring-gray-200">
                    <h2 class="text-base font-semibold text-gray-900">关联参考资料</h2>
                    <div class="mt-4 grid grid-cols-1 gap-5 lg:grid-cols-2">
                        <div>
                            <h3 class="text-sm font-medium text-gray-700">知识库</h3>
                            <div class="mt-2 space-y-2">
                                @forelse ($ticket->knowledgeBases as $knowledgeBase)
                                    <a href="{{ route('admin.knowledge-bases.detail', ['knowledgeBaseId' => (int) $knowledgeBase->id]) }}" class="block rounded-md border border-orange-100 bg-orange-50 px-3 py-2 text-sm text-orange-800 hover:bg-orange-100">{{ $knowledgeBase->name }}</a>
                                @empty
                                    <span class="text-sm text-gray-500">暂无关联</span>
                                @endforelse
                            </div>
                        </div>
                        <div>
                            <h3 class="text-sm font-medium text-gray-700">Case</h3>
                            <div class="mt-2 space-y-2">
                                @forelse ($ticket->cases as $caseRecord)
                                    <a href="{{ route('admin.cases.edit', ['caseId' => (int) $caseRecord->id]) }}" class="block rounded-md border border-emerald-100 bg-emerald-50 px-3 py-2 text-sm text-emerald-800 hover:bg-emerald-100">{{ $caseRecord->title }}</a>
                                @empty
                                    <span class="text-sm text-gray-500">暂无关联</span>
                                @endforelse
                            </div>
                        </div>
                    </div>
                </section>
            </div>

            <aside class="space-y-6">
                <section class="rounded-lg bg-white p-6 shadow-sm ring-1 ring-gray-200">
                    <h2 class="text-base font-semibold text-gray-900">基础信息</h2>
                    <dl class="mt-4 space-y-3 text-sm">
                        <div><dt class="text-gray-500">客户</dt><dd class="mt-1 font-medium text-gray-900">{{ $ticket->customer?->contact_person ?: $ticket->customer?->company_name ?? '-' }}</dd></div>
                        <div><dt class="text-gray-500">负责人</dt><dd class="mt-1 font-medium text-gray-900">{{ $ticket->owner ?: '未指定' }}</dd></div>
                        <div><dt class="text-gray-500">订单</dt><dd class="mt-1 font-medium text-gray-900">@if($ticket->order)<a href="{{ route('admin.crm.orders.show', ['orderId' => (int) $ticket->order->id]) }}" class="text-blue-600 hover:text-blue-700">{{ $ticket->order->order_no }}</a>@else 未关联 @endif</dd></div>
                        <div><dt class="text-gray-500">Entity</dt><dd class="mt-1 font-medium text-gray-900">{{ $ticket->entity?->name ?? '未关联' }}</dd></div>
                        <div><dt class="text-gray-500">问题类型</dt><dd class="mt-1 font-medium text-gray-900">{{ $ticket->issue_type ?: '未设置' }}</dd></div>
                        <div><dt class="text-gray-500">创建时间</dt><dd class="mt-1 font-medium text-gray-900">{{ $ticket->created_at?->format('Y-m-d H:i') }}</dd></div>
                    </dl>
                </section>

                <section class="rounded-lg bg-white p-6 shadow-sm ring-1 ring-gray-200">
                    <h2 class="text-base font-semibold text-gray-900">内部备注</h2>
                    <div class="mt-4 whitespace-pre-wrap rounded-md border border-gray-200 bg-gray-50 px-4 py-3 text-sm leading-6 text-gray-700">{{ $ticket->notes ?: '暂无备注' }}</div>
                </section>
            </aside>
        </div>
    </div>
@endsection
