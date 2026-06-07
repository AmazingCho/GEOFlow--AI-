<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CrmSalesOrderItem extends Model
{
    protected $table = 'crm_sales_order_items';

    protected $fillable = [
        'order_id',
        'entity_id',
        'item_name',
        'description',
        'quantity',
        'unit',
        'unit_price',
        'amount',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'order_id' => 'integer',
            'entity_id' => 'integer',
            'quantity' => 'decimal:2',
            'unit_price' => 'decimal:2',
            'amount' => 'decimal:2',
            'sort_order' => 'integer',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(CrmSalesOrder::class, 'order_id');
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(EntityRecord::class, 'entity_id');
    }
}
