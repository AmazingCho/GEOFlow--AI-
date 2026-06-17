<?php

namespace App\Services\GeoFlow;

use App\Models\CrmQuote;
use App\Support\Site\SiteSettingsBag;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Process\Process;

class CrmDocumentPdfRegressionService
{
    public const DOCUMENT_TYPES = ['quotation', 'proforma_invoice', 'invoice', 'packing_list', 'contract'];

    public const RENDER_CONTEXT = [
        'render_media' => 'print',
        'page_size' => 'A4',
        'viewport_width' => 1240,
        'viewport_height' => 1754,
        'device_scale_factor' => 1,
        'engine' => 'chromium',
        'prefer_css_page_size' => true,
    ];

    public function __construct(private readonly CrmDocumentPdfService $pdfService) {}

    /**
     * @return array<string, mixed>
     */
    public function generate(array $options = []): array
    {
        $primaryQuote = $this->resolveQuote((string) ($options['quote_id'] ?? ''))
            ?? $this->findQuoteSample();
        if (! $primaryQuote) {
            throw new RuntimeException('No CRM quote with items was found.');
        }

        $invoiceQuote = $this->resolveQuote((string) ($options['invoice_quote_id'] ?? ''))
            ?? $this->findQuoteSample('invoice')
            ?? $primaryQuote;

        $runDirectory = $this->prepareRunDirectory((string) ($options['output'] ?? 'pdf-regression'));
        $pdfDirectory = $runDirectory.'/pdfs';
        $htmlDirectory = $runDirectory.'/html';
        $screenshotDirectory = $runDirectory.'/screenshots';
        File::ensureDirectoryExists($pdfDirectory);
        File::ensureDirectoryExists($htmlDirectory);
        File::ensureDirectoryExists($screenshotDirectory);

        $warnings = [];
        if (! $this->hasChineseFont()) {
            $warnings[] = 'No CJK font was detected in the runtime. Chinese text may render as square boxes.';
        }

        $samples = [
            ['type' => 'quotation', 'quote_id' => (int) $primaryQuote->id],
            ['type' => 'proforma_invoice', 'quote_id' => (int) $primaryQuote->id],
            ['type' => 'invoice', 'quote_id' => (int) $invoiceQuote->id],
            ['type' => 'packing_list', 'quote_id' => (int) $invoiceQuote->id],
            ['type' => 'contract', 'quote_id' => (int) $primaryQuote->id],
        ];

        $results = [];
        foreach ($samples as $sample) {
            $quote = $this->loadQuote((int) $sample['quote_id']);
            $type = (string) $sample['type'];
            $fileStem = $this->fileStem($quote, $type);
            $html = $this->renderDocumentHtml($quote, $type);

            $htmlPath = $htmlDirectory.'/'.$fileStem.'.html';
            File::put($htmlPath, $this->pdfService->normalizeForFileRendering($html));

            $temporaryPdf = $this->pdfService->render($html, $fileStem);
            $pdfPath = $pdfDirectory.'/'.$fileStem.'.pdf';
            if (File::exists($pdfPath)) {
                File::delete($pdfPath);
            }
            File::move($temporaryPdf, $pdfPath);

            $screenshotResult = [
                'html_pages' => null,
                'screenshots' => [],
            ];
            if (! (bool) ($options['skip_screenshots'] ?? false)) {
                $screenshotResult = $this->renderScreenshots($htmlPath, $screenshotDirectory, $fileStem);
            }

            $pdfPages = $this->countPdfPages($pdfPath);
            $htmlPages = $screenshotResult['html_pages'];
            if ($pdfPages < 1) {
                $warnings[] = "{$fileStem}: PDF page count could not be detected.";
            }
            if (is_int($htmlPages) && $htmlPages > 0 && $pdfPages !== $htmlPages) {
                $warnings[] = "{$fileStem}: PDF pages ({$pdfPages}) differ from HTML page containers ({$htmlPages}).";
            }

            $results[] = [
                'document_type' => $type,
                'quote_id' => (int) $quote->id,
                'quote_no' => (string) $quote->quote_no,
                'items_count' => (int) $quote->items->count(),
                'pdf_pages' => $pdfPages,
                'html_pages' => $htmlPages,
                'pdf_path' => $pdfPath,
                'html_path' => $htmlPath,
                'screenshots' => $screenshotResult['screenshots'],
                'render_context' => self::RENDER_CONTEXT,
            ];
        }

        $report = [
            'generated_at' => now()->toIso8601String(),
            'run_directory' => $runDirectory,
            'primary_quote_id' => (int) $primaryQuote->id,
            'invoice_quote_id' => (int) $invoiceQuote->id,
            'render_context' => self::RENDER_CONTEXT,
            'warnings' => array_values(array_unique($warnings)),
            'results' => $results,
            'visual_diff' => [
                'status' => 'not_configured',
                'message' => 'No visual baseline comparison was requested for this run.',
            ],
        ];

        File::put($runDirectory.'/report.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        File::put($runDirectory.'/report.md', $this->markdownReport($report));

        return $report;
    }

    public function markdownReport(array $report): string
    {
        $context = $report['render_context'] ?? [];
        $lines = [
            '# CRM Document PDF Regression Report',
            '',
            '- Generated at: `'.$report['generated_at'].'`',
            '- Output: `'.$report['run_directory'].'`',
            '- Primary quote ID: `'.$report['primary_quote_id'].'`',
            '- Invoice quote ID: `'.$report['invoice_quote_id'].'`',
            '- Render media: `'.(string) ($context['render_media'] ?? 'print').'`',
            '- Page size: `'.(string) ($context['page_size'] ?? 'A4').'`',
            '- Viewport: `'.(string) ($context['viewport_width'] ?? '').'x'.(string) ($context['viewport_height'] ?? '').'`',
            '',
            '| Type | Quote | Items | PDF pages | HTML pages | Screenshots |',
            '| --- | --- | ---: | ---: | ---: | ---: |',
        ];

        foreach ($report['results'] as $row) {
            $lines[] = sprintf(
                '| %s | %s (#%d) | %d | %d | %s | %d |',
                $row['document_type'],
                $row['quote_no'],
                $row['quote_id'],
                $row['items_count'],
                $row['pdf_pages'],
                $row['html_pages'] ?? '-',
                count($row['screenshots'])
            );
        }

        $lines[] = '';
        $lines[] = '## Warnings';
        $lines[] = '';
        if ($report['warnings'] === []) {
            $lines[] = '- None';
        } else {
            foreach ($report['warnings'] as $warning) {
                $lines[] = '- '.$warning;
            }
        }

        $lines[] = '';
        $lines[] = '## Visual Diff';
        $lines[] = '';
        $visualDiff = $report['visual_diff'] ?? [];
        $lines[] = '- Status: `'.(string) ($visualDiff['status'] ?? 'not_configured').'`';
        if (! empty($visualDiff['message'])) {
            $lines[] = '- Message: '.(string) $visualDiff['message'];
        }

        $lines[] = '';
        $lines[] = '## Artifacts';
        $lines[] = '';
        foreach ($report['results'] as $row) {
            $lines[] = '### '.$row['document_type'];
            $lines[] = '';
            $lines[] = '- PDF: `'.$row['pdf_path'].'`';
            $lines[] = '- HTML: `'.$row['html_path'].'`';
            foreach ($row['screenshots'] as $screenshot) {
                $lines[] = '- Screenshot: `'.$screenshot.'`';
            }
            $lines[] = '';
        }

        return implode(PHP_EOL, $lines).PHP_EOL;
    }

    private function resolveQuote(string $quoteId): ?CrmQuote
    {
        $quoteId = trim($quoteId);
        if ($quoteId === '') {
            return null;
        }

        return $this->loadQuote((int) $quoteId);
    }

    private function findQuoteSample(?string $documentType = null): ?CrmQuote
    {
        $query = CrmQuote::query()
            ->whereHas('items')
            ->withCount('items')
            ->orderByDesc('items_count')
            ->orderByDesc('id');

        if ($documentType !== null) {
            $query->where('document_type', $documentType);
        }

        $quote = $query->first();

        return $quote ? $this->loadQuote((int) $quote->id) : null;
    }

    private function loadQuote(int $quoteId): CrmQuote
    {
        return CrmQuote::query()
            ->with(['collection', 'customer', 'inquiry.customer.followUps.inquiry', 'opportunity', 'items.entity', 'items.image'])
            ->whereKey($quoteId)
            ->firstOrFail();
    }

    private function renderDocumentHtml(CrmQuote $quote, string $documentType): string
    {
        return view($this->printViewForDocumentType($documentType), [
            'pageTitle' => $quote->quote_no.' - '.$this->documentTypeLabel($documentType),
            'activeMenu' => 'crm',
            'adminSiteName' => config('geoflow.site_name', 'GEOFlow'),
            'quote' => $quote,
            'seller' => $this->sellerProfile($quote),
            'documentKind' => $documentType,
            'documentLabels' => $this->documentLabels((string) ($quote->document_language ?? 'en')),
        ])->render();
    }

    private function printViewForDocumentType(string $documentType): string
    {
        return match ($documentType) {
            'proforma_invoice' => 'admin.crm.quotes.print-proforma-invoice',
            'invoice' => 'admin.crm.quotes.print-invoice',
            'packing_list' => 'admin.crm.quotes.print-packing-list',
            'contract' => 'admin.crm.quotes.print-contract',
            default => 'admin.crm.quotes.print-quotation',
        };
    }

    private function documentTypeLabel(string $documentType): string
    {
        return [
            'quotation' => 'Quotation',
            'proforma_invoice' => 'Proforma Invoice',
            'invoice' => 'Commercial Invoice',
            'packing_list' => 'Packing List',
            'contract' => 'Contract',
        ][$documentType] ?? 'Quotation';
    }

    private function sellerProfile(CrmQuote $quote): array
    {
        $settings = SiteSettingsBag::all();
        $stored = is_array($quote->seller_company_json) ? $quote->seller_company_json : [];

        return [
            'name' => trim((string) ($stored['name'] ?? $settings['site_name'] ?? config('geoflow.site_name', 'GEOFlow'))),
            'logo' => trim((string) ($stored['logo'] ?? $settings['site_logo'] ?? '')),
            'address' => trim((string) ($stored['address'] ?? '')),
            'phone' => trim((string) ($stored['phone'] ?? '')),
            'email' => trim((string) ($stored['email'] ?? '')),
            'website' => trim((string) ($stored['website'] ?? config('app.url', ''))),
        ];
    }

    private function documentLabels(string $language): array
    {
        if ($language === 'zh_CN') {
            return [
                'seller' => '卖方',
                'buyer' => '买方',
                'document_no' => '单据号',
                'date' => '日期',
                'valid_until' => '有效期',
                'currency' => '币种',
                'trade_term' => '贸易条款',
                'lead_time' => '交期',
                'origin' => '原产国',
                'items' => '明细',
                'item' => '项目',
                'image' => '图片',
                'sku_model' => 'SKU / 型号',
                'hs_code' => 'HS Code',
                'description' => '描述',
                'qty' => '数量',
                'unit_price' => '单价',
                'amount' => '金额',
                'summary' => '汇总',
                'subtotal' => '明细小计',
                'shipping' => '运费',
                'discount' => '折扣',
                'tax' => '税费',
                'grand_total' => '最终合计',
                'payment_terms' => '付款条款',
                'delivery_terms' => '交付条款',
                'warranty_terms' => '质保条款',
                'installation_terms' => '安装条款',
                'packing_terms' => '包装条款',
                'notes' => '备注',
                'signature' => '签名',
                'bank_account' => '银行账户',
                'contract_terms' => '合同条款',
                'governing_law' => '适用法律',
                'dispute_resolution' => '争议解决',
                'package_count' => '件数',
                'net_weight' => '净重',
                'gross_weight' => '毛重',
                'volume_cbm' => '体积 CBM',
            ];
        }

        return [
            'seller' => 'Seller',
            'buyer' => 'Buyer',
            'document_no' => 'Document No.',
            'date' => 'Date',
            'valid_until' => 'Valid Until',
            'currency' => 'Currency',
            'trade_term' => 'Trade Term',
            'lead_time' => 'Lead Time',
            'origin' => 'Origin',
            'items' => 'Items',
            'item' => 'Item',
            'image' => 'Image',
            'sku_model' => 'SKU / Model',
            'hs_code' => 'HS Code',
            'description' => 'Description',
            'qty' => 'Qty',
            'unit_price' => 'Unit Price',
            'amount' => 'Amount',
            'summary' => 'Summary',
            'subtotal' => 'Items Subtotal',
            'shipping' => 'Shipping Fee',
            'discount' => 'Discount',
            'tax' => 'Tax',
            'grand_total' => 'Grand Total',
            'payment_terms' => 'Payment Terms',
            'delivery_terms' => 'Delivery Terms',
            'warranty_terms' => 'Warranty Terms',
            'installation_terms' => 'Installation Terms',
            'packing_terms' => 'Packing',
            'notes' => 'Notes',
            'signature' => 'Signature',
            'bank_account' => 'Bank Account',
            'contract_terms' => 'Contract Terms',
            'governing_law' => 'Governing Law',
            'dispute_resolution' => 'Dispute Resolution',
            'package_count' => 'Packages',
            'net_weight' => 'Net Weight',
            'gross_weight' => 'Gross Weight',
            'volume_cbm' => 'Volume CBM',
        ];
    }

    private function prepareRunDirectory(string $output): string
    {
        $base = trim($output, '/');
        $base = $base !== '' ? $base : 'pdf-regression';
        if (str_contains($base, '..')) {
            throw new InvalidArgumentException('PDF regression output directory must be a storage/app relative path.');
        }

        $directory = storage_path('app/'.$base.'/'.now()->format('Ymd_His'));
        File::ensureDirectoryExists($directory);

        return $directory;
    }

    private function fileStem(CrmQuote $quote, string $documentType): string
    {
        $stem = preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) $quote->quote_no.'-'.$documentType);
        $stem = trim((string) $stem, '-_.');

