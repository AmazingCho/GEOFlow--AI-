<?php

namespace Tests\Feature;

use App\Models\CaseRecord;
use App\Models\Prompt;
use App\Models\Task;
use App\Models\Title;
use App\Models\TitleLibrary;
use App\Services\GeoFlow\WorkerExecutionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use ReflectionProperty;
use Tests\TestCase;

class WorkerAutoSkillResolutionTest extends TestCase
{
    use RefreshDatabase;

    public function test_auto_mode_resolves_each_selected_title_independently(): void
    {
        $comparison = $this->skill('Comparison Skill', 'comparison');
        $buying = $this->skill('Buying Guide Skill', 'buying_guide');
        $task = Task::query()->create([
            'name' => 'Mixed title task',
            'skill_selection_mode' => 'auto',
        ]);

        $comparisonResult = $this->resolve($task, $this->title('Air-Cooled vs Water-Cooled Chiller'));
        $buyingResult = $this->resolve($task, $this->title('How to Choose an Industrial Chiller'));

        $this->assertSame($comparison->id, $comparisonResult['prompt']?->id);
        $this->assertSame('comparison', $comparisonResult['intent']);
        $this->assertSame($buying->id, $buyingResult['prompt']?->id);
        $this->assertSame('buying_guide', $buyingResult['intent']);
    }

    public function test_manual_and_none_modes_do_not_run_automatic_routing(): void
    {
        $manualSkill = $this->skill('Manual Skill', null);
        $this->skill('Comparison Skill', 'comparison');
        $title = $this->title('Pump vs Valve: Key Differences');

        $manual = Task::query()->create([
            'name' => 'Manual task',
            'skill_selection_mode' => 'manual',
            'skill_prompt_id' => $manualSkill->id,
        ]);
        $none = Task::query()->create([
            'name' => 'No skill task',
            'skill_selection_mode' => 'none',
        ]);

        $manualResult = $this->resolve($manual, $title);
        $noneResult = $this->resolve($none, $title);

        $this->assertSame($manualSkill->id, $manualResult['prompt']?->id);
        $this->assertSame('manual', $manualResult['mode']);
        $this->assertNull($manualResult['intent']);
        $this->assertNull($noneResult['prompt']);
        $this->assertSame('none', $noneResult['mode']);
        $this->assertSame('disabled', $noneResult['status']);
    }

    public function test_low_confidence_auto_mode_falls_back_to_master_only(): void
    {
        $this->skill('Comparison Skill', 'comparison');
        $task = Task::query()->create([
            'name' => 'Low confidence task',
            'skill_selection_mode' => 'auto',
        ]);

        $result = $this->resolve($task, $this->title('Industrial Systems Overview'));

        $this->assertNull($result['prompt']);
        $this->assertNull($result['intent']);
        $this->assertSame('fallback', $result['status']);
        $this->assertSame('low_confidence', $result['reason']);
    }

    public function test_case_study_auto_always_falls_back_to_master_as_a_manual_only_intent(): void
    {
        $caseSkill = $this->skill('Case Study Skill', 'case_study');
        $title = $this->title('Customer Case Study: Dispensing Line Upgrade');
        $task = Task::query()->create([
            'name' => 'Case task without evidence',
            'skill_selection_mode' => 'auto',
            'generation_mode' => 'deep',
        ]);

        $blocked = $this->resolve($task, $title);

        $this->assertNull($blocked['prompt']);
        $this->assertSame('case_study', $blocked['intent']);
        $this->assertSame('fallback', $blocked['status']);
        $this->assertSame('case_evidence_missing', $blocked['reason']);

        $case = CaseRecord::query()->create([
            'title' => 'Verified customer result',
            'solution' => 'Installed a controlled dispensing process.',
            'result' => 'The line reached stable production output.',
        ]);
        $task->forceFill(['case_filter' => (string) $case->id])->save();
        $this->setKnowledgeTrace([
            'case_filter_ids' => [$case->id],
            'cases' => [['id' => $case->id, 'title' => $case->title]],
        ]);

        $stillBlocked = $this->resolve($task->fresh(), $title);

        $this->assertNull($stillBlocked['prompt']);
        $this->assertSame('fallback', $stillBlocked['status']);
        $this->assertSame('case_publication_approval_missing', $stillBlocked['reason']);
    }

