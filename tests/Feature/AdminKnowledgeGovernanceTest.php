<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\CollectionRecord;
use App\Models\KnowledgeBase;
use App\Models\KnowledgeGovernanceProposal;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AdminKnowledgeGovernanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    public function test_admin_can_create_duplicate_archive_proposal_without_changing_knowledge_bases(): void
    {
        $admin = $this->admin();
        [$primary, $duplicate] = $this->duplicateKnowledgeBases();
        $payload = $this->duplicatePayload($primary, $duplicate);

        $response = $this->actingAs($admin, 'admin')
            ->post(route('admin.knowledge-governance-proposals.store'), [
                'proposal_type' => KnowledgeGovernanceProposal::TYPE_DUPLICATE_ARCHIVE,
                'issue_payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            ]);

        $proposal = KnowledgeGovernanceProposal::query()->firstOrFail();
        $response->assertRedirect(route('admin.knowledge-governance-proposals.show', ['proposalId' => (int) $proposal->id]));
        $this->actingAs($admin, 'admin')
            ->get(route('admin.knowledge-governance-proposals.show', ['proposalId' => (int) $proposal->id]))
            ->assertOk()
            ->assertSee(__('admin.knowledge_governance_proposals.detail_title', ['id' => (int) $proposal->id]))
            ->assertSee(__('admin.knowledge_governance_proposals.apply_archive'))
            ->assertSee('Primary Product Manual')
            ->assertSee('Duplicate Product Manual');
        $this->assertSame(KnowledgeGovernanceProposal::STATUS_PENDING, (string) $proposal->status);
        $this->assertSame((int) $primary->id, (int) $proposal->primary_knowledge_base_id);
        $this->assertSame([(int) $duplicate->id], array_map('intval', (array) $proposal->related_knowledge_base_ids));
        $this->assertSame('active', (string) $primary->refresh()->status);
        $this->assertSame('active', (string) $duplicate->refresh()->status);
        $this->assertSame('Duplicated product specification content.', (string) $duplicate->content);
    }

    public function test_duplicate_archive_requires_confirmation_before_applying(): void
    {
        $admin = $this->admin('knowledge_governance_confirm_admin');
        [$primary, $duplicate] = $this->duplicateKnowledgeBases();
        $proposal = $this->proposalFor($primary, $duplicate, $admin);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.knowledge-governance-proposals.apply', ['proposalId' => (int) $proposal->id]), [
                'apply_confirmation' => 'wrong',
            ])
            ->assertSessionHasErrors();

        $this->assertSame('active', (string) $duplicate->refresh()->status);
        $this->assertSame(KnowledgeGovernanceProposal::STATUS_PENDING, (string) $proposal->refresh()->status);
    }

    public function test_admin_can_apply_and_rollback_duplicate_archive_proposal(): void
    {
        $admin = $this->admin('knowledge_governance_apply_admin');
        [$primary, $duplicate] = $this->duplicateKnowledgeBases();
        $proposal = $this->proposalFor($primary, $duplicate, $admin);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.knowledge-governance-proposals.apply', ['proposalId' => (int) $proposal->id]), [
                'apply_confirmation' => __('admin.knowledge_governance_proposals.apply_confirmation_text'),
                'admin_note' => 'Duplicate source archived.',
            ])
            ->assertRedirect(route('admin.knowledge-governance-proposals.show', ['proposalId' => (int) $proposal->id]));

        $this->assertSame('active', (string) $primary->refresh()->status);
        $this->assertSame('inactive', (string) $duplicate->refresh()->status);
        $this->assertSame('Duplicated product specification content.', (string) $duplicate->content);
        $this->assertSame(KnowledgeGovernanceProposal::STATUS_APPLIED, (string) $proposal->refresh()->status);
        if (Schema::hasTable('admin_activity_logs')) {
            $this->assertDatabaseHas('admin_activity_logs', [
                'action' => 'knowledge-governance-proposal:apply-archive',
                'target_type' => KnowledgeGovernanceProposal::class,
                'target_id' => (int) $proposal->id,
            ]);
        }

        $this->actingAs($admin, 'admin')
            ->post(route('admin.knowledge-governance-proposals.rollback', ['proposalId' => (int) $proposal->id]), [
                'admin_note' => 'Restore duplicate source for review.',
            ])
            ->assertRedirect(route('admin.knowledge-governance-proposals.show', ['proposalId' => (int) $proposal->id]));

        $this->assertSame('active', (string) $duplicate->refresh()->status);
        $this->assertSame(KnowledgeGovernanceProposal::STATUS_ROLLED_BACK, (string) $proposal->refresh()->status);
    }

    public function test_conflict_review_proposal_records_review_without_changing_content(): void
    {
        $admin = $this->admin('knowledge_governance_conflict_admin');
        [$primary, $duplicate] = $this->duplicateKnowledgeBases();
        $payload = [
            'confidence' => 91,
            'left' => ['id' => (int) $primary->id, 'name' => (string) $primary->name],
            'right' => ['id' => (int) $duplicate->id, 'name' => (string) $duplicate->name],
            'conflicts' => [
                ['label' => 'voltage', 'left' => ['220v'], 'right' => ['110v']],
            ],
        ];

        $this->actingAs($admin, 'admin')
            ->post(route('admin.knowledge-governance-proposals.store'), [
                'proposal_type' => KnowledgeGovernanceProposal::TYPE_CONFLICT_REVIEW,
                'issue_payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            ])
            ->assertRedirect();

        $proposal = KnowledgeGovernanceProposal::query()->firstOrFail();
        $this->actingAs($admin, 'admin')
            ->post(route('admin.knowledge-governance-proposals.apply', ['proposalId' => (int) $proposal->id]), [
                'admin_note' => 'Checked source priority manually.',
            ])
            ->assertRedirect(route('admin.knowledge-governance-proposals.show', ['proposalId' => (int) $proposal->id]));

        $this->assertSame(KnowledgeGovernanceProposal::STATUS_APPROVED, (string) $proposal->refresh()->status);
        $this->assertSame('Duplicated product specification content.', (string) $duplicate->refresh()->content);
        $this->assertSame('active', (string) $duplicate->status);
    }

    private function admin(string $username = 'knowledge_governance_admin'): Admin
    {
        return Admin::query()->create([
            'username' => $username,
            'password' => 'secret-123',
            'email' => $username.'@example.com',
            'display_name' => 'Knowledge Governance Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);
    }

    /**
     * @return array{0:KnowledgeBase,1:KnowledgeBase}
     */
    private function duplicateKnowledgeBases(): array
    {
        $collection = CollectionRecord::query()->create([
            'name' => 'Governance Collection',
            'slug' => 'governance-collection',
            'status' => 'active',
            'sort_order' => 1,
        ]);

        $primary = KnowledgeBase::query()->create([
            'collection_id' => (int) $collection->id,
            'name' => 'Primary Product Manual',
            'description' => '',
            'summary' => '',
            'source_url' => 'https://example.com/manual-primary',
            'content' => 'Primary product specification content.',
            'character_count' => 38,
            'word_count' => 38,
            'file_type' => 'markdown',
            'knowledge_type' => 'product_manual',
            'knowledge_role' => 'primary_source',
            'importance' => 5,
            'status' => 'active',
        ]);
        $duplicate = KnowledgeBase::query()->create([
            'collection_id' => (int) $collection->id,
            'name' => 'Duplicate Product Manual',
            'description' => '',
            'summary' => '',
            'source_url' => 'https://example.com/manual-duplicate',
            'content' => 'Duplicated product specification content.',
            'character_count' => 40,
            'word_count' => 40,
            'file_type' => 'markdown',
            'knowledge_type' => 'product_manual',
            'knowledge_role' => 'supporting_context',
            'importance' => 3,
            'status' => 'active',
        ]);

        return [$primary, $duplicate];
    }

    /**
     * @return array<string,mixed>
     */
    private function duplicatePayload(KnowledgeBase $primary, KnowledgeBase $duplicate): array
    {
        return [
            'type' => 'same_title',
            'confidence' => 95,
            'items' => [
                ['id' => (int) $primary->id, 'name' => (string) $primary->name],
                ['id' => (int) $duplicate->id, 'name' => (string) $duplicate->name],
            ],
        ];
    }

    private function proposalFor(KnowledgeBase $primary, KnowledgeBase $duplicate, Admin $admin): KnowledgeGovernanceProposal
    {
        $this->actingAs($admin, 'admin')
            ->post(route('admin.knowledge-governance-proposals.store'), [
                'proposal_type' => KnowledgeGovernanceProposal::TYPE_DUPLICATE_ARCHIVE,
                'issue_payload' => json_encode($this->duplicatePayload($primary, $duplicate), JSON_UNESCAPED_UNICODE),
            ])
            ->assertRedirect();

        return KnowledgeGovernanceProposal::query()->firstOrFail();
    }
}
