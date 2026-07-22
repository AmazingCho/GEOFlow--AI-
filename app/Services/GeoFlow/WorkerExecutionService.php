<?php

namespace App\Services\GeoFlow;

use App\Models\AiModel;
use App\Models\Article;
use App\Models\ArticleImage;
use App\Models\Author;
use App\Models\CaseRecord;
use App\Models\Category;
use App\Models\EntityRecord;
use App\Models\Image;
use App\Models\KnowledgeBase;
use App\Models\Prompt;
use App\Models\Task;
use App\Models\Title;
use App\Support\GeoFlow\ArticleGenerationModes;
use App\Support\GeoFlow\ArticleSkillIntents;
use App\Support\GeoFlow\ArticleWorkflow;
use App\Support\GeoFlow\CaseTypes;
use App\Support\GeoFlow\EntityTypes;
use App\Support\GeoFlow\ImageUrlNormalizer;
use App\Support\GeoFlow\SkillSelectionModes;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Worker 任务执行器：将队列任务落地为文章记录（占位实现，先打通 worker/队列链路）。
 */
class WorkerExecutionService
{
    /**
     * 最近一次生成链路的知识检索追踪，随 executeTask 写入 task_runs.meta。
     *
     * @var array<string,mixed>
     */
    private array $lastKnowledgeTrace = [];

    /**
     * 完整证据只在当前生成调用的内存中存在，绝不能合并进 generation trace。
     * null 表示旧调用未提供该契约，空数组表示严格契约下没有可用证据。
     *
     * @var list<array<string,mixed>>|null
     */
    private ?array $lastEvidencePackage = null;

    /**
     * @var list<array<string,mixed>>
     */
    private array $lastKnowledgeChunkTrace = [];

    /**
     * @var array{entities:list<array<string,mixed>>,cases:list<array<string,mixed>>}
     */
    private array $lastEntityCaseTrace = ['entities' => [], 'cases' => []];

    /** @var array<string,mixed> */
    private array $lastSkillRoutingTrace = [];

    /**
     * 复用统一 API Key 解密组件，确保 worker 与后台配置端解密行为一致。
     */
    public function __construct(
        private readonly ArticleModelCallService $articleModelCallService,
        private readonly KnowledgeChunkSyncService $knowledgeChunkSyncService,
        private readonly RagRetrievalService $ragRetrievalService,
        private readonly DistributionOrchestrator $distributionOrchestrator,
        private readonly TagService $tagService,
        private readonly SkillPromptRecommendationService $skillPromptRecommendationService,
        private readonly DeepArticleGenerationService $deepArticleGenerationService,
        private readonly ArticleEvidencePackage $articleEvidencePackage,
        private readonly ArticleGenerationTraceSanitizer $articleGenerationTraceSanitizer,
        private readonly ArticleGroundingGate $articleGroundingGate,
        private readonly ArticlePublicationGuard $articlePublicationGuard
    ) {}

    /**
     * @return array{article_id:int|null, title:string, message:string, meta:array<string,mixed>}
     */
    public function executeTask(
        int $taskId,
        ?callable $executionGuard = null,
        ?callable $articleCommitRecorder = null
    ): array {
        $this->lastKnowledgeTrace = [];
        $this->lastEvidencePackage = null;
        $this->lastKnowledgeChunkTrace = [];
        $this->lastEntityCaseTrace = ['entities' => [], 'cases' => []];
        $this->lastSkillRoutingTrace = [];

        try {
            /** @var Task|null $task */
            $task = Task::query()->find($taskId);
            if (! $task) {
                throw new RuntimeException('任务不存在');
            }

            if (($task->status ?? 'paused') !== 'active' || (int) ($task->schedule_enabled ?? 1) !== 1) {
                throw new RuntimeException('任务未激活');
            }

            $publishResult = $this->publishDueDraftArticle($task, $executionGuard, $articleCommitRecorder);
            if ($publishResult !== null) {
                $this->distributionOrchestrator->enqueueForArticle((int) $publishResult['article_id']);

                return $publishResult;
            }

            $generationBlockReason = $this->getGenerationBlockReason($task);
            if ($generationBlockReason !== null) {
                return [
                    'article_id' => null,
                    'title' => '',
                    'message' => $generationBlockReason,
                    'meta' => [
                        'task_id' => (int) $task->id,
                        'action' => 'noop',
                        'reason' => $generationBlockReason,
                    ],
                ];
            }

            $pipeline = $this->runArticleGenerationPipeline($task);
            $articleId = $this->persistGeneratedDraft($task, $pipeline, $executionGuard, $articleCommitRecorder);
            /** @var Title $titleRow */
            $titleRow = $pipeline['titleRow'];
            /** @var AiModel $aiModel */
            $aiModel = $pipeline['aiModel'];
            /** @var Author|null $author */
            $author = $pipeline['author'];
            /** @var Category|null $category */
            $category = $pipeline['category'];
            $generationOutcome = data_get($pipeline, 'workflow.review_status') === 'approved'
                ? 'draft_ready'
                : 'draft_review_required';

            return [
                'article_id' => $articleId,
                'title' => (string) $titleRow->title,
                'message' => '草稿生成成功',
                'meta' => [
                    'task_id' => (int) $task->id,
                    'action' => 'generate_draft',
                    'title_id' => (int) $titleRow->id,
                    'author_id' => $author?->id,
                    'category_id' => $category?->id,
                    'knowledge_length' => mb_strlen((string) $pipeline['knowledgeContext'], 'UTF-8'),
                    'image_count' => count($pipeline['selectedImages']),
                    'model_selection_mode' => (string) ($task->model_selection_mode ?? 'fixed'),
                    'generation_mode' => (string) ($pipeline['generationMode'] ?? ArticleGenerationModes::STANDARD),
                    'generation_outcome' => $generationOutcome,
                    'used_model_id' => (int) $aiModel->id,
                    'used_model_name' => (string) $aiModel->name,
                    'model_attempts' => $pipeline['generationAttempts'],
                    'generation_trace' => $this->buildGenerationTrace(
                        task: $task,
                        titleRow: $titleRow,
                        keyword: (string) $pipeline['keyword'],
                        author: $author,
                        category: $category,
                        prompt: $pipeline['prompt'],
                        skillPrompt: $pipeline['skillPrompt'],
                        stylePrompt: $pipeline['stylePrompt'],
                        aiModel: $aiModel,
                        generationAttempts: $pipeline['generationAttempts'],
                        knowledgeContext: (string) $pipeline['knowledgeContext'],
                        selectedImages: $pipeline['selectedImages'],
                        pipelineSteps: $pipeline['pipelineSteps'],
                        deepReview: $pipeline['deepReview'] ?? [],
                        deepRequiresManualReview: (bool) ($pipeline['deepRequiresManualReview'] ?? false),
                        claimLedger: is_array($pipeline['claimLedger'] ?? null) ? $pipeline['claimLedger'] : [],
                        claimCoverageStatus: (string) ($pipeline['claimCoverageStatus'] ?? 'not_applicable'),
                        evidenceSufficiency: (string) ($pipeline['evidenceSufficiency'] ?? 'not_applicable'),
                        groundingGate: is_array($pipeline['groundingGate'] ?? null) ? $pipeline['groundingGate'] : []
                    ),
                ],
            ];
        } finally {
            $this->lastEvidencePackage = null;
        }
    }

