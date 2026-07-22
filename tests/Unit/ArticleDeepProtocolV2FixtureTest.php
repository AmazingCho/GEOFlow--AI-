<?php

namespace Tests\Unit;

use App\Services\GeoFlow\ArticleDeepOutputValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ArticleDeepProtocolV2FixtureTest extends TestCase
{
    #[DataProvider('protocolFixtureProvider')]
    public function test_protocol_v2_fixture_reaches_expected_evidence_outcome(array $fixture): void
    {
        $validated = app(ArticleDeepOutputValidator::class)->validatePlan(
            $fixture['plan'],
            $fixture['allowed_evidence_ids']
        );

        $this->assertSame($fixture['expected_outcome'], $validated['evidence_sufficiency']);
        $this->assertArrayHasKey('answer_mode', $validated);
        $this->assertArrayHasKey('supported_sections', $validated);
        $this->assertArrayHasKey('verification_items', $validated);
        $this->assertArrayNotHasKey('article_angle', $validated);
        $this->assertArrayNotHasKey('central_answer', $validated);
        $this->assertArrayNotHasKey('open_questions', $validated);
    }

    public static function protocolFixtureProvider(): array
    {
        $fixtures = require __DIR__.'/../Fixtures/article-deep-protocol-v2/offline-matrix.php';

        return collect($fixtures)
            ->mapWithKeys(static fn (array $fixture): array => [$fixture['key'] => [$fixture]])
            ->all();
    }

    public function test_protocol_matrix_contains_ten_cases_for_each_evidence_state(): void
    {
        $fixtures = require __DIR__.'/../Fixtures/article-deep-protocol-v2/offline-matrix.php';

        $this->assertCount(30, $fixtures);
        $this->assertSame([
            'insufficient' => 10,
            'limited' => 10,
            'sufficient' => 10,
        ], collect($fixtures)->countBy('expected_outcome')->sortKeys()->all());
    }
}
