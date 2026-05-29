<?php

namespace App\Services\GeoFlow;

use App\Models\AiModel;
use App\Support\GeoFlow\ApiKeyCrypto;
use App\Support\GeoFlow\OpenAiRuntimeProvider;
use Throwable;

use function Laravel\Ai\agent;

/**
 * 标题 AI 生成服务。
 *
 * 该服务负责：
 * 1. 基于 ai_models 配置发起真实模型调用；
 * 2. 在模型不可用时使用模板兜底，保证流程可用性；
 * 3. 输出统一结构，便于控制器处理入库逻辑。
 */
class TitleAiGenerationService
{
    /**
     * 复用统一 API Key 解密组件，避免标题生成链路与其他 AI 链路出现差异。
     */
    public function __construct(private readonly ApiKeyCrypto $apiKeyCrypto) {}

    /**
     * 生成标题列表。
     *
     * @param  list<string|array<string,mixed>>  $keywords
     * @return array{
     *   titles:list<string>,
     *   items:list<array{title:string,keyword:string}>,
     *   fallback_used:bool,
     *   fallback_reason:?string
     * }
     */
    public function generateTitles(
        AiModel $aiModel,
        array $keywords,
        int $count,
        string $style,
        string $customPrompt = ''
    ): array {
        $keywordContexts = $this->normalizeKeywordContexts($keywords);
        try {
            $content = $this->requestTitlesFromModel($aiModel, $keywordContexts, $count, $style, $customPrompt);
            $items = $this->parseGeneratedTitleItems($content, $keywordContexts);
            if ($items !== []) {
                return [
                    'titles' => array_values(array_unique(array_column($items, 'title'))),
                    'items' => $items,
                    'fallback_used' => false,
                    'fallback_reason' => null,
                ];
            }
        } catch (Throwable $exception) {
            $items = $this->generateMockTitleItems($keywordContexts, $count, $style, $customPrompt);

            return [
                'titles' => array_values(array_unique(array_column($items, 'title'))),
                'items' => $items,
                'fallback_used' => true,
                'fallback_reason' => $exception->getMessage(),
            ];
        }

        $items = $this->generateMockTitleItems($keywordContexts, $count, $style, $customPrompt);

        return [
            'titles' => array_values(array_unique(array_column($items, 'title'))),
            'items' => $items,
            'fallback_used' => true,
            'fallback_reason' => 'empty_result',
        ];
    }

    /**
     * 请求真实模型生成标题。
     *
     * @param  list<array{keyword:string,tags:array<string,string>,tag_labels:list<string>}>  $keywordContexts
     */
    private function requestTitlesFromModel(
        AiModel $aiModel,
        array $keywordContexts,
        int $count,
        string $style,
        string $customPrompt
    ): string {
        $providerUrl = OpenAiRuntimeProvider::resolveChatBaseUrl((string) ($aiModel->api_url ?? ''));
        if ($providerUrl === '') {
            throw new \RuntimeException('ai_url_missing');
        }

        $apiKey = $this->decryptApiKey((string) ($aiModel->getRawOriginal('api_key') ?? ''));
        if ($apiKey === '') {
            throw new \RuntimeException('ai_key_missing');
        }

        $driver = OpenAiRuntimeProvider::resolveChatDriver($providerUrl, (string) ($aiModel->model_id ?? ''));
        $providerName = OpenAiRuntimeProvider::registerProvider('title_ai', $driver, $providerUrl, $apiKey);

        $styleMap = [
            'professional' => '专业严谨的',
            'attractive' => '吸引眼球的',
            'seo' => 'SEO优化的',
            'creative' => '创意新颖的',
            'question' => '疑问式的',
        ];
        $styleDescription = $styleMap[$style] ?? '专业严谨的';
        $keywordsText = $this->formatKeywordContextLines($keywordContexts, $customPrompt);

        $systemPrompt = "你是一个专业的内容标题生成专家。请根据提供的关键词生成{$styleDescription}文章标题。";
        $userPrompt = "请基于以下关键词和标签上下文生成 {$count} 个{$styleDescription}文章标题：\n\n{$keywordsText}\n\n";
        if ($customPrompt !== '' && ! $this->promptHasVariables($customPrompt)) {
            $userPrompt .= "额外要求：{$customPrompt}\n\n";
        }
        $userPrompt .= "要求：\n1. 每个标题独占一行\n2. 每行格式必须为：关键词 | 标题\n3. 标题要有吸引力和可读性\n4. 适合搜索引擎优化\n5. 不要添加序号、Markdown 或额外解释";

        try {
            $response = agent($systemPrompt)->prompt(
                $userPrompt,
                [],
                $providerName,
                (string) ($aiModel->model_id ?? '')
            );
        } catch (Throwable $exception) {
            throw new \RuntimeException(OpenAiRuntimeProvider::normalizeApiException($exception, $providerUrl), 0, $exception);
        }

        $rawContent = (string) ($response->text ?? '');
        $content = OpenAiRuntimeProvider::normalizeGeneratedText($rawContent);

        if ($content === '') {
            if (OpenAiRuntimeProvider::looksLikeSseCompletionPayload($rawContent)) {
                throw new \RuntimeException('ai_empty_stream_content');
            }

            throw new \RuntimeException('ai_empty_content');
        }

        return $content;
    }

