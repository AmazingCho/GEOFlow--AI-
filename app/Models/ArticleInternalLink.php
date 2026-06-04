<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ArticleInternalLink extends Model
{
    protected $table = 'article_internal_links';

    protected $fillable = [
        'article_id',
        'entity_id',
        'anchor_text',
        'canonical_url',
        'matched_text',
        'status',
        'applied_by',
        'applied_at',
    ];

    protected function casts(): array
    {
        return [
            'article_id' => 'integer',
            'entity_id' => 'integer',
            'applied_at' => 'datetime',
        ];
    }

    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class, 'article_id');
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(EntityRecord::class, 'entity_id');
    }
}
