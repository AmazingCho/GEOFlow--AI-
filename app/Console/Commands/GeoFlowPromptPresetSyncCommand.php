<?php

namespace App\Console\Commands;

use App\Services\GeoFlow\PromptPresetSyncService;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

class GeoFlowPromptPresetSyncCommand extends Command
{
    protected $signature = 'geoflow:prompt-presets:sync
        {--apply : Apply the reviewed synchronization plan}
        {--expect-plan= : Fingerprint returned by the reviewed dry-run}
        {--preset=* : Limit preview/apply to one or more preset keys}
        {--resolve=* : Per-preset resolution, preset_key:keep-local or preset_key:use-preset}
        {--json : Emit machine-readable JSON}';

    protected $description = 'Preview or safely apply governed GEOFlow prompt presets';

    public function handle(PromptPresetSyncService $service): int
    {
        $resolutions = [];
        $presetKeys = [];

        try {
            $resolutions = $this->parseResolutions((array) $this->option('resolve'));
            $presetKeys = array_values(array_unique(array_filter(array_map('trim', (array) $this->option('preset')))));
            $report = $this->option('apply')
                ? $service->apply((string) $this->option('expect-plan'), $resolutions, $presetKeys)
                : $service->preview($resolutions, $presetKeys);

            $this->renderReport($report);

            return self::SUCCESS;
        } catch (Throwable $exception) {
            try {
                $report = $service->preview($resolutions, $presetKeys);
            } catch (RuntimeException) {
                $report = [
                    'applied' => false,
                    'plan_fingerprint' => null,
                    'backup_path' => null,
                    'unresolved_conflicts' => [],
                    'actions' => [],
                ];
            }
            $report['error'] = $exception->getMessage();
            $report['expected_plan'] = (string) $this->option('expect-plan');
            $report['actual_plan'] = $report['plan_fingerprint'];
            $report['review_required'] = true;
            $this->renderReport($report);

            return self::FAILURE;
        }
    }

    /** @param list<string> $values @return array<string, 'keep-local'|'use-preset'> */
    private function parseResolutions(array $values): array
    {
        $resolutions = [];
        foreach ($values as $value) {
            $separator = str_contains($value, '=') ? '=' : ':';
            [$key, $resolution] = array_pad(explode($separator, $value, 2), 2, '');
            if ($key === '' || ! in_array($resolution, ['keep-local', 'use-preset'], true)) {
                throw new RuntimeException('Invalid --resolve value: '.$value);
            }
            if (array_key_exists($key, $resolutions)) {
                throw new RuntimeException('Duplicate --resolve value for preset: '.$key);
            }
            $resolutions[$key] = $resolution;
        }

        return $resolutions;
    }

    /** @param array<string, mixed> $report */
    private function renderReport(array $report): void
    {
        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            return;
        }

        $this->table(
            ['Preset key', 'Status', 'Current name', 'Target name', 'Reason'],
            array_map(static fn (array $action): array => [
                $action['preset_key'],
                $action['status'],
                $action['current_name'] ?? '-',
                $action['name'],
                $action['reason'],
            ], $report['actions'])
        );
        $this->line('Plan fingerprint: '.$report['plan_fingerprint']);
        if ($report['unresolved_conflicts'] !== []) {
            $this->warn('Unresolved conflicts: '.implode(', ', $report['unresolved_conflicts']));
        }
        if ($report['backup_path']) {
            $this->info('Backup: storage/app/private/'.$report['backup_path']);
        }
        if (isset($report['error'])) {
            $this->error($report['error']);
        }
    }
}
