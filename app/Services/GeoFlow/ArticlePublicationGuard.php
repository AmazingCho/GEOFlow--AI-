<?php

namespace App\Services\GeoFlow;

use App\Models\Article;
use App\Support\GeoFlow\ArticleWorkflow;

final class ArticlePublicationGuard
{
    public function assertCanPublish(Article $article): void
    {
        $reviewStatus = (string) ($article->review_status ?? 'pending');
        if (! in_array($reviewStatus, ['approved', 'auto_approved'], true)) {
            throw new ArticlePublicationBlockedException(
                'review_required',
                '文章尚未通过审核，不能发布或分发。'
            );
        }

        $groundingOutcome = data_get($article->context_snapshot, 'grounding_gate.outcome');
        $evidenceSufficiency = data_get($article->context_snapshot, 'evidence_sufficiency')
            ?? data_get($article->context_snapshot, 'claim_provenance.evidence_sufficiency');
        $hasCurrentHumanApproval = ArticleWorkflow::hasCurrentHumanApproval($article);
        $boundApprovalHash = data_get($article->context_snapshot, 'review_approval.article_sha256');

        if ($evidenceSufficiency === 'insufficient') {
            throw new ArticlePublicationBlockedException(
                'insufficient_evidence',
                '文章证据不足，不能通过修改审核状态发布；请补充资料后重新生成。'
            );
        }

        if (is_string($boundApprovalHash)
            && preg_match('/\A[a-f0-9]{64}\z/', $boundApprovalHash) === 1
            && ! $hasCurrentHumanApproval) {
            throw new ArticlePublicationBlockedException(
                'content_review_required',
                '文章在人工审核后发生变化，必须重新审核。'
            );
        }

        if ($evidenceSufficiency === 'limited' && ! $hasCurrentHumanApproval) {
            throw new ArticlePublicationBlockedException(
                'grounding_review_required',
                '文章证据有限，必须对当前正文进行人工审核后才能发布。'
            );
        }

        $approvedContentHash = data_get($article->context_snapshot, 'grounding_gate.content_sha256');
        if (is_string($approvedContentHash)
            && preg_match('/\A[a-f0-9]{64}\z/', $approvedContentHash) === 1
            && ! hash_equals($approvedContentHash, hash('sha256', (string) ($article->content ?? '')))
            && ! $hasCurrentHumanApproval) {
            throw new ArticlePublicationBlockedException(
                'content_review_required',
                '文章正文在自动审核后发生变化，必须重新人工审核。'
            );
        }

        if (
            in_array($groundingOutcome, ['pending_review', 'blocked'], true)
            && ! $hasCurrentHumanApproval
        ) {
            throw new ArticlePublicationBlockedException(
                'grounding_review_required',
                '文章存在待人工确认的事实依据问题，必须人工审核通过后才能发布。'
            );
        }
    }
}
