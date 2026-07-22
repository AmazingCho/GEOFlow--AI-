<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('prompts')) {
            return;
        }

        Schema::table('prompts', function (Blueprint $table): void {
            $table->string('preset_key', 160)->nullable()->unique();
            $table->string('preset_version', 32)->nullable();
            $table->string('last_synced_hash', 64)->nullable();
            $table->boolean('is_system')->default(false);
            $table->boolean('is_enabled')->default(true);
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('prompts') || ! Schema::hasColumn('prompts', 'preset_key')) {
            return;
        }

        Schema::table('prompts', function (Blueprint $table): void {
            $table->dropUnique(['preset_key']);
            $table->dropColumn([
                'preset_key',
                'preset_version',
                'last_synced_hash',
                'is_system',
                'is_enabled',
            ]);
        });
    }
};
