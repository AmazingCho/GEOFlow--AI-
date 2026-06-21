<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PromptPresetSeeder extends Seeder
{
    public function run(): void
    {
        if (! Schema::hasTable('prompts')) {
            return;
        }

        $now = now();

        foreach ($this->presets() as $preset) {
            $name = trim((string) ($preset['name'] ?? ''));
            $type = trim((string) ($preset['type'] ?? ''));

            if ($name === '' || $type === '') {
                continue;
            }

            $payload = [
                'name' => $name,
                'type' => $type,
                'content' => (string) ($preset['content'] ?? ''),
                'variables' => (string) ($preset['variables'] ?? ''),
                'updated_at' => $now,
            ];

            $existing = DB::table('prompts')
                ->where('name', $name)
                ->where('type', $type)
                ->first();

            if ($existing) {
                DB::table('prompts')->where('id', $existing->id)->update($payload);

                continue;
            }

            $legacyNames = array_values(array_filter(array_map('strval', $preset['legacy_names'] ?? [])));
            $legacy = $legacyNames === []
                ? null
                : DB::table('prompts')
                    ->where('type', $type)
                    ->whereIn('name', $legacyNames)
                    ->first();

            if ($legacy) {
                DB::table('prompts')->where('id', $legacy->id)->update($payload);

                continue;
            }

            DB::table('prompts')->insert($payload + ['created_at' => $now]);
        }
    }

    /**
     * @return list<array{name:string,type:string,content:string,variables:string,legacy_names?:list<string>}>
     */
    private function presets(): array
    {
        $path = database_path('seeders/data/prompt_presets.php');

        if (! is_file($path)) {
            return [];
        }

        $presets = require $path;

        return is_array($presets) ? $presets : [];
    }
}
