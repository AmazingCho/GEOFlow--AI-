@extends('admin.layouts.app')

@php
    $isEdit = (bool) $opportunity;
    $formAction = $isEdit
        ? route('admin.crm.opportunities.update', ['opportunityId' => (int) $opportunity->id])
        : route('admin.crm.opportunities.store');
    $inputClass = 'block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500';
    $textareaClass = 'block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500';
    $sourcePrimaryContactId = $sourceInquiry?->customer?->contacts?->firstWhere('is_primary', true)?->id
        ?: $sourceInquiry?->customer?->contacts?->first()?->id;
    $currentOpportunityTask = $isEdit
        ? $opportunity->tasks->first(static fn ($task) => (string) $task->status !== 'done')
        : null;
@endphp

@section('content')
    <div class="px-4 sm:px-0">
        <div class="mb-6 flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
            <div>
                <h1 class="text-2xl font-bold text-gray-900">{{ $isEdit ? '编辑商机' : '新增商机' }}</h1>
                <p class="mt-1 text-sm text-gray-500">商机只记录已经确认存在采购可能的机会，用于推进阶段、报价和成交。</p>
            </div>
            <a href="{{ route('admin.crm.opportunities.index') }}" class="inline-flex w-fit items-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">
                <i data-lucide="kanban-square" class="mr-2 h-4 w-4"></i>
                返回管道
            </a>
        </div>

        @include('admin.crm.partials.nav', ['currentCrmTab' => 'opportunities'])

        @if ($errors->any())
            <div class="mb-6 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ $errors->first() }}</div>
        @endif

        <div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_400px]">
            <form method="POST" action="{{ $formAction }}" class="space-y-6">
                @csrf
                @if($isEdit)
                    @method('PUT')
                @endif

                <section class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm" data-opportunity-source>
                    <div class="mb-5 border-b border-gray-100 pb-4">
                        <h2 class="text-base font-semibold text-gray-900">商机来源</h2>
                        <p class="mt-1 text-sm text-gray-500">优先关联已有询盘；只有线下直接确认的项目才使用无来源模式。</p>
                    </div>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <label class="flex cursor-pointer items-start gap-3 rounded-md border border-gray-300 bg-white p-4 hover:border-blue-300">
                            <input type="radio" name="source_mode" value="inquiry" class="mt-0.5 h-4 w-4 border-gray-300 text-blue-600 focus:ring-blue-500" @checked(old('source_mode', $sourceMode) === 'inquiry')>
                            <span><span class="block text-sm font-semibold text-gray-900">从询盘创建</span><span class="mt-1 block text-xs leading-5 text-gray-500">保留客户需求、活动、待办和单据的完整来源链。</span></span>
                        </label>
                        <label class="flex cursor-pointer items-start gap-3 rounded-md border border-gray-300 bg-white p-4 hover:border-blue-300">
                            <input type="radio" name="source_mode" value="direct" class="mt-0.5 h-4 w-4 border-gray-300 text-blue-600 focus:ring-blue-500" @checked(old('source_mode', $sourceMode) === 'direct')>
                            <span><span class="block text-sm font-semibold text-gray-900">无来源直接创建</span><span class="mt-1 block text-xs leading-5 text-gray-500">适用于展会、电话或线下已确认，但系统中没有询盘的项目。</span></span>
                        </label>
                    </div>
                    <div class="mt-5 {{ old('source_mode', $sourceMode) === 'inquiry' ? '' : 'hidden' }}" data-source-inquiry-panel>
                        <label class="block">
                            <span class="mb-1.5 block text-sm font-medium text-gray-700">搜索来源询盘</span>
                            <input type="search" placeholder="输入询盘标题或客户名称" class="{{ $inputClass }}" data-source-inquiry-search>
                        </label>
                        <label class="mt-3 block">
                            <span class="mb-1.5 block text-sm font-medium text-gray-700">来源询盘 <span class="text-red-500">*</span></span>
                            <select name="source_inquiry_id" size="5" class="{{ $inputClass }} min-h-40" data-source-inquiry-select>
                                <option value="">请选择来源询盘</option>
                                @foreach($sourceInquiryOptions as $option)
                                    <option value="{{ $option['id'] }}"
                                            data-search="{{ mb_strtolower($option['label'].' '.$option['customer'], 'UTF-8') }}"
                                            data-customer-id="{{ $option['customer_id'] }}"
                                            data-collection-id="{{ $option['collection_id'] }}"
                                            @selected((int) old('source_inquiry_id', $opportunity?->source_inquiry_id ?: $sourceInquiry?->id) === (int) $option['id'])
                                            @disabled($option['disabled'])>
                                        {{ $option['label'] }} · {{ $option['customer'] }}{{ $option['disabled'] ? ' · 已有关联商机' : '' }}
                                    </option>
                                @endforeach
                            </select>
                        </label>
                        <p class="mt-2 text-xs text-gray-500">编辑商机时只能补关联当前客户的询盘；已有活动商机的询盘不可重复选择。</p>
                    </div>
                </section>

                <section class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm">
                    <div class="mb-5 border-b border-gray-100 pb-4">
                        <h2 class="text-base font-semibold text-gray-900">商机信息</h2>
                        <p class="mt-1 text-sm text-gray-500">金额和概率可先粗略填写，后续推进阶段再更新。</p>
                    </div>
                    <div class="grid gap-5 md:grid-cols-2">
                        <label class="md:col-span-2">
                            <span class="mb-1.5 block text-sm font-medium text-gray-700">商机名称 <span class="text-red-500">*</span></span>
                            <input name="name" required maxlength="200" value="{{ old('name', $opportunity?->name ?: $sourceInquiry?->subject) }}" class="{{ $inputClass }}">
                        </label>
                        <label>
                            <span class="mb-1.5 block text-sm font-medium text-gray-700">客户 <span class="text-red-500">*</span></span>
                            <select name="customer_id" required class="{{ $inputClass }}">
                                @foreach($customers as $customer)
                                    <option value="{{ (int) $customer->id }}" @selected((int) old('customer_id', $selectedCustomerId) === (int) $customer->id)>
                                        {{ $customer->company_name ?: $customer->contact_person }}
                                    </option>
                                @endforeach
                            </select>
                        </label>
                        <label>
                            <span class="mb-1.5 block text-sm font-medium text-gray-700">业务容器</span>
                            <select name="collection_id" class="{{ $inputClass }}">
                                <option value="">未指定</option>
                                @foreach($collectionOptions as $option)
                                    <option value="{{ (int) $option['id'] }}" @selected((int) old('collection_id', $opportunity?->collection_id ?: $sourceInquiry?->collection_id) === (int) $option['id'])>{{ $option['name'] }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label>
                            <span class="mb-1.5 block text-sm font-medium text-gray-700">主要联系人</span>
                            <select name="primary_contact_id" class="{{ $inputClass }}">
                                <option value="">未指定</option>
                                @foreach($customers as $customer)
                                    @foreach($customer->contacts as $contact)
                                        <option value="{{ (int) $contact->id }}" @selected((int) old('primary_contact_id', $opportunity?->primary_contact_id ?: $sourcePrimaryContactId) === (int) $contact->id)>
                                            {{ $customer->company_name }} · {{ $contact->name }}{{ $contact->title ? ' · '.$contact->title : '' }}
                                        </option>
                                    @endforeach
                                @endforeach
                            </select>
                        </label>
                        <label>
                            <span class="mb-1.5 block text-sm font-medium text-gray-700">负责人</span>
                            <select name="owner_admin_id" class="{{ $inputClass }}">
                                <option value="">未指定</option>
                                @foreach($employeeOptions as $id => $label)
                                    <option value="{{ (int) $id }}" @selected((int) old('owner_admin_id', $opportunity?->owner_admin_id) === (int) $id)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label>
                            <span class="mb-1.5 block text-sm font-medium text-gray-700">阶段</span>
                            <select name="stage" class="{{ $inputClass }}">
                                @foreach($stages as $key => $label)
                                    <option value="{{ $key }}" @selected(old('stage', $opportunity?->stage ?: 'qualified') === $key)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label>
                            <span class="mb-1.5 block text-sm font-medium text-gray-700">金额</span>
                            <div class="flex">
                                <select name="currency" class="w-24 rounded-l-md border border-r-0 border-gray-300 px-2 py-2 text-sm outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500">
                                    @foreach(['USD', 'EUR', 'CNY'] as $currency)
                                        <option value="{{ $currency }}" @selected(old('currency', $opportunity?->currency ?: 'USD') === $currency)>{{ $currency }}</option>
                                    @endforeach
                                </select>
                                <input type="number" step="0.01" min="0" name="amount" value="{{ old('amount', $opportunity?->amount ?: 0) }}" class="min-w-0 flex-1 rounded-r-md border border-gray-300 px-3 py-2 text-sm outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500">
                            </div>
                        </label>
                        <label>
                            <span class="mb-1.5 block text-sm font-medium text-gray-700">成交概率 %</span>
                            <input type="number" min="0" max="100" name="probability" value="{{ old('probability', $opportunity?->probability ?: 20) }}" class="{{ $inputClass }}">
                        </label>
                        <label>
                            <span class="mb-1.5 block text-sm font-medium text-gray-700">预计成交日期</span>
                            <input type="date" name="expected_close_date" value="{{ old('expected_close_date', $opportunity?->expected_close_date?->format('Y-m-d')) }}" class="{{ $inputClass }}">
                        </label>
                        <label class="md:col-span-2">
                            <span class="mb-1.5 block text-sm font-medium text-gray-700">竞争对手</span>
                            <input name="competitor" maxlength="200" value="{{ old('competitor', $opportunity?->competitor) }}" class="{{ $inputClass }}">
                        </label>
                        <label class="md:col-span-2">
                            <span class="mb-1.5 block text-sm font-medium text-gray-700">输单原因（阶段为输单时必填）</span>
                            <textarea name="lost_reason" rows="3" class="{{ $textareaClass }}">{{ old('lost_reason', $opportunity?->lost_reason) }}</textarea>
                        </label>
                        <label class="md:col-span-2">
                            <span class="mb-1.5 block text-sm font-medium text-gray-700">内部备注</span>
                            <textarea name="notes" rows="5" class="{{ $textareaClass }}">{{ old('notes', $opportunity?->notes) }}</textarea>
                        </label>
                    </div>
                    <div class="mt-6 flex justify-end">
                        <button type="submit" class="rounded-md bg-blue-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-blue-700">
                            保存商机
                        </button>
                    </div>
                </section>
            </form>

            <aside class="space-y-6">
                @if($sourceInquiry)
                    <section class="rounded-lg border border-emerald-100 bg-emerald-50 p-5 shadow-sm">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <h2 class="font-semibold text-emerald-950">来源询盘</h2>
                                <p class="mt-1 text-sm text-emerald-800">{{ $sourceInquiry->subject }}</p>
                            </div>
                            <a href="{{ route('admin.crm.inquiries.show', ['inquiryId' => (int) $sourceInquiry->id]) }}" class="shrink-0 text-sm font-medium text-emerald-700 hover:text-emerald-900">查看</a>
                        </div>
                        <dl class="mt-4 grid grid-cols-3 gap-2 text-xs">
                            <div class="rounded-md bg-white px-3 py-2">
                                <dt class="text-emerald-700">Entity</dt>
                                <dd class="mt-1 text-lg font-semibold text-emerald-950">{{ $sourceInquiry->entities->count() }}</dd>
                            </div>
                            <div class="rounded-md bg-white px-3 py-2">
                                <dt class="text-emerald-700">知识库</dt>
                                <dd class="mt-1 text-lg font-semibold text-emerald-950">{{ $sourceInquiry->knowledgeBases->count() }}</dd>
                            </div>
                            <div class="rounded-md bg-white px-3 py-2">
                                <dt class="text-emerald-700">Case</dt>
                                <dd class="mt-1 text-lg font-semibold text-emerald-950">{{ $sourceInquiry->cases->count() }}</dd>
                            </div>
                        </dl>
                        @if((string) ($sourceInquiry->customer_need_summary ?? '') !== '')
                            <div class="mt-4 rounded-md border border-emerald-100 bg-white px-3 py-2">
                                <div class="text-xs font-medium uppercase tracking-wide text-emerald-700">需求摘要</div>
                                <div class="mt-1 whitespace-pre-wrap text-sm leading-6 text-emerald-950">{{ \Illuminate\Support\Str::limit((string) $sourceInquiry->customer_need_summary, 360) }}</div>
                            </div>
                        @endif
                        @if((string) ($sourceInquiry->missing_information_questions ?? '') !== '')
                            <div class="mt-3 rounded-md border border-amber-100 bg-amber-50 px-3 py-2">
                                <div class="text-xs font-medium uppercase tracking-wide text-amber-700">仍需确认</div>
                                <div class="mt-1 whitespace-pre-wrap text-sm leading-6 text-amber-900">{{ \Illuminate\Support\Str::limit((string) $sourceInquiry->missing_information_questions, 260) }}</div>
                            </div>
                        @endif
                    </section>
                @endif

                @if($isEdit)
                    <section class="rounded-lg border border-blue-100 bg-blue-50 p-5 shadow-sm">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <h2 class="font-semibold text-blue-950">当前下一步</h2>
                                <p class="mt-1 text-sm text-blue-700">商机的未来动作统一由 CRM 待办管理，避免与活动记录和商机字段重复。</p>
                            </div>
                            <i data-lucide="list-checks" class="h-5 w-5 shrink-0 text-blue-600"></i>
                        </div>
                        @if($currentOpportunityTask)
                            <div class="mt-4 rounded-md border border-blue-100 bg-white px-4 py-3">
                                <div class="font-medium text-gray-900">{{ $currentOpportunityTask->title }}</div>
                                <div class="mt-1 flex flex-wrap gap-2 text-xs text-gray-500">
                                    @if($currentOpportunityTask->due_at)
                                        <span>截止：{{ $currentOpportunityTask->due_at->format('Y-m-d H:i') }}</span>
                                    @else
                                        <span>未设置截止时间</span>
                                    @endif
                                    @if($currentOpportunityTask->assignee)
                                        <span>负责人：{{ $currentOpportunityTask->assignee->display_name ?: $currentOpportunityTask->assignee->username }}</span>
                                    @endif
                                </div>
                            </div>
                        @else
                            <div class="mt-4 rounded-md border border-dashed border-blue-200 px-4 py-3 text-sm text-blue-700">暂无未完成待办，请在下方创建下一步动作。</div>
                        @endif
                    </section>

                    <section class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
                        <h2 class="font-semibold text-gray-900">活动时间线</h2>
                        <p class="mt-1 text-sm text-gray-500">记录已经发生的沟通；可同时安排下一步待办。</p>
                        <form method="POST" action="{{ route('admin.crm.opportunities.activities.store', ['opportunityId' => (int) $opportunity->id]) }}" class="mt-4 space-y-3">
                            @csrf
                            @include('admin.crm.partials._markdown-editor', ['fieldName' => 'content', 'rows' => 4, 'placeholder' => '沟通结果（支持 Markdown）'])
                            <input type="text" name="followup_type" maxlength="80" placeholder="活动类型：电话 / 邮件 / 会议" class="block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500">
                            @include('admin.crm.partials._activity-next-task-fields')
                            <button type="submit" class="inline-flex w-full items-center justify-center rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">记录活动</button>
                        </form>
                        <div class="mt-5 space-y-3">
                            @forelse($opportunity->activities as $followUp)
                                @include('admin.crm.partials._follow-up-item', ['followUp' => $followUp, 'showInquiryLink' => true, 'editable' => true])
                            @empty
                                <div class="rounded-md border border-dashed border-gray-300 px-4 py-5 text-sm text-gray-500">暂无活动记录</div>
                            @endforelse
                        </div>
                    </section>

                    <section class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
                        <div class="flex items-center justify-between gap-3">
                            <h2 class="font-semibold text-gray-900">关联单据</h2>
                            <a href="{{ route('admin.crm.quotes.create', ['opportunity_id' => (int) $opportunity->id]) }}" class="inline-flex items-center rounded-md border border-purple-200 bg-purple-50 px-3 py-1.5 text-xs font-medium text-purple-700 hover:bg-purple-100">
                                <i data-lucide="file-plus-2" class="mr-1.5 h-3.5 w-3.5"></i>
                                新建单据
                            </a>
                        </div>
                        <div class="mt-4 space-y-2">
                            @forelse($opportunity->quotes as $quote)
                                @php($quoteTotal = (float) ($quote->grand_total ?: $quote->total_amount))
                                <a href="{{ route('admin.crm.quotes.show', ['quoteId' => (int) $quote->id]) }}" class="block rounded-md border border-gray-200 px-3 py-2 text-sm hover:bg-gray-50">
                                    <div class="font-medium text-gray-900">{{ $quote->quote_no ?: '未生成单号' }}</div>
                                    <div class="mt-1 text-xs text-gray-500">{{ $quote->title }} · {{ $quote->currency }} {{ number_format($quoteTotal, 2) }}</div>
                                </a>
                            @empty
                                <div class="rounded-md border border-dashed border-gray-300 px-3 py-4 text-sm text-gray-500">暂无关联单据</div>
                            @endforelse
                        </div>
                    </section>

                    <section class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
                        <h2 class="font-semibold text-gray-900">新建待办</h2>
                        <div class="mt-4">
                            @include('admin.crm.partials.task-form', ['customer_id' => $opportunity->customer_id, 'opportunity_id' => $opportunity->id])
                        </div>
                    </section>
                    <section class="rounded-lg border border-gray-200 bg-white shadow-sm">
                        <div class="border-b border-gray-100 px-5 py-4">
                            <h2 class="font-semibold text-gray-900">商机待办</h2>
                        </div>
                        <div class="divide-y divide-gray-100">
                            @forelse($opportunity->tasks as $task)
                                @include('admin.crm.partials.task-row', ['task' => $task])
                            @empty
                                <div class="p-5 text-sm text-gray-500">暂无待办</div>
                            @endforelse
                        </div>
                    </section>

                    <section class="rounded-lg border border-red-200 bg-white p-5 shadow-sm">
                        <h2 class="font-semibold text-gray-900">归档商机</h2>
                        <p class="mt-2 text-sm leading-6 text-gray-500">归档后商机从活动管道隐藏，但不会删除 {{ $opportunity->tasks->count() }} 个待办、{{ $opportunity->activities->count() }} 条活动或 {{ $opportunity->quotes->count() }} 份单据，可在“已归档”中恢复。</p>
                        <form method="POST" action="{{ route('admin.crm.opportunities.delete', ['opportunityId' => (int) $opportunity->id]) }}" class="mt-4" onsubmit="return confirm('确认归档此商机？关联待办、活动和单据将保留。')">
                            @csrf
                            <button type="submit" class="inline-flex items-center rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm font-semibold text-red-700 hover:bg-red-100">
                                <i data-lucide="archive" class="mr-2 h-4 w-4"></i>归档商机
                            </button>
                        </form>
                    </section>
                @else
                    <section class="rounded-lg border border-blue-100 bg-blue-50 p-5 text-sm leading-6 text-blue-800">
                        建议只把已确认存在真实采购可能的询盘转成商机，普通咨询继续留在询盘中即可。
                    </section>
                @endif
            </aside>
        </div>
    </div>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', () => {
    const root = document.querySelector('[data-opportunity-source]');
    if (!root) return;
    const panel = root.querySelector('[data-source-inquiry-panel]');
    const search = root.querySelector('[data-source-inquiry-search]');
    const select = root.querySelector('[data-source-inquiry-select]');
    const customer = document.querySelector('select[name="customer_id"]');
    const collection = document.querySelector('select[name="collection_id"]');

    const syncMode = () => {
        const mode = root.querySelector('input[name="source_mode"]:checked')?.value || 'direct';
        panel?.classList.toggle('hidden', mode !== 'inquiry');
        if (select) select.required = mode === 'inquiry';
    };
    root.querySelectorAll('input[name="source_mode"]').forEach((radio) => radio.addEventListener('change', syncMode));
    search?.addEventListener('input', () => {
        const term = search.value.trim().toLocaleLowerCase();
        Array.from(select?.options || []).forEach((option, index) => {
            if (index === 0) return;
            option.hidden = term !== '' && !(option.dataset.search || '').includes(term);
        });
    });
    select?.addEventListener('change', () => {
        const option = select.selectedOptions[0];
        if (!option?.value) return;
        if (customer) customer.value = option.dataset.customerId || '';
        if (collection && option.dataset.collectionId) collection.value = option.dataset.collectionId;
    });
    syncMode();
});
</script>
@endpush
