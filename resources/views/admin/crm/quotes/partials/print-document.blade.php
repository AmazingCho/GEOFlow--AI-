@php
    $documentKind = $documentKind ?? (string) ($quote->document_type ?? 'quotation');
    $documentLanguage = \App\Support\GeoFlow\CrmDocumentLocale::resolve(
        isset($documentLanguage) && is_string($documentLanguage) ? $documentLanguage : null,
        (string) ($quote->document_language ?? 'en'),
    );
    $titles = $documentTitles ?? \App\Support\GeoFlow\CrmDocumentLocale::documentTitles($documentLanguage);
    $languageOptions = $documentLanguageOptions ?? \App\Support\GeoFlow\CrmDocumentLocale::options();
    $title = $titles[$documentKind] ?? $titles['quotation'];
    $labels = $documentLabels ?? [];
    $label = static fn (string $key, string $fallback): string => (string) ($labels[$key] ?? $fallback);
    $formatDate = static fn (mixed $value): string => \App\Support\GeoFlow\CrmDocumentLocale::formatDate($value, $documentLanguage);
    $seller = $seller ?? ['name' => config('geoflow.site_name', 'GEOFlow'), 'logo' => '', 'address' => '', 'phone' => '', 'email' => '', 'website' => ''];
    $money = static fn (mixed $value): string => number_format((float) $value, 2);
    $weight = static fn (mixed $value): string => ((float) $value > 0) ? number_format((float) $value, 3) : '-';
    $showPrices = $documentKind !== 'packing_list';
    $showImages = $documentKind === 'quotation' || $documentKind === 'proforma_invoice';
    $showBank = $documentKind === 'proforma_invoice';
    $showInvoiceLogistics = $documentKind === 'invoice' || $documentKind === 'packing_list';
    $showContract = $documentKind === 'contract';
    $bank = is_array($quote->bank_account_json) ? $quote->bank_account_json : [];
    $isInvoice = $documentKind === 'invoice';
    $isPacking = $documentKind === 'packing_list';
    $isPI = $documentKind === 'proforma_invoice';
    $usePackagePlan = $isPacking
        && (string) ($quote->packing_mode ?? 'item_level') === 'package_plan'
        && (string) ($quote->packing_status ?? 'draft') === 'applied'
        && $quote->packages->isNotEmpty();
    $packingPlanNotApplied = $isPacking
        && (string) ($quote->packing_mode ?? 'item_level') === 'package_plan'
        && (string) ($quote->packing_status ?? 'draft') !== 'applied';

    // Compute logistics totals for invoice / packing list
    $totalPackages = 0;
    $totalNetWeight = 0.0;
    $totalGrossWeight = 0.0;
    $totalVolume = 0.0;
    if ($usePackagePlan) {
        foreach ($quote->packages as $package) {
            $totalPackages++;
            $totalNetWeight += (float) ($package->net_weight ?? 0);
            $totalGrossWeight += (float) ($package->gross_weight ?? 0);
            $totalVolume += (float) ($package->volume_cbm ?? 0);
        }
    } else {
        foreach ($quote->items as $item) {
            $totalPackages += (int) ($item->package_count ?? 0);
            $totalNetWeight += (float) ($item->net_weight ?? 0);
            $totalGrossWeight += (float) ($item->gross_weight ?? 0);
            $totalVolume += (float) ($item->volume_cbm ?? 0);
        }
    }

    $allItems = $usePackagePlan ? $quote->packages->values() : $quote->items->values();
    $textLength = static function (mixed $value): int {
        $text = trim(strip_tags((string) $value));

        return function_exists('mb_strlen') ? mb_strlen($text) : strlen($text);
    };
    $itemHasRenderableImage = static fn ($item): bool => (bool) ($item->image || trim((string) ($item->image_path ?? '')) !== '');
    $hasVisibleItemImages = $showImages && $allItems->contains($itemHasRenderableImage);
    $estimateItemWeight = static function ($item) use ($showImages, $isPacking, $isInvoice, $textLength, $itemHasRenderableImage, $usePackagePlan): int {
        if ($usePackagePlan) {
            $allocationWeight = 0;
            foreach ($item->allocations as $allocation) {
                $quoteItem = $allocation->quoteItem;
                $allocationText = implode(' ', [
                    (string) ($quoteItem?->item_name ?? ''),
                    (string) ($quoteItem?->model ?? ''),
                    (string) ($allocation->allocated_quantity ?? ''),
                    (string) ($quoteItem?->unit ?? ''),
                ]);
                $allocationWeight += 1 + (int) ceil($textLength($allocationText) / 48);
            }

            $packageText = implode(' ', [
                (string) ($item->package_no ?? ''),
                (string) ($item->package_type ?? ''),
                (string) ($item->notes ?? ''),
            ]);
            $packageTextWeight = (int) ceil($textLength($packageText) / 60);

            return max(4, 3 + $allocationWeight + $packageTextWeight);
        }

        $hasImage = $showImages && $itemHasRenderableImage($item);
        $weight = $isPacking ? 3 : ($hasImage ? 5 : ($isInvoice ? 3 : 2));
        $nameLength = $textLength($item->item_name ?? '');
        $descriptionLength = $textLength($item->description ?? '');

        if ($nameLength > 42) {
            $weight += min(2, (int) ceil(($nameLength - 42) / 42));
        }

        if ($descriptionLength > 80) {
            $weight += min(4, (int) ceil(($descriptionLength - 80) / 140));
        }

        $hasPackingDetails = (int) ($item->package_count ?? 0) > 0
            || (float) ($item->net_weight ?? 0) > 0
            || (float) ($item->gross_weight ?? 0) > 0
            || (float) ($item->volume_cbm ?? 0) > 0;
        if ($isPacking && $hasPackingDetails) {
            $weight += 1;
        }

        return max(1, $weight);
    };

    // Longer fixed labels consume more vertical space in the summary and signature areas.
    // Reserve that space up front, then let the browser's A4 measurement merge the final
    // content page back only when the rendered document genuinely fits.
    $localizedLayoutPenalty = match ($documentLanguage) {
        'ru' => 4,
        'es' => 2,
        default => 0,
    };

    if ($isPacking) {
        $singlePageCapacity = 20 - $localizedLayoutPenalty;
        $firstItemsCapacity = 28;
        $continuationItemsCapacity = 36;
        $finalItemsCapacity = 22;
    } elseif ($hasVisibleItemImages) {
        $singlePageCapacity = 36 - $localizedLayoutPenalty;
        // Image thumbnails are fixed at 48px. Let item-only first pages fit up to 8 compact image rows,
        // but keep the single-page threshold lower so summary/terms do not get squeezed into overflow.
        $firstItemsCapacity = 44;
        $continuationItemsCapacity = 42;
        $finalItemsCapacity = 22;
    } elseif ($isInvoice) {
        $singlePageCapacity = 24 - $localizedLayoutPenalty;
        $firstItemsCapacity = 32;
        $continuationItemsCapacity = 40;
        $finalItemsCapacity = 26;
    } elseif ($showContract) {
        $singlePageCapacity = 18 - $localizedLayoutPenalty;
        $firstItemsCapacity = 28;
        $continuationItemsCapacity = 36;
        $finalItemsCapacity = 18;
    } else {
        $singlePageCapacity = 24 - $localizedLayoutPenalty;
        $firstItemsCapacity = 32;
        $continuationItemsCapacity = 40;
        $finalItemsCapacity = 24;
    }

    $remainingItemWeight = static function ($items, int $start) use ($estimateItemWeight): int {
        $weight = 0;
        $count = $items->count();

        for ($index = $start; $index < $count; $index++) {
            $weight += $estimateItemWeight($items->get($index));
        }

        return $weight;
    };
    $takeItemPage = static function ($items, int $start, int $capacity) use ($estimateItemWeight): array {
        $chunk = [];
        $weight = 0;
        $count = $items->count();

        for ($index = $start; $index < $count; $index++) {
            $item = $items->get($index);
            $itemWeight = $estimateItemWeight($item);

            if ($chunk !== [] && ($weight + $itemWeight) > $capacity) {
                break;
            }

            $chunk[] = $item;
            $weight += $itemWeight;
        }

        return [collect($chunk), $start + count($chunk)];
    };
    $paginateItems = static function ($items) use ($singlePageCapacity, $firstItemsCapacity, $continuationItemsCapacity, $finalItemsCapacity, $remainingItemWeight, $takeItemPage): array {
        $items = $items->values();
        $count = $items->count();

        if ($count === 0) {
            return [
                'pages' => [[
                    'items' => $items,
                    'start_index' => 0,
                    'is_first' => true,
                    'is_final' => true,
                ]],
                'needs_final_content_page' => false,
            ];
        }

        if ($remainingItemWeight($items, 0) <= $singlePageCapacity) {
            return [
                'pages' => [[
                    'items' => $items,
                    'start_index' => 0,
                    'is_first' => true,
                    'is_final' => true,
                ]],
                'needs_final_content_page' => false,
            ];
        }

        $pages = [];
        $needsFinalContentPage = false;
        [$firstChunk, $nextIndex] = $takeItemPage($items, 0, $firstItemsCapacity);
        $pages[] = [
            'items' => $firstChunk,
            'start_index' => 0,
            'is_first' => true,
            'is_final' => false,
        ];
        if ($nextIndex >= $count) {
            return [
                'pages' => $pages,
                'needs_final_content_page' => true,
            ];
        }

        while ($nextIndex < $count) {
            $startIndex = $nextIndex;
            $remainingWeight = $remainingItemWeight($items, $startIndex);
            $capacity = $remainingWeight <= $finalItemsCapacity ? $finalItemsCapacity : $continuationItemsCapacity;

            [$chunk, $nextIndex] = $takeItemPage($items, $startIndex, $capacity);

            $pages[] = [
                'items' => $chunk,
                'start_index' => $startIndex,
                'is_first' => false,
                'is_final' => false,
            ];

            if ($nextIndex >= $count && $remainingWeight > $finalItemsCapacity) {
                $needsFinalContentPage = true;
                break;
            }
        }

        if (!$needsFinalContentPage) {
            $lastIndex = count($pages) - 1;
            $pages[$lastIndex]['is_final'] = true;
        }

        return [
            'pages' => $pages,
            'needs_final_content_page' => $needsFinalContentPage,
        ];
    };

    $pagination = $paginateItems($allItems);
    $itemPages = $pagination['pages'];
    $hasSeparateFinalContentPage = (bool) ($pagination['needs_final_content_page'] ?? false);
    $totalItemPages = count($itemPages);
    $finalContentPageCount = $hasSeparateFinalContentPage ? 1 : 0;
    $bankPageCount = $isPI ? 1 : 0;
    $contractPageCount = $showContract ? 1 : 0;
    $totalPages = max(1, $totalItemPages + $finalContentPageCount + $bankPageCount + $contractPageCount);
    $pageCountLabel = static fn (int $pageNumber): string => \App\Support\GeoFlow\CrmDocumentLocale::text(
        $documentLanguage,
        'page_of',
        'Page :current of :total',
        ['current' => $pageNumber, 'total' => $totalPages],
    );
    $pageLabel = static fn (int $pageNumber): string => \App\Support\GeoFlow\CrmDocumentLocale::text(
        $documentLanguage,
        'document_page_of',
        ':title · Page :current of :total',
        ['title' => $title, 'current' => $pageNumber, 'total' => $totalPages],
    );
