<?php

namespace App\Jobs;

use App\Models\KnowledgeBase;
use App\Models\KnowledgeChunk;
use App\Services\GeoFlow\KnowledgeChunkSyncService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class SyncKnowledgeBaseChunksJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct(
        public readonly int $knowledgeBaseId,
        public readonly bool $requireRealEmbedding = false,
    ) {}

    /**
     * @return list<string>
     */
    public function tags(): array
    {
        return [
            'geoflow',
            'knowledge_base:'.$this->knowledgeBaseId,
            'knowledge_chunks',
        ];
    }

    public function handle(KnowledgeChunkSyncService $chunkSyncService): void
    {
        $knowledgeBase = KnowledgeBase::query()
            ->whereKey($this->knowledgeBaseId)
            ->first(['id', 'content']);
        if (! $knowledgeBase) {
            return;
        }

        $this->markRunning();

        $content = trim((string) ($knowledgeBase->content ?? ''));
        if ($content === '') {
            $this->markCompleted(0, 0, __('admin.knowledge_bases.chunk_sync.empty_content_completed'));

            return;
        }

        $chunkCount = $chunkSyncService->sync((int) $knowledgeBase->id, $content, $this->requireRealEmbedding);
        $vectorizedCount = (int) KnowledgeChunk::query()
            ->where('knowledge_base_id', (int) $knowledgeBase->id)
            ->whereNotNull('embedding_model_id')
            ->where('embedding_dimensions', '>', 0)
            ->count();

        $this->markCompleted($chunkCount, $vectorizedCount, __('admin.knowledge_bases.chunk_sync.completed_message', [
            'chunks' => $chunkCount,
            'vectorized' => $vectorizedCount,
        ]));
    }

    public function failed(Throwable $exception): void
    {
        KnowledgeBase::query()
            ->whereKey($this->knowledgeBaseId)
            ->update([
                'chunk_sync_status' => KnowledgeBase::CHUNK_SYNC_FAILED,
                'chunk_sync_message' => mb_substr($exception->getMessage(), 0, 2000, 'UTF-8'),
                'chunk_sync_failed_at' => now(),
            ]);
    }

    private function markRunning(): void
    {
        KnowledgeBase::query()
            ->whereKey($this->knowledgeBaseId)
            ->update([
                'chunk_sync_status' => KnowledgeBase::CHUNK_SYNC_RUNNING,
                'chunk_sync_message' => __('admin.knowledge_bases.chunk_sync.running_message'),
                'chunk_sync_started_at' => now(),
                'chunk_sync_completed_at' => null,
                'chunk_sync_failed_at' => null,
            ]);
    }

    private function markCompleted(int $chunkCount, int $vectorizedCount, string $message): void
    {
        KnowledgeBase::query()
            ->whereKey($this->knowledgeBaseId)
            ->update([
                'chunk_sync_status' => KnowledgeBase::CHUNK_SYNC_COMPLETED,
                'chunk_sync_message' => $message,
                'chunk_sync_completed_at' => now(),
                'chunk_sync_failed_at' => null,
            ]);
    }
}
