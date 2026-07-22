<?php

namespace Tests\Unit;

use App\Services\GeoFlow\ArticleEvidencePackage;
use App\Services\GeoFlow\ArticleGroundingGate;
use Tests\TestCase;

class ArticleGroundingGateTest extends TestCase
{
    public function test_supported_number_with_unit_passes(): void
    {
        $evidence = $this->evidence('The verified working area is 300 x 300 mm.');

        $result = app(ArticleGroundingGate::class)->evaluate(
            'The verified working area is 300 x 300 mm.',
            [$evidence]
        );

        $this->assertSame('pass', $result['outcome']);
        $this->assertSame([], $result['issues']);
    }

    public function test_unsupported_number_with_unit_is_blocked(): void
    {
        $result = app(ArticleGroundingGate::class)->evaluate(
            'The system handles 500 kg.',
            [$this->evidence('The verified working area is 300 mm.')]
        );

        $this->assertSame('blocked', $result['outcome']);
        $this->assertSame('unsupported_numeric_unit', $result['issues'][0]['code']);
        $this->assertSame('critical', $result['issues'][0]['severity']);
        $this->assertGreaterThanOrEqual(90, $result['issues'][0]['confidence']);
    }

    public function test_harmless_numbers_are_not_numeric_claims(): void
    {
        $result = app(ArticleGroundingGate::class)->evaluate(
            "1. Check the process.\nDate: 2026-07-21.\nPage 2 of 3.\nModel SJ4060.",
            [$this->evidence('General selection guidance.')]
        );

        $this->assertSame('pass', $result['outcome']);
    }

    public function test_unsupported_percentage_is_blocked(): void
    {
        $result = app(ArticleGroundingGate::class)->evaluate(
            'The configuration increases output by 42%.',
            [$this->evidence('General selection guidance.')]
        );

        $this->assertSame('blocked', $result['outcome']);
        $this->assertSame('unsupported_numeric_unit', $result['issues'][0]['code']);
    }

    public function test_thousands_separator_is_not_confused_with_decimal_separator(): void
    {
        $result = app(ArticleGroundingGate::class)->evaluate(
            'The machine weighs 1.200 kg.',
            [$this->evidence('The verified machine weight is 1,200 kg.')]
        );

        $this->assertSame('blocked', $result['outcome']);
        $this->assertSame('unsupported_numeric_unit', $result['issues'][0]['code']);
    }

    public function test_multi_group_thousands_are_parsed_as_one_number(): void
    {
        $result = app(ArticleGroundingGate::class)->evaluate(
            'The machine handles 200,000 kg.',
            [$this->evidence('The documented limit is 1,200,000 kg.')]
        );

        $this->assertSame('blocked', $result['outcome']);
        $this->assertSame('unsupported_numeric_unit', $result['issues'][0]['code']);
    }

    public function test_direct_contact_exposure_is_blocked(): void
    {
        $result = app(ArticleGroundingGate::class)->evaluate(
            'Contact the customer at private.customer@example.com or +351 912 345 678.',
            [$this->evidence('General selection guidance.')]
        );

        $this->assertSame('blocked', $result['outcome']);
        $this->assertSame('privacy_contact_exposure', $result['issues'][0]['code']);
    }

    public function test_labeled_continuous_phone_number_is_blocked(): void
    {
        $result = app(ArticleGroundingGate::class)->evaluate(
            'Customer phone: 13800138000.',
            [$this->evidence('General selection guidance.')]
        );

        $this->assertSame('blocked', $result['outcome']);
        $this->assertSame('privacy_contact_exposure', $result['issues'][0]['code']);
    }

    public function test_phone_number_with_common_label_words_is_blocked(): void
    {
        $gate = app(ArticleGroundingGate::class);
        $evidence = [$this->evidence('General selection guidance.')];

        $whatsapp = $gate->evaluate('Customer WhatsApp number: 13800138000.', $evidence);
        $mobile = $gate->evaluate('Mobile number is 13800138000.', $evidence);

        $this->assertSame('blocked', $whatsapp['outcome']);
        $this->assertSame('blocked', $mobile['outcome']);
    }

