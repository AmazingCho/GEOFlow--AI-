<?php

namespace Tests\Feature;

use App\Models\Prompt;
use App\Models\Task;
use App\Services\GeoFlow\PromptPresetCatalog;
use Database\Seeders\PromptPresetSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PromptPresetSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_prompt_preset_seeder_installs_all_packaged_prompts(): void
    {
        $presets = require database_path('seeders/data/prompt_presets.php');

        $this->seed(PromptPresetSeeder::class);

        $this->assertSame(count($presets), Prompt::query()->count());

        foreach ($presets as $preset) {
            $this->assertDatabaseHas('prompts', [
                'name' => $preset['name'],
                'type' => $preset['type'],
                'preset_key' => $preset['preset_key'],
                'preset_version' => $preset['preset_version'],
                'is_system' => true,
            ]);
        }
    }

    public function test_prompt_preset_seeder_is_idempotent(): void
    {
        $this->seed(PromptPresetSeeder::class);

        $before = Prompt::query()
            ->orderBy('id')
            ->get(['id', 'name', 'content', 'variables', 'preset_key', 'last_synced_hash', 'updated_at'])
            ->toArray();

        $this->seed(PromptPresetSeeder::class);

        $this->assertSame($before, Prompt::query()
            ->orderBy('id')
            ->get(['id', 'name', 'content', 'variables', 'preset_key', 'last_synced_hash', 'updated_at'])
            ->toArray());
    }

    public function test_auto_seed_does_not_overwrite_an_administrator_modified_system_prompt(): void
    {
        $this->seed(PromptPresetSeeder::class);

        $prompt = Prompt::query()->whereNotNull('preset_key')->firstOrFail();
        $originalSyncedHash = $prompt->last_synced_hash;
        $prompt->update(['content' => 'Administrator-owned custom content.']);

        $this->seed(PromptPresetSeeder::class);

        $prompt->refresh();
        $this->assertSame('Administrator-owned custom content.', $prompt->content);
        $this->assertSame($originalSyncedHash, $prompt->last_synced_hash);
    }

    public function test_custom_prompts_without_a_preset_key_are_ignored(): void
    {
        $custom = Prompt::query()->create([
            'name' => 'My private prompt',
            'type' => 'content',
            'content' => 'Keep this content.',
            'variables' => '',
        ]);

        $this->seed(PromptPresetSeeder::class);

        $custom->refresh();
        $this->assertSame('My private prompt', $custom->name);
        $this->assertSame('Keep this content.', $custom->content);
        $this->assertNull($custom->preset_key);
    }

    public function test_auto_seed_never_downgrades_a_newer_governed_preset(): void
    {
        $preset = collect(require database_path('seeders/data/prompt_presets_v2.php'))
            ->firstWhere('preset_key', 'article.skill.comparison');
        $prompt = Prompt::query()->create([
            'name' => $preset['name'],
            'type' => $preset['type'],
            'content' => $preset['content'],
            'variables' => $preset['variables'],
            'preset_key' => $preset['preset_key'],
            'preset_version' => $preset['preset_version'],
            'last_synced_hash' => PromptPresetCatalog::contentHash($preset['content'], $preset['variables']),
            'is_system' => true,
            'is_enabled' => false,
        ]);

        $this->seed(PromptPresetSeeder::class);

        $prompt->refresh();
        $this->assertSame($preset['preset_version'], $prompt->preset_version);
        $this->assertSame($preset['content'], $prompt->content);
        $this->assertFalse($prompt->is_enabled);
    }

    public function test_auto_seed_does_not_change_or_fill_prompts_on_an_existing_business_database(): void
    {
        $this->seed(PromptPresetSeeder::class);
        Task::query()->create(['name' => 'Existing business task']);
        Prompt::query()->where('preset_key', 'keyword.generation.default')->delete();
        $before = Prompt::query()->orderBy('id')->get()->toArray();

        $this->seed(PromptPresetSeeder::class);

        $this->assertSame($before, Prompt::query()->orderBy('id')->get()->toArray());
        $this->assertDatabaseMissing('prompts', ['preset_key' => 'keyword.generation.default']);
    }

    public function test_auto_seed_does_not_recreate_an_administrator_deleted_default_without_business_references(): void
    {
        $this->seed(PromptPresetSeeder::class);
        Prompt::query()->where('preset_key', 'keyword.generation.default')->delete();
        $before = Prompt::query()->orderBy('id')->get()->toArray();

        $this->seed(PromptPresetSeeder::class);

        $this->assertSame($before, Prompt::query()->orderBy('id')->get()->toArray());
        $this->assertDatabaseMissing('prompts', ['preset_key' => 'keyword.generation.default']);
    }

    public function test_prompt_preset_seeder_does_not_apply_v2_candidate_presets(): void
    {
        $this->seed(PromptPresetSeeder::class);

        $this->assertDatabaseMissing('prompts', [
            'name' => 'GEO Master - Trust-Based Article Generation',
            'type' => 'content',
        ]);
        $this->assertDatabaseMissing('prompts', [
            'name' => 'GEO Skill - Technical',
            'type' => 'skill',
        ]);
        $this->assertDatabaseMissing('prompts', [
            'name' => 'GEO Skill - Troubleshooting',
            'type' => 'skill',
        ]);
        $this->assertDatabaseMissing('prompts', [
            'name' => 'GEO Skill - Case Study',
            'type' => 'skill',
        ]);
        $this->assertDatabaseMissing('prompts', [
            'name' => 'GEO Skill - Definition',
            'type' => 'skill',
        ]);
    }

    public function test_packaged_prompts_are_safe_for_industry_neutral_distribution(): void
    {
        $presets = require database_path('seeders/data/prompt_presets.php');
        $privateOrIndustrySpecificTerms = [
            '灌胶',
            '点胶',
            'doming',
            'dispensing',
            'potting',
            'epoxy',
            'polyurethane',
            'resin',
            'chiller',
            'cooling',
            'coating',
            'soldering',
            'curing',
            'robota',
            'sj4060',
            'industrial b2b',
            'automation equipment',
            'engineering procurement',
            'design-for-manufacturing',
            'dfm',
        ];

        $this->assertNotEmpty($presets);

        foreach ($presets as $preset) {
            $searchable = mb_strtolower(
                (string) ($preset['name'] ?? '')."\n".(string) ($preset['content'] ?? ''),
                'UTF-8'
            );

            foreach ($privateOrIndustrySpecificTerms as $term) {
                $this->assertStringNotContainsString(
                    mb_strtolower($term, 'UTF-8'),
                    $searchable,
                    sprintf('Packaged prompt "%s" contains private or industry-specific term "%s".', $preset['name'] ?? '', $term)
                );
            }
        }
    }

    public function test_prompt_preset_seeder_renames_a_trusted_legacy_default_on_a_pristine_install(): void
    {
        $legacyName = 'GEO Marketing · Trust-Based Article Generation (English)';
        $presetName = 'GEO Marketing · Trust-Based Article Generation (English)信任型正文生成';

        $preset = collect(require database_path('seeders/data/prompt_presets.php'))
            ->firstWhere('name', $presetName);

        $legacyPrompt = Prompt::query()
            ->where('name', $legacyName)
            ->where('type', 'content')
            ->firstOrFail();

        $legacyPrompt->update([
            'content' => $preset['content'],
            'variables' => $preset['variables'],
        ]);

        $this->seed(PromptPresetSeeder::class);

        $this->assertDatabaseMissing('prompts', [
            'name' => $legacyName,
            'type' => 'content',
        ]);
        $this->assertSame(1, Prompt::query()->where('name', $presetName)->where('type', 'content')->count());
        $this->assertSame($legacyPrompt->id, Prompt::query()->where('name', $presetName)->value('id'));
    }
}
