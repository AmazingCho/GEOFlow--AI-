<div class="grid-2">
    <div class="panel">
        <div class="panel-title">{{ $label('shipping_information', 'Shipping Information') }}</div>
        <div class="kv-wide">
            @if ((string) ($quote->trade_term ?? '') !== '')
                <div class="label">{{ $label('trade_term', 'Trade Term') }}:</div><div>{{ $quote->trade_term }}</div>
            @endif
            @if ((string) ($quote->port_of_loading ?? '') !== '')
                <div class="label">{{ $label('port_loading', 'Port of Loading') }}:</div><div>{{ $quote->port_of_loading }}</div>
            @endif
            @if ((string) ($quote->port_of_destination ?? '') !== '')
                <div class="label">{{ $label('port_destination', 'Port of Destination') }}:</div><div>{{ $quote->port_of_destination }}</div>
            @endif
            @if ((string) ($quote->transport_mode ?? '') !== '')
                <div class="label">{{ $label('transport', 'Transport') }}:</div><div>{{ $quote->transport_mode }}</div>
            @endif
            @if ((string) ($quote->origin_country ?? '') !== '')
                <div class="label">{{ $label('origin', 'Origin') }}:</div><div>{{ $quote->origin_country }}</div>
            @endif
        </div>
    </div>
    <div class="panel">
        <div class="panel-title">{{ $label('package_summary', 'Package Summary') }}</div>
        <div class="kv-wide">
            <div class="label">{{ $label('total_packages', 'Total Packages') }}:</div><div>{{ $totalPackages ?: '-' }}</div>
            <div class="label">{{ $label('total_net_weight', 'Total Net Weight') }}:</div><div>{{ $totalNetWeight > 0 ? number_format($totalNetWeight, 3) : '-' }} kg</div>
            <div class="label">{{ $label('total_gross_weight', 'Total Gross Weight') }}:</div><div>{{ $totalGrossWeight > 0 ? number_format($totalGrossWeight, 3) : '-' }} kg</div>
            <div class="label">{{ $label('total_volume', 'Total Volume') }}:</div><div>{{ $totalVolume > 0 ? number_format($totalVolume, 3) : '-' }} CBM</div>
        </div>
    </div>
</div>
