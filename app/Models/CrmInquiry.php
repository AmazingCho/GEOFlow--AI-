<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

class CrmInquiry extends Model
{
    protected $table = 'crm_inquiries';

    protected $fillable = [
        'collection_id',
        'customer_id',
        'source_channel',
        'source_url',
        'subject',
        'raw_message',
        'detected_language',
        'status',
        'priority',
        'assigned_to',
        'customer_need_summary',
        'product_interest',
        'suggested_reply_points',
        'missing_information_questions',
        'urgency_level',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'collection_id' => 'integer',
            'customer_id' => 'integer',
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

    public function entities(): BelongsToMany
    {
        return $this->belongsToMany(EntityRecord::class, 'crm_inquiry_entity', 'crm_inquiry_id', 'entity_id')->withTimestamps();
    }

    public function knowledgeBases(): BelongsToMany
    {
        return $this->belongsToMany(KnowledgeBase::class, 'crm_inquiry_knowledge_base', 'crm_inquiry_id', 'knowledge_base_id')->withTimestamps();
    }

    public function cases(): BelongsToMany
    {
        return $this->belongsToMany(CaseRecord::class, 'crm_inquiry_case_record', 'crm_inquiry_id', 'case_record_id')->withTimestamps();
    }

    public function tags(): MorphToMany
    {
        return $this->morphToMany(Tag::class, 'taggable')->withTimestamps();
    }

    public function followUps(): HasMany
    {
        return $this->hasMany(CrmFollowUp::class, 'inquiry_id');
    }

    public function quotes(): HasMany
    {
        return $this->hasMany(CrmQuote::class, 'inquiry_id');
    }

    public function salesOrders(): HasMany
    {
        return $this->hasMany(CrmSalesOrder::class, 'inquiry_id');
    }

    protected static function booted(): void
    {
        static::deleting(static function (CrmInquiry $inquiry): void {
            $inquiry->entities()->detach();
            $inquiry->knowledgeBases()->detach();
            $inquiry->cases()->detach();
            $inquiry->tags()->detach();
        });
    }
}
