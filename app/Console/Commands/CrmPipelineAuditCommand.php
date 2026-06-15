<?php

namespace App\Console\Commands;

use App\Services\GeoFlow\CrmPipelineConsistencyService;
use Illuminate\Console\Command;

class CrmPipelineAuditCommand extends Command
{
    protected $signature = 'crm:pipeline-audit
        {--json : Output the complete report as JSON}
        {--fail-on-issues : Return a failure exit code when issues are found}';

    protected $description = 'Audit CRM inquiry, opportunity, activity, task, and document links without changing data';

    public function handle(CrmPipelineConsistencyService $service): int
    {
        $report = $service->audit();

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } else {
            $this->info('CRM pipeline audit (read-only)');
            $this->table(
                ['Issue type', 'Count'],
                collect($report['summary'])
                    ->except('total_issues')
                    ->map(static fn (int $count, string $type): array => [$type, $count])
                    ->values()
                    ->all()
            );
            $this->line('Total issues: '.$report['summary']['total_issues']);
            $this->comment('No CRM records were changed.');
        }

        if ((bool) $this->option('fail-on-issues') && (int) $report['summary']['total_issues'] > 0) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
