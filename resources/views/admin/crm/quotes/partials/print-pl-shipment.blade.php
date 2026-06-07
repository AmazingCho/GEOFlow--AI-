
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
            <div class="panel-title">{{ $isZh ? '运输' : 'Shipment' }}</div>
            <div class="kv-wide">
                @if ($hasOrigin)
                    <div class="label">{{ $isZh ? '原产国' : 'Origin' }}:</div><div>{{ $quote->origin_country }}</div>
                @endif
                @if ($hasTrade)
                    <div class="label">{{ $label('trade_term', 'Trade Term') }}:</div><div>{{ $quote->trade_term }}</div>
                @endif
            </div>
        </div>
        <div class="panel">
            <div class="panel-title">{{ $isZh ? '唛头' : 'Shipping Mark' }}</div>
            <div style="white-space:pre;font-family:monospace;font-size:11px;line-height:1.5;">{{ $hasMark ? $quote->shipping_mark : '-' }}</div>
        </div>
        <div class="panel">
            <div class="panel-title">{{ $isZh ? '港口信息' : 'Port Info' }}</div>
            <div class="kv-wide">
                @if ($hasLoad)
                    <div class="label">{{ $isZh ? '装运港' : 'Loading' }}:</div><div>{{ $quote->port_of_loading }}</div>
                @endif
                @if ($hasDest)
                    <div class="label">{{ $isZh ? '目的港' : 'Dest.' }}:</div><div>{{ $quote->port_of_destination }}</div>
                @endif
            </div>
        </div>
    </div>
@endif
