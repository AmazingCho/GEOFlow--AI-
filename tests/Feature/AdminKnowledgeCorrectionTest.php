<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Models\KnowledgeBase;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeCorrection;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AdminKnowledgeCorrectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    public function test_admin_can_create_knowledge_correction_from_knowledge_base(): void
    {
        $admin = $this->admin();
        [$knowledgeBase, $chunk] = $this->knowledgeBaseWithChunk();

        $response = $this->actingAs($admin, 'admin')->post(route('admin.knowledge-corrections.store'), [
            'source_type' => 'knowledge_base',
            'knowledge_base_id' => (int) $knowledgeBase->id,
            'error_description' => 'The voltage description is wrong.',
            'ai_model_id' => 0,
        ]);

        $correction = KnowledgeCorrection::query()->firstOrFail();
        $response->assertRedirect(route('admin.knowledge-corrections.show', ['correctionId' => (int) $correction->id]));
        $this->assertSame((int) $knowledgeBase->id, (int) $correction->knowledge_base_id);
        $this->assertSame((int) $chunk->id, (int) $correction->knowledge_chunk_id);
        $this->assertSame(KnowledgeCorrection::STATUS_PENDING, (string) $correction->status);
        $this->assertFalse((bool) $correction->confirmed_error);
        if (Schema::hasTable('admin_activity_logs')) {
            $this->assertDatabaseHas('admin_activity_logs', [
                'action' => 'admin.knowledge-corrections.store:submit',
                'target_type' => '',
            ]);
        }
    }

    public function test_admin_can_apply_and_rollback_knowledge_correction(): void
    {
        $admin = $this->admin();
        [$knowledgeBase, $chunk] = $this->knowledgeBaseWithChunk();
        $suggested = 'SJ4060 supports 110V/220V optional power input.';
        $correction = KnowledgeCorrection::query()->create([
            'knowledge_base_id' => (int) $knowledgeBase->id,
            'knowledge_chunk_id' => (int) $chunk->id,
            'reported_by_admin_id' => (int) $admin->id,
            'status' => KnowledgeCorrection::STATUS_APPROVED,
            'error_description' => 'Voltage should mention 110V/220V optional.',
            'retrieved_context' => [],
            'ai_result' => [
                'confirmed_error' => true,
                'error_type' => 'factual',
                'suggested_content' => $suggested,
            ],
            'confirmed_error' => true,
            'error_type' => 'factual',
            'suggested_content' => $suggested,
            'reasoning' => 'The source table includes optional voltage.',
            'confidence' => 0.92,
        ]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.knowledge-corrections.apply', ['correctionId' => (int) $correction->id]), [
                'review_note' => 'Confirmed from product manual.',
            ])
            ->assertRedirect(route('admin.knowledge-corrections.show', ['correctionId' => (int) $correction->id]));

        $this->assertDatabaseHas('knowledge_corrections', [
            'id' => (int) $correction->id,
            'status' => KnowledgeCorrection::STATUS_APPLIED,
        ]);
        $this->assertDatabaseHas('knowledge_chunk_versions', [
            'knowledge_correction_id' => (int) $correction->id,
            'old_content' => (string) $chunk->content,
            'new_content' => $suggested,
        ]);
        $this->assertSame($suggested, (string) $chunk->refresh()->content);
        $this->assertStringContainsString($suggested, (string) $knowledgeBase->refresh()->content);
        $this->assertNotSame('', (string) $chunk->refresh()->embedding_json);

        $version = $correction->versions()->firstOrFail();
        $this->actingAs($admin, 'admin')
            ->post(route('admin.knowledge-corrections.versions.rollback', [
                'correctionId' => (int) $correction->id,
                'versionId' => (int) $version->id,
            ]))
            ->assertRedirect(route('admin.knowledge-corrections.show', ['correctionId' => (int) $correction->id]));

        $this->assertSame('SJ4060 supports 220V power input.', (string) $chunk->refresh()->content);
        $this->assertStringContainsString('SJ4060 supports 220V power input.', (string) $knowledgeBase->refresh()->content);
        $this->assertSame(2, $correction->versions()->count());
    }

    public function test_admin_can_create_knowledge_correction_from_article_sources(): void
    {
        $admin = $this->admin();
        [$knowledgeBase] = $this->knowledgeBaseWithChunk();
        $category = Category::query()->create(['name' => 'News', 'slug' => 'news']);
        $author = Author::query()->create(['name' => 'Author']);
        $article = Article::query()->create([
            'title' => 'SJ4060 Guide',
            'slug' => 'sj4060-guide',
            'content' => 'The article says SJ4060 is only 220V.',
            'category_id' => (int) $category->id,
            'author_id' => (int) $author->id,
            'status' => 'draft',
            'review_status' => 'pending',
            'selected_collection_id' => 0,
            'used_knowledge_base_ids' => [(int) $knowledgeBase->id],
        ]);

        $response = $this->actingAs($admin, 'admin')->post(route('admin.knowledge-corrections.store'), [
            'source_type' => 'article',
            'article_id' => (int) $article->id,
            'selected_article_text' => 'SJ4060 is only 220V.',
            'error_description' => 'This voltage line may be incomplete.',
            'ai_model_id' => 0,
        ]);

        $correction = KnowledgeCorrection::query()->firstOrFail();
        $response->assertRedirect(route('admin.knowledge-corrections.show', ['correctionId' => (int) $correction->id]));
        $this->assertSame((int) $article->id, (int) $correction->article_id);
        $this->assertSame((int) $knowledgeBase->id, (int) $correction->knowledge_base_id);
    }

    public function test_knowledge_and_article_pages_show_correction_assistant(): void
    {
        $admin = $this->admin();
        [$knowledgeBase] = $this->knowledgeBaseWithChunk();
        $category = Category::query()->create(['name' => 'News', 'slug' => 'news']);
        $author = Author::query()->create(['name' => 'Author']);
        $article = Article::query()->create([
            'title' => 'SJ4060 Guide',
            'slug' => 'sj4060-guide',
            'content' => 'Draft body',
            'category_id' => (int) $category->id,
            'author_id' => (int) $author->id,
            'status' => 'draft',
            'review_status' => 'pending',
            'used_knowledge_base_ids' => [(int) $knowledgeBase->id],
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.knowledge-bases.detail', ['knowledgeBaseId' => (int) $knowledgeBase->id]))
            ->assertOk()
            ->assertSee(__('admin.knowledge_corrections.assistant.knowledge_title'));

        $this->actingAs($admin, 'admin')
            ->get(route('admin.articles.edit', ['articleId' => (int) $article->id]))
            ->assertOk()
            ->assertSee(__('admin.knowledge_corrections.assistant.article_title'));
    }

    private function admin(): Admin
    {
        return Admin::query()->create([
            'username' => 'knowledge_correction_admin',
            'password' => 'secret-123',
            'email' => 'knowledge-correction@example.com',
            'display_name' => 'Knowledge Correction Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);
    }

    /**
     * @return array{0:KnowledgeBase,1:KnowledgeChunk}
     */
    private function knowledgeBaseWithChunk(): array
    {
        $knowledgeBase = KnowledgeBase::query()->create([
            'name' => 'SJ4060 Manual',
            'description' => 'Manual source',
            'content' => "Intro\n\nSJ4060 supports 220V power input.\n\nEnd",
            'character_count' => 47,
            'file_type' => 'markdown',
            'knowledge_type' => 'product_manual',
            'knowledge_role' => 'primary_source',
            'importance' => 5,
            'status' => 'active',
        ]);
        $chunk = KnowledgeChunk::query()->create([
            'knowledge_base_id' => (int) $knowledgeBase->id,
            'chunk_index' => 0,
            'content' => 'SJ4060 supports 220V power input.',
            'content_hash' => hash('sha256', 'SJ4060 supports 220V power input.'),
            'token_count' => 6,
            'embedding_json' => '[]',
            'embedding_dimensions' => 0,
            'embedding_provider' => '',
        ]);

        return [$knowledgeBase, $chunk];
    }
}
