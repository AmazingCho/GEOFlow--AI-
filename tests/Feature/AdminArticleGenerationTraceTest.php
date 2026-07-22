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
                    'task' => ['id' => (int) $task->id, 'name' => 'Trace Task', 'generation_mode' => 'deep'],
                    'title' => ['id' => 9, 'text' => 'Trace Source Title', 'keyword' => 'trace keyword'],
                    'model' => ['id' => 3, 'name' => 'Trace Model'],
                    'prompt' => ['id' => 4, 'name' => 'Trace Prompt', 'type' => 'content'],
                    'skill_prompt' => ['id' => 5, 'name' => 'Trace Skill', 'type' => 'skill'],
                    'skill_routing' => [
                        'mode' => 'auto',
                        'intent' => 'comparison',
                        'confidence' => 84,
                        'status' => 'recommended',
                        'reason' => 'intent_match',
                        'resolved_skill_prompt_id' => 5,
                    ],
                    'style_prompt' => ['id' => 6, 'name' => 'Trace Style', 'type' => 'style'],
                    'deep_review' => [
                        'passed' => false,
                        'score' => 76,
                        'issue_codes' => ['insufficient_negative_fit'],
                        'requires_manual_review' => true,
                    ],
                    'prompt_hashes' => [
                        'master_sha256' => str_repeat('a', 64),
                        'skill_sha256' => str_repeat('b', 64),
                        'style_sha256' => str_repeat('c', 64),
                    ],
                    'knowledge' => [
                        'strategy' => 'chunk_retrieval',
                        'context_length' => 456,
                        'evidence_summary' => [
                            'chunk_count' => 1,
                            'average_evidence_score' => 72,
                            'retrieval_sources' => ['fallback_embedding_hybrid'],
                        ],
                        'tag_filters' => ['行业:制造业'],
                        'knowledge_bases' => [
                            [
                                'id' => 11,
                                'name' => 'Trace Knowledge',
                                'knowledge_type' => 'product_manual',
                                'knowledge_role' => 'primary_source',
                                'importance' => 5,
                            ],
                        ],
                        'context_package' => [
                            'selected_entity_ids' => [5],
                            'selected_case_ids' => [6],
                            'used_knowledge_base_ids' => [11],
                        ],
                        'chunks' => [
                            [
                                'knowledge_base_id' => 11,
                                'knowledge_base_name' => 'Trace Knowledge',
                                'knowledge_type' => 'product_manual',
                                'knowledge_role' => 'primary_source',
                                'importance' => 5,
                                'chunk_index' => 2,
                                'evidence_score' => 72,
                                'retrieval_source' => 'fallback_embedding_hybrid',
                                'match_reasons' => ['keyword_overlap', 'source_priority'],
                                'score_components' => [
                                    'vector' => 0.25,
                                    'lexical' => 0.5,
                                    'metadata' => 0.05,
                                ],
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
                        [
                            'name' => 'resolve_skill',
                            'status' => 'completed',
                            'meta' => [
                                'intent' => 'comparison',
                                'confidence' => 84,
                            ],
                        ],
                        [
                            'name' => 'deep_plan',
                            'status' => 'completed',
                            'meta' => [
                                'section_count' => 4,
                                'open_question_count' => 1,
                                'attempt_count' => 1,
                                'duration_ms' => 640,
                            ],
                        ],
                        [
                            'name' => 'deep_final_review',
                            'status' => 'completed',
                            'meta' => [
                                'score' => 76,
                                'passed' => false,
                                'issue_codes' => ['insufficient_negative_fit'],
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
            ->assertSee('Trace Prompt')
            ->assertSee('Trace Skill')
            ->assertSee(__('admin.article_edit.generation_trace.skill_routing'))
            ->assertSee(__('admin.article_edit.generation_trace.skill_modes.auto'))
            ->assertSee(__('admin.article_edit.generation_trace.skill_reasons.intent_match'))
            ->assertSee(__('admin.article_edit.generation_trace.generation_mode'))
            ->assertSee(__('admin.article_edit.generation_trace.generation_modes.deep'))
            ->assertSee('Trace Style')
            ->assertSee('aaaaaaaaaaaa')
            ->assertSee('bbbbbbbbbbbb')
            ->assertSee('cccccccccccc')
            ->assertSee('KB #11')
            ->assertSee(__('admin.article_edit.generation_trace.evidence_score'))
            ->assertSee(__('admin.article_edit.generation_trace.rag_explain_hint'))
            ->assertSee(__('admin.article_edit.generation_trace.knowledge_bases_used'))
            ->assertSee(__('admin.article_edit.generation_trace.used_knowledge_bases'))
            ->assertSee(__('admin.article_edit.generation_trace.retrieval_sources.fallback_embedding_hybrid'))
            ->assertSee(__('admin.article_edit.generation_trace.match_reason_labels.keyword_overlap'))
            ->assertSee(__('admin.article_edit.generation_trace.score_components.vector'))
            ->assertSee('Entity: #5')
            ->assertSee('Case: #6')
            ->assertDontSee('Trace Knowledge')
            ->assertDontSee('Trace knowledge preview')
            ->assertDontSee('Trace Entity')
            ->assertDontSee('Trace Case')
            ->assertSee('trace-image.png')
            ->assertSee(__('admin.article_edit.quality.title'))
            ->assertSee(__('admin.article_edit.generation_trace.pipeline'))
            ->assertSee(__('admin.article_edit.generation_trace.pipeline_steps.select_sources'))
            ->assertSee(__('admin.article_edit.generation_trace.pipeline_steps.retrieve_context'))
            ->assertSee(__('admin.article_edit.generation_trace.pipeline_steps.resolve_skill'))
            ->assertSee(__('admin.article_edit.generation_trace.pipeline_steps.deep_plan'))
            ->assertSee(__('admin.article_edit.generation_trace.pipeline_steps.deep_final_review'))
            ->assertSee(__('admin.article_edit.generation_trace.pipeline_meta.section_count'))
            ->assertSee(__('admin.articles.quality.items.deep_review'))
            ->assertSee('76 / 100')
            ->assertDontSee('0/0')
            ->assertDontSee('admin.article_edit.generation_trace.pipeline_steps.deep_plan')
            ->assertDontSee('admin.article_edit.generation_trace.generation_modes.deep');
    }
}
