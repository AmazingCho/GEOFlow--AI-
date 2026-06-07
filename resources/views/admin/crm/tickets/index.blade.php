@extends('admin.layouts.app')

@section('content')
    <div class="px-4 sm:px-0">
        <div class="mb-8 flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
            <div>
                <h1 class="text-2xl font-bold text-gray-900">售后工单</h1>
                <p class="mt-1 text-sm text-gray-600">记录售后问题、关联 Entity、知识库和 Case，用于后续沉淀 FAQ 或案例素材。</p>
            </div>
            <a href="{{ route('admin.crm.tickets.create') }}" class="inline-flex w-fit items-center rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">
                <i data-lucide="plus" class="mr-2 h-4 w-4"></i>
                新增工单
            </a>
        </div>

        @include('admin.crm.partials.nav', ['currentCrmTab' => 'tickets'])

        <form method="GET" action="{{ route('admin.crm.tickets.index') }}" class="mb-6 rounded-lg border border-gray-200 bg-white px-5 py-4 shadow-sm">
            <div class="grid grid-cols-1 gap-3 lg:grid-cols-[minmax(0,1fr)_220px_160px_160px_auto] lg:items-end">
                <div>
                    <label class="mb-2 block text-sm font-medium text-gray-700">搜索</label>
                    <input type="text" name="search" value="{{ $search }}" class="block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" placeholder="工单标题、问题描述、客户">
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
                        <option value="">全部</option>
                        @foreach (['open' => '打开', 'waiting_customer' => '等待客户', 'in_progress' => '处理中', 'resolved' => '已解决', 'closed' => '关闭'] as $value => $label)
                            <option value="{{ $value }}" @selected($status === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="mb-2 block text-sm font-medium text-gray-700">优先级</label>
                    <select name="priority" class="block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                        <option value="">全部</option>
                        @foreach (['low' => '低', 'normal' => '普通', 'high' => '高', 'urgent' => '紧急'] as $value => $label)
                            <option value="{{ $value }}" @selected($priority === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="flex gap-2">
                    <button class="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">筛选</button>
                    <a href="{{ route('admin.crm.tickets.index') }}" class="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">清除</a>
                </div>
            </div>
        </form>

        <div class="overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
            <div class="border-b border-gray-200 px-6 py-4">
                <h3 class="text-base font-semibold text-gray-900">工单列表 <span class="text-sm text-gray-500">({{ $tickets->total() }})</span></h3>
            </div>
            @if ($tickets->isEmpty())
                <div class="px-6 py-10 text-center text-sm text-gray-500">暂无售后工单。</div>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium uppercase text-gray-500">工单</th>
                                <th class="px-6 py-3 text-left text-xs font-medium uppercase text-gray-500">状态</th>
                                <th class="px-6 py-3 text-left text-xs font-medium uppercase text-gray-500">引用</th>
                                <th class="px-6 py-3 text-right text-xs font-medium uppercase text-gray-500">操作</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 bg-white">
                            @foreach ($tickets as $ticket)
                                <tr>
                                    <td class="px-6 py-4">
                                        <div class="font-semibold text-gray-900">{{ $ticket->title }}</div>
                                        <div class="mt-1 text-sm text-gray-600">{{ $ticket->customer?->company_name ?? '未关联客户' }} · {{ $ticket->collection?->name ?? '未指定' }} · 负责人：{{ $ticket->owner ?: '未指定' }}</div>
                                        <div class="mt-1 line-clamp-2 text-xs leading-5 text-gray-500">{{ $ticket->issue_description ?: '暂无问题描述' }}</div>
                                    </td>
                                    <td class="px-6 py-4 text-sm text-gray-600">
                                        <div>状态：{{ $ticket->status }}</div>
                                        <div class="mt-1">优先级：{{ $ticket->priority }}</div>
                                    </td>
                                    <td class="px-6 py-4 text-sm text-gray-600">
                                        <div>Entity：{{ $ticket->entity?->name ?? '未关联' }}</div>
                                        <div class="mt-1">知识库 {{ (int) $ticket->knowledge_bases_count }} / Case {{ (int) $ticket->cases_count }}</div>
                                    </td>
                                    <td class="px-6 py-4 text-right">
                                        <div class="flex justify-end gap-2">
                                            <a href="{{ route('admin.crm.tickets.show', ['ticketId' => (int) $ticket->id]) }}" class="rounded border border-gray-300 bg-white px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50">查看</a>
                                            <a href="{{ route('admin.crm.tickets.edit', ['ticketId' => (int) $ticket->id]) }}" class="rounded border border-gray-300 bg-white px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50">编辑</a>
                                            <form method="POST" action="{{ route('admin.crm.tickets.delete', ['ticketId' => (int) $ticket->id]) }}" onsubmit="return confirm('确认删除此售后工单？')" style="display:inline">
                                                @csrf
                                                <button type="submit" class="rounded border border-red-200 bg-red-50 px-3 py-1.5 text-xs font-medium text-red-700 hover:bg-red-100">删除</button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="flex flex-col gap-3 border-t border-gray-200 px-6 py-4 lg:flex-row lg:items-center lg:justify-between">
                    <div class="text-sm text-gray-600">显示第 {{ $tickets->firstItem() ?? 0 }} - {{ $tickets->lastItem() ?? 0 }} 条，共 {{ $tickets->total() }} 条</div>
                    {{ $tickets->links() }}
                </div>
            @endif
        </div>
    </div>
@endsection
