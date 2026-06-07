<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_quotes', static function (Blueprint $table) {
            $table->string('buyer_contact', 200)->default('')->after('buyer_name');
        });
    }

    public function down(): void
    {
        Schema::table('crm_quotes', static function (Blueprint $table) {
            $table->dropColumn('buyer_contact');
        });
    }
};
