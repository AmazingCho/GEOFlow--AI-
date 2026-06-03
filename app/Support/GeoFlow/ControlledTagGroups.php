<?php

namespace App\Support\GeoFlow;

use App\Models\ControlledTagGroup;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

class ControlledTagGroups
{
    public const DEFAULTS = [
        'Topic',
        'Audience',
        'Intent',
    ];

    /**
     * @return list<string>
     */
    public static function names(): array
    {
        if (! Schema::hasTable('controlled_tag_groups')) {
            return self::DEFAULTS;
        }

        return Cache::remember('geoflow.controlled_tag_groups', now()->addMinutes(5), function (): array {
            $names = ControlledTagGroup::query()
                ->orderBy('sort_order')
                ->orderBy('name')
                ->pluck('name')
                ->map(static fn ($name): string => trim((string) $name))
                ->filter(static fn (string $name): bool => $name !== '')
                ->values()
                ->all();

            return $names !== [] ? $names : self::DEFAULTS;
        });
    }

    public static function flush(): void
    {
        Cache::forget('geoflow.controlled_tag_groups');
    }
}
