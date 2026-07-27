@php
    $contractPrintBlocks = [];
    $appendContractHeading = static function (string $text) use (&$contractPrintBlocks): void {
        $contractPrintBlocks[] = [
            'kind' => 'section_title',
            'text' => $text,
            'keep_with_next' => 2,
        ];
    };
    $appendContractContent = static function (string $text) use (&$contractPrintBlocks): void {
        foreach (\App\Support\GeoFlow\CrmContractContent::blocks($text) as $block) {
            $contractPrintBlocks[] = $block;
        }
    };

    $appendContractHeading($label('contract_terms', 'Contract Terms'));
    $appendContractContent((string) ($quote->contract_terms ?: '-'));

    if (trim((string) ($quote->governing_law ?? '')) !== '') {
        $appendContractHeading($label('governing_law', 'Governing Law'));
        $appendContractContent((string) $quote->governing_law);
    }

    if (trim((string) ($quote->dispute_resolution ?? '')) !== '') {
        $appendContractHeading($label('dispute_resolution', 'Dispute Resolution'));
        $appendContractContent((string) $quote->dispute_resolution);
    }

    $contractPrintBlocks[] = [
        'kind' => 'notice',
        'text' => $label('contract_review_notice', 'This contract template is a commercial draft and should be reviewed before sending.'),
        'keep_with_next' => 1,
    ];
@endphp

@foreach ($contractPrintBlocks as $contractBlockIndex => $contractBlock)
    @if ($contractBlock['kind'] === 'section_title')
        <div
            class="contract-term-block contract-term-section-title"
            data-contract-block
            data-contract-order="{{ $contractBlockIndex }}"
            data-keep-with-next="{{ $contractBlock['keep_with_next'] }}"
        >
            <h2>{{ $contractBlock['text'] }}</h2>
        </div>
    @else
        <div
            class="contract-term-block contract-term-{{ $contractBlock['kind'] }}"
            data-contract-block
            data-contract-order="{{ $contractBlockIndex }}"
            data-keep-with-next="{{ $contractBlock['keep_with_next'] }}"
        >{{ $contractBlock['text'] }}</div>
    @endif
@endforeach

<div
    class="contract-term-block contract-signature-block"
    data-contract-block
    data-contract-order="{{ count($contractPrintBlocks) }}"
    data-keep-with-next="0"
>
    @include('admin.crm.quotes.partials.print-signature', [
        'quote' => $quote,
        'label' => $label,
        'documentKind' => $documentKind,
    ])
</div>