    public function test_unsafe_imperative_is_blocked_but_negated_warning_passes(): void
    {
        $gate = app(ArticleGroundingGate::class);
        $evidence = [$this->evidence('General maintenance safety guidance.')];

        $unsafe = $gate->evaluate('Disable the safety interlock before servicing.', $evidence);
        $warning = $gate->evaluate('Never disable the safety interlock before servicing.', $evidence);

        $this->assertSame('blocked', $unsafe['outcome']);
        $this->assertSame('unsafe_operational_instruction', $unsafe['issues'][0]['code']);
        $this->assertSame('pass', $warning['outcome']);
    }

    public function test_negation_in_an_earlier_clause_does_not_hide_a_later_unsafe_instruction(): void
    {
        $result = app(ArticleGroundingGate::class)->evaluate(
            'Do not touch the display, then disable the safety interlock before servicing.',
            [$this->evidence('General maintenance safety guidance.')]
        );

        $this->assertSame('blocked', $result['outcome']);
        $this->assertSame('unsafe_operational_instruction', $result['issues'][0]['code']);
    }

    public function test_negated_safe_action_does_not_hide_unsafe_action_after_while(): void
    {
        $result = app(ArticleGroundingGate::class)->evaluate(
            'Do not disable the display while you remove the safety guard.',
            [$this->evidence('General maintenance safety guidance.')]
        );

        $this->assertSame('blocked', $result['outcome']);
        $this->assertSame('unsafe_operational_instruction', $result['issues'][0]['code']);
    }

    public function test_ambiguous_claim_and_partial_coverage_require_review(): void
    {
        $gate = app(ArticleGroundingGate::class);
        $evidence = [$this->evidence('General selection guidance.')];

        $ambiguous = $gate->evaluate('The system may significantly improve throughput.', $evidence);
        $partial = $gate->evaluate('General selection guidance.', $evidence, ['coverage_status' => 'partial']);

        $this->assertSame('pending_review', $ambiguous['outcome']);
        $this->assertSame('ambiguous_specific_claim', $ambiguous['issues'][0]['code']);
        $this->assertSame('pending_review', $partial['outcome']);
        $this->assertSame('partial_claim_coverage', $partial['issues'][0]['code']);
    }

    public function test_limited_evidence_blocks_extreme_output_expansion(): void
    {
        $result = app(ArticleGroundingGate::class)->evaluate(
            str_repeat('Generic but unsupported expansion sentence. ', 140),
            [$this->evidence('Short verified source.')],
            ['evidence_sufficiency' => 'limited']
        );

        $this->assertSame('blocked', $result['outcome']);
        $this->assertContains('limited_evidence_overexpansion', array_column($result['issues'], 'code'));
    }

    public function test_result_contains_only_hashes_and_safe_identifiers(): void
    {
        $private = 'private.customer@example.com';
        $result = app(ArticleGroundingGate::class)->evaluate(
            'Contact '.$private.' about the 500 kg claim.',
            [$this->evidence('The verified working area is 300 mm.')]
        );
        $serialized = json_encode($result, JSON_THROW_ON_ERROR);

        $this->assertArrayHasKey('content_sha256', $result);
        $this->assertArrayHasKey('excerpt_sha256', $result['issues'][0]);
        $this->assertStringNotContainsString($private, $serialized);
        $this->assertStringNotContainsString('500 kg', $serialized);
        $this->assertStringNotContainsString('excerpt"', $serialized);
    }

    /** @return array<string,mixed> */
    private function evidence(string $content): array
    {
        return app(ArticleEvidencePackage::class)->make(
            'knowledge_chunk',
            1,
            'Approved manual',
            $content,
            0
        );
    }
}
