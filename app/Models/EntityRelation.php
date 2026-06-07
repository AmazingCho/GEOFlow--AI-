<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EntityRelation extends Model
{
    protected $table = 'entity_relations';

    protected $fillable = [
        'source_entity_id',
        'relation_type_id',
        'target_entity_id',
        'strength',
        'source_chunk_id',
        'source_type',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'source_entity_id' => 'integer',
            'relation_type_id' => 'integer',
            'target_entity_id' => 'integer',
            'strength' => 'integer',
            'source_chunk_id' => 'integer',
        ];
    }

    public function sourceEntity(): BelongsTo
    {
        return $this->belongsTo(EntityRecord::class, 'source_entity_id');
    }

    public function targetEntity(): BelongsTo
    {
        return $this->belongsTo(EntityRecord::class, 'target_entity_id');
    }

    public function relationType(): BelongsTo
    {
        return $this->belongsTo(RelationType::class, 'relation_type_id');
    }
}
