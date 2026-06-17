<?php

namespace App\Services\GeoFlow;

use App\Models\AiModel;
use App\Models\Article;
use App\Models\KnowledgeBase;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeChunkVersion;
use App\Models\KnowledgeCorrection;
use App\Models\TaskRun;
use App\Support\GeoFlow\ApiKeyCrypto;
use App\Support\GeoFlow\OpenAiRuntimeProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class KnowledgeCorrectionService
{
    public function __construct(
        private readonly ApiKeyCrypto $apiKeyCrypto,
        private readonly KnowledgeChunkSyncService $chunkSyncService
    ) {}

    /**
     * @param  array{
     *   source_type:string,
     *   error_description:string,
     *   selected_article_text?:string|null,
     *   article_id?:int|null,
     *   knowledge_base_id?:int|null,
     *   knowledge_chunk_id?:int|null,
     *   ai_model_id?:int|null,
     *   reported_by_admin_id?:int|null
     * }  $payload
     */
    public function createProposal(array $payload): KnowledgeCorrection
    {
        $errorDescription = trim((string) ($payload['error_description'] ?? ''));
        if ($errorDescription === '') {
            throw new RuntimeException(__('admin.knowledge_corrections.error.description_required'));
        }

        $articleId = (int) ($payload['article_id'] ?? 0);
        $knowledgeBaseId = (int) ($payload['knowledge_base_id'] ?? 0);
        $knowledgeChunkId = (int) ($payload['knowledge_chunk_id'] ?? 0);
        $selectedArticleText = trim((string) ($payload['selected_article_text'] ?? ''));
        $chunks = $this->retrieveRelevantChunks($knowledgeBaseId, $knowledgeChunkId, $articleId, $errorDescription, $selectedArticleText);
        if ($chunks === []) {
            throw new RuntimeException(__('admin.knowledge_corrections.error.no_chunks'));
        }

        $primaryChunk = $chunks[0];
        $primaryKnowledgeBaseId = (int) $primaryChunk->knowledge_base_id;
        $model = $this->resolveModel((int) ($payload['ai_model_id'] ?? 0));
        $aiResult = $this->generateProposal(
            $model,
            $errorDescription,
            $selectedArticleText,
            $chunks
        );

        return KnowledgeCorrection::query()->create([
            'article_id' => $articleId > 0 ? $articleId : null,
            'knowledge_base_id' => $primaryKnowledgeBaseId,
            'knowledge_chunk_id' => (int) $primaryChunk->id,
            'reported_by_admin_id' => (int) ($payload['reported_by_admin_id'] ?? 0) ?: null,
            'ai_model_id' => $model ? (int) $model->id : null,
            'status' => KnowledgeCorrection::STATUS_PENDING,
            'error_description' => $errorDescription,
            'selected_article_text' => $selectedArticleText !== '' ? $selectedArticleText : null,
            'retrieved_context' => $this->contextForStorage($chunks),
            'ai_result' => $aiResult,
            'confirmed_error' => (bool) ($aiResult['confirmed_error'] ?? false),
            'error_type' => mb_substr((string) ($aiResult['error_type'] ?? ''), 0, 80, 'UTF-8'),
            'suggested_content' => trim((string) ($aiResult['suggested_content'] ?? '')),
            'reasoning' => trim((string) ($aiResult['reasoning'] ?? '')),
            'confidence' => $this->normalizeConfidence($aiResult['confidence'] ?? 0),
        ]);
    }

    public function approve(KnowledgeCorrection $correction, int $adminId, string $note = ''): KnowledgeCorrection
    {
        if ($correction->status === KnowledgeCorrection::STATUS_APPLIED) {
            throw new RuntimeException(__('admin.knowledge_corrections.error.already_applied'));
        }

        $correction->forceFill([
            'status' => KnowledgeCorrection::STATUS_APPROVED,
            'reviewed_by_admin_id' => $adminId > 0 ? $adminId : null,
            'review_note' => trim($note),
        ])->save();

        return $correction->refresh();
    }

    public function reject(KnowledgeCorrection $correction, int $adminId, string $note = ''): KnowledgeCorrection
    {
        if ($correction->status === KnowledgeCorrection::STATUS_APPLIED) {
            throw new RuntimeException(__('admin.knowledge_corrections.error.already_applied'));
        }

        $correction->forceFill([
            'status' => KnowledgeCorrection::STATUS_REJECTED,
            'reviewed_by_admin_id' => $adminId > 0 ? $adminId : null,
            'review_note' => trim($note),
        ])->save();

        return $correction->refresh();
    }

    public function apply(KnowledgeCorrection $correction, int $adminId, string $note = ''): KnowledgeCorrection
    {
        if ($correction->status === KnowledgeCorrection::STATUS_REJECTED) {
            throw new RuntimeException(__('admin.knowledge_corrections.error.rejected_cannot_apply'));
        }
        if ($correction->status === KnowledgeCorrection::STATUS_APPLIED) {
            throw new RuntimeException(__('admin.knowledge_corrections.error.already_applied'));
        }

        $suggestedContent = trim((string) ($correction->suggested_content ?? ''));
        if ($suggestedContent === '') {
            throw new RuntimeException(__('admin.knowledge_corrections.error.suggestion_required'));
        }

        $version = DB::transaction(function () use ($correction, $adminId, $note, $suggestedContent): KnowledgeChunkVersion {
            /** @var KnowledgeCorrection $lockedCorrection */
            $lockedCorrection = KnowledgeCorrection::query()->whereKey((int) $correction->id)->lockForUpdate()->firstOrFail();
            /** @var KnowledgeChunk|null $chunk */
            $chunk = KnowledgeChunk::query()->whereKey((int) $lockedCorrection->knowledge_chunk_id)->lockForUpdate()->first();
            if (! $chunk) {
                throw new RuntimeException(__('admin.knowledge_corrections.error.chunk_missing'));
            }
            /** @var KnowledgeBase $knowledgeBase */
            $knowledgeBase = KnowledgeBase::query()->whereKey((int) $chunk->knowledge_base_id)->lockForUpdate()->firstOrFail();

            $oldContent = (string) $chunk->content;
            $updatedKnowledgeContent = $this->replaceChunkInKnowledgeBase((string) $knowledgeBase->content, $oldContent, $suggestedContent);
            $versionNo = ((int) KnowledgeChunkVersion::query()
                ->where('knowledge_base_id', (int) $knowledgeBase->id)
                ->where('knowledge_chunk_id', (int) $chunk->id)
                ->max('version_no')) + 1;

            $version = KnowledgeChunkVersion::query()->create([
                'knowledge_correction_id' => (int) $lockedCorrection->id,
                'knowledge_base_id' => (int) $knowledgeBase->id,
                'knowledge_chunk_id' => (int) $chunk->id,
                'version_no' => $versionNo,
                'old_content' => $oldContent,
                'new_content' => $suggestedContent,
                'old_embedding_hash' => $this->embeddingHash($chunk),
                'new_embedding_hash' => '',
                'changed_by_admin_id' => $adminId > 0 ? $adminId : null,
                'change_reason' => trim($note) !== '' ? trim($note) : __('admin.knowledge_corrections.default_apply_reason'),
            ]);

            $knowledgeBase->forceFill([
                'content' => $updatedKnowledgeContent,
                'character_count' => mb_strlen($updatedKnowledgeContent, 'UTF-8'),
                'word_count' => mb_strlen(strip_tags($updatedKnowledgeContent), 'UTF-8'),
            ])->save();

            $chunk->forceFill([
                'content' => $suggestedContent,
            ])->save();
            $this->chunkSyncService->refreshSingleChunk($chunk->refresh(), false);

            $version->forceFill([
                'new_embedding_hash' => $this->embeddingHash($chunk->refresh()),
            ])->save();

            $lockedCorrection->forceFill([
                'status' => KnowledgeCorrection::STATUS_APPLIED,
                'reviewed_by_admin_id' => $adminId > 0 ? $adminId : null,
                'review_note' => trim($note),
                'applied_at' => now(),
            ])->save();

            return $version;
        });

        return $version->correction()->with(['knowledgeBase', 'chunk', 'versions'])->firstOrFail();
    }

    public function rollback(KnowledgeChunkVersion $version, int $adminId, string $note = ''): KnowledgeCorrection
    {
        $rollbackVersion = DB::transaction(function () use ($version, $adminId, $note): KnowledgeChunkVersion {
            /** @var KnowledgeChunkVersion $lockedVersion */
            $lockedVersion = KnowledgeChunkVersion::query()->whereKey((int) $version->id)->lockForUpdate()->firstOrFail();
            /** @var KnowledgeChunk|null $chunk */
            $chunk = KnowledgeChunk::query()->whereKey((int) $lockedVersion->knowledge_chunk_id)->lockForUpdate()->first();
            if (! $chunk) {
                throw new RuntimeException(__('admin.knowledge_corrections.error.chunk_missing'));
            }
            /** @var KnowledgeBase $knowledgeBase */
            $knowledgeBase = KnowledgeBase::query()->whereKey((int) $lockedVersion->knowledge_base_id)->lockForUpdate()->firstOrFail();

            $currentContent = (string) $chunk->content;
            $restoreContent = (string) $lockedVersion->old_content;
            $updatedKnowledgeContent = $this->replaceChunkInKnowledgeBase((string) $knowledgeBase->content, $currentContent, $restoreContent);
            $versionNo = ((int) KnowledgeChunkVersion::query()
                ->where('knowledge_base_id', (int) $knowledgeBase->id)
                ->where('knowledge_chunk_id', (int) $chunk->id)
                ->max('version_no')) + 1;

            $rollbackVersion = KnowledgeChunkVersion::query()->create([
                'knowledge_correction_id' => $lockedVersion->knowledge_correction_id,
                'knowledge_base_id' => (int) $knowledgeBase->id,
                'knowledge_chunk_id' => (int) $chunk->id,
                'version_no' => $versionNo,
                'old_content' => $currentContent,
                'new_content' => $restoreContent,
                'old_embedding_hash' => $this->embeddingHash($chunk),
                'new_embedding_hash' => '',
                'changed_by_admin_id' => $adminId > 0 ? $adminId : null,
                'change_reason' => trim($note) !== '' ? trim($note) : __('admin.knowledge_corrections.default_rollback_reason'),
            ]);

            $knowledgeBase->forceFill([
                'content' => $updatedKnowledgeContent,
                'character_count' => mb_strlen($updatedKnowledgeContent, 'UTF-8'),
                'word_count' => mb_strlen(strip_tags($updatedKnowledgeContent), 'UTF-8'),
            ])->save();

            $chunk->forceFill([
                'content' => $restoreContent,
            ])->save();
            $this->chunkSyncService->refreshSingleChunk($chunk->refresh(), false);

            $rollbackVersion->forceFill([
                'new_embedding_hash' => $this->embeddingHash($chunk->refresh()),
            ])->save();

            return $rollbackVersion;
        });

        return $rollbackVersion->correction()->firstOrFail();
    }

    /**
     * @return list<KnowledgeChunk>
     */
    private function retrieveRelevantChunks(int $knowledgeBaseId, int $knowledgeChunkId, int $articleId, string $errorDescription, string $selectedArticleText): array
    {
        if ($knowledgeChunkId > 0) {
            $query = KnowledgeChunk::query()->with('knowledgeBase')->whereKey($knowledgeChunkId);
            if ($knowledgeBaseId > 0) {
                $query->where('knowledge_base_id', $knowledgeBaseId);
            }

            return $query->get()->all();
        }

        $knowledgeBaseIds = $knowledgeBaseId > 0 ? [$knowledgeBaseId] : $this->knowledgeBaseIdsFromArticle($articleId);
        if ($knowledgeBaseIds === []) {
            $knowledgeBaseIds = KnowledgeBase::query()
                ->where(function ($query): void {
                    $query->whereNull('status')->orWhere('status', 'active');
                })
                ->where(function ($query): void {
                    $query->whereNull('knowledge_role')->orWhere('knowledge_role', '!=', 'archive');
                })
                ->orderByDesc('importance')
                ->orderByDesc('updated_at')
                ->limit(12)
                ->pluck('id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->all();
        }

        $chunks = KnowledgeChunk::query()
            ->with('knowledgeBase')
            ->whereIn('knowledge_base_id', $knowledgeBaseIds)
            ->orderBy('knowledge_base_id')
            ->orderBy('chunk_index')
            ->limit(80)
            ->get()
            ->all();

        $queryText = trim($errorDescription."\n".$selectedArticleText);
        if ($queryText === '') {
            return array_slice($chunks, 0, 5);
        }

        $queryTerms = $this->termFrequencies($queryText);
        usort($chunks, function (KnowledgeChunk $left, KnowledgeChunk $right) use ($queryTerms): int {
            $leftScore = $this->lexicalScore($queryTerms, $this->termFrequencies((string) $left->content));
            $rightScore = $this->lexicalScore($queryTerms, $this->termFrequencies((string) $right->content));
            $diff = $rightScore <=> $leftScore;

            return $diff !== 0 ? $diff : ((int) $left->chunk_index <=> (int) $right->chunk_index);
        });

        return array_slice($chunks, 0, 5);
    }

    /**
     * @return list<int>
     */
    private function knowledgeBaseIdsFromArticle(int $articleId): array
    {
        if ($articleId <= 0) {
            return [];
        }

        $article = Article::query()->whereKey($articleId)->first(['id', 'used_knowledge_base_ids', 'context_snapshot']);
        if (! $article) {
            return [];
        }

        $ids = collect((array) ($article->used_knowledge_base_ids ?? []))
            ->map(static fn (mixed $id): int => (int) $id)
            ->filter()
            ->all();

        $snapshotIds = collect((array) data_get($article->context_snapshot, 'used_knowledge_base_ids', []))
            ->map(static fn (mixed $id): int => (int) $id)
            ->filter()
            ->all();
        $ids = array_merge($ids, $snapshotIds);

        $run = TaskRun::query()
            ->where('article_id', $articleId)
            ->where('status', 'completed')
            ->orderByDesc('finished_at')
            ->orderByDesc('id')
            ->first(['meta']);
        $trace = is_array($run?->meta) ? (array) data_get($run->meta, 'generation_trace.knowledge', []) : [];
        foreach ((array) ($trace['knowledge_base_ids'] ?? []) as $id) {
            $ids[] = (int) $id;
        }
        foreach ((array) ($trace['knowledge_bases'] ?? []) as $row) {
            if (is_array($row)) {
                $ids[] = (int) ($row['id'] ?? 0);
            }
        }
        foreach ((array) ($trace['chunks'] ?? []) as $row) {
            if (is_array($row)) {
                $ids[] = (int) ($row['knowledge_base_id'] ?? 0);
            }
        }

        return array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));
    }

    /**
     * @param  list<KnowledgeChunk>  $chunks
     * @return array<string,mixed>
     */
    private function generateProposal(?AiModel $model, string $errorDescription, string $selectedArticleText, array $chunks): array
    {
        $fallback = $this->fallbackProposal($errorDescription, $chunks);
        if (! $model) {
            return $fallback;
        }

        try {
            $content = $this->requestAiJson($model, $errorDescription, $selectedArticleText, $chunks);
            $decoded = json_decode($content, true);
            if (! is_array($decoded)) {
                return $fallback;
            }

            return array_replace($fallback, $this->normalizeAiResult($decoded, $errorDescription, $chunks));
        } catch (Throwable) {
            return $fallback;
        }
    }

    /**
     * @param  list<KnowledgeChunk>  $chunks
     */
    private function requestAiJson(AiModel $model, string $errorDescription, string $selectedArticleText, array $chunks): string
    {
        $providerUrl = OpenAiRuntimeProvider::resolveChatBaseUrl((string) ($model->api_url ?? ''));
        $apiKey = $this->apiKeyCrypto->decrypt((string) ($model->getRawOriginal('api_key') ?? ''));
        if ($providerUrl === '' || $apiKey === '') {
            throw new RuntimeException('AI model is not configured');
        }

        $driver = OpenAiRuntimeProvider::resolveChatDriver($providerUrl, (string) ($model->model_id ?? ''));
        $providerName = OpenAiRuntimeProvider::registerProvider('knowledge_correction', $driver, $providerUrl, $apiKey);
        $system = 'You are the GEOFlow AI knowledge correction assistant. Return strict JSON only.';
        $prompt = $this->buildPrompt($errorDescription, $selectedArticleText, $chunks);
        $response = agent($system)->prompt($prompt, [], $providerName, (string) ($model->model_id ?? ''));
        $content = OpenAiRuntimeProvider::normalizeGeneratedText((string) ($response->text ?? ''));
        if ($content === '') {
            throw new RuntimeException('empty ai response');
        }

        return trim(preg_replace('/^```(?:json)?|```$/m', '', $content) ?? $content);
    }

    /**
     * @param  list<KnowledgeChunk>  $chunks
     */
    private function buildPrompt(string $errorDescription, string $selectedArticleText, array $chunks): string
    {
        $contexts = [];
        foreach ($chunks as $index => $chunk) {
            $contexts[] = implode("\n", [
                'Context #'.($index + 1),
                'knowledge_base_id: '.(int) $chunk->knowledge_base_id,
                'knowledge_base_name: '.(string) ($chunk->knowledgeBase?->name ?? ''),
                'knowledge_chunk_id: '.(int) $chunk->id,
                'chunk_index: '.(int) $chunk->chunk_index,
                'content:',
                (string) $chunk->content,
            ]);
        }

        return implode("\n\n", array_filter([
            'Analyze whether the reported issue is truly present in the retrieved knowledge chunks.',
            'Never invent facts that are not supported by the source text or user-provided error description.',
            'If a correction is needed, suggested_content MUST be the full replacement content for the single most relevant knowledge chunk, not a partial patch.',
            'If the error is not confirmed, keep suggested_content equal to the original chunk content and set confirmed_error=false.',
            'Return only this JSON object: {"confirmed_error":true|false,"error_type":"factual|outdated|translation|formatting|table_parse|duplicate|unclear|other","original_error_description":"...","suggested_content":"...","reasoning":"...","confidence":0.0}',
            $selectedArticleText !== '' ? "Selected article text:\n".$selectedArticleText : '',
            "Reported issue:\n".$errorDescription,
            "Retrieved knowledge chunks:\n".implode("\n\n---\n\n", $contexts),
        ], static fn (string $part): bool => trim($part) !== ''));
    }

    /**
     * @param  list<KnowledgeChunk>  $chunks
     * @return array<string,mixed>
     */
    private function fallbackProposal(string $errorDescription, array $chunks): array
    {
        $chunk = $chunks[0] ?? null;

        return [
            'confirmed_error' => false,
            'error_type' => 'unclear',
            'original_error_description' => $errorDescription,
            'suggested_content' => $chunk ? (string) $chunk->content : '',
            'reasoning' => __('admin.knowledge_corrections.fallback_reasoning'),
            'confidence' => 0.0,
        ];
    }

    /**
     * @param  list<KnowledgeChunk>  $chunks
     * @return array<string,mixed>
     */
    private function normalizeAiResult(array $payload, string $errorDescription, array $chunks): array
    {
        $allowedTypes = ['factual', 'outdated', 'translation', 'formatting', 'table_parse', 'duplicate', 'unclear', 'other'];
        $errorType = trim((string) ($payload['error_type'] ?? 'other'));
        if (! in_array($errorType, $allowedTypes, true)) {
            $errorType = 'other';
        }

        $suggestedContent = trim((string) ($payload['suggested_content'] ?? ''));
        if ($suggestedContent === '' && isset($chunks[0])) {
            $suggestedContent = (string) $chunks[0]->content;
        }

        return [
            'confirmed_error' => (bool) ($payload['confirmed_error'] ?? false),
            'error_type' => $errorType,
            'original_error_description' => trim((string) ($payload['original_error_description'] ?? $errorDescription)),
            'suggested_content' => $suggestedContent,
            'reasoning' => trim((string) ($payload['reasoning'] ?? '')),
            'confidence' => $this->normalizeConfidence($payload['confidence'] ?? 0),
        ];
    }

    private function resolveModel(int $modelId): ?AiModel
    {
        $query = AiModel::query()
            ->where('status', 'active')
            ->where(function ($builder): void {
                $builder->whereNull('model_type')
                    ->orWhere('model_type', '')
                    ->orWhere('model_type', 'chat');
            });

        if ($modelId > 0) {
            return (clone $query)->whereKey($modelId)->first();
        }

        return $query->orderBy('failover_priority')->orderBy('id')->first();
    }

    /**
     * @param  list<KnowledgeChunk>  $chunks
     * @return list<array<string,mixed>>
     */
    private function contextForStorage(array $chunks): array
    {
        return array_map(static fn (KnowledgeChunk $chunk): array => [
            'knowledge_base_id' => (int) $chunk->knowledge_base_id,
            'knowledge_base_name' => (string) ($chunk->knowledgeBase?->name ?? ''),
            'knowledge_chunk_id' => (int) $chunk->id,
            'chunk_index' => (int) $chunk->chunk_index,
            'chunk_title' => (string) ($chunk->chunk_title ?? ''),
            'section_path' => (string) ($chunk->section_path ?? ''),
            'preview' => Str::limit(trim((string) $chunk->content), 420, ''),
        ], $chunks);
    }

    private function replaceChunkInKnowledgeBase(string $knowledgeContent, string $oldChunkContent, string $newChunkContent): string
    {
        $position = strpos($knowledgeContent, $oldChunkContent);
        if ($position === false) {
            throw new RuntimeException(__('admin.knowledge_corrections.error.source_changed'));
        }

        return substr_replace($knowledgeContent, $newChunkContent, $position, strlen($oldChunkContent));
    }

    private function embeddingHash(KnowledgeChunk $chunk): string
    {
        return hash('sha256', implode('|', [
            (string) ($chunk->embedding_json ?? ''),
            (string) ($chunk->embedding_model_id ?? ''),
            (string) ($chunk->embedding_dimensions ?? ''),
            (string) ($chunk->embedding_provider ?? ''),
            (string) ($chunk->embedding_vector ?? ''),
        ]));
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

    private function normalizeConfidence(mixed $confidence): float
    {
        $value = is_numeric($confidence) ? (float) $confidence : 0.0;
        if ($value > 1.0 && $value <= 100.0) {
            $value /= 100.0;
        }

        return max(0.0, min(1.0, $value));
    }
}
