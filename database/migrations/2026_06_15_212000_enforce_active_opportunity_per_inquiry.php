<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            'CREATE UNIQUE INDEX crm_opportunities_active_source_inquiry_unique '
            .'ON crm_opportunities (source_inquiry_id) '
            .'WHERE source_inquiry_id IS NOT NULL AND deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS crm_opportunities_active_source_inquiry_unique');
    }
};
