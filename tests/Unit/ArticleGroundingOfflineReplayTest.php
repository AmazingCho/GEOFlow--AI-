<?php

namespace Tests\Unit;

use App\Models\Article;
use App\Services\GeoFlow\ArticleEvidencePackage;
use App\Services\GeoFlow\ArticleGroundingGate;
use App\Services\GeoFlow\ArticlePublicationBlockedException;
use App\Services\GeoFlow\ArticlePublicationGuard;
use App\Services\GeoFlow\ArticleSkillReleaseGate;
use App\Services\GeoFlow\PromptPresetCatalog;
use InvalidArgumentException;
use Tests\TestCase;

class ArticleGroundingOfflineReplayTest extends TestCase
{
    private array $fixture;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fixture = require base_path('tests/Fixtures/article-grounding/offline-replay.php');
    }

    public function test_grounding_cases_replay_deterministically_without_network_or_database(): void
    {
        $packages = app(ArticleEvidencePackage::class);
        $gate = app(ArticleGroundingGate::class);

        foreach ($this->fixture['grounding'] as $name => $case) {
            $evidence = [$packages->make('knowledge_chunk', 1, 'Synthetic manual', $case['evidence'], 0)];
            $claimAnalysis = is_array($case['claim_analysis'] ?? null) ? $case['claim_analysis'] : [];
            $first = $gate->evaluate($case['content'], $evidence, $claimAnalysis);
            $second = $gate->evaluate($case['content'], $evidence, $claimAnalysis);

            $this->assertSame($first, $second, $name);
            $this->assertSame($case['outcome'], $first['outcome'], $name);
            $this->assertSame($case['issue_code'], $first['issues'][0]['code'] ?? null, $name);
        }
    }

    public function test_marker_cases_replay_valid_missing_and_unknown_evidence_ids(): void
    {
        $packages = app(ArticleEvidencePackage::class);
        $evidence = $packages->make(
            'knowledge_chunk',
            12,
            'Synthetic manual',
            'The verified travel is 300 mm.',
            3
        );
        $unknownId = 'KB:999:CHUNK:1:deadbeefdeadbeef';

        foreach ($this->fixture['markers'] as $name => $case) {
            $article = str_replace(
                ['{EVIDENCE_ID}', '{UNKNOWN_ID}'],
                [$evidence['id'], $unknownId],
                $case['article']
            );

            if ($case['throws']) {
                try {
                    $packages->validateAndStripMarkers($article, [$evidence]);
                    $this->fail($name.' should reject an unknown evidence marker.');
                } catch (InvalidArgumentException $exception) {
                    $this->assertStringNotContainsString($unknownId, $exception->getMessage(), $name);
                    $this->assertStringNotContainsString('300 mm', $exception->getMessage(), $name);
                }

                continue;
            }

            $result = $packages->validateAndStripMarkers($article, [$evidence]);
            $this->assertSame($case['coverage_status'], $result['coverage_status'], $name);
            $this->assertStringNotContainsString('<!-- evidence:', $result['content'], $name);
        }
    }

    public function test_publication_cases_replay_revoked_and_explicit_approval_boundaries(): void
    {
        $guard = app(ArticlePublicationGuard::class);

        foreach ($this->fixture['publication'] as $name => $case) {
            $article = new Article;
            $article->review_status = $case['review_status'];
            $article->context_snapshot = [
                'grounding_gate' => ['outcome' => $case['grounding_outcome']],
            ];
            if ((bool) ($case['approval_bound_to_current_revision'] ?? false)) {
                $snapshot = $article->context_snapshot;
                $snapshot['review_approval'] = [
                    'article_sha256' => hash('sha256', "\0"),
                ];
                $article->context_snapshot = $snapshot;
            }

            try {
                $guard->assertCanPublish($article);
                $this->assertTrue($case['allowed'], $name);
            } catch (ArticlePublicationBlockedException $exception) {
                $this->assertFalse($case['allowed'], $name);
                $this->assertSame($case['reason_code'], $exception->reasonCode, $name);
            }
        }
    }

    public function test_release_cohorts_replay_pairing_and_style_diagnostic_boundaries(): void
    {
        $gate = app(ArticleSkillReleaseGate::class);

        foreach ($this->fixture['release'] as $name => $case) {
            $report = $gate->evaluate($case['artifacts'], [
                'expected_pair_keys' => $case['expected_pair_keys'],
            ]);

            $this->assertSame($case['valid'], $report['valid'], $name);
            $this->assertSame($case['decision'], $report['release_decision'], $name);
            if ($case['issue'] !== null) {
                $this->assertContains($case['issue'], $report['validation_issues'], $name);
            }
        }
    }

    public function test_prompt_hash_replay_changes_only_the_four_approved_v23_presets(): void
    {
        $baseline = require base_path('tests/Fixtures/article-grounding/prompt-v22-baseline-hashes.php');
        $candidate = collect(app(PromptPresetCatalog::class)->candidate())
            ->mapWithKeys(fn (array $preset): array => [$preset['preset_key'] => $preset['content_hash']])
            ->all();

        $changed = collect($baseline)
            ->filter(fn (string $hash, string $key): bool => ($candidate[$key] ?? null) !== $hash)
            ->keys()
            ->sort()
            ->values()
            ->all();
        $expected = collect($this->fixture['prompt_changed_keys'])->sort()->values()->all();

        $this->assertSame($expected, $changed);
    }
}
