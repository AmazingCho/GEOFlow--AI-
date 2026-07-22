<?php

namespace App\Support\GeoFlow;

final class SkillSelectionModes
{
    public const NONE = 'none';

    public const MANUAL = 'manual';

    public const AUTO = 'auto';

    /** @return list<string> */
    public static function all(): array
    {
        return [self::NONE, self::MANUAL, self::AUTO];
    }

    public static function normalize(mixed $value): ?string
    {
        $mode = trim((string) $value);

        return in_array($mode, self::all(), true) ? $mode : null;
    }

    public static function fromLegacySkillId(mixed $skillPromptId): string
    {
        return (int) $skillPromptId > 0 ? self::MANUAL : self::NONE;
    }
}
