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
            ->assertSee(__('admin.ai_prompts.type_skill'))
            ->assertSee(__('admin.ai_prompts.intent_help'));
    }

    public function test_style_prompt_can_be_created_and_listed(): void
    {
        $admin = Admin::query()->create([
            'username' => 'ai_style_prompt_admin',
            'password' => 'secret-123',
            'email' => 'ai-style-prompt-admin@example.com',
            'display_name' => 'AI Style Prompt Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.ai-prompts.store'), [
                'name' => 'Style - Clear Decision Support',
                'type' => 'style',
                'content' => 'Use concise criteria, balanced trade-offs, and clear next steps.',
            ])
            ->assertRedirect(route('admin.ai-prompts'));

        $this->assertDatabaseHas('prompts', [
            'name' => 'Style - Clear Decision Support',
            'type' => 'style',
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.ai-prompts'))
            ->assertOk()
            ->assertSee('Style - Clear Decision Support')
            ->assertSee(__('admin.ai_prompts.type_style'));
    }

    public function test_skill_prompt_can_store_and_display_a_controlled_intent(): void
    {
        $admin = Admin::query()->create([
            'username' => 'ai_prompt_intent_admin',
            'password' => 'secret-123',
            'email' => 'ai-prompt-intent@example.com',
            'display_name' => 'AI Prompt Intent Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.ai-prompts.store'), [
                'name' => 'Technical Explanation Skill',
                'type' => 'skill',
                'intent_key' => 'technical',
                'content' => 'Explain mechanisms and working principles.',
            ])
            ->assertRedirect(route('admin.ai-prompts'));

        $this->assertDatabaseHas('prompts', [
            'name' => 'Technical Explanation Skill',
            'type' => 'skill',
            'intent_key' => 'technical',
        ]);
        $prompt = Prompt::query()->where('name', 'Technical Explanation Skill')->firstOrFail();

        $this->actingAs($admin, 'admin')
            ->put(route('admin.ai-prompts.update', ['promptId' => $prompt->id]), [
                'name' => 'Technical Explanation Skill Updated',
                'type' => 'skill',
                'intent_key' => 'technical',
                'content' => 'Explain mechanisms, components, and working principles.',
            ])
            ->assertRedirect(route('admin.ai-prompts'));

        $this->actingAs($admin, 'admin')
            ->get(route('admin.ai-prompts'))
            ->assertOk()
            ->assertSee('Technical Explanation Skill Updated')
            ->assertSee(__('admin.ai_prompts.intent.technical'));
    }

    public function test_non_skill_prompt_cannot_persist_forged_intent_metadata(): void
    {
        $admin = Admin::query()->create([
            'username' => 'ai_prompt_intent_guard_admin',
            'password' => 'secret-123',
            'email' => 'ai-prompt-intent-guard@example.com',
            'display_name' => 'AI Prompt Intent Guard Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.ai-prompts.store'), [
                'name' => 'Master Without Intent',
                'type' => 'content',
                'intent_key' => 'comparison',
                'content' => 'Shared generation rules.',
            ])
            ->assertRedirect(route('admin.ai-prompts'));

        $this->assertDatabaseHas('prompts', [
            'name' => 'Master Without Intent',
            'type' => 'content',
            'intent_key' => null,
        ]);
    }

    public function test_only_one_skill_can_be_auto_matched_per_intent(): void
    {
        $admin = Admin::query()->create([
            'username' => 'ai_prompt_unique_intent_admin',
            'password' => 'secret-123',
            'email' => 'ai-prompt-unique-intent@example.com',
            'display_name' => 'AI Prompt Unique Intent Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);
        Prompt::query()->create([
            'name' => 'Primary Comparison Skill',
            'type' => 'skill',
            'intent_key' => 'comparison',
            'content' => 'Primary comparison workflow.',
        ]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.ai-prompts.store'), [
                'name' => 'Alternative Comparison Skill',
                'type' => 'skill',
                'intent_key' => 'comparison',
                'content' => 'Alternative comparison workflow.',
            ])
            ->assertSessionHasErrors('intent_key');

        $this->assertDatabaseMissing('prompts', [
            'name' => 'Alternative Comparison Skill',
        ]);
    }
}
