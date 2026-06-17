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
                <form method="POST" action="{{ route('admin.crm.tickets.delete', ['ticketId' => (int) $ticket->id]) }}" onsubmit="return confirm('确认归档此售后工单？')">
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

                <section class="rounded-lg bg-white p-6 shadow-sm ring-1 ring-gray-200">
                    <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                        <div>
                            <h2 class="text-base font-semibold text-gray-900">知识纠错候选</h2>
                            <p class="mt-1 text-sm leading-6 text-gray-600">把这个售后问题提交为知识库纠错建议。系统只生成待审核记录，不会直接覆盖知识库内容。</p>
                        </div>
                        @if($ticket->knowledgeBases->isNotEmpty())
                            <a href="{{ route('admin.knowledge-corrections.index', ['knowledge_base_id' => (int) $ticket->knowledgeBases->first()->id]) }}" class="inline-flex h-9 items-center rounded-md border border-gray-300 bg-white px-3 text-sm font-medium text-gray-700 hover:bg-gray-50">
                                <i data-lucide="list-checks" class="mr-2 h-4 w-4"></i>
                                纠错记录
                            </a>
                        @endif
                    </div>

                    @if($ticket->knowledgeBases->isEmpty())
                        <div class="mt-4 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm leading-6 text-amber-800">
                            该工单还没有关联知识库。请先编辑工单并关联目标知识库，再从这里发起纠错建议。
                        </div>
                    @else
                        @php
                            $defaultCorrectionDescription = trim(implode("\n\n", array_filter([
                                '来源售后工单 #'.(int) $ticket->id.'：'.(string) $ticket->title,
                                (string) ($ticket->issue_description ?? '') !== '' ? '客户问题：'.(string) $ticket->issue_description : '',
                                (string) ($ticket->reply_points ?? '') !== '' ? '建议回复：'.(string) $ticket->reply_points : '',
                                (string) ($ticket->resolution ?? '') !== '' ? '处理结果：'.(string) $ticket->resolution : '',
                                '请检查关联知识库中是否存在过时、不完整或错误的说明，并生成待审核纠错建议。',
                            ])));
                            $inputClass = 'block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-orange-500 focus:ring-orange-500';
                        @endphp
                        <form method="POST" action="{{ route('admin.knowledge-corrections.store') }}" class="mt-5 space-y-4 rounded-lg border border-orange-100 bg-orange-50/60 p-4">
                            @csrf
                            <input type="hidden" name="source_type" value="knowledge_base">

                            <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
                                <div>
                                    <label class="block text-sm font-semibold text-gray-800">目标知识库</label>
                                    <select name="knowledge_base_id" required class="{{ $inputClass }}">
                                        @foreach($ticket->knowledgeBases as $knowledgeBase)
                                            <option value="{{ (int) $knowledgeBase->id }}">{{ $knowledgeBase->name }}</option>
                                        @endforeach
                                    </select>
                                    <p class="mt-2 text-xs leading-5 text-gray-500">只允许选择当前工单已关联的知识库，避免把售后问题提交到错误来源。</p>
                                </div>
                                <div>
                                    <label class="block text-sm font-semibold text-gray-800">AI 模型</label>
                                    <select name="ai_model_id" class="{{ $inputClass }}">
                                        <option value="0">自动选择模型</option>
                                        @foreach(($aiModelOptions ?? []) as $modelOption)
                                            <option value="{{ (int) ($modelOption['id'] ?? 0) }}">{{ (string) ($modelOption['name'] ?? '') }}</option>
                                        @endforeach
                                    </select>
                                    <p class="mt-2 text-xs leading-5 text-gray-500">模型不可用时会生成安全回退提案，仍需人工审核。</p>
                                </div>
                            </div>

                            <div>
                                <label class="block text-sm font-semibold text-gray-800">纠错说明 *</label>
                                <textarea name="error_description" rows="6" required class="{{ $inputClass }}" placeholder="描述这个工单暴露出的知识库问题">{{ old('error_description', $defaultCorrectionDescription) }}</textarea>
                            </div>

                            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                <p class="text-xs leading-5 text-gray-500">提交后会进入知识库纠错助手，管理员确认后才会应用到知识片段。</p>
                                <button type="submit" class="inline-flex h-10 items-center justify-center rounded-md border border-transparent bg-orange-600 px-4 text-sm font-medium text-white hover:bg-orange-700">
                                    <i data-lucide="sparkles" class="mr-2 h-4 w-4"></i>
                                    发起知识纠错
                                </button>
                            </div>
                        </form>
                    @endif
                </section>
            </div>

            @if ($ticket->order?->inquiry?->customer?->followUps?->isNotEmpty())
                <section class="rounded-lg bg-white p-6 shadow-sm ring-1 ring-gray-200">
                    <h2 class="text-base font-semibold text-gray-900"><i data-lucide="message-square-text" class="mr-2 inline-block h-4 w-4 text-gray-500"></i>活动记录</h2>
                    <div class="mt-4 space-y-3">
                        @foreach ($ticket->order->inquiry->customer->followUps as $followUp)
                            @include('admin.crm.partials._follow-up-item', ['followUp' => $followUp, 'showInquiryLink' => true])
                        @endforeach
                    </div>
                </section>
                @endif

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
