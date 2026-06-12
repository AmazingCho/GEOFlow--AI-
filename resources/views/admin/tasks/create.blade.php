@extends('admin.layouts.app')

@php
    $isEdit = (bool) ($isEdit ?? false);
    $taskForm = is_array($taskForm ?? null) ? $taskForm : [];
    $hasCategories = (bool) ($hasCategories ?? true);
    $categoryCreateUrl = (string) ($categoryCreateUrl ?? route('admin.categories.create'));
    $t = static fn (string $key, array $replace = []): string => __("admin.$key", $replace);
    $selectedDistributionChannelIds = collect(old('distribution_channel_ids', $taskForm['distribution_channel_ids'] ?? []))
        ->map(static fn ($id): string => (string) $id)
        ->all();
    $publishScope = (string) old('publish_scope', (string) ($taskForm['publish_scope'] ?? 'local_and_distribution'));
    $distributionChannelsDisabled = $publishScope === 'local_only';
    $storedKnowledgeTagFilter = (string) ($taskForm['knowledge_tag_filter'] ?? '');
    $selectedKnowledgeTagFilters = old('knowledge_tag_filters', null);
    if (! is_array($selectedKnowledgeTagFilters)) {
        $selectedKnowledgeTagFilters = $storedKnowledgeTagFilter !== ''
            ? preg_split('/\s*,\s*/u', $storedKnowledgeTagFilter, -1, PREG_SPLIT_NO_EMPTY)
            : [];
    }
    $selectedKnowledgeTagFilters = collect($selectedKnowledgeTagFilters)
        ->map(static fn ($value): string => (string) $value)
        ->all();
    $storedImageTagFilter = (string) ($taskForm['image_tag_filter'] ?? '');
    $selectedImageTagFilters = old('image_tag_filters', null);
    if (! is_array($selectedImageTagFilters)) {
        $selectedImageTagFilters = $storedImageTagFilter !== ''
            ? preg_split('/\s*,\s*/u', $storedImageTagFilter, -1, PREG_SPLIT_NO_EMPTY)
            : [];
    }
    $selectedImageTagFilters = collect($selectedImageTagFilters)
        ->map(static fn ($value): string => (string) $value)
        ->all();
    $selectedTaskEntityIds = collect(old('entity_ids', $taskForm['entity_ids'] ?? []))
        ->map(static fn ($id): int => (int) $id)
        ->filter(static fn (int $id): bool => $id > 0)
        ->values()
        ->all();
    $selectedTaskCaseIds = collect(old('case_ids', $taskForm['case_ids'] ?? []))
        ->map(static fn ($id): int => (int) $id)
        ->filter(static fn (int $id): bool => $id > 0)
        ->values()
        ->all();
    $selectedCrmSourceType = (string) old('crm_source_type', (string) ($taskForm['crm_source_type'] ?? ''));
    $selectedCrmSourceId = (string) old('crm_source_id', (string) ($taskForm['crm_source_id'] ?? ''));
    $controlledTagGroups = collect($formOptions['controlledTagGroups'] ?? ['Topic', 'Audience', 'Intent'])
        ->map(static fn ($group): string => (string) $group)
        ->filter(static fn (string $group): bool => $group !== '')
        ->values()
        ->all();
    $controlledTagFieldName = static function (string $groupName): string {
        return match ($groupName) {
            'Product Line' => 'product_line_tag_filters',
            'Product Model' => 'product_model_tag_filters',
            'Industry' => 'industry_tag_filters',
            'Topic' => 'topic_tag_filters',
            'Content Type' => 'content_type_tag_filters',
            'Audience' => 'audience_tag_filters',
            'Intent' => 'intent_tag_filters',
            default => 'controlled_tag_filters_'.\Illuminate\Support\Str::slug($groupName, '_'),
        };
    };
    $genericKnowledgeTagFilters = collect($selectedKnowledgeTagFilters)
        ->reject(static function (string $label) use ($controlledTagGroups): bool {
            foreach ($controlledTagGroups as $groupName) {
                if (str_starts_with($label, $groupName.':')) {
                    return true;
                }
            }

            return false;
        })
        ->values()
        ->all();
    $selectedGroupTagFilters = static function (string $fieldName, string $groupName) use ($selectedKnowledgeTagFilters): array {
        $oldValue = old($fieldName, null);
        if (is_array($oldValue)) {
            return collect($oldValue)
                ->map(static fn ($value): string => trim((string) $value))
                ->filter(static fn (string $value): bool => $value !== '')
                ->values()
                ->all();
        }

        return collect($selectedKnowledgeTagFilters)
            ->filter(static fn (string $label): bool => str_starts_with($label, $groupName.':'))
            ->values()
            ->all();
    };
    $crossCollectionMode = old('cross_collection_mode', (string) ($taskForm['cross_collection_mode'] ?? '0')) === '1';
    $hasImageConfiguration = (string) old('image_library_id', (string) ($taskForm['image_library_id'] ?? '')) !== ''
        || count($selectedImageTagFilters) > 0;
    $hasDistributionConfiguration = count($selectedDistributionChannelIds) > 0
        || $publishScope !== 'local_and_distribution';
    $fieldClass = 'mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500';
    $compactFieldClass = 'block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500';
@endphp

