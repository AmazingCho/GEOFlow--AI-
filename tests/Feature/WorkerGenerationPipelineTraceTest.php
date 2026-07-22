<?php

namespace Tests\Feature;

use App\Models\AiModel;
use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Models\CollectionRecord;
use App\Models\Prompt;
use App\Models\Task;
use App\Models\Title;
use App\Models\TitleLibrary;
use App\Services\GeoFlow\WorkerExecutionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
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

    public function test_generation_trace_contains_deterministic_prompt_hashes_without_prompt_content(): void
    {
        $task = Task::query()->create(['name' => 'Prompt Hash Task']);
        $library = TitleLibrary::query()->create(['name' => 'Prompt Hash Library']);
        $title = Title::query()->create([
            'library_id' => (int) $library->id,
            'title' => 'Prompt Hash Article',
            'keyword' => 'prompt hash',
            'used_count' => 0,
            'usage_count' => 0,
        ]);
        $model = AiModel::query()->create([
            'name' => 'Prompt Hash Model',
            'model_id' => 'prompt-hash-model',
            'model_type' => 'chat',
            'api_url' => 'https://ai.test/v1',
            'api_key' => 'test-key',
            'status' => 'active',
        ]);
        $master = Prompt::query()->create(['name' => 'Hash Master', 'type' => 'content', 'content' => 'MASTER_SECRET_CONTENT']);
        $skill = Prompt::query()->create(['name' => 'Hash Skill', 'type' => 'skill', 'content' => 'SKILL_SECRET_CONTENT']);
        $style = Prompt::query()->create(['name' => 'Hash Style', 'type' => 'style', 'content' => 'STYLE_SECRET_CONTENT']);

        $trace = $this->buildGenerationTrace($task, $title, $model, [], $master, $skill, $style);

        $this->assertSame(hash('sha256', 'MASTER_SECRET_CONTENT'), data_get($trace, 'prompt_hashes.master_sha256'));
        $this->assertSame(hash('sha256', 'SKILL_SECRET_CONTENT'), data_get($trace, 'prompt_hashes.skill_sha256'));
        $this->assertSame(hash('sha256', 'STYLE_SECRET_CONTENT'), data_get($trace, 'prompt_hashes.style_sha256'));
        $encoded = json_encode($trace, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('MASTER_SECRET_CONTENT', (string) $encoded);
        $this->assertStringNotContainsString('SKILL_SECRET_CONTENT', (string) $encoded);
        $this->assertStringNotContainsString('STYLE_SECRET_CONTENT', (string) $encoded);
        $this->assertStringNotContainsString('Reference context', (string) $encoded);

        $skill->forceFill(['content' => 'CHANGED_SKILL_SECRET_CONTENT'])->save();
        $changedTrace = $this->buildGenerationTrace($task, $title, $model, [], $master, $skill->fresh(), $style);

        $this->assertSame(
            data_get($trace, 'prompt_hashes.master_sha256'),
            data_get($changedTrace, 'prompt_hashes.master_sha256')
        );
        $this->assertNotSame(
            data_get($trace, 'prompt_hashes.skill_sha256'),
            data_get($changedTrace, 'prompt_hashes.skill_sha256')
        );
        $this->assertSame(
            data_get($trace, 'prompt_hashes.style_sha256'),
            data_get($changedTrace, 'prompt_hashes.style_sha256')
        );
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
                'knowledge_bases' => [['id' => 33, 'name' => 'CANARY-KB-NAME']],
                'chunks' => [['knowledge_base_id' => 33, 'chunk_index' => 1, 'preview' => 'CANARY-PREVIEW']],
                'entities' => [['id' => 11, 'name' => 'CANARY-ENTITY']],
                'cases' => [['id' => 22, 'title' => 'CANARY-CASE']],
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
            'generationMode' => 'deep',
            'claimLedger' => [[
                'paragraph_sha256' => str_repeat('a', 64),
                'evidence_refs' => ['KB:33:CHUNK:1:0123456789abcdef'],
                'content' => 'CANARY-CLAIM-CONTENT',
            ]],
            'claimCoverageStatus' => 'complete',
        ]);

        $article = Article::query()->findOrFail((int) $articleId);
        $this->assertSame(88, (int) $article->selected_collection_id);
        $this->assertSame([11], $article->selected_entity_ids);
        $this->assertSame([22], $article->selected_case_ids);
        $this->assertSame([33], $article->used_knowledge_base_ids);
        $this->assertSame(['Product Model:SJ4060'], $article->used_tags);
        $this->assertSame('hybrid_vector_lexical', $article->context_snapshot['strategy']);
        $this->assertStringNotContainsString('CANARY-', json_encode($article->context_snapshot, JSON_THROW_ON_ERROR));
    }

    public function test_persisted_article_uses_task_collection_when_trace_has_no_collection(): void
    {
        $collection = CollectionRecord::query()->create([
            'name' => 'Task Article Collection',
            'slug' => 'task-article-collection',
            'status' => 'active',
        ]);
        $task = Task::query()->create([
            'name' => 'Task Collection Persist Task',
            'collection_id' => (int) $collection->id,
            'status' => 'active',
            'schedule_enabled' => 1,
            'article_limit' => 10,
            'draft_limit' => 10,
            'publish_interval' => 3600,
        ]);
        $library = TitleLibrary::query()->create(['name' => 'Task Collection Library']);
        $title = Title::query()->create([
            'library_id' => (int) $library->id,
            'title' => 'Task Collection Article',
            'keyword' => 'collection keyword',
            'used_count' => 0,
            'usage_count' => 0,
        ]);
        $author = Author::query()->create(['name' => 'Task Collection Author']);
        $category = Category::query()->create(['name' => 'Task Collection Category', 'slug' => 'task-collection-category']);
        $service = app(WorkerExecutionService::class);
        $property = new ReflectionProperty($service, 'lastKnowledgeTrace');
        $property->setAccessible(true);
        $property->setValue($service, ['context_package' => ['strategy' => 'empty_trace']]);

        $method = new ReflectionMethod($service, 'persistGeneratedDraft');
        $method->setAccessible(true);
        $articleId = $method->invoke($service, $task, [
            'titleRow' => $title,
            'author' => $author,
            'category' => $category,
            'keyword' => 'collection keyword',
            'content' => 'Task collection generated article.',
            'excerpt' => 'Task collection generated article.',
            'workflow' => ['status' => 'draft', 'review_status' => 'approved', 'published_at' => null],
            'selectedImages' => [],
        ]);

        $article = Article::query()->findOrFail((int) $articleId);
        $this->assertSame((int) $collection->id, (int) $article->selected_collection_id);
    }

    public function test_persistence_rejects_any_unstripped_evidence_comment(): void
    {
        $task = Task::query()->create([
            'name' => 'Marker guard task',
            'status' => 'active',
            'schedule_enabled' => 1,
            'article_limit' => 10,
            'draft_limit' => 10,
            'publish_interval' => 3600,
        ]);
        $library = TitleLibrary::query()->create(['name' => 'Marker guard library']);
        $title = Title::query()->create([
            'library_id' => (int) $library->id,
            'title' => 'Marker guard article',
            'keyword' => 'marker',
            'used_count' => 0,
            'usage_count' => 0,
        ]);
        $method = new ReflectionMethod(app(WorkerExecutionService::class), 'persistGeneratedDraft');
        $method->setAccessible(true);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('证据标记');

        $method->invoke(app(WorkerExecutionService::class), $task, [
            'titleRow' => $title,
            'author' => null,
            'category' => null,
            'keyword' => 'marker',
            'content' => "Specific claim. <!--\u{200B} evidence_label:PRIVATE-SOURCE-LABEL -->",
            'excerpt' => 'Specific claim.',
            'workflow' => ['status' => 'draft', 'review_status' => 'pending', 'published_at' => null],
            'selectedImages' => [],
            'generationMode' => 'standard',
        ]);
    }

    public function test_persistence_rejects_evidence_comment_obfuscated_with_any_unicode_format_character(): void
    {
        $task = Task::query()->create([
            'name' => 'Unicode marker guard task',
            'status' => 'active',
            'schedule_enabled' => 1,
            'article_limit' => 10,
            'draft_limit' => 10,
            'publish_interval' => 3600,
        ]);
        $library = TitleLibrary::query()->create(['name' => 'Unicode marker guard library']);
        $title = Title::query()->create([
            'library_id' => (int) $library->id,
            'title' => 'Unicode marker guard article',
            'keyword' => 'marker',
            'used_count' => 0,
            'usage_count' => 0,
        ]);
        $method = new ReflectionMethod(app(WorkerExecutionService::class), 'persistGeneratedDraft');
        $method->setAccessible(true);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('证据标记');

        $method->invoke(app(WorkerExecutionService::class), $task, [
            'titleRow' => $title,
            'author' => null,
            'category' => null,
            'keyword' => 'marker',
            'content' => "Specific claim. <!-- evi\u{2061}dence:RAW-EVIDENCE api_key=SECRET -->",
            'excerpt' => 'Specific claim.',
            'workflow' => ['status' => 'draft', 'review_status' => 'pending', 'published_at' => null],
            'selectedImages' => [],
            'generationMode' => 'standard',
        ]);
    }

    /**
     * @param  list<array<string,mixed>>  $pipelineSteps
     * @return array<string,mixed>
     */
    private function buildGenerationTrace(
        Task $task,
        Title $title,
        AiModel $model,
        array $pipelineSteps,
        ?Prompt $prompt = null,
        ?Prompt $skillPrompt = null,
        ?Prompt $stylePrompt = null
    ): array {
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
            $prompt,
            $skillPrompt,
            $stylePrompt,
            $model,
            [],
            'Reference context',
            [],
            $pipelineSteps
        );
    }
}
