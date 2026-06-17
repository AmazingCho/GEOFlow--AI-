@php
    $sourceType = (string) ($sourceType ?? 'knowledge_base');
    $knowledgeBaseId = (int) ($knowledgeBaseId ?? 0);
    $articleId = (int) ($articleId ?? 0);
    $aiModelOptions = collect($aiModelOptions ?? []);
    $knowledgeBaseOptions = collect($knowledgeBaseOptions ?? []);
    $tone = $sourceType === 'article' ? 'blue' : 'orange';
    $toneClasses = $tone === 'blue'
        ? [
            'card' => 'border-blue-200 bg-blue-50/70',
            'input' => 'focus:border-blue-500 focus:ring-blue-500',
            'button' => 'bg-blue-600 hover:bg-blue-700',
        ]
        : [
            'card' => 'border-orange-200 bg-orange-50/70',
            'input' => 'focus:border-orange-500 focus:ring-orange-500',
            'button' => 'bg-orange-600 hover:bg-orange-700',
        ];
    $cardTitle = $title ?? __('admin.knowledge_corrections.assistant.title');
    $cardDesc = $description ?? __('admin.knowledge_corrections.assistant.desc');
    $inputClass = 'block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm '.$toneClasses['input'];
@endphp

<div class="rounded-xl border {{ $toneClasses['card'] }} p-5">
    <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
        <div>
            <h3 class="text-base font-semibold text-gray-900">{{ $cardTitle }}</h3>
            <p class="mt-1 text-sm leading-6 text-gray-600">{{ $cardDesc }}</p>
        </div>
        <a href="{{ route('admin.knowledge-corrections.index', array_filter([
            'knowledge_base_id' => $knowledgeBaseId ?: null,
            'article_id' => $articleId ?: null,
        ])) }}" class="inline-flex h-9 items-center rounded-md border border-gray-300 bg-white px-3 text-sm font-medium text-gray-700 hover:bg-gray-50">
            <i data-lucide="list-checks" class="mr-2 h-4 w-4"></i>
            {{ __('admin.knowledge_corrections.assistant.view_records') }}
        </a>
    </div>

    <form method="POST" action="{{ route('admin.knowledge-corrections.store') }}" class="mt-5 space-y-4">
        @csrf
        <input type="hidden" name="source_type" value="{{ $sourceType }}">
        @if($knowledgeBaseId > 0)
            <input type="hidden" name="knowledge_base_id" value="{{ $knowledgeBaseId }}">
        @endif
        @if($articleId > 0)
            <input type="hidden" name="article_id" value="{{ $articleId }}">
        @endif

        @if($sourceType === 'article')
            <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
                <div>
                    <label class="block text-sm font-semibold text-gray-800">{{ __('admin.knowledge_corrections.field_selected_article_text') }}</label>
                    <textarea name="selected_article_text" rows="4" data-knowledge-correction-selected-text class="{{ $inputClass }}" placeholder="{{ __('admin.knowledge_corrections.placeholder_selected_article_text') }}"></textarea>
                    <button type="button" data-knowledge-correction-fill-selection class="mt-2 inline-flex items-center rounded-md border border-gray-300 bg-white px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50">
                        <i data-lucide="text-select" class="mr-1.5 h-3.5 w-3.5"></i>
                        {{ __('admin.knowledge_corrections.assistant.use_selection') }}
                    </button>
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-800">{{ __('admin.knowledge_corrections.field_knowledge_base_optional') }}</label>
                    <select name="knowledge_base_id" class="{{ $inputClass }}">
                        <option value="">{{ __('admin.knowledge_corrections.option_auto_source') }}</option>
                        @foreach($knowledgeBaseOptions as $option)
                            <option value="{{ (int) ($option['id'] ?? 0) }}">{{ (string) ($option['name'] ?? '') }}</option>
                        @endforeach
                    </select>
                    <p class="mt-2 text-xs leading-5 text-gray-500">{{ __('admin.knowledge_corrections.help_knowledge_base_optional') }}</p>
                </div>
            </div>
        @endif

        <div>
            <label class="block text-sm font-semibold text-gray-800">{{ __('admin.knowledge_corrections.field_error_description') }} *</label>
            <textarea name="error_description" rows="4" required class="{{ $inputClass }}" placeholder="{{ __('admin.knowledge_corrections.placeholder_error_description') }}">{{ old('error_description') }}</textarea>
        </div>

        <div class="grid grid-cols-1 gap-4 md:grid-cols-[minmax(0,1fr)_auto] md:items-end">
            <div>
                <label class="block text-sm font-semibold text-gray-800">{{ __('admin.knowledge_corrections.field_ai_model') }}</label>
                <select name="ai_model_id" class="{{ $inputClass }}">
                    <option value="0">{{ __('admin.knowledge_corrections.option_auto_model') }}</option>
                    @foreach($aiModelOptions as $modelOption)
                        <option value="{{ (int) ($modelOption['id'] ?? 0) }}">{{ (string) ($modelOption['name'] ?? '') }}</option>
                    @endforeach
                </select>
            </div>
            <button type="submit" class="inline-flex h-10 items-center justify-center rounded-md border border-transparent px-4 text-sm font-medium text-white {{ $toneClasses['button'] }}">
                <i data-lucide="sparkles" class="mr-2 h-4 w-4"></i>
                {{ __('admin.knowledge_corrections.assistant.submit') }}
            </button>
        </div>
    </form>
</div>

@if($sourceType === 'article')
    @once
        @push('scripts')
            <script>
                document.addEventListener('click', function (event) {
                    const button = event.target.closest('[data-knowledge-correction-fill-selection]');
                    if (!button) {
                        return;
                    }
                    const form = button.closest('form');
                    const textarea = form?.querySelector('[data-knowledge-correction-selected-text]');
                    if (!textarea) {
                        return;
                    }
                    const selected = String(window.getSelection ? window.getSelection().toString() : '').trim();
                    if (selected !== '') {
                        textarea.value = selected;
                        textarea.focus();
                    }
                });
            </script>
        @endpush
    @endonce
@endif
