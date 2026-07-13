@if ($documentKind !== 'invoice')
<div class="signature">
    <div class="sig-box">
        <div class="sig-title">{{ $label('seller', 'Seller') }}</div>
        <div class="sig-kv">
            <div>{{ $label('name', 'Name') }}:</div><div>{{ $quote->owner ?: '_______________' }}</div>
            <div>{{ $label('date', 'Date') }}:</div><div>{{ $formatDate($quote->created_at) ?: '_______________' }}</div>
        </div>
    </div>
    @if ($documentKind === 'invoice')
        <div class="sig-box">
            <div class="sig-title">{{ $label('authorized_signature', 'Authorized Signature') }}</div>
            <div class="sig-line">&nbsp;</div>
        </div>
    @else
        <div class="sig-box">
            <div class="sig-title">{{ $label('buyer', 'Buyer') }}</div>
            <div class="sig-kv">
                <div>{{ $label('name', 'Name') }}:</div><div>_______________</div>
                <div>{{ $label('date', 'Date') }}:</div><div>_______________</div>
            </div>
        </div>
    @endif
</div>
@if ((string) ($quote->signature_notes ?? '') !== '')
    <div class="muted" style="margin-top:8px;">{{ $quote->signature_notes }}</div>
@endif

@endif
