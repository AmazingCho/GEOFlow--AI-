@if ($documentKind === 'invoice')
    {{-- CI: Exporter/Seller (left) + Importer/Buyer (right) --}}
    <div class="info-grid">
        <div class="panel">
            <div class="panel-title">{{ $label('exporter_seller', 'Exporter / Seller') }}</div>
            <div class="kv">
                <div class="label">{{ $label('company', 'Company') }}:</div><div>{{ $seller['name'] }}</div>
                @if ((string) ($seller['address'] ?? '') !== '')
                    <div class="label">{{ $label('address', 'Address') }}:</div><div>{{ $seller['address'] }}</div>
                @endif
                @if ((string) ($seller['phone'] ?? '') !== '')
                    <div class="label">{{ $label('tel', 'Tel') }}:</div><div>{{ $seller['phone'] }}</div>
                @endif
                @if ((string) ($seller['email'] ?? '') !== '')
                    <div class="label">{{ $label('email', 'Email') }}:</div><div>{{ $seller['email'] }}</div>
                @endif
                <div class="label">{{ $label('contact', 'Contact') }}:</div><div>{{ $quote->owner ?: '_______________' }}</div>
            </div>
        </div>
        <div class="panel">
            <div class="panel-title">{{ $label('importer_buyer', 'Importer / Buyer') }}</div>
            <div class="kv">
                @if ((string) ($quote->buyer_company ?? '') !== '')
                    <div class="label">{{ $label('company', 'Company') }}:</div><div>{{ $quote->buyer_company }}</div>
                @endif
                @if ((string) ($quote->buyer_tax_number ?? '') !== '')
                    <div class="label">{{ $label('tax_id', 'Tax ID') }}:</div><div>{{ $quote->buyer_tax_number }}</div>
                @endif
                @if ((string) ($quote->buyer_contact ?? '') !== '')
                    <div class="label">{{ $label('contact', 'Contact') }}:</div><div>{{ $quote->buyer_contact }}</div>
                @endif
                @if ((string) ($quote->buyer_email ?? '') !== '')
                    <div class="label">{{ $label('email', 'Email') }}:</div><div>{{ $quote->buyer_email }}</div>
                @endif
                @if ((string) ($quote->buyer_phone ?? '') !== '')
                    <div class="label">{{ $label('phone', 'Phone') }}:</div><div>{{ $quote->buyer_phone }}</div>
                @endif
                @if ((string) ($quote->buyer_address ?? '') !== '')
                    <div class="label">{{ $label('address', 'Address') }}:</div><div>{{ $quote->buyer_address }}</div>
                @endif
                @if ((string) ($quote->buyer_country ?? '') !== '')
                    <div class="label">{{ $label('country', 'Country') }}:</div><div>{{ $quote->buyer_country }}</div>
                @endif
            </div>
        </div>
    </div>
@elseif ($documentKind === 'contract')
    {{-- Contract: Buyer/Importer (left) + Seller (right) --}}
    <div class="info-grid">
        <div class="panel">
            <div class="panel-title">{{ $label('buyer_importer', 'Buyer / Importer') }}</div>
            <div class="kv">
                @if ((string) ($quote->buyer_company ?? '') !== '')
                    <div class="label">{{ $label('company', 'Company') }}:</div><div>{{ $quote->buyer_company }}</div>
                @endif
                @if ((string) ($quote->buyer_tax_number ?? '') !== '')
                    <div class="label">{{ $label('tax_id', 'Tax ID') }}:</div><div>{{ $quote->buyer_tax_number }}</div>
                @endif
                @if ((string) ($quote->buyer_contact ?? '') !== '')
                    <div class="label">{{ $label('contact', 'Contact') }}:</div><div>{{ $quote->buyer_contact }}</div>
                @endif
                @if ((string) ($quote->buyer_email ?? '') !== '')
                    <div class="label">{{ $label('email', 'Email') }}:</div><div>{{ $quote->buyer_email }}</div>
                @endif
                @if ((string) ($quote->buyer_phone ?? '') !== '')
                    <div class="label">{{ $label('phone', 'Phone') }}:</div><div>{{ $quote->buyer_phone }}</div>
                @endif
                @if ((string) ($quote->buyer_address ?? '') !== '')
                    <div class="label">{{ $label('address', 'Address') }}:</div><div>{{ $quote->buyer_address }}</div>
                @endif
                @if ((string) ($quote->buyer_country ?? '') !== '')
                    <div class="label">{{ $label('country', 'Country') }}:</div><div>{{ $quote->buyer_country }}</div>
                @endif
            </div>
        </div>
        <div class="panel">
            <div class="panel-title">{{ $label('seller_info', 'Seller Info') }}</div>
            <div class="kv">
                <div class="label">{{ $label('company', 'Company') }}:</div><div>{{ $seller['name'] }}</div>
                @if ((string) ($seller['address'] ?? '') !== '')
                    <div class="label">{{ $label('address', 'Address') }}:</div><div>{{ $seller['address'] }}</div>
                @endif
                @if ((string) ($seller['phone'] ?? '') !== '')
                    <div class="label">{{ $label('tel', 'Tel') }}:</div><div>{{ $seller['phone'] }}</div>
                @endif
                @if ((string) ($seller['email'] ?? '') !== '')
                    <div class="label">{{ $label('email', 'Email') }}:</div><div>{{ $seller['email'] }}</div>
                @endif
                <div class="label">{{ $label('contact', 'Contact') }}:</div><div>{{ $quote->owner ?: '_______________' }}</div>
            </div>
        </div>
    </div>
