<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class KeywordLibrary extends Model
{
    protected $table = 'keyword_libraries';

    protected $fillable = [
        'collection_id',
        'name',
        'description',
        'keyword_count',
    ];

    protected function casts(): array
    {
        return [
            'collection_id' => 'integer',
            'keyword_count' => 'integer',
        ];
    }

    public function collection(): BelongsTo
    {
        return $this->belongsTo(CollectionRecord::class, 'collection_id');
    }

    public function keywords(): HasMany
    {
        return $this->hasMany(Keyword::class, 'library_id');
    }

    public function titleLibraries(): HasMany
    {
        return $this->hasMany(TitleLibrary::class, 'keyword_library_id');
    }

    protected static function booted(): void
    {
        static::deleting(static function (KeywordLibrary $library): void {
            DB::table('entity_material_links')
                ->where('linkable_type', self::class)
                ->where('linkable_id', (int) $library->id)
                ->delete();
        });
    }
}
