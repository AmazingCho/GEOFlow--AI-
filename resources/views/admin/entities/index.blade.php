@extends('admin.layouts.app')

@section('content')
    <div class="px-4 sm:px-0">
        <div class="mb-8 flex items-center justify-between">
            <div class="flex items-center space-x-4">
                <a href="{{ route('admin.materials.index') }}" class="text-gray-400 hover:text-gray-600">
                    <i data-lucide="arrow-left" class="h-5 w-5"></i>
                </a>
                <div>
                    <h1 class="text-2xl font-bold text-gray-900">{{ __('admin.entities.heading') }}</h1>
                    <p class="mt-1 text-sm text-gray-600">{{ __('admin.entities.subtitle') }}</p>
                </div>
            </div>
            <a href="{{ route('admin.entities.create') }}" class="inline-flex items-center rounded-md border border-transparent bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">
                <i data-lucide="plus" class="mr-2 h-4 w-4"></i>
                {{ __('admin.entities.create') }}
            </a>
        </div>

        <div class="mb-8 grid grid-cols-1 gap-6 md:grid-cols-2">
            <div class="rounded-lg bg-white p-5 shadow">
                <div class="flex items-center">
                    <i data-lucide="boxes" class="h-6 w-6 text-blue-600"></i>
                    <div class="ml-4">
                        <div class="text-sm text-gray-500">{{ __('admin.entities.stat_total') }}</div>
                        <div class="text-lg font-medium text-gray-900">{{ number_format((int) ($stats['total'] ?? 0)) }}</div>
                    </div>
                </div>
            </div>
            <div class="rounded-lg bg-white p-5 shadow">
                <div class="flex items-center">
                    <i data-lucide="tags" class="h-6 w-6 text-emerald-600"></i>
                    <div class="ml-4">
                        <div class="text-sm text-gray-500">{{ __('admin.entities.stat_tagged') }}</div>
                        <div class="text-lg font-medium text-gray-900">{{ number_format((int) ($stats['tagged'] ?? 0)) }}</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 gap-6 xl:grid-cols-[260px_minmax(0,1fr)]" data-collection-scoped-list>
            @include('admin.partials.collection-sidebar', [
                'routeName' => 'admin.entities.index',
                'selectedId' => (string) ($collectionId ?? ''),
                'collectionOptions' => $collectionOptions ?? [],
            ])
            <div class="min-w-0">
        <div class="mb-6 rounded-lg bg-white shadow">
            <div class="px-6 py-4">
                <form method="GET" class="grid grid-cols-1 gap-3 lg:grid-cols-[minmax(0,1fr)_minmax(0,320px)_auto_auto] lg:items-center">
                    <input type="text" name="search" value="{{ $search }}" placeholder="{{ __('admin.entities.search_placeholder') }}" class="block w-full rounded-md border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                    <input type="text" name="tag" value="{{ $tagFilter }}" placeholder="{{ __('admin.entities.tag_placeholder') }}" class="block w-full rounded-md border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                    <button type="submit" class="inline-flex items-center justify-center rounded-md border border-transparent bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">
                        <i data-lucide="search" class="mr-2 h-4 w-4"></i>
                        {{ __('admin.button.search') }}
                    </button>
                    <a href="{{ route('admin.entities.index') }}" class="inline-flex items-center justify-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                        <i data-lucide="x" class="mr-2 h-4 w-4"></i>
                        {{ __('admin.button.clear') }}
                    </a>
                </form>
            </div>
        </div>

        <div class="overflow-hidden rounded-lg bg-white shadow">
            <div class="border-b border-gray-200 px-6 py-4">
                <h3 class="text-lg font-medium text-gray-900">
                    {{ __('admin.entities.list_title') }}
                    <span class="text-sm text-gray-500">({{ (int) $entities->total() }})</span>
                </h3>
            </div>

            @if ($entities->isEmpty())
                <div class="px-6 py-10 text-center text-sm text-gray-500">{{ __('admin.entities.empty') }}</div>
            @else
                <div class="divide-y divide-gray-200">
                    @foreach ($entities as $entity)
                        <div class="px-6 py-5">
                            <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <h4 class="text-lg font-semibold text-gray-900">{{ $entity->name }}</h4>
                                        @if ((string) ($entity->entity_type ?? '') !== '')
                                            <span class="rounded bg-blue-50 px-2 py-0.5 text-xs font-medium text-blue-700">{{ $entity->entity_type }}</span>
                                        @endif
                                        <span class="rounded bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-700">
                                            {{ $entity->collection?->name ?? __('admin.collections.badge_unassigned') }}
                                        </span>
                                        <span class="rounded bg-gray-100 px-2 py-0.5 text-xs text-gray-600">{{ __('admin.entities.case_count', ['count' => (int) ($entity->cases_count ?? 0)]) }}</span>
                                    </div>
                                    @if ((string) ($entity->aliases ?? '') !== '')
                                        <p class="mt-1 text-sm text-gray-500">{{ __('admin.entities.aliases_prefix') }}{{ $entity->aliases }}</p>
                                    @endif
                                    @if ((string) ($entity->description ?? '') !== '')
                                        <p class="mt-2 text-sm leading-6 text-gray-600">{{ \Illuminate\Support\Str::limit((string) $entity->description, 180, '...') }}</p>
                                    @endif
                                    @if ($entity->tags->isNotEmpty())
                                        <div class="mt-3 flex flex-wrap gap-2">
                                            @foreach ($entity->tags as $tag)
                                                <a href="{{ route('admin.entities.index', ['tag' => $tag->displayName()]) }}" class="rounded bg-gray-50 px-2 py-1 text-xs font-medium text-gray-700 hover:bg-blue-50 hover:text-blue-700">{{ $tag->displayName() }}</a>
                                            @endforeach
                                        </div>
                                    @endif
                                </div>
                                <div class="flex shrink-0 items-center gap-2">
                                    <a href="{{ route('admin.entities.edit', ['entityId' => (int) $entity->id]) }}" class="inline-flex items-center rounded border border-gray-300 bg-white px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50">
                                        <i data-lucide="pencil" class="mr-1 h-4 w-4"></i>
                                        {{ __('admin.button.edit') }}
                                    </a>
                                    <form method="POST" action="{{ route('admin.entities.delete', ['entityId' => (int) $entity->id]) }}" onsubmit="return confirm(@json(__('admin.entities.confirm_delete', ['name' => $entity->name])));">
                                        @csrf
                                        <button type="submit" class="inline-flex items-center rounded border border-transparent bg-red-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-red-700">
                                            <i data-lucide="trash-2" class="mr-1 h-4 w-4"></i>
                                            {{ __('admin.button.delete') }}
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>

                @if ($entities->lastPage() > 1)
                    <div class="border-t border-gray-200 px-6 py-4">
                        {{ $entities->links() }}
                    </div>
                @endif
            @endif
        </div>
            </div>
        </div>
    </div>
@endsection
