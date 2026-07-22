<?php

namespace Tests\Feature;

use App\Jobs\ProcessArticleDistributionJob;
use App\Models\Admin;
use App\Models\Article;
use App\Models\ArticleDistribution;
use App\Models\Author;
use App\Models\Category;
use App\Models\DistributionChannel;
use App\Services\GeoFlow\ArticlePublicationBlockedException;
use App\Services\GeoFlow\DistributionOrchestrator;
use App\Services\GeoFlow\DistributionRetryPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ArticlePublicationGuardIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_direct_publish_cannot_bypass_pending_review(): void
    {
        Queue::fake();
        $article = $this->article('pending');

        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.articles.publish', ['articleId' => (int) $article->id]), [
                'distribution_channel_ids' => [999],
            ])
            ->assertRedirect()
            ->assertSessionHasErrors();

        $article->refresh();
        $this->assertSame('draft', $article->status);
        $this->assertSame('pending', $article->review_status);
        Queue::assertNothingPushed();
    }

    public function test_api_write_scope_can_create_draft_but_cannot_create_published_article(): void
    {
        $admin = $this->admin();
        $token = $admin->createToken('article-write-only', ['articles:write'])->plainTextToken;
        $category = Category::query()->create([
            'name' => 'API Guard Category',
            'slug' => 'api-guard-category',
        ]);
        $author = Author::query()->create(['name' => 'API Guard Author']);
        $payload = [
            'title' => 'API Guarded Article',
            'content' => 'Article body.',
            'category_id' => (int) $category->id,
            'author_id' => (int) $author->id,
            'status' => 'published',
            'review_status' => 'approved',
        ];

        $this->withToken($token)
            ->postJson('/api/v1/articles', $payload)
            ->assertForbidden()
            ->assertJsonPath('error.code', 'forbidden');

        $this->assertDatabaseMissing('articles', ['title' => 'API Guarded Article']);

        $payload['title'] = 'API Draft Article';
        $payload['status'] = 'draft';
        $payload['review_status'] = 'pending';
        $this->withToken($token)
            ->postJson('/api/v1/articles', $payload)
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft');
    }

    public function test_api_content_update_resets_an_approved_published_article_to_pending_draft(): void
    {
        $admin = $this->admin();
        $token = $admin->createToken('article-edit-reset', ['articles:write'])->plainTextToken;
        $article = $this->article('approved', 'pass');
        $snapshot = $article->context_snapshot;
        $snapshot['review_approval'] = [
            'article_sha256' => hash('sha256', $article->title."\0".$article->content),
        ];
        $article->forceFill([
            'status' => 'published',
            'published_at' => now(),
            'context_snapshot' => $snapshot,
        ])->save();

        $this->withToken($token)
            ->patchJson('/api/v1/articles/'.$article->id, [
                'content' => 'Materially changed article body.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.review_status', 'pending');

        $article->refresh();
        $this->assertSame('draft', $article->status);
        $this->assertSame('pending', $article->review_status);
        $this->assertNull($article->published_at);
        $this->assertNull(data_get($article->context_snapshot, 'review_approval'));
    }

    public function test_api_auto_approval_cannot_publish_a_pending_grounding_gate(): void
    {
        $admin = $this->admin();
        $token = $admin->createToken('publication-guard', ['articles:publish'])->plainTextToken;
        $article = $this->article('auto_approved', 'pending_review');

        $this->withToken($token)
            ->postJson('/api/v1/articles/'.$article->id.'/publish')
            ->assertStatus(409)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'article_not_publishable');

        $this->assertSame('draft', $article->fresh()->status);
    }

    public function test_api_review_cannot_auto_approve_a_pending_grounding_gate(): void
    {
        $admin = $this->admin();
        $token = $admin->createToken('publication-review-guard', ['articles:publish'])->plainTextToken;
        $article = $this->article('pending', 'pending_review');

        $this->withToken($token)
            ->postJson('/api/v1/articles/'.$article->id.'/review', [
                'review_status' => 'auto_approved',
                'review_note' => 'Automated review attempt.',
            ])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'article_not_publishable');

        $article->refresh();
        $this->assertSame('draft', $article->status);
        $this->assertSame('pending', $article->review_status);
    }

    public function test_admin_batch_status_cannot_publish_auto_approved_pending_grounding(): void
    {
        Queue::fake();
        $article = $this->article('auto_approved', 'pending_review');

        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.articles.batch.update-status'), [
                'article_ids' => [(string) $article->id],
                'new_status' => 'published',
            ])
            ->assertRedirect()
            ->assertSessionHasErrors();

        $this->assertSame('draft', $article->fresh()->status);
        Queue::assertNothingPushed();
    }

    public function test_revoked_approval_stops_queued_distribution_without_remote_call_or_retry(): void
    {
        Queue::fake();
        Http::fake();
        $article = $this->article('pending', 'pass');
        $article->forceFill([
            'status' => 'published',
            'published_at' => now(),
        ])->save();
        $channel = DistributionChannel::query()->create([
            'name' => 'Guarded remote channel',
            'domain' => 'example.com',
            'endpoint_url' => 'https://example.com',
            'status' => 'active',
        ]);
        $distribution = ArticleDistribution::query()->create([
            'article_id' => (int) $article->id,
            'distribution_channel_id' => (int) $channel->id,
            'action' => 'publish',
            'status' => 'queued',
            'idempotency_key' => 'guarded-'.$article->id,
        ]);

        app(ProcessArticleDistributionJob::class, ['distributionId' => (int) $distribution->id])
            ->handle(app(DistributionOrchestrator::class), app(DistributionRetryPolicy::class));

        $distribution->refresh();
        $this->assertSame('failed', $distribution->status);
        $this->assertSame('review_required', $distribution->last_error_message);
        $this->assertNull($distribution->next_retry_at);
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_distribution_rechecks_fresh_article_state_instead_of_loaded_relation(): void
    {
        Http::fake();
        $article = $this->article('approved', 'pass');
        $article->forceFill(['status' => 'published', 'published_at' => now()])->save();
        $channel = DistributionChannel::query()->create([
            'name' => 'Fresh state channel',
            'domain' => 'fresh.example.com',
            'endpoint_url' => 'https://fresh.example.com',
            'status' => 'active',
        ]);
        $distribution = ArticleDistribution::query()->create([
            'article_id' => (int) $article->id,
            'distribution_channel_id' => (int) $channel->id,
            'action' => 'publish',
            'status' => 'queued',
            'idempotency_key' => 'fresh-state-'.$article->id,
        ]);
        $distribution->load(['article', 'channel']);
        Article::query()->whereKey((int) $article->id)->update(['review_status' => 'pending']);

        try {
            app(DistributionOrchestrator::class)->process($distribution);
            $this->fail('Expected fresh approval state to block distribution.');
        } catch (ArticlePublicationBlockedException $exception) {
            $this->assertSame('review_required', $exception->reasonCode);
        }

        Http::assertNothingSent();
    }

    public function test_channel_refresh_skips_articles_that_are_no_longer_publishable(): void
    {
        Queue::fake();
        $article = $this->article('pending', 'pass');
        $article->forceFill(['status' => 'published', 'published_at' => now()])->save();
        $channel = DistributionChannel::query()->create([
            'name' => 'Refresh guarded channel',
            'domain' => 'refresh.example.com',
            'endpoint_url' => 'https://refresh.example.com',
            'status' => 'active',
        ]);
        $distribution = ArticleDistribution::query()->create([
            'article_id' => (int) $article->id,
            'distribution_channel_id' => (int) $channel->id,
            'action' => 'publish',
            'status' => 'synced',
            'idempotency_key' => 'refresh-guarded-'.$article->id,
        ]);

        $queued = app(DistributionOrchestrator::class)->enqueueChannelContentRefresh($channel);

        $this->assertSame(0, $queued);
        $this->assertSame('synced', $distribution->fresh()->status);
        Queue::assertNothingPushed();
    }

    public function test_manual_retry_does_not_requeue_after_approval_is_revoked(): void
    {
        Queue::fake();
        $article = $this->article('pending', 'pass');
        $channel = DistributionChannel::query()->create([
            'name' => 'Retry guarded channel',
            'domain' => 'retry.example.com',
            'endpoint_url' => 'https://retry.example.com',
            'status' => 'active',
        ]);
        $distribution = ArticleDistribution::query()->create([
            'article_id' => (int) $article->id,
            'distribution_channel_id' => (int) $channel->id,
            'action' => 'publish',
            'status' => 'failed',
            'last_error_message' => 'previous_failure',
            'idempotency_key' => 'retry-guarded-'.$article->id,
        ]);

        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.distribution.retry', ['distributionId' => (int) $distribution->id]))
            ->assertRedirect()
            ->assertSessionHasErrors();

        $distribution->refresh();
        $this->assertSame('failed', $distribution->status);
        $this->assertSame('previous_failure', $distribution->last_error_message);
        Queue::assertNothingPushed();
    }

    private function article(string $reviewStatus, ?string $groundingOutcome = null): Article
    {
        $category = Category::query()->create([
            'name' => 'Publication Guard Category '.uniqid(),
            'slug' => 'publication-guard-category-'.uniqid(),
        ]);
        $author = Author::query()->create(['name' => 'Publication Guard Author '.uniqid()]);

        return Article::query()->create([
            'title' => 'Publication Guard Article '.uniqid(),
            'slug' => 'publication-guard-article-'.uniqid(),
            'content' => 'Grounded article content.',
            'excerpt' => 'Grounded article content.',
            'category_id' => (int) $category->id,
            'author_id' => (int) $author->id,
            'status' => 'draft',
            'review_status' => $reviewStatus,
            'context_snapshot' => $groundingOutcome === null
                ? null
                : ['grounding_gate' => ['outcome' => $groundingOutcome]],
        ]);
    }

    private function admin(): Admin
    {
        return Admin::query()->create([
            'username' => 'publication_guard_'.uniqid(),
            'password' => 'secret-123',
            'email' => uniqid().'@example.com',
            'display_name' => 'Publication Guard Admin',
            'role' => 'super_admin',
            'status' => 'active',
        ]);
    }
}