@elseif ($documentKind === 'packing_list')
    {{-- PL: Shipper/Seller (left) + Consignee/Buyer (right) --}}
    <div class="info-grid">
        <div class="panel">
            <div class="panel-title">{{ $label('shipper_seller', 'Shipper / Seller') }}</div>
            <div class="kv">
                <div class="label">{{ $label('company', 'Company') }}:</div><div>{{ $seller['name'] }}</div>
                @if ((string) ($seller['address'] ?? '') !== '')
                    <div class="label">{{ $label('address', 'Address') }}:</div><div>{{ $seller['address'] }}</div>
                @endif
                @if ((string) ($seller['phone'] ?? '') !== '')
                    <div class="label">{{ $label('tel', 'Tel') }}:</div><div>{{ $seller['phone'] }}</div>
                @endif
                @if ((string) ($seller['email'] ?? '') !== '')
                    <div class="label">{{ $label('email', 'Email') }}:</div><div>{{ $seller['email'] }}</div>
                @endif
                <div class="label">{{ $label('contact', 'Contact') }}:</div><div>{{ $quote->owner ?: '_______________' }}</div>
            </div>
        </div>
        <div class="panel">
            <div class="panel-title">{{ $label('consignee_buyer', 'Consignee / Buyer') }}</div>
            <div class="kv">
                @if ((string) ($quote->buyer_company ?? '') !== '')
                    <div class="label">{{ $label('company', 'Company') }}:</div><div>{{ $quote->buyer_company }}</div>
                @endif
                @if ((string) ($quote->buyer_tax_number ?? '') !== '')
                    <div class="label">{{ $label('tax_id', 'Tax ID') }}:</div><div>{{ $quote->buyer_tax_number }}</div>
                @endif
                @if ((string) ($quote->buyer_contact ?? '') !== '')
                    <div class="label">{{ $label('contact', 'Contact') }}:</div><div>{{ $quote->buyer_contact }}</div>
                @endif
                @if ((string) ($quote->buyer_email ?? '') !== '')
                    <div class="label">{{ $label('email', 'Email') }}:</div><div>{{ $quote->buyer_email }}</div>
                @endif
                @if ((string) ($quote->buyer_phone ?? '') !== '')
                    <div class="label">{{ $label('phone', 'Phone') }}:</div><div>{{ $quote->buyer_phone }}</div>
                @endif
                @if ((string) ($quote->buyer_address ?? '') !== '')
                    <div class="label">{{ $label('address', 'Address') }}:</div><div>{{ $quote->buyer_address }}</div>
                @endif
                @if ((string) ($quote->buyer_country ?? '') !== '')
                    <div class="label">{{ $label('country', 'Country') }}:</div><div>{{ $quote->buyer_country }}</div>
                @endif
            </div>
        </div>
    </div>
@else
    {{-- Quotation / PI: Buyer (left) + Commercial Info (right) --}}
    <div class="info-grid">
        <div class="panel">
            <div class="panel-title">{{ $label('buyer_customer', 'Buyer / Customer') }}</div>
            <div class="kv">
                @if ((string) ($quote->buyer_company ?? '') !== '')
                    <div class="label">{{ $label('company', 'Company') }}:</div><div>{{ $quote->buyer_company }}</div>
                @endif
                @if ((string) ($quote->buyer_tax_number ?? '') !== '')
                    <div class="label">{{ $label('tax_id', 'Tax ID') }}:</div><div>{{ $quote->buyer_tax_number }}</div>
                @endif
                @if ((string) ($quote->buyer_contact ?? '') !== '')
                    <div class="label">{{ $label('contact', 'Contact') }}:</div><div>{{ $quote->buyer_contact }}</div>
                @endif
                @if ((string) ($quote->buyer_email ?? '') !== '')
                    <div class="label">{{ $label('email', 'Email') }}:</div><div>{{ $quote->buyer_email }}</div>
                @endif
                @if ((string) ($quote->buyer_phone ?? '') !== '')
                    <div class="label">{{ $label('phone', 'Phone') }}:</div><div>{{ $quote->buyer_phone }}</div>
                @endif
                @if ((string) ($quote->buyer_address ?? '') !== '')
                    <div class="label">{{ $label('address', 'Address') }}:</div><div>{{ $quote->buyer_address }}</div>
                @endif
                @if ((string) ($quote->buyer_country ?? '') !== '')
                    <div class="label">{{ $label('country', 'Country') }}:</div><div>{{ $quote->buyer_country }}</div>
                @endif
            </div>
        </div>
        <div class="panel">
            <div class="panel-title">{{ $label('commercial_info', 'Commercial Info') }}</div>
            <div class="kv-wide">
                @if ((string) ($quote->trade_term ?? '') !== '')
                    <div class="label">{{ $label('trade_term', 'Trade Term') }}:</div><div>{{ $quote->trade_term }}</div>
                @endif
                @if ((string) ($quote->lead_time ?? '') !== '' && $documentKind !== 'invoice' && $documentKind !== 'packing_list')
                    <div class="label">{{ $label('lead_time', 'Lead Time') }}:</div><div>{{ $quote->lead_time }}</div>
                @endif
                @if ((string) ($quote->origin_country ?? '') !== '')
                    <div class="label">{{ $label('origin', 'Origin') }}:</div><div>{{ $quote->origin_country }}</div>
                @endif
                @if ($documentKind !== 'invoice' && $documentKind !== 'packing_list' && (string) ($quote->valid_until ?? '') !== '')
                    <div class="label">{{ $label('validity', 'Validity') }}:</div><div>{{ $formatDate($quote->valid_until) }}</div>
                @endif
            </div>
        </div>
    </div>
@endif
