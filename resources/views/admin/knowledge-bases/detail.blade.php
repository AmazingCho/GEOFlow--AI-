@extends('admin.layouts.app')

@php
    $fieldClass = 'w-full rounded-lg border border-gray-300 bg-white px-3 py-2.5 text-sm leading-6 shadow-sm transition focus:border-orange-500 focus:ring-2 focus:ring-orange-200';
    $textareaClass = 'w-full rounded-lg border border-gray-300 bg-white px-3 py-3 text-sm leading-6 shadow-sm transition focus:border-orange-500 focus:ring-2 focus:ring-orange-200';
    $labelClass = 'block text-sm font-semibold text-gray-800 mb-2';
@endphp

@section('content')
    <div class="px-4 sm:px-0">
        <div class="mb-8 flex items-center justify-between">
            <div class="flex items-center space-x-4">
                <a href="{{ route('admin.knowledge-bases.index') }}" class="text-gray-400 hover:text-gray-600">
                    <i data-lucide="arrow-left" class="w-5 h-5"></i>
                </a>
                <div>
                    <h1 class="text-2xl font-bold text-gray-900">{{ __('admin.knowledge_detail.heading') }}</h1>
                    <p class="mt-1 text-sm text-gray-600">{{ $knowledgeBase->name }}</p>
                    <div class="mt-2">
                        <span class="inline-flex rounded-full bg-slate-100 px-2.5 py-1 text-xs font-medium text-slate-700">
                            {{ $knowledgeBase->collection?->name ?? __('admin.collections.badge_unassigned') }}
                        </span>
                    </div>
                </div>
            </div>
        </div>

        <div class="mb-8 rounded-xl border border-gray-200 bg-white shadow-sm">
            <div class="px-8 py-5 border-b border-gray-200">
                <h3 class="text-lg font-medium text-gray-900">{{ __('admin.knowledge_detail.content_title') }}</h3>
            </div>
            <form method="POST" action="{{ route('admin.knowledge-bases.detail.update', ['knowledgeBaseId' => (int) $knowledgeBase->id]) }}" class="space-y-7 p-8" data-ai-analysis-form data-ai-analysis-url="{{ route('admin.knowledge-bases.analyze') }}">
                @csrf
                @method('PUT')
                <div class="rounded-xl border border-orange-200 bg-orange-50/80 p-5">
                    <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                        <div>
                            <h3 class="text-base font-semibold text-orange-950">{{ __('admin.knowledge_bases.ai_classify_title') }}</h3>
                            <p class="mt-1 text-sm text-orange-800">{{ __('admin.knowledge_bases.ai_classify_desc') }}</p>
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                            <select data-ai-analysis-model class="rounded-lg border border-orange-300 bg-white px-3 py-2.5 text-sm shadow-sm transition focus:border-orange-500 focus:ring-2 focus:ring-orange-200">
                                <option value="0">{{ __('admin.knowledge_bases.ai_classify_auto_model') }}</option>
                                @foreach ($aiModelOptions ?? [] as $modelOption)
                                    <option value="{{ (int) $modelOption['id'] }}">{{ $modelOption['name'] }}</option>
                                @endforeach
                            </select>
                            <button type="button" data-ai-analysis-submit class="inline-flex items-center justify-center rounded-md border border-transparent bg-orange-600 px-4 py-2 text-sm font-medium text-white hover:bg-orange-700">
                                <i data-lucide="sparkles" class="mr-2 h-4 w-4"></i>
                                {{ __('admin.knowledge_bases.ai_classify_button') }}
                            </button>
                        </div>
                    </div>
                    <textarea data-ai-analysis-content rows="5" class="mt-5 block w-full rounded-lg border border-orange-300 bg-white px-3 py-3 text-sm leading-6 shadow-sm transition focus:border-orange-500 focus:ring-2 focus:ring-orange-200" placeholder="{{ __('admin.knowledge_bases.ai_classify_placeholder') }}"></textarea>
                    @include('admin.partials.material-ai-analysis-instructions')
                    <p data-ai-analysis-status class="mt-2 hidden text-sm text-orange-800"></p>
                    <p data-ai-analysis-tags class="mt-2 hidden text-xs text-orange-700"></p>
                </div>
                <div class="grid grid-cols-1 gap-5 md:grid-cols-2">
                    <div>
                        <label class="{{ $labelClass }}">{{ __('admin.knowledge_detail.field_name') }}</label>
                        <input type="text" name="name" value="{{ old('name', (string) $knowledgeBase->name) }}" data-tag-source="knowledge-detail" class="{{ $fieldClass }}" required>
                    </div>
                    <div>
                        <label class="{{ $labelClass }}">{{ __('admin.knowledge_bases.field_doc_type') }}</label>
                        <select name="file_type" class="{{ $fieldClass }}" required>
                            <option value="markdown" @selected(old('file_type', (string) ($knowledgeBase->file_type ?? 'markdown')) === 'markdown')>{{ __('admin.status.markdown') }}</option>
                            <option value="word" @selected(old('file_type', (string) ($knowledgeBase->file_type ?? 'markdown')) === 'word')>{{ __('admin.status.word_document') }}</option>
                            <option value="text" @selected(old('file_type', (string) ($knowledgeBase->file_type ?? 'markdown')) === 'text')>{{ __('admin.status.text') }}</option>
                        </select>
                    </div>
                </div>
                @include('admin.partials.collection-select', [
                    'selectedId' => (string) ((int) ($knowledgeBase->collection_id ?? 0) ?: ''),
                    'collectionOptions' => $collectionOptions ?? [],
                    'class' => $fieldClass,
                ])
                @include('admin.knowledge-bases.partials.metadata-fields', [
                    'class' => $fieldClass,
                    'knowledgeBase' => $knowledgeBase,
                    'knowledgeTypeOptions' => $knowledgeTypeOptions ?? [],
                    'knowledgeRoleOptions' => $knowledgeRoleOptions ?? [],
                    'importanceOptions' => $importanceOptions ?? [],
                    'showRoleHelp' => true,
                ])
                <div class="grid grid-cols-1 gap-5 md:grid-cols-2">
                    <div>
                        <label class="{{ $labelClass }}">{{ __('admin.knowledge_bases.field_source_url') }}</label>
                        <input type="url" name="source_url" value="{{ old('source_url', (string) ($knowledgeBase->source_url ?? '')) }}" data-tag-source="knowledge-detail" class="{{ $fieldClass }}" placeholder="https://example.com/source">
                    </div>
                    <div>
                        <label class="{{ $labelClass }}">{{ __('admin.common.status') }}</label>
                        <select name="status" class="{{ $fieldClass }}">
                            @foreach ($statusOptions ?? [] as $option)
                                <option value="{{ $option['value'] }}" @selected(old('status', (string) ($knowledgeBase->status ?? 'active')) === $option['value'])>{{ $option['label'] }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div>
                    <label class="{{ $labelClass }}">{{ __('admin.knowledge_detail.field_description') }}</label>
                    <textarea name="description" rows="3" data-tag-source="knowledge-detail" class="{{ $textareaClass }}">{{ old('description', (string) ($knowledgeBase->description ?? '')) }}</textarea>
                </div>
                <div>
                    <label class="{{ $labelClass }}">{{ __('admin.knowledge_bases.field_summary') }}</label>
                    <textarea name="summary" rows="3" data-tag-source="knowledge-detail" class="{{ $textareaClass }}" placeholder="{{ __('admin.knowledge_bases.placeholder_summary') }}">{{ old('summary', (string) ($knowledgeBase->summary ?? '')) }}</textarea>
                </div>
                <div>
                    <label class="{{ $labelClass }}">{{ __('admin.knowledge_bases.field_entity_relation') }}</label>
                    <p class="mb-3 text-xs leading-5 text-gray-500">{{ __('admin.knowledge_bases.entity_relation_help') }}</p>
                    @include('admin.partials.relation-multi-selector', [
                        'selectorName' => 'entity_ids',
                        'options' => $entityOptions ?? [],
                        'selectedIds' => old('entity_ids', $selectedEntityIds ?? []),
                        'relationFieldName' => 'entity_relation_types',
                        'defaultRelationFieldName' => 'entity_relation_type',
                        'defaultRelationType' => (string) ($entityRelationType ?? 'supporting_reference'),
                        'relationTypesById' => $entityRelationTypesById ?? [],
                        'relationOptions' => $knowledgeRelationTypeOptions ?? [],
                        'tone' => 'orange',
                        'placeholder' => __('admin.entities.selector_placeholder'),
                        'emptyText' => __('admin.entities.no_entity_options'),
                        'noneSelectedText' => __('admin.entities.selector_none_selected'),
                        'removeText' => __('admin.entities.selector_remove'),
                        'relationSuffixText' => __('admin.knowledge_bases.relation_suffix'),
                        'helpText' => __('admin.knowledge_bases.entity_relation_per_item_help'),
                    ])
                </div>
                <div>
                    <label class="{{ $labelClass }}">{{ __('admin.knowledge_detail.field_content') }}</label>
                    <textarea name="content" rows="18" data-tag-source="knowledge-detail" class="{{ $textareaClass }}" required>{{ old('content', (string) ($knowledgeBase->content ?? '')) }}</textarea>
                </div>
                <div class="flex justify-end">
                    <button type="submit" class="inline-flex items-center px-4 py-2 border border-transparent rounded-md text-sm text-white bg-orange-600 hover:bg-orange-700">
                        <i data-lucide="save" class="w-4 h-4 mr-2"></i>
                        {{ __('admin.knowledge_detail.save_changes') }}
                    </button>
                </div>
            </form>
        </div>

        <div class="bg-white shadow rounded-lg mb-6">
            <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-medium text-gray-900">{{ __('admin.knowledge_detail.tags_title') }}</h3>
            </div>
            <form method="POST" action="{{ route('admin.knowledge-bases.tags', ['knowledgeBaseId' => (int) $knowledgeBase->id]) }}" class="p-6">
                @csrf
                @include('admin.partials.tag-selector', [
                    'name' => 'tag_ids',
                    'tagOptions' => $tagOptions ?? [],
                    'selectedTagIds' => $selectedTagIds ?? [],
                    'tone' => 'orange',
                    'autoSubmit' => true,
                ])
            </form>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6">
            <div class="bg-white shadow rounded-lg p-5">
                <div class="text-sm text-gray-500">{{ __('admin.knowledge_detail.chunk_count') }}</div>
                <div class="mt-2 text-2xl font-semibold text-gray-900">{{ number_format((int) ($chunkStats['chunk_count'] ?? 0)) }}</div>
            </div>
            <div class="bg-white shadow rounded-lg p-5">
                <div class="text-sm text-gray-500">{{ __('admin.knowledge_detail.vectorized_count') }}</div>
                <div class="mt-2 text-2xl font-semibold text-gray-900">{{ number_format((int) ($chunkStats['vectorized_count'] ?? 0)) }}</div>
            </div>
            <div class="bg-white shadow rounded-lg p-5">
                <div class="text-sm text-gray-500">{{ __('admin.knowledge_detail.updated_at') }}</div>
                <div class="mt-2 text-sm font-medium text-gray-900">{{ optional($knowledgeBase->updated_at)->format('Y-m-d H:i:s') ?? '-' }}</div>
            </div>
        </div>

        <div class="bg-white shadow rounded-lg mb-6">
            <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-medium text-gray-900">{{ __('admin.common.related_tasks') }}</h3>
            </div>
            @if ($relatedTasks->isEmpty())
                <div class="px-6 py-5 text-sm text-gray-500">{{ __('admin.knowledge_detail.related_tasks_empty') }}</div>
            @else
                <div class="divide-y divide-gray-200">
                    @foreach ($relatedTasks as $task)
                        <div class="px-6 py-4 flex items-center justify-between">
                            <div class="text-sm text-gray-900">#{{ (int) $task->id }} {{ $task->name }}</div>
                            <div class="text-xs text-gray-500">{{ $task->status }}</div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>

        <div id="chunk-preview" class="bg-white shadow rounded-lg overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-medium text-gray-900">{{ __('admin.knowledge_detail.chunk_preview_title') }}</h3>
                <p class="mt-1 text-sm text-gray-500">{{ __('admin.knowledge_detail.chunk_preview_desc') }}</p>
            </div>
            @if ($chunkPreviewRows->isEmpty())
                <div class="px-6 py-8 text-sm text-gray-500">{{ __('admin.knowledge_detail.chunk_preview_empty') }}</div>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('admin.knowledge_detail.chunk_index') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('admin.knowledge_detail.chunk_status') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('admin.knowledge_detail.chunk_length') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('admin.knowledge_detail.chunk_tokens') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('admin.knowledge_detail.chunk_embedding') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('admin.knowledge_detail.chunk_preview_column') }}</th>
                        </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                        @foreach ($chunkPreviewRows as $chunkRow)
                            @php
                                $isVectorized = $chunkRow['embedding_model_id'] !== null && (int) $chunkRow['embedding_dimensions'] > 0;
                            @endphp
                            <tr>
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">#{{ (int) $chunkRow['chunk_index'] }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700">
                                    @if ($isVectorized)
                                        <span class="inline-flex px-2 py-0.5 rounded bg-green-100 text-green-700">{{ __('admin.knowledge_detail.chunk_status_vectorized') }}</span>
                                    @else
                                        <span class="inline-flex px-2 py-0.5 rounded bg-amber-100 text-amber-700">{{ __('admin.knowledge_detail.chunk_status_fallback') }}</span>
                                    @endif
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700">{{ __('admin.knowledge_bases.text_unit', ['count' => (int) $chunkRow['content_length']]) }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700">{{ number_format((int) $chunkRow['token_count']) }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700">
                                    @if ($isVectorized)
                                        {{ __('admin.knowledge_detail.chunk_embedding_meta', ['model_id' => (int) $chunkRow['embedding_model_id'], 'dimensions' => (int) $chunkRow['embedding_dimensions']]) }}
                                    @else
                                        {{ __('admin.knowledge_detail.chunk_embedding_none') }}
                                    @endif
                                </td>
                                <td class="px-6 py-4 text-sm text-gray-700">
                                    <div class="mb-2 flex flex-wrap items-center gap-2">
                                        <span class="inline-flex rounded bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-700">
                                            {{ __('admin.knowledge_detail.chunk_strategy_label') }}:
                                            {{ __('admin.knowledge_detail.chunk_strategy_'.$chunkRow['chunk_strategy']) }}
                                        </span>
                                        @if ($chunkRow['chunk_title'] !== '')
                                            <span class="text-xs font-medium text-gray-700">{{ $chunkRow['chunk_title'] }}</span>
                                        @endif
                                    </div>
                                    @if ($chunkRow['section_path'] !== '')
                                        <div class="mb-2 text-xs text-gray-500">{{ $chunkRow['section_path'] }}</div>
                                    @endif
                                    <div class="max-w-xl break-words">{{ $chunkRow['content_preview'] }}</div>
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
@endsection

@include('admin.partials.material-ai-analysis-script')
