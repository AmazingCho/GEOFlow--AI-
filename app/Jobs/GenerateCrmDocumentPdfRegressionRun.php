<?php

namespace App\Jobs;

use App\Models\CrmDocumentPdfRegressionBaseline;
use App\Models\CrmDocumentPdfRegressionRun;
use App\Services\GeoFlow\CrmDocumentPdfRegressionService;
use App\Services\GeoFlow\CrmDocumentPdfVisualDiffService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\File;
use Throwable;

class GenerateCrmDocumentPdfRegressionRun implements ShouldQueue
{
    use Queueable;

    public int $timeout = 300;

    public function __construct(public readonly int $runId)
    {
        /**
         * The PDF regression runner needs Node/Chromium. In the current local Docker
         * setup those binaries exist in the app container, while the queue container
         * does not include them, so keep this job on the sync connection for now.
         */
        $this->onConnection('sync');
    }

    public function handle(
        CrmDocumentPdfRegressionService $regressionService,
        CrmDocumentPdfVisualDiffService $visualDiffService
    ): void {
        $run = CrmDocumentPdfRegressionRun::query()->findOrFail($this->runId);
        if (! $run->isRunning()) {
            return;
        }

        $run->forceFill([
            'status' => CrmDocumentPdfRegressionRun::STATUS_RUNNING,
            'started_at' => now(),
            'error_message' => null,
        ])->save();

        try {
            $options = is_array($run->options_json) ? $run->options_json : [];
            $report = $regressionService->generate([
                'quote_id' => (string) ($options['quote_id'] ?? ''),
                'invoice_quote_id' => (string) ($options['invoice_quote_id'] ?? ''),
                'output' => (string) ($options['output'] ?? 'pdf-regression'),
                'skip_screenshots' => (bool) ($options['skip_screenshots'] ?? false),
            ]);

            $baseline = CrmDocumentPdfRegressionBaseline::query()->where('name', 'default')->latest('id')->first();
            $visualDiff = $visualDiffService->compareReportToBaseline($report, $baseline);
            $report['visual_diff'] = $visualDiff;
            File::put($report['run_directory'].'/report.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            File::put($report['run_directory'].'/report.md', $regressionService->markdownReport($report));

            $run->forceFill([
                'status' => CrmDocumentPdfRegressionRun::STATUS_COMPLETED,
                'output_directory' => (string) $report['run_directory'],
                'primary_quote_id' => (int) $report['primary_quote_id'],
                'invoice_quote_id' => (int) $report['invoice_quote_id'],
                'warnings_count' => count($report['warnings'] ?? []),
                'report_json_path' => (string) $report['run_directory'].'/report.json',
                'report_md_path' => (string) $report['run_directory'].'/report.md',
                'render_context_json' => $report['render_context'] ?? [],
                'visual_diff_json' => $visualDiff,
                'finished_at' => now(),
            ])->save();
        } catch (Throwable $exception) {
            $run->forceFill([
                'status' => CrmDocumentPdfRegressionRun::STATUS_FAILED,
                'error_message' => $exception->getMessage(),
                'finished_at' => now(),
            ])->save();

            throw $exception;
        }
    }
}
