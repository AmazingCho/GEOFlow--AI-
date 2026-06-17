<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('knowledge_corrections')) {
            Schema::create('knowledge_corrections', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('article_id')->nullable()->constrained('articles')->nullOnDelete();
                $table->foreignId('knowledge_base_id')->nullable()->constrained('knowledge_bases')->cascadeOnDelete();
                $table->unsignedBigInteger('knowledge_chunk_id')->nullable()->index();
                $table->foreignId('reported_by_admin_id')->nullable()->constrained('admins')->nullOnDelete();
                $table->foreignId('reviewed_by_admin_id')->nullable()->constrained('admins')->nullOnDelete();
                $table->foreignId('ai_model_id')->nullable()->constrained('ai_models')->nullOnDelete();
                $table->string('status', 30)->default('pending')->index();
                $table->text('error_description');
                $table->text('selected_article_text')->nullable();
                $table->json('retrieved_context')->nullable();
                $table->json('ai_result')->nullable();
                $table->boolean('confirmed_error')->default(false);
                $table->string('error_type', 80)->default('');
                $table->longText('suggested_content')->nullable();
                $table->text('reasoning')->nullable();
                $table->decimal('confidence', 5, 4)->default(0);
                $table->text('review_note')->nullable();
                $table->timestamp('applied_at')->nullable();
                $table->timestamps();

                $table->index(['knowledge_base_id', 'status']);
                $table->index(['article_id', 'status']);
            });
        }

        if (! Schema::hasTable('knowledge_chunk_versions')) {
            Schema::create('knowledge_chunk_versions', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('knowledge_correction_id')->nullable()->constrained('knowledge_corrections')->nullOnDelete();
                $table->foreignId('knowledge_base_id')->constrained('knowledge_bases')->cascadeOnDelete();
                $table->unsignedBigInteger('knowledge_chunk_id')->nullable()->index();
                $table->integer('version_no')->default(1);
                $table->longText('old_content');
                $table->longText('new_content');
                $table->string('old_embedding_hash', 64)->default('');
                $table->string('new_embedding_hash', 64)->default('');
                $table->foreignId('changed_by_admin_id')->nullable()->constrained('admins')->nullOnDelete();
                $table->string('change_reason', 255)->default('');
                $table->timestamps();

                $table->index(['knowledge_base_id', 'knowledge_chunk_id']);
                $table->index(['knowledge_correction_id', 'version_no']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_chunk_versions');
        Schema::dropIfExists('knowledge_corrections');
    }
};
