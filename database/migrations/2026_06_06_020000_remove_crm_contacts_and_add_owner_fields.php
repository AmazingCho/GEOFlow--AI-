<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::disableForeignKeyConstraints();

        foreach ([
            'crm_after_sales_tickets',
            'crm_sales_orders',
            'crm_quotes',
            'crm_follow_ups',
            'crm_inquiries',
        ] as $tableName) {
            if (Schema::hasColumn($tableName, 'contact_id')) {
                Schema::table($tableName, static function (Blueprint $table) use ($tableName): void {
                    $table->dropConstrainedForeignId('contact_id');
                });
            }
        }

        Schema::dropIfExists('crm_contacts');

        Schema::enableForeignKeyConstraints();

        Schema::table('crm_customers', static function (Blueprint $table): void {
            if (! Schema::hasColumn('crm_customers', 'phone')) {
                $table->string('phone', 120)->default('')->after('source_channel');
            }
            if (! Schema::hasColumn('crm_customers', 'contact_title')) {
                $table->string('contact_title', 160)->default('')->after('phone');
            }
            if (! Schema::hasColumn('crm_customers', 'owner')) {
                $table->string('owner', 120)->default('')->after('contact_title')->index();
            }
        });

        foreach (['crm_quotes', 'crm_sales_orders', 'crm_after_sales_tickets'] as $tableName) {
            Schema::table($tableName, static function (Blueprint $table) use ($tableName): void {
                if (! Schema::hasColumn($tableName, 'owner')) {
                    $table->string('owner', 120)->default('')->after('customer_id')->index();
                }
            });
        }
    }

    public function down(): void
    {
        Schema::table('crm_after_sales_tickets', static function (Blueprint $table): void {
            if (Schema::hasColumn('crm_after_sales_tickets', 'owner')) {
                $table->dropColumn('owner');
            }
        });
        Schema::table('crm_sales_orders', static function (Blueprint $table): void {
            if (Schema::hasColumn('crm_sales_orders', 'owner')) {
                $table->dropColumn('owner');
            }
        });
        Schema::table('crm_quotes', static function (Blueprint $table): void {
            if (Schema::hasColumn('crm_quotes', 'owner')) {
                $table->dropColumn('owner');
            }
        });
        Schema::table('crm_customers', static function (Blueprint $table): void {
            foreach (['owner', 'contact_title', 'phone'] as $column) {
                if (Schema::hasColumn('crm_customers', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
