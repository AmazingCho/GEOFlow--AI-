<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('tasks', 'generation_mode')) {
            Schema::table('tasks', function (Blueprint $table): void {
                $table->string('generation_mode', 20)
                    ->default('standard')
                    ->after('model_selection_mode')
                    ->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('tasks', 'generation_mode')) {
            Schema::table('tasks', function (Blueprint $table): void {
                $table->dropColumn('generation_mode');
            });
        }
    }
};
