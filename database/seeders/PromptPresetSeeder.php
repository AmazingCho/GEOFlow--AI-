<?php

namespace Database\Seeders;

use App\Services\GeoFlow\PromptPresetCatalog;
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

        if (! Schema::hasColumn('prompts', 'preset_key')) {
            return;
        }
        if (Schema::hasTable('prompt_preset_installations')
            && DB::table('prompt_preset_installations')->where('catalog_key', 'active-v1')->exists()) {
            return;
        }

        $presets = $this->presets();
        if (! $this->isPristineDefaultInstallation($presets)) {
            return;
        }

        DB::transaction(function () use ($presets): void {
            foreach ($presets as $preset) {
                $this->installSafely($preset);
            }

            if (Schema::hasTable('prompt_preset_installations')) {
                DB::table('prompt_preset_installations')->updateOrInsert(
                    ['catalog_key' => 'active-v1'],
                    [
                        'installed_version' => '1.0.0',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                );
            }
        });
    }

    /** @param list<array<string, mixed>> $presets */
    private function isPristineDefaultInstallation(array $presets): bool
    {
        if (Schema::hasTable('tasks') && DB::table('tasks')->exists()) {
            return false;
        }
        if (Schema::hasTable('title_libraries')
            && DB::table('title_libraries')->whereNotNull('prompt_id')->exists()) {
            return false;
        }

        foreach (DB::table('prompts')->get() as $prompt) {
            $matches = array_values(array_filter($presets, static function (array $preset) use ($prompt): bool {
                $names = array_values(array_unique(array_filter([
                    (string) $preset['name'],
                    ...array_map('strval', $preset['legacy_names'] ?? []),
                ])));

                return (string) $prompt->type === (string) $preset['type']
                    && in_array((string) $prompt->name, $names, true);
            }));
            if (count($matches) !== 1) {
                return false;
            }

            $preset = $matches[0];
            $currentHash = PromptPresetCatalog::contentHash(
                (string) $prompt->content,
                (string) ($prompt->variables ?? '')
            );
            $trustedHashes = array_values(array_unique([
                (string) $preset['content_hash'],
                ...array_map('strval', $preset['legacy_content_hashes'] ?? []),
            ]));

            if (! in_array($currentHash, $trustedHashes, true)) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, mixed> $preset */
    private function installSafely(array $preset): void
    {
        $now = now();
        $key = (string) $preset['preset_key'];
        $desiredHash = (string) $preset['content_hash'];
        $existing = DB::table('prompts')->where('preset_key', $key)->first();

        if (! $existing) {
            $candidateNames = array_values(array_unique(array_filter([
                (string) $preset['name'],
                ...array_map('strval', $preset['legacy_names'] ?? []),
            ])));
            $matches = DB::table('prompts')
                ->where('type', (string) $preset['type'])
                ->whereIn('name', $candidateNames)
                ->get();

            if ($matches->count() > 1) {
                return;
            }
            $existing = $matches->first();
        }

        $payload = [
            'name' => (string) $preset['name'],
            'type' => (string) $preset['type'],
            'intent_key' => $preset['intent_key'] ?? null,
            'content' => (string) $preset['content'],
            'variables' => (string) $preset['variables'],
            'preset_key' => $key,
            'preset_version' => (string) $preset['preset_version'],
            'last_synced_hash' => $desiredHash,
            'is_system' => true,
        ];

        if (! $existing) {
            DB::table('prompts')->insert($payload + [
                'is_enabled' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return;
        }

        $currentHash = PromptPresetCatalog::contentHash(
            (string) $existing->content,
            (string) ($existing->variables ?? '')
        );
        $lastSyncedHash = trim((string) ($existing->last_synced_hash ?? ''));
        $existingVersion = trim((string) ($existing->preset_version ?? ''));

        if ($existingVersion !== '' && version_compare($existingVersion, (string) $preset['preset_version'], '>')) {
            return;
        }

        if ($lastSyncedHash !== '' && ! hash_equals($lastSyncedHash, $currentHash)) {
            return;
        }

        if ($lastSyncedHash !== '' && ($existing->intent_key ?? null) !== ($preset['intent_key'] ?? null)) {
            return;
        }

        $trustedHashes = array_values(array_unique([
            $desiredHash,
            ...array_map('strval', $preset['legacy_content_hashes'] ?? []),
        ]));
        if ($lastSyncedHash === '' && ! in_array($currentHash, $trustedHashes, true)) {
            return;
        }

        $changes = array_filter(
            $payload,
            static fn (mixed $value, string $column): bool => (string) ($existing->{$column} ?? '') !== (string) $value,
            ARRAY_FILTER_USE_BOTH
        );
        if ($changes === []) {
            return;
        }

        DB::table('prompts')->where('id', $existing->id)->update($changes + ['updated_at' => $now]);
    }

    /**
     * @return list<array{name:string,type:string,content:string,variables:string,legacy_names?:list<string>}>
     */
    private function presets(): array
    {
        return app(PromptPresetCatalog::class)->active();
    }
}
