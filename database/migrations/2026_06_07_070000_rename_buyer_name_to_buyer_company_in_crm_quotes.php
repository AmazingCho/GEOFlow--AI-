<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_quotes', static function (Blueprint $table) {
            $table->renameColumn('buyer_name', 'buyer_company');
        });
    }

    public function down(): void
    {
        Schema::table('crm_quotes', static function (Blueprint $table) {
            $table->renameColumn('buyer_company', 'buyer_name');
        });
    }
};
