@extends('admin.layouts.app')

@section('content')
    <div class="space-y-6 px-4 sm:px-0">
        <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
            <div>
                <div class="flex flex-wrap items-center gap-2">
                    <h1 class="text-2xl font-bold text-gray-900">AI 录入草稿详情</h1>
                    <span class="inline-flex rounded-full bg-gray-100 px-2.5 py-1 text-xs font-medium text-gray-700">{{ $draftSummary['status'] }}</span>
                </div>
                <p class="mt-1 text-sm text-gray-600">审核 AI 建议将要创建或沉淀的业务内容。只有点击应用后，系统才会写入 CRM 或内容候选。</p>
            </div>
            <a href="{{ route('admin.assistant-intake-drafts.index') }}" class="inline-flex w-fit items-center rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50">
                <i data-lucide="arrow-left" class="mr-2 h-4 w-4"></i>
                返回草稿箱
            </a>
        </div>

        @if ($errors->any())
            <div class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ $errors->first() }}</div>
        @endif
        @if (session('message'))
            <div class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">{{ session('message') }}</div>
        @endif

        <div class="grid grid-cols-1 gap-6 xl:grid-cols-[minmax(0,1fr)_360px]">
            <div class="space-y-6">
                <section class="rounded-lg border border-gray-200 bg-white shadow-sm">
                    <div class="border-b border-gray-200 px-5 py-4">
                        <h2 class="text-base font-semibold text-gray-900">原始输入与 AI 摘要</h2>
                    </div>
                    <div class="space-y-4 px-5 py-5">
                        <div>
                            <div class="text-sm font-medium text-gray-700">原始输入</div>
                            <div class="mt-2 whitespace-pre-wrap rounded-md border border-gray-200 bg-gray-50 px-3 py-3 text-sm leading-6 text-gray-800">{{ $draftSummary['raw_input'] }}</div>
                        </div>
                        <div>
                            <div class="text-sm font-medium text-gray-700">AI 摘要</div>
                            <div class="mt-2 whitespace-pre-wrap rounded-md border border-gray-200 bg-white px-3 py-3 text-sm leading-6 text-gray-800">{{ $draftSummary['normalized_summary'] !== '' ? $draftSummary['normalized_summary'] : '未提供摘要' }}</div>
                        </div>
                    </div>
                </section>

                <section class="rounded-lg border border-gray-200 bg-white shadow-sm">
                    <div class="flex flex-col gap-2 border-b border-gray-200 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <h2 class="text-base font-semibold text-gray-900">待执行动作</h2>
                            <p class="mt-1 text-sm text-gray-500">请重点检查目标类型、风险等级、字段内容和关联关系。</p>
                        </div>
                        <span class="text-sm text-gray-500">{{ count($actions) }} 个动作</span>
                    </div>
                    <div class="divide-y divide-gray-200">
                        @foreach ($actions as $action)
                            @php
                                $riskColors = [
                                    'low' => 'bg-emerald-100 text-emerald-700',
                                    'medium' => 'bg-amber-100 text-amber-700',
                                    'high' => 'bg-red-100 text-red-700',
                                ];
                                $riskClass = $riskColors[$action['risk_level']] ?? 'bg-gray-100 text-gray-700';
                            @endphp
                            <article class="px-5 py-5">
                                <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                                    <div>
                                        <div class="flex flex-wrap items-center gap-2">
                                            <h3 class="text-sm font-semibold text-gray-900">{{ $action['action_label'] }}</h3>
                                            <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-medium {{ $riskClass }}">{{ $action['risk_level'] }}</span>
                                            <span class="inline-flex rounded-full bg-gray-100 px-2.5 py-1 text-xs font-medium text-gray-700">{{ $action['status'] }}</span>
                                        </div>
                                        <p class="mt-1 text-sm text-gray-500">{{ $action['action_type'] }} / {{ $action['target_type'] }} @if($action['confidence'] !== null) · 置信度 {{ number_format((float) $action['confidence'] * 100, 0) }}% @endif</p>
                                    </div>
                                </div>
                                <div class="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-2">
                                    <div>
                                        <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">字段内容</div>
                                        <pre class="mt-2 max-h-64 overflow-auto rounded-md border border-gray-200 bg-gray-50 px-3 py-3 text-xs leading-5 text-gray-800">{{ json_encode($action['payload'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
                                    </div>
                                    <div>
                                        <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">关联关系</div>
                                        <pre class="mt-2 max-h-64 overflow-auto rounded-md border border-gray-200 bg-gray-50 px-3 py-3 text-xs leading-5 text-gray-800">{{ json_encode($action['relation'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
                                    </div>
                                </div>
                            </article>
                        @endforeach
                    </div>
                </section>
            </div>

            <aside class="space-y-6">
                <section class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
                    <h2 class="text-base font-semibold text-gray-900">审核信息</h2>
                    <dl class="mt-4 space-y-3 text-sm">
                        <div class="flex justify-between gap-4">
                            <dt class="text-gray-500">Collection</dt>
                            <dd class="text-right font-medium text-gray-900">{{ $draftSummary['collection_name'] !== '' ? $draftSummary['collection_name'] : '未指定' }}</dd>
                        </div>
                        <div class="flex justify-between gap-4">
                            <dt class="text-gray-500">来源</dt>
                            <dd class="text-right font-medium text-gray-900">{{ $draftSummary['source'] }}</dd>
                        </div>
                        <div class="flex justify-between gap-4">
                            <dt class="text-gray-500">语言</dt>
                            <dd class="text-right font-medium text-gray-900">{{ $draftSummary['detected_language'] !== '' ? $draftSummary['detected_language'] : '未识别' }}</dd>
                        </div>
                        <div class="flex justify-between gap-4">
                            <dt class="text-gray-500">置信度</dt>
                            <dd class="text-right font-medium text-gray-900">{{ $draftSummary['confidence'] !== null ? number_format((float) $draftSummary['confidence'] * 100, 0).'%' : '未提供' }}</dd>
                        </div>
                        <div class="flex justify-between gap-4">
                            <dt class="text-gray-500">创建时间</dt>
                            <dd class="text-right font-medium text-gray-900">{{ $draftSummary['created_at'] }}</dd>
                        </div>
                    </dl>
                </section>

                <section class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
                    <h2 class="text-base font-semibold text-gray-900">治理提醒</h2>
                    @php $warnings = is_array($draftSummary['warnings']) ? $draftSummary['warnings'] : []; @endphp
                    @if ($warnings === [])
                        <p class="mt-3 text-sm text-gray-500">暂无重复、低置信度或 Collection 缺失提醒。</p>
                    @else
                        <div class="mt-3 space-y-2">
                            @foreach ($warnings as $warning)
                                <div class="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800">
                                    <div class="font-medium">{{ $warning['code'] ?? 'warning' }}</div>
                                    <div class="mt-1">{{ $warning['message'] ?? '' }}</div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </section>

                <section class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
                    <h2 class="text-base font-semibold text-gray-900">操作</h2>
                    @if ($draft->status === \App\Models\AiIntakeDraft::STATUS_NEEDS_REVIEW)
                        <form method="POST" action="{{ route('admin.assistant-intake-drafts.apply', ['draftId' => (int) $draft->id]) }}" onsubmit="return confirm('确认应用这个 AI 录入草稿？系统会创建对应 CRM 记录或内容候选。');" class="mt-4">
                            @csrf
                            <button type="submit" class="inline-flex w-full items-center justify-center rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">
                                <i data-lucide="check" class="mr-2 h-4 w-4"></i>
                                应用草稿
                            </button>
                        </form>
                        <form method="POST" action="{{ route('admin.assistant-intake-drafts.reject', ['draftId' => (int) $draft->id]) }}" class="mt-4 space-y-3">
                            @csrf
                            <label class="block text-sm font-medium text-gray-700">拒绝原因</label>
                            <textarea name="rejected_reason" rows="3" class="block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500"></textarea>
                            <button type="submit" class="inline-flex w-full items-center justify-center rounded-md border border-red-300 bg-white px-4 py-2 text-sm font-medium text-red-700 hover:bg-red-50">
                                <i data-lucide="x" class="mr-2 h-4 w-4"></i>
                                拒绝草稿
                            </button>
                        </form>
                    @else
                        <p class="mt-3 text-sm text-gray-500">该草稿当前状态为 {{ $draft->status }}，不能重复应用。</p>
                    @endif
                </section>
            </aside>
        </div>
    </div>
@endsection
