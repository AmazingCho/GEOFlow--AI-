<div class="flex items-start gap-3 px-5 py-4">
    @if($task->status === 'done')
        <form method="POST" action="{{ route('admin.crm.tasks.reopen', ['taskId' => $task->id]) }}">
            @csrf
            <button type="submit" title="重新打开" class="mt-0.5 flex h-7 w-7 items-center justify-center rounded-full border border-emerald-300 bg-emerald-50 text-emerald-700"><i data-lucide="check" class="h-4 w-4"></i></button>
        </form>
    @else
        <details class="relative shrink-0">
            <summary class="mt-0.5 flex h-7 w-7 cursor-pointer list-none items-center justify-center rounded-full border border-gray-300 bg-white text-gray-400 hover:border-emerald-400 hover:text-emerald-600" title="完成待办"><i data-lucide="check" class="h-4 w-4"></i></summary>
            <div class="absolute left-0 z-40 mt-2 w-[min(32rem,calc(100vw-3rem))] rounded-lg border border-gray-200 bg-white p-4 shadow-xl">
                <div class="font-semibold text-gray-900">完成待办</div>
                <p class="mt-1 text-xs leading-5 text-gray-500">可直接完成，也可以填写实际结果并同步到活动时间线。</p>
                <form method="POST" action="{{ route('admin.crm.tasks.complete', ['taskId' => $task->id]) }}" class="mt-3 space-y-3">
                    @csrf
                    <textarea name="result_content" rows="4" placeholder="完成结果（可选，填写后会生成活动记录）" class="block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500"></textarea>
                    @include('admin.crm.partials._activity-type-select', ['value' => 'task_completed'])
                    <input type="text" name="followup_type" maxlength="80" value="待办结果" placeholder="补充备注（可选）" class="block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500">
                    <button type="submit" class="inline-flex w-full items-center justify-center rounded-md bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700">确认完成</button>
                </form>
            </div>
        </details>
    @endif
    <div class="min-w-0 flex-1">
        <div class="font-medium {{ $task->status === 'done' ? 'text-gray-400 line-through' : 'text-gray-900' }}">{{ $task->title }}</div>
        <div class="mt-1 flex flex-wrap gap-x-3 gap-y-1 text-xs text-gray-500">
            <span class="{{ $task->due_at && $task->due_at->isPast() && $task->status === 'open' ? 'font-semibold text-red-600' : '' }}">{{ $task->due_at?->format('Y-m-d H:i') ?: '未设截止时间' }}</span>
            @if($task->customer)<span>{{ $task->customer->company_name }}</span>@endif
            @if($task->inquiry)<a class="text-blue-600" href="{{ route('admin.crm.inquiries.show', ['inquiryId' => $task->inquiry_id]) }}">{{ $task->inquiry->subject }}</a>@endif
            @if($task->opportunity)<a class="text-blue-600" href="{{ route('admin.crm.opportunities.edit', ['opportunityId' => $task->opportunity_id]) }}">{{ $task->opportunity->name }}</a>@endif
        </div>
    </div>
</div>
