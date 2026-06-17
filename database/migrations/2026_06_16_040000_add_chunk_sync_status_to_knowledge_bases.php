<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('knowledge_bases', function (Blueprint $table): void {
            if (! Schema::hasColumn('knowledge_bases', 'chunk_sync_status')) {
                $table->string('chunk_sync_status', 20)->default('idle')->index();
            }
            if (! Schema::hasColumn('knowledge_bases', 'chunk_sync_message')) {
                $table->text('chunk_sync_message')->nullable();
            }
            if (! Schema::hasColumn('knowledge_bases', 'chunk_sync_requires_real_embedding')) {
                $table->boolean('chunk_sync_requires_real_embedding')->default(false);
            }
            if (! Schema::hasColumn('knowledge_bases', 'chunk_sync_queued_at')) {
                $table->timestamp('chunk_sync_queued_at')->nullable();
            }
            if (! Schema::hasColumn('knowledge_bases', 'chunk_sync_started_at')) {
                $table->timestamp('chunk_sync_started_at')->nullable();
            }
            if (! Schema::hasColumn('knowledge_bases', 'chunk_sync_completed_at')) {
                $table->timestamp('chunk_sync_completed_at')->nullable();
            }
            if (! Schema::hasColumn('knowledge_bases', 'chunk_sync_failed_at')) {
                $table->timestamp('chunk_sync_failed_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('knowledge_bases', function (Blueprint $table): void {
            foreach ([
                'chunk_sync_failed_at',
                'chunk_sync_completed_at',
                'chunk_sync_started_at',
                'chunk_sync_queued_at',
                'chunk_sync_requires_real_embedding',
                'chunk_sync_message',
                'chunk_sync_status',
            ] as $column) {
                if (Schema::hasColumn('knowledge_bases', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
