<?php

namespace App\Ai\Agents;

use App\Ai\Agents\Concerns\ConfiguresMaxOutputTokens;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;

#[Timeout(240)]
class ArticleReviewAgent implements Agent, Conversational, HasProviderOptions, HasStructuredOutput, HasTools
{
    use ConfiguresMaxOutputTokens;
    use Promptable;

    /**
     * @param  iterable<int, mixed>  $messages
     * @param  iterable<int, mixed>  $tools
     */
    public function __construct(
        public string $instructions = 'You are the structured review stage of an evidence-grounded article workflow. Audit the supplied article and return only data that conforms to the supplied schema.',
        public iterable $messages = [],
        public iterable $tools = [],
        public ?int $maxTokens = 2048,
    ) {}

    public function instructions(): string
    {
        return $this->instructions;
    }

    public function messages(): iterable
    {
        return $this->messages;
    }

    public function tools(): iterable
    {
        return $this->tools;
    }

    public function schema(JsonSchema $schema): array
    {
        $metric = fn () => $schema->integer()->min(1)->max(5)->required();

        return [
            'passed' => $schema->boolean()->required(),
            'score' => $schema->integer()->min(0)->max(100)->required(),
            'issue_codes' => $schema->array()
                ->items($schema->string()->min(1)->max(120))
                ->max(50)
                ->unique()
                ->required(),
            'issues' => $schema->array()->items(
                $schema->object([
                    'code' => $schema->string()->min(1)->max(120)->required(),
                    'severity' => $schema->string()->enum(['low', 'medium', 'high', 'critical'])->required(),
                    'message' => $schema->string()->max(1500)->required(),
                ])->withoutAdditionalProperties()
            )->max(50)->required(),
            'revision_instructions' => $schema->array()->items(
                $schema->object([
                    'target' => $schema->string()->min(1)->max(500)->required(),
                    'instruction' => $schema->string()->min(1)->max(2000)->required(),
                ])->withoutAdditionalProperties()
            )->max(50)->required(),
            'metrics' => $schema->object([
                'factual_support' => $metric(),
                'clarity' => $metric(),
                'buyer_decision_value' => $metric(),
                'structure_naturalness' => $metric(),
                'uncertainty_and_negative_fit' => $metric(),
                'privacy_and_safety' => $metric(),
                'style_fitness' => $metric(),
                'non_template_naturalness' => $metric(),
            ])->withoutAdditionalProperties()->required(),
        ];
    }
}
