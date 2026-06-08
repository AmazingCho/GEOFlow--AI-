<?php

namespace App\Services\GeoFlow;

use App\Models\CaseRecord;
use App\Models\EntityRecord;
use App\Models\KnowledgeBase;
use App\Models\KnowledgeChunk;
use App\Models\Task;
use App\Support\GeoFlow\CaseTypes;
use App\Support\GeoFlow\EntityTypes;
use Illuminate\Support\Facades\DB;
use Throwable;

class RagRetrievalService
{
    public function __construct(
        private readonly KnowledgeChunkSyncService $knowledgeChunkSyncService,
        private readonly TagService $tagService
    ) {}

    /**
     * @return array{context:string,trace:array<string,mixed>}
     */
    public function retrieveForTask(Task $task, string $title, string $keyword, int $knowledgeMaxChars = 3200, int $entityCaseMaxChars = 2200): array
    {
        $tagFilters = $this->taskTagFilters($task);
        $entityFilterIds = $this->taskEntityIds($task);
        $caseFilterIds = $this->taskCaseIds($task);
        $collectionId = $this->taskCollectionId($task);
        $crossCollectionMode = $this->taskCrossCollectionMode($task);
        $retrievalCollectionId = $crossCollectionMode ? null : $collectionId;
        $knowledgeBaseIds = $this->resolveKnowledgeBaseIds($task, $tagFilters, $entityFilterIds, $caseFilterIds, $retrievalCollectionId);
        $query = trim($title."\n".$keyword);
        $knowledgeContext = '';
        $strategy = 'none';
        $chunks = [];
        $knowledgeBaseTrace = [];

        if ($knowledgeBaseIds !== []) {
            $knowledgeBases = KnowledgeBase::query()
                ->whereIn('id', $knowledgeBaseIds)
                ->orderBy('id')
                ->get(['id', 'name', 'content', 'knowledge_type', 'knowledge_role', 'importance'])
                ->sortBy(static fn (KnowledgeBase $knowledgeBase): int => array_search((int) $knowledgeBase->id, $knowledgeBaseIds, true) ?: 0)
                ->values()
                ->filter(static fn (KnowledgeBase $knowledgeBase): bool => trim((string) ($knowledgeBase->content ?? '')) !== '')
                ->values();

            $knowledgeBaseTrace = $knowledgeBases
                ->map(fn (KnowledgeBase $knowledgeBase): array => [
                    'id' => (int) $knowledgeBase->id,
                    'name' => (string) $knowledgeBase->name,
                    'knowledge_type' => $this->normalizeKnowledgeType((string) ($knowledgeBase->knowledge_type ?? '')),
                    'knowledge_role' => $this->normalizeKnowledgeRole((string) ($knowledgeBase->knowledge_role ?? '')),
                    'importance' => $this->normalizeImportance($knowledgeBase->importance ?? null),
                ])
                ->values()
                ->all();

            if ($knowledgeBases->isNotEmpty()) {
                $chunkResult = $this->fetchKnowledgeContextFromChunks(
                    $knowledgeBases->pluck('id')->map(static fn ($id): int => (int) $id)->all(),
                    $query,
                    6,
                    $knowledgeMaxChars
                );
                $knowledgeContext = $chunkResult['context'];
                $chunks = $chunkResult['chunks'];
                $strategy = $chunkResult['strategy'];

                if ($knowledgeContext === '') {
                    $knowledgeContext = $this->composeFallbackKnowledgeContent($knowledgeBases->all(), $knowledgeMaxChars);
                    $strategy = $knowledgeContext !== '' ? 'fallback_content' : 'none';
                }
            }
        }

        $entityCase = $this->composeTaggedEntityCaseContext($tagFilters, $entityCaseMaxChars, $entityFilterIds, $caseFilterIds, $retrievalCollectionId);
        $context = trim(implode("\n\n", array_filter(
            [$knowledgeContext, $entityCase['context']],
            static fn (string $part): bool => trim($part) !== ''
        )));
        $contextPackage = $this->buildContextPackage(
            $collectionId,
            $crossCollectionMode,
            $tagFilters,
            $entityFilterIds,
            $caseFilterIds,
            $knowledgeBaseIds,
            $knowledgeBaseTrace,
            $chunks,
            $entityCase,
            $strategy,
            $context
        );

        return [
            'context' => $context,
            'trace' => [
                'query' => $query,
                'collection_id' => $collectionId,
                'cross_collection_mode' => $crossCollectionMode,
                'tag_filters' => $this->tagFilterLabels($tagFilters),
                'entity_filter_ids' => $entityFilterIds,
                'case_filter_ids' => $caseFilterIds,
                'knowledge_base_ids' => $knowledgeBaseIds,
                'knowledge_bases' => $knowledgeBaseTrace,
                'chunks' => $chunks,
                'entities' => $entityCase['entities'],
                'cases' => $entityCase['cases'],
                'strategy' => $strategy,
                'evidence_summary' => $this->evidenceSummary($chunks),
                'context_package' => $contextPackage,
                'retrieval_engine' => 'rag_retrieval_service',
                'context_length' => mb_strlen($context, 'UTF-8'),
            ],
        ];
    }