    /**
     * @return array{
     *   titleRow:Title,
     *   author:Author|null,
     *   category:Category|null,
     *   prompt:Prompt|null,
     *   skillPrompt:Prompt|null,
     *   stylePrompt:Prompt|null,
     *   keyword:string,
     *   knowledgeContext:string,
     *   contentPrompt:string,
     *   generatedContent:string,
     *   content:string,
     *   excerpt:string,
     *   workflow:array{status:string,review_status:string,published_at:null},
     *   aiModel:AiModel,
     *   generationAttempts:list<array<string,mixed>>,
     *   generationMode:string,
     *   deepReview:array<string,mixed>,
     *   deepRequiresManualReview:bool,
     *   claimLedger:list<array<string,mixed>>,
     *   claimCoverageStatus:string,
     *   groundingGate:array<string,mixed>,
     *   selectedImages:list<Image>,
     *   pipelineSteps:list<array<string,mixed>>
     * }
     */
    private function runArticleGenerationPipeline(Task $task): array
    {
        $pipelineSteps = [];

        $titleRow = $this->pickTitle($task);
        $author = $this->pickAuthor($task);
        $category = $this->pickCategory($task);
        $prompt = $task->prompt_id ? Prompt::query()->find((int) $task->prompt_id) : null;
        $stylePrompt = $task->style_prompt_id ? Prompt::query()->whereKey((int) $task->style_prompt_id)->where('type', 'style')->first() : null;
        $keyword = (string) ($titleRow->keyword ?? '');
        $pipelineSteps[] = $this->pipelineStep('select_sources', [
            'title_id' => (int) $titleRow->id,
            'author_id' => $author?->id,
            'category_id' => $category?->id,
            'prompt_id' => $prompt?->id,
            'skill_selection_mode' => (string) ($task->skill_selection_mode ?? SkillSelectionModes::fromLegacySkillId($task->skill_prompt_id)),
            'configured_skill_prompt_id' => $task->skill_prompt_id !== null ? (int) $task->skill_prompt_id : null,
            'style_prompt_id' => $stylePrompt?->id,
        ]);

        $generationMode = ArticleGenerationModes::normalize($task->generation_mode ?? null) ?? ArticleGenerationModes::STANDARD;
        $skillResolution = $this->resolveSkillPromptForTitle($task, $titleRow);
        if ($generationMode === ArticleGenerationModes::DEEP) {
            $caseStudyBlockReason = $this->deepCaseStudyBlockReason($task, $titleRow, $skillResolution);
            if ($caseStudyBlockReason !== null) {
                throw new RuntimeException('Case Study 生成已被证据治理门禁阻止：'.$caseStudyBlockReason);
            }
        }
        /** @var Prompt|null $skillPrompt */
        $skillPrompt = $skillResolution['prompt'];
        $pipelineSteps[] = $this->pipelineStep('resolve_skill', [
            'mode' => $skillResolution['mode'],
            'intent' => $skillResolution['intent'],
            'confidence' => $skillResolution['confidence'],
            'status' => $skillResolution['status'],
            'reason' => $skillResolution['reason'],
            'resolved_skill_prompt_id' => $skillPrompt?->id,
        ]);

        $knowledgeContext = $this->resolveKnowledgeContext(
            $task,
            (string) $titleRow->title,
            $keyword,
            $generationMode === ArticleGenerationModes::DEEP
        );
        $pipelineSteps[] = $this->pipelineStep('retrieve_context', [
            'strategy' => (string) ($this->lastKnowledgeTrace['strategy'] ?? 'none'),
            'context_length' => mb_strlen($knowledgeContext, 'UTF-8'),
            'chunks' => count($this->lastKnowledgeTrace['chunks'] ?? []),
            'entities' => count($this->lastKnowledgeTrace['entities'] ?? []),
            'cases' => count($this->lastKnowledgeTrace['cases'] ?? []),
        ]);
        if ($generationMode === ArticleGenerationModes::DEEP && $this->lastEvidencePackage === null) {
            throw new RuntimeException('深度生成缺少结构化证据包，已在调用模型前停止');
        }

        $composedPromptContent = $this->composeMasterAndSkillPrompt($prompt?->content, $skillPrompt?->content, $stylePrompt?->content);
        $targetLanguage = $this->determineGenerationLanguage(
            (string) $titleRow->title,
            $keyword,
            $this->composeMasterAndSkillPrompt($prompt?->content, $skillPrompt?->content)
        );
        $contentPrompt = $this->buildContentPrompt((string) $titleRow->title, $keyword, $composedPromptContent, $knowledgeContext, $targetLanguage);
        $pipelineSteps[] = $this->pipelineStep('compose_prompt', [
            'prompt_length' => mb_strlen($contentPrompt, 'UTF-8'),
            'has_custom_prompt' => $prompt !== null,
            'has_skill_prompt' => $skillPrompt !== null,
            'has_style_prompt' => $stylePrompt !== null,
            'target_language' => $targetLanguage,
        ]);

        $deepReview = [];
        $deepRequiresManualReview = false;
        $claimLedger = [];
        $claimCoverageStatus = 'not_applicable';
        $evidenceSufficiency = 'not_applicable';
        $groundingGate = [];
        if ($generationMode === ArticleGenerationModes::DEEP) {
            $writingBrief = trim((string) $composedPromptContent);
            if ($writingBrief === '') {
                $writingBrief = $targetLanguage === 'zh'
                    ? '围绕标题回答读者问题，使用已验证证据，明确未知信息，并让文章结构服从实际内容。'
                    : 'Answer the reader question using verified evidence, qualify unknowns, and let the content determine the structure.';
            }
            $generation = $this->deepArticleGenerationService->generate(
                $task,
                (string) $titleRow->title,
                $keyword,
                $writingBrief,
                $knowledgeContext,
                $targetLanguage,
                $this->lastEvidencePackage,
                is_string($skillResolution['intent'] ?? null) ? $skillResolution['intent'] : null,
                $stylePrompt?->content
            );
            $pipelineSteps = array_merge($pipelineSteps, $generation['stages'] ?? []);
            $deepReview = is_array($generation['review'] ?? null) ? $generation['review'] : [];
            $deepRequiresManualReview = (bool) ($generation['requires_manual_review'] ?? false);
            $claimLedger = is_array($generation['claim_ledger'] ?? null) ? $generation['claim_ledger'] : [];
            $claimCoverageStatus = (string) ($generation['claim_coverage_status'] ?? 'not_applicable');
            $evidenceSufficiency = (string) ($generation['evidence_sufficiency'] ?? 'sufficient');
            $groundingGate = is_array($generation['grounding_gate'] ?? null) ? $generation['grounding_gate'] : [];
        } else {
            $generation = $this->generateContentWithModelSelection($task, $contentPrompt);
        }
        /** @var AiModel $aiModel */
        $aiModel = $generation['model'];
        $generatedContent = (string) $generation['content'];
        $generationAttempts = is_array($generation['attempts'] ?? null) ? $generation['attempts'] : [];
        if ($generationMode === ArticleGenerationModes::STANDARD) {
            $pipelineSteps[] = $this->pipelineStep('generate_article', [
                'model_id' => (int) $aiModel->id,
                'model_name' => (string) $aiModel->name,
                'content_length' => mb_strlen($generatedContent, 'UTF-8'),
                'attempts' => count($generationAttempts),
            ]);
        }

        $imageResult = $this->insertTaskImagesIntoContent($task, $generatedContent);
        $content = (string) $imageResult['content'];
        $selectedImages = $imageResult['images'];
        $pipelineSteps[] = $this->pipelineStep('attach_images', [
            'image_count' => count($selectedImages),
            'content_length' => mb_strlen($content, 'UTF-8'),
        ]);
        $groundingGate = $this->articleGroundingGate->evaluate(
            $content,
            is_array($this->lastEvidencePackage) ? $this->lastEvidencePackage : [],
            [
                'coverage_status' => $claimCoverageStatus,
                'evidence_sufficiency' => $evidenceSufficiency,
            ]
        );
        $pipelineSteps[] = $this->pipelineStep('grounding_gate', [
            'rule_version' => (string) ($groundingGate['rule_version'] ?? ''),
            'outcome' => (string) ($groundingGate['outcome'] ?? 'pending_review'),
            'issue_count' => count($groundingGate['issues'] ?? []),
        ]);

        $excerpt = $this->buildExcerpt($content);
        $resolvedIntent = trim((string) ($skillResolution['intent'] ?? $skillPrompt?->intent_key ?? ''));
        $titleClassification = $this->skillPromptRecommendationService->classifyTitle(
            trim((string) $titleRow->title.' '.(string) ($titleRow->keyword ?? ''))
        );
        $governanceIntent = $resolvedIntent !== '' ? $resolvedIntent : trim((string) ($titleClassification['intent'] ?? ''));
        $requiresGovernanceReview = ($skillResolution['status'] ?? '') === 'governance_pending'
            || in_array($governanceIntent, [ArticleSkillIntents::CASE_STUDY, ArticleSkillIntents::TROUBLESHOOTING], true);
        $requiresGroundingReview = ($groundingGate['outcome'] ?? 'pending_review') !== 'pass';
        $workflow = [
            'status' => 'draft',
            'review_status' => (int) ($task->need_review ?? 1) === 1
                || $deepRequiresManualReview
                || $requiresGovernanceReview
                || $requiresGroundingReview
                ? 'pending'
                : 'approved',
            'published_at' => null,
        ];
        $pipelineSteps[] = $this->pipelineStep('prepare_draft', [
            'excerpt_length' => mb_strlen($excerpt, 'UTF-8'),
            'review_status' => $workflow['review_status'],
            'generation_mode' => $generationMode,
            'deep_issue_codes' => $deepReview['issue_codes'] ?? [],
            'governance_review_required' => $requiresGovernanceReview,
            'grounding_review_required' => $requiresGroundingReview,
            'grounding_outcome' => (string) ($groundingGate['outcome'] ?? 'pending_review'),
            'claim_coverage_status' => $claimCoverageStatus,
            'evidence_sufficiency' => $evidenceSufficiency,
            'claim_count' => count($claimLedger),
        ]);

        return [
            'titleRow' => $titleRow,
            'author' => $author,
            'category' => $category,
            'prompt' => $prompt,
            'skillPrompt' => $skillPrompt,
            'stylePrompt' => $stylePrompt,
            'keyword' => $keyword,
            'knowledgeContext' => $knowledgeContext,
            'contentPrompt' => $contentPrompt,
            'generatedContent' => $generatedContent,
            'content' => $content,
            'excerpt' => $excerpt,
            'workflow' => $workflow,
            'aiModel' => $aiModel,
            'generationAttempts' => $generationAttempts,
            'generationMode' => $generationMode,
            'deepReview' => $deepReview,
            'deepRequiresManualReview' => $deepRequiresManualReview,
            'claimLedger' => $claimLedger,
            'claimCoverageStatus' => $claimCoverageStatus,
            'evidenceSufficiency' => $evidenceSufficiency,
            'groundingGate' => $groundingGate,
            'selectedImages' => $selectedImages,
            'pipelineSteps' => $pipelineSteps,
        ];
    }

    /**
     * @return array{prompt:Prompt|null,mode:string,intent:?string,confidence:?int,status:string,reason:string}
     */
    private function resolveSkillPromptForTitle(Task $task, Title $titleRow): array
    {
        $mode = SkillSelectionModes::normalize($task->skill_selection_mode)
            ?? SkillSelectionModes::fromLegacySkillId($task->skill_prompt_id);
        $result = [
            'prompt' => null,
            'mode' => $mode,
            'intent' => null,
            'confidence' => null,
            'status' => 'disabled',
            'reason' => 'skill_disabled',
        ];

        if ($mode === SkillSelectionModes::NONE) {
            return $this->rememberSkillRouting($result);
        }

        if ($mode === SkillSelectionModes::MANUAL) {
            $prompt = $task->skill_prompt_id
                ? Prompt::query()->whereKey((int) $task->skill_prompt_id)->where('type', 'skill')->first()
                : null;
            $intent = $prompt ? $this->resolveManualSkillIntent($prompt) : null;
            $result['intent'] = $intent;
            $governanceReason = $prompt ? $this->skillGovernanceFailureReason($task, $intent) : null;
            if ($prompt && $intent === null) {
                $hasSelectedCase = $this->integerList(
                    preg_split('/\s*,\s*/u', trim((string) ($task->case_filter ?? '')), -1, PREG_SPLIT_NO_EMPTY) ?: []
                ) !== [];
                if ($hasSelectedCase) {
                    $governanceReason = 'case_skill_intent_unclassified';
                } elseif ($this->isDeepGeneration($task)) {
                    $governanceReason = 'manual_skill_intent_unclassified';
                }
            }
            if ($governanceReason !== null && $this->isDeepGeneration($task)) {
                $result['status'] = 'blocked';
                $result['reason'] = $governanceReason;

                return $this->rememberSkillRouting($result);
            }
            $result['prompt'] = $prompt;
            $result['status'] = $prompt
                ? ($governanceReason !== null ? 'governance_pending' : 'manual')
                : 'fallback';
            $result['reason'] = $prompt
                ? ($governanceReason ?? 'manual_selection')
                : 'manual_skill_missing';

            return $this->rememberSkillRouting($result);
        }

        $classificationText = trim((string) $titleRow->title.' '.(string) ($titleRow->keyword ?? ''));
        $classification = $this->skillPromptRecommendationService->classifyTitle($classificationText);
        if ($classification === null) {
            $result['status'] = 'fallback';
            $result['reason'] = 'low_confidence';

            return $this->rememberSkillRouting($result);
        }

        $intent = (string) $classification['intent'];
        $result['intent'] = $intent;
        $result['confidence'] = (int) $classification['confidence'];

        if (! in_array($intent, ArticleSkillIntents::autoEligible(), true)) {
            $result['status'] = 'fallback';
            $result['reason'] = $this->skillGovernanceFailureReason($task, $intent) ?? 'skill_disabled';

            return $this->rememberSkillRouting($result);
        }

        $governanceReason = $this->skillGovernanceFailureReason($task, $intent);
        if ($governanceReason !== null && $this->isDeepGeneration($task)) {
            $result['status'] = 'blocked';
            $result['reason'] = $governanceReason;

            return $this->rememberSkillRouting($result);
        }

        $prompt = $this->skillPromptRecommendationService->findSkillPromptForIntent($intent);
        if ($prompt === null) {
            $result['status'] = 'fallback';
            $result['reason'] = 'skill_unconfigured';

            return $this->rememberSkillRouting($result);
        }

        $result['prompt'] = $prompt;
        $result['status'] = $governanceReason !== null ? 'governance_pending' : 'recommended';
        $result['reason'] = $governanceReason ?? 'intent_match';

        return $this->rememberSkillRouting($result);
    }

