<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\AiModel;
use App\Models\CaseRecord;
use App\Models\CollectionRecord;
use App\Models\CrmAfterSalesTicket;
use App\Models\CrmContentProposal;
use App\Models\CrmCustomer;
use App\Models\CrmFollowUp;
use App\Models\CrmInquiry;
use App\Models\CrmCustomerContact;
use App\Models\CrmDocumentPdfRegressionBaseline;
use App\Models\CrmDocumentPdfRegressionRun;
use App\Models\CrmOpportunity;
use App\Models\CrmQuote;
use App\Models\CrmSalesOrder;
use App\Models\CrmSellerProfile;
use App\Models\CrmTask;
use App\Models\EntityRecord;
use App\Models\KnowledgeBase;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeCorrection;
use App\Models\Prompt;
use App\Models\Task;
use App\Models\Title;
use App\Models\TitleLibrary;
use App\Jobs\GenerateCrmDocumentPdfRegressionRun;
use App\Services\GeoFlow\CrmDocumentPdfRegressionCleanupService;
use App\Services\GeoFlow\CrmDocumentPdfVisualDiffService;
use App\Services\GeoFlow\CrmDocumentPdfService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdminCrmPagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    public function test_guest_is_redirected_from_crm_pages(): void
    {
        $this->get(route('admin.crm.customers.index'))->assertRedirect(route('admin.login'));
        $this->get(route('admin.crm.inquiries.index'))->assertRedirect(route('admin.login'));
        $this->get(route('admin.crm.quotes.index'))->assertRedirect(route('admin.login'));
    }

    public function test_admin_can_create_customer_and_follow_up(): void
    {
        $admin = $this->admin();
        $collection = $this->collection('Automation CRM');

        $this->actingAs($admin, 'admin')
            ->get(route('admin.crm.customers.index'))
            ->assertOk()
            ->assertSee('CRM 客户管理')
            ->assertSee(__('admin.nav.crm'));

        $this->actingAs($admin, 'admin')
            ->post(route('admin.crm.customers.store'), [
                'collection_id' => (int) $collection->id,
                'company_name' => 'Acme Automation Ltd',
                'contact_person' => 'John Smith', 
                'customer_type' => 'Integrator',
                'country' => 'US',
                'region' => 'CA',
                'website' => 'https://acme.example',
                'industry' => 'Battery Manufacturing',
                'source_channel' => 'Website',
                'phone' => '+61 400',
                'tax_number' => 'ABN-51824753556',
                'contact_title' => 'Purchasing Manager',
                'owner' => 'CRM Admin',
                'status' => 'active',
                'notes' => 'Important customer.',
            ])
            ->assertRedirect();

        $customer = CrmCustomer::query()->where('company_name', 'Acme Automation Ltd')->firstOrFail();
        $this->assertSame((int) $collection->id, (int) $customer->collection_id);
        $this->assertDatabaseHas('crm_customers', [
            'id' => (int) $customer->id,
            'phone' => '+61 400',
            'tax_number' => 'ABN-51824753556',
            'contact_title' => 'Purchasing Manager',
            'owner' => 'CRM Admin',
        ]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.crm.customers.follow-ups.store', ['customerId' => (int) $customer->id]), [
                'activity_type' => 'call',
                'content' => 'Confirmed application requirements.',
                'next_action' => 'Prepare quotation.',
                'owner' => 'CRM Admin',
                'status' => 'open',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('crm_follow_ups', [
            'customer_id' => (int) $customer->id,
            'activity_type' => 'call',
            'content' => 'Confirmed application requirements.',
            'owner' => 'CRM Admin',
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.crm.customers.show', ['customerId' => (int) $customer->id]))
            ->assertOk()
            ->assertSee('Acme Automation Ltd')
            ->assertSee('Purchasing Manager')
            ->assertSee('+61 400')
            ->assertSee('ABN-51824753556')
            ->assertSee('CRM Admin')
            ->assertSee('电话沟通')
            ->assertSee('Confirmed application requirements.');
    }

    public function test_customer_detail_shows_full_crm_chain_overview(): void
    {
        $admin = $this->admin('crm_customer_overview_admin');
        $collection = $this->collection('Customer Overview CRM');
        $customer = CrmCustomer::query()->create([
            'collection_id' => (int) $collection->id,
            'company_name' => 'Overview Buyer',
            'contact_person' => 'Graham',
            'status' => 'active',
            'owner' => 'CRM Admin',
        ]);
        $inquiry = CrmInquiry::query()->create([
            'collection_id' => (int) $collection->id,
            'customer_id' => (int) $customer->id,
            'subject' => 'Need machine quotation',
            'status' => 'converted',
            'priority' => 'high',
        ]);
        $opportunity = CrmOpportunity::query()->create([
            'collection_id' => (int) $collection->id,
            'customer_id' => (int) $customer->id,
            'source_inquiry_id' => (int) $inquiry->id,
            'name' => 'Machine opportunity',
            'stage' => 'proposal',
            'amount' => 12000,
            'currency' => 'USD',
            'probability' => 60,
        ]);
        $quote = CrmQuote::query()->create([
            'collection_id' => (int) $collection->id,
            'customer_id' => (int) $customer->id,
            'inquiry_id' => (int) $inquiry->id,
            'opportunity_id' => (int) $opportunity->id,
            'quote_no' => 'Q-CUSTOMER-001',
            'title' => 'Customer quotation',
            'document_type' => 'quotation',
            'currency' => 'USD',
            'grand_total' => 12500,
            'status' => 'sent',
        ]);
        $order = CrmSalesOrder::query()->create([
            'collection_id' => (int) $collection->id,
            'customer_id' => (int) $customer->id,
            'inquiry_id' => (int) $inquiry->id,
            'quote_id' => (int) $quote->id,
            'order_no' => 'SO-CUSTOMER-001',
            'title' => 'Customer order',
            'currency' => 'USD',
            'total_amount' => 12500,
            'order_status' => 'production',
        ]);
        CrmAfterSalesTicket::query()->create([
            'collection_id' => (int) $collection->id,
            'customer_id' => (int) $customer->id,
            'order_id' => (int) $order->id,
            'title' => 'Install issue',
            'issue_description' => 'Need installation support.',
            'priority' => 'high',
            'status' => 'open',
        ]);
        CrmTask::query()->create([
            'customer_id' => (int) $customer->id,
            'inquiry_id' => (int) $inquiry->id,
            'opportunity_id' => (int) $opportunity->id,
            'title' => 'Send follow-up pack',
            'status' => 'open',
            'priority' => 'normal',
        ]);
        $customer->followUps()->create([
            'inquiry_id' => (int) $inquiry->id,
            'opportunity_id' => (int) $opportunity->id,
            'activity_type' => 'meeting',
            'content' => 'Negotiation note for overview.',
            'followup_type' => '会议',
            'owner' => 'CRM Admin',
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.crm.customers.show', ['customerId' => (int) $customer->id]))
            ->assertOk()
            ->assertSee('销售链条')
            ->assertSee('Machine opportunity')
            ->assertSee('Need machine quotation')
            ->assertSee('Q-CUSTOMER-001')
            ->assertSee('SO-CUSTOMER-001')
            ->assertSee('Install issue')
            ->assertSee('Send follow-up pack')
            ->assertSee('Negotiation note for overview.');
    }

    public function test_document_chain_is_visible_from_inquiry_opportunity_and_customer(): void
    {
        $admin = $this->admin('crm_document_chain_admin');
        $collection = $this->collection('Document Chain CRM');
        $customer = CrmCustomer::query()->create([
            'collection_id' => (int) $collection->id,
            'company_name' => 'Chain Buyer',
            'contact_person' => 'Graham',
            'status' => 'active',
        ]);
        $inquiry = CrmInquiry::query()->create([
            'collection_id' => (int) $collection->id,
            'customer_id' => (int) $customer->id,
            'subject' => 'Chain source inquiry',
            'status' => 'converted',
            'priority' => 'high',
        ]);
        $opportunity = CrmOpportunity::query()->create([
            'collection_id' => (int) $collection->id,
            'customer_id' => (int) $customer->id,
            'source_inquiry_id' => (int) $inquiry->id,
            'name' => 'Chain opportunity',
            'stage' => 'proposal',
            'amount' => 15500,
            'currency' => 'USD',
            'probability' => 70,
        ]);
        $quote = CrmQuote::query()->create([
            'collection_id' => (int) $collection->id,
            'customer_id' => (int) $customer->id,
            'inquiry_id' => (int) $inquiry->id,
            'opportunity_id' => (int) $opportunity->id,
            'quote_no' => 'Q-CHAIN-001',
            'title' => 'Chain quotation',
            'document_type' => 'quotation',
            'currency' => 'USD',
            'grand_total' => 15500,
            'status' => 'sent',
        ]);
        $order = CrmSalesOrder::query()->create([
            'collection_id' => (int) $collection->id,
            'customer_id' => (int) $customer->id,
            'inquiry_id' => (int) $inquiry->id,
            'quote_id' => (int) $quote->id,
            'order_no' => 'SO-CHAIN-001',
            'title' => 'Chain order',
            'currency' => 'USD',
            'total_amount' => 15500,
            'order_status' => 'production',
        ]);
        $ticket = CrmAfterSalesTicket::query()->create([
            'collection_id' => (int) $collection->id,
            'customer_id' => (int) $customer->id,
            'order_id' => (int) $order->id,
            'title' => 'Chain after-sales issue',
            'issue_description' => 'Need installation support.',
            'priority' => 'normal',
            'status' => 'open',
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.crm.inquiries.show', ['inquiryId' => (int) $inquiry->id]))
            ->assertOk()
            ->assertSee('单据链路')
            ->assertSee('Chain opportunity')
            ->assertSee('Q-CHAIN-001')
            ->assertSee('SO-CHAIN-001')
            ->assertSee('Chain after-sales issue')
            ->assertSee(route('admin.crm.quotes.show', ['quoteId' => (int) $quote->id]), false)
            ->assertSee(route('admin.crm.orders.show', ['orderId' => (int) $order->id]), false)
            ->assertSee(route('admin.crm.tickets.show', ['ticketId' => (int) $ticket->id]), false);

        $opportunityResponse = $this->actingAs($admin, 'admin')
            ->get(route('admin.crm.opportunities.edit', ['opportunityId' => (int) $opportunity->id]))
            ->assertOk()
            ->assertSee('单据链路')
            ->assertSee('Chain source inquiry')
            ->assertSee('Q-CHAIN-001')
            ->assertSee('SO-CHAIN-001')
            ->assertSee('Chain after-sales issue');
        $opportunityContent = $opportunityResponse->getContent();
        $chainPosition = strpos($opportunityContent, '单据链路');
        $asidePosition = strpos($opportunityContent, '<aside class="space-y-6">');
        $this->assertNotFalse($chainPosition);
        $this->assertNotFalse($asidePosition);
        $this->assertLessThan($asidePosition, $chainPosition);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.crm.customers.show', ['customerId' => (int) $customer->id]))
            ->assertOk()
            ->assertSee('客户单据链路')
            ->assertSee('Chain source inquiry')
            ->assertSee('Chain opportunity')
            ->assertSee('Q-CHAIN-001')
            ->assertSee('SO-CHAIN-001')
            ->assertSee('Chain after-sales issue');
    }

    public function test_inquiry_links_are_collection_limited_and_analysis_recommends_existing_materials(): void
    {
        $admin = $this->admin('crm_inquiry_admin');
        $collection = $this->collection('Cooling CRM');
        $otherCollection = $this->collection('Lighting CRM');
        $customer = CrmCustomer::query()->create([
            'collection_id' => (int) $collection->id,
            'company_name' => 'Cooling Buyer',
                'contact_person' => 'John Smith', 
            'status' => 'active',
        ]);

        $entity = EntityRecord::query()->create([
            'collection_id' => (int) $collection->id,
            'name' => 'SJ4060',
            'entity_type' => '产品型号',
            'aliases' => 'SJ-4060',
            'description' => 'Industrial cooling product.',
        ]);
        $otherEntity = EntityRecord::query()->create([
            'collection_id' => (int) $otherCollection->id,
            'name' => 'LIGHT999',
            'entity_type' => '产品型号',
        ]);
        $knowledgeBase = KnowledgeBase::query()->create([
            'collection_id' => (int) $collection->id,
            'name' => 'SJ4060 Manual',
            'description' => 'SJ4060 installation and sizing manual.',
            'summary' => 'SJ4060 technical manual.',
            'content' => 'SJ4060 supports industrial cooling applications.',
            'file_type' => 'markdown',
        ]);
        $otherKnowledgeBase = KnowledgeBase::query()->create([
            'collection_id' => (int) $otherCollection->id,
            'name' => 'LIGHT999 Manual',
            'content' => 'Lighting content.',
            'file_type' => 'markdown',
        ]);
        $caseRecord = CaseRecord::query()->create([
            'collection_id' => (int) $collection->id,
            'entity_id' => (int) $entity->id,
            'title' => 'SJ4060 Battery Plant Case',
            'case_type' => 'application_scenario',
            'summary' => 'Battery plant case for SJ4060.',
        ]);
        $otherCaseRecord = CaseRecord::query()->create([
            'collection_id' => (int) $otherCollection->id,
            'title' => 'LIGHT999 Showroom Case',
            'case_type' => 'application_scenario',
        ]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.crm.inquiries.store'), [
                'collection_id' => (int) $collection->id,
                'customer_id' => (int) $customer->id,
                'subject' => 'SJ4060 quotation request',
                'raw_message' => 'Need SJ4060 quotation for a battery plant.',
                'status' => 'new',
                'priority' => 'high',
                'assigned_to' => 'CRM Admin',
                'entity_ids' => [(int) $entity->id, (int) $otherEntity->id],
                'knowledge_base_ids' => [(int) $knowledgeBase->id, (int) $otherKnowledgeBase->id],
                'case_record_ids' => [(int) $caseRecord->id, (int) $otherCaseRecord->id],
            ])
            ->assertRedirect();

        $inquiry = CrmInquiry::query()->where('subject', 'SJ4060 quotation request')->firstOrFail();
        $this->assertSame('CRM Admin', (string) $inquiry->assigned_to);
        $this->assertSame([(int) $entity->id], $inquiry->entities()->pluck('entities.id')->map(fn ($id): int => (int) $id)->all());
        $this->assertSame([(int) $knowledgeBase->id], $inquiry->knowledgeBases()->pluck('knowledge_bases.id')->map(fn ($id): int => (int) $id)->all());
        $this->assertSame([(int) $caseRecord->id], $inquiry->cases()->pluck('case_records.id')->map(fn ($id): int => (int) $id)->all());

        $this->actingAs($admin, 'admin')
            ->postJson(route('admin.crm.inquiries.analyze'), [
                'collection_id' => (int) $collection->id,
                'content' => 'Urgent: Need SJ4060 quotation for battery plant. Please use SJ4060 Manual and SJ4060 Battery Plant Case.',
                'ai_model_id' => 0,
            ])
            ->assertOk()
            ->assertJsonPath('fields.entity_ids.0', (int) $entity->id)
            ->assertJsonPath('fields.knowledge_base_ids.0', (int) $knowledgeBase->id)
            ->assertJsonPath('fields.case_record_ids.0', (int) $caseRecord->id)
            ->assertJsonPath('fields.urgency_level', 'high');
    }

    public function test_admin_can_create_quote_from_inquiry_and_open_print_page(): void
    {
        $admin = $this->admin('crm_quote_admin');
        $collection = $this->collection('Quote CRM');
        $customer = CrmCustomer::query()->create([
            'collection_id' => (int) $collection->id,
            'company_name' => 'Quote Buyer',
                'contact_person' => 'John Smith', 
            'tax_number' => 'TAX-QB-001',
            'status' => 'active',
        ]);
        $entity = EntityRecord::query()->create([
            'collection_id' => (int) $collection->id,
            'name' => 'SJ4060',
            'entity_type' => '产品型号',
        ]);
        $inquiry = CrmInquiry::query()->create([
            'collection_id' => (int) $collection->id,
            'customer_id' => (int) $customer->id,
            'subject' => 'Need quotation',
            'status' => 'qualified',
            'priority' => 'normal',
        ]);
        $inquiry->entities()->attach((int) $entity->id);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.crm.quotes.create', ['inquiry_id' => (int) $inquiry->id]))
            ->assertOk()
            ->assertSee('SJ4060');

        Storage::fake('public');

        $this->actingAs($admin, 'admin')
            ->post(route('admin.crm.quotes.store'), [
                'collection_id' => (int) $collection->id,
                'customer_id' => (int) $customer->id,
                'inquiry_id' => (int) $inquiry->id,
                'title' => 'SJ4060 Quotation',
                'currency' => 'USD',
                'status' => 'draft',
                'items' => [
                    'entity_id' => [(int) $entity->id, ''],
                    'item_name' => ['SJ4060 System', ''],
                    'description' => ['Configured system.', ''],
                    'quantity' => [2, ''],
                    'unit' => ['set', ''],
                    'unit_price' => [1500, ''],
                ],
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('crm_quotes', [
            'title' => 'SJ4060 Quotation',
            'total_amount' => 3000,
            'buyer_tax_number' => 'TAX-QB-001',
        ]);
        $this->assertDatabaseHas('crm_quote_items', [
            'item_name' => 'SJ4060 System',
            'quantity' => 2,
            'unit_price' => 1500,
            'amount' => 3000,
        ]);

        $quote = \App\Models\CrmQuote::query()->where('title', 'SJ4060 Quotation')->firstOrFail();
        $this->actingAs($admin, 'admin')
            ->get(route('admin.crm.quotes.print', ['quoteId' => (int) $quote->id]))
            ->assertOk()
            ->assertSee('Quotation')
            ->assertSee('SJ4060 System')
            ->assertSee('USD 3,000.00');

        $this->actingAs($admin, 'admin')
            ->post(route('admin.crm.quotes.store'), [
                'collection_id' => (int) $collection->id,
                'customer_id' => (int) $customer->id,
                'document_type' => 'invoice',
                'title' => 'SJ4060 Commercial Invoice',
                'currency' => 'USD',
                'buyer_company' => 'Quote Buyer',
                'trade_term' => 'FOB',
                'shipping_fee' => 200,
                'discount_amount' => 50,
                'tax_amount' => 10,
                'status' => 'draft',
                'items' => [
                    'entity_id' => [(int) $entity->id],
                    'line_type' => ['product'],
                    'model' => ['SJ4060'],
                    'hs_code' => ['842489'],
                    'image_upload' => [UploadedFile::fake()->image('sj4060.png', 64, 64)->size(50)],
                    'item_name' => ['SJ4060 Invoice System'],
                    'description' => ['Commercial invoice line.'],
                    'quantity' => [1],
                    'unit' => ['set'],
                    'unit_price' => [1000],
                    'package_count' => [2],
                    'net_weight' => [80],
                    'gross_weight' => [95],
                    'volume_cbm' => [1.2],
                ],
            ])
            ->assertRedirect();

        $invoice = CrmQuote::query()->where('title', 'SJ4060 Commercial Invoice')->firstOrFail();
        $this->assertSame('invoice', (string) $invoice->document_type);
        $this->assertSame('1160.00', (string) $invoice->grand_total);
        $this->assertDatabaseHas('crm_quote_items', [
            'quote_id' => (int) $invoice->id,
            'item_name' => 'SJ4060 Invoice System',
            'line_type' => 'product',
            'package_count' => 2,
        ]);
        $this->assertNotSame('', (string) $invoice->items()->firstOrFail()->image_path);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.crm.quotes.print', ['quoteId' => (int) $invoice->id]))
            ->assertOk()
            ->assertSee('Commercial Invoice')
            ->assertSee('SJ4060 Invoice System')
            ->assertSee('TAX-QB-001');
        $this->assertSame('TAX-QB-001', (string) $invoice->buyer_tax_number);

        foreach ([
            'proforma_invoice' => 'Proforma Invoice',
            'packing_list' => 'Packing List',
            'contract' => 'Contract',
        ] as $documentType => $expectedTitle) {
            $document = CrmQuote::query()->create([
                'collection_id' => (int) $collection->id,
                'customer_id' => (int) $customer->id,
                'quote_no' => 'DOC-'.strtoupper($documentType),
                'document_type' => $documentType,
                'title' => $expectedTitle.' Test',
                'currency' => 'USD',
                'buyer_company' => 'Quote Buyer',
                'buyer_tax_number' => 'DOC-TAX-001',
                'payment_terms' => '30% deposit.',
                'delivery_terms' => 'Ship by sea.',
                'contract_terms' => 'Custom contract terms for this buyer.',
                'bank_account_json' => [
                    'bank_name' => 'Test Bank',
                    'account_no' => '123456',
                    'bank_code' => 'BANK001',
                    'branch_code' => 'BRANCH002',
                    'beneficiary' => 'Test Beneficiary',
                    'swift' => 'TESTCNBJ',
                ],
                'total_amount' => 1000,
                'grand_total' => 1000,
                'status' => 'draft',
            ]);
            $document->items()->create([
                'entity_id' => (int) $entity->id,
                'line_type' => 'product',
                    'model' => 'SJ4060',
                'hs_code' => '842489',
                'item_name' => 'SJ4060 System',
                'quantity' => 1,
                'unit' => 'set',
                'unit_price' => 1000,
                'amount' => 1000,
                'package_count' => 1,
                'net_weight' => 80,
                'gross_weight' => 95,
                'volume_cbm' => 1.2,
                'sort_order' => 1,
            ]);

            $response = $this->actingAs($admin, 'admin')
                ->get(route('admin.crm.quotes.print', ['quoteId' => (int) $document->id]))
                ->assertOk()
                ->assertSee($expectedTitle);

            if ($documentType === 'packing_list') {
                $response->assertSee('Packages')->assertSee('DOC-TAX-001')->assertDontSee('Unit Price');
            }
            if ($documentType === 'contract') {
                $response->assertSee('Custom contract terms for this buyer.');
            }
            if ($documentType === 'proforma_invoice') {
                $response->assertSee('Test Bank')
                    ->assertSee('Bank Code')
                    ->assertSee('BANK001')
                    ->assertSee('Branch Code')
                    ->assertSee('BRANCH002')
                    ->assertSee('DOC-TAX-001');
            }
        }
    }

    public function test_admin_can_save_seller_profiles_and_quote_json_must_be_valid(): void
    {
        $admin = $this->admin('crm_seller_profile_admin');
        $collection = $this->collection('Seller Profile CRM');
        $customer = CrmCustomer::query()->create([
            'collection_id' => (int) $collection->id,
            'company_name' => 'Seller Profile Buyer',
            'status' => 'active',
        ]);

        $this->actingAs($admin, 'admin')
            ->postJson(route('admin.crm.quotes.seller-profiles.store'), [
                'type' => 'seller_company',
                'name' => 'Robota Default Seller',
                'payload' => json_encode([
                    'name' => 'Robota Automation',
                    'address' => 'Shenzhen',
                    'email' => 'sales@example.com',
                ], JSON_THROW_ON_ERROR),
                'set_default' => true,
            ])
            ->assertOk()
            ->assertJsonPath('profile.name', 'Robota Default Seller')
            ->assertJsonPath('profile.is_default', true);

        $this->assertDatabaseHas('crm_seller_profiles', [
            'type' => 'seller_company',
            'name' => 'Robota Default Seller',
            'is_default' => true,
        ]);
        $this->assertSame('Robota Automation', (string) CrmSellerProfile::query()->firstOrFail()->payload['name']);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.crm.quotes.create', ['collection_id' => (int) $collection->id]))
            ->assertOk()
            ->assertSee('Robota Default Seller')
            ->assertSee('Seller Company JSON')
            ->assertSee('bank_code')
            ->assertSee('branch_code');

        $this->actingAs($admin, 'admin')
            ->post(route('admin.crm.quotes.store'), [
                'collection_id' => (int) $collection->id,
                'customer_id' => (int) $customer->id,
                'title' => 'Invalid Seller JSON Quote',
                'seller_company_json' => '{"name":',
                'bank_account_json' => '{"bank_name":"Valid Bank"}',
                'items' => [
                    'item_name' => ['Machine'],
                    'quantity' => [1],
                    'unit_price' => [100],
                ],
            ])
            ->assertSessionHasErrors('seller_company_json');
    }

    public function test_admin_can_convert_quote_to_order_and_create_after_sales_ticket(): void
    {
        $admin = $this->admin('crm_order_admin');
        $collection = $this->collection('Order CRM');
        $customer = CrmCustomer::query()->create([
            'collection_id' => (int) $collection->id,
            'company_name' => 'Order Buyer',
                'contact_person' => 'John Smith', 
            'status' => 'active',
        ]);
        $entity = EntityRecord::query()->create([
            'collection_id' => (int) $collection->id,
            'name' => 'SJ4060',
            'entity_type' => '产品型号',
        ]);
        $quote = CrmQuote::query()->create([
            'collection_id' => (int) $collection->id,
            'customer_id' => (int) $customer->id,
            'quote_no' => 'Q-TEST-001',
            'title' => 'SJ4060 Quote',
            'currency' => 'USD',
            'total_amount' => 1200,
            'status' => 'accepted',
        ]);
        $quote->items()->create([
            'entity_id' => (int) $entity->id,
            'item_name' => 'SJ4060 System',
            'quantity' => 1,
            'unit' => 'set',
            'unit_price' => 1200,
            'amount' => 1200,
            'sort_order' => 1,
        ]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.crm.orders.from-quote', ['quoteId' => (int) $quote->id]))
            ->assertRedirect();

        $order = CrmSalesOrder::query()->where('quote_id', (int) $quote->id)->firstOrFail();
        $this->assertSame('SJ4060 Quote', (string) $order->title);
        $this->assertDatabaseHas('crm_sales_order_items', [
            'order_id' => (int) $order->id,
            'item_name' => 'SJ4060 System',
            'amount' => 1200,
        ]);

        $knowledgeBase = KnowledgeBase::query()->create([
            'collection_id' => (int) $collection->id,
            'name' => 'SJ4060 Troubleshooting',
            'content' => 'Alarm diagnosis content.',
            'file_type' => 'markdown',
        ]);
        $knowledgeChunk = KnowledgeChunk::query()->create([
            'knowledge_base_id' => (int) $knowledgeBase->id,
            'chunk_index' => 0,
            'content' => 'Alarm diagnosis content.',
            'content_hash' => hash('sha256', 'Alarm diagnosis content.'),
            'token_count' => 3,
            'embedding_json' => '[]',
            'embedding_dimensions' => 0,
            'embedding_provider' => '',
        ]);
        $caseRecord = CaseRecord::query()->create([
            'collection_id' => (int) $collection->id,
            'entity_id' => (int) $entity->id,
            'title' => 'SJ4060 Alarm Case',
            'case_type' => 'troubleshooting',
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.crm.tickets.create', ['order_id' => (int) $order->id]))
            ->assertOk()
            ->assertSee('SJ4060')
            ->assertSee('AI 工单分析');

        $this->actingAs($admin, 'admin')
            ->post(route('admin.crm.tickets.store'), [
                'collection_id' => (int) $collection->id,
                'customer_id' => (int) $customer->id,
                'order_id' => (int) $order->id,
                'entity_id' => (int) $entity->id,
                'title' => 'SJ4060 alarm after installation',
                'issue_description' => 'Customer reports alarm after installation.',
                'priority' => 'high',
                'status' => 'open',
                'knowledge_base_ids' => [(int) $knowledgeBase->id],
                'case_record_ids' => [(int) $caseRecord->id],
            ])
            ->assertRedirect();

        $ticket = CrmAfterSalesTicket::query()->where('title', 'SJ4060 alarm after installation')->firstOrFail();
        $this->assertSame([(int) $knowledgeBase->id], $ticket->knowledgeBases()->pluck('knowledge_bases.id')->map(fn ($id): int => (int) $id)->all());
        $this->assertSame([(int) $caseRecord->id], $ticket->cases()->pluck('case_records.id')->map(fn ($id): int => (int) $id)->all());

        $this->actingAs($admin, 'admin')
            ->get(route('admin.crm.tickets.show', ['ticketId' => (int) $ticket->id]))
            ->assertOk()
            ->assertSee('生成 FAQ 草稿')
            ->assertSee('生成 Case 草稿')
            ->assertSee('知识纠错候选')
            ->assertSee('发起知识纠错')
            ->assertSee('SJ4060 Troubleshooting');

        $this->actingAs($admin, 'admin')
            ->post(route('admin.knowledge-corrections.store'), [
                'source_type' => 'knowledge_base',
                'knowledge_base_id' => (int) $knowledgeBase->id,
                'error_description' => '来源售后工单 #'.$ticket->id.'：customer reports a repeated alarm; check if troubleshooting content is incomplete.',
                'ai_model_id' => 0,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('knowledge_corrections', [
            'knowledge_base_id' => (int) $knowledgeBase->id,
            'knowledge_chunk_id' => (int) $knowledgeChunk->id,
            'status' => KnowledgeCorrection::STATUS_PENDING,
        ]);
    }

    public function test_crm_content_proposals_are_applied_only_after_confirmation(): void
    {
        $admin = $this->admin('crm_proposal_admin');
        $collection = $this->collection('Proposal CRM');
        $customer = CrmCustomer::query()->create([
            'collection_id' => (int) $collection->id,
            'company_name' => 'Proposal Buyer',
                'contact_person' => 'John Smith', 
            'status' => 'active',
        ]);
        $inquiry = CrmInquiry::query()->create([
            'collection_id' => (int) $collection->id,
            'customer_id' => (int) $customer->id,
            'subject' => 'Need SJ4060 sizing',
            'raw_message' => 'How to size SJ4060 for battery manufacturing?',
            'status' => 'qualified',
            'priority' => 'normal',
        ]);
        $titleLibrary = TitleLibrary::query()->create([
            'collection_id' => (int) $collection->id,
            'name' => 'CRM Titles',
            'description' => '',
            'title_count' => 0,
            'generation_type' => 'manual',
        ]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.crm.proposals.from-inquiry', ['inquiryId' => (int) $inquiry->id]), [
                'proposal_type' => 'title_suggestion',
            ])
            ->assertRedirect(route('admin.crm.proposals.index', ['proposal_type' => 'title_suggestion']));

        $proposal = CrmContentProposal::query()->where('proposal_type', 'title_suggestion')->firstOrFail();
        $this->assertSame('pending', (string) $proposal->status);
        $this->assertSame(0, Title::query()->count());

        $this->actingAs($admin, 'admin')
            ->post(route('admin.crm.proposals.apply', ['proposalId' => (int) $proposal->id]), [
                'title_library_id' => (int) $titleLibrary->id,
            ])
            ->assertRedirect();

        $proposal->refresh();
        $this->assertSame('applied', (string) $proposal->status);
        $this->assertSame(1, Title::query()->where('library_id', (int) $titleLibrary->id)->count());

        $ticket = CrmAfterSalesTicket::query()->create([
            'collection_id' => (int) $collection->id,
            'customer_id' => (int) $customer->id,
            'title' => 'Installation alarm',
            'issue_description' => 'Alarm on startup.',
            'reply_points' => 'Check wiring and parameters.',
            'resolution' => 'Adjusted sensor wiring.',
            'priority' => 'normal',
            'status' => 'resolved',
        ]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.crm.proposals.from-ticket', ['ticketId' => (int) $ticket->id]), [
                'proposal_type' => 'faq_draft',
            ])
            ->assertRedirect(route('admin.crm.proposals.index', ['proposal_type' => 'faq_draft']));

        $faqProposal = CrmContentProposal::query()->where('proposal_type', 'faq_draft')->firstOrFail();
        $this->actingAs($admin, 'admin')
            ->post(route('admin.crm.proposals.apply', ['proposalId' => (int) $faqProposal->id]))
            ->assertRedirect();

        $this->assertDatabaseHas('knowledge_bases', [
            'collection_id' => (int) $collection->id,
            'knowledge_type' => 'faq',
            'status' => 'active',
        ]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.crm.proposals.from-ticket', ['ticketId' => (int) $ticket->id]), [
                'proposal_type' => 'case_draft',
            ])
            ->assertRedirect(route('admin.crm.proposals.index', ['proposal_type' => 'case_draft']));

        $caseProposal = CrmContentProposal::query()->where('proposal_type', 'case_draft')->firstOrFail();
        $this->assertSame('pending', (string) $caseProposal->status);
        $this->assertSame(0, CaseRecord::query()->where('title', (string) $caseProposal->title)->count());

        $this->actingAs($admin, 'admin')
            ->post(route('admin.crm.proposals.apply', ['proposalId' => (int) $caseProposal->id]))
            ->assertRedirect();

        $caseProposal->refresh();
        $this->assertSame('applied', (string) $caseProposal->status);
        $this->assertDatabaseHas('case_records', [
            'collection_id' => (int) $collection->id,
            'title' => (string) $caseProposal->title,
            'case_type' => 'troubleshooting_case',
        ]);
    }

    public function test_task_create_can_record_crm_source(): void
    {
        $admin = $this->admin('crm_task_admin');
        $collection = $this->collection('Task CRM');
        $customer = CrmCustomer::query()->create([
            'collection_id' => (int) $collection->id,
            'company_name' => 'Task Buyer',
                'contact_person' => 'John Smith', 
            'status' => 'active',
        ]);
        $inquiry = CrmInquiry::query()->create([
            'collection_id' => (int) $collection->id,
            'customer_id' => (int) $customer->id,
            'subject' => 'Task source inquiry',
            'status' => 'qualified',
            'priority' => 'normal',
        ]);
        $titleLibrary = TitleLibrary::query()->create([
            'collection_id' => (int) $collection->id,
            'name' => 'Task Titles',
            'description' => '',
            'title_count' => 1,
            'generation_type' => 'manual',
        ]);
        Title::query()->create([
            'library_id' => (int) $titleLibrary->id,
            'title' => 'How to choose SJ4060',
            'keyword' => 'SJ4060',
        ]);
        $prompt = Prompt::query()->create([
            'name' => 'Content Prompt',
            'type' => 'content',
            'content' => 'Write an article.',
        ]);
        $model = AiModel::query()->create([
            'name' => 'Local Model',
            'model_id' => 'local-model',
            'model_type' => 'chat',
            'status' => 'active',
        ]);
        \App\Models\Category::query()->create([
            'name' => 'Articles',
            'slug' => 'articles',
            'sort_order' => 1,
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.tasks.create'))
            ->assertOk()
            ->assertSee('CRM 来源')
            ->assertSee('Task source inquiry');

        $this->actingAs($admin, 'admin')
            ->post(route('admin.tasks.store'), [
                'task_name' => 'CRM sourced task',
                'collection_id' => (int) $collection->id,
                'title_library_id' => (int) $titleLibrary->id,
                'prompt_id' => (int) $prompt->id,
                'ai_model_id' => (int) $model->id,
                'crm_source_type' => 'inquiry',
                'crm_source_id' => (int) $inquiry->id,
                'status' => 'paused',
                'article_limit' => 3,
                'draft_limit' => 2,
                'publish_interval' => 60,
                'category_mode' => 'smart',
                'model_selection_mode' => 'fixed',
                'publish_scope' => 'local_only',
            ])
            ->assertRedirect(route('admin.tasks.index'));

        $task = Task::query()->where('name', 'CRM sourced task')->firstOrFail();
        $this->assertSame('inquiry', (string) $task->crm_source_type);
        $this->assertSame((int) $inquiry->id, (int) $task->crm_source_id);
    }

    public function test_customer_archive_preserves_commercial_records_and_primary_contact(): void
    {
        $admin = $this->admin('crm_archive_admin');
        $collection = $this->collection('Archive CRM');
        $this->actingAs($admin, 'admin')->post(route('admin.crm.customers.store'), [
            'collection_id'=>$collection->id,'company_name'=>'Archive Buyer','contact_person'=>'Alice','phone'=>'+1 555','email'=>'alice@example.com','status'=>'active',
        ])->assertRedirect();
        $customer = CrmCustomer::query()->where('company_name','Archive Buyer')->firstOrFail();
        $this->assertDatabaseHas('crm_customer_contacts',['customer_id'=>$customer->id,'name'=>'Alice','is_primary'=>1]);
        $quote = CrmQuote::query()->create(['customer_id'=>$customer->id,'quote_no'=>'Q-ARCHIVE','title'=>'Archive quote','document_type'=>'quotation','currency'=>'USD','status'=>'draft']);
        $this->actingAs($admin, 'admin')->post(route('admin.crm.customers.delete',['customerId'=>$customer->id]))->assertRedirect();
        $this->assertSoftDeleted('crm_customers',['id'=>$customer->id]);
        $this->assertDatabaseHas('crm_quotes',['id'=>$quote->id,'customer_id'=>$customer->id,'deleted_at'=>null]);
    }

    public function test_contact_can_be_created_with_only_required_name(): void
    {
        $admin = $this->admin('crm_contact_optional_fields_admin');
        $customer = CrmCustomer::query()->create([
            'company_name' => 'Optional Contact Buyer',
            'contact_person' => 'Primary Buyer',
            'status' => 'active',
        ]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.crm.customers.contacts.store', ['customerId' => $customer->id]), [
                'name' => 'Secondary Buyer',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('crm_customer_contacts', [
            'customer_id' => $customer->id,
            'name' => 'Secondary Buyer',
            'title' => '',
            'department' => '',
            'phone' => '',
            'email' => '',
            'decision_role' => '',
            'status' => 'active',
        ]);
    }

    public function test_activity_can_be_updated_and_soft_deleted(): void
    {
        $admin = $this->admin('crm_activity_edit_admin');
        $customer = CrmCustomer::query()->create([
            'company_name' => 'Activity Edit Buyer',
            'contact_person' => 'Buyer',
            'status' => 'active',
        ]);
        $followUp = $customer->followUps()->create([
            'content' => 'Original activity',
            'activity_type' => 'call',
            'followup_type' => 'Email',
            'owner' => 'Leo',
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.crm.customers.show', ['customerId' => $customer->id]))
            ->assertOk()
            ->assertSee('编辑活动记录')
            ->assertSee('电话沟通')
            ->assertSee(route('admin.crm.follow-ups.update', ['followUpId' => $followUp->id]), false);

        $this->actingAs($admin, 'admin')
            ->put(route('admin.crm.follow-ups.update', ['followUpId' => $followUp->id]), [
                'activity_type' => 'meeting',
                'content' => 'Updated activity',
                'followup_type' => 'Meeting',
                'owner' => 'Sales Admin',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('crm_follow_ups', [
            'id' => $followUp->id,
            'activity_type' => 'meeting',
            'content' => 'Updated activity',
            'followup_type' => 'Meeting',
            'owner' => 'Sales Admin',
        ]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.crm.follow-ups.delete', ['followUpId' => $followUp->id]))
            ->assertRedirect();

        $this->assertSoftDeleted('crm_follow_ups', ['id' => $followUp->id]);
    }

    public function test_inquiry_activity_is_scoped_and_future_work_uses_tasks(): void
    {
        $admin = $this->admin('crm_activity_admin');
        $customer = CrmCustomer::query()->create(['company_name'=>'Activity Buyer','contact_person'=>'A','status'=>'active']);
        $first = CrmInquiry::query()->create(['customer_id'=>$customer->id,'subject'=>'First inquiry','status'=>'new','priority'=>'normal']);
        $second = CrmInquiry::query()->create(['customer_id'=>$customer->id,'subject'=>'Second inquiry','status'=>'new','priority'=>'normal']);
        $first->followUps()->create(['customer_id'=>$customer->id,'content'=>'Only first activity']);
        $second->followUps()->create(['customer_id'=>$customer->id,'content'=>'Only second activity']);
        $this->actingAs($admin,'admin')->get(route('admin.crm.inquiries.show',['inquiryId'=>$first->id]))->assertOk()->assertSee('Only first activity')->assertDontSee('Only second activity')->assertSee('下一步待办');
        $this->actingAs($admin,'admin')->post(route('admin.crm.tasks.store'),['customer_id'=>$customer->id,'inquiry_id'=>$first->id,'title'=>'Send specification','due_at'=>now()->addDay()->format('Y-m-d H:i:s')])->assertRedirect();
        $task = CrmTask::query()->firstOrFail();
        $this->actingAs($admin,'admin')->post(route('admin.crm.tasks.complete',['taskId'=>$task->id]))->assertRedirect();
        $this->assertSame('done',$task->fresh()->status);
    }

    public function test_inquiry_can_become_opportunity_and_lost_reason_is_required(): void
    {
        $admin = $this->admin('crm_opportunity_admin');
        $collection = $this->collection('Pipeline CRM');
        $customer = CrmCustomer::query()->create(['collection_id'=>$collection->id,'company_name'=>'Pipeline Buyer','contact_person'=>'Buyer','status'=>'active']);
        $inquiry = CrmInquiry::query()->create(['collection_id'=>$collection->id,'customer_id'=>$customer->id,'subject'=>'Machine project','status'=>'qualified','priority'=>'high']);
        $this->actingAs($admin,'admin')->get(route('admin.crm.inquiries.show',['inquiryId'=>$inquiry->id]))->assertOk()->assertSee('转为商机');
        $this->actingAs($admin,'admin')->post(route('admin.crm.opportunities.from-inquiry',['inquiryId'=>$inquiry->id]))->assertRedirect();
        $opportunity = CrmOpportunity::query()->firstOrFail();
        $this->assertSame('converted', (string) $inquiry->fresh()->status);
        $this->assertSame((int) $inquiry->id, (int) $opportunity->source_inquiry_id);
        $this->actingAs($admin,'admin')->put(route('admin.crm.opportunities.update',['opportunityId'=>$opportunity->id]),['customer_id'=>$customer->id,'name'=>'Machine project','stage'=>'lost','amount'=>12000,'currency'=>'USD','probability'=>0])->assertSessionHasErrors('lost_reason');
        $this->actingAs($admin,'admin')->get(route('admin.crm.opportunities.index'))->assertOk()->assertSee('Machine project');
        $this->actingAs($admin,'admin')->get(route('admin.crm.quotes.create',['opportunity_id'=>$opportunity->id]))->assertOk()->assertSee('关联商机')->assertSee('Machine project');
        $this->actingAs($admin,'admin')->post(route('admin.crm.quotes.store'),[
            'collection_id'=>$collection->id,
            'customer_id'=>$customer->id,
            'inquiry_id'=>$inquiry->id,
            'opportunity_id'=>$opportunity->id,
            'title'=>'Opportunity quotation',
            'currency'=>'USD',
            'status'=>'draft',
        ])->assertRedirect();
        $this->assertDatabaseHas('crm_quotes',[
            'title'=>'Opportunity quotation',
            'opportunity_id'=>$opportunity->id,
            'inquiry_id'=>$inquiry->id,
        ]);
        $closedInquiry = CrmInquiry::query()->create(['collection_id'=>$collection->id,'customer_id'=>$customer->id,'subject'=>'Closed inquiry','status'=>'closed','priority'=>'normal']);
        $this->actingAs($admin,'admin')->post(route('admin.crm.opportunities.store'),[
            'collection_id'=>$collection->id,
            'customer_id'=>$customer->id,
            'source_inquiry_id'=>$closedInquiry->id,
            'name'=>'Closed source opportunity',
            'stage'=>'qualified',
            'amount'=>0,
            'currency'=>'USD',
            'probability'=>10,
        ])->assertRedirect();
        $this->assertSame('closed', (string) $closedInquiry->fresh()->status);
    }

    public function test_opportunity_kanban_shows_stage_cards_and_next_task(): void
    {
        $admin = $this->admin('crm_opportunity_kanban_admin');
        $collection = $this->collection('Kanban CRM');
        $customer = CrmCustomer::query()->create([
            'collection_id' => (int) $collection->id,
            'company_name' => 'Kanban Buyer',
            'contact_person' => 'Buyer',
            'status' => 'active',
        ]);
        $inquiry = CrmInquiry::query()->create([
            'collection_id' => (int) $collection->id,
            'customer_id' => (int) $customer->id,
            'subject' => 'Kanban source inquiry',
            'status' => 'converted',
            'priority' => 'high',
        ]);
        $opportunity = CrmOpportunity::query()->create([
            'collection_id' => (int) $collection->id,
            'customer_id' => (int) $customer->id,
            'source_inquiry_id' => (int) $inquiry->id,
            'name' => 'Kanban opportunity',
            'stage' => 'proposal',
            'amount' => 9800,
            'currency' => 'USD',
            'probability' => 55,
            'expected_close_date' => now()->addDays(12)->toDateString(),
        ]);
        CrmTask::query()->create([
            'customer_id' => (int) $customer->id,
            'inquiry_id' => (int) $inquiry->id,
            'opportunity_id' => (int) $opportunity->id,
            'title' => 'Send PI for approval',
            'priority' => 'high',
            'status' => 'open',
            'due_at' => now()->addDay(),
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.crm.opportunities.kanban', ['collection_id' => (int) $collection->id]))
            ->assertOk()
            ->assertSee('商机看板')
            ->assertSee('报价方案')
            ->assertSee('Kanban opportunity')
            ->assertSee('Kanban Buyer')
            ->assertSee('USD 9,800')
            ->assertSee('55%')
            ->assertSee('Kanban source inquiry')
            ->assertSee('Send PI for approval');
    }

    public function test_opportunity_can_be_created_with_blank_optional_text_fields(): void
    {
        $admin = $this->admin('crm_opportunity_blank_fields_admin');
        $customer = CrmCustomer::query()->create([
            'company_name' => 'Blank Fields Buyer',
            'contact_person' => 'Buyer',
            'status' => 'active',
        ]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.crm.opportunities.store'), [
                'customer_id' => $customer->id,
                'name' => 'Blank optional fields project',
                'stage' => 'qualified',
                'amount' => 0,
                'currency' => 'USD',
                'probability' => 20,
                'expected_close_date' => '',
                'competitor' => '',
                'lost_reason' => '',
                'notes' => '',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('crm_opportunities', [
            'customer_id' => $customer->id,
            'name' => 'Blank optional fields project',
            'competitor' => '',
        ]);
    }

    public function test_opportunity_uses_open_tasks_as_the_next_action(): void
    {
        $admin = $this->admin('crm_opportunity_task_admin');
        $customer = CrmCustomer::query()->create([
            'company_name' => 'Task Driven Buyer',
            'contact_person' => 'Buyer',
            'status' => 'active',
        ]);
        $opportunity = CrmOpportunity::query()->create([
            'customer_id' => $customer->id,
            'name' => 'Task Driven Project',
            'stage' => 'qualified',
            'currency' => 'USD',
            'probability' => 20,
            'next_step' => 'Legacy duplicated step',
        ]);
        CrmTask::query()->create([
            'customer_id' => $customer->id,
            'opportunity_id' => $opportunity->id,
            'title' => 'Send technical proposal',
            'status' => 'open',
            'due_at' => now()->addDay(),
        ]);

        $response = $this->actingAs($admin, 'admin')
            ->get(route('admin.crm.opportunities.edit', ['opportunityId' => $opportunity->id]))
            ->assertOk()
            ->assertSee('下一步摘要')
            ->assertSee('来自商机待办')
            ->assertSee('商机工作区')
            ->assertSee('商机待办')
            ->assertSee('Send technical proposal')
            ->assertSee('快捷操作')
            ->assertDontSee('name="next_step"', false)
            ->assertDontSee('name="next_step_at"', false)
            ->assertDontSee('Legacy duplicated step')
            ->assertDontSee('当前下一步')
            ->assertDontSee('新建待办</h2>', false);

        $html = $response->getContent();
        $sidebarPosition = strpos($html, '<aside class="space-y-6');

        $this->assertNotFalse($sidebarPosition);
        $this->assertLessThan($sidebarPosition, strpos($html, 'id="opportunity-activity"'));
        $this->assertLessThan($sidebarPosition, strpos($html, 'id="opportunity-tasks"'));
        $this->assertLessThan($sidebarPosition, strpos($html, 'id="opportunity-documents"'));
        $this->assertGreaterThan($sidebarPosition, strpos($html, '快捷操作'));
    }

    public function test_opportunity_creation_exposes_source_modes_and_blocks_duplicate_active_source(): void
    {
        $admin = $this->admin('crm_opportunity_source_admin');
        $customer = CrmCustomer::query()->create([
            'company_name' => 'Source Mode Buyer',
            'contact_person' => 'Buyer',
            'status' => 'active',
        ]);
        $inquiry = CrmInquiry::query()->create([
            'customer_id' => $customer->id,
            'subject' => 'Source mode inquiry',
            'status' => 'qualified',
            'priority' => 'normal',
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.crm.opportunities.create'))
            ->assertOk()
            ->assertSee('从询盘创建')
            ->assertSee('无来源直接创建')
            ->assertSee('Source mode inquiry');

        $payload = [
            'source_mode' => 'inquiry',
            'source_inquiry_id' => $inquiry->id,
            'customer_id' => $customer->id,
            'name' => 'Source mode opportunity',
            'stage' => 'qualified',
            'currency' => 'USD',
            'amount' => 0,
            'probability' => 20,
        ];

        $this->actingAs($admin, 'admin')
            ->post(route('admin.crm.opportunities.store'), $payload)
            ->assertRedirect();

        $this->actingAs($admin, 'admin')
            ->post(route('admin.crm.opportunities.store'), $payload)
            ->assertSessionHasErrors('source_inquiry_id');

        $this->assertSame(1, CrmOpportunity::query()->where('source_inquiry_id', $inquiry->id)->count());
    }

    public function test_opportunity_can_be_archived_and_restored_without_deleting_related_records(): void
    {
        $admin = $this->admin('crm_opportunity_archive_admin');
        $customer = CrmCustomer::query()->create([
            'company_name' => 'Archive Buyer',
            'contact_person' => 'Buyer',
            'status' => 'active',
        ]);
        $inquiry = CrmInquiry::query()->create([
            'customer_id' => $customer->id,
            'subject' => 'Archive inquiry',
            'status' => 'qualified',
            'priority' => 'normal',
        ]);
        $opportunity = CrmOpportunity::query()->create([
            'customer_id' => $customer->id,
            'source_inquiry_id' => $inquiry->id,
            'name' => 'Archive opportunity',
            'stage' => 'qualified',
        ]);
        $task = CrmTask::query()->create([
            'customer_id' => $customer->id,
            'inquiry_id' => $inquiry->id,
            'opportunity_id' => $opportunity->id,
            'title' => 'Archive task',
            'status' => 'open',
        ]);
        $activity = CrmFollowUp::query()->create([
            'customer_id' => $customer->id,
            'inquiry_id' => $inquiry->id,
            'opportunity_id' => $opportunity->id,
            'content' => 'Archive activity',
        ]);
        $document = CrmQuote::query()->create([
            'customer_id' => $customer->id,
            'inquiry_id' => $inquiry->id,
            'opportunity_id' => $opportunity->id,
            'quote_no' => 'Q-ARCHIVE',
            'title' => 'Archive document',
            'currency' => 'USD',
            'status' => 'draft',
        ]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.crm.opportunities.delete', ['opportunityId' => $opportunity->id]))
            ->assertRedirect(route('admin.crm.opportunities.index'));

        $this->assertSoftDeleted('crm_opportunities', ['id' => $opportunity->id]);
        $this->assertDatabaseHas('crm_tasks', ['id' => $task->id, 'opportunity_id' => $opportunity->id]);
        $this->assertDatabaseHas('crm_follow_ups', ['id' => $activity->id, 'opportunity_id' => $opportunity->id]);
        $this->assertDatabaseHas('crm_quotes', ['id' => $document->id, 'opportunity_id' => $opportunity->id]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.crm.opportunities.index', ['view' => 'archived']))
            ->assertOk()
            ->assertSee('Archive opportunity')
            ->assertSee('恢复');

        $this->actingAs($admin, 'admin')
            ->post(route('admin.crm.opportunities.restore', ['opportunityId' => $opportunity->id]))
            ->assertRedirect();

        $this->assertDatabaseHas('crm_opportunities', ['id' => $opportunity->id, 'deleted_at' => null]);
    }

    public function test_opportunity_edit_can_attach_same_customer_inquiry_and_restore_blocks_source_conflict(): void
    {
        $admin = $this->admin('crm_opportunity_link_admin');
        $customer = CrmCustomer::query()->create([
            'company_name' => 'Link Buyer',
            'contact_person' => 'Buyer',
            'status' => 'active',
        ]);
        $inquiry = CrmInquiry::query()->create([
            'customer_id' => $customer->id,
            'subject' => 'Link inquiry',
            'status' => 'qualified',
            'priority' => 'normal',
        ]);
        $opportunity = CrmOpportunity::query()->create([
            'customer_id' => $customer->id,
            'name' => 'Direct opportunity to link',
            'stage' => 'qualified',
        ]);

        $this->actingAs($admin, 'admin')
            ->put(route('admin.crm.opportunities.update', ['opportunityId' => $opportunity->id]), [
                'source_mode' => 'inquiry',
                'source_inquiry_id' => $inquiry->id,
                'customer_id' => $customer->id,
                'name' => $opportunity->name,
                'stage' => 'qualified',
                'amount' => 0,
                'currency' => 'USD',
                'probability' => 20,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('crm_opportunities', [
            'id' => $opportunity->id,
            'source_inquiry_id' => $inquiry->id,
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.crm.opportunities.edit', ['opportunityId' => $opportunity->id]))
            ->assertOk()
            ->assertSee('当前来源询盘')
            ->assertSee('修改来源询盘')
            ->assertSee('data-source-editor class="mt-5 hidden"', false);

        $opportunity->delete();
        CrmOpportunity::query()->create([
            'customer_id' => $customer->id,
            'source_inquiry_id' => $inquiry->id,
            'name' => 'Replacement active opportunity',
            'stage' => 'qualified',
        ]);

        $this->actingAs($admin, 'admin')
            ->from(route('admin.crm.opportunities.index', ['view' => 'archived']))
            ->post(route('admin.crm.opportunities.restore', ['opportunityId' => $opportunity->id]))
            ->assertRedirect(route('admin.crm.opportunities.index', ['view' => 'archived']))
            ->assertSessionHasErrors('source_inquiry_id');

        $this->assertSoftDeleted('crm_opportunities', ['id' => $opportunity->id]);
    }

    public function test_inquiry_conversion_links_existing_open_tasks_and_documents_without_copying(): void
    {
        $admin = $this->admin('crm_conversion_chain_admin');
        $customer = CrmCustomer::query()->create([
            'company_name' => 'Conversion Chain Buyer',
            'contact_person' => 'Buyer',
            'status' => 'active',
        ]);
        $inquiry = CrmInquiry::query()->create([
            'customer_id' => $customer->id,
            'subject' => 'Conversion chain inquiry',
            'status' => 'qualified',
            'priority' => 'normal',
        ]);
        $openTask = CrmTask::query()->create([
            'customer_id' => $customer->id,
            'inquiry_id' => $inquiry->id,
            'title' => 'Open inquiry task',
            'status' => 'open',
        ]);
        $doneTask = CrmTask::query()->create([
            'customer_id' => $customer->id,
            'inquiry_id' => $inquiry->id,
            'title' => 'Completed inquiry task',
            'status' => 'done',
            'completed_at' => now(),
        ]);
        $document = CrmQuote::query()->create([
            'customer_id' => $customer->id,
            'inquiry_id' => $inquiry->id,
            'quote_no' => 'Q-CONVERSION',
            'title' => 'Existing inquiry document',
            'currency' => 'USD',
            'status' => 'draft',
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.crm.inquiries.show', ['inquiryId' => $inquiry->id]))
            ->assertOk()
            ->assertSee('将补关联的未完成待办')
            ->assertSee('将补关联的已有单据');

        $this->actingAs($admin, 'admin')
            ->post(route('admin.crm.opportunities.from-inquiry', ['inquiryId' => $inquiry->id]))
            ->assertRedirect();

        $opportunity = CrmOpportunity::query()->where('source_inquiry_id', $inquiry->id)->firstOrFail();
        $this->assertDatabaseHas('crm_tasks', ['id' => $openTask->id, 'opportunity_id' => $opportunity->id]);
        $this->assertDatabaseHas('crm_tasks', ['id' => $doneTask->id, 'opportunity_id' => null]);
        $this->assertDatabaseHas('crm_quotes', ['id' => $document->id, 'opportunity_id' => $opportunity->id]);
        $this->assertSame(2, CrmTask::query()->where('inquiry_id', $inquiry->id)->count());
        $this->assertSame(1, CrmQuote::query()->where('inquiry_id', $inquiry->id)->count());
    }

    public function test_new_tasks_keep_inquiry_and_opportunity_sales_chain(): void
    {
        $admin = $this->admin('crm_task_chain_admin');
        $customer = CrmCustomer::query()->create([
            'company_name' => 'Task Chain Buyer',
            'contact_person' => 'Buyer',
            'status' => 'active',
        ]);
        $inquiry = CrmInquiry::query()->create([
            'customer_id' => $customer->id,
            'subject' => 'Task chain inquiry',
            'status' => 'converted',
            'priority' => 'normal',
        ]);
        $opportunity = CrmOpportunity::query()->create([
            'customer_id' => $customer->id,
            'source_inquiry_id' => $inquiry->id,
            'name' => 'Task chain opportunity',
            'stage' => 'qualified',
        ]);

        $this->actingAs($admin, 'admin')->post(route('admin.crm.tasks.store'), [
            'customer_id' => $customer->id,
            'inquiry_id' => $inquiry->id,
            'title' => 'Created from inquiry',
        ])->assertRedirect();
        $this->actingAs($admin, 'admin')->post(route('admin.crm.tasks.store'), [
            'customer_id' => $customer->id,
            'opportunity_id' => $opportunity->id,
            'title' => 'Created from opportunity',
        ])->assertRedirect();

        $this->assertDatabaseHas('crm_tasks', [
            'title' => 'Created from inquiry',
            'inquiry_id' => $inquiry->id,
            'opportunity_id' => $opportunity->id,
        ]);
        $this->assertDatabaseHas('crm_tasks', [
            'title' => 'Created from opportunity',
            'inquiry_id' => $inquiry->id,
            'opportunity_id' => $opportunity->id,
        ]);
    }

    public function test_opportunity_activity_is_shared_and_can_create_and_complete_next_task(): void
    {
        $admin = $this->admin('crm_activity_timeline_admin');
        $customer = CrmCustomer::query()->create([
            'company_name' => 'Timeline Buyer',
            'contact_person' => 'Buyer',
            'status' => 'active',
        ]);
        $inquiry = CrmInquiry::query()->create([
            'customer_id' => $customer->id,
            'subject' => 'Timeline inquiry',
            'status' => 'converted',
            'priority' => 'normal',
        ]);
        $opportunity = CrmOpportunity::query()->create([
            'customer_id' => $customer->id,
            'source_inquiry_id' => $inquiry->id,
            'name' => 'Timeline opportunity',
            'stage' => 'qualified',
        ]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.crm.opportunities.activities.store', ['opportunityId' => $opportunity->id]), [
                'activity_type' => 'meeting',
                'followup_type' => '会议',
                'content' => 'Customer confirmed the technical scope.',
                'create_task' => '1',
                'task_title' => 'Send revised proposal',
                'task_priority' => 'high',
            ])
            ->assertRedirect();

        $task = CrmTask::query()->where('title', 'Send revised proposal')->firstOrFail();
        $activity = CrmFollowUp::query()->where('content', 'Customer confirmed the technical scope.')->firstOrFail();
        $this->assertSame((int) $customer->id, (int) $activity->customer_id);
        $this->assertSame('meeting', (string) $activity->activity_type);
        $this->assertSame((int) $inquiry->id, (int) $activity->inquiry_id);
        $this->assertSame((int) $opportunity->id, (int) $activity->opportunity_id);
        $this->assertSame((int) $task->id, (int) $activity->task_id);
        $this->assertSame((int) $inquiry->id, (int) $task->inquiry_id);
        $this->assertSame((int) $opportunity->id, (int) $task->opportunity_id);

        foreach ([
            route('admin.crm.customers.show', ['customerId' => $customer->id]),
            route('admin.crm.inquiries.show', ['inquiryId' => $inquiry->id]),
            route('admin.crm.opportunities.edit', ['opportunityId' => $opportunity->id]),
        ] as $url) {
            $this->actingAs($admin, 'admin')->get($url)->assertOk()->assertSee('Customer confirmed the technical scope.');
        }

        $this->actingAs($admin, 'admin')
            ->post(route('admin.crm.tasks.complete', ['taskId' => $task->id]), [
                'activity_type' => 'task_completed',
                'followup_type' => '待办结果',
                'result_content' => 'Revised proposal sent to customer.',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('crm_tasks', ['id' => $task->id, 'status' => 'done']);
        $this->assertDatabaseHas('crm_follow_ups', [
            'task_id' => $task->id,
            'opportunity_id' => $opportunity->id,
            'activity_type' => 'task_completed',
            'content' => 'Revised proposal sent to customer.',
        ]);
        $this->assertSame(2, CrmFollowUp::query()->where('task_id', $task->id)->count());
    }

    public function test_quote_conversion_creates_independent_document_and_items(): void
    {
        $admin = $this->admin('crm_convert_admin');
        $customer = CrmCustomer::query()->create(['company_name'=>'Convert Buyer','contact_person'=>'Buyer','status'=>'active']);
        $quote = CrmQuote::query()->create(['customer_id'=>$customer->id,'quote_no'=>'Q-CONVERT','title'=>'Base quotation','document_type'=>'quotation','currency'=>'USD','status'=>'draft']);
        $quote->items()->create(['item_name'=>'Machine','quantity'=>2,'unit'=>'set','unit_price'=>100,'amount'=>200]);
        $this->actingAs($admin,'admin')->post(route('admin.crm.quotes.convert',['quoteId'=>$quote->id]),['document_type'=>'proforma_invoice'])->assertRedirect();
        $copy = CrmQuote::query()->where('source_quote_id',$quote->id)->firstOrFail();
        $this->assertSame('proforma_invoice',$copy->document_type);
        $this->assertNotSame($quote->quote_no,$copy->quote_no);
        $this->assertDatabaseHas('crm_quote_items',['quote_id'=>$copy->id,'item_name'=>'Machine']);
    }

    public function test_quote_show_uses_print_type_switch_instead_of_copy_creation_entry(): void
    {
        $admin = $this->admin('crm_quote_print_switch_admin');
        $customer = CrmCustomer::query()->create([
            'company_name' => 'Print Switch Buyer',
            'contact_person' => 'Buyer',
            'status' => 'active',
        ]);
        $quote = CrmQuote::query()->create([
            'customer_id' => $customer->id,
            'quote_no' => 'Q-PRINT-SWITCH',
            'title' => 'Print switch quotation',
            'document_type' => 'quotation',
            'currency' => 'USD',
            'status' => 'draft',
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.crm.quotes.show', ['quoteId' => $quote->id]))
            ->assertOk()
            ->assertSee('打印单据')
            ->assertSee('下载 PDF')
            ->assertSee('形式发票')
            ->assertSee('正式发票')
            ->assertDontSee('创建副本')
            ->assertDontSee(route('admin.crm.quotes.convert', ['quoteId' => $quote->id]));
    }

    public function test_quote_pdf_download_uses_chromium_service_and_print_template(): void
    {
        $admin = $this->admin('crm_quote_pdf_admin');
        $customer = CrmCustomer::query()->create([
            'company_name' => 'PDF Buyer',
            'contact_person' => 'Buyer',
            'status' => 'active',
        ]);
        $quote = CrmQuote::query()->create([
            'customer_id' => $customer->id,
            'quote_no' => 'Q-PDF',
            'title' => 'PDF quotation',
            'document_type' => 'quotation',
            'currency' => 'USD',
            'status' => 'draft',
        ]);
        $quote->items()->create([
            'item_name' => 'PDF Machine',
            'quantity' => 1,
            'unit' => 'set',
            'unit_price' => 100,
            'amount' => 100,
        ]);

        $this->app->bind(CrmDocumentPdfService::class, static function () {
            return new class extends CrmDocumentPdfService {
                public function render(string $html, string $fileStem): string
                {
                    if (! str_contains($html, 'PDF Machine')) {
                        throw new \RuntimeException('Expected print template HTML was not rendered.');
                    }

                    $path = storage_path('app/tmp/testing-crm-document.pdf');
                    if (! is_dir(dirname($path))) {
                        mkdir(dirname($path), 0777, true);
                    }
                    file_put_contents($path, "%PDF-1.4\n% Test PDF\n");

                    return $path;
                }
            };
        });

        $this->actingAs($admin, 'admin')
            ->get(route('admin.crm.quotes.pdf', ['quoteId' => $quote->id, 'type' => 'invoice']))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    public function test_quote_pdf_print_template_paginates_long_item_tables(): void
    {
        $admin = $this->admin('crm_quote_pdf_pagination_admin');
        $customer = CrmCustomer::query()->create([
            'company_name' => 'Long PDF Buyer',
            'contact_person' => 'Buyer',
            'status' => 'active',
        ]);
        $quote = CrmQuote::query()->create([
            'customer_id' => $customer->id,
            'quote_no' => 'Q-PDF-LONG',
            'title' => 'Long PDF quotation',
            'document_type' => 'quotation',
            'currency' => 'USD',
            'status' => 'draft',
        ]);

        for ($index = 1; $index <= 18; $index++) {
            $quote->items()->create([
                'item_name' => 'Long PDF Machine '.$index,
                'description' => str_repeat('Stable pagination requires conservative row height estimation for long product descriptions. ', 3),
                'quantity' => 1,
                'unit' => 'set',
                'unit_price' => 100 + $index,
                'amount' => 100 + $index,
            ]);
        }

        $capturedHtml = null;
        $this->app->bind(CrmDocumentPdfService::class, static function () use (&$capturedHtml) {
            return new class($capturedHtml) extends CrmDocumentPdfService {
                private $capturedHtml;

                public function __construct(&$capturedHtml)
                {
                    $this->capturedHtml = &$capturedHtml;
                }

                public function render(string $html, string $fileStem): string
                {
                    $this->capturedHtml = $html;

                    $path = storage_path('app/tmp/testing-crm-document-pagination.pdf');
                    if (! is_dir(dirname($path))) {
                        mkdir(dirname($path), 0777, true);
                    }
                    file_put_contents($path, "%PDF-1.4\n% Test PDF\n");

                    return $path;
                }
            };
        });

        $this->actingAs($admin, 'admin')
            ->get(route('admin.crm.quotes.pdf', ['quoteId' => $quote->id, 'type' => 'quotation']))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        $this->assertIsString($capturedHtml);
        $this->assertStringContainsString('Long PDF Machine 18', $capturedHtml);
        $this->assertStringContainsString('Items continued', $capturedHtml);
        $this->assertStringContainsString('Page 2 of', $capturedHtml);
    }

    public function test_quote_print_template_keeps_compact_no_image_quotation_on_one_page(): void
    {
        $admin = $this->admin('crm_quote_compact_pagination_admin');
        $customer = CrmCustomer::query()->create([
            'company_name' => 'Compact PDF Buyer',
            'contact_person' => 'Buyer',
            'status' => 'active',
        ]);
        $quote = CrmQuote::query()->create([
            'customer_id' => $customer->id,
            'quote_no' => 'Q-PDF-COMPACT',
            'title' => 'Compact quotation',
            'document_type' => 'quotation',
            'document_language' => 'en',
            'currency' => 'USD',
            'status' => 'draft',
            'trade_term' => 'EXW',
            'lead_time' => '15-20 working days',
            'valid_until' => now()->addMonth(),
            'payment_terms' => '50% deposit, 50% balance before shipment.',
            'delivery_terms' => 'DDP',
            'warranty_terms' => '18 months warranty for machine main parts.',
            'installation_terms' => 'Remote training and online technical support included.',
            'total_amount' => 15345,
            'shipping_fee' => 1200,
            'grand_total' => 16545,
        ]);

        foreach ([
            ['Base recommended 2K epoxy dispensing system', 'Stand-alone XYZ dispensing machine, 300*300 mm working area, 2K servo-driven kit', 'SF331-2K', 6500],
            ['Optional cooling system', '', '-', 200],
            ['Optional simple 1K pneumatic syringe module', '', '-', 850],
            ['Optional 1K silicone screw valve module', '', '-', 3000],
            ['Optional CCD alignment system', '', '-', 4500],
            ['Dispensing tips', '21G(0.6)-1000pcs, 22G(0.45)-2000pcs, 23G(0.34)-1000pcs, 24(0.31)G-1000pcs', '-', 110],
            ['Static mixers', 'PMF06-24 or to be determined', '-', 185],
        ] as $index => [$name, $description, $model, $amount]) {
            $quote->items()->create([
                'item_name' => $name,
                'description' => $description,
                'model' => $model,
                'quantity' => $index === 6 ? 500 : 1,
                'unit' => $index === 6 ? 'pcs' : 'set',
                'unit_price' => $index === 6 ? 0.37 : $amount,
                'amount' => $amount,
                'sort_order' => $index + 1,
            ]);
        }

        $response = $this->actingAs($admin, 'admin')
            ->get(route('admin.crm.quotes.print', ['quoteId' => (int) $quote->id, 'type' => 'quotation', 'language' => 'en']))
            ->assertOk()
            ->assertSee('Static mixers')
            ->assertSee('Page 1 of 1')
            ->assertDontSee('Items continued')
            ->assertDontSee('Page 2 of');

        $response->assertSee('class="term-item" data-term-field="payment_terms"', false)
            ->assertSee('class="term-item" data-term-field="delivery_terms"', false)
            ->assertSee('class="term-item full" data-term-field="warranty_terms"', false)
            ->assertSee('class="term-item full" data-term-field="installation_terms"', false)
            ->assertSee('class="term-item full" data-term-field="packing_terms"', false);
    }

    public function test_quote_pdf_regression_admin_page_can_start_run(): void
    {
        Queue::fake();
        $admin = $this->admin('crm_pdf_regression_admin');

        $this->actingAs($admin, 'admin')
            ->get(route('admin.crm.quotes.index'))
            ->assertOk()
            ->assertSee('PDF 回归检查');

        $this->actingAs($admin, 'admin')
            ->get(route('admin.crm.quotes.pdf-regression.index'))
            ->assertOk()
            ->assertSee('PDF 回归检查')
            ->assertSee('print CSS');

        $this->actingAs($admin, 'admin')
            ->post(route('admin.crm.quotes.pdf-regression.store'))
            ->assertRedirect();

        $run = CrmDocumentPdfRegressionRun::query()->firstOrFail();
        $this->assertSame(CrmDocumentPdfRegressionRun::STATUS_PENDING, (string) $run->status);
        $this->assertSame((int) $admin->id, (int) $run->triggered_by_admin_id);
        Queue::assertPushed(GenerateCrmDocumentPdfRegressionRun::class);
    }

    public function test_quote_pdf_regression_cleanup_keeps_baseline_runs(): void
    {
        $oldDirectory = storage_path('app/pdf-regression/testing-old-baseline');
        File::ensureDirectoryExists($oldDirectory);
        File::put($oldDirectory.'/report.json', '{}');

        $run = CrmDocumentPdfRegressionRun::query()->create([
            'status' => CrmDocumentPdfRegressionRun::STATUS_COMPLETED,
            'output_directory' => $oldDirectory,
            'created_at' => now()->subDays(60),
            'updated_at' => now()->subDays(60),
        ]);
        CrmDocumentPdfRegressionBaseline::query()->create([
            'name' => 'default',
            'run_id' => (int) $run->id,
            'baseline_directory' => storage_path('app/pdf-regression-baselines/default'),
            'render_context_json' => ['render_media' => 'print'],
        ]);
        CrmDocumentPdfRegressionRun::query()->create([
            'status' => CrmDocumentPdfRegressionRun::STATUS_COMPLETED,
            'output_directory' => storage_path('app/pdf-regression/testing-newer-run'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $cleanup = app(CrmDocumentPdfRegressionCleanupService::class);
        $preview = $cleanup->preview(1, 30);

        $this->assertSame([], $preview['candidates']);
        $this->assertDirectoryExists($oldDirectory);

        File::deleteDirectory($oldDirectory);
    }

    public function test_quote_pdf_visual_diff_rejects_render_context_mismatch(): void
    {
        $baselineDirectory = storage_path('app/tmp/testing-pdf-baseline-context');
        $currentDirectory = storage_path('app/tmp/testing-pdf-current-context');
        File::deleteDirectory($baselineDirectory);
        File::deleteDirectory($currentDirectory);
        File::ensureDirectoryExists($baselineDirectory);
        File::ensureDirectoryExists($currentDirectory);
        File::put($baselineDirectory.'/baseline.json', json_encode([
            'render_context' => ['render_media' => 'screen', 'page_size' => 'A4', 'viewport_width' => 1240, 'viewport_height' => 1754, 'device_scale_factor' => 1],
            'results' => [],
        ]));
        File::put($currentDirectory.'/report.json', json_encode([
            'render_context' => ['render_media' => 'print', 'page_size' => 'A4', 'viewport_width' => 1240, 'viewport_height' => 1754, 'device_scale_factor' => 1],
            'results' => [],
        ]));

        $baseline = CrmDocumentPdfRegressionBaseline::query()->create([
            'name' => 'default',
            'baseline_directory' => $baselineDirectory,
            'render_context_json' => ['render_media' => 'screen', 'page_size' => 'A4', 'viewport_width' => 1240, 'viewport_height' => 1754, 'device_scale_factor' => 1],
        ]);

        $result = app(CrmDocumentPdfVisualDiffService::class)->compareReportToBaseline([
            'run_directory' => $currentDirectory,
            'render_context' => ['render_media' => 'print', 'page_size' => 'A4', 'viewport_width' => 1240, 'viewport_height' => 1754, 'device_scale_factor' => 1],
            'results' => [],
        ], $baseline);

        $this->assertSame('render_context_mismatch', $result['status']);

        File::deleteDirectory($baselineDirectory);
        File::deleteDirectory($currentDirectory);
    }

    public function test_quote_pdf_regression_command_generates_report_artifacts(): void
    {
        $customer = CrmCustomer::query()->create([
            'company_name' => 'PDF Regression Buyer',
            'contact_person' => 'Buyer',
            'status' => 'active',
        ]);
        $quote = CrmQuote::query()->create([
            'customer_id' => $customer->id,
            'quote_no' => 'Q-PDF-REGRESSION',
            'title' => 'PDF regression quotation',
            'document_type' => 'quotation',
            'currency' => 'USD',
            'status' => 'draft',
        ]);
        $quote->items()->create([
            'item_name' => 'Regression Machine',
            'quantity' => 1,
            'unit' => 'set',
            'unit_price' => 100,
            'amount' => 100,
        ]);

        $outputRoot = storage_path('app/tmp/testing-crm-document-pdf-regression');
        \Illuminate\Support\Facades\File::deleteDirectory($outputRoot);

        $this->app->bind(CrmDocumentPdfService::class, static function () {
            return new class extends CrmDocumentPdfService {
                public function render(string $html, string $fileStem): string
                {
                    if (! str_contains($html, 'Regression Machine')) {
                        throw new \RuntimeException('Expected regression print template HTML was not rendered.');
                    }

                    $path = storage_path('app/tmp/'.$fileStem.'-testing-regression.pdf');
                    if (! is_dir(dirname($path))) {
                        mkdir(dirname($path), 0777, true);
                    }
                    file_put_contents($path, "%PDF-1.4\n1 0 obj\n<< /Type /Page >>\nendobj\n");

                    return $path;
                }
            };
        });

        $exitCode = \Illuminate\Support\Facades\Artisan::call('crm:document-pdf-regression', [
            '--quote' => (string) $quote->id,
            '--invoice-quote' => (string) $quote->id,
            '--skip-screenshots' => true,
            '--output' => 'tmp/testing-crm-document-pdf-regression',
        ]);

        $this->assertSame(0, $exitCode, \Illuminate\Support\Facades\Artisan::output());
        $reports = glob($outputRoot.'/*/report.json') ?: [];
        $this->assertCount(1, $reports);

        $report = json_decode((string) file_get_contents($reports[0]), true);
        $this->assertIsArray($report);
        $this->assertCount(5, $report['results']);
        $this->assertSame(['quotation', 'proforma_invoice', 'invoice', 'packing_list', 'contract'], array_column($report['results'], 'document_type'));
        foreach ($report['results'] as $result) {
            $this->assertSame(1, (int) $result['pdf_pages']);
            $this->assertFileExists($result['pdf_path']);
            $this->assertFileExists($result['html_path']);
        }

        \Illuminate\Support\Facades\File::deleteDirectory($outputRoot);
    }

    public function test_quote_sales_chain_is_normalized_and_conflicts_are_rejected(): void
    {
        $admin = $this->admin('crm_quote_chain_admin');
        $collection = $this->collection('Quote Chain Collection');
        $customer = CrmCustomer::query()->create([
            'collection_id' => $collection->id,
            'company_name' => 'Quote Chain Buyer',
            'contact_person' => 'Buyer',
            'status' => 'active',
        ]);
        $otherCustomer = CrmCustomer::query()->create([
            'company_name' => 'Other Quote Buyer',
            'contact_person' => 'Other',
            'status' => 'active',
        ]);
        $inquiry = CrmInquiry::query()->create([
            'collection_id' => $collection->id,
            'customer_id' => $customer->id,
            'subject' => 'Quote chain inquiry',
            'status' => 'converted',
            'priority' => 'normal',
        ]);
        $opportunity = CrmOpportunity::query()->create([
            'collection_id' => $collection->id,
            'customer_id' => $customer->id,
            'source_inquiry_id' => $inquiry->id,
            'name' => 'Quote chain opportunity',
            'stage' => 'qualified',
        ]);

        $basePayload = [
            'collection_id' => $collection->id,
            'customer_id' => $customer->id,
            'title' => 'Quote chain document',
            'document_type' => 'quotation',
            'currency' => 'USD',
            'items' => [
                'item_name' => ['Machine'],
                'quantity' => [1],
                'unit_price' => [100],
            ],
        ];

        $this->actingAs($admin, 'admin')
            ->post(route('admin.crm.quotes.store'), $basePayload + ['inquiry_id' => $inquiry->id])
            ->assertRedirect();

        $quote = CrmQuote::query()->where('title', 'Quote chain document')->firstOrFail();
        $this->assertSame((int) $inquiry->id, (int) $quote->inquiry_id);
        $this->assertSame((int) $opportunity->id, (int) $quote->opportunity_id);
        $this->assertSame((int) $customer->id, (int) $quote->customer_id);
        $this->assertSame((int) $collection->id, (int) $quote->collection_id);

        $otherInquiry = CrmInquiry::query()->create([
            'customer_id' => $otherCustomer->id,
            'subject' => 'Other quote chain inquiry',
            'status' => 'qualified',
            'priority' => 'normal',
        ]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.crm.quotes.store'), array_replace($basePayload, [
                'inquiry_id' => $otherInquiry->id,
                'opportunity_id' => $opportunity->id,
            ]))
            ->assertSessionHasErrors('opportunity_id');

        $this->actingAs($admin, 'admin')
            ->post(route('admin.crm.quotes.store'), array_replace($basePayload, [
                'customer_id' => $otherCustomer->id,
                'opportunity_id' => $opportunity->id,
            ]))
            ->assertSessionHasErrors('customer_id');
    }

    private function admin(string $username = 'crm_admin'): Admin
    {
        return Admin::query()->create([
            'username' => $username,
            'password' => 'secret-123',
            'email' => $username.'@example.com',
            'display_name' => 'CRM Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);
    }

    private function collection(string $name): CollectionRecord
    {
        return CollectionRecord::query()->create([
            'name' => $name,
            'slug' => \Illuminate\Support\Str::slug($name),
            'description' => '',
            'status' => 'active',
            'sort_order' => 1,
        ]);
    }
}
