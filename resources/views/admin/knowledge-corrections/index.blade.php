@extends('admin.layouts.app')

@section('content')
    <div class="px-4 sm:px-0">
        <div class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <h1 class="text-2xl font-bold text-gray-900">{{ __('admin.knowledge_corrections.heading') }}</h1>
                <p class="mt-1 text-sm leading-6 text-gray-600">{{ __('admin.knowledge_corrections.subtitle') }}</p>
            </div>
            <a href="{{ route('admin.knowledge-bases.index') }}" class="inline-flex h-10 items-center rounded-md border border-gray-300 bg-white px-4 text-sm font-medium text-gray-700 hover:bg-gray-50">
                <i data-lucide="database" class="mr-2 h-4 w-4"></i>
                {{ __('admin.knowledge_corrections.back_to_knowledge') }}
            </a>
        </div>

        <form method="GET" action="{{ route('admin.knowledge-corrections.index') }}" class="mb-6 rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
            <div class="grid grid-cols-1 gap-4 md:grid-cols-[180px_minmax(0,1fr)_minmax(0,1fr)_auto] md:items-end">
                <div>
                    <label class="block text-sm font-medium text-gray-700">{{ __('admin.common.status') }}</label>
                    <select name="status" class="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                        <option value="">{{ __('admin.knowledge_corrections.filter_all_status') }}</option>
                        @foreach($statusOptions as $option)
                            <option value="{{ $option['value'] }}" @selected(($filters['status'] ?? '') === $option['value'])>{{ $option['label'] }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">{{ __('admin.knowledge_corrections.filter_knowledge_base_id') }}</label>
                    <input type="number" min="1" name="knowledge_base_id" value="{{ (int) ($filters['knowledge_base_id'] ?? 0) ?: '' }}" class="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">{{ __('admin.knowledge_corrections.filter_article_id') }}</label>
                    <input type="number" min="1" name="article_id" value="{{ (int) ($filters['article_id'] ?? 0) ?: '' }}" class="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                </div>
                <div class="flex gap-2">
                    <button type="submit" class="inline-flex h-10 items-center rounded-md border border-transparent bg-blue-600 px-4 text-sm font-medium text-white hover:bg-blue-700">
                        <i data-lucide="filter" class="mr-2 h-4 w-4"></i>
                        {{ __('admin.button.filter') }}
                    </button>
                    <a href="{{ route('admin.knowledge-corrections.index') }}" class="inline-flex h-10 items-center rounded-md border border-gray-300 bg-white px-4 text-sm font-medium text-gray-700 hover:bg-gray-50">
                        {{ __('admin.button.reset') }}
                    </a>
                </div>
            </div>
        </form>

        <div class="overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">ID</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">{{ __('admin.knowledge_corrections.column_source') }}</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">{{ __('admin.knowledge_corrections.column_issue') }}</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">{{ __('admin.common.status') }}</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">{{ __('admin.knowledge_corrections.column_confidence') }}</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">{{ __('admin.knowledge_corrections.column_created') }}</th>
                        <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-gray-500">{{ __('admin.common.actions') }}</th>
                    </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 bg-white">
                    @forelse($corrections as $correction)
                        <tr>
                            <td class="whitespace-nowrap px-4 py-4 text-sm font-semibold text-gray-900">#{{ (int) $correction->id }}</td>
                            <td class="px-4 py-4 text-sm text-gray-700">
                                <div class="font-medium text-gray-900">{{ $correction->knowledgeBase?->name ?? '-' }}</div>
                                <div class="mt-1 text-xs text-gray-500">
                                    KB #{{ (int) ($correction->knowledge_base_id ?? 0) }}
                                    @if($correction->article)
                                        · {{ __('admin.knowledge_corrections.article_ref') }} #{{ (int) $correction->article->id }}
                                    @endif
                                    @if($correction->chunk)
                                        · Chunk #{{ (int) $correction->chunk->chunk_index }}
                                    @endif
                                </div>
                            </td>
                            <td class="px-4 py-4 text-sm text-gray-700">
                                <div class="line-clamp-2 max-w-xl">{{ (string) $correction->error_description }}</div>
                                <div class="mt-1 text-xs text-gray-500">{{ __('admin.knowledge_corrections.error_type') }}: {{ (string) ($correction->error_type ?: '-') }}</div>
                            </td>
                            <td class="whitespace-nowrap px-4 py-4 text-sm">
                                <span class="inline-flex rounded-full bg-gray-100 px-2.5 py-1 text-xs font-semibold text-gray-700">
                                    {{ __('admin.knowledge_corrections.status.'.(string) $correction->status) }}
                                </span>
                            </td>
                            <td class="whitespace-nowrap px-4 py-4 text-sm text-gray-700">{{ number_format((float) $correction->confidence * 100, 0) }}%</td>
                            <td class="whitespace-nowrap px-4 py-4 text-sm text-gray-500">{{ optional($correction->created_at)->format('Y-m-d H:i') }}</td>
                            <td class="whitespace-nowrap px-4 py-4 text-right text-sm">
                                <a href="{{ route('admin.knowledge-corrections.show', ['correctionId' => (int) $correction->id]) }}" class="inline-flex items-center rounded-md border border-gray-300 bg-white px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50">
                                    <i data-lucide="eye" class="mr-1.5 h-3.5 w-3.5"></i>
                                    {{ __('admin.button.view') }}
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-4 py-10 text-center text-sm text-gray-500">{{ __('admin.knowledge_corrections.empty') }}</td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            <div class="border-t border-gray-100 px-4 py-3">
                {{ $corrections->links() }}
            </div>
        </div>
    </div>
@endsection
