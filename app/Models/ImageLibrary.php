<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class ImageLibrary extends Model
{
    protected $table = 'image_libraries';

    protected $fillable = [
        'collection_id',
        'name',
        'description',
        'image_count',
        'used_task_count',
    ];

    protected function casts(): array
    {
        return [
            'collection_id' => 'integer',
            'image_count' => 'integer',
            'used_task_count' => 'integer',
        ];
    }

    public function images(): HasMany
    {
        return $this->hasMany(Image::class, 'library_id');
    }

    public function collection(): BelongsTo
    {
        return $this->belongsTo(CollectionRecord::class, 'collection_id');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class, 'image_library_id');
    }

    protected static function booted(): void
    {
        static::deleting(static function (ImageLibrary $library): void {
            DB::table('entity_material_links')
                ->where('linkable_type', self::class)
                ->where('linkable_id', (int) $library->id)
                ->delete();
        });
    }
}
