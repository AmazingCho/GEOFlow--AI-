<?php

namespace App\Services\GeoFlow;

final class ArticleSkillReleaseGate
{
    public const THRESHOLD_VERSION = 'article-v2.3-rubric-1';

    public const METRIC_KEYS = [
        'factual_support',
        'clarity',
        'buyer_decision_value',
        'structure_naturalness',
        'uncertainty_and_negative_fit',
        'privacy_and_safety',
        'style_fitness',
        'non_template_naturalness',
    ];

    private const CANDIDATE_FOUR_POINT_KEYS = [
        'factual_support',
        'structure_naturalness',
        'privacy_and_safety',
        'non_template_naturalness',
    ];

    /**
     * @param  list<array<string,mixed>>  $artifacts
     * @param  array{claim_deep_validation?:bool,expected_pair_keys?:list<string>}  $options
     * @return array<string,mixed>
     */
    public function evaluate(array $artifacts, array $options = []): array
    {
        $validationIssues = [];
        $styleIssues = [];
        $normalized = [];
        $seenCoreIds = [];
        $seenStyleIds = [];
        $claimDeepOption = $options['claim_deep_validation'] ?? false;
        if (! is_bool($claimDeepOption)) {
            $validationIssues[] = 'invalid_claim_deep_validation_option';
        }
        $claimDeepValidation = $claimDeepOption === true;
        $expectedPairKeys = $this->normalizeExpectedPairKeys($options['expected_pair_keys'] ?? null);
        if ($expectedPairKeys === null || $expectedPairKeys === []) {
            $validationIssues[] = 'invalid_expected_pair_keys_option';
            $expectedPairKeys = [];
        }

        foreach (array_values($artifacts) as $index => $artifact) {
            if (! is_array($artifact)) {
                $validationIssues[] = 'invalid_artifact:'.($index + 1);

                continue;
            }

            $cohort = $this->machineIdentifier($artifact['cohort'] ?? null) ?? '';
            $isStyle = $cohort === 'style';
            $id = $this->machineIdentifier($artifact['artifact_id'] ?? null);
            if ($id === null) {
                if ($isStyle) {
                    $styleIssues[] = 'invalid_artifact_id:'.($index + 1);
                } else {
                    $validationIssues[] = 'invalid_artifact_id:'.($index + 1);
                }

                continue;
            }
            $seenIds = $isStyle ? $seenStyleIds : $seenCoreIds;
            if (isset($seenIds[$id])) {
                if ($isStyle) {
                    $styleIssues[] = 'duplicate_artifact_id:'.$id;
                } else {
                    $validationIssues[] = 'duplicate_artifact_id:'.$id;
                }

                continue;
            }
            if ($isStyle) {
                $seenStyleIds[$id] = true;
            } else {
                $seenCoreIds[$id] = true;
            }

            if (! in_array($cohort, ['candidate', 'control', 'style'], true)) {
                $validationIssues[] = 'invalid_cohort:'.$id;

                continue;
            }

            $scores = $this->normalizeScores($artifact['scores'] ?? null);
            if ($scores === null) {
                if ($isStyle) {
                    $styleIssues[] = 'invalid_scores:'.$id;
                } else {
                    $validationIssues[] = 'invalid_scores:'.$id;
                }

                continue;
            }

            $workflowMode = $this->machineIdentifier($artifact['workflow_mode'] ?? null) ?? '';
            if (! in_array($workflowMode, ['single_turn', 'deep_pipeline'], true)) {
                if ($isStyle) {
                    $styleIssues[] = 'invalid_workflow_mode:'.$id;
                } else {
                    $validationIssues[] = 'invalid_workflow_mode:'.$id;
                }

                continue;
            }

            $pairKey = $this->machineIdentifier($artifact['pair_key'] ?? null) ?? '';
            $styleMatrixKey = $this->machineIdentifier($artifact['style_matrix_key'] ?? null) ?? '';
            if (in_array($cohort, ['candidate', 'control'], true) && $pairKey === '') {
                $validationIssues[] = 'missing_pair_key:'.$id;
            }
            if ($cohort === 'style' && $styleMatrixKey === '') {
                $styleIssues[] = 'missing_style_matrix_key:'.$id;

                continue;
            }

            $normalized[] = [
                'artifact_id' => $id,
                'cohort' => $cohort,
                'pair_key' => $pairKey,
                'style_matrix_key' => $styleMatrixKey,
                'workflow_mode' => $workflowMode,
                'stages' => $this->normalizeStages($artifact['stages'] ?? []),
                'scores' => $scores,
            ];
        }

        $candidates = array_values(array_filter($normalized, static fn (array $artifact): bool => $artifact['cohort'] === 'candidate'));
        $controls = array_values(array_filter($normalized, static fn (array $artifact): bool => $artifact['cohort'] === 'control'));
        $styles = array_values(array_filter($normalized, static fn (array $artifact): bool => $artifact['cohort'] === 'style'));
        if ($candidates === []) {
            $validationIssues[] = 'candidate_cohort_empty';
        }

        $pairs = $this->buildPairs($candidates, $controls, $validationIssues);
        $actualPairKeys = collect($pairs)->pluck('pair_key')->sort()->values()->all();
        $sortedExpectedPairKeys = $expectedPairKeys;
        sort($sortedExpectedPairKeys);
        if ($expectedPairKeys !== [] && $actualPairKeys !== $sortedExpectedPairKeys) {
            $validationIssues[] = 'pair_coverage_mismatch';
        }
        $validationIssues = array_values(array_unique($validationIssues));
        $valid = $validationIssues === [];

        $candidateAbsolute = $valid && $this->candidateAbsolutePassed($candidates);
        $pairedFactual = $valid && $this->pairMetricPassed($pairs, 'factual_support');
        $pairedPrivacy = $valid && $this->pairMetricPassed($pairs, 'privacy_and_safety');
        $deepPipelineEvidence = ! $claimDeepValidation
            || ($valid && $this->deepPipelineEvidencePassed(array_merge($candidates, $controls)));

        $gates = [
            'candidate_absolute' => $candidateAbsolute,
            'paired_factual_support_no_regression' => $pairedFactual,
            'paired_privacy_and_safety_no_regression' => $pairedPrivacy,
            'deep_pipeline_evidence' => $deepPipelineEvidence,
        ];
        $releaseBlockers = [];
        if (! $valid) {
            $releaseBlockers[] = 'invalid_release_cohort';
        } else {
            if (! $candidateAbsolute) {
                $releaseBlockers[] = 'candidate_absolute_threshold_failed';
            }
            if (! $pairedFactual) {
                $releaseBlockers[] = 'paired_factual_support_regression';
            }
            if (! $pairedPrivacy) {
                $releaseBlockers[] = 'paired_privacy_and_safety_regression';
            }
            if (! $deepPipelineEvidence) {
                $releaseBlockers[] = 'deep_pipeline_evidence_required';
            }
        }

        return [
            'schema_version' => 2,
            'threshold_version' => self::THRESHOLD_VERSION,
            'valid' => $valid,
            'validation_issues' => $validationIssues,
            'cohorts' => [
                'candidate' => $this->cohortSummary($candidates),
                'control' => $this->cohortSummary($controls),
                'style' => $this->cohortSummary($styles),
            ],
            'diagnostics' => [
                'style_valid' => $styleIssues === [],
                'style_issues' => array_values(array_unique($styleIssues)),
                'style_threshold_passed' => $this->styleDiagnosticPassed($styles),
            ],
            'pairs' => $pairs,
            'deep_validation_claimed' => $claimDeepValidation,
            'gates' => $gates,
            'release_decision' => $releaseBlockers === [] ? 'manual_approval_still_required' : 'no_go',
            'release_blockers' => $releaseBlockers,
        ];
    }

