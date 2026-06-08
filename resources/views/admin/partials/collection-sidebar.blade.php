@php
    $collectionSidebarRoute = $routeName ?? \Illuminate\Support\Facades\Route::currentRouteName();
    $collectionSidebarOptions = $collectionOptions ?? [];
    $collectionSidebarSelectedId = (string) ($selectedId ?? request('collection_id', ''));
    $collectionSidebarQuery = \Illuminate\Support\Arr::except(request()->query(), ['collection_id', 'page']);
    $collectionSidebarAllUrl = route($collectionSidebarRoute, array_merge($collectionSidebarQuery, ['collection_id' => 0]));
    $collectionSidebarShowUnassigned = (bool) ($showUnassigned ?? false);
    $collectionSidebarUnassignedUrl = route($collectionSidebarRoute, array_merge($collectionSidebarQuery, ['collection_id' => 'unassigned']));
@endphp

<aside class="sticky top-24 self-start rounded-lg border border-gray-200 bg-white shadow-sm" data-collection-sidebar>
    <div class="border-b border-gray-100 px-4 py-3">
        <div class="flex items-center justify-between gap-3">
            <div>
                <h2 class="text-sm font-semibold text-gray-900">{{ __('admin.collections.sidebar_title') }}</h2>
                <p class="mt-1 text-xs text-gray-500">{{ __('admin.collections.sidebar_desc') }}</p>
            </div>
            <a href="{{ route('admin.collections.index') }}" class="shrink-0 text-xs font-medium text-slate-600 hover:text-slate-900">
                {{ __('admin.collections.sidebar_manage') }}
            </a>
        </div>
    </div>
    <nav class="max-h-[calc(100vh-12rem)] space-y-1 overflow-y-auto px-3 py-3">
        <a href="{{ $collectionSidebarAllUrl }}" @class([
            'flex items-center justify-between rounded-md px-3 py-2 text-sm font-medium',
            'bg-slate-900 text-white' => $collectionSidebarSelectedId === '',
            'text-gray-700 hover:bg-gray-50' => $collectionSidebarSelectedId !== '',
        ])>
            <span>{{ __('admin.collections.sidebar_all') }}</span>
        </a>
        @if ($collectionSidebarShowUnassigned)
            <a href="{{ $collectionSidebarUnassignedUrl }}" @class([
                'flex items-center justify-between rounded-md px-3 py-2 text-sm font-medium',
                'bg-slate-900 text-white' => $collectionSidebarSelectedId === 'unassigned',
                'text-gray-700 hover:bg-gray-50' => $collectionSidebarSelectedId !== 'unassigned',
            ])>
                <span>{{ __('admin.collections.badge_unassigned') }}</span>
            </a>
        @endif
        @foreach ($collectionSidebarOptions as $collectionOption)
            @php
                $optionId = (string) ($collectionOption['id'] ?? '');
                $optionUrl = route($collectionSidebarRoute, array_merge($collectionSidebarQuery, ['collection_id' => $optionId]));
                $isSelected = $collectionSidebarSelectedId === $optionId;
            @endphp
            <a href="{{ $optionUrl }}" @class([
                'flex items-center justify-between gap-3 rounded-md px-3 py-2 text-sm font-medium',
                'bg-slate-900 text-white' => $isSelected,
                'text-gray-700 hover:bg-gray-50' => ! $isSelected,
            ])>
                <span class="min-w-0 truncate">{{ $collectionOption['name'] ?? '' }}</span>
                @if (($collectionOption['status'] ?? 'active') !== 'active')
                    <span @class([
                        'shrink-0 rounded px-1.5 py-0.5 text-[10px]',
                        'bg-white/20 text-white' => $isSelected,
                        'bg-gray-100 text-gray-500' => ! $isSelected,
                    ])>{{ __('admin.collections.status_inactive') }}</span>
                @endif
            </a>
        @endforeach
    </nav>
</aside>
