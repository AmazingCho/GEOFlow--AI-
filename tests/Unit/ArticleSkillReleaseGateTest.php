<?php

namespace Tests\Unit;

use App\Services\GeoFlow\ArticleSkillReleaseGate;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ArticleSkillReleaseGateTest extends TestCase
{
    public function test_candidate_and_control_cohorts_are_gated_without_style_scores_polluting_the_result(): void
    {
        $report = app(ArticleSkillReleaseGate::class)->evaluate([
            $this->artifact('candidate-a', 'candidate', 'pair-a', $this->scores()),
            $this->artifact('control-a', 'control', 'pair-a', $this->scores(3)),
            $this->styleArtifact('style-a', $this->scores(1)),
        ], ['expected_pair_keys' => ['pair-a']]);

        $this->assertTrue($report['valid']);
        $this->assertSame('article-v2.3-rubric-1', $report['threshold_version']);
        $this->assertSame(1, $report['cohorts']['candidate']['count']);
        $this->assertSame(1, $report['cohorts']['control']['count']);
        $this->assertSame(1, $report['cohorts']['style']['count']);
        $this->assertSame(5.0, $report['cohorts']['candidate']['averages']['factual_support']);
        $this->assertFalse($report['diagnostics']['style_threshold_passed']);
        $this->assertSame('manual_approval_still_required', $report['release_decision']);
        $this->assertSame([], $report['release_blockers']);
    }

    public function test_candidate_absolute_threshold_and_pairwise_no_regression_are_independent_gates(): void
    {
        $candidateScores = $this->scores();
        $candidateScores['factual_support'] = 3;
        $controlScores = $this->scores();

        $report = app(ArticleSkillReleaseGate::class)->evaluate([
            $this->artifact('candidate-a', 'candidate', 'pair-a', $candidateScores),
            $this->artifact('control-a', 'control', 'pair-a', $controlScores),
        ], ['expected_pair_keys' => ['pair-a']]);

        $this->assertTrue($report['valid']);
        $this->assertSame('no_go', $report['release_decision']);
        $this->assertContains('candidate_absolute_threshold_failed', $report['release_blockers']);
        $this->assertContains('paired_factual_support_regression', $report['release_blockers']);
        $this->assertFalse($report['gates']['candidate_absolute']);
        $this->assertFalse($report['gates']['paired_factual_support_no_regression']);
    }

    #[DataProvider('invalidCohortProvider')]
    public function test_invalid_cohort_metadata_is_no_go(array $artifacts, string $expectedIssue): void
    {
        $report = app(ArticleSkillReleaseGate::class)->evaluate($artifacts, ['expected_pair_keys' => ['p']]);

        $this->assertFalse($report['valid']);
        $this->assertSame('no_go', $report['release_decision']);
        $this->assertContains('invalid_release_cohort', $report['release_blockers']);
        $this->assertContains($expectedIssue, $report['validation_issues']);
    }

    public static function invalidCohortProvider(): array
    {
        $scores = array_fill_keys([
            'factual_support',
            'clarity',
            'buyer_decision_value',
            'structure_naturalness',
            'uncertainty_and_negative_fit',
            'privacy_and_safety',
            'style_fitness',
            'non_template_naturalness',
        ], 5);

        return [
            'duplicate artifact id' => [[
                ['artifact_id' => 'same', 'cohort' => 'candidate', 'pair_key' => 'p', 'workflow_mode' => 'single_turn', 'scores' => $scores],
                ['artifact_id' => 'same', 'cohort' => 'control', 'pair_key' => 'p', 'workflow_mode' => 'single_turn', 'scores' => $scores],
            ], 'duplicate_artifact_id:same'],
            'unpaired candidate' => [[
                ['artifact_id' => 'candidate', 'cohort' => 'candidate', 'pair_key' => 'p', 'workflow_mode' => 'single_turn', 'scores' => $scores],
            ], 'incomplete_pair:p'],
            'missing required score' => [[
                ['artifact_id' => 'candidate', 'cohort' => 'candidate', 'pair_key' => 'p', 'workflow_mode' => 'single_turn', 'scores' => array_diff_key($scores, ['clarity' => true])],
                ['artifact_id' => 'control', 'cohort' => 'control', 'pair_key' => 'p', 'workflow_mode' => 'single_turn', 'scores' => $scores],
            ], 'invalid_scores:candidate'],
        ];
    }

    public function test_single_turn_artifacts_cannot_be_reported_as_deep_pipeline_validation(): void
    {
        $artifacts = [
            $this->artifact('candidate-a', 'candidate', 'pair-a', $this->scores()),
            $this->artifact('control-a', 'control', 'pair-a', $this->scores()),
        ];

        $singleTurn = app(ArticleSkillReleaseGate::class)->evaluate($artifacts, [
            'claim_deep_validation' => true,
            'expected_pair_keys' => ['pair-a'],
        ]);

        $this->assertSame('no_go', $singleTurn['release_decision']);
        $this->assertContains('deep_pipeline_evidence_required', $singleTurn['release_blockers']);

        foreach ($artifacts as &$artifact) {
            $artifact['workflow_mode'] = 'deep_pipeline';
            $artifact['stages'] = ['deep_plan', 'deep_draft', 'deep_review'];
        }
        unset($artifact);

        $deep = app(ArticleSkillReleaseGate::class)->evaluate($artifacts, [
            'claim_deep_validation' => true,
            'expected_pair_keys' => ['pair-a'],
        ]);

        $this->assertTrue($deep['gates']['deep_pipeline_evidence']);
        $this->assertSame('manual_approval_still_required', $deep['release_decision']);
    }

    public function test_expected_pair_coverage_prevents_cherry_picked_release_results(): void
    {
        $report = app(ArticleSkillReleaseGate::class)->evaluate([
            $this->artifact('candidate-a', 'candidate', 'pair-a', $this->scores()),
            $this->artifact('control-a', 'control', 'pair-a', $this->scores()),
        ], ['expected_pair_keys' => ['pair-a', 'pair-b']]);

        $this->assertFalse($report['valid']);
        $this->assertContains('pair_coverage_mismatch', $report['validation_issues']);
        $this->assertSame('no_go', $report['release_decision']);
    }

    public function test_malformed_options_and_non_machine_identifiers_fail_closed(): void
    {
        $artifacts = [
            $this->artifact('Customer Name', 'candidate', 'pair-a', $this->scores()),
            $this->artifact('control-a', 'control', 'pair-a', $this->scores()),
        ];

        $report = app(ArticleSkillReleaseGate::class)->evaluate($artifacts, [
            'claim_deep_validation' => 'true',
            'expected_pair_keys' => ['pair-a'],
        ]);

        $this->assertFalse($report['valid']);
        $this->assertContains('invalid_claim_deep_validation_option', $report['validation_issues']);
        $this->assertContains('invalid_artifact_id:1', $report['validation_issues']);
    }

    public function test_style_validation_is_diagnostic_only_and_cannot_invalidate_the_core_cohort(): void
    {
        $report = app(ArticleSkillReleaseGate::class)->evaluate([
            $this->artifact('candidate-a', 'candidate', 'pair-a', $this->scores()),
            $this->artifact('control-a', 'control', 'pair-a', $this->scores()),
            [
                'artifact_id' => 'style-a',
                'cohort' => 'style',
                'workflow_mode' => 'single_turn',
                'scores' => $this->scores(),
            ],
        ], ['expected_pair_keys' => ['pair-a']]);

        $this->assertTrue($report['valid']);
        $this->assertFalse($report['diagnostics']['style_valid']);
        $this->assertContains('missing_style_matrix_key:style-a', $report['diagnostics']['style_issues']);
        $this->assertSame('manual_approval_still_required', $report['release_decision']);
    }

    public function test_core_scores_reject_extra_metric_keys(): void
    {
        $scores = $this->scores();
        $scores['improvement_over_master_only'] = 5;
        $report = app(ArticleSkillReleaseGate::class)->evaluate([
            $this->artifact('candidate-a', 'candidate', 'pair-a', $scores),
            $this->artifact('control-a', 'control', 'pair-a', $this->scores()),
        ], ['expected_pair_keys' => ['pair-a']]);

        $this->assertFalse($report['valid']);
        $this->assertContains('invalid_scores:candidate-a', $report['validation_issues']);
    }

    /** @return array<string,int> */
    private function scores(int $value = 5): array
    {
        return array_fill_keys([
            'factual_support',
            'clarity',
            'buyer_decision_value',
            'structure_naturalness',
            'uncertainty_and_negative_fit',
            'privacy_and_safety',
            'style_fitness',
            'non_template_naturalness',
        ], $value);
    }

    /** @param array<string,int> $scores @return array<string,mixed> */
    private function artifact(string $id, string $cohort, string $pairKey, array $scores): array
    {
        return [
            'artifact_id' => $id,
            'cohort' => $cohort,
            'pair_key' => $pairKey,
            'workflow_mode' => 'single_turn',
            'scores' => $scores,
        ];
    }

    /** @param array<string,int> $scores @return array<string,mixed> */
    private function styleArtifact(string $id, array $scores): array
    {
        return [
            'artifact_id' => $id,
            'cohort' => 'style',
            'style_matrix_key' => 'technical_clarity',
            'workflow_mode' => 'single_turn',
            'scores' => $scores,
        ];
    }
}
