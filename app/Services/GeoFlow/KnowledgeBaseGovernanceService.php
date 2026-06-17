<?php

namespace App\Services\GeoFlow;

use App\Models\KnowledgeBase;
use App\Support\GeoFlow\EntityTypes;
use Illuminate\Support\Collection;

class KnowledgeBaseGovernanceService
{
    private const DEFAULT_SCAN_LIMIT = 500;

    /**
     * @return array<string,mixed>
     */
    public function report(?int $collectionId = null, int $limit = self::DEFAULT_SCAN_LIMIT): array
    {
        $limit = max(20, min(1000, $limit));
        $query = KnowledgeBase::query()
            ->select([
                'id',
                'collection_id',
                'name',
                'description',
                'summary',
                'source_url',
                'content',
                'knowledge_type',
                'knowledge_role',
                'status',
                'word_count',
                'updated_at',
            ])
            ->with('collection:id,name')
            ->with(['linkedEntities' => fn ($query) => $query->select('entities.id', 'entities.name', 'entities.entity_type')])
            ->withCount('chunks')
            ->orderByDesc('updated_at');

        if ($collectionId !== null && $collectionId > 0) {
            $query->where('collection_id', $collectionId);
        }

        $records = $query->limit($limit)->get()
            ->map(fn (KnowledgeBase $knowledgeBase): array => $this->recordFor($knowledgeBase))
            ->values();

        $duplicateGroups = $this->duplicateGroups($records);
        $conflictPairs = $this->conflictPairs($records);

        return [
            'stats' => [
                'scanned' => $records->count(),
                'duplicate_groups' => count($duplicateGroups),
                'duplicate_items' => collect($duplicateGroups)->sum(fn (array $group): int => count($group['items'] ?? [])),
                'conflict_pairs' => count($conflictPairs),
                'scan_limit' => $limit,
            ],
            'duplicate_groups' => $duplicateGroups,
            'conflict_pairs' => $conflictPairs,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function recordFor(KnowledgeBase $knowledgeBase): array
    {
        $content = (string) ($knowledgeBase->content ?? '');
        $contentFingerprint = $this->normalizeContentFingerprint($content);
        $entities = $knowledgeBase->linkedEntities
            ->map(static fn ($entity): array => [
                'id' => (int) $entity->id,
                'name' => (string) $entity->name,
                'type' => (string) ($entity->entity_type ?? ''),
            ])
            ->values()
            ->all();

        return [
            'id' => (int) $knowledgeBase->id,
            'name' => (string) $knowledgeBase->name,
            'collection_id' => (int) ($knowledgeBase->collection_id ?? 0),
            'collection_name' => (string) ($knowledgeBase->collection?->name ?? ''),
            'source_url' => (string) ($knowledgeBase->source_url ?? ''),
            'normalized_source_url' => $this->normalizeUrl((string) ($knowledgeBase->source_url ?? '')),
            'normalized_name' => $this->normalizeName((string) $knowledgeBase->name),
            'content_fingerprint' => mb_strlen($contentFingerprint, 'UTF-8') >= 80 ? hash('sha256', $contentFingerprint) : '',
            'content_terms' => $this->contentTerms($content),
            'facts' => $this->extractFacts($content),
            'entities' => $entities,
            'entity_ids' => array_map(static fn (array $entity): int => (int) $entity['id'], $entities),
            'spec_subject_entity_ids' => $this->specSubjectEntityIdsFromEntities($entities),
            'knowledge_type' => (string) ($knowledgeBase->knowledge_type ?? ''),
            'knowledge_role' => (string) ($knowledgeBase->knowledge_role ?? ''),
            'status' => (string) ($knowledgeBase->status ?? ''),
            'word_count' => (int) ($knowledgeBase->word_count ?? 0),
            'chunk_count' => (int) ($knowledgeBase->chunks_count ?? 0),
            'updated_at' => $knowledgeBase->updated_at?->format('Y-m-d H:i:s'),
            'preview' => mb_substr(trim(preg_replace('/\s+/u', ' ', strip_tags($content)) ?: ''), 0, 160, 'UTF-8'),
        ];
    }

    /**
     * @param  Collection<int,array<string,mixed>>  $records
     * @return list<array<string,mixed>>
     */
    private function duplicateGroups(Collection $records): array
    {
        $groups = [];

        foreach ([
            'content_fingerprint' => ['type' => 'exact_content', 'confidence' => 100],
            'normalized_source_url' => ['type' => 'same_source_url', 'confidence' => 95],
            'normalized_name' => ['type' => 'same_title', 'confidence' => 82],
        ] as $field => $meta) {
            $records
                ->filter(static fn (array $record): bool => (string) ($record[$field] ?? '') !== '')
                ->groupBy(static fn (array $record): string => (string) $record[$field])
                ->filter(static fn (Collection $group): bool => $group->count() > 1)
                ->each(function (Collection $group) use (&$groups, $meta): void {
                    $groups[] = [
                        'type' => $meta['type'],
                        'confidence' => $meta['confidence'],
                        'items' => $group->values()->map(fn (array $record): array => $this->compactRecord($record))->all(),
                    ];
                });
        }

        $nearTitlePairs = [];
        $recordList = $records->values()->all();
        $count = count($recordList);
        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                $left = $recordList[$i];
                $right = $recordList[$j];
                if (! $this->sameCollectionScope($left, $right)) {
                    continue;
                }

                $titleScore = $this->similarityScore((string) ($left['normalized_name'] ?? ''), (string) ($right['normalized_name'] ?? ''));
                if ($titleScore < 88) {
                    continue;
                }

                $contentScore = $this->termOverlapScore((array) ($left['content_terms'] ?? []), (array) ($right['content_terms'] ?? []));
                if ($contentScore < 45) {
                    continue;
                }

                $nearTitlePairs[] = [
                    'type' => 'similar_title',
                    'confidence' => min(94, max(70, (int) round(($titleScore * 0.7) + ($contentScore * 0.3)))),
                    'items' => [$this->compactRecord($left), $this->compactRecord($right)],
                ];

                if (count($nearTitlePairs) >= 30) {
                    break 2;
                }
            }
        }

        return array_slice(array_merge($groups, $nearTitlePairs), 0, 50);
    }

