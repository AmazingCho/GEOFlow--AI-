<?php

namespace Tests\Feature;

use App\Models\CaseRecord;
use App\Models\CollectionRecord;
use App\Models\EntityRecord;
use App\Models\KnowledgeBase;
use App\Models\KnowledgeChunk;
use App\Models\Task;
use App\Services\GeoFlow\RagRetrievalService;
use App\Services\GeoFlow\TagService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    public function test_it_includes_knowledge_metadata_in_context_and_trace(): void
    {
        $knowledgeBase = $this->createKnowledgeBaseWithChunk(
            'SJ4060 产品手册',
            'SJ4060 支持高精度视觉定位和自动点胶。',
            '',
            [
                'knowledge_type' => 'product_manual',
                'knowledge_role' => 'primary_source',
                'importance' => 5,
            ]
        );

        $task = Task::query()->create([
            'name' => '知识元数据检索任务',
            'status' => 'active',
            'schedule_enabled' => 1,
            'knowledge_base_id' => (int) $knowledgeBase->id,
        ]);

        $result = app(RagRetrievalService::class)->retrieveForTask($task, 'SJ4060 视觉点胶能力', 'SJ4060');

        $this->assertStringContainsString('类型：产品手册', $result['context']);
        $this->assertStringContainsString('角色：事实依据', $result['context']);
        $this->assertStringContainsString('重要度：5', $result['context']);
        $this->assertStringContainsString('优先作为事实依据', $result['context']);

        $trace = $result['trace'];
        $this->assertSame('product_manual', $trace['knowledge_bases'][0]['knowledge_type']);
        $this->assertSame('primary_source', $trace['knowledge_bases'][0]['knowledge_role']);
        $this->assertSame(5, $trace['knowledge_bases'][0]['importance']);
        $this->assertSame('product_manual', $trace['chunks'][0]['knowledge_type']);
        $this->assertSame('primary_source', $trace['chunks'][0]['knowledge_role']);
        $this->assertSame(5, $trace['chunks'][0]['importance']);
    }

    public function test_it_retrieves_knowledge_linked_to_tagged_entity(): void
    {
        $knowledgeBase = $this->createKnowledgeBaseWithChunk(
            'SJ4060 深度资料',
            'SJ4060 采用视觉定位、精密胶量控制和自动校准。',
            ''
        );
        $tag = app(TagService::class)->firstOrCreateTag('产品型号', 'SJ4060');
        $entity = EntityRecord::query()->create([
            'name' => 'SJ4060',
            'entity_type' => '产品型号',
            'description' => '视觉点胶设备型号',
        ]);
        app(TagService::class)->syncExisting($entity, [(int) $tag->id]);
        DB::table('entity_material_links')->insert([
            'entity_id' => (int) $entity->id,
            'linkable_type' => KnowledgeBase::class,
            'linkable_id' => (int) $knowledgeBase->id,
            'link_role' => 'related',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $task = Task::query()->create([
            'name' => 'Entity 关联知识检索任务',
            'status' => 'active',
            'schedule_enabled' => 1,
            'knowledge_tag_filter' => '产品型号:SJ4060',
        ]);

        $result = app(RagRetrievalService::class)->retrieveForTask($task, 'SJ4060 点胶能力', 'SJ4060');

        $this->assertStringContainsString('SJ4060 采用视觉定位', $result['context']);
        $this->assertContains((int) $knowledgeBase->id, $result['trace']['knowledge_base_ids']);
    }

    public function test_it_retrieves_knowledge_and_cases_from_selected_entity_filter(): void
    {
        $knowledgeBase = $this->createKnowledgeBaseWithChunk(
            'SJ4060 关联资料',
            'SJ4060 可用于视觉灌胶场景，支持稳定胶量控制。',
            ''
        );
        $entity = EntityRecord::query()->create([
            'name' => 'SJ4060',
            'entity_type' => '产品型号',
            'description' => '视觉灌胶设备型号。',
        ]);
        DB::table('entity_material_links')->insert([
            'entity_id' => (int) $entity->id,
            'linkable_type' => KnowledgeBase::class,
            'linkable_id' => (int) $knowledgeBase->id,
            'link_role' => 'related',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $caseRecord = CaseRecord::query()->create([
            'entity_id' => (int) $entity->id,
            'title' => 'SJ4060 电池灌胶应用案例',
            'case_type' => '应用案例',
            'summary' => '客户使用 SJ4060 提升灌胶一致性。',
            'result' => '返修率降低。',
        ]);

        $task = Task::query()->create([
            'name' => 'Entity 过滤任务',
            'status' => 'active',
            'schedule_enabled' => 1,
            'entity_filter' => (string) $entity->id,
        ]);

        $result = app(RagRetrievalService::class)->retrieveForTask($task, 'SJ4060 视觉灌胶能力', 'SJ4060');

        $this->assertStringContainsString('SJ4060 可用于视觉灌胶场景', $result['context']);
        $this->assertStringContainsString('实体：SJ4060', $result['context']);
        $this->assertStringContainsString('SJ4060 电池灌胶应用案例', $result['context']);
        $this->assertContains((int) $knowledgeBase->id, $result['trace']['knowledge_base_ids']);
        $this->assertSame([(int) $entity->id], $result['trace']['entity_filter_ids']);
        $this->assertSame('SJ4060', $result['trace']['entities'][0]['name']);
        $this->assertSame((int) $caseRecord->id, (int) $result['trace']['cases'][0]['id']);
    }

    public function test_entity_linked_primary_subject_knowledge_is_prioritized(): void
    {
        $supportingKnowledge = $this->createKnowledgeBaseWithChunk(
            'SJ4060 辅助资料',
            '辅助资料：SJ4060 可用于一般点胶场景。',
            ''
        );
        $primaryKnowledge = $this->createKnowledgeBaseWithChunk(
            'SJ4060 官方手册',
            '官方手册：SJ4060 支持视觉定位和精密胶量控制。',
            ''
        );
        $entity = EntityRecord::query()->create([
            'name' => 'SJ4060',
            'entity_type' => '产品型号',
            'description' => '视觉点胶设备型号。',
        ]);

        foreach ([
            [(int) $supportingKnowledge->id, 'supporting_reference'],
            [(int) $primaryKnowledge->id, 'primary_subject'],
        ] as [$knowledgeBaseId, $role]) {
            DB::table('entity_material_links')->insert([
                'entity_id' => (int) $entity->id,
                'linkable_type' => KnowledgeBase::class,
                'linkable_id' => $knowledgeBaseId,
                'link_role' => $role,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $task = Task::query()->create([
            'name' => 'Entity 主资料优先任务',
            'status' => 'active',
            'schedule_enabled' => 1,
            'entity_filter' => (string) $entity->id,
        ]);

        $result = app(RagRetrievalService::class)->retrieveForTask($task, 'SJ4060 视觉定位能力', 'SJ4060');

        $this->assertSame((int) $primaryKnowledge->id, (int) $result['trace']['knowledge_base_ids'][0]);
        $this->assertContains((int) $supportingKnowledge->id, $result['trace']['knowledge_base_ids']);
    }

    public function test_selected_case_adds_case_context_and_entity_linked_knowledge(): void
    {
        $knowledgeBase = $this->createKnowledgeBaseWithChunk(
            'SJ4060 案例关联资料',
            'SJ4060 在电池灌胶案例中提升胶量稳定性。',
            ''
        );
        $entity = EntityRecord::query()->create([
            'name' => 'SJ4060',
            'entity_type' => '产品型号',
            'description' => '视觉灌胶设备型号。',
        ]);
        DB::table('entity_material_links')->insert([
            'entity_id' => (int) $entity->id,
            'linkable_type' => KnowledgeBase::class,
            'linkable_id' => (int) $knowledgeBase->id,
            'link_role' => 'primary_subject',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $caseRecord = CaseRecord::query()->create([
            'entity_id' => (int) $entity->id,
            'title' => 'SJ4060 电池灌胶案例',
            'case_type' => '应用案例',
            'summary' => '客户通过 SJ4060 提升一致性。',
            'metrics' => '返修率下降 18%',
        ]);

        $task = Task::query()->create([
            'name' => 'Case 过滤任务',
            'status' => 'active',
            'schedule_enabled' => 1,
            'case_filter' => (string) $caseRecord->id,
        ]);

        $result = app(RagRetrievalService::class)->retrieveForTask($task, 'SJ4060 电池灌胶方案', 'SJ4060');

        $this->assertStringContainsString('SJ4060 在电池灌胶案例中提升胶量稳定性', $result['context']);
        $this->assertStringContainsString('SJ4060 电池灌胶案例', $result['context']);
        $this->assertSame([(int) $caseRecord->id], $result['trace']['case_filter_ids']);
        $this->assertSame([(int) $caseRecord->id], $result['trace']['context_package']['selected_case_ids']);
        $this->assertContains((int) $knowledgeBase->id, $result['trace']['context_package']['used_knowledge_base_ids']);
    }

    public function test_collection_general_knowledge_excludes_archive_unless_selected_manually(): void
    {
        $collection = CollectionRecord::query()->create([
            'name' => 'Automation Equipment Retrieval',
            'slug' => 'automation-equipment-retrieval',
            'status' => 'active',
        ]);
        $supportingKnowledge = $this->createKnowledgeBaseWithChunk(
            'Automation 通用资料',
            '自动化设备通用资料可作为补充上下文。',
            '',
            ['collection_id' => (int) $collection->id, 'knowledge_role' => 'supporting_context']
        );
        $archiveKnowledge = $this->createKnowledgeBaseWithChunk(
            'Automation 归档资料',
            '归档资料默认不应参与自动生成。',
            '',
            ['collection_id' => (int) $collection->id, 'knowledge_role' => 'archive']
        );

        $task = Task::query()->create([
            'name' => 'Collection 通用知识任务',
            'status' => 'active',
            'schedule_enabled' => 1,
            'collection_id' => (int) $collection->id,
        ]);

        $result = app(RagRetrievalService::class)->retrieveForTask($task, '自动化设备选型', '自动化设备');
        $this->assertStringContainsString('自动化设备通用资料', $result['context']);
        $this->assertStringNotContainsString('归档资料默认不应参与自动生成', $result['context']);
        $this->assertContains((int) $supportingKnowledge->id, $result['trace']['knowledge_base_ids']);
        $this->assertNotContains((int) $archiveKnowledge->id, $result['trace']['knowledge_base_ids']);

        $manualTask = Task::query()->create([
            'name' => '手动归档知识任务',
            'status' => 'active',
            'schedule_enabled' => 1,
            'collection_id' => (int) $collection->id,
            'knowledge_base_id' => (int) $archiveKnowledge->id,
        ]);
        $manualResult = app(RagRetrievalService::class)->retrieveForTask($manualTask, '自动化设备历史版本', '归档');
        $this->assertStringContainsString('归档资料默认不应参与自动生成', $manualResult['context']);
    }

    public function test_inactive_knowledge_is_excluded_from_automatic_retrieval_but_manual_selection_still_works(): void
    {
        $collection = CollectionRecord::query()->create([
            'name' => 'Inactive Knowledge Retrieval',
            'slug' => 'inactive-knowledge-retrieval',
            'status' => 'active',
        ]);
        $activeKnowledge = $this->createKnowledgeBaseWithChunk(
            'Active Automation Knowledge',
            '启用资料可以自动参与生成。',
            '',
            ['collection_id' => (int) $collection->id, 'status' => 'active']
        );
        $inactiveKnowledge = $this->createKnowledgeBaseWithChunk(
            'Inactive Automation Knowledge',
            '停用资料不应自动参与生成。',
            '',
            ['collection_id' => (int) $collection->id, 'status' => 'inactive']
        );

        $task = Task::query()->create([
            'name' => '停用知识自动排除任务',
            'status' => 'active',
            'schedule_enabled' => 1,
            'collection_id' => (int) $collection->id,
        ]);

        $result = app(RagRetrievalService::class)->retrieveForTask($task, '自动化设备资料', '自动化');
        $this->assertStringContainsString('启用资料可以自动参与生成', $result['context']);
        $this->assertStringNotContainsString('停用资料不应自动参与生成', $result['context']);
        $this->assertContains((int) $activeKnowledge->id, $result['trace']['knowledge_base_ids']);
        $this->assertNotContains((int) $inactiveKnowledge->id, $result['trace']['knowledge_base_ids']);

        $manualTask = Task::query()->create([
            'name' => '手动停用知识任务',
            'status' => 'active',
            'schedule_enabled' => 1,
            'collection_id' => (int) $collection->id,
            'knowledge_base_id' => (int) $inactiveKnowledge->id,
        ]);
        $manualResult = app(RagRetrievalService::class)->retrieveForTask($manualTask, '自动化设备停用资料', '停用');
        $this->assertStringContainsString('停用资料不应自动参与生成', $manualResult['context']);
    }

    /**
     * @param  array<string,mixed>  $metadata
     */
    private function createKnowledgeBaseWithChunk(string $name, string $content, string $tags, array $metadata = []): KnowledgeBase
    {
        $knowledgeBase = KnowledgeBase::query()->create([
            'name' => $name,
            'description' => '',
            'content' => $content,
            'character_count' => mb_strlen($content, 'UTF-8'),
            'file_type' => 'markdown',
            'collection_id' => (int) ($metadata['collection_id'] ?? 0) ?: null,
            'knowledge_type' => (string) ($metadata['knowledge_type'] ?? 'reference'),
            'knowledge_role' => (string) ($metadata['knowledge_role'] ?? 'supporting_context'),
            'importance' => (int) ($metadata['importance'] ?? 3),
            'status' => (string) ($metadata['status'] ?? 'active'),
            'word_count' => mb_strlen($content, 'UTF-8'),
        ]);
        if (trim($tags) !== '') {
            app(TagService::class)->sync($knowledgeBase, $tags);
        }

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
