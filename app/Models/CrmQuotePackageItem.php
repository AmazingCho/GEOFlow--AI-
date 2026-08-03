<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CrmQuotePackageItem extends Model
{
    protected $table = 'crm_quote_package_items';

    protected $fillable = [
        'package_id',
        'quote_id',
        'quote_item_id',
        'allocated_quantity',
    ];

    protected function casts(): array
    {
        return [
            'package_id' => 'integer',
            'quote_id' => 'integer',
            'quote_item_id' => 'integer',
            'allocated_quantity' => 'decimal:2',
        ];
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(CrmQuotePackage::class, 'package_id');
    }

    public function quoteItem(): BelongsTo
    {
        return $this->belongsTo(CrmQuoteItem::class, 'quote_item_id');
    }
}
