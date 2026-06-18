<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\CrmContentProposal;
use App\Models\CollectionRecord;
use App\Models\CrmCustomer;
use App\Models\KnowledgeBase;
use App\Models\CaseRecord;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AssistantIntakeDraftApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    private function createActiveAdmin(): Admin
    {
        return Admin::query()->create([
            'username' => 'assistant_intake_admin',
            'password' => 'secret-123',
            'email' => 'assistant-intake@example.test',
            'display_name' => 'Assistant Intake',
            'role' => 'super_admin',
            'status' => 'active',
        ]);
    }

    private function bearer(Admin $admin, array $scopes): string
    {
        return $admin->createToken('assistant-intake-test', $scopes)->plainTextToken;
    }

    public function test_intake_draft_creation_requires_assistant_write_scope(): void
    {
        $token = $this->bearer($this->createActiveAdmin(), ['assistant:read']);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/assistant/intake-drafts', $this->draftPayload())
            ->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'forbidden')
            ->assertJsonPath('error.details.required_scope', 'assistant:write');
    }

    public function test_assistant_can_create_reviewable_intake_draft_without_writing_crm_records(): void
    {
        $admin = $this->createActiveAdmin();
        $token = $this->bearer($admin, ['assistant:write']);
        $collection = CollectionRecord::query()->create([
            'name' => 'Assistant Intake Automation',
            'slug' => 'assistant-intake-automation',
            'description' => '',
            'status' => 'active',
            'sort_order' => 1,
        ]);

        $this->assertSame(0, CrmCustomer::query()->count());

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->withHeader('X-Idempotency-Key', 'assistant-intake-create-001')
            ->postJson('/api/v1/assistant/intake-drafts', $this->draftPayload($collection->id));

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.draft.status', 'needs_review')
            ->assertJsonPath('data.draft.collection_id', (int) $collection->id)
            ->assertJsonPath('data.actions.0.action_type', 'create')
            ->assertJsonPath('data.actions.0.target_type', 'customer')
            ->assertJsonStructure([
                'data' => [
                    'draft' => ['id', 'status', 'source', 'collection_id', 'raw_input', 'normalized_summary', 'confidence'],
                    'actions' => [['id', 'action_type', 'target_type', 'risk_level', 'status']],
                ],
                'meta' => ['request_id', 'timestamp'],
            ]);

        $this->assertDatabaseHas('ai_intake_drafts', [
            'id' => (int) $response->json('data.draft.id'),
            'status' => 'needs_review',
            'collection_id' => (int) $collection->id,
        ]);
        $this->assertDatabaseHas('ai_intake_actions', [
            'draft_id' => (int) $response->json('data.draft.id'),
            'action_type' => 'create',
            'target_type' => 'customer',
            'risk_level' => 'low',
            'status' => 'pending',
        ]);
        $this->assertSame(0, CrmCustomer::query()->count(), 'Phase 2 must not write final CRM records.');
    }

    public function test_assistant_intake_draft_creation_is_idempotent(): void
    {
        $token = $this->bearer($this->createActiveAdmin(), ['assistant:write']);
        $headers = [
            'Authorization' => 'Bearer '.$token,
            'X-Idempotency-Key' => 'assistant-intake-idempotent-001',
        ];

        $first = $this->withHeaders($headers)
            ->postJson('/api/v1/assistant/intake-drafts', $this->draftPayload())
            ->assertCreated();

        $second = $this->withHeaders($headers)
            ->postJson('/api/v1/assistant/intake-drafts', $this->draftPayload())
            ->assertCreated();

        $this->assertSame($first->json('data.draft.id'), $second->json('data.draft.id'));
        $this->assertSame(1, DB::table('ai_intake_drafts')->count());
        $this->assertSame(1, DB::table('ai_intake_actions')->count());
    }

    public function test_assistant_can_validate_draft_payload_without_persisting_it(): void
    {
        $token = $this->bearer($this->createActiveAdmin(), ['assistant:write']);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/assistant/intake-drafts/validate', $this->draftPayload());

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.valid', true)
            ->assertJsonPath('data.action_count', 1)
            ->assertJsonPath('data.risk_summary.low', 1);

        $this->assertSame(0, DB::table('ai_intake_drafts')->count());
        $this->assertSame(0, DB::table('ai_intake_actions')->count());
    }

    public function test_admin_can_review_and_apply_low_risk_customer_draft(): void
    {
        $admin = $this->createActiveAdmin();
        $token = $this->bearer($admin, ['assistant:write']);
        $collection = CollectionRecord::query()->create([
            'name' => 'Assistant Apply Automation',
            'slug' => 'assistant-apply-automation',
            'description' => '',
            'status' => 'active',
            'sort_order' => 1,
        ]);

        $draftId = (int) $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/assistant/intake-drafts', $this->draftPayload($collection->id))
            ->assertCreated()
            ->json('data.draft.id');

        $this->actingAs($admin, 'admin')
            ->get(route('admin.assistant-intake-drafts.index'))
            ->assertOk()
            ->assertSee('AI 录入草稿箱')
            ->assertSee('Graham Automation Spain');

        $this->actingAs($admin, 'admin')
            ->get(route('admin.assistant-intake-drafts.show', ['draftId' => $draftId]))
            ->assertOk()
            ->assertSee('Spanish customer Graham')
            ->assertSee('创建客户');

        $this->actingAs($admin, 'admin')
            ->post(route('admin.assistant-intake-drafts.apply', ['draftId' => $draftId]))
            ->assertRedirect(route('admin.assistant-intake-drafts.show', ['draftId' => $draftId]));

        $customer = CrmCustomer::query()->where('company_name', 'Graham Automation Spain')->firstOrFail();
        $this->assertSame((int) $collection->id, (int) $customer->collection_id);
        $this->assertDatabaseHas('ai_intake_drafts', [
            'id' => $draftId,
            'status' => 'applied',
            'reviewed_by_admin_id' => (int) $admin->id,
        ]);
        $this->assertDatabaseHas('ai_intake_actions', [
            'draft_id' => $draftId,
            'status' => 'applied',
            'applied_target_type' => CrmCustomer::class,
            'applied_target_id' => (int) $customer->id,
        ]);
        if (Schema::hasTable('admin_activity_logs')) {
            $this->assertDatabaseHas('admin_activity_logs', [
                'admin_id' => (int) $admin->id,
                'action' => 'assistant-intake:apply',
                'target_type' => 'ai_intake_draft',
                'target_id' => $draftId,
            ]);
        }
    }

    public function test_apply_creates_knowledge_and_case_content_proposals_without_direct_material_writes(): void
    {
        $admin = $this->createActiveAdmin();
        $token = $this->bearer($admin, ['assistant:write']);
        $collection = CollectionRecord::query()->create([
            'name' => 'Assistant Proposal Automation',
            'slug' => 'assistant-proposal-automation',
            'description' => '',
            'status' => 'active',
            'sort_order' => 1,
        ]);

        $draftId = (int) $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/assistant/intake-drafts', $this->proposalPayload((int) $collection->id))
            ->assertCreated()
            ->json('data.draft.id');

        $this->actingAs($admin, 'admin')
            ->post(route('admin.assistant-intake-drafts.apply', ['draftId' => $draftId]))
            ->assertRedirect(route('admin.assistant-intake-drafts.show', ['draftId' => $draftId]));

        $this->assertSame(2, CrmContentProposal::query()->count());
        $this->assertDatabaseHas('crm_content_proposals', [
            'collection_id' => (int) $collection->id,
            'source_type' => 'ai_intake_draft',
            'source_id' => $draftId,
            'proposal_type' => 'faq_draft',
            'title' => 'How to troubleshoot SJ4060 nozzle clogging',
            'status' => 'pending',
        ]);
        $this->assertDatabaseHas('crm_content_proposals', [
            'collection_id' => (int) $collection->id,
            'source_type' => 'ai_intake_draft',
            'source_id' => $draftId,
            'proposal_type' => 'case_draft',
            'title' => 'SJ4060 Spanish customer troubleshooting story',
            'status' => 'pending',
        ]);
        $this->assertSame(0, KnowledgeBase::query()->count(), 'Proposal actions must not write knowledge bases directly.');
        $this->assertSame(0, CaseRecord::query()->count(), 'Proposal actions must not write case records directly.');
    }

    public function test_validate_reports_governance_warnings_for_missing_collection_duplicate_customer_and_low_confidence(): void
    {
        $admin = $this->createActiveAdmin();
        $token = $this->bearer($admin, ['assistant:write']);
        CrmCustomer::query()->create([
            'company_name' => 'Graham Automation Spain',
            'email' => 'graham@example.test',
            'country' => 'Spain',
            'status' => 'active',
        ]);

        $payload = $this->draftPayload();
        $payload['confidence'] = 0.44;
        $payload['actions'][0]['confidence'] = 0.39;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/assistant/intake-drafts/validate', $payload)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.valid', true);

        $warningCodes = collect($response->json('data.warnings'))->pluck('code')->all();
        $this->assertContains('collection_missing', $warningCodes);
        $this->assertContains('possible_duplicate_customer', $warningCodes);
        $this->assertContains('low_confidence_action', $warningCodes);
        $this->assertGreaterThanOrEqual(1, (int) $response->json('data.risk_summary.medium'));
    }

    private function draftPayload(?int $collectionId = null): array
    {
        return [
            'source' => 'codex',
            'source_reference' => 'local-thread:test',
            'collection_id' => $collectionId,
            'raw_input' => 'Spanish customer Graham asks about SJ4060 nozzle clogging and may need follow-up.',
            'normalized_summary' => 'Create a reviewable customer draft and follow-up action for Graham.',
            'detected_language' => 'en',
            'confidence' => 0.82,
            'actions' => [
                [
                    'action_type' => 'create',
                    'target_type' => 'customer',
                    'target_id' => null,
                    'payload' => [
                        'company_name' => 'Graham Automation Spain',
                        'country' => 'Spain',
                        'email' => 'graham@example.test',
                    ],
                    'relation' => [
                        'collection_id' => $collectionId,
                    ],
                    'diff' => [],
                    'confidence' => 0.78,
                    'risk_level' => 'low',
                ],
            ],
        ];
    }

    private function proposalPayload(int $collectionId): array
    {
        return [
            'source' => 'codex',
            'source_reference' => 'local-thread:proposal-test',
            'collection_id' => $collectionId,
            'raw_input' => 'Spanish customer resolved SJ4060 nozzle clogging after adjusting glue viscosity.',
            'normalized_summary' => 'Create FAQ and Case proposals for administrator review.',
            'detected_language' => 'en',
            'confidence' => 0.76,
            'actions' => [
                [
                    'action_type' => 'proposal',
                    'target_type' => 'knowledge_base',
                    'payload' => [
                        'proposal_type' => 'faq_draft',
                        'title' => 'How to troubleshoot SJ4060 nozzle clogging',
                        'content' => 'Draft FAQ: check glue viscosity, needle size, pressure, and cleaning interval.',
                    ],
                    'confidence' => 0.72,
                    'risk_level' => 'medium',
                ],
                [
                    'action_type' => 'proposal',
                    'target_type' => 'case',
                    'payload' => [
                        'proposal_type' => 'case_draft',
                        'title' => 'SJ4060 Spanish customer troubleshooting story',
                        'content' => 'Draft Case: a Spanish customer restored stable dispensing by tuning viscosity and cleaning workflow.',
                    ],
                    'confidence' => 0.71,
                    'risk_level' => 'medium',
                ],
            ],
        ];
    }
}
