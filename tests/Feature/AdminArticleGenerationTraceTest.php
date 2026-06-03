<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Models\Task;
use App\Models\TaskRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminArticleGenerationTraceTest extends TestCase
{
    use RefreshDatabase;

    public function test_article_edit_page_shows_generation_trace_sources(): void
    {
        $admin = Admin::query()->create([
            'username' => 'trace_admin',
            'password' => 'secret-123',
            'email' => 'trace-admin@example.com',
            'display_name' => 'Trace Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);
        $author = Author::query()->create(['name' => 'Trace Author']);
        $category = Category::query()->create(['name' => 'Trace Category', 'slug' => 'trace-category']);
        $task = Task::query()->create(['name' => 'Trace Task']);
        $article = Article::query()->create([
            'title' => 'Trace Article',
            'slug' => 'trace-article',
            'excerpt' => 'Trace excerpt',
            'content' => 'Trace content',
            'category_id' => (int) $category->id,
            'author_id' => (int) $author->id,
            'task_id' => (int) $task->id,
            'original_keyword' => 'trace keyword',
            'keywords' => 'trace keyword',
            'status' => 'draft',
            'review_status' => 'pending',
            'is_ai_generated' => 1,
            'view_count' => 0,
        ]);

        TaskRun::query()->create([
            'task_id' => (int) $task->id,
            'status' => 'completed',
            'article_id' => (int) $article->id,
            'duration_ms' => 1234,
            'meta' => [
                'generation_trace' => [
                    'task' => ['id' => (int) $task->id, 'name' => 'Trace Task'],
                    'title' => ['id' => 9, 'text' => 'Trace Source Title', 'keyword' => 'trace keyword'],
                    'model' => ['id' => 3, 'name' => 'Trace Model'],
                    'prompt' => ['id' => 4, 'name' => 'Trace Prompt', 'type' => 'content'],
                    'knowledge' => [
                        'strategy' => 'chunk_retrieval',
                        'context_length' => 456,
                        'evidence_summary' => [
                            'chunk_count' => 1,
                            'average_evidence_score' => 72,
                            'retrieval_sources' => ['fallback_embedding_hybrid'],
                        ],
                        'tag_filters' => ['行业:制造业'],
                        'chunks' => [
                            [
                                'knowledge_base_name' => 'Trace Knowledge',
                                'chunk_index' => 2,
                                'evidence_score' => 72,
                                'retrieval_source' => 'fallback_embedding_hybrid',
                                'match_reasons' => ['keyword_overlap', 'source_priority'],
                                'preview' => 'Trace knowledge preview',
                            ],
                        ],
                        'entities' => [
                            ['id' => 5, 'name' => 'Trace Entity'],
                        ],
                        'cases' => [
                            ['id' => 6, 'title' => 'Trace Case'],
                        ],
                    ],
                    'images' => [
                        ['id' => 7, 'original_name' => 'trace-image.png'],
                    ],
                    'pipeline' => [
                        [
                            'name' => 'select_sources',
                            'status' => 'completed',
                            'meta' => [
                                'title' => 'Trace Source Title',
                                'keyword' => 'trace keyword',
                            ],
                        ],
                        [
                            'name' => 'retrieve_context',
                            'status' => 'completed',
                            'meta' => [
                                'knowledge_strategy' => 'chunk_retrieval',
                                'context_length' => 456,
                            ],
                        ],
                    ],
                ],
            ],
            'started_at' => now()->subSeconds(2),
            'finished_at' => now(),
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.articles.edit', ['articleId' => (int) $article->id]))
            ->assertOk()
            ->assertSee(__('admin.article_edit.generation_trace.title'))
            ->assertSee('Trace Task')
            ->assertSee('Trace Source Title')
            ->assertSee('Trace Model')
            ->assertSee('Trace Knowledge')
            ->assertSee(__('admin.article_edit.generation_trace.evidence_score'))
            ->assertSee('fallback_embedding_hybrid')
            ->assertSee('keyword_overlap')
            ->assertSee('Trace Entity')
            ->assertSee('Trace Case')
            ->assertSee('trace-image.png')
            ->assertSee(__('admin.article_edit.quality.title'))
            ->assertSee(__('admin.article_edit.generation_trace.pipeline'))
            ->assertSee(__('admin.article_edit.generation_trace.pipeline_steps.select_sources'))
            ->assertSee(__('admin.article_edit.generation_trace.pipeline_steps.retrieve_context'));
    }
}
