<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CrmQuotePackage extends Model
{
    protected $table = 'crm_quote_packages';

    protected $fillable = [
        'quote_id',
        'package_no',
        'package_type',
        'package_length',
        'package_width',
        'package_height',
        'net_weight',
        'gross_weight',
        'volume_cbm',
        'volume_is_manual',
        'notes',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'quote_id' => 'integer',
            'package_length' => 'decimal:1',
            'package_width' => 'decimal:1',
            'package_height' => 'decimal:1',
            'net_weight' => 'decimal:3',
            'gross_weight' => 'decimal:3',
            'volume_cbm' => 'decimal:3',
            'volume_is_manual' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(CrmQuote::class, 'quote_id');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(CrmQuotePackageItem::class, 'package_id')->orderBy('id');
    }
}
