<?php

namespace App\Services\GeoFlow;

use App\Models\AiModel;
use App\Support\GeoFlow\ApiKeyCrypto;
use App\Support\GeoFlow\OpenAiRuntimeProvider;
use Illuminate\Support\Str;
use Throwable;

class MaterialFormAnalysisService
{
    public function __construct(private readonly ApiKeyCrypto $apiKeyCrypto) {}

    /**
     * @return array<string, mixed>
     */
    public function analyzeEntity(string $text, int $modelId = 0): array
    {
        return $this->analyze($text, $modelId, 'entity');
    }

    /**
     * @return array<string, mixed>
     */
    public function analyzeCase(string $text, int $modelId = 0): array
    {
        return $this->analyze($text, $modelId, 'case');
    }

    /**
     * @return list<array{id:int,name:string}>
     */
    public function modelOptions(): array
    {
        return AiModel::query()
            ->select(['id', 'name'])
            ->where('status', 'active')
            ->where(function ($query): void {
                $query->whereNull('model_type')
                    ->orWhere('model_type', '')
                    ->orWhere('model_type', 'chat');
            })
            ->orderBy('failover_priority')
            ->orderBy('id')
            ->get()
            ->map(static fn (AiModel $model): array => [
                'id' => (int) $model->id,
                'name' => (string) $model->name,
            ])
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function analyze(string $text, int $modelId, string $type): array
    {
        $text = trim($text);
        if ($text === '') {
            return [];
        }

        $model = $this->resolveModel($modelId);
        if (! $model) {
            return $this->fallback($text, $type);
        }

        try {
            $content = $this->requestJson($model, $text, $type);
            $decoded = json_decode($content, true);

            return is_array($decoded) ? $this->normalize($decoded, $text, $type) : $this->fallback($text, $type);
        } catch (Throwable) {
            return $this->fallback($text, $type);
        }
    }

    private function resolveModel(int $modelId): ?AiModel
    {
        $query = AiModel::query()
            ->where('status', 'active')
            ->where(function ($builder): void {
                $builder->whereNull('model_type')
                    ->orWhere('model_type', '')
                    ->orWhere('model_type', 'chat');
            });

        if ($modelId > 0) {
            return (clone $query)->whereKey($modelId)->first();
        }

        return $query->orderBy('failover_priority')->orderBy('id')->first();
    }

    private function requestJson(AiModel $model, string $text, string $type): string
    {
        $providerUrl = OpenAiRuntimeProvider::resolveChatBaseUrl((string) ($model->api_url ?? ''));
        $apiKey = $this->apiKeyCrypto->decrypt((string) ($model->getRawOriginal('api_key') ?? ''));
        if ($providerUrl === '' || $apiKey === '') {
            throw new \RuntimeException('AI model is not configured');
        }

        $driver = OpenAiRuntimeProvider::resolveChatDriver($providerUrl, (string) ($model->model_id ?? ''));
        $providerName = OpenAiRuntimeProvider::registerProvider('material_form_analysis', $driver, $providerUrl, $apiKey);
        $schema = $type === 'entity'
            ? 'name, entity_type, aliases, description, attributes_json, source_url, tags'
            : 'title, case_type, summary, challenge, solution, result, metrics, source_url, tags';
        $system = '你是GEOFlow素材分析助手。只输出JSON，不要Markdown。';
        $prompt = "请分析以下内容并提取{$type}表单字段。JSON字段固定为：{$schema}。tags为字符串数组，attributes_json必须是可解析JSON字符串。\n\n内容：\n{$text}";

        $response = agent($system)->prompt($prompt, [], $providerName, (string) ($model->model_id ?? ''));
        $content = OpenAiRuntimeProvider::normalizeGeneratedText((string) ($response->text ?? ''));
        if ($content === '') {
            throw new \RuntimeException('empty ai response');
        }

        return trim(preg_replace('/^```(?:json)?|```$/m', '', $content) ?? $content);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function normalize(array $payload, string $text, string $type): array
    {
        $fallback = $this->fallback($text, $type);

        return array_replace($fallback, collect($payload)
            ->map(static fn ($value) => is_array($value) ? $value : trim((string) $value))
            ->filter(static fn ($value): bool => $value !== '' && $value !== [])
            ->all());
    }

    /**
     * @return array<string, mixed>
     */
    private function fallback(string $text, string $type): array
    {
        $firstLine = trim((string) Str::of($text)->replace(["\r\n", "\r"], "\n")->explode("\n")->first());
        $summary = Str::limit(trim(preg_replace('/\s+/u', ' ', $text) ?? $text), 600, '');

        if ($type === 'entity') {
            return [
                'name' => Str::limit($firstLine !== '' ? $firstLine : $summary, 160, ''),
                'entity_type' => 'AI识别实体',
                'aliases' => '',
                'description' => $summary,
                'attributes_json' => json_encode(['source' => 'manual_ai_analysis'], JSON_UNESCAPED_UNICODE),
                'source_url' => '',
                'tags' => [],
            ];
        }

        return [
            'title' => Str::limit($firstLine !== '' ? $firstLine : $summary, 200, ''),
            'case_type' => 'AI识别案例',
            'summary' => $summary,
            'challenge' => '',
            'solution' => '',
            'result' => '',
            'metrics' => '',
            'source_url' => '',
            'tags' => [],
        ];
    }
}
