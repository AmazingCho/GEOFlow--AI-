<?php

namespace App\Services\GeoFlow;

use App\Ai\Agents\ArticlePlanAgent;
use App\Ai\Agents\ArticleReviewAgent;
use App\Ai\Agents\MarkdownContentWriterAgent;
use App\Models\AiModel;
use App\Models\Task;
use App\Support\GeoFlow\ApiKeyCrypto;
use App\Support\GeoFlow\ArticleGenerationStage;
use App\Support\GeoFlow\OpenAiRuntimeProvider;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Responses\Data\FinishReason;
use RuntimeException;
use Throwable;

class ArticleModelCallService
{
    public function __construct(
        private readonly ApiKeyCrypto $apiKeyCrypto
    ) {}

    /**
     * Fixed mode only tries the primary model. Smart failover tries active chat models in priority order.
     *
     * @return array{content:string,model:AiModel,attempts:list<array{model_id:int,model_name:string,status:string,reason:?string}>}
     */
    public function generateWithModelSelection(
        Task $task,
        string $prompt,
        bool $validateArticleCompleteness = true,
        ?int $maxProviderAttempts = null
    ): array {
        return $this->generateStageWithModelSelection(
            $task,
            new ArticleModelCallRequest(
                ArticleGenerationStage::Draft,
                $prompt,
                $validateArticleCompleteness
            ),
            $maxProviderAttempts
        );
    }

    /**
     * @return array{content:string,structured:array<string,mixed>|null,model:AiModel,attempts:list<array{model_id:int,model_name:string,status:string,reason:?string}>}
     */
    public function generateStageWithModelSelection(
        Task $task,
        ArticleModelCallRequest $request,
        ?int $maxProviderAttempts = null
    ): array {
        $mode = (string) ($task->model_selection_mode ?? 'fixed');
        $attempts = [];
        $lastMessage = '';

        try {
            $candidates = $this->resolveAiModelCandidates($task);
        } catch (Throwable) {
            throw new ArticleModelSelectionException(
                $request->stage,
                [],
                'AI模型不可用或未配置'
            );
        }

        $providerAttemptCount = 0;
        foreach ($candidates as $candidate) {
            $unavailableReason = $this->getAiModelUnavailableReason($candidate);
            if ($unavailableReason !== null) {
                $attempts[] = $this->buildModelAttempt($candidate, 'skipped', $unavailableReason);
                $lastMessage = $unavailableReason;
                if ($mode !== 'smart_failover') {
                    throw new ArticleModelSelectionException(
                        $request->stage,
                        $this->stageAttempts($attempts, $request->stage),
                        $unavailableReason
                    );
                }

                continue;
            }

            if ($maxProviderAttempts !== null && $providerAttemptCount >= max(1, $maxProviderAttempts)) {
                break;
            }
            $providerAttemptCount++;

            $startedAt = hrtime(true);
            try {
                $result = $this->invokeModel($candidate, $request);
                $attempts[] = $this->buildModelAttempt($candidate, 'success', null, $result['meta']);

                return [
                    'content' => $result['content'],
                    'structured' => $result['structured'],
                    'model' => $candidate,
                    'attempts' => $this->stageAttempts($attempts, $request->stage),
                ];
            } catch (Throwable $exception) {
                $lastMessage = trim($exception->getMessage());
                $metadata = $exception instanceof ArticleModelCallException
                    ? $exception->callMetadata
                    : ['duration_ms' => $this->elapsedMilliseconds($startedAt)];
                $attempts[] = $this->buildModelAttempt($candidate, 'failed', $lastMessage, $metadata);

                if ($mode !== 'smart_failover') {
                    throw new ArticleModelSelectionException(
                        $request->stage,
                        $this->stageAttempts($attempts, $request->stage),
                        $lastMessage !== '' ? $lastMessage : '模型服务异常'
                    );
                }
            }
        }

        if ($mode === 'smart_failover' && $attempts !== []) {
            throw new ArticleModelSelectionException(
                $request->stage,
                $this->stageAttempts($attempts, $request->stage),
                $this->buildFailoverErrorMessage($attempts, $lastMessage)
            );
        }

        throw new ArticleModelSelectionException(
            $request->stage,
            $this->stageAttempts($attempts, $request->stage),
            'AI模型不可用或已达每日限制'
        );
    }

