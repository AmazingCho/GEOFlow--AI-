<?php

namespace Tests\Unit;

use App\Services\GeoFlow\ArticleDeepOutputValidator;
use App\Services\GeoFlow\ArticlePlanValidationException;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ArticleDeepOutputValidatorTest extends TestCase
{
    public function test_plan_accepts_structured_array_and_canonicalizes_v2_contract(): void
    {
        $plan = app(ArticleDeepOutputValidator::class)->validatePlan(
            $this->validPlan(),
            ['KB:12:CHUNK:3:0123456789abcdef']
        );

        $this->assertSame('Which configuration fits the buyer?', $plan['reader_question']);
        $this->assertSame('direct', $plan['answer_mode']);
        $this->assertSame('evidence', $plan['supported_sections'][0]['support_type']);
        $this->assertSame('Compare verified operating constraints', $plan['supported_sections'][0]['purpose']);
        $this->assertArrayNotHasKey('central_answer', $plan);
        $this->assertArrayNotHasKey('open_questions', $plan);
    }

    public function test_plan_accepts_fenced_json_for_provider_compatibility(): void
    {
        $validated = app(ArticleDeepOutputValidator::class)->validatePlan(
            "```json\n".json_encode($this->validPlan(), JSON_THROW_ON_ERROR)."\n```",
            ['KB:12:CHUNK:3:0123456789abcdef']
        );

        $this->assertSame('sufficient', $validated['evidence_sufficiency']);
    }

    public function test_limited_plan_requires_conditional_mode_and_supported_content(): void
    {
        $plan = $this->validPlan();
        $plan['evidence_sufficiency'] = 'limited';
        $plan['answer_mode'] = 'direct';

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('limited');

        app(ArticleDeepOutputValidator::class)->validatePlan(
            $plan,
            ['KB:12:CHUNK:3:0123456789abcdef']
        );
    }

    public function test_insufficient_plan_stops_without_article_sections_and_uses_typed_verification_items(): void
    {
        $plan = $this->validPlan();
        $plan['evidence_sufficiency'] = 'insufficient';
        $plan['answer_mode'] = 'stop';
        $plan['supported_sections'] = [];
        $plan['evidence_mapping'] = [];
        $plan['verification_items'] = [[
            'question' => 'Confirm the measured process load.',
            'category' => 'specification',
            'required_for_draft' => true,
        ]];

        $validated = app(ArticleDeepOutputValidator::class)->validatePlan($plan, []);

        $this->assertSame('stop', $validated['answer_mode']);
        $this->assertSame([], $validated['supported_sections']);
        $this->assertTrue($validated['verification_items'][0]['required_for_draft']);
    }

    public function test_insufficient_plan_requires_a_draft_blocking_verification_item(): void
    {
        $plan = $this->validPlan();
        $plan['evidence_sufficiency'] = 'insufficient';
        $plan['answer_mode'] = 'stop';
        $plan['supported_sections'] = [];
        $plan['evidence_mapping'] = [];
        $plan['verification_items'][0]['required_for_draft'] = false;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('verification_items');

        app(ArticleDeepOutputValidator::class)->validatePlan($plan, []);
    }

    public function test_plan_rejects_unknown_evidence_references(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('evidence_refs');

        app(ArticleDeepOutputValidator::class)->validatePlan(
            $this->validPlan(),
            ['KB:12:CHUNK:3:ffffffffffffffff']
        );
    }

    public function test_general_explanation_cannot_claim_evidence_or_specific_product_facts(): void
    {
        $plan = $this->validPlan();
        $plan['supported_sections'][0] = [
            'purpose' => 'MX-200 supports 30 kg loads.',
            'support_type' => 'general_explanation',
            'evidence_refs' => ['KB:12:CHUNK:3:0123456789abcdef'],
        ];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('general_explanation');

        app(ArticleDeepOutputValidator::class)->validatePlan(
            $plan,
            ['KB:12:CHUNK:3:0123456789abcdef']
        );
    }

    public function test_verification_category_and_boolean_are_strictly_typed(): void
    {
        $plan = $this->validPlan();
        $plan['verification_items'][0]['category'] = 'marketing_guess';
        $plan['verification_items'][0]['required_for_draft'] = 'false';

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('category');

        app(ArticleDeepOutputValidator::class)->validatePlan(
            $plan,
            ['KB:12:CHUNK:3:0123456789abcdef']
        );
    }

    public function test_plan_validation_reports_all_safe_contract_violations_in_one_pass(): void
    {
        $plan = $this->validPlan();
        $plan['answer_mode'] = 'invent';
        $plan['evidence_sufficiency'] = 'unknown';
        $plan['supported_sections'][0]['evidence_refs'] = ['UNKNOWN'];
        $plan['verification_items'][0]['category'] = 'guess';

        try {
            app(ArticleDeepOutputValidator::class)->validatePlan(
                $plan,
                ['KB:12:CHUNK:3:0123456789abcdef']
            );
            $this->fail('The invalid plan must be rejected.');
        } catch (ArticlePlanValidationException $exception) {
            $this->assertSame([
                'schema.invalid_enum',
                'schema.invalid_enum',
                'evidence.unknown_reference',
                'schema.invalid_enum',
            ], array_column($exception->violations, 'code'));
            $this->assertSame([
                '$.evidence_sufficiency',
                '$.answer_mode',
                '$.supported_sections[0].evidence_refs[0]',
                '$.verification_items[0].category',
            ], array_column($exception->violations, 'path'));
        }
    }

    public function test_plan_normalizes_enum_casing_without_changing_meaning(): void
    {
        $plan = $this->validPlan();
        $plan['answer_mode'] = 'DIRECT';
        $plan['evidence_sufficiency'] = 'Sufficient';

        $validated = app(ArticleDeepOutputValidator::class)->validatePlan(
            $plan,
            ['KB:12:CHUNK:3:0123456789abcdef']
        );

        $this->assertSame('direct', $validated['answer_mode']);
        $this->assertSame('sufficient', $validated['evidence_sufficiency']);
    }

    public function test_sufficient_plan_rejects_empty_structure_and_near_match_evidence_ids(): void
    {
        $plan = $this->validPlan();
        $plan['supported_sections'] = [];
        $plan['evidence_mapping'][0]['evidence_refs'] = ['KB:12:CHUNK:3:0123456789abcdee'];

        try {
            app(ArticleDeepOutputValidator::class)->validatePlan(
                $plan,
                ['KB:12:CHUNK:3:0123456789abcdef']
            );
            $this->fail('Near-match IDs and empty sufficient plans must be rejected.');
        } catch (ArticlePlanValidationException $exception) {
            $this->assertContains('contract.inconsistent_state', array_column($exception->violations, 'code'));
            $this->assertContains('evidence.unknown_reference', array_column($exception->violations, 'code'));
        }
    }

    public function test_review_normalizes_issue_codes_and_detects_blocking_safety_issues(): void
    {
        $review = app(ArticleDeepOutputValidator::class)->validateReview(json_encode([
            'passed' => false,
            'score' => 62,
            'issue_codes' => ['unsupported_claim', 'privacy_violation', 'unsupported_claim'],
            'issues' => [
                ['code' => 'privacy_violation', 'severity' => 'critical', 'message' => 'Customer identity is exposed.'],
            ],
            'revision_instructions' => [
                ['target' => 'Case result', 'instruction' => 'Remove identifying details.'],
            ],
            'metrics' => $this->reviewMetrics(factualSupport: 2),
        ]));

        $this->assertSame(['unsupported_claim', 'privacy_violation'], $review['issue_codes']);
        $this->assertTrue(app(ArticleDeepOutputValidator::class)->hasBlockingIssues($review));
    }

    public function test_review_requires_exactly_all_eight_metrics(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('metrics');

        app(ArticleDeepOutputValidator::class)->validateReview(json_encode([
            'passed' => true,
            'score' => 92,
            'issue_codes' => [],
            'issues' => [],
            'revision_instructions' => [],
            'metrics' => ['factual_support' => 5, 'clarity' => 4],
        ]));
    }

    public function test_review_cannot_pass_with_weak_factual_or_privacy_score(): void
    {
        foreach ([$this->reviewMetrics(factualSupport: 3), $this->reviewMetrics(privacyAndSafety: 3)] as $metrics) {
            $review = app(ArticleDeepOutputValidator::class)->validateReview(json_encode([
                'passed' => true,
                'score' => 92,
                'issue_codes' => [],
                'issues' => [],
                'revision_instructions' => [],
                'metrics' => $metrics,
            ]));

            $this->assertFalse($review['passed']);
        }
    }

    #[DataProvider('invalidJsonProvider')]
    public function test_unparseable_structured_output_is_rejected(string $output): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(ArticleDeepOutputValidator::class)->validatePlan($output);
    }

    /** @return array<string,array{string}> */
    public static function invalidJsonProvider(): array
    {
        return [
            'plain prose' => ['Here is a thoughtful plan, but it is not JSON.'],
            'truncated json' => ['{"reader_question":"What",'],
            'empty' => [''],
        ];
    }

    /** @return array<string,mixed> */
    private function validPlan(): array
    {
        return [
            'reader_question' => 'Which configuration fits the buyer?',
            'answer_mode' => 'direct',
            'evidence_sufficiency' => 'sufficient',
            'supported_sections' => [[
                'purpose' => 'Compare verified operating constraints',
                'support_type' => 'evidence',
                'evidence_refs' => ['KB:12:CHUNK:3:0123456789abcdef'],
            ]],
            'evidence_mapping' => [[
                'claim_scope' => 'Operating constraints',
                'evidence_refs' => ['KB:12:CHUNK:3:0123456789abcdef'],
            ]],
            'optional_modules' => [],
            'unsupported_claims_to_avoid' => ['Do not invent performance figures.'],
            'verification_items' => [[
                'question' => 'Confirm the measured process load.',
                'category' => 'process',
                'required_for_draft' => false,
            ]],
        ];
    }

    /** @return array<string,int> */
    private function reviewMetrics(int $factualSupport = 5, int $privacyAndSafety = 5): array
    {
        return [
            'factual_support' => $factualSupport,
            'clarity' => 4,
            'buyer_decision_value' => 4,
            'structure_naturalness' => 4,
            'uncertainty_and_negative_fit' => 4,
            'privacy_and_safety' => $privacyAndSafety,
            'style_fitness' => 4,
            'non_template_naturalness' => 4,
        ];
    }
}
