<?php

namespace App\Services\GeoFlow;

use App\Support\GeoFlow\ArticleSkillIntents;
use InvalidArgumentException;

class ArticleSkillOutputEvaluator
{
    private const MAX_ESTIMATED_PROMPT_TOKENS = 4000;

    public function __construct(
        private readonly SkillPromptRecommendationService $recommendationService,
        private readonly PromptPresetCatalog $promptPresetCatalog,
        private readonly ArticleSkillReleaseGate $releaseGate
    ) {}

    /**
     * @param  list<array<string,mixed>>  $cases
     * @param  list<array<string,mixed>>  $outputs
     * @param  array<string,mixed>  $model
     * @param  array<string,array<string,mixed>>  $pmReviews
     * @param  list<array<string,mixed>>  $pairedControls
     * @return array<string,mixed>
     */
    public function evaluate(array $cases, array $outputs, array $model, array $pmReviews = [], array $pairedControls = []): array
    {
        $isRealModel = ($model['is_real_model'] ?? null) === true;
        $this->assertOutputSetMatchesCases($cases, $outputs);
        $outputsByCase = collect($outputs)->keyBy(fn (array $output): string => (string) ($output['case_id'] ?? ''));
        $promptSizes = $this->promptSizes();
        $results = [];

        foreach ($cases as $case) {
            $id = (string) $case['id'];
            $output = $outputsByCase->get($id);
            $content = (string) (($output['content'] ?? ''));
            $classification = $this->recommendationService->classifyTitle(trim((string) $case['title'].' '.(string) $case['keyword']));
            $actualIntent = $classification['intent'] ?? null;
            $expectedIntent = $case['expected_intent'] ?? null;
            $actualStatus = $actualIntent === null
                ? 'master_only'
                : (in_array($actualIntent, ArticleSkillIntents::autoEligible(), true) ? 'recommended' : 'blocked');

            $checks = [
                'routing' => $this->check($actualIntent === $expectedIntent && $actualStatus === $case['expected_status'], [
                    'expected_intent' => $expectedIntent,
                    'actual_intent' => $actualIntent,
                    'expected_status' => $case['expected_status'],
                    'actual_status' => $actualStatus,
                    'confidence' => $classification['confidence'] ?? null,
                ]),
                'prompt_size' => $this->promptSizeCheck($expectedIntent, $promptSizes),
                'language_consistency' => $this->languageCheck($content, (string) $case['language']),
                'no_body_h1' => $this->check(preg_match('/^#\s+/mu', $content) !== 1),
                'heading_density' => $this->headingDensityCheck($content),
                'single_sentence_sections' => $this->singleSentenceSectionCheck($content),
                'duplicate_modules' => $this->duplicateModuleCheck($content),
                'generic_module_density' => $this->genericModuleDensityCheck($content),
                'paragraph_fragmentation' => $this->paragraphFragmentationCheck($content),
                'section_information_gain' => $this->sectionInformationGainCheck($content),
                'style_fitness' => $this->styleFitnessCheck(is_array($output) ? $output : [], $content),
                'style_boundary' => $this->styleBoundaryCheck(is_array($output) ? $output : []),
                'case_evidence_state' => $this->caseEvidenceCheck($case, $actualStatus),
                'case_privacy' => $this->casePrivacyCheck($case, $content),
                'troubleshooting_safety' => $this->troubleshootingSafetyCheck($case, $content),
            ];

            $results[] = [
                'id' => $id,
                'intent' => $expectedIntent,
                'variant' => (string) $case['variant'],
                'output_sha256' => hash('sha256', $content),
                'output_chars' => mb_strlen($content),
                'checks' => $checks,
                'automatic_passed' => collect($checks)->every(fn (array $check): bool => $check['passed']),
            ];
        }

        $corpusChecks = $this->corpusTemplateChecks($cases, $outputsByCase->all());
        $templatePatternWarnings = collect($corpusChecks)->where('passed', false)->count();
        $automaticFailures = collect($results)->where('automatic_passed', false)->count();
        $eligibleCaseIds = collect($cases)
            ->where('expected_status', 'recommended')
            ->pluck('id')
            ->map(fn ($id): string => (string) $id)
            ->all();
        $validPairRows = collect($pairedControls)
            ->filter(function (mixed $pair) use ($eligibleCaseIds, $outputsByCase): bool {
                if (! is_array($pair) || (! is_string($pair['case_id'] ?? null) && ! is_int($pair['case_id'] ?? null))) {
                    return false;
                }
                $caseId = (string) $pair['case_id'];
                $expectedOutput = $outputsByCase->get($caseId);
                $masterContent = $pair['master_content'] ?? null;
                $skillContent = $pair['skill_content'] ?? null;

                return in_array($caseId, $eligibleCaseIds, true)
                    && is_string($masterContent) && trim($masterContent) !== ''
                    && is_string($skillContent) && trim($skillContent) !== ''
                    && is_array($expectedOutput)
                    && hash_equals(hash('sha256', (string) $expectedOutput['content']), hash('sha256', $skillContent))
                    && preg_match('/\A[0-9a-f]{64}\z/i', (string) ($pair['shared_context_sha256'] ?? '')) === 1
                    && preg_match('/\A[0-9a-f]{64}\z/i', (string) ($pair['model_config_sha256'] ?? '')) === 1;
            })
            ->values();
        $duplicatePairCaseIds = $validPairRows
            ->countBy(fn (array $pair): string => (string) $pair['case_id'])
            ->filter(fn (int $count): bool => $count > 1)
            ->keys()
            ->values()
            ->all();
        $sanitizedPairs = $validPairRows
            ->reject(fn (array $pair): bool => in_array((string) $pair['case_id'], $duplicatePairCaseIds, true))
            ->map(fn (array $pair): array => [
                'case_id' => (string) $pair['case_id'],
                'master_sha256' => hash('sha256', (string) $pair['master_content']),
                'skill_sha256' => hash('sha256', (string) $pair['skill_content']),
                'shared_context_sha256' => strtolower((string) $pair['shared_context_sha256']),
                'model_config_sha256' => strtolower((string) $pair['model_config_sha256']),
                'master_scores' => $this->releaseScores($pair['master_scores'] ?? null),
                'control_workflow_mode' => $this->workflowMode($pair['workflow_mode'] ?? null),
                'control_stages' => $this->stageNames($pair['stages'] ?? []),
                'provenance_verified' => $this->pairedProvenanceVerified($pair),
            ])
            ->values()
            ->all();
        $pairedCaseIds = collect($sanitizedPairs)->pluck('case_id')->all();
        $baseScoreKeys = [
            'factual_support',
            'clarity',
            'buyer_decision_value',
            'structure_naturalness',
            'uncertainty_and_negative_fit',
            'privacy_and_safety',
            'style_fitness',
            'non_template_naturalness',
        ];
        $scoreKeys = [
            ...$baseScoreKeys,
            'improvement_over_master_only',
        ];
        $pmTemplate = array_map(function (array $case) use ($pmReviews, $scoreKeys, $baseScoreKeys, $pairedCaseIds): array {
            $review = $pmReviews[(string) $case['id']] ?? [];
            $scores = is_array($review['scores'] ?? null) ? $review['scores'] : [];
            $improvementApplicable = in_array((string) $case['id'], $pairedCaseIds, true);

            return [
                'case_id' => (string) $case['id'],
                'reviewer' => isset($review['reviewer']) ? trim((string) $review['reviewer']) : null,
                'evidence_note' => isset($review['evidence_note']) ? trim((string) $review['evidence_note']) : null,
                'scores' => array_replace(array_fill_keys($scoreKeys, null), array_intersect_key($scores, array_flip($scoreKeys))),
                'required_score_keys' => $improvementApplicable ? $scoreKeys : $baseScoreKeys,
                'improvement_over_master_only_applicable' => $improvementApplicable,
            ];
        }, $cases);
        $pmComplete = collect($pmTemplate)->every(fn (array $review): bool => $review['reviewer'] !== null
            && $review['reviewer'] !== ''
            && $review['evidence_note'] !== null
            && $review['evidence_note'] !== ''
            && collect($review['required_score_keys'])->every(function (string $key) use ($review): bool {
                $score = $review['scores'][$key] ?? null;

                return is_int($score) && $score >= 0 && $score <= 5;
            }));
        $pmThresholdPassed = $pmComplete && collect($pmTemplate)->every(function (array $review) use ($cases): bool {
            $scores = $review['scores'];
            $case = collect($cases)->firstWhere('id', $review['case_id']);
            $restrictedIntent = in_array($case['expected_intent'] ?? null, [ArticleSkillIntents::CASE_STUDY, ArticleSkillIntents::TROUBLESHOOTING], true);
            $releaseCandidate = ($case['expected_status'] ?? null) === 'recommended';

            return collect($review['required_score_keys'])->every(function (string $key) use ($scores, $releaseCandidate): bool {
                $minimum = $releaseCandidate && in_array($key, [
                    'factual_support',
                    'structure_naturalness',
                    'privacy_and_safety',
                    'non_template_naturalness',
                ], true) ? 4 : 3;

                return $scores[$key] >= $minimum;
            })
                && (! $restrictedIntent || $scores['privacy_and_safety'] >= 4);
        });
        $pmReviewsByCase = collect($pmTemplate)->keyBy('case_id');
        $outputsForRelease = $outputsByCase;
        $releaseArtifacts = [];
        foreach ($sanitizedPairs as $pair) {
            $caseId = (string) $pair['case_id'];
            $candidateReview = $pmReviewsByCase->get($caseId);
            $candidateScores = is_array($candidateReview) ? $this->releaseScores($candidateReview['scores'] ?? null) : null;
            $controlScores = $pair['master_scores'] ?? null;
            if ($candidateScores === null || ! is_array($controlScores) || ($pair['provenance_verified'] ?? false) !== true) {
                continue;
            }
            $candidateOutput = $outputsForRelease->get($caseId);
            $candidateWorkflowMode = $this->workflowMode(is_array($candidateOutput) ? ($candidateOutput['workflow_mode'] ?? null) : null);
            $releaseArtifacts[] = [
                'artifact_id' => 'candidate:'.$caseId,
                'cohort' => 'candidate',
                'pair_key' => $caseId,
                'workflow_mode' => $candidateWorkflowMode,
                'stages' => $this->stageNames(is_array($candidateOutput) ? ($candidateOutput['stages'] ?? []) : []),
                'scores' => $candidateScores,
            ];
            $releaseArtifacts[] = [
                'artifact_id' => 'control:'.$caseId,
                'cohort' => 'control',
                'pair_key' => $caseId,
                'workflow_mode' => (string) $pair['control_workflow_mode'],
                'stages' => $pair['control_stages'],
                'scores' => $controlScores,
            ];
        }
        $claimDeepValidation = ($model['evaluation_workflow_mode'] ?? null) === 'deep_pipeline';
        $releaseGateReport = $this->releaseGate->evaluate($releaseArtifacts, [
            'claim_deep_validation' => $claimDeepValidation,
            'expected_pair_keys' => $eligibleCaseIds,
        ]);
        $releaseBlockers = [];
        if (! $isRealModel) {
            $releaseBlockers[] = 'real_model_evaluation_required';
        }
        if (! $pmComplete) {
            $releaseBlockers[] = 'pm_content_review_required';
        } elseif (! $pmThresholdPassed) {
            $releaseBlockers[] = 'pm_score_threshold_failed';
        }
        if ($automaticFailures > 0) {
            $releaseBlockers[] = 'automatic_checks_failed';
        }
        if ($isRealModel && count($sanitizedPairs) < 10) {
            $releaseBlockers[] = 'paired_master_controls_required';
        }
        if ($isRealModel && $duplicatePairCaseIds !== []) {
            $releaseBlockers[] = 'duplicate_paired_controls';
        }
        if ($isRealModel && collect($sanitizedPairs)->where('provenance_verified', true)->count() < count($eligibleCaseIds)) {
            $releaseBlockers[] = 'paired_control_provenance_required';
        }
        if ($isRealModel && $templatePatternWarnings > 0) {
            $releaseBlockers[] = 'template_naturalness_checks_failed';
        }
        if ($isRealModel) {
            $releaseBlockers = array_merge($releaseBlockers, $releaseGateReport['release_blockers']);
        }
        if ($isRealModel) {
            $releaseBlockers[] = 'external_input_provenance_unverified';
        }
        $releaseBlockers[] = 'manual_release_approval_required';
        $releaseBlockers = array_values(array_unique($releaseBlockers));
        $safeModel = $this->safeModelMetadata($model, $isRealModel);
        $safePmTemplate = array_map(function (array $review): array {
            $note = (string) ($review['evidence_note'] ?? '');

            return [
                'case_id' => $review['case_id'],
                'reviewer_present' => trim((string) ($review['reviewer'] ?? '')) !== '',
                'reviewer_sha256' => trim((string) ($review['reviewer'] ?? '')) !== '' ? hash('sha256', trim((string) $review['reviewer'])) : null,
                'evidence_note_present' => $note !== '',
                'evidence_note_sha256' => $note !== '' ? hash('sha256', $note) : null,
                'scores' => $review['scores'],
                'improvement_over_master_only_applicable' => $review['improvement_over_master_only_applicable'],
            ];
        }, $pmTemplate);

        return [
            'schema_version' => 2,
            'threshold_version' => ArticleSkillReleaseGate::THRESHOLD_VERSION,
            'generated_at' => now()->toIso8601String(),
            'evaluation_mode' => $isRealModel ? 'real_model_output' : 'offline_fixture',
            'model' => $safeModel,
            'summary' => [
                'case_count' => count($cases),
                'output_count' => $outputsByCase->filter(fn (array $output): bool => trim((string) ($output['content'] ?? '')) !== '')->count(),
                'routing_passed' => collect($results)->filter(fn (array $result): bool => $result['checks']['routing']['passed'])->count(),
                'automatic_failures' => $automaticFailures,
                'template_pattern_warnings' => $templatePatternWarnings,
                'pm_reviews_complete' => $pmComplete,
                'paired_master_control_count' => count($sanitizedPairs),
                'duplicate_paired_control_case_count' => count($duplicatePairCaseIds),
                'provenance_verified_pair_count' => collect($sanitizedPairs)->where('provenance_verified', true)->count(),
            ],
            'release_decision' => 'no_go',
            'release_blockers' => $releaseBlockers,
            'limitations' => [
                'fixture_results_are_not_quality_evidence' => ! $isRealModel,
                'automatic_report_cannot_approve_release' => true,
                'minimum_paired_master_controls_for_improvement_claim' => 10,
                'external_input_provenance_is_not_cryptographically_verified' => $isRealModel,
                'troubleshooting_safety_check_is_screening_only' => true,
            ],
            'cases' => $results,
            'corpus_checks' => $corpusChecks,
            'paired_master_controls' => $sanitizedPairs,
            'release_gate' => $releaseGateReport,
            'pm_review_template' => $safePmTemplate,
        ];
    }

