<?php

namespace App\Support\GeoFlow;

final class CrmContractContent
{
    private const MAX_BLOCK_LENGTH = 900;

    /**
     * @return array<int, array{kind: string, text: string, keep_with_next: int}>
     */
    public static function blocks(?string $content): array
    {
        $content = str_replace(["\r\n", "\r"], "\n", trim((string) $content));
        if ($content === '') {
            return [];
        }

        $lines = preg_split('/\n/u', $content) ?: [];
        $lines = array_values(array_filter(
            array_map(static fn (string $line): string => trim($line), $lines),
            static fn (string $line): bool => $line !== ''
        ));

        $blocks = [];

        foreach ($lines as $index => $line) {
            $kind = self::kind($line, $lines[$index + 1] ?? null);
            $chunks = self::splitOversizedText($line);

            foreach ($chunks as $chunkIndex => $chunk) {
                $chunkKind = $chunkIndex === 0 ? $kind : 'line';
                $blocks[] = [
                    'kind' => $chunkKind,
                    'text' => $chunk,
                    'keep_with_next' => $chunkIndex === 0
                        ? self::keepWithNext($chunkKind, $line, $lines, $index)
                        : 0,
                ];
            }
        }

        return $blocks;
    }

    private static function kind(string $line, ?string $nextLine): string
    {
        if (preg_match('/^\d+(?:\.\d+)*[.)]?\s+\S/u', $line) === 1) {
            return 'heading';
        }

        if (in_array(self::normalized($line), ['correspondent bank', 'correspondent banks'], true)) {
            return 'subheading';
        }

        $wordCount = count(preg_split('/\s+/u', trim($line)) ?: []);
        $nextLooksLikeField = $nextLine !== null && str_contains($nextLine, ':');
        if (
            ! str_contains($line, ':')
            && self::length($line) <= 80
            && $wordCount <= 8
            && $nextLooksLikeField
        ) {
            return 'subheading';
        }

        return 'line';
    }

    /**
     * @param array<int, string> $lines
     */
    private static function keepWithNext(string $kind, string $line, array $lines, int $index): int
    {
        $remaining = max(0, count($lines) - $index - 1);
        if ($kind === 'heading') {
            return min(2, $remaining);
        }

        if ($kind !== 'subheading') {
            return 0;
        }

        if (self::normalized($line) !== 'correspondent bank') {
            return min(1, $remaining);
        }

        $keep = 0;
        $limit = min(count($lines), $index + 6);
        for ($nextIndex = $index + 1; $nextIndex < $limit; $nextIndex++) {
            $nextLine = $lines[$nextIndex];
            if (
                preg_match('/^\d+(?:\.\d+)*[.)]?\s+\S/u', $nextLine) === 1
                || self::normalized($nextLine) === 'correspondent bank'
            ) {
                break;
            }
            $keep++;
        }

        return $keep;
    }

    /**
     * @return array<int, string>
     */
    private static function splitOversizedText(string $text): array
    {
        if (self::length($text) <= self::MAX_BLOCK_LENGTH) {
            return [$text];
        }

        $sentences = preg_split('/(?<=[.!?。！？;；])\s+/u', $text) ?: [$text];
        $chunks = [];
        $current = '';

        foreach ($sentences as $sentence) {
            $sentence = trim($sentence);
            if ($sentence === '') {
                continue;
            }

            if (self::length($sentence) > self::MAX_BLOCK_LENGTH) {
                if ($current !== '') {
                    $chunks[] = $current;
                    $current = '';
                }
                array_push($chunks, ...self::splitByWords($sentence));
                continue;
            }

            $candidate = $current === '' ? $sentence : $current.' '.$sentence;
            if (self::length($candidate) > self::MAX_BLOCK_LENGTH) {
                $chunks[] = $current;
                $current = $sentence;
            } else {
                $current = $candidate;
            }
        }

        if ($current !== '') {
            $chunks[] = $current;
        }

        return $chunks !== [] ? $chunks : self::splitByWords($text);
    }

    /**
     * @return array<int, string>
     */
    private static function splitByWords(string $text): array
    {
        $words = preg_split('/\s+/u', trim($text)) ?: [];
        $chunks = [];
        $current = '';

        foreach ($words as $word) {
            while (self::length($word) > self::MAX_BLOCK_LENGTH) {
                if ($current !== '') {
                    $chunks[] = $current;
                    $current = '';
                }
                $chunks[] = self::slice($word, 0, self::MAX_BLOCK_LENGTH);
                $word = self::slice($word, self::MAX_BLOCK_LENGTH);
            }

            $candidate = $current === '' ? $word : $current.' '.$word;
            if ($current !== '' && self::length($candidate) > self::MAX_BLOCK_LENGTH) {
                $chunks[] = $current;
                $current = $word;
            } else {
                $current = $candidate;
            }
        }

        if ($current !== '') {
            $chunks[] = $current;
        }

        return $chunks;
    }

    private static function length(string $text): int
    {
        return function_exists('mb_strlen') ? mb_strlen($text) : strlen($text);
    }

    private static function normalized(string $text): string
    {
        return function_exists('mb_strtolower')
            ? mb_strtolower(trim($text))
            : strtolower(trim($text));
    }

    private static function slice(string $text, int $start, ?int $length = null): string
    {
        if (function_exists('mb_substr')) {
            return $length === null ? mb_substr($text, $start) : mb_substr($text, $start, $length);
        }

        return $length === null ? substr($text, $start) : substr($text, $start, $length);
    }
}
