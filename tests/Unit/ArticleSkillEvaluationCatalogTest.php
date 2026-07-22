<?php

namespace Tests\Unit;

use App\Services\GeoFlow\ArticleSkillEvaluationCatalog;
use App\Services\GeoFlow\SkillPromptRecommendationService;
use App\Support\GeoFlow\ArticleSkillIntents;
use Tests\TestCase;

class ArticleSkillEvaluationCatalogTest extends TestCase
{
    public function test_catalog_has_two_cases_per_intent_and_one_master_only_control(): void
    {
        $catalog = app(ArticleSkillEvaluationCatalog::class);
        $cases = collect($catalog->cases());

        $this->assertCount(15, $cases);
        foreach (ArticleSkillIntents::all() as $intent) {
            $intentCases = $cases->where('expected_intent', $intent);
            $this->assertCount(2, $intentCases, $intent);
            $this->assertSame(['boundary', 'clear'], $intentCases->pluck('variant')->sort()->values()->all());
        }

        $control = $cases->whereNull('expected_intent');
        $this->assertCount(1, $control);
        $this->assertSame('master_only', $control->first()['expected_status']);
    }

    public function test_catalog_routing_expectations_match_the_runtime_classifier(): void
    {
        $catalog = app(ArticleSkillEvaluationCatalog::class);
        $classifier = app(SkillPromptRecommendationService::class);

        foreach ($catalog->cases() as $case) {
            $classification = $classifier->classifyTitle(trim($case['title'].' '.$case['keyword']));
            $this->assertSame($case['expected_intent'], $classification['intent'] ?? null, $case['id']);
        }
    }

    public function test_offline_fixture_is_explicitly_not_a_real_model_run(): void
    {
        $catalog = app(ArticleSkillEvaluationCatalog::class);

        $this->assertSame('offline-fixture-v1', $catalog->model()['name']);
        $this->assertFalse($catalog->model()['is_real_model']);
        $this->assertSame(0, $catalog->model()['temperature']);
        $this->assertGreaterThanOrEqual(1500, $catalog->model()['max_output_tokens']);
        $this->assertCount(15, $catalog->outputs());
        $this->assertSame(
            collect($catalog->cases())->pluck('id')->sort()->values()->all(),
            collect($catalog->outputs())->pluck('case_id')->sort()->values()->all()
        );
    }
}
