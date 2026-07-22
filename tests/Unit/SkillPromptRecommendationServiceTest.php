<?php

namespace Tests\Unit;

use App\Models\Prompt;
use App\Models\Title;
use App\Models\TitleLibrary;
use App\Services\GeoFlow\SkillPromptRecommendationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SkillPromptRecommendationServiceTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('intentTitleProvider')]
    public function test_it_classifies_all_seven_intents_in_chinese_and_english(string $title, string $expectedIntent): void
    {
        $result = app(SkillPromptRecommendationService::class)->classifyTitle($title);

        $this->assertNotNull($result);
        $this->assertSame($expectedIntent, $result['intent']);
        $this->assertGreaterThanOrEqual(60, $result['confidence']);
    }

    public function test_explicit_comparison_wins_a_tie_with_buying_language(): void
    {
        $result = app(SkillPromptRecommendationService::class)
            ->classifyTitle('How to choose an air-cooled vs water-cooled chiller');

        $this->assertSame('comparison', $result['intent']);
    }

    #[DataProvider('boundaryIntentProvider')]
    public function test_chinese_intent_boundaries_are_deterministic(string $title, string $expectedIntent): void
    {
        $result = app(SkillPromptRecommendationService::class)->classifyTitle($title);

        $this->assertNotNull($result);
        $this->assertSame($expectedIntent, $result['intent']);
    }

    public function test_low_confidence_title_returns_no_classification(): void
    {
        $this->assertNull(
            app(SkillPromptRecommendationService::class)->classifyTitle('Notes from the factory')
        );
    }

    public function test_auto_uses_explicit_intent_metadata_and_ignores_prompt_name_or_content(): void
    {
        $library = TitleLibrary::query()->create(['name' => 'Decision articles']);
        Title::query()->create([
            'library_id' => $library->id,
            'title' => 'Air-Cooled vs Water-Cooled Chiller: Key Differences',
            'keyword' => 'air cooled vs water cooled chiller',
        ]);
        Prompt::query()->create([
            'name' => 'Comparison Skill With No Metadata',
            'type' => 'skill',
            'content' => 'comparison compare difference vs',
            'intent_key' => null,
        ]);
        $explicit = Prompt::query()->create([
            'name' => 'Neutral Internal Label',
            'type' => 'skill',
            'content' => 'No intent words are required here.',
            'intent_key' => 'comparison',
        ]);

        $result = app(SkillPromptRecommendationService::class)
            ->recommendForTitleLibrary((int) $library->id);

        $this->assertNotNull($result);
        $this->assertSame($explicit->id, $result['skill_prompt_id']);
        $this->assertSame('comparison', $result['intent']);
        $this->assertNotEmpty($result['sample_titles']);
    }

    #[DataProvider('gatedIntentProvider')]
    public function test_case_and_troubleshooting_are_classified_as_manual_only(string $title, string $intent): void
    {
        $library = TitleLibrary::query()->create(['name' => $title]);
        Title::query()->create([
            'library_id' => $library->id,
            'title' => $title,
            'keyword' => '',
        ]);
        Prompt::query()->create([
            'name' => 'Explicit '.$intent,
            'type' => 'skill',
            'content' => 'Explicitly configured Skill.',
            'intent_key' => $intent,
        ]);

        $service = app(SkillPromptRecommendationService::class);

        $this->assertSame($intent, $service->classifyTitle($title)['intent']);
        $recommendation = $service->recommendForTitleLibrary((int) $library->id);
        $this->assertNotNull($recommendation);
        $this->assertSame($intent, $recommendation['intent']);
        $this->assertSame('manual_only', $recommendation['status']);
        $this->assertFalse($recommendation['auto_eligible']);
        $this->assertNull($recommendation['skill_prompt_id']);
    }

    public static function intentTitleProvider(): array
    {
        return [
            'comparison en' => ['Air-Cooled vs Water-Cooled Chillers: Key Differences', 'comparison'],
            'comparison zh' => ['风冷冷水机与水冷冷水机有什么区别', 'comparison'],
            'buying guide en' => ['How to Choose the Right Industrial Chiller Size', 'buying_guide'],
            'buying guide zh' => ['工业冷水机选型指南：如何选择合适规格', 'buying_guide'],
            'application en' => ['Industrial Chillers for Battery Manufacturing Applications', 'application'],
            'application zh' => ['工业冷水机在电池制造中的应用场景', 'application'],
            'technical en' => ['How Does a Two-Component Dispensing Valve Work?', 'technical'],
            'technical zh' => ['双组份点胶阀的工作原理是什么', 'technical'],
            'troubleshooting en' => ['Why Is My Dispensing Valve Clogging and How to Fix It?', 'troubleshooting'],
            'troubleshooting zh' => ['点胶阀堵塞的原因与故障排查方法', 'troubleshooting'],
            'case study en' => ['Case Study: Improving Battery Potting Consistency', 'case_study'],
            'case study zh' => ['客户案例：提升电池灌封一致性', 'case_study'],
            'definition en' => ['What Is a Two-Component Dispensing Machine?', 'definition'],
            'definition zh' => ['什么是双组份点胶机', 'definition'],
        ];
    }

    public static function gatedIntentProvider(): array
    {
        return [
            'case study' => ['Case Study: Improving Battery Potting Consistency', 'case_study'],
            'troubleshooting' => ['Why Is My Dispensing Valve Clogging and How to Fix It?', 'troubleshooting'],
        ];
    }

    public static function boundaryIntentProvider(): array
    {
        return [
            'strong buying intent over comparison' => ['如何选择风冷冷水机与水冷冷水机：规格对比', 'buying_guide'],
            'technical over definition tie' => ['什么是双组份点胶机的工作原理', 'technical'],
            'case over application' => ['客户案例：工业冷水机在电池制造中的应用', 'case_study'],
        ];
    }
}
