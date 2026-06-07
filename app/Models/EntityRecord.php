<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use App\Models\EntityRelation;
use Illuminate\Support\Facades\DB;

class EntityRecord extends Model
{
    protected $table = 'entities';

    protected $fillable = [
        'collection_id',
        'name',
        'entity_type',
        'aliases',
        'description',
        'attributes_json',
        'source_url',
        'canonical_url',
        'link_anchor_text',
        'link_policy',
        'usage_count',
    ];

    protected function casts(): array
    {
        return [
            'collection_id' => 'integer',
            'usage_count' => 'integer',
        ];
    }

    public function collection(): BelongsTo
    {
        return $this->belongsTo(CollectionRecord::class, 'collection_id');
    }

    public function cases(): HasMany
    {
        return $this->hasMany(CaseRecord::class, 'entity_id');
    }

    public function relatedCases(): BelongsToMany
    {
        return $this->belongsToMany(CaseRecord::class, 'case_record_entity', 'entity_id', 'case_record_id')->withTimestamps();
    }

    public function tags(): MorphToMany
    {
        return $this->morphToMany(Tag::class, 'taggable')->withTimestamps();
    }

    protected static function booted(): void
    {
        static::deleting(static function (EntityRecord $entity): void {
            $entity->tags()->detach();
            $entity->cases()->update(['entity_id' => null]);
            DB::table('entity_material_links')->where('entity_id', (int) $entity->id)->delete();
        });
    }

    public function sourceRelations(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(EntityRelation::class, 'source_entity_id')->with('relationType');
    }

    public function targetRelations(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(EntityRelation::class, 'target_entity_id')->with('relationType');
    }
}
