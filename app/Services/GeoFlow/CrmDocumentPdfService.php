<?php

namespace App\Services\GeoFlow;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;

class CrmDocumentPdfService
{
    public function normalizeForFileRendering(string $html): string
    {
        return $this->normalizeHtmlForFileRendering($html);
    }

    public function render(string $html, string $fileStem): string
    {
        $directory = storage_path('app/tmp/crm-document-pdfs');
        File::ensureDirectoryExists($directory);

        $id = (string) Str::uuid();
        $htmlPath = $directory.'/'.$id.'.html';
        $pdfPath = $directory.'/'.$id.'.pdf';

        File::put($htmlPath, $this->normalizeHtmlForFileRendering($html));

        $process = new Process([
            $this->resolveExecutable('GEOFLOW_PDF_NODE_BINARY', ['/usr/local/bin/node', '/usr/bin/node']),
            base_path('scripts/render-crm-document-pdf.mjs'),
            $htmlPath,
            $pdfPath,
        ], base_path(), [
            'PUPPETEER_EXECUTABLE_PATH' => $this->resolveExecutable(
                'GEOFLOW_PDF_CHROMIUM_BINARY',
                ['/usr/bin/chromium-headless-shell', '/usr/bin/chromium', '/usr/bin/chromium-browser']
            ),
        ]);
        $process->setTimeout((int) env('GEOFLOW_PDF_TIMEOUT', 120));
        $process->run();

        File::delete($htmlPath);

        if (! $process->isSuccessful()) {
            File::delete($pdfPath);

            $message = trim($process->getErrorOutput() ?: $process->getOutput());
            throw new RuntimeException('CRM document PDF generation failed'.($message !== '' ? ': '.$message : '.'));
        }

        if (! File::exists($pdfPath) || File::size($pdfPath) <= 0) {
            File::delete($pdfPath);
            throw new RuntimeException('CRM document PDF generation produced an empty file.');
        }

        return $pdfPath;
    }

    private function normalizeHtmlForFileRendering(string $html): string
    {
        $publicFileUrl = $this->fileUrl(public_path());

        return preg_replace_callback(
            '/\b(src|href)=([\'"])(\/(?!\/)[^\'"#?]*(?:\?[^\'"#]*)?(?:#[^\'"]*)?)\2/i',
            static function (array $matches) use ($publicFileUrl): string {
                $attribute = $matches[1];
                $quote = $matches[2];
                $path = $matches[3];
                $normalizedPath = ltrim($path, '/');

                return $attribute.'='.$quote.$publicFileUrl.'/'.$normalizedPath.$quote;
            },
            $html
        ) ?? $html;
    }

    private function fileUrl(string $path): string
    {
        $path = str_replace('\\', '/', realpath($path) ?: $path);
        $segments = array_map(static fn (string $segment): string => rawurlencode($segment), explode('/', ltrim($path, '/')));

        return 'file:///'.implode('/', $segments);
    }

    /**
     * @param array<int, string> $candidates
     */
    private function resolveExecutable(string $environmentKey, array $candidates): string
    {
        $configured = trim((string) env($environmentKey, ''));
        if ($configured !== '') {
            return $configured;
        }

        foreach ($candidates as $candidate) {
            if (is_executable($candidate)) {
                return $candidate;
            }
        }

        return $candidates[0];
    }
}
