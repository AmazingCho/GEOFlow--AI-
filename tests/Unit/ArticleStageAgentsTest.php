<?php

namespace Tests\Unit;

use App\Ai\Agents\ArticlePlanAgent;
use App\Ai\Agents\ArticleReviewAgent;
use App\Ai\Agents\MarkdownContentWriterAgent;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Tests\TestCase;

class ArticleStageAgentsTest extends TestCase
{
    public function test_plan_agent_has_neutral_role_and_structured_plan_schema(): void
    {
        $agent = new ArticlePlanAgent(maxTokens: 2048);

        $this->assertInstanceOf(HasStructuredOutput::class, $agent);
        $this->assertStringNotContainsString('中文', $agent->instructions());
        $this->assertStringNotContainsString('Markdown article', $agent->instructions());
        $this->assertSame([
            'reader_question',
            'answer_mode',
            'evidence_sufficiency',
            'supported_sections',
            'evidence_mapping',
            'optional_modules',
            'unsupported_claims_to_avoid',
            'verification_items',
        ], array_keys($agent->schema(new JsonSchemaTypeFactory)));
    }

    public function test_review_agent_has_neutral_role_and_complete_review_schema(): void
    {
        $agent = new ArticleReviewAgent(maxTokens: 2048);

        $this->assertInstanceOf(HasStructuredOutput::class, $agent);
        $this->assertStringNotContainsString('中文', $agent->instructions());
        $this->assertSame([
            'passed',
            'score',
            'issue_codes',
            'issues',
            'revision_instructions',
            'metrics',
        ], array_keys($agent->schema(new JsonSchemaTypeFactory)));
    }

    public function test_markdown_writer_default_is_language_neutral_and_not_a_json_agent(): void
    {
        $agent = new MarkdownContentWriterAgent;

        $this->assertNotInstanceOf(HasStructuredOutput::class, $agent);
        $this->assertStringNotContainsString('专业中文', $agent->instructions());
        $this->assertStringContainsString('Markdown', $agent->instructions());
    }
}
