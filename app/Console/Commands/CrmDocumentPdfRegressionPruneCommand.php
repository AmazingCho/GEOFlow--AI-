<?php

namespace App\Console\Commands;

use App\Services\GeoFlow\CrmDocumentPdfRegressionCleanupService;
use Illuminate\Console\Command;

class CrmDocumentPdfRegressionPruneCommand extends Command
{
    protected $signature = 'crm:document-pdf-regression:prune
        {--dry-run : Preview cleanup candidates without deleting files}
        {--keep-runs=20 : Always keep this many latest runs}
        {--keep-days=30 : Always keep runs newer than this many days}';

    protected $description = 'Prune old CRM document PDF regression artifact directories';

    public function handle(CrmDocumentPdfRegressionCleanupService $cleanupService): int
    {
        $keepRuns = (int) $this->option('keep-runs');
        $keepDays = (int) $this->option('keep-days');

        if ((bool) $this->option('dry-run')) {
            $preview = $cleanupService->preview($keepRuns, $keepDays);
            $this->info('CRM document PDF regression prune dry-run.');
            $this->table(
                ['Run ID', 'Status', 'Created', 'Size', 'Directory'],
                array_map(static fn (array $row): array => [
                    $row['run_id'],
                    $row['status'],
                    $row['created_at'],
                    $row['size'],
                    $row['output_directory'],
                ], $preview['candidates'])
            );
            $this->line('Candidates: '.count($preview['candidates']));

            return self::SUCCESS;
        }

        $result = $cleanupService->prune($keepRuns, $keepDays);
        $this->info('CRM document PDF regression artifacts pruned.');
        $this->line('Deleted runs: '.$result['deleted_count']);
        $this->line('Deleted size: '.$cleanupService->formatBytes((int) $result['deleted_bytes']));

        return self::SUCCESS;
    }
}
