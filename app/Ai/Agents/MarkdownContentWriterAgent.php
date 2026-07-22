<?php

namespace App\Ai\Agents;

use App\Ai\Agents\Concerns\ConfiguresMaxOutputTokens;
use App\Jobs\ProcessGeoFlowTaskJob;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;

/**
 * Worker 正文生成专用 Agent：通过 {@see Timeout} 配置 HTTP 超时（秒）。
 *
 * 须小于 {@see ProcessGeoFlowTaskJob::$timeout}，避免队列作业尚未结束而 HTTP 已先超时。
 *
 * 可通过 provider options 注入最大输出 token，避免长文生成被服务商较小的默认上限截断。
 */
#[Timeout(240)]
class MarkdownContentWriterAgent implements Agent, Conversational, HasProviderOptions, HasTools
{
    use ConfiguresMaxOutputTokens;
    use Promptable;

    /**
     * @param  iterable<int, mixed>  $messages
     * @param  iterable<int, mixed>  $tools
     */
    public function __construct(
        public string $instructions = 'You are the Markdown drafting stage of an article workflow. Follow the requested target language and return only the complete article body.',
        public iterable $messages = [],
        public iterable $tools = [],
        public ?int $maxTokens = null,
    ) {}

    /**
     * {@inheritdoc}
     */
    public function instructions(): string
    {
        return $this->instructions;
    }

    /**
     * {@inheritdoc}
     */
    public function messages(): iterable
    {
        return $this->messages;
    }

    /**
     * {@inheritdoc}
     */
    public function tools(): iterable
    {
        return $this->tools;
    }
}
