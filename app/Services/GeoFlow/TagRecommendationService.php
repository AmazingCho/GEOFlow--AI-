<?php

namespace App\Services\GeoFlow;

use App\Models\Tag;
use Illuminate\Support\Facades\Cache;

class TagRecommendationService
{
    /**
     * @param  list<int>  $selectedTagIds
     * @return list<array{id:int,label:string,score:float,reason:string}>
     */
    public function recommendForText(string $text, array $selectedTagIds = [], int $limit = 6): array
    {
        $normalizedText = $this->normalizeText($text);
        if ($normalizedText === '') {
            return [];
        }

        $selectedLookup = array_fill_keys(array_map('intval', $selectedTagIds), true);
        $textTokens = $this->tokens($normalizedText);

        $recommendations = [];
        foreach ($this->candidateMaterialTags($normalizedText, $textTokens) as $tag) {
            $tagId = (int) $tag->id;
            if ($tagId <= 0 || isset($selectedLookup[$tagId])) {
                continue;
            }

            $displayName = $tag->displayName();
            $name = trim((string) $tag->name);
            $groupName = trim((string) ($tag->group_name ?? ''));
            $score = 0.0;
            $reasons = [];

            if ($displayName !== '' && str_contains($normalizedText, $this->normalizeText($displayName))) {
                $score += 120.0;
                $reasons[] = 'full';
            }
            if ($name !== '' && str_contains($normalizedText, $this->normalizeText($name))) {
                $score += 100.0;
                $reasons[] = 'name';
            }
            if ($groupName !== '' && str_contains($normalizedText, $this->normalizeText($groupName))) {
                $score += 25.0;
                $reasons[] = 'group';
            }

            $tagTokens = $this->tokens($displayName.' '.$name.' '.$groupName);
            if ($textTokens !== [] && $tagTokens !== []) {
                $overlap = count(array_intersect(array_keys($textTokens), array_keys($tagTokens)));
                if ($overlap > 0) {
                    $score += min(60.0, $overlap * 20.0);
                    $reasons[] = 'terms';
                }
            }

            $usage = (int) ($tag->keywords_count ?? 0)
                + (int) ($tag->images_count ?? 0)
                + (int) ($tag->knowledge_bases_count ?? 0)
                + (int) ($tag->entities_count ?? 0)
                + (int) ($tag->case_records_count ?? 0);
            if ($score > 0.0 && $usage > 0) {
                $score += min(15.0, log($usage + 1) * 5.0);
            }

            if ($score <= 0.0) {
                continue;
            }

            $recommendations[] = [
                'id' => $tagId,
                'label' => $displayName,
                'score' => round($score, 6),
                'reason' => implode(',', array_values(array_unique($reasons))),
            ];
        }

        usort($recommendations, static function (array $left, array $right): int {
            $scoreDiff = ((float) $right['score']) <=> ((float) $left['score']);

            return $scoreDiff !== 0 ? $scoreDiff : strcmp((string) $left['label'], (string) $right['label']);
        });

        return array_slice($recommendations, 0, max(0, $limit));
    }

    /**
     * @param  array<int|string,string>  $items
     * @param  array<int|string,list<int>>  $selectedTagIdsByItem
     * @return array<int|string,list<array{id:int,label:string,score:float,reason:string}>>
     */
    public function recommendForItems(array $items, array $selectedTagIdsByItem = [], int $limit = 4): array
    {
        $recommendations = [];
        foreach ($items as $key => $text) {
            $recommendations[$key] = $this->recommendForText(
                (string) $text,
                $selectedTagIdsByItem[$key] ?? [],
                $limit
            );
        }

        return $recommendations;
    }

    /**
     * @return list<Tag>
     */
    private function materialTags(): array
    {
        return Cache::remember('tag_recommendations:material_tags', now()->addMinutes(10), fn (): array => Tag::query()
            ->where('type', 'material')
            ->withCount(['keywords', 'images', 'knowledgeBases', 'entities', 'caseRecords'])
            ->orderBy('group_name')
            ->orderBy('name')
            ->get()
            ->all());
    }

    /**
     * @param  array<string,int>  $textTokens
     * @return list<Tag>
     */
    private function candidateMaterialTags(string $normalizedText, array $textTokens): array
    {
        $tags = $this->materialTags();
        $candidates = [];
        foreach ($tags as $tag) {
            $displayName = $this->normalizeText($tag->displayName());
            $name = $this->normalizeText((string) $tag->name);
            $groupName = $this->normalizeText((string) ($tag->group_name ?? ''));
            if (($displayName !== '' && str_contains($normalizedText, $displayName))
                || ($name !== '' && str_contains($normalizedText, $name))
                || ($groupName !== '' && str_contains($normalizedText, $groupName))) {
                $candidates[(int) $tag->id] = $tag;
                continue;
            }

            $tagTokens = $this->tokens($displayName.' '.$name.' '.$groupName);
            if ($textTokens !== [] && $tagTokens !== [] && array_intersect_key($textTokens, $tagTokens) !== []) {
                $candidates[(int) $tag->id] = $tag;
            }
        }

        return array_values($candidates);
    }

    private function normalizeText(string $text): string
    {
        $text = mb_strtolower(trim($text), 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }

    /**
     * @return array<string,int>
     */
    private function tokens(string $text): array
    {
        $parts = preg_split('/[^\p{L}\p{N}_]+/u', $this->normalizeText($text)) ?: [];
        $tokens = [];
        foreach ($parts as $part) {
            $part = trim((string) $part);
            if ($part === '' || mb_strlen($part, 'UTF-8') <= 1) {
                continue;
            }
            $tokens[$part] = 1;
        }

        return $tokens;
    }
}
