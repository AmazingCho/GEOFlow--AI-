<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('tasks', 'skill_prompt_id')) {
            return;
        }

        Schema::table('tasks', function (Blueprint $table): void {
            $table->foreignId('skill_prompt_id')->nullable()->constrained('prompts')->nullOnDelete();
        });

        $now = now();
        foreach ($this->defaultSkillPrompts() as $prompt) {
            if (! DB::table('prompts')->where('name', $prompt['name'])->where('type', 'skill')->exists()) {
                DB::table('prompts')->insert([
                    'name' => $prompt['name'],
                    'type' => 'skill',
                    'content' => $prompt['content'],
                    'variables' => '',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('tasks', 'skill_prompt_id')) {
            return;
        }

        Schema::table('tasks', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('skill_prompt_id');
        });
    }

    /**
     * @return list<array{name:string,content:string}>
     */
    private function defaultSkillPrompts(): array
    {
        return [
            [
                'name' => 'GEO Skill - Comparison',
                'content' => "Use this skill when the title implies comparison, \"vs\", differences, alternatives, or trade-offs.\n\nRequired structure:\n- Start with a direct answer that names the best-fit choice by scenario.\n- Include a comparison table with decision criteria.\n- Explain trade-offs, hidden costs, and selection risks.\n- Add a short buyer decision framework.\n- Keep claims grounded in the provided knowledge context.",
            ],
            [
                'name' => 'GEO Skill - Buying Guide',
                'content' => "Use this skill when the title implies how to choose, sizing, selection guide, buyer guide, or specifications.\n\nRequired structure:\n- Start with the key selection factors.\n- Explain sizing or configuration logic step by step.\n- Include mistakes to avoid.\n- Add a practical checklist.\n- Tie recommendations back to the provided knowledge context.",
            ],
            [
                'name' => 'GEO Skill - Application',
                'content' => "Use this skill when the title focuses on an industry, application scenario, process, or use case.\n\nRequired structure:\n- Start with the application problem and why it matters.\n- Map requirements to product/entity capabilities.\n- Reference relevant cases or knowledge context when available.\n- Include implementation considerations and measurable outcomes.\n- Add FAQ questions that match application buyers' concerns.",
            ],
        ];
    }
};
