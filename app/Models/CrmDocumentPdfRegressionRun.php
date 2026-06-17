<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\File;

class CrmDocumentPdfRegressionRun extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_RUNNING = 'running';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'status',
        'triggered_by_admin_id',
        'output_directory',
        'primary_quote_id',
        'invoice_quote_id',
        'warnings_count',
        'report_json_path',
        'report_md_path',
        'render_context_json',
        'visual_diff_json',
        'options_json',
        'error_message',
        'started_at',
        'finished_at',
        'pruned_at',
        'deleted_bytes',
    ];

    protected function casts(): array
    {
        return [
            'triggered_by_admin_id' => 'integer',
            'primary_quote_id' => 'integer',
            'invoice_quote_id' => 'integer',
            'warnings_count' => 'integer',
            'render_context_json' => 'array',
            'visual_diff_json' => 'array',
            'options_json' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'pruned_at' => 'datetime',
            'deleted_bytes' => 'integer',
        ];
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'triggered_by_admin_id');
    }

    public function baseline(): HasOne
    {
        return $this->hasOne(CrmDocumentPdfRegressionBaseline::class, 'run_id');
    }

    public function isRunning(): bool
    {
        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_RUNNING], true);
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function hasFiles(): bool
    {
        return $this->output_directory !== null
            && $this->pruned_at === null
            && File::isDirectory((string) $this->output_directory);
    }

    public function reportData(): array
    {
        $path = (string) ($this->report_json_path ?? '');
        if ($path === '' || ! File::exists($path)) {
            return [];
        }

        $decoded = json_decode((string) File::get($path), true);

        return is_array($decoded) ? $decoded : [];
    }
}