@endphp

<!doctype html>
<html lang="{{ \App\Support\GeoFlow\CrmDocumentLocale::htmlLang($documentLanguage) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $quote->quote_no }} - {{ $title }}</title>
    <style>
        :root {
            --text: #1f2933;
            --muted: #657386;
            --border: #d9dee7;
            --light: #f5f7fa;
            --accent: #111827;
        }
        * { box-sizing: border-box; }
        @page { size: A4; margin: 0; }
        body { margin: 0; background: #e9edf3; color: var(--text); font-family: Arial, "DejaVu Sans", "WenQuanYi Zen Hei", "Noto Sans CJK SC", "Noto Sans CJK TC", "Microsoft YaHei", Helvetica, sans-serif; font-size: 11px; line-height: 1.25; }
        .page { width: 210mm; min-height: 297mm; margin: 0 auto 20px; background: #fff; padding: 10mm 12mm 18mm; position: relative; }
        .page-break { page-break-before: always; }
        .topbar { display: flex; width: 210mm; max-width: calc(100vw - 24px); flex-wrap: wrap; justify-content: flex-end; gap: 8px; align-items: center; margin: 0 auto 10px; padding-top: 10px; text-align: right; }
        .doc-switcher { border: 1px solid var(--border); background: #fff; padding: 6px 10px; font-size: 11px; cursor: pointer; }
        .doc-action { border: 1px solid var(--border); background: #fff; color: var(--text); padding: 6px 10px; font-size: 11px; font-weight: 700; text-decoration: none; }
        .doc-action:hover { background: var(--light); }
        .print-alert { flex-basis: 100%; border: 1px solid #f59e0b; background: #fffbeb; color: #92400e; padding: 7px 9px; text-align: left; font-size: 11px; }
        .header { display: grid; grid-template-columns: 1.3fr 1fr; gap: 14px; align-items: start; border-bottom: 2px solid var(--accent); padding-bottom: 10px; }
        .continuation-header { margin-bottom: 10px; }
        .continuation-header .title-box { align-self: center; }
        .continuation-kicker { color: var(--muted); font-size: 11px; font-weight: 700; letter-spacing: .04em; text-transform: uppercase; }
        .continuation-page-count { margin-top: 4px; }
        .brand { display: flex; flex-direction: column; gap: 7px; align-items: flex-start; }
        .logo { width: 86px; height: 44px; object-fit: contain; }
        .company-name { font-size: 14px; font-weight: 700; margin-bottom: 3px; }
        .muted { color: var(--muted); font-size: 10px; }
        .title-box { text-align: right; }
        h1 { margin: 0 0 6px; font-size: 24px; text-transform: uppercase; letter-spacing: .8px; }
        .doc-meta { display: grid; grid-template-columns: 86px 1fr; gap: 3px 8px; justify-content: end; font-size: 11.5px; max-width: 250px; margin-left: auto; }
        .doc-meta-wide { display: grid; grid-template-columns: 90px 1fr; gap: 3px 8px; justify-content: end; font-size: 11.5px; max-width: 260px; margin-left: auto; }
        .label { color: var(--muted); font-weight: 600; white-space: nowrap; }
        .info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 7px; margin-top: 7px; }
        .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 7px; margin-top: 7px; }
        .grid-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 7px; margin-top: 7px; }
        .panel { border: 1px solid var(--border); padding: 5px 6px; background: #fff; }
        .panel-title { padding: 4px 6px; margin: -5px -6px 5px; background: var(--light); border-bottom: 1px solid var(--border); font-weight: 700; font-size: 11px; text-transform: uppercase; letter-spacing: .02em; }
        .kv { display: grid; grid-template-columns: minmax(0, 42%) minmax(0, 58%); gap: 3px 8px; }
        .kv-wide { display: grid; grid-template-columns: minmax(0, 44%) minmax(0, 56%); gap: 3px 8px; }
        .kv > .label, .kv-wide > .label { white-space: normal; overflow-wrap: anywhere; }
        h2 { margin: 9px 0 4px; padding-bottom: 4px; border-bottom: 1px solid var(--border); font-size: 13px; font-weight: 700; text-transform: uppercase; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid var(--border); padding: 4px 5px; vertical-align: top; }
        th { background: var(--light); font-size: 11px; text-transform: uppercase; letter-spacing: .02em; }
        .right { text-align: right; }
        .center { text-align: center; }
        .nowrap { white-space: nowrap; }
        .thumb { width: 48px; height: 48px; border: 1px solid var(--border); object-fit: cover; flex-shrink: 0; }
        .product-row { display: flex; gap: 7px; align-items: flex-start; }
        .package-goods-list { display: grid; gap: 4px; }
        .package-goods-item { border-bottom: 1px dashed var(--border); padding-bottom: 3px; }
        .package-goods-item:last-child { border-bottom: 0; padding-bottom: 0; }
        .product-name { font-weight: 700; margin-bottom: 2px; }
        .summary-wrap { display: grid; grid-template-columns: 1fr 250px; gap: 8px; margin-top: 6px; align-items: start; }
        .notes-box { border: 1px solid var(--border); padding: 7px 8px; min-height: 34px; color: var(--muted); font-size: 10.5px; }
        .summary-table td { padding: 4px 6px; font-size: 11.5px; }
        .summary-table tr:last-child td { background: var(--accent); color: #fff; font-weight: 700; font-size: 13px; }
        .terms-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 4px 12px; margin-top: 6px; }
        .term-item { display: grid; grid-template-columns: max-content minmax(0, 1fr); gap: 6px; align-items: start; font-size: 10.5px; line-height: 1.45; }
        .term-item.full { grid-column: span 2; }
        .bank-block { border: 2px solid var(--accent); padding: 14px 16px; margin-top: 12px; }
        .bank-title { font-size: 13px; font-weight: 700; text-transform: uppercase; margin-bottom: 10px; }
        .bank-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 8px 24px; }
        .bank-item { display: flex; gap: 6px; font-size: 12px; }
        .bank-item.wide { grid-column: span 2; }
        .summary-card { border: 2px solid #111827; margin-top: 10px; }
        .summary-card-title { background: #111827; color: #fff; padding: 8px 12px; font-weight: 700; font-size: 12px; text-transform: uppercase; }
        .summary-card-grid { display: grid; grid-template-columns: repeat(4, 1fr); }
        .summary-card-grid > div { padding: 10px 12px; border-right: 1px solid var(--border); }
        .summary-card-grid > div:last-child { border-right: none; }
        .summary-card-value { font-weight: 700; font-size: 15px; margin-top: 4px; }
        .pay-to-bar { margin-top: 8px; padding: 6px 10px; border-left: 3px solid var(--accent); background: var(--light); font-size: 11px; color: #374151; }
        .remittance-note { margin-top: 14px; padding: 8px 12px; background: #fef9c3; border-radius: 4px; font-size: 11px; color: #854d0e; }
        .declaration { border: 1px solid var(--border); padding: 7px 8px; min-height: 48px; color: var(--muted); font-size: 11.5px; }
        .section { white-space: pre-wrap; color: #374151; font-size: 13px; line-height: 1.75; }
        body.is-contract-document .page { height: 297mm; min-height: 0; overflow: hidden; }
        .contract-inline-slot:empty { display: none; }
        .contract-inline-slot { margin-top: 4px; }
        .contract-page-body { min-height: 1px; }
        .contract-term-block { color: #374151; overflow-wrap: anywhere; }
        .contract-term-section-title h2 { margin-top: 8px; }
        .contract-term-heading { margin: 8px 0 3px; font-size: 14px; line-height: 1.45; font-weight: 700; }
        .contract-term-subheading { margin: 6px 0 2px; font-size: 14px; line-height: 1.45; font-weight: 700; }
        .contract-term-line { margin: 0 0 3px; font-size: 14px; line-height: 1.45; white-space: pre-wrap; }
        .contract-term-notice { margin-top: 12px; color: var(--muted); font-size: 10px; line-height: 1.4; }
        .contract-signature-block { margin-top: 8px; }
        .contract-signature-block .signature { margin-top: 0; }
        .packing-blocked-panel { margin-top: 22px; border: 1px solid #f59e0b; background: #fffbeb; padding: 18px; color: #92400e; }
        .packing-blocked-panel h2 { margin: 0 0 8px; border: 0; padding: 0; color: #78350f; }
        .packing-blocked-panel p { margin: 0; font-size: 13px; line-height: 1.7; }
        .signature { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-top: 12px; }
        .sig-box { border: 1px solid var(--border); padding: 7px; }
        .sig-title { font-weight: 700; margin-bottom: 6px; }
        .sig-kv { display: grid; grid-template-columns: 38px 1fr; gap: 3px 8px; font-size: 11px; }
        .sig-line { margin-top: 24px; border-top: 1px solid var(--accent); }
        .footer { position: absolute; left: 12mm; right: 12mm; bottom: 8mm; margin-top: 0; padding-top: 8px; border-top: 1px solid var(--border); display: flex; justify-content: space-between; color: #6b7280; font-size: 10.5px; }
        .page.is-hidden { display: none; }
        .page.is-measuring-final-merge { height: 297mm; min-height: 0; overflow: hidden; }
        .final-content-merged [data-final-content-body] h2,
        .is-measuring-final-merge [data-final-content-body] h2 { margin-top: 4px; }
        .final-content-merged [data-final-content-body] .summary-wrap,
        .is-measuring-final-merge [data-final-content-body] .summary-wrap { margin-top: 3px; }
        .final-content-merged [data-final-content-body] .terms-grid,
        .is-measuring-final-merge [data-final-content-body] .terms-grid { margin-top: 2px; }
        .final-content-merged [data-final-content-body] .term-item,
        .is-measuring-final-merge [data-final-content-body] .term-item { line-height: 1.25; }
        .final-content-merged [data-final-content-body] .signature,
        .is-measuring-final-merge [data-final-content-body] .signature { margin-top: 4px; }
        .final-content-merged [data-final-content-body] .sig-box,
        .is-measuring-final-merge [data-final-content-body] .sig-box { padding: 5px; }
        .final-content-merged [data-final-content-body] .sig-line,
        .is-measuring-final-merge [data-final-content-body] .sig-line { margin-top: 10px; }
        .panel, .summary-wrap, .notes-box, .terms-grid, .term-item, .bank-block, .summary-card, .declaration, .signature, .sig-box { break-inside: avoid; page-break-inside: avoid; }
        thead { display: table-header-group; }
        tfoot { display: table-footer-group; }
        tr, .product-row { break-inside: avoid; page-break-inside: avoid; }
        @media print {
            body { background: #fff; }
            .page { width: 210mm; height: 297mm; min-height: 0; margin: 0; box-shadow: none; }
            .summary-card { margin-top: 7px; }
            .summary-card-title { padding: 6px 9px; }
            .summary-card-grid > div { padding: 7px 9px; }
            .topbar, .print-alert { display: none; }
        }
    </style>
</head>
<body
    @class(['is-contract-document' => $showContract])
    @if ($showContract) data-contract-pagination-status="pending" @endif
>
    <div class="topbar" data-preview-toolbar>
        @if (session('error'))
            <div class="print-alert">{{ session('error') }}</div>
        @endif
        @if($packingPlanNotApplied)
            <div class="print-alert">包装方案尚未应用或已经失效；正式装箱内容已被阻断，请回到编辑页检查并应用包装方案。</div>
        @endif
        @include('admin.crm.quotes.partials.print-controls')
    </div>

    @if ($packingPlanNotApplied)
        <div class="page" data-print-page data-packing-print-blocked>
            @include('admin.crm.quotes.partials.print-header', ['seller' => $seller, 'quote' => $quote, 'title' => $title, 'documentKind' => $documentKind])

            <div class="packing-blocked-panel">
                <h2>包装方案尚未应用或已经失效</h2>
                <p>为避免误发旧的逐行装箱数据，本页不会显示或打印正式装箱内容。请返回单据编辑页，检查商品分配、包装尺寸与重量后，点击“应用包装方案”。</p>
            </div>

            <div class="footer">
                <div>{{ $seller['website'] ?: '' }}</div>
                <div>{{ $quote->quote_no }} · BLOCKED</div>
            </div>
        </div>
    @elseif ($isPI)
        {{-- ====== PI ITEM PAGES: Items + Summary on final item page ====== --}}
        @foreach ($itemPages as $pageIndex => $itemPage)
            @php $pageNumber = $pageIndex + 1; @endphp
            <div class="page{{ $pageIndex > 0 ? ' page-break' : '' }}" data-print-page data-item-page>
                @if ($itemPage['is_first'])
                    @include('admin.crm.quotes.partials.print-header', ['seller' => $seller, 'quote' => $quote, 'title' => $title, 'documentKind' => $documentKind])

                    @include('admin.crm.quotes.partials.print-buyer-commercial', ['quote' => $quote, 'label' => $label, 'documentKind' => $documentKind])

                    <div class="pay-to-bar">
                        <strong>{{ $label('make_payment_to', 'Make Payment To') }}:</strong> {{ $seller['name'] }} &nbsp;|&nbsp; {{ $label('bank_details_final_page', 'Bank details on the final page') }} &rarr;
                    </div>
                @else
                    <div class="header continuation-header">
                        <div class="brand">
                            <div>
                                <div class="company-name">{{ $seller['name'] }}</div>
                                <div class="muted">{{ $quote->quote_no }}</div>
                            </div>
                        </div>
                        <div class="title-box">
                            <div class="continuation-kicker">{{ $label('items_continued', 'Items continued') }}</div>
                            <div class="muted continuation-page-count" data-page-count-line>{{ $pageCountLabel($pageNumber) }}</div>
                        </div>
                    </div>
                @endif

                @include('admin.crm.quotes.partials.print-items', ['quote' => $quote, 'items' => $itemPage['items'], 'startIndex' => $itemPage['start_index'], 'documentKind' => $documentKind, 'showImages' => $showImages, 'showPrices' => $showPrices, 'isPacking' => false, 'label' => $label, 'money' => $money, 'weight' => $weight])

                @if ($itemPage['is_final'])
                    @if ($showPrices)
                        @include('admin.crm.quotes.partials.print-summary', ['quote' => $quote, 'label' => $label, 'money' => $money, 'showTax' => false])
                    @endif

                    @include('admin.crm.quotes.partials.print-terms', ['quote' => $quote, 'label' => $label, 'documentKind' => $documentKind])
                @endif

                <div class="footer">
                    <div>{{ $seller['website'] ?: '' }}</div>
                    <div data-page-label>{{ $pageLabel($pageNumber) }}</div>
                </div>
            </div>
        @endforeach

        @if ($hasSeparateFinalContentPage)
            @php $finalContentPageNumber = $totalItemPages + 1; @endphp
            <div class="page page-break" data-print-page data-final-content-page>
                <div class="header continuation-header">
                    <div class="brand">
                        <div>
                            <div class="company-name">{{ $seller['name'] }}</div>
                            <div class="muted">{{ $quote->quote_no }}</div>
                        </div>
                    </div>
                    <div class="title-box">
                        <div class="continuation-kicker">{{ $label('summary_terms', 'Summary & Terms') }}</div>
                        <div class="muted continuation-page-count" data-page-count-line>{{ $pageCountLabel($finalContentPageNumber) }}</div>
                    </div>
                </div>

                <div data-final-content-body>
                    @if ($showPrices)
                        @include('admin.crm.quotes.partials.print-summary', ['quote' => $quote, 'label' => $label, 'money' => $money, 'showTax' => false])
                    @endif

                    @include('admin.crm.quotes.partials.print-terms', ['quote' => $quote, 'label' => $label, 'documentKind' => $documentKind])
                </div>

                <div class="footer">
                    <div>{{ $seller['website'] ?: '' }}</div>
                    <div data-page-label>{{ $pageLabel($finalContentPageNumber) }}</div>
                </div>
            </div>
        @endif

        {{-- ====== PI FINAL PAGE: Bank Account ====== --}}
        @php $bankPageNumber = $totalItemPages + $finalContentPageCount + 1; @endphp
        <div class="page page-break" data-print-page>
            <div class="header">
                <div class="brand">
                    <div>
                        <div class="company-name">{{ $seller['name'] }}</div>
                        <div class="muted">{{ $quote->quote_no }}</div>
                    </div>
                </div>
                <div class="title-box">
                    <h1>{{ $label('bank_account_title', 'BANK ACCOUNT') }}</h1>
                    <div class="muted" style="margin-top:4px;" data-page-count-line>{{ $pageCountLabel($bankPageNumber) }}</div>
                </div>
            </div>

            @if (!empty(array_filter($bank)))
                <div class="bank-block">
                    <div class="bank-title">{{ $label('bank_wire_transfer', 'Bank Account for Wire Transfer') }}</div>
                    <div class="bank-grid">
                        @if (!empty($bank['beneficiary']))
                            <div class="bank-item wide"><div class="label">{{ $label('beneficiary', 'Beneficiary') }}:</div><div>{{ $bank['beneficiary'] }}</div></div>
                        @endif
                        @if (!empty($bank['bank_name']))
                            <div class="bank-item"><div class="label">{{ $label('bank_name', 'Bank Name') }}:</div><div>{{ $bank['bank_name'] }}</div></div>
                        @endif
                        @if (!empty($bank['account_no']))
                            <div class="bank-item"><div class="label">{{ $label('account_no', 'Account No.') }}:</div><div>{{ $bank['account_no'] }}</div></div>
                        @endif
                        @if (!empty($bank['bank_code']))
                            <div class="bank-item"><div class="label">{{ $label('bank_code', 'Bank Code') }}:</div><div>{{ $bank['bank_code'] }}</div></div>
                        @endif
                        @if (!empty($bank['branch_code']))
                            <div class="bank-item"><div class="label">{{ $label('branch_code', 'Branch Code') }}:</div><div>{{ $bank['branch_code'] }}</div></div>
                        @endif
                        @if (!empty($bank['swift']))
                            <div class="bank-item"><div class="label">{{ $label('swift', 'SWIFT') }}:</div><div>{{ $bank['swift'] }}</div></div>
                        @endif
                        @if (!empty($bank['payment_method']))
                            <div class="bank-item"><div class="label">{{ $label('payment_method', 'Payment Method') }}:</div><div>{{ $bank['payment_method'] }}</div></div>
                        @endif
                        <div class="bank-item"><div class="label">{{ $label('currency', 'Currency') }}:</div><div>{{ $quote->currency }}</div></div>
                        @if (!empty($bank['bank_address']))
                            <div class="bank-item wide"><div class="label">{{ $label('bank_address', 'Bank Address') }}:</div><div>{{ $bank['bank_address'] }}</div></div>
                        @endif
                        @if (!empty($bank['country_region']))
                            <div class="bank-item wide"><div class="label">{{ $label('country_region', 'Country / Region') }}:</div><div>{{ $bank['country_region'] }}</div></div>
                        @endif
                    </div>
                </div>
            @endif

            <h2>{{ $label('payment_summary', 'Payment Summary') }}</h2>
            @php
                $depositPct = max(0, min(100, (int) ($quote->deposit_percent ?? 60)));
                $balancePct = 100 - $depositPct;
                $total = (float) ($quote->grand_total ?: $quote->total_amount);
                $depositAmt = round($total * $depositPct / 100, 2);
                $balanceAmt = round($total * $balancePct / 100, 2);
            @endphp
            <table>
                <tr><td>{{ $label('invoice_total', 'Invoice Total') }}</td><td class="right">{{ $quote->currency }} {{ $money($total) }}</td></tr>
                <tr><td>{{ \App\Support\GeoFlow\CrmDocumentLocale::text($documentLanguage, 'deposit_required', 'Deposit Required (:percent%)', ['percent' => $depositPct]) }}</td><td class="right"><strong>{{ $quote->currency }} {{ $money($depositAmt) }}</strong></td></tr>
                @if ($balancePct > 0)
                    <tr><td>{{ \App\Support\GeoFlow\CrmDocumentLocale::text($documentLanguage, 'balance_before_shipment', 'Balance Before Shipment (:percent%)', ['percent' => $balancePct]) }}</td><td class="right">{{ $quote->currency }} {{ $money($balanceAmt) }}</td></tr>
                @endif
            </table>

            <div class="remittance-note">
                {{ \App\Support\GeoFlow\CrmDocumentLocale::text($documentLanguage, 'remittance_note', 'Please include :reference in your remittance reference.', ['reference' => $quote->quote_no]) }}
            </div>

            @include('admin.crm.quotes.partials.print-signature', ['quote' => $quote, 'label' => $label, 'documentKind' => $documentKind])

            <div class="footer">
                <div>{{ $seller['website'] ?: '' }}</div>
                <div data-page-label>{{ $pageLabel($bankPageNumber) }}</div>
            </div>
        </div>
    @else
        {{-- ====== Dynamic pages: Quotation / Invoice / Packing List / Contract ====== --}}
        @foreach ($itemPages as $pageIndex => $itemPage)
            @php $pageNumber = $pageIndex + 1; @endphp
            <div class="page{{ $pageIndex > 0 ? ' page-break' : '' }}" data-print-page data-item-page>
                @if ($itemPage['is_first'])
                    @include('admin.crm.quotes.partials.print-header', ['seller' => $seller, 'quote' => $quote, 'title' => $title, 'documentKind' => $documentKind])

                    @include('admin.crm.quotes.partials.print-buyer-commercial', ['quote' => $quote, 'label' => $label, 'documentKind' => $documentKind])

                    {{-- Invoice: logistics summary panels before items --}}
                    @if ($isInvoice)
                        @include('admin.crm.quotes.partials.print-invoice-logistics', ['quote' => $quote, 'totalPackages' => $totalPackages, 'totalNetWeight' => $totalNetWeight, 'totalGrossWeight' => $totalGrossWeight, 'totalVolume' => $totalVolume, 'label' => $label])
                    @endif

                    @if ($isPacking)
                        @include('admin.crm.quotes.partials.print-pl-shipment', ['quote' => $quote, 'label' => $label])
                    @endif
                @else
                    <div class="header continuation-header">
                        <div class="brand">
                            <div>
                                <div class="company-name">{{ $seller['name'] }}</div>
                                <div class="muted">{{ $quote->quote_no }}</div>
                            </div>
                        </div>
                        <div class="title-box">
                            <div class="continuation-kicker">{{ $label('items_continued', 'Items continued') }}</div>
                            <div class="muted continuation-page-count" data-page-count-line>{{ $pageCountLabel($pageNumber) }}</div>
                        </div>
                    </div>
                @endif

                @if($isPacking && $usePackagePlan)
                    @include('admin.crm.quotes.partials.print-package-plan-items', ['packages' => $itemPage['items'], 'startIndex' => $itemPage['start_index'], 'label' => $label, 'money' => $money, 'weight' => $weight])
                @else
                    @include('admin.crm.quotes.partials.print-items', ['quote' => $quote, 'items' => $itemPage['items'], 'startIndex' => $itemPage['start_index'], 'documentKind' => $documentKind, 'showImages' => $showImages, 'showPrices' => $showPrices, 'isPacking' => $isPacking, 'label' => $label, 'money' => $money, 'weight' => $weight])
                @endif

                @if ($itemPage['is_final'])
                    <div data-final-content-inline>
                        @include('admin.crm.quotes.partials.print-final-content')
                    </div>
                @endif

                <div class="footer">
                    <div>{{ $seller['website'] ?: '' }}</div>
                    <div data-page-label>{{ $pageLabel($pageNumber) }}</div>
                </div>
            </div>
        @endforeach

        @if ($hasSeparateFinalContentPage)
            @php $finalContentPageNumber = $totalItemPages + 1; @endphp
            <div class="page page-break" data-print-page data-final-content-page>
                <div class="header continuation-header">
                    <div class="brand">
                        <div>
                            <div class="company-name">{{ $seller['name'] }}</div>
                            <div class="muted">{{ $quote->quote_no }}</div>
                        </div>
                    </div>
                    <div class="title-box">
                        <div class="continuation-kicker">{{ $label('summary_terms', 'Summary & Terms') }}</div>
                        <div class="muted continuation-page-count" data-page-count-line>{{ $pageCountLabel($finalContentPageNumber) }}</div>
                    </div>
                </div>

                <div data-final-content-body>
                    @include('admin.crm.quotes.partials.print-final-content')
                </div>

                <div class="footer">
                    <div>{{ $seller['website'] ?: '' }}</div>
                    <div data-page-label>{{ $pageLabel($finalContentPageNumber) }}</div>
                </div>
            </div>
        @endif

        @if ($showContract)
            @php $contractPageNumber = $totalItemPages + $finalContentPageCount + 1; @endphp
            <div class="page page-break" data-print-page data-contract-page data-contract-page-template>
                <div class="header continuation-header">
                    <div class="brand">
                        <div>
                            <div class="company-name">{{ $seller['name'] }}</div>
                            <div class="muted">{{ $quote->quote_no }}</div>
                        </div>
                    </div>
                    <div class="title-box">
                        <div class="continuation-kicker" data-contract-page-kicker>
                            {{ $label('contract_terms_continued', 'Contract terms continued') }}
                        </div>
                        <div class="muted continuation-page-count" data-page-count-line>{{ $pageCountLabel($contractPageNumber) }}</div>
                    </div>
                </div>

                <div class="contract-page-body" data-contract-page-body>
                    @include('admin.crm.quotes.partials.print-contract-terms', [
                        'quote' => $quote,
                        'label' => $label,
                        'documentKind' => $documentKind,
                    ])
                </div>

                <div class="footer">
                    <div>{{ $seller['website'] ?: '' }}</div>
                    <div data-page-label>{{ $pageLabel($contractPageNumber) }}</div>
                </div>
            </div>
        @endif
    @endif
    <script>
        (() => {
            const documentTitle = @json($title);
            const pageTemplate = @json($label('page_of', 'Page :current of :total'));
            const documentPageTemplate = @json($label('document_page_of', ':title · Page :current of :total'));
            const contractTermsLabel = @json($label('contract_terms', 'Contract Terms'));
            const contractTermsContinuedLabel = @json($label('contract_terms_continued', 'Contract terms continued'));
            const isContractDocument = document.body.classList.contains('is-contract-document');
            const safetyGap = 6;
            const interpolate = (template, replacements) => Object.entries(replacements)
                .reduce((value, [key, replacement]) => value.split(`:${key}`).join(String(replacement)), template);

            const visiblePages = () => Array.from(document.querySelectorAll('[data-print-page]'))
                .filter((page) => !page.classList.contains('is-hidden'));

            const updatePageLabels = () => {
                const pages = visiblePages();
                const total = pages.length;

                pages.forEach((page, index) => {
                    const pageNumber = index + 1;
                    page.querySelectorAll('[data-page-count-line]').forEach((element) => {
                        element.textContent = interpolate(pageTemplate, { current: pageNumber, total });
                    });
                    page.querySelectorAll('[data-page-label]').forEach((element) => {
                        element.textContent = interpolate(documentPageTemplate, { title: documentTitle, current: pageNumber, total });
                    });
                });
            };

            const availableBottom = (page) => {
                const footer = page.querySelector(':scope > .footer');

                return footer ? footer.getBoundingClientRect().top - safetyGap : page.getBoundingClientRect().bottom - safetyGap;
            };

            const targetItemPageFor = (finalPage) => {
                const pages = visiblePages();
                const finalIndex = pages.indexOf(finalPage);
                if (finalIndex < 1) {
                    return null;
                }

                return pages
                    .slice(0, finalIndex)
                    .reverse()
                    .find((page) => page.matches('[data-item-page]')) || null;
            };

            const tryMergeFinalContent = (finalPage) => {
                const finalContent = finalPage.querySelector('[data-final-content-body]');
                const targetPage = targetItemPageFor(finalPage);
                const targetFooter = targetPage?.querySelector(':scope > .footer') || null;

                if (!finalContent || !targetPage || !targetFooter || targetPage.querySelector('[data-final-content-inline]')) {
                    return false;
                }

                const placeholder = document.createComment('final-content-placeholder');
                finalContent.parentNode.insertBefore(placeholder, finalContent);
                targetPage.classList.add('is-measuring-final-merge');
                targetPage.insertBefore(finalContent, targetFooter);

                const fits = finalContent.getBoundingClientRect().bottom <= availableBottom(targetPage);
                if (!fits) {
                    targetPage.classList.remove('is-measuring-final-merge');
                    placeholder.replaceWith(finalContent);
                    return false;
                }

                placeholder.remove();
                finalPage.classList.add('is-hidden');
                finalPage.setAttribute('aria-hidden', 'true');
                targetPage.classList.remove('is-measuring-final-merge');
                targetPage.classList.add('final-content-merged');
                targetPage.setAttribute('data-final-content-merged', 'true');

                return true;
            };

            const contractBlocksInOrder = () => Array.from(document.querySelectorAll('[data-contract-block]'))
                .sort((left, right) => Number(left.dataset.contractOrder || 0) - Number(right.dataset.contractOrder || 0));

            const contractUnits = (blocks) => {
                const units = [];

                for (let index = 0; index < blocks.length;) {
                    const keepWithNext = Math.max(0, Number.parseInt(blocks[index].dataset.keepWithNext || '0', 10) || 0);
                    const unit = blocks.slice(index, Math.min(blocks.length, index + keepWithNext + 1));
                    units.push(unit);
                    index += unit.length;
                }

                return units;
            };

            const appendContractUnit = (unit, container) => {
                unit.forEach((block) => container.appendChild(block));
            };

            const removeContractUnit = (unit) => {
                unit.forEach((block) => block.remove());
            };

            const contractDestinationFits = (unit, destination) => {
                appendContractUnit(unit, destination.container);
                const lastBlock = unit[unit.length - 1];
                const fits = lastBlock.getBoundingClientRect().bottom <= availableBottom(destination.page);

                if (!fits) {
                    removeContractUnit(unit);
                }

                return fits;
            };

            const createContractPage = (templatePage, afterPage) => {
                const page = templatePage.cloneNode(true);
                page.removeAttribute('data-contract-page-template');
                page.classList.remove('is-hidden');
                page.removeAttribute('aria-hidden');
                page.querySelector('[data-contract-page-body]')?.replaceChildren();
                afterPage.after(page);

                return {
                    page,
                    container: page.querySelector('[data-contract-page-body]'),
                    type: 'contract',
                };
            };

            const updateContractPageKickers = (inlineSlot) => {
                const hasInlineTerms = Boolean(inlineSlot?.querySelector('[data-contract-block]'));
                const pages = Array.from(document.querySelectorAll('[data-contract-page]'))
                    .filter((page) => !page.classList.contains('is-hidden'));

                pages.forEach((page, index) => {
                    const kicker = page.querySelector('[data-contract-page-kicker]');
                    if (kicker) {
                        kicker.textContent = hasInlineTerms || index > 0
                            ? contractTermsContinuedLabel
                            : contractTermsLabel;
                    }
                });
            };

            const paginateContractContent = () => {
                const templatePage = document.querySelector('[data-contract-page-template]');
                const inlineSlot = document.querySelector('[data-contract-inline-slot]');
                if (!templatePage) {
                    return [];
                }

                const blocks = contractBlocksInOrder();
                blocks.forEach((block) => block.remove());
                document.querySelectorAll('[data-contract-page]:not([data-contract-page-template])')
                    .forEach((page) => page.remove());

                const templateBody = templatePage.querySelector('[data-contract-page-body]');
                templateBody?.replaceChildren();
                inlineSlot?.replaceChildren();
                templatePage.classList.remove('is-hidden');
                templatePage.removeAttribute('aria-hidden');

                let currentDestination = inlineSlot
                    ? {
                        page: inlineSlot.closest('[data-print-page]'),
                        container: inlineSlot,
                        type: 'inline',
                    }
                    : null;
                let lastContractPage = templatePage;
                let templatePageUsed = false;
                const overflowBlocks = [];

                const nextContractDestination = () => {
                    if (!templatePageUsed) {
                        templatePageUsed = true;

                        return {
                            page: templatePage,
                            container: templateBody,
                            type: 'contract',
                        };
                    }

                    const destination = createContractPage(templatePage, lastContractPage);
                    lastContractPage = destination.page;

                    return destination;
                };

                contractUnits(blocks).forEach((unit) => {
                    if (currentDestination && contractDestinationFits(unit, currentDestination)) {
                        return;
                    }

                    const destinationWasEmpty = currentDestination?.type === 'contract'
                        && !currentDestination.container.querySelector('[data-contract-block]');
                    if (destinationWasEmpty) {
                        appendContractUnit(unit, currentDestination.container);
                        overflowBlocks.push(...unit);

                        return;
                    }

                    currentDestination = nextContractDestination();
                    if (!contractDestinationFits(unit, currentDestination)) {
                        appendContractUnit(unit, currentDestination.container);
                        overflowBlocks.push(...unit);
                    }
                });

                if (!templatePageUsed) {
                    templatePage.classList.add('is-hidden');
                    templatePage.setAttribute('aria-hidden', 'true');
                }

                updateContractPageKickers(inlineSlot);

                return overflowBlocks;
            };

            const detectPageOverflow = () => visiblePages().filter((page) => {
                const footer = page.querySelector(':scope > .footer');
                const bottom = availableBottom(page);

                return Array.from(page.children).some((element) => {
                    if (element === footer || element.classList.contains('footer')) {
                        return false;
                    }

                    return element.getBoundingClientRect().bottom > bottom + 1;
                });
            });

            const autoPaginate = () => {
                document.querySelectorAll('[data-final-content-page]:not(.is-hidden)').forEach((finalPage) => {
                    tryMergeFinalContent(finalPage);
                });

                const overflowBlocks = isContractDocument ? paginateContractContent() : [];
                updatePageLabels();

                const overflowingPages = isContractDocument ? detectPageOverflow() : [];
                const hasOverflow = overflowBlocks.length > 0 || overflowingPages.length > 0;
                if (isContractDocument) {
                    document.body.dataset.contractPaginationStatus = hasOverflow ? 'overflow' : 'ready';
                    document.body.dataset.contractOverflowPages = overflowingPages
                        .map((page) => visiblePages().indexOf(page) + 1)
                        .join(',');
                }
                window.GeoFlowCrmDocumentPaginationReady = !hasOverflow;
            };

            const scheduleAutoPaginate = () => {
                window.requestAnimationFrame(() => {
                    window.requestAnimationFrame(autoPaginate);
                });
            };

            window.GeoFlowCrmDocumentAutoPaginate = autoPaginate;
            window.addEventListener('load', scheduleAutoPaginate);
            window.addEventListener('beforeprint', autoPaginate);

            if (document.fonts?.ready) {
                document.fonts.ready.then(scheduleAutoPaginate).catch(() => {});
            }
        })();
    </script>
</body>
</html>
