<?php

namespace App\Services\GeoFlow;

use App\Models\CrmDocumentPdfRegressionRun;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;

class CrmDocumentPdfRegressionCleanupService
{
    public function preview(int $keepRuns = 20, int $keepDays = 30): array
    {
        return [
            'keep_runs' => $keepRuns,
            'keep_days' => $keepDays,
            'candidates' => $this->candidates($keepRuns, $keepDays)
                ->map(fn (CrmDocumentPdfRegressionRun $run): array => $this->candidatePayload($run))
                ->values()
                ->all(),
        ];
    }

    public function prune(int $keepRuns = 20, int $keepDays = 30): array
    {
        $deleted = [];
        foreach ($this->candidates($keepRuns, $keepDays) as $run) {
            $directory = (string) $run->output_directory;
            $this->assertSafeRegressionDirectory($directory);
            $bytes = File::isDirectory($directory) ? $this->directorySize($directory) : 0;
            if (File::isDirectory($directory)) {
                File::deleteDirectory($directory);
            }

            $run->forceFill([
                'pruned_at' => now(),
                'deleted_bytes' => $bytes,
            ])->save();

            $deleted[] = [
                'run_id' => (int) $run->id,
                'output_directory' => $directory,
                'deleted_bytes' => $bytes,
            ];
        }

        return [
            'keep_runs' => $keepRuns,
            'keep_days' => $keepDays,
            'deleted' => $deleted,
            'deleted_count' => count($deleted),
            'deleted_bytes' => array_sum(array_column($deleted, 'deleted_bytes')),
        ];
    }

    public function formatBytes(int $bytes): string
    {
        if ($bytes >= 1024 * 1024 * 1024) {
            return number_format($bytes / 1024 / 1024 / 1024, 2).' GB';
        }
        if ($bytes >= 1024 * 1024) {
            return number_format($bytes / 1024 / 1024, 2).' MB';
        }
        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 2).' KB';
        }

        return $bytes.' B';
    }

    private function candidates(int $keepRuns, int $keepDays): Collection
    {
        $keepRuns = max(1, $keepRuns);
        $keepDays = max(1, $keepDays);
        $cutoff = now()->subDays($keepDays);

        $keepIds = CrmDocumentPdfRegressionRun::query()
            ->whereNull('pruned_at')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($keepRuns)
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        return CrmDocumentPdfRegressionRun::query()
            ->whereNotIn('status', [CrmDocumentPdfRegressionRun::STATUS_PENDING, CrmDocumentPdfRegressionRun::STATUS_RUNNING])
            ->whereNull('pruned_at')
            ->whereNotNull('output_directory')
            ->whereDoesntHave('baseline')
            ->whereNotIn('id', $keepIds)
            ->where('created_at', '<', $cutoff)
            ->orderBy('created_at')
            ->get()
            ->filter(function (CrmDocumentPdfRegressionRun $run): bool {
                $directory = (string) $run->output_directory;
                return $directory !== '' && $this->isSafeRegressionDirectory($directory);
            })
            ->values();
    }

    private function candidatePayload(CrmDocumentPdfRegressionRun $run): array
    {
        $directory = (string) $run->output_directory;
        $bytes = File::isDirectory($directory) ? $this->directorySize($directory) : 0;

        return [
            'run_id' => (int) $run->id,
            'status' => (string) $run->status,
            'created_at' => optional($run->created_at)->toDateTimeString(),
            'output_directory' => $directory,
            'bytes' => $bytes,
            'size' => $this->formatBytes($bytes),
        ];
    }

    private function directorySize(string $directory): int
    {
        $bytes = 0;
        if (! File::isDirectory($directory)) {
            return 0;
        }

        foreach (File::allFiles($directory) as $file) {
            $bytes += $file->getSize();
        }

        return $bytes;
    }

    private function isSafeRegressionDirectory(string $directory): bool
    {
        $base = realpath(storage_path('app/pdf-regression')) ?: storage_path('app/pdf-regression');
        $real = realpath($directory) ?: $directory;
        $base = rtrim(str_replace('\\', '/', $base), '/').'/';
        $real = rtrim(str_replace('\\', '/', $real), '/').'/';

        return str_starts_with($real, $base) && $real !== $base;
    }

    private function assertSafeRegressionDirectory(string $directory): void
    {
        if (! $this->isSafeRegressionDirectory($directory)) {
            throw new InvalidArgumentException('Refusing to delete a path outside storage/app/pdf-regression.');
        }
    }
}
