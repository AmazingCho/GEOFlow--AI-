<?php

use App\Support\GeoFlow\ControlledTagGroups;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('controlled_tag_groups')) {
            Schema::create('controlled_tag_groups', function (Blueprint $table): void {
                $table->id();
                $table->string('name', 100)->unique();
                $table->unsignedInteger('sort_order')->default(0)->index();
                $table->timestamps();
            });
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
    }

    public function down(): void
    {
        Schema::dropIfExists('controlled_tag_groups');
    }
};
