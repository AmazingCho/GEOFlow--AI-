<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tasks') || Schema::hasColumn('tasks', 'knowledge_tag_filter')) {
            return;
        }

        Schema::table('tasks', function (Blueprint $table): void {
            $table->string('knowledge_tag_filter', 1000)->default('');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('tasks') || ! Schema::hasColumn('tasks', 'knowledge_tag_filter')) {
            return;
        }

        Schema::table('tasks', function (Blueprint $table): void {
            $table->dropColumn('knowledge_tag_filter');
        });
    }
};
