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
class ArticlePlanAgent implements Agent, Conversational, HasProviderOptions, HasStructuredOutput, HasTools
{
    use ConfiguresMaxOutputTokens;
    use Promptable;

    /**
     * @param  iterable<int, mixed>  $messages
     * @param  iterable<int, mixed>  $tools
     */
    public function __construct(
        public string $instructions = 'You are the structured planning stage of an evidence-grounded article workflow. Return only data that conforms to the supplied schema.',
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
        $evidenceReferences = fn () => $schema->array()
            ->items($schema->string()->min(1)->max(240))
            ->max(50)
            ->unique()
            ->required();

        return [
            'reader_question' => $schema->string()->min(1)->max(2000)->required(),
            'answer_mode' => $schema->string()
                ->enum(['direct', 'conditional', 'evidence_limited', 'stop'])
                ->required(),
            'evidence_sufficiency' => $schema->string()
                ->enum(['sufficient', 'limited', 'insufficient'])
                ->required(),
            'supported_sections' => $schema->array()->items(
                $schema->object([
                    'purpose' => $schema->string()->min(1)->max(1200)->required(),
                    'support_type' => $schema->string()
                        ->enum(['evidence', 'general_explanation'])
                        ->required(),
                    'evidence_refs' => $evidenceReferences(),
                ])->withoutAdditionalProperties()
            )->max(20)->required(),
            'evidence_mapping' => $schema->array()->items(
                $schema->object([
                    'claim_scope' => $schema->string()->min(1)->max(1000)->required(),
                    'evidence_refs' => $evidenceReferences(),
                ])->withoutAdditionalProperties()
            )->max(50)->required(),
            'optional_modules' => $schema->array()
                ->items($schema->string()->min(1)->max(500))
                ->max(20)
                ->unique()
                ->required(),
            'unsupported_claims_to_avoid' => $schema->array()
                ->items($schema->string()->min(1)->max(1000))
                ->max(50)
                ->unique()
                ->required(),
            'verification_items' => $schema->array()->items(
                $schema->object([
                    'question' => $schema->string()->min(1)->max(1000)->required(),
                    'category' => $schema->string()->enum([
                        'specification',
                        'compatibility',
                        'process',
                        'integration',
                        'safety',
                        'commercial',
                        'case_evidence',
                        'other',
                    ])->required(),
                    'required_for_draft' => $schema->boolean()->required(),
                ])->withoutAdditionalProperties()
            )->max(50)->required(),
        ];
    }
}
