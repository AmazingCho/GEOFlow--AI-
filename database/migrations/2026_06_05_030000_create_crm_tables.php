<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_customers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('collection_id')->nullable()->constrained('collections')->nullOnDelete();
            $table->string('company_name', 200);
            $table->string('customer_type', 80)->default('');
            $table->string('country', 100)->default('');
            $table->string('region', 120)->default('');
            $table->string('website', 500)->default('');
            $table->string('industry', 160)->default('');
            $table->string('source_channel', 120)->default('');
            $table->string('phone', 120)->default('');
            $table->string('contact_title', 160)->default('');
            $table->string('owner', 120)->default('')->index();
            $table->string('status', 40)->default('active')->index();
            $table->text('notes')->nullable();
            $table->text('tags_json')->nullable();
            $table->string('created_by', 120)->default('');
            $table->string('updated_by', 120)->default('');
            $table->timestamps();

            $table->index('company_name');
            $table->index('collection_id');
        });

        Schema::create('crm_inquiries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('collection_id')->nullable()->constrained('collections')->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('crm_customers')->nullOnDelete();
            $table->string('source_channel', 120)->default('');
            $table->string('source_url', 500)->default('');
            $table->string('subject', 200);
            $table->longText('raw_message')->nullable();
            $table->string('detected_language', 80)->default('');
            $table->string('status', 40)->default('new')->index();
            $table->string('priority', 40)->default('normal')->index();
            $table->string('assigned_to', 120)->default('');
            $table->text('customer_need_summary')->nullable();
            $table->text('product_interest')->nullable();
            $table->text('suggested_reply_points')->nullable();
            $table->text('missing_information_questions')->nullable();
            $table->string('urgency_level', 40)->default('');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('collection_id');
            $table->index('customer_id');
            $table->index('subject');
        });

        Schema::create('crm_follow_ups', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained('crm_customers')->cascadeOnDelete();
            $table->foreignId('inquiry_id')->nullable()->constrained('crm_inquiries')->nullOnDelete();
            $table->string('followup_type', 80)->default('');
            $table->text('content')->nullable();
            $table->text('next_action')->nullable();
            $table->dateTime('next_followup_at')->nullable()->index();
            $table->string('owner', 120)->default('');
            $table->string('status', 40)->default('open')->index();
            $table->timestamps();
        });

        Schema::create('crm_quotes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('collection_id')->nullable()->constrained('collections')->nullOnDelete();
            $table->foreignId('customer_id')->constrained('crm_customers')->cascadeOnDelete();
            $table->string('owner', 120)->default('')->index();
            $table->foreignId('inquiry_id')->nullable()->constrained('crm_inquiries')->nullOnDelete();
            $table->string('quote_no', 80)->unique();
            $table->string('document_type', 40)->default('quotation');
            $table->string('title', 200);
            $table->string('currency', 10)->default('USD');
            $table->date('valid_until')->nullable();
            $table->text('payment_terms')->nullable();
            $table->text('delivery_terms')->nullable();
            $table->string('lead_time', 120)->default('');
            $table->string('status', 40)->default('draft')->index();
            $table->text('notes')->nullable();
            $table->text('internal_notes')->nullable();
            $table->unsignedInteger('revision')->default(1);
            $table->decimal('total_amount', 14, 2)->default(0);
            $table->timestamps();

            $table->index('collection_id');
            $table->index('customer_id');
            $table->index('inquiry_id');
        });

        Schema::create('crm_quote_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('quote_id')->constrained('crm_quotes')->cascadeOnDelete();
            $table->foreignId('entity_id')->nullable()->constrained('entities')->nullOnDelete();
            $table->string('item_name', 200);
            $table->text('description')->nullable();
            $table->decimal('quantity', 14, 2)->default(1);
            $table->string('unit', 40)->default('');
            $table->decimal('unit_price', 14, 2)->default(0);
            $table->decimal('amount', 14, 2)->default(0);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index('quote_id');
            $table->index('entity_id');
        });

        Schema::create('crm_inquiry_entity', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('crm_inquiry_id')->constrained('crm_inquiries')->cascadeOnDelete();
            $table->foreignId('entity_id')->constrained('entities')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['crm_inquiry_id', 'entity_id'], 'crm_inquiry_entity_unique');
        });

        Schema::create('crm_inquiry_knowledge_base', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('crm_inquiry_id')->constrained('crm_inquiries')->cascadeOnDelete();
            $table->foreignId('knowledge_base_id')->constrained('knowledge_bases')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['crm_inquiry_id', 'knowledge_base_id'], 'crm_inquiry_knowledge_unique');
        });

        Schema::create('crm_inquiry_case_record', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('crm_inquiry_id')->constrained('crm_inquiries')->cascadeOnDelete();
            $table->foreignId('case_record_id')->constrained('case_records')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['crm_inquiry_id', 'case_record_id'], 'crm_inquiry_case_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_inquiry_case_record');
        Schema::dropIfExists('crm_inquiry_knowledge_base');
        Schema::dropIfExists('crm_inquiry_entity');
        Schema::dropIfExists('crm_quote_items');
        Schema::dropIfExists('crm_quotes');
        Schema::dropIfExists('crm_follow_ups');
        Schema::dropIfExists('crm_inquiries');
        Schema::dropIfExists('crm_customers');
    }
};
