<?php

namespace App\Services\GeoFlow;

use App\Models\Prompt;
use App\Models\Title;
use App\Models\TitleLibrary;

class SkillPromptRecommendationService
{
    public const AUTO_VALUE = '__auto';

    private const MAX_TITLES_PER_LIBRARY = 60;

    /**
     * @param  iterable<int, Prompt>|null  $skillPrompts
     * @return array{skill_prompt_id:int,skill_prompt_name:string,intent:string,confidence:int,sample_titles:list<string>}|null
     */
    public function recommendForTitleLibrary(int $libraryId, ?iterable $skillPrompts = null): ?array
    {
        if ($libraryId <= 0) {
            return null;
        }

        $libraryName = (string) (TitleLibrary::query()->whereKey($libraryId)->value('name') ?? '');
        $titleRows = Title::query()
            ->where('library_id', $libraryId)
            ->orderByDesc('id')
            ->limit(self::MAX_TITLES_PER_LIBRARY)
            ->get(['title', 'keyword']);

        return $this->buildRecommendation(
            $libraryName,
            $this->titleSamples($titleRows),
            $this->normalizeSkillPrompts($skillPrompts)
        );
    }

    /**
     * @param  list<int>  $libraryIds
     * @param  iterable<int, Prompt>|null  $skillPrompts
     * @return array<int, array{skill_prompt_id:int,skill_prompt_name:string,intent:string,confidence:int,sample_titles:list<string>}>
     */
    public function recommendForTitleLibraries(array $libraryIds, ?iterable $skillPrompts = null): array
    {
        $libraryIds = collect($libraryIds)
            ->map(static fn (mixed $id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();
        if ($libraryIds === []) {
            return [];
        }

        $libraries = TitleLibrary::query()
            ->whereIn('id', $libraryIds)
            ->pluck('name', 'id')
            ->mapWithKeys(static fn (string $name, int|string $id): array => [(int) $id => $name])
            ->all();
        $titlesByLibrary = Title::query()
            ->whereIn('library_id', $libraryIds)
            ->orderByDesc('id')
            ->limit(max(200, count($libraryIds) * self::MAX_TITLES_PER_LIBRARY))
            ->get(['library_id', 'title', 'keyword'])
            ->groupBy(static fn (Title $title): int => (int) $title->library_id);
        $skills = $this->normalizeSkillPrompts($skillPrompts);
        $recommendations = [];

        foreach ($libraryIds as $libraryId) {
            $recommendation = $this->buildRecommendation(
                (string) ($libraries[$libraryId] ?? ''),
                $this->titleSamples(($titlesByLibrary->get($libraryId) ?? collect())->take(self::MAX_TITLES_PER_LIBRARY)),
                $skills
            );
            if ($recommendation !== null) {
                $recommendations[$libraryId] = $recommendation;
            }
        }

        return $recommendations;
    }

    /**
     * @param  iterable<int, Prompt>|null  $skillPrompts
     * @return list<array{id:int,name:string,content:string}>
     */
    private function normalizeSkillPrompts(?iterable $skillPrompts): array
    {
        if ($skillPrompts === null) {
            $skillPrompts = Prompt::query()
                ->where('type', 'skill')
                ->orderBy('name')
                ->get(['id', 'name', 'content']);
        }

        return collect($skillPrompts)
            ->map(static fn (Prompt $prompt): array => [
                'id' => (int) $prompt->id,
                'name' => (string) $prompt->name,
                'content' => (string) $prompt->content,
            ])
            ->filter(static fn (array $prompt): bool => $prompt['id'] > 0 && $prompt['name'] !== '')
            ->values()
            ->all();
    }

    /**
     * @param  iterable<int, Title>  $titles
     * @return list<array{text:string,title:string}>
     */
    private function titleSamples(iterable $titles): array
    {
        return collect($titles)
            ->map(static fn (Title $title): array => [
                'text' => trim((string) $title->title.' '.(string) $title->keyword),
                'title' => trim((string) $title->title),
            ])
            ->filter(static fn (array $sample): bool => $sample['text'] !== '')
            ->values()
            ->all();
    }

    /**
     * @param  list<array{text:string,title:string}>  $titleSamples
     * @param  list<array{id:int,name:string,content:string}>  $skills
     * @return array{skill_prompt_id:int,skill_prompt_name:string,intent:string,confidence:int,sample_titles:list<string>}|null
     */
    private function buildRecommendation(string $libraryName, array $titleSamples, array $skills): ?array
    {
        if ($skills === []) {
            return null;
        }

        $scoredIntent = $this->scoreIntent($libraryName, $titleSamples);
        if ($scoredIntent === null) {
            return null;
        }

        $skill = $this->matchSkillPrompt($scoredIntent['intent'], $skills);
        if ($skill === null) {
            return null;
        }

        return [
            'skill_prompt_id' => $skill['id'],
            'skill_prompt_name' => $skill['name'],
            'intent' => $scoredIntent['intent'],
            'confidence' => $scoredIntent['confidence'],
            'sample_titles' => $scoredIntent['sample_titles'],
        ];
    }

    /**
     * @param  list<array{text:string,title:string}>  $titleSamples
     * @return array{intent:string,confidence:int,sample_titles:list<string>}|null
     */
    private function scoreIntent(string $libraryName, array $titleSamples): ?array
    {
        $scores = [
            'comparison' => 0,
            'buying_guide' => 0,
            'application' => 0,
        ];
        $samples = [
            'comparison' => [],
            'buying_guide' => [],
            'application' => [],
        ];

        $allSamples = $titleSamples;
        if (trim($libraryName) !== '') {
            array_unshift($allSamples, ['text' => $libraryName, 'title' => $libraryName]);
        }

        foreach ($allSamples as $sample) {
            $text = $this->normalizeText((string) $sample['text']);
            foreach ($this->intentPatterns() as $intent => $patterns) {
                foreach ($patterns as $pattern => $weight) {
                    if (preg_match($pattern, $text) === 1) {
                        $scores[$intent] += $weight;
                        if (count($samples[$intent]) < 3 && trim((string) $sample['title']) !== '') {
                            $samples[$intent][] = trim((string) $sample['title']);
                        }
                    }
                }
            }
        }

        arsort($scores);
        $intent = (string) array_key_first($scores);
        $score = (int) ($scores[$intent] ?? 0);
        if ($score < 2) {
            return null;
        }

        return [
            'intent' => $intent,
            'confidence' => min(95, 50 + ($score * 9)),
            'sample_titles' => array_values(array_unique($samples[$intent])),
        ];
    }

    /**
     * @return array<string, array<string, int>>
     */
    private function intentPatterns(): array
    {
        return [
            'comparison' => [
                '/\bvs\.?\b/u' => 5,
                '/\bversus\b/u' => 5,
                '/\bcompare|comparison|compared\b/u' => 4,
                '/\bdifference|differences|different\b/u' => 4,
                '/\balternative|alternatives\b/u' => 3,
                '/\bpros\s+and\s+cons\b/u' => 3,
                '/\bwhich\s+is\s+better\b/u' => 3,
                '/对比|比较|区别|差异|哪个好|替代/u' => 4,
            ],
            'buying_guide' => [
                '/\bhow\s+to\s+(choose|select|size|buy)\b/u' => 5,
                '/\bselection\s+guide|buying\s+guide|buyer\s+guide\b/u' => 5,
                '/\bwhat\s+size\b/u' => 4,
                '/\bsizing|configuration|specifications?\b/u' => 3,
                '/\bchoose|selecting|selection\b/u' => 2,
                '/如何选择|怎么选|选型|购买指南|尺寸|规格|配置/u' => 4,
            ],
            'application' => [
                '/\bapplication|applications|use\s+case|use\s+cases\b/u' => 5,
                '/\bfor\s+[a-z0-9][a-z0-9\s\\-]{3,}\b/u' => 3,
                '/\bindustry|industrial|manufacturing|process\b/u' => 3,
                '/\bbattery|semiconductor|wafer|automotive|electronics|packaging\b/u' => 2,
                '/应用|场景|行业|用于|制造|工艺|案例/u' => 4,
            ],
        ];
    }

    /**
     * @param  list<array{id:int,name:string,content:string}>  $skills
     * @return array{id:int,name:string}|null
     */
    private function matchSkillPrompt(string $intent, array $skills): ?array
    {
        $aliases = [
            'comparison' => ['comparison', 'compare', 'vs', 'difference', '对比', '比较'],
            'buying_guide' => ['buying', 'buyer', 'selection', 'choose', 'sizing', '选型', '购买'],
            'application' => ['application', 'use case', 'scenario', 'industry', '应用', '场景'],
        ];
        $terms = $aliases[$intent] ?? [];
        $best = null;
        $bestScore = 0;

        foreach ($skills as $skill) {
            $name = $this->normalizeText($skill['name']);
            $content = $this->normalizeText($skill['content']);
            $score = 0;
            foreach ($terms as $term) {
                $term = $this->normalizeText($term);
                if ($term !== '' && str_contains($name, $term)) {
                    $score += 4;
                }
                if ($term !== '' && str_contains($content, $term)) {
                    $score += 1;
                }
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $best = ['id' => (int) $skill['id'], 'name' => (string) $skill['name']];
            }
        }

        return $bestScore > 0 ? $best : null;
    }

    private function normalizeText(string $text): string
    {
        return mb_strtolower(trim($text), 'UTF-8');
    }
}
