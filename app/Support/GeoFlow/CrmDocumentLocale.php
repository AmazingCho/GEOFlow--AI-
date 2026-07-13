<?php

namespace App\Support\GeoFlow;

use Carbon\Carbon;
use DateTimeInterface;
use Illuminate\Support\Facades\Lang;
use Throwable;

final class CrmDocumentLocale
{
    /** @var array<string, string> */
    private const LANGUAGE_OPTIONS = [
        'en' => 'English',
        'zh_CN' => '简体中文',
        'ru' => 'Русский',
        'es' => 'Español',
    ];

    /** @var array<string, string> */
    private const HTML_LANGUAGES = [
        'en' => 'en',
        'zh_CN' => 'zh-CN',
        'ru' => 'ru',
        'es' => 'es',
    ];

    /** @var array<int, string> */
    private const RUSSIAN_MONTHS = [
        1 => 'янв.',
        2 => 'февр.',
        3 => 'мар.',
        4 => 'апр.',
        5 => 'мая',
        6 => 'июн.',
        7 => 'июл.',
        8 => 'авг.',
        9 => 'сент.',
        10 => 'окт.',
        11 => 'нояб.',
        12 => 'дек.',
    ];

    /** @var array<int, string> */
    private const SPANISH_MONTHS = [
        1 => 'ene',
        2 => 'feb',
        3 => 'mar',
        4 => 'abr',
        5 => 'may',
        6 => 'jun',
        7 => 'jul',
        8 => 'ago',
        9 => 'sept',
        10 => 'oct',
        11 => 'nov',
        12 => 'dic',
    ];

    /** @var array<string, array<string, mixed>> */
    private static array $catalogCache = [];

    /** @return array<int, string> */
    public static function supported(): array
    {
        return array_keys(self::LANGUAGE_OPTIONS);
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return self::LANGUAGE_OPTIONS;
    }

    public static function resolve(?string $requested, ?string $stored): string
    {
        $requested = trim((string) $requested);
        if (array_key_exists($requested, self::LANGUAGE_OPTIONS)) {
            return $requested;
        }

        $stored = trim((string) $stored);
        if (array_key_exists($stored, self::LANGUAGE_OPTIONS)) {
            return $stored;
        }

        return 'en';
    }

    /** @return array<string, string> */
    public static function labels(string $language): array
    {
        $labels = self::catalog($language)['labels'] ?? [];

        return is_array($labels) ? $labels : [];
    }

    /** @return array<string, string> */
    public static function documentTitles(string $language): array
    {
        $titles = self::catalog($language)['document_titles'] ?? [];

        return is_array($titles) ? $titles : [];
    }

    /** @param array<string, string|int|float> $replace */
    public static function text(string $language, string $key, string $fallback = '', array $replace = []): string
    {
        $value = self::labels($language)[$key] ?? null;
        if (! is_string($value) || $value === '') {
            $value = self::labels('en')[$key] ?? $fallback;
        }
        if ((! is_string($value) || $value === '') && $fallback !== '') {
            $value = $fallback;
        }

        $replacements = [];
        foreach ($replace as $name => $replacement) {
            $replacements[':'.$name] = (string) $replacement;
        }

        return strtr((string) $value, $replacements);
    }

    public static function formatDate(mixed $date, string $language): string
    {
        if ($date === null || $date === '') {
            return '';
        }

        try {
            $carbon = $date instanceof DateTimeInterface
                ? Carbon::instance($date)
                : Carbon::parse($date);
        } catch (Throwable) {
            return '';
        }

        $language = self::resolve($language, 'en');

        return match ($language) {
            'zh_CN' => $carbon->format('Y年n月j日'),
            'ru' => $carbon->format('j').' '.self::RUSSIAN_MONTHS[(int) $carbon->format('n')].' '.$carbon->format('Y').' г.',
            'es' => $carbon->format('j').' '.self::SPANISH_MONTHS[(int) $carbon->format('n')].' '.$carbon->format('Y'),
            default => $carbon->format('M j, Y'),
        };
    }

    public static function htmlLang(string $language): string
    {
        $language = self::resolve($language, 'en');

        return self::HTML_LANGUAGES[$language];
    }

    /** @return array<string, mixed> */
    private static function catalog(string $language): array
    {
        $language = array_key_exists($language, self::LANGUAGE_OPTIONS) ? $language : 'en';
        if (array_key_exists($language, self::$catalogCache)) {
            return self::$catalogCache[$language];
        }

        $catalog = Lang::get('crm_document', [], $language);
        self::$catalogCache[$language] = is_array($catalog) ? $catalog : [];

        return self::$catalogCache[$language];
    }
}
