<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('tasks', 'style_prompt_id')) {
            Schema::table('tasks', function (Blueprint $table): void {
                $table->foreignId('style_prompt_id')->nullable()->after('skill_prompt_id')->constrained('prompts')->nullOnDelete();
            });
        }

        $now = now();
        foreach ($this->defaultStylePrompts() as $prompt) {
            if (! DB::table('prompts')->where('name', $prompt['name'])->where('type', 'style')->exists()) {
                DB::table('prompts')->insert([
                    'name' => $prompt['name'],
                    'type' => 'style',
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
        if (! Schema::hasColumn('tasks', 'style_prompt_id')) {
            return;
        }

        Schema::table('tasks', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('style_prompt_id');
        });
    }

    /**
     * @return list<array{name:string,content:string}>
     */
    private function defaultStylePrompts(): array
    {
        return [
            [
                'name' => 'Style - Engineering Procurement Advisor',
                'content' => "Use a practical engineering procurement tone.\n\nStyle boundaries:\n- Write like a supplier-side technical advisor helping a buyer avoid mistakes.\n- Prefer clear criteria, constraints, trade-offs, and implementation notes.\n- Keep claims restrained and evidence-based.\n- Avoid hype, vague superiority claims, and celebrity or author imitation.\n- Do not change facts, language, structure requirements, or safety rules from the Master Prompt.",
            ],
            [
                'name' => 'Style - Cost Breakdown Analyst',
                'content' => "Use a cost-analysis style for buyers comparing total ownership cost.\n\nStyle boundaries:\n- Explain price, configuration, maintenance, operation, and hidden cost factors.\n- Use sober, finance-friendly wording and practical examples.\n- Make uncertainty explicit when numbers are not provided.\n- Avoid invented prices, unsupported ROI claims, and aggressive sales language.\n- Do not change facts, language, structure requirements, or safety rules from the Master Prompt.",
            ],
            [
                'name' => 'Style - Supplier Comparison Matrix',
                'content' => "Use a comparison-oriented sourcing style.\n\nStyle boundaries:\n- Make differences easy to scan with criteria, scenarios, and buyer fit.\n- Compare options by use case instead of declaring one universal winner.\n- Mention limitations and when an option is not suitable.\n- Avoid attacking competitors or inventing competitor facts.\n- Do not change facts, language, structure requirements, or safety rules from the Master Prompt.",
            ],
            [
                'name' => 'Style - DFM Risk Explainer',
                'content' => "Use a design-for-manufacturing risk explanation style.\n\nStyle boundaries:\n- Focus on process risk, tolerance, material behavior, commissioning, and failure prevention.\n- Explain why a detail matters for production reliability.\n- Prefer diagnostic language, checklists, and practical mitigation advice.\n- Avoid overstating guarantees or implying risk can be eliminated.\n- Do not change facts, language, structure requirements, or safety rules from the Master Prompt.",
            ],
            [
                'name' => 'Style - Process Selection Guide',
                'content' => "Use a process-selection advisory style.\n\nStyle boundaries:\n- Guide the reader through application requirements, constraints, and decision steps.\n- Connect equipment choice to material, production volume, accuracy, labor, and maintenance conditions.\n- Use concise headings, scenario cues, and next-step checks.\n- Avoid generic marketing claims and unsupported product recommendations.\n- Do not change facts, language, structure requirements, or safety rules from the Master Prompt.",
            ],
        ];
    }
};
