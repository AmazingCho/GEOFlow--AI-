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
    $context = $report['render_context'] ?? ($run->render_context_json ?? []);
    $visualDiff = $report['visual_diff'] ?? ($run->visual_diff_json ?? []);
    $relativeArtifact = static function (string $path) use ($run): string {
        $base = rtrim((string) $run->output_directory, '/').'/';
        return str_starts_with($path, $base) ? substr($path, strlen($base)) : '';
    };
@endphp

@section('content')
    <div class="px-4 sm:px-0">
        <div class="mb-8 flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
            <div>
                <h1 class="text-2xl font-bold text-gray-900">PDF 回归报告 #{{ $run->id }}</h1>
                <p class="mt-1 text-sm text-gray-600">报告以最终 PDF / Chrome 打印版为准，普通 HTML 预览尺寸更大不代表分页失败。</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <a href="{{ route('admin.crm.quotes.pdf-regression.index') }}" class="inline-flex w-fit items-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                    <i data-lucide="arrow-left" class="mr-2 h-4 w-4"></i>
                    返回回归检查
                </a>
                @if ($run->isCompleted() && !$run->pruned_at)
                    <form method="POST" action="{{ route('admin.crm.quotes.pdf-regression.baseline', ['runId' => (int) $run->id]) }}" onsubmit="return confirm('确认将本次 print/A4 截图设为默认视觉基线？')">
                        @csrf
                        <button type="submit" class="inline-flex items-center rounded-md border border-indigo-200 bg-indigo-50 px-4 py-2 text-sm font-medium text-indigo-700 hover:bg-indigo-100">
                            <i data-lucide="badge-check" class="mr-2 h-4 w-4"></i>
                            设为视觉基线
                        </button>
                    </form>
                @endif
            </div>
        </div>

        @include('admin.crm.partials.nav', ['currentCrmTab' => 'quotes'])

        @if (session('success'))
            <div class="mb-4 rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ session('success') }}</div>
        @endif
        @if (session('error'))
            <div class="mb-4 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">{{ session('error') }}</div>
        @endif

        <div class="mb-6 grid grid-cols-1 gap-4 lg:grid-cols-4">
            <div class="rounded-lg bg-white p-5 shadow-sm ring-1 ring-gray-200">
                <div class="text-sm text-gray-500">状态</div>
                <div class="mt-3"><span class="inline-flex rounded-full border px-2.5 py-1 text-xs font-semibold {{ $statusClasses[$run->status] ?? 'border-gray-200 bg-gray-50 text-gray-700' }}">{{ $statusLabels[$run->status] ?? $run->status }}</span></div>
            </div>
            <div class="rounded-lg bg-white p-5 shadow-sm ring-1 ring-gray-200">
                <div class="text-sm text-gray-500">Warnings</div>
                <div class="mt-2 text-2xl font-semibold text-gray-900">{{ count($report['warnings'] ?? []) ?: (int) $run->warnings_count }}</div>
            </div>
            <div class="rounded-lg bg-white p-5 shadow-sm ring-1 ring-gray-200">
                <div class="text-sm text-gray-500">渲染基准</div>
                <div class="mt-2 text-sm font-semibold text-gray-900">{{ $context['render_media'] ?? 'print' }} / {{ $context['page_size'] ?? 'A4' }}</div>
                <div class="mt-1 text-xs text-gray-500">{{ $context['viewport_width'] ?? 1240 }}x{{ $context['viewport_height'] ?? 1754 }}</div>
            </div>
            <div class="rounded-lg bg-white p-5 shadow-sm ring-1 ring-gray-200">
                <div class="text-sm text-gray-500">视觉对比</div>
                <div class="mt-2 text-sm font-semibold text-gray-900">{{ $visualDiff['status'] ?? 'missing_baseline' }}</div>
                <div class="mt-1 truncate text-xs text-gray-500">{{ $visualDiff['message'] ?? '未设置基线' }}</div>
            </div>
        </div>

        @if ($run->status === 'failed')
            <div class="mb-6 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">{{ $run->error_message }}</div>
        @elseif ($run->isRunning())
            <div class="mb-6 rounded-md border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-800">正在生成 PDF、HTML 和 print 截图，请稍后刷新。</div>
        @endif

        @if (($report['warnings'] ?? []) !== [])
            <section class="mb-6 rounded-lg border border-amber-200 bg-amber-50 p-5">
                <h2 class="text-base font-semibold text-amber-900">Warnings</h2>
                <ul class="mt-3 space-y-1 text-sm text-amber-800">
                    @foreach ($report['warnings'] as $warning)
                        <li>{{ $warning }}</li>
                    @endforeach
                </ul>
            </section>
        @endif

        <section class="mb-6 overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
            <div class="border-b border-gray-200 px-6 py-4">
                <h2 class="text-base font-semibold text-gray-900">五类单据结果</h2>
            </div>
            @if (($report['results'] ?? []) === [])
                <div class="px-6 py-10 text-center text-sm text-gray-500">暂无报告结果</div>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">类型</th>
                                <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">样本</th>
                                <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">页数</th>
                                <th class="px-6 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500">产物</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 bg-white">
                            @foreach ($report['results'] as $row)
                                <tr>
                                    <td class="px-6 py-4 text-sm font-semibold text-gray-900">{{ $row['document_type'] }}</td>
                                    <td class="px-6 py-4 text-sm text-gray-600">{{ $row['quote_no'] }} (#{{ $row['quote_id'] }}) · {{ $row['items_count'] }} 项</td>
                                    <td class="px-6 py-4 text-sm text-gray-600">PDF {{ $row['pdf_pages'] }} / HTML {{ $row['html_pages'] ?? '-' }}</td>
                                    <td class="px-6 py-4 text-right">
                                        <div class="flex flex-wrap justify-end gap-2">
                                            @foreach (['PDF' => $row['pdf_path'] ?? '', 'HTML' => $row['html_path'] ?? ''] as $label => $path)
                                                @php $relative = $relativeArtifact((string) $path); @endphp
                                                @if ($relative !== '')
                                                    <a href="{{ route('admin.crm.quotes.pdf-regression.artifact', ['runId' => (int) $run->id, 'path' => $relative]) }}" target="_blank" class="inline-flex items-center rounded border border-gray-300 bg-white px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50">{{ $label }}</a>
                                                @endif
                                            @endforeach
                                            @foreach (($row['screenshots'] ?? []) as $index => $screenshot)
                                                @php $relative = $relativeArtifact((string) $screenshot); @endphp
                                                @if ($relative !== '')
                                                    <a href="{{ route('admin.crm.quotes.pdf-regression.artifact', ['runId' => (int) $run->id, 'path' => $relative]) }}" target="_blank" class="inline-flex items-center rounded border border-blue-200 bg-blue-50 px-3 py-1.5 text-xs font-medium text-blue-700 hover:bg-blue-100">截图 {{ $index + 1 }}</a>
                                                @endif
                                            @endforeach
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>

        @if (($visualDiff['results'] ?? []) !== [])
            <section class="overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
                <div class="border-b border-gray-200 px-6 py-4">
                    <h2 class="text-base font-semibold text-gray-900">视觉差异对比</h2>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">类型</th>
                                <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">状态</th>
                                <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">最大差异</th>
                                <th class="px-6 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500">Diff</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 bg-white">
                            @foreach ($visualDiff['results'] as $row)
                                <tr>
                                    <td class="px-6 py-4 text-sm font-semibold text-gray-900">{{ $row['document_type'] }}</td>
                                    <td class="px-6 py-4 text-sm text-gray-600">{{ $row['status'] }}</td>
                                    <td class="px-6 py-4 text-sm text-gray-600">{{ isset($row['max_diff_ratio']) ? number_format((float) $row['max_diff_ratio'] * 100, 3).'%' : '-' }}</td>
                                    <td class="px-6 py-4 text-right">
                                        <div class="flex flex-wrap justify-end gap-2">
                                            @foreach (($row['pages'] ?? []) as $page)
                                                @php $relative = $relativeArtifact((string) ($page['diff_path'] ?? '')); @endphp
                                                @if ($relative !== '')
                                                    <a href="{{ route('admin.crm.quotes.pdf-regression.artifact', ['runId' => (int) $run->id, 'path' => $relative]) }}" target="_blank" class="inline-flex items-center rounded border border-indigo-200 bg-indigo-50 px-3 py-1.5 text-xs font-medium text-indigo-700 hover:bg-indigo-100">Page {{ $page['page'] }}</a>
                                                @endif
                                            @endforeach
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
        @endif
    </div>
@endsection
