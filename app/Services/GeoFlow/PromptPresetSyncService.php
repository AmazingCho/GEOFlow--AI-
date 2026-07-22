<?php

namespace App\Services\GeoFlow;

use App\Models\Prompt;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class PromptPresetSyncService
{
    public function __construct(private readonly PromptPresetCatalog $catalog) {}

    /**
     * @param  array<string, 'keep-local'|'use-preset'>  $resolutions
     * @param  list<string>  $presetKeys
     * @return array<string, mixed>
     */
    public function preview(array $resolutions = [], array $presetKeys = []): array
    {
        $actions = [];
        $trustedHashes = collect($this->catalog->active())
            ->mapWithKeys(static fn (array $preset): array => [
                $preset['preset_key'] => array_values(array_unique([
                    $preset['content_hash'],
                    ...array_map('strval', $preset['legacy_content_hashes'] ?? []),
                ])),
            ]);

        $candidates = $this->catalog->candidate();
        if ($presetKeys !== []) {
            $knownKeys = array_column($candidates, 'preset_key');
            $unknownKeys = array_values(array_diff($presetKeys, $knownKeys));
            if ($unknownKeys !== []) {
                throw new RuntimeException('Unknown preset key(s): '.implode(', ', $unknownKeys));
            }
            $candidates = array_values(array_filter(
                $candidates,
                static fn (array $preset): bool => in_array($preset['preset_key'], $presetKeys, true)
            ));
        }

        $candidateKeys = array_column($candidates, 'preset_key');
        $unknownResolutionKeys = array_values(array_diff(array_keys($resolutions), $candidateKeys));
        if ($unknownResolutionKeys !== []) {
            throw new RuntimeException('Resolution does not belong to the selected preset scope: '.implode(', ', $unknownResolutionKeys));
        }

        foreach ($candidates as $preset) {
            $baseAction = $this->planPreset($preset, $trustedHashes->get($preset['preset_key'], []), []);
            if (isset($resolutions[$preset['preset_key']])) {
                if ($baseAction['status'] !== 'conflict') {
                    throw new RuntimeException('Resolution is only valid for a conflict: '.$preset['preset_key']);
                }
                $baseAction = $this->planPreset($preset, $trustedHashes->get($preset['preset_key'], []), $resolutions);
            }
            $actions[] = $baseAction;
        }

        $unresolved = array_values(array_map(
            static fn (array $action): string => $action['preset_key'],
            array_filter($actions, static fn (array $action): bool => $action['status'] === 'conflict')
        ));

        $fingerprintSource = array_map(
            static fn (array $action): array => [
                'preset_key' => $action['preset_key'],
                'status' => $action['status'],
                'existing_id' => $action['existing_id'],
                'current_hash' => $action['current_hash'],
                'desired_hash' => $action['desired_hash'],
                'name' => $action['name'],
                'type' => $action['type'],
                'current_intent_key' => $action['current_intent_key'],
                'desired_intent_key' => $action['desired_intent_key'],
                'from_version' => $action['from_version'],
                'to_version' => $action['to_version'],
                'resolution' => $action['resolution'],
            ],
            $actions
        );

        return [
            'applied' => false,
            'plan_fingerprint' => hash('sha256', json_encode($fingerprintSource, JSON_THROW_ON_ERROR)),
            'backup_path' => null,
            'unresolved_conflicts' => $unresolved,
            'actions' => $actions,
        ];
    }

    /**
     * @param  array<string, 'keep-local'|'use-preset'>  $resolutions
     * @param  list<string>  $presetKeys
     * @return array<string, mixed>
     */
    public function apply(string $expectedFingerprint, array $resolutions = [], array $presetKeys = []): array
    {
        if ($expectedFingerprint === '') {
            throw new RuntimeException('Apply requires --expect-plan from a reviewed dry-run.');
        }

        return Cache::lock('geoflow:prompt-preset-sync', 60)->block(10, function () use ($expectedFingerprint, $resolutions, $presetKeys): array {
            return DB::transaction(function () use ($expectedFingerprint, $resolutions, $presetKeys): array {
                Prompt::query()->lockForUpdate()->get(['id']);
                $plan = $this->preview($resolutions, $presetKeys);

                if (! hash_equals((string) $plan['plan_fingerprint'], $expectedFingerprint)) {
                    throw new RuntimeException('Prompt preset plan changed after preview. Run dry-run again.');
                }
                if ($plan['unresolved_conflicts'] !== []) {
                    throw new RuntimeException('Unresolved prompt preset conflicts block the entire apply.');
                }

                $backupPath = $this->writeBackup($plan);
                $candidateByKey = collect($this->catalog->candidate())->keyBy('preset_key');

                foreach ($plan['actions'] as $action) {
                    if (! in_array($action['status'], ['create', 'rename', 'update'], true)) {
                        continue;
                    }

                    $preset = $candidateByKey->get($action['preset_key']);
                    if (! is_array($preset)) {
                        throw new RuntimeException('Preset disappeared during apply: '.$action['preset_key']);
                    }

                    $payload = [
                        'name' => $preset['name'],
                        'type' => $preset['type'],
                        'intent_key' => $preset['intent_key'] ?? null,
                        'content' => $preset['content'],
                        'variables' => $preset['variables'],
                        'preset_key' => $preset['preset_key'],
                        'preset_version' => $preset['preset_version'],
                        'last_synced_hash' => $preset['content_hash'],
                        'is_system' => true,
                        'updated_at' => now(),
                    ];

                    if ($action['status'] === 'create') {
                        Prompt::query()->create($payload + ['is_enabled' => true]);
                    } else {
                        Prompt::query()->whereKey($action['existing_id'])->update($payload);
                    }
                }

                return array_merge($plan, [
                    'applied' => true,
                    'backup_path' => $backupPath,
                ]);
            });
        });
    }

    /**
     * @param  array<string, mixed>  $preset
     * @param  list<string>  $trustedHashes
     * @param  array<string, 'keep-local'|'use-preset'>  $resolutions
     * @return array<string, mixed>
     */
    private function planPreset(array $preset, array $trustedHashes, array $resolutions): array
    {
        $key = (string) $preset['preset_key'];
        $resolution = $resolutions[$key] ?? null;
        $matches = Prompt::query()->where('preset_key', $key)->get();

        if ($matches->isEmpty()) {
            $names = array_values(array_unique(array_filter([
                (string) $preset['name'],
                ...array_map('strval', $preset['legacy_names'] ?? []),
            ])));
            $matches = Prompt::query()
                ->where('type', (string) $preset['type'])
                ->whereIn('name', $names)
                ->get();
        }

        if ($matches->count() > 1) {
            return $this->action($preset, 'conflict', null, null, $resolution, 'Multiple legacy matches require manual cleanup.');
        }

        /** @var Prompt|null $existing */
        $existing = $matches->first();
        if (! $existing) {
            return $this->action($preset, 'create', null, null, $resolution, 'Packaged preset is missing.');
        }

        $currentHash = PromptPresetCatalog::contentHash((string) $existing->content, (string) ($existing->variables ?? ''));
        $lastSyncedHash = trim((string) ($existing->last_synced_hash ?? ''));
        $desiredIntentKey = $preset['intent_key'] ?? null;
        $intentConflict = $existing->intent_key !== $desiredIntentKey;
        $isDowngrade = trim((string) ($existing->preset_version ?? '')) !== ''
            && version_compare((string) $existing->preset_version, (string) $preset['preset_version'], '>');
        if ($isDowngrade) {
            if ($resolution === 'keep-local') {
                return $this->action($preset, 'skip', $existing, $currentHash, $resolution, 'Administrator kept the newer local preset version.');
            }

            return $this->action($preset, 'conflict', $existing, $currentHash, $resolution, 'Preset downgrade is blocked. Keep the newer local version.');
        }
        $isConflict = $existing->type !== $preset['type']
            || $intentConflict
            || ($lastSyncedHash !== '' && ! hash_equals($lastSyncedHash, $currentHash))
            || ($lastSyncedHash === ''
                && ! in_array($currentHash, array_values(array_unique([...$trustedHashes, $preset['content_hash']])), true));

        if ($isConflict) {
            if ($resolution === 'keep-local') {
                return $this->action($preset, 'skip', $existing, $currentHash, $resolution, 'Administrator chose to keep the local version.');
            }
            if ($resolution !== 'use-preset') {
                return $this->action(
                    $preset,
                    'conflict',
                    $existing,
                    $currentHash,
                    $resolution,
                    $intentConflict
                        ? 'Local intent metadata differs from the packaged preset.'
                        : 'Local content differs from the last trusted preset.'
                );
            }
        }

        $sameName = $existing->name === $preset['name'];
        $samePayload = $sameName
            && $existing->type === $preset['type']
            && $existing->intent_key === ($preset['intent_key'] ?? null)
            && hash_equals($currentHash, (string) $preset['content_hash'])
            && $existing->preset_key === $key
            && (string) $existing->preset_version === (string) $preset['preset_version']
            && (string) $existing->last_synced_hash === (string) $preset['content_hash']
            && (bool) $existing->is_system;

        if ($samePayload) {
            return $this->action($preset, 'unchanged', $existing, $currentHash, $resolution, 'Already synchronized.');
        }

        return $this->action(
            $preset,
            $sameName ? 'update' : 'rename',
            $existing,
            $currentHash,
            $resolution,
            $sameName ? 'A safe packaged update is available.' : 'A trusted legacy preset will be renamed in place.'
        );
    }

    /** @return array<string, mixed> */
    private function action(
        array $preset,
        string $status,
        ?Prompt $existing,
        ?string $currentHash,
        ?string $resolution,
        string $reason
    ): array {
        return [
            'preset_key' => $preset['preset_key'],
            'name' => $preset['name'],
            'type' => $preset['type'],
            'status' => $status,
            'reason' => $reason,
            'existing_id' => $existing?->id,
            'current_name' => $existing?->name,
            'current_hash' => $currentHash,
            'desired_hash' => $preset['content_hash'],
            'current_intent_key' => $existing?->intent_key,
            'desired_intent_key' => $preset['intent_key'] ?? null,
            'from_version' => $existing?->preset_version,
            'to_version' => $preset['preset_version'],
            'resolution' => $resolution,
        ];
    }

    /** @param array<string, mixed> $plan */
    private function writeBackup(array $plan): string
    {
        $path = 'prompt-preset-backups/'.now()->format('Ymd-His').'-'.bin2hex(random_bytes(4));
        $disk = Storage::disk('local');
        $options = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;
        if (! $disk->makeDirectory($path)) {
            throw new RuntimeException('Unable to create the private prompt preset backup directory.');
        }
        @chmod($disk->path($path), 0700);

        $snapshotFiles = [
            'prompts.json' => Prompt::query()->orderBy('id')->get()->toArray(),
            'task-prompt-mappings.json' => DB::table('tasks')
                ->orderBy('id')
                ->get(['id', 'prompt_id', 'skill_prompt_id', 'style_prompt_id'])
                ->map(static fn (object $row): array => (array) $row)
                ->all(),
            'title-library-prompt-mappings.json' => DB::table('title_libraries')
                ->orderBy('id')
                ->get(['id', 'prompt_id'])
                ->map(static fn (object $row): array => (array) $row)
                ->all(),
        ];

        $encodedFiles = [];
        foreach ($snapshotFiles as $filename => $data) {
            $encodedFiles[$filename] = json_encode($data, $options);
        }
        $encodedFiles['manifest.json'] = json_encode([
            'status' => 'complete',
            'created_at' => now()->toIso8601String(),
            'plan_fingerprint' => $plan['plan_fingerprint'],
            'files' => array_map(
                static fn (string $contents): string => hash('sha256', $contents),
                $encodedFiles
            ),
            'actions' => $plan['actions'],
        ], $options);

        foreach ($encodedFiles as $filename => $contents) {
            if (! $disk->put($path.'/'.$filename, $contents)) {
                throw new RuntimeException('Unable to write prompt preset backup: '.$filename);
            }
            @chmod($disk->path($path.'/'.$filename), 0600);
        }

        return $path;
    }
}
