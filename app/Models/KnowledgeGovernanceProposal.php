<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KnowledgeGovernanceProposal extends Model
{
    protected $table = 'knowledge_governance_proposals';

    public const TYPE_DUPLICATE_ARCHIVE = 'duplicate_archive';
    public const TYPE_DUPLICATE_MERGE = 'duplicate_merge';
    public const TYPE_CONFLICT_REVIEW = 'conflict_review';

    public const TYPES = [
        self::TYPE_DUPLICATE_ARCHIVE,
        self::TYPE_DUPLICATE_MERGE,
        self::TYPE_CONFLICT_REVIEW,
    ];

    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_APPLIED = 'applied';
    public const STATUS_ROLLED_BACK = 'rolled_back';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_APPROVED,
        self::STATUS_REJECTED,
        self::STATUS_APPLIED,
        self::STATUS_ROLLED_BACK,
    ];

    protected $fillable = [
        'proposal_type',
        'status',
        'collection_id',
        'primary_knowledge_base_id',
        'related_knowledge_base_ids',
        'detection_snapshot',
        'proposed_content',
        'before_content_snapshot',
        'admin_note',
        'created_by_admin_id',
        'applied_by_admin_id',
        'rolled_back_by_admin_id',
        'applied_at',
        'rolled_back_at',
    ];

    protected function casts(): array
    {
        return [
            'collection_id' => 'integer',
            'primary_knowledge_base_id' => 'integer',
            'related_knowledge_base_ids' => 'array',
            'detection_snapshot' => 'array',
            'created_by_admin_id' => 'integer',
            'applied_by_admin_id' => 'integer',
            'rolled_back_by_admin_id' => 'integer',
            'applied_at' => 'datetime',
            'rolled_back_at' => 'datetime',
        ];
    }

    public function collection(): BelongsTo
    {
        return $this->belongsTo(CollectionRecord::class, 'collection_id');
    }

    public function primaryKnowledgeBase(): BelongsTo
    {
        return $this->belongsTo(KnowledgeBase::class, 'primary_knowledge_base_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'created_by_admin_id');
    }

    public function appliedBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'applied_by_admin_id');
    }

    public function rolledBackBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'rolled_back_by_admin_id');
    }
}
