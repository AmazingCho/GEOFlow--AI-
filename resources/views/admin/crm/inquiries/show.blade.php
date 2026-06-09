@extends('admin.layouts.app')

@section('content')
    <div class="px-4 sm:px-0">
        <div class="mb-8 flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
            <div>
                <div class="flex items-center gap-3">
                    <a href="{{ route('admin.crm.inquiries.index') }}" class="text-gray-400 hover:text-gray-600">
                        <i data-lucide="arrow-left" class="h-5 w-5"></i>
                    </a>
                    <h1 class="text-2xl font-bold text-gray-900">{{ $inquiry->subject }}</h1>
                </div>
                <p class="mt-2 text-sm text-gray-600">{{ $inquiry->collection?->name ?? '未指定业务容器' }} · {{ $inquiry->status }} · {{ $inquiry->priority }}</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <form method="POST" action="{{ route('admin.crm.proposals.from-inquiry', ['inquiryId' => (int) $inquiry->id]) }}">
                    @csrf
                    <input type="hidden" name="proposal_type" value="title_suggestion">
                    <button type="submit" class="inline-flex items-center rounded-md border border-blue-200 bg-blue-50 px-4 py-2 text-sm font-medium text-blue-700 hover:bg-blue-100">
                        <i data-lucide="type" class="mr-2 h-4 w-4"></i>
                        生成标题候选
                    </button>
                </form>
                <form method="POST" action="{{ route('admin.crm.proposals.from-inquiry', ['inquiryId' => (int) $inquiry->id]) }}">
                    @csrf
                    <input type="hidden" name="proposal_type" value="faq_draft">
                    <button type="submit" class="inline-flex items-center rounded-md border border-orange-200 bg-orange-50 px-4 py-2 text-sm font-medium text-orange-700 hover:bg-orange-100">
                        <i data-lucide="file-question" class="mr-2 h-4 w-4"></i>
                        生成 FAQ 候选
                    </button>
                </form>
                <a href="{{ route('admin.crm.quotes.create', ['inquiry_id' => (int) $inquiry->id]) }}" class="inline-flex items-center rounded-md border border-purple-200 bg-purple-50 px-4 py-2 text-sm font-medium text-purple-700 hover:bg-purple-100">
                    <i data-lucide="file-plus-2" class="mr-2 h-4 w-4"></i>
                    生成报价
                </a>
                <a href="{{ route('admin.crm.inquiries.edit', ['inquiryId' => (int) $inquiry->id]) }}" class="inline-flex items-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                    <i data-lucide="pencil" class="mr-2 h-4 w-4"></i>
                    编辑
                </a>
                <form method="POST" action="{{ route('admin.crm.inquiries.delete', ['inquiryId' => (int) $inquiry->id]) }}" onsubmit="return confirm('确认删除此询盘？')">
                    @csrf
                    <button type="submit" class="inline-flex items-center rounded-md border border-red-200 bg-red-50 px-4 py-2 text-sm font-medium text-red-700 hover:bg-red-100">
                        <i data-lucide="trash-2" class="mr-2 h-4 w-4"></i>
                        删除
                    </button>
                </form>
            </div>
        </div>

        @include('admin.crm.partials.nav', ['currentCrmTab' => 'inquiries'])

        <div class="grid grid-cols-1 gap-6 xl:grid-cols-[minmax(0,1fr)_420px]">
            <div class="space-y-6">
                <section class="rounded-lg bg-white p-6 shadow-sm ring-1 ring-gray-200">
                    <h2 class="text-base font-semibold text-gray-900">需求识别结果</h2>
                    <dl class="mt-4 grid grid-cols-1 gap-4 text-sm md:grid-cols-3">
                        <div><dt class="text-gray-500">客户</dt><dd class="mt-1 font-medium text-gray-900">{{ $inquiry->customer?->contact_person ?: $inquiry->customer?->company_name ?? '未关联' }}</dd></div>
                        <div><dt class="text-gray-500">负责人</dt><dd class="mt-1 font-medium text-gray-900">{{ $inquiry->assigned_to ?: '未指定' }}</dd></div>
                        <div><dt class="text-gray-500">语言</dt><dd class="mt-1 font-medium text-gray-900">{{ $inquiry->detected_language ?: '未识别' }}</dd></div>
                    </dl>
                    <div class="mt-5 grid grid-cols-1 gap-4 lg:grid-cols-2">
                        <div class="rounded-md border border-gray-200 bg-gray-50 px-4 py-3">
                            <div class="text-xs font-medium uppercase tracking-wider text-gray-500">需求摘要</div>
                            <div class="mt-2 whitespace-pre-wrap text-sm leading-6 text-gray-700">{{ $inquiry->customer_need_summary ?: '暂无摘要' }}</div>
                        </div>
                        <div class="rounded-md border border-gray-200 bg-gray-50 px-4 py-3">
                            <div class="text-xs font-medium uppercase tracking-wider text-gray-500">产品兴趣</div>
                            <div class="mt-2 whitespace-pre-wrap text-sm leading-6 text-gray-700">{{ $inquiry->product_interest ?: '暂无' }}</div>
                        </div>
                        <div class="rounded-md border border-gray-200 bg-gray-50 px-4 py-3">
                            <div class="text-xs font-medium uppercase tracking-wider text-gray-500">建议回复要点</div>
                            <div class="mt-2 whitespace-pre-wrap text-sm leading-6 text-gray-700">{{ $inquiry->suggested_reply_points ?: '暂无' }}</div>
                        </div>
                        <div class="rounded-md border border-gray-200 bg-gray-50 px-4 py-3">
                            <div class="text-xs font-medium uppercase tracking-wider text-gray-500">需补充问题</div>
                            <div class="mt-2 whitespace-pre-wrap text-sm leading-6 text-gray-700">{{ $inquiry->missing_information_questions ?: '暂无' }}</div>
                        </div>
                    </div>
                </section>

                <section class="rounded-lg bg-white p-6 shadow-sm ring-1 ring-gray-200">
                    <h2 class="text-base font-semibold text-gray-900">原始询盘内容</h2>
                    <div class="mt-4 whitespace-pre-wrap rounded-md border border-gray-200 bg-gray-50 px-4 py-3 text-sm leading-6 text-gray-700">{{ $inquiry->raw_message ?: '暂无原文' }}</div>
                </section>

                <section class="rounded-lg bg-white p-6 shadow-sm ring-1 ring-gray-200">
                    <h2 class="text-base font-semibold text-gray-900">报价记录</h2>
                    <div class="mt-4 space-y-2">
                        @forelse ($inquiry->quotes as $quote)
                            <a href="{{ route('admin.crm.quotes.show', ['quoteId' => (int) $quote->id]) }}" class="flex items-center justify-between rounded-md border border-gray-200 px-4 py-3 text-sm hover:bg-gray-50">
                                <span class="font-medium text-gray-900">{{ $quote->quote_no }} · {{ $quote->title }}</span>
                                <span class="text-gray-500">{{ $quote->currency }} {{ number_format((float) $quote->total_amount, 2) }}</span>
                            </a>
                        @empty
                            <div class="text-sm text-gray-500">暂无报价</div>
                        @endforelse
                    </div>
                </section>

                <section class="rounded-lg bg-white p-6 shadow-sm ring-1 ring-gray-200">
                    <h2 class="text-base font-semibold text-gray-900">订单记录</h2>
                    <div class="mt-4 space-y-2">
                        @forelse ($inquiry->salesOrders as $order)
                            <a href="{{ route('admin.crm.orders.show', ['orderId' => (int) $order->id]) }}" class="flex items-center justify-between rounded-md border border-gray-200 px-4 py-3 text-sm hover:bg-gray-50">
                                <span class="font-medium text-gray-900">{{ $order->order_no }} · {{ $order->title }}</span>
                                <span class="text-gray-500">{{ $order->currency }} {{ number_format((float) $order->total_amount, 2) }}</span>
                            </a>
                        @empty
                            <div class="text-sm text-gray-500">暂无订单</div>
                        @endforelse
                    </div>
                </section>
                <section class="rounded-lg bg-white p-6 shadow-sm ring-1 ring-gray-200">
                    <h2 class="text-base font-semibold text-gray-900"><i data-lucide="message-square-text" class="mr-2 inline-block h-4 w-4 text-gray-500"></i>跟进记录</h2>
                    <form method="POST" action="{{ route('admin.crm.inquiries.follow-ups.store', ['inquiryId' => (int) $inquiry->id]) }}" class="mt-4 space-y-3">
                        @csrf
                        @include('admin.crm.partials._markdown-editor', ['fieldName' => 'content', 'rows' => 4, 'placeholder' => '跟进内容（支持 Markdown）'])
                        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                            <input type="text" name="next_action" placeholder="下一步动作（可选）" class="block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            <input type="datetime-local" name="next_followup_at" class="block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                        </div>
                        <input type="hidden" name="owner" value="{{ $inquiry->assigned_to ?? '' }}">
                        <button type="submit" class="inline-flex w-full items-center justify-center rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800">添加跟进</button>
                    </form>
                    <div class="mt-5 space-y-3">
                        @forelse ($inquiry->customer?->followUps ?? [] as $followUp)
                            @include('admin.crm.partials._follow-up-item', ['followUp' => $followUp, 'showInquiryLink' => true])
                        @empty
                            <div class="text-sm text-gray-500">暂无跟进记录</div>
                        @endforelse
                    </div>
                </section>
            </div>

            <aside class="space-y-6">
                <section class="rounded-lg bg-white p-6 shadow-sm ring-1 ring-gray-200">
                    <h2 class="text-base font-semibold text-gray-900">推荐引用资料</h2>
                    <div class="mt-4 space-y-5">
                        <div>
                            <h3 class="text-sm font-medium text-gray-700">Entity</h3>
                            <div class="mt-2 flex flex-wrap gap-2">
                                @forelse ($inquiry->entities as $entity)
                                    <a href="{{ route('admin.entities.edit', ['entityId' => (int) $entity->id]) }}" class="rounded-full bg-blue-50 px-3 py-1 text-xs font-medium text-blue-700 hover:bg-blue-100">{{ $entity->name }}</a>
                                @empty
                                    <span class="text-sm text-gray-500">暂无关联</span>
                                @endforelse
                            </div>
                        </div>
                        <div>
                            <h3 class="text-sm font-medium text-gray-700">知识库</h3>
                            <div class="mt-2 space-y-2">
                                @forelse ($inquiry->knowledgeBases as $knowledgeBase)
                                    <a href="{{ route('admin.knowledge-bases.detail', ['knowledgeBaseId' => (int) $knowledgeBase->id]) }}" class="block rounded-md border border-orange-100 bg-orange-50 px-3 py-2 text-sm text-orange-800 hover:bg-orange-100">{{ $knowledgeBase->name }}</a>
                                @empty
                                    <span class="text-sm text-gray-500">暂无关联</span>
                                @endforelse
                            </div>
                        </div>
                        <div>
                            <h3 class="text-sm font-medium text-gray-700">Case</h3>
                            <div class="mt-2 space-y-2">
                                @forelse ($inquiry->cases as $caseRecord)
                                    <a href="{{ route('admin.cases.edit', ['caseId' => (int) $caseRecord->id]) }}" class="block rounded-md border border-emerald-100 bg-emerald-50 px-3 py-2 text-sm text-emerald-800 hover:bg-emerald-100">{{ $caseRecord->title }}</a>
                                @empty
                                    <span class="text-sm text-gray-500">暂无关联</span>
                                @endforelse
                            </div>
                        </div>
                    </div>
                </section>

                <section class="rounded-lg bg-white p-6 shadow-sm ring-1 ring-gray-200">
                    <h2 class="text-base font-semibold text-gray-900">标签与备注</h2>
                    <div class="mt-3 flex flex-wrap gap-2">
                        @forelse ($inquiry->tags as $tag)
                            <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-medium text-slate-700">{{ $tag->displayName() }}</span>
                        @empty
                            <span class="text-sm text-gray-500">暂无标签</span>
                        @endforelse
                    </div>
                    @if ((string) ($inquiry->notes ?? '') !== '')
                        <div class="mt-4 whitespace-pre-wrap rounded-md border border-gray-200 bg-gray-50 px-4 py-3 text-sm leading-6 text-gray-700">{{ $inquiry->notes }}</div>
                    @endif
                </section>


            </aside>
        </div>
    </div>
@endsection
