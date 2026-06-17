@if ($documentKind === 'invoice')
    {{-- CI: Exporter/Seller (left) + Importer/Buyer (right) --}}
    <div class="info-grid">
        <div class="panel">
            <div class="panel-title">{{ $isZh ? '出口商 / 卖方' : 'Exporter / Seller' }}</div>
            <div class="kv">
                <div class="label">{{ $isZh ? '公司' : 'Company' }}:</div><div>{{ $seller['name'] }}</div>
                @if ((string) ($seller['address'] ?? '') !== '')
                    <div class="label">{{ $isZh ? '地址' : 'Address' }}:</div><div>{{ $seller['address'] }}</div>
                @endif
                @if ((string) ($seller['phone'] ?? '') !== '')
                    <div class="label">Tel:</div><div>{{ $seller['phone'] }}</div>
                @endif
                @if ((string) ($seller['email'] ?? '') !== '')
                    <div class="label">Email:</div><div>{{ $seller['email'] }}</div>
                @endif
                <div class="label">{{ $isZh ? '联系人' : 'Contact' }}:</div><div>{{ $quote->owner ?: '_______________' }}</div>
            </div>
        </div>
        <div class="panel">
            <div class="panel-title">{{ $isZh ? '进口商/买方' : 'Importer / Buyer' }}</div>
            <div class="kv">
                @if ((string) ($quote->buyer_company ?? '') !== '')
                    <div class="label">{{ $isZh ? '公司' : 'Company' }}:</div><div>{{ $quote->buyer_company }}</div>
                @endif
                @if ((string) ($quote->buyer_tax_number ?? '') !== '')
                    <div class="label">{{ $isZh ? '税号' : 'Tax ID' }}:</div><div>{{ $quote->buyer_tax_number }}</div>
                @endif
                @if ((string) ($quote->buyer_contact ?? '') !== '')
                    <div class="label">{{ $isZh ? '联系人' : 'Contact' }}:</div><div>{{ $quote->buyer_contact }}</div>
                @endif
                @if ((string) ($quote->buyer_email ?? '') !== '')
                    <div class="label">Email:</div><div>{{ $quote->buyer_email }}</div>
                @endif
                @if ((string) ($quote->buyer_phone ?? '') !== '')
                    <div class="label">{{ $isZh ? '电话' : 'Phone' }}:</div><div>{{ $quote->buyer_phone }}</div>
                @endif
                @if ((string) ($quote->buyer_address ?? '') !== '')
                    <div class="label">{{ $isZh ? '地址' : 'Address' }}:</div><div>{{ $quote->buyer_address }}</div>
                @endif
                @if ((string) ($quote->buyer_country ?? '') !== '')
                    <div class="label">{{ $isZh ? '国家' : 'Country' }}:</div><div>{{ $quote->buyer_country }}</div>
                @endif
            </div>
        </div>
    </div>
@elseif ($documentKind === 'contract')
    {{-- Contract: Buyer/Importer (left) + Seller (right) --}}
    <div class="info-grid">
        <div class="panel">
            <div class="panel-title">{{ $isZh ? '买方/进口商' : 'Buyer/Importer' }}</div>
            <div class="kv">
                @if ((string) ($quote->buyer_company ?? '') !== '')
                    <div class="label">{{ $isZh ? '公司' : 'Company' }}:</div><div>{{ $quote->buyer_company }}</div>
                @endif
                @if ((string) ($quote->buyer_tax_number ?? '') !== '')
                    <div class="label">{{ $isZh ? '税号' : 'Tax ID' }}:</div><div>{{ $quote->buyer_tax_number }}</div>
                @endif
                @if ((string) ($quote->buyer_contact ?? '') !== '')
                    <div class="label">{{ $isZh ? '联系人' : 'Contact' }}:</div><div>{{ $quote->buyer_contact }}</div>
                @endif
                @if ((string) ($quote->buyer_email ?? '') !== '')
                    <div class="label">Email:</div><div>{{ $quote->buyer_email }}</div>
                @endif
                @if ((string) ($quote->buyer_phone ?? '') !== '')
                    <div class="label">{{ $isZh ? '电话' : 'Phone' }}:</div><div>{{ $quote->buyer_phone }}</div>
                @endif
                @if ((string) ($quote->buyer_address ?? '') !== '')
                    <div class="label">{{ $isZh ? '地址' : 'Address' }}:</div><div>{{ $quote->buyer_address }}</div>
                @endif
                @if ((string) ($quote->buyer_country ?? '') !== '')
                    <div class="label">{{ $isZh ? '国家' : 'Country' }}:</div><div>{{ $quote->buyer_country }}</div>
                @endif
            </div>
        </div>
        <div class="panel">
            <div class="panel-title">{{ $isZh ? '卖方信息' : 'Seller Info' }}</div>
            <div class="kv">
                <div class="label">{{ $isZh ? '公司' : 'Company' }}:</div><div>{{ $seller['name'] }}</div>
                @if ((string) ($seller['address'] ?? '') !== '')
                    <div class="label">{{ $isZh ? '地址' : 'Address' }}:</div><div>{{ $seller['address'] }}</div>
                @endif
                @if ((string) ($seller['phone'] ?? '') !== '')
                    <div class="label">Tel:</div><div>{{ $seller['phone'] }}</div>
                @endif
                @if ((string) ($seller['email'] ?? '') !== '')
                    <div class="label">Email:</div><div>{{ $seller['email'] }}</div>
                @endif
                <div class="label">{{ $isZh ? '联系人' : 'Contact' }}:</div><div>{{ $quote->owner ?: '_______________' }}</div>
            </div>
        </div>
    </div>
