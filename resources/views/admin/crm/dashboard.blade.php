@extends('admin.layouts.app')
@section('content')
<div class="px-4 sm:px-0">
    <div class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div><h1 class="text-2xl font-bold text-gray-900">CRM 工作台</h1><p class="mt-1 text-sm text-gray-500">先处理到期事项，再推进商机和询盘。</p></div>
        <div class="flex gap-2"><a href="{{ route('admin.crm.inquiries.create') }}" class="inline-flex items-center rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700"><i data-lucide="plus" class="mr-2 h-4 w-4"></i>新增询盘</a><a href="{{ route('admin.crm.opportunities.create') }}" class="inline-flex items-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">新增商机</a></div>
    </div>
    @include('admin.crm.partials.nav', ['currentCrmTab'=>'dashboard'])
    <div class="grid grid-cols-2 gap-3 lg:grid-cols-5">
        @foreach ([['待处理',$stats['open_tasks'],'clipboard-list'],['已逾期',$stats['overdue_tasks'],'alarm-clock'],['进行中商机',$stats['open_opportunities'],'briefcase-business'],['进行中订单',$stats['open_orders'],'package-check'],['待处理售后',$stats['open_tickets'],'wrench']] as $card)
        <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm"><div class="flex items-center justify-between"><span class="text-sm text-gray-500">{{ $card[0] }}</span><i data-lucide="{{ $card[2] }}" class="h-4 w-4 text-gray-400"></i></div><div class="mt-2 text-2xl font-semibold text-gray-900">{{ $card[1] }}</div></div>
        @endforeach
    </div>
    <div class="mt-6 grid gap-6 xl:grid-cols-[minmax(0,1.2fr)_minmax(360px,.8fr)]">
        <section class="rounded-lg border border-gray-200 bg-white shadow-sm"><div class="border-b border-gray-100 px-5 py-4"><h2 class="font-semibold text-gray-900">今天与逾期待办</h2></div><div class="divide-y divide-gray-100">
            @forelse ($overdueTasks->concat($todayTasks)->unique('id') as $task)
                @include('admin.crm.partials.task-row', ['task'=>$task])
            @empty <div class="px-5 py-10 text-center text-sm text-gray-500">今天没有待办，节奏很好。</div> @endforelse
        </div></section>
        <section class="rounded-lg border border-gray-200 bg-white shadow-sm"><div class="border-b border-gray-100 px-5 py-4"><h2 class="font-semibold text-gray-900">最近询盘</h2></div><div class="divide-y divide-gray-100">@forelse($recentInquiries as $inquiry)<a href="{{ route('admin.crm.inquiries.show',['inquiryId'=>$inquiry->id]) }}" class="block px-5 py-4 hover:bg-gray-50"><div class="font-medium text-gray-900">{{ $inquiry->subject }}</div><div class="mt-1 text-xs text-gray-500">{{ $inquiry->customer?->company_name ?: '未关联客户' }} · {{ $inquiry->created_at?->format('m-d H:i') }}</div></a>@empty<div class="px-5 py-10 text-center text-sm text-gray-500">暂无询盘</div>@endforelse</div></section>
    </div>
</div>
@endsection
