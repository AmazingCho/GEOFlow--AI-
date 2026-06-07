<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CrmQuoteItem extends Model
{
    protected $table = 'crm_quote_items';

    protected $fillable = [
        'quote_id',
        'entity_id',
        'line_type',
        'model',
        'hs_code',
        'image_id',
        'image_path',
        'image_original_name',
        'item_name',
        'description',
        'quantity',
        'unit',
        'unit_price',
        'amount',
        'package_count',
        'net_weight',
        'gross_weight',
        'volume_cbm',
        'package_length',
        'package_width',
        'package_height',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'quote_id' => 'integer',
            'entity_id' => 'integer',
            'image_id' => 'integer',
            'quantity' => 'decimal:2',
            'unit_price' => 'decimal:2',
            'amount' => 'decimal:2',
            'package_count' => 'integer',
            'net_weight' => 'decimal:3',
            'gross_weight' => 'decimal:3',
            'volume_cbm' => 'decimal:3',
            'package_length' => 'decimal:1',
            'package_width' => 'decimal:1',
            'package_height' => 'decimal:1',
            'sort_order' => 'integer',
        ];
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(CrmQuote::class, 'quote_id');
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(EntityRecord::class, 'entity_id');
    }

    public function image(): BelongsTo
    {
        return $this->belongsTo(Image::class, 'image_id');
    }
}
