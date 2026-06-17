@extends('admin.layouts.app')

@php
    $statusClasses = [
        'pending' => 'bg-amber-50 text-amber-700 border-amber-200',
        'running' => 'bg-blue-50 text-blue-700 border-blue-200',
        'completed' => 'bg-emerald-50 text-emerald-700 border-emerald-200',
        'failed' => 'bg-red-50 text-red-700 border-red-200',
    ];
    $statusLabels = [
        'pending' => '等待中',
        'running' => '生成中',
        'completed' => '已完成',
        'failed' => '失败',
    ];
@endphp

@section('content')
    <div class="px-4 sm:px-0">
        <div class="mb-8 flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
            <div>
                <h1 class="text-2xl font-bold text-gray-900">PDF 回归检查</h1>
                <p class="mt-1 text-sm text-gray-600">生成五类 CRM 单据的 PDF、HTML、print 截图和视觉差异报告，用于检查模板是否被改坏。</p>
            </div>
            <a href="{{ route('admin.crm.quotes.index') }}" class="inline-flex w-fit items-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                <i data-lucide="arrow-left" class="mr-2 h-4 w-4"></i>
                返回单据制作
            </a>
        </div>

        @include('admin.crm.partials.nav', ['currentCrmTab' => 'quotes'])

        @if (session('success'))
            <div class="mb-4 rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ session('success') }}</div>
        @endif
        @if (session('error'))
            <div class="mb-4 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">{{ session('error') }}</div>
        @endif

        <div class="mb-6 grid grid-cols-1 gap-4 xl:grid-cols-[minmax(0,1.4fr)_minmax(320px,.8fr)]">
            <section class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                    <div>
                        <h2 class="text-base font-semibold text-gray-900">生成回归包</h2>
                        <p class="mt-1 text-sm leading-6 text-gray-600">检查结果以最终 PDF / Chrome 打印版为准，普通 HTML 预览尺寸可能更大，不作为分页失败依据。</p>
                        <div class="mt-3 flex flex-wrap gap-2 text-xs text-gray-600">
                            <span class="rounded-full border border-gray-200 bg-gray-50 px-2.5 py-1">A4</span>
                            <span class="rounded-full border border-gray-200 bg-gray-50 px-2.5 py-1">print CSS</span>
                            <span class="rounded-full border border-gray-200 bg-gray-50 px-2.5 py-1">Chromium</span>
                            <span class="rounded-full border border-gray-200 bg-gray-50 px-2.5 py-1">五类单据</span>
                        </div>
                    </div>
                    <form method="POST" action="{{ route('admin.crm.quotes.pdf-regression.store') }}">
                        @csrf
                        <button type="submit" @disabled($runningRun) class="inline-flex items-center rounded-md border border-transparent bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700 disabled:cursor-not-allowed disabled:bg-gray-300">
                            <i data-lucide="play" class="mr-2 h-4 w-4"></i>
                            生成回归包
                        </button>
                    </form>
                </div>

                @if ($runningRun)
                    <div class="mt-4 rounded-md border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-800">
                        当前有检查正在运行：#{{ $runningRun->id }}，状态 {{ $statusLabels[$runningRun->status] ?? $runningRun->status }}。
                        <a href="{{ route('admin.crm.quotes.pdf-regression.show', ['runId' => (int) $runningRun->id]) }}" class="font-semibold underline">查看进度</a>
                    </div>
                @endif
            </section>

            <section class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
                <h2 class="text-base font-semibold text-gray-900">视觉基线与清理</h2>
                <dl class="mt-3 space-y-2 text-sm">
                    <div class="flex justify-between gap-4">
                        <dt class="text-gray-500">当前基线</dt>
                        <dd class="font-medium text-gray-900">{{ $baseline ? '#'.$baseline->run_id : '未设置' }}</dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-gray-500">清理候选</dt>
                        <dd class="font-medium text-gray-900">{{ count($cleanupPreview['candidates'] ?? []) }} 个</dd>
                    </div>
                </dl>
                <form method="POST" action="{{ route('admin.crm.quotes.pdf-regression.prune') }}" class="mt-4 space-y-3">
                    @csrf
                    <label class="block text-sm font-medium text-gray-700">输入“确认清理”后删除过期回归包</label>
                    <input type="text" name="confirm" class="block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" placeholder="确认清理">
                    <button type="submit" class="inline-flex items-center rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                        <i data-lucide="archive-x" class="mr-2 h-4 w-4"></i>
                        执行清理
                    </button>
                </form>
            </section>
        </div>

        <section class="overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
            <div class="border-b border-gray-200 px-6 py-4">
                <h2 class="text-base font-semibold text-gray-900">历史回归记录</h2>
            </div>
            @if ($runs->isEmpty())
                <div class="px-6 py-10 text-center text-sm text-gray-500">暂无 PDF 回归检查记录</div>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">记录</th>
                                <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">结果</th>
                                <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">时间</th>
                                <th class="px-6 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500">操作</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 bg-white">
                            @foreach ($runs as $run)
                                <tr>
                                    <td class="px-6 py-4 text-sm">
                                        <div class="font-semibold text-gray-900">#{{ $run->id }}</div>
                                        <div class="mt-1 text-gray-500">{{ $run->admin?->username ?? '系统' }}</div>
                                        @if ($run->baseline)
                                            <span class="mt-2 inline-flex rounded-full border border-indigo-200 bg-indigo-50 px-2.5 py-1 text-xs font-semibold text-indigo-700">视觉基线</span>
                                        @endif
                                    </td>
                                    <td class="px-6 py-4 text-sm">
                                        <span class="inline-flex rounded-full border px-2.5 py-1 text-xs font-semibold {{ $statusClasses[$run->status] ?? 'border-gray-200 bg-gray-50 text-gray-700' }}">{{ $statusLabels[$run->status] ?? $run->status }}</span>
                                        <div class="mt-2 text-gray-500">Warnings: {{ (int) $run->warnings_count }}</div>
                                        @if ($run->pruned_at)
                                            <div class="mt-1 text-xs text-gray-500">文件已清理</div>
                                        @endif
                                    </td>
                                    <td class="px-6 py-4 text-sm text-gray-600">
                                        <div>创建：{{ optional($run->created_at)->format('Y-m-d H:i') }}</div>
                                        <div class="mt-1">完成：{{ optional($run->finished_at)->format('Y-m-d H:i') ?: '-' }}</div>
                                    </td>
                                    <td class="px-6 py-4 text-right">
                                        <div class="flex flex-wrap justify-end gap-2">
                                            <a href="{{ route('admin.crm.quotes.pdf-regression.show', ['runId' => (int) $run->id]) }}" class="inline-flex items-center rounded border border-gray-300 bg-white px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50">
                                                <i data-lucide="eye" class="mr-1 h-4 w-4"></i>查看
                                            </a>
                                            @if (!$run->isRunning() && !$run->baseline && !$run->pruned_at)
                                                <form method="POST" action="{{ route('admin.crm.quotes.pdf-regression.delete', ['runId' => (int) $run->id]) }}" onsubmit="return confirm('确认删除该回归包文件？运行记录会保留。')">
                                                    @csrf
                                                    <button type="submit" class="inline-flex items-center rounded border border-red-200 bg-red-50 px-3 py-1.5 text-xs font-medium text-red-700 hover:bg-red-100">
                                                        <i data-lucide="trash-2" class="mr-1 h-4 w-4"></i>删除文件
                                                    </button>
                                                </form>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="flex flex-col gap-3 border-t border-gray-200 px-6 py-4 lg:flex-row lg:items-center lg:justify-between">
                    <div class="text-sm text-gray-600">显示第 {{ $runs->firstItem() ?? 0 }} - {{ $runs->lastItem() ?? 0 }} 条，共 {{ $runs->total() }} 条</div>
                    @if ($runs->lastPage() > 1)
                        <div>{{ $runs->links() }}</div>
                    @endif
                </div>
            @endif
        </section>
    </div>
@endsection