    /**
     * @param  list<array{keyword:string,tags:array<string,string>,tag_labels:list<string>}>  $keywordContexts
     * @return list<array{title:string,keyword:string}>
     */
    private function parseGeneratedTitleItems(string $content, array $keywordContexts): array
    {
        $items = [];
        $fallbackKeywords = array_values(array_map(static fn (array $context): string => $context['keyword'], $keywordContexts));
        foreach (preg_split('/\R/u', $content) ?: [] as $index => $line) {
            $line = preg_replace('/^\d+[\.\)\-、\s]*/u', '', trim($line));
            $line = trim((string) $line);
            if ($line === '') {
                continue;
            }

            $keyword = '';
            $title = $line;
            if (str_contains($line, '|')) {
                [$keywordPart, $titlePart] = array_pad(explode('|', $line, 2), 2, '');
                $keyword = trim((string) $keywordPart);
                $title = trim((string) $titlePart);
            }
            if ($title === '') {
                continue;
            }

            $keyword = $this->resolveTitleKeyword($title, $keyword, $keywordContexts, $fallbackKeywords, $index);
            $items[] = [
                'title' => $title,
                'keyword' => $keyword,
            ];
        }

        return collect($items)
            ->unique(static fn (array $item): string => $item['title'])
            ->values()
            ->all();
    }

    /**
     * 解密 ai_models 中的 API Key（兼容旧系统 enc:v1 格式）。
     */
    private function decryptApiKey(string $storedApiKey): string
    {
        return $this->apiKeyCrypto->decrypt($storedApiKey);
    }

    /**
     * @param  list<array{keyword:string,tags:array<string,string>,tag_labels:list<string>}>  $keywordContexts
     * @return list<array{title:string,keyword:string}>
     */
    private function generateMockTitleItems(array $keywordContexts, int $count, string $style, string $customPrompt): array
    {
        $styleTemplates = [
            'professional' => [
                '{keyword}的深度分析与研究',
                '关于{keyword}的专业见解',
                '{keyword}行业发展趋势报告',
            ],
            'attractive' => [
                '你绝对不知道的{keyword}秘密',
                '揭秘{keyword}背后的故事',
                '{keyword}让人意想不到的用途',
            ],
            'seo' => [
                '{keyword}完整指南：从入门到精通',
                '{keyword}常见问题解答大全',
                '如何选择最适合的{keyword}方案',
            ],
            'creative' => [
                '重新定义{keyword}的可能性',
                '如果{keyword}会说话，它会告诉你什么？',
                '当{keyword}遇上创新思维',
            ],
            'question' => [
                '{keyword}真的有用吗？',
                '为什么{keyword}如此重要？',
                '{keyword}的未来在哪里？',
            ],
        ];

        $templates = $styleTemplates[$style] ?? $styleTemplates['professional'];
        $items = [];
        $keywordContexts = $keywordContexts !== [] ? $keywordContexts : [[
            'keyword' => 'GEOFlow',
            'tags' => [],
            'tag_labels' => [],
        ]];
        for ($index = 0; $index < $count; $index++) {
            $context = $keywordContexts[array_rand($keywordContexts)];
            $keyword = $context['keyword'];
            if ($customPrompt !== '' && $this->promptHasVariables($customPrompt)) {
                $title = $this->renderPromptVariables($customPrompt, $context);
            } else {
                $template = $templates[array_rand($templates)];
                $title = str_replace('{keyword}', $keyword, $template);
            }

            $items[] = [
                'title' => mb_substr(trim($title), 0, 500, 'UTF-8'),
                'keyword' => $keyword,
            ];
        }

        return collect($items)
            ->filter(static fn (array $item): bool => $item['title'] !== '')
            ->unique(static fn (array $item): string => $item['title'])
            ->values()
            ->all();
    }

