<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class CrmFollowUp extends Model
{
    use SoftDeletes;
    protected $table = 'crm_follow_ups';

    protected $fillable = [
        'customer_id',
        'inquiry_id',
        'followup_type',
        'content',
        'next_action',
        'next_followup_at',
        'owner',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'customer_id' => 'integer',
            'inquiry_id' => 'integer',
            'next_followup_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(CrmCustomer::class, 'customer_id');
    }

    public function inquiry(): BelongsTo
    {
        return $this->belongsTo(CrmInquiry::class, 'inquiry_id');
    }
}
