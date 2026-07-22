<?php

namespace Tests\Feature;

use App\Ai\Agents\MarkdownContentWriterAgent;
use App\Models\AiModel;
use App\Models\Task;
use App\Services\GeoFlow\ArticleModelCallRequest;
use App\Services\GeoFlow\ArticleModelCallService;
use App\Services\GeoFlow\ArticleModelSelectionException;
use App\Support\GeoFlow\ApiKeyCrypto;
use App\Support\GeoFlow\ArticleGenerationStage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Enums\Lab;
use RuntimeException;
use Tests\TestCase;

class WorkerExecutionServiceMaxTokensTest extends TestCase
{
    use RefreshDatabase;

    public function test_writer_agent_uses_provider_specific_max_token_option_names(): void
    {
        $agent = new MarkdownContentWriterAgent(maxTokens: 8192);

        $this->assertSame(['max_tokens' => 8192], $agent->providerOptions('deepseek'));
        $this->assertSame(['max_tokens' => 8192], $agent->providerOptions(Lab::OpenRouter));
        $this->assertSame(['max_output_tokens' => 8192], $agent->providerOptions('openai'));
        $this->assertSame(['max_output_tokens' => 8192], $agent->providerOptions(Lab::OpenAI));
        $this->assertSame(['maxOutputTokens' => 8192], $agent->providerOptions('gemini'));
        $this->assertSame(['maxOutputTokens' => 8192], $agent->providerOptions(Lab::Gemini));
    }

