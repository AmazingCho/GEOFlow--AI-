<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            if (! Schema::hasColumn('tasks', 'case_filter')) {
                $table->text('case_filter')->nullable()->after('entity_filter');
            }
            if (! Schema::hasColumn('tasks', 'cross_collection_mode')) {
                $table->unsignedTinyInteger('cross_collection_mode')->default(0)->after('collection_id');
            }
        });

        Schema::table('articles', function (Blueprint $table): void {
            if (! Schema::hasColumn('articles', 'selected_collection_id')) {
                $table->unsignedBigInteger('selected_collection_id')->nullable()->after('task_id')->index();
            }
            if (! Schema::hasColumn('articles', 'selected_entity_ids')) {
                $table->json('selected_entity_ids')->nullable()->after('selected_collection_id');
            }
            if (! Schema::hasColumn('articles', 'selected_case_ids')) {
                $table->json('selected_case_ids')->nullable()->after('selected_entity_ids');
            }
            if (! Schema::hasColumn('articles', 'used_knowledge_base_ids')) {
                $table->json('used_knowledge_base_ids')->nullable()->after('selected_case_ids');
            }
            if (! Schema::hasColumn('articles', 'used_tags')) {
                $table->json('used_tags')->nullable()->after('used_knowledge_base_ids');
            }
            if (! Schema::hasColumn('articles', 'context_snapshot')) {
                $table->json('context_snapshot')->nullable()->after('used_tags');
            }
        });
    }

    public function down(): void
    {
        Schema::table('articles', function (Blueprint $table): void {
            foreach ([
                'context_snapshot',
                'used_tags',
                'used_knowledge_base_ids',
                'selected_case_ids',
                'selected_entity_ids',
                'selected_collection_id',
            ] as $column) {
                if (Schema::hasColumn('articles', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('tasks', function (Blueprint $table): void {
            if (Schema::hasColumn('tasks', 'cross_collection_mode')) {
                $table->dropColumn('cross_collection_mode');
            }
            if (Schema::hasColumn('tasks', 'case_filter')) {
                $table->dropColumn('case_filter');
            }
        });
    }
};
