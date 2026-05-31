<?php

namespace Tests\Feature;

use App\Models\AiModel;
use App\Models\Task;
use App\Models\Title;
use App\Models\TitleLibrary;
use App\Services\GeoFlow\WorkerExecutionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
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
