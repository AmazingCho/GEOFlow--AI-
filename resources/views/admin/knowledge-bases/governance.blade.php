@extends('admin.layouts.app')

@php
    $stats = $report['stats'] ?? [];
    $duplicateGroups = $report['duplicate_groups'] ?? [];
    $conflictPairs = $report['conflict_pairs'] ?? [];

    $detailUrl = static fn (array $item): string => route('admin.knowledge-bases.detail', ['knowledgeBaseId' => (int) ($item['id'] ?? 0)]);
    $collectionLabel = static fn (array $item): string => (string) ($item['collection_name'] ?? '') !== '' ? (string) $item['collection_name'] : __('admin.collections.badge_unassigned');
    $entitiesLabel = static function (array $item): string {
        $entities = collect($item['entities'] ?? [])
            ->map(static fn (array $entity): string => trim((string) ($entity['name'] ?? '').(((string) ($entity['type'] ?? '') !== '') ? ' / '.(string) $entity['type'] : '')))
            ->filter()
            ->values();

        return $entities->isNotEmpty() ? $entities->implode('、') : __('admin.knowledge_governance.no_entities');
    };
@endphp

@section('content')
    <div class="px-4 sm:px-0">
        <div class="mb-6 flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
            <div class="flex items-start gap-4">
                <a href="{{ route('admin.knowledge-bases.index', array_filter(['collection_id' => $collectionId ?? null])) }}" class="mt-1 text-gray-400 hover:text-gray-600">
                    <i data-lucide="arrow-left" class="h-5 w-5"></i>
                </a>
                <div>
                    <h1 class="text-2xl font-bold text-gray-900">{{ __('admin.knowledge_governance.heading') }}</h1>
                    <p class="mt-1 max-w-3xl text-sm leading-6 text-gray-600">{{ __('admin.knowledge_governance.subtitle') }}</p>
                </div>
            </div>
            <a href="{{ route('admin.knowledge-bases.index', array_filter(['collection_id' => $collectionId ?? null])) }}" class="inline-flex w-fit items-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                <i data-lucide="list" class="mr-2 h-4 w-4"></i>
                {{ __('admin.knowledge_governance.back_to_knowledge') }}
            </a>
        </div>

        <section class="mb-6 rounded-lg border border-gray-200 bg-white shadow-sm">
            <div class="grid grid-cols-1 gap-4 p-5 lg:grid-cols-[minmax(0,1fr)_280px] lg:items-end">
                <div>
                    <h2 class="text-base font-semibold text-gray-900">{{ __('admin.knowledge_governance.filter_title') }}</h2>
                    <p class="mt-1 text-sm leading-6 text-gray-600">{{ __('admin.knowledge_governance.filter_desc') }}</p>
                </div>
                <form method="GET" action="{{ route('admin.knowledge-bases.governance') }}" class="flex items-end gap-2">
                    <div class="min-w-0 flex-1">
                        <label for="governance-collection" class="mb-2 block text-sm font-medium text-gray-700">{{ __('admin.collections.field_collection') }}</label>
                        <select id="governance-collection" name="collection_id" class="block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-purple-500 focus:ring-purple-500">
                            <option value="">{{ __('admin.common.all') }}</option>
                            @foreach ($collectionOptions ?? [] as $option)
                                <option value="{{ (int) $option['id'] }}" @selected((int) ($collectionId ?? 0) === (int) $option['id'])>{{ $option['name'] }}</option>
                            @endforeach
                        </select>
                    </div>
                    <button type="submit" class="inline-flex h-10 items-center rounded-md border border-transparent bg-purple-600 px-4 text-sm font-semibold text-white hover:bg-purple-700">
                        {{ __('admin.knowledge_governance.scan_button') }}
                    </button>
                </form>
            </div>
        </section>

        <div class="mb-6 grid grid-cols-1 gap-4 md:grid-cols-4">
            @foreach ([
                ['icon' => 'database', 'label' => __('admin.knowledge_governance.stats_scanned'), 'value' => number_format((int) ($stats['scanned'] ?? 0)), 'color' => 'text-blue-600'],
                ['icon' => 'copy-check', 'label' => __('admin.knowledge_governance.stats_duplicate_groups'), 'value' => number_format((int) ($stats['duplicate_groups'] ?? 0)), 'color' => 'text-purple-600'],
                ['icon' => 'files', 'label' => __('admin.knowledge_governance.stats_duplicate_items'), 'value' => number_format((int) ($stats['duplicate_items'] ?? 0)), 'color' => 'text-amber-600'],
                ['icon' => 'triangle-alert', 'label' => __('admin.knowledge_governance.stats_conflict_pairs'), 'value' => number_format((int) ($stats['conflict_pairs'] ?? 0)), 'color' => 'text-red-600'],
            ] as $card)
                <div class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
                    <div class="flex items-center gap-3">
                        <i data-lucide="{{ $card['icon'] }}" class="h-5 w-5 {{ $card['color'] }}"></i>
                        <div>
                            <div class="text-sm font-medium text-gray-500">{{ $card['label'] }}</div>
                            <div class="mt-1 text-2xl font-semibold text-gray-900">{{ $card['value'] }}</div>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        <div class="mb-6 rounded-lg border border-blue-200 bg-blue-50 p-4">
            <div class="flex items-start gap-3">
                <i data-lucide="info" class="mt-0.5 h-5 w-5 text-blue-700"></i>
                <div>
                    <h2 class="text-sm font-semibold text-blue-900">{{ __('admin.knowledge_governance.readonly_notice_title') }}</h2>
                    <p class="mt-1 text-sm leading-6 text-blue-800">{{ __('admin.knowledge_governance.readonly_notice_desc', ['limit' => (int) ($stats['scan_limit'] ?? 0)]) }}</p>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 gap-6 2xl:grid-cols-2">
            <section class="rounded-lg border border-gray-200 bg-white shadow-sm">
                <div class="border-b border-gray-100 px-5 py-4">
                    <h2 class="text-lg font-semibold text-gray-900">{{ __('admin.knowledge_governance.duplicate_title') }}</h2>
                    <p class="mt-1 text-sm leading-6 text-gray-600">{{ __('admin.knowledge_governance.duplicate_desc') }}</p>
                </div>

                <div class="space-y-4 p-5">
                    @forelse ($duplicateGroups as $group)
                        <article class="rounded-lg border border-gray-200 bg-gray-50 p-4">
                            <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="inline-flex items-center rounded-full bg-purple-100 px-2.5 py-1 text-xs font-semibold text-purple-700">
                                        {{ __('admin.knowledge_governance.type_'.($group['type'] ?? 'similar_title')) }}
                                    </span>
                                    <span class="text-xs font-medium text-gray-500">{{ __('admin.knowledge_governance.confidence', ['value' => (int) ($group['confidence'] ?? 0)]) }}</span>
                                </div>
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="text-xs text-gray-500">{{ __('admin.knowledge_governance.group_items', ['count' => count($group['items'] ?? [])]) }}</span>
                                    <form method="POST" action="{{ route('admin.knowledge-governance-proposals.store') }}">
                                        @csrf
                                        <input type="hidden" name="proposal_type" value="duplicate_archive">
                                        <input type="hidden" name="issue_payload" value="{{ json_encode($group, JSON_UNESCAPED_UNICODE) }}">
                                        <button type="submit" class="inline-flex items-center rounded-md border border-purple-200 bg-white px-3 py-1.5 text-xs font-semibold text-purple-700 hover:bg-purple-50">
                                            <i data-lucide="clipboard-check" class="mr-1.5 h-3.5 w-3.5"></i>
                                            {{ __('admin.knowledge_governance.create_proposal') }}
                                        </button>
                                    </form>
                                </div>
                            </div>

                            <div class="space-y-3">
                                @foreach (($group['items'] ?? []) as $item)
                                    <div class="rounded-md border border-gray-200 bg-white p-3">
                                        <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                                            <div class="min-w-0">
                                                <a href="{{ $detailUrl($item) }}" class="font-semibold text-gray-900 hover:text-purple-700">{{ $item['name'] ?? '-' }}</a>
                                                <div class="mt-1 flex flex-wrap gap-2 text-xs text-gray-500">
                                                    <span>{{ $collectionLabel($item) }}</span>
                                                    <span>{{ __('admin.knowledge_governance.item_meta', ['words' => number_format((int) ($item['word_count'] ?? 0)), 'chunks' => number_format((int) ($item['chunk_count'] ?? 0))]) }}</span>
                                                </div>
                                            </div>
                                            <a href="{{ $detailUrl($item) }}" class="inline-flex w-fit items-center rounded-md border border-gray-300 bg-white px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50">
                                                {{ __('admin.knowledge_governance.view_detail') }}
                                            </a>
                                        </div>
                                        @if ((string) ($item['source_url'] ?? '') !== '')
                                            <p class="mt-2 truncate text-xs text-gray-500">{{ __('admin.knowledge_governance.source_url') }}：{{ $item['source_url'] }}</p>
                                        @endif
                                        <p class="mt-2 text-xs leading-5 text-gray-600">{{ $entitiesLabel($item) }}</p>
                                        @if ((string) ($item['preview'] ?? '') !== '')
                                            <p class="mt-2 line-clamp-2 text-sm leading-6 text-gray-600">{{ $item['preview'] }}</p>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        </article>
                    @empty
                        <div class="rounded-lg border border-dashed border-gray-300 p-8 text-center text-sm text-gray-500">
                            {{ __('admin.knowledge_governance.duplicate_empty') }}
                        </div>
                    @endforelse
                </div>
            </section>

            <section class="rounded-lg border border-gray-200 bg-white shadow-sm">
                <div class="border-b border-gray-100 px-5 py-4">
                    <h2 class="text-lg font-semibold text-gray-900">{{ __('admin.knowledge_governance.conflict_title') }}</h2>
                    <p class="mt-1 text-sm leading-6 text-gray-600">{{ __('admin.knowledge_governance.conflict_desc') }}</p>
                </div>

                <div class="space-y-4 p-5">
                    @forelse ($conflictPairs as $pair)
                        <article class="rounded-lg border border-red-100 bg-red-50 p-4">
                            <div class="mb-3 flex items-center justify-between gap-3">
                                <span class="inline-flex items-center rounded-full bg-red-100 px-2.5 py-1 text-xs font-semibold text-red-700">
                                    {{ __('admin.knowledge_governance.confidence', ['value' => (int) ($pair['confidence'] ?? 0)]) }}
                                </span>
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="text-xs text-red-700">{{ __('admin.knowledge_governance.review_primary') }}</span>
                                    <form method="POST" action="{{ route('admin.knowledge-governance-proposals.store') }}">
                                        @csrf
                                        <input type="hidden" name="proposal_type" value="conflict_review">
                                        <input type="hidden" name="issue_payload" value="{{ json_encode($pair, JSON_UNESCAPED_UNICODE) }}">
                                        <button type="submit" class="inline-flex items-center rounded-md border border-red-200 bg-white px-3 py-1.5 text-xs font-semibold text-red-700 hover:bg-red-50">
                                            <i data-lucide="clipboard-check" class="mr-1.5 h-3.5 w-3.5"></i>
                                            {{ __('admin.knowledge_governance.create_review_proposal') }}
                                        </button>
                                    </form>
                                </div>
                            </div>

                            <div class="grid grid-cols-1 gap-3 xl:grid-cols-2">
                                @foreach (['left', 'right'] as $side)
                                    @php($item = $pair[$side] ?? [])
                                    <div class="rounded-md border border-red-100 bg-white p-3">
                                        <a href="{{ $detailUrl($item) }}" class="font-semibold text-gray-900 hover:text-red-700">{{ $item['name'] ?? '-' }}</a>
                                        <div class="mt-1 flex flex-wrap gap-2 text-xs text-gray-500">
                                            <span>{{ $collectionLabel($item) }}</span>
                                            <span>{{ __('admin.knowledge_governance.item_meta', ['words' => number_format((int) ($item['word_count'] ?? 0)), 'chunks' => number_format((int) ($item['chunk_count'] ?? 0))]) }}</span>
                                        </div>
                                        <p class="mt-2 text-xs leading-5 text-gray-600">{{ $entitiesLabel($item) }}</p>
                                    </div>
                                @endforeach
                            </div>

                            <div class="mt-3 overflow-hidden rounded-md border border-red-100 bg-white">
                                <table class="min-w-full divide-y divide-red-100 text-sm">
                                    <thead class="bg-red-50 text-xs font-semibold uppercase tracking-wide text-red-700">
                                        <tr>
                                            <th class="px-3 py-2 text-left">{{ __('admin.knowledge_governance.issue_label') }}</th>
                                            <th class="px-3 py-2 text-left">{{ __('admin.knowledge_governance.left_value') }}</th>
                                            <th class="px-3 py-2 text-left">{{ __('admin.knowledge_governance.right_value') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-red-50 text-gray-700">
                                        @foreach (($pair['conflicts'] ?? []) as $conflict)
                                            <tr>
                                                <td class="px-3 py-2 font-medium">{{ $conflict['label'] ?? '-' }}</td>
                                                <td class="px-3 py-2">{{ implode(', ', $conflict['left'] ?? []) }}</td>
                                                <td class="px-3 py-2">{{ implode(', ', $conflict['right'] ?? []) }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </article>
                    @empty
                        <div class="rounded-lg border border-dashed border-gray-300 p-8 text-center text-sm text-gray-500">
                            {{ __('admin.knowledge_governance.conflict_empty') }}
                        </div>
                    @endforelse
                </div>
            </section>
        </div>
    </div>
@endsection