@elseif ($documentKind === 'packing_list')
    {{-- PL: Shipper/Seller (left) + Consignee/Buyer (right) --}}
    <div class="info-grid">
        <div class="panel">
            <div class="panel-title">{{ $isZh ? '发货人 / 卖方' : 'Shipper / Seller' }}</div>
            <div class="kv">
                <div class="label">{{ $isZh ? '公司' : 'Company' }}:</div><div>{{ $seller['name'] }}</div>
                @if ((string) ($seller['address'] ?? '') !== '')
                    <div class="label">{{ $isZh ? '地址' : 'Address' }}:</div><div>{{ $seller['address'] }}</div>
                @endif
                @if ((string) ($seller['phone'] ?? '') !== '')
                    <div class="label">Tel:</div><div>{{ $seller['phone'] }}</div>
                @endif
                @if ((string) ($seller['email'] ?? '') !== '')
                    <div class="label">Email:</div><div>{{ $seller['email'] }}</div>
                @endif
                <div class="label">{{ $isZh ? '联系人' : 'Contact' }}:</div><div>{{ $quote->owner ?: '_______________' }}</div>
            </div>
        </div>
        <div class="panel">
            <div class="panel-title">{{ $isZh ? '收货人/买方' : 'Consignee / Buyer' }}</div>
            <div class="kv">
                @if ((string) ($quote->buyer_company ?? '') !== '')
                    <div class="label">{{ $isZh ? '公司' : 'Company' }}:</div><div>{{ $quote->buyer_company }}</div>
                @endif
                @if ((string) ($quote->buyer_tax_number ?? '') !== '')
                    <div class="label">{{ $isZh ? '税号' : 'Tax ID' }}:</div><div>{{ $quote->buyer_tax_number }}</div>
                @endif
                @if ((string) ($quote->buyer_contact ?? '') !== '')
                    <div class="label">{{ $isZh ? '联系人' : 'Contact' }}:</div><div>{{ $quote->buyer_contact }}</div>
                @endif
                @if ((string) ($quote->buyer_email ?? '') !== '')
                    <div class="label">Email:</div><div>{{ $quote->buyer_email }}</div>
                @endif
                @if ((string) ($quote->buyer_phone ?? '') !== '')
                    <div class="label">{{ $isZh ? '电话' : 'Phone' }}:</div><div>{{ $quote->buyer_phone }}</div>
                @endif
                @if ((string) ($quote->buyer_address ?? '') !== '')
                    <div class="label">{{ $isZh ? '地址' : 'Address' }}:</div><div>{{ $quote->buyer_address }}</div>
                @endif
                @if ((string) ($quote->buyer_country ?? '') !== '')
                    <div class="label">{{ $isZh ? '国家' : 'Country' }}:</div><div>{{ $quote->buyer_country }}</div>
                @endif
            </div>
        </div>
    </div>
@else
    {{-- Quotation / PI: Buyer (left) + Commercial Info (right) --}}
    <div class="info-grid">
        <div class="panel">
            <div class="panel-title">Buyer / Customer</div>
            <div class="kv">
                @if ((string) ($quote->buyer_company ?? '') !== '')
                    <div class="label">{{ $isZh ? '公司' : 'Company' }}:</div><div>{{ $quote->buyer_company }}</div>
                @endif
                @if ((string) ($quote->buyer_tax_number ?? '') !== '')
                    <div class="label">{{ $isZh ? '税号' : 'Tax ID' }}:</div><div>{{ $quote->buyer_tax_number }}</div>
                @endif
                @if ((string) ($quote->buyer_contact ?? '') !== '')
                    <div class="label">{{ $isZh ? '联系人' : 'Contact' }}:</div><div>{{ $quote->buyer_contact }}</div>
                @endif
                @if ((string) ($quote->buyer_email ?? '') !== '')
                    <div class="label">Email:</div><div>{{ $quote->buyer_email }}</div>
                @endif
                @if ((string) ($quote->buyer_phone ?? '') !== '')
                    <div class="label">{{ $isZh ? '电话' : 'Phone' }}:</div><div>{{ $quote->buyer_phone }}</div>
                @endif
                @if ((string) ($quote->buyer_address ?? '') !== '')
                    <div class="label">{{ $isZh ? '地址' : 'Address' }}:</div><div>{{ $quote->buyer_address }}</div>
                @endif
                @if ((string) ($quote->buyer_country ?? '') !== '')
                    <div class="label">{{ $isZh ? '国家' : 'Country' }}:</div><div>{{ $quote->buyer_country }}</div>
                @endif
            </div>
        </div>
        <div class="panel">
            <div class="panel-title">{{ $isZh ? '商业信息' : 'Commercial Info' }}</div>
            <div class="kv-wide">
                @if ((string) ($quote->trade_term ?? '') !== '')
                    <div class="label">{{ $label('trade_term', 'Trade Term') }}:</div><div>{{ $quote->trade_term }}</div>
                @endif
                @if ((string) ($quote->lead_time ?? '') !== '' && $documentKind !== 'invoice' && $documentKind !== 'packing_list')
                    <div class="label">{{ $label('lead_time', 'Lead Time') }}:</div><div>{{ $quote->lead_time }}</div>
                @endif
                @if ((string) ($quote->origin_country ?? '') !== '')
                    <div class="label">{{ $isZh ? '原产国' : 'Origin' }}:</div><div>{{ $quote->origin_country }}</div>
                @endif
                @if ($documentKind !== 'invoice' && $documentKind !== 'packing_list' && (string) ($quote->valid_until ?? '') !== '')
                    <div class="label">{{ $isZh ? '有效期' : 'Validity' }}:</div><div>{{ \Carbon\Carbon::parse($quote->valid_until)->format('M d, Y') }}</div>
                @endif
            </div>
        </div>
    </div>
@endif
