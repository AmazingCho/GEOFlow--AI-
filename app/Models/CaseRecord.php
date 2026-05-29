<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

class CaseRecord extends Model
{
    protected $table = 'case_records';

    protected $fillable = [
        'entity_id',
        'title',
        'case_type',
        'summary',
        'challenge',
        'solution',
        'result',
        'metrics',
        'source_url',
        'usage_count',
    ];

    protected function casts(): array
    {
        return [
            'entity_id' => 'integer',
            'usage_count' => 'integer',
        ];
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(EntityRecord::class, 'entity_id');
    }

    public function tags(): MorphToMany
    {
        return $this->morphToMany(Tag::class, 'taggable')->withTimestamps();
    }

    protected static function booted(): void
    {
        static::deleting(static function (CaseRecord $caseRecord): void {
            $caseRecord->tags()->detach();
        });
    }
}
