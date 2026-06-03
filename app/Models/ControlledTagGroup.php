<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ControlledTagGroup extends Model
{
    protected $table = 'controlled_tag_groups';

    protected $fillable = [
        'name',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }
}
