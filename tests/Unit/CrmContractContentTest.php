<?php

namespace Tests\Unit;

use App\Support\GeoFlow\CrmContractContent;
use PHPUnit\Framework\TestCase;

class CrmContractContentTest extends TestCase
{
    public function test_contract_terms_are_split_into_semantic_pagination_blocks(): void
    {
        $blocks = CrmContractContent::blocks(<<<'TEXT'
1. Buyer's Bank Details:
Company Name: Example Buyer
IBAN Code: UA123456
Correspondent banks
Bank Name: Example Correspondent Bank
SWIFT Code: EXAMPLE33

2. Delivery
Delivery Time: 7-10 working days after receipt of deposit.
TEXT);

        $this->assertSame([
            'heading',
            'line',
            'line',
            'subheading',
            'line',
            'line',
            'heading',
            'line',
        ], array_column($blocks, 'kind'));
        $this->assertSame(2, $blocks[0]['keep_with_next']);
        $this->assertSame(1, $blocks[3]['keep_with_next']);
        $this->assertSame('2. Delivery', $blocks[6]['text']);
    }

    public function test_a_single_oversized_paragraph_is_split_into_safe_blocks(): void
    {
        $blocks = CrmContractContent::blocks(str_repeat(
            'The buyer and seller agree that each delivery milestone must be reviewed before shipment. ',
            35
        ));

        $this->assertGreaterThan(1, count($blocks));
        foreach ($blocks as $block) {
            $this->assertLessThanOrEqual(900, mb_strlen($block['text']));
        }
    }

    public function test_a_correspondent_bank_record_is_kept_as_one_pagination_unit(): void
    {
        $blocks = CrmContractContent::blocks(<<<'TEXT'
Correspondent bank
JP Morgan Chase Bank, New York, USA
Account in the correspondent bank
890-0085-754
SWIFT Code of the correspondent bank
IRVT US 3N
2. Seller's Bank Details:
TEXT);

        $this->assertSame('subheading', $blocks[0]['kind']);
        $this->assertSame(5, $blocks[0]['keep_with_next']);
        $this->assertSame('heading', $blocks[6]['kind']);
    }
}
