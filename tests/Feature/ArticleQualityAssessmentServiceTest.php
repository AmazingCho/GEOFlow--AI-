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

    public function test_quality_assessment_flags_missing_knowledge_images_and_faq(): void
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
        $this->assertContains('faq', collect($report['issues'])->pluck('key')->all());
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
                'chunks' => [['knowledge_base_name' => 'Quality KB', 'chunk_index' => 1]],
                'entities' => [['name' => 'GEOFlow']],
                'cases' => [['title' => 'Quality case']],
            ],
            'images' => [['id' => 1, 'original_name' => 'quality.png']],
            'task' => ['image_tag_filter' => 'quality'],
        ]);

        $this->assertGreaterThanOrEqual(80, $report['score']);
        $this->assertSame('good', $report['status']);
    }
}
