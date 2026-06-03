<?php

use App\Support\GeoFlow\ControlledTagGroups;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('controlled_tag_groups')) {
            return;
        }

        foreach (ControlledTagGroups::DEFAULTS as $index => $name) {
            DB::table('controlled_tag_groups')->updateOrInsert(
                ['name' => $name],
                [
                    'sort_order' => ($index + 1) * 10,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }

        ControlledTagGroups::flush();
    }

    public function down(): void
    {
        ControlledTagGroups::flush();
    }
};