        return $stem !== '' ? $stem : 'crm-document-'.$documentType.'-'.$quote->id;
    }

    private function renderScreenshots(string $htmlPath, string $screenshotDirectory, string $fileStem): array
    {
        $process = new Process([
            env('GEOFLOW_PDF_NODE_BINARY', '/usr/bin/node'),
            base_path('scripts/render-crm-document-screenshots.mjs'),
            $htmlPath,
            $screenshotDirectory,
            $fileStem,
        ], base_path(), [
            'PUPPETEER_EXECUTABLE_PATH' => env('GEOFLOW_PDF_CHROMIUM_BINARY', '/usr/bin/chromium'),
        ]);
        $process->setTimeout((int) env('GEOFLOW_PDF_TIMEOUT', 120));
        $process->run();

        if (! $process->isSuccessful()) {
            $message = trim($process->getErrorOutput() ?: $process->getOutput());
            throw new RuntimeException('CRM document screenshot generation failed'.($message !== '' ? ': '.$message : '.'));
        }

        $result = json_decode(trim($process->getOutput()), true);
        if (! is_array($result)) {
            throw new RuntimeException('CRM document screenshot generation returned invalid JSON.');
        }

        return [
            'html_pages' => (int) ($result['htmlPages'] ?? 0),
            'screenshots' => array_values(array_map('strval', $result['screenshots'] ?? [])),
        ];
    }

    private function countPdfPages(string $pdfPath): int
    {
        $contents = File::get($pdfPath);
        preg_match_all('/\/Type\s*\/Page\b/', $contents, $matches);

        return count($matches[0] ?? []);
    }

    private function hasChineseFont(): bool
    {
        $process = new Process(['fc-list', ':lang=zh', 'family']);
        $process->setTimeout(10);
        $process->run();

        return $process->isSuccessful() && trim($process->getOutput()) !== '';
    }
}
