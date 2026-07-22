<?php

namespace App\Console\Commands;

use App\Services\GeoFlow\ArticleSkillEvaluationCatalog;
use App\Services\GeoFlow\ArticleSkillOutputEvaluator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class GeoFlowArticleSkillEvaluateCommand extends Command
{
    protected $signature = 'geoflow:article-skills:evaluate
        {--input= : JSON file containing pinned model metadata, outputs, and optional PM scores}
        {--json : Emit machine-readable JSON}';

    protected $description = 'Run the offline article Skill evaluation or inspect externally generated pinned-model outputs';

    public function handle(ArticleSkillEvaluationCatalog $catalog, ArticleSkillOutputEvaluator $evaluator): int
    {
        try {
            [$outputs, $model, $pmReviews, $pairedControls] = $this->loadInput($catalog);
            $report = $evaluator->evaluate($catalog->cases(), $outputs, $model, $pmReviews, $pairedControls);
            $reportLabel = ($model['is_real_model'] ?? null) === true ? 'external-real-model' : 'offline-fixture-v1';
            $path = 'article-skill-evaluations/'.now()->format('Ymd-His').'-'.$reportLabel.'-'.Str::lower(Str::random(8)).'.json';
            $report['report_path'] = $path;
            Storage::disk('local')->put($path, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            $absolutePath = Storage::disk('local')->path($path);
            if (! chmod($absolutePath, 0600) || (fileperms($absolutePath) & 0777) !== 0600) {
                Storage::disk('local')->delete($path);

                throw new RuntimeException('Evaluation report permissions could not be restricted to 0600.');
            }
            $this->render($report);

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    /** @return array{0:list<array<string,mixed>>,1:array<string,mixed>,2:array<string,array<string,mixed>>,3:list<array<string,mixed>>} */
    private function loadInput(ArticleSkillEvaluationCatalog $catalog): array
    {
        $input = trim((string) $this->option('input'));
        if ($input === '') {
            return [$catalog->outputs(), $catalog->model(), [], []];
        }
        if (! is_file($input)) {
            throw new RuntimeException('Evaluation input file does not exist: '.$input);
        }

        $payload = json_decode((string) file_get_contents($input), true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($payload) || ! is_array($payload['outputs'] ?? null) || ! is_array($payload['model'] ?? null)) {
            throw new RuntimeException('Evaluation input must contain model and outputs objects.');
        }
        if (($payload['model']['is_real_model'] ?? null) !== true) {
            throw new RuntimeException('External evaluation input must explicitly declare is_real_model=true.');
        }
        foreach (['name', 'provider', 'model_version', 'temperature', 'max_output_tokens', 'code_commit'] as $field) {
            if (! array_key_exists($field, $payload['model']) || trim((string) $payload['model'][$field]) === '') {
                throw new RuntimeException('External evaluation model metadata is missing '.$field.'.');
            }
        }
        foreach (['name', 'provider', 'model_version'] as $field) {
            $value = (string) $payload['model'][$field];
            if (preg_match('/\A[A-Za-z0-9._:\/-]{1,120}\z/', $value) !== 1 || preg_match('/(?:sk-|api[_-]?key|bearer|secret|password)/i', $value) === 1) {
                throw new RuntimeException('External evaluation model metadata has invalid '.$field.'.');
            }
        }
        if (! is_int($payload['model']['max_output_tokens']) || $payload['model']['max_output_tokens'] < 1 || $payload['model']['max_output_tokens'] > 100000) {
            throw new RuntimeException('External evaluation model metadata has invalid max_output_tokens.');
        }
        if (! is_int($payload['model']['temperature']) && ! is_float($payload['model']['temperature'])) {
            throw new RuntimeException('External evaluation model metadata has invalid temperature.');
        }
        if ($payload['model']['temperature'] < 0 || $payload['model']['temperature'] > 2) {
            throw new RuntimeException('External evaluation model metadata has invalid temperature.');
        }
        if (preg_match('/\A[0-9a-f]{7,64}\z/i', (string) $payload['model']['code_commit']) !== 1) {
            throw new RuntimeException('External evaluation model metadata has invalid code_commit.');
        }

        return [
            array_values($payload['outputs']),
            $payload['model'],
            is_array($payload['pm_reviews'] ?? null) ? $payload['pm_reviews'] : [],
            is_array($payload['paired_controls'] ?? null) ? array_values($payload['paired_controls']) : [],
        ];
    }

    /** @param array<string,mixed> $report */
    private function render(array $report): void
    {
        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            return;
        }

        $this->table(['Mode', 'Cases', 'Outputs', 'Automatic failures', 'Decision'], [[
            $report['evaluation_mode'],
            $report['summary']['case_count'],
            $report['summary']['output_count'],
            $report['summary']['automatic_failures'],
            strtoupper((string) $report['release_decision']),
        ]]);
        $this->line('Report: storage/app/private/'.$report['report_path']);
        if ($report['release_blockers'] !== []) {
            $this->warn('Release blockers: '.implode(', ', $report['release_blockers']));
        }
    }
}
