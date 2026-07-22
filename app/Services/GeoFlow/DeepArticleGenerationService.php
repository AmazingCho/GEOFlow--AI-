<?php

namespace App\Services\GeoFlow;

use App\Models\AiModel;
use App\Models\Task;
use App\Support\GeoFlow\ArticleGenerationStage;
use InvalidArgumentException;
use RuntimeException;

class DeepArticleGenerationService
{
    public const PROTOCOL_VERSION = 'deep-v2.4-structured-plan-1';

    private const MAX_CALLS = 6;

    public function __construct(
        private readonly ArticleModelCallService $modelCallService,
        private readonly ArticleDeepOutputValidator $outputValidator,
        private readonly ArticleEvidencePackage $articleEvidencePackage,
        private readonly ArticleGroundingGate $groundingGate,
        private readonly ArticleDeepPromptBuilder $promptBuilder
    ) {}

    /**
     * @return array{
     *   content:string,
     *   model:AiModel,
     *   attempts:list<array<string,mixed>>,
     *   stages:list<array<string,mixed>>,
     *   review:array<string,mixed>,
     *   requires_manual_review:bool,
     *   evidence_sha256:string,
     *   claim_ledger:list<array<string,mixed>>,
     *   claim_coverage_status:string,
     *   evidence_sufficiency:string,
     *   call_count:int
     * }
     */
    public function generate(
        Task $task,
        string $title,
        string $keyword,
        string $writingBrief,
        string $knowledgeContext,
        string $targetLanguage,
        ?array $evidencePackage = null,
        ?string $intentKey = null,
        ?string $styleBrief = null
    ): array {
        try {
            $allowedEvidenceIds = $this->articleEvidencePackage->assertGenerationReady($evidencePackage ?? []);
        } catch (InvalidArgumentException) {
            throw new RuntimeException('深度生成缺少有效的结构化证据包，已在调用模型前停止');
        }
        try {
            $allowedEvidenceIds = $this->articleEvidencePackage->assertGenerationContextSafe(
                $knowledgeContext,
                $evidencePackage ?? []
            );
        } catch (InvalidArgumentException) {
            throw new RuntimeException('深度生成缺少有效的安全证据上下文，已在调用模型前停止');
        }
        $evidenceHash = hash('sha256', $knowledgeContext);
        $stages = [];
        $attempts = [];
        $callCount = 0;
        $planResult = $this->call(
            $task,
            new ArticleModelCallRequest(
                ArticleGenerationStage::Plan,
                $this->promptBuilder->plan($title, $keyword, $knowledgeContext, $targetLanguage, $allowedEvidenceIds, $intentKey),
                false,
                2048
            ),
            $callCount
        );
        try {
            $plan = $this->outputValidator->validatePlan(
                $planResult['structured'] ?? (string) $planResult['content'],
                $allowedEvidenceIds
            );
            $this->rememberStage($stages, $attempts, 'deep_plan', $planResult, [
                'evidence_sha256' => $evidenceHash,
                'evidence_sufficiency' => $plan['evidence_sufficiency'],
                'section_count' => count($plan['supported_sections']),
                'verification_item_count' => count($plan['verification_items']),
            ]);
        } catch (InvalidArgumentException $exception) {
            $violations = $this->validationViolations($exception);
            $this->rememberStage($stages, $attempts, 'deep_plan', $planResult, [
                'evidence_sha256' => $evidenceHash,
                'reason' => 'invalid_plan_contract',
                'violation_count' => count($violations),
                'violation_codes' => array_values(array_unique(array_column($violations, 'code'))),
            ], 'failed');
            $planRepairResult = $this->call(
                $task,
                new ArticleModelCallRequest(
                    ArticleGenerationStage::PlanRepair,
                    $this->promptBuilder->planRepair(
                        $title,
                        $keyword,
                        $knowledgeContext,
                        $targetLanguage,
                        (string) $planResult['content'],
                        $violations,
                        $allowedEvidenceIds,
                        $intentKey
                    ),
                    false,
                    2048
                ),
                $callCount
            );
            try {
                $plan = $this->outputValidator->validatePlan(
                    $planRepairResult['structured'] ?? (string) $planRepairResult['content'],
                    $allowedEvidenceIds
                );
                $this->rememberStage($stages, $attempts, 'deep_plan_repair', $planRepairResult, [
                    'evidence_sha256' => $evidenceHash,
                    'evidence_sufficiency' => $plan['evidence_sufficiency'],
                    'section_count' => count($plan['supported_sections']),
                    'verification_item_count' => count($plan['verification_items']),
                ]);
            } catch (InvalidArgumentException $repairException) {
                $repairViolations = $this->validationViolations($repairException);
                $this->rememberStage($stages, $attempts, 'deep_plan_repair', $planRepairResult, [
                    'evidence_sha256' => $evidenceHash,
                    'reason' => 'plan_repair_exhausted',
                    'violation_count' => count($repairViolations),
                    'violation_codes' => array_values(array_unique(array_column($repairViolations, 'code'))),
                ], 'failed');

                throw new ArticleGenerationProtocolException(
                    ArticleGenerationStage::PlanRepair,
                    self::PROTOCOL_VERSION,
                    $repairViolations,
                    $attempts,
                    $stages
                );
            }
        }

        $this->assertPlanCanDraft($plan);

        $draftResult = $this->call(
            $task,
            new ArticleModelCallRequest(
                ArticleGenerationStage::Draft,
                $this->promptBuilder->draft($title, $keyword, $writingBrief, $knowledgeContext, $targetLanguage, $plan, $allowedEvidenceIds),
                true
            ),
            $callCount
        );
        $content = trim((string) $draftResult['content']);
        $claimAnalysis = $this->articleEvidencePackage->validateAndStripMarkers($content, $evidencePackage);
        $claimAnalysis['evidence_sufficiency'] = $plan['evidence_sufficiency'];
        $groundingGate = $this->groundingGate->evaluate($claimAnalysis['content'], $evidencePackage, $claimAnalysis);
        $this->assertGroundingGateAllowsDeepDraft($groundingGate);
        /** @var AiModel $articleModel */
        $articleModel = $draftResult['model'];
        $this->rememberStage($stages, $attempts, 'deep_draft', $draftResult, [
            'evidence_sha256' => $evidenceHash,
            'plan_sha256' => $this->structuredHash($plan),
            'claim_coverage_status' => $claimAnalysis['coverage_status'],
            'unmarked_claim_count' => $claimAnalysis['unmarked_claim_count'],
            'marker_normalization_count' => $claimAnalysis['marker_normalization_count'],
        ]);

        $reviewResult = $this->call(
            $task,
            new ArticleModelCallRequest(
                ArticleGenerationStage::Review,
                $this->promptBuilder->review($title, $keyword, $knowledgeContext, $targetLanguage, $plan, $content, $allowedEvidenceIds, $styleBrief),
                false,
                2048
            ),
            $callCount
        );
        $review = $this->outputValidator->validateReview($this->structuredContent($reviewResult));
        $this->rememberStage($stages, $attempts, 'deep_review', $reviewResult, $this->reviewStageMeta($review, $evidenceHash));

        if ($review['passed']) {
            return $this->result(
                $claimAnalysis['content'],
                $articleModel,
                $attempts,
                $stages,
                $review,
                $plan['evidence_sufficiency'] === 'limited'
                    || $claimAnalysis['coverage_status'] === 'partial'
                    || $groundingGate['outcome'] === 'pending_review',
                $evidenceHash,
                $callCount,
                $claimAnalysis,
                $groundingGate
            );
        }

        $revisionResult = $this->call(
            $task,
            new ArticleModelCallRequest(
                ArticleGenerationStage::Revision,
                $this->promptBuilder->revision($title, $keyword, $writingBrief, $knowledgeContext, $targetLanguage, $plan, $content, $review, $allowedEvidenceIds),
                true
            ),
            $callCount
        );
        $content = trim((string) $revisionResult['content']);
        $claimAnalysis = $this->articleEvidencePackage->validateAndStripMarkers($content, $evidencePackage);
        $claimAnalysis['evidence_sufficiency'] = $plan['evidence_sufficiency'];
        $groundingGate = $this->groundingGate->evaluate($claimAnalysis['content'], $evidencePackage, $claimAnalysis);
        $this->assertGroundingGateAllowsDeepDraft($groundingGate);
        $articleModel = $revisionResult['model'];
        $this->rememberStage($stages, $attempts, 'deep_revision', $revisionResult, [
            'evidence_sha256' => $evidenceHash,
            'review_sha256' => $this->structuredHash($review),
            'requested_issue_codes' => $review['issue_codes'],
            'claim_coverage_status' => $claimAnalysis['coverage_status'],
            'unmarked_claim_count' => $claimAnalysis['unmarked_claim_count'],
            'marker_normalization_count' => $claimAnalysis['marker_normalization_count'],
        ]);

        $finalReviewResult = $this->call(
            $task,
            new ArticleModelCallRequest(
                ArticleGenerationStage::FinalReview,
                $this->promptBuilder->review($title, $keyword, $knowledgeContext, $targetLanguage, $plan, $content, $allowedEvidenceIds, $styleBrief),
                false,
                2048
            ),
            $callCount
        );
        $finalReview = $this->outputValidator->validateReview($this->structuredContent($finalReviewResult));
        $this->rememberStage($stages, $attempts, 'deep_final_review', $finalReviewResult, $this->reviewStageMeta($finalReview, $evidenceHash));

        if (! $finalReview['passed'] && $this->outputValidator->hasBlockingIssues($finalReview)) {
            throw new RuntimeException('深度生成终审发现阻断级安全或隐私问题，未保存草稿');
        }

        return $this->result(
            $claimAnalysis['content'],
            $articleModel,
            $attempts,
            $stages,
            $finalReview,
            $plan['evidence_sufficiency'] === 'limited'
                || ! $finalReview['passed']
                || $claimAnalysis['coverage_status'] === 'partial'
                || $groundingGate['outcome'] === 'pending_review',
            $evidenceHash,
            $callCount,
            $claimAnalysis,
            $groundingGate
        );
    }

