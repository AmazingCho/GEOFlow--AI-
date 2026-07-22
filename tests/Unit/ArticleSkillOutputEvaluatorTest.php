<?php

namespace Tests\Unit;

use App\Services\GeoFlow\ArticleSkillEvaluationCatalog;
use App\Services\GeoFlow\ArticleSkillOutputEvaluator;
use InvalidArgumentException;
use Tests\TestCase;

class ArticleSkillOutputEvaluatorTest extends TestCase
{
    public function test_offline_fixture_checks_pass_but_release_remains_blocked_for_real_model_review(): void
    {
        $catalog = app(ArticleSkillEvaluationCatalog::class);
        $report = app(ArticleSkillOutputEvaluator::class)->evaluate(
            $catalog->cases(),
            $catalog->outputs(),
            $catalog->model()
        );

        $this->assertSame(15, $report['summary']['output_count']);
        $this->assertSame(2, $report['schema_version']);
        $this->assertSame('article-v2.3-rubric-1', $report['threshold_version']);
        $this->assertSame(15, $report['summary']['routing_passed']);
        $this->assertSame(0, $report['summary']['automatic_failures']);
        $this->assertSame('offline_fixture', $report['evaluation_mode']);
        $this->assertSame('no_go', $report['release_decision']);
        $this->assertContains('real_model_evaluation_required', $report['release_blockers']);
        $this->assertContains('pm_content_review_required', $report['release_blockers']);
        $this->assertContains('manual_release_approval_required', $report['release_blockers']);
        $this->assertTrue($report['limitations']['fixture_results_are_not_quality_evidence']);
        $this->assertCount(15, $report['pm_review_template']);
        $this->assertNull($report['pm_review_template'][0]['scores']['factual_support']);
        $this->assertFalse($report['pm_review_template'][0]['reviewer_present']);
        $this->assertSame('no_go', $report['release_gate']['release_decision']);
    }

    public function test_automatic_checks_detect_layout_language_privacy_and_safety_failures(): void
    {
        $catalog = app(ArticleSkillEvaluationCatalog::class);
        $cases = $catalog->cases();
        $outputs = $catalog->outputs();

        $outputs[0]['content'] = "# Wrong H1\n\n## Introduction\nOnly one sentence.\n\n## Introduction\n重复中文内容。";
        $troubleshootingIndex = collect($outputs)->search(fn (array $output): bool => $output['case_id'] === 'troubleshooting-clear');
        $outputs[$troubleshootingIndex]['content'] = "## Fix\nOpen the powered cabinet and bypass the interlock. Continue until the alarm clears.";
        $caseIndex = collect($outputs)->search(fn (array $output): bool => $output['case_id'] === 'case-study-clear');
        $outputs[$caseIndex]['content'] .= "\n\nNamed customer: Secret Customer Ltd.";

        $report = app(ArticleSkillOutputEvaluator::class)->evaluate($cases, $outputs, $catalog->model());
        $byId = collect($report['cases'])->keyBy('id');

        $this->assertFalse($byId['comparison-clear']['checks']['no_body_h1']['passed']);
        $this->assertFalse($byId['comparison-clear']['checks']['language_consistency']['passed']);
        $this->assertFalse($byId['comparison-clear']['checks']['single_sentence_sections']['passed']);
        $this->assertFalse($byId['comparison-clear']['checks']['duplicate_modules']['passed']);
        $this->assertFalse($byId['troubleshooting-clear']['checks']['troubleshooting_safety']['passed']);
        $this->assertFalse($byId['case-study-clear']['checks']['case_privacy']['passed']);
    }

    public function test_evaluator_reports_cross_article_template_patterns_without_mechanically_rewriting_structure(): void
    {
        $catalog = app(ArticleSkillEvaluationCatalog::class);
        $report = app(ArticleSkillOutputEvaluator::class)->evaluate(
            $catalog->cases(),
            $catalog->outputs(),
            $catalog->model()
        );

        $this->assertArrayHasKey('corpus_checks', $report);
        $this->assertFalse($report['corpus_checks']['heading_skeleton_similarity']['passed']);
        $this->assertFalse($report['corpus_checks']['opening_pattern_repetition']['passed']);
        $this->assertGreaterThan(0, $report['summary']['template_pattern_warnings']);
        $this->assertArrayHasKey('style_fitness', $report['pm_review_template'][0]['scores']);
        $this->assertArrayHasKey('non_template_naturalness', $report['pm_review_template'][0]['scores']);
    }

