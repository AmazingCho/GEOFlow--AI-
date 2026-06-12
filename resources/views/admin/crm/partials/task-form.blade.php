<form method="POST" action="{{ route('admin.crm.tasks.store') }}" class="space-y-3">@csrf
    @if(!empty($customer_id ?? null))<input type="hidden" name="customer_id" value="{{ $customer_id }}">@endif
    @if(!empty($inquiry_id ?? null))<input type="hidden" name="inquiry_id" value="{{ $inquiry_id }}">@endif
    @if(!empty($opportunity_id ?? null))<input type="hidden" name="opportunity_id" value="{{ $opportunity_id }}">@endif
    <input type="text" name="title" required placeholder="下一步要完成什么？" class="block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500">
    <div class="grid gap-3 sm:grid-cols-2"><input type="datetime-local" name="due_at" class="block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500"><select name="priority" class="block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500"><option value="normal">普通优先级</option><option value="high">高优先级</option><option value="urgent">紧急</option><option value="low">低优先级</option></select></div>
    <button class="inline-flex w-full items-center justify-center rounded-md bg-gray-900 px-4 py-2 text-sm font-semibold text-white hover:bg-gray-800">创建待办</button>
</form>
