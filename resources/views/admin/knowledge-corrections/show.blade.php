@extends('admin.layouts.app')

@php
    $status = (string) $correction->status;
    $statusTone = match($status) {
        'approved' => 'bg-blue-50 text-blue-700 ring-blue-100',
        'applied' => 'bg-emerald-50 text-emerald-700 ring-emerald-100',
        'rejected' => 'bg-red-50 text-red-700 ring-red-100',
        default => 'bg-amber-50 text-amber-700 ring-amber-100',
    };
    $sourceContext = collect($correction->retrieved_context ?? [])->filter(fn ($row) => is_array($row))->values();
    $currentChunkContent = (string) ($correction->chunk?->content ?? '');
    $originalContent = (string) ($correction->versions->last()?->old_content ?? $currentChunkContent);
    $suggestedContent = (string) ($correction->suggested_content ?? '');
    $textareaClass = 'block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500';
@endphp

@section('content')
    <div class="px-4 sm:px-0">
        <div class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <div class="flex flex-wrap items-center gap-2">
                    <a href="{{ route('admin.knowledge-corrections.index') }}" class="text-gray-400 hover:text-gray-600">
                        <i data-lucide="arrow-left" class="h-5 w-5"></i>
                    </a>
                    <h1 class="text-2xl font-bold text-gray-900">{{ __('admin.knowledge_corrections.detail_title', ['id' => (int) $correction->id]) }}</h1>
                    <span class="inline-flex rounded-full px-3 py-1 text-xs font-semibold ring-1 {{ $statusTone }}">
                        {{ __('admin.knowledge_corrections.status.'.$status) }}
                    </span>
                </div>
                <p class="mt-2 text-sm leading-6 text-gray-600">{{ __('admin.knowledge_corrections.detail_subtitle') }}</p>
            </div>
            <div class="flex flex-wrap gap-2">
                @if($correction->knowledgeBase)
                    <a href="{{ route('admin.knowledge-bases.detail', ['knowledgeBaseId' => (int) $correction->knowledgeBase->id]) }}" class="inline-flex h-9 items-center rounded-md border border-gray-300 bg-white px-3 text-sm font-medium text-gray-700 hover:bg-gray-50">
                        <i data-lucide="database" class="mr-2 h-4 w-4"></i>
                        {{ __('admin.knowledge_corrections.open_knowledge') }}
                    </a>
                @endif
                @if($correction->article)
                    <a href="{{ route('admin.articles.edit', ['articleId' => (int) $correction->article->id]) }}" class="inline-flex h-9 items-center rounded-md border border-gray-300 bg-white px-3 text-sm font-medium text-gray-700 hover:bg-gray-50">
                        <i data-lucide="file-text" class="mr-2 h-4 w-4"></i>
                        {{ __('admin.knowledge_corrections.open_article') }}
                    </a>
                @endif
            </div>
        </div>

        <div class="grid grid-cols-1 gap-6 xl:grid-cols-[minmax(0,1fr)_360px]">
            <div class="space-y-6">
                <div class="rounded-lg border border-gray-200 bg-white shadow-sm">
                    <div class="border-b border-gray-200 px-6 py-4">
                        <h3 class="text-lg font-medium text-gray-900">{{ __('admin.knowledge_corrections.issue_title') }}</h3>
                    </div>
                    <div class="space-y-4 px-6 py-5 text-sm text-gray-700">
                        <div>
                            <div class="text-xs font-semibold uppercase tracking-wide text-gray-400">{{ __('admin.knowledge_corrections.field_error_description') }}</div>
                            <div class="mt-2 whitespace-pre-wrap rounded-lg border border-gray-100 bg-gray-50 p-3">{{ (string) $correction->error_description }}</div>
                        </div>
                        @if((string) ($correction->selected_article_text ?? '') !== '')
                            <div>
                                <div class="text-xs font-semibold uppercase tracking-wide text-gray-400">{{ __('admin.knowledge_corrections.field_selected_article_text') }}</div>
                                <div class="mt-2 whitespace-pre-wrap rounded-lg border border-blue-100 bg-blue-50/60 p-3">{{ (string) $correction->selected_article_text }}</div>
                            </div>
                        @endif
                    </div>
                </div>

                <div class="rounded-lg border border-gray-200 bg-white shadow-sm">
                    <div class="border-b border-gray-200 px-6 py-4">
                        <h3 class="text-lg font-medium text-gray-900">{{ __('admin.knowledge_corrections.ai_result_title') }}</h3>
                    </div>
                    <div class="grid grid-cols-1 gap-4 px-6 py-5 text-sm md:grid-cols-3">
                        <div class="rounded-lg border border-gray-100 bg-gray-50 p-3">
                            <div class="text-xs font-medium text-gray-400">{{ __('admin.knowledge_corrections.confirmed_error') }}</div>
                            <div class="mt-1 font-semibold text-gray-900">{{ $correction->confirmed_error ? __('admin.common.yes') : __('admin.common.no') }}</div>
                        </div>
                        <div class="rounded-lg border border-gray-100 bg-gray-50 p-3">
                            <div class="text-xs font-medium text-gray-400">{{ __('admin.knowledge_corrections.error_type') }}</div>
                            <div class="mt-1 font-semibold text-gray-900">{{ (string) ($correction->error_type ?: '-') }}</div>
                        </div>
                        <div class="rounded-lg border border-gray-100 bg-gray-50 p-3">
                            <div class="text-xs font-medium text-gray-400">{{ __('admin.knowledge_corrections.column_confidence') }}</div>
                            <div class="mt-1 font-semibold text-gray-900">{{ number_format((float) $correction->confidence * 100, 0) }}%</div>
                        </div>
                    </div>
                    <div class="border-t border-gray-100 px-6 py-5">
                        <div class="text-xs font-semibold uppercase tracking-wide text-gray-400">{{ __('admin.knowledge_corrections.reasoning') }}</div>
                        <div class="mt-2 whitespace-pre-wrap rounded-lg border border-gray-100 bg-gray-50 p-3 text-sm leading-6 text-gray-700">{{ (string) ($correction->reasoning ?: '-') }}</div>
                    </div>
                </div>

                <div class="rounded-lg border border-gray-200 bg-white shadow-sm">
                    <div class="border-b border-gray-200 px-6 py-4">
                        <h3 class="text-lg font-medium text-gray-900">{{ __('admin.knowledge_corrections.diff_title') }}</h3>
                        <p class="mt-1 text-xs leading-5 text-gray-500">{{ __('admin.knowledge_corrections.diff_desc') }}</p>
                    </div>
                    <div class="grid grid-cols-1 gap-4 px-6 py-5 lg:grid-cols-2">
                        <div>
                            <div class="mb-2 text-sm font-semibold text-gray-800">{{ __('admin.knowledge_corrections.original_content') }}</div>
                            <pre class="max-h-[520px] overflow-auto whitespace-pre-wrap rounded-lg border border-red-100 bg-red-50/40 p-4 text-xs leading-6 text-gray-800">{{ $originalContent !== '' ? $originalContent : '-' }}</pre>
                        </div>
                        <div>
                            <div class="mb-2 text-sm font-semibold text-gray-800">{{ __('admin.knowledge_corrections.suggested_content') }}</div>
                            <pre class="max-h-[520px] overflow-auto whitespace-pre-wrap rounded-lg border border-emerald-100 bg-emerald-50/40 p-4 text-xs leading-6 text-gray-800">{{ $suggestedContent !== '' ? $suggestedContent : '-' }}</pre>
                        </div>
                    </div>
                </div>

                <div class="rounded-lg border border-gray-200 bg-white shadow-sm">
                    <div class="border-b border-gray-200 px-6 py-4">
                        <h3 class="text-lg font-medium text-gray-900">{{ __('admin.knowledge_corrections.context_title') }}</h3>
                    </div>
                    <div class="divide-y divide-gray-100">
                        @forelse($sourceContext as $context)
                            <div class="px-6 py-4 text-sm">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="font-semibold text-gray-900">{{ (string) ($context['knowledge_base_name'] ?? '-') }}</span>
                                    <span class="rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-600">KB #{{ (int) ($context['knowledge_base_id'] ?? 0) }}</span>
                                    <span class="rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-600">Chunk #{{ (int) ($context['chunk_index'] ?? 0) }}</span>
                                </div>
                                @if((string) ($context['section_path'] ?? '') !== '')
                                    <div class="mt-1 text-xs text-gray-500">{{ (string) $context['section_path'] }}</div>
                                @endif
                                <div class="mt-2 text-xs leading-5 text-gray-600">{{ (string) ($context['preview'] ?? '') }}</div>
                            </div>
                        @empty
                            <div class="px-6 py-6 text-sm text-gray-500">{{ __('admin.knowledge_corrections.context_empty') }}</div>
                        @endforelse
                    </div>
                </div>

                <div class="rounded-lg border border-gray-200 bg-white shadow-sm">
                    <div class="border-b border-gray-200 px-6 py-4">
                        <h3 class="text-lg font-medium text-gray-900">{{ __('admin.knowledge_corrections.versions_title') }}</h3>
                    </div>
                    <div class="divide-y divide-gray-100">
                        @forelse($correction->versions as $version)
                            <div class="px-6 py-4">
                                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                    <div>
                                        <div class="text-sm font-semibold text-gray-900">V{{ (int) $version->version_no }} · {{ (string) ($version->change_reason ?: '-') }}</div>
                                        <div class="mt-1 text-xs text-gray-500">{{ optional($version->created_at)->format('Y-m-d H:i:s') }}</div>
                                    </div>
                                    <form method="POST" action="{{ route('admin.knowledge-corrections.versions.rollback', ['correctionId' => (int) $correction->id, 'versionId' => (int) $version->id]) }}" onsubmit="return confirm(@js(__('admin.knowledge_corrections.confirm_rollback')));">
                                        @csrf
                                        <button type="submit" class="inline-flex items-center rounded-md border border-gray-300 bg-white px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50">
                                            <i data-lucide="rotate-ccw" class="mr-1.5 h-3.5 w-3.5"></i>
                                            {{ __('admin.knowledge_corrections.rollback') }}
                                        </button>
                                    </form>
                                </div>
                            </div>
                        @empty
                            <div class="px-6 py-6 text-sm text-gray-500">{{ __('admin.knowledge_corrections.versions_empty') }}</div>
                        @endforelse
                    </div>
                </div>
            </div>

            <aside class="space-y-6">
                <div class="rounded-lg border border-gray-200 bg-white shadow-sm">
                    <div class="border-b border-gray-200 px-5 py-4">
                        <h3 class="text-base font-semibold text-gray-900">{{ __('admin.knowledge_corrections.review_title') }}</h3>
                    </div>
                    <div class="space-y-4 px-5 py-5">
                        <form method="POST" action="{{ route('admin.knowledge-corrections.approve', ['correctionId' => (int) $correction->id]) }}" class="space-y-3">
                            @csrf
                            <textarea name="review_note" rows="3" class="{{ $textareaClass }}" placeholder="{{ __('admin.knowledge_corrections.placeholder_review_note') }}"></textarea>
                            <button type="submit" @disabled($status === 'applied' || $status === 'rejected') class="inline-flex w-full items-center justify-center rounded-md border border-transparent bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-50">
                                <i data-lucide="check" class="mr-2 h-4 w-4"></i>
                                {{ __('admin.knowledge_corrections.approve') }}
                            </button>
                        </form>
                        <form method="POST" action="{{ route('admin.knowledge-corrections.apply', ['correctionId' => (int) $correction->id]) }}" class="space-y-3">
                            @csrf
                            <textarea name="review_note" rows="3" class="{{ $textareaClass }}" placeholder="{{ __('admin.knowledge_corrections.placeholder_apply_note') }}"></textarea>
                            <button type="submit" @disabled($status === 'applied' || $status === 'rejected') class="inline-flex w-full items-center justify-center rounded-md border border-transparent bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700 disabled:cursor-not-allowed disabled:opacity-50" onclick="return confirm(@js(__('admin.knowledge_corrections.confirm_apply')));">
                                <i data-lucide="check-check" class="mr-2 h-4 w-4"></i>
                                {{ __('admin.knowledge_corrections.apply') }}
                            </button>
                        </form>
                        <form method="POST" action="{{ route('admin.knowledge-corrections.reject', ['correctionId' => (int) $correction->id]) }}" class="space-y-3">
                            @csrf
                            <textarea name="review_note" rows="3" class="{{ $textareaClass }}" placeholder="{{ __('admin.knowledge_corrections.placeholder_reject_note') }}"></textarea>
                            <button type="submit" @disabled($status === 'applied' || $status === 'rejected') class="inline-flex w-full items-center justify-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-50">
                                <i data-lucide="x" class="mr-2 h-4 w-4"></i>
                                {{ __('admin.knowledge_corrections.reject') }}
                            </button>
                        </form>
                    </div>
                </div>

                <div class="rounded-lg border border-gray-200 bg-white p-5 text-sm shadow-sm">
                    <div class="text-xs font-semibold uppercase tracking-wide text-gray-400">{{ __('admin.knowledge_corrections.meta_title') }}</div>
                    <dl class="mt-3 space-y-3">
                        <div>
                            <dt class="text-gray-500">{{ __('admin.knowledge_corrections.reported_by') }}</dt>
                            <dd class="font-medium text-gray-900">{{ $correction->reportedBy?->display_name ?: $correction->reportedBy?->username ?: '-' }}</dd>
                        </div>
                        <div>
                            <dt class="text-gray-500">{{ __('admin.knowledge_corrections.reviewed_by') }}</dt>
                            <dd class="font-medium text-gray-900">{{ $correction->reviewedBy?->display_name ?: $correction->reviewedBy?->username ?: '-' }}</dd>
                        </div>
                        <div>
                            <dt class="text-gray-500">{{ __('admin.knowledge_corrections.ai_model') }}</dt>
                            <dd class="font-medium text-gray-900">{{ $correction->aiModel?->name ?? '-' }}</dd>
                        </div>
                        <div>
                            <dt class="text-gray-500">{{ __('admin.knowledge_corrections.applied_at') }}</dt>
                            <dd class="font-medium text-gray-900">{{ optional($correction->applied_at)->format('Y-m-d H:i:s') ?? '-' }}</dd>
                        </div>
                    </dl>
                </div>
            </aside>
        </div>
    </div>
@endsection
