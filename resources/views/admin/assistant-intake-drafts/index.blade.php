@extends('admin.layouts.app')

@section('content')
    <div class="space-y-6 px-4 sm:px-0">
        <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
            <div>
                <h1 class="text-2xl font-bold text-gray-900">AI 录入草稿箱</h1>
                <p class="mt-1 text-sm text-gray-600">Codex / AI 创建的业务录入建议会先进入这里，管理员确认后才会写入 CRM、知识库候选或 Case 候选。</p>
            </div>
            <a href="{{ route('admin.api-tokens.index') }}" class="inline-flex w-fit items-center rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50">
                <i data-lucide="key-round" class="mr-2 h-4 w-4"></i>
                API Token
            </a>
        </div>

        <form method="GET" action="{{ route('admin.assistant-intake-drafts.index') }}" class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
            <div class="grid grid-cols-1 gap-4 md:grid-cols-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700">状态</label>
                    <select name="status" class="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                        <option value="">全部状态</option>
                        @foreach (['needs_review' => '待审核', 'applied' => '已应用', 'rejected' => '已拒绝', 'failed' => '失败'] as $value => $label)
                            <option value="{{ $value }}" @selected($status === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">风险</label>
                    <select name="risk" class="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                        <option value="">全部风险</option>
                        @foreach (['low' => '低风险', 'medium' => '中风险', 'high' => '高风险'] as $value => $label)
                            <option value="{{ $value }}" @selected($risk === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">来源</label>
                    <input name="source" value="{{ $source }}" placeholder="codex / api / admin_form" class="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                </div>
                <div class="flex items-end gap-2">
                    <button type="submit" class="inline-flex items-center rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">
                        <i data-lucide="search" class="mr-2 h-4 w-4"></i>
                        筛选
                    </button>
                    <a href="{{ route('admin.assistant-intake-drafts.index') }}" class="inline-flex items-center rounded-md border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">清除</a>
                </div>
            </div>
        </form>

        <div class="overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">草稿</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">Collection</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">状态 / 风险</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">动作</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">时间</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wider text-gray-500">操作</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 bg-white">
                        @forelse ($drafts as $draft)
                            @php
                                $firstAction = $draft->actions->first();
                                $payload = is_array($firstAction?->payload_json) ? $firstAction->payload_json : [];
                                $previewTitle = (string) ($payload['company_name'] ?? $payload['title'] ?? $draft->normalized_summary ?? $draft->raw_input);
                                $riskClass = ((int) ($draft->high_risk_actions_count ?? 0) > 0)
                                    ? 'bg-red-100 text-red-700'
                                    : (((int) ($draft->medium_risk_actions_count ?? 0) > 0) ? 'bg-amber-100 text-amber-700' : 'bg-emerald-100 text-emerald-700');
                            @endphp
                            <tr>
                                <td class="px-4 py-4 align-top">
                                    <div class="max-w-xl">
                                        <div class="text-sm font-semibold text-gray-900">{{ $previewTitle }}</div>
                                        <div class="mt-1 line-clamp-2 text-sm text-gray-600">{{ $draft->normalized_summary ?: $draft->raw_input }}</div>
                                        <div class="mt-2 text-xs text-gray-500">来源：{{ $draft->source }} @if($draft->source_reference) · {{ $draft->source_reference }} @endif</div>
                                    </div>
                                </td>
                                <td class="px-4 py-4 text-sm text-gray-700">{{ $draft->collection?->name ?? '未指定' }}</td>
                                <td class="px-4 py-4">
                                    <div class="flex flex-wrap gap-2">
                                        <span class="inline-flex rounded-full bg-gray-100 px-2.5 py-1 text-xs font-medium text-gray-700">{{ $draft->status }}</span>
                                        <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-medium {{ $riskClass }}">
                                            高 {{ (int) ($draft->high_risk_actions_count ?? 0) }} / 中 {{ (int) ($draft->medium_risk_actions_count ?? 0) }}
                                        </span>
                                    </div>
                                </td>
                                <td class="px-4 py-4 text-sm text-gray-700">{{ (int) ($draft->actions_count ?? $draft->actions->count()) }} 个动作</td>
                                <td class="px-4 py-4 text-sm text-gray-500">{{ $draft->created_at?->format('Y-m-d H:i') }}</td>
                                <td class="px-4 py-4 text-right">
                                    <a href="{{ route('admin.assistant-intake-drafts.show', ['draftId' => (int) $draft->id]) }}" class="inline-flex items-center rounded-md border border-gray-300 px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50">
                                        <i data-lucide="eye" class="mr-1.5 h-4 w-4"></i>
                                        查看
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-6 py-10 text-center text-sm text-gray-500">暂无 AI 录入草稿</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="border-t border-gray-200 px-4 py-3">
                {{ $drafts->links() }}
            </div>
        </div>
    </div>
@endsection
