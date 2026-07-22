<?php

namespace App\Support\GeoFlow;

final class ArticleSkillIntents
{
    public const COMPARISON = 'comparison';

    public const BUYING_GUIDE = 'buying_guide';

    public const APPLICATION = 'application';

    public const TECHNICAL = 'technical';

    public const TROUBLESHOOTING = 'troubleshooting';

    public const CASE_STUDY = 'case_study';

    public const DEFINITION = 'definition';

    /** @return list<string> */
    public static function all(): array
    {
        return [
            self::COMPARISON,
            self::BUYING_GUIDE,
            self::APPLICATION,
            self::TECHNICAL,
            self::TROUBLESHOOTING,
            self::CASE_STUDY,
            self::DEFINITION,
        ];
    }

    /** @return list<string> */
    public static function autoEligible(): array
    {
        return [
            self::COMPARISON,
            self::BUYING_GUIDE,
            self::APPLICATION,
            self::TECHNICAL,
            self::DEFINITION,
        ];
    }

    public static function normalize(mixed $value): ?string
    {
        $intent = trim((string) $value);

        return in_array($intent, self::all(), true) ? $intent : null;
    }
}
