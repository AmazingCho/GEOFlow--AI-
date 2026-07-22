<?php

namespace Tests\Feature;

use App\Models\AiModel;
use App\Models\Task;
use App\Services\GeoFlow\ArticleContentBlockedException;
use App\Services\GeoFlow\ArticleEvidencePackage;
use App\Services\GeoFlow\ArticleGenerationProtocolException;
use App\Services\GeoFlow\ArticleInsufficientEvidenceException;
use App\Services\GeoFlow\ArticleModelCallRequest;
use App\Services\GeoFlow\ArticleModelCallService;
use App\Services\GeoFlow\ArticleModelSelectionException;
use App\Services\GeoFlow\ArticleProviderFailureException;
use App\Services\GeoFlow\DeepArticleGenerationService;
use App\Support\GeoFlow\ArticleGenerationStage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class DeepArticleGenerationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_deep_generation_fails_closed_before_model_calls_when_evidence_contract_is_missing(): void
    {
        $task = new Task(['generation_mode' => 'deep']);
        $this->mock(ArticleModelCallService::class)
            ->shouldNotReceive('generateStageWithModelSelection');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('结构化证据包');

        app(DeepArticleGenerationService::class)->generate(
            $task,
            'How to Select a Dispensing System',
            'dispensing system selection',
            'Use verified evidence.',
            'Legacy context without a structured evidence package.',
            'en'
        );
    }

    public function test_deep_generation_fails_closed_before_model_calls_when_evidence_package_is_empty(): void
    {
        $task = new Task(['generation_mode' => 'deep']);
        $this->mock(ArticleModelCallService::class)
            ->shouldNotReceive('generateStageWithModelSelection');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('结构化证据包');

        app(DeepArticleGenerationService::class)->generate(
            $task,
            'How to Select a Dispensing System',
            'dispensing system selection',
            'Use verified evidence.',
            'Legacy context only.',
            'en',
            []
        );
    }

    public function test_deep_generation_fails_closed_before_model_calls_when_evidence_package_is_malformed(): void
    {
        $task = new Task(['generation_mode' => 'deep']);
        $this->mock(ArticleModelCallService::class)
            ->shouldNotReceive('generateStageWithModelSelection');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('结构化证据包');

        app(DeepArticleGenerationService::class)->generate(
            $task,
            'How to Select a Dispensing System',
            'dispensing system selection',
            'Use verified evidence.',
            'Legacy context only.',
            'en',
            [['source_type' => 'knowledge_chunk', 'content' => 'Missing identity and hashes']]
        );
    }

    public function test_case_only_deep_generation_fails_before_model_calls(): void
    {
        $task = new Task(['generation_mode' => 'deep']);
        $case = app(ArticleEvidencePackage::class)->make(
            'case',
            9,
            'Customer case',
            'Unverified customer result.'
        );
        $this->mock(ArticleModelCallService::class)
            ->shouldNotReceive('generateStageWithModelSelection');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('结构化证据包');

        app(DeepArticleGenerationService::class)->generate(
            $task,
            'Customer Case Study',
            'customer case',
            'Use verified evidence.',
            "Evidence ID: {$case['id']}\n{$case['content']}",
            'en',
            [$case]
        );
    }

    public function test_deep_generation_rejects_restricted_evidence_in_context_before_model_calls(): void
    {
        $task = new Task(['generation_mode' => 'deep']);
        $knowledge = $this->evidence();
        $case = app(ArticleEvidencePackage::class)->make(
            'case',
            9,
            'Private customer case',
            'PRIVATE-CANARY-DO-NOT-SEND'
        );
        $this->mock(ArticleModelCallService::class)
            ->shouldNotReceive('generateStageWithModelSelection');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('安全证据上下文');

        app(DeepArticleGenerationService::class)->generate(
            $task,
            'How to Select a Dispensing System',
            'dispensing system selection',
            'Use verified evidence.',
            "Evidence ID: {$knowledge['id']}\n{$knowledge['content']}\n\n"
                ."Evidence ID: {$case['id']}\n{$case['content']}",
            'en',
            [$knowledge, $case]
        );
    }

    public function test_deep_generation_rejects_unowned_context_before_model_calls(): void
    {
        $task = new Task(['generation_mode' => 'deep']);
        $knowledge = $this->evidence();
        $this->mock(ArticleModelCallService::class)
            ->shouldNotReceive('generateStageWithModelSelection');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('安全证据上下文');

        app(DeepArticleGenerationService::class)->generate(
            $task,
            'How to Select a Dispensing System',
            'dispensing system selection',
            'Use verified evidence.',
            $this->evidenceContext($knowledge)."\n\nUNOWNED PRIVATE EVIDENCE",
            'en',
            [$knowledge]
        );
    }

    public function test_deterministic_blocker_stops_deep_pipeline_before_review_call(): void
    {
        $task = new Task(['generation_mode' => 'deep']);
        $model = $this->model();
        $evidence = $this->evidence();
        $this->mock(ArticleModelCallService::class)
            ->shouldReceive('generateStageWithModelSelection')
            ->twice()
            ->andReturn(
                $this->modelResult(json_encode($this->validPlan($evidence['id'])), $model),
                $this->modelResult("The system handles 500 kg.\n<!-- evidence:{$evidence['id']} -->", $model),
            );

        try {
            app(DeepArticleGenerationService::class)->generate(
                $task,
                'Evidence-aware article',
                'evidence governance',
                'Use verified evidence.',
                $this->evidenceContext($evidence),
                'en',
                [$evidence]
            );
            $this->fail('The deterministic blocker must become a typed terminal outcome.');
        } catch (ArticleContentBlockedException $exception) {
            $this->assertSame('grounding_blocked', $exception->reasonCode);
            $this->assertSame(ArticleGenerationStage::Draft, $exception->stage);
            $this->assertCount(2, $exception->attempts);
            $this->assertSame(['deep_plan', 'deep_draft'], array_column($exception->stages, 'name'));
            $this->assertSame('failed', $exception->stages[1]['status']);
        }
    }

    public function test_provider_failure_after_a_valid_plan_preserves_prior_and_failed_attempts(): void
    {
        $task = new Task(['generation_mode' => 'deep']);
        $model = $this->model();
        $evidence = $this->evidence();
        $failedAttempt = [
            'model_id' => (int) $model->id,
            'model_name' => (string) $model->name,
            'status' => 'failed',
            'reason' => 'AI 生成失败',
            'duration_ms' => 25,
            'finish_reason' => null,
            'prompt_tokens' => 35,
            'completion_tokens' => 0,
            'reasoning_tokens' => 0,
        ];
        $this->mock(ArticleModelCallService::class)
            ->shouldReceive('generateStageWithModelSelection')
            ->twice()
            ->andReturnUsing(function (Task $receivedTask, ArticleModelCallRequest $request) use ($evidence, $model, $failedAttempt): array {
                if ($request->stage === ArticleGenerationStage::Plan) {
                    return $this->modelResult(json_encode($this->validPlan($evidence['id'])), $model);
                }

                throw new ArticleModelSelectionException($request->stage, [$failedAttempt], '模型服务异常');
            });

        try {
            app(DeepArticleGenerationService::class)->generate(
                $task,
                'Evidence-aware article',
                'evidence governance',
                'Use verified evidence.',
                $this->evidenceContext($evidence),
                'en',
                [$evidence]
            );
            $this->fail('The provider failure must carry the full Deep attempt history.');
        } catch (ArticleProviderFailureException $exception) {
            $this->assertSame(ArticleGenerationStage::Draft, $exception->stage);
            $this->assertCount(2, $exception->attempts);
            $this->assertSame(['success', 'failed'], array_column($exception->attempts, 'status'));
            $this->assertSame(['deep_plan', 'deep_draft'], array_column($exception->stages, 'name'));
            $this->assertSame('failed', $exception->stages[1]['status']);
        }
    }

    public function test_insufficient_evidence_stops_after_planning_without_drafting(): void
    {
        $task = new Task(['generation_mode' => 'deep']);
        $model = $this->model();
        $evidence = $this->evidence();
        $plan = $this->validPlan($evidence['id']);
        $plan['evidence_sufficiency'] = 'insufficient';
        $plan['answer_mode'] = 'stop';
        $plan['evidence_mapping'] = [];
        $plan['supported_sections'] = [];
        $plan['verification_items'] = [[
            'question' => 'Confirm the process conditions.',
            'category' => 'process',
            'required_for_draft' => true,
        ]];

        $this->mock(ArticleModelCallService::class)
            ->shouldReceive('generateStageWithModelSelection')
            ->once()
            ->andReturn($this->modelResult(json_encode($plan), $model));

        $this->expectException(ArticleInsufficientEvidenceException::class);
        $this->expectExceptionMessage('证据不足');
        $this->expectExceptionMessage('应用或工艺条件');

        app(DeepArticleGenerationService::class)->generate(
            $task,
            'How does this system fit the process?',
            'application fit',
            'Write 900-1200 words.',
            $this->evidenceContext($evidence),
            'en',
            [$evidence]
        );
    }

    public function test_limited_evidence_overrides_length_pressure_and_forces_manual_review(): void
    {
        $task = new Task(['generation_mode' => 'deep']);
        $model = $this->model();
        $evidence = $this->evidence();
        $plan = $this->validPlan($evidence['id']);
        $plan['evidence_sufficiency'] = 'limited';
        $plan['answer_mode'] = 'evidence_limited';
        $prompts = [];
        $responses = [
            $this->modelResult(json_encode($plan), $model),
            $this->modelResult($this->completeDraft('Concise supported answer', $evidence['id']), $model),
            $this->modelResult(json_encode($this->review(true, 92)), $model),
        ];
        $index = 0;
        $this->mock(ArticleModelCallService::class)
            ->shouldReceive('generateStageWithModelSelection')
            ->times(3)
            ->andReturnUsing(function (Task $receivedTask, ArticleModelCallRequest $request) use (&$index, &$prompts, $responses): array {
                $prompts[] = $request->prompt;

                return $responses[$index++];
            });

        $result = app(DeepArticleGenerationService::class)->generate(
            $task,
            'How does this system fit the process?',
            'application fit',
            'Write 900-1200 words and cover every standard section.',
            $this->evidenceContext($evidence),
            'en',
            [$evidence]
        );

        $this->assertSame('limited', $result['evidence_sufficiency']);
        $this->assertTrue($result['requires_manual_review']);
        $this->assertSame('pending_review', $result['grounding_gate']['outcome']);
        $this->assertStringNotContainsString('Write 900-1200 words', $prompts[0]);
        $this->assertStringContainsString('Do not force modules or expand after eligible evidence is exhausted', $prompts[1]);
        $this->assertStringContainsString('A concise but complete limited-evidence article is not incomplete', $prompts[2]);
    }

    public function test_ambiguous_deterministic_finding_keeps_deep_draft_pending_review(): void
    {
        $task = new Task(['generation_mode' => 'deep']);
        $model = $this->model();
        $evidence = $this->evidence();
        $draft = "The system may significantly improve throughput.\n<!-- evidence:{$evidence['id']} -->";
        $this->mock(ArticleModelCallService::class)
            ->shouldReceive('generateStageWithModelSelection')
            ->times(3)
            ->andReturn(
                $this->modelResult(json_encode($this->validPlan($evidence['id'])), $model),
                $this->modelResult($draft, $model),
                $this->modelResult(json_encode($this->review(true, 92)), $model),
            );

        $result = app(DeepArticleGenerationService::class)->generate(
            $task,
            'Evidence-aware article',
            'evidence governance',
            'Use verified evidence.',
            $this->evidenceContext($evidence),
            'en',
            [$evidence]
        );

        $this->assertSame('pending_review', $result['grounding_gate']['outcome']);
        $this->assertTrue($result['requires_manual_review']);
    }

    public function test_every_deep_stage_receives_the_same_authoritative_citable_id_allowlist(): void
    {
        $task = new Task(['generation_mode' => 'deep']);
        $model = $this->model();
        $knowledge = $this->evidence();
        $case = app(ArticleEvidencePackage::class)->make('case', 9, 'Customer case', 'Unverified customer result.');
        $context = $this->evidenceContext($knowledge);
        $prompts = [];
        $responses = [
            $this->modelResult(json_encode($this->validPlan($knowledge['id'])), $model),
            $this->modelResult($this->completeDraft('Initial', $knowledge['id']), $model),
            $this->modelResult(json_encode($this->review(false, 70, ['weak_evidence_link'])), $model),
            $this->modelResult($this->completeDraft('Revised', $knowledge['id']), $model),
            $this->modelResult(json_encode($this->review(true, 92)), $model),
        ];
        $index = 0;
        $this->mock(ArticleModelCallService::class)
            ->shouldReceive('generateStageWithModelSelection')
            ->times(5)
            ->andReturnUsing(function (Task $receivedTask, ArticleModelCallRequest $request) use (&$index, &$prompts, $responses): array {
                $prompts[] = $request->prompt;

                return $responses[$index++];
            });

        app(DeepArticleGenerationService::class)->generate(
            $task,
            'Evidence-aware article',
            'evidence governance',
            'Use verified evidence.',
            $context,
            'en',
            [$knowledge, $case]
        );

        $expectedAllowlist = json_encode([$knowledge['id']], JSON_UNESCAPED_SLASHES);
        foreach ($prompts as $prompt) {
            $this->assertStringContainsString('Authoritative citable Evidence ID allowlist: '.$expectedAllowlist, $prompt);
            $this->assertStringContainsString('IDs outside this allowlist are not citable', $prompt);
        }
    }

    public function test_passing_deep_pipeline_uses_plan_draft_review_and_one_frozen_evidence_package(): void
    {
        $task = new Task(['generation_mode' => 'deep', 'model_selection_mode' => 'fixed']);
        $model = $this->model();
        $evidence = $this->evidence();
        $context = $this->evidenceContext($evidence);
        $prompts = [];
        $responses = [
            $this->modelResult(json_encode($this->validPlan($evidence['id'])), $model),
            $this->modelResult($this->completeDraft('Initial', $evidence['id']), $model),
            $this->modelResult(json_encode($this->review(true, 91)), $model),
        ];
        $index = 0;
        $this->mock(ArticleModelCallService::class)
            ->shouldReceive('generateStageWithModelSelection')
            ->times(3)
            ->andReturnUsing(function (Task $receivedTask, ArticleModelCallRequest $request) use (&$index, &$prompts, $responses): array {
                $prompts[] = [
                    'prompt' => $request->prompt,
                    'validate_article' => $request->validateArticleCompleteness,
                ];

                return $responses[$index++];
            });

        $result = app(DeepArticleGenerationService::class)->generate(
            $task,
            'How to Select a Dispensing System',
            'dispensing system selection',
            'Use verified evidence and explain trade-offs.',
            $context,
            'en',
            [$evidence]
        );

        $this->assertStringContainsString('Initial', $result['content']);
        $this->assertFalse($result['requires_manual_review']);
        $this->assertSame(['deep_plan', 'deep_draft', 'deep_review'], array_column($result['stages'], 'name'));
        $this->assertSame(hash('sha256', $context), $result['evidence_sha256']);
        $this->assertTrue($prompts[0]['validate_article'] === false);
        $this->assertTrue($prompts[1]['validate_article'] === true);
        $this->assertTrue($prompts[2]['validate_article'] === false);
        $this->assertCount(3, array_filter($prompts, static fn (array $item): bool => str_contains($item['prompt'], 'FROZEN-EVIDENCE-PACKAGE')));
    }

    public function test_invalid_plan_gets_one_bounded_structured_repair_before_drafting(): void
    {
        $task = new Task(['generation_mode' => 'deep']);
        $model = $this->model();
        $evidence = $this->evidence();
        $invalidPlan = $this->validPlan($evidence['id']);
        unset($invalidPlan['answer_mode']);
        $prompts = [];
        $responses = [
            $this->modelResult(json_encode($invalidPlan), $model),
            $this->modelResult(json_encode($this->validPlan($evidence['id'])), $model),
            $this->modelResult($this->completeDraft('Initial', $evidence['id']), $model),
            $this->modelResult(json_encode($this->review(true, 92)), $model),
        ];
        $index = 0;
        $this->mock(ArticleModelCallService::class)
            ->shouldReceive('generateStageWithModelSelection')
            ->times(4)
            ->andReturnUsing(function (Task $receivedTask, ArticleModelCallRequest $request) use (&$index, &$prompts, $responses): array {
                $prompts[] = $request->prompt;

                return $responses[$index++];
            });

        $result = app(DeepArticleGenerationService::class)->generate(
            $task,
            'Evidence-aware article',
            'evidence governance',
            'Use verified evidence.',
            $this->evidenceContext($evidence),
            'en',
            [$evidence]
        );

        $this->assertSame(4, $result['call_count']);
        $this->assertSame(
            ['deep_plan', 'deep_plan_repair', 'deep_draft', 'deep_review'],
            array_column($result['stages'], 'name')
        );
        $this->assertSame('failed', $result['stages'][0]['status']);
        $this->assertStringContainsString('Protocol repair attempt 1 of 1', $prompts[1]);
        $this->assertStringContainsString('Authoritative citable Evidence ID allowlist', $prompts[1]);
    }

    public function test_repair_exhaustion_preserves_attempts_and_stops_without_a_third_plan_call(): void
    {
        $task = new Task(['generation_mode' => 'deep']);
        $model = $this->model();
        $evidence = $this->evidence();
        $invalidPlan = $this->validPlan($evidence['id']);
        unset($invalidPlan['answer_mode']);

        $this->mock(ArticleModelCallService::class)
            ->shouldReceive('generateStageWithModelSelection')
            ->twice()
            ->andReturn(
                $this->modelResult(json_encode($invalidPlan), $model),
                $this->modelResult(json_encode($invalidPlan), $model),
            );

        try {
            app(DeepArticleGenerationService::class)->generate(
                $task,
                'Evidence-aware article',
                'evidence governance',
                'Use verified evidence.',
                $this->evidenceContext($evidence),
                'en',
                [$evidence]
            );
            $this->fail('Repair exhaustion must be terminal.');
        } catch (ArticleGenerationProtocolException $exception) {
            $this->assertSame('plan_repair', $exception->stage->value);
            $this->assertSame('deep-v2.4-structured-plan-1', $exception->protocolVersion);
            $this->assertCount(2, $exception->attempts);
            $this->assertSame(['deep_plan', 'deep_plan_repair'], array_column($exception->stages, 'name'));
            $this->assertSame(['schema.invalid_enum'], array_values(array_unique(array_column($exception->violations, 'code'))));
        }
    }

    public function test_malformed_known_draft_marker_is_normalized_locally_without_an_extra_model_call(): void
    {
        $task = new Task(['generation_mode' => 'deep']);
        $model = $this->model();
        $evidence = $this->evidence();
        $invalidDraft = "Specific claim.\n<!-- evidence {$evidence['id']} -->";
        $responses = [
            $this->modelResult(json_encode($this->validPlan($evidence['id'])), $model),
            $this->modelResult($invalidDraft, $model),
            $this->modelResult(json_encode($this->review(true, 92)), $model),
        ];
        $index = 0;
        $this->mock(ArticleModelCallService::class)
            ->shouldReceive('generateStageWithModelSelection')
            ->times(3)
            ->andReturnUsing(function (Task $receivedTask, ArticleModelCallRequest $request) use (&$index, $responses): array {
                return $responses[$index++];
            });

        $result = app(DeepArticleGenerationService::class)->generate(
            $task,
            'Evidence-aware article',
            'evidence governance',
            'Use verified evidence.',
            $this->evidenceContext($evidence),
            'en',
            [$evidence]
        );

        $this->assertSame(3, $result['call_count']);
        $this->assertSame(
            ['deep_plan', 'deep_draft', 'deep_review'],
            array_column($result['stages'], 'name')
        );
        $this->assertSame(1, $result['stages'][1]['meta']['marker_normalization_count']);
        $this->assertSame('Specific claim.', $result['content']);
    }

    public function test_one_protocol_repair_can_coexist_with_the_existing_revision_cycle(): void
    {
        $task = new Task(['generation_mode' => 'deep']);
        $model = $this->model();
        $evidence = $this->evidence();
        $invalidPlan = $this->validPlan($evidence['id']);
        unset($invalidPlan['answer_mode']);
        $responses = [
            $this->modelResult(json_encode($invalidPlan), $model),
            $this->modelResult(json_encode($this->validPlan($evidence['id'])), $model),
            $this->modelResult($this->completeDraft('Initial', $evidence['id']), $model),
            $this->modelResult(json_encode($this->review(false, 70, ['weak_evidence_link'])), $model),
            $this->modelResult($this->completeDraft('Revised', $evidence['id']), $model),
            $this->modelResult(json_encode($this->review(true, 92)), $model),
        ];
        $this->mock(ArticleModelCallService::class)
            ->shouldReceive('generateStageWithModelSelection')
            ->times(6)
            ->andReturn(...$responses);

        $result = app(DeepArticleGenerationService::class)->generate(
            $task,
            'Evidence-aware article',
            'evidence governance',
            'Use verified evidence.',
            $this->evidenceContext($evidence),
            'en',
            [$evidence]
        );

        $this->assertSame(6, $result['call_count']);
        $this->assertSame(
            ['deep_plan', 'deep_plan_repair', 'deep_draft', 'deep_review', 'deep_revision', 'deep_final_review'],
            array_column($result['stages'], 'name')
        );
        $this->assertStringContainsString('Revised', $result['content']);
    }

    public function test_provider_attempt_budget_exhaustion_before_final_review_uses_provider_failure_contract(): void
    {
        $task = new Task(['generation_mode' => 'deep']);
        $model = $this->model();
        $evidence = $this->evidence();
        $invalidPlan = $this->validPlan($evidence['id']);
        unset($invalidPlan['answer_mode']);
        $planResult = $this->modelResult(json_encode($invalidPlan), $model);
        $failedAttempt = $planResult['attempts'][0];
        $failedAttempt['status'] = 'failed';
        $failedAttempt['finish_reason'] = null;
        $planResult['attempts'] = [$failedAttempt, $planResult['attempts'][0]];

        $this->mock(ArticleModelCallService::class)
            ->shouldReceive('generateStageWithModelSelection')
            ->times(5)
            ->andReturn(
                $planResult,
                $this->modelResult(json_encode($this->validPlan($evidence['id'])), $model),
                $this->modelResult($this->completeDraft('Initial', $evidence['id']), $model),
                $this->modelResult(json_encode($this->review(false, 70, ['weak_evidence_link'])), $model),
                $this->modelResult($this->completeDraft('Revised', $evidence['id']), $model)
            );

        try {
            app(DeepArticleGenerationService::class)->generate(
                $task,
                'Evidence-aware article',
                'evidence governance',
                'Use verified evidence.',
                $this->evidenceContext($evidence),
                'en',
                [$evidence]
            );
            $this->fail('The exhausted provider-attempt budget must not become a generic retry failure.');
        } catch (ArticleProviderFailureException $exception) {
            $this->assertSame(ArticleGenerationStage::FinalReview, $exception->stage);
            $this->assertCount(6, $exception->attempts);
            $this->assertSame('deep_final_review', data_get($exception->stages, '5.name'));
            $this->assertSame('failed', data_get($exception->stages, '5.status'));
            $this->assertSame('provider_attempt_budget_exhausted', data_get($exception->stages, '5.meta.reason'));
        }
    }

    public function test_failed_first_review_gets_one_revision_and_second_failure_forces_manual_review(): void
    {
        $task = new Task(['generation_mode' => 'deep']);
        $model = $this->model();
        $evidence = $this->evidence();
        $responses = [
            $this->modelResult(json_encode($this->validPlan($evidence['id'])), $model),
            $this->modelResult($this->completeDraft('Initial', $evidence['id']), $model),
            $this->modelResult(json_encode($this->review(false, 66, ['weak_evidence_link'])), $model),
            $this->modelResult($this->completeDraft('Revised', $evidence['id']), $model),
            $this->modelResult(json_encode($this->review(false, 74, ['insufficient_negative_fit'])), $model),
        ];
        $this->mock(ArticleModelCallService::class)
            ->shouldReceive('generateStageWithModelSelection')
            ->times(5)
            ->andReturn(...$responses);

        $result = app(DeepArticleGenerationService::class)->generate(
            $task,
            'How to Select a Dispensing System',
            'dispensing system selection',
            'Use verified evidence.',
            $this->evidenceContext($evidence),
            'en',
            [$evidence]
        );

        $this->assertStringContainsString('Revised', $result['content']);
        $this->assertTrue($result['requires_manual_review']);
        $this->assertSame(['insufficient_negative_fit'], $result['review']['issue_codes']);
        $this->assertSame(5, $result['call_count']);
        $this->assertSame(['deep_plan', 'deep_draft', 'deep_review', 'deep_revision', 'deep_final_review'], array_column($result['stages'], 'name'));
    }

    public function test_blocking_issue_after_revision_prevents_article_output(): void
    {
        $task = new Task(['generation_mode' => 'deep']);
        $model = $this->model();
        $evidence = $this->evidence();
        $this->mock(ArticleModelCallService::class)
            ->shouldReceive('generateStageWithModelSelection')
            ->times(5)
            ->andReturn(
                $this->modelResult(json_encode($this->validPlan($evidence['id'])), $model),
                $this->modelResult($this->completeDraft('Initial', $evidence['id']), $model),
                $this->modelResult(json_encode($this->review(false, 40, ['unsupported_claim'])), $model),
                $this->modelResult($this->completeDraft('Revised', $evidence['id']), $model),
                $this->modelResult(json_encode($this->review(false, 35, ['privacy_violation'])), $model),
            );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('阻断级');

        app(DeepArticleGenerationService::class)->generate(
            $task,
            'Customer Project Case',
            'project case',
            'Use verified evidence.',
            $this->evidenceContext($evidence),
            'en',
            [$evidence]
        );
    }

    public function test_deep_pipeline_validates_refs_strips_markers_and_returns_safe_claim_ledger(): void
    {
        $task = new Task(['generation_mode' => 'deep']);
        $model = $this->model();
        $evidence = app(ArticleEvidencePackage::class)->make(
            'knowledge_chunk',
            1,
            'SJ4060 manual',
            'The rated travel is 300 mm.',
            0
        );
        $plan = $this->validPlan($evidence['id']);
        $draft = "## Verified travel\n\nThe rated travel is 300 mm.\n<!-- evidence:{$evidence['id']} -->";
        $this->mock(ArticleModelCallService::class)
            ->shouldReceive('generateStageWithModelSelection')
            ->times(3)
            ->andReturn(
                $this->modelResult(json_encode($plan), $model),
                $this->modelResult($draft, $model),
                $this->modelResult(json_encode($this->review(true, 92)), $model),
            );

        $result = app(DeepArticleGenerationService::class)->generate(
            $task,
            'SJ4060 Travel Specification',
            'SJ4060 travel',
            'Use verified evidence.',
            "Evidence ID: {$evidence['id']}\n{$evidence['content']}",
            'en',
            [$evidence]
        );

        $this->assertStringNotContainsString('<!-- evidence:', $result['content']);
        $this->assertSame('complete', $result['claim_coverage_status']);
        $this->assertSame([$evidence['id']], $result['claim_ledger'][0]['evidence_refs']);
        $this->assertArrayNotHasKey('paragraph', $result['claim_ledger'][0]);
        $this->assertFalse($result['requires_manual_review']);
    }

    public function test_unmarked_specific_claim_forces_manual_review(): void
    {
        $task = new Task(['generation_mode' => 'deep']);
        $model = $this->model();
        $evidence = app(ArticleEvidencePackage::class)->make(
            'knowledge_chunk',
            1,
            'SJ4060 manual',
            'The rated travel is 300 mm.',
            0
        );
        $this->mock(ArticleModelCallService::class)
            ->shouldReceive('generateStageWithModelSelection')
            ->times(3)
            ->andReturn(
                $this->modelResult(json_encode($this->validPlan($evidence['id'])), $model),
                $this->modelResult('The rated travel is 300 mm.', $model),
                $this->modelResult(json_encode($this->review(true, 92)), $model),
            );

        $result = app(DeepArticleGenerationService::class)->generate(
            $task,
            'SJ4060 Travel Specification',
            'SJ4060 travel',
            'Use verified evidence.',
            "Evidence ID: {$evidence['id']}\n{$evidence['content']}",
            'en',
            [$evidence]
        );

        $this->assertSame('partial', $result['claim_coverage_status']);
        $this->assertTrue($result['requires_manual_review']);
    }

    public function test_revision_replaces_initial_claim_ledger_with_final_article_ledger(): void
    {
        $task = new Task(['generation_mode' => 'deep']);
        $model = $this->model();
        $firstEvidence = app(ArticleEvidencePackage::class)->make('knowledge_chunk', 1, 'First source', 'Travel is 300 mm.', 0);
        $finalEvidence = app(ArticleEvidencePackage::class)->make('knowledge_chunk', 2, 'Final source', 'Travel is 320 mm.', 0);
        $firstDraft = "Travel is 300 mm.\n<!-- evidence:{$firstEvidence['id']} -->";
        $finalDraft = "Travel is 320 mm.\n<!-- evidence:{$finalEvidence['id']} -->";
        $this->mock(ArticleModelCallService::class)
            ->shouldReceive('generateStageWithModelSelection')
            ->times(5)
            ->andReturn(
                $this->modelResult(json_encode($this->validPlan($firstEvidence['id'])), $model),
                $this->modelResult($firstDraft, $model),
                $this->modelResult(json_encode($this->review(false, 70, ['weak_evidence_link'])), $model),
                $this->modelResult($finalDraft, $model),
                $this->modelResult(json_encode($this->review(true, 92)), $model),
            );

        $result = app(DeepArticleGenerationService::class)->generate(
            $task,
            'Travel Specification',
            'travel',
            'Use verified evidence.',
            "Evidence ID: {$firstEvidence['id']}\n{$firstEvidence['content']}\n\nEvidence ID: {$finalEvidence['id']}\n{$finalEvidence['content']}",
            'en',
            [$firstEvidence, $finalEvidence]
        );

        $this->assertSame([$finalEvidence['id']], $result['claim_ledger'][0]['evidence_refs']);
        $this->assertStringNotContainsString($firstEvidence['id'], json_encode($result['claim_ledger'], JSON_THROW_ON_ERROR));
    }

    private function model(): AiModel
    {
        return AiModel::query()->create([
            'name' => 'Deep Pipeline Model',
            'model_id' => 'deep-pipeline-model',
            'model_type' => 'chat',
            'status' => 'active',
        ]);
    }

    /** @return array<string,mixed> */
    private function modelResult(string $content, AiModel $model): array
    {
        return [
            'content' => $content,
            'model' => $model,
            'attempts' => [[
                'model_id' => (int) $model->id,
                'model_name' => (string) $model->name,
                'status' => 'success',
                'reason' => null,
                'duration_ms' => 12,
                'finish_reason' => 'stop',
                'prompt_tokens' => 100,
                'completion_tokens' => 200,
                'reasoning_tokens' => 0,
            ]],
        ];
    }

    /** @return array<string,mixed> */
    private function validPlan(string $evidenceId = 'KB:1#0'): array
    {
        return [
            'reader_question' => 'Which system fits the process?',
            'answer_mode' => 'direct',
            'evidence_sufficiency' => 'sufficient',
            'supported_sections' => [[
                'purpose' => 'Explain the verified inputs that change the decision.',
                'support_type' => 'evidence',
                'evidence_refs' => [$evidenceId],
            ]],
            'evidence_mapping' => [[
                'claim_scope' => 'Selection inputs',
                'evidence_refs' => [$evidenceId],
            ]],
            'optional_modules' => [],
            'unsupported_claims_to_avoid' => ['Unverified performance figures'],
            'verification_items' => [[
                'question' => 'Confirm the measured process load.',
                'category' => 'process',
                'required_for_draft' => false,
            ]],
        ];
    }

    /** @return array<string,mixed> */
    private function review(bool $passed, int $score, array $issueCodes = []): array
    {
        return [
            'passed' => $passed,
            'score' => $score,
            'issue_codes' => $issueCodes,
            'issues' => array_map(static fn (string $code): array => [
                'code' => $code,
                'severity' => $code === 'privacy_violation' ? 'critical' : 'medium',
                'message' => 'Review issue: '.$code,
            ], $issueCodes),
            'revision_instructions' => array_map(static fn (string $code): array => [
                'target' => 'Affected passage',
                'instruction' => 'Resolve '.$code.' without adding facts.',
            ], $issueCodes),
            'metrics' => [
                'factual_support' => $passed ? 5 : 3,
                'clarity' => 4,
                'buyer_decision_value' => 4,
                'structure_naturalness' => 4,
                'uncertainty_and_negative_fit' => 4,
                'privacy_and_safety' => $passed ? 5 : 3,
                'style_fitness' => 4,
                'non_template_naturalness' => 4,
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function evidence(): array
    {
        return app(ArticleEvidencePackage::class)->make(
            'knowledge_chunk',
            1,
            'Frozen evidence',
            'FROZEN-EVIDENCE-PACKAGE',
            0
        );
    }

    /** @param array<string,mixed> ...$items */
    private function evidenceContext(array ...$items): string
    {
        return app(ArticleEvidencePackage::class)->generationContext($items);
    }

    private function completeDraft(string $label, ?string $evidenceId = null): string
    {
        $draft = "## {$label} decision context\n\n".str_repeat('Verified evidence supports this complete decision-focused explanation. ', 12).'The buyer should confirm process constraints before selecting a configuration.';

        return $evidenceId ? $draft."\n<!-- evidence:{$evidenceId} -->" : $draft;
    }
}
