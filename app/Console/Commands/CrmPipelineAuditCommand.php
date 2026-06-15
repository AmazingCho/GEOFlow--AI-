<?php

namespace App\Console\Commands;

use App\Services\GeoFlow\CrmPipelineConsistencyService;
use Illuminate\Console\Command;

class CrmPipelineAuditCommand extends Command
{
    protected $signature = 'crm:pipeline-audit
        {--json : Output the complete report as JSON}
        {--apply : Apply only unambiguous repairs; default is read-only}
        {--fail-on-issues : Return a failure exit code when issues are found}';

    protected $description = 'Audit CRM inquiry, opportunity, activity, task, and document links without changing data';

    public function handle(CrmPipelineConsistencyService $service): int
    {
        $applied = (bool) $this->option('apply');
        $report = $applied ? $service->repairUniqueLinks() : $service->audit();
        $summary = $applied ? $report['after']['summary'] : $report['summary'];

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } else {
            $this->info($applied ? 'CRM pipeline audit repair applied' : 'CRM pipeline audit (read-only)');
            $this->table(
                ['Issue type', 'Count'],
                collect($summary)
                    ->except('total_issues')
                    ->map(static fn (int $count, string $type): array => [$type, $count])
                    ->values()
                    ->all()
            );
            $this->line('Total issues: '.$summary['total_issues']);
            if ($applied) {
                $this->comment('Applied: '.json_encode($report['repair']['applied'], JSON_UNESCAPED_UNICODE));
                $this->comment('Skipped: '.json_encode($report['repair']['skipped'], JSON_UNESCAPED_UNICODE));
            } else {
                $this->comment('No CRM records were changed.');
            }
        }

        if ((bool) $this->option('fail-on-issues') && (int) $summary['total_issues'] > 0) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
