<?php

namespace Tests\Feature;

use App\Jobs\ProcessGeoFlowTaskJob;
use App\Models\Admin;
use App\Models\AiModel;
use App\Models\Article;
use App\Models\Author;
use App\Models\CaseRecord;
use App\Models\Category;
use App\Models\KnowledgeBase;
use App\Models\KnowledgeChunk;
use App\Models\Prompt;
use App\Models\Task;
use App\Models\TaskRun;
use App\Models\Title;
use App\Models\TitleLibrary;
use App\Services\GeoFlow\ArticleEvidencePackage;
use App\Services\GeoFlow\ArticleGenerationProtocolException;
use App\Services\GeoFlow\JobQueueService;
use App\Services\GeoFlow\RagRetrievalService;
use App\Services\GeoFlow\TaskLifecycleService;
use App\Services\GeoFlow\WorkerExecutionService;
use App\Support\GeoFlow\ApiKeyCrypto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use ReflectionProperty;
use RuntimeException;
use Tests\TestCase;

class WorkerDeepGenerationPipelineTest extends TestCase
{
    use RefreshDatabase;

    private string $evidenceId;

    private string $privateEvidenceCanary = 'CANARY-PRIVATE-DEEP-EVIDENCE';

    public function test_deep_task_runs_plan_draft_review_and_persists_approved_article(): void
    {
        [$task, $model] = $this->task(['need_review' => 0]);
        $this->fakeFrozenEvidence();
        Http::fake([
            'https://ai.test/v1/chat/completions' => Http::sequence()
                ->push($this->completion(json_encode($this->plan())))
                ->push($this->completion($this->article('Initial')))
                ->push($this->completion(json_encode($this->review(true, 92)))),
        ]);

        $result = app(WorkerExecutionService::class)->executeTask((int) $task->id);

        $article = Article::query()->findOrFail((int) $result['article_id']);
        $this->assertSame('approved', $article->review_status);
        $this->assertStringContainsString('Initial', $article->content);
        $this->assertStringNotContainsString('<!-- evidence:', $article->content);
        $this->assertSame('complete', data_get($article->context_snapshot, 'claim_coverage_status'));
        $this->assertSame(hash('sha256', $article->content), data_get($article->context_snapshot, 'grounding_gate.content_sha256'));
        $this->assertSame([$this->evidenceId], data_get($article->context_snapshot, 'claim_ledger.0.evidence_refs'));
        $this->assertStringNotContainsString(
            $this->privateEvidenceCanary,
            json_encode($result['meta'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)
        );
        $this->assertSame('deep', data_get($result, 'meta.generation_mode'));
        $this->assertSame('draft_ready', data_get($result, 'meta.generation_outcome'));
        $this->assertSame('deep-v2.4-structured-plan-1', data_get($result, 'meta.generation_trace.deep_protocol_version'));
        $this->assertSame(
            ['deep_plan', 'deep_draft', 'deep_review'],
            collect(data_get($result, 'meta.generation_trace.pipeline', []))
                ->pluck('name')
                ->filter(fn (string $name): bool => str_starts_with($name, 'deep_'))
                ->values()
                ->all()
        );
        $this->assertSame(3, (int) $model->fresh()->used_today);
        $this->assertCount(3, Http::recorded());
    }

    public function test_limited_evidence_deep_task_persists_only_a_pending_review_draft(): void
    {
        [$task] = $this->task(['need_review' => 0]);
        $this->fakeFrozenEvidence();
        $plan = $this->plan();
        $plan['evidence_sufficiency'] = 'limited';
        $plan['answer_mode'] = 'evidence_limited';
        Http::fake([
            'https://ai.test/v1/chat/completions' => Http::sequence()
                ->push($this->completion(json_encode($plan)))
                ->push($this->completion($this->article('Limited evidence answer')))
                ->push($this->completion(json_encode($this->review(true, 92)))),
        ]);

        $result = app(WorkerExecutionService::class)->executeTask((int) $task->id);

        $article = Article::query()->findOrFail((int) $result['article_id']);
        $this->assertSame('pending', $article->review_status);
        $this->assertSame('limited', data_get($article->context_snapshot, 'evidence_sufficiency'));
        $this->assertSame('pending_review', data_get($article->context_snapshot, 'grounding_gate.outcome'));
        $this->assertSame('limited', data_get($result, 'meta.generation_trace.claim_provenance.evidence_sufficiency'));
        $this->assertTrue((bool) data_get($result, 'meta.generation_trace.deep_review.requires_manual_review'));
        $this->assertSame('draft_review_required', data_get($result, 'meta.generation_outcome'));
    }

    public function test_deep_model_requests_use_only_generation_safe_context(): void
    {
        [$task] = $this->task(['need_review' => 0]);
        $safeEvidence = app(ArticleEvidencePackage::class)->make(
            'knowledge_chunk',
            1,
            'Approved manual',
            'VERIFIED-DEEP-CONTEXT',
            0
        );
        $caseEvidence = app(ArticleEvidencePackage::class)->make(
            'case',
            9,
            'Private customer case',
            'CANARY-UNVERIFIED-CASE-CONTENT'
        );
        $this->evidenceId = $safeEvidence['id'];
        $this->mock(RagRetrievalService::class)
            ->shouldReceive('retrieveForTask')
            ->once()
            ->andReturn([
                'context' => "Evidence ID: {$safeEvidence['id']}\nVERIFIED-DEEP-CONTEXT\n\n"
                    ."Evidence ID: {$caseEvidence['id']}\nCANARY-UNVERIFIED-CASE-CONTENT",
                'generation_context' => "Evidence ID: {$safeEvidence['id']}\nVERIFIED-DEEP-CONTEXT",
                'evidence_package' => [$safeEvidence, $caseEvidence],
                'trace' => [
                    'strategy' => 'safe_context_test',
                    'chunks' => [],
                    'entities' => [],
                    'cases' => [['id' => 9, 'evidence_id' => $caseEvidence['id']]],
                    'context_length' => 100,
                    'generation_context_length' => 50,
                ],
            ]);
        Http::fake([
            'https://ai.test/v1/chat/completions' => Http::sequence()
                ->push($this->completion(json_encode($this->plan())))
                ->push($this->completion($this->article('Safe context')))
                ->push($this->completion(json_encode($this->review(true, 92)))),
        ]);

        $result = app(WorkerExecutionService::class)->executeTask((int) $task->id);

        $this->assertNotNull($result['article_id']);
        foreach (Http::recorded() as [$request]) {
            $this->assertStringContainsString('VERIFIED-DEEP-CONTEXT', $request->body());
            $this->assertStringNotContainsString('CANARY-UNVERIFIED-CASE-CONTENT', $request->body());
        }
    }

    public function test_real_rag_keeps_selected_case_content_out_of_all_deep_model_requests(): void
    {
        $knowledgeContent = 'VERIFIED-REAL-RAG-CONTEXT explains approved selection constraints.';
        $knowledgeBase = KnowledgeBase::query()->create([
            'name' => 'Approved real RAG manual',
            'description' => '',
            'content' => $knowledgeContent,
            'character_count' => mb_strlen($knowledgeContent, 'UTF-8'),
            'file_type' => 'markdown',
            'knowledge_type' => 'product_manual',
            'knowledge_role' => 'primary_source',
            'importance' => 5,
            'status' => 'active',
            'word_count' => mb_strlen($knowledgeContent, 'UTF-8'),
        ]);
        KnowledgeChunk::query()->create([
            'knowledge_base_id' => (int) $knowledgeBase->id,
            'chunk_index' => 0,
            'content' => $knowledgeContent,
            'content_hash' => hash('sha256', $knowledgeContent),
            'chunk_title' => (string) $knowledgeBase->name,
            'section_path' => (string) $knowledgeBase->name,
            'chunk_strategy' => 'test',
            'metadata_json' => '{}',
            'source_hash' => hash('sha256', $knowledgeBase->name.'|'.$knowledgeContent),
            'token_count' => 20,
            'embedding_json' => '[]',
            'embedding_model_id' => null,
            'embedding_dimensions' => 0,
            'embedding_provider' => '',
            'embedding_vector' => null,
        ]);
        $caseRecord = CaseRecord::query()->create([
            'title' => 'Private customer project',
            'case_type' => 'customer_case',
            'summary' => 'CASE_CANARY_REAL_RAG_MUST_NOT_REACH_MODEL',
            'solution' => 'Private solution details.',
            'result' => 'Private outcome details.',
        ]);
        [$task] = $this->task([
            'knowledge_base_id' => (int) $knowledgeBase->id,
            'case_filter' => (string) $caseRecord->id,
            'need_review' => 0,
        ]);
        $this->evidenceId = app(ArticleEvidencePackage::class)->make(
            'knowledge_chunk',
            (int) $knowledgeBase->id,
            (string) $knowledgeBase->name,
            $knowledgeContent,
            0
        )['id'];
        Http::fake([
            'https://ai.test/v1/chat/completions' => Http::sequence()
                ->push($this->completion(json_encode($this->plan())))
                ->push($this->completion($this->article('Real RAG safe context')))
                ->push($this->completion(json_encode($this->review(true, 92)))),
        ]);

        $result = app(WorkerExecutionService::class)->executeTask((int) $task->id);

        $this->assertNotNull($result['article_id']);
        $this->assertCount(3, Http::recorded());
        foreach (Http::recorded() as [$request]) {
            $this->assertStringContainsString('VERIFIED-REAL-RAG-CONTEXT', $request->body());
            $this->assertStringNotContainsString('CASE_CANARY_REAL_RAG_MUST_NOT_REACH_MODEL', $request->body());
            $this->assertStringNotContainsString('Private outcome details.', $request->body());
        }
    }

    public function test_deep_worker_rejects_protected_case_content_returned_by_the_model(): void
    {
        [$task, $model] = $this->task(['need_review' => 0]);
        $safeEvidence = app(ArticleEvidencePackage::class)->make(
            'knowledge_chunk',
            1,
            'Approved manual',
            'VERIFIED-DEEP-CONTEXT',
            0
        );
        $caseEvidence = app(ArticleEvidencePackage::class)->make(
            'case',
            9,
            'Private customer case',
            'CASE_CANARY_DO_NOT_PERSIST_ALPHA'
        );
        $this->evidenceId = $safeEvidence['id'];
        $this->mock(RagRetrievalService::class)
            ->shouldReceive('retrieveForTask')
            ->once()
            ->andReturn([
                'context' => "Evidence ID: {$safeEvidence['id']}\nVERIFIED-DEEP-CONTEXT\n\n"
                    ."Evidence ID: {$caseEvidence['id']}\nCASE_CANARY_DO_NOT_PERSIST_ALPHA",
                'generation_context' => "Evidence ID: {$safeEvidence['id']}\nVERIFIED-DEEP-CONTEXT",
                'evidence_package' => [$safeEvidence, $caseEvidence],
                'trace' => ['strategy' => 'protected_output_test', 'chunks' => [], 'entities' => [], 'cases' => []],
            ]);
        $leakingDraft = "## Draft\n\n"
            .str_repeat('Approved generic selection guidance remains intentionally cautious. ', 8)
            .'CASE_CANARY_DO_NOT_PERSIST_ALPHA.';
        Http::fake([
            'https://ai.test/v1/chat/completions' => Http::sequence()
                ->push($this->completion(json_encode($this->plan())))
                ->push($this->completion($leakingDraft)),
        ]);

        try {
            app(WorkerExecutionService::class)->executeTask((int) $task->id);
            $this->fail('Protected Case content returned by a model must never be persisted.');
        } catch (InvalidArgumentException|RuntimeException $exception) {
            $this->assertStringContainsString('受限证据', $exception->getMessage());
            $this->assertStringNotContainsString('CASE_CANARY_DO_NOT_PERSIST_ALPHA', $exception->getMessage());
        }

        $this->assertDatabaseCount('articles', 0);
        $this->assertSame(2, (int) $model->fresh()->used_today);
        $this->assertSame(0, (int) Title::query()->firstOrFail()->used_count);
    }

    public function test_deep_worker_fails_closed_when_rag_omits_generation_safe_context(): void
    {
        [$task, $model] = $this->task(['need_review' => 0]);
        $safeEvidence = app(ArticleEvidencePackage::class)->make(
            'knowledge_chunk',
            1,
            'Approved manual',
            'VERIFIED-DEEP-CONTEXT',
            0
        );
        $caseEvidence = app(ArticleEvidencePackage::class)->make(
            'case',
            9,
            'Private customer case',
            'CANARY-UNVERIFIED-CASE-CONTENT'
        );
        $this->mock(RagRetrievalService::class)
            ->shouldReceive('retrieveForTask')
            ->once()
            ->andReturn([
                'context' => "Evidence ID: {$safeEvidence['id']}\nVERIFIED-DEEP-CONTEXT\n\n"
                    ."Evidence ID: {$caseEvidence['id']}\nCANARY-UNVERIFIED-CASE-CONTENT",
                'evidence_package' => [$safeEvidence, $caseEvidence],
                'trace' => ['strategy' => 'legacy_context_without_safe_contract'],
            ]);
        Http::preventStrayRequests();

        try {
            app(WorkerExecutionService::class)->executeTask((int) $task->id);
            $this->fail('Deep generation must stop when generation_context is absent.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('安全证据上下文', $exception->getMessage());
            $this->assertStringNotContainsString('CANARY-UNVERIFIED-CASE-CONTENT', $exception->getMessage());
        }

        $this->assertDatabaseCount('articles', 0);
        $this->assertSame(0, (int) $model->fresh()->used_today);
        $this->assertSame(0, (int) Title::query()->firstOrFail()->used_count);
        $this->assertCount(0, Http::recorded());
    }

    public function test_manual_case_study_skill_is_blocked_without_publishable_case_evidence(): void
    {
        $caseStudySkill = Prompt::query()->create([
            'name' => 'Deep Worker Case Study Skill',
            'type' => 'skill',
            'intent_key' => 'case_study',
            'content' => 'Use only approved case evidence and preserve privacy boundaries.',
        ]);
        $caseRecord = CaseRecord::query()->create([
            'title' => 'Unverified customer result',
            'case_type' => 'customer_case',
            'solution' => 'A configured dispensing process was tested.',
            'result' => 'The process met the internal target.',
        ]);
        [$task] = $this->task([
            'need_review' => 0,
            'skill_selection_mode' => 'manual',
            'skill_prompt_id' => (int) $caseStudySkill->id,
            'case_filter' => (string) $caseRecord->id,
        ]);
        Http::preventStrayRequests();

        try {
            app(WorkerExecutionService::class)->executeTask((int) $task->id);
            $this->fail('Blocked Case Study generation should stop before any model request.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Case Study', $exception->getMessage());
            $this->assertStringContainsString('case_publication_approval_missing', $exception->getMessage());
        }

        $this->assertDatabaseCount('articles', 0);
        $this->assertCount(0, Http::recorded());
    }

    public function test_deep_case_study_gate_runs_before_rag_can_call_an_embedding_provider(): void
    {
        $caseStudySkill = Prompt::query()->create([
            'name' => 'Preflight Case Study Skill',
            'type' => 'skill',
            'intent_key' => 'case_study',
            'content' => 'Use only approved case evidence.',
        ]);
        $caseRecord = CaseRecord::query()->create([
            'title' => 'Private case preflight',
            'case_type' => 'customer_case',
            'solution' => 'A private process was configured.',
            'result' => 'A private result was recorded.',
        ]);
        $knowledgeBase = KnowledgeBase::query()->create([
            'name' => 'Preflight knowledge',
            'content' => 'This source must not be retrieved after a deterministic Case gate failure.',
            'status' => 'active',
        ]);
        $embeddingModel = AiModel::query()->create([
            'name' => 'Preflight Embedding Model',
            'model_id' => 'preflight-embedding-model',
            'model_type' => 'embedding',
            'api_url' => 'https://embedding.test/v1',
            'api_key' => app(ApiKeyCrypto::class)->encrypt('embedding-key'),
            'used_today' => 0,
            'total_used' => 0,
            'status' => 'active',
        ]);
        KnowledgeChunk::query()->create([
            'knowledge_base_id' => (int) $knowledgeBase->id,
            'chunk_index' => 0,
            'content' => 'A real-embedding chunk that would trigger query embedding retrieval.',
            'content_hash' => hash('sha256', 'A real-embedding chunk that would trigger query embedding retrieval.'),
            'embedding_json' => json_encode([0.1, 0.2, 0.3], JSON_THROW_ON_ERROR),
            'embedding_model_id' => (int) $embeddingModel->id,
            'embedding_dimensions' => 3,
            'embedding_provider' => 'embedding.test',
        ]);
        [$task, $chatModel] = $this->task([
            'skill_selection_mode' => 'manual',
            'skill_prompt_id' => (int) $caseStudySkill->id,
            'case_filter' => (string) $caseRecord->id,
            'knowledge_base_id' => (int) $knowledgeBase->id,
        ]);
        $this->mock(RagRetrievalService::class)
            ->shouldNotReceive('retrieveForTask');
        Http::preventStrayRequests();

        try {
            app(WorkerExecutionService::class)->executeTask((int) $task->id);
            $this->fail('The deterministic Case gate must run before RAG or any provider request.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('case_publication_approval_missing', $exception->getMessage());
        }

        $this->assertCount(0, Http::recorded());
        $this->assertSame(0, (int) $embeddingModel->fresh()->used_today);
        $this->assertSame(0, (int) $chatModel->fresh()->used_today);
        $this->assertDatabaseCount('articles', 0);
    }

    public function test_deep_case_study_title_is_blocked_when_skill_mode_is_none(): void
    {
        $caseRecord = CaseRecord::query()->create([
            'title' => 'Unverified customer result',
            'case_type' => 'customer_case',
            'solution' => 'A configured dispensing process was tested.',
            'result' => 'The process met the internal target.',
        ]);
        [$task, $model] = $this->task([
            'need_review' => 0,
            'skill_selection_mode' => 'none',
            'case_filter' => (string) $caseRecord->id,
        ]);
        Title::query()->where('library_id', (int) $task->title_library_id)->update([
            'title' => 'Customer Alpha Case Study',
            'keyword' => 'customer case study',
        ]);
        Http::preventStrayRequests();

        try {
            app(WorkerExecutionService::class)->executeTask((int) $task->id);
            $this->fail('A Deep case-study title must not bypass evidence governance when Skills are disabled.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('case_publication_approval_missing', $exception->getMessage());
        }

        $this->assertDatabaseCount('articles', 0);
        $this->assertSame(0, (int) $model->fresh()->used_today);
        $this->assertSame(0, (int) Title::query()->firstOrFail()->used_count);
        $this->assertCount(0, Http::recorded());
    }

    public function test_deep_case_study_title_is_blocked_when_manual_skill_intent_disagrees(): void
    {
        $buyingSkill = Prompt::query()->create([
            'name' => 'Buying Guide Skill',
            'type' => 'skill',
            'intent_key' => 'buying_guide',
            'content' => 'Explain buyer selection criteria.',
        ]);
        $caseRecord = CaseRecord::query()->create([
            'title' => 'Unverified customer result',
            'case_type' => 'customer_case',
            'solution' => 'A configured dispensing process was tested.',
            'result' => 'The process met the internal target.',
        ]);
        [$task, $model] = $this->task([
            'need_review' => 0,
            'skill_selection_mode' => 'manual',
            'skill_prompt_id' => (int) $buyingSkill->id,
            'case_filter' => (string) $caseRecord->id,
        ]);
        Title::query()->where('library_id', (int) $task->title_library_id)->update([
            'title' => 'Customer Alpha Case Study',
            'keyword' => 'customer case study',
        ]);
        Http::preventStrayRequests();

        try {
            app(WorkerExecutionService::class)->executeTask((int) $task->id);
            $this->fail('A manual non-Case Skill must not bypass Deep case-study governance.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('case_publication_approval_missing', $exception->getMessage());
        }

        $this->assertDatabaseCount('articles', 0);
        $this->assertSame(0, (int) $model->fresh()->used_today);
        $this->assertSame(0, (int) Title::query()->firstOrFail()->used_count);
        $this->assertCount(0, Http::recorded());
    }

    public function test_deep_unclassified_manual_skill_cannot_use_selected_case_evidence(): void
    {
        $customSkill = Prompt::query()->create([
            'name' => 'Project Narrative',
            'type' => 'skill',
            'content' => 'Write a customer project narrative using the selected Case.',
        ]);
        $caseRecord = CaseRecord::query()->create([
            'title' => 'Unverified private customer project',
            'case_type' => 'customer_case',
            'solution' => 'A configured process was tested.',
            'result' => 'The internal target was met.',
        ]);
        [$task, $model] = $this->task([
            'skill_selection_mode' => 'manual',
            'skill_prompt_id' => (int) $customSkill->id,
            'case_filter' => (string) $caseRecord->id,
        ]);
        Http::preventStrayRequests();

        try {
            app(WorkerExecutionService::class)->executeTask((int) $task->id);
            $this->fail('An unclassified Deep Skill with selected Case evidence must fail closed.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('case_skill_intent_unclassified', $exception->getMessage());
        }

        $this->assertDatabaseCount('articles', 0);
        $this->assertSame(0, (int) $model->fresh()->used_today);
        $this->assertCount(0, Http::recorded());
    }

    public function test_deep_unclassified_manual_skill_is_blocked_before_rag_without_selected_case(): void
    {
        $customSkill = Prompt::query()->create([
            'name' => 'Project Narrative',
            'type' => 'skill',
            'content' => 'Write a customer success story using project context.',
        ]);
        [$task, $model] = $this->task([
            'skill_selection_mode' => 'manual',
            'skill_prompt_id' => (int) $customSkill->id,
            'case_filter' => null,
        ]);
        $this->mock(RagRetrievalService::class)
            ->shouldNotReceive('retrieveForTask');
        Http::preventStrayRequests();

        try {
            app(WorkerExecutionService::class)->executeTask((int) $task->id);
            $this->fail('An unclassified manual Deep Skill must fail closed before retrieval.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('manual_skill_intent_unclassified', $exception->getMessage());
        }

        $this->assertDatabaseCount('articles', 0);
        $this->assertSame(0, (int) $model->fresh()->used_today);
        $this->assertSame(0, (int) Title::query()->firstOrFail()->used_count);
        $this->assertCount(0, Http::recorded());
    }

    public function test_standard_case_study_title_with_none_mode_remains_operational(): void
    {
        $caseRecord = CaseRecord::query()->create([
            'title' => 'Unverified customer result',
            'case_type' => 'customer_case',
            'solution' => 'A configured dispensing process was tested.',
            'result' => 'The process met the internal target.',
        ]);
        [$task, $model] = $this->task([
            'generation_mode' => 'standard',
            'need_review' => 0,
            'skill_selection_mode' => 'none',
            'case_filter' => (string) $caseRecord->id,
        ]);
        Title::query()->where('library_id', (int) $task->title_library_id)->update([
            'title' => 'Customer Alpha Case Study',
            'keyword' => 'customer case study',
        ]);
        $this->fakeFrozenEvidence();
        Http::fake([
            'https://ai.test/v1/chat/completions' => Http::response($this->completion(
                str_repeat('A cautious general explanation that does not assert customer results. ', 16)
            )),
        ]);

        $result = app(WorkerExecutionService::class)->executeTask((int) $task->id);

        $article = Article::query()->findOrFail((int) $result['article_id']);
        $this->assertSame('pending', $article->review_status);
        $this->assertSame('none', data_get($result, 'meta.generation_trace.skill_routing.mode'));
        $this->assertSame('disabled', data_get($result, 'meta.generation_trace.skill_routing.status'));
        $this->assertNull(data_get($result, 'meta.generation_trace.skill_prompt'));
        $this->assertSame(1, (int) $model->fresh()->used_today);
        $this->assertCount(1, Http::recorded());
    }

    public function test_standard_unclassified_manual_skill_with_case_evidence_is_saved_pending(): void
    {
        $customSkill = Prompt::query()->create([
            'name' => 'Project Narrative',
            'type' => 'skill',
            'content' => 'Write a customer project narrative using the selected Case.',
        ]);
        $caseRecord = CaseRecord::query()->create([
            'title' => 'Unverified standard project',
            'case_type' => 'customer_case',
            'solution' => 'A configured process was tested.',
            'result' => 'The internal target was met.',
        ]);
        [$task] = $this->task([
            'generation_mode' => 'standard',
            'need_review' => 0,
            'skill_selection_mode' => 'manual',
            'skill_prompt_id' => (int) $customSkill->id,
            'case_filter' => (string) $caseRecord->id,
        ]);
        $this->fakeFrozenEvidence();
        Http::fake([
            'https://ai.test/v1/chat/completions' => Http::response($this->completion(
                str_repeat('A cautious project explanation without asserting customer results. ', 16)
            )),
        ]);

        $result = app(WorkerExecutionService::class)->executeTask((int) $task->id);

        $article = Article::query()->findOrFail((int) $result['article_id']);
        $this->assertSame('pending', $article->review_status);
        $this->assertSame((int) $customSkill->id, data_get($result, 'meta.generation_trace.skill_prompt.id'));
        $this->assertSame('governance_pending', data_get($result, 'meta.generation_trace.skill_routing.status'));
        $this->assertSame('case_skill_intent_unclassified', data_get($result, 'meta.generation_trace.skill_routing.reason'));
    }

    public function test_deep_worker_fails_closed_when_rag_omits_structured_evidence_package(): void
    {
        [$task, $model] = $this->task(['need_review' => 0]);
        $this->mock(RagRetrievalService::class)
            ->shouldReceive('retrieveForTask')
            ->once()
            ->andReturn([
                'context' => 'Legacy RAG context only.',
                'generation_context' => 'Legacy RAG context only.',
                'trace' => ['strategy' => 'legacy'],
            ]);
        Http::preventStrayRequests();

        try {
            app(WorkerExecutionService::class)->executeTask((int) $task->id);
            $this->fail('Deep generation must stop when the structured evidence package is absent.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('结构化证据包', $exception->getMessage());
        }

        $this->assertDatabaseCount('articles', 0);
        $this->assertSame(0, (int) $model->fresh()->used_today);
        $this->assertSame(0, (int) Title::query()->firstOrFail()->used_count);
        $this->assertCount(0, Http::recorded());
    }

    public function test_worker_that_lost_queue_ownership_cannot_persist_generated_article(): void
    {
        [$task, $model] = $this->task([
            'generation_mode' => 'standard',
            'need_review' => 0,
        ]);
        $this->fakeFrozenEvidence();
        Http::fake([
            'https://ai.test/v1/chat/completions' => Http::response($this->completion(
                str_repeat('A complete evidence-aware buyer explanation. ', 20)
            )),
        ]);

        try {
            app(WorkerExecutionService::class)->executeTask((int) $task->id, static fn (): bool => false);
            $this->fail('A superseded worker must not persist its generated article.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('所有权已失效', $exception->getMessage());
        }

        $this->assertDatabaseCount('articles', 0);
        $this->assertSame(0, (int) Title::query()->firstOrFail()->used_count);
        $this->assertSame(1, (int) $model->fresh()->used_today);
    }

    public function test_standard_draft_completes_through_the_real_queue_job_entrypoint(): void
    {
        Queue::fake();
        [$task, $model] = $this->task([
            'generation_mode' => 'standard',
            'need_review' => 0,
        ]);
        $this->fakeFrozenEvidence();
        Http::fake([
            'https://ai.test/v1/chat/completions' => Http::response($this->completion(
                str_repeat('A complete evidence-aware buyer explanation. ', 20)
            )),
        ]);
        $queueService = app(JobQueueService::class);
        $runId = $queueService->enqueueTaskJob((int) $task->id);
        $this->assertNotNull($runId);
        $run = TaskRun::query()->findOrFail((int) $runId);
        $dispatchToken = (string) data_get($run->meta, 'dispatch_token');
        $executionToken = '20202020-2020-4020-8020-202020202020';

        (new ProcessGeoFlowTaskJob((int) $run->id, $dispatchToken, $executionToken))
            ->handle($queueService, app(WorkerExecutionService::class));

        $run->refresh();
        $this->assertSame('completed', $run->status);
        $this->assertSame('completed', data_get($run->meta, 'dispatch_state'));
        $this->assertSame(
            $queueService->claimTokenForDelivery($executionToken, 1),
            data_get($run->meta, 'claim_token')
        );
        $this->assertNotNull($run->article_id);
        $this->assertDatabaseHas('articles', [
            'id' => (int) $run->article_id,
            'task_id' => (int) $task->id,
        ]);
        $this->assertSame(1, (int) $model->fresh()->used_today);
    }

    public function test_standard_blocked_gate_saves_pending_draft_and_preserves_task_counters(): void
    {
        [$task, $model] = $this->task([
            'generation_mode' => 'standard',
            'need_review' => 0,
        ]);
        $this->fakeFrozenEvidence();
        Http::fake([
            'https://ai.test/v1/chat/completions' => Http::response($this->completion(
                'The system handles 500 kg. '.str_repeat('A complete buyer explanation follows. ', 20)
            )),
        ]);

        $result = app(WorkerExecutionService::class)->executeTask((int) $task->id);

        $article = Article::query()->findOrFail((int) $result['article_id']);
        $task->refresh();
        $this->assertSame('pending', $article->review_status);
        $this->assertSame('blocked', data_get($article->context_snapshot, 'grounding_gate.outcome'));
        $this->assertSame('blocked', data_get($result, 'meta.generation_trace.grounding_gate.outcome'));
        $this->assertSame(1, (int) $task->created_count);
        $this->assertSame(1, (int) $task->loop_count);
        $this->assertSame(1, (int) Title::query()->firstOrFail()->used_count);
        $this->assertSame(1, (int) $model->fresh()->used_today);
    }

    public function test_standard_case_study_preserves_selected_skill_and_saves_pending_draft(): void
    {
        $caseStudySkill = Prompt::query()->create([
            'name' => 'Standard Worker Case Study Skill',
            'type' => 'skill',
            'intent_key' => 'case_study',
            'content' => 'CANARY-BLOCKED-CASE-SKILL',
        ]);
        $caseRecord = CaseRecord::query()->create([
            'title' => 'Unverified standard case',
            'case_type' => 'customer_case',
            'solution' => 'A configured dispensing process was tested.',
            'result' => 'The process met the internal target.',
        ]);
        [$task, $model] = $this->task([
            'generation_mode' => 'standard',
            'need_review' => 0,
            'skill_selection_mode' => 'manual',
            'skill_prompt_id' => (int) $caseStudySkill->id,
            'case_filter' => (string) $caseRecord->id,
        ]);
        $this->fakeFrozenEvidence();
        Http::fake([
            'https://ai.test/v1/chat/completions' => Http::response($this->completion(
                str_repeat('A general buyer decision explanation without case claims. ', 16)
            )),
        ]);

        $result = app(WorkerExecutionService::class)->executeTask((int) $task->id);

        $article = Article::query()->findOrFail((int) $result['article_id']);
        $this->assertSame('pending', $article->review_status);
        $this->assertSame('governance_pending', data_get($result, 'meta.generation_trace.skill_routing.status'));
        $this->assertSame('case_publication_approval_missing', data_get($result, 'meta.generation_trace.skill_routing.reason'));
        $this->assertSame((int) $caseStudySkill->id, data_get($result, 'meta.generation_trace.skill_prompt.id'));
        $this->assertStringNotContainsString('CANARY-BLOCKED-CASE-SKILL', $article->content);
        Http::assertSent(fn ($request): bool => str_contains($request->body(), 'CANARY-BLOCKED-CASE-SKILL'));
        $this->assertSame(1, (int) $model->fresh()->used_today);
        $this->assertCount(1, Http::recorded());
    }

    public function test_second_non_blocking_review_failure_saves_pending_draft_with_issue_codes(): void
    {
        [$task] = $this->task(['need_review' => 0]);
        $this->fakeFrozenEvidence();
        Http::fake([
            'https://ai.test/v1/chat/completions' => Http::sequence()
                ->push($this->completion(json_encode($this->plan())))
                ->push($this->completion($this->article('Initial')))
                ->push($this->completion(json_encode($this->review(false, 65, ['weak_evidence_link']))))
                ->push($this->completion($this->article('Revised')))
                ->push($this->completion(json_encode($this->review(false, 74, ['insufficient_negative_fit'])))),
        ]);

        $result = app(WorkerExecutionService::class)->executeTask((int) $task->id);

        $article = Article::query()->findOrFail((int) $result['article_id']);
        $this->assertSame('pending', $article->review_status);
        $this->assertStringContainsString('Revised', $article->content);
        $this->assertSame(
            ['insufficient_negative_fit'],
            data_get($result, 'meta.generation_trace.deep_review.issue_codes')
        );
        $this->assertTrue((bool) data_get($result, 'meta.generation_trace.deep_review.requires_manual_review'));
        $this->assertCount(5, Http::recorded());
    }

    public function test_invalid_deep_plan_does_not_persist_an_article(): void
    {
        [$task] = $this->task();
        $this->fakeFrozenEvidence();
        Http::fake([
            'https://ai.test/v1/chat/completions' => Http::response($this->completion('This is not structured JSON.')),
        ]);

        $service = app(WorkerExecutionService::class);
        try {
            $service->executeTask((int) $task->id);
            $this->fail('Invalid deep planning output must stop the pipeline.');
        } catch (ArticleGenerationProtocolException $exception) {
            $this->assertSame(['schema.invalid_output'], array_values(array_unique(array_column($exception->violations, 'code'))));
        }

        $this->assertDatabaseCount('articles', 0);
        $this->assertSame(0, (int) Title::query()->firstOrFail()->used_count);
        $property = new ReflectionProperty($service, 'lastEvidencePackage');
        $this->assertNull($property->getValue($service));
    }

    public function test_fabricated_plan_reference_gets_one_repair_then_stops_before_draft(): void
    {
        [$task] = $this->task();
        $this->fakeFrozenEvidence();
        $plan = $this->plan();
        $plan['supported_sections'][0]['evidence_refs'] = ['KB:999:FULL:deadbeefdeadbeef'];
        $plan['evidence_mapping'][0]['evidence_refs'] = ['KB:999:FULL:deadbeefdeadbeef'];
        Http::fake([
            'https://ai.test/v1/chat/completions' => Http::response($this->completion(json_encode($plan))),
        ]);

        try {
            app(WorkerExecutionService::class)->executeTask((int) $task->id);
            $this->fail('Fabricated evidence reference must stop before drafting.');
        } catch (ArticleGenerationProtocolException $exception) {
            $this->assertContains('evidence.unknown_reference', array_column($exception->violations, 'code'));
            $this->assertStringNotContainsString('deadbeefdeadbeef', $exception->getMessage());
        }

        $this->assertDatabaseCount('articles', 0);
        $this->assertSame(0, (int) Title::query()->firstOrFail()->used_count);
        $this->assertCount(2, Http::recorded());
    }

    public function test_private_evidence_never_reaches_task_run_api_or_admin_html(): void
    {
        [$task] = $this->task(['need_review' => 0]);
        $this->fakeFrozenEvidence();
        Http::fake([
            'https://ai.test/v1/chat/completions' => Http::sequence()
                ->push($this->completion(json_encode($this->plan())))
                ->push($this->completion($this->article('Private evidence audit')))
                ->push($this->completion(json_encode($this->review(true, 92)))),
        ]);
        $run = TaskRun::query()->create([
            'task_id' => (int) $task->id,
            'status' => 'pending',
            'meta' => [
                'job_type' => 'generate_article',
                'attempt_count' => 0,
                'max_attempts' => 3,
                'payload' => [],
            ],
        ]);

        $worker = app(WorkerExecutionService::class);
        (new ProcessGeoFlowTaskJob((int) $run->id))->handle(
            app(JobQueueService::class),
            $worker
        );

        $run->refresh();
        $this->assertSame('completed', $run->status);
        $persistedJson = json_encode($run->meta, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString($this->privateEvidenceCanary, $persistedJson);
        $this->assertStringContainsString($this->evidenceId, $persistedJson);
        $property = new ReflectionProperty($worker, 'lastEvidencePackage');
        $this->assertNull($property->getValue($worker));

        $taskLifecycle = app(TaskLifecycleService::class);
        $apiJson = json_encode(
            $taskLifecycle->getJob((int) $run->id),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE
        );
        $this->assertStringNotContainsString($this->privateEvidenceCanary, $apiJson);
        $listApiJson = json_encode(
            $taskLifecycle->listTaskJobs((int) $task->id),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE
        );
        $this->assertStringNotContainsString($this->privateEvidenceCanary, $listApiJson);

        $article = Article::query()->findOrFail((int) $run->article_id);
        $snapshotJson = json_encode($article->context_snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString($this->privateEvidenceCanary, $snapshotJson);
        $admin = Admin::query()->create([
            'username' => 'private_evidence_admin',
            'password' => 'secret-123',
            'email' => 'private-evidence@example.com',
            'display_name' => 'Private Evidence Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);
        $this->actingAs($admin, 'admin')
            ->get(route('admin.articles.edit', ['articleId' => (int) $article->id]))
            ->assertOk()
            ->assertDontSee($this->privateEvidenceCanary);
    }

    public function test_failed_job_persists_only_sanitized_provider_error(): void
    {
        Queue::fake();
        [$task] = $this->task();
        $this->fakeFrozenEvidence();
        Http::fake([
            'https://ai.test/v1/chat/completions' => function () {
                throw new RuntimeException('Authorization: Bearer test-key '.$this->privateEvidenceCanary);
            },
        ]);
        $run = TaskRun::query()->create([
            'task_id' => (int) $task->id,
            'status' => 'pending',
            'meta' => [
                'job_type' => 'generate_article',
                'attempt_count' => 0,
                'max_attempts' => 3,
                'payload' => [],
            ],
        ]);

        (new ProcessGeoFlowTaskJob((int) $run->id))->handle(
            app(JobQueueService::class),
            app(WorkerExecutionService::class)
        );

        $run->refresh();
        $persistedJson = json_encode([
            'error_message' => $run->error_message,
            'meta' => $run->meta,
            'task_error' => $task->fresh()->last_error_message,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $apiJson = json_encode(app(TaskLifecycleService::class)->getJob((int) $run->id), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        $this->assertStringNotContainsString($this->privateEvidenceCanary, $persistedJson);
        $this->assertStringNotContainsString('test-key', $persistedJson);
        $this->assertStringNotContainsString($this->privateEvidenceCanary, $apiJson);
        $this->assertMatchesRegularExpression('/[a-f0-9]{12}/', (string) $run->error_message);
        $this->assertSame('pending', $run->status);
        $this->assertSame('provider_retrying', data_get($run->meta, 'generation_outcome'));
        $this->assertNull(data_get($run->meta, 'terminal_reason'));
        $this->assertSame(1, data_get($run->meta, 'provider_attempt_count'));
        $this->assertSame('deep_plan', data_get($run->meta, 'model_attempts.0.stage'));
        $this->assertSame('failed', data_get($run->meta, 'model_attempts.0.status'));
        $this->assertDatabaseCount('articles', 0);
    }

    public function test_content_blocker_is_terminal_without_queue_retry_and_preserves_safe_attempts(): void
    {
        Queue::fake();
        [$task] = $this->task();
        $this->fakeFrozenEvidence();
        Http::fake([
            'https://ai.test/v1/chat/completions' => Http::sequence()
                ->push($this->completion(json_encode($this->plan())))
                ->push($this->completion("The system handles 500 kg.\n<!-- evidence:{$this->evidenceId} -->")),
        ]);
        $run = TaskRun::query()->create([
            'task_id' => (int) $task->id,
            'status' => 'pending',
            'meta' => [
                'job_type' => 'generate_article',
                'attempt_count' => 0,
                'max_attempts' => 3,
                'payload' => [],
            ],
        ]);

        (new ProcessGeoFlowTaskJob((int) $run->id))->handle(
            app(JobQueueService::class),
            app(WorkerExecutionService::class)
        );

        $run->refresh();
        $this->assertSame('failed', $run->status);
        $this->assertSame('content_blocked', data_get($run->meta, 'terminal_reason'));
        $this->assertSame('content_blocked', data_get($run->meta, 'generation_outcome'));
        $this->assertSame('grounding_blocked', data_get($run->meta, 'content_block_reason'));
        $this->assertSame(2, data_get($run->meta, 'provider_attempt_count'));
        $this->assertSame(['deep_plan', 'deep_draft'], array_column(data_get($run->meta, 'model_attempts', []), 'stage'));
        $this->assertCount(2, Http::recorded());
        Queue::assertNotPushed(ProcessGeoFlowTaskJob::class);
        $this->assertDatabaseCount('articles', 0);
    }

    public function test_provider_failure_becomes_terminal_after_the_queue_budget_is_exhausted(): void
    {
        Queue::fake();
        [$task] = $this->task();
        $this->fakeFrozenEvidence();
        Http::fake([
            'https://ai.test/v1/chat/completions' => function () {
                throw new RuntimeException('Authorization: Bearer test-key '.$this->privateEvidenceCanary);
            },
        ]);
        $run = TaskRun::query()->create([
            'task_id' => (int) $task->id,
            'status' => 'pending',
            'meta' => [
                'job_type' => 'generate_article',
                'attempt_count' => 0,
                'max_attempts' => 1,
                'payload' => [],
            ],
        ]);

        (new ProcessGeoFlowTaskJob((int) $run->id))->handle(
            app(JobQueueService::class),
            app(WorkerExecutionService::class)
        );

        $run->refresh();
        $this->assertSame('failed', $run->status);
        $this->assertSame('provider_failure', data_get($run->meta, 'terminal_reason'));
        $this->assertSame('provider_failure', data_get($run->meta, 'generation_outcome'));
        $this->assertSame(1, data_get($run->meta, 'provider_attempt_count'));
        $this->assertSame('failed', data_get($run->meta, 'model_attempts.0.status'));
        Queue::assertNotPushed(ProcessGeoFlowTaskJob::class);
    }

    public function test_insufficient_evidence_stops_job_without_retrying_or_exposing_raw_questions(): void
    {
        Queue::fake();
        [$task] = $this->task();
        $this->fakeFrozenEvidence();
        $plan = $this->plan();
        $plan['evidence_sufficiency'] = 'insufficient';
        $plan['answer_mode'] = 'stop';
        $plan['evidence_mapping'] = [];
        $plan['supported_sections'] = [];
        $plan['verification_items'] = [[
            'question' => 'Confirm the private process conditions.',
            'category' => 'process',
            'required_for_draft' => true,
        ]];
        Http::fake([
            'https://ai.test/v1/chat/completions' => Http::response($this->completion(json_encode($plan))),
        ]);
        $run = TaskRun::query()->create([
            'task_id' => (int) $task->id,
            'status' => 'pending',
            'meta' => [
                'job_type' => 'generate_article',
                'attempt_count' => 0,
                'max_attempts' => 3,
                'payload' => [],
            ],
        ]);

        (new ProcessGeoFlowTaskJob((int) $run->id))->handle(
            app(JobQueueService::class),
            app(WorkerExecutionService::class)
        );

        $run->refresh();
        $this->assertSame('failed', $run->status);
        $this->assertSame('failed', data_get($run->meta, 'dispatch_state'));
        $this->assertSame('insufficient_evidence', data_get($run->meta, 'terminal_reason'));
        $this->assertSame(['application_conditions'], data_get($run->meta, 'missing_information_categories'));
        $this->assertStringContainsString('应用或工艺条件', (string) $run->error_message);
        $this->assertStringNotContainsString('private process', (string) $run->error_message);
        $this->assertCount(1, Http::recorded());
        Queue::assertNotPushed(ProcessGeoFlowTaskJob::class);
        $this->assertDatabaseCount('articles', 0);
    }

    public function test_protocol_repair_exhaustion_is_terminal_and_persists_only_safe_audit_fields(): void
    {
        Queue::fake();
        [$task] = $this->task();
        $this->fakeFrozenEvidence();
        $plan = $this->plan();
        unset($plan['answer_mode']);
        Http::fake([
            'https://ai.test/v1/chat/completions' => Http::sequence()
                ->push($this->completion(json_encode($plan)))
                ->push($this->completion(json_encode($plan))),
        ]);
        $run = TaskRun::query()->create([
            'task_id' => (int) $task->id,
            'status' => 'pending',
            'meta' => [
                'job_type' => 'generate_article',
                'attempt_count' => 0,
                'max_attempts' => 3,
                'payload' => [],
            ],
        ]);

        (new ProcessGeoFlowTaskJob((int) $run->id))->handle(
            app(JobQueueService::class),
            app(WorkerExecutionService::class)
        );

        $run->refresh();
        $this->assertSame('failed', $run->status);
        $this->assertSame('protocol_failure', data_get($run->meta, 'terminal_reason'));
        $this->assertSame('protocol_failure', data_get($run->meta, 'generation_outcome'));
        $this->assertSame('deep-v2.4-structured-plan-1', data_get($run->meta, 'protocol_version'));
        $this->assertSame('plan_repair', data_get($run->meta, 'protocol_stage'));
        $this->assertSame(['schema.invalid_enum'], data_get($run->meta, 'protocol_violation_codes'));
        $this->assertSame(['$.answer_mode'], data_get($run->meta, 'protocol_violation_paths'));
        $this->assertSame(2, data_get($run->meta, 'provider_attempt_count'));
        $this->assertStringNotContainsString('answer_mode', (string) $run->error_message);
        $this->assertCount(2, Http::recorded());
        Queue::assertNotPushed(ProcessGeoFlowTaskJob::class);
        $this->assertDatabaseCount('articles', 0);
    }

    /** @return array{Task,AiModel} */
    private function task(array $overrides = []): array
    {
        $library = TitleLibrary::query()->create(['name' => 'Deep Worker Titles']);
        Title::query()->create([
            'library_id' => (int) $library->id,
            'title' => 'How to Select a Dispensing System',
            'keyword' => 'dispensing system selection',
            'used_count' => 0,
            'usage_count' => 0,
        ]);
        $prompt = Prompt::query()->create([
            'name' => 'Deep Worker Master',
            'type' => 'content',
            'content' => 'Use verified evidence and explain decision trade-offs.',
        ]);
        $model = AiModel::query()->create([
            'name' => 'Deep Worker Model',
            'model_id' => 'deep-worker-model',
            'model_type' => 'chat',
            'api_url' => 'https://ai.test/v1',
            'api_key' => app(ApiKeyCrypto::class)->encrypt('test-key'),
            'used_today' => 0,
            'total_used' => 0,
            'status' => 'active',
        ]);
        $author = Author::query()->create(['name' => 'Deep Worker Author']);
        $category = Category::query()->create(['name' => 'Deep Worker Category', 'slug' => 'deep-worker-category']);
        $task = Task::query()->create(array_merge([
            'name' => 'Deep Worker Task',
            'title_library_id' => (int) $library->id,
            'prompt_id' => (int) $prompt->id,
            'ai_model_id' => (int) $model->id,
            'author_id' => (int) $author->id,
            'category_mode' => 'fixed',
            'fixed_category_id' => (int) $category->id,
            'generation_mode' => 'deep',
            'model_selection_mode' => 'fixed',
            'status' => 'active',
            'schedule_enabled' => 1,
            'need_review' => 0,
            'article_limit' => 5,
            'draft_limit' => 5,
            'publish_interval' => 3600,
            'is_loop' => 0,
        ], $overrides));

        return [$task, $model];
    }

    private function fakeFrozenEvidence(): void
    {
        $evidence = app(ArticleEvidencePackage::class)->make(
            'knowledge_chunk',
            1,
            'Private deep source',
            $this->privateEvidenceCanary,
            0
        );
        $this->evidenceId = $evidence['id'];
        $this->mock(RagRetrievalService::class)
            ->shouldReceive('retrieveForTask')
            ->once()
            ->andReturn([
                'context' => "Evidence ID: {$evidence['id']}\n{$this->privateEvidenceCanary}",
                'generation_context' => "Evidence ID: {$evidence['id']}\n{$this->privateEvidenceCanary}",
                'evidence_package' => [$evidence],
                'trace' => [
                    'strategy' => 'test_frozen_context',
                    'context_length' => 23,
                    'chunks' => [[
                        'knowledge_base_id' => 1,
                        'chunk_index' => 0,
                        'evidence_id' => $evidence['id'],
                        'content_sha256' => $evidence['content_sha256'],
                    ]],
                    'entities' => [],
                    'cases' => [],
                    'context_package' => [
                        'strategy' => 'test_frozen_context',
                        'used_knowledge_base_ids' => [1],
                        'evidence_audit' => app(ArticleEvidencePackage::class)->audit([$evidence]),
                    ],
                    'evidence_audit' => app(ArticleEvidencePackage::class)->audit([$evidence]),
                ],
            ]);
    }

    /** @return array<string,mixed> */
    private function plan(): array
    {
        return [
            'reader_question' => 'Which system fits the process?',
            'answer_mode' => 'direct',
            'evidence_sufficiency' => 'sufficient',
            'supported_sections' => [[
                'purpose' => 'Explain the verified inputs that change the decision.',
                'support_type' => 'evidence',
                'evidence_refs' => [$this->evidenceId],
            ]],
            'evidence_mapping' => [[
                'claim_scope' => 'Selection inputs',
                'evidence_refs' => [$this->evidenceId],
            ]],
            'optional_modules' => [],
            'unsupported_claims_to_avoid' => ['Unverified performance figures'],
            'verification_items' => [[
                'question' => 'Confirm the measured load.',
                'category' => 'specification',
                'required_for_draft' => false,
            ]],
        ];
    }

    /** @return array<string,mixed> */
    private function review(bool $passed, int $score, array $issueCodes = []): array
    {
        return [
            'passed' => $passed,
            'score' => $score,
            'issue_codes' => $issueCodes,
            'issues' => array_map(static fn (string $code): array => [
                'code' => $code,
                'severity' => 'medium',
                'message' => 'Review issue: '.$code,
            ], $issueCodes),
            'revision_instructions' => array_map(static fn (string $code): array => [
                'target' => 'Affected section',
                'instruction' => 'Resolve '.$code.' without adding facts.',
            ], $issueCodes),
            'metrics' => [
                'factual_support' => $passed ? 5 : 3,
                'clarity' => 4,
                'buyer_decision_value' => 4,
                'structure_naturalness' => 4,
                'uncertainty_and_negative_fit' => 4,
                'privacy_and_safety' => 5,
                'style_fitness' => 4,
                'non_template_naturalness' => 4,
            ],
        ];
    }

    private function article(string $label): string
    {
        return "## {$label} decision context\n\n"
            .str_repeat('Verified evidence supports this complete decision-focused explanation. ', 12)
            .'The buyer should confirm process constraints before selecting a configuration.'
            ."\n<!-- evidence:{$this->evidenceId} -->";
    }

    /** @return array<string,mixed> */
    private function completion(string $content): array
    {
        return [
            'model' => 'deep-worker-model',
            'choices' => [[
                'message' => ['content' => $content],
                'finish_reason' => 'stop',
            ]],
        ];
    }
}
