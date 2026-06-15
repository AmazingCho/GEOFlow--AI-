<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class CrmTask extends Model
{
    use SoftDeletes;

    protected $table = 'crm_tasks';
    protected $fillable = ['customer_id', 'inquiry_id', 'opportunity_id', 'quote_id', 'order_id', 'ticket_id', 'assigned_admin_id', 'created_by_admin_id', 'title', 'description', 'priority', 'status', 'due_at', 'completed_at'];
    protected function casts(): array { return ['due_at'=>'datetime','completed_at'=>'datetime']; }
    public function customer(): BelongsTo { return $this->belongsTo(CrmCustomer::class, 'customer_id'); }
    public function inquiry(): BelongsTo { return $this->belongsTo(CrmInquiry::class, 'inquiry_id'); }
    public function opportunity(): BelongsTo { return $this->belongsTo(CrmOpportunity::class, 'opportunity_id'); }
    public function quote(): BelongsTo { return $this->belongsTo(CrmQuote::class, 'quote_id'); }
    public function order(): BelongsTo { return $this->belongsTo(CrmSalesOrder::class, 'order_id'); }
    public function ticket(): BelongsTo { return $this->belongsTo(CrmAfterSalesTicket::class, 'ticket_id'); }
    public function assignee(): BelongsTo { return $this->belongsTo(Admin::class, 'assigned_admin_id'); }
    public function activities(): HasMany { return $this->hasMany(CrmFollowUp::class, 'task_id'); }
}
