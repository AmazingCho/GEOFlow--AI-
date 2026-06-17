<?php

namespace App\Services\GeoFlow;

use App\Models\CrmDocumentPdfRegressionBaseline;
use App\Models\CrmDocumentPdfRegressionRun;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Symfony\Component\Process\Process;

class CrmDocumentPdfVisualDiffService
{
    public function compareReportToBaseline(array $currentReport, ?CrmDocumentPdfRegressionBaseline $baseline): array
    {
        if (! $baseline || ! File::isDirectory((string) $baseline->baseline_directory)) {
            return [
                'status' => 'missing_baseline',
                'message' => 'No visual baseline has been configured. Run generation is still valid.',
                'results' => [],
            ];
        }

        $baselineReportPath = rtrim((string) $baseline->baseline_directory, '/').'/baseline.json';
        if (! File::exists($baselineReportPath)) {
            return [
                'status' => 'missing_baseline',
                'message' => 'The configured baseline has no readable baseline.json file.',
                'results' => [],
            ];
        }

        $baselineReport = json_decode((string) File::get($baselineReportPath), true);
        if (! is_array($baselineReport)) {
            return [
                'status' => 'missing_baseline',
                'message' => 'The configured baseline file is not valid JSON.',
                'results' => [],
            ];
        }

        $baselineContext = $baseline->render_context_json ?: ($baselineReport['render_context'] ?? []);
        $currentContext = $currentReport['render_context'] ?? [];
        if (! $this->sameRenderContext($baselineContext, $currentContext)) {
            return [
                'status' => 'render_context_mismatch',
                'message' => 'Baseline and current screenshots were not generated with the same print/A4 render context.',
                'baseline_context' => $baselineContext,
                'current_context' => $currentContext,
                'results' => [],
            ];
        }

        $currentReportPath = rtrim((string) $currentReport['run_directory'], '/').'/report.json';
        $diffDirectory = rtrim((string) $currentReport['run_directory'], '/').'/diffs';
        File::ensureDirectoryExists($diffDirectory);

        $process = new Process([
            env('GEOFLOW_PDF_NODE_BINARY', '/usr/bin/node'),
            base_path('scripts/compare-crm-document-screenshots.mjs'),
            $baselineReportPath,
            $currentReportPath,
            $diffDirectory,
            (string) (float) env('GEOFLOW_PDF_VISUAL_DIFF_THRESHOLD', 0.01),
        ], base_path());
        $process->setTimeout((int) env('GEOFLOW_PDF_TIMEOUT', 120));
        $process->run();

        if (! $process->isSuccessful()) {
            $message = trim($process->getErrorOutput() ?: $process->getOutput());
            throw new RuntimeException('CRM document visual diff failed'.($message !== '' ? ': '.$message : '.'));
        }

        $result = json_decode(trim($process->getOutput()), true);
        if (! is_array($result)) {
            throw new RuntimeException('CRM document visual diff returned invalid JSON.');
        }

        File::put($diffDirectory.'/diff-report.json', json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $result;
    }

    public function createDefaultBaseline(CrmDocumentPdfRegressionRun $run, ?int $adminId = null): CrmDocumentPdfRegressionBaseline
    {
        $report = $run->reportData();
        if ($report === [] || ! $run->isCompleted()) {
            throw new RuntimeException('Only completed PDF regression runs with a report can be used as a baseline.');
        }

        $baselineDirectory = storage_path('app/pdf-regression-baselines/default');
        File::deleteDirectory($baselineDirectory);
        File::ensureDirectoryExists($baselineDirectory.'/screenshots');

        $baselineReport = $report;
        foreach ($baselineReport['results'] as &$row) {
            $copiedScreenshots = [];
            foreach (($row['screenshots'] ?? []) as $screenshot) {
                $source = (string) $screenshot;
                if (! File::exists($source)) {
                    continue;
                }
                $target = $baselineDirectory.'/screenshots/'.basename($source);
                File::copy($source, $target);
                $copiedScreenshots[] = $target;
            }
            $row['screenshots'] = $copiedScreenshots;
        }
        unset($row);

        File::put($baselineDirectory.'/baseline.json', json_encode($baselineReport, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return CrmDocumentPdfRegressionBaseline::query()->updateOrCreate(
            ['name' => 'default'],
            [
                'run_id' => (int) $run->id,
                'baseline_directory' => $baselineDirectory,
                'created_by_admin_id' => $adminId,
                'render_context_json' => $report['render_context'] ?? [],
            ]
        );
    }

    private function sameRenderContext(array $baselineContext, array $currentContext): bool
    {
        foreach (['render_media', 'page_size', 'viewport_width', 'viewport_height', 'device_scale_factor'] as $key) {
            if ((string) ($baselineContext[$key] ?? '') !== (string) ($currentContext[$key] ?? '')) {
                return false;
            }
        }

        return true;
    }
}