    /** @param array{prompt:Prompt|null,mode:string,intent:?string,confidence:?int,status:string,reason:string} $result */
    private function rememberSkillRouting(array $result): array
    {
        $this->lastSkillRoutingTrace = [
            'mode' => $result['mode'],
            'intent' => $result['intent'],
            'confidence' => $result['confidence'],
            'status' => $result['status'],
            'reason' => $result['reason'],
            'resolved_skill_prompt_id' => $result['prompt']?->id,
        ];

        return $result;
    }

    /** @param array{prompt:Prompt|null,mode:string,intent:?string,confidence:?int,status:string,reason:string} $skillResolution */
    private function deepCaseStudyBlockReason(Task $task, Title $titleRow, array $skillResolution): ?string
    {
        if (($skillResolution['status'] ?? null) === 'blocked') {
            return (string) ($skillResolution['reason'] ?? 'skill_governance_blocked');
        }
        if (($skillResolution['mode'] ?? null) === SkillSelectionModes::MANUAL
            && ($skillResolution['prompt'] ?? null) instanceof Prompt
            && ($skillResolution['intent'] ?? null) === null
            && $this->integerList(preg_split('/\s*,\s*/u', trim((string) ($task->case_filter ?? '')), -1, PREG_SPLIT_NO_EMPTY) ?: []) !== []) {
            return 'case_skill_intent_unclassified';
        }

        if (($skillResolution['intent'] ?? null) === ArticleSkillIntents::CASE_STUDY) {
            return ($skillResolution['status'] ?? '') === 'blocked'
                ? (string) ($skillResolution['reason'] ?? 'case_publication_approval_missing')
                : $this->caseStudyGateFailureReason($task);
        }

        $classification = $this->skillPromptRecommendationService->classifyTitle(
            trim((string) $titleRow->title.' '.(string) ($titleRow->keyword ?? ''))
        );

        return ($classification['intent'] ?? null) === ArticleSkillIntents::CASE_STUDY
            ? $this->caseStudyGateFailureReason($task)
            : null;
    }

    private function skillGovernanceFailureReason(Task $task, ?string $intent): ?string
    {
        return match ($intent) {
            ArticleSkillIntents::CASE_STUDY => $this->caseStudyGateFailureReason($task),
            ArticleSkillIntents::TROUBLESHOOTING => $this->troubleshootingGateFailureReason($task),
            default => null,
        };
    }

    private function isDeepGeneration(Task $task): bool
    {
        return (ArticleGenerationModes::normalize($task->generation_mode ?? null) ?? ArticleGenerationModes::STANDARD)
            === ArticleGenerationModes::DEEP;
    }

    private function resolveManualSkillIntent(Prompt $prompt): ?string
    {
        $intent = ArticleSkillIntents::normalize($prompt->intent_key);
        if ($intent !== null) {
            return $intent;
        }

        if (preg_match('/\Aarticle\.skill\.([a-z_]+)\z/', trim((string) ($prompt->preset_key ?? '')), $matches) === 1) {
            $intent = ArticleSkillIntents::normalize($matches[1] ?? null);
            if ($intent !== null) {
                return $intent;
            }
        }

        $name = mb_strtolower(trim((string) $prompt->name), 'UTF-8');
        if (preg_match('/\b(?:case\s*study|success\s*story)\b|成功故事|(?:客户|成功|项目)?案例(?:文章|研究|写作|故事)/iu', $name) === 1) {
            return ArticleSkillIntents::CASE_STUDY;
        }
        if (preg_match('/\btroubleshoot(?:ing)?\b|故障排查|故障处理/iu', $name) === 1) {
            return ArticleSkillIntents::TROUBLESHOOTING;
        }

        return null;
    }

    private function caseStudyGateFailureReason(Task $task): ?string
    {
        $caseIds = $this->integerList(preg_split('/\s*,\s*/u', trim((string) ($task->case_filter ?? '')), -1, PREG_SPLIT_NO_EMPTY) ?: []);
        if ($caseIds === []) {
            return 'case_evidence_missing';
        }

        $hasStructuredEvidence = CaseRecord::query()
            ->whereIn('id', $caseIds)
            ->whereNotNull('solution')
            ->where('solution', '<>', '')
            ->where(function ($query): void {
                $query->where(function ($nested): void {
                    $nested->whereNotNull('result')->where('result', '<>', '');
                })->orWhere(function ($nested): void {
                    $nested->whereNotNull('metrics')->where('metrics', '<>', '');
                });
            })
            ->exists();

        if (! $hasStructuredEvidence) {
            return 'case_evidence_missing';
        }

        // The current Case schema cannot prove publication consent or anonymization review.
        return 'case_publication_approval_missing';
    }

    private function troubleshootingGateFailureReason(Task $task): ?string
    {
        if ((int) ($task->need_review ?? 1) !== 1) {
            return 'troubleshooting_evidence_missing';
        }

        $knowledgeBaseIds = $this->integerList($this->lastKnowledgeTrace['knowledge_base_ids'] ?? []);
        $contextLength = (int) ($this->lastKnowledgeTrace['context_length'] ?? 0);

        if ($knowledgeBaseIds === [] || $contextLength <= 0) {
            return 'troubleshooting_evidence_missing';
        }

        // Knowledge sources are not yet classified for operator-safe vs technician-only guidance.
        return 'troubleshooting_safety_classification_missing';
    }

    /**
     * @param  array<string,mixed>  $pipeline
     */
    private function persistGeneratedDraft(
        Task $task,
        array $pipeline,
        ?callable $executionGuard = null,
        ?callable $articleCommitRecorder = null
    ): int {
        /** @var Title $titleRow */
        $titleRow = $pipeline['titleRow'];
        /** @var Author|null $author */
        $author = $pipeline['author'];
        /** @var Category|null $category */
        $category = $pipeline['category'];
        $keyword = (string) $pipeline['keyword'];
        $content = (string) $pipeline['content'];
        if ($this->articleEvidencePackage->containsEvidenceLikeMarker($content)) {
            throw new RuntimeException('文章包含未清理的证据标记，未保存草稿');
        }
        $excerpt = (string) $pipeline['excerpt'];
        /** @var array{status:string,review_status:string,published_at:null} $workflow */
        $workflow = $pipeline['workflow'];
        /** @var list<Image> $selectedImages */
        $selectedImages = $pipeline['selectedImages'];
        $contextMetadata = $this->articleContextMetadataFromTrace($this->lastKnowledgeTrace);
        if (($pipeline['generationMode'] ?? ArticleGenerationModes::STANDARD) === ArticleGenerationModes::DEEP) {
            $contextMetadata['context_snapshot']['claim_ledger'] = $this->articleGenerationTraceSanitizer
                ->sanitizeClaimLedger($pipeline['claimLedger'] ?? []);
            $contextMetadata['context_snapshot']['claim_coverage_status'] = (string) ($pipeline['claimCoverageStatus'] ?? 'not_applicable');
            $contextMetadata['context_snapshot']['evidence_sufficiency'] = (string) ($pipeline['evidenceSufficiency'] ?? 'sufficient');
        }
        $contextMetadata['context_snapshot']['grounding_gate'] = $this->articleGenerationTraceSanitizer
            ->sanitizeGroundingGate($pipeline['groundingGate'] ?? []);

        return DB::transaction(function () use ($task, $titleRow, $author, $category, $keyword, $content, $excerpt, $workflow, $selectedImages, $contextMetadata, $executionGuard, $articleCommitRecorder): int {
            $freshTask = Task::query()
                ->whereKey((int) $task->id)
                ->lockForUpdate()
                ->first(['id', 'collection_id', 'status', 'schedule_enabled', 'created_count', 'draft_limit', 'article_limit', 'publish_interval', 'next_publish_at']);
            if (! $freshTask || ($freshTask->status ?? 'paused') !== 'active' || (int) ($freshTask->schedule_enabled ?? 1) !== 1) {
                throw new RuntimeException('任务未激活');
            }
            $generationBlockReason = $this->getGenerationBlockReason($freshTask, true);
            if ($generationBlockReason !== null) {
                throw new RuntimeException($generationBlockReason);
            }
            $this->assertExecutionOwnership($executionGuard);

            $article = Article::query()->create([
                'title' => (string) $titleRow->title,
                'slug' => ArticleWorkflow::generateUniqueSlug((string) $titleRow->title),
                'excerpt' => $excerpt,
                'content' => $content,
                'category_id' => $category?->id,
                'author_id' => $author?->id,
                'task_id' => (int) $task->id,
                'selected_collection_id' => $contextMetadata['selected_collection_id'] ?? ((int) ($freshTask->collection_id ?? 0) ?: null),
                'selected_entity_ids' => $contextMetadata['selected_entity_ids'],
                'selected_case_ids' => $contextMetadata['selected_case_ids'],
                'used_knowledge_base_ids' => $contextMetadata['used_knowledge_base_ids'],
                'used_tags' => $contextMetadata['used_tags'],
                'context_snapshot' => $contextMetadata['context_snapshot'],
                'original_keyword' => $keyword,
                'keywords' => $keyword,
                'meta_description' => mb_substr($excerpt, 0, 120),
                'status' => $workflow['status'],
                'review_status' => $workflow['review_status'],
                'is_ai_generated' => 1,
                'published_at' => $workflow['published_at'],
                'view_count' => 0,
            ]);
            if ($selectedImages !== []) {
                foreach ($selectedImages as $position => $image) {
                    ArticleImage::query()->create([
                        'article_id' => (int) $article->id,
                        'image_id' => (int) $image->id,
                        'position' => $position,
                    ]);
                    Image::query()->whereKey((int) $image->id)->update([
                        'used_count' => DB::raw('COALESCE(used_count,0)+1'),
                        'usage_count' => DB::raw('COALESCE(usage_count,0)+1'),
                    ]);
                }
            }

            // 保持与旧逻辑一致：每次任务执行会消耗标题并累加任务计数。
            Title::query()->whereKey($titleRow->id)->increment('used_count');
            Title::query()->whereKey($titleRow->id)->increment('usage_count');

            $taskUpdate = [
                'created_count' => DB::raw('COALESCE(created_count,0)+1'),
                'loop_count' => DB::raw('COALESCE(loop_count,0)+1'),
                'updated_at' => now(),
            ];
            if ($freshTask->next_publish_at === null || ! $freshTask->next_publish_at->greaterThan(now())) {
                $taskUpdate['next_publish_at'] = now()->addSeconds($this->normalizePublishInterval($freshTask));
            }
            Task::query()->whereKey($task->id)->update($taskUpdate);
            $this->recordCommittedArticle($articleCommitRecorder, (int) $article->id);

            return (int) $article->id;
        });
    }

