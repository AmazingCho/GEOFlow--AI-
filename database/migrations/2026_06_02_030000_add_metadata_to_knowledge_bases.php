<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('knowledge_bases', function (Blueprint $table): void {
            if (! Schema::hasColumn('knowledge_bases', 'knowledge_type')) {
                $table->string('knowledge_type', 50)->default('reference')->index()->after('file_type');
            }
            if (! Schema::hasColumn('knowledge_bases', 'knowledge_role')) {
                $table->string('knowledge_role', 50)->default('supporting_context')->index()->after('knowledge_type');
            }
            if (! Schema::hasColumn('knowledge_bases', 'importance')) {
                $table->unsignedTinyInteger('importance')->default(3)->index()->after('knowledge_role');
            }
        });
    }

    public function down(): void
    {
        Schema::table('knowledge_bases', function (Blueprint $table): void {
            foreach (['importance', 'knowledge_role', 'knowledge_type'] as $column) {
                if (Schema::hasColumn('knowledge_bases', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
