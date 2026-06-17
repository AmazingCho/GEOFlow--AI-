<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KnowledgeCorrection extends Model
{
    protected $table = 'knowledge_corrections';

    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_APPLIED = 'applied';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_APPROVED,
        self::STATUS_REJECTED,
        self::STATUS_APPLIED,
    ];

    protected $fillable = [
        'article_id',
        'knowledge_base_id',
        'knowledge_chunk_id',
        'reported_by_admin_id',
        'reviewed_by_admin_id',
        'ai_model_id',
        'status',
        'error_description',
        'selected_article_text',
        'retrieved_context',
        'ai_result',
        'confirmed_error',
        'error_type',
        'suggested_content',
        'reasoning',
        'confidence',
        'review_note',
        'applied_at',
    ];

    protected function casts(): array
    {
        return [
            'article_id' => 'integer',
            'knowledge_base_id' => 'integer',
            'knowledge_chunk_id' => 'integer',
            'reported_by_admin_id' => 'integer',
            'reviewed_by_admin_id' => 'integer',
            'ai_model_id' => 'integer',
            'retrieved_context' => 'array',
            'ai_result' => 'array',
            'confirmed_error' => 'boolean',
            'confidence' => 'float',
            'applied_at' => 'datetime',
        ];
    }

    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class, 'article_id');
    }

    public function knowledgeBase(): BelongsTo
    {
        return $this->belongsTo(KnowledgeBase::class, 'knowledge_base_id');
    }

    public function chunk(): BelongsTo
    {
        return $this->belongsTo(KnowledgeChunk::class, 'knowledge_chunk_id');
    }

    public function reportedBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'reported_by_admin_id');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'reviewed_by_admin_id');
    }

    public function aiModel(): BelongsTo
    {
        return $this->belongsTo(AiModel::class, 'ai_model_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(KnowledgeChunkVersion::class, 'knowledge_correction_id');
    }
}