    /**
     * @param  array<string,mixed>  $meta
     * @return array<string,mixed>
     */
    private function pipelineStep(string $name, array $meta = []): array
    {
        return [
            'name' => $name,
            'status' => 'completed',
            'meta' => $meta,
        ];
    }

    /**
     * @param  list<Image>  $selectedImages
     * @param  list<array<string,mixed>>  $generationAttempts
     * @param  list<array<string,mixed>>  $pipelineSteps
     * @return array<string,mixed>
     */
    private function buildGenerationTrace(
        Task $task,
        Title $titleRow,
        string $keyword,
        ?Author $author,
        ?Category $category,
        ?Prompt $prompt,
        ?Prompt $skillPrompt,
        ?Prompt $stylePrompt,
        AiModel $aiModel,
        array $generationAttempts,
        string $knowledgeContext,
        array $selectedImages,
        array $pipelineSteps = [],
        array $deepReview = [],
        bool $deepRequiresManualReview = false,
        array $claimLedger = [],
        string $claimCoverageStatus = 'not_applicable',
        string $evidenceSufficiency = 'not_applicable',
        array $groundingGate = []
    ): array {
        return [
            'version' => 1,
            'deep_protocol_version' => ArticleGenerationModes::normalize($task->generation_mode ?? null) === ArticleGenerationModes::DEEP
                ? DeepArticleGenerationService::PROTOCOL_VERSION
                : null,
            'generated_at' => now()->toDateTimeString(),
            'pipeline' => $pipelineSteps,
            'task' => [
                'id' => (int) $task->id,
                'name' => (string) ($task->name ?? ''),
                'collection_id' => $task->collection_id !== null ? (int) $task->collection_id : null,
                'knowledge_tag_filter' => (string) ($task->knowledge_tag_filter ?? ''),
                'entity_filter' => (string) ($task->entity_filter ?? ''),
                'image_tag_filter' => (string) ($task->image_tag_filter ?? ''),
                'model_selection_mode' => (string) ($task->model_selection_mode ?? 'fixed'),
                'generation_mode' => ArticleGenerationModes::normalize($task->generation_mode ?? null) ?? ArticleGenerationModes::STANDARD,
                'skill_selection_mode' => (string) ($task->skill_selection_mode ?? SkillSelectionModes::fromLegacySkillId($task->skill_prompt_id)),
            ],
            'title' => [
                'id' => (int) $titleRow->id,
                'text' => (string) $titleRow->title,
                'keyword' => $keyword,
            ],
            'author' => $author ? ['id' => (int) $author->id, 'name' => (string) $author->name] : null,
            'category' => $category ? ['id' => (int) $category->id, 'name' => (string) $category->name] : null,
            'prompt' => $this->promptTraceReference($prompt),
            'skill_prompt' => $this->promptTraceReference($skillPrompt),
            'skill_routing' => $this->lastSkillRoutingTrace,
            'style_prompt' => $this->promptTraceReference($stylePrompt),
            'prompt_hashes' => [
                'master_sha256' => $this->promptContentHash($prompt),
                'skill_sha256' => $this->promptContentHash($skillPrompt),
                'style_sha256' => $this->promptContentHash($stylePrompt),
            ],
            'language' => [
                'code' => $this->determineGenerationLanguage((string) $titleRow->title, $keyword, $this->composeMasterAndSkillPrompt($prompt?->content, $skillPrompt?->content)),
            ],
            'model' => [
                'id' => (int) $aiModel->id,
                'name' => (string) $aiModel->name,
                'model_id' => (string) ($aiModel->model_id ?? ''),
                'provider' => (string) ($aiModel->provider ?? ''),
            ],
            'model_attempts' => $generationAttempts,
            'deep_review' => $deepReview === [] ? null : [
                'passed' => (bool) ($deepReview['passed'] ?? false),
                'score' => (int) ($deepReview['score'] ?? 0),
                'issue_codes' => array_values($deepReview['issue_codes'] ?? []),
                'metrics' => is_array($deepReview['metrics'] ?? null) ? $deepReview['metrics'] : [],
                'requires_manual_review' => $deepRequiresManualReview,
            ],
            'claim_provenance' => [
                'coverage_status' => $claimCoverageStatus,
                'evidence_sufficiency' => $evidenceSufficiency,
                'claim_ledger' => $this->articleGenerationTraceSanitizer->sanitizeClaimLedger($claimLedger),
            ],
            'grounding_gate' => $this->articleGenerationTraceSanitizer->sanitizeGroundingGate($groundingGate),
            'knowledge' => array_merge($this->articleGenerationTraceSanitizer->sanitizeKnowledgeTrace($this->lastKnowledgeTrace), [
                'context_length' => mb_strlen($knowledgeContext, 'UTF-8'),
            ]),
            'images' => array_map(static fn (Image $image): array => [
                'id' => (int) $image->id,
                'library_id' => (int) ($image->library_id ?? 0),
                'filename' => (string) ($image->filename ?? ''),
                'original_name' => (string) ($image->original_name ?? ''),
                'file_path' => (string) ($image->file_path ?? ''),
            ], $selectedImages),
        ];
    }

    /**
     * @param  array<string,mixed>  $trace
     * @return array{selected_collection_id:int|null,selected_entity_ids:list<int>,selected_case_ids:list<int>,used_knowledge_base_ids:list<int>,used_tags:list<string>,context_snapshot:array<string,mixed>}
     */
    private function articleContextMetadataFromTrace(array $trace): array
    {
        $package = is_array($trace['context_package'] ?? null)
            ? $this->articleGenerationTraceSanitizer->sanitizeContextPackage($trace['context_package'])
            : $this->articleGenerationTraceSanitizer->sanitizeKnowledgeTrace($trace);

        return [
            'selected_collection_id' => isset($package['selected_collection_id'])
                ? ((int) $package['selected_collection_id'] ?: null)
                : (isset($trace['collection_id']) ? ((int) $trace['collection_id'] ?: null) : null),
            'selected_entity_ids' => $this->integerList($package['selected_entity_ids'] ?? $trace['entity_filter_ids'] ?? []),
            'selected_case_ids' => $this->integerList($package['selected_case_ids'] ?? $trace['case_filter_ids'] ?? []),
            'used_knowledge_base_ids' => $this->integerList($package['used_knowledge_base_ids'] ?? $trace['knowledge_base_ids'] ?? []),
            'used_tags' => $this->stringList($package['used_tags'] ?? $trace['tag_filters'] ?? []),
            'context_snapshot' => $package,
        ];
    }

    /**
     * @return list<int>
     */
    private function integerList(mixed $value): array
    {
        return collect(is_array($value) ? $value : [])
            ->map(static fn (mixed $item): int => (int) $item)
            ->filter(static fn (int $item): bool => $item > 0)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        return collect(is_array($value) ? $value : [])
            ->map(static fn (mixed $item): string => trim((string) $item))
            ->filter(static fn (string $item): bool => $item !== '')
            ->unique(static fn (string $item): string => mb_strtolower($item, 'UTF-8'))
            ->values()
            ->all();
    }

    private function detectPromptLanguage(string $text): string
    {
        $text = mb_strtolower(trim($text), 'UTF-8');
        if ($text === '') {
            return 'unknown';
        }
        $han = preg_match_all('/\p{Han}/u', $text);
        if ($han > 10) {
            return 'zh';
        }
        preg_match_all('/[A-Za-z]/', $text, $latinMatches);
        if ($han === 0 && count($latinMatches[0] ?? []) > 20) {
            return 'en';
        }

        return preg_match('/\b(?:the|and|for|with|how|what|why|service|customer|business|company)\b/u', $text) === 1
            ? 'en'
            : 'unknown';
    }

    private function determineGenerationLanguage(string $title, string $keyword, ?string $promptContent): string
    {
        $titleKeywordLanguage = $this->detectPromptLanguage(trim($title."\n".$keyword));
        if ($titleKeywordLanguage !== 'unknown') {
            return $titleKeywordLanguage;
        }

        $promptLanguage = $this->detectPromptLanguage((string) $promptContent);

        return $promptLanguage !== 'unknown' ? $promptLanguage : 'zh';
    }

