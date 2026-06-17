<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KnowledgeChunkVersion extends Model
{
    protected $table = 'knowledge_chunk_versions';

    protected $fillable = [
        'knowledge_correction_id',
        'knowledge_base_id',
        'knowledge_chunk_id',
        'version_no',
        'old_content',
        'new_content',
        'old_embedding_hash',
        'new_embedding_hash',
        'changed_by_admin_id',
        'change_reason',
    ];

    protected function casts(): array
    {
        return [
            'knowledge_correction_id' => 'integer',
            'knowledge_base_id' => 'integer',
            'knowledge_chunk_id' => 'integer',
            'version_no' => 'integer',
            'changed_by_admin_id' => 'integer',
        ];
    }

    public function correction(): BelongsTo
    {
        return $this->belongsTo(KnowledgeCorrection::class, 'knowledge_correction_id');
    }

    public function knowledgeBase(): BelongsTo
    {
        return $this->belongsTo(KnowledgeBase::class, 'knowledge_base_id');
    }

    public function chunk(): BelongsTo
    {
        return $this->belongsTo(KnowledgeChunk::class, 'knowledge_chunk_id');
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'changed_by_admin_id');
    }
}