    /** @return array<string,mixed> */
    private function call(Task $task, ArticleModelCallRequest $request, int &$callCount): array
    {
        if ($callCount >= self::MAX_CALLS) {
            throw new RuntimeException('深度生成已达到单篇最多 6 次模型调用上限');
        }

        $result = $this->modelCallService->generateStageWithModelSelection(
            $task,
            $request,
            self::MAX_CALLS - $callCount
        );
        $providerAttempts = collect($result['attempts'] ?? [])->filter(
            static fn (mixed $attempt): bool => is_array($attempt)
                && in_array(($attempt['status'] ?? null), ['success', 'failed'], true)
        )->count();
        $callCount += max(1, $providerAttempts);

        return $result;
    }

    /** @param array<string,mixed> $result */
    private function structuredContent(array $result): string
    {
        if (is_array($result['structured'] ?? null)) {
            return (string) json_encode($result['structured'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        }

        return (string) ($result['content'] ?? '');
    }

    /** @return list<array{code:string,path:string,expected:string}> */
    private function validationViolations(InvalidArgumentException $exception): array
    {
        if ($exception instanceof ArticlePlanValidationException) {
            return $exception->violations;
        }

        return [[
            'code' => 'schema.invalid_output',
            'path' => '$',
            'expected' => 'valid Plan V2 object',
        ]];
    }

    /**
     * @param  list<array<string,mixed>>  $stages
     * @param  list<array<string,mixed>>  $allAttempts
     * @param  array<string,mixed>  $result
     * @param  array<string,mixed>  $meta
     */
    private function rememberStage(
        array &$stages,
        array &$allAttempts,
        string $name,
        array $result,
        array $meta,
        string $status = 'completed'
    ): void {
        $attempts = is_array($result['attempts'] ?? null) ? $result['attempts'] : [];
        foreach ($attempts as $attempt) {
            if (is_array($attempt)) {
                $allAttempts[] = array_merge(['stage' => $name], $attempt);
            }
        }

        $stages[] = [
            'name' => $name,
            'status' => $status,
            'meta' => array_merge([
                'model_id' => (int) ($result['model']->id ?? 0),
                'attempt_count' => count($attempts),
                'duration_ms' => (int) collect($attempts)->sum(fn (mixed $attempt): int => is_array($attempt) ? (int) ($attempt['duration_ms'] ?? 0) : 0),
                'prompt_tokens' => (int) collect($attempts)->sum(fn (mixed $attempt): int => is_array($attempt) ? (int) ($attempt['prompt_tokens'] ?? 0) : 0),
                'completion_tokens' => (int) collect($attempts)->sum(fn (mixed $attempt): int => is_array($attempt) ? (int) ($attempt['completion_tokens'] ?? 0) : 0),
                'output_sha256' => hash('sha256', (string) ($result['content'] ?? '')),
                'output_length' => mb_strlen((string) ($result['content'] ?? ''), 'UTF-8'),
            ], $meta),
        ];
    }

    /** @param array<string,mixed> $review @return array<string,mixed> */
    private function reviewStageMeta(array $review, string $evidenceHash): array
    {
        return [
            'evidence_sha256' => $evidenceHash,
            'passed' => (bool) $review['passed'],
            'score' => (int) $review['score'],
            'issue_codes' => $review['issue_codes'],
            'metrics' => $review['metrics'],
            'blocking' => $this->outputValidator->hasBlockingIssues($review),
        ];
    }

    /** @return array<string,mixed> */
    private function result(
        string $content,
        AiModel $model,
        array $attempts,
        array $stages,
        array $review,
        bool $requiresManualReview,
        string $evidenceHash,
        int $callCount,
        array $claimAnalysis,
        array $groundingGate
    ): array {
        return [
            'content' => $content,
            'model' => $model,
            'attempts' => $attempts,
            'stages' => $stages,
            'review' => $review,
            'requires_manual_review' => $requiresManualReview,
            'evidence_sha256' => $evidenceHash,
            'claim_ledger' => $claimAnalysis['claim_ledger'],
            'claim_coverage_status' => $claimAnalysis['coverage_status'],
            'evidence_sufficiency' => (string) ($claimAnalysis['evidence_sufficiency'] ?? 'sufficient'),
            'unmarked_claim_count' => $claimAnalysis['unmarked_claim_count'],
            'unmarked_claim_hashes' => $claimAnalysis['unmarked_claim_hashes'],
            'grounding_gate' => $groundingGate,
            'call_count' => $callCount,
        ];
    }

    /** @param array<string,mixed> $gate */
    private function assertGroundingGateAllowsDeepDraft(array $gate): void
    {
        if (($gate['outcome'] ?? null) === 'blocked') {
            throw new RuntimeException('深度生成被确定性事实或安全门禁阻止，未保存草稿');
        }
    }

    /** @param array<string,mixed> $plan */
    private function assertPlanCanDraft(array $plan): void
    {
        if (($plan['evidence_sufficiency'] ?? null) !== 'insufficient') {
            return;
        }

        throw ArticleInsufficientEvidenceException::fromVerificationItems(
            is_array($plan['verification_items'] ?? null) ? $plan['verification_items'] : []
        );
    }

    /** @param array<string,mixed> $value */
    private function structuredHash(array $value): string
    {
        return hash('sha256', $this->encode($value));
    }

    /** @param array<string,mixed> $value */
    private function encode(array $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
