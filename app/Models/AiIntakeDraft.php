<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiIntakeDraft extends Model
{
    public const STATUS_NEEDS_REVIEW = 'needs_review';
    public const STATUS_APPLIED = 'applied';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_FAILED = 'failed';

    protected $table = 'ai_intake_drafts';

    protected $fillable = [
        'source',
        'source_reference',
        'collection_id',
        'raw_input',
        'normalized_summary',
        'status',
        'confidence',
        'detected_language',
        'created_by_admin_id',
        'reviewed_by_admin_id',
        'applied_at',
        'rejected_reason',
        'metadata_json',
    ];

    protected function casts(): array
    {
        return [
            'collection_id' => 'integer',
            'confidence' => 'float',
            'created_by_admin_id' => 'integer',
            'reviewed_by_admin_id' => 'integer',
            'applied_at' => 'datetime',
            'metadata_json' => 'array',
        ];
    }

    public function actions(): HasMany
    {
        return $this->hasMany(AiIntakeAction::class, 'draft_id')->orderBy('id');
    }

    public function collection(): BelongsTo
    {
        return $this->belongsTo(CollectionRecord::class, 'collection_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'created_by_admin_id');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'reviewed_by_admin_id');
    }
}