    /** @return array<string,int>|null */
    private function normalizeScores(mixed $scores): ?array
    {
        if (! is_array($scores)) {
            return null;
        }

        $actualKeys = array_map('strval', array_keys($scores));
        $expectedKeys = self::METRIC_KEYS;
        sort($actualKeys);
        sort($expectedKeys);
        if ($actualKeys !== $expectedKeys) {
            return null;
        }

        $normalized = [];
        foreach (self::METRIC_KEYS as $key) {
            $score = $scores[$key] ?? null;
            if (! is_int($score) || $score < 1 || $score > 5) {
                return null;
            }
            $normalized[$key] = $score;
        }

        return $normalized;
    }

    /** @return list<string> */
    private function normalizeStages(mixed $stages): array
    {
        if (! is_array($stages)) {
            return [];
        }

        return collect($stages)
            ->map(fn (mixed $stage): string => $this->machineIdentifier($stage) ?? '')
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  list<array<string,mixed>>  $candidates
     * @param  list<array<string,mixed>>  $controls
     * @param  list<string>  $validationIssues
     * @return list<array<string,mixed>>
     */
    private function buildPairs(array $candidates, array $controls, array &$validationIssues): array
    {
        $groups = collect(array_merge($candidates, $controls))->groupBy('pair_key');
        $pairs = [];
        foreach ($groups as $pairKey => $group) {
            $candidateRows = $group->where('cohort', 'candidate')->values();
            $controlRows = $group->where('cohort', 'control')->values();
            if ($pairKey === '' || $candidateRows->count() !== 1 || $controlRows->count() !== 1) {
                $validationIssues[] = 'incomplete_pair:'.($pairKey !== '' ? $pairKey : 'missing');

                continue;
            }

            $candidate = $candidateRows->first();
            $control = $controlRows->first();
            $pairs[] = [
                'pair_key' => (string) $pairKey,
                'candidate_artifact_id' => $candidate['artifact_id'],
                'control_artifact_id' => $control['artifact_id'],
                'deltas' => collect(self::METRIC_KEYS)->mapWithKeys(
                    static fn (string $key): array => [$key => $candidate['scores'][$key] - $control['scores'][$key]]
                )->all(),
            ];
        }

        return $pairs;
    }

    /** @param list<array<string,mixed>> $candidates */
    private function candidateAbsolutePassed(array $candidates): bool
    {
        return collect($candidates)->every(function (array $artifact): bool {
            foreach (self::METRIC_KEYS as $key) {
                $minimum = in_array($key, self::CANDIDATE_FOUR_POINT_KEYS, true) ? 4 : 3;
                if ($artifact['scores'][$key] < $minimum) {
                    return false;
                }
            }

            return true;
        });
    }

    /** @param list<array<string,mixed>> $pairs */
    private function pairMetricPassed(array $pairs, string $metric): bool
    {
        return $pairs !== [] && collect($pairs)->every(
            static fn (array $pair): bool => (int) ($pair['deltas'][$metric] ?? -5) >= 0
        );
    }

    /** @param list<array<string,mixed>> $artifacts */
    private function deepPipelineEvidencePassed(array $artifacts): bool
    {
        return $artifacts !== [] && collect($artifacts)->every(function (array $artifact): bool {
            if ($artifact['workflow_mode'] !== 'deep_pipeline') {
                return false;
            }
            $stages = $artifact['stages'];
            foreach (['deep_plan', 'deep_draft', 'deep_review'] as $required) {
                if (! in_array($required, $stages, true)) {
                    return false;
                }
            }

            return ! in_array('deep_revision', $stages, true) || in_array('deep_final_review', $stages, true);
        });
    }

    /** @return list<string>|null */
    private function normalizeExpectedPairKeys(mixed $keys): ?array
    {
        if (! is_array($keys)) {
            return null;
        }

        $normalized = [];
        foreach ($keys as $key) {
            $identifier = $this->machineIdentifier($key);
            if ($identifier === null) {
                return null;
            }
            $normalized[] = $identifier;
        }

        return array_values(array_unique($normalized));
    }

    private function machineIdentifier(mixed $value): ?string
    {
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }
        $identifier = trim((string) $value);

        return preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,119}\z/', $identifier) === 1
            ? $identifier
            : null;
    }

    /** @param list<array<string,mixed>> $styles */
    private function styleDiagnosticPassed(array $styles): ?bool
    {
        if ($styles === []) {
            return null;
        }

        return collect($styles)->every(function (array $artifact): bool {
            foreach (self::METRIC_KEYS as $key) {
                $minimum = in_array($key, ['factual_support', 'privacy_and_safety', 'style_fitness'], true) ? 4 : 3;
                if ($artifact['scores'][$key] < $minimum) {
                    return false;
                }
            }

            return true;
        });
    }

    /** @param list<array<string,mixed>> $artifacts @return array<string,mixed> */
    private function cohortSummary(array $artifacts): array
    {
        $averages = [];
        foreach (self::METRIC_KEYS as $key) {
            $averages[$key] = $artifacts === []
                ? null
                : round(collect($artifacts)->avg(static fn (array $artifact): int => $artifact['scores'][$key]), 2);
        }

        return [
            'count' => count($artifacts),
            'averages' => $averages,
        ];
    }
}
