<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_sales_orders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('collection_id')->nullable()->constrained('collections')->nullOnDelete();
            $table->foreignId('customer_id')->constrained('crm_customers')->cascadeOnDelete();
            $table->string('owner', 120)->default('')->index();
            $table->foreignId('inquiry_id')->nullable()->constrained('crm_inquiries')->nullOnDelete();
            $table->foreignId('quote_id')->nullable()->constrained('crm_quotes')->nullOnDelete();
            $table->string('order_no', 80)->unique();
            $table->string('title', 200);
            $table->string('currency', 10)->default('USD');
            $table->decimal('total_amount', 14, 2)->default(0);
            $table->string('payment_status', 40)->default('pending')->index();
            $table->string('production_status', 40)->default('not_started')->index();
            $table->string('delivery_status', 40)->default('pending')->index();
            $table->string('order_status', 40)->default('open')->index();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('collection_id');
            $table->index('customer_id');
            $table->index('quote_id');
        });

        Schema::create('crm_sales_order_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained('crm_sales_orders')->cascadeOnDelete();
            $table->foreignId('entity_id')->nullable()->constrained('entities')->nullOnDelete();
            $table->string('item_name', 200);
            $table->text('description')->nullable();
            $table->decimal('quantity', 14, 2)->default(1);
            $table->string('unit', 40)->default('');
            $table->decimal('unit_price', 14, 2)->default(0);
            $table->decimal('amount', 14, 2)->default(0);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index('order_id');
            $table->index('entity_id');
        });

        Schema::create('crm_after_sales_tickets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('collection_id')->nullable()->constrained('collections')->nullOnDelete();
            $table->foreignId('customer_id')->constrained('crm_customers')->cascadeOnDelete();
            $table->string('owner', 120)->default('')->index();
            $table->foreignId('order_id')->nullable()->constrained('crm_sales_orders')->nullOnDelete();
            $table->foreignId('entity_id')->nullable()->constrained('entities')->nullOnDelete();
            $table->string('title', 200);
            $table->longText('issue_description')->nullable();
            $table->string('issue_type', 100)->default('');
            $table->string('priority', 40)->default('normal')->index();
            $table->string('status', 40)->default('open')->index();
            $table->text('reply_points')->nullable();
            $table->text('missing_information_questions')->nullable();
            $table->text('resolution')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('collection_id');
            $table->index('customer_id');
            $table->index('order_id');
        });

        Schema::create('crm_ticket_knowledge_base', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ticket_id')->constrained('crm_after_sales_tickets')->cascadeOnDelete();
            $table->foreignId('knowledge_base_id')->constrained('knowledge_bases')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['ticket_id', 'knowledge_base_id'], 'crm_ticket_knowledge_unique');
        });

        Schema::create('crm_ticket_case_record', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ticket_id')->constrained('crm_after_sales_tickets')->cascadeOnDelete();
            $table->foreignId('case_record_id')->constrained('case_records')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['ticket_id', 'case_record_id'], 'crm_ticket_case_unique');
        });

        Schema::create('crm_content_proposals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('collection_id')->nullable()->constrained('collections')->nullOnDelete();
            $table->string('source_type', 60);
            $table->unsignedBigInteger('source_id');
            $table->string('proposal_type', 60)->index();
            $table->string('title', 240);
            $table->longText('content')->nullable();
            $table->longText('metadata_json')->nullable();
            $table->string('status', 40)->default('pending')->index();
            $table->unsignedBigInteger('applied_target_id')->nullable();
            $table->string('applied_target_type', 120)->default('');
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();

            $table->index(['source_type', 'source_id']);
            $table->index('collection_id');
        });

        Schema::table('tasks', function (Blueprint $table): void {
            if (! Schema::hasColumn('tasks', 'crm_source_type')) {
                $table->string('crm_source_type', 60)->default('')->after('case_filter')->index();
            }
            if (! Schema::hasColumn('tasks', 'crm_source_id')) {
                $table->unsignedBigInteger('crm_source_id')->nullable()->after('crm_source_type')->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            if (Schema::hasColumn('tasks', 'crm_source_id')) {
                $table->dropColumn('crm_source_id');
            }
            if (Schema::hasColumn('tasks', 'crm_source_type')) {
                $table->dropColumn('crm_source_type');
            }
        });

        Schema::dropIfExists('crm_content_proposals');
        Schema::dropIfExists('crm_ticket_case_record');
        Schema::dropIfExists('crm_ticket_knowledge_base');
        Schema::dropIfExists('crm_after_sales_tickets');
        Schema::dropIfExists('crm_sales_order_items');
        Schema::dropIfExists('crm_sales_orders');
    }
};
