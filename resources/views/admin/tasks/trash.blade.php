@extends('admin.layouts.app')

@section('content')
    <div class="px-4 sm:px-0">
        <div class="mb-8 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <h1 class="text-2xl font-bold text-gray-900">{{ $pageTitle }}</h1>
                <p class="mt-1 text-sm text-gray-600">{{ __('admin.tasks.trash.subtitle') }}</p>
            </div>
            <a href="{{ route('admin.tasks.index') }}" class="inline-flex w-fit items-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                <i data-lucide="arrow-left" class="mr-2 h-4 w-4"></i>
                {{ __('admin.tasks.trash.back') }}
            </a>
        </div>

        @if (!empty($legacyError))
            <div class="admin-flash-alert mb-4 rounded border border-red-300 bg-red-50 px-4 py-3 text-sm text-red-700">
                {{ $legacyError }}
            </div>
        @endif

        <div class="rounded-lg bg-white shadow">
            <div class="border-b border-gray-200 px-6 py-4">
                <div class="flex flex-col gap-1 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <h3 class="text-lg font-medium text-gray-900">{{ __('admin.tasks.trash.list_title') }}</h3>
                        <p class="mt-1 text-sm text-gray-500">{{ __('admin.tasks.trash.list_hint') }}</p>
                    </div>
                    <span class="text-xs font-medium text-gray-500">{{ __('admin.tasks.trash.count', ['count' => count($tasks)]) }}</span>
                </div>
            </div>

            @if (empty($tasks))
                <div class="px-6 py-12 text-center">
                    <i data-lucide="archive" class="mx-auto mb-4 h-12 w-12 text-gray-400"></i>
                    <h3 class="mb-2 text-lg font-medium text-gray-900">{{ __('admin.tasks.trash.empty_title') }}</h3>
                    <p class="text-sm text-gray-500">{{ __('admin.tasks.trash.empty_desc') }}</p>
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[920px] table-fixed divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                        <tr>
                            <th class="w-[30%] px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">{{ __('admin.tasks.column.name') }}</th>
                            <th class="w-[16%] px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">{{ __('admin.tasks.trash.deleted_at') }}</th>
                            <th class="w-[16%] px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">{{ __('admin.tasks.column.created_at') }}</th>
                            <th class="w-[18%] px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">{{ __('admin.tasks.column.article_stats') }}</th>
                            <th class="w-[10%] px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">{{ __('admin.tasks.column.status') }}</th>
                            <th class="w-[10%] px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">{{ __('admin.tasks.column.actions') }}</th>
                        </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 bg-white">
                        @foreach ($tasks as $task)
                            <tr class="hover:bg-gray-50">
                                <td class="px-5 py-4 align-top">
                                    <div class="line-clamp-2 break-words text-sm font-semibold text-gray-900">{{ (string) ($task['name'] ?? '-') }}</div>
                                    @if((string) ($task['collection_name'] ?? '') !== '')
                                        <div class="mt-1 truncate text-xs text-gray-500">{{ (string) $task['collection_name'] }}</div>
                                    @endif
                                </td>
                                <td class="px-5 py-4 align-top text-sm text-gray-600">{{ (string) ($task['deleted_at'] ?? '-') ?: '-' }}</td>
                                <td class="px-5 py-4 align-top text-sm text-gray-600">{{ (string) ($task['created_at'] ?? '-') ?: '-' }}</td>
                                <td class="px-5 py-4 align-top text-sm text-gray-600">
                                    <div>{{ __('admin.tasks.label.created_of_limit', ['created' => (int) ($task['total_articles'] ?? 0), 'limit' => (int) ($task['article_limit'] ?? 0)]) }}</div>
                                    <div class="mt-1 text-xs text-gray-500">{{ __('admin.tasks.label.published_articles', ['count' => (int) ($task['published_articles'] ?? 0)]) }}</div>
                                </td>
                                <td class="px-5 py-4 align-top">
                                    <span class="inline-flex rounded-full bg-gray-100 px-2.5 py-1 text-xs font-semibold text-gray-700">{{ __('admin.tasks.trash.deleted_badge') }}</span>
                                </td>
                                <td class="px-5 py-4 align-top">
                                    <form method="POST" action="{{ route('admin.tasks.restore', ['taskId' => (int) ($task['id'] ?? 0)]) }}" onsubmit="return confirm(@js(__('admin.tasks.confirm.restore')))">
                                        @csrf
                                        <button type="submit" class="inline-flex items-center rounded-md border border-emerald-200 bg-emerald-50 px-3 py-1.5 text-xs font-semibold text-emerald-700 hover:bg-emerald-100">
                                            <i data-lucide="rotate-ccw" class="mr-1.5 h-3.5 w-3.5"></i>
                                            {{ __('admin.tasks.action.restore') }}
                                        </button>
                                    </form>
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
