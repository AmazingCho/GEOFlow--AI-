<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Support\Facades\DB;

class TitleLibrary extends Model
{
    protected $table = 'title_libraries';

    protected $fillable = [
        'collection_id',
        'name',
        'description',
        'title_count',
        'generation_type',
        'keyword_library_id',
        'ai_model_id',
        'prompt_id',
        'generation_rounds',
        'is_ai_generated',
    ];

    protected function casts(): array
    {
        return [
            'collection_id' => 'integer',
            'title_count' => 'integer',
            'keyword_library_id' => 'integer',
            'ai_model_id' => 'integer',
            'prompt_id' => 'integer',
            'generation_rounds' => 'integer',
            'is_ai_generated' => 'integer',
        ];
    }

    public function keywordLibrary(): BelongsTo
    {
        return $this->belongsTo(KeywordLibrary::class, 'keyword_library_id');
    }

    public function collection(): BelongsTo
    {
        return $this->belongsTo(CollectionRecord::class, 'collection_id');
    }

    public function aiModel(): BelongsTo
    {
        return $this->belongsTo(AiModel::class, 'ai_model_id');
    }

    public function prompt(): BelongsTo
    {
        return $this->belongsTo(Prompt::class, 'prompt_id');
    }

    public function titles(): HasMany
    {
        return $this->hasMany(Title::class, 'library_id');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class, 'title_library_id');
    }

    public function tags(): MorphToMany
    {
        return $this->morphToMany(Tag::class, 'taggable')->withTimestamps();
    }

    protected static function booted(): void
    {
        static::deleting(static function (TitleLibrary $library): void {
            $library->tags()->detach();
            DB::table('entity_material_links')
                ->where('linkable_type', self::class)
                ->where('linkable_id', (int) $library->id)
                ->delete();
        });
    }
}
