@extends('admin.layouts.app')

@php
    $selectedGroups = collect($selectedGroups ?? [])->map(static fn ($group): string => (string) $group)->values();
    $groupOptions = collect($groupOptions ?? [])->map(static fn ($group): string => (string) $group)->values();
    $controlledGroupNames = collect($controlledTagGroups ?? [])
        ->map(static fn ($group): string => is_array($group) ? (string) ($group['name'] ?? '') : (string) $group)
        ->filter(static fn (string $group): bool => $group !== '')
        ->values();
    $scope = (string) ($scope ?? '');
    $scopeLabels = $scopeLabels ?? [];
    $baseQuery = array_filter([
        'search' => $search !== '' ? $search : null,
        'scope' => $scope !== '' ? $scope : null,
        'per_page' => (int) $perPage !== 20 ? (int) $perPage : null,
    ], static fn ($value): bool => $value !== null && $value !== '');
    if ($selectedGroups->isNotEmpty()) {
        $baseQuery['groups'] = $selectedGroups->all();
    }
    $clearQuery = $scope !== '' ? ['scope' => $scope] : [];
@endphp

@section('content')
    <div class="px-4 sm:px-0">
        <div class="mb-8 flex items-center justify-between">
            <div class="flex items-center space-x-4">
                <a href="{{ route('admin.materials.index') }}" class="text-gray-400 hover:text-gray-600">
                    <i data-lucide="arrow-left" class="h-5 w-5"></i>
                </a>
                <div>
                    <h1 class="text-2xl font-bold text-gray-900">{{ __('admin.material_tags.heading') }}</h1>
                    <p class="mt-1 text-sm text-gray-600">{{ __('admin.material_tags.subtitle') }}</p>
                </div>
            </div>
        </div>

        <div class="mb-8 grid grid-cols-1 gap-6 md:grid-cols-2 xl:grid-cols-6">
            <a href="{{ route('admin.material-tags.index') }}" target="_blank" rel="noopener noreferrer" class="overflow-hidden rounded-lg bg-white shadow transition hover:shadow-md">
                <div class="p-5">
                    <div class="text-sm font-medium text-gray-500">{{ __('admin.material_tags.stat_total') }}</div>
                    <div class="mt-2 text-2xl font-semibold text-gray-900">{{ number_format((int) ($stats['total'] ?? 0)) }}</div>
                </div>
            </a>
            <a href="{{ route('admin.material-tags.index', ['scope' => 'keywords']) }}" target="_blank" rel="noopener noreferrer" class="overflow-hidden rounded-lg bg-white shadow transition hover:shadow-md">
                <div class="p-5">
                    <div class="text-sm font-medium text-gray-500">{{ __('admin.material_tags.stat_keywords') }}</div>
                    <div class="mt-2 text-2xl font-semibold text-gray-900">{{ number_format((int) ($stats['keyword_links'] ?? 0)) }}</div>
                </div>
            </a>
            <a href="{{ route('admin.material-tags.index', ['scope' => 'images']) }}" target="_blank" rel="noopener noreferrer" class="overflow-hidden rounded-lg bg-white shadow transition hover:shadow-md">
                <div class="p-5">
                    <div class="text-sm font-medium text-gray-500">{{ __('admin.material_tags.stat_images') }}</div>
                    <div class="mt-2 text-2xl font-semibold text-gray-900">{{ number_format((int) ($stats['image_links'] ?? 0)) }}</div>
                </div>
            </a>
            <a href="{{ route('admin.material-tags.index', ['scope' => 'knowledge']) }}" target="_blank" rel="noopener noreferrer" class="overflow-hidden rounded-lg bg-white shadow transition hover:shadow-md">
                <div class="p-5">
                    <div class="text-sm font-medium text-gray-500">{{ __('admin.material_tags.stat_knowledge') }}</div>
                    <div class="mt-2 text-2xl font-semibold text-gray-900">{{ number_format((int) ($stats['knowledge_links'] ?? 0)) }}</div>
                </div>
            </a>
            <a href="{{ route('admin.material-tags.index', ['scope' => 'entities']) }}" target="_blank" rel="noopener noreferrer" class="overflow-hidden rounded-lg bg-white shadow transition hover:shadow-md">
                <div class="p-5">
                    <div class="text-sm font-medium text-gray-500">{{ __('admin.material_tags.stat_entities') }}</div>
                    <div class="mt-2 text-2xl font-semibold text-gray-900">{{ number_format((int) ($stats['entity_links'] ?? 0)) }}</div>
                </div>
            </a>
            <a href="{{ route('admin.material-tags.index', ['scope' => 'cases']) }}" target="_blank" rel="noopener noreferrer" class="overflow-hidden rounded-lg bg-white shadow transition hover:shadow-md">
                <div class="p-5">
                    <div class="text-sm font-medium text-gray-500">{{ __('admin.material_tags.stat_cases') }}</div>
                    <div class="mt-2 text-2xl font-semibold text-gray-900">{{ number_format((int) ($stats['case_links'] ?? 0)) }}</div>
                </div>
            </a>
        </div>

        @if ($scope !== '')
            <div class="mb-6 rounded-md border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-700">
                {{ __('admin.material_tags.scope_notice', ['scope' => $scopeLabels[$scope] ?? $scope]) }}
                <a href="{{ route('admin.material-tags.index') }}" class="ml-2 font-medium text-blue-800 hover:text-blue-900">{{ __('admin.material_tags.scope_clear') }}</a>
            </div>

            <div data-scope-groups-card class="mb-6 rounded-lg bg-white shadow">
                <div class="border-b border-gray-200 px-6 py-4">
                    <h3 class="text-lg font-medium text-gray-900">{{ __('admin.material_tags.scope_groups_title', ['scope' => $scopeLabels[$scope] ?? $scope]) }}</h3>
                </div>
                <div class="px-6 py-4">
                    @if (empty($scopeGroups))
                        <div class="text-sm text-gray-500">{{ __('admin.material_tags.scope_groups_empty') }}</div>
                    @else
                        <div class="flex flex-wrap gap-2">
                            @foreach ($scopeGroups as $group)
                                <span data-scope-group-chip class="inline-flex rounded-full bg-gray-100 px-3 py-1 text-sm font-medium text-gray-700">{{ $group }}</span>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>
        @endif

        <div class="mb-6 rounded-lg border border-blue-100 bg-blue-50/40 shadow-sm">
            <div class="flex flex-col gap-3 border-b border-blue-100 px-6 py-4 lg:flex-row lg:items-start lg:justify-between">
                <div>
                    <h3 class="text-lg font-medium text-gray-900">{{ __('admin.material_tags.controlled_groups_title') }}</h3>
                    <p class="mt-1 text-sm text-gray-600">{{ __('admin.material_tags.controlled_groups_desc') }}</p>
                </div>
                <form method="POST" action="{{ route('admin.material-tags.controlled-groups.store') }}" class="flex w-full gap-2 lg:w-auto">
                    @csrf
                    <input type="text" name="name" maxlength="100" placeholder="{{ __('admin.material_tags.controlled_group_placeholder') }}" class="min-w-0 flex-1 rounded-md border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500 lg:w-56">
                    <button type="submit" class="inline-flex items-center rounded-md border border-transparent bg-blue-600 px-3 py-2 text-sm font-medium text-white hover:bg-blue-700">
                        <i data-lucide="plus" class="mr-1 h-4 w-4"></i>
                        {{ __('admin.material_tags.controlled_group_add') }}
                    </button>
                </form>
            </div>
            <div class="px-6 py-4">
                <div class="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-3">
                    @foreach (($controlledTagGroups ?? []) as $group)
                        <div class="rounded-lg border border-blue-100 bg-white px-3 py-3">
                            <form method="POST" action="{{ route('admin.material-tags.controlled-groups.update', ['groupId' => (int) $group['id']]) }}" class="flex gap-2">
                                @csrf
                                @method('PUT')
                                <input type="text" name="name" value="{{ $group['name'] }}" maxlength="100" class="min-w-0 flex-1 rounded-md border-gray-300 px-3 py-2 text-sm font-semibold text-blue-700 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                <button type="submit" class="inline-flex items-center rounded-md border border-gray-300 bg-white px-3 py-2 text-xs font-medium text-gray-700 hover:bg-gray-50">
                                    {{ __('admin.material_tags.controlled_group_save') }}
                                </button>
                            </form>
                            <form method="POST" action="{{ route('admin.material-tags.controlled-groups.delete', ['groupId' => (int) $group['id']]) }}" class="mt-2" onsubmit="return confirm(@js(__('admin.material_tags.controlled_group_delete_confirm', ['group' => $group['name']])));">
                                @csrf
                                <button type="submit" class="inline-flex items-center text-xs font-medium text-red-600 hover:text-red-700">
                                    <i data-lucide="trash-2" class="mr-1 h-3.5 w-3.5"></i>
                                    {{ __('admin.material_tags.controlled_group_delete') }}
                                </button>
                            </form>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>

        <div class="mb-6 rounded-lg bg-white shadow">
            <div class="border-b border-gray-200 px-6 py-4">
                <h3 class="text-lg font-medium text-gray-900">{{ __('admin.material_tags.create_title') }}</h3>
            </div>
            <form method="POST" action="{{ route('admin.material-tags.store') }}" class="grid grid-cols-1 gap-4 px-6 py-4 md:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto] md:items-end">
                @csrf
                <div>
                    <label class="block text-sm font-medium text-gray-700">{{ __('admin.material_tags.field_group') }}</label>
                    <select name="group_name" required class="mt-1 block w-full rounded-md border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                        <option value="">{{ __('admin.material_tags.placeholder_group') }}</option>
                        @foreach ($controlledGroupNames as $groupName)
                            <option value="{{ $groupName }}" @selected(old('group_name') === $groupName)>{{ $groupName }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">{{ __('admin.material_tags.field_name') }}</label>
                    <input type="text" name="name" value="{{ old('name') }}" maxlength="100" required placeholder="{{ __('admin.material_tags.placeholder_name') }}" class="mt-1 block w-full rounded-md border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                </div>
                <button type="submit" class="inline-flex items-center justify-center rounded-md border border-transparent bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">
                    <i data-lucide="plus" class="mr-2 h-4 w-4"></i>
                    {{ __('admin.material_tags.create_button') }}
                </button>
            </form>
        </div>

        <div class="mb-6 rounded-lg bg-white shadow">
            <div class="px-6 py-4">
                <form method="GET" class="grid grid-cols-1 gap-4 xl:grid-cols-[minmax(0,1fr)_minmax(360px,1.5fr)_auto_auto] xl:items-start">
                    @if ($scope !== '')
                        <input type="hidden" name="scope" value="{{ $scope }}">
                    @endif
                    @if ((int) $perPage !== 20)
                        <input type="hidden" name="per_page" value="{{ (int) $perPage }}">
                    @endif
                    <input type="text" name="search" value="{{ $search }}" placeholder="{{ __('admin.material_tags.search_placeholder') }}" class="block w-full rounded-md border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">

                    <div data-group-selector class="relative">
                        <div data-group-selected class="flex min-h-[2.5rem] w-full flex-wrap items-center gap-2 rounded-md border border-gray-300 bg-white px-3 py-1.5 shadow-sm focus-within:border-blue-500 focus-within:ring-1 focus-within:ring-blue-500">
                            @foreach ($selectedGroups as $group)
                                <span data-group-chip data-group-value="{{ $group }}" class="group relative inline-flex items-center rounded-full bg-blue-50 px-2.5 py-1 text-xs font-medium text-blue-700">
                                    {{ $group }}
                                    <button type="button" data-group-remove data-group-value="{{ $group }}" class="absolute -right-1 -top-1 hidden rounded-full bg-blue-600 p-0.5 text-white group-hover:inline-flex" title="{{ __('admin.material_tags.group_remove') }}">
                                        <i data-lucide="x" class="h-3 w-3"></i>
                                    </button>
                                    <input type="hidden" name="groups[]" value="{{ $group }}">
                                </span>
                            @endforeach
                            <input type="text" data-group-search value="" autocomplete="off" placeholder="{{ __('admin.material_tags.group_placeholder') }}" class="min-w-[180px] flex-1 border-0 p-0 text-sm outline-none focus:ring-0">
                        </div>
                        <div data-group-menu class="absolute left-0 right-0 z-40 mt-1 hidden max-h-48 overflow-y-auto rounded-md border border-gray-200 bg-white py-1 shadow-lg">
                            @forelse ($groupOptions as $group)
                                <button type="button" data-group-option data-group-value="{{ $group }}" data-group-label="{{ mb_strtolower($group, 'UTF-8') }}" class="flex w-full items-center justify-between px-3 py-2 text-left text-sm text-gray-700 hover:bg-blue-50 hover:text-blue-700">
                                    <span>{{ $group }}</span>
                                    <i data-lucide="check" class="hidden h-4 w-4" data-group-check></i>
                                </button>
                            @empty
                                <div class="px-3 py-2 text-sm text-gray-400">{{ __('admin.material_tags.group_empty') }}</div>
                            @endforelse
                        </div>
                    </div>

                    <button type="submit" class="inline-flex items-center justify-center rounded-md border border-transparent bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">
                        <i data-lucide="search" class="mr-2 h-4 w-4"></i>
                        {{ __('admin.button.search') }}
                    </button>
                    <a href="{{ route('admin.material-tags.index', $clearQuery) }}" class="inline-flex items-center justify-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                        <i data-lucide="x" class="mr-2 h-4 w-4"></i>
                        {{ __('admin.button.clear') }}
                    </a>
                </form>
            </div>
        </div>

        <div class="overflow-hidden rounded-lg bg-white shadow">
            <div class="border-b border-gray-200 px-6 py-4">
                <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                    <h3 class="text-lg font-medium text-gray-900">
                        {{ __('admin.material_tags.list_title') }}
                        <span class="text-sm text-gray-500">({{ (int) $tags->total() }})</span>
                    </h3>
                    <form id="tag-bulk-form" method="POST" action="{{ route('admin.material-tags.bulk', $baseQuery) }}" class="flex flex-wrap items-center gap-2">
                        @csrf
                        <span id="tag-bulk-selected-count" class="text-xs text-gray-500">{{ __('admin.material_tags.bulk_selected', ['count' => 0]) }}</span>
                        <select name="bulk_action" data-bulk-action class="rounded-md border-gray-300 px-3 py-1.5 text-xs shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            <option value="move_group">{{ __('admin.material_tags.bulk_move_group') }}</option>
                            <option value="delete">{{ __('admin.material_tags.bulk_delete') }}</option>
                        </select>
                        <select name="bulk_group_name" data-bulk-group class="w-40 rounded-md border-gray-300 px-3 py-1.5 text-xs shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            <option value="">{{ __('admin.material_tags.bulk_group_placeholder') }}</option>
                            @foreach ($controlledGroupNames as $groupName)
                                <option value="{{ $groupName }}">{{ $groupName }}</option>
                            @endforeach
                        </select>
                        <input type="hidden" name="delete_confirmation" data-bulk-confirm-hidden value="">
                        <button type="submit" class="inline-flex items-center rounded-md border border-gray-300 bg-white px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50">
                            <i data-lucide="layers-3" class="mr-1 h-4 w-4"></i>
                            {{ __('admin.button.apply') }}
                        </button>
                    </form>
                </div>
            </div>

            @if ($tags->isEmpty())
                <div class="px-6 py-10 text-center text-sm text-gray-500">{{ __('admin.material_tags.empty') }}</div>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="w-10 px-4 py-3 text-left">
                                    <input type="checkbox" data-tag-select-all class="h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                                </th>
                                <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">ID</th>
                                <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">{{ __('admin.material_tags.column_name') }}</th>
                                <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">{{ __('admin.material_tags.stat_keywords') }}</th>
                                <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">{{ __('admin.material_tags.stat_images') }}</th>
                                <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">{{ __('admin.material_tags.stat_knowledge') }}</th>
                                <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">{{ __('admin.material_tags.stat_entities') }}</th>
                                <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">{{ __('admin.material_tags.stat_cases') }}</th>
                                <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500">{{ __('admin.material_tags.column_actions') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 bg-white">
                            @foreach ($tags as $tag)
                                <tr data-tag-row>
                                    <td class="px-4 py-3">
                                        <input type="checkbox" form="tag-bulk-form" name="tag_ids[]" value="{{ (int) $tag->id }}" data-tag-row-checkbox class="h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                                    </td>
                                    <td class="whitespace-nowrap px-4 py-3 text-sm text-gray-500">#{{ (int) $tag->id }}</td>
                                    <td class="px-4 py-3">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <span class="inline-flex rounded bg-blue-50 px-3 py-1 text-sm font-semibold text-blue-700">{{ $tag->displayName() }}</span>
                                            @if ((string) ($tag->group_name ?? '') !== '')
                                                <span class="text-xs text-gray-400">{{ $tag->group_name }}</span>
                                            @endif
                                        </div>
                                    </td>
                                    <td class="whitespace-nowrap px-4 py-3 text-sm text-gray-700">{{ number_format((int) ($tag->keywords_count ?? 0)) }}</td>
                                    <td class="whitespace-nowrap px-4 py-3 text-sm text-gray-700">{{ number_format((int) ($tag->images_count ?? 0)) }}</td>
                                    <td class="whitespace-nowrap px-4 py-3 text-sm text-gray-700">{{ number_format((int) ($tag->knowledge_bases_count ?? 0)) }}</td>
                                    <td class="whitespace-nowrap px-4 py-3 text-sm text-gray-700">{{ number_format((int) ($tag->entities_count ?? 0)) }}</td>
                                    <td class="whitespace-nowrap px-4 py-3 text-sm text-gray-700">{{ number_format((int) ($tag->case_records_count ?? 0)) }}</td>
                                    <td class="whitespace-nowrap px-4 py-3 text-right">
                                        <div class="inline-flex items-center gap-1">
                                            <button type="button" data-open-modal="tag-references-{{ (int) $tag->id }}" class="rounded p-2 text-gray-500 hover:bg-gray-100 hover:text-blue-600" title="{{ __('admin.material_tags.action_view') }}">
                                                <i data-lucide="eye" class="h-4 w-4"></i>
                                            </button>
                                            <button type="button" data-open-modal="tag-rename-{{ (int) $tag->id }}" class="rounded p-2 text-gray-500 hover:bg-gray-100 hover:text-green-600" title="{{ __('admin.material_tags.action_rename') }}">
                                                <i data-lucide="pencil" class="h-4 w-4"></i>
                                            </button>
                                            <button type="button" data-open-modal="tag-delete-{{ (int) $tag->id }}" class="rounded p-2 text-gray-500 hover:bg-red-50 hover:text-red-600" title="{{ __('admin.material_tags.action_delete') }}">
                                                <i data-lucide="trash-2" class="h-4 w-4"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                @foreach ($tags as $tag)
                    <div id="tag-references-{{ (int) $tag->id }}" data-modal class="fixed inset-0 z-50 hidden overflow-y-auto bg-gray-900/50">
                        <div class="mx-auto my-10 w-[92vw] max-w-6xl rounded-lg bg-white shadow-xl">
                            <div class="flex items-center justify-between border-b border-gray-200 px-6 py-4">
                                <div>
                                    <h3 class="text-lg font-semibold text-gray-900">{{ __('admin.material_tags.references_title', ['tag' => $tag->displayName()]) }}</h3>
                                    <p class="mt-1 text-sm text-gray-500">{{ __('admin.material_tags.references_subtitle') }}</p>
                                </div>
                                <button type="button" data-close-modal class="rounded p-2 text-gray-400 hover:bg-gray-100 hover:text-gray-600">
                                    <i data-lucide="x" class="h-5 w-5"></i>
                                </button>
                            </div>
                            <div class="p-6" data-tag-references-body data-tag-references-url="{{ route('admin.material-tags.references', ['tagId' => (int) $tag->id]) }}">
                                <div class="rounded-md border border-gray-200 bg-gray-50 px-4 py-8 text-center text-sm text-gray-500">
                                    {{ __('admin.material_tags.references_lazy_hint') }}
                                </div>
                            </div>
                        </div>
                    </div>

                    <div id="tag-rename-{{ (int) $tag->id }}" data-modal class="fixed inset-0 z-50 hidden overflow-y-auto bg-gray-900/50">
                        <div class="mx-auto my-16 w-[92vw] max-w-lg rounded-lg bg-white shadow-xl">
                            <div class="flex items-center justify-between border-b border-gray-200 px-6 py-4">
                                <h3 class="text-lg font-semibold text-gray-900">{{ __('admin.material_tags.rename_title') }}</h3>
                                <button type="button" data-close-modal class="rounded p-2 text-gray-400 hover:bg-gray-100 hover:text-gray-600">
                                    <i data-lucide="x" class="h-5 w-5"></i>
                                </button>
                            </div>
                            <form method="POST" action="{{ route('admin.material-tags.update', ['tagId' => (int) $tag->id]) }}" class="space-y-4 px-6 py-5">
                                @csrf
                                @method('PUT')
                                <div>
                                    <label class="block text-sm font-medium text-gray-700">{{ __('admin.material_tags.field_group') }}</label>
                                    <select name="group_name" required class="mt-1 block w-full rounded-md border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                        <option value="">{{ __('admin.material_tags.placeholder_group') }}</option>
                                        @foreach ($controlledGroupNames as $groupName)
                                            <option value="{{ $groupName }}" @selected((string) ($tag->group_name ?? '') === $groupName)>{{ $groupName }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700">{{ __('admin.material_tags.field_name') }}</label>
                                    <input type="text" name="name" value="{{ (string) $tag->name }}" maxlength="100" required class="mt-1 block w-full rounded-md border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                </div>
                                <p class="text-sm text-gray-500">{{ __('admin.material_tags.rename_help') }}</p>
                                <div class="flex justify-end gap-3">
                                    <button type="button" data-close-modal class="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">{{ __('admin.button.cancel') }}</button>
                                    <button type="submit" class="rounded-md border border-transparent bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">{{ __('admin.material_tags.rename_submit') }}</button>
                                </div>
                            </form>
                        </div>
                    </div>

                    <div id="tag-delete-{{ (int) $tag->id }}" data-modal class="fixed inset-0 z-50 hidden overflow-y-auto bg-gray-900/50">
                        <div class="mx-auto my-16 w-[92vw] max-w-lg rounded-lg bg-white shadow-xl">
                            <div class="flex items-center justify-between border-b border-gray-200 px-6 py-4">
                                <h3 class="text-lg font-semibold text-red-700">{{ __('admin.material_tags.delete_title') }}</h3>
                                <button type="button" data-close-modal class="rounded p-2 text-gray-400 hover:bg-gray-100 hover:text-gray-600">
                                    <i data-lucide="x" class="h-5 w-5"></i>
                                </button>
                            </div>
                            <form method="POST" action="{{ route('admin.material-tags.delete', ['tagId' => (int) $tag->id]) }}" class="space-y-4 px-6 py-5">
                                @csrf
                                <p class="text-sm text-gray-700">{{ __('admin.material_tags.delete_help', ['tag' => $tag->displayName()]) }}</p>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700">{{ __('admin.material_tags.delete_confirmation_label', ['text' => __('admin.material_tags.delete_confirmation_text')]) }}</label>
                                    <input type="text" name="delete_confirmation" data-delete-confirm-input autocomplete="off" class="mt-1 block w-full rounded-md border-2 border-red-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-red-500 focus:ring-red-500">
                                </div>
                                <div class="flex justify-end gap-3">
                                    <button type="button" data-close-modal class="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">{{ __('admin.button.cancel') }}</button>
                                    <button type="submit" data-delete-confirm-submit disabled class="rounded-md border border-transparent bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-700 disabled:bg-gray-300 disabled:text-gray-500">{{ __('admin.material_tags.delete_submit') }}</button>
                                </div>
                            </form>
                        </div>
                    </div>
                @endforeach
            @endif

            <div class="border-t border-gray-200 px-6 py-4">
                <div class="flex flex-col gap-3 lg:grid lg:grid-cols-[minmax(0,1fr)_auto_minmax(0,1fr)] lg:items-center">
                    <div data-tag-pagination-summary class="text-sm text-gray-600">
                        {{ __('admin.material_tags.pagination_summary', [
                            'from' => $tags->firstItem() ?? 0,
                            'to' => $tags->lastItem() ?? 0,
                            'total' => $tags->total(),
                        ]) }}
                    </div>
                    <div class="flex justify-start lg:justify-center">
                        @if ($tags->lastPage() > 1)
                            {{ $tags->links() }}
                        @endif
                    </div>
                    <form method="GET" data-tag-per-page-form class="flex items-center justify-start gap-2 lg:justify-end">
                        @if ($scope !== '')
                            <input type="hidden" name="scope" value="{{ $scope }}">
                        @endif
                        @if ($search !== '')
                            <input type="hidden" name="search" value="{{ $search }}">
                        @endif
                        @foreach ($selectedGroups as $group)
                            <input type="hidden" name="groups[]" value="{{ $group }}">
                        @endforeach
                        <label for="tag-per-page" class="text-sm text-gray-500">{{ __('admin.material_tags.per_page_label') }}</label>
                        <select id="tag-per-page" name="per_page" data-per-page-select class="rounded-md border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            @foreach ($perPageOptions as $option)
                                <option value="{{ $option }}" @selected((int) $perPage === (int) $option)>{{ __('admin.material_tags.per_page_option', ['count' => (int) $option]) }}</option>
                            @endforeach
                        </select>
                    </form>
                </div>
            </div>

            <div id="tag-bulk-delete-modal" data-modal class="fixed inset-0 z-50 hidden overflow-y-auto bg-gray-900/50">
                <div class="mx-auto my-16 w-[92vw] max-w-lg rounded-lg bg-white shadow-xl">
                    <div class="flex items-center justify-between border-b border-gray-200 px-6 py-4">
                        <h3 class="text-lg font-semibold text-red-700">{{ __('admin.material_tags.bulk_delete') }}</h3>
                        <button type="button" data-close-modal class="rounded p-2 text-gray-400 hover:bg-gray-100 hover:text-gray-600">
                            <i data-lucide="x" class="h-5 w-5"></i>
                        </button>
                    </div>
                    <div class="space-y-4 px-6 py-5">
                        <p class="text-sm text-gray-700">{{ __('admin.material_tags.bulk_delete_help') }}</p>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">{{ __('admin.material_tags.delete_confirmation_label', ['text' => __('admin.material_tags.delete_confirmation_text')]) }}</label>
                            <input type="text" data-bulk-delete-confirm-input autocomplete="off" class="mt-1 block w-full rounded-md border-2 border-red-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-red-500 focus:ring-red-500">
                        </div>
                        <div class="flex justify-end gap-3">
                            <button type="button" data-close-modal class="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">{{ __('admin.button.cancel') }}</button>
                            <button type="button" data-bulk-delete-confirm-submit disabled class="rounded-md border border-transparent bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-700 disabled:bg-gray-300 disabled:text-gray-500">{{ __('admin.material_tags.delete_submit') }}</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        (function () {
            const deleteText = @json(__('admin.material_tags.delete_confirmation_text'));
            const referenceLabels = {
                keywords: @json(__('admin.material_tags.applied_keywords')),
                images: @json(__('admin.material_tags.applied_images')),
                knowledge: @json(__('admin.material_tags.applied_knowledge')),
                entities: @json(__('admin.material_tags.applied_entities')),
                cases: @json(__('admin.material_tags.applied_cases')),
            };
            const referenceLoadingText = @json(__('admin.material_tags.references_loading'));
            const referenceErrorText = @json(__('admin.material_tags.references_error'));
            const referenceEmptyText = @json(__('admin.material_tags.none'));

            function refreshIcons() {
                if (typeof lucide !== 'undefined') {
                    lucide.createIcons();
                }
            }

            function renderReferenceSections(body, sections) {
                body.innerHTML = '';
                const grid = document.createElement('div');
                grid.className = 'grid grid-cols-1 gap-4 lg:grid-cols-5';

                Object.keys(referenceLabels).forEach(function (key) {
                    const card = document.createElement('div');
                    card.className = 'rounded-md border border-gray-200 p-3';

                    const title = document.createElement('div');
                    title.className = 'mb-2 text-sm font-medium text-gray-900';
                    title.textContent = referenceLabels[key];
                    card.appendChild(title);

                    const items = Array.isArray(sections?.[key]) ? sections[key] : [];
                    if (items.length === 0) {
                        const empty = document.createElement('div');
                        empty.className = 'text-xs text-gray-400';
                        empty.textContent = referenceEmptyText;
                        card.appendChild(empty);
                    } else {
                        items.forEach(function (item) {
                            const link = document.createElement('a');
                            link.href = item.href || '#';
                            link.className = 'mb-1 block rounded bg-gray-50 px-2 py-1 text-xs text-gray-700 hover:bg-blue-50 hover:text-blue-700';
                            link.textContent = item.label || '';
                            if (item.meta) {
                                const meta = document.createElement('span');
                                meta.className = 'text-gray-400';
                                meta.textContent = ' / ' + item.meta;
                                link.appendChild(meta);
                            }
                            card.appendChild(link);
                        });
                    }

                    grid.appendChild(card);
                });

                body.appendChild(grid);
                body.dataset.loaded = '1';
                refreshIcons();
            }

            function loadReferences(modal) {
                const body = modal?.querySelector('[data-tag-references-body]');
                const url = body?.getAttribute('data-tag-references-url') || '';
                if (!body || !url || body.dataset.loaded === '1' || body.dataset.loading === '1') {
                    return;
                }

                body.dataset.loading = '1';
                body.innerHTML = '<div class="rounded-md border border-gray-200 bg-gray-50 px-4 py-8 text-center text-sm text-gray-500"></div>';
                body.firstElementChild.textContent = referenceLoadingText;

                fetch(url, {
                    headers: {'Accept': 'application/json'},
                    credentials: 'same-origin',
                })
                    .then((response) => {
                        if (!response.ok) {
                            throw new Error('Request failed');
                        }

                        return response.json();
                    })
                    .then((payload) => renderReferenceSections(body, payload.sections || {}))
                    .catch(() => {
                        body.innerHTML = '<div class="rounded-md border border-red-200 bg-red-50 px-4 py-8 text-center text-sm text-red-700"></div>';
                        body.firstElementChild.textContent = referenceErrorText;
                    })
                    .finally(() => {
                        body.dataset.loading = '0';
                    });
            }

            document.addEventListener('click', function (event) {
                const openButton = event.target.closest('[data-open-modal]');
                if (openButton) {
                    const modal = document.getElementById(openButton.getAttribute('data-open-modal'));
                    modal?.classList.remove('hidden');
                    loadReferences(modal);
                    refreshIcons();
                    return;
                }

                if (event.target.closest('[data-close-modal]')) {
                    event.target.closest('[data-modal]')?.classList.add('hidden');
                    return;
                }

                if (event.target.matches('[data-modal]')) {
                    event.target.classList.add('hidden');
                }
            });

            document.addEventListener('input', function (event) {
                const confirmInput = event.target.closest('[data-delete-confirm-input]');
                if (confirmInput) {
                    const form = confirmInput.closest('form');
                    const submit = form?.querySelector('[data-delete-confirm-submit]');
                    if (submit) {
                        submit.disabled = confirmInput.value.trim() !== deleteText;
                    }
                }
            });

            const selectAll = document.querySelector('[data-tag-select-all]');
            const rowCheckboxes = Array.from(document.querySelectorAll('[data-tag-row-checkbox]'));
            const selectedCount = document.getElementById('tag-bulk-selected-count');
            function updateBulkCount() {
                const count = rowCheckboxes.filter((checkbox) => checkbox.checked).length;
                if (selectedCount) {
                    selectedCount.textContent = @json(__('admin.material_tags.bulk_selected', ['count' => '{count}'])).replace('{count}', String(count));
                }
                if (selectAll) {
                    selectAll.checked = rowCheckboxes.length > 0 && count === rowCheckboxes.length;
                    selectAll.indeterminate = count > 0 && count < rowCheckboxes.length;
                }
            }
            selectAll?.addEventListener('change', function () {
                rowCheckboxes.forEach((checkbox) => {
                    checkbox.checked = selectAll.checked;
                });
                updateBulkCount();
            });
            rowCheckboxes.forEach((checkbox) => checkbox.addEventListener('change', updateBulkCount));
            updateBulkCount();

            const bulkAction = document.querySelector('[data-bulk-action]');
            const bulkGroup = document.querySelector('[data-bulk-group]');
            const bulkConfirmHidden = document.querySelector('[data-bulk-confirm-hidden]');
            const bulkDeleteModal = document.getElementById('tag-bulk-delete-modal');
            const bulkDeleteInput = bulkDeleteModal?.querySelector('[data-bulk-delete-confirm-input]');
            const bulkDeleteSubmit = bulkDeleteModal?.querySelector('[data-bulk-delete-confirm-submit]');
            function updateBulkFields() {
                const isDelete = bulkAction?.value === 'delete';
                bulkGroup?.classList.toggle('hidden', isDelete);
                if (bulkConfirmHidden) {
                    bulkConfirmHidden.value = '';
                }
                const bulkForm = document.getElementById('tag-bulk-form');
                if (bulkForm) {
                    bulkForm.dataset.bulkConfirmed = '0';
                }
            }
            bulkAction?.addEventListener('change', updateBulkFields);
            updateBulkFields();

            document.querySelector('[data-per-page-select]')?.addEventListener('change', function () {
                this.form?.submit();
            });

            function openBulkDeleteModal() {
                if (!bulkDeleteModal) {
                    return;
                }

                if (bulkDeleteInput) {
                    bulkDeleteInput.value = '';
                }
                if (bulkDeleteSubmit) {
                    bulkDeleteSubmit.disabled = true;
                }
                bulkDeleteModal.classList.remove('hidden');
                window.setTimeout(function () {
                    bulkDeleteInput?.focus();
                }, 0);
                refreshIcons();
            }

            bulkDeleteInput?.addEventListener('input', function () {
                if (bulkDeleteSubmit) {
                    bulkDeleteSubmit.disabled = bulkDeleteInput.value.trim() !== deleteText;
                }
            });

            bulkDeleteSubmit?.addEventListener('click', function () {
                const bulkForm = document.getElementById('tag-bulk-form');
                if (!bulkForm || !bulkConfirmHidden || bulkDeleteInput?.value.trim() !== deleteText) {
                    return;
                }

                bulkConfirmHidden.value = deleteText;
                bulkForm.dataset.bulkConfirmed = '1';
                bulkDeleteModal?.classList.add('hidden');
                if (typeof bulkForm.requestSubmit === 'function') {
                    bulkForm.requestSubmit();
                    return;
                }

                bulkForm.submit();
            });

            document.getElementById('tag-bulk-form')?.addEventListener('submit', function (event) {
                const count = rowCheckboxes.filter((checkbox) => checkbox.checked).length;
                if (count === 0) {
                    event.preventDefault();
                    alert(@json(__('admin.material_tags.error_select_tags')));
                    return;
                }
                if (bulkAction?.value === 'delete' && this.dataset.bulkConfirmed !== '1') {
                    event.preventDefault();
                    openBulkDeleteModal();
                    return;
                }
                if (bulkAction?.value === 'delete' && bulkConfirmHidden?.value.trim() !== deleteText) {
                    event.preventDefault();
                    openBulkDeleteModal();
                }
            });

            document.querySelectorAll('[data-group-selector]').forEach((selector) => {
                const search = selector.querySelector('[data-group-search]');
                const menu = selector.querySelector('[data-group-menu]');
                const selected = selector.querySelector('[data-group-selected]');
                const options = Array.from(selector.querySelectorAll('[data-group-option]'));

                function selectedValues() {
                    return Array.from(selector.querySelectorAll('input[name="groups[]"]')).map((input) => input.value);
                }

                function optionValue(option) {
                    return option.getAttribute('data-group-value') || '';
                }

                function updateOptionState() {
                    const values = selectedValues();
                    options.forEach((option) => {
                        const isSelected = values.includes(optionValue(option));
                        option.classList.toggle('bg-blue-50', isSelected);
                        option.classList.toggle('text-blue-700', isSelected);
                        option.querySelector('[data-group-check]')?.classList.toggle('hidden', !isSelected);
                    });
                    refreshIcons();
                }

                function filterOptions() {
                    const query = (search?.value || '').trim().toLowerCase();
                    options.forEach((option) => {
                        const label = (option.getAttribute('data-group-label') || '').toLowerCase();
                        option.classList.toggle('hidden', query !== '' && !label.includes(query));
                    });
                }

                function showMenu() {
                    menu?.classList.remove('hidden');
                    filterOptions();
                    updateOptionState();
                }

                function addGroup(value) {
                    if (value === '' || selectedValues().includes(value) || !selected) {
                        return;
                    }

                    const chip = document.createElement('span');
                    chip.setAttribute('data-group-chip', '');
                    chip.setAttribute('data-group-value', value);
                    chip.className = 'group relative inline-flex items-center rounded-full bg-blue-50 px-2.5 py-1 text-xs font-medium text-blue-700';
                    chip.appendChild(document.createTextNode(value));

                    const removeButton = document.createElement('button');
                    removeButton.type = 'button';
                    removeButton.setAttribute('data-group-remove', '');
                    removeButton.setAttribute('data-group-value', value);
                    removeButton.className = 'absolute -right-1 -top-1 hidden rounded-full bg-blue-600 p-0.5 text-white group-hover:inline-flex';
                    removeButton.title = @json(__('admin.material_tags.group_remove'));
                    const icon = document.createElement('i');
                    icon.setAttribute('data-lucide', 'x');
                    icon.className = 'h-3 w-3';
                    removeButton.appendChild(icon);
                    chip.appendChild(removeButton);

                    const hiddenInput = document.createElement('input');
                    hiddenInput.type = 'hidden';
                    hiddenInput.name = 'groups[]';
                    hiddenInput.value = value;
                    chip.appendChild(hiddenInput);

                    selected.insertBefore(chip, search);
                    if (search) {
                        search.value = '';
                        search.focus();
                    }
                    filterOptions();
                    updateOptionState();
                }

                search?.addEventListener('focus', showMenu);
                search?.addEventListener('input', function () {
                    showMenu();
                });
                options.forEach((option) => {
                    option.addEventListener('click', function () {
                        addGroup(optionValue(option));
                    });
                });
                selector.addEventListener('click', function (event) {
                    const remove = event.target.closest('[data-group-remove]');
                    if (remove) {
                        remove.closest('[data-group-chip]')?.remove();
                        updateOptionState();
                        event.preventDefault();
                    } else if (event.target === selected) {
                        search?.focus();
                    }
                });
                document.addEventListener('click', function (event) {
                    if (!selector.contains(event.target)) {
                        menu?.classList.add('hidden');
                    }
                });
                updateOptionState();
            });
        })();
    </script>
@endpush