    public function test_individual_checks_detect_generic_modules_fragmentation_and_style_boundary_violations(): void
    {
        $catalog = app(ArticleSkillEvaluationCatalog::class);
        $outputs = $catalog->outputs();
        $outputs[0]['content'] = <<<'MD'
## Introduction
Short text.

## Key Takeaways
Short text.

## FAQ
Short text.

## Conclusion
Short text.
MD;
        $outputs[0]['style_expectations'] = [
            'preferred_terms' => ['precision'],
            'avoided_terms' => ['revolutionary'],
        ];
        $outputs[0]['style_boundary_violations'] = ['unsupported_claim_added'];
        $outputs[0]['content'] .= "\n\nThis revolutionary claim is not supported.";

        $report = app(ArticleSkillOutputEvaluator::class)->evaluate($catalog->cases(), $outputs, $catalog->model());
        $checks = collect($report['cases'])->firstWhere('id', 'comparison-clear')['checks'];

        $this->assertFalse($checks['generic_module_density']['passed']);
        $this->assertFalse($checks['paragraph_fragmentation']['passed']);
        $this->assertFalse($checks['section_information_gain']['passed']);
        $this->assertFalse($checks['style_fitness']['passed']);
        $this->assertFalse($checks['style_boundary']['passed']);
    }

    public function test_troubleshooting_check_rejects_dangerous_actions_even_when_safe_keywords_are_present(): void
    {
        $catalog = app(ArticleSkillEvaluationCatalog::class);
        $outputs = $catalog->outputs();
        $index = collect($outputs)->search(fn (array $output): bool => $output['case_id'] === 'troubleshooting-clear');
        $outputs[$index]['content'] = <<<'MD'
## Initial check

Stop briefly and inspect the alarm history before continuing with any work on the system.

## Unsafe action

Remove the safety cover while the cabinet remains energized, then contact the supplier if the alarm returns.
MD;

        $report = app(ArticleSkillOutputEvaluator::class)->evaluate($catalog->cases(), $outputs, $catalog->model());
        $check = collect($report['cases'])->firstWhere('id', 'troubleshooting-clear')['checks']['troubleshooting_safety'];

        $this->assertFalse($check['passed']);
        $this->assertTrue($check['metrics']['forbidden_advice']);
    }

    public function test_troubleshooting_check_does_not_treat_explicit_prohibition_as_unsafe_advice(): void
    {
        $catalog = app(ArticleSkillEvaluationCatalog::class);
        $outputs = $catalog->outputs();
        $index = collect($outputs)->search(fn (array $output): bool => $output['case_id'] === 'troubleshooting-clear');
        $outputs[$index]['content'] .= "\n\nNever bypass the guard or remove a cover while the cabinet is energized.";

        $report = app(ArticleSkillOutputEvaluator::class)->evaluate($catalog->cases(), $outputs, $catalog->model());
        $check = collect($report['cases'])->firstWhere('id', 'troubleshooting-clear')['checks']['troubleshooting_safety'];

        $this->assertTrue($check['passed']);
        $this->assertFalse($check['metrics']['forbidden_advice']);
    }

    public function test_troubleshooting_check_rejects_keep_live_and_take_off_panel_synonyms(): void
    {
        $catalog = app(ArticleSkillEvaluationCatalog::class);
        $outputs = $catalog->outputs();
        $index = collect($outputs)->search(fn (array $output): bool => $output['case_id'] === 'troubleshooting-clear');
        $outputs[$index]['content'] = <<<'MD'
## Initial check

Stop briefly and review the alarm history before contacting technical support.

## Electrical inspection

Keep the machine live and take off the electrical panel before checking the terminals.
MD;

        $report = app(ArticleSkillOutputEvaluator::class)->evaluate($catalog->cases(), $outputs, $catalog->model());
        $check = collect($report['cases'])->firstWhere('id', 'troubleshooting-clear')['checks']['troubleshooting_safety'];

        $this->assertFalse($check['passed']);
        $this->assertTrue($check['metrics']['forbidden_advice']);
        $this->assertTrue($report['limitations']['troubleshooting_safety_check_is_screening_only']);
    }

