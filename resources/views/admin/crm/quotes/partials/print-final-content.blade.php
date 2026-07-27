@if ($isPacking)
    @include('admin.crm.quotes.partials.print-packing-summary', ['totalPackages' => $totalPackages, 'totalNetWeight' => $totalNetWeight, 'totalGrossWeight' => $totalGrossWeight, 'totalVolume' => $totalVolume])

    <h2>{{ $label('notes', 'Notes') }}</h2>
    <div class="notes-box">{{ $label('packaging_note', 'Package type: Export-grade wooden case. All dimensions and weights are for customs clearance and logistics reference.') }}</div>

    @include('admin.crm.quotes.partials.print-signature', ['quote' => $quote, 'label' => $label, 'documentKind' => $documentKind])
@else
    @if ($showPrices)
        @include('admin.crm.quotes.partials.print-summary', ['quote' => $quote, 'label' => $label, 'money' => $money, 'showTax' => $isInvoice])
    @endif

    @if ($isInvoice)
        <h2>{{ $label('declaration', 'Declaration') }}</h2>
        <div class="declaration">
            {{ $label('declaration_text', 'The above information is true and correct. Goods are of Chinese origin unless otherwise stated.') }}
        </div>
    @endif

    @if (!$isInvoice)
        @include('admin.crm.quotes.partials.print-terms', ['quote' => $quote, 'label' => $label, 'documentKind' => $documentKind])

        @if ($showBank)
            @if (!empty(array_filter($bank)))
                <h2>{{ $label('bank_account', 'Bank Account') }}</h2>
                <div class="bank-block">
                    <div class="bank-grid">
                        @if (!empty($bank['beneficiary']))
                            <div class="bank-item wide"><div class="label">{{ $label('beneficiary', 'Beneficiary') }}:</div><div>{{ $bank['beneficiary'] }}</div></div>
                        @endif
                        @if (!empty($bank['bank_name']))
                            <div class="bank-item"><div class="label">{{ $label('bank_name', 'Bank Name') }}:</div><div>{{ $bank['bank_name'] }}</div></div>
                        @endif
                        @if (!empty($bank['account_no']))
                            <div class="bank-item"><div class="label">{{ $label('account_no', 'Account No.') }}:</div><div>{{ $bank['account_no'] }}</div></div>
                        @endif
                        @if (!empty($bank['bank_code']))
                            <div class="bank-item"><div class="label">{{ $label('bank_code', 'Bank Code') }}:</div><div>{{ $bank['bank_code'] }}</div></div>
                        @endif
                        @if (!empty($bank['branch_code']))
                            <div class="bank-item"><div class="label">{{ $label('branch_code', 'Branch Code') }}:</div><div>{{ $bank['branch_code'] }}</div></div>
                        @endif
                        @if (!empty($bank['swift']))
                            <div class="bank-item"><div class="label">{{ $label('swift', 'SWIFT') }}:</div><div>{{ $bank['swift'] }}</div></div>
                        @endif
                        @if (!empty($bank['currency']))
                            <div class="bank-item"><div class="label">{{ $label('currency', 'Currency') }}:</div><div>{{ $bank['currency'] }}</div></div>
                        @endif
                        @if (!empty($bank['bank_address']))
                            <div class="bank-item wide"><div class="label">{{ $label('bank_address', 'Bank Address') }}:</div><div>{{ $bank['bank_address'] }}</div></div>
                        @endif
                    </div>
                </div>
            @endif
        @endif
    @endif

    @if (!$isInvoice && !$showContract && !$showPrices && (string) ($quote->notes ?? '') !== '')
        <h2>{{ $label('notes', 'Notes') }}</h2>
        <div class="section">{{ $quote->notes }}</div>
    @endif

    @if ($showContract)
        <div class="contract-inline-slot" data-contract-inline-slot></div>
    @else
        @include('admin.crm.quotes.partials.print-signature', ['quote' => $quote, 'label' => $label, 'documentKind' => $documentKind])
    @endif
@endif
