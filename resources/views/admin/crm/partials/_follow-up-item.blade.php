@php
    $followUp = $followUp ?? null;
    if (!$followUp) return;
    $showInquiryLink = $showInquiryLink ?? true;
@endphp

<div class="group rounded-md border border-gray-200 px-4 py-3 text-sm">
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
            </div>
        </div>
        <div class="flex shrink-0 items-start gap-1.5">
            @if ((string) ($followUp->followup_type ?? '') !== '')
                <span class="rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-500">{{ $followUp->followup_type }}</span>
            @endif
            <form method="POST" action="{{ route('admin.crm.follow-ups.delete', ['followUpId' => (int) $followUp->id]) }}" onsubmit="return confirm('确认删除这条跟进记录？')" class="inline-flex">
                @csrf
                <button type="submit" class="rounded p-0.5 text-gray-300 opacity-0 group-hover:opacity-100 hover:text-red-500 hover:bg-red-50 transition" title="删除">
                    <i data-lucide="trash-2" class="h-3.5 w-3.5"></i>
                </button>
            </form>
        </div>
    </div>
</div>
