@php
    $terms = [];
    if ((string) ($quote->payment_terms ?? '') !== '') $terms['payment_terms'] = $label('payment_terms', 'Payment');
    if ((string) ($quote->delivery_terms ?? '') !== '') $terms['delivery_terms'] = $label('delivery_terms', 'Delivery');
    if ((string) ($quote->warranty_terms ?? '') !== '') $terms['warranty_terms'] = $label('warranty_terms', 'Warranty');
    if ((string) ($quote->installation_terms ?? '') !== '') $terms['installation_terms'] = $label('installation_terms', 'Installation');
    $terms['packing_terms'] = $label('packing_terms', 'Packing');
    $fullWidthTermFields = ['warranty_terms', 'installation_terms', 'packing_terms'];
@endphp
@if (!empty($terms))
    <h2>Terms &amp; Conditions</h2>
    <div class="terms-grid">
        @foreach ($terms as $field => $heading)
            @php($termClass = in_array($field, $fullWidthTermFields, true) ? 'term-item full' : 'term-item')
            <div class="{{ $termClass }}" data-term-field="{{ $field }}">
                <div class="label">{{ $heading }}:</div><div>{{ $field === 'packing_terms' && (string) ($quote->{$field} ?? '') === '' ? ($isZh ? '标准出口木箱' : 'Standard export wooden case') : $quote->{$field} }}</div>
            </div>
        @endforeach
    </div>
@endif
