<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Prompt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminAiPromptsPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_content_prompts_are_visible(): void
    {
        $admin = Admin::query()->create([
            'username' => 'ai_prompt_admin',
            'password' => 'secret-123',
            'email' => 'ai-prompt-admin@example.com',
            'display_name' => 'AI Prompt Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.ai-prompts'))
            ->assertOk()
            ->assertSee('GEO营销学·信任型正文生成')
            ->assertSee('GEO榜单型正文生成')
            ->assertSee('GEO Marketing · Trust-Based Article Generation (English)')
            ->assertSee('GEO Ranking-Style Article Generation (English)');
    }

    public function test_skill_prompts_are_visible_on_article_prompt_page(): void
    {
        $admin = Admin::query()->create([
            'username' => 'ai_skill_prompt_admin',
            'password' => 'secret-123',
            'email' => 'ai-skill-prompt-admin@example.com',
            'display_name' => 'AI Skill Prompt Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);
        Prompt::query()->create([
            'name' => 'Comparison Skill Prompt',
            'type' => 'skill',
            'content' => 'Add comparison structure.',
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.ai-prompts'))
            ->assertOk()
            ->assertSee('Comparison Skill Prompt')
            ->assertSee(__('admin.ai_prompts.type_skill'));
    }
}
