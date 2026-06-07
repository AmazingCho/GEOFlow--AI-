<div class="grid-2">
    <div class="panel">
        <div class="panel-title">{{ $isZh ? '运输信息' : 'Shipping Information' }}</div>
        <div class="kv-wide">
            @if ((string) ($quote->trade_term ?? '') !== '')
                <div class="label">{{ $label('trade_term', 'Trade Term') }}:</div><div>{{ $quote->trade_term }}</div>
            @endif
            @if ((string) ($quote->port_of_loading ?? '') !== '')
                <div class="label">{{ $isZh ? '装运港' : 'Port of Loading' }}:</div><div>{{ $quote->port_of_loading }}</div>
            @endif
            @if ((string) ($quote->port_of_destination ?? '') !== '')
                <div class="label">{{ $isZh ? '目的港' : 'Port of Dest.' }}:</div><div>{{ $quote->port_of_destination }}</div>
            @endif
            @if ((string) ($quote->transport_mode ?? '') !== '')
                <div class="label">{{ $isZh ? '运输方式' : 'Transport' }}:</div><div>{{ $quote->transport_mode }}</div>
            @endif
            @if ((string) ($quote->origin_country ?? '') !== '')
                <div class="label">{{ $isZh ? '原产国' : 'Origin' }}:</div><div>{{ $quote->origin_country }}</div>
            @endif
        </div>
    </div>
    <div class="panel">
        <div class="panel-title">{{ $isZh ? '包装汇总' : 'Package Summary' }}</div>
        <div class="kv-wide">
            <div class="label">{{ $isZh ? '总件数' : 'Total Packages' }}:</div><div>{{ $totalPackages ?: '-' }}</div>
            <div class="label">{{ $isZh ? '净重' : 'Net Weight' }}:</div><div>{{ $totalNetWeight > 0 ? number_format($totalNetWeight, 3) : '-' }} kg</div>
            <div class="label">{{ $isZh ? '毛重' : 'Gross Weight' }}:</div><div>{{ $totalGrossWeight > 0 ? number_format($totalGrossWeight, 3) : '-' }} kg</div>
            <div class="label">{{ $isZh ? '总体积' : 'Total Volume' }}:</div><div>{{ $totalVolume > 0 ? number_format($totalVolume, 3) : '-' }} CBM</div>
        </div>
    </div>
</div>
