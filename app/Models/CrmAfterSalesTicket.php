<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

class CrmAfterSalesTicket extends Model
{
    use SoftDeletes;
    protected $table = 'crm_after_sales_tickets';

    protected $fillable = [
        'collection_id',
        'customer_id',
        'owner',
        'order_id',
        'entity_id',
        'title',
        'issue_description',
        'issue_type',
        'priority',
        'status',
        'reply_points',
        'missing_information_questions',
        'resolution',
        'resolved_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'collection_id' => 'integer',
            'customer_id' => 'integer',
            'order_id' => 'integer',
            'entity_id' => 'integer',
            'resolved_at' => 'datetime',
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

    public function order(): BelongsTo
    {
        return $this->belongsTo(CrmSalesOrder::class, 'order_id');
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(EntityRecord::class, 'entity_id');
    }

    public function knowledgeBases(): BelongsToMany
    {
        return $this->belongsToMany(KnowledgeBase::class, 'crm_ticket_knowledge_base', 'ticket_id', 'knowledge_base_id')->withTimestamps();
    }

    public function cases(): BelongsToMany
    {
        return $this->belongsToMany(CaseRecord::class, 'crm_ticket_case_record', 'ticket_id', 'case_record_id')->withTimestamps();
    }

    protected static function booted(): void
    {
        static::forceDeleting(static function (CrmAfterSalesTicket $ticket): void {
            DB::table('crm_ticket_knowledge_base')->where('ticket_id', (int) $ticket->id)->delete();
            DB::table('crm_ticket_case_record')->where('ticket_id', (int) $ticket->id)->delete();
        });
    }
}
