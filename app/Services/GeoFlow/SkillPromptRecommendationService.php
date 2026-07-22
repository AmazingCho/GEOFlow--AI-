<?php

namespace App\Services\GeoFlow;

use App\Models\Prompt;
use App\Models\Title;
use App\Models\TitleLibrary;
use App\Support\GeoFlow\ArticleSkillIntents;
use Illuminate\Support\Facades\DB;

class SkillPromptRecommendationService
{
    public const AUTO_VALUE = '__auto';

    private const MAX_TITLES_PER_LIBRARY = 60;

    private const MIN_SCORE = 5;

    /** @var list<string> */
    private const TIE_BREAK_ORDER = [
        ArticleSkillIntents::COMPARISON,
        ArticleSkillIntents::BUYING_GUIDE,
        ArticleSkillIntents::TROUBLESHOOTING,
        ArticleSkillIntents::CASE_STUDY,
        ArticleSkillIntents::TECHNICAL,
        ArticleSkillIntents::DEFINITION,
        ArticleSkillIntents::APPLICATION,
    ];

    /**
     * @param  iterable<int, Prompt>|null  $skillPrompts
     * @return array{skill_prompt_id:int|null,skill_prompt_name:string,intent:string,confidence:int,sample_titles:list<string>,status:string,auto_eligible:bool}|null
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
     * @return array<int, array{skill_prompt_id:int|null,skill_prompt_name:string,intent:string,confidence:int,sample_titles:list<string>,status:string,auto_eligible:bool}>
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
        $rankedTitles = Title::query()
            ->whereIn('library_id', $libraryIds)
            ->select(['library_id', 'title', 'keyword'])
            ->selectRaw('ROW_NUMBER() OVER (PARTITION BY library_id ORDER BY id DESC) AS sample_rank');
        $titlesByLibrary = DB::query()
            ->fromSub($rankedTitles, 'ranked_titles')
            ->where('sample_rank', '<=', self::MAX_TITLES_PER_LIBRARY)
            ->orderBy('library_id')
            ->orderBy('sample_rank')
            ->get()
            ->groupBy(static fn (object $title): int => (int) $title->library_id);
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
     * @return list<array{id:int,name:string,intent_key:?string,is_system:bool}>
     */
    private function normalizeSkillPrompts(?iterable $skillPrompts): array
    {
        if ($skillPrompts === null) {
            $skillPrompts = Prompt::query()
                ->where('type', 'skill')
                ->orderBy('name')
                ->get(['id', 'name', 'intent_key', 'is_system']);
        }

        return collect($skillPrompts)
            ->map(static fn (Prompt $prompt): array => [
                'id' => (int) $prompt->id,
                'name' => (string) $prompt->name,
                'intent_key' => ArticleSkillIntents::normalize($prompt->intent_key),
                'is_system' => (bool) $prompt->is_system,
            ])
            ->filter(static fn (array $prompt): bool => $prompt['id'] > 0 && $prompt['name'] !== '')
            ->values()
            ->all();
    }

    /**
     * @param  iterable<int, object>  $titles
     * @return list<array{text:string,title:string}>
     */
    private function titleSamples(iterable $titles): array
    {
        return collect($titles)
            ->map(static fn (object $title): array => [
                'text' => trim((string) $title->title.' '.(string) $title->keyword),
                'title' => trim((string) $title->title),
            ])
            ->filter(static fn (array $sample): bool => $sample['text'] !== '')
            ->values()
            ->all();
    }

    /**
     * @param  list<array{text:string,title:string}>  $titleSamples
     * @param  list<array{id:int,name:string,intent_key:?string,is_system:bool}>  $skills
     * @return array{skill_prompt_id:int|null,skill_prompt_name:string,intent:string,confidence:int,sample_titles:list<string>,status:string,auto_eligible:bool}|null
     */
    private function buildRecommendation(string $libraryName, array $titleSamples, array $skills): ?array
    {
        $scoredIntent = $this->scoreIntent($libraryName, $titleSamples);
        if ($scoredIntent === null) {
            return null;
        }

        $intent = $scoredIntent['intent'];
        $skill = $this->matchSkillPrompt($intent, $skills);
        $autoEligible = in_array($intent, ArticleSkillIntents::autoEligible(), true);
        $status = ! $autoEligible
            ? 'manual_only'
            : ($skill === null ? 'unconfigured' : 'recommended');

        return [
            'skill_prompt_id' => $status === 'recommended' ? $skill['id'] : null,
            'skill_prompt_name' => $skill['name'] ?? '',
            'intent' => $intent,
            'confidence' => $scoredIntent['confidence'],
            'sample_titles' => $scoredIntent['sample_titles'],
            'status' => $status,
            'auto_eligible' => $autoEligible,
        ];
    }

