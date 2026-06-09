@php
    $fieldName = $fieldName ?? 'content';
    $rows = $rows ?? 5;
    $placeholder = $placeholder ?? '';
@endphp

<div x-data="markdownEditor()" class="rounded-md border border-gray-300 overflow-hidden">
    <div class="flex items-center gap-1 border-b border-gray-200 bg-gray-50 px-2 py-1.5">
        <div class="flex items-center gap-0.5 pr-2 border-r border-gray-200">
            <button type="button" @click="mode='write'" :class="mode==='write' ? 'bg-white shadow-sm' : ''" class="rounded px-2 py-1 text-xs font-medium text-gray-600 hover:bg-white transition">编辑</button>
            <button type="button" @click="mode='preview'" :class="mode==='preview' ? 'bg-white shadow-sm' : ''" class="rounded px-2 py-1 text-xs font-medium text-gray-600 hover:bg-white transition">预览</button>
            <button type="button" @click="mode='code'" :class="mode==='code' ? 'bg-white shadow-sm' : ''" class="rounded px-2 py-1 text-xs font-medium text-gray-600 hover:bg-white transition">源码</button>
        </div>
        <div class="flex items-center gap-0.5" x-show="mode !== 'preview'">
            <button type="button" @click="insertMarkdown('bold')" class="rounded px-1.5 py-0.5 text-xs font-bold text-gray-500 hover:bg-gray-200" title="加粗">B</button>
            <button type="button" @click="insertMarkdown('italic')" class="rounded px-1.5 py-0.5 text-xs italic text-gray-500 hover:bg-gray-200" title="斜体">I</button>
            <button type="button" @click="insertMarkdown('heading')" class="rounded px-1.5 py-0.5 text-xs text-gray-500 hover:bg-gray-200" title="标题">H</button>
            <button type="button" @click="insertMarkdown('link')" class="rounded px-1.5 py-0.5 text-xs text-gray-500 hover:bg-gray-200" title="链接">&#128279;</button>
            <button type="button" @click="insertMarkdown('code')" class="rounded px-1.5 py-0.5 text-xs text-gray-500 hover:bg-gray-200" title="行内代码">&lt;/&gt;</button>
            <button type="button" @click="insertMarkdown('list')" class="rounded px-1.5 py-0.5 text-xs text-gray-500 hover:bg-gray-200" title="列表">&bull;</button>
        </div>
    </div>

    {{-- Write / Code (shared container, modes toggled by x-show) --}}
    <div x-show="mode === 'write'" style="display:block">
        <textarea
            x-ref="textarea"
            x-model="content"
            name="{{ $fieldName }}"
            rows="{{ $rows }}"
            placeholder="{{ $placeholder }}"
            class="block w-full border-0 px-3 py-2 text-sm focus:ring-0 resize-y"
        ></textarea>
    </div>
    <div x-show="mode === 'code'" style="display:none">
        <div class="block w-full border-0 px-3 py-2 text-sm font-mono whitespace-pre-wrap min-h-[120px] bg-gray-50" style="font-size:13px;" x-text="content"></div>
    </div>

    {{-- Preview --}}
    <div x-show="mode === 'preview'" style="display:none" class="px-3 py-2 text-sm text-gray-700 min-h-[120px] bg-white prose prose-sm max-w-none" x-html="previewHtml"></div>
</div>

<script>
function markdownEditor() {
    return {
        mode: 'write',
        content: '',

        get previewHtml() {
            const t = (this.content || '').trim();
            if (!t) return '<span class="text-gray-400">暂无内容</span>';
            let html = t.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
            html = html.replace(/```(\w*)\n([\s\S]*?)```/g, '<pre class="bg-gray-100 rounded p-3 my-2 text-xs overflow-x-auto"><code>$2</code></pre>');
            html = html.replace(/`([^`]+)`/g, '<code class="bg-gray-100 rounded px-1.5 py-0.5 text-xs text-red-600">$1</code>');
            html = html.replace(/^### (.+)$/gm, '<h4 class="text-sm font-semibold mt-3 mb-1">$1</h4>');
            html = html.replace(/^## (.+)$/gm, '<h3 class="text-base font-semibold mt-3 mb-1">$1</h3>');
            html = html.replace(/^# (.+)$/gm, '<h3 class="text-base font-bold mt-3 mb-2">$1</h3>');
            html = html.replace(/\*\*\*(.+?)\*\*\*/g, '<strong><em>$1</em></strong>');
            html = html.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
            html = html.replace(/\*(.+?)\*/g, '<em>$1</em>');
            html = html.replace(/\[([^\]]+)\]\(([^)]+)\)/g, '<a href="$2" class="text-blue-600 underline" target="_blank">$1</a>');
            html = html.replace(/^- (.+)$/gm, '<li class="ml-4 list-disc">$1</li>');
            html = html.replace(/^\d+\. (.+)$/gm, '<li class="ml-4 list-decimal">$1</li>');
            html = html.replace(/\n\n/g, '</p><p class="mb-2">');
            html = html.replace(/\n/g, '<br>');
            return '<p class="mb-2">' + html + '</p>';
        },

        insertMarkdown(syntax) {
            const ta = this.$refs.textarea;
            const start = ta.selectionStart;
            const end = ta.selectionEnd;
            const selected = this.content.substring(start, end);
            let replacement = '';
            switch(syntax) {
                case 'bold': replacement = '**' + (selected || 'bold text') + '**'; break;
                case 'italic': replacement = '*' + (selected || 'italic text') + '*'; break;
                case 'link': replacement = '[' + (selected || 'link text') + '](url)'; break;
                case 'code': replacement = '`' + (selected || 'code') + '`'; break;
                case 'heading': replacement = '## ' + (selected || 'heading'); break;
                case 'list': replacement = '- ' + (selected || 'item'); break;
                case 'codeblock': replacement = '```\n' + (selected || 'code block') + '\n```'; break;
                default: replacement = selected;
            }
            this.content = this.content.substring(0, start) + replacement + this.content.substring(end);
            this.$nextTick(() => {
                ta.focus();
                ta.setSelectionRange(start + replacement.length, start + replacement.length);
            });
        }
    };
}
</script>
