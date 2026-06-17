<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_follow_ups', static function (Blueprint $table): void {
            if (! Schema::hasColumn('crm_follow_ups', 'activity_type')) {
                $table->string('activity_type', 40)->default('note')->after('followup_type')->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('crm_follow_ups', static function (Blueprint $table): void {
            if (Schema::hasColumn('crm_follow_ups', 'activity_type')) {
                $table->dropColumn('activity_type');
            }
        });
    }
};
