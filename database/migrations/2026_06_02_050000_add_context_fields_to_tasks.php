<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            if (! Schema::hasColumn('tasks', 'collection_id')) {
                $table->foreignId('collection_id')->nullable()->after('name')->constrained('collections')->nullOnDelete();
            }
            if (! Schema::hasColumn('tasks', 'entity_filter')) {
                $table->text('entity_filter')->nullable()->after('knowledge_tag_filter');
            }
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            if (Schema::hasColumn('tasks', 'collection_id')) {
                $table->dropConstrainedForeignId('collection_id');
            }
            if (Schema::hasColumn('tasks', 'entity_filter')) {
                $table->dropColumn('entity_filter');
            }
        });
    }
};
