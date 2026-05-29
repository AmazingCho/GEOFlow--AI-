<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

class EntityRecord extends Model
{
    protected $table = 'entities';

    protected $fillable = [
        'name',
        'entity_type',
        'aliases',
        'description',
        'attributes_json',
        'source_url',
        'usage_count',
    ];

    protected function casts(): array
    {
        return [
            'usage_count' => 'integer',
        ];
    }

    public function cases(): HasMany
    {
        return $this->hasMany(CaseRecord::class, 'entity_id');
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
        });
    }
}
