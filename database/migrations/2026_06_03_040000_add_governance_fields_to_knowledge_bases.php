<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('knowledge_bases', function (Blueprint $table): void {
            if (! Schema::hasColumn('knowledge_bases', 'source_url')) {
                $table->string('source_url', 500)->default('')->after('summary');
            }

            if (! Schema::hasColumn('knowledge_bases', 'status')) {
                $table->string('status', 20)->default('active')->index()->after('importance');
            }
        });
    }

    public function down(): void
    {
        Schema::table('knowledge_bases', function (Blueprint $table): void {
            foreach (['status', 'source_url'] as $column) {
                if (Schema::hasColumn('knowledge_bases', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
