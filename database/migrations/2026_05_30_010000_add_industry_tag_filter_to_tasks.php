<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tasks') && ! Schema::hasColumn('tasks', 'industry_tag_filter')) {
            Schema::table('tasks', function (Blueprint $table): void {
                $table->string('industry_tag_filter', 1000)->default('')->after('knowledge_tag_filter');
            });
        }

        if (! Schema::hasTable('tags')) {
            return;
        }

        foreach (['自动化设备', '制冷设备', '工业检测', '医疗器械', '新能源', '食品加工', '电子制造'] as $name) {
            $exists = DB::table('tags')
                ->where('type', 'material')
                ->where('group_name', '行业领域')
                ->where('name', $name)
                ->exists();
            if ($exists) {
                continue;
            }

            DB::table('tags')->insert([
                'type' => 'material',
                'group_name' => '行业领域',
                'name' => $name,
                'slug' => Str::slug('material 行业领域 '.$name) ?: 'tag-'.substr(sha1('material 行业领域 '.$name), 0, 16),
                'color' => '',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('tasks') || ! Schema::hasColumn('tasks', 'industry_tag_filter')) {
            return;
        }

        Schema::table('tasks', function (Blueprint $table): void {
            $table->dropColumn('industry_tag_filter');
        });
    }
};
