<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class CrmSalesOrder extends Model
{
    use SoftDeletes;
    protected $table = 'crm_sales_orders';

    protected $fillable = [
        'collection_id',
        'customer_id',
        'owner',
        'inquiry_id',
        'quote_id',
        'order_no',
        'title',
        'currency',
        'total_amount',
        'payment_status',
        'production_status',
        'delivery_status',
        'order_status',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'collection_id' => 'integer',
            'customer_id' => 'integer',
            'inquiry_id' => 'integer',
            'quote_id' => 'integer',
            'total_amount' => 'decimal:2',
        ];
    }

    public function collection(): BelongsTo
    {
        return $this->belongsTo(CollectionRecord::class, 'collection_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(CrmCustomer::class, 'customer_id');
    }

    public function inquiry(): BelongsTo
    {
        return $this->belongsTo(CrmInquiry::class, 'inquiry_id');
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(CrmQuote::class, 'quote_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(CrmSalesOrderItem::class, 'order_id')->orderBy('sort_order')->orderBy('id');
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(CrmAfterSalesTicket::class, 'order_id');
    }
}
