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
