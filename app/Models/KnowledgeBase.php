<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Support\Facades\DB;

class KnowledgeBase extends Model
{
    protected $table = 'knowledge_bases';

    public const KNOWLEDGE_TYPES = [
        'reference',
        'product_manual',
        'faq',
        'competitor_analysis',
        'troubleshooting',
        'technical_spec',
        'policy',
        'marketing_copy',
        'other',
    ];

    public const KNOWLEDGE_ROLES = [
        'primary_source',
        'supporting_context',
        'constraint',
        'comparison_reference',
        'style_reference',
        'archive',
    ];

    public const CHUNK_SYNC_IDLE = 'idle';

    public const CHUNK_SYNC_QUEUED = 'queued';

    public const CHUNK_SYNC_RUNNING = 'running';

    public const CHUNK_SYNC_COMPLETED = 'completed';

    public const CHUNK_SYNC_FAILED = 'failed';

    protected $fillable = [
        'collection_id',
        'name',
        'description',
        'summary',
        'source_url',
        'content',
        'character_count',
        'used_task_count',
        'file_type',
        'knowledge_type',
        'knowledge_role',
        'importance',
        'status',
        'file_path',
        'word_count',
        'usage_count',
        'chunk_sync_status',
        'chunk_sync_message',
        'chunk_sync_requires_real_embedding',
        'chunk_sync_queued_at',
        'chunk_sync_started_at',
        'chunk_sync_completed_at',
        'chunk_sync_failed_at',
    ];

    protected function casts(): array
    {
        return [
            'collection_id' => 'integer',
            'character_count' => 'integer',
            'used_task_count' => 'integer',
            'importance' => 'integer',
            'word_count' => 'integer',
            'usage_count' => 'integer',
            'chunk_sync_requires_real_embedding' => 'boolean',
            'chunk_sync_queued_at' => 'datetime',
            'chunk_sync_started_at' => 'datetime',
            'chunk_sync_completed_at' => 'datetime',
            'chunk_sync_failed_at' => 'datetime',
        ];
    }

    public function chunks(): HasMany
    {
        return $this->hasMany(KnowledgeChunk::class, 'knowledge_base_id');
    }

    public function corrections(): HasMany
    {
        return $this->hasMany(KnowledgeCorrection::class, 'knowledge_base_id');
    }

    public function collection(): BelongsTo
    {
        return $this->belongsTo(CollectionRecord::class, 'collection_id');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class, 'knowledge_base_id');
    }

    public function tags(): MorphToMany
    {
        return $this->morphToMany(Tag::class, 'taggable')->withTimestamps();
    }

    public function linkedEntities(): MorphToMany
    {
        return $this->morphToMany(
            EntityRecord::class,
            'linkable',
            'entity_material_links',
            'linkable_id',
            'entity_id'
        )->withPivot(['link_role', 'confidence'])->withTimestamps();
    }

    protected static function booted(): void
    {
        static::deleting(static function (KnowledgeBase $knowledgeBase): void {
            $knowledgeBase->tags()->detach();
            DB::table('entity_material_links')
                ->where('linkable_type', self::class)
                ->where('linkable_id', (int) $knowledgeBase->id)
                ->delete();
        });
    }
}
