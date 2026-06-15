<div class="rounded-md border border-gray-200 bg-gray-50 p-3" data-activity-task-fields>
    <label class="flex cursor-pointer items-center gap-2 text-sm font-medium text-gray-700">
        <input type="checkbox" name="create_task" value="1" class="h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500" data-activity-task-toggle>
        同时创建下一步待办
    </label>
    <div class="mt-3 hidden space-y-3" data-activity-task-panel>
        <input type="text" name="task_title" maxlength="240" placeholder="下一步要完成什么？" class="block w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm shadow-sm outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500">
        <div class="grid gap-3 sm:grid-cols-2">
            <input type="datetime-local" name="task_due_at" class="block w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm shadow-sm outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500">
            <select name="task_priority" class="block w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm shadow-sm outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500">
                <option value="normal">普通优先级</option><option value="high">高优先级</option><option value="urgent">紧急</option><option value="low">低优先级</option>
            </select>
        </div>
    </div>
</div>

@once
@push('scripts')
<script>
document.addEventListener('change', (event) => {
    const toggle = event.target.closest('[data-activity-task-toggle]');
    if (!toggle) return;
    const root = toggle.closest('[data-activity-task-fields]');
    const panel = root?.querySelector('[data-activity-task-panel]');
    panel?.classList.toggle('hidden', !toggle.checked);
    const title = panel?.querySelector('input[name="task_title"]');
    if (title) title.required = toggle.checked;
});
</script>
@endpush
@endonce
