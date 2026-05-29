<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

class Tag extends Model
{
    protected $table = 'tags';

    protected $fillable = [
        'type',
        'group_name',
        'name',
        'slug',
        'color',
    ];

    public function displayName(): string
    {
        $group = trim((string) ($this->group_name ?? ''));
        $name = trim((string) ($this->name ?? ''));

        return $group !== '' ? $group.':'.$name : $name;
    }

    public function keywords(): MorphToMany
    {
        return $this->morphedByMany(Keyword::class, 'taggable')->withTimestamps();
    }

    public function images(): MorphToMany
    {
        return $this->morphedByMany(Image::class, 'taggable')->withTimestamps();
    }

    public function knowledgeBases(): MorphToMany
    {
        return $this->morphedByMany(KnowledgeBase::class, 'taggable')->withTimestamps();
    }

    public function entities(): MorphToMany
    {
        return $this->morphedByMany(EntityRecord::class, 'taggable')->withTimestamps();
    }

    public function caseRecords(): MorphToMany
    {
        return $this->morphedByMany(CaseRecord::class, 'taggable')->withTimestamps();
    }
}
