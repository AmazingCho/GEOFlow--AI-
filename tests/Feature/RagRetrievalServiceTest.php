<?php

namespace Tests\Feature;

use App\Models\CaseRecord;
use App\Models\EntityRecord;
use App\Models\KnowledgeBase;
use App\Models\KnowledgeChunk;
use App\Models\Task;
use App\Services\GeoFlow\RagRetrievalService;
use App\Services\GeoFlow\TagService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RagRetrievalServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_retrieves_tagged_knowledge_entity_and_case_with_trace(): void
    {
        $knowledgeBase = $this->createKnowledgeBaseWithChunk(
            '制造业售后知识库',
            '智能客服可以降低制造业售后响应时间，并沉淀常见问题。',
            '行业:制造业'
        );
        $this->createKnowledgeBaseWithChunk(
            '医疗知识库',
            '医疗客服需要遵守诊疗合规边界。',
            '行业:医疗'
        );

        $industryTag = app(TagService::class)->firstOrCreateTag('行业', '制造业');
        $entity = EntityRecord::query()->create([
            'name' => '制造业客户A',
            'entity_type' => '客户',
            'description' => '专注精密制造，售后问题量大。',
        ]);
        app(TagService::class)->syncExisting($entity, [(int) $industryTag->id]);

        $caseRecord = CaseRecord::query()->create([
            'entity_id' => (int) $entity->id,
            'title' => '制造业售后响应效率提升案例',
            'case_type' => '客户案例',
            'summary' => '智能客服帮助售后团队提升响应效率。',
            'metrics' => '响应时间下降 40%',
        ]);
        app(TagService::class)->syncExisting($caseRecord, [(int) $industryTag->id]);

        $task = Task::query()->create([
            'name' => 'RAG 检索任务',
            'status' => 'active',
            'schedule_enabled' => 1,
            'knowledge_tag_filter' => '行业:制造业',
        ]);

        $result = app(RagRetrievalService::class)->retrieveForTask($task, '制造业智能客服如何提升售后', '智能客服');

        $this->assertStringContainsString('智能客服可以降低制造业售后响应时间', $result['context']);
        $this->assertStringContainsString('实体：制造业客户A', $result['context']);
        $this->assertStringContainsString('制造业售后响应效率提升案例', $result['context']);
        $this->assertStringNotContainsString('医疗客服需要遵守诊疗合规边界', $result['context']);

        $trace = $result['trace'];
        $this->assertSame('rag_retrieval_service', $trace['retrieval_engine']);
        $this->assertContains('行业:制造业', $trace['tag_filters']);
        $this->assertContains((int) $knowledgeBase->id, $trace['knowledge_base_ids']);
        $this->assertSame('hybrid_vector_lexical', $trace['strategy']);
        $this->assertSame((int) $knowledgeBase->id, (int) $trace['chunks'][0]['knowledge_base_id']);
        $this->assertSame('制造业客户A', $trace['entities'][0]['name']);
        $this->assertSame('制造业售后响应效率提升案例', $trace['cases'][0]['title']);
        $this->assertGreaterThan(0, $trace['context_length']);
    }

    private function createKnowledgeBaseWithChunk(string $name, string $content, string $tags): KnowledgeBase
    {
        $knowledgeBase = KnowledgeBase::query()->create([
            'name' => $name,
            'description' => '',
            'content' => $content,
            'character_count' => mb_strlen($content, 'UTF-8'),
            'file_type' => 'markdown',
            'word_count' => mb_strlen($content, 'UTF-8'),
        ]);
        app(TagService::class)->sync($knowledgeBase, $tags);

        KnowledgeChunk::query()->create([
            'knowledge_base_id' => (int) $knowledgeBase->id,
            'chunk_index' => 0,
            'content' => $content,
            'content_hash' => hash('sha256', $content),
            'chunk_title' => $name,
            'section_path' => $name,
            'chunk_strategy' => 'test',
            'metadata_json' => '{}',
            'source_hash' => hash('sha256', $name.'|'.$content),
            'token_count' => 20,
            'embedding_json' => '[]',
            'embedding_model_id' => null,
            'embedding_dimensions' => 0,
            'embedding_provider' => '',
            'embedding_vector' => null,
        ]);

        return $knowledgeBase;
    }
}
