<div class="header">
    <div class="brand">
        @if ((string) ($seller['logo'] ?? '') !== '')
            <img src="{{ $seller['logo'] }}" alt="{{ $seller['name'] }}" class="logo">
        @else
            <div style="width:86px;height:44px;border:1px dashed var(--border);display:flex;align-items:center;justify-content:center;color:#9ca3af;font-size:10px;">{{ $label('logo', 'LOGO') }}</div>
        @endif
        <div>
            <div class="company-name">{{ $seller['name'] }}</div>
            @if ((string) ($seller['address'] ?? '') !== '')<div class="muted">{{ $seller['address'] }}</div>@endif
            <div class="muted">
                @if ((string) ($seller['phone'] ?? '') !== ''){{ $label('tel', 'Tel') }}: {{ $seller['phone'] }}@endif
                @if ((string) ($seller['phone'] ?? '') !== '' && (string) ($seller['email'] ?? '') !== '') | @endif
                @if ((string) ($seller['email'] ?? '') !== ''){{ $label('email', 'Email') }}: {{ $seller['email'] }}@endif
            </div>
            @if ((string) ($seller['website'] ?? '') !== '')<div class="muted">{{ $label('website', 'Web') }}: {{ $seller['website'] }}</div>@endif
        </div>
    </div>
    <div class="title-box">
        <h1>{{ $title }}</h1>
        <div class="{{ $documentKind === 'packing_list' ? 'doc-meta-wide' : 'doc-meta' }}">
            @if ($documentKind === 'packing_list')
                <div class="label">{{ $label('packing_no', 'Packing No.') }}:</div><div class="value">{{ $quote->quote_no }}</div>
                <div class="label">{{ $label('date', 'Date') }}:</div><div>{{ $formatDate($quote->created_at) }}</div>
                <div class="label">{{ $label('invoice_no', 'Invoice No.') }}:</div><div>{{ $quote->quote_no }}</div>
            @elseif ($documentKind === 'invoice')
                <div class="label">{{ $label('invoice_no', 'Invoice No.') }}:</div><div class="value">{{ $quote->quote_no }}</div>
                <div class="label">{{ $label('date', 'Date') }}:</div><div>{{ $formatDate($quote->created_at) }}</div>
                <div class="label">{{ $label('currency', 'Currency') }}:</div><div>{{ $quote->currency }}</div>
            @else
                <div class="label">{{ $label('number', 'No.') }}:</div><div class="value">{{ $quote->quote_no }}</div>
                <div class="label">{{ $label('date', 'Date') }}:</div><div>{{ $formatDate($quote->created_at) }}</div>
                @if ((string) ($quote->valid_until ?? '') !== '')<div class="label">{{ $label('valid_until', 'Valid Until') }}:</div><div>{{ $formatDate($quote->valid_until) }}</div>@endif
                <div class="label">{{ $label('currency', 'Currency') }}:</div><div>{{ $quote->currency }}</div>
            @endif
        </div>
    </div>
</div>