    /**
     * 发布一个已审核草稿。生成与发布解耦后，Worker 每次执行优先释放到期草稿。
     *
     * @return array{article_id:int, title:string, message:string, meta:array<string,mixed>}|null
     */
    private function publishDueDraftArticle(
        Task $task,
        ?callable $executionGuard = null,
        ?callable $articleCommitRecorder = null
    ): ?array {
        if ($task->next_publish_at !== null && $task->next_publish_at->greaterThan(now())) {
            return null;
        }

        return DB::transaction(function () use ($task, $executionGuard, $articleCommitRecorder): ?array {
            $freshTask = Task::query()
                ->whereKey((int) $task->id)
                ->lockForUpdate()
                ->first(['id', 'status', 'schedule_enabled', 'publish_interval', 'next_publish_at', 'publish_scope']);
            if (! $freshTask || ($freshTask->status ?? 'paused') !== 'active' || (int) ($freshTask->schedule_enabled ?? 1) !== 1) {
                throw new RuntimeException('任务未激活');
            }

            if ($freshTask->next_publish_at !== null && $freshTask->next_publish_at->greaterThan(now())) {
                return null;
            }

            $candidateArticleId = Article::query()
                ->where('task_id', (int) $freshTask->id)
                ->where('status', 'draft')
                ->whereIn('review_status', ['approved', 'auto_approved'])
                ->whereNull('deleted_at')
                ->orderBy('id')
                ->value('id');
            if ($candidateArticleId === null) {
                return null;
            }
            $this->assertExecutionOwnership($executionGuard);

            /** @var Article|null $article */
            $article = Article::query()
                ->whereKey((int) $candidateArticleId)
                ->where('task_id', (int) $freshTask->id)
                ->where('status', 'draft')
                ->whereIn('review_status', ['approved', 'auto_approved'])
                ->whereNull('deleted_at')
                ->lockForUpdate()
                ->first(['id', 'title', 'review_status', 'context_snapshot']);
            if (! $article) {
                return null;
            }
            $this->articlePublicationGuard->assertCanPublish($article);

            $publishScope = (string) ($freshTask->publish_scope ?? 'local_and_distribution');
            $targetStatus = $publishScope === 'distribution_only' ? 'private' : 'published';
            $workflow = ArticleWorkflow::normalizeState($targetStatus, (string) ($article->review_status ?: 'approved'));
            Article::query()->whereKey((int) $article->id)->update([
                'status' => $workflow['status'],
                'review_status' => $workflow['review_status'],
                'published_at' => $workflow['published_at'],
                'updated_at' => now(),
            ]);

            $publishInterval = $this->normalizePublishInterval($freshTask);
            Task::query()->whereKey((int) $freshTask->id)->update([
                'published_count' => DB::raw('COALESCE(published_count,0)+1'),
                'next_publish_at' => now()->addSeconds($publishInterval),
                'updated_at' => now(),
            ]);
            $this->recordCommittedArticle($articleCommitRecorder, (int) $article->id);

            return [
                'article_id' => (int) $article->id,
                'title' => (string) $article->title,
                'message' => '草稿发布成功',
                'meta' => [
                    'task_id' => (int) $freshTask->id,
                    'action' => 'publish_draft',
                    'publish_interval' => $publishInterval,
                ],
            ];
        });
    }

    private function assertExecutionOwnership(?callable $executionGuard): void
    {
        if ($executionGuard !== null && $executionGuard() !== true) {
            throw new RuntimeException('任务执行所有权已失效，当前 worker 不允许写入业务数据');
        }
    }

    private function recordCommittedArticle(?callable $articleCommitRecorder, int $articleId): void
    {
        if ($articleCommitRecorder !== null && $articleCommitRecorder($articleId) !== true) {
            throw new RuntimeException('任务执行结果无法关联当前运行记录，业务写入已回滚');
        }
    }

    /**
     * 判断是否允许继续生成草稿。
     */
    private function getGenerationBlockReason(Task $task, bool $lock = false): ?string
    {
        $articleLimit = max(1, (int) ($task->article_limit ?? $task->draft_limit ?? 10));
        if ((int) ($task->created_count ?? 0) >= $articleLimit) {
            return '已达到文章总数上限';
        }

        $draftLimit = max(1, (int) ($task->draft_limit ?? 10));
        $draftQuery = Article::query()
            ->where('task_id', (int) $task->id)
            ->where('status', 'draft')
            ->whereNull('deleted_at');
        // PostgreSQL 不允许在 count(*) 聚合查询上追加 FOR UPDATE。
        // 这里的并发保护由任务行锁和 task_runs 的单任务串行队列保证，草稿计数不需要再单独加锁。

        if ($draftQuery->count() >= $draftLimit) {
            return '草稿池已满，等待审核或按间隔发布';
        }

        return null;
    }

    private function normalizePublishInterval(Task $task): int
    {
        return max(60, (int) ($task->publish_interval ?? 3600));
    }

    /**
     * 保留 Worker 内部入口，实际模型调用由独立服务统一处理。
     *
     * @return array{content:string,model:AiModel,attempts:list<array{model_id:int,model_name:string,status:string,reason:?string}>}
     */
    private function generateContentWithModelSelection(Task $task, string $contentPrompt): array
    {
        return $this->articleModelCallService->generateWithModelSelection($task, $contentPrompt);
    }

    private function pickTitle(Task $task): Title
    {
        $libraryId = (int) ($task->title_library_id ?? 0);
        if ($libraryId <= 0) {
            throw new RuntimeException('任务未配置标题库');
        }

        $query = Title::query()->where('library_id', $libraryId);
        if ((int) ($task->is_loop ?? 0) !== 1) {
            $query->where(function ($builder): void {
                $builder->whereNull('used_count')->orWhere('used_count', '<=', 0);
            });
        }

        /** @var Title|null $title */
        $title = $query
            ->orderBy('used_count')
            ->orderBy('id')
            ->first();

        if (! $title) {
            throw new RuntimeException((int) ($task->is_loop ?? 0) === 1 ? '没有可用的标题' : '标题库已用尽');
        }

        return $title;
    }

    private function pickAuthor(Task $task): Author
    {
        $authorId = (int) ($task->custom_author_id ?: $task->author_id);
        if ($authorId > 0) {
            $author = Author::query()->find($authorId);
            if ($author) {
                return $author;
            }
        }

        $author = Author::query()->orderBy('id')->first();
        if ($author) {
            return $author;
        }

        return Author::query()->firstOrCreate(
            ['name' => 'GEOFlow'],
            ['bio' => 'Default GEOFlow author for automated content generation.']
        );
    }

    private function pickCategory(Task $task): ?Category
    {
        if (($task->category_mode ?? 'smart') === 'fixed' && (int) ($task->fixed_category_id ?? 0) > 0) {
            return Category::query()->find((int) $task->fixed_category_id);
        }

        return Category::query()->orderBy('sort_order')->orderBy('id')->first();
    }

    /**
     * 构造正文提示词：优先精确替换变量；无变量的自定义提示词自动补齐任务上下文。
     */
    private function composeMasterAndSkillPrompt(?string $masterPromptContent, ?string $skillPromptContent, ?string $stylePromptContent = null): ?string
    {
        $masterPrompt = trim((string) $masterPromptContent);
        $skillPrompt = trim((string) $skillPromptContent);
        $stylePrompt = trim((string) $stylePromptContent);

        if ($masterPrompt === '' && $stylePrompt === '') {
            return $skillPrompt !== '' ? $skillPrompt : null;
        }

        if ($skillPrompt === '' && $stylePrompt === '') {
            return $masterPrompt;
        }

        $sections = [];
        if ($masterPrompt !== '') {
            $sections[] = "=== Master Prompt ===\n{$masterPrompt}";
        }
        if ($skillPrompt !== '') {
            $sections[] = "=== Skill Prompt ===\n{$skillPrompt}";
        }
        if ($stylePrompt !== '') {
            $sections[] = "=== Writing Style Prompt ===\n{$stylePrompt}";
        }

        return $sections !== [] ? trim(implode("\n\n", $sections)) : null;
    }

    private function buildContentPrompt(string $title, string $keyword, ?string $promptContent, string $knowledgeContext, string $targetLanguage): string
    {
        $prompt = trim((string) $promptContent);
        $isFallbackPrompt = false;
        if ($prompt === '') {
            $prompt = $targetLanguage === 'zh'
                ? "请围绕标题“{$title}”和关键词“{$keyword}”生成一篇结构清晰、语言自然的中文文章。"
                : "Write a clear, well-structured article around the title \"{$title}\" and the keyword \"{$keyword}\".";
            $isFallbackPrompt = true;
        }

        $prompt = $this->stripReservedPromptPlaceholders($prompt);
        $explicitContext = [
            'title' => $isFallbackPrompt || $this->promptHasContextVariable($prompt, 'title'),
            'keyword' => $isFallbackPrompt || $this->promptHasContextVariable($prompt, 'keyword'),
            'knowledge' => $this->promptHasContextVariable($prompt, 'knowledge'),
        ];
        $renderedPrompt = $this->renderPromptTemplate($prompt, [
            'title' => $title,
            'keyword' => $keyword,
            'knowledge' => $knowledgeContext,
        ]);

        if (! $explicitContext['title'] || ! $explicitContext['keyword'] || ! $explicitContext['knowledge']) {
            $renderedPrompt = $this->appendSmartPromptContext(
                $renderedPrompt,
                $title,
                $keyword,
                $knowledgeContext,
                $targetLanguage,
                ! $explicitContext['title'],
                ! $explicitContext['keyword'],
                ! $explicitContext['knowledge']
            );
        }

        return trim($renderedPrompt)."\n\n".$this->finalPromptInstruction($targetLanguage);
    }

    private function promptHasContextVariable(string $prompt, string $name): bool
    {
        $quotedName = preg_quote($name, '/');

        return preg_match('/\{\{\s*'.$quotedName.'\s*\}\}/iu', $prompt) === 1
            || preg_match('/\{\{#if\s+'.$quotedName.'\s*\}\}/iu', $prompt) === 1;
    }

    private function stripReservedPromptPlaceholders(string $prompt): string
    {
        foreach (['language', 'audience', 'SkillPrompt'] as $name) {
            $quotedName = preg_quote($name, '/');
            $prompt = preg_replace('/\{\{#if\s+'.$quotedName.'\s*\}\}.*?\{\{\/if\}\}/isu', '', $prompt) ?? $prompt;
            $prompt = preg_replace('/\{\{\s*'.$quotedName.'\s*\}\}/iu', '', $prompt) ?? $prompt;
        }

        return trim($prompt);
    }

