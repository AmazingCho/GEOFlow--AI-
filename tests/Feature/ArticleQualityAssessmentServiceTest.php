<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Services\GeoFlow\ArticleQualityAssessmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ArticleQualityAssessmentServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_quality_assessment_flags_missing_knowledge_and_images_without_forcing_optional_faq(): void
    {
        $category = Category::query()->create(['name' => 'Quality', 'slug' => 'quality']);
        $author = Author::query()->create(['name' => 'Quality Author']);
        $article = Article::query()->create([
            'title' => 'Quality Check Article',
            'slug' => 'quality-check-article',
            'excerpt' => 'Short',
            'content' => 'This is a very short article about GEOFlow quality. It has no evidence and no question section.',
            'keywords' => 'GEOFlow quality',
            'category_id' => $category->id,
            'author_id' => $author->id,
            'status' => 'draft',
            'review_status' => 'pending',
        ]);

        $report = app(ArticleQualityAssessmentService::class)->assess($article, [
            'knowledge' => ['context_length' => 0, 'chunks' => []],
            'images' => [],
        ]);

        $this->assertLessThan(70, $report['score']);
        $this->assertContains('knowledge', collect($report['issues'])->pluck('key')->all());
        $this->assertContains('images', collect($report['issues'])->pluck('key')->all());
        $this->assertNotContains('faq', collect($report['issues'])->pluck('key')->all());
        $faqItem = collect($report['items'])->firstWhere('key', 'faq');
        $this->assertSame('passed', $faqItem['status']);
        $this->assertContains('optional_module_not_used', $faqItem['reasons']);
        $knowledgeItem = collect($report['items'])->firstWhere('key', 'knowledge');
        $this->assertIsArray($knowledgeItem);
        $this->assertSame(0, (int) data_get($knowledgeItem, 'metrics.context_length'));
        $this->assertContains('no_rag_context', data_get($knowledgeItem, 'reasons'));
        $imageItem = collect($report['items'])->firstWhere('key', 'images');
        $this->assertContains('no_images', data_get($imageItem, 'reasons'));
    }

    public function test_quality_assessment_surfaces_unresolved_deep_review_issues(): void
    {
        $category = Category::query()->create(['name' => 'Deep Review', 'slug' => 'deep-review']);
        $author = Author::query()->create(['name' => 'Deep Review Author']);
        $article = Article::query()->create([
            'title' => 'Deep Review Article',
            'slug' => 'deep-review-article',
            'content' => "## Decision\n\nA complete decision paragraph explains the current evidence boundary.",
            'category_id' => $category->id,
            'author_id' => $author->id,
            'status' => 'draft',
            'review_status' => 'pending',
        ]);

        $report = app(ArticleQualityAssessmentService::class)->assess($article, [
            'deep_review' => [
                'passed' => false,
                'score' => 74,
                'issue_codes' => ['insufficient_negative_fit'],
                'requires_manual_review' => true,
            ],
        ]);

        $deepItem = collect($report['items'])->firstWhere('key', 'deep_review');
        $this->assertSame('warning', $deepItem['status']);
        $this->assertContains('insufficient_negative_fit', $deepItem['reasons']);
        $this->assertContains('deep_review', collect($report['issues'])->pluck('key')->all());
    }

    public function test_quality_assessment_rewards_supported_structured_articles(): void
    {
        $category = Category::query()->create(['name' => 'Quality Good', 'slug' => 'quality-good']);
        $author = Author::query()->create(['name' => 'Quality Author']);
        $content = <<<'MARKDOWN'
## Overview
GEOFlow quality assessment helps teams review generated articles with consistent standards. GEOFlow quality workflows combine reliable source material, factual evidence, and editorial review so each article is easier to cite.

## Evidence and Context
The process uses knowledge-base chunks, entities, cases, and measurable data such as 85% coverage targets. These details help reviewers understand why a recommendation was included and what source material supported it.

## Implementation Steps
Editors can review language consistency, keyword coverage, image matching, and structure before publishing. The checklist keeps production fast while preserving a clear manual review path.

## Conclusion
With quality scoring, generated drafts become easier to prioritize, improve, and publish with confidence.

## FAQ
What does the quality score measure?
It measures language, knowledge references, keywords, facts, structure, FAQ, images, and duplication.

Why does GEOFlow quality matter?
It helps teams find weak articles before publication and improve them with concrete suggestions.
MARKDOWN;
        $article = Article::query()->create([
            'title' => 'GEOFlow Quality Assessment Guide',
            'slug' => 'geoflow-quality-assessment-guide',
            'excerpt' => 'A quality assessment guide.',
            'content' => $content,
            'keywords' => 'GEOFlow quality, quality assessment',
            'category_id' => $category->id,
            'author_id' => $author->id,
            'status' => 'draft',
            'review_status' => 'pending',
        ]);

        $report = app(ArticleQualityAssessmentService::class)->assess($article, [
            'knowledge' => [
                'context_length' => 1200,
                'evidence_summary' => [
                    'chunk_count' => 1,
                    'average_evidence_score' => 72,
                    'retrieval_sources' => ['fallback_embedding_hybrid'],
                ],
                'knowledge_bases' => [['id' => 1, 'name' => 'Quality KB']],
                'chunks' => [['knowledge_base_name' => 'Quality KB', 'chunk_index' => 1]],
                'entities' => [['name' => 'GEOFlow']],
                'cases' => [['title' => 'Quality case']],
            ],
            'images' => [['id' => 1, 'original_name' => 'quality.png']],
            'task' => ['image_tag_filter' => 'quality'],
        ]);

        $this->assertGreaterThanOrEqual(80, $report['score']);
        $this->assertSame('good', $report['status']);
        $knowledgeItem = collect($report['items'])->firstWhere('key', 'knowledge');
        $this->assertSame(1, (int) data_get($knowledgeItem, 'metrics.chunk_count'));
        $this->assertSame(72, (int) data_get($knowledgeItem, 'metrics.average_evidence_score'));
        $factsItem = collect($report['items'])->firstWhere('key', 'facts');
        $this->assertSame(1, (int) data_get($factsItem, 'metrics.entity_count'));
        $this->assertSame(1, (int) data_get($factsItem, 'metrics.case_count'));
    }

    public function test_quality_assessment_counts_markdown_prose_paragraphs_before_plain_text_normalization(): void
    {
        $category = Category::query()->create(['name' => 'Paragraph Quality', 'slug' => 'paragraph-quality']);
        $author = Author::query()->create(['name' => 'Paragraph Author']);
        $repeatedParagraph = 'This repeated paragraph documents the same supported operating condition for duplicate detection.';
        $content = <<<MARKDOWN
## Overview
The opening paragraph explains the article scope and gives the reader enough context to understand the decision.

The second paragraph adds a distinct supported detail without repeating the opening explanation.

## Operating Conditions
{$repeatedParagraph}

{$repeatedParagraph}

## Conclusion
The final paragraph summarizes the documented decision and gives the reader a clear next step.
MARKDOWN;
        $article = Article::query()->create([
            'title' => 'Paragraph Structure Assessment',
            'slug' => 'paragraph-structure-assessment',
            'excerpt' => 'Paragraph structure regression coverage.',
            'content' => $content,
            'keywords' => 'paragraph structure',
            'category_id' => $category->id,
            'author_id' => $author->id,
            'status' => 'draft',
            'review_status' => 'pending',
        ]);

        $report = app(ArticleQualityAssessmentService::class)->assess($article);
        $structureItem = collect($report['items'])->firstWhere('key', 'structure');
        $uniquenessItem = collect($report['items'])->firstWhere('key', 'uniqueness');

        $this->assertSame(5, (int) data_get($structureItem, 'metrics.paragraph_count'));
        $this->assertNotContains('few_paragraphs', data_get($structureItem, 'reasons'));
        $this->assertSame(5, (int) data_get($uniquenessItem, 'metrics.paragraph_count'));
        $this->assertSame(1, (int) data_get($uniquenessItem, 'metrics.duplicate_paragraphs'));
        $this->assertContains('duplicate_paragraphs', data_get($uniquenessItem, 'reasons'));
    }
}