    /** @return array<string,int> */
    private function promptSizes(): array
    {
        $presets = collect($this->promptPresetCatalog->candidate());
        $master = (string) ($presets->firstWhere('type', 'content')['content'] ?? '');
        $sizes = ['master_only' => (int) ceil(mb_strlen($master) / 4)];
        foreach ($presets->where('type', 'skill') as $skill) {
            $sizes[(string) $skill['intent_key']] = (int) ceil(mb_strlen($master."\n".(string) $skill['content']) / 4);
        }

        return $sizes;
    }

    /** @param array<string,int> $sizes */
    private function promptSizeCheck(?string $intent, array $sizes): array
    {
        $tokens = (int) ($sizes[$intent ?? 'master_only'] ?? PHP_INT_MAX);

        return $this->check($tokens <= self::MAX_ESTIMATED_PROMPT_TOKENS, [
            'estimated_tokens' => $tokens,
            'limit' => self::MAX_ESTIMATED_PROMPT_TOKENS,
            'estimation' => 'characters_divided_by_four',
        ]);
    }

    private function languageCheck(string $content, string $language): array
    {
        $han = preg_match_all('/\p{Han}/u', $content);
        $latinWords = preg_match_all('/\b[A-Za-z][A-Za-z\'-]{2,}\b/u', $content);
        $passed = match ($language) {
            'en' => $han <= 10 && $latinWords >= 20,
            'zh-CN' => $han >= 20,
            default => false,
        };

        return $this->check($passed, ['han_chars' => $han, 'latin_words' => $latinWords, 'expected' => $language]);
    }