    /**
     * @param  list<array{group_name:string,name:string}>  $tagFilters
     * @return list<int>
     */
    private function resolveKnowledgeBaseIds(Task $task, array $tagFilters, array $entityFilterIds = [], array $caseFilterIds = [], ?int $collectionId = null): array
    {
        $ids = [];
        $knowledgeBaseId = (int) ($task->knowledge_base_id ?? 0);
        if ($knowledgeBaseId > 0) {
            $ids[] = $knowledgeBaseId;
        }

        $linkedEntityIds = $entityFilterIds;
        if ($caseFilterIds !== []) {
            $caseEntityIds = CaseRecord::query()
                ->whereIn('id', $caseFilterIds)
                ->pluck('entity_id')
                ->map(static fn ($id): int => (int) $id)
                ->filter(static fn (int $id): bool => $id > 0)
                ->all();
            $linkedEntityIds = array_merge($linkedEntityIds, $caseEntityIds);
        }

        if ($tagFilters !== []) {
            $tagKnowledgeQuery = KnowledgeBase::query()
                ->whereHas('tags', fn ($query) => $this->addExactTagFilterConditions($query, $tagFilters))
                ->where(fn ($query) => $this->includeActiveKnowledge($query))
                ->where(fn ($query) => $this->excludeArchivedKnowledge($query))
                ->when($collectionId !== null, fn ($query) => $this->addCollectionScope($query, $collectionId));
            $tagKnowledgeBaseIds = $tagKnowledgeQuery->pluck('id')
                ->map(static fn ($id): int => (int) $id)
                ->all();
            $ids = array_merge($ids, $tagKnowledgeBaseIds);

            $entityIds = EntityRecord::query()
                ->whereHas('tags', fn ($query) => $this->addExactTagFilterConditions($query, $tagFilters))
                ->when($collectionId !== null, fn ($query) => $this->addCollectionScope($query, $collectionId))
                ->pluck('id')
                ->map(static fn ($id): int => (int) $id)
                ->all();
            $linkedEntityIds = array_merge($linkedEntityIds, $entityIds);
        }

        $linkedEntityIds = array_values(array_unique(array_filter($linkedEntityIds, static fn (int $id): bool => $id > 0)));
        if ($linkedEntityIds !== []) {
            $linkedKnowledgeBaseIds = DB::table('entity_material_links')
                ->join('knowledge_bases', 'knowledge_bases.id', '=', 'entity_material_links.linkable_id')
                ->whereIn('entity_material_links.entity_id', $linkedEntityIds)
                ->where('entity_material_links.linkable_type', KnowledgeBase::class)
                ->where(function ($query): void {
                    $query->whereNull('knowledge_bases.knowledge_role')
                        ->orWhere('knowledge_bases.knowledge_role', '!=', 'archive');
                })
                ->where(function ($query): void {
                    $query->whereNull('knowledge_bases.status')
                        ->orWhere('knowledge_bases.status', 'active');
                })
                ->orderByRaw("CASE link_role
                    WHEN 'primary_subject' THEN 1
                    WHEN 'supporting_reference' THEN 2
                    WHEN 'application_reference' THEN 3
                    WHEN 'troubleshooting_reference' THEN 4
                    WHEN 'competitor_reference' THEN 5
                    ELSE 9
                END")
                ->pluck('entity_material_links.linkable_id')
                ->map(static fn ($id): int => (int) $id)
                ->all();
            $ids = array_merge($ids, $linkedKnowledgeBaseIds);
        }

        if ($collectionId !== null) {
            $generalKnowledgeBaseIds = KnowledgeBase::query()
                ->where(fn ($query) => $this->includeActiveKnowledge($query))
                ->where(fn ($query) => $this->excludeArchivedKnowledge($query))
                ->where(fn ($query) => $this->addCollectionScope($query, $collectionId))
                ->whereIn('knowledge_role', ['primary_source', 'supporting_context', 'constraint'])
                ->orderByRaw("CASE knowledge_role
                    WHEN 'primary_source' THEN 1
                    WHEN 'constraint' THEN 2
                    WHEN 'supporting_context' THEN 3
                    ELSE 9
                END")
                ->orderByDesc('importance')
                ->orderBy('name')
                ->limit(8)
                ->pluck('id')
                ->map(static fn ($id): int => (int) $id)
                ->all();
            $ids = array_merge($ids, $generalKnowledgeBaseIds);
        }

        return array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));
    }

    /**
     * @return list<array{group_name:string,name:string}>
     */
    private function taskTagFilters(Task $task): array
    {
        $tagFilter = trim((string) ($task->knowledge_tag_filter ?? ''));

        return $tagFilter === '' ? [] : $this->tagService->parseTagText($tagFilter);
    }

