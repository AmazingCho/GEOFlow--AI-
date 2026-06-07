<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ai_models')) {
            return;
        }

        Schema::table('ai_models', function (Blueprint $table): void {
            if (! Schema::hasColumn('ai_models', 'max_tokens')) {
                $table->unsignedInteger('max_tokens')->nullable()->after('daily_limit');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('ai_models')) {
            return;
        }

        Schema::table('ai_models', function (Blueprint $table): void {
            if (Schema::hasColumn('ai_models', 'max_tokens')) {
                $table->dropColumn('max_tokens');
            }
        });
    }
};
