<?php

namespace Tests\Feature;

use App\Models\AiModel;
use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Models\Task;
use App\Models\Title;
use App\Models\TitleLibrary;
use App\Services\GeoFlow\WorkerExecutionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use ReflectionProperty;
use Tests\TestCase;

class WorkerGenerationPipelineTraceTest extends TestCase
{
    use RefreshDatabase;

    public function test_generation_trace_contains_pipeline_steps(): void
    {
        $task = Task::query()->create(['name' => 'Pipeline Task']);
        $library = TitleLibrary::query()->create([
            'name' => 'Pipeline Library',
            'title_count' => 1,
        ]);
        $title = Title::query()->create([
            'library_id' => (int) $library->id,
            'title' => 'Pipeline Article',
            'keyword' => 'pipeline keyword',
            'used_count' => 0,
            'usage_count' => 0,
        ]);
        $model = AiModel::query()->create([
            'name' => 'Pipeline Model',
            'model_id' => 'pipeline-model',
            'model_type' => 'chat',
            'api_url' => 'https://ai.test/v1',
            'api_key' => 'test-key',
            'status' => 'active',
        ]);

        $trace = $this->buildGenerationTrace($task, $title, $model, [
            ['name' => 'select_sources', 'status' => 'completed', 'meta' => ['title_id' => (int) $title->id]],
            ['name' => 'retrieve_context', 'status' => 'completed', 'meta' => ['strategy' => 'hybrid_vector_lexical']],
        ]);

        $this->assertSame('select_sources', $trace['pipeline'][0]['name']);
        $this->assertSame('retrieve_context', $trace['pipeline'][1]['name']);
        $this->assertSame('Pipeline Article', $trace['title']['text']);
        $this->assertSame('Pipeline Model', $trace['model']['name']);
    }

    public function test_persisted_article_stores_context_package_metadata(): void
    {
        $task = Task::query()->create([
            'name' => 'Context Persist Task',
            'status' => 'active',
            'schedule_enabled' => 1,
            'article_limit' => 10,
            'draft_limit' => 10,
            'publish_interval' => 3600,
        ]);
        $library = TitleLibrary::query()->create(['name' => 'Context Persist Library']);
        $title = Title::query()->create([
            'library_id' => (int) $library->id,
            'title' => 'Context Persist Article',
            'keyword' => 'SJ4060',
            'used_count' => 0,
            'usage_count' => 0,
        ]);
        $author = Author::query()->create(['name' => 'Context Author']);
        $category = Category::query()->create(['name' => 'Context Category', 'slug' => 'context-category']);
        $service = app(WorkerExecutionService::class);
        $property = new ReflectionProperty($service, 'lastKnowledgeTrace');
        $property->setAccessible(true);
        $property->setValue($service, [
            'context_package' => [
                'selected_collection_id' => 88,
                'selected_entity_ids' => [11],
                'selected_case_ids' => [22],
                'used_knowledge_base_ids' => [33],
                'used_tags' => ['Product Model:SJ4060'],
                'strategy' => 'hybrid_vector_lexical',
            ],
        ]);

        $method = new ReflectionMethod($service, 'persistGeneratedDraft');
        $method->setAccessible(true);
        $articleId = $method->invoke($service, $task, [
            'titleRow' => $title,
            'author' => $author,
            'category' => $category,
            'keyword' => 'SJ4060',
            'content' => "## Context\nSJ4060 generated article.",
            'excerpt' => 'SJ4060 generated article.',
            'workflow' => ['status' => 'draft', 'review_status' => 'approved', 'published_at' => null],
            'selectedImages' => [],
        ]);

        $article = Article::query()->findOrFail((int) $articleId);
        $this->assertSame(88, (int) $article->selected_collection_id);
        $this->assertSame([11], $article->selected_entity_ids);
        $this->assertSame([22], $article->selected_case_ids);
        $this->assertSame([33], $article->used_knowledge_base_ids);
        $this->assertSame(['Product Model:SJ4060'], $article->used_tags);
        $this->assertSame('hybrid_vector_lexical', $article->context_snapshot['strategy']);
    }

    /**
     * @param  list<array<string,mixed>>  $pipelineSteps
     * @return array<string,mixed>
     */
    private function buildGenerationTrace(Task $task, Title $title, AiModel $model, array $pipelineSteps): array
    {
        $service = app(WorkerExecutionService::class);
        $method = new ReflectionMethod($service, 'buildGenerationTrace');
        $method->setAccessible(true);

        return $method->invoke(
            $service,
            $task,
            $title,
            (string) $title->keyword,
            null,
            null,
            null,
            $model,
            [],
            'Reference context',
            [],
            $pipelineSteps
        );
    }
}
