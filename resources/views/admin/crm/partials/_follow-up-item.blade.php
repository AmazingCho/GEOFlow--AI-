@php
    $followUp = $followUp ?? null;
    if (!$followUp) return;
    $showInquiryLink = $showInquiryLink ?? true;
    $editable = (bool) ($editable ?? false);
    $wasEdited = $followUp->updated_at
        && $followUp->created_at
        && $followUp->updated_at->greaterThan($followUp->created_at);
@endphp

<div class="rounded-md border border-gray-200 px-4 py-3 text-sm">
    <div class="flex items-start justify-between gap-2">
        <div class="flex-1 min-w-0">
            <div class="prose prose-sm max-w-none text-gray-900 follow-up-content">
                @php
                    $html = strip_tags((string) ($followUp->content ?? ''));
                    $html = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $html);
                    $html = preg_replace('/\*(.+?)\*/', '<em>$1</em>', $html);
                    $html = preg_replace('/`([^`]+)`/', '<code class="bg-gray-100 rounded px-1 py-0.5 text-xs text-red-600">$1</code>', $html);
                    $html = preg_replace('/^### (.+)$/m', '<h4 class="text-sm font-semibold mt-2 mb-1">$1</h4>', $html);
                    $html = preg_replace('/^## (.+)$/m', '<h4 class="text-sm font-semibold mt-2 mb-1">$1</h4>', $html);
                    $html = preg_replace('/^- (.+)$/m', '<li class="ml-4 list-disc">$1</li>', $html);
                    $html = nl2br($html);
                @endphp
                {!! $html !!}
            </div>
            @if ((string) ($followUp->next_action ?? '') !== '')
                <div class="mt-1 text-gray-500">下一步：{{ $followUp->next_action }}</div>
            @endif
            <div class="mt-2 flex flex-wrap items-center gap-2 text-xs text-gray-400">
                @if ((string) ($followUp->owner ?? '') !== '')
                    <span class="rounded-full bg-gray-100 px-2 py-0.5">负责人：{{ $followUp->owner }}</span>
                @endif
                @if ($showInquiryLink && $followUp->inquiry_id && ($followUp->inquiry ?? false))
                    <a href="{{ route('admin.crm.inquiries.show', ['inquiryId' => (int) $followUp->inquiry->id]) }}" class="rounded-full bg-blue-50 px-2 py-0.5 text-blue-600 hover:bg-blue-100">询盘：{{ $followUp->inquiry->subject }}</a>
                @endif
                @if ($followUp->next_followup_at)
                    <span class="rounded-full bg-amber-50 px-2 py-0.5 text-amber-700">下次跟进：{{ $followUp->next_followup_at->format('m-d H:i') }}</span>
                @endif
                @if ($followUp->created_at)
                    <span class="text-gray-400">{{ $followUp->created_at->format('Y-m-d H:i') }}</span>
                @endif
                @if ($wasEdited)
                    <span class="text-gray-400">编辑于 {{ $followUp->updated_at->format('Y-m-d H:i') }}</span>
                @endif
            </div>
        </div>
        <div class="flex shrink-0 items-start gap-1.5">
            @if ((string) ($followUp->followup_type ?? '') !== '')
                <span class="rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-500">{{ $followUp->followup_type }}</span>
            @endif
            @if($editable)
                <details class="relative">
                    <summary class="flex h-7 w-7 cursor-pointer list-none items-center justify-center rounded-md border border-gray-200 bg-white text-gray-500 hover:border-blue-200 hover:bg-blue-50 hover:text-blue-700" title="编辑活动记录">
                        <i data-lucide="pencil" class="h-3.5 w-3.5"></i>
                        <span class="sr-only">编辑活动记录</span>
                    </summary>
                    <div class="absolute right-0 z-30 mt-2 w-[min(36rem,calc(100vw-3rem))] rounded-lg border border-gray-200 bg-white p-4 shadow-xl">
                        <div class="mb-3">
                            <div class="font-semibold text-gray-900">编辑活动记录</div>
                            <p class="mt-1 text-xs text-gray-500">修改已经发生的沟通内容，不要在这里安排未来待办。</p>
                        </div>
                        <form method="POST" action="{{ route('admin.crm.follow-ups.update', ['followUpId' => (int) $followUp->id]) }}" class="space-y-3">
                            @csrf
                            @method('PUT')
                            @include('admin.crm.partials._markdown-editor', [
                                'fieldName' => 'content',
                                'rows' => 4,
                                'placeholder' => '活动内容（支持 Markdown）',
                                'value' => (string) $followUp->content,
                            ])
                            <div class="grid gap-3 sm:grid-cols-2">
                                <input type="text" name="followup_type" maxlength="80" value="{{ $followUp->followup_type }}" placeholder="活动类型：电话 / 邮件 / 会议" class="block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500">
                                <input type="text" name="owner" maxlength="120" value="{{ $followUp->owner }}" placeholder="负责人" class="block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500">
                            </div>
                            <div class="flex justify-end">
                                <button type="submit" class="inline-flex items-center rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700">
                                    <i data-lucide="save" class="mr-2 h-4 w-4"></i>
                                    保存修改
                                </button>
                            </div>
                        </form>
                    </div>
                </details>
                <form method="POST" action="{{ route('admin.crm.follow-ups.delete', ['followUpId' => (int) $followUp->id]) }}" onsubmit="return confirm('确认删除这条活动记录？删除后会进入软删除状态。')" class="inline-flex">
                    @csrf
                    <button type="submit" class="flex h-7 w-7 items-center justify-center rounded-md border border-gray-200 bg-white text-gray-500 hover:border-red-200 hover:bg-red-50 hover:text-red-600" title="删除活动记录">
                        <i data-lucide="trash-2" class="h-3.5 w-3.5"></i>
                        <span class="sr-only">删除活动记录</span>
                    </button>
                </form>
            @endif
        </div>
    </div>
</div>
