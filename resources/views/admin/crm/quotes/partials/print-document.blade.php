@php
    $documentKind = $documentKind ?? (string) ($quote->document_type ?? 'quotation');
    $isZh = (string) ($quote->document_language ?? 'en') === 'zh_CN';
    $titles = $isZh
        ? [
            'quotation' => '报价单',
            'proforma_invoice' => '形式发票',
            'invoice' => '正式发票',
            'packing_list' => '装箱单',
            'contract' => '合同',
        ]
        : [
            'quotation' => 'Quotation',
            'proforma_invoice' => 'Proforma Invoice',
            'invoice' => 'Commercial Invoice',
            'packing_list' => 'Packing List',
            'contract' => 'Contract',
        ];
    $title = $titles[$documentKind] ?? $titles['quotation'];
    $labels = $documentLabels ?? [];
    $label = static fn (string $key, string $fallback): string => (string) ($labels[$key] ?? $fallback);
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

    // Compute logistics totals for invoice / packing list
    $totalPackages = 0;
    $totalNetWeight = 0.0;
    $totalGrossWeight = 0.0;
    $totalVolume = 0.0;
    foreach ($quote->items as $item) {
        $totalPackages += (int) ($item->package_count ?? 0);
        $totalNetWeight += (float) ($item->net_weight ?? 0);
        $totalGrossWeight += (float) ($item->gross_weight ?? 0);
        $totalVolume += (float) ($item->volume_cbm ?? 0);
    }

    $allItems = $quote->items->values();
    $textLength = static function (mixed $value): int {
        $text = trim(strip_tags((string) $value));

        return function_exists('mb_strlen') ? mb_strlen($text) : strlen($text);
    };
    $itemHasRenderableImage = static fn ($item): bool => (bool) ($item->image || trim((string) ($item->image_path ?? '')) !== '');
    $hasVisibleItemImages = $showImages && $allItems->contains($itemHasRenderableImage);
    $estimateItemWeight = static function ($item) use ($showImages, $isPacking, $isInvoice, $textLength, $itemHasRenderableImage): int {
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

    if ($isPacking) {
        $singlePageCapacity = 20;
        $firstItemsCapacity = 28;
        $continuationItemsCapacity = 36;
        $finalItemsCapacity = 22;
    } elseif ($hasVisibleItemImages) {
        $singlePageCapacity = 20;
        $firstItemsCapacity = 28;
        $continuationItemsCapacity = 34;
        $finalItemsCapacity = 22;
    } elseif ($isInvoice) {
        $singlePageCapacity = 24;
        $firstItemsCapacity = 32;
        $continuationItemsCapacity = 40;
        $finalItemsCapacity = 26;
    } elseif ($showContract) {
        $singlePageCapacity = 18;
        $firstItemsCapacity = 28;
        $continuationItemsCapacity = 36;
        $finalItemsCapacity = 18;
    } else {
        $singlePageCapacity = 24;
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
            return [[
                'items' => $items,
                'start_index' => 0,
                'is_first' => true,
                'is_final' => true,
            ]];
        }

        if ($remainingItemWeight($items, 0) <= $singlePageCapacity) {
            return [[
                'items' => $items,
                'start_index' => 0,
                'is_first' => true,
                'is_final' => true,
            ]];
        }

        $pages = [];
        [$firstChunk, $nextIndex] = $takeItemPage($items, 0, $firstItemsCapacity);
        if ($nextIndex >= $count && $count > 1) {
            $nextIndex = $count - 1;
            $firstChunk = $items->slice(0, $nextIndex)->values();
        }
        $pages[] = [
            'items' => $firstChunk,
            'start_index' => 0,
            'is_first' => true,
            'is_final' => false,
        ];

        while ($nextIndex < $count) {
            $startIndex = $nextIndex;
            $remainingWeight = $remainingItemWeight($items, $startIndex);
            $capacity = $remainingWeight <= $finalItemsCapacity ? $finalItemsCapacity : $continuationItemsCapacity;

            [$chunk, $nextIndex] = $takeItemPage($items, $startIndex, $capacity);
            if ($remainingWeight > $finalItemsCapacity && $nextIndex >= $count && $startIndex < ($count - 1)) {
                $nextIndex = $count - 1;
                $chunk = $items->slice($startIndex, $nextIndex - $startIndex)->values();
            }

            $pages[] = [
                'items' => $chunk,
                'start_index' => $startIndex,
                'is_first' => false,
                'is_final' => false,
            ];
        }

        $lastIndex = count($pages) - 1;
        $pages[$lastIndex]['is_final'] = true;

        return $pages;
    };

    $itemPages = $paginateItems($allItems);
    $totalItemPages = count($itemPages);
    $bankPageCount = $isPI ? 1 : 0;
    $totalPages = max(1, $totalItemPages + $bankPageCount);
    $pageLabel = static fn (int $pageNumber): string => $title.' · Page '.$pageNumber.' of '.$totalPages;
