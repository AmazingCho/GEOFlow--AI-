<?php

namespace Tests\Feature;

use App\Models\Prompt;
use App\Models\Task;
use App\Models\TitleLibrary;
use App\Services\GeoFlow\PromptPresetCatalog;
use App\Services\GeoFlow\PromptPresetSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class PromptPresetSyncCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    public function test_default_command_is_a_non_mutating_dry_run(): void
    {
        $this->seed();
        $before = $this->promptSnapshot();

        $report = $this->runJsonCommand();

        $this->assertFalse($report['applied']);
        $this->assertNotEmpty($report['actions']);
        $this->assertSame($before, $this->promptSnapshot());
        $this->assertSame([], Storage::disk('local')->allFiles('prompt-preset-backups'));
    }

    public function test_apply_creates_a_backup_and_second_preview_is_unchanged(): void
    {
        $this->seed();
        $beforePrompts = Prompt::query()->orderBy('id')->get()->toArray();

        $apply = $this->runApplyCommand();

        $this->assertTrue($apply['applied']);
        $this->assertNotNull($apply['backup_path']);
        $files = Storage::disk('local')->allFiles((string) $apply['backup_path']);
        $this->assertContains($apply['backup_path'].'/prompts.json', $files);
        $this->assertContains($apply['backup_path'].'/task-prompt-mappings.json', $files);
        $this->assertContains($apply['backup_path'].'/title-library-prompt-mappings.json', $files);
        $this->assertSame($beforePrompts, json_decode(
            Storage::disk('local')->get($apply['backup_path'].'/prompts.json'),
            true,
            flags: JSON_THROW_ON_ERROR
        ));
        $manifest = json_decode(
            Storage::disk('local')->get($apply['backup_path'].'/manifest.json'),
            true,
            flags: JSON_THROW_ON_ERROR
        );
        $this->assertSame('complete', $manifest['status']);
        $this->assertSame($apply['plan_fingerprint'], $manifest['plan_fingerprint']);
        $this->assertSame(
            hash('sha256', Storage::disk('local')->get($apply['backup_path'].'/prompts.json')),
            $manifest['files']['prompts.json']
        );

        $preview = $this->runJsonCommand();
        $this->assertNotEmpty($preview['actions']);
        $this->assertSame([], array_values(array_filter(
            $preview['actions'],
            static fn (array $action): bool => $action['status'] !== 'unchanged'
        )));
    }

    public function test_user_modified_preset_is_a_conflict_and_blocks_the_entire_apply(): void
    {
        $this->seed();

        $prompt = Prompt::query()->where('preset_key', 'article.skill.comparison')->firstOrFail();
        $prompt->update(['content' => 'My carefully customized comparison workflow.']);
        $before = $this->promptSnapshot();
        $preview = $this->runJsonCommand();

        $output = new BufferedOutput;
        $exitCode = Artisan::call('geoflow:prompt-presets:sync', [
            '--apply' => true,
            '--expect-plan' => $preview['plan_fingerprint'],
            '--json' => true,
        ], $output);
        $report = $this->decodeJsonOutput($output->fetch());

        $this->assertNotSame(0, $exitCode);
        $this->assertFalse($report['applied']);
        $this->assertTrue($report['review_required']);
        $this->assertSame($preview['plan_fingerprint'], $report['expected_plan']);
        $this->assertSame($report['plan_fingerprint'], $report['actual_plan']);
        $this->assertContains('article.skill.comparison', $report['unresolved_conflicts']);
        $this->assertSame($before, $this->promptSnapshot());
        $this->assertSame('My carefully customized comparison workflow.', $prompt->fresh()->content);
    }

    public function test_keep_local_resolution_preserves_content_and_sync_hash(): void
    {
        $this->seed();
        $this->runApplyCommand();

        $prompt = Prompt::query()->where('preset_key', 'article.skill.comparison')->firstOrFail();
        $lastSyncedHash = $prompt->last_synced_hash;
        $prompt->update(['content' => 'Keep my local comparison workflow.']);

        $report = $this->runApplyCommand([
            '--resolve' => ['article.skill.comparison:keep-local'],
        ]);

        $this->assertTrue($report['applied']);
        $this->assertSame('Keep my local comparison workflow.', $prompt->fresh()->content);
        $this->assertSame($lastSyncedHash, $prompt->fresh()->last_synced_hash);
        $this->assertSame('skip', collect($report['actions'])->firstWhere('preset_key', 'article.skill.comparison')['status']);
    }

    public function test_local_intent_change_is_a_conflict_and_can_remain_manual_only(): void
    {
        $this->seed();
        $this->runApplyCommand();

        $prompt = Prompt::query()->where('preset_key', 'article.skill.comparison')->firstOrFail();
        $prompt->update(['intent_key' => null]);

        $preview = $this->runJsonCommand();
        $action = collect($preview['actions'])->firstWhere('preset_key', 'article.skill.comparison');

        $this->assertSame('conflict', $action['status']);
        $this->assertSame('Local intent metadata differs from the packaged preset.', $action['reason']);
        $this->assertNull($action['current_intent_key']);
        $this->assertSame('comparison', $action['desired_intent_key']);

        $report = $this->runApplyCommand([
            '--resolve' => ['article.skill.comparison:keep-local'],
        ]);

        $this->assertTrue($report['applied']);
        $this->assertNull($prompt->fresh()->intent_key);
        $this->assertSame('skip', collect($report['actions'])->firstWhere('preset_key', 'article.skill.comparison')['status']);
    }

    public function test_use_preset_resolution_updates_only_the_named_conflict(): void
    {
        $this->seed();
        $this->runApplyCommand();

        $comparison = Prompt::query()->where('preset_key', 'article.skill.comparison')->firstOrFail();
        $buying = Prompt::query()->where('preset_key', 'article.skill.buying_guide')->firstOrFail();
        $comparison->update(['content' => 'Customized comparison.']);
        $buying->update(['content' => 'Customized buying guide.']);

        $partialPreview = $this->runJsonCommand([
            '--resolve' => ['article.skill.comparison:use-preset'],
        ]);
        $partialOutput = new BufferedOutput;
        $failedCode = Artisan::call('geoflow:prompt-presets:sync', [
            '--apply' => true,
            '--expect-plan' => $partialPreview['plan_fingerprint'],
            '--json' => true,
            '--resolve' => ['article.skill.comparison:use-preset'],
        ], $partialOutput);
        $this->assertNotSame(0, $failedCode);
        $this->assertSame('Customized comparison.', $comparison->fresh()->content);

        $report = $this->runApplyCommand([
            '--resolve' => [
                'article.skill.comparison:use-preset',
                'article.skill.buying_guide:keep-local',
            ],
        ]);

        $this->assertTrue($report['applied']);
        $this->assertNotSame('Customized comparison.', $comparison->fresh()->content);
        $this->assertSame('Customized buying guide.', $buying->fresh()->content);
    }

    public function test_apply_updates_prompts_in_place_and_preserves_all_references(): void
    {
        $this->seed();
        $master = Prompt::query()->where('preset_key', 'article.master.trust_based')->firstOrFail();
        $skill = Prompt::query()->where('preset_key', 'article.skill.comparison')->firstOrFail();
        $style = Prompt::query()->create([
            'name' => 'Private style',
            'type' => 'style',
            'content' => 'Use a calm private style.',
            'variables' => '',
        ]);
        $task = Task::query()->create([
            'name' => 'Prompt reference preservation',
            'prompt_id' => $master->id,
            'skill_prompt_id' => $skill->id,
            'style_prompt_id' => $style->id,
        ]);
        $titleLibrary = TitleLibrary::query()->create([
            'name' => 'Prompt reference preservation',
            'prompt_id' => $master->id,
        ]);

        $this->runApplyCommand();

        $this->assertSame($master->id, Prompt::query()->where('preset_key', 'article.master.trust_based')->value('id'));
        $this->assertSame('GEO Master - Trust-Based Article Generation', $master->fresh()->name);
        $this->assertSame($skill->id, Prompt::query()->where('preset_key', 'article.skill.comparison')->value('id'));
        $this->assertSame($master->id, $task->fresh()->prompt_id);
        $this->assertSame($skill->id, $task->fresh()->skill_prompt_id);
        $this->assertSame($style->id, $task->fresh()->style_prompt_id);
        $this->assertSame($master->id, $titleLibrary->fresh()->prompt_id);
        $this->assertSame('Use a calm private style.', $style->fresh()->content);
    }

    public function test_preset_filter_limits_preview_and_apply_scope(): void
    {
        $this->seed();

        $report = $this->runApplyCommand([
            '--preset' => ['article.skill.comparison'],
        ]);

        $this->assertCount(1, $report['actions']);
        $this->assertSame('article.skill.comparison', $report['actions'][0]['preset_key']);
        $this->assertSame('2.3.0', Prompt::query()->where('preset_key', 'article.skill.comparison')->value('preset_version'));
        $this->assertDatabaseMissing('prompts', ['preset_key' => 'article.skill.technical']);
    }

    public function test_apply_rejects_a_plan_that_changed_after_preview(): void
    {
        $this->seed();
        $preview = $this->runJsonCommand([
            '--preset' => ['article.skill.comparison'],
        ]);
        Prompt::query()
            ->where('preset_key', 'article.skill.comparison')
            ->update(['content' => 'Changed after the reviewed preview.']);

        $output = new BufferedOutput;
        $exitCode = Artisan::call('geoflow:prompt-presets:sync', [
            '--apply' => true,
            '--expect-plan' => $preview['plan_fingerprint'],
            '--preset' => ['article.skill.comparison'],
            '--json' => true,
        ], $output);
        $report = $this->decodeJsonOutput($output->fetch());

        $this->assertNotSame(0, $exitCode);
        $this->assertFalse($report['applied']);
        $this->assertTrue($report['review_required']);
        $this->assertSame($preview['plan_fingerprint'], $report['expected_plan']);
        $this->assertNotSame($preview['plan_fingerprint'], $report['actual_plan']);
        $this->assertSame([], Storage::disk('local')->allFiles('prompt-preset-backups'));
    }

    public function test_preview_fingerprint_changes_when_only_candidate_version_changes(): void
    {
        $this->seed();
        $realCatalog = app(PromptPresetCatalog::class);
        $candidate = collect($realCatalog->candidate())->firstWhere('preset_key', 'article.skill.comparison');
        $catalog = new class($realCatalog->active(), [$candidate]) extends PromptPresetCatalog
        {
            public function __construct(private array $activePresets, public array $candidatePresets) {}

            public function active(): array
            {
                return $this->activePresets;
            }

            public function candidate(): array
            {
                return $this->candidatePresets;
            }
        };
        $service = new PromptPresetSyncService($catalog);
        $first = $service->preview([], ['article.skill.comparison']);
        $catalog->candidatePresets[0]['preset_version'] = '2.3.1';
        $second = $service->preview([], ['article.skill.comparison']);

        $this->assertNotSame($first['plan_fingerprint'], $second['plan_fingerprint']);
    }

    public function test_sync_refuses_to_downgrade_a_newer_installed_preset(): void
    {
        $this->seed();
        Prompt::query()->where('preset_key', 'article.skill.comparison')->update([
            'preset_version' => '9.0.0',
        ]);

        $preview = $this->runJsonCommand(['--preset' => ['article.skill.comparison']]);

        $this->assertSame(['article.skill.comparison'], $preview['unresolved_conflicts']);
        $this->assertSame('conflict', $preview['actions'][0]['status']);
        $this->assertStringContainsString('downgrade', strtolower($preview['actions'][0]['reason']));
    }

    public function test_unknown_or_duplicate_resolutions_are_rejected(): void
    {
        foreach ([
            ['missing.preset:keep-local'],
            ['article.skill.comparison:keep-local', 'article.skill.comparison:use-preset'],
            ['article.skill.comparison:keep-local'],
        ] as $resolutions) {
            $output = new BufferedOutput;
            $exitCode = Artisan::call('geoflow:prompt-presets:sync', [
                '--resolve' => $resolutions,
                '--json' => true,
            ], $output);
            $report = $this->decodeJsonOutput($output->fetch());

            $this->assertNotSame(0, $exitCode);
            $this->assertFalse($report['applied']);
            $this->assertTrue($report['review_required']);
        }
    }

    public function test_backup_failure_aborts_before_any_prompt_mutation(): void
    {
        $this->seed();
        $before = $this->promptSnapshot();
        $preview = $this->runJsonCommand();
        Storage::disk('local')->put('blocked-backup-root', 'This path is a file.');
        config()->set('filesystems.disks.local.root', Storage::disk('local')->path('blocked-backup-root'));
        Storage::forgetDisk('local');

        $output = new BufferedOutput;
        $exitCode = Artisan::call('geoflow:prompt-presets:sync', [
            '--apply' => true,
            '--expect-plan' => $preview['plan_fingerprint'],
            '--json' => true,
        ], $output);

        $this->assertNotSame(0, $exitCode);
        $this->assertSame($before, $this->promptSnapshot());
    }

    public function test_database_failure_rolls_back_all_prompt_changes_but_keeps_the_backup(): void
    {
        $this->seed();
        $before = $this->promptSnapshot();
        $preview = $this->runJsonCommand();
        DB::statement(<<<'SQL'
            CREATE TRIGGER fail_comparison_preset_update
            BEFORE UPDATE ON prompts
            WHEN OLD.preset_key = 'article.skill.comparison'
            BEGIN
                SELECT RAISE(ABORT, 'simulated prompt update failure');
            END
        SQL);

        $output = new BufferedOutput;
        $exitCode = Artisan::call('geoflow:prompt-presets:sync', [
            '--apply' => true,
            '--expect-plan' => $preview['plan_fingerprint'],
            '--json' => true,
        ], $output);

        $this->assertNotSame(0, $exitCode);
        $this->assertSame($before, $this->promptSnapshot());
        $this->assertNotEmpty(Storage::disk('local')->allFiles('prompt-preset-backups'));
    }

    /** @param array<string, mixed> $arguments */
    private function runJsonCommand(array $arguments = []): array
    {
        $output = new BufferedOutput;
        $exitCode = Artisan::call('geoflow:prompt-presets:sync', $arguments + ['--json' => true], $output);
        $rendered = $output->fetch();
        $this->assertSame(0, $exitCode, $rendered);

        return $this->decodeJsonOutput($rendered);
    }

    /** @param array<string, mixed> $arguments */
    private function runApplyCommand(array $arguments = []): array
    {
        $preview = $this->runJsonCommand($arguments);

        return $this->runJsonCommand($arguments + [
            '--apply' => true,
            '--expect-plan' => $preview['plan_fingerprint'],
        ]);
    }

    /** @return array<string, mixed> */
    private function decodeJsonOutput(string $output): array
    {
        $jsonStart = strpos($output, '{');
        $this->assertNotFalse($jsonStart, $output);

        return json_decode(substr($output, $jsonStart), true, flags: JSON_THROW_ON_ERROR);
    }

    /** @return array<int, array<string, mixed>> */
    private function promptSnapshot(): array
    {
        return Prompt::query()
            ->orderBy('id')
            ->get(['id', 'name', 'type', 'content', 'variables', 'preset_key', 'preset_version', 'last_synced_hash', 'is_system', 'is_enabled', 'updated_at'])
            ->map(static fn (Prompt $prompt): array => $prompt->attributesToArray())
            ->all();
    }
}