    /**
     * 渲染任务上下文变量，兼容 {{Knowledge}} 与 {{knowledge}} 等大小写写法。
     *
     * @param  array{title:string, keyword:string, knowledge:string}  $context
     */
    private function renderPromptTemplate(string $prompt, array $context): string
    {
        $renderedPrompt = preg_replace_callback('/\{\{#if\s+([A-Za-z_][A-Za-z0-9_]*)\s*\}\}(.*?)\{\{\/if\}\}/su', function (array $matches) use ($context): string {
            $name = (string) ($matches[1] ?? '');
            if (! $this->isKnownPromptContextName($name)) {
                return (string) ($matches[0] ?? '');
            }

            $value = $this->promptContextValue($name, $context);

            return trim($value) !== '' ? (string) ($matches[2] ?? '') : '';
        }, $prompt) ?? $prompt;

        return preg_replace_callback('/\{\{\s*([A-Za-z_][A-Za-z0-9_]*)\s*\}\}/u', function (array $matches) use ($context): string {
            $name = (string) ($matches[1] ?? '');
            $value = $this->promptContextValue($name, $context);

            return $value !== '' || $this->isKnownPromptContextName($name) ? $value : (string) ($matches[0] ?? '');
        }, $renderedPrompt) ?? $renderedPrompt;
    }

    /**
     * @param  array{title:string, keyword:string, knowledge:string}  $context
     */
    private function promptContextValue(string $name, array $context): string
    {
        return match (mb_strtolower($name, 'UTF-8')) {
            'title' => $context['title'],
            'keyword' => $context['keyword'],
            'knowledge' => $context['knowledge'],
            default => '',
        };
    }

    private function isKnownPromptContextName(string $name): bool
    {
        return in_array(mb_strtolower($name, 'UTF-8'), ['title', 'keyword', 'knowledge'], true);
    }

    private function appendSmartPromptContext(
        string $prompt,
        string $title,
        string $keyword,
        string $knowledgeContext,
        string $targetLanguage,
        bool $includeTitle = true,
        bool $includeKeyword = true,
        bool $includeKnowledge = true
    ): string {
        if ($targetLanguage !== 'zh') {
            $lines = ['Task context:'];
            if ($includeTitle) {
                $lines[] = '- Article title: '.$title;
            }
            if ($includeKeyword && trim($keyword) !== '') {
                $lines[] = '- Core keyword: '.$keyword;
            }
            if ($includeKnowledge && trim($knowledgeContext) !== '') {
                $lines[] = '- Reference knowledge:';
                $lines[] = $knowledgeContext;
            }

            return trim($prompt)."\n\n".implode("\n", $lines);
        }

        $lines = ['【任务上下文】'];
        if ($includeTitle) {
            $lines[] = '- 文章标题：'.$title;
        }
        if ($includeKeyword && trim($keyword) !== '') {
            $lines[] = '- 核心关键词：'.$keyword;
        }
        if ($includeKnowledge && trim($knowledgeContext) !== '') {
            $lines[] = '- 参考知识：';
            $lines[] = $knowledgeContext;
        }

        return trim($prompt)."\n\n".implode("\n", $lines);
    }

    private function finalPromptInstruction(string $targetLanguage): string
    {
        $instruction = match ($targetLanguage) {
            'en' => 'The final article must be written entirely in English. Output only the final article body in Markdown. The page template renders the article title. Do not output an H1 heading in the body. Do not repeat the prompt or output placeholders. Let the title, evidence, and reader decision determine the structure; do not force FAQ, table, key takeaways, introduction, or conclusion modules. End with a complete prose sentence, not a heading, list item, table row, colon, or unfinished module. If the output budget is tight, shorten earlier sections instead of starting content you cannot finish.',
            default => '请直接输出最终中文文章正文（Markdown）。全文必须使用中文。页面模板会显示文章标题，因此正文不要输出 H1 标题。不要重复提示词、不要输出占位符。让标题、证据和读者决策决定文章结构；不要强制加入 FAQ、表格、要点、引言或总结模块。必须以完整的正文句子结束，不能停在标题、列表项、表格行、冒号或未完成模块处。如果输出额度不足，应缩短前文，不能开启无法完成的新内容。',
        };

        return $instruction."\n".($targetLanguage === 'zh'
            ? '不要自行插入站内链接；草稿审核页会单独处理内链建议。'
            : 'Do not insert internal links yourself; the draft review page handles internal link suggestions separately.');
    }

    private function promptContentHash(?Prompt $prompt): ?string
    {
        if ($prompt === null) {
            return null;
        }

        $content = preg_replace('/\R/u', "\n", trim((string) $prompt->content)) ?? trim((string) $prompt->content);

        return hash('sha256', $content);
    }

    /** @return array<string,mixed>|null */
    private function promptTraceReference(?Prompt $prompt): ?array
    {
        if ($prompt === null) {
            return null;
        }

        return [
            'id' => (int) $prompt->id,
            'name' => (string) $prompt->name,
            'type' => (string) $prompt->type,
            'preset_key' => trim((string) ($prompt->preset_key ?? '')) ?: null,
            'preset_version' => trim((string) ($prompt->preset_version ?? '')) ?: null,
        ];
    }

    private function isLikelyEnglishPrompt(string $prompt): bool
    {
        preg_match_all('/\p{Han}/u', $prompt, $cjkMatches);
        preg_match_all('/[A-Za-z]/', $prompt, $latinMatches);

        return count($latinMatches[0] ?? []) > 20 && count($cjkMatches[0] ?? []) <= 3;
    }

    /**
     * 按任务配置检索知识库上下文并回填到 {{Knowledge}}。
     *
     * 支持两种范围：
     * - knowledge_base_id：单个固定知识库；
     * - knowledge_tag_filter：跨所有命中标签的知识库、Entity DB 和 Case DB。
     */
    private function resolveKnowledgeContext(Task $task, string $title, string $keyword, bool $generationSafeOnly = false): string
    {
        $result = $this->ragRetrievalService->retrieveForTask($task, $title, $keyword);
        $this->lastEvidencePackage = array_key_exists('evidence_package', $result)
            ? (is_array($result['evidence_package']) ? array_values($result['evidence_package']) : [])
            : null;
        $trace = is_array($result['trace'] ?? null) ? $result['trace'] : [];
        $this->lastKnowledgeChunkTrace = is_array($trace['chunks'] ?? null) ? $trace['chunks'] : [];
        $this->lastEntityCaseTrace = [
            'entities' => is_array($trace['entities'] ?? null) ? $trace['entities'] : [],
            'cases' => is_array($trace['cases'] ?? null) ? $trace['cases'] : [],
        ];
        $this->lastKnowledgeTrace = $trace;

        if ($generationSafeOnly) {
            if (! array_key_exists('generation_context', $result)
                || ! is_string($result['generation_context'])
                || trim($result['generation_context']) === '') {
                throw new RuntimeException('深度生成缺少安全证据上下文，已在调用模型前停止');
            }

            return $result['generation_context'];
        }

        return (string) ($result['context'] ?? '');
    }

