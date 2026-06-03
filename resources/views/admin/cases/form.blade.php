@extends('admin.layouts.app')

@php
    $formAction = $isEdit
        ? route('admin.cases.update', ['caseId' => (int) $caseId])
        : route('admin.cases.store');
@endphp

@section('content')
    <div class="px-4 sm:px-0">
        <div class="mb-8 flex items-center space-x-4">
            <a href="{{ route('admin.cases.index') }}" class="text-gray-400 hover:text-gray-600">
                <i data-lucide="arrow-left" class="h-5 w-5"></i>
            </a>
            <div>
                <h1 class="text-2xl font-bold text-gray-900">{{ $isEdit ? __('admin.cases.edit_title') : __('admin.cases.create_title') }}</h1>
                <p class="mt-1 text-sm text-gray-600">{{ __('admin.cases.subtitle') }}</p>
            </div>
        </div>

        <div class="rounded-lg bg-white shadow">
            <div class="px-6 py-6">
                @if ($errors->any())
                    <div class="mb-6 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                        {{ $errors->first() }}
                    </div>
                @endif

                <form method="POST" action="{{ $formAction }}" class="space-y-6" data-ai-analysis-form data-ai-analysis-url="{{ route('admin.cases.analyze') }}">
                    @csrf
                    @if ($isEdit)
                        @method('PUT')
                    @endif

                    <div class="rounded-xl border border-emerald-100 bg-emerald-50/60 p-5">
                        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                            <div>
                                <h3 class="text-base font-semibold text-emerald-950">AI 自动识别分析</h3>
                                <p class="mt-1 text-sm text-emerald-800">粘贴案例材料后，系统会自动填入标题、背景、方案、结果、指标和标签建议。</p>
                            </div>
                            <div class="flex min-w-[260px] flex-col gap-2 sm:flex-row">
                                <select data-ai-analysis-model class="rounded-md border-emerald-200 text-sm shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
                                    <option value="0">自动选择模型</option>
                                    @foreach(($aiModelOptions ?? []) as $model)
                                        <option value="{{ (int) $model['id'] }}">{{ $model['name'] }}</option>
                                    @endforeach
                                </select>
                                <button type="button" data-ai-analysis-submit class="inline-flex items-center justify-center rounded-md border border-transparent bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700">
                                    <i data-lucide="sparkles" class="mr-2 h-4 w-4"></i>
                                    分析
                                </button>
                            </div>
                        </div>
                        <textarea data-ai-analysis-content rows="5" class="mt-4 block w-full rounded-md border-emerald-200 px-3 py-2 text-sm shadow-sm focus:border-emerald-500 focus:ring-emerald-500" placeholder="粘贴客户案例、项目描述、成果数据或一段待识别内容"></textarea>
                        <p data-ai-analysis-status class="mt-2 hidden text-sm text-emerald-800"></p>
                    </div>

                    <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
                        <div>
                            <label class="mb-2 block text-sm font-medium text-gray-700">{{ __('admin.cases.field_title') }}</label>
                            <input type="text" name="title" required maxlength="200" value="{{ old('title', (string) ($caseForm['title'] ?? '')) }}" data-tag-source="case-form" class="block w-full rounded-md border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-emerald-500 focus:ring-emerald-500" placeholder="{{ __('admin.cases.placeholder_title') }}">
                        </div>
                        <div>
                            <label class="mb-2 block text-sm font-medium text-gray-700">{{ __('admin.cases.field_type') }}</label>
                            <input type="text" name="case_type" maxlength="100" value="{{ old('case_type', (string) ($caseForm['case_type'] ?? '')) }}" data-tag-source="case-form" class="block w-full rounded-md border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-emerald-500 focus:ring-emerald-500" placeholder="{{ __('admin.cases.placeholder_type') }}">
                        </div>
                    </div>

                    <div>
                        <label class="mb-2 block text-sm font-medium text-gray-700">{{ __('admin.cases.field_entity') }}</label>
                        <select name="entity_id" class="block w-full rounded-md border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
                            <option value="">{{ __('admin.cases.option_no_entity') }}</option>
                            @foreach ($entityOptions as $entity)
                                <option value="{{ $entity['id'] }}" @selected((string) old('entity_id', (string) ($caseForm['entity_id'] ?? '')) === (string) $entity['id'])>{{ $entity['name'] }}</option>
                            @endforeach
                        </select>
                    </div>

                    @include('admin.partials.collection-select', [
                        'selectedId' => (string) ($caseForm['collection_id'] ?? ''),
                        'collectionOptions' => $collectionOptions ?? [],
                        'class' => 'block w-full rounded-md border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-emerald-500 focus:ring-emerald-500',
                    ])

                    <div>
                        <label class="mb-2 block text-sm font-medium text-gray-700">{{ __('admin.cases.field_summary') }}</label>
                        <textarea name="summary" rows="4" data-tag-source="case-form" class="block w-full rounded-md border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-emerald-500 focus:ring-emerald-500" placeholder="{{ __('admin.cases.placeholder_summary') }}">{{ old('summary', (string) ($caseForm['summary'] ?? '')) }}</textarea>
                    </div>

                    <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
                        <div>
                            <label class="mb-2 block text-sm font-medium text-gray-700">{{ __('admin.cases.field_challenge') }}</label>
                            <textarea name="challenge" rows="5" data-tag-source="case-form" class="block w-full rounded-md border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-emerald-500 focus:ring-emerald-500" placeholder="{{ __('admin.cases.placeholder_challenge') }}">{{ old('challenge', (string) ($caseForm['challenge'] ?? '')) }}</textarea>
                        </div>
                        <div>
                            <label class="mb-2 block text-sm font-medium text-gray-700">{{ __('admin.cases.field_solution') }}</label>
                            <textarea name="solution" rows="5" data-tag-source="case-form" class="block w-full rounded-md border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-emerald-500 focus:ring-emerald-500" placeholder="{{ __('admin.cases.placeholder_solution') }}">{{ old('solution', (string) ($caseForm['solution'] ?? '')) }}</textarea>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
                        <div>
                            <label class="mb-2 block text-sm font-medium text-gray-700">{{ __('admin.cases.field_result') }}</label>
                            <textarea name="result" rows="5" data-tag-source="case-form" class="block w-full rounded-md border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-emerald-500 focus:ring-emerald-500" placeholder="{{ __('admin.cases.placeholder_result') }}">{{ old('result', (string) ($caseForm['result'] ?? '')) }}</textarea>
                        </div>
                        <div>
                            <label class="mb-2 block text-sm font-medium text-gray-700">{{ __('admin.cases.field_metrics') }}</label>
                            <textarea name="metrics" rows="5" data-tag-source="case-form" class="block w-full rounded-md border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-emerald-500 focus:ring-emerald-500" placeholder="{{ __('admin.cases.placeholder_metrics') }}">{{ old('metrics', (string) ($caseForm['metrics'] ?? '')) }}</textarea>
                        </div>
                    </div>

                    <div>
                        <label class="mb-2 block text-sm font-medium text-gray-700">{{ __('admin.cases.field_source_url') }}</label>
                        <input type="text" name="source_url" maxlength="500" value="{{ old('source_url', (string) ($caseForm['source_url'] ?? '')) }}" class="block w-full rounded-md border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-emerald-500 focus:ring-emerald-500" placeholder="https://example.com">
                    </div>

                    <div>
                        <label class="mb-2 block text-sm font-medium text-gray-700">{{ __('admin.cases.field_tags') }}</label>
                        @include('admin.partials.tag-selector', ['tagOptions' => $tagOptions, 'selectedTagIds' => $selectedTagIds, 'tone' => 'blue'])
                        <p data-ai-analysis-tags class="mt-2 hidden text-xs text-blue-700"></p>
                    </div>

                    <div class="flex justify-end gap-3">
                        <a href="{{ route('admin.cases.index') }}" class="inline-flex items-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                            {{ __('admin.button.cancel') }}
                        </a>
                        <button type="submit" class="inline-flex items-center rounded-md border border-transparent bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700">
                            <i data-lucide="save" class="mr-2 h-4 w-4"></i>
                            {{ $isEdit ? __('admin.cases.save_edit') : __('admin.cases.save_create') }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection

@include('admin.partials.material-ai-analysis-script')
