<h2>{{ $label('items', 'Items') }}</h2>
<table>
    <thead>
        @if ($isPacking)
            <tr>
                <th class="center" style="width:28px;">#</th>
                <th>{{ $isZh ? '品名' : 'Description of Goods' }}</th>
                <th style="width:78px;">Model</th>
                <th class="right" style="width:58px;">{{ $isZh ? '数量' : 'Qty' }}</th>
                <th class="right" style="width:64px;">{{ $isZh ? '件数' : 'Pkg Qty' }}</th>
                <th class="right" style="width:64px;">N.W. (kg)</th>
                <th class="right" style="width:64px;">G.W. (kg)</th>
                <th style="width:105px;">{{ $isZh ? '包装尺寸(cm)' : 'Pkg Size (cm)' }}</th>
                <th class="right" style="width:62px;">CBM</th>
            </tr>
        @else
            <tr>
                <th class="center" style="width:28px;">#</th>
                <th>{{ $isZh ? '品名' : 'Description' }}</th>
                <th style="width:78px;">Model</th>
                @if ($documentKind === 'invoice')<th style="width:78px;">{{ $label('hs_code', 'HS Code') }}</th>@endif
                <th class="right" style="width:58px;">{{ $isZh ? '数量' : 'Qty' }}</th>
                <th class="right" style="width:78px;">{{ $label('unit_price', 'Unit Price') }}</th>
                <th class="right" style="width:84px;">{{ $label('amount', 'Amount') }}</th>
            </tr>
        @endif
    </thead>
    <tbody>
        @php $index = 0; @endphp
        @foreach ($quote->items as $item)
            @php
                $index++;
                $itemImageUrl = $item->image
                    ? \App\Support\GeoFlow\ImageUrlNormalizer::toPublicUrl((string) $item->image->file_path)
                    : \App\Support\GeoFlow\ImageUrlNormalizer::toPublicUrl((string) ($item->image_path ?? ''));
            @endphp
            @if ($isPacking)
                <tr>
                    <td class="center">{{ $index }}</td>
                    <td>
                        <strong>{{ $item->item_name }}</strong>
                        @if ((string) ($item->description ?? '') !== '')<div class="muted">{{ $item->description }}</div>@endif
                    </td>
                    <td>{{ (string) ($item->model ?? '') ?: '-' }}</td>
                    <td class="right nowrap">{{ $money($item->quantity) }} {{ $item->unit }}</td>
                    <td class="right">{{ (int) ($item->package_count ?? 0) ?: '-' }}</td>
                    <td class="right">{{ $weight($item->net_weight) }}</td>
                    <td class="right">{{ $weight($item->gross_weight) }}</td>
                    <td>
                        @php
                            $pkgLen = (float) ($item->package_length ?? 0);
                            $pkgWid = (float) ($item->package_width ?? 0);
                            $pkgHgt = (float) ($item->package_height ?? 0);
                        @endphp
                        @if ($pkgLen > 0 && $pkgWid > 0 && $pkgHgt > 0)
                            {{ number_format($pkgLen, 1) }}×{{ number_format($pkgWid, 1) }}×{{ number_format($pkgHgt, 1) }}
                        @else
                            -
                        @endif
                    </td>
                    <td class="right">{{ $weight($item->volume_cbm) }}</td>
                </tr>
            @else
                <tr>
                    <td class="center">{{ $index }}</td>
                    <td>
                        @if ($showImages && $itemImageUrl !== '')
                            <div class="product-row">
                                <img src="{{ $itemImageUrl }}" alt="" class="thumb">
                                <div>
                                    <div class="product-name">{{ $item->item_name }}</div>
                                    @if ((string) ($item->description ?? '') !== '')<div class="muted">{{ $item->description }}</div>@endif
                                    @if ($documentKind === 'invoice')<div class="muted">Origin: China</div>@endif
                                </div>
                            </div>
                        @else
                            <div class="product-name">{{ $item->item_name }}</div>
                            @if ((string) ($item->description ?? '') !== '')<div class="muted">{{ $item->description }}</div>@endif
                            @if ($documentKind === 'invoice')<div class="muted">Origin: China</div>@endif
                        @endif
                    </td>
                    <td>{{ (string) ($item->model ?? '') ?: '-' }}</td>
                    @if ($documentKind === 'invoice')<td>{{ $item->hs_code ?: '-' }}</td>@endif
                    <td class="right nowrap">{{ $money($item->quantity) }} {{ $item->unit }}</td>
                    <td class="right nowrap">{{ $quote->currency }} {{ $money($item->unit_price) }}</td>
                    <td class="right nowrap">{{ $quote->currency }} {{ $money($item->amount) }}</td>
                </tr>
            @endif
        @endforeach
    </tbody>
</table>