    /**
     * @param  list<string|array<string,mixed>>  $keywords
     * @return list<array{keyword:string,tags:array<string,string>,tag_labels:list<string>}>
     */
    private function normalizeKeywordContexts(array $keywords): array
    {
        $contexts = [];
        foreach ($keywords as $keyword) {
            if (is_array($keyword)) {
                $keywordText = trim((string) ($keyword['keyword'] ?? ''));
                if ($keywordText === '') {
                    continue;
                }
                $tags = [];
                foreach ((array) ($keyword['tags'] ?? []) as $group => $value) {
                    $group = trim((string) $group);
                    $value = trim((string) $value);
                    if ($group !== '' && $value !== '') {
                        $tags[$group] = $value;
                    }
                }
                $tagLabels = array_values(array_filter(array_map('trim', (array) ($keyword['tag_labels'] ?? []))));
                if ($tagLabels === [] && $tags !== []) {
                    $tagLabels = array_map(static fn (string $group, string $value): string => $group.':'.$value, array_keys($tags), array_values($tags));
                }
                $contexts[] = [
                    'keyword' => $keywordText,
                    'tags' => $tags,
                    'tag_labels' => $tagLabels,
                ];

                continue;
            }

            $keywordText = trim((string) $keyword);
            if ($keywordText !== '') {
                $contexts[] = [
                    'keyword' => $keywordText,
                    'tags' => [],
                    'tag_labels' => [],
                ];
            }
        }

        return $contexts;
    }

    /**
     * @param  list<array{keyword:string,tags:array<string,string>,tag_labels:list<string>}>  $keywordContexts
     */
    private function formatKeywordContextLines(array $keywordContexts, string $customPrompt): string
    {
        $lines = [];
        foreach ($keywordContexts as $context) {
            $line = '- 关键词：'.$context['keyword'];
            if ($context['tag_labels'] !== []) {
                $line .= '；标签：'.implode('，', $context['tag_labels']);
            }
            if ($customPrompt !== '' && $this->promptHasVariables($customPrompt)) {
                $line .= '；变量要求：'.$this->renderPromptVariables($customPrompt, $context);
            }
            $lines[] = $line;
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array{keyword:string,tags:array<string,string>,tag_labels:list<string>}  $context
     */
    private function renderPromptVariables(string $prompt, array $context): string
    {
        return preg_replace_callback('/\{\{\s*([A-Za-z0-9_.\-\x{4e00}-\x{9fa5}]+)\s*\}\}/u', function (array $matches) use ($context): string {
            $name = trim((string) ($matches[1] ?? ''));
            $lowerName = mb_strtolower($name, 'UTF-8');
            if ($lowerName === 'keyword') {
                return $context['keyword'];
            }
            if (in_array($lowerName, ['tags', 'keyword.tags', 'keyword.tag_labels'], true)) {
                return implode('，', $context['tag_labels']);
            }
            foreach (['keyword.tags.', 'keyword.tag.', 'tags.', 'tag.'] as $prefix) {
                if (! str_starts_with($lowerName, $prefix)) {
                    continue;
                }
                $groupName = trim(mb_substr($name, mb_strlen($prefix, 'UTF-8'), null, 'UTF-8'));

                return $this->tagValue($context['tags'], $groupName);
            }

            return (string) ($matches[0] ?? '');
        }, $prompt) ?? $prompt;
    }

    /**
     * @param  array<string,string>  $tags
     */
    private function tagValue(array $tags, string $groupName): string
    {
        foreach ($tags as $group => $value) {
            if (mb_strtolower($group, 'UTF-8') === mb_strtolower($groupName, 'UTF-8')) {
                return $value;
            }
        }

        return '';
    }

    private function promptHasVariables(string $prompt): bool
    {
        return preg_match('/\{\{\s*(keyword|tags|tag|keyword\.tags|keyword\.tag|keyword\.tag_labels)(?:[.\s}])/iu', $prompt) === 1;
    }

    /**
     * @param  list<array{keyword:string,tags:array<string,string>,tag_labels:list<string>}>  $keywordContexts
     * @param  list<string>  $fallbackKeywords
     */
    private function resolveTitleKeyword(string $title, string $reportedKeyword, array $keywordContexts, array $fallbackKeywords, int $index): string
    {
        foreach ($keywordContexts as $context) {
            if ($reportedKeyword !== '' && trim($reportedKeyword) === $context['keyword']) {
                return $context['keyword'];
            }
        }
        foreach ($keywordContexts as $context) {
            if (mb_stripos($title, $context['keyword'], 0, 'UTF-8') !== false) {
                return $context['keyword'];
            }
        }

        return $fallbackKeywords !== [] ? $fallbackKeywords[$index % count($fallbackKeywords)] : '';
    }
}
