<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_customers', static function (Blueprint $table) {
            $table->string('contact_person', 200)->default('')->after('company_name');
        });
    }

    public function down(): void
    {
        Schema::table('crm_customers', static function (Blueprint $table) {
            $table->dropColumn('contact_person');
        });
    }
};