    /**
     * @return list<int>
     */
    private function resolveKnowledgeBaseIds(Task $task): array
    {
        $ids = [];
        $knowledgeBaseId = (int) ($task->knowledge_base_id ?? 0);
        if ($knowledgeBaseId > 0) {
            $ids[] = $knowledgeBaseId;
        }

        $tagFilters = $this->taskTagFilters($task);
        if ($tagFilters !== []) {
            $tagKnowledgeBaseIds = KnowledgeBase::query()
                ->whereHas('tags', fn ($query) => $this->addExactTagFilterConditions($query, $tagFilters))
                ->pluck('id')
                ->map(static fn ($id): int => (int) $id)
                ->all();
            $ids = array_merge($ids, $tagKnowledgeBaseIds);
        }

        return array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));
    }

    /**
     * @return list<array{group_name:string,name:string}>
     */
    private function taskTagFilters(Task $task): array
    {
        $tagFilter = trim((string) ($task->knowledge_tag_filter ?? ''));

        return $tagFilter === '' ? [] : $this->tagService->parseTagText($tagFilter);
    }

    /**
     * @param  list<array{group_name:string,name:string}>  $tagFilters
     */
    private function addExactTagFilterConditions($query, array $tagFilters): void
    {
        $query->where(function ($nested) use ($tagFilters): void {
            foreach ($tagFilters as $tagFilter) {
                $groupName = trim((string) ($tagFilter['group_name'] ?? ''));
                $name = trim((string) ($tagFilter['name'] ?? ''));
                if ($name === '') {
                    continue;
                }

                $nested->orWhere(function ($tagQuery) use ($groupName, $name): void {
                    if ($groupName !== '') {
                        $tagQuery
                            ->where('group_name', $groupName)
                            ->where('name', $name);

                        return;
                    }

                    $tagQuery->where('name', $name);
                });
            }
        });
    }

    /**
     * @param  list<array{group_name:string,name:string}>  $tagFilters
     */
    private function composeTaggedEntityCaseContext(array $tagFilters, int $maxChars): string
    {
        if ($tagFilters === []) {
            return '';
        }

        $entities = EntityRecord::query()
            ->whereHas('tags', fn ($query) => $this->addExactTagFilterConditions($query, $tagFilters))
            ->orderBy('name')
            ->limit(12)
            ->get(['id', 'name', 'entity_type', 'aliases', 'description', 'attributes_json', 'canonical_url', 'link_policy']);

        $cases = CaseRecord::query()
            ->with('entities:id,name')
            ->where(function ($query) use ($tagFilters): void {
                $query
                    ->whereHas('tags', fn ($tagQuery) => $this->addExactTagFilterConditions($tagQuery, $tagFilters))
                    ->orWhereHas('entity.tags', fn ($tagQuery) => $this->addExactTagFilterConditions($tagQuery, $tagFilters));
            })
            ->orderByDesc('id')
            ->limit(12)
            ->get(['id', 'entity_id', 'title', 'case_type', 'summary', 'challenge', 'solution', 'result', 'metrics']);

        if ($entities->isEmpty() && $cases->isEmpty()) {
            return '';
        }

        $this->lastEntityCaseTrace = [
            'entities' => $entities
                ->map(static fn (EntityRecord $entity): array => [
                    'id' => (int) $entity->id,
                    'name' => (string) $entity->name,
                    'type' => (string) ($entity->entity_type ?? ''),
                    'role' => EntityTypes::roleDescription((string) ($entity->entity_type ?? '')),
                    'linkable' => EntityTypes::isLinkable((string) ($entity->entity_type ?? ''))
                        && (string) ($entity->link_policy ?? '') === EntityTypes::LINK_POLICY_SUGGEST
                        && trim((string) ($entity->canonical_url ?? '')) !== '',
                ])
                ->values()
                ->all(),
            'cases' => $cases
                ->map(static fn (CaseRecord $caseRecord): array => [
                    'id' => (int) $caseRecord->id,
                    'title' => (string) $caseRecord->title,
                    'type' => (string) ($caseRecord->case_type ?? ''),
                    'role' => CaseTypes::referenceRule((string) ($caseRecord->case_type ?? '')),
                    'entity_id' => $caseRecord->entity_id !== null ? (int) $caseRecord->entity_id : null,
                    'entity_name' => (string) (($e = $caseRecord->entities->first()) ? $e->name : ''),
                ])
                ->values()
                ->all(),
        ];

        $lines = [];
        if ($entities->isNotEmpty()) {
            $lines[] = '【Entity DB 参考】';
            foreach ($entities as $entity) {
                $line = '- 实体：'.(string) $entity->name;
                if ((string) ($entity->entity_type ?? '') !== '') {
                    $line .= '（类型：'.(string) $entity->entity_type.'）';
                }
                $lines[] = $line;
                $lines[] = '  写作角色：'.EntityTypes::roleDescription((string) ($entity->entity_type ?? ''));
                if ((string) ($entity->aliases ?? '') !== '') {
                    $lines[] = '  别名：'.$this->shortContextText($entity->aliases, 180);
                }
                if ((string) ($entity->description ?? '') !== '') {
                    $lines[] = '  描述：'.$this->shortContextText($entity->description, 320);
                }
                if ((string) ($entity->attributes_json ?? '') !== '' && trim((string) $entity->attributes_json) !== '{}') {
                    $lines[] = '  属性：'.$this->shortContextText($entity->attributes_json, 260);
                }
            }
        }

        if ($cases->isNotEmpty()) {
            $lines[] = '【Case DB 参考】';
            foreach ($cases as $caseRecord) {
                $line = '- 案例：'.(string) $caseRecord->title;
                if ((string) ($caseRecord->case_type ?? '') !== '') {
                    $line .= '（类型：'.(string) $caseRecord->case_type.'）';
                }
                if (($e = $caseRecord->entities->first())) {
                    $line .= '，关联实体：'.(string) $e->name;
                }
                $lines[] = $line;
                $lines[] = '  引用规则：'.CaseTypes::referenceRule((string) ($caseRecord->case_type ?? ''));

                foreach ([
                    'summary' => '摘要',
                    'challenge' => '挑战',
                    'solution' => '方案',
                    'result' => '结果',
                    'metrics' => '指标',
                ] as $field => $label) {
                    $value = (string) ($caseRecord->{$field} ?? '');
                    if ($value !== '') {
                        $lines[] = '  '.$label.'：'.$this->shortContextText($value, 260);
                    }
                }
            }
        }

        $context = trim(implode("\n", $lines));

        return mb_strlen($context, 'UTF-8') > $maxChars
            ? mb_substr($context, 0, $maxChars, 'UTF-8').'...'
            : $context;
    }

    private function shortContextText(mixed $value, int $maxChars): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', (string) $value));

        return mb_strlen($text, 'UTF-8') > $maxChars
            ? mb_substr($text, 0, $maxChars, 'UTF-8').'...'
            : $text;
    }

    /**
     * 从 knowledge_chunks 中检索相关片段。
     *
     * @param  list<int>  $knowledgeBaseIds
     */
    private function fetchKnowledgeContextFromChunks(array $knowledgeBaseIds, string $query, int $limit, int $maxChars): string
    {
        $knowledgeBaseIds = array_values(array_unique(array_filter($knowledgeBaseIds, static fn (int $id): bool => $id > 0)));
        if ($knowledgeBaseIds === []) {
            return '';
        }

        if (trim($query) !== '') {
            $vectorRows = $this->fetchKnowledgeChunksByPgvector($knowledgeBaseIds, $query, max($limit * 3, 8));
            if ($vectorRows !== []) {
                return $this->composeKnowledgeContext($vectorRows, $limit, $maxChars);
            }
        }

        $rows = DB::table('knowledge_chunks as kc')
            ->join('knowledge_bases as kb', 'kb.id', '=', 'kc.knowledge_base_id')
            ->whereIn('kc.knowledge_base_id', $knowledgeBaseIds)
            ->orderBy('kc.knowledge_base_id')
            ->orderBy('kc.chunk_index')
            ->get([
                'kc.knowledge_base_id',
                'kb.name as knowledge_base_name',
                'kc.chunk_index',
                'kc.content',
                'kc.embedding_json',
                'kc.embedding_model_id',
                'kc.embedding_dimensions',
            ])
            ->all();
        if ($rows === []) {
            return '';
        }

        $queryTerms = $this->termFrequencies($query);
        $hasRealEmbeddingRows = collect($rows)->contains(
            fn ($row): bool => $this->chunkHasRealEmbedding($row)
        );
        $useRealEmbeddingScore = false;
        $queryVector = [];
        if ($hasRealEmbeddingRows && trim($query) !== '') {
            $queryVector = $this->knowledgeChunkSyncService->generateQueryEmbeddingVector($query);
            $useRealEmbeddingScore = $queryVector !== [];
        }
        if ($queryVector === []) {
            $queryVector = $this->decodeVector(json_encode($this->buildFallbackVector($query, 256)));
        }

        $scored = [];
        foreach ($rows as $row) {
            $content = trim((string) ($row->content ?? ''));
            if ($content === '') {
                continue;
            }

            $vector = $this->decodeVector((string) ($row->embedding_json ?? ''));
            $chunkTerms = $this->termFrequencies($content);
            $lexicalScore = $this->lexicalScore($queryTerms, $chunkTerms);
            $chunkUsesRealEmbedding = $this->chunkHasRealEmbedding($row);
            $vectorScore = ($useRealEmbeddingScore === $chunkUsesRealEmbedding)
                ? $this->dotProduct($queryVector, $vector)
                : 0.0;
            $score = ($vectorScore * 0.75) + ($lexicalScore * 0.25);

            $scored[] = [
                'knowledge_base_id' => (int) ($row->knowledge_base_id ?? 0),
                'knowledge_base_name' => (string) ($row->knowledge_base_name ?? ''),
                'chunk_index' => (int) ($row->chunk_index ?? 0),
                'content' => $content,
                'score' => $score,
            ];
        }

        usort($scored, static function (array $a, array $b): int {
            $diff = ($b['score'] <=> $a['score']);

            return $diff !== 0 ? $diff : (($a['knowledge_base_id'] <=> $b['knowledge_base_id']) ?: ($a['chunk_index'] <=> $b['chunk_index']));
        });

        return $this->composeKnowledgeContext($scored, $limit, $maxChars);
    }

    /**
     * 判断 chunk 是否保存了真实 embedding，而不是 fallback hash 向量。
     */
    private function chunkHasRealEmbedding(object $row): bool
    {
        return (int) ($row->embedding_model_id ?? 0) > 0
            && (int) ($row->embedding_dimensions ?? 0) > 0;
    }

    /**
     * 按任务图片配置插入 Markdown 配图并返回被选中的图片列表。
     *
     * @return array{content:string,images:list<Image>}
     */
    private function insertTaskImagesIntoContent(Task $task, string $content): array
    {
        $libraryId = (int) ($task->image_library_id ?? 0);
        $imageCount = max(0, (int) ($task->image_count ?? 0));
        if ($libraryId <= 0 || $imageCount <= 0) {
            return ['content' => $content, 'images' => []];
        }

        $imageQuery = Image::query()->where('library_id', $libraryId);
        $imageTagFilters = $this->taskImageTagFilters($task);
        if ($imageTagFilters !== []) {
            $imageQuery->whereHas('tags', fn ($query) => $this->addExactTagFilterConditions($query, $imageTagFilters));
        }

        /** @var list<Image> $images */
        $images = $imageQuery
            ->inRandomOrder()
            ->limit($imageCount)
            ->get(['id', 'file_path', 'original_name'])
            ->all();
        if ($images === []) {
            return ['content' => $content, 'images' => []];
        }

        $markdownBlocks = [];
        foreach ($images as $image) {
            $path = trim((string) ($image->file_path ?? ''));
            if ($path === '') {
                continue;
            }
            $path = ImageUrlNormalizer::toPublicUrl($path);
            $alt = ImageUrlNormalizer::readableAlt((string) ($image->original_name ?? ''));
            $markdownBlocks[] = '!['.($alt !== '' ? $alt : 'image').']('.$path.')';
        }

        if ($markdownBlocks !== []) {
            $content = $this->insertImagesByParagraphInterval($content, $markdownBlocks);
        }

        return ['content' => $content, 'images' => $images];
    }

    /**
     * @return list<array{group_name:string,name:string}>
     */
    private function taskImageTagFilters(Task $task): array
    {
        $tagFilter = trim((string) ($task->image_tag_filter ?? ''));

        return $tagFilter === '' ? [] : $this->tagService->parseTagText($tagFilter);
    }

    /**
     * 按段落间隔插入图片，避免全部堆在文末。
     *
     * @param  list<string>  $markdownBlocks
     */
    private function insertImagesByParagraphInterval(string $content, array $markdownBlocks): string
    {
        $trimmed = trim($content);
        if ($trimmed === '' || $markdownBlocks === []) {
            return $content;
        }

        $paragraphs = preg_split("/\n{2,}/u", $trimmed, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($paragraphs === []) {
            return $trimmed."\n\n".implode("\n\n", $markdownBlocks);
        }

        $paragraphCount = count($paragraphs);
        $imageCount = count($markdownBlocks);
        $interval = max(1, (int) floor($paragraphCount / ($imageCount + 1)));

        $parts = [];
        $imageIndex = 0;
        foreach ($paragraphs as $index => $paragraph) {
            $parts[] = trim((string) $paragraph);
            $nextParagraphPosition = $index + 1;

            if (
                $imageIndex < $imageCount
                && $nextParagraphPosition % $interval === 0
                && $nextParagraphPosition < $paragraphCount
            ) {
                $parts[] = $markdownBlocks[$imageIndex];
                $imageIndex++;
            }
        }

        while ($imageIndex < $imageCount) {
            $parts[] = $markdownBlocks[$imageIndex];
            $imageIndex++;
        }

        return implode("\n\n", array_values(array_filter($parts, static fn (string $part): bool => trim($part) !== '')));
    }

    /**
     * 调用任务配置模型生成正文。
     */
    private function generateContent(AiModel $aiModel, string $contentPrompt): string
    {
        return $this->articleModelCallService->generate($aiModel, $contentPrompt);
    }

    /**
     * 从正文提取摘要，避免把完整提示词原文当摘要。
     */
    private function buildExcerpt(string $content): string
    {
        $plain = preg_replace('/[`#>*_\-\[\]\(\)]/u', ' ', $content) ?: $content;
        $plain = preg_replace('/\s+/u', ' ', $plain) ?: $plain;
        $plain = trim($plain);
        if ($plain === '') {
            return 'AI 生成内容摘要';
        }

        return mb_substr($plain, 0, 180);
    }

    /**
     * @return array<string,int>
     */
    private function termFrequencies(string $text): array
    {
        $tokens = preg_split('/[^\p{L}\p{N}_]+/u', mb_strtolower(trim($text), 'UTF-8')) ?: [];
        $frequencies = [];
        foreach ($tokens as $token) {
            $token = trim((string) $token);
            if ($token === '' || mb_strlen($token, 'UTF-8') <= 1) {
                continue;
            }
            $frequencies[$token] = (int) ($frequencies[$token] ?? 0) + 1;
        }

        return $frequencies;
    }

    /**
     * @param  array<string,int>  $queryTerms
     * @param  array<string,int>  $chunkTerms
     */
    private function lexicalScore(array $queryTerms, array $chunkTerms): float
    {
        if ($queryTerms === [] || $chunkTerms === []) {
            return 0.0;
        }

        $matched = 0;
        $total = 0;
        foreach ($queryTerms as $term => $count) {
            $total += $count;
            if (isset($chunkTerms[$term])) {
                $matched += min($count, (int) $chunkTerms[$term]);
            }
        }

        return $total > 0 ? ($matched / $total) : 0.0;
    }

    /**
     * @return list<float>
     */
    private function decodeVector(string $json): array
    {
        $decoded = json_decode($json, true);
        if (! is_array($decoded) || $decoded === []) {
            return [];
        }

        $vector = [];
        foreach ($decoded as $value) {
            if (is_numeric($value)) {
                $vector[] = (float) $value;
            }
        }

        return $vector;
    }

    /**
     * @param  list<float>  $left
     * @param  list<float>  $right
     */
    private function dotProduct(array $left, array $right): float
    {
        if ($left === [] || $right === []) {
            return 0.0;
        }
        $sum = 0.0;
        $limit = min(count($left), count($right));
        for ($i = 0; $i < $limit; $i++) {
            $sum += ((float) $left[$i]) * ((float) $right[$i]);
        }

        return $sum;
    }

    /**
     * @return list<float>
     */
    private function buildFallbackVector(string $text, int $dimensions): array
    {
        $dimensions = max(1, $dimensions);
        $vector = array_fill(0, $dimensions, 0.0);
        foreach ($this->termFrequencies($text) as $token => $count) {
            $indexSeed = abs((int) crc32('i:'.$token));
            $signSeed = abs((int) crc32('s:'.$token));
            $index = $indexSeed % $dimensions;
            $sign = ($signSeed % 2 === 0) ? 1.0 : -1.0;
            $tokenLength = max(1, mb_strlen($token, 'UTF-8'));
            $weight = (1.0 + log(1 + $count)) * min(2.0, 0.8 + ($tokenLength / 4));
            $vector[$index] += $sign * $weight;
        }

        $norm = 0.0;
        foreach ($vector as $value) {
            $norm += $value * $value;
        }
        if ($norm > 0.0) {
            $norm = sqrt($norm);
            foreach ($vector as $index => $value) {
                $vector[$index] = $value / $norm;
            }
        }

        return $vector;
    }

    /**
     * 优先使用 pgvector 执行数据库向量检索，命中则返回候选块。
     *
     * @param  list<int>  $knowledgeBaseIds
     * @return list<array{knowledge_base_id:int,knowledge_base_name:string,chunk_index:int,content:string,score:float}>
     */
    private function fetchKnowledgeChunksByPgvector(array $knowledgeBaseIds, string $query, int $candidateLimit): array
    {
        if (! $this->canUsePgvectorSearch()) {
            return [];
        }
        $knowledgeBaseIds = array_values(array_unique(array_filter($knowledgeBaseIds, static fn (int $id): bool => $id > 0)));
        if ($knowledgeBaseIds === []) {
            return [];
        }

        $vectorLiteral = $this->knowledgeChunkSyncService->generateQueryVectorLiteral($query);
        if ($vectorLiteral === '') {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($knowledgeBaseIds), '?'));

        $rows = DB::select(
            "
                SELECT kc.knowledge_base_id, kb.name AS knowledge_base_name, kc.chunk_index, kc.content,
                       (kc.embedding_vector <=> CAST(? AS vector)) AS vector_distance
                FROM knowledge_chunks kc
                JOIN knowledge_bases kb ON kb.id = kc.knowledge_base_id
                WHERE kc.knowledge_base_id IN ({$placeholders})
                  AND kc.embedding_vector IS NOT NULL
                ORDER BY kc.embedding_vector <=> CAST(? AS vector), kc.chunk_index ASC
                LIMIT ?
            ",
            array_merge([$vectorLiteral], $knowledgeBaseIds, [$vectorLiteral, max(1, $candidateLimit)])
        );

        $results = [];
        foreach ($rows as $row) {
            $content = trim((string) ($row->content ?? ''));
            if ($content === '') {
                continue;
            }
            $distance = (float) ($row->vector_distance ?? 1.0);
            $results[] = [
                'knowledge_base_id' => (int) ($row->knowledge_base_id ?? 0),
                'knowledge_base_name' => (string) ($row->knowledge_base_name ?? ''),
                'chunk_index' => (int) ($row->chunk_index ?? 0),
                'content' => $content,
                'score' => 1.0 - $distance,
            ];
        }

        return $results;
    }

    /**
     * 仅在 PostgreSQL 且 pgvector 可用时启用向量检索。
     */
    private function canUsePgvectorSearch(): bool
    {
        if (DB::getDriverName() !== 'pgsql') {
            return false;
        }

        try {
            $typeRow = DB::selectOne("
                SELECT EXISTS (
                    SELECT 1 FROM pg_type WHERE typname = 'vector'
                ) AS ok
            ");
            if (! $typeRow || ! (bool) ($typeRow->ok ?? false)) {
                return false;
            }

            $columnRow = DB::selectOne("
                SELECT EXISTS (
                    SELECT 1
                    FROM information_schema.columns
                    WHERE table_name = 'knowledge_chunks'
                      AND column_name = 'embedding_vector'
                ) AS ok
            ");

            return $columnRow !== null && (bool) ($columnRow->ok ?? false);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * 从候选块拼装知识上下文，按片段顺序输出。
     *
     * @param  list<array{knowledge_base_id?:int,knowledge_base_name?:string,chunk_index:int,content:string,score:float}>  $scored
     */
    private function composeKnowledgeContext(array $scored, int $limit, int $maxChars): string
    {
        if ($scored === []) {
            $this->lastKnowledgeChunkTrace = [];

            return '';
        }

        $selected = array_slice($scored, 0, max(1, $limit));
        usort($selected, static fn (array $a, array $b): int => (($a['knowledge_base_id'] ?? 0) <=> ($b['knowledge_base_id'] ?? 0)) ?: ($a['chunk_index'] <=> $b['chunk_index']));
        $this->lastKnowledgeChunkTrace = array_map(static fn (array $chunk): array => [
            'knowledge_base_id' => (int) ($chunk['knowledge_base_id'] ?? 0),
            'knowledge_base_name' => (string) ($chunk['knowledge_base_name'] ?? ''),
            'chunk_index' => (int) ($chunk['chunk_index'] ?? 0),
            'score' => round((float) ($chunk['score'] ?? 0), 6),
            'preview' => mb_substr(trim((string) ($chunk['content'] ?? '')), 0, 160, 'UTF-8'),
        ], $selected);

        $parts = [];
        $charCount = 0;
        foreach ($selected as $index => $chunk) {
            $content = trim((string) ($chunk['content'] ?? ''));
            if ($content === '') {
                continue;
            }
            $nextLength = $charCount + mb_strlen($content, 'UTF-8');
            if ($parts !== [] && $nextLength > $maxChars) {
                continue;
            }
            $source = trim((string) ($chunk['knowledge_base_name'] ?? ''));
            $heading = '【知识片段'.($index + 1).($source !== '' ? ' / 知识库：'.$source : '').'】';
            $parts[] = $heading."\n".$content;
            $charCount = $nextLength;
        }

        return trim(implode("\n\n", $parts));
    }

    /**
     * @param  list<KnowledgeBase>  $knowledgeBases
     */
    private function composeFallbackKnowledgeContent(array $knowledgeBases, int $maxChars): string
    {
        $parts = [];
        $charCount = 0;
        foreach ($knowledgeBases as $knowledgeBase) {
            $content = trim((string) ($knowledgeBase->content ?? ''));
            if ($content === '') {
                continue;
            }
            $name = trim((string) ($knowledgeBase->name ?? ''));
            $block = ($name !== '' ? "【知识库：{$name}】\n" : '').$content;
            $blockLength = mb_strlen($block, 'UTF-8');
            if ($parts !== [] && $charCount + $blockLength > $maxChars) {
                $remaining = $maxChars - $charCount;
                if ($remaining <= 120) {
                    break;
                }
                $block = mb_substr($block, 0, $remaining, 'UTF-8');
                $blockLength = mb_strlen($block, 'UTF-8');
            }
            $parts[] = $block;
            $charCount += $blockLength;
            if ($charCount >= $maxChars) {
                break;
            }
        }

        return trim(implode("\n\n", $parts));
    }
}
