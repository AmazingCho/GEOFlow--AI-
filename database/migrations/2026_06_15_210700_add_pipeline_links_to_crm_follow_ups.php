<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_follow_ups', static function (Blueprint $table): void {
            if (! Schema::hasColumn('crm_follow_ups', 'opportunity_id')) {
                $table->foreignId('opportunity_id')
                    ->nullable()
                    ->after('inquiry_id')
                    ->constrained('crm_opportunities')
                    ->nullOnDelete();
            }
            if (! Schema::hasColumn('crm_follow_ups', 'task_id')) {
                $table->foreignId('task_id')
                    ->nullable()
                    ->after('opportunity_id')
                    ->constrained('crm_tasks')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('crm_follow_ups', static function (Blueprint $table): void {
            if (Schema::hasColumn('crm_follow_ups', 'task_id')) {
                $table->dropConstrainedForeignId('task_id');
            }
            if (Schema::hasColumn('crm_follow_ups', 'opportunity_id')) {
                $table->dropConstrainedForeignId('opportunity_id');
            }
        });
    }
};
