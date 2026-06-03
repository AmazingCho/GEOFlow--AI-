<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('knowledge_bases', function (Blueprint $table): void {
            if (! Schema::hasColumn('knowledge_bases', 'summary')) {
                $table->text('summary')->nullable()->after('description');
            }
        });
    }

    public function down(): void
    {
        Schema::table('knowledge_bases', function (Blueprint $table): void {
            if (Schema::hasColumn('knowledge_bases', 'summary')) {
                $table->dropColumn('summary');
            }
        });
    }
};
