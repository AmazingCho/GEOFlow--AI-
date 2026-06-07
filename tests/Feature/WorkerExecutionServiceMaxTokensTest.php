<?php

namespace Tests\Feature;

use App\Ai\Agents\MarkdownContentWriterAgent;
use App\Models\AiModel;
use App\Services\GeoFlow\WorkerExecutionService;
use App\Support\GeoFlow\ApiKeyCrypto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Enums\Lab;
use ReflectionMethod;
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
        $service = app(WorkerExecutionService::class);
        $method = new ReflectionMethod($service, 'generateContent');
        $method->setAccessible(true);

        return (string) $method->invoke($service, $model, $prompt);
    }
}
