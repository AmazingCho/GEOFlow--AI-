<h2>{{ $label('summary', 'Summary') }}</h2>
@php
    $showTax = $showTax ?? false;
    $hasShippingFee = (float) ($quote->shipping_fee ?? 0) > 0;
@endphp
<div class="summary-wrap">
    @if ((string) ($quote->notes ?? '') !== '')
        <div class="notes-box">
            <strong>{{ $label('note', 'Note') }}:</strong> {{ $quote->notes }}
        </div>
    @else
        <div></div>
    @endif
    <table class="summary-table">
        <tr><td>{{ $label('subtotal', 'Items Subtotal') }}</td><td class="right nowrap">{{ $quote->currency }} {{ $money($quote->total_amount) }}</td></tr>
        @if ($showTax)
            @if ($hasShippingFee)
                <tr><td>{{ $label('freight_shipping', 'Freight / Shipping') }}</td><td class="right nowrap">{{ $quote->currency }} {{ $money($quote->shipping_fee) }}</td></tr>
            @endif
            @if ((float) ($quote->discount_amount ?? 0) > 0)
                <tr><td>{{ $label('discount', 'Discount') }}</td><td class="right nowrap">- {{ $quote->currency }} {{ $money($quote->discount_amount) }}</td></tr>
            @endif
            @if ((float) ($quote->tax_amount ?? 0) > 0)
                <tr><td>{{ $label('tax', 'Tax') }}</td><td class="right nowrap">{{ $quote->currency }} {{ $money($quote->tax_amount) }}</td></tr>
            @endif
            <tr><td>{{ $label('total_invoice_value', 'Total Invoice Value') }}</td><td class="right nowrap">{{ $quote->currency }} {{ $money($quote->grand_total ?: $quote->total_amount) }}</td></tr>
        @else
            @if ($hasShippingFee)
                <tr><td>{{ $label('shipping', 'Shipping Fee') }}</td><td class="right nowrap">{{ $quote->currency }} {{ $money($quote->shipping_fee) }}</td></tr>
            @endif
            @if ((float) ($quote->discount_amount ?? 0) > 0)
                <tr><td>{{ $label('discount', 'Discount') }}</td><td class="right nowrap">- {{ $quote->currency }} {{ $money($quote->discount_amount) }}</td></tr>
            @endif
            <tr><td>{{ $label('grand_total', 'Grand Total') }}</td><td class="right nowrap">{{ $quote->currency }} {{ $money($quote->grand_total ?: $quote->total_amount) }}</td></tr>
        @endif
    </table>
</div>
