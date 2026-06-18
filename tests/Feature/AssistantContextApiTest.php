<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\CaseRecord;
use App\Models\CollectionRecord;
use App\Models\CrmAfterSalesTicket;
use App\Models\CrmCustomer;
use App\Models\CrmCustomerContact;
use App\Models\CrmInquiry;
use App\Models\CrmOpportunity;
use App\Models\CrmQuote;
use App\Models\CrmSalesOrder;
use App\Models\EntityRecord;
use App\Models\KnowledgeBase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssistantContextApiTest extends TestCase
{
    use RefreshDatabase;

    private function createActiveAdmin(): Admin
    {
        return Admin::query()->create([
            'username' => 'assistant_api_admin',
            'password' => 'secret-123',
            'email' => 'assistant@example.test',
            'display_name' => 'Assistant API',
            'role' => 'admin',
            'status' => 'active',
        ]);
    }

    private function bearer(Admin $admin, array $scopes): string
    {
        return $admin->createToken('assistant-context-test', $scopes)->plainTextToken;
    }

    public function test_assistant_context_search_requires_bearer_token(): void
    {
        $this->getJson('/api/v1/assistant/context/search?q=SJ4060')
            ->assertStatus(401)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'unauthorized');
    }

    public function test_assistant_context_search_requires_assistant_read_scope(): void
    {
        $token = $this->bearer($this->createActiveAdmin(), ['catalog:read']);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/assistant/context/search?q=SJ4060')
            ->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'forbidden')
            ->assertJsonPath('error.details.required_scope', 'assistant:read');
    }

    public function test_assistant_context_search_returns_collection_limited_business_context(): void
    {
        $admin = $this->createActiveAdmin();
        $token = $this->bearer($admin, ['assistant:read']);
        $automation = CollectionRecord::query()->create([
            'name' => 'Assistant Automation Equipment',
            'slug' => 'assistant-automation-equipment',
            'description' => '',
            'status' => 'active',
            'sort_order' => 1,
        ]);
        $cooling = CollectionRecord::query()->create([
            'name' => 'Assistant Industrial Cooling',
            'slug' => 'assistant-industrial-cooling',
            'description' => '',
            'status' => 'active',
            'sort_order' => 2,
        ]);

        $customer = CrmCustomer::query()->create([
            'collection_id' => $automation->id,
            'company_name' => 'Graham Automation Spain',
            'contact_person' => 'Graham',
            'customer_type' => 'distributor',
            'country' => 'Spain',
            'email' => 'graham@example.test',
            'status' => 'active',
        ]);
        CrmCustomer::query()->create([
            'collection_id' => $cooling->id,
            'company_name' => 'Graham Cooling Spain',
            'contact_person' => 'Graham',
            'customer_type' => 'end_user',
            'country' => 'Spain',
            'email' => 'cooling@example.test',
            'status' => 'active',
        ]);
        CrmCustomerContact::query()->create([
            'customer_id' => $customer->id,
            'name' => 'Graham Buyer',
            'title' => 'Purchasing Manager',
            'email' => 'buyer@example.test',
            'is_primary' => true,
            'status' => 'active',
        ]);
        $entity = EntityRecord::query()->create([
            'collection_id' => $automation->id,
            'name' => 'SJ4060',
            'entity_type' => 'product_model',
            'aliases' => 'SJ-4060',
            'description' => 'SJ4060 dispensing system for automation projects.',
        ]);
        $inquiry = CrmInquiry::query()->create([
            'collection_id' => $automation->id,
            'customer_id' => $customer->id,
            'source_channel' => 'email',
            'subject' => 'SJ4060 nozzle clogging support',
            'raw_message' => 'The SJ4060 nozzle is clogging during dispensing.',
            'status' => 'new',
            'priority' => 'high',
            'customer_need_summary' => 'Customer needs troubleshooting advice for SJ4060 nozzle clogging.',
        ]);
        CrmOpportunity::query()->create([
            'collection_id' => $automation->id,
            'customer_id' => $customer->id,
            'source_inquiry_id' => $inquiry->id,
            'name' => 'SJ4060 Spain upgrade',
            'stage' => 'qualified',
            'amount' => 12000,
            'currency' => 'USD',
            'probability' => 30,
        ]);
        $quote = CrmQuote::query()->create([
            'collection_id' => $automation->id,
            'customer_id' => $customer->id,
            'inquiry_id' => $inquiry->id,
            'quote_no' => 'QT-SJ4060-001',
            'document_type' => 'quotation',
            'title' => 'SJ4060 quotation',
            'buyer_company' => 'Graham Automation Spain',
            'currency' => 'USD',
            'status' => 'draft',
            'total_amount' => 12000,
            'grand_total' => 12000,
        ]);
        CrmSalesOrder::query()->create([
            'collection_id' => $automation->id,
            'customer_id' => $customer->id,
            'inquiry_id' => $inquiry->id,
            'quote_id' => $quote->id,
            'order_no' => 'SO-SJ4060-001',
            'title' => 'SJ4060 automation order',
            'currency' => 'USD',
            'total_amount' => 12000,
            'order_status' => 'open',
        ]);
        CrmAfterSalesTicket::query()->create([
            'collection_id' => $automation->id,
            'customer_id' => $customer->id,
            'entity_id' => $entity->id,
            'title' => 'SJ4060 nozzle clogging',
            'issue_description' => 'SJ4060 nozzle clogging may relate to glue viscosity.',
            'issue_type' => 'troubleshooting',
            'status' => 'open',
            'priority' => 'normal',
        ]);
        KnowledgeBase::query()->create([
            'collection_id' => $automation->id,
            'name' => 'SJ4060 troubleshooting FAQ',
            'description' => 'Nozzle clogging FAQ for SJ4060.',
            'summary' => 'Troubleshooting SJ4060 nozzle clogging and glue viscosity.',
            'content' => 'SJ4060 nozzle clogging can be affected by glue viscosity.',
            'knowledge_type' => 'faq',
            'knowledge_role' => 'supporting_context',
            'status' => 'active',
        ]);
        CaseRecord::query()->create([
            'collection_id' => $automation->id,
            'entity_id' => $entity->id,
            'title' => 'SJ4060 Spain nozzle clogging case',
            'case_type' => 'troubleshooting_case',
            'summary' => 'A Spanish customer resolved SJ4060 nozzle clogging.',
            'solution' => 'Adjusted glue viscosity and cleaning workflow.',
            'result' => 'Stable dispensing restored.',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/assistant/context/search?q=SJ4060&collection_id='.$automation->id.'&limit=3');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.query', 'SJ4060')
            ->assertJsonPath('data.collection.id', (int) $automation->id)
            ->assertJsonPath('data.sections.customers.0.label', 'Graham Automation Spain')
            ->assertJsonPath('data.sections.entities.0.label', 'SJ4060')
            ->assertJsonPath('data.sections.inquiries.0.label', 'SJ4060 nozzle clogging support')
            ->assertJsonPath('data.sections.opportunities.0.label', 'SJ4060 Spain upgrade')
            ->assertJsonPath('data.sections.quotes.0.label', 'QT-SJ4060-001')
            ->assertJsonPath('data.sections.orders.0.label', 'SO-SJ4060-001')
            ->assertJsonPath('data.sections.tickets.0.label', 'SJ4060 nozzle clogging')
            ->assertJsonPath('data.sections.knowledge_bases.0.label', 'SJ4060 troubleshooting FAQ')
            ->assertJsonPath('data.sections.cases.0.label', 'SJ4060 Spain nozzle clogging case');

        $this->assertSame([], $response->json('data.sections.customers.1') ?? []);
    }

    public function test_assistant_context_search_rejects_empty_query_without_collection(): void
    {
        $token = $this->bearer($this->createActiveAdmin(), ['assistant:read']);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/assistant/context/search')
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'validation_failed');
    }
}
