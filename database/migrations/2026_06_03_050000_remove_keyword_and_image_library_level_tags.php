<?php

use App\Models\ImageLibrary;
use App\Models\KeywordLibrary;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('taggables')) {
            return;
        }

        DB::table('taggables')
            ->whereIn('taggable_type', [
                KeywordLibrary::class,
                ImageLibrary::class,
            ])
            ->delete();
    }

    public function down(): void
    {
        // Library-level tags for keyword and image libraries were intentionally
        // removed to avoid ambiguity with per-keyword and per-image tags.
    }
};