    private function headingDensityCheck(string $content): array
    {
        $headings = preg_match_all('/^#{2,6}\s+.+$/mu', $content);
        $words = preg_match_all('/\b[\p{L}\p{N}][\p{L}\p{N}\'-]*\b/u', strip_tags($content));
        $limit = max(4, (int) ceil(max(1, $words) / 100) * 3);

        return $this->check($headings <= $limit, ['headings' => $headings, 'words' => $words, 'limit' => $limit]);
    }

    private function singleSentenceSectionCheck(string $content): array
    {
        preg_match_all('/^#{2,6}\s+.+?\R+(.*?)(?=^#{2,6}\s+|\z)/msu', $content, $sections);
        $singleSentenceSections = 0;
        foreach ($sections[1] ?? [] as $body) {
            $plain = trim(preg_replace('/\s+/u', ' ', (string) $body) ?? '');
            $sentences = preg_split('/(?<=[.!?。！？])\s*/u', $plain, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            if (count($sentences) <= 1) {
                $singleSentenceSections++;
            }
        }

        return $this->check($singleSentenceSections === 0, ['single_sentence_sections' => $singleSentenceSections]);
    }

    private function duplicateModuleCheck(string $content): array
    {
        preg_match_all('/^#{2,6}\s+(.+)$/mu', $content, $matches);
        $headings = collect($matches[1] ?? [])->map(fn (string $heading): string => mb_strtolower(trim($heading), 'UTF-8'));
        $tracked = ['introduction', 'quick answer', 'key takeaways', 'conclusion'];
        $duplicates = $headings->countBy()->filter(fn (int $count, string $heading): bool => $count > 1 && in_array($heading, $tracked, true))->keys()->values()->all();

        return $this->check($duplicates === [], ['duplicates' => $duplicates]);
    }

    private function genericModuleDensityCheck(string $content): array
    {
        $genericModules = [
            'introduction', 'intro', 'overview', 'quick answer', 'key takeaway', 'key takeaways',
            'summary', 'conclusion', 'faq', 'faqs', 'q a', '引言', '介绍', '概述', '快速回答',
            '核心要点', '要点', '总结', '结论', '常见问题', '问答',
        ];
        $headings = $this->headingSkeleton($content);
        $matches = collect($headings)
            ->filter(fn (string $heading): bool => in_array($heading, $genericModules, true))
            ->values()
            ->all();

        return $this->check(count($matches) <= 2, [
            'generic_module_count' => count($matches),
            'heading_count' => count($headings),
            'limit' => 2,
        ]);
    }

    private function paragraphFragmentationCheck(string $content): array
    {
        $paragraphs = $this->proseParagraphs($content);
        if (count($paragraphs) < 4) {
            return $this->check(true, [
                'applicable' => false,
                'paragraph_count' => count($paragraphs),
            ]);
        }

        $wordCounts = array_map(fn (string $paragraph): int => $this->wordCount($paragraph), $paragraphs);
        sort($wordCounts);
        $shortCount = count(array_filter($wordCounts, fn (int $count): bool => $count < 12));
        $shortRatio = $shortCount / max(1, count($wordCounts));
        $middle = (int) floor(count($wordCounts) / 2);
        $median = count($wordCounts) % 2 === 0
            ? (int) round(($wordCounts[$middle - 1] + $wordCounts[$middle]) / 2)
            : $wordCounts[$middle];

        return $this->check($shortRatio <= 0.6 && $median >= 12, [
            'applicable' => true,
            'paragraph_count' => count($paragraphs),
            'short_paragraph_count' => $shortCount,
            'short_paragraph_ratio' => (int) round($shortRatio * 100),
            'median_words' => $median,
        ]);
    }

    private function sectionInformationGainCheck(string $content): array
    {
        $sections = $this->sectionBodies($content);
        if (count($sections) < 2) {
            return $this->check(true, ['applicable' => false, 'section_count' => count($sections)]);
        }

        $duplicatePairs = 0;
        for ($left = 0; $left < count($sections); $left++) {
            for ($right = $left + 1; $right < count($sections); $right++) {
                if ($this->textSimilarity($sections[$left], $sections[$right]) >= 0.8) {
                    $duplicatePairs++;
                }
            }
        }

        return $this->check($duplicatePairs === 0, [
            'applicable' => true,
            'section_count' => count($sections),
            'low_information_gain_pairs' => $duplicatePairs,
        ]);
    }

    /** @param array<string,mixed> $output */
    private function styleFitnessCheck(array $output, string $content): array
    {
        $expectations = is_array($output['style_expectations'] ?? null) ? $output['style_expectations'] : [];
        if ($expectations === []) {
            return $this->check(true, ['applicable' => false]);
        }

        $preferred = $this->stringList($expectations['preferred_terms'] ?? []);
        $avoided = $this->stringList($expectations['avoided_terms'] ?? []);
        $preferredHits = collect($preferred)->filter(fn (string $term): bool => mb_stripos($content, $term) !== false)->count();
        $avoidedHits = collect($avoided)->filter(fn (string $term): bool => mb_stripos($content, $term) !== false)->count();

        return $this->check($avoidedHits === 0 && ($preferred === [] || $preferredHits > 0), [
            'applicable' => true,
            'preferred_term_count' => count($preferred),
            'preferred_term_hits' => $preferredHits,
            'avoided_term_count' => count($avoided),
            'avoided_term_hits' => $avoidedHits,
        ]);
    }

    /** @param array<string,mixed> $output */
    private function styleBoundaryCheck(array $output): array
    {
        $violations = $this->stringList($output['style_boundary_violations'] ?? []);
        $applicable = is_array($output['style_expectations'] ?? null) || $violations !== [];

        return $this->check($violations === [], [
            'applicable' => $applicable,
            'violation_count' => count($violations),
        ]);
    }

    /**
     * Cross-article checks are diagnostic. They block a real-model release but never rewrite headings.
     *
     * @param  list<array<string,mixed>>  $cases
     * @param  array<string,array<string,mixed>>  $outputsByCase
     * @return array<string,array{passed:bool,metrics:array<string,mixed>}>
     */
    private function corpusTemplateChecks(array $cases, array $outputsByCase): array
    {
        $groups = collect($cases)
            ->filter(fn (array $case): bool => is_string($case['expected_intent'] ?? null) && $case['expected_intent'] !== '')
            ->groupBy(fn (array $case): string => (string) $case['expected_intent'])
            ->filter(fn ($group): bool => $group->count() >= 2);
        $headingMatches = 0;
        $openingMatches = 0;
        $pairCount = 0;
        $affectedCases = [];

        foreach ($groups as $group) {
            $groupCases = $group->values()->all();
            for ($left = 0; $left < count($groupCases); $left++) {
                for ($right = $left + 1; $right < count($groupCases); $right++) {
                    $leftId = (string) $groupCases[$left]['id'];
                    $rightId = (string) $groupCases[$right]['id'];
                    $leftContent = (string) data_get($outputsByCase, $leftId.'.content', '');
                    $rightContent = (string) data_get($outputsByCase, $rightId.'.content', '');
                    $pairCount++;

                    if ($this->sequenceSimilarity($this->headingSkeleton($leftContent), $this->headingSkeleton($rightContent)) >= 0.85) {
                        $headingMatches++;
                        $affectedCases[] = $leftId;
                        $affectedCases[] = $rightId;
                    }
                    if ($this->textSimilarity($this->openingSignature($leftContent), $this->openingSignature($rightContent)) >= 0.8) {
                        $openingMatches++;
                        $affectedCases[] = $leftId;
                        $affectedCases[] = $rightId;
                    }
                }
            }
        }

        $sharedMetrics = [
            'compared_pairs' => $pairCount,
            'affected_case_count' => count(array_unique($affectedCases)),
        ];

        return [
            'heading_skeleton_similarity' => $this->check($headingMatches === 0, $sharedMetrics + [
                'matching_pairs' => $headingMatches,
                'threshold_percent' => 85,
            ]),
            'opening_pattern_repetition' => $this->check($openingMatches === 0, $sharedMetrics + [
                'matching_pairs' => $openingMatches,
                'threshold_percent' => 80,
            ]),
        ];
    }

    /** @return list<string> */
    private function headingSkeleton(string $content): array
    {
        preg_match_all('/^#{2,6}\s+(.+)$/mu', $content, $matches);

        return collect($matches[1] ?? [])
            ->map(fn (string $heading): string => $this->normalizeText($heading))
            ->filter()
            ->values()
            ->all();
    }

    /** @return list<string> */
    private function proseParagraphs(string $content): array
    {
        $content = preg_replace('/```.*?```/su', "\n\n", $content) ?? $content;
        $content = preg_replace('/^\s{0,3}#{1,6}\s+.*$/mu', "\n\n", $content) ?? $content;
        $content = preg_replace('/^\s*(?:[-*+]\s+|\d+[.)]\s+).+$/mu', "\n\n", $content) ?? $content;
        $content = preg_replace('/^\s*\|.*\|\s*$/mu', "\n\n", $content) ?? $content;

        return collect(preg_split('/\R{2,}/u', $content) ?: [])
            ->map(fn ($paragraph): string => trim(strip_tags((string) $paragraph)))
            ->filter(fn (string $paragraph): bool => $paragraph !== '')
            ->values()
            ->all();
    }

    /** @return list<string> */
    private function sectionBodies(string $content): array
    {
        preg_match_all('/^#{2,6}\s+.+?\R+(.*?)(?=^#{2,6}\s+|\z)/msu', $content, $sections);

        return collect($sections[1] ?? [])
            ->map(fn ($body): string => $this->normalizeText((string) $body))
            ->filter()
            ->values()
            ->all();
    }

    private function openingSignature(string $content): string
    {
        $paragraph = $this->proseParagraphs($content)[0] ?? '';
        preg_match_all('/[\p{L}\p{N}]+/u', mb_strtolower($paragraph, 'UTF-8'), $matches);

        return implode(' ', array_slice($matches[0] ?? [], 0, 16));
    }

    /** @param list<string> $left @param list<string> $right */
    private function sequenceSimilarity(array $left, array $right): float
    {
        if ($left === [] || $right === []) {
            return 0.0;
        }
        $matches = 0;
        foreach ($left as $index => $value) {
            if (($right[$index] ?? null) === $value) {
                $matches++;
            }
        }

        return $matches / max(count($left), count($right));
    }

    private function textSimilarity(string $left, string $right): float
    {
        $leftTokens = array_values(array_unique(preg_split('/\s+/u', $this->normalizeText($left), -1, PREG_SPLIT_NO_EMPTY) ?: []));
        $rightTokens = array_values(array_unique(preg_split('/\s+/u', $this->normalizeText($right), -1, PREG_SPLIT_NO_EMPTY) ?: []));
        if ($leftTokens === [] || $rightTokens === []) {
            return 0.0;
        }

        return count(array_intersect($leftTokens, $rightTokens)) / max(1, count(array_unique(array_merge($leftTokens, $rightTokens))));
    }

    private function normalizeText(string $text): string
    {
        $text = mb_strtolower(strip_tags($text), 'UTF-8');
        $text = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text) ?? $text;

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }

