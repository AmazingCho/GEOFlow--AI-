@extends('admin.layouts.app')

@php
    $formAction = $isEdit
        ? route('admin.crm.customers.update', ['customerId' => (int) $customerId])
        : route('admin.crm.customers.store');
    $fieldClass = 'block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500';
    $currentCustomerType = old('customer_type', (string) ($customerForm['customer_type'] ?? ''));
    $currentSourceChannel = old('source_channel', (string) ($customerForm['source_channel'] ?? ''));
    $currentOwner = old('owner', (string) ($customerForm['owner'] ?? ''));
@endphp

@section('content')
    <div class="px-4 sm:px-0">
        <div class="mb-8 flex items-center space-x-4">
            <a href="{{ route('admin.crm.customers.index') }}" class="text-gray-400 hover:text-gray-600">
                <i data-lucide="arrow-left" class="h-5 w-5"></i>
            </a>
            <div>
                <h1 class="text-2xl font-bold text-gray-900">{{ $isEdit ? '编辑客户' : '新增客户' }}</h1>
                <p class="mt-1 text-sm text-gray-600">客户是 CRM 的基础对象，可绑定 Collection，后续询盘和报价会沿用该业务容器。</p>
            </div>
        </div>

        @include('admin.crm.partials.nav', ['currentCrmTab' => 'customers'])

        <div class="rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
            <div class="px-6 py-6">
                @if ($errors->any())
                    <div class="mb-6 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ $errors->first() }}</div>
                @endif

                <form method="POST" action="{{ $formAction }}" class="space-y-6">
                    @csrf
                    @if ($isEdit)
                        @method('PUT')
                    @endif

                    <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
                        <div>
                            <label class="mb-2 block text-sm font-medium text-gray-700">客户名称（联系人） <span class="text-red-500">*</span></label>
                            <input type="text" name="contact_person" required maxlength="200" value="{{ old('contact_person', (string) ($customerForm['contact_person'] ?? '')) }}" class="{{ $fieldClass }}" placeholder="联系人姓名">
                        </div>
                        <div>
                            <label class="mb-2 block text-sm font-medium text-gray-700">公司名</label>
                            <input type="text" name="company_name" maxlength="200" value="{{ old('company_name', (string) ($customerForm['company_name'] ?? '')) }}" class="{{ $fieldClass }}" placeholder="客户公司名称（选填）">
                        </div>
                        @include('admin.partials.collection-select', [
                            'selectedId' => (string) ($customerForm['collection_id'] ?? ''),
                            'collectionOptions' => $collectionOptions ?? [],
                            'label' => '业务容器',
                            'help' => '用于限制询盘、Entity、知识库和报价资料范围。',
                            'class' => $fieldClass,
                        ])
                    </div>

                    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
                        <div>
                            <label class="mb-2 block text-sm font-medium text-gray-700">客户类型</label>
                            <select name="customer_type" class="{{ $fieldClass }}">
                                <option value="">未指定</option>
                                @if ($currentCustomerType !== '' && !array_key_exists($currentCustomerType, $customerTypeOptions ?? []))
                                    <option value="{{ $currentCustomerType }}" selected>{{ $currentCustomerType }}（历史值）</option>
                                @endif
                                @foreach (($customerTypeOptions ?? []) as $value => $label)
                                    <option value="{{ $value }}" @selected($currentCustomerType === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="mb-2 block text-sm font-medium text-gray-700">行业</label>
                            <input type="text" name="industry" maxlength="160" value="{{ old('industry', (string) ($customerForm['industry'] ?? '')) }}" class="{{ $fieldClass }}" placeholder="行业或应用领域">
                        </div>
                        <div>
                            <label class="mb-2 block text-sm font-medium text-gray-700">状态</label>
                            <select name="status" class="{{ $fieldClass }}">
                                @foreach (['active' => '活跃', 'lead' => '潜在', 'inactive' => '不活跃', 'blocked' => '暂停合作'] as $value => $label)
                                    <option value="{{ $value }}" @selected(old('status', (string) ($customerForm['status'] ?? 'active')) === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
                        <div>
                            <label class="mb-2 block text-sm font-medium text-gray-700">国家</label>
                            <input type="text" name="country" maxlength="100" list="crm-country-options" value="{{ old('country', (string) ($customerForm['country'] ?? '')) }}" class="{{ $fieldClass }}" placeholder="输入搜索或选择国家">
                            <datalist id="crm-country-options">
                                @foreach (($countryOptions ?? []) as $country)
                                    <option value="{{ $country }}"></option>
                                @endforeach
                            </datalist>
                        </div>
                        <div>
                            <label class="mb-2 block text-sm font-medium text-gray-700">地址</label>
                            <input type="text" name="address" maxlength="120" value="{{ old('address', (string) ($customerForm['address'] ?? '')) }}" class="{{ $fieldClass }}">
                        </div>
                        <div>
                            <label class="mb-2 block text-sm font-medium text-gray-700">来源渠道</label>
                            <select name="source_channel" class="{{ $fieldClass }}">
                                <option value="">未指定</option>
                                @if ($currentSourceChannel !== '' && !array_key_exists($currentSourceChannel, $sourceChannelOptions ?? []))
                                    <option value="{{ $currentSourceChannel }}" selected>{{ $currentSourceChannel }}（历史值）</option>
                                @endif
                                @foreach (($sourceChannelOptions ?? []) as $value => $label)
                                    <option value="{{ $value }}" @selected($currentSourceChannel === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 gap-6 lg:grid-cols-4">
                        <div>
                            <label class="mb-2 block text-sm font-medium text-gray-700">联系电话</label>
                            <input type="text" name="phone" maxlength="120" value="{{ old('phone', (string) ($customerForm['phone'] ?? '')) }}" class="{{ $fieldClass }}" placeholder="客户电话 / 公司总机">
                        </div>
                        <div>
                            <label class="mb-2 block text-sm font-medium text-gray-700">邮箱</label>
                            <input type="email" name="email" maxlength="200" value="{{ old('email', (string) ($customerForm['email'] ?? '')) }}" class="{{ $fieldClass }}" placeholder="客户邮箱 / 联系邮箱">
                        </div>
                        <div>
                            <label class="mb-2 block text-sm font-medium text-gray-700">职位</label>
                            <input type="text" name="contact_title" maxlength="160" value="{{ old('contact_title', (string) ($customerForm['contact_title'] ?? '')) }}" class="{{ $fieldClass }}" placeholder="采购经理 / 工程负责人">
                        </div>
                        <div>
                            <label class="mb-2 block text-sm font-medium text-gray-700">负责人</label>
                            <select name="owner" class="{{ $fieldClass }}">
                                <option value="">未指定</option>
                                @if ($currentOwner !== '' && !array_key_exists($currentOwner, $employeeOptions ?? []))
                                    <option value="{{ $currentOwner }}" selected>{{ $currentOwner }}（历史值）</option>
                                @endif
                                @foreach (($employeeOptions ?? []) as $value => $label)
                                    <option value="{{ $value }}" @selected($currentOwner === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="mb-2 block text-sm font-medium text-gray-700">官网</label>
                            <input type="text" name="website" maxlength="500" value="{{ old('website', (string) ($customerForm['website'] ?? '')) }}" class="{{ $fieldClass }}" placeholder="https://example.com">
                        </div>
                    </div>

                    <div>
                        <label class="mb-2 block text-sm font-medium text-gray-700">备注</label>
                        <textarea name="notes" rows="5" class="{{ $fieldClass }}">{{ old('notes', (string) ($customerForm['notes'] ?? '')) }}</textarea>
                    </div>

                    <div class="flex justify-end gap-3">
                        <a href="{{ route('admin.crm.customers.index') }}" class="inline-flex items-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">取消</a>
                        <button type="submit" class="inline-flex items-center rounded-md border border-transparent bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">
                            <i data-lucide="save" class="mr-2 h-4 w-4"></i>
                            保存客户
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection
