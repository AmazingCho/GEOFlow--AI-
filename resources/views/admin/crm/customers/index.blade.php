@extends('admin.layouts.app')

@section('content')
    <div class="px-4 sm:px-0">
        <div class="mb-8 flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
            <div>
                <h1 class="text-2xl font-bold text-gray-900">CRM 客户管理</h1>
                <p class="mt-1 text-sm text-gray-600">管理客户、内部负责人、跟进记录，并与业务容器关联。</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <a href="{{ route('admin.crm.inquiries.create') }}" class="inline-flex items-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                    <i data-lucide="message-square-plus" class="mr-2 h-4 w-4"></i>
                    新增询盘
                </a>
                <a href="{{ route('admin.crm.customers.create') }}" class="inline-flex items-center rounded-md border border-transparent bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">
                    <i data-lucide="plus" class="mr-2 h-4 w-4"></i>
                    新增客户
                </a>
            </div>
        </div>

        @include('admin.crm.partials.nav', ['currentCrmTab' => 'customers'])

        <div class="mb-6 grid grid-cols-1 gap-4 md:grid-cols-3">
            <div class="rounded-lg bg-white p-5 shadow-sm ring-1 ring-gray-200">
                <div class="text-sm text-gray-500">客户总数</div>
                <div class="mt-2 text-2xl font-semibold text-gray-900">{{ number_format((int) ($stats['total'] ?? 0)) }}</div>
            </div>
            <div class="rounded-lg bg-white p-5 shadow-sm ring-1 ring-gray-200">
                <div class="text-sm text-gray-500">活跃客户</div>
                <div class="mt-2 text-2xl font-semibold text-gray-900">{{ number_format((int) ($stats['active'] ?? 0)) }}</div>
            </div>
            <div class="rounded-lg bg-white p-5 shadow-sm ring-1 ring-gray-200">
                <div class="text-sm text-gray-500">询盘总数</div>
                <div class="mt-2 text-2xl font-semibold text-gray-900">{{ number_format((int) ($stats['inquiries'] ?? 0)) }}</div>
            </div>
        </div>

        <form method="GET" action="{{ route('admin.crm.customers.index') }}" class="mb-6 rounded-lg border border-gray-200 bg-white px-5 py-4 shadow-sm">
            <div class="grid grid-cols-1 gap-3 lg:grid-cols-[30%_220px_auto] lg:items-end">
                <div>
                    <label class="mb-2 block text-sm font-medium text-gray-700">搜索</label>
                    <input type="text" name="search" value="{{ $search }}" placeholder="联系人、公司、国家、行业、网站" class="block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                </div>
                @include('admin.partials.collection-select', [
                    'selectedId' => (string) ($collectionId ?? ''),
                    'collectionOptions' => $collectionOptions ?? [],
                    'label' => '业务容器',
                    'help' => '',
                    'emptyLabel' => '全部业务容器',
                    'class' => 'block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500',
                ])
                <div>
                    <label class="mb-2 block text-sm font-medium text-gray-700">状态</label>
                    <select name="status" class="block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                        <option value="">全部状态</option>
                        <option value="active" @selected($status === 'active')>活跃</option>
                        <option value="lead" @selected($status === 'lead')>潜在</option>
                        <option value="inactive" @selected($status === 'inactive')>不活跃</option>
                        <option value="blocked" @selected($status === 'blocked')>暂停合作</option>
                    </select>
                </div>
                <div class="flex gap-2">
                    <button type="submit" class="inline-flex items-center justify-center rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">
                        <i data-lucide="search" class="mr-2 h-4 w-4"></i>
                        筛选
                    </button>
                    <a href="{{ route('admin.crm.customers.index') }}" class="inline-flex items-center justify-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">清除</a>
                </div>
            </div>
        </form>

        <div class="overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
            <div class="border-b border-gray-200 px-6 py-4">
                <h3 class="text-base font-semibold text-gray-900">客户列表 <span class="text-sm text-gray-500">({{ (int) $customers->total() }})</span></h3>
            </div>
            @if ($customers->isEmpty())
                <div class="px-6 py-10 text-center text-sm text-gray-500">暂无客户</div>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">客户</th>
                                <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">业务容器</th>
                                <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">负责人和进度</th>
                                <th class="px-6 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500">操作</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 bg-white">
                            @foreach ($customers as $customer)
                                <tr>
                                    <td class="px-6 py-4">
                                        <div class="font-semibold text-gray-900">{{ $customer->company_name ?: $customer->contact_person }}</div>
                                        <div class="mt-1 text-sm text-gray-600">{{ $customer->contact_person ?: '未填写主联系人' }}{{ $customer->contact_title ? ' · '.$customer->contact_title : '' }}</div>
                                        <div class="mt-1 text-sm text-gray-500">{{ trim(($customer->country ?? '').' '.($customer->address ?? '')) ?: '未填写地址' }}</div>
                                        @if ((string) ($customer->website ?? '') !== '')
                                            <a href="{{ $customer->website }}" target="_blank" rel="noopener noreferrer" class="mt-1 inline-flex text-xs text-blue-600 hover:text-blue-700">{{ $customer->website }}</a>
                                        @endif
                                    </td>
                                    <td class="px-6 py-4">
                                        <span class="inline-flex rounded-full bg-slate-100 px-2.5 py-1 text-xs font-medium text-slate-700">{{ $customer->collection?->name ?? '未指定' }}</span>
                                        <div class="mt-2 text-xs text-gray-500">{{ $customer->industry ?: '未填写行业' }}</div>
                                    </td>
                                    <td class="px-6 py-4">
                                        <div class="flex flex-wrap gap-2 text-xs font-medium">
                                            <span class="rounded bg-blue-50 px-2 py-1 text-blue-700">{{ $customer->owner ?: '未指定负责人' }}</span>
                                            <span class="rounded bg-emerald-50 px-2 py-1 text-emerald-700">{{ (int) ($customer->inquiries_count ?? 0) }} 询盘</span>
                                            <span class="rounded bg-purple-50 px-2 py-1 text-purple-700">{{ (int) ($customer->quotes_count ?? 0) }} 报价</span>
                                        </div>
                                        <div class="mt-2 text-xs text-gray-500">电话：{{ $customer->phone ?: '未填写' }} @if((string) ($customer->contact_title ?? '') !== '') · {{ $customer->contact_title }} @endif</div>
                                        <div class="mt-2 text-xs text-gray-500">状态：{{ $customer->status }}</div>
                                    </td>
                                    <td class="px-6 py-4 text-right">
                                        <div class="flex flex-wrap justify-end gap-2">
                                            <a href="{{ route('admin.crm.customers.show', ['customerId' => (int) $customer->id]) }}" class="inline-flex items-center rounded border border-gray-300 bg-white px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50">
                                                <i data-lucide="eye" class="mr-1 h-4 w-4"></i>
                                                查看
                                            </a>
                                            <a href="{{ route('admin.crm.customers.edit', ['customerId' => (int) $customer->id]) }}" class="inline-flex items-center rounded border border-gray-300 bg-white px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50">
                                                <i data-lucide="pencil" class="mr-1 h-4 w-4"></i>
                                                编辑
                                            </a>
                                            <a href="{{ route('admin.crm.inquiries.create', ['customer_id' => (int) $customer->id]) }}" class="inline-flex items-center rounded border border-blue-200 bg-blue-50 px-3 py-1.5 text-xs font-medium text-blue-700 hover:bg-blue-100">
                                                <i data-lucide="message-square-plus" class="mr-1 h-4 w-4"></i>
                                                询盘
                                            </a>
                                            <form method="POST" action="{{ route('admin.crm.customers.delete', ['customerId' => (int) $customer->id]) }}" onsubmit="return confirm('\u5220\u9664\u5ba2\u6237\u5c06\u540c\u65f6\u5220\u9664\u5176\u5173\u8054\u7684\u8be2\u76d8\u3001\u62a5\u4ef7\u3001\u8ba2\u5355\u548c\u552e\u540e\u5de5\u5355\u3002\u786e\u8ba4\u5220\u9664\uff1f')">
                                                @csrf
                                                <button type="submit" class="inline-flex items-center rounded border border-red-200 bg-red-50 px-3 py-1.5 text-xs font-medium text-red-700 hover:bg-red-100">
                                                    <i data-lucide="trash-2" class="mr-1 h-3.5 w-3.5"></i>
                                                    删除
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="flex flex-col gap-3 border-t border-gray-200 px-6 py-4 lg:flex-row lg:items-center lg:justify-between">
                    <div class="text-sm text-gray-600">显示第 {{ $customers->firstItem() ?? 0 }} - {{ $customers->lastItem() ?? 0 }} 条，共 {{ $customers->total() }} 条</div>
                    @if ($customers->lastPage() > 1)
                        <div>{{ $customers->links() }}</div>
                    @endif
                </div>
            @endif
        </div>
    </div>
@endsection
