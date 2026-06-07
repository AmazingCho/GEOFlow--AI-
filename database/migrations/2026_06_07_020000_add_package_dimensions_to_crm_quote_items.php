<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_quote_items', static function (Blueprint $table) {
            $table->decimal('package_length', 8, 1)->nullable()->after('volume_cbm');
            $table->decimal('package_width', 8, 1)->nullable()->after('package_length');
            $table->decimal('package_height', 8, 1)->nullable()->after('package_width');
        });
    }

    public function down(): void
    {
        Schema::table('crm_quote_items', static function (Blueprint $table) {
            $table->dropColumn(['package_length', 'package_width', 'package_height']);
        });
    }
};
