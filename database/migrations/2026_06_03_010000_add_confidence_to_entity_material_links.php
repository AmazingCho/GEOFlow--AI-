<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('entity_material_links', function (Blueprint $table): void {
            if (! Schema::hasColumn('entity_material_links', 'confidence')) {
                $table->decimal('confidence', 5, 2)->nullable()->after('link_role');
            }
        });
    }

    public function down(): void
    {
        Schema::table('entity_material_links', function (Blueprint $table): void {
            if (Schema::hasColumn('entity_material_links', 'confidence')) {
                $table->dropColumn('confidence');
            }
        });
    }
};
