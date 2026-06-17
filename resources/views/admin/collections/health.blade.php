@extends('admin.layouts.app')

@php
    $collection = is_array($health['collection'] ?? null) ? $health['collection'] : [];
    $stats = is_array($health['stats'] ?? null) ? $health['stats'] : [];
    $checks = collect($health['checks'] ?? [])->filter(fn ($check) => is_array($check))->values();
    $score = (int) ($health['score'] ?? 0);
    $status = (string) ($health['status'] ?? 'critical');
    $statusClass = match ($status) {
        'good' => 'bg-emerald-50 text-emerald-700 ring-emerald-100',
        'warning' => 'bg-amber-50 text-amber-700 ring-amber-100',
        default => 'bg-red-50 text-red-700 ring-red-100',
    };
    $statusIcon = match ($status) {
        'good' => 'circle-check',
        'warning' => 'triangle-alert',
        default => 'circle-alert',
    };
    $statCards = [
        ['label' => __('admin.collections.count_entities', ['count' => (int) ($stats['entity_count'] ?? 0)]), 'icon' => 'boxes', 'class' => 'text-blue-600 bg-blue-50'],
        ['label' => __('admin.collections.count_knowledge', ['count' => (int) ($stats['knowledge_base_count'] ?? 0)]), 'icon' => 'database', 'class' => 'text-orange-600 bg-orange-50'],
        ['label' => __('admin.collections.count_titles', ['count' => (int) ($stats['title_library_count'] ?? 0)]), 'icon' => 'text-cursor-input', 'class' => 'text-green-600 bg-green-50'],
        ['label' => __('admin.collections.count_images', ['count' => (int) ($stats['image_library_count'] ?? 0)]), 'icon' => 'image', 'class' => 'text-purple-600 bg-purple-50'],
        ['label' => __('admin.collections.count_cases', ['count' => (int) ($stats['case_count'] ?? 0)]), 'icon' => 'briefcase-business', 'class' => 'text-emerald-600 bg-emerald-50'],
        ['label' => __('admin.collections.count_keywords', ['count' => (int) ($stats['keyword_library_count'] ?? 0)]), 'icon' => 'tags', 'class' => 'text-indigo-600 bg-indigo-50'],
    ];
@endphp

@section('content')
    <div class="px-4 sm:px-0">
        <div class="mb-8 flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
            <div>
                <div class="flex flex-wrap items-center gap-2">
                    <h1 class="text-2xl font-bold text-gray-900">{{ __('admin.collections.health.heading') }}</h1>
                    <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold ring-1 {{ $statusClass }}">
                        <i data-lucide="{{ $statusIcon }}" class="mr-1 h-3.5 w-3.5"></i>
                        {{ __('admin.collections.health.status.'.$status) }}
                    </span>
                </div>
                <p class="mt-1 text-sm text-gray-600">{{ (string) ($collection['name'] ?? '-') }} · {{ (string) ($collection['slug'] ?? '-') }}</p>
            </div>
            <div class="flex flex-wrap justify-end gap-2">
                <a href="{{ route('admin.collections.index') }}" class="inline-flex items-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                    <i data-lucide="arrow-left" class="mr-2 h-4 w-4"></i>
                    {{ __('admin.collections.health.back') }}
                </a>
                <a href="{{ route('admin.collections.edit', ['collectionId' => (int) ($collection['id'] ?? 0)]) }}" class="inline-flex items-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                    <i data-lucide="pencil" class="mr-2 h-4 w-4"></i>
                    {{ __('admin.button.edit') }}
                </a>
            </div>
        </div>

        <div class="mb-6 grid grid-cols-1 gap-6 lg:grid-cols-[320px_minmax(0,1fr)]">
            <div class="rounded-lg bg-white p-6 shadow">
                <div class="text-sm font-medium text-gray-500">{{ __('admin.collections.health.score_title') }}</div>
                <div class="mt-3 flex items-end gap-2">
                    <div class="text-5xl font-bold text-gray-900">{{ $score }}</div>
                    <div class="pb-2 text-sm text-gray-500">/ 100</div>
                </div>
                <div class="mt-4 h-2 overflow-hidden rounded-full bg-gray-100">
                    <div class="h-full rounded-full {{ $score >= 80 ? 'bg-emerald-500' : ($score >= 60 ? 'bg-amber-500' : 'bg-red-500') }}" style="width: {{ max(0, min(100, $score)) }}%"></div>
                </div>
                <p class="mt-4 text-sm leading-6 text-gray-600">{{ __('admin.collections.health.score_hint') }}</p>
            </div>

            <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
                @foreach($statCards as $card)
                    <div class="rounded-lg bg-white p-5 shadow">
                        <div class="flex items-center gap-3">
                            <div class="flex h-10 w-10 items-center justify-center rounded-lg {{ $card['class'] }}">
                                <i data-lucide="{{ $card['icon'] }}" class="h-5 w-5"></i>
                            </div>
                            <div class="text-sm font-semibold text-gray-900">{{ $card['label'] }}</div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>

        <div class="rounded-lg bg-white shadow">
            <div class="border-b border-gray-200 px-6 py-4">
                <h3 class="text-lg font-medium text-gray-900">{{ __('admin.collections.health.check_title') }}</h3>
                <p class="mt-1 text-sm text-gray-500">{{ __('admin.collections.health.check_subtitle') }}</p>
            </div>
            <div class="divide-y divide-gray-100">
                @foreach($checks as $check)
                    @php
                        $passed = (bool) ($check['passed'] ?? false);
                    @endphp
                    <div class="grid grid-cols-1 gap-3 px-6 py-4 lg:grid-cols-[minmax(0,1fr)_120px_120px] lg:items-center">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="inline-flex h-6 w-6 items-center justify-center rounded-full {{ $passed ? 'bg-emerald-50 text-emerald-700' : 'bg-red-50 text-red-700' }}">
                                    <i data-lucide="{{ $passed ? 'check' : 'x' }}" class="h-3.5 w-3.5"></i>
                                </span>
                                <div class="font-semibold text-gray-900">{{ __((string) ($check['label_key'] ?? '')) }}</div>
                            </div>
                            <p class="mt-2 text-sm leading-6 text-gray-600">{{ __((string) ($check['description_key'] ?? '')) }}</p>
                        </div>
                        <div class="text-sm text-gray-600">
                            {{ __('admin.collections.health.issue_count', ['count' => (int) ($check['count'] ?? 0)]) }}
                        </div>
                        <div class="text-sm">
                            @if($passed)
                                <span class="font-semibold text-emerald-700">{{ __('admin.collections.health.no_penalty') }}</span>
                            @else
                                <span class="font-semibold text-red-700">{{ __('admin.collections.health.penalty', ['points' => (int) ($check['penalty'] ?? 0)]) }}</span>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>
@endsection
