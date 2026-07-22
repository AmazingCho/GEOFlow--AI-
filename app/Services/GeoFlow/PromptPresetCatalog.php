<?php

namespace App\Services\GeoFlow;

use App\Support\GeoFlow\ArticleSkillIntents;
use RuntimeException;

class PromptPresetCatalog
{
    /** @return list<array<string, mixed>> */
    public function active(): array
    {
        return $this->load(database_path('seeders/data/prompt_presets.php'));
    }

    /** @return list<array<string, mixed>> */
    public function candidate(): array
    {
        return $this->load(database_path('seeders/data/prompt_presets_v2.php'));
    }

    public static function contentHash(string $content, ?string $variables): string
    {
        return hash('sha256', $content."\0".($variables ?? ''));
    }

    /** @return list<array<string, mixed>> */
    private function load(string $path): array
    {
        if (! is_file($path)) {
            throw new RuntimeException(sprintf('Prompt preset catalog is missing: %s', $path));
        }

        $raw = require $path;
        if (! is_array($raw)) {
            throw new RuntimeException(sprintf('Prompt preset catalog must return an array: %s', $path));
        }
        if ($raw === []) {
            throw new RuntimeException(sprintf('Prompt preset catalog is empty: %s', $path));
        }

        $presets = [];
        $keys = [];
        foreach ($raw as $preset) {
            if (! is_array($preset)) {
                continue;
            }

            $name = trim((string) ($preset['name'] ?? ''));
            $type = trim((string) ($preset['type'] ?? ''));
            $key = trim((string) ($preset['preset_key'] ?? ''));
            $version = trim((string) ($preset['preset_version'] ?? ''));
            if ($name === '' || $type === '' || $key === '' || $version === '') {
                throw new RuntimeException(sprintf('Incomplete governed prompt preset in %s.', $path));
            }
            if (isset($keys[$key])) {
                throw new RuntimeException(sprintf('Duplicate prompt preset key "%s" in %s.', $key, $path));
            }
            $keys[$key] = true;

            $intentKey = ArticleSkillIntents::normalize($preset['intent_key'] ?? null);
            if ($type === 'skill' && $intentKey === null) {
                throw new RuntimeException(sprintf('Skill preset "%s" has no valid intent key in %s.', $key, $path));
            }
            if ($type !== 'skill' && array_key_exists('intent_key', $preset) && trim((string) $preset['intent_key']) !== '') {
                throw new RuntimeException(sprintf('Non-skill preset "%s" cannot define an intent key in %s.', $key, $path));
            }

            $content = (string) ($preset['content'] ?? '');
            $variables = (string) ($preset['variables'] ?? '');
            $presets[] = $preset + [
                'legacy_names' => [],
                'legacy_content_hashes' => [],
            ];
            $presets[array_key_last($presets)]['name'] = $name;
            $presets[array_key_last($presets)]['type'] = $type;
            $presets[array_key_last($presets)]['intent_key'] = $intentKey;
            $presets[array_key_last($presets)]['preset_key'] = $key;
            $presets[array_key_last($presets)]['preset_version'] = $version;
            $presets[array_key_last($presets)]['content'] = $content;
            $presets[array_key_last($presets)]['variables'] = $variables;
            $presets[array_key_last($presets)]['content_hash'] = self::contentHash($content, $variables);
        }

        return $presets;
    }
}
