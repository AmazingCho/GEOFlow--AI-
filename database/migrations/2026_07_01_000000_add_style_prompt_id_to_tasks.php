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
        // Public releases keep Style Prompt as an empty extension point.
        // Teams can create private style presets in the admin without publishing them.
        return [];
    }
};
