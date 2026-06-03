<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tasks') || ! Schema::hasColumn('tasks', 'industry_tag_filter')) {
            return;
        }

        Schema::table('tasks', function (Blueprint $table): void {
            $table->dropColumn('industry_tag_filter');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('tasks') && ! Schema::hasColumn('tasks', 'industry_tag_filter')) {
            Schema::table('tasks', function (Blueprint $table): void {
                $table->string('industry_tag_filter', 1000)->default('')->after('knowledge_tag_filter');
            });
        }
    }
};
