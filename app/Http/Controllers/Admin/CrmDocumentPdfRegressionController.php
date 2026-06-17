<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\GenerateCrmDocumentPdfRegressionRun;
use App\Models\CrmDocumentPdfRegressionBaseline;
use App\Models\CrmDocumentPdfRegressionRun;
use App\Services\GeoFlow\CrmDocumentPdfRegressionCleanupService;
use App\Services\GeoFlow\CrmDocumentPdfVisualDiffService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\File;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class CrmDocumentPdfRegressionController extends Controller
{
    public function index(CrmDocumentPdfRegressionCleanupService $cleanupService): View
    {
        $runs = CrmDocumentPdfRegressionRun::query()
            ->with(['admin', 'baseline'])
            ->latest('id')
            ->paginate(20);
        $runningRun = CrmDocumentPdfRegressionRun::query()
            ->whereIn('status', [CrmDocumentPdfRegressionRun::STATUS_PENDING, CrmDocumentPdfRegressionRun::STATUS_RUNNING])
            ->latest('id')
            ->first();
        $baseline = CrmDocumentPdfRegressionBaseline::query()->where('name', 'default')->with('run')->first();
        $cleanupPreview = $cleanupService->preview(
            (int) env('GEOFLOW_PDF_REGRESSION_KEEP_RUNS', 20),
            (int) env('GEOFLOW_PDF_REGRESSION_KEEP_DAYS', 30)
        );

        return view('admin.crm.pdf-regression.index', [
            'pageTitle' => 'PDF 回归检查',
            'activeMenu' => 'crm',
            'adminSiteName' => config('geoflow.site_name', 'GEOFlow'),
            'runs' => $runs,
            'runningRun' => $runningRun,
            'baseline' => $baseline,
            'cleanupPreview' => $cleanupPreview,
            'cleanupService' => $cleanupService,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $runningRun = CrmDocumentPdfRegressionRun::query()
            ->whereIn('status', [CrmDocumentPdfRegressionRun::STATUS_PENDING, CrmDocumentPdfRegressionRun::STATUS_RUNNING])
            ->latest('id')
            ->first();
        if ($runningRun) {
            return redirect()
                ->route('admin.crm.quotes.pdf-regression.index')
                ->with('error', '已有 PDF 回归检查正在运行，请等待完成后再创建新的检查。');
        }

        $data = $request->validate([
            'skip_screenshots' => ['nullable', 'boolean'],
            'fail_on_warnings' => ['nullable', 'boolean'],
        ]);

        $run = CrmDocumentPdfRegressionRun::query()->create([
            'status' => CrmDocumentPdfRegressionRun::STATUS_PENDING,
            'triggered_by_admin_id' => Auth::guard('admin')->id(),
            'options_json' => [
                'output' => 'pdf-regression',
                'skip_screenshots' => (bool) ($data['skip_screenshots'] ?? false),
                'fail_on_warnings' => (bool) ($data['fail_on_warnings'] ?? false),
            ],
        ]);

        GenerateCrmDocumentPdfRegressionRun::dispatch((int) $run->id);

        return redirect()
            ->route('admin.crm.quotes.pdf-regression.show', ['runId' => (int) $run->id])
            ->with('success', 'PDF 回归检查已创建。生成完成后会显示五类单据的 PDF、HTML、截图和视觉对比报告。');
    }

    public function show(int $runId): View
    {
        $run = CrmDocumentPdfRegressionRun::query()->with(['admin', 'baseline'])->findOrFail($runId);

        return view('admin.crm.pdf-regression.show', [
            'pageTitle' => 'PDF 回归报告',
            'activeMenu' => 'crm',
            'adminSiteName' => config('geoflow.site_name', 'GEOFlow'),
            'run' => $run,
            'report' => $run->reportData(),
            'baseline' => CrmDocumentPdfRegressionBaseline::query()->where('name', 'default')->first(),
        ]);
    }

    public function artifact(int $runId, Request $request): BinaryFileResponse
    {
        $run = CrmDocumentPdfRegressionRun::query()->findOrFail($runId);
        $relativePath = trim((string) $request->query('path', ''), '/');
        $path = $this->safeArtifactPath($run, $relativePath);

        return response()->file($path);
    }

    public function baseline(int $runId, CrmDocumentPdfVisualDiffService $visualDiffService): RedirectResponse
    {
        $run = CrmDocumentPdfRegressionRun::query()->findOrFail($runId);
        $visualDiffService->createDefaultBaseline($run, Auth::guard('admin')->id());

        return redirect()
            ->route('admin.crm.quotes.pdf-regression.show', ['runId' => (int) $run->id])
            ->with('success', '已将本次 print/A4 截图设为默认视觉基线。后续回归检查会自动对比它。');
    }

    public function destroy(int $runId): RedirectResponse
    {
        $run = CrmDocumentPdfRegressionRun::query()->with('baseline')->findOrFail($runId);
        if ($run->isRunning()) {
            return back()->with('error', '运行中的回归检查不能删除。');
        }
        if ($run->baseline) {
            return back()->with('error', '当前回归包已被设为视觉基线，不能删除。请先设置新的基线。');
        }

        $directory = (string) $run->output_directory;
        $deletedBytes = 0;
        if ($directory !== '' && $this->isSafeRegressionDirectory($directory) && File::isDirectory($directory)) {
            foreach (File::allFiles($directory) as $file) {
                $deletedBytes += $file->getSize();
            }
            File::deleteDirectory($directory);
        }

        $run->forceFill([
            'pruned_at' => now(),
            'deleted_bytes' => $deletedBytes,
        ])->save();

        return redirect()
            ->route('admin.crm.quotes.pdf-regression.index')
            ->with('success', '已删除该回归包文件，运行记录保留用于追踪。');
    }

    public function prune(Request $request, CrmDocumentPdfRegressionCleanupService $cleanupService): RedirectResponse
    {
        $data = $request->validate([
            'confirm' => ['required', 'string'],
        ]);
        if ((string) $data['confirm'] !== '确认清理') {
            return back()->with('error', '请输入“确认清理”后再执行旧回归包清理。');
        }

        $result = $cleanupService->prune(
            (int) env('GEOFLOW_PDF_REGRESSION_KEEP_RUNS', 20),
            (int) env('GEOFLOW_PDF_REGRESSION_KEEP_DAYS', 30)
        );

        return redirect()
            ->route('admin.crm.quotes.pdf-regression.index')
            ->with('success', '清理完成：删除 '.$result['deleted_count'].' 个旧回归包，释放 '.$cleanupService->formatBytes((int) $result['deleted_bytes']).'。');
    }

    private function safeArtifactPath(CrmDocumentPdfRegressionRun $run, string $relativePath): string
    {
        abort_if($relativePath === '' || str_contains($relativePath, '..'), 404);

        $base = realpath((string) $run->output_directory);
        abort_if(! $base, 404);

        $path = realpath($base.'/'.$relativePath);
        abort_if(! $path || ! str_starts_with(str_replace('\\', '/', $path), rtrim(str_replace('\\', '/', $base), '/').'/'), 404);
        abort_if(! File::isFile($path), 404);

        return $path;
    }

    private function isSafeRegressionDirectory(string $directory): bool
    {
        $base = realpath(storage_path('app/pdf-regression')) ?: storage_path('app/pdf-regression');
        $real = realpath($directory) ?: $directory;
        $base = rtrim(str_replace('\\', '/', $base), '/').'/';
        $real = rtrim(str_replace('\\', '/', $real), '/').'/';

        return str_starts_with($real, $base) && $real !== $base;
    }
}
