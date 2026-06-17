<?php

namespace App\Console\Commands;

use App\Services\GeoFlow\CrmDocumentPdfRegressionService;
use Illuminate\Console\Command;
use Throwable;

class CrmDocumentPdfRegressionCommand extends Command
{
    protected $signature = 'crm:document-pdf-regression
        {--quote= : Quote ID for quotation, proforma invoice, and contract samples}
        {--invoice-quote= : Quote ID for commercial invoice and packing list samples}
        {--output=pdf-regression : Storage/app relative output directory}
        {--skip-screenshots : Generate PDFs and report without Chromium screenshots}
        {--json : Output the complete report as JSON}
        {--fail-on-warnings : Return a failure exit code when warnings are found}';

    protected $description = 'Generate CRM document PDF and screenshot regression artifacts for the five document types';

    public function handle(CrmDocumentPdfRegressionService $regressionService): int
    {
        try {
            $report = $regressionService->generate([
                'quote_id' => (string) ($this->option('quote') ?? ''),
                'invoice_quote_id' => (string) ($this->option('invoice-quote') ?? ''),
                'output' => (string) ($this->option('output') ?? 'pdf-regression'),
                'skip_screenshots' => (bool) $this->option('skip-screenshots'),
            ]);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ((bool) $this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } else {
            $this->info('CRM document PDF regression artifacts generated.');
            $this->line('Output: '.$report['run_directory']);
            $this->line('Render: '.($report['render_context']['render_media'] ?? 'print').' / '.($report['render_context']['page_size'] ?? 'A4'));
            $this->table(
                ['Type', 'Quote', 'Items', 'PDF pages', 'HTML pages', 'Screenshots'],
                array_map(static fn (array $row): array => [
                    $row['document_type'],
                    $row['quote_no'].' (#'.$row['quote_id'].')',
                    $row['items_count'],
                    $row['pdf_pages'],
                    $row['html_pages'] ?? '-',
                    count($row['screenshots']),
                ], $report['results'])
            );

            foreach ($report['warnings'] as $warning) {
                $this->warn($warning);
            }
        }

        if ((bool) $this->option('fail-on-warnings') && $report['warnings'] !== []) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