    /**
     * @return list<int>
     */
    private function taskEntityIds(Task $task): array
    {
        return collect(preg_split('/\s*,\s*/u', trim((string) ($task->entity_filter ?? '')), -1, PREG_SPLIT_NO_EMPTY) ?: [])
            ->map(static fn (mixed $id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return list<int>
     */
    private function taskCaseIds(Task $task): array
    {
        return collect(preg_split('/\s*,\s*/u', trim((string) ($task->case_filter ?? '')), -1, PREG_SPLIT_NO_EMPTY) ?: [])
            ->map(static fn (mixed $id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();
    }

    private function taskCollectionId(Task $task): ?int
    {
        $collectionId = (int) ($task->collection_id ?? 0);

        return $collectionId > 0 ? $collectionId : null;
    }

    private function taskCrossCollectionMode(Task $task): bool
    {
        return (int) ($task->cross_collection_mode ?? 0) === 1;
    }

    /**
     * @param  list<array{group_name:string,name:string}>  $tagFilters
     * @return list<string>
     */
    private function tagFilterLabels(array $tagFilters): array
    {
        return array_map(static function (array $tag): string {
            $groupName = trim((string) ($tag['group_name'] ?? ''));
            $name = trim((string) ($tag['name'] ?? ''));

            return $groupName !== '' ? $groupName.':'.$name : $name;
        }, $tagFilters);
    }

    /**
     * @param  list<array{group_name:string,name:string}>  $tagFilters
     */
    private function addExactTagFilterConditions($query, array $tagFilters): void
    {
        $query->where(function ($nested) use ($tagFilters): void {
            foreach ($tagFilters as $tagFilter) {
                $groupName = trim((string) ($tagFilter['group_name'] ?? ''));
                $name = trim((string) ($tagFilter['name'] ?? ''));
                if ($name === '') {
                    continue;
                }

                $nested->orWhere(function ($tagQuery) use ($groupName, $name): void {
                    if ($groupName !== '') {
                        $tagQuery
                            ->where('group_name', $groupName)
                            ->where('name', $name);

                        return;
                    }

                    $tagQuery->where('name', $name);
                });
            }
        });
    }

    private function addCollectionScope($query, int $collectionId): void
    {
        $query->where(function ($scope) use ($collectionId): void {
            $scope->whereNull('collection_id')->orWhere('collection_id', $collectionId);
        });
    }

    private function excludeArchivedKnowledge($query): void
    {
        $query->where(function ($scope): void {
            $scope->whereNull('knowledge_role')->orWhere('knowledge_role', '!=', 'archive');
        });
    }

    private function includeActiveKnowledge($query): void
    {
        $query->where(function ($scope): void {
            $scope->whereNull('status')->orWhere('status', 'active');
        });
    }

    /**
     * @param  list<array{group_name:string,name:string}>  $tagFilters
     * @return array{context:string,entities:list<array<string,mixed>>,cases:list<array<string,mixed>>}
     */
    private function composeTaggedEntityCaseContext(array $tagFilters, int $maxChars, array $entityFilterIds = [], array $caseFilterIds = [], ?int $collectionId = null): array
    {
        $entityFilterIds = array_values(array_unique(array_filter($entityFilterIds, static fn (int $id): bool => $id > 0)));
        $caseFilterIds = array_values(array_unique(array_filter($caseFilterIds, static fn (int $id): bool => $id > 0)));
        if ($tagFilters === [] && $entityFilterIds === [] && $caseFilterIds === []) {
            return ['context' => '', 'entities' => [], 'cases' => []];
        }

        $entities = EntityRecord::query()
            ->where(function ($query) use ($tagFilters, $entityFilterIds, $collectionId): void {
                if ($entityFilterIds !== []) {
                    $query->orWhereIn('id', $entityFilterIds);
                }
                if ($tagFilters !== []) {
                    $query->orWhere(function ($taggedQuery) use ($tagFilters, $collectionId): void {
                        $taggedQuery->whereHas('tags', fn ($tagQuery) => $this->addExactTagFilterConditions($tagQuery, $tagFilters));
                        if ($collectionId !== null) {
                            $this->addCollectionScope($taggedQuery, $collectionId);
                        }
                    });
                }
            })
            ->orderBy('name')
            ->limit(12)
            ->get(['id', 'name', 'entity_type', 'aliases', 'description', 'attributes_json', 'canonical_url', 'link_policy']);

        $cases = CaseRecord::query()
            ->with('entities:id,name')
            ->where(function ($query) use ($tagFilters, $entityFilterIds, $caseFilterIds, $collectionId): void {
                if ($caseFilterIds !== []) {
                    $query->orWhereIn('id', $caseFilterIds);
                }
                if ($entityFilterIds !== []) {
                    $query->orWhereIn('entity_id', $entityFilterIds);
                }
                if ($tagFilters !== []) {
                    $query->orWhere(function ($taggedQuery) use ($tagFilters, $collectionId): void {
                        $taggedQuery
                            ->where(function ($tagScope) use ($tagFilters): void {
                                $tagScope
                                    ->whereHas('tags', fn ($tagQuery) => $this->addExactTagFilterConditions($tagQuery, $tagFilters))
                                    ->orWhereHas('entity.tags', fn ($tagQuery) => $this->addExactTagFilterConditions($tagQuery, $tagFilters));
                            });
                        if ($collectionId !== null) {
                            $this->addCollectionScope($taggedQuery, $collectionId);
                        }
                    });
                }
            })
            ->orderByDesc('id')
            ->limit(12)
            ->get(['id', 'entity_id', 'title', 'case_type', 'summary', 'challenge', 'solution', 'result', 'metrics']);

        $entityTrace = $entities
            ->map(static fn (EntityRecord $entity): array => [
                'id' => (int) $entity->id,
                'name' => (string) $entity->name,
                'type' => (string) ($entity->entity_type ?? ''),
                'role' => EntityTypes::roleDescription((string) ($entity->entity_type ?? '')),
                'linkable' => EntityTypes::isLinkable((string) ($entity->entity_type ?? ''))
                    && (string) ($entity->link_policy ?? '') === EntityTypes::LINK_POLICY_SUGGEST
                    && trim((string) ($entity->canonical_url ?? '')) !== '',
            ])
            ->values()
            ->all();
        $caseTrace = $cases
            ->map(static fn (CaseRecord $caseRecord): array => [
                'id' => (int) $caseRecord->id,
                'title' => (string) $caseRecord->title,
                'type' => (string) ($caseRecord->case_type ?? ''),
                'role' => CaseTypes::referenceRule((string) ($caseRecord->case_type ?? '')),
                'entity_id' => $caseRecord->entity_id !== null ? (int) $caseRecord->entity_id : null,
                'entity_name' => (string) (($e = $caseRecord->entities->first()) ? $e->name : ''),
            ])
            ->values()
            ->all();

        if ($entities->isEmpty() && $cases->isEmpty()) {
            return ['context' => '', 'entities' => $entityTrace, 'cases' => $caseTrace];
        }

        $lines = [];
        if ($entities->isNotEmpty()) {
            $lines[] = '【Entity DB 参考】';
            foreach ($entities as $entity) {
                $line = '- 实体：'.(string) $entity->name;
                if ((string) ($entity->entity_type ?? '') !== '') {
                    $line .= '（类型：'.(string) $entity->entity_type.'）';
                }
                $lines[] = $line;
                $lines[] = '  写作角色：'.EntityTypes::roleDescription((string) ($entity->entity_type ?? ''));
                if ((string) ($entity->aliases ?? '') !== '') {
                    $lines[] = '  别名：'.$this->shortContextText($entity->aliases, 180);
                }
                if ((string) ($entity->description ?? '') !== '') {
                    $lines[] = '  描述：'.$this->shortContextText($entity->description, 320);
                }
                if ((string) ($entity->attributes_json ?? '') !== '' && trim((string) $entity->attributes_json) !== '{}') {
                    $lines[] = '  属性：'.$this->shortContextText($entity->attributes_json, 260);
                }
            }
        }

        if ($cases->isNotEmpty()) {
            $lines[] = '【Case DB 参考】';
            foreach ($cases as $caseRecord) {
                $line = '- 案例：'.(string) $caseRecord->title;
                if ((string) ($caseRecord->case_type ?? '') !== '') {
                    $line .= '（类型：'.(string) $caseRecord->case_type.'）';
                }
                if (($e = $caseRecord->entities->first())) {
                    $line .= '，关联实体：'.(string) $e->name;
                }
                $lines[] = $line;
                $lines[] = '  引用规则：'.CaseTypes::referenceRule((string) ($caseRecord->case_type ?? ''));

                foreach ([
                    'summary' => '摘要',
                    'challenge' => '挑战',
                    'solution' => '方案',
                    'result' => '结果',
                    'metrics' => '指标',
                ] as $field => $label) {
                    $value = (string) ($caseRecord->{$field} ?? '');
                    if ($value !== '') {
                        $lines[] = '  '.$label.'：'.$this->shortContextText($value, 260);
                    }
                }
            }
        }

        $context = trim(implode("\n", $lines));
        if (mb_strlen($context, 'UTF-8') > $maxChars) {
            $context = mb_substr($context, 0, $maxChars, 'UTF-8').'...';
        }

        return ['context' => $context, 'entities' => $entityTrace, 'cases' => $caseTrace];
    }

    /**
     * @param  list<array{group_name:string,name:string}>  $tagFilters
     * @param  list<int>  $entityFilterIds
     * @param  list<int>  $caseFilterIds
     * @param  list<int>  $knowledgeBaseIds
     * @param  list<array<string,mixed>>  $knowledgeBaseTrace
     * @param  list<array<string,mixed>>  $chunks
     * @param  array{context:string,entities:list<array<string,mixed>>,cases:list<array<string,mixed>>}  $entityCase
     * @return array<string,mixed>
     */
    private function buildContextPackage(
        ?int $collectionId,
        bool $crossCollectionMode,
        array $tagFilters,
        array $entityFilterIds,
        array $caseFilterIds,
        array $knowledgeBaseIds,
        array $knowledgeBaseTrace,
        array $chunks,
        array $entityCase,
        string $strategy,
        string $context
    ): array {
        $usedKnowledgeIds = collect($knowledgeBaseTrace)
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->values()
            ->all();

        return [
            'selected_collection_id' => $collectionId,
            'cross_collection_mode' => $crossCollectionMode,
            'selected_entity_ids' => $entityFilterIds,
            'selected_case_ids' => $caseFilterIds,
            'used_knowledge_base_ids' => $usedKnowledgeIds !== [] ? $usedKnowledgeIds : $knowledgeBaseIds,
            'used_tags' => $this->tagFilterLabels($tagFilters),
            'strategy' => $strategy,
            'context_length' => mb_strlen($context, 'UTF-8'),
            'evidence_summary' => $this->evidenceSummary($chunks),
            'knowledge_bases' => $knowledgeBaseTrace,
            'chunks' => $chunks,
            'entities' => $entityCase['entities'],
            'cases' => $entityCase['cases'],
        ];
    }

    private function shortContextText(mixed $value, int $maxChars): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', (string) $value));

        return mb_strlen($text, 'UTF-8') > $maxChars
            ? mb_substr($text, 0, $maxChars, 'UTF-8').'...'
            : $text;
    }

    /**
     * @param  list<int>  $knowledgeBaseIds
     * @return array{context:string,chunks:list<array<string,mixed>>,strategy:string}
     */
    private function fetchKnowledgeContextFromChunks(array $knowledgeBaseIds, string $query, int $limit, int $maxChars): array
    {
        $knowledgeBaseIds = array_values(array_unique(array_filter($knowledgeBaseIds, static fn (int $id): bool => $id > 0)));
        if ($knowledgeBaseIds === []) {
            return ['context' => '', 'chunks' => [], 'strategy' => 'none'];
        }

        if (trim($query) !== '') {
            $vectorRows = $this->fetchKnowledgeChunksByPgvector($knowledgeBaseIds, $query, max($limit * 3, 8));
            if ($vectorRows !== []) {
                $composed = $this->composeKnowledgeContext($vectorRows, $limit, $maxChars);

                return ['context' => $composed['context'], 'chunks' => $composed['chunks'], 'strategy' => 'pgvector'];
            }
        }

        $rows = DB::table('knowledge_chunks as kc')
            ->join('knowledge_bases as kb', 'kb.id', '=', 'kc.knowledge_base_id')
            ->whereIn('kc.knowledge_base_id', $knowledgeBaseIds)
            ->orderBy('kc.knowledge_base_id')
            ->orderBy('kc.chunk_index')
            ->get([
                'kc.knowledge_base_id',
                'kb.name as knowledge_base_name',
                'kb.knowledge_type',
                'kb.knowledge_role',
                'kb.importance',
                'kc.chunk_index',
                'kc.content',
                'kc.embedding_json',
                'kc.embedding_model_id',
                'kc.embedding_dimensions',
            ])
            ->all();
        if ($rows === []) {
            return ['context' => '', 'chunks' => [], 'strategy' => 'none'];
        }

        $queryTerms = $this->termFrequencies($query);
        $hasRealEmbeddingRows = collect($rows)->contains(
            fn ($row): bool => $this->chunkHasRealEmbedding($row)
        );
        $useRealEmbeddingScore = false;
        $queryVector = [];
        if ($hasRealEmbeddingRows && trim($query) !== '') {
            $queryVector = $this->knowledgeChunkSyncService->generateQueryEmbeddingVector($query);
            $useRealEmbeddingScore = $queryVector !== [];
        }
        if ($queryVector === []) {
            $queryVector = $this->decodeVector(json_encode($this->buildFallbackVector($query, 256)) ?: '[]');
        }

        $scored = [];
        foreach ($rows as $row) {
            $content = trim((string) ($row->content ?? ''));
            if ($content === '') {
                continue;
            }

            $vector = $this->decodeVector((string) ($row->embedding_json ?? ''));
            $chunkTerms = $this->termFrequencies($content);
            $lexicalScore = $this->lexicalScore($queryTerms, $chunkTerms);
            $chunkUsesRealEmbedding = $this->chunkHasRealEmbedding($row);
            $vectorScore = ($useRealEmbeddingScore === $chunkUsesRealEmbedding)
                ? $this->dotProduct($queryVector, $vector)
                : 0.0;
            $metadataScore = $this->metadataScore($row);
            $score = ($vectorScore * 0.75) + ($lexicalScore * 0.25) + $metadataScore;

            $scored[] = [
                'knowledge_base_id' => (int) ($row->knowledge_base_id ?? 0),
                'knowledge_base_name' => (string) ($row->knowledge_base_name ?? ''),
                'knowledge_type' => $this->normalizeKnowledgeType((string) ($row->knowledge_type ?? '')),
                'knowledge_role' => $this->normalizeKnowledgeRole((string) ($row->knowledge_role ?? '')),
                'importance' => $this->normalizeImportance($row->importance ?? null),
                'chunk_index' => (int) ($row->chunk_index ?? 0),
                'content' => $content,
                'score' => $score,
                'score_components' => [
                    'vector' => round($vectorScore, 6),
                    'lexical' => round($lexicalScore, 6),
                    'metadata' => round($metadataScore, 6),
                ],
                'retrieval_source' => $useRealEmbeddingScore && $chunkUsesRealEmbedding
                    ? 'real_embedding_hybrid'
                    : 'fallback_embedding_hybrid',
            ];
        }

        usort($scored, static function (array $a, array $b): int {
            $diff = ($b['score'] <=> $a['score']);

            return $diff !== 0 ? $diff : (($a['knowledge_base_id'] <=> $b['knowledge_base_id']) ?: ($a['chunk_index'] <=> $b['chunk_index']));
        });

        $composed = $this->composeKnowledgeContext($scored, $limit, $maxChars);

        return [
            'context' => $composed['context'],
            'chunks' => $composed['chunks'],
            'strategy' => $composed['context'] !== '' ? 'hybrid_vector_lexical' : 'none',
        ];
    }

    private function chunkHasRealEmbedding(object $row): bool
    {
        return (int) ($row->embedding_model_id ?? 0) > 0
            && (int) ($row->embedding_dimensions ?? 0) > 0;
    }

    /**
     * @return array<string,int>
     */
    private function termFrequencies(string $text): array
    {
        $tokens = preg_split('/[^\p{L}\p{N}_]+/u', mb_strtolower(trim($text), 'UTF-8')) ?: [];
        $frequencies = [];
        foreach ($tokens as $token) {
            $token = trim((string) $token);
            if ($token === '' || mb_strlen($token, 'UTF-8') <= 1) {
                continue;
            }
            $frequencies[$token] = (int) ($frequencies[$token] ?? 0) + 1;
        }

        return $frequencies;
    }

    /**
     * @param  array<string,int>  $queryTerms
     * @param  array<string,int>  $chunkTerms
     */
    private function lexicalScore(array $queryTerms, array $chunkTerms): float
    {
        if ($queryTerms === [] || $chunkTerms === []) {
            return 0.0;
        }

        $matched = 0;
        $total = 0;
        foreach ($queryTerms as $term => $count) {
            $total += $count;
            if (isset($chunkTerms[$term])) {
                $matched += min($count, (int) $chunkTerms[$term]);
            }
        }

        return $total > 0 ? ($matched / $total) : 0.0;
    }

    /**
     * @return list<float>
     */
    private function decodeVector(string $json): array
    {
        $decoded = json_decode($json, true);
        if (! is_array($decoded) || $decoded === []) {
            return [];
        }

        $vector = [];
        foreach ($decoded as $value) {
            if (is_numeric($value)) {
                $vector[] = (float) $value;
            }
        }

        return $vector;
    }

    /**
     * @param  list<float>  $left
     * @param  list<float>  $right
     */
    private function dotProduct(array $left, array $right): float
    {
        if ($left === [] || $right === []) {
            return 0.0;
        }
        $sum = 0.0;
        $limit = min(count($left), count($right));
        for ($i = 0; $i < $limit; $i++) {
            $sum += ((float) $left[$i]) * ((float) $right[$i]);
        }

        return $sum;
    }

    /**
     * @return list<float>
     */
    private function buildFallbackVector(string $text, int $dimensions): array
    {
        $dimensions = max(1, $dimensions);
        $vector = array_fill(0, $dimensions, 0.0);
        foreach ($this->termFrequencies($text) as $token => $count) {
            $indexSeed = abs((int) crc32('i:'.$token));
            $signSeed = abs((int) crc32('s:'.$token));
            $index = $indexSeed % $dimensions;
            $sign = ($signSeed % 2 === 0) ? 1.0 : -1.0;
            $tokenLength = max(1, mb_strlen($token, 'UTF-8'));
            $weight = (1.0 + log(1 + $count)) * min(2.0, 0.8 + ($tokenLength / 4));
            $vector[$index] += $sign * $weight;
        }

        $norm = 0.0;
        foreach ($vector as $value) {
            $norm += $value * $value;
        }
        if ($norm > 0.0) {
            $norm = sqrt($norm);
            foreach ($vector as $index => $value) {
                $vector[$index] = $value / $norm;
            }
        }

        return $vector;
    }

    /**
     * @param  list<int>  $knowledgeBaseIds
     * @return list<array{knowledge_base_id:int,knowledge_base_name:string,knowledge_type:string,knowledge_role:string,importance:int,chunk_index:int,content:string,score:float}>
     */
    private function fetchKnowledgeChunksByPgvector(array $knowledgeBaseIds, string $query, int $candidateLimit): array
    {
        if (! $this->canUsePgvectorSearch()) {
            return [];
        }
        $knowledgeBaseIds = array_values(array_unique(array_filter($knowledgeBaseIds, static fn (int $id): bool => $id > 0)));
        if ($knowledgeBaseIds === []) {
            return [];
        }

        $vectorLiteral = $this->knowledgeChunkSyncService->generateQueryVectorLiteral($query);
        if ($vectorLiteral === '') {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($knowledgeBaseIds), '?'));

        $rows = DB::select(
            "
                SELECT kc.knowledge_base_id, kb.name AS knowledge_base_name,
                       kb.knowledge_type, kb.knowledge_role, kb.importance,
                       kc.chunk_index, kc.content,
                       (kc.embedding_vector <=> CAST(? AS vector)) AS vector_distance
                FROM knowledge_chunks kc
                JOIN knowledge_bases kb ON kb.id = kc.knowledge_base_id
                WHERE kc.knowledge_base_id IN ({$placeholders})
                  AND kc.embedding_vector IS NOT NULL
                ORDER BY kc.embedding_vector <=> CAST(? AS vector), kc.chunk_index ASC
                LIMIT ?
            ",
            array_merge([$vectorLiteral], $knowledgeBaseIds, [$vectorLiteral, max(1, $candidateLimit)])
        );

        $results = [];
        foreach ($rows as $row) {
            $content = trim((string) ($row->content ?? ''));
            if ($content === '') {
                continue;
            }
            $distance = (float) ($row->vector_distance ?? 1.0);
            $vectorScore = 1.0 - $distance;
            $metadataScore = $this->metadataScore($row);
            $results[] = [
                'knowledge_base_id' => (int) ($row->knowledge_base_id ?? 0),
                'knowledge_base_name' => (string) ($row->knowledge_base_name ?? ''),
                'knowledge_type' => $this->normalizeKnowledgeType((string) ($row->knowledge_type ?? '')),
                'knowledge_role' => $this->normalizeKnowledgeRole((string) ($row->knowledge_role ?? '')),
                'importance' => $this->normalizeImportance($row->importance ?? null),
                'chunk_index' => (int) ($row->chunk_index ?? 0),
                'content' => $content,
                'score' => $vectorScore + $metadataScore,
                'score_components' => [
                    'vector' => round($vectorScore, 6),
                    'lexical' => 0.0,
                    'metadata' => round($metadataScore, 6),
                    'distance' => round($distance, 6),
                ],
                'retrieval_source' => 'pgvector',
            ];
        }

        return $results;
    }

    private function canUsePgvectorSearch(): bool
    {
        if (DB::getDriverName() !== 'pgsql') {
            return false;
        }

        try {
            $typeRow = DB::selectOne("
                SELECT EXISTS (
                    SELECT 1 FROM pg_type WHERE typname = 'vector'
                ) AS ok
            ");
            if (! $typeRow || ! (bool) ($typeRow->ok ?? false)) {
                return false;
            }

            $columnRow = DB::selectOne("
                SELECT EXISTS (
                    SELECT 1
                    FROM information_schema.columns
                    WHERE table_name = 'knowledge_chunks'
                      AND column_name = 'embedding_vector'
                ) AS ok
            ");

            return $columnRow !== null && (bool) ($columnRow->ok ?? false);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param  list<array{knowledge_base_id?:int,knowledge_base_name?:string,knowledge_type?:string,knowledge_role?:string,importance?:int,chunk_index:int,content:string,score:float}>  $scored
     * @return array{context:string,chunks:list<array<string,mixed>>}
     */
    private function composeKnowledgeContext(array $scored, int $limit, int $maxChars): array
    {
        if ($scored === []) {
            return ['context' => '', 'chunks' => []];
        }

        $selected = array_slice($scored, 0, max(1, $limit));
        usort($selected, static fn (array $a, array $b): int => (($a['knowledge_base_id'] ?? 0) <=> ($b['knowledge_base_id'] ?? 0)) ?: ($a['chunk_index'] <=> $b['chunk_index']));
        $chunkTrace = array_map(fn (array $chunk): array => [
            'knowledge_base_id' => (int) ($chunk['knowledge_base_id'] ?? 0),
            'knowledge_base_name' => (string) ($chunk['knowledge_base_name'] ?? ''),
            'knowledge_type' => $this->normalizeKnowledgeType((string) ($chunk['knowledge_type'] ?? '')),
            'knowledge_role' => $this->normalizeKnowledgeRole((string) ($chunk['knowledge_role'] ?? '')),
            'importance' => $this->normalizeImportance($chunk['importance'] ?? null),
            'chunk_index' => (int) ($chunk['chunk_index'] ?? 0),
            'score' => round((float) ($chunk['score'] ?? 0), 6),
            'evidence_score' => $this->evidenceScore($chunk),
            'retrieval_source' => (string) ($chunk['retrieval_source'] ?? 'lexical_fallback'),
            'match_reasons' => $this->chunkMatchReasons($chunk),
            'score_components' => is_array($chunk['score_components'] ?? null) ? $chunk['score_components'] : [],
            'preview' => mb_substr(trim((string) ($chunk['content'] ?? '')), 0, 160, 'UTF-8'),
        ], $selected);

        $parts = [];
        $charCount = 0;
        foreach ($selected as $index => $chunk) {
            $content = trim((string) ($chunk['content'] ?? ''));
            if ($content === '') {
                continue;
            }
            $nextLength = $charCount + mb_strlen($content, 'UTF-8');
            if ($parts !== [] && $nextLength > $maxChars) {
                continue;
            }
            $source = trim((string) ($chunk['knowledge_base_name'] ?? ''));
            $type = $this->normalizeKnowledgeType((string) ($chunk['knowledge_type'] ?? ''));
            $role = $this->normalizeKnowledgeRole((string) ($chunk['knowledge_role'] ?? ''));
            $importance = $this->normalizeImportance($chunk['importance'] ?? null);
            $heading = '【知识片段'.($index + 1)
                .($source !== '' ? ' / 知识库：'.$source : '')
                .' / 类型：'.$this->knowledgeTypeLabel($type)
                .' / 角色：'.$this->knowledgeRoleLabel($role)
                .' / 重要度：'.$importance
                .'】';
            $parts[] = $heading."\n使用方式：".$this->knowledgeRoleInstruction($role)."\n".$content;
            $charCount = $nextLength;
        }

        return ['context' => trim(implode("\n\n", $parts)), 'chunks' => $chunkTrace];
    }

    /**
     * @param  list<KnowledgeBase>  $knowledgeBases
     */
    private function composeFallbackKnowledgeContent(array $knowledgeBases, int $maxChars): string
    {
        $parts = [];
        $charCount = 0;
        foreach ($knowledgeBases as $knowledgeBase) {
            $content = trim((string) ($knowledgeBase->content ?? ''));
            if ($content === '') {
                continue;
            }
            $name = trim((string) ($knowledgeBase->name ?? ''));
            $type = $this->normalizeKnowledgeType((string) ($knowledgeBase->knowledge_type ?? ''));
            $role = $this->normalizeKnowledgeRole((string) ($knowledgeBase->knowledge_role ?? ''));
            $importance = $this->normalizeImportance($knowledgeBase->importance ?? null);
            $heading = '【知识库'.($name !== '' ? '：'.$name : '')
                .' / 类型：'.$this->knowledgeTypeLabel($type)
                .' / 角色：'.$this->knowledgeRoleLabel($role)
                .' / 重要度：'.$importance
                .'】';
            $block = $heading."\n使用方式：".$this->knowledgeRoleInstruction($role)."\n".$content;
            $blockLength = mb_strlen($block, 'UTF-8');
            if ($parts !== [] && $charCount + $blockLength > $maxChars) {
                $remaining = $maxChars - $charCount;
                if ($remaining <= 120) {
                    break;
                }
                $block = mb_substr($block, 0, $remaining, 'UTF-8');
                $blockLength = mb_strlen($block, 'UTF-8');
            }
            $parts[] = $block;
            $charCount += $blockLength;
            if ($charCount >= $maxChars) {
                break;
            }
        }

        return trim(implode("\n\n", $parts));
    }

    private function normalizeKnowledgeType(string $value): string
    {
        $value = trim($value);

        return in_array($value, KnowledgeBase::KNOWLEDGE_TYPES, true) ? $value : 'reference';
    }

    private function normalizeKnowledgeRole(string $value): string
    {
        $value = trim($value);

        return in_array($value, KnowledgeBase::KNOWLEDGE_ROLES, true) ? $value : 'supporting_context';
    }

    private function normalizeImportance(mixed $value): int
    {
        $importance = (int) $value;

        return min(5, max(1, $importance > 0 ? $importance : 3));
    }

    private function metadataScore(object|array $source): float
    {
        $get = static fn (string $key): mixed => is_array($source) ? ($source[$key] ?? null) : ($source->{$key} ?? null);
        $importance = $this->normalizeImportance($get('importance'));
        $role = $this->normalizeKnowledgeRole((string) $get('knowledge_role'));
        $roleBoost = match ($role) {
            'primary_source' => 0.05,
            'constraint' => 0.04,
            'comparison_reference' => 0.025,
            'supporting_context' => 0.015,
            'style_reference' => 0.0,
            'archive' => -0.05,
            default => 0.0,
        };

        return $roleBoost + (($importance - 3) * 0.01);
    }

    /**
     * @param  array<string,mixed>  $chunk
     */
    private function evidenceScore(array $chunk): int
    {
        $components = is_array($chunk['score_components'] ?? null) ? $chunk['score_components'] : [];
        $vector = max(0.0, min(1.0, (float) ($components['vector'] ?? 0.0)));
        $lexical = max(0.0, min(1.0, (float) ($components['lexical'] ?? 0.0)));
        $metadata = max(-0.1, min(0.1, (float) ($components['metadata'] ?? 0.0)));
        $score = ($vector * 70) + ($lexical * 20) + (($metadata + 0.1) * 50);

        return max(0, min(100, (int) round($score)));
    }

    /**
     * @param  list<array<string,mixed>>  $chunks
     * @return array<string,mixed>
     */
    private function evidenceSummary(array $chunks): array
    {
        if ($chunks === []) {
            return [
                'chunk_count' => 0,
                'average_evidence_score' => 0,
                'retrieval_sources' => [],
            ];
        }

        $scores = array_map(fn (array $chunk): int => (int) ($chunk['evidence_score'] ?? $this->evidenceScore($chunk)), $chunks);
        $sources = array_values(array_unique(array_filter(array_map(
            static fn (array $chunk): string => trim((string) ($chunk['retrieval_source'] ?? '')),
            $chunks
        ))));

        return [
            'chunk_count' => count($chunks),
            'average_evidence_score' => (int) round(array_sum($scores) / max(1, count($scores))),
            'retrieval_sources' => $sources,
        ];
    }

    /**
     * @param  array<string,mixed>  $chunk
     * @return list<string>
     */
    private function chunkMatchReasons(array $chunk): array
    {
        $components = is_array($chunk['score_components'] ?? null) ? $chunk['score_components'] : [];
        $reasons = [];
        if ((float) ($components['vector'] ?? 0.0) > 0.2) {
            $reasons[] = 'vector_similarity';
        }
        if ((float) ($components['lexical'] ?? 0.0) > 0.0) {
            $reasons[] = 'keyword_overlap';
        }
        if ((float) ($components['metadata'] ?? 0.0) > 0.0) {
            $reasons[] = 'source_priority';
        }
        if ($reasons === []) {
            $reasons[] = 'ordered_source';
        }

        return $reasons;
    }

    private function knowledgeTypeLabel(string $type): string
    {
        return match ($this->normalizeKnowledgeType($type)) {
            'product_manual' => '产品手册',
            'faq' => 'FAQ',
            'competitor_analysis' => '竞品分析',
            'troubleshooting' => '故障排查',
            'technical_spec' => '技术规格',
            'policy' => '规则/合规',
            'marketing_copy' => '营销文案',
            'other' => '其他资料',
            default => '参考资料',
        };
    }

    private function knowledgeRoleLabel(string $role): string
    {
        return match ($this->normalizeKnowledgeRole($role)) {
            'primary_source' => '事实依据',
            'constraint' => '规则约束',
            'comparison_reference' => '对比参考',
            'style_reference' => '风格参考',
            'archive' => '归档资料',
            default => '补充语境',
        };
    }

    private function knowledgeRoleInstruction(string $role): string
    {
        return match ($this->normalizeKnowledgeRole($role)) {
            'primary_source' => '优先作为事实依据，关键参数和产品说明以此为准。',
            'constraint' => '作为规则边界优先遵守，避免生成与其冲突的内容。',
            'comparison_reference' => '用于对比、差异化表达和竞品定位，不要把竞品信息写成本产品事实。',
            'style_reference' => '仅参考表达风格和结构，不作为事实来源。',
            'archive' => '仅在用户手动指定时参考；默认不参与自动生成上下文。',
            default => '作为补充背景使用，用于完善语境和解释。',
        };
    }
}