@endphp

<!doctype html>
<html lang="{{ $isZh ? 'zh-CN' : 'en' }}">
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
        body { margin: 0; background: #e9edf3; color: var(--text); font-family: Arial, "WenQuanYi Zen Hei", "Noto Sans CJK SC", "Noto Sans CJK TC", "Microsoft YaHei", Helvetica, sans-serif; font-size: 12px; line-height: 1.35; }
        .page { width: 210mm; min-height: 297mm; margin: 0 auto 20px; background: #fff; padding: 12mm 13mm 18mm; position: relative; }
        .page-break { page-break-before: always; }
        .topbar { display: flex; flex-wrap: wrap; justify-content: flex-end; gap: 8px; align-items: center; margin-bottom: 10px; text-align: right; }
        .doc-switcher { border: 1px solid var(--border); background: #fff; padding: 6px 10px; font-size: 11px; cursor: pointer; }
        .doc-action { border: 1px solid var(--border); background: #fff; color: var(--text); padding: 6px 10px; font-size: 11px; font-weight: 700; text-decoration: none; }
        .doc-action:hover { background: var(--light); }
        .print-alert { flex-basis: 100%; border: 1px solid #f59e0b; background: #fffbeb; color: #92400e; padding: 7px 9px; text-align: left; font-size: 11px; }
        .header { display: grid; grid-template-columns: 1.3fr 1fr; gap: 14px; align-items: start; border-bottom: 2px solid var(--accent); padding-bottom: 10px; }
        .continuation-header { margin-bottom: 10px; }
        .continuation-kicker { color: var(--muted); font-size: 11px; font-weight: 700; letter-spacing: .04em; text-transform: uppercase; }
        .brand { display: flex; gap: 12px; align-items: flex-start; }
        .logo { width: 86px; height: 44px; object-fit: contain; }
        .company-name { font-size: 14px; font-weight: 700; margin-bottom: 3px; }
        .muted { color: var(--muted); font-size: 11px; }
        .title-box { text-align: right; }
        h1 { margin: 0 0 6px; font-size: 24px; text-transform: uppercase; letter-spacing: .8px; }
        .doc-meta { display: grid; grid-template-columns: 86px 1fr; gap: 3px 8px; justify-content: end; font-size: 11.5px; max-width: 250px; margin-left: auto; }
        .doc-meta-wide { display: grid; grid-template-columns: 90px 1fr; gap: 3px 8px; justify-content: end; font-size: 11.5px; max-width: 260px; margin-left: auto; }
        .label { color: var(--muted); font-weight: 600; white-space: nowrap; }
        .info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-top: 10px; }
        .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-top: 10px; }
        .grid-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 10px; margin-top: 10px; }
        .panel { border: 1px solid var(--border); padding: 7px 8px; background: #fff; }
        .panel-title { padding: 5px 8px; margin: -7px -8px 6px; background: var(--light); border-bottom: 1px solid var(--border); font-weight: 700; font-size: 11px; text-transform: uppercase; letter-spacing: .02em; }
        .kv { display: grid; grid-template-columns: 92px 1fr; gap: 3px 8px; }
        .kv-wide { display: grid; grid-template-columns: 100px 1fr; gap: 3px 8px; }
        h2 { margin: 14px 0 6px; padding-bottom: 4px; border-bottom: 1px solid var(--border); font-size: 13px; font-weight: 700; text-transform: uppercase; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid var(--border); padding: 6px 7px; vertical-align: top; }
        th { background: var(--light); font-size: 11px; text-transform: uppercase; letter-spacing: .02em; }
        .right { text-align: right; }
        .center { text-align: center; }
        .nowrap { white-space: nowrap; }
        .thumb { width: 48px; height: 48px; border: 1px solid var(--border); object-fit: cover; flex-shrink: 0; }
        .product-row { display: flex; gap: 7px; align-items: flex-start; }
        .product-name { font-weight: 700; margin-bottom: 2px; }
        .summary-wrap { display: grid; grid-template-columns: 1fr 250px; gap: 12px; margin-top: 8px; align-items: start; }
        .notes-box { border: 1px solid var(--border); padding: 7px 8px; min-height: 52px; color: var(--muted); font-size: 11.5px; }
        .summary-table td { padding: 5px 7px; font-size: 11.5px; }
        .summary-table tr:last-child td { background: var(--accent); color: #fff; font-weight: 700; font-size: 13px; }
        .terms-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 6px 16px; margin-top: 8px; }
        .term-item { display: grid; grid-template-columns: max-content minmax(0, 1fr); gap: 6px; align-items: start; font-size: 11.5px; line-height: 1.45; }
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
        .signature { display: grid; grid-template-columns: 1fr 1fr; gap: 22px; margin-top: 20px; }
        .sig-box { border: 1px solid var(--border); padding: 10px; }
        .sig-title { font-weight: 700; margin-bottom: 6px; }
        .sig-kv { display: grid; grid-template-columns: 38px 1fr; gap: 3px 8px; font-size: 11px; }
        .sig-line { margin-top: 36px; border-top: 1px solid var(--accent); }
        .footer { position: absolute; left: 13mm; right: 13mm; bottom: 8mm; margin-top: 0; padding-top: 8px; border-top: 1px solid var(--border); display: flex; justify-content: space-between; color: #6b7280; font-size: 10.5px; }
        .panel, .summary-wrap, .notes-box, .terms-grid, .term-item, .bank-block, .summary-card, .declaration, .signature, .sig-box { break-inside: avoid; page-break-inside: avoid; }
        thead { display: table-header-group; }
        tfoot { display: table-footer-group; }
        tr, .product-row { break-inside: avoid; page-break-inside: avoid; }
        @media print {
            body { background: #fff; font-size: 11px; line-height: 1.25; }
            .page { width: 210mm; height: 297mm; min-height: 0; margin: 0; padding: 10mm 12mm 18mm; box-shadow: none; }
            .footer { left: 12mm; right: 12mm; bottom: 8mm; }
            h1 { font-size: 22px; }
            h2 { margin: 9px 0 4px; }
            th, td { padding: 4px 5px; }
            .muted { font-size: 10px; }
            .info-grid, .grid-2, .grid-3 { gap: 7px; margin-top: 7px; }
            .panel { padding: 5px 6px; }
            .panel-title { padding: 4px 6px; margin: -5px -6px 5px; }
            .summary-wrap { gap: 8px; margin-top: 6px; }
            .summary-table td { padding: 4px 6px; }
            .summary-card { margin-top: 7px; }
            .summary-card-title { padding: 6px 9px; }
            .summary-card-grid > div { padding: 7px 9px; }
            .terms-grid { gap: 4px 12px; margin-top: 6px; }
            .term-item { font-size: 10.5px; }
            .notes-box { min-height: 34px; font-size: 10.5px; }
            .signature { gap: 16px; margin-top: 12px; }
            .sig-box { padding: 7px; }
            .sig-line { margin-top: 24px; }
            .topbar, .print-alert { display: none; }
        }
    </style>
</head>
<body>
    @if ($isPI)
        {{-- ====== PI ITEM PAGES: Items + Summary on final item page ====== --}}
        @foreach ($itemPages as $pageIndex => $itemPage)
            @php $pageNumber = $pageIndex + 1; @endphp
            <div class="page{{ $pageIndex > 0 ? ' page-break' : '' }}">
                @if ($itemPage['is_first'])
                    <div class="topbar">
                        @if (session('error'))
                            <div class="print-alert">{{ session('error') }}</div>
                        @endif
                        <select class="doc-switcher" onchange="if(this.value) window.location.href=this.value">
                            @foreach (['quotation' => 'Quotation', 'proforma_invoice' => 'Proforma Invoice', 'invoice' => 'Commercial Invoice', 'packing_list' => 'Packing List', 'contract' => 'Contract'] as $typeVal => $typeLabel)
                                <option value="{{ route('admin.crm.quotes.print', ['quoteId' => (int) $quote->id, 'type' => $typeVal, 'language' => $quote->document_language ?? 'en']) }}" @selected($documentKind === $typeVal)>{{ $typeLabel }}</option>
                            @endforeach
                        </select>
                        <a class="doc-action" href="{{ route('admin.crm.quotes.pdf', ['quoteId' => (int) $quote->id, 'type' => $documentKind]) }}" onclick="this.textContent='{{ $isZh ? '生成中...' : 'Generating...' }}';">{{ $isZh ? '下载 PDF' : 'Download PDF' }}</a>
                    </div>

                    @include('admin.crm.quotes.partials.print-header', ['seller' => $seller, 'quote' => $quote, 'title' => $title, 'isZh' => $isZh, 'documentKind' => $documentKind])

                    @include('admin.crm.quotes.partials.print-buyer-commercial', ['quote' => $quote, 'label' => $label, 'documentKind' => $documentKind])

                    <div class="pay-to-bar">
                        <strong>{{ $isZh ? '收款方' : 'Make Payment To' }}:</strong> {{ $seller['name'] }} &nbsp;|&nbsp; {{ $isZh ? '银行信息见最后一页' : 'Bank details on the final page' }} &rarr;
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
                            <div class="continuation-kicker">{{ $isZh ? '明细续页' : 'Items continued' }}</div>
                            <h1>{{ $title }}</h1>
                            <div class="muted" style="margin-top:4px;">Page {{ $pageNumber }} of {{ $totalPages }}</div>
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
                    <div>{{ $pageLabel($pageNumber) }}</div>
                </div>
            </div>
        @endforeach

        {{-- ====== PI PAGE 2: Bank Account ====== --}}
        @php $bankPageNumber = $totalItemPages + 1; @endphp
        <div class="page page-break">
            <div class="topbar">
                <select class="doc-switcher" onchange="if(this.value) window.location.href=this.value">
                    @foreach (['quotation' => 'Quotation', 'proforma_invoice' => 'Proforma Invoice', 'invoice' => 'Commercial Invoice', 'packing_list' => 'Packing List', 'contract' => 'Contract'] as $typeVal => $typeLabel)
                        <option value="{{ route('admin.crm.quotes.print', ['quoteId' => (int) $quote->id, 'type' => $typeVal, 'language' => $quote->document_language ?? 'en']) }}" @selected($documentKind === $typeVal)>{{ $typeLabel }}</option>
                    @endforeach
                </select>
                <a class="doc-action" href="{{ route('admin.crm.quotes.pdf', ['quoteId' => (int) $quote->id, 'type' => $documentKind]) }}" onclick="this.textContent='{{ $isZh ? '生成中...' : 'Generating...' }}';">{{ $isZh ? '下载 PDF' : 'Download PDF' }}</a>
            </div>

            <div class="header">
                <div class="brand">
                    <div>
                        <div class="company-name">{{ $seller['name'] }}</div>
                        <div class="muted">{{ $quote->quote_no }}</div>
                    </div>
                </div>
                <div class="title-box">
                    <h1>BANK ACCOUNT</h1>
                    <div class="muted" style="margin-top:4px;">Page {{ $bankPageNumber }} of {{ $totalPages }}</div>
                </div>
            </div>

            @if (!empty(array_filter($bank)))
                <div class="bank-block">
                    <div class="bank-title">{{ $isZh ? '银行汇款信息' : 'Bank Account for Wire Transfer' }}</div>
                    <div class="bank-grid">
                        @if (!empty($bank['beneficiary']))
                            <div class="bank-item wide"><div class="label">{{ $isZh ? '收款人' : 'Beneficiary' }}:</div><div>{{ $bank['beneficiary'] }}</div></div>
                        @endif
                        @if (!empty($bank['bank_name']))
                            <div class="bank-item"><div class="label">{{ $isZh ? '银行名称' : 'Bank Name' }}:</div><div>{{ $bank['bank_name'] }}</div></div>
                        @endif
                        @if (!empty($bank['account_no']))
                            <div class="bank-item"><div class="label">{{ $isZh ? '账号' : 'Account No.' }}:</div><div>{{ $bank['account_no'] }}</div></div>
                        @endif
                        @if (!empty($bank['bank_code']))
                            <div class="bank-item"><div class="label">{{ $isZh ? '银行代码' : 'Bank Code' }}:</div><div>{{ $bank['bank_code'] }}</div></div>
                        @endif
                        @if (!empty($bank['branch_code']))
                            <div class="bank-item"><div class="label">{{ $isZh ? '分行代码' : 'Branch Code' }}:</div><div>{{ $bank['branch_code'] }}</div></div>
                        @endif
                        @if (!empty($bank['swift']))
                            <div class="bank-item"><div class="label">SWIFT:</div><div>{{ $bank['swift'] }}</div></div>
                        @endif
                        <div class="bank-item"><div class="label">{{ $isZh ? '币种' : 'Currency' }}:</div><div>{{ $quote->currency }}</div></div>
                        @if (!empty($bank['bank_address']))
                            <div class="bank-item wide"><div class="label">{{ $isZh ? '银行地址' : 'Bank Address' }}:</div><div>{{ $bank['bank_address'] }}</div></div>
                        @endif
                    </div>
                </div>
            @endif

            <h2>Payment Summary</h2>
            @php
                $depositPct = max(0, min(100, (int) ($quote->deposit_percent ?? 60)));
                $balancePct = 100 - $depositPct;
                $total = (float) ($quote->grand_total ?: $quote->total_amount);
                $depositAmt = round($total * $depositPct / 100, 2);
                $balanceAmt = round($total * $balancePct / 100, 2);
            @endphp
            <table>
                <tr><td>Invoice Total</td><td class="right">{{ $quote->currency }} {{ $money($total) }}</td></tr>
                <tr><td>Deposit Required ({{ $depositPct }}%)</td><td class="right"><strong>{{ $quote->currency }} {{ $money($depositAmt) }}</strong></td></tr>
                <tr><td>Balance Before Shipment ({{ $balancePct }}%)</td><td class="right">{{ $quote->currency }} {{ $money($balanceAmt) }}</td></tr>
            </table>

            <div class="remittance-note">
                &#9888;&#65039; Please include <strong>{{ $quote->quote_no }}</strong> in your remittance reference.
            </div>

            @include('admin.crm.quotes.partials.print-signature', ['quote' => $quote, 'label' => $label, 'isZh' => $isZh, 'documentKind' => $documentKind])

            <div class="footer">
                <div>{{ $seller['website'] ?: '' }}</div>
                <div>{{ $pageLabel($bankPageNumber) }}</div>
            </div>
        </div>
    @else
        {{-- ====== Dynamic pages: Quotation / Invoice / Packing List / Contract ====== --}}
        @foreach ($itemPages as $pageIndex => $itemPage)
            @php $pageNumber = $pageIndex + 1; @endphp
            <div class="page{{ $pageIndex > 0 ? ' page-break' : '' }}">
                @if ($itemPage['is_first'])
                    <div class="topbar">
                        @if (session('error'))
                            <div class="print-alert">{{ session('error') }}</div>
                        @endif
                        <select class="doc-switcher" onchange="if(this.value) window.location.href=this.value">
                            @foreach (['quotation' => 'Quotation', 'proforma_invoice' => 'Proforma Invoice', 'invoice' => 'Commercial Invoice', 'packing_list' => 'Packing List', 'contract' => 'Contract'] as $typeVal => $typeLabel)
                                <option value="{{ route('admin.crm.quotes.print', ['quoteId' => (int) $quote->id, 'type' => $typeVal, 'language' => $quote->document_language ?? 'en']) }}" @selected($documentKind === $typeVal)>{{ $typeLabel }}</option>
                            @endforeach
                        </select>
                        <a class="doc-action" href="{{ route('admin.crm.quotes.pdf', ['quoteId' => (int) $quote->id, 'type' => $documentKind]) }}" onclick="this.textContent='{{ $isZh ? '生成中...' : 'Generating...' }}';">{{ $isZh ? '下载 PDF' : 'Download PDF' }}</a>
                    </div>

                    @include('admin.crm.quotes.partials.print-header', ['seller' => $seller, 'quote' => $quote, 'title' => $title, 'isZh' => $isZh, 'documentKind' => $documentKind])

                    @include('admin.crm.quotes.partials.print-buyer-commercial', ['quote' => $quote, 'label' => $label, 'documentKind' => $documentKind])

                    {{-- Invoice: logistics summary panels before items --}}
                    @if ($isInvoice)
                        @include('admin.crm.quotes.partials.print-invoice-logistics', ['quote' => $quote, 'totalPackages' => $totalPackages, 'totalNetWeight' => $totalNetWeight, 'totalGrossWeight' => $totalGrossWeight, 'totalVolume' => $totalVolume, 'label' => $label])
                    @endif

                    @if ($isPacking)
                        @include('admin.crm.quotes.partials.print-pl-shipment', ['quote' => $quote, 'isZh' => $isZh, 'label' => $label])
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
                            <div class="continuation-kicker">{{ $isZh ? '明细续页' : 'Items continued' }}</div>
                            <h1>{{ $title }}</h1>
                            <div class="muted" style="margin-top:4px;">Page {{ $pageNumber }} of {{ $totalPages }}</div>
                        </div>
                    </div>
                @endif

                @include('admin.crm.quotes.partials.print-items', ['quote' => $quote, 'items' => $itemPage['items'], 'startIndex' => $itemPage['start_index'], 'documentKind' => $documentKind, 'showImages' => $showImages, 'showPrices' => $showPrices, 'isPacking' => $isPacking, 'label' => $label, 'money' => $money, 'weight' => $weight])

                @if ($itemPage['is_final'])
                    {{-- Packing List: summary card --}}
                    @if ($isPacking)
                        @include('admin.crm.quotes.partials.print-packing-summary', ['totalPackages' => $totalPackages, 'totalNetWeight' => $totalNetWeight, 'totalGrossWeight' => $totalGrossWeight, 'totalVolume' => $totalVolume])

                        <h2>{{ $label('notes', 'Notes') }}</h2>
                            <div class="notes-box">{{ $isZh ? '包装类型：出口级木箱。所有尺寸和重量仅供海关清关和物流参考。' : 'Package type: Export-grade wooden case. All dimensions and weights are for customs clearance and logistics reference.' }}</div>

                        @include('admin.crm.quotes.partials.print-signature', ['quote' => $quote, 'label' => $label, 'isZh' => $isZh, 'documentKind' => $documentKind])
                    @else
                        @if ($showPrices)
                            @include('admin.crm.quotes.partials.print-summary', ['quote' => $quote, 'label' => $label, 'money' => $money, 'showTax' => $isInvoice])
                        @endif

                        {{-- Invoice: declaration --}}
                        @if ($isInvoice)
                            <h2>Declaration</h2>
                            <div class="declaration">
                                The above information is true and correct. Goods are of Chinese origin unless otherwise stated.
                            </div>
                        @endif

                        @if (!$isInvoice)
                            @include('admin.crm.quotes.partials.print-terms', ['quote' => $quote, 'label' => $label, 'documentKind' => $documentKind])

                            @if ($showBank)
                                @if (!empty(array_filter($bank)))
                                    <h2>{{ $label('bank_account', 'Bank Account') }}</h2>
                                    <div class="bank-block">
                                        <div class="bank-grid">
                                            @if (!empty($bank['beneficiary']))
                                                <div class="bank-item wide"><div class="label">Beneficiary:</div><div>{{ $bank['beneficiary'] }}</div></div>
                                            @endif
                                            @if (!empty($bank['bank_name']))
                                                <div class="bank-item"><div class="label">Bank Name:</div><div>{{ $bank['bank_name'] }}</div></div>
                                            @endif
                                            @if (!empty($bank['account_no']))
                                                <div class="bank-item"><div class="label">Account No.:</div><div>{{ $bank['account_no'] }}</div></div>
                                            @endif
                                            @if (!empty($bank['bank_code']))
                                                <div class="bank-item"><div class="label">Bank Code:</div><div>{{ $bank['bank_code'] }}</div></div>
                                            @endif
                                            @if (!empty($bank['branch_code']))
                                                <div class="bank-item"><div class="label">Branch Code:</div><div>{{ $bank['branch_code'] }}</div></div>
                                            @endif
                                            @if (!empty($bank['swift']))
                                                <div class="bank-item"><div class="label">SWIFT:</div><div>{{ $bank['swift'] }}</div></div>
                                            @endif
                                            @if (!empty($bank['currency']))
                                                <div class="bank-item"><div class="label">Currency:</div><div>{{ $bank['currency'] }}</div></div>
                                            @endif
                                            @if (!empty($bank['bank_address']))
                                                <div class="bank-item wide"><div class="label">Bank Address:</div><div>{{ $bank['bank_address'] }}</div></div>
                                            @endif
                                        </div>
                                    </div>
                                @endif
                            @endif
                        @endif

                        @if ($showContract)
                            @include('admin.crm.quotes.partials.print-contract-terms', ['quote' => $quote, 'label' => $label, 'isZh' => $isZh])
                        @endif

                        @if (!$isInvoice && !$showContract && !$showPrices && (string) ($quote->notes ?? '') !== '')
                            <h2>{{ $label('notes', 'Notes') }}</h2>
                            <div class="section">{{ $quote->notes }}</div>
                        @endif

                        @include('admin.crm.quotes.partials.print-signature', ['quote' => $quote, 'label' => $label, 'isZh' => $isZh, 'documentKind' => $documentKind])
                    @endif
                @endif

                <div class="footer">
                    <div>{{ $seller['website'] ?: '' }}</div>
                    <div>{{ $pageLabel($pageNumber) }}</div>
                </div>
            </div>
        @endforeach
    @endif
</body>
</html>
