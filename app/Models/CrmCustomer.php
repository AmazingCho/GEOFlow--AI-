<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CrmCustomer extends Model
{
    protected $table = 'crm_customers';

    protected $fillable = [
        'collection_id',
        'company_name',
        'contact_person',
        'customer_type',
        'country',
        'address',
        'website',
        'industry',
        'source_channel',
        'phone',
        'email',
        'contact_title',
        'owner',
        'status',
        'notes',
        'tags_json',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'collection_id' => 'integer',
        ];
    }

    public function collection(): BelongsTo
    {
        return $this->belongsTo(CollectionRecord::class, 'collection_id');
    }

    public function followUps(): HasMany
    {
        return $this->hasMany(CrmFollowUp::class, 'customer_id');
    }

    public function inquiries(): HasMany
    {
        return $this->hasMany(CrmInquiry::class, 'customer_id');
    }

    public function quotes(): HasMany
    {
        return $this->hasMany(CrmQuote::class, 'customer_id');
    }

    public function salesOrders(): HasMany
    {
        return $this->hasMany(CrmSalesOrder::class, 'customer_id');
    }

    public function afterSalesTickets(): HasMany
    {
        return $this->hasMany(CrmAfterSalesTicket::class, 'customer_id');
    }
}
