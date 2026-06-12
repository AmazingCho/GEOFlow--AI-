<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['crm_customers', 'crm_inquiries', 'crm_follow_ups', 'crm_quotes', 'crm_sales_orders', 'crm_after_sales_tickets'] as $tableName) {
            Schema::table($tableName, static function (Blueprint $table) use ($tableName): void {
                if (! Schema::hasColumn($tableName, 'deleted_at')) {
                    $table->softDeletes();
                }
            });
        }

        Schema::create('crm_customer_contacts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained('crm_customers')->cascadeOnDelete();
            $table->string('name', 160);
            $table->string('title', 160)->default('');
            $table->string('department', 160)->default('');
            $table->string('phone', 120)->default('');
            $table->string('email', 200)->default('');
            $table->string('decision_role', 80)->default('');
            $table->boolean('is_primary')->default(false)->index();
            $table->string('status', 40)->default('active')->index();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['customer_id', 'name']);
        });

        Schema::create('crm_opportunities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('collection_id')->nullable()->constrained('collections')->nullOnDelete();
            $table->foreignId('customer_id')->constrained('crm_customers')->cascadeOnDelete();
            $table->foreignId('primary_contact_id')->nullable()->constrained('crm_customer_contacts')->nullOnDelete();
            $table->foreignId('source_inquiry_id')->nullable()->constrained('crm_inquiries')->nullOnDelete();
            $table->foreignId('owner_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->string('name', 200);
            $table->string('stage', 40)->default('qualified')->index();
            $table->decimal('amount', 14, 2)->default(0);
            $table->string('currency', 10)->default('USD');
            $table->unsignedTinyInteger('probability')->default(20);
            $table->date('expected_close_date')->nullable()->index();
            $table->string('next_step', 500)->default('');
            $table->dateTime('next_step_at')->nullable();
            $table->string('competitor', 200)->default('');
            $table->text('lost_reason')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('won_at')->nullable();
            $table->timestamp('lost_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['customer_id', 'stage']);
        });

        Schema::create('crm_tasks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->nullable()->constrained('crm_customers')->nullOnDelete();
            $table->foreignId('inquiry_id')->nullable()->constrained('crm_inquiries')->nullOnDelete();
            $table->foreignId('opportunity_id')->nullable()->constrained('crm_opportunities')->nullOnDelete();
            $table->foreignId('quote_id')->nullable()->constrained('crm_quotes')->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained('crm_sales_orders')->nullOnDelete();
            $table->foreignId('ticket_id')->nullable()->constrained('crm_after_sales_tickets')->nullOnDelete();
            $table->foreignId('assigned_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->foreignId('created_by_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->string('title', 240);
            $table->text('description')->nullable();
            $table->string('priority', 40)->default('normal')->index();
            $table->string('status', 40)->default('open')->index();
            $table->dateTime('due_at')->nullable()->index();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::table('crm_quotes', static function (Blueprint $table): void {
            if (! Schema::hasColumn('crm_quotes', 'opportunity_id')) {
                $table->foreignId('opportunity_id')->nullable()->after('inquiry_id')->constrained('crm_opportunities')->nullOnDelete();
            }
            if (! Schema::hasColumn('crm_quotes', 'source_quote_id')) {
                $table->foreignId('source_quote_id')->nullable()->after('opportunity_id')->constrained('crm_quotes')->nullOnDelete();
            }
        });

        $customers = DB::table('crm_customers')
            ->whereNull('deleted_at')
            ->whereNotNull('contact_person')
            ->where('contact_person', '<>', '')
            ->get(['id', 'contact_person', 'contact_title', 'phone', 'email', 'created_at', 'updated_at']);

        foreach ($customers as $customer) {
            DB::table('crm_customer_contacts')->insert([
                'customer_id' => $customer->id,
                'name' => $customer->contact_person,
                'title' => $customer->contact_title ?? '',
                'department' => '',
                'phone' => $customer->phone ?? '',
                'email' => $customer->email ?? '',
                'decision_role' => 'primary',
                'is_primary' => true,
                'status' => 'active',
                'notes' => null,
                'created_at' => $customer->created_at ?? now(),
                'updated_at' => $customer->updated_at ?? now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('crm_quotes', static function (Blueprint $table): void {
            if (Schema::hasColumn('crm_quotes', 'source_quote_id')) {
                $table->dropConstrainedForeignId('source_quote_id');
            }
            if (Schema::hasColumn('crm_quotes', 'opportunity_id')) {
                $table->dropConstrainedForeignId('opportunity_id');
            }
        });

        Schema::dropIfExists('crm_tasks');
        Schema::dropIfExists('crm_opportunities');
        Schema::dropIfExists('crm_customer_contacts');

        foreach (['crm_after_sales_tickets', 'crm_sales_orders', 'crm_quotes', 'crm_follow_ups', 'crm_inquiries', 'crm_customers'] as $tableName) {
            Schema::table($tableName, static function (Blueprint $table) use ($tableName): void {
                if (Schema::hasColumn($tableName, 'deleted_at')) {
                    $table->dropSoftDeletes();
                }
            });
        }
    }
};
