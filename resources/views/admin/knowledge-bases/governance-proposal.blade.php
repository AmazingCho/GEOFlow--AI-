@extends('admin.layouts.app')

@php
    $status = (string) $proposal->status;
    $type = (string) $proposal->proposal_type;
    $statusTone = match($status) {
        'approved' => 'bg-blue-50 text-blue-700 ring-blue-100',
        'applied' => 'bg-emerald-50 text-emerald-700 ring-emerald-100',
        'rejected' => 'bg-red-50 text-red-700 ring-red-100',
        'rolled_back' => 'bg-gray-100 text-gray-700 ring-gray-200',
        default => 'bg-amber-50 text-amber-700 ring-amber-100',
    };
    $isDuplicateArchive = $type === 'duplicate_archive';
    $isConflictReview = $type === 'conflict_review';
    $canApply = in_array($status, ['pending', 'approved'], true);
    $canReject = in_array($status, ['pending', 'approved'], true);
    $canRollback = $status === 'applied' && $isDuplicateArchive;
    $detailUrl = static fn ($knowledgeBase): string => route('admin.knowledge-bases.detail', ['knowledgeBaseId' => (int) $knowledgeBase->id]);
    $textareaClass = 'block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500';
@endphp

@section('content')
    <div class="px-4 sm:px-0">
        <div class="mb-6 flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
            <div>
                <div class="flex flex-wrap items-center gap-2">
                    <a href="{{ route('admin.knowledge-bases.governance', array_filter(['collection_id' => $proposal->collection_id])) }}" class="text-gray-400 hover:text-gray-600">
                        <i data-lucide="arrow-left" class="h-5 w-5"></i>
                    </a>
                    <h1 class="text-2xl font-bold text-gray-900">{{ __('admin.knowledge_governance_proposals.detail_title', ['id' => (int) $proposal->id]) }}</h1>
                    <span class="inline-flex rounded-full px-3 py-1 text-xs font-semibold ring-1 {{ $statusTone }}">
                        {{ __('admin.knowledge_governance_proposals.status.'.$status) }}
                    </span>
                </div>
                <p class="mt-2 max-w-3xl text-sm leading-6 text-gray-600">{{ __('admin.knowledge_governance_proposals.detail_subtitle') }}</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <a href="{{ route('admin.knowledge-bases.governance', array_filter(['collection_id' => $proposal->collection_id])) }}" class="inline-flex h-9 items-center rounded-md border border-gray-300 bg-white px-3 text-sm font-medium text-gray-700 hover:bg-gray-50">
                    <i data-lucide="scan-search" class="mr-2 h-4 w-4"></i>
                    {{ __('admin.knowledge_governance_proposals.back_to_governance') }}
                </a>
                <a href="{{ route('admin.knowledge-bases.index', array_filter(['collection_id' => $proposal->collection_id])) }}" class="inline-flex h-9 items-center rounded-md border border-gray-300 bg-white px-3 text-sm font-medium text-gray-700 hover:bg-gray-50">
                    <i data-lucide="database" class="mr-2 h-4 w-4"></i>
                    {{ __('admin.knowledge_governance.back_to_knowledge') }}
                </a>
            </div>
        </div>

        <div class="grid grid-cols-1 gap-6 xl:grid-cols-[minmax(0,1fr)_360px]">
            <main class="space-y-6">
                <section class="rounded-lg border border-gray-200 bg-white shadow-sm">
                    <div class="border-b border-gray-100 px-6 py-4">
                        <h2 class="text-lg font-semibold text-gray-900">{{ __('admin.knowledge_governance_proposals.summary_title') }}</h2>
                    </div>
                    <div class="grid grid-cols-1 gap-4 px-6 py-5 text-sm md:grid-cols-3">
                        <div class="rounded-lg border border-gray-100 bg-gray-50 p-3">
                            <div class="text-xs font-medium text-gray-500">{{ __('admin.knowledge_governance_proposals.field_type') }}</div>
                            <div class="mt-1 font-semibold text-gray-900">{{ __('admin.knowledge_governance_proposals.type.'.$type) }}</div>
                        </div>
                        <div class="rounded-lg border border-gray-100 bg-gray-50 p-3">
                            <div class="text-xs font-medium text-gray-500">{{ __('admin.collections.field_collection') }}</div>
                            <div class="mt-1 font-semibold text-gray-900">{{ $proposal->collection?->name ?? __('admin.collections.badge_unassigned') }}</div>
                        </div>
                        <div class="rounded-lg border border-gray-100 bg-gray-50 p-3">
                            <div class="text-xs font-medium text-gray-500">{{ __('admin.knowledge_governance_proposals.field_created_by') }}</div>
                            <div class="mt-1 font-semibold text-gray-900">{{ $proposal->createdBy?->display_name ?: $proposal->createdBy?->username ?: '-' }}</div>
                        </div>
                    </div>
                    <div class="border-t border-gray-100 px-6 py-5">
                        <div class="text-sm font-semibold text-gray-900">{{ __('admin.knowledge_governance_proposals.proposed_action') }}</div>
                        <div class="mt-2 whitespace-pre-wrap rounded-md border border-blue-100 bg-blue-50/60 px-4 py-3 text-sm leading-6 text-blue-900">{{ (string) ($proposal->proposed_content ?: '-') }}</div>
                    </div>
                </section>

                <section class="rounded-lg border border-gray-200 bg-white shadow-sm">
                    <div class="border-b border-gray-100 px-6 py-4">
                        <h2 class="text-lg font-semibold text-gray-900">{{ __('admin.knowledge_governance_proposals.sources_title') }}</h2>
                        <p class="mt-1 text-sm leading-6 text-gray-600">{{ __('admin.knowledge_governance_proposals.sources_desc') }}</p>
                    </div>
                    <div class="divide-y divide-gray-100">
                        @foreach ($knowledgeBases as $knowledgeBase)
                            @php($before = $beforeSnapshot[(int) $knowledgeBase->id] ?? [])
                            <div class="px-6 py-4">
                                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                    <div class="min-w-0">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <a href="{{ $detailUrl($knowledgeBase) }}" class="font-semibold text-gray-900 hover:text-blue-700">{{ (string) $knowledgeBase->name }}</a>
                                            @if ((int) $proposal->primary_knowledge_base_id === (int) $knowledgeBase->id)
                                                <span class="rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-semibold text-emerald-700">{{ __('admin.knowledge_governance_proposals.primary_source') }}</span>
                                            @else
                                                <span class="rounded-full bg-amber-50 px-2 py-0.5 text-xs font-semibold text-amber-700">{{ __('admin.knowledge_governance_proposals.related_source') }}</span>
                                            @endif
                                            <span class="rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-600">{{ (string) ($knowledgeBase->status ?: '-') }}</span>
                                        </div>
                                        <div class="mt-2 flex flex-wrap gap-2 text-xs text-gray-500">
                                            <span>{{ $knowledgeBase->collection?->name ?? __('admin.collections.badge_unassigned') }}</span>
                                            @if ((string) ($knowledgeBase->source_url ?? '') !== '')
                                                <span class="truncate">{{ (string) $knowledgeBase->source_url }}</span>
                                            @endif
                                            <span>{{ __('admin.knowledge_governance_proposals.before_status', ['status' => (string) ($before['status'] ?? '-')]) }}</span>
                                        </div>
                                        @if ((string) ($knowledgeBase->summary ?? '') !== '')
                                            <p class="mt-2 text-sm leading-6 text-gray-600">{{ (string) $knowledgeBase->summary }}</p>
                                        @endif
                                    </div>
                                    <a href="{{ $detailUrl($knowledgeBase) }}" class="inline-flex w-fit items-center rounded-md border border-gray-300 bg-white px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50">
                                        {{ __('admin.knowledge_governance.view_detail') }}
                                    </a>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </section>

                <section class="rounded-lg border border-gray-200 bg-white shadow-sm">
                    <div class="border-b border-gray-100 px-6 py-4">
                        <h2 class="text-lg font-semibold text-gray-900">{{ __('admin.knowledge_governance_proposals.snapshot_title') }}</h2>
                        <p class="mt-1 text-sm leading-6 text-gray-600">{{ __('admin.knowledge_governance_proposals.snapshot_desc') }}</p>
                    </div>
                    <pre class="max-h-[420px] overflow-auto whitespace-pre-wrap px-6 py-5 text-xs leading-6 text-gray-700">{{ json_encode($proposal->detection_snapshot ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                </section>
            </main>

            <aside class="space-y-6">
                <section class="rounded-lg border border-gray-200 bg-white shadow-sm">
                    <div class="border-b border-gray-100 px-5 py-4">
                        <h2 class="text-base font-semibold text-gray-900">{{ __('admin.knowledge_governance_proposals.actions_title') }}</h2>
                    </div>
                    <div class="space-y-4 px-5 py-5">
                        @if ($isDuplicateArchive)
                            <div class="rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm leading-6 text-amber-900">
                                {{ __('admin.knowledge_governance_proposals.archive_notice') }}
                            </div>
                        @elseif ($isConflictReview)
                            <div class="rounded-md border border-blue-200 bg-blue-50 px-4 py-3 text-sm leading-6 text-blue-900">
                                {{ __('admin.knowledge_governance_proposals.conflict_notice') }}
                            </div>
                        @endif

                        @if ($canApply)
                            <form method="POST" action="{{ route('admin.knowledge-governance-proposals.apply', ['proposalId' => (int) $proposal->id]) }}" class="space-y-3">
                                @csrf
                                <textarea name="admin_note" rows="3" class="{{ $textareaClass }}" placeholder="{{ __('admin.knowledge_governance_proposals.placeholder_note') }}">{{ old('admin_note') }}</textarea>
                                @if ($isDuplicateArchive)
                                    <label class="block text-sm font-medium text-gray-700" for="apply-confirmation">{{ __('admin.knowledge_governance_proposals.apply_confirmation_label') }}</label>
                                    <input id="apply-confirmation" name="apply_confirmation" value="{{ old('apply_confirmation') }}" class="block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" placeholder="{{ __('admin.knowledge_governance_proposals.apply_confirmation_text') }}">
                                @endif
                                <button type="submit" class="inline-flex w-full items-center justify-center rounded-md border border-transparent bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700">
                                    <i data-lucide="{{ $isConflictReview ? 'check' : 'archive' }}" class="mr-2 h-4 w-4"></i>
                                    {{ $isConflictReview ? __('admin.knowledge_governance_proposals.mark_reviewed') : __('admin.knowledge_governance_proposals.apply_archive') }}
                                </button>
                            </form>
                        @endif

                        @if ($canReject)
                            <form method="POST" action="{{ route('admin.knowledge-governance-proposals.reject', ['proposalId' => (int) $proposal->id]) }}" class="space-y-3">
                                @csrf
                                <textarea name="admin_note" rows="3" class="{{ $textareaClass }}" placeholder="{{ __('admin.knowledge_governance_proposals.placeholder_reject_note') }}"></textarea>
                                <button type="submit" class="inline-flex w-full items-center justify-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">
                                    <i data-lucide="x" class="mr-2 h-4 w-4"></i>
                                    {{ __('admin.knowledge_governance_proposals.reject') }}
                                </button>
                            </form>
                        @endif

                        @if ($canRollback)
                            <form method="POST" action="{{ route('admin.knowledge-governance-proposals.rollback', ['proposalId' => (int) $proposal->id]) }}" class="space-y-3">
                                @csrf
                                <textarea name="admin_note" rows="3" class="{{ $textareaClass }}" placeholder="{{ __('admin.knowledge_governance_proposals.placeholder_rollback_note') }}"></textarea>
                                <button type="submit" class="inline-flex w-full items-center justify-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">
                                    <i data-lucide="rotate-ccw" class="mr-2 h-4 w-4"></i>
                                    {{ __('admin.knowledge_governance_proposals.rollback') }}
                                </button>
                            </form>
                        @endif

                        @if (! $canApply && ! $canReject && ! $canRollback)
                            <div class="rounded-md border border-gray-200 bg-gray-50 px-4 py-3 text-sm text-gray-600">{{ __('admin.knowledge_governance_proposals.no_actions') }}</div>
                        @endif
                    </div>
                </section>

                <section class="rounded-lg border border-gray-200 bg-white p-5 text-sm shadow-sm">
                    <div class="text-xs font-semibold uppercase tracking-wide text-gray-400">{{ __('admin.knowledge_governance_proposals.meta_title') }}</div>
                    <dl class="mt-3 space-y-3">
                        <div>
                            <dt class="text-gray-500">{{ __('admin.knowledge_governance_proposals.created_at') }}</dt>
                            <dd class="font-medium text-gray-900">{{ optional($proposal->created_at)->format('Y-m-d H:i:s') ?? '-' }}</dd>
                        </div>
                        <div>
                            <dt class="text-gray-500">{{ __('admin.knowledge_governance_proposals.applied_by') }}</dt>
                            <dd class="font-medium text-gray-900">{{ $proposal->appliedBy?->display_name ?: $proposal->appliedBy?->username ?: '-' }}</dd>
                        </div>
                        <div>
                            <dt class="text-gray-500">{{ __('admin.knowledge_governance_proposals.rolled_back_by') }}</dt>
                            <dd class="font-medium text-gray-900">{{ $proposal->rolledBackBy?->display_name ?: $proposal->rolledBackBy?->username ?: '-' }}</dd>
                        </div>
                        <div>
                            <dt class="text-gray-500">{{ __('admin.knowledge_governance_proposals.admin_note') }}</dt>
                            <dd class="whitespace-pre-wrap font-medium text-gray-900">{{ (string) ($proposal->admin_note ?: '-') }}</dd>
                        </div>
                    </dl>
                </section>
            </aside>
        </div>
    </div>
@endsection
