<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class CrmCustomerContact extends Model
{
    use SoftDeletes;

    protected $table = 'crm_customer_contacts';
    protected $fillable = ['customer_id', 'name', 'title', 'department', 'phone', 'email', 'decision_role', 'is_primary', 'status', 'notes'];
    protected function casts(): array { return ['customer_id' => 'integer', 'is_primary' => 'boolean']; }
    public function customer(): BelongsTo { return $this->belongsTo(CrmCustomer::class, 'customer_id'); }
}
