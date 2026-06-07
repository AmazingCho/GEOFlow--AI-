<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RelationType extends Model
{
    protected $fillable = [
        'name',
        'forward_label',
        'reverse_label',
        'bidirectional',
        'description',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'bidirectional' => 'boolean',
            'sort_order' => 'integer',
        ];
    }
}