@section('content')
    <div class="px-4 sm:px-0">
        <div class="flex items-center justify-between mb-6">
            <div class="flex items-center space-x-4">
                <a href="{{ route('admin.tasks.index') }}" class="text-gray-400 hover:text-gray-600">
                    <i data-lucide="arrow-left" class="w-5 h-5"></i>
                </a>
                <div>
                    <h1 class="text-2xl font-bold text-gray-900">{{ $isEdit ? $t('task_edit.page_heading') : $t('task_create.page_heading') }}</h1>
                    <p class="mt-1 text-sm text-gray-600">{{ $t('task_create.page_subtitle') }}</p>
                </div>
            </div>
        </div>

        <div data-task-form-shell class="w-full">
            @if (! $hasCategories)
                <div class="bg-amber-50 border border-amber-200 rounded-lg p-5">
                    <h3 class="text-base font-semibold text-amber-900">{{ $t('task_create.error.no_categories_configured') }}</h3>
                    <p class="mt-2 text-sm text-amber-800">{{ $t('task_create.help.no_categories_configured') }}</p>
                    <div class="mt-4">
                        <a href="{{ $categoryCreateUrl }}" class="inline-flex items-center px-4 py-2 border border-amber-300 rounded-md text-sm font-medium text-amber-900 bg-white hover:bg-amber-100">
                            <i data-lucide="folder-plus" class="w-4 h-4 mr-2"></i>
                            {{ $t('categories.add') }}
                        </a>
                    </div>
                </div>
            @else
            <div class="mb-5 flex flex-col gap-3 rounded-lg border border-gray-200 bg-white px-4 py-3 shadow-sm lg:flex-row lg:items-center lg:justify-between">
                <div class="flex flex-wrap items-center gap-3 text-xs font-medium">
                    <span class="inline-flex items-center gap-1.5 text-red-700"><span class="h-2 w-2 rounded-full bg-red-500"></span>必填</span>
                    <span class="inline-flex items-center gap-1.5 text-emerald-700"><span class="h-2 w-2 rounded-full bg-emerald-500"></span>推荐</span>
                    <span class="inline-flex items-center gap-1.5 text-gray-600"><span class="h-2 w-2 rounded-full bg-gray-400"></span>可选</span>
                </div>
                <p class="text-sm text-gray-500">先完成蓝色必填区；素材上下文可提高生成准确度，发布与高级配置可按需展开。</p>
            </div>
            <form method="POST" action="{{ $isEdit ? route('admin.tasks.update', ['taskId' => $taskId]) : route('admin.tasks.store') }}" class="grid grid-cols-1 gap-6 xl:grid-cols-12">
                @csrf
                @if ($isEdit)
                    @method('PUT')
                @endif

                <div class="overflow-hidden rounded-lg border border-blue-200 bg-white shadow-sm xl:col-span-12">
                    <div class="flex items-start justify-between gap-4 border-b border-blue-200 bg-blue-50 px-6 py-4">
                        <div>
                            <h3 class="text-lg font-semibold text-gray-900">1. {{ $t('task_create.section.basic_title') }}</h3>
                            <p class="mt-1 text-sm text-gray-600">{{ $t('task_create.section.basic_desc') }}</p>
                        </div>
                        <span class="shrink-0 rounded-full bg-red-100 px-2.5 py-1 text-xs font-semibold text-red-700">必填</span>
                    </div>
                    <div class="px-6 py-4">
                        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
                            <div class="order-1 lg:col-span-3">
                                <label for="task_name" class="block text-sm font-medium text-gray-700">{{ $t('task_create.field.task_name') }} *</label>
                                <input type="text" name="task_name" id="task_name" required value="{{ old('task_name', (string) ($taskForm['task_name'] ?? '')) }}"
                                       class="{{ $fieldClass }}"
                                       placeholder="{{ $t('task_create.placeholder.task_name') }}">
                            </div>
                            <div class="order-2">
                                @include('admin.partials.collection-select', [
                                    'name' => 'collection_id',
                                    'collectionOptions' => $formOptions['collections'] ?? [],
                                    'selectedId' => old('collection_id', (string) ($taskForm['collection_id'] ?? '')),
                                    'label' => $t('task_create.field.collection'),
                                    'help' => $t('task_create.help.collection_required'),
                                    'required' => true,
                                    'emptyLabel' => '请选择 Collection',
                                    'class' => $fieldClass,
                                ])
                                <label class="mt-3 flex items-start gap-2 text-sm text-gray-600">
                                    <input type="checkbox" name="cross_collection_mode" value="1" @checked($crossCollectionMode) class="mt-0.5 h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                                    <span>
                                        <span class="block font-medium text-gray-800">{{ $t('task_create.field.cross_collection_mode') }}</span>
                                        <span class="block text-gray-500">{{ $t('task_create.help.cross_collection_mode') }}</span>
                                    </span>
                                </label>
                            </div>
                            <div class="order-5 rounded-lg border border-emerald-200 bg-emerald-50/50 p-4 lg:col-span-2">
                                <label class="block text-sm font-medium text-gray-700">{{ $t('task_create.field.entities') }}</label>
                                <div class="mt-1">
                                    @include('admin.partials.entity-selector', [
                                        'name' => 'entity_ids',
                                        'entityOptions' => $formOptions['entityOptions'] ?? [],
                                        'selectedEntityIds' => $selectedTaskEntityIds,
                                        'tone' => 'blue',
                                    ])
                                </div>
                                <p class="mt-1 text-sm text-gray-500">{{ $t('task_create.help.entities') }}</p>
                            </div>
                            <div class="order-6 rounded-lg border border-emerald-200 bg-emerald-50/50 p-4 lg:col-span-3">
                                <label class="block text-sm font-medium text-gray-700">{{ $t('task_create.field.cases') }}</label>
                                <div class="mt-1">
                                    @include('admin.partials.option-multi-selector', [
                                        'name' => 'case_ids',
                                        'options' => $formOptions['caseOptions'] ?? [],
                                        'selectedIds' => $selectedTaskCaseIds,
                                        'tone' => 'blue',
                                        'placeholder' => $t('task_create.field.cases'),
                                        'emptyText' => $t('task_create.option.no_cases'),
                                    ])
                                </div>
                                <p class="mt-1 text-sm text-gray-500">{{ $t('task_create.help.cases') }}</p>
                            </div>
                            <div class="order-3 lg:col-span-2">
                                <label for="title_library_id" class="block text-sm font-medium text-gray-700">{{ $t('task_create.field.title_library') }} *</label>
                                <select name="title_library_id" id="title_library_id" required class="{{ $fieldClass }}">
                                    <option value="">{{ $t('task_create.option.select_title_library') }}</option>
                                    @foreach ($formOptions['titleLibraries'] as $library)
                                        <option value="{{ $library['id'] }}" data-collection-id="{{ (int) ($library['collection_id'] ?? 0) }}" @selected((string) old('title_library_id', (string) ($taskForm['title_library_id'] ?? '')) === (string) $library['id'])>
                                            {{ $t('task_create.option.library_count', ['name' => $library['name'], 'count' => $library['count']]) }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="order-4">
                                <label for="status" class="block text-sm font-medium text-gray-700">{{ $t('task_create.field.task_status') }}</label>
                                <select name="status" id="status" class="{{ $fieldClass }}">
                                    <option value="active" @selected(old('status', (string) ($taskForm['status'] ?? 'active')) === 'active')>{{ $t('task_create.option.status_active') }}</option>
                                    <option value="paused" @selected(old('status', (string) ($taskForm['status'] ?? 'active')) === 'paused')>{{ $t('task_create.option.status_paused') }}</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>

                <details class="overflow-hidden rounded-lg border border-emerald-200 bg-white shadow-sm xl:col-span-12" @if($selectedCrmSourceType !== '') open @endif>
                    <summary class="flex cursor-pointer list-none items-start justify-between gap-4 bg-emerald-50 px-6 py-4">
                        <div>
                            <h3 class="text-lg font-semibold text-gray-900">2. CRM 业务来源</h3>
                            <p class="mt-1 text-sm text-gray-600">推荐在有明确客户、询盘或售后工单时关联，便于回溯业务来源。</p>
                        </div>
                        <span class="shrink-0 rounded-full bg-emerald-100 px-2.5 py-1 text-xs font-semibold text-emerald-700">推荐</span>
                    </summary>
                    <div class="px-6 py-4">
                        <div class="grid grid-cols-1 gap-6 lg:grid-cols-[240px_minmax(0,1fr)]">
                            <div>
                                <label for="crm_source_type" class="block text-sm font-medium text-gray-700">来源类型</label>
                                <select name="crm_source_type" id="crm_source_type" class="{{ $fieldClass }}">
                                    <option value="" @selected($selectedCrmSourceType === '')>不关联 CRM 来源</option>
                                    <option value="customer" @selected($selectedCrmSourceType === 'customer')>客户</option>
                                    <option value="inquiry" @selected($selectedCrmSourceType === 'inquiry')>询盘</option>
                                    <option value="ticket" @selected($selectedCrmSourceType === 'ticket')>售后工单</option>
                                </select>
                            </div>
                            <div>
                                <label for="crm_source_id" class="block text-sm font-medium text-gray-700">来源记录</label>
                                <select name="crm_source_id" id="crm_source_id" class="{{ $fieldClass }}">
                                    <option value="">请选择来源记录</option>
                                    @foreach (($formOptions['crmSourceOptions'] ?? []) as $source)
                                        <option value="{{ (int) $source['id'] }}"
                                                data-source-type="{{ $source['type'] }}"
                                                data-collection-id="{{ (int) ($source['collection_id'] ?? 0) }}"
                                                @selected($selectedCrmSourceType === (string) $source['type'] && $selectedCrmSourceId === (string) $source['id'])>
                                            {{ $source['label'] }} @if((string) ($source['meta'] ?? '') !== '') · {{ $source['meta'] }} @endif
                                        </option>
                                    @endforeach
                                </select>
                                <p class="mt-1 text-sm text-gray-500">选择 Collection 后，来源记录会同步限制到同一业务容器；开启跨 Collection 时不限制。</p>
                            </div>
                        </div>
                    </div>
                </details>

                <div class="overflow-hidden rounded-lg border border-blue-200 bg-white shadow-sm xl:col-span-12">
                    <div class="flex items-start justify-between gap-4 border-b border-blue-200 bg-blue-50 px-6 py-4">
                        <div>
                            <h3 class="text-lg font-semibold text-gray-900">3. {{ $t('task_create.section.content_title') }}</h3>
                            <p class="mt-1 text-sm text-gray-600">{{ $t('task_create.section.content_desc') }}</p>
                        </div>
                        <span class="shrink-0 rounded-full bg-red-100 px-2.5 py-1 text-xs font-semibold text-red-700">核心必填</span>
                    </div>
                    <div class="px-6 py-4">
                        <div class="grid grid-cols-1 gap-6 lg:grid-cols-4">
                            <div class="order-1 lg:col-span-2">
                                <label for="prompt_id" class="block text-sm font-medium text-gray-700">{{ $t('task_create.field.content_prompt') }} *</label>
                                <select name="prompt_id" id="prompt_id" required class="{{ $fieldClass }}">
                                    <option value="">{{ $t('task_create.option.select_prompt') }}</option>
                                    @foreach ($formOptions['prompts'] as $prompt)
                                        <option value="{{ $prompt['id'] }}" @selected((string) old('prompt_id', (string) ($taskForm['prompt_id'] ?? '')) === (string) $prompt['id'])>{{ $prompt['name'] }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="order-3">
                                <label for="skill_prompt_id" class="block text-sm font-medium text-gray-700">{{ $t('task_create.field.skill_prompt') }}</label>
                                <select name="skill_prompt_id" id="skill_prompt_id" class="{{ $fieldClass }}">
                                    <option value="">{{ $t('task_create.option.no_skill_prompt') }}</option>
                                    @foreach (($formOptions['skillPrompts'] ?? []) as $prompt)
                                        <option value="{{ $prompt['id'] }}" @selected((string) old('skill_prompt_id', (string) ($taskForm['skill_prompt_id'] ?? '')) === (string) $prompt['id'])>{{ $prompt['name'] }}</option>
                                    @endforeach
                                </select>
                                <p class="mt-1 text-sm text-gray-500">{{ $t('task_create.help.skill_prompt') }}</p>
                            </div>
                            <div class="order-2 lg:col-span-2">
                                <label for="ai_model_id" class="block text-sm font-medium text-gray-700">{{ $t('task_create.field.ai_model') }} *</label>
                                <select name="ai_model_id" id="ai_model_id" required class="{{ $fieldClass }}">
                                    <option value="">{{ $t('task_create.option.select_ai_model') }}</option>
                                    @foreach ($formOptions['aiModels'] as $model)
                                        <option value="{{ $model['id'] }}" @selected((string) old('ai_model_id', (string) ($taskForm['ai_model_id'] ?? '')) === (string) $model['id'])>{{ $model['name'] }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="order-4">
                                <label for="model_selection_mode" class="block text-sm font-medium text-gray-700">{{ $t('task_create.field.model_selection_mode') }}</label>
                                <select name="model_selection_mode" id="model_selection_mode" class="{{ $fieldClass }}">
                                    <option value="fixed" @selected(old('model_selection_mode', (string) ($taskForm['model_selection_mode'] ?? 'fixed')) === 'fixed')>{{ $t('task_create.option.model_selection_fixed') }}</option>
                                    <option value="smart_failover" @selected(old('model_selection_mode', (string) ($taskForm['model_selection_mode'] ?? 'fixed')) === 'smart_failover')>{{ $t('task_create.option.model_selection_smart_failover') }}</option>
                                </select>
                                <p class="mt-1 text-sm text-gray-500">{!! $t('task_create.help.model_selection_mode') !!}</p>
                            </div>
                            <div class="order-5 rounded-lg border border-emerald-200 bg-emerald-50/50 p-4">
                                <label for="knowledge_base_id" class="block text-sm font-medium text-gray-700">{{ $t('task_create.field.knowledge_base') }}</label>
                                <select name="knowledge_base_id" id="knowledge_base_id" class="{{ $fieldClass }}">
                                    <option value="">{{ $t('task_create.option.no_knowledge_base') }}</option>
                                    @foreach ($formOptions['knowledgeBases'] as $kb)
                                        <option value="{{ $kb['id'] }}" data-collection-id="{{ (int) ($kb['collection_id'] ?? 0) }}" @selected((string) old('knowledge_base_id', (string) ($taskForm['knowledge_base_id'] ?? '')) === (string) $kb['id'])>{{ $kb['name'] }}</option>
                                    @endforeach
                                </select>
                                <p class="mt-1 text-sm text-gray-500">{!! $t('task_create.help.knowledge_base') !!}</p>
                            </div>
                            <div class="order-6 rounded-lg border border-emerald-200 bg-emerald-50/50 p-4 lg:col-span-3">
                                <label class="block text-sm font-medium text-gray-700">{{ $t('task_create.field.knowledge_tags') }}</label>
                                <input type="hidden" name="knowledge_tag_filter_present" value="1">
                                <div class="mt-1">
                                    @include('admin.partials.tag-label-selector', [
                                        'name' => 'knowledge_tag_filters',
                                        'tagOptions' => $formOptions['knowledgeTags'],
                                        'selectedLabels' => $genericKnowledgeTagFilters,
                                        'countLabelKey' => 'admin.task_create.option.knowledge_tag_count',
                                        'searchScope' => 'knowledge',
                                        'emptyText' => $t('task_create.option.no_knowledge_tags'),
                                        'tone' => 'blue',
                                    ])
                                </div>
                                <p class="mt-1 text-sm text-gray-500">{!! $t('task_create.help.knowledge_tags') !!}</p>
                            </div>
                            <div class="order-8 lg:col-span-4">
                                <details class="rounded-lg border border-gray-200 bg-gray-50 px-4 py-3">
                                    <summary class="cursor-pointer text-sm font-semibold text-gray-700">{{ $t('task_create.help.controlled_tag_groups_optional') }}</summary>
                                    <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-5">
                                        @foreach ($controlledTagGroups as $controlledGroupName)
                                            @php($tagGroup = ['field' => $controlledTagFieldName($controlledGroupName), 'group' => $controlledGroupName, 'label' => $controlledGroupName])
                                            <div>
                                                <label class="block text-sm font-medium text-gray-700">{{ $tagGroup['label'] }}</label>
                                                <div class="mt-1">
                                                    @include('admin.partials.tag-label-selector', [
                                                        'name' => $tagGroup['field'],
                                                        'tagOptions' => [],
                                                        'selectedLabels' => $selectedGroupTagFilters($tagGroup['field'], $tagGroup['group']),
                                                        'countLabelKey' => 'admin.task_create.option.knowledge_tag_count',
                                                        'searchScope' => 'knowledge',
                                                        'searchGroup' => $tagGroup['group'],
                                                        'emptyText' => $t('task_create.option.no_group_tags'),
                                                        'placeholder' => $t('task_create.placeholder.group_tag_search'),
                                                        'tone' => 'blue',
                                                    ])
                                                </div>
                                            </div>
                                        @endforeach
                                    </div>
                                </details>
                            </div>
                            <div class="order-7">
                                <label for="author_id" class="block text-sm font-medium text-gray-700">{{ $t('task_create.field.author') }}</label>
                                <select name="author_id" id="author_id" class="{{ $fieldClass }}">
                                    <option value="0">{{ $t('task_create.option.random_author') }}</option>
                                    @foreach ($formOptions['authors'] as $author)
                                        <option value="{{ $author['id'] }}" @selected((string) old('author_id', (string) ($taskForm['author_id'] ?? '0')) === (string) $author['id'])>{{ $author['name'] }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                    </div>
                </div>

                <details class="overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm xl:col-span-6" @if($hasImageConfiguration) open @endif>
                    <summary class="flex cursor-pointer list-none items-start justify-between gap-4 bg-gray-50 px-6 py-4">
                        <div>
                            <h3 class="text-lg font-semibold text-gray-900">4. {{ $t('task_create.section.image_title') }}</h3>
                            <p class="mt-1 text-sm text-gray-600">{{ $t('task_create.section.image_desc') }}</p>
                        </div>
                        <span class="shrink-0 rounded-full bg-gray-200 px-2.5 py-1 text-xs font-semibold text-gray-700">可选</span>
                    </summary>
                    <div class="px-6 py-4">
                        @php($imageCountValue = (string) old('image_count', (string) ($taskForm['image_count'] ?? '1')))
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label for="image_library_id" class="block text-sm font-medium text-gray-700">{{ $t('task_create.field.image_library') }}</label>
                                <select name="image_library_id" id="image_library_id" class="{{ $fieldClass }}">
                                    <option value="">{{ $t('task_create.option.no_image_library') }}</option>
                                    @foreach ($formOptions['imageLibraries'] as $library)
                                        <option value="{{ $library['id'] }}" data-collection-id="{{ (int) ($library['collection_id'] ?? 0) }}" data-entity-ids="{{ implode(',', array_map('intval', $library['entity_ids'] ?? [])) }}" @selected((string) old('image_library_id', (string) ($taskForm['image_library_id'] ?? '')) === (string) $library['id'])>
                                            {{ $t('task_create.option.image_library_count', ['name' => $library['name'], 'count' => $library['count']]) }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label for="image_count" class="block text-sm font-medium text-gray-700">{{ $t('task_create.field.image_count') }}</label>
                                <select name="image_count" id="image_count" class="{{ $fieldClass }}">
                                    <option value="0" @selected($imageCountValue === '0')>{{ $t('task_create.option.no_image_count') }}</option>
                                    <option value="1" @selected($imageCountValue === '1')>{{ $t('task_create.option.auto_image_count') }}</option>
                                    <option value="2" @selected($imageCountValue === '2')>{{ $t('task_create.option.image_count', ['count' => 2]) }}</option>
                                    <option value="3" @selected($imageCountValue === '3')>{{ $t('task_create.option.image_count', ['count' => 3]) }}</option>
                                    <option value="4" @selected($imageCountValue === '4')>{{ $t('task_create.option.image_count', ['count' => 4]) }}</option>
                                    <option value="5" @selected($imageCountValue === '5')>{{ $t('task_create.option.image_count', ['count' => 5]) }}</option>
                                </select>
                                <p class="mt-1 text-sm text-gray-500">{{ $t('task_create.help.image_count_auto') }}</p>
                            </div>
                            <div class="md:col-span-2" data-image-tag-filter-section>
                                <label class="block text-sm font-medium text-gray-700">{{ $t('task_create.field.image_tags') }}</label>
                                <input type="hidden" name="image_tag_filter_present" value="1">
                                <div class="mt-1">
                                    @include('admin.partials.tag-label-selector', [
                                        'name' => 'image_tag_filters',
                                        'tagOptions' => $formOptions['imageTags'],
                                        'selectedLabels' => $selectedImageTagFilters,
                                        'countLabelKey' => 'admin.task_create.option.image_tag_count',
                                        'searchScope' => 'images',
                                        'emptyText' => $t('task_create.option.no_image_tags'),
                                        'tone' => 'purple',
                                    ])
                                </div>
                                <p class="mt-1 text-sm text-gray-500">{!! $t('task_create.help.image_tags') !!}</p>
                            </div>
                        </div>
                    </div>
                </details>

                <details class="overflow-hidden rounded-lg border border-amber-200 bg-white shadow-sm xl:col-span-6">
                    <summary class="flex cursor-pointer list-none items-start justify-between gap-4 bg-amber-50 px-6 py-4">
                        <div>
                            <h3 class="text-lg font-semibold text-gray-900">5. {{ $t('task_create.section.publish_title') }}</h3>
                            <p class="mt-1 text-sm text-gray-600">{{ $t('task_create.section.publish_desc') }}</p>
                        </div>
                        <span class="shrink-0 rounded-full bg-amber-100 px-2.5 py-1 text-xs font-semibold text-amber-700">按需设置</span>
                    </summary>
                    <div class="px-6 py-4">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <div class="flex items-center">
                                    <input type="checkbox" name="need_review" id="need_review" @checked((bool) old('need_review', (bool) ($taskForm['need_review'] ?? false)))
                                           class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded">
                                    <label for="need_review" class="ml-2 block text-sm text-gray-900">{{ $t('task_create.field.need_review') }}</label>
                                </div>
                                <p class="mt-1 text-sm text-gray-500">{{ $t('task_create.help.need_review') }}</p>
                            </div>
                            <div>
                                <label for="publish_interval" class="block text-sm font-medium text-gray-700">{{ $t('task_create.field.publish_interval') }}</label>
                                <input type="number" name="publish_interval" id="publish_interval" min="1" value="{{ old('publish_interval', (string) ($taskForm['publish_interval'] ?? 60)) }}"
                                       class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500">
                                <p class="mt-1 text-sm text-gray-500">{{ $t('task_create.help.publish_interval') }}</p>
                            </div>
                        </div>
                    </div>
                </details>

                <details class="overflow-hidden rounded-lg border border-amber-200 bg-white shadow-sm xl:col-span-12" @if($hasDistributionConfiguration) open @endif>
                    <summary class="flex cursor-pointer list-none items-start justify-between gap-4 bg-amber-50 px-6 py-4">
                        <div>
                            <h3 class="text-lg font-semibold text-gray-900">6. {{ $t('task_create.section.distribution_title') }}</h3>
                            <p class="mt-1 text-sm text-gray-600">{{ $t('task_create.section.distribution_desc') }}</p>
                        </div>
                        <span class="shrink-0 rounded-full bg-amber-100 px-2.5 py-1 text-xs font-semibold text-amber-700">发布配置</span>
                    </summary>
                    <div class="px-6 py-4">
                        <fieldset class="mb-5">
                            <legend class="text-sm font-medium text-gray-900">{{ $t('task_create.distribution.scope_title') }}</legend>
                            <p class="mt-1 text-sm text-gray-500">{{ $t('task_create.distribution.scope_help') }}</p>
                            <div class="mt-4 grid grid-cols-1 gap-3 lg:grid-cols-3">
                                <label class="flex cursor-pointer gap-3 rounded-md border border-gray-200 px-4 py-3 text-sm hover:border-blue-300 hover:bg-blue-50">
                                    <input type="radio" name="publish_scope" value="local_and_distribution" @checked($publishScope === 'local_and_distribution') data-publish-scope-option class="mt-1 h-4 w-4 border-gray-300 text-blue-600 focus:ring-blue-500">
                                    <span>
                                        <span class="block font-medium text-gray-900">{{ $t('task_create.distribution.scope_local_and_distribution') }}</span>
                                        <span class="block text-gray-500">{{ $t('task_create.distribution.scope_local_and_distribution_desc') }}</span>
                                    </span>
                                </label>
                                <label class="flex cursor-pointer gap-3 rounded-md border border-gray-200 px-4 py-3 text-sm hover:border-blue-300 hover:bg-blue-50">
                                    <input type="radio" name="publish_scope" value="distribution_only" @checked($publishScope === 'distribution_only') data-publish-scope-option class="mt-1 h-4 w-4 border-gray-300 text-blue-600 focus:ring-blue-500">
                                    <span>
                                        <span class="block font-medium text-gray-900">{{ $t('task_create.distribution.scope_distribution_only') }}</span>
                                        <span class="block text-gray-500">{{ $t('task_create.distribution.scope_distribution_only_desc') }}</span>
                                    </span>
                                </label>
                                <label class="flex cursor-pointer gap-3 rounded-md border border-gray-200 px-4 py-3 text-sm hover:border-blue-300 hover:bg-blue-50">
                                    <input type="radio" name="publish_scope" value="local_only" @checked($publishScope === 'local_only') data-publish-scope-option class="mt-1 h-4 w-4 border-gray-300 text-blue-600 focus:ring-blue-500">
                                    <span>
                                        <span class="block font-medium text-gray-900">{{ $t('task_create.distribution.scope_local_only') }}</span>
                                        <span class="block text-gray-500">{{ $t('task_create.distribution.scope_local_only_desc') }}</span>
                                    </span>
                                </label>
                            </div>
                        </fieldset>

                        @if (empty($formOptions['distributionChannels']))
                            <div class="rounded-md bg-gray-50 px-4 py-3 text-sm text-gray-600">
                                {{ $t('task_create.distribution.empty') }}
                                <a href="{{ route('admin.distribution.create') }}" class="font-medium text-blue-600 hover:text-blue-700">{{ $t('task_create.distribution.create_link') }}</a>
                            </div>
                        @else
                            <div class="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-3">
                                @foreach ($formOptions['distributionChannels'] as $channel)
                                    @php($channelId = (string) $channel['id'])
                                    <label data-distribution-channel-card @class([
                                        'flex items-start gap-3 rounded-md border border-gray-200 px-4 py-3 text-sm transition',
                                        'cursor-pointer hover:border-blue-300 hover:bg-blue-50' => ! $distributionChannelsDisabled,
                                        'cursor-not-allowed bg-gray-50 opacity-50' => $distributionChannelsDisabled,
                                    ])>
                                        <input type="checkbox" name="distribution_channel_ids[]" value="{{ $channelId }}" @checked(! $distributionChannelsDisabled && in_array($channelId, $selectedDistributionChannelIds, true)) @disabled($distributionChannelsDisabled) data-distribution-channel-input
                                               class="mt-1 h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500 disabled:cursor-not-allowed disabled:opacity-50">
                                        <span class="min-w-0">
                                            <span class="block font-medium text-gray-900">{{ $channel['name'] }}</span>
                                            <span class="block break-all text-gray-500">{{ $channel['domain'] }}</span>
                                        </span>
                                    </label>
                                @endforeach
                            </div>
                            <p class="mt-3 text-sm text-gray-500">{{ $t('task_create.distribution.help') }}</p>
                        @endif
                    </div>
                </details>

                <details class="overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm xl:col-span-12">
                    <summary class="flex cursor-pointer list-none items-start justify-between gap-4 bg-gray-50 px-6 py-4">
                        <div>
                            <h3 class="text-lg font-semibold text-gray-900">7. {{ $t('task_create.section.seo_title') }}</h3>
                            <p class="mt-1 text-sm text-gray-600">{{ $t('task_create.section.seo_desc') }}</p>
                        </div>
                        <span class="shrink-0 rounded-full bg-gray-200 px-2.5 py-1 text-xs font-semibold text-gray-700">高级</span>
                    </summary>
                    <div class="px-6 py-4">
                        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
                            <div>
                                <div class="flex items-center">
                                    <input type="checkbox" name="auto_keywords" id="auto_keywords" @checked(old('auto_keywords', (string) ($taskForm['auto_keywords'] ?? '1')) === '1')
                                           class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded">
                                    <label for="auto_keywords" class="ml-2 block text-sm text-gray-900">{{ $t('task_create.field.auto_keywords') }}</label>
                                </div>
                                <p class="mt-1 text-sm text-gray-500">{{ $t('task_create.help.auto_keywords') }}</p>
                            </div>
                            <div>
                                <div class="flex items-center">
                                    <input type="checkbox" name="auto_description" id="auto_description" @checked(old('auto_description', (string) ($taskForm['auto_description'] ?? '1')) === '1')
                                           class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded">
                                    <label for="auto_description" class="ml-2 block text-sm text-gray-900">{{ $t('task_create.field.auto_description') }}</label>
                                </div>
                                <p class="mt-1 text-sm text-gray-500">{{ $t('task_create.help.auto_description') }}</p>
                            </div>
                        </div>
                    </div>
                </details>

                <details class="overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm xl:col-span-8">
                    <summary class="flex cursor-pointer list-none items-start justify-between gap-4 bg-gray-50 px-6 py-4">
                        <div>
                            <h3 class="text-lg font-semibold text-gray-900">8. {{ $t('task_create.section.category_title') }}</h3>
                            <p class="mt-1 text-sm text-gray-600">{{ $t('task_create.section.category_desc') }}</p>
                        </div>
                        <span class="shrink-0 rounded-full bg-gray-200 px-2.5 py-1 text-xs font-semibold text-gray-700">高级</span>
                    </summary>
                    @php($categoryMode = (string) old('category_mode', (string) ($taskForm['category_mode'] ?? 'smart')))
                    <div class="px-6 py-4 space-y-4">
                        <div>
                            <label class="text-base font-medium text-gray-900">{{ $t('task_create.field.category_mode') }}</label>
                            <p class="text-sm leading-5 text-gray-500">{{ $t('task_create.help.category_mode') }}</p>
                            <fieldset class="mt-4">
                                <legend class="sr-only">{{ $t('task_create.field.category_mode') }}</legend>
                                <div class="grid grid-cols-1 gap-3 lg:grid-cols-3">
                                    <div class="flex items-start rounded-md border border-gray-200 px-4 py-3">
                                        <div class="flex items-center h-5">
                                            <input id="category_smart" name="category_mode" type="radio" value="smart" @checked($categoryMode === 'smart')
                                                   class="focus:ring-blue-500 h-4 w-4 text-blue-600 border-gray-300">
                                        </div>
                                        <div class="ml-3 text-sm">
                                            <label for="category_smart" class="font-medium text-gray-700">{{ $t('task_create.option.category_smart') }}</label>
                                            <p class="text-gray-500">{{ $t('task_create.help.category_smart') }}</p>
                                        </div>
                                    </div>
                                    <div class="flex items-start rounded-md border border-gray-200 px-4 py-3">
                                        <div class="flex items-center h-5">
                                            <input id="category_fixed" name="category_mode" type="radio" value="fixed" @checked($categoryMode === 'fixed')
                                                   class="focus:ring-blue-500 h-4 w-4 text-blue-600 border-gray-300">
                                        </div>
                                        <div class="ml-3 text-sm">
                                            <label for="category_fixed" class="font-medium text-gray-700">{{ $t('task_create.option.category_fixed') }}</label>
                                            <p class="text-gray-500">{{ $t('task_create.help.category_fixed') }}</p>
                                        </div>
                                    </div>
                                    <div class="flex items-start rounded-md border border-gray-200 px-4 py-3">
                                        <div class="flex items-center h-5">
                                            <input id="category_random" name="category_mode" type="radio" value="random" @checked($categoryMode === 'random')
                                                   class="focus:ring-blue-500 h-4 w-4 text-blue-600 border-gray-300">
                                        </div>
                                        <div class="ml-3 text-sm">
                                            <label for="category_random" class="font-medium text-gray-700">{{ $t('task_create.option.category_random') }}</label>
                                            <p class="text-gray-500">{{ $t('task_create.help.category_random') }}</p>
                                        </div>
                                    </div>
                                </div>
                            </fieldset>
                        </div>
                        <div id="fixed-category-section" class="hidden">
                            <label for="fixed_category_id" class="block text-sm font-medium text-gray-700">{{ $t('task_create.field.fixed_category') }}</label>
                            <select name="fixed_category_id" id="fixed_category_id" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500">
                                <option value="">{{ $t('task_create.option.select_category') }}</option>
                                @foreach ($formOptions['categories'] as $category)
                                    <option value="{{ $category['id'] }}" @selected((string) old('fixed_category_id', (string) ($taskForm['fixed_category_id'] ?? '')) === (string) $category['id'])>{{ $category['name'] }}</option>
                                @endforeach
                            </select>
                            <p class="mt-2 text-sm text-gray-500">{{ $t('task_create.help.fixed_category') }}</p>
                        </div>
                        <div class="bg-gray-50 rounded-lg p-4">
                            <h4 class="text-sm font-medium text-gray-900 mb-2">{{ $t('task_create.preview.categories_title') }}</h4>
                            <div class="flex flex-wrap gap-2">
                                @foreach ($formOptions['categories'] as $category)
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800">{{ $category['name'] }}</span>
                                @endforeach
                            </div>
                            <p class="mt-2 text-xs text-gray-500">{{ $t('task_create.preview.categories_count', ['count' => count($formOptions['categories'])]) }}</p>
                        </div>
                    </div>
                </details>

                <details class="overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm xl:col-span-4">
                    <summary class="flex cursor-pointer list-none items-start justify-between gap-4 bg-gray-50 px-6 py-4">
                        <div>
                            <h3 class="text-lg font-semibold text-gray-900">9. {{ $t('task_create.section.advanced_title') }}</h3>
                            <p class="mt-1 text-sm text-gray-600">{{ $t('task_create.section.advanced_desc') }}</p>
                        </div>
                        <span class="shrink-0 rounded-full bg-gray-200 px-2.5 py-1 text-xs font-semibold text-gray-700">高级</span>
                    </summary>
                    <div class="px-6 py-4">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label for="article_limit" class="block text-sm font-medium text-gray-700">{{ $t('task_create.field.article_limit') }}</label>
                                <input type="number" name="article_limit" id="article_limit" min="1" value="{{ old('article_limit', (string) ($taskForm['article_limit'] ?? 10)) }}"
                                       class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500">
                                <p class="mt-1 text-sm text-gray-500">{{ $t('task_create.help.article_limit') }}</p>
                            </div>
                            <div>
                                <label for="draft_limit" class="block text-sm font-medium text-gray-700">{{ $t('task_create.field.draft_limit') }}</label>
                                <input type="number" name="draft_limit" id="draft_limit" min="1" value="{{ old('draft_limit', (string) ($taskForm['draft_limit'] ?? 10)) }}"
                                       class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500">
                                <p class="mt-1 text-sm text-gray-500">{{ $t('task_create.help.draft_limit') }}</p>
                            </div>
                            <div>
                                <div class="flex items-center">
                                    <input type="checkbox" name="is_loop" id="is_loop" @checked(old('is_loop', (string) ($taskForm['is_loop'] ?? '1')) === '1')
                                           class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded">
                                    <label for="is_loop" class="ml-2 block text-sm text-gray-900">{{ $t('task_create.field.loop_mode') }}</label>
                                </div>
                                <p class="mt-1 text-sm text-gray-500">{{ $t('task_create.help.loop_mode') }}</p>
                            </div>
                        </div>
                    </div>
                </details>

                <div class="sticky bottom-3 z-30 flex flex-col gap-3 rounded-lg border border-gray-200 bg-white/95 px-4 py-3 shadow-lg backdrop-blur xl:col-span-12 sm:flex-row sm:items-center sm:justify-between">
                    <div class="flex items-center gap-2 text-sm">
                        <span data-required-status-dot class="h-2.5 w-2.5 rounded-full bg-amber-500"></span>
                        <span data-required-status class="font-medium text-gray-700">正在检查必填项...</span>
                    </div>
                    <div class="flex justify-end gap-3">
                        <a href="{{ route('admin.tasks.index') }}" class="inline-flex items-center rounded-md border border-gray-300 bg-white px-5 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50">
                            {{ __('admin.button.cancel') }}
                        </a>
                        <button type="submit" data-task-submit class="inline-flex items-center rounded-md border border-transparent bg-blue-600 px-5 py-2 text-sm font-medium text-white shadow-sm hover:bg-blue-700 disabled:cursor-not-allowed disabled:bg-gray-300">
                            {{ $isEdit ? __('admin.task_edit.button.save_changes') : __('admin.button.create_task') }}
                        </button>
                    </div>
                </div>
            </form>
            @endif
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const isEditMode = @json($isEdit);

            if (typeof lucide !== 'undefined') {
                lucide.createIcons();
            }

            const titleLibrarySelect = document.getElementById('title_library_id');
            const knowledgeBaseSelect = document.getElementById('knowledge_base_id');
            const imageLibrarySelect = document.getElementById('image_library_id');
            const crmSourceTypeSelect = document.getElementById('crm_source_type');
            const crmSourceSelect = document.getElementById('crm_source_id');
            const collectionSelect = document.querySelector('select[name="collection_id"]');
            const crossCollectionCheckbox = document.querySelector('input[name="cross_collection_mode"]');
            const imageCountSelect = document.getElementById('image_count');
            const needReviewCheckbox = document.getElementById('need_review');
            const publishIntervalInput = document.getElementById('publish_interval');
            const articleLimitInput = document.getElementById('article_limit');
            const draftLimitInput = document.getElementById('draft_limit');
            const fixedCategorySection = document.getElementById('fixed-category-section');
            const fixedCategorySelect = document.getElementById('fixed_category_id');
            const categoryModeRadios = document.querySelectorAll('input[name="category_mode"]');
            const publishScopeRadios = document.querySelectorAll('[data-publish-scope-option]');
            const distributionChannelInputs = document.querySelectorAll('[data-distribution-channel-input]');
            const form = document.querySelector('form');
            const requiredStatus = document.querySelector('[data-required-status]');
            const requiredStatusDot = document.querySelector('[data-required-status-dot]');
            const submitButton = document.querySelector('[data-task-submit]');

            if (!form) {
                return;
            }

            const requiredFields = [
                { id: 'task_name', label: @json($t('task_create.field.task_name')) },
                { selector: 'select[name="collection_id"]', label: @json($t('task_create.field.collection')) },
                { id: 'title_library_id', label: @json($t('task_create.field.title_library')) },
                { id: 'prompt_id', label: @json($t('task_create.field.content_prompt')) },
                { id: 'ai_model_id', label: @json($t('task_create.field.ai_model')) },
            ];

            function syncRequiredStatus() {
                const missing = requiredFields.filter((field) => {
                    const element = field.id ? document.getElementById(field.id) : document.querySelector(field.selector);
                    return !String(element?.value || '').trim();
                });

                if (requiredStatus) {
                    requiredStatus.textContent = missing.length === 0
                        ? '必填项已完成，可以创建任务'
                        : `还需填写 ${missing.length} 项：${missing.map((field) => field.label).join('、')}`;
                }

                requiredStatusDot?.classList.toggle('bg-emerald-500', missing.length === 0);
                requiredStatusDot?.classList.toggle('bg-amber-500', missing.length > 0);

                if (submitButton) {
                    submitButton.disabled = missing.length > 0;
                }
            }

            requiredFields.forEach((field) => {
                const element = field.id ? document.getElementById(field.id) : document.querySelector(field.selector);
                element?.addEventListener('input', syncRequiredStatus);
                element?.addEventListener('change', syncRequiredStatus);
            });

            function selectedEntityIds() {
                return Array.from(document.querySelectorAll('input[name="entity_ids[]"]'))
                    .map((input) => String(input.value || '').trim())
                    .filter(Boolean);
            }

            function selectedCollectionId() {
                return String(collectionSelect?.value || '').trim();
            }

            function collectionFilterIsActive() {
                return selectedCollectionId() !== '' && !Boolean(crossCollectionCheckbox?.checked);
            }

            function optionMatchesCollection(option, allowShared) {
                if (!collectionFilterIsActive()) {
                    return true;
                }

                const optionCollectionId = String(option.getAttribute('data-collection-id') || option.getAttribute('data-option-collection-id') || '').trim();
                if (allowShared && (optionCollectionId === '' || optionCollectionId === '0')) {
                    return true;
                }

                return optionCollectionId === selectedCollectionId();
            }

            function syncNativeSelectByCollection(select, allowShared = true) {
                if (!select) {
                    return;
                }

                Array.from(select.querySelectorAll('option[value]')).forEach((option) => {
                    if (option.value === '') {
                        option.hidden = false;
                        return;
                    }

                    option.hidden = !optionMatchesCollection(option, allowShared);
                });

                const selectedOption = select.selectedOptions[0];
                if (selectedOption && selectedOption.hidden) {
                    select.value = '';
                }
            }

            function syncCrmSourceOptions() {
                if (!crmSourceTypeSelect || !crmSourceSelect) {
                    return;
                }

                const sourceType = String(crmSourceTypeSelect.value || '').trim();
                Array.from(crmSourceSelect.querySelectorAll('option[value]')).forEach((option) => {
                    if (option.value === '') {
                        option.hidden = false;
                        return;
                    }

                    const matchesType = sourceType !== '' && String(option.getAttribute('data-source-type') || '') === sourceType;
                    option.hidden = !matchesType || !optionMatchesCollection(option, false);
                });

                const selectedOption = crmSourceSelect.selectedOptions[0];
                if (sourceType === '' || (selectedOption && selectedOption.hidden)) {
                    crmSourceSelect.value = '';
                }
                crmSourceSelect.disabled = sourceType === '';
                crmSourceSelect.parentElement.classList.toggle('opacity-60', sourceType === '');
            }

            function syncOptionSelectorByCollection(fieldName) {
                const selector = document.querySelector('[data-option-multi-selector][data-field-name="' + fieldName + '"]');
                if (!selector || !collectionSelect) {
                    return;
                }

                const shouldFilter = collectionFilterIsActive();
                selector.querySelectorAll('[data-option-item]').forEach((item) => {
                    const hidden = shouldFilter && !optionMatchesCollection(item, false);
                    item.hidden = hidden;
                    item.dataset.optionFilterHidden = hidden ? '1' : '0';
                    item.classList.toggle('hidden', hidden);
                });

                selector.querySelectorAll('[data-option-chip]').forEach((chip) => {
                    if (!shouldFilter) {
                        return;
                    }

                    const chipId = String(chip.getAttribute('data-option-id') || '');
                    const option = Array.from(selector.querySelectorAll('[data-option-item]'))
                        .find((item) => String(item.getAttribute('data-option-id') || '') === chipId);
                    if (!option || !optionMatchesCollection(option, false)) {
                        chip.remove();
                    }
                });

                const search = selector.querySelector('[data-option-search]');
                if (search) {
                    search.value = '';
                }
                const visibleItems = Array.from(selector.querySelectorAll('[data-option-item]'))
                    .filter((item) => !item.hidden && item.dataset.optionFilterHidden !== '1');
                selector.querySelector('[data-option-menu-empty]')?.classList.toggle('hidden', visibleItems.length > 0);
                selector.dispatchEvent(new CustomEvent('option-selector:changed', { bubbles: true }));
            }

            function syncContextOptionsByCollection() {
                syncOptionSelectorByCollection('entity_ids');
                syncOptionSelectorByCollection('case_ids');
                syncNativeSelectByCollection(titleLibrarySelect, true);
                syncNativeSelectByCollection(knowledgeBaseSelect, true);
                syncCrmSourceOptions();
                window.setTimeout(syncImageLibrariesByEntities, 0);
            }

            function syncImageLibrariesByEntities() {
                if (!imageLibrarySelect) {
                    return;
                }

                const selectedEntities = selectedEntityIds();
                const options = Array.from(imageLibrarySelect.querySelectorAll('option[value]')).filter((option) => option.value !== '');
                options.forEach((option) => {
                    option.hidden = !optionMatchesCollection(option, true);
                });
                if (selectedEntities.length === 0) {
                    const selectedOption = imageLibrarySelect.selectedOptions[0];
                    if (selectedOption && selectedOption.hidden) {
                        imageLibrarySelect.value = '';
                    }
                    return;
                }

                const visibleOptions = options.filter((option) => !option.hidden);
                const matched = visibleOptions.filter((option) => {
                    const linked = String(option.getAttribute('data-entity-ids') || '').split(',').map((id) => id.trim()).filter(Boolean);
                    return linked.some((id) => selectedEntities.includes(id));
                });
                if (matched.length === 0) {
                    return;
                }

                visibleOptions.forEach((option) => {
                    const linked = String(option.getAttribute('data-entity-ids') || '').split(',').map((id) => id.trim()).filter(Boolean);
                    option.hidden = !linked.some((id) => selectedEntities.includes(id));
                });

                const selectedOption = imageLibrarySelect.selectedOptions[0];
                if (selectedOption && selectedOption.hidden) {
                    imageLibrarySelect.value = '';
                }
            }

            function togglePublishInterval() {
                if (needReviewCheckbox.checked) {
                    publishIntervalInput.disabled = true;
                    publishIntervalInput.parentElement.style.opacity = '0.5';
                } else {
                    publishIntervalInput.disabled = false;
                    publishIntervalInput.parentElement.style.opacity = '1';
                }
            }

            function handleCategoryModeChange() {
                const selected = document.querySelector('input[name="category_mode"]:checked');
                if (!selected) {
                    return;
                }

                if (selected.value === 'fixed') {
                    fixedCategorySection.classList.remove('hidden');
                    fixedCategorySelect.required = true;
                } else {
                    fixedCategorySection.classList.add('hidden');
                    fixedCategorySelect.required = false;
                    fixedCategorySelect.value = '';
                }
            }

            function syncDraftLimitMax() {
                const articleLimit = Math.max(1, Number(articleLimitInput.value || 1));
                draftLimitInput.max = String(articleLimit);
                if (Number(draftLimitInput.value || 1) > articleLimit) {
                    draftLimitInput.value = String(articleLimit);
                }
            }

            function syncDistributionChannelsByScope() {
                const selectedScope = document.querySelector('input[name="publish_scope"]:checked');
                const isLocalOnly = selectedScope && selectedScope.value === 'local_only';

                distributionChannelInputs.forEach((input) => {
                    input.disabled = isLocalOnly;
                    if (isLocalOnly) {
                        input.checked = false;
                    }

                    const card = input.closest('[data-distribution-channel-card]');
                    if (!card) {
                        return;
                    }

                    card.classList.toggle('cursor-pointer', !isLocalOnly);
                    card.classList.toggle('hover:border-blue-300', !isLocalOnly);
                    card.classList.toggle('hover:bg-blue-50', !isLocalOnly);
                    card.classList.toggle('cursor-not-allowed', isLocalOnly);
                    card.classList.toggle('bg-gray-50', isLocalOnly);
                    card.classList.toggle('opacity-50', isLocalOnly);
                });
            }

            document.addEventListener('click', function (event) {
                if (event.target.closest('[data-option-item], [data-option-remove]')) {
                    window.setTimeout(syncImageLibrariesByEntities, 0);
                }
            });
            collectionSelect?.addEventListener('change', syncContextOptionsByCollection);
            crossCollectionCheckbox?.addEventListener('change', syncContextOptionsByCollection);
            crmSourceTypeSelect?.addEventListener('change', syncCrmSourceOptions);
            needReviewCheckbox.addEventListener('change', togglePublishInterval);
            articleLimitInput.addEventListener('input', syncDraftLimitMax);
            categoryModeRadios.forEach((radio) => radio.addEventListener('change', handleCategoryModeChange));
            publishScopeRadios.forEach((radio) => radio.addEventListener('change', syncDistributionChannelsByScope));

            form.addEventListener('submit', function (event) {
                syncContextOptionsByCollection();

                if (!document.getElementById('task_name').value.trim()) {
                    alert(@json(__('admin.task_create.error.name_required')));
                    event.preventDefault();
                    return;
                }

                const collectionSelect = document.querySelector('select[name="collection_id"]');
                if (collectionSelect && !collectionSelect.value) {
                    alert(@json(__('admin.task_create.field.collection')));
                    event.preventDefault();
                    return;
                }

                if (!document.getElementById('title_library_id').value) {
                    alert(@json(__('admin.task_create.error.title_library_required')));
                    event.preventDefault();
                    return;
                }

                if (!document.getElementById('prompt_id').value) {
                    alert(@json(__('admin.task_create.error.prompt_required')));
                    event.preventDefault();
                    return;
                }

                if (!document.getElementById('ai_model_id').value) {
                    alert(@json(__('admin.task_create.error.ai_model_required')));
                    event.preventDefault();
                    return;
                }

                if (Number(draftLimitInput.value || 0) > Number(articleLimitInput.value || 0)) {
                    alert(@json(__('admin.task_create.error.draft_limit_too_large')));
                    event.preventDefault();
                    return;
                }

                if (!isEditMode && !confirm(@json(__('admin.task_create.confirm.create')))) {
                    event.preventDefault();
                }
            });

            syncImageLibrariesByEntities();
            syncContextOptionsByCollection();
            togglePublishInterval();
            handleCategoryModeChange();
            syncDraftLimitMax();
            syncDistributionChannelsByScope();
            syncRequiredStatus();
        });
    </script>
@endpush