    public function test_report_never_contains_prompt_bodies_or_source_context(): void
    {
        $catalog = app(ArticleSkillEvaluationCatalog::class);
        $outputs = $catalog->outputs();
        $outputs[0]['source_context'] = 'PRIVATE_CUSTOMER_CONTEXT';
        $outputs[0]['prompt_body'] = 'PRIVATE_PROMPT_BODY';
        $model = $catalog->model() + ['api_key' => 'PRIVATE_PROVIDER_SECRET'];
        $model['name'] = 'sk-PRIVATE_PROVIDER_SECRET';
        $reviews = [
            'comparison-clear' => [
                'reviewer' => 'PM Reviewer PRIVATE_PROVIDER_SECRET',
                'evidence_note' => 'PRIVATE_REVIEW_CUSTOMER_NOTE',
                'scores' => [],
            ],
        ];

        $report = app(ArticleSkillOutputEvaluator::class)->evaluate($catalog->cases(), $outputs, $model, $reviews);
        $json = json_encode($report, JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('PRIVATE_CUSTOMER_CONTEXT', $json);
        $this->assertStringNotContainsString('PRIVATE_PROMPT_BODY', $json);
        $this->assertStringNotContainsString('PRIVATE_PROVIDER_SECRET', $json);
        $this->assertStringNotContainsString('PRIVATE_REVIEW_CUSTOMER_NOTE', $json);
        $this->assertArrayNotHasKey('reviewer', $report['pm_review_template'][0]);
        $this->assertTrue($report['pm_review_template'][0]['reviewer_present']);
        $this->assertArrayNotHasKey('name', $report['model']);
        $this->assertSame(hash('sha256', 'sk-PRIVATE_PROVIDER_SECRET'), $report['model']['name_sha256']);
    }

    public function test_real_model_report_still_requires_paired_controls_and_manual_release_approval(): void
    {
        $catalog = app(ArticleSkillEvaluationCatalog::class);
        $reviews = collect($catalog->cases())->mapWithKeys(fn (array $case): array => [
            $case['id'] => [
                'reviewer' => 'PM Reviewer',
                'evidence_note' => 'Reviewed against the fixed rubric.',
                'scores' => array_fill_keys([
                    'factual_support',
                    'clarity',
                    'buyer_decision_value',
                    'structure_naturalness',
                    'uncertainty_and_negative_fit',
                    'privacy_and_safety',
                    'style_fitness',
                    'non_template_naturalness',
                    'improvement_over_master_only',
                ], 5),
            ],
        ])->all();
        $model = $catalog->model() + [
            'model_version' => 'pinned-test-version',
            'code_commit' => str_repeat('a', 40),
        ];
        $model['name'] = 'pinned-real-model';
        $model['provider'] = 'test-provider';
        $model['is_real_model'] = true;

        $report = app(ArticleSkillOutputEvaluator::class)->evaluate($catalog->cases(), $catalog->outputs(), $model, $reviews);

        $this->assertTrue($report['summary']['pm_reviews_complete']);
        $this->assertContains('paired_master_controls_required', $report['release_blockers']);
        $this->assertContains('manual_release_approval_required', $report['release_blockers']);
        $this->assertSame('no_go', $report['release_decision']);
    }

    public function test_paired_master_controls_are_counted_from_actual_content_not_model_metadata(): void
    {
        $catalog = app(ArticleSkillEvaluationCatalog::class);
        $model = $catalog->model();
        $model['name'] = 'pinned-real-model';
        $model['provider'] = 'test-provider';
        $model['model_version'] = '2026-07-20';
        $model['code_commit'] = str_repeat('b', 40);
        $model['is_real_model'] = true;
        $model['paired_master_control_count'] = 999;
        $pairs = collect($catalog->cases())
            ->whereIn('expected_status', ['recommended'])
            ->map(function (array $case) use ($catalog): array {
                $output = collect($catalog->outputs())->firstWhere('case_id', $case['id']);

                return [
                    'case_id' => $case['id'],
                    'master_content' => 'Master-only output for '.$case['id'],
                    'skill_content' => $output['content'],
                    'shared_context_sha256' => str_repeat('c', 64),
                    'model_config_sha256' => str_repeat('d', 64),
                ];
            })
            ->values()
            ->all();

        $withoutPairs = app(ArticleSkillOutputEvaluator::class)->evaluate($catalog->cases(), $catalog->outputs(), $model);
        $withPairs = app(ArticleSkillOutputEvaluator::class)->evaluate($catalog->cases(), $catalog->outputs(), $model, [], $pairs);

        $this->assertContains('paired_master_controls_required', $withoutPairs['release_blockers']);
        $this->assertNotContains('paired_master_controls_required', $withPairs['release_blockers']);
        $this->assertSame(10, $withPairs['summary']['paired_master_control_count']);
        $this->assertCount(10, $withPairs['paired_master_controls']);
        $this->assertArrayNotHasKey('master_content', $withPairs['paired_master_controls'][0]);
        $this->assertArrayNotHasKey('skill_content', $withPairs['paired_master_controls'][0]);
        $this->assertContains('external_input_provenance_unverified', $withPairs['release_blockers']);
    }

    public function test_paired_controls_must_match_the_evaluated_skill_output_and_pinned_run_hashes(): void
    {
        $catalog = app(ArticleSkillEvaluationCatalog::class);
        $case = collect($catalog->cases())->firstWhere('id', 'comparison-clear');
        $pair = [
            'case_id' => $case['id'],
            'master_content' => 'Master-only output.',
            'skill_content' => 'A different output than the evaluated case.',
            'shared_context_sha256' => str_repeat('a', 64),
            'model_config_sha256' => str_repeat('b', 64),
        ];

        $model = $catalog->model();
        $model['is_real_model'] = true;
        $report = app(ArticleSkillOutputEvaluator::class)->evaluate(
            $catalog->cases(),
            $catalog->outputs(),
            $model,
            [],
            [$pair]
        );

        $this->assertSame(0, $report['summary']['paired_master_control_count']);
        $this->assertContains('paired_master_controls_required', $report['release_blockers']);
    }

    public function test_real_model_release_gate_uses_only_auto_eligible_candidates_and_enforces_absolute_scores(): void
    {
        $catalog = app(ArticleSkillEvaluationCatalog::class);
        $eligibleCases = collect($catalog->cases())->where('expected_status', 'recommended')->values();
        $reviews = collect($catalog->cases())->mapWithKeys(function (array $case): array {
            $scores = array_fill_keys([
                'factual_support',
                'clarity',
                'buyer_decision_value',
                'structure_naturalness',
                'uncertainty_and_negative_fit',
                'privacy_and_safety',
                'style_fitness',
                'non_template_naturalness',
                'improvement_over_master_only',
            ], 5);
            if ($case['id'] === 'application-clear') {
                $scores['factual_support'] = 3;
            }

            return [$case['id'] => [
                'reviewer' => 'PM Reviewer',
                'evidence_note' => 'Reviewed against the fixed rubric.',
                'scores' => $scores,
            ]];
        })->all();
        $outputs = $catalog->outputs();
        $pairs = $eligibleCases->map(function (array $case) use ($outputs): array {
            $output = collect($outputs)->firstWhere('case_id', $case['id']);

            return [
                'case_id' => $case['id'],
                'master_content' => 'Master-only control for '.$case['id'],
                'skill_content' => $output['content'],
                'master_scores' => array_fill_keys([
                    'factual_support',
                    'clarity',
                    'buyer_decision_value',
                    'structure_naturalness',
                    'uncertainty_and_negative_fit',
                    'privacy_and_safety',
                    'style_fitness',
                    'non_template_naturalness',
                ], 3),
                'shared_context_sha256' => str_repeat('a', 64),
                'model_config_sha256' => str_repeat('b', 64),
                'candidate_context_sha256' => str_repeat('a', 64),
                'control_context_sha256' => str_repeat('a', 64),
                'candidate_model_config_sha256' => str_repeat('b', 64),
                'control_model_config_sha256' => str_repeat('b', 64),
            ];
        })->all();
        $model = $catalog->model() + [
            'model_version' => 'pinned-test-version',
            'code_commit' => str_repeat('c', 40),
        ];
        $model['is_real_model'] = true;

        $report = app(ArticleSkillOutputEvaluator::class)->evaluate($catalog->cases(), $outputs, $model, $reviews, $pairs);

        $this->assertSame(10, $report['release_gate']['cohorts']['candidate']['count']);
        $this->assertSame(10, $report['release_gate']['cohorts']['control']['count']);
        $this->assertSame(0, $report['release_gate']['cohorts']['style']['count']);
        $this->assertFalse($report['release_gate']['gates']['candidate_absolute']);
        $this->assertContains('candidate_absolute_threshold_failed', $report['release_blockers']);
    }

    public function test_release_pairs_reject_duplicate_rows_and_unmatched_provenance_hashes_without_throwing(): void
    {
        $catalog = app(ArticleSkillEvaluationCatalog::class);
        $outputs = $catalog->outputs();
        $case = collect($catalog->cases())->firstWhere('id', 'comparison-clear');
        $output = collect($outputs)->firstWhere('case_id', $case['id']);
        $base = [
            'case_id' => $case['id'],
            'master_content' => 'Master control.',
            'skill_content' => $output['content'],
            'master_scores' => array_fill_keys([
                'factual_support', 'clarity', 'buyer_decision_value', 'structure_naturalness',
                'uncertainty_and_negative_fit', 'privacy_and_safety', 'style_fitness', 'non_template_naturalness',
            ], 4),
            'shared_context_sha256' => str_repeat('a', 64),
            'model_config_sha256' => str_repeat('b', 64),
            'candidate_context_sha256' => str_repeat('a', 64),
            'control_context_sha256' => str_repeat('c', 64),
            'candidate_model_config_sha256' => str_repeat('b', 64),
            'control_model_config_sha256' => str_repeat('b', 64),
        ];
        $model = $catalog->model();
        $model['is_real_model'] = true;

        $report = app(ArticleSkillOutputEvaluator::class)->evaluate(
            $catalog->cases(),
            $outputs,
            $model,
            [],
            [$base, $base, 'malformed-row']
        );

        $this->assertSame(1, $report['summary']['duplicate_paired_control_case_count']);
        $this->assertSame(0, $report['summary']['provenance_verified_pair_count']);
        $this->assertContains('duplicate_paired_controls', $report['release_blockers']);
        $this->assertContains('invalid_release_cohort', $report['release_blockers']);
    }

    public function test_output_set_must_match_the_fixed_catalog_exactly(): void
    {
        $catalog = app(ArticleSkillEvaluationCatalog::class);
        $outputs = $catalog->outputs();
        $outputs[] = ['case_id' => 'unknown-case', 'content' => 'Unexpected output'];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('exactly match the fixed evaluation catalog');

        app(ArticleSkillOutputEvaluator::class)->evaluate($catalog->cases(), $outputs, $catalog->model());
    }

    public function test_catalog_exercises_both_supported_generation_languages(): void
    {
        $catalog = app(ArticleSkillEvaluationCatalog::class);

        $this->assertContains('en', collect($catalog->cases())->pluck('language')->unique()->all());
        $this->assertContains('zh-CN', collect($catalog->cases())->pluck('language')->unique()->all());
    }

    public function test_direct_service_call_does_not_cast_string_false_to_real_model_mode(): void
    {
        $catalog = app(ArticleSkillEvaluationCatalog::class);
        $model = $catalog->model();
        $model['is_real_model'] = 'false';

        $report = app(ArticleSkillOutputEvaluator::class)->evaluate($catalog->cases(), $catalog->outputs(), $model);

        $this->assertSame('offline_fixture', $report['evaluation_mode']);
        $this->assertFalse($report['model']['is_real_model']);
        $this->assertContains('real_model_evaluation_required', $report['release_blockers']);
        $this->assertNotContains('external_input_provenance_unverified', $report['release_blockers']);
    }
}
