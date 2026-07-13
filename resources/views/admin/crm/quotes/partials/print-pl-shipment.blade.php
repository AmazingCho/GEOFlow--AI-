
@php
    $hasMark = (string) ($quote->shipping_mark ?? '') !== '';
    $hasOrigin = (string) ($quote->origin_country ?? '') !== '';
    $hasTrade = (string) ($quote->trade_term ?? '') !== '';
    $hasLoad = (string) ($quote->port_of_loading ?? '') !== '';
    $hasDest = (string) ($quote->port_of_destination ?? '') !== '';
    $hasShip = $hasMark || $hasLoad || $hasDest || $hasTrade || $hasOrigin;
@endphp

@if ($hasShip)
    <div class="grid-3">
        <div class="panel">
            <div class="panel-title">{{ $label('shipment', 'Shipment') }}</div>
            <div class="kv-wide">
                @if ($hasOrigin)
                    <div class="label">{{ $label('origin', 'Origin') }}:</div><div>{{ $quote->origin_country }}</div>
                @endif
                @if ($hasTrade)
                    <div class="label">{{ $label('trade_term', 'Trade Term') }}:</div><div>{{ $quote->trade_term }}</div>
                @endif
            </div>
        </div>
        <div class="panel">
            <div class="panel-title">{{ $label('shipping_mark', 'Shipping Mark') }}</div>
            <div style="white-space:pre;font-family:monospace;font-size:11px;line-height:1.5;">{{ $hasMark ? $quote->shipping_mark : '-' }}</div>
        </div>
        <div class="panel">
            <div class="panel-title">{{ $label('port_info', 'Port Info') }}</div>
            <div class="kv-wide">
                @if ($hasLoad)
                    <div class="label">{{ $label('loading', 'Loading') }}:</div><div>{{ $quote->port_of_loading }}</div>
                @endif
                @if ($hasDest)
                    <div class="label">{{ $label('destination', 'Destination') }}:</div><div>{{ $quote->port_of_destination }}</div>
                @endif
            </div>
        </div>
    </div>
@endif
