@extends('admin.layouts.app')

@php
    $formAction = $isEdit
        ? route('admin.crm.tickets.update', ['ticketId' => (int) $ticketId])
        : route('admin.crm.tickets.store');
    $fieldClass = 'block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500';
@endphp

@section('content')
    <div class="px-4 sm:px-0">
        <div class="mb-8 flex items-center space-x-4">
            <a href="{{ $isEdit ? route('admin.crm.tickets.show', ['ticketId' => (int) $ticketId]) : route('admin.crm.tickets.index') }}" class="text-gray-400 hover:text-gray-600">
                <i data-lucide="arrow-left" class="h-5 w-5"></i>
            </a>
            <div>
                <h1 class="text-2xl font-bold text-gray-900">{{ $isEdit ? '编辑售后工单' : '新增售后工单' }}</h1>
                <p class="mt-1 text-sm text-gray-600">售后工单用于把真实问题与知识库、Case 和 Entity 建立引用关系。</p>
            </div>
        </div>

        @include('admin.crm.partials.nav', ['currentCrmTab' => 'tickets'])

        <div class="rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
            <div class="px-6 py-6">
                @if ($errors->any())
                    <div class="mb-6 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ $errors->first() }}</div>
                @endif

                <form method="POST" action="{{ $formAction }}" class="space-y-6" data-crm-ticket-form data-analysis-url="{{ route('admin.crm.tickets.analyze') }}">
                    @csrf
                    @if ($isEdit)
                        @method('PUT')
                    @endif

                    <section class="rounded-lg border border-blue-100 bg-blue-50/60 p-5">
                        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                            <div>
                                <h2 class="text-base font-semibold text-blue-950">AI 工单分析</h2>
                                <p class="mt-1 text-sm text-blue-800">粘贴售后问题后，系统会推荐关联资料，并生成回复要点和需补充问题。</p>
                            </div>
                            <div class="flex min-w-[280px] flex-col gap-2 sm:flex-row">
                                <select data-crm-analysis-model class="block w-full rounded-md border border-blue-200 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                    <option value="0">自动选择模型</option>
                                    @foreach(($aiModelOptions ?? []) as $model)
                                        <option value="{{ (int) $model['id'] }}">{{ $model['name'] }}</option>
                                    @endforeach
                                </select>
                                <button type="button" data-crm-analysis-submit class="inline-flex items-center justify-center rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">
                                    <i data-lucide="sparkles" class="mr-2 h-4 w-4"></i>
                                    分析
                                </button>
                            </div>
                        </div>
                        <textarea data-crm-analysis-content rows="5" class="mt-4 block w-full rounded-md border border-blue-200 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" placeholder="粘贴客户售后问题、邮件或聊天记录"></textarea>
                        <p data-crm-analysis-status class="mt-2 hidden text-sm text-blue-800"></p>
                    </section>

                    <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
                        @include('admin.partials.collection-select', [
                            'selectedId' => old('collection_id', (string) ($ticketForm['collection_id'] ?? '')),
                            'collectionOptions' => $collectionOptions ?? [],
                            'label' => '业务容器',
                            'help' => '选择后，Entity、知识库和 Case 选项会限制在同一业务容器中。',
                            'class' => $fieldClass,
                        ])
                        <div>
                            <label class="mb-2 block text-sm font-medium text-gray-700">工单标题 <span class="text-red-500">*</span></label>
                            <input type="text" name="title" required maxlength="200" value="{{ old('title', (string) ($ticketForm['title'] ?? '')) }}" class="{{ $fieldClass }}" placeholder="例如：SJ4060 安装后报警">
                        </div>
                    </div>

                    <div class="grid grid-cols-1 gap-6 lg:grid-cols-4">
                        <div>
                            <label class="mb-2 block text-sm font-medium text-gray-700">客户 <span class="text-red-500">*</span></label>
                            <select name="customer_id" required class="{{ $fieldClass }}">
                                <option value="">选择客户</option>
                                @foreach (($customerOptions ?? []) as $customer)
                                    <option value="{{ (int) $customer['id'] }}" @selected(old('customer_id', (string) ($ticketForm['customer_id'] ?? '')) === (string) $customer['id'])>{{ $customer['label'] }} @if (($customer['meta'] ?? '') !== '') · {{ $customer['meta'] }} @endif</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="mb-2 block text-sm font-medium text-gray-700">负责人</label>
                            <select name="owner" class="{{ $fieldClass }}">
                                <option value="">未指定</option>
                                @foreach (($employeeOptions ?? []) as $value => $label)
                                    <option value="{{ $value }}" @selected(old('owner', (string) ($ticketForm['owner'] ?? '')) === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="mb-2 block text-sm font-medium text-gray-700">关联订单</label>
                            <select name="order_id" class="{{ $fieldClass }}">
                                <option value="">不关联订单</option>
                                @foreach (($orderOptions ?? []) as $order)
                                    <option value="{{ (int) $order->id }}" data-collection-id="{{ (int) ($order->collection_id ?? 0) }}" @selected(old('order_id', (string) ($ticketForm['order_id'] ?? '')) === (string) $order->id)>{{ $order->order_no }} · {{ $order->title }} @if($order->customer) · {{ $order->customer->contact_person ?: $order->customer->company_name }} @endif</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="mb-2 block text-sm font-medium text-gray-700">核心 Entity</label>
                            <select name="entity_id" class="{{ $fieldClass }}">
                                <option value="">不关联 Entity</option>
                                @foreach (($entityOptions ?? []) as $entity)
                                    <option value="{{ (int) $entity['id'] }}" data-collection-id="{{ (int) ($entity['collection_id'] ?? 0) }}" @selected(old('entity_id', (string) ($ticketForm['entity_id'] ?? '')) === (string) $entity['id'])>{{ $entity['label'] }} @if($entity['meta'] !== '') · {{ $entity['meta'] }} @endif</option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 gap-6 lg:grid-cols-4">
                        <div>
                            <label class="mb-2 block text-sm font-medium text-gray-700">问题类型</label>
                            <input type="text" name="issue_type" maxlength="100" value="{{ old('issue_type', (string) ($ticketForm['issue_type'] ?? '')) }}" class="{{ $fieldClass }}" placeholder="安装 / 参数 / 故障 / 售后咨询">
                        </div>
                        <div>
                            <label class="mb-2 block text-sm font-medium text-gray-700">优先级</label>
                            <select name="priority" class="{{ $fieldClass }}">
                                @foreach (['low' => '低', 'normal' => '普通', 'high' => '高', 'urgent' => '紧急'] as $value => $label)
                                    <option value="{{ $value }}" @selected(old('priority', (string) ($ticketForm['priority'] ?? 'normal')) === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="mb-2 block text-sm font-medium text-gray-700">状态</label>
                            <select name="status" class="{{ $fieldClass }}">
                                @foreach (['open' => '打开', 'waiting_customer' => '等待客户', 'in_progress' => '处理中', 'resolved' => '已解决', 'closed' => '关闭'] as $value => $label)
                                    <option value="{{ $value }}" @selected(old('status', (string) ($ticketForm['status'] ?? 'open')) === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="mb-2 block text-sm font-medium text-gray-700">处理边界</label>
                            <div class="rounded-md border border-gray-200 bg-gray-50 px-3 py-2 text-sm text-gray-600">不直接修改知识库；需要沉淀内容时生成候选草稿。</div>
                        </div>
                    </div>

                    <div>
                        <label class="mb-2 block text-sm font-medium text-gray-700">问题描述</label>
                        <textarea name="issue_description" rows="7" data-crm-field="issue_description" class="{{ $fieldClass }}">{{ old('issue_description', (string) ($ticketForm['issue_description'] ?? '')) }}</textarea>
                    </div>

                    <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
                        <div>
                            <label class="mb-2 block text-sm font-medium text-gray-700">建议回复要点</label>
                            <textarea name="reply_points" rows="5" data-crm-field="reply_points" class="{{ $fieldClass }}">{{ old('reply_points', (string) ($ticketForm['reply_points'] ?? '')) }}</textarea>
                        </div>
                        <div>
                            <label class="mb-2 block text-sm font-medium text-gray-700">需补充问题</label>
                            <textarea name="missing_information_questions" rows="5" data-crm-field="missing_information_questions" class="{{ $fieldClass }}">{{ old('missing_information_questions', (string) ($ticketForm['missing_information_questions'] ?? '')) }}</textarea>
                        </div>
                    </div>

                    <div>
                        <label class="mb-2 block text-sm font-medium text-gray-700">解决方案 / 处理结果</label>
                        <textarea name="resolution" rows="5" class="{{ $fieldClass }}">{{ old('resolution', (string) ($ticketForm['resolution'] ?? '')) }}</textarea>
                    </div>

                    <section class="rounded-lg border border-slate-200 bg-slate-50/60 p-4">
                        <h2 class="text-base font-semibold text-slate-900">关联参考资料</h2>
                        <p class="mt-1 text-sm text-slate-600">用于辅助售后回复和后续内容沉淀，不会自动创建或覆盖素材。</p>
                        <div class="mt-4 grid grid-cols-1 gap-5 lg:grid-cols-2">
                            <div>
                                <label class="mb-2 block text-sm font-medium text-gray-700">知识库</label>
                                @include('admin.partials.option-multi-selector', [
                                    'name' => 'knowledge_base_ids',
                                    'options' => $knowledgeBaseOptions ?? [],
                                    'selectedIds' => $selectedKnowledgeBaseIds ?? [],
                                    'tone' => 'orange',
                                    'placeholder' => '搜索知识库',
                                    'noneSelectedText' => '暂未关联知识库',
                                ])
                            </div>
                            <div>
                                <label class="mb-2 block text-sm font-medium text-gray-700">Case</label>
                                @include('admin.partials.option-multi-selector', [
                                    'name' => 'case_record_ids',
                                    'options' => $caseOptions ?? [],
                                    'selectedIds' => $selectedCaseRecordIds ?? [],
                                    'tone' => 'green',
                                    'placeholder' => '搜索 Case',
                                    'noneSelectedText' => '暂未关联 Case',
                                ])
                            </div>
                        </div>
                    </section>

                    <div>
                        <label class="mb-2 block text-sm font-medium text-gray-700">内部备注</label>
                        <textarea name="notes" rows="4" class="{{ $fieldClass }}">{{ old('notes', (string) ($ticketForm['notes'] ?? '')) }}</textarea>
                    </div>

                    <div class="flex justify-end gap-3">
                        <a href="{{ route('admin.crm.tickets.index') }}" class="inline-flex items-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">取消</a>
                        <button type="submit" class="inline-flex items-center rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">
                            <i data-lucide="save" class="mr-2 h-4 w-4"></i>
                            保存工单
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        (() => {
            const form = document.querySelector('[data-crm-ticket-form]');
            if (!form) return;

            const collectionSelect = form.querySelector('select[name="collection_id"]');

            function selectedCollectionId() {
                return String(collectionSelect?.value || '');
            }

            function optionMatchesCollection(option) {
                const collectionId = selectedCollectionId();
                if (collectionId === '') return true;
                const optionCollectionId = String(option.getAttribute('data-collection-id') || option.getAttribute('data-option-collection-id') || '');
                return optionCollectionId === collectionId;
            }

            function syncNativeSelect(name) {
                const select = form.querySelector('select[name="' + name + '"]');
                if (!select) return;
                Array.from(select.querySelectorAll('option[value]')).forEach((option) => {
                    if (option.value === '') {
                        option.hidden = false;
                        return;
                    }
                    option.hidden = !optionMatchesCollection(option);
                });
                if (select.selectedOptions[0]?.hidden) {
                    select.value = '';
                }
            }

            function syncOptionSelector(fieldName) {
                const selector = form.querySelector('[data-option-multi-selector][data-field-name="' + fieldName + '"]');
                if (!selector) return;
                selector.querySelectorAll('[data-option-item]').forEach((item) => {
                    const hidden = !optionMatchesCollection(item);
                    item.hidden = hidden;
                    item.dataset.optionFilterHidden = hidden ? '1' : '0';
                    item.classList.toggle('hidden', hidden);
                });
                selector.querySelectorAll('[data-option-chip]').forEach((chip) => {
                    const id = chip.getAttribute('data-option-id') || '';
                    const item = selector.querySelector('[data-option-item][data-option-id="' + CSS.escape(id) + '"]');
                    if (item && !optionMatchesCollection(item)) {
                        chip.remove();
                    }
                });
                selector.dispatchEvent(new CustomEvent('option-selector:changed', {bubbles: true}));
            }

            function syncByCollection() {
                syncNativeSelect('order_id');
                syncNativeSelect('entity_id');
                syncOptionSelector('knowledge_base_ids');
                syncOptionSelector('case_record_ids');
            }

            function selectMulti(fieldName, id) {
                const selector = form.querySelector('[data-option-multi-selector][data-field-name="' + fieldName + '"]');
                if (!selector || !id) return;
                const item = selector.querySelector('[data-option-item][data-option-id="' + CSS.escape(String(id)) + '"]');
                if (item && item.dataset.optionFilterHidden !== '1') item.click();
            }

            collectionSelect?.addEventListener('change', syncByCollection);
            customerSelect?.addEventListener('change', filterContactsByCustomer);
            syncByCollection();
            filterContactsByCustomer();

            form.querySelector('[data-crm-analysis-submit]')?.addEventListener('click', async () => {
                const status = form.querySelector('[data-crm-analysis-status]');
                const source = form.querySelector('[data-crm-analysis-content]');
                const content = (source?.value || form.querySelector('[name="issue_description"]')?.value || '').trim();
                if (!content) {
                    if (status) {
                        status.textContent = '请先粘贴或填写售后问题。';
                        status.classList.remove('hidden');
                    }
                    return;
                }

                if (status) {
                    status.textContent = '正在分析...';
                    status.classList.remove('hidden');
                }

                try {
                    const response = await fetch(form.dataset.analysisUrl, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': form.querySelector('input[name="_token"]').value,
                        },
                        body: JSON.stringify({
                            content,
                            collection_id: selectedCollectionId(),
                            ai_model_id: form.querySelector('[data-crm-analysis-model]')?.value || 0,
                        }),
                    });
                    const payload = await response.json();
                    const fields = payload.fields || {};
                    if (form.querySelector('[name="issue_description"]') && !form.querySelector('[name="issue_description"]').value.trim()) {
                        form.querySelector('[name="issue_description"]').value = content;
                    }
                    if (fields.entity_id && form.querySelector('[name="entity_id"]')) {
                        form.querySelector('[name="entity_id"]').value = String(fields.entity_id);
                    }
                    Object.entries({
                        reply_points: 'reply_points',
                        missing_information_questions: 'missing_information_questions',
                        priority: 'priority',
                    }).forEach(([key, name]) => {
                        const input = form.querySelector('[name="' + name + '"]');
                        if (input && fields[key] !== undefined) input.value = fields[key] || '';
                    });
                    (fields.knowledge_base_ids || []).forEach((id) => selectMulti('knowledge_base_ids', id));
                    (fields.case_record_ids || []).forEach((id) => selectMulti('case_record_ids', id));
                    if (status) status.textContent = '分析完成，可继续人工调整。';
                } catch (error) {
                    if (status) status.textContent = '分析失败，请检查模型配置或稍后重试。';
                }
            });
        })();
    </script>
@endpush