    private function wordCount(string $text): int
    {
        $latinWords = preg_match_all('/\b[A-Za-z0-9][A-Za-z0-9\'-]*\b/u', $text);
        $han = preg_match_all('/\p{Han}/u', $text);

        return max($latinWords, (int) ceil($han / 2));
    }

    /** @return list<string> */
    private function stringList(mixed $values): array
    {
        return collect(is_array($values) ? $values : [])
            ->map(fn ($value): string => trim((string) $value))
            ->filter()
            ->unique(fn (string $value): string => mb_strtolower($value, 'UTF-8'))
            ->take(100)
            ->values()
            ->all();
    }

    /** @param array<string,mixed> $case */
    private function caseEvidenceCheck(array $case, string $actualStatus): array
    {
        if (($case['expected_intent'] ?? null) !== ArticleSkillIntents::CASE_STUDY) {
            return $this->check(true, ['applicable' => false]);
        }
        $metadata = is_array($case['metadata'] ?? null) ? $case['metadata'] : [];
        $passed = $actualStatus === 'blocked'
            && ($metadata['evidence_state'] ?? null) !== 'verified'
            && ! str_starts_with((string) ($metadata['publication_scope'] ?? ''), 'approved');

        return $this->check($passed, ['applicable' => true, 'route_status' => $actualStatus]);
    }

