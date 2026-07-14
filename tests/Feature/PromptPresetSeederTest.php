<?php

namespace Tests\Feature;

use App\Models\Prompt;
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
            ]);
        }
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

    public function test_prompt_preset_seeder_renames_legacy_defaults_without_creating_duplicates(): void
    {
        $legacyName = 'GEO Marketing · Trust-Based Article Generation (English)';
        $presetName = 'GEO Marketing · Trust-Based Article Generation (English)信任型正文生成';

        $legacyPrompt = Prompt::query()
            ->where('name', $legacyName)
            ->where('type', 'content')
            ->firstOrFail();

        $legacyPrompt->update(['content' => 'old prompt']);

        $this->seed(PromptPresetSeeder::class);

        $this->assertDatabaseMissing('prompts', [
            'name' => $legacyName,
            'type' => 'content',
        ]);
        $this->assertSame(1, Prompt::query()->where('name', $presetName)->where('type', 'content')->count());
        $this->assertNotSame('old prompt', (string) Prompt::query()->where('name', $presetName)->value('content'));
    }
}
