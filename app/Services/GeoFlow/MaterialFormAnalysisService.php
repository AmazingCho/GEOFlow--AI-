<?php

namespace App\Services\GeoFlow;

use App\Models\AiModel;
use App\Models\CollectionRecord;
use App\Models\EntityRecord;
use App\Models\KnowledgeBase;
use App\Models\Tag;
use App\Support\GeoFlow\ApiKeyCrypto;
use App\Support\GeoFlow\EntityTypes;
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
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function analyzeKnowledge(string $text, int $modelId = 0, array $context = []): array
    {
        return $this->analyze($text, $modelId, 'knowledge', $context);
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
    private function analyze(string $text, int $modelId, string $type, array $context = []): array
    {
        $text = trim($text);
        if ($text === '') {
            return [];
        }

        $model = $this->resolveModel($modelId);
        if (! $model) {
            return $this->fallback($text, $type, $context);
        }

        try {
            $content = $this->requestJson($model, $text, $type, $context);
            $decoded = json_decode($content, true);

            return is_array($decoded) ? $this->normalize($decoded, $text, $type, $context) : $this->fallback($text, $type, $context);
        } catch (Throwable) {
            return $this->fallback($text, $type, $context);
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

    private function requestJson(AiModel $model, string $text, string $type, array $context = []): string
    {
        $providerUrl = OpenAiRuntimeProvider::resolveChatBaseUrl((string) ($model->api_url ?? ''));
        $apiKey = $this->apiKeyCrypto->decrypt((string) ($model->getRawOriginal('api_key') ?? ''));
        if ($providerUrl === '' || $apiKey === '') {
            throw new \RuntimeException('AI model is not configured');
        }

        $driver = OpenAiRuntimeProvider::resolveChatDriver($providerUrl, (string) ($model->model_id ?? ''));
        $providerName = OpenAiRuntimeProvider::registerProvider('material_form_analysis', $driver, $providerUrl, $apiKey);
        $schema = match ($type) {
            'entity' => 'name, entity_type, aliases, description, attributes_json, source_url, tags',
            'knowledge' => 'collection_id, knowledge_type, knowledge_role, importance, summary, description, content, entity_ids, tags',
            default => 'title, case_type, summary, challenge, solution, result, metrics, source_url, tags',
        };
        $system = '你是GEOFlow素材分析助手。只输出JSON，不要Markdown。';
        $contextText = $type === 'knowledge' ? $this->knowledgeContextPrompt($context) : '';
        $knowledgeInstruction = $type === 'knowledge'
            ? '知识库 content 字段必须返回可直接入库的 Markdown 正文：保留事实、参数、FAQ、步骤、案例线索和重要细节，可以清理格式和去重，但不要改写成短摘要，也不要包含表单标题或来源 URL。'
            : '';
        $prompt = "请分析以下内容并提取{$type}表单字段。JSON字段固定为：{$schema}。tags为字符串数组，attributes_json必须是可解析JSON字符串。知识库 role 只能使用 primary_source、supporting_context、constraint、comparison_reference、style_reference、archive；importance 为 1 到 5。{$knowledgeInstruction}\n{$contextText}\n\n内容：\n{$text}";

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
    private function normalize(array $payload, string $text, string $type, array $context = []): array
    {
        $fallback = $this->fallback($text, $type, $context);

        if ($type === 'entity') {
            $payload['entity_type'] = EntityTypes::normalize((string) ($payload['entity_type'] ?? ''));
        }

        if ($type === 'knowledge') {
            $payload = $this->normalizeKnowledgePayload($payload, $context);
        }

        return array_replace($fallback, collect($payload)
            ->map(static fn ($value) => is_array($value) ? $value : trim((string) $value))
            ->filter(static fn ($value): bool => $value !== '' && $value !== [])
            ->all());
    }

    /**
     * @return array<string, mixed>
     */
    private function fallback(string $text, string $type, array $context = []): array
    {
        $firstLine = trim((string) Str::of($text)->replace(["\r\n", "\r"], "\n")->explode("\n")->first());
        $summary = Str::limit(trim(preg_replace('/\s+/u', ' ', $text) ?? $text), 600, '');

        if ($type === 'entity') {
            return [
                'name' => Str::limit($firstLine !== '' ? $firstLine : $summary, 160, ''),
                'entity_type' => EntityTypes::GENERAL,
                'aliases' => '',
                'description' => $summary,
                'attributes_json' => json_encode(['source' => 'manual_ai_analysis'], JSON_UNESCAPED_UNICODE),
                'source_url' => '',
                'tags' => [],
            ];
        }

        if ($type === 'knowledge') {
            return $this->fallbackKnowledge($text, $summary, $context);
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

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function fallbackKnowledge(string $text, string $summary, array $context): array
    {
        $rawContent = trim((string) ($context['raw_content'] ?? ''));
        $content = $rawContent !== '' ? $rawContent : $text;
        $lower = mb_strtolower($text, 'UTF-8');
        $knowledgeType = 'reference';
        if (str_contains($lower, 'faq') || str_contains($lower, '常见问题')) {
            $knowledgeType = 'faq';
        } elseif (str_contains($lower, '竞品') || str_contains($lower, 'competitor')) {
            $knowledgeType = 'competitor_analysis';
        } elseif (str_contains($lower, '故障') || str_contains($lower, 'troubleshooting')) {
            $knowledgeType = 'troubleshooting';
        } elseif (str_contains($lower, '手册') || str_contains($lower, 'manual')) {
            $knowledgeType = 'product_manual';
        }

        $knowledgeRole = match ($knowledgeType) {
            'product_manual', 'technical_spec' => 'primary_source',
            'competitor_analysis' => 'comparison_reference',
            'troubleshooting' => 'supporting_context',
            default => 'supporting_context',
        };

        $collectionId = $this->firstMatchedId((array) ($context['collections'] ?? []), $text, 'name');
        $entityIds = $this->matchedIds((array) ($context['entities'] ?? []), $text, 'name', 5);
        $tags = $this->matchedTagLabels((array) ($context['tags'] ?? []), $text);

        return [
            'collection_id' => $collectionId,
            'knowledge_type' => $knowledgeType,
            'knowledge_role' => $knowledgeRole,
            'importance' => $knowledgeRole === 'primary_source' ? 5 : 3,
            'summary' => $summary,
            'description' => $summary,
            'content' => $content,
            'entity_ids' => $entityIds,
            'tags' => $tags,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function normalizeKnowledgePayload(array $payload, array $context): array
    {
        $roleMap = [
            'primary' => 'primary_source',
            'supporting' => 'supporting_context',
            'reference' => 'supporting_context',
            'archive' => 'archive',
        ];
        $importanceMap = ['low' => 2, 'medium' => 3, 'high' => 5];

        $collectionId = (int) ($payload['collection_id'] ?? 0);
        if ($collectionId <= 0) {
            $collectionName = (string) ($payload['suggested_collection'] ?? '');
            $collectionId = $this->firstMatchedId((array) ($context['collections'] ?? []), $collectionName, 'name');
        }

        $entityIds = collect($payload['entity_ids'] ?? $payload['matched_entities'] ?? [])
            ->map(function (mixed $entity) use ($context): int {
                if (is_numeric($entity)) {
                    return (int) $entity;
                }

                return $this->firstMatchedId((array) ($context['entities'] ?? []), (string) $entity, 'name');
            })
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();

        $tags = $payload['tags'] ?? [];
        if ((empty($tags) || ! is_array($tags)) && is_array($payload['suggested_tags'] ?? null)) {
            $tags = [];
            foreach ($payload['suggested_tags'] as $group => $values) {
                foreach ((array) $values as $value) {
                    $name = trim((string) $value);
                    if ($name !== '') {
                        $tags[] = trim((string) $group).':'.$name;
                    }
                }
            }
        }

        $role = trim((string) ($payload['knowledge_role'] ?? $payload['role'] ?? ''));
        $payload['collection_id'] = $collectionId > 0 ? $collectionId : '';
        $mappedRole = $roleMap[$role] ?? $role;
        $payload['knowledge_role'] = in_array($mappedRole, KnowledgeBase::KNOWLEDGE_ROLES, true) ? $mappedRole : 'supporting_context';
        $payload['knowledge_type'] = in_array((string) ($payload['knowledge_type'] ?? ''), KnowledgeBase::KNOWLEDGE_TYPES, true)
            ? (string) $payload['knowledge_type']
            : 'reference';
        $importance = $payload['importance'] ?? 3;
        if (is_string($importance) && isset($importanceMap[$importance])) {
            $importance = $importanceMap[$importance];
        }
        $payload['importance'] = min(5, max(1, (int) $importance));
        $payload['content'] = trim((string) ($payload['content'] ?? $context['raw_content'] ?? ''));
        $payload['entity_ids'] = $entityIds;
        $payload['tags'] = array_values(array_unique(array_filter(array_map('strval', (array) $tags))));

        return $payload;
    }

    /**
     * @param  list<array<string,mixed>>  $rows
     */
    private function firstMatchedId(array $rows, string $text, string $field): int
    {
        $text = mb_strtolower(trim($text), 'UTF-8');
        if ($text === '') {
            return 0;
        }

        foreach ($rows as $row) {
            $name = mb_strtolower(trim((string) ($row[$field] ?? '')), 'UTF-8');
            if ($name !== '' && (str_contains($text, $name) || str_contains($name, $text))) {
                return (int) ($row['id'] ?? 0);
            }
        }

        return 0;
    }

    /**
     * @param  list<array<string,mixed>>  $rows
     * @return list<int>
     */
    private function matchedIds(array $rows, string $text, string $field, int $limit): array
    {
        $ids = [];
        foreach ($rows as $row) {
            $id = $this->firstMatchedId([$row], $text, $field);
            if ($id > 0) {
                $ids[] = $id;
            }
            if (count($ids) >= $limit) {
                break;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param  list<array<string,mixed>>  $tags
     * @return list<string>
     */
    private function matchedTagLabels(array $tags, string $text): array
    {
        $labels = [];
        $lower = mb_strtolower($text, 'UTF-8');
        foreach ($tags as $tag) {
            $name = trim((string) ($tag['name'] ?? ''));
            if ($name !== '' && str_contains($lower, mb_strtolower($name, 'UTF-8'))) {
                $group = trim((string) ($tag['group_name'] ?? ''));
                $labels[] = $group !== '' ? $group.':'.$name : $name;
            }
        }

        return array_values(array_unique($labels));
    }

    /**
     * @param  array<string,mixed>  $context
     */
    private function knowledgeContextPrompt(array $context): string
    {
        $collections = collect($context['collections'] ?? [])->pluck('name')->take(30)->implode(', ');
        $entities = collect($context['entities'] ?? [])->pluck('name')->take(80)->implode(', ');
        $tags = collect($context['tags'] ?? [])->map(static function ($tag): string {
            $group = trim((string) ($tag['group_name'] ?? ''));
            $name = trim((string) ($tag['name'] ?? ''));

            return $group !== '' ? $group.':'.$name : $name;
        })->filter()->take(120)->implode(', ');

        return "可选 Collection：{$collections}\n可选 Entity：{$entities}\n可选已有标签：{$tags}";
    }
}