    /**
     * @param  Collection<int,array<string,mixed>>  $records
     * @return list<array<string,mixed>>
     */
    private function conflictPairs(Collection $records): array
    {
        $pairs = [];
        $recordList = $records->values()->all();
        $count = count($recordList);

        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                $left = $recordList[$i];
                $right = $recordList[$j];
                if (! $this->shouldCompareForConflict($left, $right)) {
                    continue;
                }

                $conflicts = $this->factConflicts((array) ($left['facts'] ?? []), (array) ($right['facts'] ?? []));
                if ($conflicts === []) {
                    continue;
                }

                $pairs[] = [
                    'confidence' => $this->conflictConfidence($left, $right, $conflicts),
                    'left' => $this->compactRecord($left),
                    'right' => $this->compactRecord($right),
                    'conflicts' => array_slice($conflicts, 0, 5),
                ];

                if (count($pairs) >= 50) {
                    break 2;
                }
            }
        }

        usort($pairs, static fn (array $a, array $b): int => ((int) ($b['confidence'] ?? 0)) <=> ((int) ($a['confidence'] ?? 0)));

        return $pairs;
    }

    /**
     * @return array<string,mixed>
     */
    private function compactRecord(array $record): array
    {
        return [
            'id' => (int) ($record['id'] ?? 0),
            'name' => (string) ($record['name'] ?? ''),
            'collection_name' => (string) ($record['collection_name'] ?? ''),
            'source_url' => (string) ($record['source_url'] ?? ''),
            'knowledge_type' => (string) ($record['knowledge_type'] ?? ''),
            'knowledge_role' => (string) ($record['knowledge_role'] ?? ''),
            'status' => (string) ($record['status'] ?? ''),
            'word_count' => (int) ($record['word_count'] ?? 0),
            'chunk_count' => (int) ($record['chunk_count'] ?? 0),
            'updated_at' => (string) ($record['updated_at'] ?? ''),
            'preview' => (string) ($record['preview'] ?? ''),
            'entities' => (array) ($record['entities'] ?? []),
        ];
    }

    private function sameCollectionScope(array $left, array $right): bool
    {
        $leftCollection = (int) ($left['collection_id'] ?? 0);
        $rightCollection = (int) ($right['collection_id'] ?? 0);

        return $leftCollection > 0 && $leftCollection === $rightCollection;
    }

    private function shouldCompareForConflict(array $left, array $right): bool
    {
        if ($this->hasDifferentSpecSubjects($left, $right)) {
            return false;
        }

        if ($this->sharedSpecSubjectEntityIds($left, $right) !== []) {
            return true;
        }

        if ($this->normalizeUrl((string) ($left['source_url'] ?? '')) !== ''
            && $this->normalizeUrl((string) ($left['source_url'] ?? '')) === $this->normalizeUrl((string) ($right['source_url'] ?? ''))) {
            return true;
        }

        return $this->sameCollectionScope($left, $right)
            && $this->similarityScore((string) ($left['normalized_name'] ?? ''), (string) ($right['normalized_name'] ?? '')) >= 78
            && $this->termOverlapScore((array) ($left['content_terms'] ?? []), (array) ($right['content_terms'] ?? [])) >= 25;
    }

    /**
     * @param  array<string,list<string>>  $leftFacts
     * @param  array<string,list<string>>  $rightFacts
     * @return list<array{label:string,left:list<string>,right:list<string>}>
     */
    private function factConflicts(array $leftFacts, array $rightFacts): array
    {
        $conflicts = [];
        foreach (array_intersect(array_keys($leftFacts), array_keys($rightFacts)) as $label) {
            $leftValues = array_values(array_unique($leftFacts[$label]));
            $rightValues = array_values(array_unique($rightFacts[$label]));

            if ($leftValues === [] || $rightValues === [] || array_intersect($leftValues, $rightValues) !== []) {
                continue;
            }

            $conflicts[] = [
                'label' => $label,
                'left' => $leftValues,
                'right' => $rightValues,
            ];
        }

        return $conflicts;
    }

    private function conflictConfidence(array $left, array $right, array $conflicts): int
    {
        $score = 55 + min(25, count($conflicts) * 8);
        if ($this->sharedSpecSubjectEntityIds($left, $right) !== []) {
            $score += 15;
        }
        if ($this->normalizeUrl((string) ($left['source_url'] ?? '')) !== ''
            && $this->normalizeUrl((string) ($left['source_url'] ?? '')) === $this->normalizeUrl((string) ($right['source_url'] ?? ''))) {
            $score += 10;
        }

        return min(98, $score);
    }

    private function hasDifferentSpecSubjects(array $left, array $right): bool
    {
        $leftSubjects = $this->specSubjectEntityIds($left);
        $rightSubjects = $this->specSubjectEntityIds($right);

        return $leftSubjects !== []
            && $rightSubjects !== []
            && array_intersect($leftSubjects, $rightSubjects) === [];
    }

    /**
     * @return list<int>
     */
    private function sharedSpecSubjectEntityIds(array $left, array $right): array
    {
        return array_values(array_intersect($this->specSubjectEntityIds($left), $this->specSubjectEntityIds($right)));
    }

    /**
     * @return list<int>
     */
    private function specSubjectEntityIds(array $record): array
    {
        if (isset($record['spec_subject_entity_ids']) && is_array($record['spec_subject_entity_ids'])) {
            return array_values(array_unique(array_map('intval', array_filter($record['spec_subject_entity_ids']))));
        }

        return $this->specSubjectEntityIdsFromEntities((array) ($record['entities'] ?? []));
    }

    /**
     * @param  list<array<string,mixed>>  $entities
     * @return list<int>
     */
    private function specSubjectEntityIdsFromEntities(array $entities): array
    {
        return collect($entities)
            ->filter(fn (array $entity): bool => $this->isSpecSubjectEntityType((string) ($entity['type'] ?? '')))
            ->map(static fn (array $entity): int => (int) ($entity['id'] ?? 0))
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();
    }

    private function isSpecSubjectEntityType(string $type): bool
    {
        $type = trim($type);
        if ($type === '') {
            return false;
        }

        if (in_array($type, [
            EntityTypes::PRODUCT_MODEL,
            EntityTypes::PRODUCT_LINE,
            EntityTypes::MATERIAL_COMPONENT,
            EntityTypes::COMPETITOR,
            'Product Model',
            'Product Line',
            'Product',
            'Product/Service',
            'Product Service',
            'product_model',
            'product_line',
            'product',
            'product_service',
            '产品/服务',
            '产品服务',
            '产品',
        ], true)) {
            return true;
        }

        $normalized = mb_strtolower($type, 'UTF-8');
        $normalized = preg_replace('/[^\\p{L}\\p{N}]+/u', ' ', $normalized) ?: '';
        $normalized = trim(preg_replace('/\\s+/u', ' ', $normalized) ?: '');

        foreach ([
            'product model',
            'product line',
            'product service',
            'material component',
            'competitor',
            '产品型号',
            '产品线',
            '产品服务',
            '材料 部件',
            '竞品',
        ] as $keyword) {
            if (str_contains($normalized, $keyword)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string,list<string>>
     */
    private function extractFacts(string $content): array
    {
        $facts = [];
        $lines = array_slice(preg_split('/\R/u', $content) ?: [], 0, 500);

        foreach ($lines as $line) {
            $line = trim((string) $line);
            if ($line === '') {
                continue;
            }

            if (str_contains($line, '|')) {
                $cells = array_values(array_filter(array_map('trim', explode('|', trim($line, '|'))), static fn (string $cell): bool => $cell !== ''));
                if (count($cells) >= 2 && ! $this->looksLikeMarkdownSeparator($cells)) {
                    $this->addFact($facts, $cells[0], implode(' ', array_slice($cells, 1)));
                }
                continue;
            }

            if (preg_match('/^(.{2,60}?)[：:]\s*(.{1,140})$/u', $line, $match) === 1) {
                $this->addFact($facts, (string) $match[1], (string) $match[2]);
            }
        }

        return $facts;
    }

    /**
     * @param  array<string,list<string>>  $facts
     */
    private function addFact(array &$facts, string $label, string $value): void
    {
        $key = $this->normalizeFactLabel($label);
        if ($key === '') {
            return;
        }

        $values = $this->numericValues($value);
        if ($values === []) {
            return;
        }

        $facts[$key] = array_values(array_unique(array_merge($facts[$key] ?? [], $values)));
    }

    /**
     * @return list<string>
     */
    private function numericValues(string $value): array
    {
        preg_match_all('/[-+]?\d+(?:[\\.,]\d+)?\\s*(?:mm|cm|m|kg|g|kw|w|v|a|bar|mpa|psi|rpm|hz|%|℃|°c|pcs|set|sets|l\\/min|ml\\/min|ml|l)?/iu', $value, $matches);

        return collect($matches[0] ?? [])
            ->map(static fn (string $token): string => mb_strtolower(trim(str_replace(',', '.', $token)), 'UTF-8'))
            ->filter(static fn (string $token): bool => preg_match('/\\d/u', $token) === 1)
            ->unique()
            ->values()
            ->all();
    }

    private function normalizeFactLabel(string $label): string
    {
        $normalized = preg_replace('/[^\\p{L}\\p{N}]+/u', ' ', mb_strtolower(trim($label), 'UTF-8')) ?: '';
        $normalized = trim(preg_replace('/\\s+/u', ' ', $normalized) ?: '');

        if (mb_strlen($normalized, 'UTF-8') < 2 || mb_strlen($normalized, 'UTF-8') > 50) {
            return '';
        }

        if (in_array($normalized, [
            'id',
            'url',
            'name',
            'model',
            'product',
            'products',
            'description',
            'summary',
            'overview',
            'comparison',
            'application',
            'applications',
            'misconception',
            '型号',
            '名称',
            '产品',
            '描述',
            '摘要',
            '概述',
            '对比',
            '应用',
        ], true)) {
            return '';
        }

        return $normalized;
    }

    private function looksLikeMarkdownSeparator(array $cells): bool
    {
        return collect($cells)->every(static fn (string $cell): bool => preg_match('/^:?-{2,}:?$/', $cell) === 1);
    }

    private function normalizeContentFingerprint(string $text): string
    {
        $text = mb_strtolower(strip_tags($text), 'UTF-8');
        $text = preg_replace('/\\s+/u', '', $text) ?: '';
        $text = preg_replace('/[^\\p{L}\\p{N}]+/u', '', $text) ?: '';

        return trim($text);
    }

    private function normalizeName(string $name): string
    {
        $name = mb_strtolower($name, 'UTF-8');
        $name = preg_replace('/[^\\p{L}\\p{N}]+/u', ' ', $name) ?: '';

        return trim(preg_replace('/\\s+/u', ' ', $name) ?: '');
    }

    private function normalizeUrl(string $url): string
    {
        $url = trim(mb_strtolower($url, 'UTF-8'));
        if ($url === '') {
            return '';
        }

        $url = preg_replace('#^https?://#', '', $url) ?: $url;
        $url = preg_replace('#^www\\.#', '', $url) ?: $url;

        return rtrim($url, '/');
    }

    /**
     * @return list<string>
     */
    private function contentTerms(string $content): array
    {
        $content = mb_strtolower(strip_tags($content), 'UTF-8');
        preg_match_all('/[\\p{L}\\p{N}]{2,}/u', $content, $matches);

        return collect($matches[0] ?? [])
            ->map(static fn (string $term): string => mb_substr($term, 0, 40, 'UTF-8'))
            ->unique()
            ->take(200)
            ->values()
            ->all();
    }

    private function similarityScore(string $left, string $right): int
    {
        if ($left === '' || $right === '') {
            return 0;
        }

        similar_text($left, $right, $percent);

        return (int) round($percent);
    }

    /**
     * @param  list<string>  $left
     * @param  list<string>  $right
     */
    private function termOverlapScore(array $left, array $right): int
    {
        if ($left === [] || $right === []) {
            return 0;
        }

        $intersection = count(array_intersect($left, $right));
        $base = max(1, min(count($left), count($right)));

        return (int) round(($intersection / $base) * 100);
    }
}
