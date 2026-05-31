<?php

namespace App\Services\GeoFlow;

use App\Models\CaseRecord;
use App\Models\EntityRecord;
use App\Models\KnowledgeBase;
use App\Models\KnowledgeChunk;
use App\Models\Task;
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
        $knowledgeBaseIds = $this->resolveKnowledgeBaseIds($task, $tagFilters);
        $query = trim($title."\n".$keyword);
        $knowledgeContext = '';
        $strategy = 'none';
        $chunks = [];
        $knowledgeBaseTrace = [];

        if ($knowledgeBaseIds !== []) {
            $knowledgeBases = KnowledgeBase::query()
                ->whereIn('id', $knowledgeBaseIds)
                ->orderBy('id')
                ->get(['id', 'name', 'content'])
                ->sortBy(static fn (KnowledgeBase $knowledgeBase): int => array_search((int) $knowledgeBase->id, $knowledgeBaseIds, true) ?: 0)
                ->values()
                ->filter(static fn (KnowledgeBase $knowledgeBase): bool => trim((string) ($knowledgeBase->content ?? '')) !== '')
                ->values();

            $knowledgeBaseTrace = $knowledgeBases
                ->map(static fn (KnowledgeBase $knowledgeBase): array => [
                    'id' => (int) $knowledgeBase->id,
                    'name' => (string) $knowledgeBase->name,
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

        $entityCase = $this->composeTaggedEntityCaseContext($tagFilters, $entityCaseMaxChars);
        $context = trim(implode("\n\n", array_filter(
            [$knowledgeContext, $entityCase['context']],
            static fn (string $part): bool => trim($part) !== ''
        )));

        return [
            'context' => $context,
            'trace' => [
                'query' => $query,
                'tag_filters' => $this->tagFilterLabels($tagFilters),
                'knowledge_base_ids' => $knowledgeBaseIds,
                'knowledge_bases' => $knowledgeBaseTrace,
                'chunks' => $chunks,
                'entities' => $entityCase['entities'],
                'cases' => $entityCase['cases'],
                'strategy' => $strategy,
                'retrieval_engine' => 'rag_retrieval_service',
                'context_length' => mb_strlen($context, 'UTF-8'),
            ],
        ];
    }

    /**
     * @param  list<array{group_name:string,name:string}>  $tagFilters
     * @return list<int>
     */
    private function resolveKnowledgeBaseIds(Task $task, array $tagFilters): array
    {
        $ids = [];
        $knowledgeBaseId = (int) ($task->knowledge_base_id ?? 0);
        if ($knowledgeBaseId > 0) {
            $ids[] = $knowledgeBaseId;
        }

        if ($tagFilters !== []) {
            $tagKnowledgeBaseIds = KnowledgeBase::query()
                ->whereHas('tags', fn ($query) => $this->addExactTagFilterConditions($query, $tagFilters))
                ->pluck('id')
                ->map(static fn ($id): int => (int) $id)
                ->all();
            $ids = array_merge($ids, $tagKnowledgeBaseIds);
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

    /**
     * @param  list<array{group_name:string,name:string}>  $tagFilters
     * @return array{context:string,entities:list<array<string,mixed>>,cases:list<array<string,mixed>>}
     */
    private function composeTaggedEntityCaseContext(array $tagFilters, int $maxChars): array
    {
        if ($tagFilters === []) {
            return ['context' => '', 'entities' => [], 'cases' => []];
        }

        $entities = EntityRecord::query()
            ->whereHas('tags', fn ($query) => $this->addExactTagFilterConditions($query, $tagFilters))
            ->orderBy('name')
            ->limit(12)
            ->get(['id', 'name', 'entity_type', 'aliases', 'description', 'attributes_json']);

        $cases = CaseRecord::query()
            ->with('entity:id,name')
            ->where(function ($query) use ($tagFilters): void {
                $query
                    ->whereHas('tags', fn ($tagQuery) => $this->addExactTagFilterConditions($tagQuery, $tagFilters))
                    ->orWhereHas('entity.tags', fn ($tagQuery) => $this->addExactTagFilterConditions($tagQuery, $tagFilters));
            })
            ->orderByDesc('id')
            ->limit(12)
            ->get(['id', 'entity_id', 'title', 'case_type', 'summary', 'challenge', 'solution', 'result', 'metrics']);

        $entityTrace = $entities
            ->map(static fn (EntityRecord $entity): array => [
                'id' => (int) $entity->id,
                'name' => (string) $entity->name,
                'type' => (string) ($entity->entity_type ?? ''),
            ])
            ->values()
            ->all();
        $caseTrace = $cases
            ->map(static fn (CaseRecord $caseRecord): array => [
                'id' => (int) $caseRecord->id,
                'title' => (string) $caseRecord->title,
                'type' => (string) ($caseRecord->case_type ?? ''),
                'entity_id' => $caseRecord->entity_id !== null ? (int) $caseRecord->entity_id : null,
                'entity_name' => (string) ($caseRecord->entity?->name ?? ''),
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
                if ($caseRecord->entity) {
                    $line .= '，关联实体：'.(string) $caseRecord->entity->name;
                }
                $lines[] = $line;

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
            $score = ($vectorScore * 0.75) + ($lexicalScore * 0.25);

            $scored[] = [
                'knowledge_base_id' => (int) ($row->knowledge_base_id ?? 0),
                'knowledge_base_name' => (string) ($row->knowledge_base_name ?? ''),
                'chunk_index' => (int) ($row->chunk_index ?? 0),
                'content' => $content,
                'score' => $score,
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
     * @return list<array{knowledge_base_id:int,knowledge_base_name:string,chunk_index:int,content:string,score:float}>
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
                SELECT kc.knowledge_base_id, kb.name AS knowledge_base_name, kc.chunk_index, kc.content,
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
            $results[] = [
                'knowledge_base_id' => (int) ($row->knowledge_base_id ?? 0),
                'knowledge_base_name' => (string) ($row->knowledge_base_name ?? ''),
                'chunk_index' => (int) ($row->chunk_index ?? 0),
                'content' => $content,
                'score' => 1.0 - $distance,
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
     * @param  list<array{knowledge_base_id?:int,knowledge_base_name?:string,chunk_index:int,content:string,score:float}>  $scored
     * @return array{context:string,chunks:list<array<string,mixed>>}
     */
    private function composeKnowledgeContext(array $scored, int $limit, int $maxChars): array
    {
        if ($scored === []) {
            return ['context' => '', 'chunks' => []];
        }

        $selected = array_slice($scored, 0, max(1, $limit));
        usort($selected, static fn (array $a, array $b): int => (($a['knowledge_base_id'] ?? 0) <=> ($b['knowledge_base_id'] ?? 0)) ?: ($a['chunk_index'] <=> $b['chunk_index']));
        $chunkTrace = array_map(static fn (array $chunk): array => [
            'knowledge_base_id' => (int) ($chunk['knowledge_base_id'] ?? 0),
            'knowledge_base_name' => (string) ($chunk['knowledge_base_name'] ?? ''),
            'chunk_index' => (int) ($chunk['chunk_index'] ?? 0),
            'score' => round((float) ($chunk['score'] ?? 0), 6),
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
            $heading = '【知识片段'.($index + 1).($source !== '' ? ' / 知识库：'.$source : '').'】';
            $parts[] = $heading."\n".$content;
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
            $block = ($name !== '' ? "【知识库：{$name}】\n" : '').$content;
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
}
