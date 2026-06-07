<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CrmQuote extends Model
{
    protected $table = 'crm_quotes';

    protected $fillable = [
        'collection_id',
        'customer_id',
        'owner',
        'inquiry_id',
        'quote_no',
        'document_type',
        'title',
        'buyer_company',
        'buyer_contact',
        'buyer_phone',
        'buyer_email',
        'buyer_address',
        'buyer_country',
        'document_language',
        'currency',
        'trade_term',
        'port_of_loading',
        'port_of_destination',
        'transport_mode',
        'shipping_mark',
        'origin_country',
        'valid_until',
        'payment_terms',
        'delivery_terms',
        'lead_time',
        'warranty_terms',
        'installation_terms',
        'packing_terms',
        'deposit_percent',
        'status',
        'notes',
        'internal_notes',
        'revision',
        'total_amount',
        'shipping_fee',
        'discount_amount',
        'tax_amount',
        'grand_total',
        'bank_account_json',
        'seller_company_json',
        'signature_notes',
        'contract_terms',
        'governing_law',
        'dispute_resolution',
    ];

    protected function casts(): array
    {
        return [
            'collection_id' => 'integer',
            'customer_id' => 'integer',
            'inquiry_id' => 'integer',
            'valid_until' => 'date',
            'revision' => 'integer',
            'total_amount' => 'decimal:2',
            'shipping_fee' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'grand_total' => 'decimal:2',
            'bank_account_json' => 'array',
            'seller_company_json' => 'array',
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

    public function items(): HasMany
    {
        return $this->hasMany(CrmQuoteItem::class, 'quote_id')->orderBy('sort_order')->orderBy('id');
    }

    public function salesOrders(): HasMany
    {
        return $this->hasMany(CrmSalesOrder::class, 'quote_id');
    }
}
