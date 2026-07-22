<?php

namespace Tests\Unit;

use App\Models\Article;
use App\Services\GeoFlow\ArticlePublicationBlockedException;
use App\Services\GeoFlow\ArticlePublicationGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ArticlePublicationGuardTest extends TestCase
{
    #[DataProvider('allowedStates')]
    public function test_allows_publishable_review_and_grounding_states(
        string $reviewStatus,
        ?string $groundingOutcome
    ): void {
        $article = $this->article($reviewStatus, $groundingOutcome);

        app(ArticlePublicationGuard::class)->assertCanPublish($article);

        $this->addToAssertionCount(1);
    }

    public static function allowedStates(): array
    {
        return [
            'legacy manual approved' => ['approved', null],
            'legacy manual auto-approved' => ['auto_approved', null],
            'grounding pass auto-approved' => ['auto_approved', 'pass'],
            'pending grounding explicitly approved by human' => ['approved', 'pending_review'],
            'blocked grounding explicitly approved by human' => ['approved', 'blocked'],
        ];
    }

    #[DataProvider('blockedStates')]
    public function test_blocks_unreviewed_or_non_human_resolved_states(
        string $reviewStatus,
        ?string $groundingOutcome,
        string $expectedReason
    ): void {
        $article = $this->article($reviewStatus, $groundingOutcome);

        try {
            app(ArticlePublicationGuard::class)->assertCanPublish($article);
            $this->fail('Expected publication guard to block the article.');
        } catch (ArticlePublicationBlockedException $exception) {
            $this->assertSame($expectedReason, $exception->reasonCode);
        }
    }

    public static function blockedStates(): array
    {
        return [
            'pending review' => ['pending', null, 'review_required'],
            'rejected review' => ['rejected', null, 'review_required'],
            'pending grounding cannot auto-approve' => ['auto_approved', 'pending_review', 'grounding_review_required'],
            'blocked grounding cannot auto-approve' => ['auto_approved', 'blocked', 'grounding_review_required'],
        ];
    }

    public function test_limited_evidence_cannot_be_auto_approved_even_when_gate_says_pass(): void
    {
        $article = $this->article('auto_approved', 'pass');
        $snapshot = $article->context_snapshot;
        $snapshot['evidence_sufficiency'] = 'limited';
        $article->forceFill(['context_snapshot' => $snapshot]);

        try {
            app(ArticlePublicationGuard::class)->assertCanPublish($article);
            $this->fail('Limited evidence must require explicit human approval.');
        } catch (ArticlePublicationBlockedException $exception) {
            $this->assertSame('grounding_review_required', $exception->reasonCode);
        }
    }

    public function test_changed_content_hash_cannot_remain_auto_approved(): void
    {
        $article = $this->article('auto_approved', 'pass');
        $article->forceFill(['content' => 'Changed body after approval.']);
        $snapshot = $article->context_snapshot;
        $snapshot['grounding_gate']['content_sha256'] = hash('sha256', 'Original approved body.');
        $article->forceFill(['context_snapshot' => $snapshot]);

        try {
            app(ArticlePublicationGuard::class)->assertCanPublish($article);
            $this->fail('Changed content must not keep automatic approval.');
        } catch (ArticlePublicationBlockedException $exception) {
            $this->assertSame('content_review_required', $exception->reasonCode);
        }
    }

    public function test_changed_content_hash_cannot_reuse_stale_human_approval(): void
    {
        $article = $this->article('approved', 'pending_review');
        $snapshot = $article->context_snapshot;
        $snapshot['review_approval'] = [
            'article_sha256' => hash('sha256', "Governed title\0Original approved body."),
        ];
        $article->forceFill([
            'title' => 'Governed title',
            'content' => 'Changed body after approval.',
            'context_snapshot' => $snapshot,
        ]);

        try {
            app(ArticlePublicationGuard::class)->assertCanPublish($article);
            $this->fail('Changed content must not reuse an earlier human approval.');
        } catch (ArticlePublicationBlockedException $exception) {
            $this->assertSame('content_review_required', $exception->reasonCode);
        }
    }

    public function test_insufficient_evidence_cannot_be_published_by_changing_review_status(): void
    {
        $article = $this->article('approved', 'pending_review');
        $snapshot = $article->context_snapshot;
        $snapshot['evidence_sufficiency'] = 'insufficient';
        $snapshot['review_approval'] = [
            'article_sha256' => hash('sha256', "\0"),
        ];
        $article->forceFill(['context_snapshot' => $snapshot]);

        try {
            app(ArticlePublicationGuard::class)->assertCanPublish($article);
            $this->fail('Insufficient evidence must remain a terminal publication block.');
        } catch (ArticlePublicationBlockedException $exception) {
            $this->assertSame('insufficient_evidence', $exception->reasonCode);
        }
    }

    private function article(string $reviewStatus, ?string $groundingOutcome): Article
    {
        $article = new Article;
        $snapshot = $groundingOutcome === null
            ? null
            : ['grounding_gate' => ['outcome' => $groundingOutcome]];
        if ($groundingOutcome !== null && $reviewStatus === 'approved') {
            $snapshot['review_approval'] = [
                'article_sha256' => hash('sha256', "\0"),
            ];
        }
        $article->forceFill([
            'review_status' => $reviewStatus,
            'context_snapshot' => $snapshot,
        ]);

        return $article;
    }
}
