<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_quotes', static function (Blueprint $table) {
            $table->string('port_of_loading', 200)->default('')->after('trade_term');
            $table->string('port_of_destination', 200)->default('')->after('port_of_loading');
            $table->string('transport_mode', 100)->default('')->after('port_of_destination');
            $table->string('shipping_mark', 500)->default('')->after('transport_mode');
        });
    }

    public function down(): void
    {
        Schema::table('crm_quotes', static function (Blueprint $table) {
            $table->dropColumn(['port_of_loading', 'port_of_destination', 'transport_mode', 'shipping_mark']);
        });
    }
};