    /** @param array<string,mixed> $case */
    private function casePrivacyCheck(array $case, string $content): array
    {
        if (($case['expected_intent'] ?? null) !== ArticleSkillIntents::CASE_STUDY) {
            return $this->check(true, ['applicable' => false]);
        }
        $restricted = collect(data_get($case, 'metadata.restricted_terms', []))->map(fn ($term): string => trim((string) $term))->filter();
        $leaked = $restricted->filter(fn (string $term): bool => mb_stripos($content, $term) !== false)->values()->all();

        return $this->check($leaked === [], ['applicable' => true, 'restricted_term_matches' => count($leaked)]);
    }

    /** @param array<string,mixed> $case */
    private function troubleshootingSafetyCheck(array $case, string $content): array
    {
        if (($case['expected_intent'] ?? null) !== ArticleSkillIntents::TROUBLESHOOTING) {
            return $this->check(true, ['applicable' => false]);
        }
        $hasStopBoundary = preg_match('/\b(?:stop|disconnect|de-energiz|isolate)\w*\b/iu', $content) === 1;
        $hasEscalation = preg_match('/\b(?:qualified technician|supplier|escalate|technical support)\b/iu', $content) === 1;
        $hasForbiddenAdvice = $this->containsUnsafeTroubleshootingInstruction($content);

        return $this->check($hasStopBoundary && $hasEscalation && ! $hasForbiddenAdvice, [
            'applicable' => true,
            'stop_boundary' => $hasStopBoundary,
            'escalation' => $hasEscalation,
            'forbidden_advice' => $hasForbiddenAdvice,
        ]);
    }

