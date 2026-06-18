<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiIntakeAction extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPLIED = 'applied';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_FAILED = 'failed';

    protected $table = 'ai_intake_actions';

    protected $fillable = [
        'draft_id',
        'action_type',
        'target_type',
        'target_id',
        'payload_json',
        'relation_json',
        'diff_json',
        'confidence',
        'risk_level',
        'status',
        'error_message',
        'applied_target_type',
        'applied_target_id',
        'applied_at',
    ];

    protected function casts(): array
    {
        return [
            'draft_id' => 'integer',
            'target_id' => 'integer',
            'payload_json' => 'array',
            'relation_json' => 'array',
            'diff_json' => 'array',
            'confidence' => 'float',
            'applied_target_id' => 'integer',
            'applied_at' => 'datetime',
        ];
    }

    public function draft(): BelongsTo
    {
        return $this->belongsTo(AiIntakeDraft::class, 'draft_id');
    }
}
