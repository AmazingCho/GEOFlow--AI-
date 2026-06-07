<div class="header">
    <div class="brand">
        @if ((string) ($seller['logo'] ?? '') !== '')
            <img src="{{ $seller['logo'] }}" alt="{{ $seller['name'] }}" class="logo">
        @else
            <div style="width:86px;height:44px;border:1px dashed var(--border);display:flex;align-items:center;justify-content:center;color:#9ca3af;font-size:10px;">LOGO</div>
        @endif
        <div>
            <div class="company-name">{{ $seller['name'] }}</div>
            @if ((string) ($seller['address'] ?? '') !== '')<div class="muted">{{ $seller['address'] }}</div>@endif
            <div class="muted">
                @if ((string) ($seller['phone'] ?? '') !== '')Tel: {{ $seller['phone'] }}@endif
                @if ((string) ($seller['phone'] ?? '') !== '' && (string) ($seller['email'] ?? '') !== '') | @endif
                @if ((string) ($seller['email'] ?? '') !== '')Email: {{ $seller['email'] }}@endif
            </div>
            @if ((string) ($seller['website'] ?? '') !== '')<div class="muted">Web: {{ $seller['website'] }}</div>@endif
        </div>
    </div>
    <div class="title-box">
        <h1>{{ $title }}</h1>
        <div class="{{ $documentKind === 'packing_list' ? 'doc-meta-wide' : 'doc-meta' }}">
            @if ($documentKind === 'packing_list')
                <div class="label">{{ $isZh ? '装箱单号' : 'Packing No.' }}:</div><div class="value">{{ $quote->quote_no }}</div>
                <div class="label">{{ $isZh ? '日期' : 'Date' }}:</div><div>{{ $quote->created_at?->format('M d, Y') ?? '' }}</div>
                <div class="label">{{ $isZh ? '发票号' : 'Invoice No.' }}:</div><div>{{ $quote->quote_no }}</div>
            @elseif ($documentKind === 'invoice')
                <div class="label">{{ $isZh ? '发票号' : 'Invoice No.' }}:</div><div class="value">{{ $quote->quote_no }}</div>
                <div class="label">{{ $isZh ? '日期' : 'Date' }}:</div><div>{{ $quote->created_at?->format('M d, Y') ?? '' }}</div>
                <div class="label">{{ $isZh ? '币种' : 'Currency' }}:</div><div>{{ $quote->currency }}</div>
            @else
                <div class="label">{{ $isZh ? '编号' : 'No.' }}:</div><div class="value">{{ $quote->quote_no }}</div>
                <div class="label">{{ $isZh ? '日期' : 'Date' }}:</div><div>{{ $quote->created_at?->format('M d, Y') ?? '' }}</div>
                @if ((string) ($quote->valid_until ?? '') !== '')<div class="label">{{ $isZh ? '有效期' : 'Valid Until' }}:</div><div>{{ \Carbon\Carbon::parse($quote->valid_until)->format('M d, Y') }}</div>@endif
                <div class="label">{{ $isZh ? '币种' : 'Currency' }}:</div><div>{{ $quote->currency }}</div>
            @endif
        </div>
    </div>
</div>
