<?php

namespace App\Support\GeoFlow;

use App\Models\Article;

final class ArticleWorkflow
{
    public static function articleSha256(Article $article): string
    {
        return hash('sha256', (string) ($article->title ?? '')."\0".(string) ($article->content ?? ''));
    }

    /**
     * Bind an explicit human approval to the exact title/body revision.
     * Non-human or revoked states remove any earlier approval binding.
     *
     * @return array<string,mixed>
     */
    public static function contextSnapshotForReviewStatus(Article $article, string $reviewStatus): array
    {
        $snapshot = is_array($article->context_snapshot) ? $article->context_snapshot : [];
        unset($snapshot['review_approval']);

        if ($reviewStatus === 'approved') {
            $snapshot['review_approval'] = [
                'article_sha256' => self::articleSha256($article),
                'approved_at' => now()->toDateTimeString(),
            ];
        }

        return $snapshot;
    }

    public static function hasCurrentHumanApproval(Article $article): bool
    {
        if ((string) ($article->review_status ?? '') !== 'approved') {
            return false;
        }

        $approvedHash = data_get($article->context_snapshot, 'review_approval.article_sha256');

        return is_string($approvedHash)
            && preg_match('/\A[a-f0-9]{64}\z/', $approvedHash) === 1
            && hash_equals($approvedHash, self::articleSha256($article));
    }

    public static function normalizeState(string $status, string $reviewStatus, ?string $publishedAt = null): array
    {
        $allowedStatuses = ['draft', 'published', 'private'];
        $allowedReviewStatuses = ['pending', 'approved', 'rejected', 'auto_approved'];

        if (! in_array($status, $allowedStatuses, true)) {
            $status = 'draft';
        }

        if (! in_array($reviewStatus, $allowedReviewStatuses, true)) {
            $reviewStatus = 'pending';
        }

        if (in_array($reviewStatus, ['pending', 'rejected'], true)) {
            $status = 'draft';
        }

        if ($status === 'published' && in_array($reviewStatus, ['pending', 'rejected'], true)) {
            $reviewStatus = 'approved';
        }

        if ($status !== 'published' && $reviewStatus === 'auto_approved') {
            $status = 'published';
        }

        if ($status === 'published' && $reviewStatus === 'pending') {
            $reviewStatus = 'approved';
        }

        if ($status === 'published') {
            $publishedAt = $publishedAt ?: date('Y-m-d H:i:s');
        } else {
            $publishedAt = null;
        }

        return [
            'status' => $status,
            'review_status' => $reviewStatus,
            'published_at' => $publishedAt,
        ];
    }

    public static function generateUniqueSlug(string $title, ?int $excludeArticleId = null): string
    {
        $slug = self::randomSlug(8);

        while (true) {
            try {
                $q = Article::withTrashed()->where('slug', $slug);
                if ($excludeArticleId !== null) {
                    $q->where('id', '!=', $excludeArticleId);
                }

                if (! $q->exists()) {
                    return $slug;
                }

                $slug = self::randomSlug(8);
            } catch (\Throwable) {
                return self::randomSlug(8);
            }
        }
    }

    private static function randomSlug(int $length): string
    {
        $characters = 'abcdefghijklmnopqrstuvwxyz0123456789';
        $slug = '';
        for ($i = 0; $i < $length; $i++) {
            $slug .= $characters[random_int(0, strlen($characters) - 1)];
        }

        return $slug;
    }
}
