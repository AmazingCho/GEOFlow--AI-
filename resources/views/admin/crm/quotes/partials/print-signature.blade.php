@if ($documentKind !== 'invoice')
<div class="signature">
    <div class="sig-box">
        <div class="sig-title">{{ $isZh ? '卖方' : 'Seller' }}</div>
        <div class="sig-kv">
            <div>{{ $isZh ? '姓名' : 'Name' }}:</div><div>{{ $quote->owner ?: '_______________' }}</div>
            <div>{{ $isZh ? '日期' : 'Date' }}:</div><div>{{ $quote->created_at?->format('M d, Y') ?? '_______________' }}</div>
        </div>
    </div>
    @if ($documentKind === 'invoice')
        <div class="sig-box">
            <div class="sig-title">{{ $isZh ? '授权签章' : 'Authorized Signature' }}</div>
            <div class="sig-line">&nbsp;</div>
        </div>
    @else
        <div class="sig-box">
            <div class="sig-title">{{ $isZh ? '买方' : 'Buyer' }}</div>
            <div class="sig-kv">
                <div>{{ $isZh ? '姓名' : 'Name' }}:</div><div>_______________</div>
                <div>{{ $isZh ? '日期' : 'Date' }}:</div><div>_______________</div>
            </div>
        </div>
    @endif
</div>
@if ((string) ($quote->signature_notes ?? '') !== '')
    <div class="muted" style="margin-top:8px;">{{ $quote->signature_notes }}</div>
@endif

@endif
