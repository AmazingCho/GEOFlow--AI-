<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class CrmOpportunity extends Model
{
    use SoftDeletes;

    protected $table = 'crm_opportunities';
    protected $fillable = ['collection_id', 'customer_id', 'primary_contact_id', 'source_inquiry_id', 'owner_admin_id', 'name', 'stage', 'amount', 'currency', 'probability', 'expected_close_date', 'next_step', 'next_step_at', 'competitor', 'lost_reason', 'notes', 'won_at', 'lost_at'];
    protected function casts(): array { return ['collection_id'=>'integer','customer_id'=>'integer','primary_contact_id'=>'integer','source_inquiry_id'=>'integer','owner_admin_id'=>'integer','amount'=>'decimal:2','probability'=>'integer','expected_close_date'=>'date','next_step_at'=>'datetime','won_at'=>'datetime','lost_at'=>'datetime']; }
    public function customer(): BelongsTo { return $this->belongsTo(CrmCustomer::class, 'customer_id'); }
    public function collection(): BelongsTo { return $this->belongsTo(CollectionRecord::class, 'collection_id'); }
    public function primaryContact(): BelongsTo { return $this->belongsTo(CrmCustomerContact::class, 'primary_contact_id'); }
    public function sourceInquiry(): BelongsTo { return $this->belongsTo(CrmInquiry::class, 'source_inquiry_id'); }
    public function owner(): BelongsTo { return $this->belongsTo(Admin::class, 'owner_admin_id'); }
    public function tasks(): HasMany { return $this->hasMany(CrmTask::class, 'opportunity_id'); }
    public function quotes(): HasMany { return $this->hasMany(CrmQuote::class, 'opportunity_id'); }
}
