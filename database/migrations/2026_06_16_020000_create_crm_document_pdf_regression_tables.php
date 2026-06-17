<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_document_pdf_regression_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('status', 24)->default('pending')->index();
            $table->foreignId('triggered_by_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->string('output_directory')->nullable();
            $table->foreignId('primary_quote_id')->nullable()->constrained('crm_quotes')->nullOnDelete();
            $table->foreignId('invoice_quote_id')->nullable()->constrained('crm_quotes')->nullOnDelete();
            $table->unsignedInteger('warnings_count')->default(0);
            $table->string('report_json_path')->nullable();
            $table->string('report_md_path')->nullable();
            $table->json('render_context_json')->nullable();
            $table->json('visual_diff_json')->nullable();
            $table->json('options_json')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('pruned_at')->nullable();
            $table->unsignedBigInteger('deleted_bytes')->default(0);
            $table->timestamps();
        });

        Schema::create('crm_document_pdf_regression_baselines', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->default('default')->unique();
            $table->foreignId('run_id')->nullable()->constrained('crm_document_pdf_regression_runs')->nullOnDelete();
            $table->string('baseline_directory');
            $table->foreignId('created_by_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->json('render_context_json')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_document_pdf_regression_baselines');
        Schema::dropIfExists('crm_document_pdf_regression_runs');
    }
};