    /** @return array{intent:string,confidence:int,sample_titles:list<string>}|null */
    public function classifyTitle(string $title): ?array
    {
        $title = trim($title);
        if ($title === '') {
            return null;
        }

        return $this->scoreIntent('', [['text' => $title, 'title' => $title]]);
    }

    public function findSkillPromptForIntent(string $intent): ?Prompt
    {
        $intent = ArticleSkillIntents::normalize($intent);
        if ($intent === null) {
            return null;
        }

        return Prompt::query()
            ->where('type', 'skill')
            ->where('intent_key', $intent)
            ->orderByDesc('is_system')
            ->orderBy('id')
            ->first();
    }

    /**
     * @param  list<array{text:string,title:string}>  $titleSamples
     * @return array{intent:string,confidence:int,sample_titles:list<string>}|null
     */
    private function scoreIntent(string $libraryName, array $titleSamples): ?array
    {
        $scores = array_fill_keys(ArticleSkillIntents::all(), 0);
        $samples = array_fill_keys(ArticleSkillIntents::all(), []);

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

        $intent = self::TIE_BREAK_ORDER[0];
        foreach (self::TIE_BREAK_ORDER as $candidate) {
            if ($scores[$candidate] > $scores[$intent]) {
                $intent = $candidate;
            }
        }
        $score = (int) ($scores[$intent] ?? 0);
        if ($score < self::MIN_SCORE) {
            return null;
        }

        return [
            'intent' => $intent,
            'confidence' => min(95, 52 + ($score * 4)),
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
                '/\bvs\.?\b/u' => 8,
                '/\bversus\b/u' => 8,
                '/\b(?:compare|comparison|compared|difference|differences)\b/u' => 7,
                '/\b(?:alternative|alternatives|pros\s+and\s+cons|which\s+is\s+better)\b/u' => 5,
                '/对比|比较|区别|差异|哪个好/u' => 8,
            ],
            'buying_guide' => [
                '/\bhow\s+to\s+(?:choose|select|size|buy)\b/u' => 8,
                '/\b(?:selection|buying|buyer)\s+guide\b/u' => 8,
                '/\bwhat\s+size\b/u' => 7,
                '/\b(?:sizing|configuration|specifications?|shortlist)\b/u' => 5,
                '/如何选择|怎么选|选型|购买指南/u' => 8,
                '/尺寸|规格|配置/u' => 5,
            ],
            'application' => [
                '/\b(?:application|applications|use\s+case|use\s+cases)\b/u' => 8,
                '/\b(?:manufacturing|process)\s+(?:application|solution)\b/u' => 6,
                '/应用场景|行业应用|解决方案/u' => 8,
                '/在.{1,30}中的应用|用于.{1,30}(?:制造|工艺)/u' => 7,
            ],
            'technical' => [
                '/\bhow\s+(?:does|do)\b.{1,80}\bwork\b/u' => 8,
                '/\b(?:working\s+principle|operating\s+principle|mechanism|component\s+interaction)\b/u' => 8,
                '/工作原理|运行原理|如何工作|作用机制|内部结构/u' => 8,
            ],
            'troubleshooting' => [
                '/\b(?:troubleshooting|fault|error|alarm|clogging|clogged|not\s+working|how\s+to\s+fix|maintenance)\b/u' => 9,
                '/故障排查|故障|报警|堵塞|异常|无法工作|维修|维护/u' => 9,
            ],
            'case_study' => [
                '/\b(?:case\s+study|customer\s+story|success\s+story|project\s+(?:implementation|result))\b/u' => 9,
                '/客户案例|案例研究|成功案例|项目交付|实施结果/u' => 9,
            ],
            'definition' => [
                '/\b(?:what\s+is|definition|meaning|basics|beginner(?:\s+guide)?)\b/u' => 8,
                '/什么是|定义|含义|基础入门/u' => 8,
            ],
        ];
    }

    /**
     * @param  list<array{id:int,name:string,intent_key:?string,is_system:bool}>  $skills
     * @return array{id:int,name:string}|null
     */
    private function matchSkillPrompt(string $intent, array $skills): ?array
    {
        $matches = array_values(array_filter(
            $skills,
            static fn (array $skill): bool => ($skill['intent_key'] ?? null) === $intent
        ));
        usort($matches, static fn (array $left, array $right): int => [! $left['is_system'], $left['id']] <=> [! $right['is_system'], $right['id']]);
        $skill = $matches[0] ?? null;

        return $skill === null
            ? null
            : ['id' => (int) $skill['id'], 'name' => (string) $skill['name']];
    }

    private function normalizeText(string $text): string
    {
        return mb_strtolower(trim($text), 'UTF-8');
    }
}
