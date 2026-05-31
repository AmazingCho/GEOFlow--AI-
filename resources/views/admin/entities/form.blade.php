@extends('admin.layouts.app')

@php
    $formAction = $isEdit
        ? route('admin.entities.update', ['entityId' => (int) $entityId])
        : route('admin.entities.store');
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
                                <select data-ai-analysis-model class="rounded-md border-blue-200 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
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
                        <textarea data-ai-analysis-content rows="5" class="mt-4 block w-full rounded-md border-blue-200 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" placeholder="粘贴官网介绍、产品描述、公司资料或一段待识别内容"></textarea>
                        <p data-ai-analysis-status class="mt-2 hidden text-sm text-blue-800"></p>
                    </div>

                    <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
                        <div>
                            <label class="mb-2 block text-sm font-medium text-gray-700">{{ __('admin.entities.field_name') }}</label>
                            <input type="text" name="name" required maxlength="160" value="{{ old('name', (string) ($entityForm['name'] ?? '')) }}" data-tag-source="entity-form" class="block w-full rounded-md border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" placeholder="{{ __('admin.entities.placeholder_name') }}">
                        </div>
                        <div>
                            <label class="mb-2 block text-sm font-medium text-gray-700">{{ __('admin.entities.field_type') }}</label>
                            <input type="text" name="entity_type" maxlength="80" value="{{ old('entity_type', (string) ($entityForm['entity_type'] ?? '')) }}" data-tag-source="entity-form" class="block w-full rounded-md border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" placeholder="{{ __('admin.entities.placeholder_type') }}">
                        </div>
                    </div>

                    <div>
                        <label class="mb-2 block text-sm font-medium text-gray-700">{{ __('admin.entities.field_aliases') }}</label>
                        <textarea name="aliases" rows="2" data-tag-source="entity-form" class="block w-full rounded-md border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" placeholder="{{ __('admin.entities.placeholder_aliases') }}">{{ old('aliases', (string) ($entityForm['aliases'] ?? '')) }}</textarea>
                    </div>

                    <div>
                        <label class="mb-2 block text-sm font-medium text-gray-700">{{ __('admin.entities.field_description') }}</label>
                        <textarea name="description" rows="5" data-tag-source="entity-form" class="block w-full rounded-md border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" placeholder="{{ __('admin.entities.placeholder_description') }}">{{ old('description', (string) ($entityForm['description'] ?? '')) }}</textarea>
                    </div>

                    <div>
                        <label class="mb-2 block text-sm font-medium text-gray-700">{{ __('admin.entities.field_attributes') }}</label>
                        <textarea name="attributes_json" rows="5" data-tag-source="entity-form" class="block w-full rounded-md border-gray-300 px-3 py-2 font-mono text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" placeholder="{{ __('admin.entities.placeholder_attributes') }}">{{ old('attributes_json', (string) ($entityForm['attributes_json'] ?? '{}')) }}</textarea>
                    </div>

                    <div>
                        <label class="mb-2 block text-sm font-medium text-gray-700">{{ __('admin.entities.field_source_url') }}</label>
                        <input type="text" name="source_url" maxlength="500" value="{{ old('source_url', (string) ($entityForm['source_url'] ?? '')) }}" class="block w-full rounded-md border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" placeholder="https://example.com">
                    </div>

                    <div>
                        <label class="mb-2 block text-sm font-medium text-gray-700">{{ __('admin.entities.field_tags') }}</label>
                        @include('admin.partials.tag-selector', ['tagOptions' => $tagOptions, 'selectedTagIds' => $selectedTagIds, 'recommendedTags' => $recommendedTags ?? [], 'recommendationUrl' => route('admin.material-tags.recommendations'), 'recommendationSourceSelector' => '[data-tag-source="entity-form"]', 'tone' => 'blue'])
                        <p data-ai-analysis-tags class="mt-2 hidden text-xs text-blue-700"></p>
                    </div>

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
