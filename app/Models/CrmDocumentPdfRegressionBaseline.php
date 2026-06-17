<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CrmDocumentPdfRegressionBaseline extends Model
{
    protected $fillable = [
        'name',
        'run_id',
        'baseline_directory',
        'created_by_admin_id',
        'render_context_json',
    ];

    protected function casts(): array
    {
        return [
            'run_id' => 'integer',
            'created_by_admin_id' => 'integer',
            'render_context_json' => 'array',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(CrmDocumentPdfRegressionRun::class, 'run_id');
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'created_by_admin_id');
    }
}