    public function test_worker_content_generation_sends_model_max_tokens(): void
    {
        Http::fake([
            'https://ai.test/v1/chat/completions' => Http::response([
                'model' => 'deepseek-chat',
                'choices' => [
                    [
                        'message' => ['content' => '# 标题'.PHP_EOL.PHP_EOL.'这是一篇完整正文。'],
                        'finish_reason' => 'stop',
                    ],
                ],
            ]),
        ]);

        $model = $this->createChatModel(['max_tokens' => 12000]);

        $content = $this->generateContent($model, '请生成一篇文章。');

        $this->assertStringContainsString('完整正文', $content);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://ai.test/v1/chat/completions'
            && ($request['model'] ?? '') === 'deepseek-chat'
            && ($request['max_tokens'] ?? null) === 12000);
    }

    public function test_worker_content_generation_falls_back_to_system_max_tokens(): void
    {
        config(['geoflow.content_max_tokens' => 9000]);

        Http::fake([
            'https://ai.test/v1/chat/completions' => Http::response([
                'model' => 'deepseek-chat',
                'choices' => [
                    [
                        'message' => ['content' => '# 标题'.PHP_EOL.PHP_EOL.'这是一篇完整正文。'],
                        'finish_reason' => 'stop',
                    ],
                ],
            ]),
        ]);

        $model = $this->createChatModel(['max_tokens' => null]);

        $this->generateContent($model, '请生成一篇文章。');

        Http::assertSent(fn ($request): bool => $request->url() === 'https://ai.test/v1/chat/completions'
            && ($request['max_tokens'] ?? null) === 9000);
    }

    public function test_plan_stage_uses_structured_output_and_stage_token_limit(): void
    {
        $plan = [
            'reader_question' => 'What can the evidence support?',
            'answer_mode' => 'direct',
            'evidence_sufficiency' => 'sufficient',
            'supported_sections' => [],
            'evidence_mapping' => [],
            'optional_modules' => [],
            'unsupported_claims_to_avoid' => [],
            'verification_items' => [],
        ];
        Http::fake([
            'https://ai.test/v1/chat/completions' => Http::response([
                'model' => 'deepseek-chat',
                'choices' => [[
                    'message' => ['content' => json_encode($plan, JSON_THROW_ON_ERROR)],
                    'finish_reason' => 'stop',
                ]],
            ]),
        ]);
        $model = $this->createChatModel(['max_tokens' => 12000]);
        $task = Task::query()->create([
            'name' => 'Structured plan stage',
            'ai_model_id' => $model->id,
            'model_selection_mode' => 'fixed',
        ]);

        $result = app(ArticleModelCallService::class)->generateStageWithModelSelection(
            $task,
            new ArticleModelCallRequest(
                ArticleGenerationStage::Plan,
                'Return the structured plan.',
                false,
                2048
            )
        );

        $this->assertSame($plan, $result['structured']);
        Http::assertSent(fn ($request): bool => data_get($request->data(), 'response_format.type') === 'json_object'
            && ($request['max_tokens'] ?? null) === 2048
            && str_contains((string) data_get($request->data(), 'messages.0.content'), 'structured planning stage'));
    }

    public function test_missing_model_is_reported_as_a_typed_provider_selection_failure(): void
    {
        $task = Task::query()->create([
            'name' => 'Missing model task',
            'model_selection_mode' => 'fixed',
        ]);

        try {
            app(ArticleModelCallService::class)->generateStageWithModelSelection(
                $task,
                new ArticleModelCallRequest(
                    ArticleGenerationStage::Plan,
                    'Return a plan.',
                    false,
                    2048
                )
            );
            $this->fail('A missing model must use the provider failure contract.');
        } catch (ArticleModelSelectionException $exception) {
            $this->assertSame(ArticleGenerationStage::Plan, $exception->stage);
            $this->assertSame([], $exception->attempts);
            $this->assertStringNotContainsString('api', strtolower($exception->getMessage()));
        }
    }

    public function test_worker_rejects_length_limited_output_but_counts_the_completed_provider_call(): void
    {
        Http::fake([
            'https://ai.test/v1/chat/completions' => Http::response([
                'model' => 'deepseek-chat',
                'choices' => [[
                    'message' => ['content' => str_repeat('This section is incomplete. ', 30)],
                    'finish_reason' => 'length',
                ]],
            ]),
        ]);
        $model = $this->createChatModel(['max_tokens' => 1200]);

        try {
            $this->generateContent($model, 'Write an article.');
            $this->fail('Length-limited output must not be accepted.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('正文生成未完整结束', $exception->getMessage());
        }

        $this->assertSame(1, (int) $model->fresh()->used_today);
        $this->assertSame(1, (int) $model->fresh()->total_used);
    }

    public function test_worker_rejects_output_ending_in_an_unfinished_markdown_item(): void
    {
        Http::fake([
            'https://ai.test/v1/chat/completions' => Http::response([
                'model' => 'deepseek-chat',
                'choices' => [[
                    'message' => ['content' => str_repeat('Complete supporting paragraph. ', 25).PHP_EOL.PHP_EOL.'- Reference installations'],
                    'finish_reason' => 'stop',
                ]],
            ]),
        ]);
        $model = $this->createChatModel();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('正文生成未完整结束');

        $this->generateContent($model, 'Write an article.');
    }

    public function test_worker_accepts_complete_output_and_counts_usage(): void
    {
        Http::fake([
            'https://ai.test/v1/chat/completions' => Http::response([
                'model' => 'deepseek-chat',
                'choices' => [[
                    'message' => ['content' => str_repeat('Complete supporting paragraph. ', 25).'The article ends with a complete recommendation.'],
                    'finish_reason' => 'stop',
                ]],
            ]),
        ]);
        $model = $this->createChatModel();

        $content = $this->generateContent($model, 'Write an article.');

        $this->assertStringEndsWith('complete recommendation.', $content);
        $this->assertSame(1, (int) $model->fresh()->used_today);
        $this->assertSame(1, (int) $model->fresh()->total_used);
    }

    public function test_worker_accepts_a_complete_final_list_item_with_sentence_punctuation(): void
    {
        Http::fake([
            'https://ai.test/v1/chat/completions' => Http::response([
                'model' => 'deepseek-chat',
                'choices' => [[
                    'message' => ['content' => str_repeat('Complete supporting paragraph. ', 25).PHP_EOL.PHP_EOL.'- Verify the final requirement with the supplier.'],
                    'finish_reason' => 'stop',
                ]],
            ]),
        ]);
        $model = $this->createChatModel();

        $content = $this->generateContent($model, 'Write an article.');

        $this->assertStringEndsWith('supplier.', $content);
        $this->assertSame(1, (int) $model->fresh()->used_today);
    }

    public function test_smart_failover_rejects_truncated_primary_and_keeps_complete_fallback(): void
    {
        Http::fake([
            'https://ai.test/v1/chat/completions' => Http::sequence()
                ->push([
                    'model' => 'primary-chat',
                    'choices' => [[
                        'message' => ['content' => str_repeat('Primary output is incomplete. ', 30)],
                        'finish_reason' => 'length',
                    ]],
                ])
                ->push([
                    'model' => 'fallback-chat',
                    'choices' => [[
                        'message' => ['content' => str_repeat('Complete fallback paragraph. ', 25).'The fallback article is complete.'],
                        'finish_reason' => 'stop',
                    ]],
                ]),
        ]);
        $primary = $this->createChatModel([
            'name' => 'Primary',
            'model_id' => 'primary-chat',
            'failover_priority' => 1,
        ]);
        $fallback = $this->createChatModel([
            'name' => 'Fallback',
            'model_id' => 'fallback-chat',
            'failover_priority' => 2,
        ]);
        $task = Task::query()->create([
            'name' => 'Completeness failover test',
            'ai_model_id' => $primary->id,
            'model_selection_mode' => 'smart_failover',
        ]);

        $result = $this->generateWithModelSelection($task, 'Write an article.');

        $this->assertSame($fallback->id, $result['model']->id);
        $this->assertStringEndsWith('complete.', $result['content']);
        $this->assertSame(['failed', 'success'], array_column($result['attempts'], 'status'));
        $this->assertSame('length', $result['attempts'][0]['finish_reason']);
        $this->assertSame('stop', $result['attempts'][1]['finish_reason']);
        $this->assertArrayHasKey('duration_ms', $result['attempts'][1]);
        $this->assertArrayHasKey('prompt_tokens', $result['attempts'][1]);
        $this->assertArrayHasKey('completion_tokens', $result['attempts'][1]);
        $this->assertSame(1, (int) $primary->fresh()->used_today);
        $this->assertSame(1, (int) $fallback->fresh()->used_today);
    }

    public function test_smart_failover_respects_a_provider_attempt_budget(): void
    {
        Http::fake([
            'https://ai.test/v1/chat/completions' => Http::sequence()
                ->push(['choices' => [[
                    'message' => ['content' => str_repeat('Incomplete primary output. ', 30)],
                    'finish_reason' => 'length',
                ]]])
                ->push(['choices' => [[
                    'message' => ['content' => str_repeat('Incomplete fallback output. ', 30)],
                    'finish_reason' => 'length',
                ]]])
                ->push(['choices' => [[
                    'message' => ['content' => str_repeat('Unexpected third output. ', 30).'Complete.'],
                    'finish_reason' => 'stop',
                ]]]),
        ]);
        $primary = $this->createChatModel(['name' => 'Budget Primary', 'failover_priority' => 1]);
        $second = $this->createChatModel(['name' => 'Budget Second', 'failover_priority' => 2]);
        $third = $this->createChatModel(['name' => 'Budget Third', 'failover_priority' => 3]);
        $task = Task::query()->create([
            'name' => 'Provider budget test',
            'ai_model_id' => $primary->id,
            'model_selection_mode' => 'smart_failover',
        ]);

        try {
            app(ArticleModelCallService::class)->generateWithModelSelection($task, 'Write an article.', true, 2);
            $this->fail('The third provider request must not run after the budget is exhausted.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('智能模型切换', $exception->getMessage());
        }

        Http::assertSentCount(2);
        $this->assertSame(1, (int) $primary->fresh()->used_today);
        $this->assertSame(1, (int) $second->fresh()->used_today);
        $this->assertSame(0, (int) $third->fresh()->used_today);
    }

    public function test_fixed_model_failure_preserves_the_failed_provider_attempt_and_usage(): void
    {
        Http::fake([
            'https://ai.test/v1/chat/completions' => Http::response([
                'model' => 'deepseek-chat',
                'choices' => [[
                    'message' => ['content' => str_repeat('Incomplete fixed output. ', 30)],
                    'finish_reason' => 'length',
                ]],
                'usage' => [
                    'prompt_tokens' => 41,
                    'completion_tokens' => 73,
                ],
            ]),
        ]);
        $model = $this->createChatModel();
        $task = Task::query()->create([
            'name' => 'Fixed provider audit test',
            'ai_model_id' => $model->id,
            'model_selection_mode' => 'fixed',
        ]);

        try {
            app(ArticleModelCallService::class)->generateWithModelSelection($task, 'Write an article.');
            $this->fail('The fixed provider failure must retain its safe attempt metadata.');
        } catch (ArticleModelSelectionException $exception) {
            $this->assertSame(ArticleGenerationStage::Draft, $exception->stage);
            $this->assertCount(1, $exception->attempts);
            $this->assertSame('failed', $exception->attempts[0]['status']);
            $this->assertSame('length', $exception->attempts[0]['finish_reason']);
            $this->assertSame(41, $exception->attempts[0]['prompt_tokens']);
            $this->assertSame(73, $exception->attempts[0]['completion_tokens']);
            $this->assertNull($exception->getPrevious());
        }
    }

    public function test_provider_exception_does_not_echo_prompt_api_key_or_private_evidence(): void
    {
        $privatePrompt = 'PROMPT-CANARY with PRIVATE-EVIDENCE-CANARY';
        Http::fake([
            'https://ai.test/v1/chat/completions' => static function () use ($privatePrompt) {
                throw new RuntimeException('Authorization: Bearer test-api-key request_body='.$privatePrompt);
            },
        ]);
        $model = $this->createChatModel();

        try {
            $this->generateContent($model, $privatePrompt);
            $this->fail('Provider error should have stopped generation.');
        } catch (RuntimeException $exception) {
            $this->assertStringNotContainsString('test-api-key', $exception->getMessage());
            $this->assertStringNotContainsString('PROMPT-CANARY', $exception->getMessage());
            $this->assertStringNotContainsString('PRIVATE-EVIDENCE-CANARY', $exception->getMessage());
            $this->assertStringNotContainsString('test-api-key', (string) $exception);
            $this->assertStringNotContainsString('PROMPT-CANARY', (string) $exception);
            $this->assertStringNotContainsString('PRIVATE-EVIDENCE-CANARY', (string) $exception);
            $this->assertStringNotContainsString('PROMPT-CANARY', json_encode($exception->getTrace(), JSON_THROW_ON_ERROR));
            $this->assertNull($exception->getPrevious());
            $this->assertMatchesRegularExpression('/[a-f0-9]{12}/', $exception->getMessage());
        }
    }

    private function createChatModel(array $overrides = []): AiModel
    {
        return AiModel::query()->create(array_merge([
            'name' => 'Article Writer',
            'version' => 'test',
            'api_key' => app(ApiKeyCrypto::class)->encrypt('test-api-key'),
            'model_id' => 'deepseek-chat',
            'model_type' => 'chat',
            'api_url' => 'https://ai.test',
            'failover_priority' => 100,
            'daily_limit' => 0,
            'used_today' => 0,
            'total_used' => 0,
            'status' => 'active',
        ], $overrides));
    }

    private function generateContent(AiModel $model, string $prompt): string
    {
        return app(ArticleModelCallService::class)->generate($model, $prompt);
    }

    /** @return array{content:string,model:AiModel,attempts:array<int,array<string,mixed>>} */
    private function generateWithModelSelection(Task $task, string $prompt): array
    {
        return app(ArticleModelCallService::class)->generateWithModelSelection($task, $prompt);
    }
}
