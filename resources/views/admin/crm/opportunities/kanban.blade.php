@extends('admin.layouts.app')

@section('content')
<div class="px-4 sm:px-0">
    <div class="mb-6 flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">商机看板</h1>
            <p class="mt-1 text-sm text-gray-500">按销售阶段查看商机推进状态。当前版本只做查看和跳转，不支持拖拽改阶段。</p>
        </div>
        <div class="flex flex-wrap gap-2">
            <a href="{{ route('admin.crm.opportunities.index', array_filter(['collection_id' => $collectionId])) }}" class="inline-flex items-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">
                <i data-lucide="list" class="mr-2 h-4 w-4"></i>商机列表
            </a>
            <a href="{{ route('admin.crm.opportunities.create') }}" class="inline-flex items-center rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700">
                <i data-lucide="plus" class="mr-2 h-4 w-4"></i>新增商机
            </a>
        </div>
    </div>

    @include('admin.crm.partials.nav', ['currentCrmTab' => 'opportunities_kanban'])

    <section class="mb-5 rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
            <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
                <div>
                    <div class="text-xs font-medium text-gray-500">商机总数</div>
                    <div class="mt-1 text-xl font-semibold text-gray-900">{{ (int) $summary['total'] }}</div>
                </div>
                <div>
                    <div class="text-xs font-medium text-gray-500">活动中</div>
                    <div class="mt-1 text-xl font-semibold text-gray-900">{{ (int) $summary['open'] }}</div>
                </div>
                <div>
                    <div class="text-xs font-medium text-gray-500">总金额</div>
                    <div class="mt-1 text-xl font-semibold text-gray-900">USD {{ number_format((float) $summary['amount'], 0) }}</div>
                </div>
                <div>
                    <div class="text-xs font-medium text-gray-500">未完成待办</div>
                    <div class="mt-1 text-xl font-semibold text-gray-900">{{ (int) $summary['open_tasks'] }}</div>
                </div>
            </div>
            <form method="GET" class="flex flex-col gap-2 sm:flex-row sm:items-center">
                <select name="collection_id" class="min-w-64 rounded-md border border-gray-300 bg-white px-3 py-2 text-sm outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500">
                    <option value="0">全部业务容器</option>
                    @foreach($collectionOptions as $option)
                        <option value="{{ $option['id'] }}" @selected($collectionId === $option['id'])>{{ $option['name'] }}</option>
                    @endforeach
                </select>
                <button class="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">筛选</button>
            </form>
        </div>
    </section>

    <div class="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-4 2xl:grid-cols-7">
        @foreach($stages as $stageKey => $stageLabel)
            @php($rows = $opportunities->get($stageKey, collect()))
            <section class="min-w-0 rounded-lg border border-gray-200 bg-gray-50">
                <div class="border-b border-gray-200 px-3 py-3">
                    <div class="flex items-center justify-between gap-3">
                        <h2 class="text-sm font-semibold text-gray-800">{{ $stageLabel }}</h2>
                        <span class="rounded-full bg-white px-2 py-0.5 text-xs text-gray-500 ring-1 ring-gray-200">{{ $rows->count() }}</span>
                    </div>
                    <div class="mt-1 text-xs text-gray-500">USD {{ number_format((float) $rows->sum('amount'), 0) }}</div>
                </div>

                <div class="space-y-2 p-2">
                    @forelse($rows as $opportunity)
                        @php($nextTask = $opportunity->tasks->first())
                        <a href="{{ route('admin.crm.opportunities.edit', ['opportunityId' => (int) $opportunity->id]) }}" class="block rounded-md border border-gray-200 bg-white p-3 shadow-sm hover:border-blue-300 hover:bg-blue-50/40">
                            <div class="text-sm font-semibold leading-5 text-gray-900">{{ $opportunity->name }}</div>
                            <div class="mt-2 text-xs text-gray-500">{{ $opportunity->customer?->company_name ?: '未关联客户' }}</div>
                            <div class="mt-3 flex flex-wrap items-center gap-2 text-xs">
                                <span class="rounded-full bg-gray-100 px-2 py-0.5 font-medium text-gray-700">{{ $opportunity->currency ?: 'USD' }} {{ number_format((float) $opportunity->amount, 0) }}</span>
                                <span class="rounded-full bg-blue-50 px-2 py-0.5 text-blue-700">{{ (int) $opportunity->probability }}%</span>
                            </div>
                            @if($opportunity->expected_close_date)
                                <div class="mt-2 text-xs text-gray-500">预计成交：{{ $opportunity->expected_close_date->format('Y-m-d') }}</div>
                            @endif
                            @if($opportunity->sourceInquiry)
                                <div class="mt-2 rounded bg-emerald-50 px-2 py-1 text-xs leading-5 text-emerald-800">来源：{{ $opportunity->sourceInquiry->subject }}</div>
                            @endif
                            @if($nextTask)
                                <div class="mt-2 rounded border border-amber-200 bg-amber-50 px-2 py-1 text-xs leading-5 text-amber-800">
                                    下一步：{{ $nextTask->title }}
                                    @if($nextTask->due_at)
                                        <span class="block text-amber-700">{{ $nextTask->due_at->format('m-d H:i') }}</span>
                                    @endif
                                </div>
                            @else
                                <div class="mt-2 text-xs text-gray-400">暂无下一步待办</div>
                            @endif
                        </a>
                    @empty
                        <div class="rounded-md border border-dashed border-gray-300 bg-white px-3 py-8 text-center text-xs text-gray-400">暂无商机</div>
                    @endforelse
                </div>
            </section>
        @endforeach
    </div>
</div>
@endsection
