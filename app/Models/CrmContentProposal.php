<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CrmContentProposal extends Model
{
    protected $table = 'crm_content_proposals';

    protected $fillable = [
        'collection_id',
        'source_type',
        'source_id',
        'proposal_type',
        'title',
        'content',
        'metadata_json',
        'status',
        'applied_target_id',
        'applied_target_type',
        'applied_at',
    ];

    protected function casts(): array
    {
        return [
            'collection_id' => 'integer',
            'source_id' => 'integer',
            'applied_target_id' => 'integer',
            'applied_at' => 'datetime',
        ];
    }

    public function collection(): BelongsTo
    {
        return $this->belongsTo(CollectionRecord::class, 'collection_id');
    }
}
