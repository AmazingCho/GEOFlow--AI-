<?php

namespace App\Services\GeoFlow;

use App\Models\CaseRecord;
use App\Models\EntityRecord;
use App\Models\UrlImportJob;
use Illuminate\Support\Str;

class EntityExtractionService
{
    public function __construct(
        private readonly TagRecommendationService $tagRecommendationService,
        private readonly TagService $tagService
    ) {}

    /**
     * @param  array<string,mixed>  $analysis
     * @param  array<string,mixed>  $page
     * @return array{entities:list<array<string,mixed>>,cases:list<array<string,mixed>>}
     */
    public function extractFromUrlImport(array $analysis, array $page, UrlImportJob $job): array
    {
        $cleaned = is_array($analysis['cleaned'] ?? null) ? $analysis['cleaned'] : [];
        $coreBusiness = is_array($cleaned['core_business'] ?? null) ? $cleaned['core_business'] : [];
        $facts = $this->stringList($cleaned['facts'] ?? []);
        $entityNames = $this->entityNames($cleaned['entities'] ?? [], $analysis['keywords'] ?? [], $coreBusiness);
        $summary = $this->normalize((string) ($analysis['summary'] ?? $cleaned['summary'] ?? $page['summary'] ?? ''));
        $sourceUrl = (string) ($job->normalized_url ?: $job->url);

        $entities = [];
        foreach ($entityNames as $name) {
            $entityText = implode("\n", array_filter([$name, $summary, implode("\n", $facts)]));
            $entities[] = [
                'name' => $name,
                'entity_type' => $this->inferEntityType($name, $coreBusiness),
                'aliases' => '',
                'description' => Str::limit($summary !== '' ? $summary : implode(' ', array_slice($facts, 0, 3)), 500, ''),
                'attributes_json' => json_encode([
                    'source' => 'url_import',
                    'source_domain' => (string) ($job->source_domain ?? ''),
                    'source_title' => (string) ($page['title'] ?? ''),
                    'evidence_facts' => array_slice($facts, 0, 8),
                    'core_business' => $coreBusiness,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'source_url' => $sourceUrl,
                'recommended_tag_ids' => collect($this->tagRecommendationService->recommendForText($entityText, [], 5))
                    ->pluck('id')
                    ->map(static fn ($id): int => (int) $id)
                    ->values()
                    ->all(),
            ];
        }

        $cases = $this->caseCandidates($analysis, $page, $job, $coreBusiness, $facts, $entities);

        return [
            'entities' => array_slice($entities, 0, 12),
            'cases' => array_slice($cases, 0, 6),
        ];
    }

    /**
     * @param  array{entities:list<array<string,mixed>>,cases:list<array<string,mixed>>}  $candidates
     * @return array{entities:int,cases:int}
     */
    public function persistCandidates(array $candidates): array
    {
        $entityMap = [];
        $entityCount = 0;
        foreach ($candidates['entities'] ?? [] as $candidate) {
            $name = $this->normalize((string) ($candidate['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $entity = EntityRecord::query()->where('name', $name)->first();
            if (! $entity) {
                $entity = EntityRecord::query()->create([
                    'name' => $name,
                    'entity_type' => $this->limit((string) ($candidate['entity_type'] ?? ''), 80),
                    'aliases' => $this->limit((string) ($candidate['aliases'] ?? ''), 2000),
                    'description' => $this->limit((string) ($candidate['description'] ?? ''), 10000),
                    'attributes_json' => $this->validJson((string) ($candidate['attributes_json'] ?? '')),
                    'source_url' => $this->limit((string) ($candidate['source_url'] ?? ''), 500),
                    'usage_count' => 0,
                ]);
                $entityCount++;
            }

            $tagIds = $this->tagIds($candidate['recommended_tag_ids'] ?? []);
            if ($tagIds !== []) {
                $this->tagService->syncExisting($entity, $tagIds);
            }
            $entityMap[$name] = $entity;
        }

        $caseCount = 0;
        foreach ($candidates['cases'] ?? [] as $candidate) {
            $title = $this->normalize((string) ($candidate['title'] ?? ''));
            if ($title === '') {
                continue;
            }

            $entityName = $this->normalize((string) ($candidate['entity_name'] ?? ''));
            $entity = $entityName !== '' ? ($entityMap[$entityName] ?? EntityRecord::query()->where('name', $entityName)->first()) : null;
            $sourceUrl = $this->limit((string) ($candidate['source_url'] ?? ''), 500);
            $caseRecord = CaseRecord::query()
                ->where('title', $title)
                ->when($sourceUrl !== '', fn ($query) => $query->where('source_url', $sourceUrl))
                ->first();
            if (! $caseRecord) {
                $caseRecord = CaseRecord::query()->create([
                    'entity_id' => $entity?->id,
                    'title' => $this->limit($title, 200),
                    'case_type' => $this->limit((string) ($candidate['case_type'] ?? ''), 100),
                    'summary' => $this->limit((string) ($candidate['summary'] ?? ''), 10000),
                    'challenge' => $this->limit((string) ($candidate['challenge'] ?? ''), 10000),
                    'solution' => $this->limit((string) ($candidate['solution'] ?? ''), 10000),
                    'result' => $this->limit((string) ($candidate['result'] ?? ''), 10000),
                    'metrics' => $this->limit((string) ($candidate['metrics'] ?? ''), 5000),
                    'source_url' => $sourceUrl,
                    'usage_count' => 0,
                ]);
                $caseCount++;
            }

            $tagIds = $this->tagIds($candidate['recommended_tag_ids'] ?? []);
            if ($tagIds !== []) {
                $this->tagService->syncExisting($caseRecord, $tagIds);
            }
        }

        return ['entities' => $entityCount, 'cases' => $caseCount];
    }

    /**
     * @param  array<string,mixed>  $analysis
     * @param  array<string,mixed>  $page
     * @param  array<string,mixed>  $coreBusiness
     * @param  list<string>  $facts
     * @param  list<array<string,mixed>>  $entities
     * @return list<array<string,mixed>>
     */
    private function caseCandidates(array $analysis, array $page, UrlImportJob $job, array $coreBusiness, array $facts, array $entities): array
    {
        $summary = $this->normalize((string) ($analysis['summary'] ?? data_get($analysis, 'cleaned.summary', '') ?: $page['summary'] ?? ''));
        $titleSource = $this->normalize((string) ($analysis['library_name'] ?? $page['title'] ?? $job->source_domain ?? 'URL素材'));
        if ($summary === '' && $facts === []) {
            return [];
        }

        $caseTitle = Str::limit($titleSource.' 资料案例', 200, '');
        $challenge = $this->businessValue($coreBusiness, 'target_audience') ?: $this->businessValue($coreBusiness, 'commercial_scenarios');
        $solution = $this->businessValue($coreBusiness, 'products_services') ?: $this->businessValue($coreBusiness, 'value_proposition');
        $result = implode("\n", array_slice($facts, 0, 4));
        $caseText = implode("\n", array_filter([$caseTitle, $summary, $challenge, $solution, $result]));

        return [[
            'entity_name' => (string) ($entities[0]['name'] ?? ''),
            'title' => $caseTitle,
            'case_type' => 'URL采集案例',
            'summary' => $summary,
            'challenge' => $challenge,
            'solution' => $solution,
            'result' => $result,
            'metrics' => $this->extractMetrics($facts),
            'source_url' => (string) ($job->normalized_url ?: $job->url),
            'recommended_tag_ids' => collect($this->tagRecommendationService->recommendForText($caseText, [], 5))
                ->pluck('id')
                ->map(static fn ($id): int => (int) $id)
                ->values()
                ->all(),
        ]];
    }

    /**
     * @param  mixed  $entities
     * @param  mixed  $keywords
     * @param  array<string,mixed>  $coreBusiness
     * @return list<string>
     */
    private function entityNames(mixed $entities, mixed $keywords, array $coreBusiness): array
    {
        $names = array_merge(
            $this->stringList($entities),
            $this->flattenBusinessList($coreBusiness, ['industry', 'products_services', 'target_audience', 'commercial_scenarios']),
            array_slice($this->stringList($keywords), 0, 4)
        );

        return collect($names)
            ->map(fn (string $name): string => $this->normalizeEntityName($name))
            ->filter(fn (string $name): bool => $this->isUsefulEntityName($name))
            ->unique(fn (string $name): string => mb_strtolower($name, 'UTF-8'))
            ->take(12)
            ->values()
            ->all();
    }

    private function inferEntityType(string $name, array $coreBusiness): string
    {
        foreach ([
            'industry' => '行业',
            'products_services' => '产品/服务',
            'target_audience' => '目标用户',
            'commercial_scenarios' => '业务场景',
        ] as $field => $label) {
            foreach ($this->flattenValue($coreBusiness[$field] ?? []) as $value) {
                if ($this->normalize($value) !== '' && str_contains($this->normalize($value), $this->normalize($name))) {
                    return $label;
                }
            }
        }

        return 'URL实体';
    }

    private function businessValue(array $coreBusiness, string $field): string
    {
        return Str::limit(implode('；', array_slice($this->flattenValue($coreBusiness[$field] ?? []), 0, 4)), 500, '');
    }

    /**
     * @param  list<string>  $facts
     */
    private function extractMetrics(array $facts): string
    {
        $metrics = array_values(array_filter($facts, static fn (string $fact): bool => preg_match('/\d|%|％|倍|年|月|day|days|month|year|percent/i', $fact) === 1));

        return Str::limit(implode("\n", array_slice($metrics, 0, 4)), 5000, '');
    }

    /**
     * @param  list<string>  $fields
     * @return list<string>
     */
    private function flattenBusinessList(array $coreBusiness, array $fields): array
    {
        $values = [];
        foreach ($fields as $field) {
            $values = array_merge($values, $this->flattenValue($coreBusiness[$field] ?? []));
        }

        return $values;
    }

    /**
     * @return list<string>
     */
    private function flattenValue(mixed $value): array
    {
        if (is_string($value) || is_numeric($value)) {
            return [$this->normalize((string) $value)];
        }
        if (! is_array($value)) {
            return [];
        }

        $items = [];
        foreach ($value as $item) {
            $items = array_merge($items, $this->flattenValue($item));
        }

        return array_values(array_filter($items));
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        return collect($this->flattenValue($value))
            ->map(fn (string $item): string => $this->normalize($item))
            ->filter(static fn (string $item): bool => $item !== '')
            ->unique()
            ->values()
            ->all();
    }

    private function normalizeEntityName(string $name): string
    {
        $name = $this->normalize($name);
        $name = preg_replace('/^[\s,，。.!！?？:：;；|｜\/\\\\()（）\[\]【】{}「」\'"“”‘’]+|[\s,，。.!！?？:：;；|｜\/\\\\()（）\[\]【】{}「」\'"“”‘’]+$/u', '', $name) ?? $name;

        return Str::limit($name, 160, '');
    }

    private function isUsefulEntityName(string $name): bool
    {
        $length = mb_strlen($name, 'UTF-8');
        if ($length < 2 || $length > 80) {
            return false;
        }

        $lower = mb_strtolower($name, 'UTF-8');
        $stopWords = ['ai', 'geo', 'url', 'http', 'https', 'www', '页面', '内容', '首页', '详情', '更多', '官网', 'source', 'page', 'home'];

        return ! in_array($lower, $stopWords, true);
    }

    private function normalize(string $text): string
    {
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }

    private function limit(string $value, int $max): string
    {
        return mb_substr($this->normalize($value), 0, $max, 'UTF-8');
    }

    private function validJson(string $json): string
    {
        $decoded = json_decode($json, true);

        return is_array($decoded) ? json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '{}';
    }

    /**
     * @return list<int>
     */
    private function tagIds(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return collect($value)
            ->map(static fn ($id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();
    }
}
