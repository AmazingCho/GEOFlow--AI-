@php
    $packages = $packages ?? collect();
    $startIndex = (int) ($startIndex ?? 0);
    $packageTypeLabels = [
        'wooden_case' => $label('package_type_wooden_case', 'Wooden Case'),
        'carton' => $label('package_type_carton', 'Carton'),
        'pallet' => $label('package_type_pallet', 'Pallet'),
        'other' => $label('package_type_other', 'Other'),
    ];
@endphp

<h2>{{ $label('packages', 'Packages') }}</h2>
<table data-package-plan-table>
    <thead>
        <tr>
            <th class="center" style="width:28px;">#</th>
            <th style="width:86px;">{{ $label('package_no', 'Package No.') }}</th>
            <th style="width:76px;">{{ $label('package_type', 'Type') }}</th>
            <th>{{ $label('description_goods', 'Description of Goods') }}</th>
            <th class="right" style="width:64px;">{{ $label('net_weight_short', 'N.W. (kg)') }}</th>
            <th class="right" style="width:64px;">{{ $label('gross_weight_short', 'G.W. (kg)') }}</th>
            <th style="width:105px;">{{ $label('package_size_cm', 'Pkg Size (cm)') }}</th>
            <th class="right" style="width:62px;">{{ $label('cbm', 'CBM') }}</th>
        </tr>
    </thead>
    <tbody>
        @foreach($packages as $package)
            @php
                $startIndex++;
                $pkgLen = (float) ($package->package_length ?? 0);
                $pkgWid = (float) ($package->package_width ?? 0);
                $pkgHgt = (float) ($package->package_height ?? 0);
            @endphp
            <tr>
                <td class="center">{{ $startIndex }}</td>
                <td><strong>{{ $package->package_no }}</strong></td>
                <td>{{ $packageTypeLabels[(string) $package->package_type] ?? $packageTypeLabels['other'] }}</td>
                <td>
                    <div class="package-goods-list">
                        @foreach($package->allocations as $allocation)
                            @php $quoteItem = $allocation->quoteItem; @endphp
                            @if($quoteItem)
                                <div class="package-goods-item">
                                    <strong>{{ $quoteItem->item_name }}</strong>
                                    @if((string) ($quoteItem->model ?? '') !== '')
                                        <span class="muted"> · {{ $quoteItem->model }}</span>
                                    @endif
                                    <div class="muted">{{ $label('qty', 'Qty') }}: {{ $money($allocation->allocated_quantity) }} {{ $quoteItem->unit }}</div>
                                </div>
                            @endif
                        @endforeach
                    </div>
                    @if((string) ($package->notes ?? '') !== '')
                        <div class="muted" style="margin-top:4px;">{{ $package->notes }}</div>
                    @endif
                </td>
                <td class="right">{{ $weight($package->net_weight) }}</td>
                <td class="right">{{ $weight($package->gross_weight) }}</td>
                <td>
                    @if($pkgLen > 0 && $pkgWid > 0 && $pkgHgt > 0)
                        {{ number_format($pkgLen, 1) }}×{{ number_format($pkgWid, 1) }}×{{ number_format($pkgHgt, 1) }}
                    @else
                        -
                    @endif
                </td>
                <td class="right">{{ $weight($package->volume_cbm) }}</td>
            </tr>
        @endforeach
    </tbody>
</table>
