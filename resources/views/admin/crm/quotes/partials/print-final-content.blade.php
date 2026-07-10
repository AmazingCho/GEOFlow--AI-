@if ($isPacking)
    @include('admin.crm.quotes.partials.print-packing-summary', ['totalPackages' => $totalPackages, 'totalNetWeight' => $totalNetWeight, 'totalGrossWeight' => $totalGrossWeight, 'totalVolume' => $totalVolume])

    <h2>{{ $label('notes', 'Notes') }}</h2>
    <div class="notes-box">{{ $isZh ? '包装类型：出口级木箱。所有尺寸和重量仅供海关清关和物流参考。' : 'Package type: Export-grade wooden case. All dimensions and weights are for customs clearance and logistics reference.' }}</div>

    @include('admin.crm.quotes.partials.print-signature', ['quote' => $quote, 'label' => $label, 'isZh' => $isZh, 'documentKind' => $documentKind])
@else
    @if ($showPrices)
        @include('admin.crm.quotes.partials.print-summary', ['quote' => $quote, 'label' => $label, 'money' => $money, 'showTax' => $isInvoice])
    @endif

    @if ($isInvoice)
        <h2>Declaration</h2>
        <div class="declaration">
            The above information is true and correct. Goods are of Chinese origin unless otherwise stated.
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
                            <div class="bank-item wide"><div class="label">Beneficiary:</div><div>{{ $bank['beneficiary'] }}</div></div>
                        @endif
                        @if (!empty($bank['bank_name']))
                            <div class="bank-item"><div class="label">Bank Name:</div><div>{{ $bank['bank_name'] }}</div></div>
                        @endif
                        @if (!empty($bank['account_no']))
                            <div class="bank-item"><div class="label">Account No.:</div><div>{{ $bank['account_no'] }}</div></div>
                        @endif
                        @if (!empty($bank['bank_code']))
                            <div class="bank-item"><div class="label">Bank Code:</div><div>{{ $bank['bank_code'] }}</div></div>
                        @endif
                        @if (!empty($bank['branch_code']))
                            <div class="bank-item"><div class="label">Branch Code:</div><div>{{ $bank['branch_code'] }}</div></div>
                        @endif
                        @if (!empty($bank['swift']))
                            <div class="bank-item"><div class="label">SWIFT:</div><div>{{ $bank['swift'] }}</div></div>
                        @endif
                        @if (!empty($bank['currency']))
                            <div class="bank-item"><div class="label">Currency:</div><div>{{ $bank['currency'] }}</div></div>
                        @endif
                        @if (!empty($bank['bank_address']))
                            <div class="bank-item wide"><div class="label">Bank Address:</div><div>{{ $bank['bank_address'] }}</div></div>
                        @endif
                    </div>
                </div>
            @endif
        @endif
    @endif

    @if ($showContract)
        @include('admin.crm.quotes.partials.print-contract-terms', ['quote' => $quote, 'label' => $label, 'isZh' => $isZh])
    @endif

    @if (!$isInvoice && !$showContract && !$showPrices && (string) ($quote->notes ?? '') !== '')
        <h2>{{ $label('notes', 'Notes') }}</h2>
        <div class="section">{{ $quote->notes }}</div>
    @endif

    @include('admin.crm.quotes.partials.print-signature', ['quote' => $quote, 'label' => $label, 'isZh' => $isZh, 'documentKind' => $documentKind])
@endif
