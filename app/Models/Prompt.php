<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Prompt extends Model
{
    protected $table = 'prompts';

    protected $fillable = [
        'name',
        'type',
        'intent_key',
        'content',
        'variables',
        'preset_key',
        'preset_version',
        'last_synced_hash',
        'is_system',
        'is_enabled',
    ];

    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
            'is_enabled' => 'boolean',
        ];
    }

    public function titleLibraries(): HasMany
    {
        return $this->hasMany(TitleLibrary::class, 'prompt_id');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class, 'prompt_id');
    }

    public function skillTasks(): HasMany
    {
        return $this->hasMany(Task::class, 'skill_prompt_id');
    }

    public function styleTasks(): HasMany
    {
        return $this->hasMany(Task::class, 'style_prompt_id');
    }
}