    /** @param array<string,mixed> $metrics @return array{passed:bool,metrics:array<string,mixed>} */
    private function check(bool $passed, array $metrics = []): array
    {
        return ['passed' => $passed, 'metrics' => $metrics];
    }

    /** @param list<array<string,mixed>> $cases @param list<array<string,mixed>> $outputs */
    private function assertOutputSetMatchesCases(array $cases, array $outputs): void
    {
        $caseIds = array_map(fn (array $case): string => (string) ($case['id'] ?? ''), $cases);
        $outputIds = array_map(fn (array $output): string => (string) ($output['case_id'] ?? ''), $outputs);
        $sortedCaseIds = $caseIds;
        $sortedOutputIds = $outputIds;
        sort($sortedCaseIds);
        sort($sortedOutputIds);
        $allContentIsPresent = collect($outputs)->every(fn (array $output): bool => is_string($output['content'] ?? null) && trim($output['content']) !== '');

        if ($sortedCaseIds !== $sortedOutputIds || count(array_unique($outputIds)) !== count($outputIds) || ! $allContentIsPresent) {
            throw new InvalidArgumentException('Evaluation outputs must exactly match the fixed evaluation catalog with one non-empty string output per case.');
        }
    }

    private function containsUnsafeTroubleshootingInstruction(string $content): bool
    {
        $sentences = preg_split('/(?<=[.!?。！？])\s*/u', $content, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        foreach ($sentences as $sentence) {
            $plain = trim(strip_tags($sentence));
            $explicitProhibition = preg_match('/\b(?:do not|never|must not|should not)\b.{0,50}\b(?:bypass|disable|remove|open|dismantle)\b/iu', $plain) === 1;
            if ($explicitProhibition) {
                continue;
            }
            if (preg_match('/\b(?:bypass|disable)\w*\b.{0,40}\b(?:guard|interlock|emergency stop|e-stop)\b/iu', $plain) === 1
                || preg_match('/\b(?:remove|open|dismantle|detach|take off|loosen|expose)\w*\b.{0,80}\b(?:while|when)\b.{0,40}\b(?:energized|powered|live|running|pressurized)\b/iu', $plain) === 1
                || preg_match('/\b(?:remove|open|dismantle|detach|take off|loosen|expose)\w*\b.{0,80}\b(?:before|without)\b.{0,40}\b(?:disconnect|de-energiz|isolat|depressuriz)\w*\b/iu', $plain) === 1
                || preg_match('/\b(?:keep|leave)\b.{0,30}\b(?:machine|equipment|cabinet|system)\b.{0,20}\b(?:live|energized|powered|running|pressurized)\b.{0,100}\b(?:remove|open|dismantle|detach|take off|loosen|expose)\b/iu', $plain) === 1) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string,int>|null */
    private function releaseScores(mixed $scores): ?array
    {
        if (! is_array($scores)) {
            return null;
        }

        $normalized = [];
        foreach (ArticleSkillReleaseGate::METRIC_KEYS as $key) {
            $score = $scores[$key] ?? null;
            if (! is_int($score) || $score < 1 || $score > 5) {
                return null;
            }
            $normalized[$key] = $score;
        }

        return $normalized;
    }

    private function workflowMode(mixed $mode): string
    {
        return trim((string) $mode) === 'deep_pipeline' ? 'deep_pipeline' : 'single_turn';
    }

    /** @return list<string> */
    private function stageNames(mixed $stages): array
    {
        return collect(is_array($stages) ? $stages : [])
            ->map(static fn (mixed $stage): string => is_array($stage)
                ? trim((string) ($stage['name'] ?? ''))
                : trim((string) $stage))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /** @param array<string,mixed> $pair */
    private function pairedProvenanceVerified(array $pair): bool
    {
        $candidateContext = $this->sha256($pair['candidate_context_sha256'] ?? null);
        $controlContext = $this->sha256($pair['control_context_sha256'] ?? null);
        $candidateModel = $this->sha256($pair['candidate_model_config_sha256'] ?? null);
        $controlModel = $this->sha256($pair['control_model_config_sha256'] ?? null);

        return $candidateContext !== null
            && $controlContext !== null
            && hash_equals($candidateContext, $controlContext)
            && $candidateModel !== null
            && $controlModel !== null
            && hash_equals($candidateModel, $controlModel);
    }

    private function sha256(mixed $value): ?string
    {
        if (! is_string($value) || preg_match('/\A[0-9a-f]{64}\z/i', $value) !== 1) {
            return null;
        }

        return strtolower($value);
    }

    /** @param array<string,mixed> $model @return array<string,mixed> */
    private function safeModelMetadata(array $model, bool $isRealModel): array
    {
        $safe = ['is_real_model' => $isRealModel];
        foreach (['name', 'provider', 'model_version'] as $field) {
            if (! isset($model[$field])) {
                continue;
            }
            $value = (string) $model[$field];
            $safe[$field.'_sha256'] = hash('sha256', $value);
        }
        if (isset($model['temperature']) && (is_int($model['temperature']) || is_float($model['temperature']))) {
            $safe['temperature'] = $model['temperature'];
        }
        if (isset($model['max_output_tokens']) && is_int($model['max_output_tokens'])) {
            $safe['max_output_tokens'] = $model['max_output_tokens'];
        }
        foreach (['code_commit' => 64, 'prompt_catalog_hash' => 64] as $field => $maxLength) {
            $value = (string) ($model[$field] ?? '');
            if ($value !== '' && preg_match('/\A[0-9a-f]{7,'.$maxLength.'}\z/i', $value) === 1) {
                $safe[$field] = strtolower($value);
            }
        }
        $generatedAt = (string) ($model['generated_at'] ?? '');
        if ($generatedAt !== '' && preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})\z/', $generatedAt) === 1) {
            $safe['generated_at'] = $generatedAt;
        }

        return $safe;
    }
}
