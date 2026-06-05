<?php

namespace App\Services\GeoFlow;

use App\Models\CaseRecord;
use App\Models\EntityRecord;
use App\Models\UrlImportJob;
use App\Support\GeoFlow\CaseTypes;
use Illuminate\Support\Str;

class EntityExtractionService
{
    private const ENTITY_TYPE_PRODUCT_MODEL = '产品型号';
    private const ENTITY_TYPE_PRODUCT_LINE = '产品线';
    private const ENTITY_TYPE_INDUSTRY = '行业';
    private const ENTITY_TYPE_APPLICATION = '应用场景';
    private const ENTITY_TYPE_MATERIAL = '材料';
    private const ENTITY_TYPE_TECHNOLOGY = '技术';
    private const ENTITY_TYPE_BRAND = '品牌';
    private const ENTITY_TYPE_COMPETITOR = '竞品';
    private const ENTITY_TYPE_PROCESS = '工艺流程';
    private const ENTITY_TYPE_COMPONENT = '部件';
    private const ENTITY_TYPE_AUDIENCE = '目标客户';
    private const ENTITY_TYPE_GENERAL = '业务实体';

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
        $language = $this->resolvedLanguage($analysis, $page);

        $entities = [];
        foreach ($entityNames as $name) {
            $entityType = $this->inferEntityType($name, $coreBusiness);
            $entities[] = [
                'name' => $name,
                'entity_type' => $entityType,
                'aliases' => '',
                'description' => $this->entityDescription($name, $entityType, $summary, $coreBusiness, $facts, $language),
                'attributes_json' => json_encode([
                    'source' => 'url_import',
                    'source_domain' => (string) ($job->source_domain ?? ''),
                    'source_title' => (string) ($page['title'] ?? ''),
                    'inferred_type' => $entityType,
                    'evidence_facts' => array_slice($facts, 0, 8),
                    'core_business' => $coreBusiness,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'source_url' => $sourceUrl,
            ];
        }

        $cases = $this->caseCandidates($analysis, $page, $job, $coreBusiness, $facts, $entities, $language);

        return [
            'entities' => array_slice($entities, 0, 12),
            'cases' => array_slice($cases, 0, 6),
        ];
    }

    /**
     * @param  array{entities:list<array<string,mixed>>,cases:list<array<string,mixed>>}  $candidates
     * @return array{entities:int,cases:int,entity_ids:list<int>,case_ids:list<int>}
     */
    public function persistCandidates(array $candidates, ?int $collectionId = null): array
    {
        $entityMap = [];
        $entityIds = [];
        $entityCount = 0;
        foreach ($candidates['entities'] ?? [] as $candidate) {
            $name = $this->normalize((string) ($candidate['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $entity = EntityRecord::query()->where('name', $name)->first();
            if (! $entity) {
                $entity = EntityRecord::query()->create([
                    'collection_id' => $collectionId,
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

            $entityMap[$name] = $entity;
            $entityIds[] = (int) $entity->id;
        }

        $caseIds = [];
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
                    'collection_id' => $collectionId,
                    'entity_id' => $entity?->id,
                    'title' => $this->limit($title, 200),
                    'case_type' => CaseTypes::normalize((string) ($candidate['case_type'] ?? '')),
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

            $caseIds[] = (int) $caseRecord->id;
        }

        return [
            'entities' => $entityCount,
            'cases' => $caseCount,
            'entity_ids' => array_values(array_unique($entityIds)),
            'case_ids' => array_values(array_unique($caseIds)),
        ];
    }

    /**
     * @param  array<string,mixed>  $analysis
     * @param  array<string,mixed>  $page
     * @param  array<string,mixed>  $coreBusiness
     * @param  list<string>  $facts
     * @param  list<array<string,mixed>>  $entities
     * @return list<array<string,mixed>>
     */
    private function caseCandidates(array $analysis, array $page, UrlImportJob $job, array $coreBusiness, array $facts, array $entities, array $language): array
    {
        $summary = $this->normalize((string) ($analysis['summary'] ?? data_get($analysis, 'cleaned.summary', '') ?: $page['summary'] ?? ''));
        $titleSource = $this->normalize((string) ($analysis['library_name'] ?? $page['title'] ?? $job->source_domain ?? 'URL素材'));
        if ($summary === '' && $facts === []) {
            return [];
        }

        $caseTitle = $this->caseTitle($titleSource, $entities, $coreBusiness, $language);
        $challenge = $this->businessValue($coreBusiness, 'target_audience') ?: $this->businessValue($coreBusiness, 'commercial_scenarios');
        $solution = $this->businessValue($coreBusiness, 'products_services') ?: $this->businessValue($coreBusiness, 'value_proposition');
        $result = implode("\n", array_slice($facts, 0, 4));
        return [[
            'entity_name' => (string) ($entities[0]['name'] ?? ''),
            'title' => $caseTitle,
            'case_type' => CaseTypes::APPLICATION_SCENARIO,
            'summary' => $summary,
            'challenge' => $challenge,
            'solution' => $solution,
            'result' => $result,
            'metrics' => $this->extractMetrics($facts),
            'source_url' => (string) ($job->normalized_url ?: $job->url),
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
        $normalizedName = $this->normalize($name);
        if (preg_match('/\b[A-Z]{1,8}[-_]?\d{2,}[A-Z0-9-]*\b/u', $normalizedName) === 1) {
            return self::ENTITY_TYPE_PRODUCT_MODEL;
        }

        if (preg_match('/(客户|用户|团队|audience|customer|client|user|team)/iu', $normalizedName) === 1) {
            return self::ENTITY_TYPE_AUDIENCE;
        }

        foreach ([
            'industry' => self::ENTITY_TYPE_INDUSTRY,
            'products_services' => self::ENTITY_TYPE_PRODUCT_LINE,
            'target_audience' => self::ENTITY_TYPE_AUDIENCE,
            'commercial_scenarios' => self::ENTITY_TYPE_APPLICATION,
        ] as $field => $label) {
            foreach ($this->flattenValue($coreBusiness[$field] ?? []) as $value) {
                if ($this->normalize($value) !== '' && str_contains($this->normalize($value), $this->normalize($name))) {
                    return $label;
                }
            }
        }

        $lower = mb_strtolower($normalizedName, 'UTF-8');
        if (preg_match('/(resin|胶|树脂|material|材料|pu|epoxy|硅胶)/iu', $lower) === 1) {
            return self::ENTITY_TYPE_MATERIAL;
        }
        if (preg_match('/(vision|视觉|laser|ai|automation|自动化|technology|技术|system|系统)/iu', $lower) === 1) {
            return self::ENTITY_TYPE_TECHNOLOGY;
        }
        if (preg_match('/(process|工艺|制造|processing|manufacturing|封装|点胶|灌胶)/iu', $lower) === 1) {
            return self::ENTITY_TYPE_PROCESS;
        }
        if (preg_match('/(competitor|竞品|替代|alternative|vs\.?)/iu', $lower) === 1) {
            return self::ENTITY_TYPE_COMPETITOR;
        }

        return self::ENTITY_TYPE_GENERAL;
    }

    /**
     * @param  list<string>  $facts
     */
    private function entityDescription(string $name, string $entityType, string $summary, array $coreBusiness, array $facts, array $language): string
    {
        $isChinese = $this->isChineseLanguage($language);
        $evidence = array_values(array_filter($facts, fn (string $fact): bool => mb_stripos($fact, $name, 0, 'UTF-8') !== false));
        if ($evidence !== []) {
            return Str::limit($name.($isChinese ? '：' : ': ').implode($isChinese ? '；' : '; ', array_slice($evidence, 0, 2)), 500, '');
        }

        $context = match ($entityType) {
            self::ENTITY_TYPE_INDUSTRY => $this->businessValue($coreBusiness, 'commercial_scenarios') ?: $summary,
            self::ENTITY_TYPE_PRODUCT_MODEL, self::ENTITY_TYPE_PRODUCT_LINE => $this->businessValue($coreBusiness, 'products_services') ?: $summary,
            self::ENTITY_TYPE_AUDIENCE => $this->businessValue($coreBusiness, 'target_audience') ?: $summary,
            self::ENTITY_TYPE_APPLICATION => $this->businessValue($coreBusiness, 'commercial_scenarios') ?: $summary,
            default => $summary,
        };

        $context = $this->normalize($context);
        if ($context === '') {
            $context = implode('；', array_slice($facts, 0, 2));
        }

        if (! $isChinese) {
            return Str::limit($context !== '' ? $name.': '.$context : $name, 500, '');
        }

        return Str::limit($name.' 是从 URL 采集内容中识别出的'.$entityType.($context !== '' ? '，相关上下文：'.$context : '。'), 500, '');
    }

    /**
     * @param  list<array<string,mixed>>  $entities
     */
    private function caseTitle(string $titleSource, array $entities, array $coreBusiness, array $language): string
    {
        $subject = $this->normalize((string) ($entities[0]['name'] ?? ''));
        if ($subject === '') {
            $subject = $this->businessValue($coreBusiness, 'products_services')
                ?: $this->businessValue($coreBusiness, 'commercial_scenarios')
                ?: $titleSource;
        }

        $subject = $this->normalizeCaseSubject($subject);
        if ($subject === '') {
            $subject = $this->isChineseLanguage($language) ? 'URL采集内容' : 'URL imported content';
        }

        $suffix = $this->isChineseLanguage($language) || preg_match('/[\p{Han}\p{Hiragana}\p{Katakana}\p{Hangul}]/u', $subject) === 1
            ? '应用案例'
            : 'Case';

        if (preg_match('/(案例|case)$/iu', $subject) === 1) {
            return Str::limit($subject, 200, '');
        }

        return Str::limit($subject.' '.$suffix, 200, '');
    }

    /**
     * @param  array<string,mixed>  $analysis
     * @param  array<string,mixed>  $page
     * @return array{code:string,name:string}
     */
    private function resolvedLanguage(array $analysis, array $page): array
    {
        $language = $analysis['language'] ?? $page['language'] ?? [];
        if (is_string($language)) {
            $language = ['code' => $language, 'name' => $language];
        }
        if (! is_array($language)) {
            $language = [];
        }

        $code = trim((string) ($language['code'] ?? $page['resolved_content_language'] ?? ''));
        $name = trim((string) ($language['name'] ?? $page['resolved_content_language_name'] ?? ''));

        if ($code === '' && $name === '') {
            $source = implode(' ', array_filter([
                (string) ($analysis['summary'] ?? ''),
                (string) data_get($analysis, 'cleaned.summary', ''),
                (string) ($page['summary'] ?? ''),
                (string) ($page['title'] ?? ''),
            ]));
            $code = preg_match('/[\p{Han}]/u', $source) === 1 ? 'zh' : 'en';
            $name = $code === 'zh' ? 'Chinese' : 'English';
        }

        return [
            'code' => $code !== '' ? mb_strtolower($code, 'UTF-8') : 'en',
            'name' => $name !== '' ? $name : ($code === 'zh' ? 'Chinese' : 'English'),
        ];
    }

    /**
     * @param  array{code:string,name:string}  $language
     */
    private function isChineseLanguage(array $language): bool
    {
        $code = mb_strtolower((string) ($language['code'] ?? ''), 'UTF-8');
        $name = mb_strtolower((string) ($language['name'] ?? ''), 'UTF-8');

        return str_starts_with($code, 'zh') || str_contains($name, 'chinese') || str_contains($name, '中文');
    }

    private function normalizeCaseSubject(string $subject): string
    {
        $subject = $this->normalize($subject);
        $subject = preg_replace('/\s*(?:资料案例|URL采集案例|Knowledge Base|Title Library|Keyword Library)\s*$/iu', '', $subject) ?? $subject;

        return trim($subject);
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

}
