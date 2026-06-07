<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CollectionRecord extends Model
{
    protected $table = 'collections';

    protected $fillable = [
        'name',
        'slug',
        'description',
        'status',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }

    public function knowledgeBases(): HasMany
    {
        return $this->hasMany(KnowledgeBase::class, 'collection_id');
    }

    public function entities(): HasMany
    {
        return $this->hasMany(EntityRecord::class, 'collection_id');
    }

    public function cases(): HasMany
    {
        return $this->hasMany(CaseRecord::class, 'collection_id');
    }

    public function keywordLibraries(): HasMany
    {
        return $this->hasMany(KeywordLibrary::class, 'collection_id');
    }

    public function titleLibraries(): HasMany
    {
        return $this->hasMany(TitleLibrary::class, 'collection_id');
    }

    public function imageLibraries(): HasMany
    {
        return $this->hasMany(ImageLibrary::class, 'collection_id');
    }

    public function crmCustomers(): HasMany
    {
        return $this->hasMany(CrmCustomer::class, 'collection_id');
    }

    public function crmInquiries(): HasMany
    {
        return $this->hasMany(CrmInquiry::class, 'collection_id');
    }

    public function crmQuotes(): HasMany
    {
        return $this->hasMany(CrmQuote::class, 'collection_id');
    }

    public function crmSalesOrders(): HasMany
    {
        return $this->hasMany(CrmSalesOrder::class, 'collection_id');
    }

    public function crmAfterSalesTickets(): HasMany
    {
        return $this->hasMany(CrmAfterSalesTicket::class, 'collection_id');
    }

    public function isActive(): bool
    {
        return (string) $this->status === 'active';
    }
}
