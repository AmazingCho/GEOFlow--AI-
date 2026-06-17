<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_customers', static function (Blueprint $table): void {
            if (! Schema::hasColumn('crm_customers', 'tax_number')) {
                $table->string('tax_number', 120)->default('')->after('email');
            }
        });

        Schema::table('crm_quotes', static function (Blueprint $table): void {
            if (! Schema::hasColumn('crm_quotes', 'buyer_tax_number')) {
                $table->string('buyer_tax_number', 120)->default('')->after('buyer_company');
            }
        });
    }

    public function down(): void
    {
        Schema::table('crm_quotes', static function (Blueprint $table): void {
            if (Schema::hasColumn('crm_quotes', 'buyer_tax_number')) {
                $table->dropColumn('buyer_tax_number');
            }
        });

        Schema::table('crm_customers', static function (Blueprint $table): void {
            if (Schema::hasColumn('crm_customers', 'tax_number')) {
                $table->dropColumn('tax_number');
            }
        });
    }
};
