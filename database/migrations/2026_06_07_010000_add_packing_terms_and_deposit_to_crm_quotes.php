<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_quotes', static function (Blueprint $table) {
            $table->string('packing_terms', 500)->default('')->after('installation_terms');
            $table->unsignedTinyInteger('deposit_percent')->default(60)->after('packing_terms');
        });
    }

    public function down(): void
    {
        Schema::table('crm_quotes', static function (Blueprint $table) {
            $table->dropColumn(['packing_terms', 'deposit_percent']);
        });
    }
};
