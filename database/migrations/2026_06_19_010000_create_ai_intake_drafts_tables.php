<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_intake_drafts', function (Blueprint $table): void {
            $table->id();
            $table->string('source', 80)->default('codex')->index();
            $table->string('source_reference', 255)->default('');
            $table->foreignId('collection_id')->nullable()->constrained('collections')->nullOnDelete();
            $table->longText('raw_input');
            $table->longText('normalized_summary')->nullable();
            $table->string('status', 40)->default('needs_review')->index();
            $table->decimal('confidence', 5, 4)->nullable();
            $table->string('detected_language', 32)->default('');
            $table->foreignId('created_by_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->foreignId('reviewed_by_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamp('applied_at')->nullable();
            $table->text('rejected_reason')->nullable();
            $table->json('metadata_json')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['collection_id', 'status']);
        });

        Schema::create('ai_intake_actions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('draft_id')->constrained('ai_intake_drafts')->cascadeOnDelete();
            $table->string('action_type', 40)->index();
            $table->string('target_type', 80)->index();
            $table->unsignedBigInteger('target_id')->nullable();
            $table->json('payload_json')->nullable();
            $table->json('relation_json')->nullable();
            $table->json('diff_json')->nullable();
            $table->decimal('confidence', 5, 4)->nullable();
            $table->string('risk_level', 20)->default('medium')->index();
            $table->string('status', 40)->default('pending')->index();
            $table->text('error_message')->nullable();
            $table->string('applied_target_type', 180)->default('');
            $table->unsignedBigInteger('applied_target_id')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();

            $table->index(['draft_id', 'status']);
            $table->index(['target_type', 'target_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_intake_actions');
        Schema::dropIfExists('ai_intake_drafts');
    }
};