    public function generate(AiModel $aiModel, string $prompt, bool $validateArticleCompleteness = true): string
    {
        return $this->invokeModel(
            $aiModel,
            new ArticleModelCallRequest(
                ArticleGenerationStage::Draft,
                $prompt,
                $validateArticleCompleteness
            )
        )['content'];
    }

    /**
     * @return array{content:string,structured:array<string,mixed>|null,meta:array<string,mixed>}
     */
    private function invokeModel(AiModel $aiModel, ArticleModelCallRequest $request): array
    {
        $startedAt = hrtime(true);
        $providerUrl = OpenAiRuntimeProvider::resolveChatBaseUrl((string) ($aiModel->api_url ?? ''));
        if ($providerUrl === '') {
            throw new ArticleModelCallException('AI 模型 API 地址为空', ['duration_ms' => $this->elapsedMilliseconds($startedAt)]);
        }

        $apiKey = $this->apiKeyCrypto->decrypt((string) ($aiModel->getRawOriginal('api_key') ?? ''));
        if ($apiKey === '') {
            throw new ArticleModelCallException('AI 模型密钥为空', ['duration_ms' => $this->elapsedMilliseconds($startedAt)]);
        }

        $driver = OpenAiRuntimeProvider::resolveChatDriver($providerUrl, (string) ($aiModel->model_id ?? ''));
        $providerName = OpenAiRuntimeProvider::registerProvider('worker', $driver, $providerUrl, $apiKey);
        $maxTokens = $request->maxTokens ?? $this->resolveMaxTokens($aiModel);
        $agent = match ($request->stage) {
            ArticleGenerationStage::Plan, ArticleGenerationStage::PlanRepair => new ArticlePlanAgent(maxTokens: $maxTokens),
            ArticleGenerationStage::Review, ArticleGenerationStage::FinalReview => new ArticleReviewAgent(maxTokens: $maxTokens),
            default => new MarkdownContentWriterAgent(maxTokens: $maxTokens),
        };

        try {
            $response = $agent->prompt($request->prompt, [], $providerName, (string) ($aiModel->model_id ?? ''));
        } catch (Throwable $exception) {
            ini_set('zend.exception_ignore_args', '1');
            throw new ArticleModelCallException(
                $this->safeProviderExceptionMessage($exception, $providerUrl),
                ['duration_ms' => $this->elapsedMilliseconds($startedAt)]
            );
        }

        $metadata = $this->responseMetadata($response, $startedAt);
        $structured = is_array($response->structured ?? null) ? $response->structured : null;
        $rawContent = $structured !== null
            ? (string) json_encode($structured, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
            : (string) ($response->text ?? '');
        $content = $structured !== null
            ? $rawContent
            : OpenAiRuntimeProvider::normalizeGeneratedText($rawContent);
        if ($content === '') {
            if (OpenAiRuntimeProvider::looksLikeSseCompletionPayload($rawContent)) {
                throw new ArticleModelCallException('AI 返回空流式响应，未生成正文内容，请重试或检查模型流式输出兼容性', $metadata);
            }

            throw new ArticleModelCallException('AI返回空正文', $metadata);
        }

        AiModel::query()->whereKey((int) $aiModel->id)->update([
            'used_today' => DB::raw('COALESCE(used_today,0)+1'),
            'total_used' => DB::raw('COALESCE(total_used,0)+1'),
            'updated_at' => now(),
        ]);

        $this->assertResponseIsComplete(
            $content,
            $aiModel,
            $response,
            $request->validateArticleCompleteness,
            $metadata
        );

        return ['content' => $content, 'structured' => $structured, 'meta' => $metadata];
    }

    private function safeProviderExceptionMessage(Throwable $exception, string $providerUrl): string
    {
        $normalized = OpenAiRuntimeProvider::normalizeApiException($exception, $providerUrl);
        $fingerprint = substr(hash('sha256', $exception::class.'|'.$normalized), 0, 12);
        if (str_starts_with($normalized, 'AI 接口返回了非 JSON 响应')) {
            return $normalized.' 错误标识：'.$fingerprint;
        }

        return 'AI 生成失败：上游模型服务返回异常（错误标识：'.$fingerprint.'）';
    }

    public function resolveAiModel(Task $task): AiModel
    {
        $aiModelId = (int) ($task->ai_model_id ?? 0);
        if ($aiModelId <= 0) {
            throw new RuntimeException('任务未配置 AI 模型');
        }

        $aiModel = AiModel::query()
            ->whereKey($aiModelId)
            ->where('status', 'active')
            ->where(function ($query): void {
                $query->whereNull('model_type')
                    ->orWhere('model_type', '')
                    ->orWhere('model_type', 'chat');
            })
            ->first();

        if (! $aiModel) {
            throw new RuntimeException('任务 AI 模型不可用');
        }

        return $aiModel;
    }

    /**
     * @return list<AiModel>
     */
    private function resolveAiModelCandidates(Task $task): array
    {
        $primaryModel = $this->resolveAiModel($task);
        if (($task->model_selection_mode ?? 'fixed') !== 'smart_failover') {
            return [$primaryModel];
        }

        $fallbackModels = AiModel::query()
            ->whereKeyNot((int) $primaryModel->id)
            ->where(function ($query): void {
                $query->whereNull('model_type')
                    ->orWhere('model_type', '')
                    ->orWhere('model_type', 'chat');
            })
            ->orderBy('failover_priority')
            ->orderBy('id')
            ->get()
            ->all();

        return array_values(array_merge([$primaryModel], $fallbackModels));
    }

    private function getAiModelUnavailableReason(AiModel $aiModel): ?string
    {
        if (($aiModel->status ?? 'inactive') !== 'active') {
            return 'AI模型不可用或已达每日限制';
        }

        $dailyLimit = (int) ($aiModel->daily_limit ?? 0);
        $usedToday = (int) ($aiModel->used_today ?? 0);
        if ($dailyLimit > 0 && $usedToday >= $dailyLimit) {
            return 'AI模型不可用或已达每日限制';
        }

        return null;
    }

    /** @param array<string,mixed> $metadata */
    private function buildModelAttempt(AiModel $aiModel, string $status, ?string $reason, array $metadata = []): array
    {
        return array_merge([
            'model_id' => (int) $aiModel->id,
            'model_name' => (string) $aiModel->name,
            'status' => $status,
            'reason' => $reason,
            'duration_ms' => null,
            'finish_reason' => null,
            'prompt_tokens' => null,
            'completion_tokens' => null,
            'reasoning_tokens' => null,
        ], array_intersect_key($metadata, array_flip([
            'duration_ms',
            'finish_reason',
            'prompt_tokens',
            'completion_tokens',
            'reasoning_tokens',
        ])));
    }

    /**
     * @param  list<array<string,mixed>>  $attempts
     * @return list<array<string,mixed>>
     */
    private function stageAttempts(array $attempts, ArticleGenerationStage $stage): array
    {
        return array_map(
            static fn (array $attempt): array => array_merge($attempt, ['stage' => $stage->value]),
            $attempts
        );
    }

    /** @param list<array{model_id:int,model_name:string,status:string,reason:?string}> $attempts */
    private function buildFailoverErrorMessage(array $attempts, string $lastMessage): string
    {
        $summaries = [];
        foreach ($attempts as $attempt) {
            $reason = trim((string) ($attempt['reason'] ?? ''));
            $summaries[] = (string) $attempt['model_name'].($reason !== '' ? '（'.$reason.'）' : '');
        }

        return '智能模型切换已尝试：'.implode('；', $summaries).'。最终失败：'.$lastMessage;
    }

    private function resolveMaxTokens(AiModel $aiModel): int
    {
        $configured = (int) ($aiModel->max_tokens ?? 0);
        if ($configured > 0) {
            return $configured;
        }

        return max(256, (int) config('geoflow.content_max_tokens', 8192));
    }

    /** @param array<string,mixed> $metadata */
    private function assertResponseIsComplete(
        string $content,
        AiModel $aiModel,
        mixed $response,
        bool $validateArticleCompleteness,
        array $metadata
    ): void {
        $finishReason = $this->responseFinishReason($response);
        $signals = [];

        if ($finishReason === FinishReason::Length) {
            $signals[] = 'finish_reason_length';
        }

        $trimmed = rtrim($content);
        $completenessText = rtrim((string) preg_replace('/\s*<!--\s*evidence\s*:.*?-->\s*/isu', "\n", $trimmed));
        if ($validateArticleCompleteness && $completenessText !== '' && substr_count($completenessText, '```') % 2 === 1) {
            $signals[] = 'unclosed_code_fence';
        }

        if (
            $validateArticleCompleteness
            && mb_strlen($completenessText, 'UTF-8') > 500
            && preg_match('/[。！？.!?）\]\)"\'`》]$/u', $completenessText) !== 1
        ) {
            $signals[] = 'unfinished_sentence';
        }

        $lines = preg_split('/\R/u', $completenessText) ?: [];
        $lastLine = trim((string) collect($lines)->filter(static fn (string $line): bool => trim($line) !== '')->last());
        if (
            $validateArticleCompleteness
            && $lastLine !== ''
            && preg_match('/^(?:#{1,6}\s+.+|[-*+]\s*|\d+[.)]\s*)$/u', $lastLine) === 1
        ) {
            $signals[] = 'unfinished_markdown_block';
        }

        if ($signals === []) {
            return;
        }

        Log::warning('GEOFlow article generation may be truncated.', [
            'ai_model_id' => (int) $aiModel->id,
            'model_id' => (string) ($aiModel->model_id ?? ''),
            'max_tokens' => $this->resolveMaxTokens($aiModel),
            'finish_reason' => $finishReason instanceof FinishReason ? $finishReason->value : null,
            'content_length' => mb_strlen($trimmed, 'UTF-8'),
            'signals' => $signals,
        ]);

        throw new ArticleModelCallException(
            'AI正文生成未完整结束（'.implode(', ', array_values(array_unique($signals))).'），未保存该草稿。请提高模型最大输出 token、缩短文章要求或使用智能模型切换后重试。',
            $metadata
        );
    }

    /** @return array<string,int|string|null> */
    private function responseMetadata(mixed $response, int $startedAt): array
    {
        $usage = $response->usage ?? null;
        $finishReason = $this->responseFinishReason($response);

        return [
            'duration_ms' => $this->elapsedMilliseconds($startedAt),
            'finish_reason' => $finishReason?->value,
            'prompt_tokens' => isset($usage->promptTokens) ? (int) $usage->promptTokens : null,
            'completion_tokens' => isset($usage->completionTokens) ? (int) $usage->completionTokens : null,
            'reasoning_tokens' => isset($usage->reasoningTokens) ? (int) $usage->reasoningTokens : null,
        ];
    }

    private function elapsedMilliseconds(int $startedAt): int
    {
        return max(0, (int) round((hrtime(true) - $startedAt) / 1_000_000));
    }

    private function responseFinishReason(mixed $response): ?FinishReason
    {
        $steps = $response->steps ?? null;
        $lastStep = null;

        if ($steps instanceof Collection) {
            $lastStep = $steps->last();
        } elseif (is_array($steps) && $steps !== []) {
            $lastStep = end($steps);
        }

        $finishReason = $lastStep->finishReason ?? null;
        if ($finishReason instanceof FinishReason) {
            return $finishReason;
        }

        return is_string($finishReason) ? FinishReason::tryFrom($finishReason) : null;
    }
}
