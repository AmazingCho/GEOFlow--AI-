@extends('admin.layouts.app')

@php
    $formAction = $isEdit
        ? route('admin.entities.update', ['entityId' => (int) $entityId])
        : route('admin.entities.store');
    $selectedEntityType = old('entity_type', (string) ($entityForm['entity_type'] ?? '业务实体'));
    $selectedLinkPolicy = old('link_policy', (string) ($entityForm['link_policy'] ?? 'disabled'));
@endphp

@section('content')
    <div class="px-4 sm:px-0">
        <div class="mb-8 flex items-center space-x-4">
            <a href="{{ route('admin.entities.index') }}" class="text-gray-400 hover:text-gray-600">
                <i data-lucide="arrow-left" class="h-5 w-5"></i>
            </a>
            <div>
                <h1 class="text-2xl font-bold text-gray-900">{{ $isEdit ? __('admin.entities.edit_title') : __('admin.entities.create_title') }}</h1>
                <p class="mt-1 text-sm text-gray-600">{{ __('admin.entities.subtitle') }}</p>
            </div>
        </div>

        <div class="rounded-lg bg-white shadow">
            <div class="px-6 py-6">
                @if ($errors->any())
                    <div class="mb-6 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                        {{ $errors->first() }}
                    </div>
                @endif

                <form method="POST" action="{{ $formAction }}" class="space-y-6" data-ai-analysis-form data-ai-analysis-url="{{ route('admin.entities.analyze') }}">
                    @csrf
                    @if ($isEdit)
                        @method('PUT')
                    @endif

                    <div class="rounded-xl border border-blue-100 bg-blue-50/60 p-5">
                        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                            <div>
                                <h3 class="text-base font-semibold text-blue-950">AI 自动识别分析</h3>
                                <p class="mt-1 text-sm text-blue-800">粘贴实体相关内容后，系统会自动填入名称、类型、属性、标签建议和描述。</p>
                            </div>
                            <div class="flex min-w-[260px] flex-col gap-2 sm:flex-row">
                                <select data-ai-analysis-model class="rounded-md border border-blue-200 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                    <option value="0">自动选择模型</option>
                                    @foreach(($aiModelOptions ?? []) as $model)
                                        <option value="{{ (int) $model['id'] }}">{{ $model['name'] }}</option>
                                    @endforeach
                                </select>
                                <button type="button" data-ai-analysis-submit class="inline-flex items-center justify-center rounded-md border border-transparent bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">
                                    <i data-lucide="sparkles" class="mr-2 h-4 w-4"></i>
                                    分析
                                </button>
                            </div>
                        </div>
                        <textarea data-ai-analysis-content rows="5" class="mt-4 block w-full rounded-md border border-blue-200 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" placeholder="粘贴官网介绍、产品描述、公司资料或一段待识别内容"></textarea>
                        @include('admin.partials.material-ai-analysis-instructions')
                        <p data-ai-analysis-status class="mt-2 hidden text-sm text-blue-800"></p>
                    </div>

                    <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
                        <div>
                            <label class="mb-2 block text-sm font-medium text-gray-700">{{ __('admin.entities.field_name') }}</label>
                            <input type="text" name="name" required maxlength="160" value="{{ old('name', (string) ($entityForm['name'] ?? '')) }}" data-tag-source="entity-form" class="block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" placeholder="{{ __('admin.entities.placeholder_name') }}">
                        </div>
                        <div>
                            <label class="mb-2 block text-sm font-medium text-gray-700">{{ __('admin.entities.field_type') }}</label>
                            <select name="entity_type" data-entity-type-select data-tag-source="entity-form" class="block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                @foreach(($entityTypeOptions ?? []) as $option)
                                    <option value="{{ (string) $option['value'] }}" @selected($selectedEntityType === (string) $option['value'])>
                                        {{ (string) $option['label'] }}
                                    </option>
                                @endforeach
                            </select>
                            <p data-entity-type-help class="mt-2 text-xs leading-5 text-gray-500"></p>
                        </div>
                    </div>

                    <div data-entity-link-fields class="rounded-lg border border-emerald-100 bg-emerald-50/50 p-4">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <h3 class="text-sm font-semibold text-emerald-950">{{ __('admin.entities.link_fields_title') }}</h3>
                                <p class="mt-1 text-xs leading-5 text-emerald-800">{{ __('admin.entities.link_fields_desc') }}</p>
                            </div>
                            <span class="rounded-full bg-white px-2 py-1 text-[11px] font-semibold text-emerald-700 ring-1 ring-emerald-100">{{ __('admin.entities.link_fields_badge') }}</span>
                        </div>
                        <div class="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-3">
                            <div class="lg:col-span-2">
                                <label class="mb-2 block text-sm font-medium text-gray-700">{{ __('admin.entities.field_canonical_url') }}</label>
                                <input type="text" name="canonical_url" maxlength="500" value="{{ old('canonical_url', (string) ($entityForm['canonical_url'] ?? '')) }}" class="block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" placeholder="https://example.com/product/sj4060">
                            </div>
                            <div>
                                <label class="mb-2 block text-sm font-medium text-gray-700">{{ __('admin.entities.field_link_policy') }}</label>
                                <select name="link_policy" class="block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                    <option value="suggest" @selected($selectedLinkPolicy === 'suggest')>{{ __('admin.entities.link_policy_suggest') }}</option>
                                    <option value="disabled" @selected($selectedLinkPolicy === 'disabled')>{{ __('admin.entities.link_policy_disabled') }}</option>
                                </select>
                            </div>
                            <div class="lg:col-span-3">
                                <label class="mb-2 block text-sm font-medium text-gray-700">{{ __('admin.entities.field_link_anchor_text') }}</label>
                                <input type="text" name="link_anchor_text" maxlength="160" value="{{ old('link_anchor_text', (string) ($entityForm['link_anchor_text'] ?? '')) }}" class="block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" placeholder="{{ __('admin.entities.placeholder_link_anchor_text') }}">
                            </div>
                        </div>
                    </div>

                    @include('admin.partials.collection-select', [
                        'selectedId' => (string) ($entityForm['collection_id'] ?? ''),
                        'collectionOptions' => $collectionOptions ?? [],
                        'class' => 'block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500',
                    ])

                    <div>
                        <label class="mb-2 block text-sm font-medium text-gray-700">{{ __('admin.entities.field_aliases') }}</label>
                        <textarea name="aliases" rows="2" data-tag-source="entity-form" class="block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" placeholder="{{ __('admin.entities.placeholder_aliases') }}">{{ old('aliases', (string) ($entityForm['aliases'] ?? '')) }}</textarea>
                    </div>

                    <div>
                        <label class="mb-2 block text-sm font-medium text-gray-700">{{ __('admin.entities.field_description') }}</label>
                        <textarea name="description" rows="5" data-tag-source="entity-form" class="block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" placeholder="{{ __('admin.entities.placeholder_description') }}">{{ old('description', (string) ($entityForm['description'] ?? '')) }}</textarea>
                    </div>

                    <div>
                        <label class="mb-2 block text-sm font-medium text-gray-700">{{ __('admin.entities.field_attributes') }}</label>
                        <textarea name="attributes_json" rows="5" data-tag-source="entity-form" class="block w-full rounded-md border border-gray-300 px-3 py-2 font-mono text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" placeholder="{{ __('admin.entities.placeholder_attributes') }}">{{ old('attributes_json', (string) ($entityForm['attributes_json'] ?? '{}')) }}</textarea>
                    </div>

                    <div>
                        <label class="mb-2 block text-sm font-medium text-gray-700">{{ __('admin.entities.field_source_url') }}</label>
                        <input type="text" name="source_url" maxlength="500" value="{{ old('source_url', (string) ($entityForm['source_url'] ?? '')) }}" class="block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" placeholder="https://example.com">
                    </div>

                    <div>
                        <label class="mb-2 block text-sm font-medium text-gray-700">{{ __('admin.entities.field_tags') }}</label>
                        @include('admin.partials.tag-selector', ['tagOptions' => $tagOptions, 'selectedTagIds' => $selectedTagIds, 'tone' => 'blue'])
                        <p data-ai-analysis-tags class="mt-2 hidden text-xs text-blue-700"></p>
                    </div>

                    @include('admin.entities.partials.material-links', [
                        'materialOptions' => $materialOptions ?? [],
                        'selectedMaterialIds' => $selectedMaterialIds ?? [],
                        'knowledgeRelationType' => $knowledgeRelationType ?? 'supporting_reference',
                        'knowledgeRelationTypeOptions' => $knowledgeRelationTypeOptions ?? [],
                    ])

                    @include('admin.entities.partials.entity-relations', [
                        'entityRelationService' => $entityRelationService ?? null,
                        'entityOptionsForRelation' => $entityOptionsForRelation ?? [],
                        'isEdit' => $isEdit,
                        'entityId' => $entityId ?? 0,
                    ])

                    <div class="flex justify-end gap-3">
                        <a href="{{ route('admin.entities.index') }}" class="inline-flex items-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                            {{ __('admin.button.cancel') }}
                        </a>
                        <button type="submit" class="inline-flex items-center rounded-md border border-transparent bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">
                            <i data-lucide="save" class="mr-2 h-4 w-4"></i>
                            {{ $isEdit ? __('admin.entities.save_edit') : __('admin.entities.save_create') }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection

@include('admin.partials.material-ai-analysis-script')

@push('scripts')
    <script>
        (() => {
            const linkableTypes = new Set(@json($linkableEntityTypes ?? []));
            const typeDescriptions = @json(collect($entityTypeOptions ?? [])->mapWithKeys(fn ($option) => [(string) $option['value'] => (string) $option['description']])->all());
            const select = document.querySelector('[data-entity-type-select]');
            const linkFields = document.querySelector('[data-entity-link-fields]');
            const help = document.querySelector('[data-entity-type-help]');

            function syncEntityTypeUi() {
                if (!select) {
                    return;
                }
                const value = select.value || '';
                if (help) {
                    help.textContent = typeDescriptions[value] || '';
                }
                if (linkFields) {
                    linkFields.classList.toggle('hidden', !linkableTypes.has(value));
                }
            }

            select?.addEventListener('change', syncEntityTypeUi);
            syncEntityTypeUi();
        })();
    </script>
@endpush
