<?php

namespace App\Support\GeoFlow;

final class ArticleGenerationModes
{
    public const STANDARD = 'standard';

    public const DEEP = 'deep';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return [self::STANDARD, self::DEEP];
    }

    public static function normalize(mixed $value): ?string
    {
        $mode = trim((string) $value);

        return in_array($mode, self::values(), true) ? $mode : null;
    }
}
