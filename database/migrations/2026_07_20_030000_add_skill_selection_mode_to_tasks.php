<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('tasks', 'skill_selection_mode')) {
            Schema::table('tasks', function (Blueprint $table): void {
                $table->string('skill_selection_mode', 20)->default('none')->after('skill_prompt_id')->index();
            });
        }

        DB::table('tasks')
            ->whereNotNull('skill_prompt_id')
            ->where('skill_prompt_id', '>', 0)
            ->update(['skill_selection_mode' => 'manual']);
        DB::table('tasks')
            ->where(function ($query): void {
                $query->whereNull('skill_prompt_id')->orWhere('skill_prompt_id', '<=', 0);
            })
            ->update(['skill_selection_mode' => 'none']);
    }

    public function down(): void
    {
        if (Schema::hasColumn('tasks', 'skill_selection_mode')) {
            Schema::table('tasks', function (Blueprint $table): void {
                $table->dropColumn('skill_selection_mode');
            });
        }
    }
};
