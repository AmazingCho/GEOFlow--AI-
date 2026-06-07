@extends('admin.layouts.app')

@section('content')
    <div class="px-4 sm:px-0">
        <div class="mb-8">
            <h1 class="text-2xl font-bold text-gray-900">内容候选草稿</h1>
            <p class="mt-1 text-sm text-gray-600">CRM 产生的标题、FAQ、Case 草稿先进入候选区，人工确认后再写入对应素材库。</p>
        </div>

        @include('admin.crm.partials.nav', ['currentCrmTab' => 'proposals'])

        <form method="GET" action="{{ route('admin.crm.proposals.index') }}" class="mb-6 rounded-lg border border-gray-200 bg-white px-5 py-4 shadow-sm">
            <div class="grid grid-cols-1 gap-3 lg:grid-cols-[220px_180px_220px_auto] lg:items-end">
                <div>
                    <label class="mb-2 block text-sm font-medium text-gray-700">候选类型</label>
                    <select name="proposal_type" class="block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                        <option value="">全部</option>
                        @foreach (['title_suggestion' => '标题建议', 'faq_draft' => 'FAQ 草稿', 'case_draft' => 'Case 草稿'] as $value => $label)
                            <option value="{{ $value }}" @selected($proposalType === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="mb-2 block text-sm font-medium text-gray-700">状态</label>
                    <select name="status" class="block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                        <option value="">全部</option>
                        @foreach (['pending' => '待确认', 'applied' => '已应用', 'rejected' => '已拒绝'] as $value => $label)
                            <option value="{{ $value }}" @selected($status === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                @include('admin.partials.collection-select', [
                    'selectedId' => (string) ($collectionId ?? ''),
                    'collectionOptions' => $collectionOptions ?? [],
                    'label' => '业务容器',
                    'help' => '',
                    'emptyLabel' => '全部业务容器',
                    'class' => 'block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500',
                ])
                <div class="flex gap-2">
                    <button class="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">筛选</button>
                    <a href="{{ route('admin.crm.proposals.index') }}" class="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">清除</a>
                </div>
            </div>
        </form>

        <div class="space-y-4">
            @forelse ($proposals as $proposal)
                @php
                    $typeLabel = ['title_suggestion' => '标题建议', 'faq_draft' => 'FAQ 草稿', 'case_draft' => 'Case 草稿'][(string) $proposal->proposal_type] ?? (string) $proposal->proposal_type;
                    $statusLabel = ['pending' => '待确认', 'applied' => '已应用', 'rejected' => '已拒绝'][(string) $proposal->status] ?? (string) $proposal->status;
                @endphp
                <article class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
                    <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="rounded-full bg-blue-50 px-2.5 py-1 text-xs font-semibold text-blue-700">{{ $typeLabel }}</span>
                                <span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-700">{{ $statusLabel }}</span>
                                <span class="text-xs text-gray-500">{{ $proposal->collection?->name ?? '未指定业务容器' }} · {{ class_basename((string) $proposal->source_type) }} #{{ (int) $proposal->source_id }}</span>
                            </div>
                            <h2 class="mt-3 text-base font-semibold text-gray-900">{{ $proposal->title }}</h2>
                            <div class="mt-3 max-h-48 overflow-y-auto whitespace-pre-wrap rounded-md border border-gray-200 bg-gray-50 px-4 py-3 text-sm leading-6 text-gray-700">{{ $proposal->content ?: '暂无内容' }}</div>
                        </div>
                        <div class="shrink-0 lg:w-80">
                            @if ((string) $proposal->status === 'pending')
                                @if ((string) $proposal->proposal_type === 'title_suggestion')
                                    <form method="POST" action="{{ route('admin.crm.proposals.apply', ['proposalId' => (int) $proposal->id]) }}" class="space-y-3">
                                        @csrf
                                        <div>
                                            <label class="mb-2 block text-sm font-medium text-gray-700">写入标题库</label>
                                            <select name="title_library_id" required class="block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                                <option value="">选择标题库</option>
                                                @foreach ($titleLibraries as $library)
                                                    <option value="{{ (int) $library->id }}" @if((int) ($proposal->collection_id ?? 0) > 0 && (int) ($library->collection_id ?? 0) !== (int) $proposal->collection_id) hidden @endif>{{ $library->name }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                        <button class="w-full rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">确认应用</button>
                                    </form>
                                @else
                                    <form method="POST" action="{{ route('admin.crm.proposals.apply', ['proposalId' => (int) $proposal->id]) }}">
                                        @csrf
                                        <button class="w-full rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">确认应用</button>
                                    </form>
                                @endif
                                <form method="POST" action="{{ route('admin.crm.proposals.reject', ['proposalId' => (int) $proposal->id]) }}" class="mt-3">
                                    @csrf
                                    <button class="w-full rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">拒绝候选</button>
                                </form>
                            @else
                                <div class="rounded-md border border-gray-200 bg-gray-50 px-4 py-3 text-sm text-gray-600">
                                    @if ((string) $proposal->status === 'applied')
                                        已应用到 {{ class_basename((string) $proposal->applied_target_type) }} #{{ (int) $proposal->applied_target_id }}
                                    @else
                                        已拒绝，不会写入素材库。
                                    @endif
                                </div>
                            @endif
                        </div>
                    </div>
                </article>
            @empty
                <div class="rounded-lg border border-gray-200 bg-white px-6 py-10 text-center text-sm text-gray-500 shadow-sm">暂无内容候选草稿。</div>
            @endforelse
        </div>

        @if ($proposals->hasPages())
            <div class="mt-6 flex flex-col gap-3 rounded-lg border border-gray-200 bg-white px-6 py-4 lg:flex-row lg:items-center lg:justify-between">
                <div class="text-sm text-gray-600">显示第 {{ $proposals->firstItem() ?? 0 }} - {{ $proposals->lastItem() ?? 0 }} 条，共 {{ $proposals->total() }} 条</div>
                {{ $proposals->links() }}
            </div>
        @endif
    </div>
@endsection
