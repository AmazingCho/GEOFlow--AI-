<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\CrmCustomer;
use App\Models\CrmQuote;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CrmContractPrintPaginationTest extends TestCase
{
    use RefreshDatabase;

    public function test_contract_print_uses_dedicated_semantic_pagination_flow(): void
    {
        $admin = Admin::query()->create([
            'username' => 'contract_pagination_admin',
            'password' => 'secret-123',
            'email' => 'contract-pagination@example.com',
            'display_name' => 'Contract Pagination Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);
        $customer = CrmCustomer::query()->create([
            'company_name' => 'Contract Pagination Buyer',
            'contact_person' => 'Buyer',
            'status' => 'active',
        ]);
        $quote = CrmQuote::query()->create([
            'customer_id' => (int) $customer->id,
            'quote_no' => 'CONTRACT-PAGINATION-001',
            'title' => 'Long contract pagination',
            'document_type' => 'contract',
            'document_language' => 'en',
            'buyer_company' => 'Contract Pagination Buyer',
            'currency' => 'USD',
            'status' => 'draft',
            'payment_terms' => '60% deposit, 40% balance before shipment.',
            'warranty_terms' => '12 months warranty for machine main parts.',
            'contract_terms' => implode("\n", [
                "1. Buyer's Bank Details:",
                'Company Name: Contract Pagination Buyer',
                'IBAN Code: UA123456789',
                'Correspondent banks',
                'Bank Name: Example Correspondent Bank',
                'Account Number: 001-1-000080',
                'SWIFT Code: EXAMPLE33',
                '',
                "2. Seller's Bank Details:",
                'Bank Name: Example Seller Bank',
                'Beneficiary Number: 17870060620465381001',
                'SWIFT/BIC: BKCHCNBJ45A',
                '',
                '3. Delivery',
                'Delivery Time: 7-10 working days after receipt of deposit.',
                '',
                '4. Warranty',
                str_repeat('Warranty obligations remain subject to the agreed operating conditions. ', 20),
            ]),
            'total_amount' => 5500,
            'grand_total' => 5500,
        ]);
        $quote->items()->create([
            'item_name' => 'Epoxy Resin',
            'model' => '308AB-42',
            'quantity' => 1000,
            'unit' => 'kg',
            'unit_price' => 5.5,
            'amount' => 5500,
        ]);

        $response = $this->actingAs($admin, 'admin')
            ->get(route('admin.crm.quotes.print', [
                'quoteId' => (int) $quote->id,
                'type' => 'contract',
                'language' => 'en',
            ]))
            ->assertOk()
            ->assertSee('data-contract-inline-slot', false)
            ->assertSee('data-contract-page-template', false)
            ->assertSee('data-contract-block', false)
            ->assertSee('data-contract-page-body', false)
            ->assertSee('window.GeoFlowCrmDocumentAutoPaginate', false)
            ->assertSee('data-contract-pagination-status', false)
            ->assertSee('Contract terms continued')
            ->assertSee('Page 1 of 2')
            ->assertSee('Page 2 of 2');

        $html = (string) $response->getContent();
        $this->assertSame(1, substr_count($html, '<h1>Contract</h1>'));
        $this->assertStringContainsString('body.is-contract-document .page', $html);
        $this->assertStringContainsString('paginateContractContent', $html);
        $this->assertStringContainsString('detectPageOverflow', $html);
        $this->assertLessThan(
            strpos($html, 'data-contract-page-template'),
            strpos($html, 'CONTRACT-PAGINATION-001')
        );
    }
}
