<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('prompt_preset_installations')) {
            Schema::create('prompt_preset_installations', function (Blueprint $table): void {
                $table->id();
                $table->string('catalog_key', 100)->unique();
                $table->string('installed_version', 32);
                $table->timestamps();
            });
        }

        $governanceBatch = (int) DB::table('migrations')
            ->where('migration', '2026_07_20_000000_add_preset_governance_to_prompts')
            ->value('batch');
        $hasBusinessReferences = (Schema::hasTable('tasks') && DB::table('tasks')->exists())
            || (Schema::hasTable('title_libraries')
                && DB::table('title_libraries')->whereNotNull('prompt_id')->exists());

        if ($governanceBatch > 1 || $hasBusinessReferences) {
            DB::table('prompt_preset_installations')->updateOrInsert(
                ['catalog_key' => 'active-v1'],
                [
                    'installed_version' => 'legacy-existing',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('prompt_preset_installations');
    }
};
