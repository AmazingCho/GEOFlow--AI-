<div class="summary-card">
    <div class="summary-card-title">{{ $isZh ? '包装汇总' : 'Packing Summary' }}</div>
    <div class="summary-card-grid">
        <div>
            <div class="label">{{ $isZh ? '总件数' : 'Total Packages' }}</div>
            <div class="summary-card-value">{{ $totalPackages ?: '-' }}</div>
        </div>
        <div>
            <div class="label">{{ $isZh ? '总净重' : 'Total Net Weight' }}</div>
            <div class="summary-card-value">{{ $totalNetWeight > 0 ? number_format($totalNetWeight, 3) : '-' }} kg</div>
        </div>
        <div>
            <div class="label">{{ $isZh ? '总毛重' : 'Total Gross Weight' }}</div>
            <div class="summary-card-value">{{ $totalGrossWeight > 0 ? number_format($totalGrossWeight, 3) : '-' }} kg</div>
        </div>
        <div>
            <div class="label">{{ $isZh ? '总体积' : 'Total Volume' }}</div>
            <div class="summary-card-value">{{ $totalVolume > 0 ? number_format($totalVolume, 3) : '-' }} CBM</div>
        </div>
    </div>
</div>