    public function test_legacy_manual_case_study_without_intent_cannot_bypass_governance(): void
    {
        $legacyCaseSkill = $this->skill('Skill – Case Study & Success Story Article案例+成功故事', null);
        $case = CaseRecord::query()->create([
            'title' => 'Legacy customer result',
            'solution' => 'Installed a controlled dispensing process.',
            'result' => 'The line reached stable production output.',
        ]);
        $task = Task::query()->create([
            'name' => 'Legacy manual case task',
            'skill_selection_mode' => 'manual',
            'skill_prompt_id' => $legacyCaseSkill->id,
            'case_filter' => (string) $case->id,
            'need_review' => 0,
            'generation_mode' => 'deep',
        ]);

        $result = $this->resolve($task, $this->title('Customer implementation result'));

        $this->assertNull($result['prompt']);
        $this->assertSame('case_study', $result['intent']);
        $this->assertSame('blocked', $result['status']);
        $this->assertSame('case_publication_approval_missing', $result['reason']);
    }

    public function test_legacy_manual_skill_with_generic_case_word_is_not_misclassified(): void
    {
        $skill = $this->skill('案例引用规范', null);
        $task = Task::query()->create([
            'name' => 'Generic case guidance task',
            'skill_selection_mode' => 'manual',
            'skill_prompt_id' => $skill->id,
        ]);

        $result = $this->resolve($task, $this->title('How to cite examples responsibly'));

        $this->assertSame($skill->id, $result['prompt']?->id);
        $this->assertNull($result['intent']);
        $this->assertSame('manual', $result['status']);
    }

    public function test_troubleshooting_auto_always_falls_back_to_master_as_a_manual_only_intent(): void
    {
        $troubleshooting = $this->skill('Troubleshooting Skill', 'troubleshooting');
        $title = $this->title('How to Fix a Clogged Dispensing Needle');
        $task = Task::query()->create([
            'name' => 'Troubleshooting task',
            'skill_selection_mode' => 'auto',
            'need_review' => 1,
            'generation_mode' => 'deep',
        ]);

        $blocked = $this->resolve($task, $title);
        $this->assertNull($blocked['prompt']);
        $this->assertSame('fallback', $blocked['status']);
        $this->assertSame('troubleshooting_evidence_missing', $blocked['reason']);

        $this->setKnowledgeTrace([
            'knowledge_base_ids' => [42],
            'context_length' => 480,
        ]);
        $stillBlocked = $this->resolve($task, $title);

        $this->assertNull($stillBlocked['prompt']);
        $this->assertSame('fallback', $stillBlocked['status']);
        $this->assertSame('troubleshooting_safety_classification_missing', $stillBlocked['reason']);

        $task->forceFill(['need_review' => 0])->save();
        $reviewBlocked = $this->resolve($task->fresh(), $title);
        $this->assertNull($reviewBlocked['prompt']);
        $this->assertSame('fallback', $reviewBlocked['status']);
        $this->assertSame('troubleshooting_evidence_missing', $reviewBlocked['reason']);
    }

    public function test_auto_classification_uses_the_selected_title_keyword_as_supporting_signal(): void
    {
        $comparison = $this->skill('Comparison Skill', 'comparison');
        $task = Task::query()->create([
            'name' => 'Keyword-assisted routing task',
            'skill_selection_mode' => 'auto',
        ]);
        $title = $this->title('Industrial Equipment Overview');
        $title->forceFill(['keyword' => 'air cooled vs water cooled chiller'])->save();

        $result = $this->resolve($task, $title->fresh());

        $this->assertSame($comparison->id, $result['prompt']?->id);
        $this->assertSame('comparison', $result['intent']);
    }

    private function skill(string $name, ?string $intent): Prompt
    {
        return Prompt::query()->create([
            'name' => $name,
            'type' => 'skill',
            'intent_key' => $intent,
            'content' => $name.' content',
        ]);
    }

    private function title(string $text): Title
    {
        $library = TitleLibrary::query()->create(['name' => $text.' Library']);

        return Title::query()->create([
            'library_id' => $library->id,
            'title' => $text,
            'keyword' => '',
        ]);
    }

    /** @return array<string,mixed> */
    private function resolve(Task $task, Title $title): array
    {
        $service = app(WorkerExecutionService::class);
        $method = new ReflectionMethod($service, 'resolveSkillPromptForTitle');
        $method->setAccessible(true);

        return $method->invoke($service, $task, $title);
    }

    /** @param array<string,mixed> $trace */
    private function setKnowledgeTrace(array $trace): void
    {
        $service = app(WorkerExecutionService::class);
        $property = new ReflectionProperty($service, 'lastKnowledgeTrace');
        $property->setAccessible(true);
        $property->setValue($service, $trace);
        app()->instance(WorkerExecutionService::class, $service);
    }
}
