<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('knowledge_governance_proposals')) {
            return;
        }

        Schema::create('knowledge_governance_proposals', function (Blueprint $table): void {
            $table->id();
            $table->string('proposal_type', 40)->index();
            $table->string('status', 40)->default('pending')->index();
            $table->foreignId('collection_id')->nullable()->constrained('collections')->nullOnDelete();
            $table->foreignId('primary_knowledge_base_id')->nullable()->constrained('knowledge_bases')->nullOnDelete();
            $table->json('related_knowledge_base_ids')->nullable();
            $table->json('detection_snapshot')->nullable();
            $table->longText('proposed_content')->nullable();
            $table->longText('before_content_snapshot')->nullable();
            $table->text('admin_note')->nullable();
            $table->foreignId('created_by_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->foreignId('applied_by_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->foreignId('rolled_back_by_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamp('applied_at')->nullable();
            $table->timestamp('rolled_back_at')->nullable();
            $table->timestamps();

            $table->index(['proposal_type', 'status']);
            $table->index(['collection_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_governance_proposals');
    }
};
