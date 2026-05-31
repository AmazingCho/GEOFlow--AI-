<?php

namespace App\Jobs;

use App\Models\KnowledgeBase;
use App\Services\GeoFlow\KnowledgeChunkSyncService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SyncKnowledgeBaseChunksJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

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

        $content = trim((string) ($knowledgeBase->content ?? ''));
        if ($content === '') {
            return;
        }

        $chunkSyncService->sync((int) $knowledgeBase->id, $content, $this->requireRealEmbedding);
    }
}
