<?php

namespace Tests\Feature;

use App\Models\CaseRecord;
use App\Models\EntityRecord;
use App\Models\Image;
use App\Models\ImageLibrary;
use App\Models\KnowledgeBase;
use App\Models\KnowledgeChunk;
use App\Models\Task;
use App\Services\GeoFlow\TagService;
use App\Services\GeoFlow\WorkerExecutionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

class WorkerKnowledgeTagContextTest extends TestCase
{
    use RefreshDatabase;

    public function test_worker_resolves_knowledge_context_across_bases_by_tag(): void
    {
        $manufacturingSupport = $this->createKnowledgeBaseWithChunk(
            '制造业售后知识库',
            '智能客服可以降低制造业售后响应时间，并沉淀常见问题。',
            '行业:制造业'
        );
        $manufacturingQuality = $this->createKnowledgeBaseWithChunk(
            '制造业质检知识库',
            '视觉质检可以帮助制造业发现产线异常并减少返工。',
            '行业:制造业'
        );
        $this->createKnowledgeBaseWithChunk(
            '医疗知识库',
            '医疗客服需要遵守诊疗合规边界。',
            '行业:医疗'
        );

        $task = Task::query()->create([
            'name' => '跨库知识标签任务',
            'status' => 'active',
            'schedule_enabled' => 1,
            'knowledge_tag_filter' => '行业:制造业',
        ]);

        $context = $this->resolveKnowledgeContext($task, '制造业智能客服如何提升售后', '智能客服');

        $this->assertStringContainsString('知识库：'.$manufacturingSupport->name, $context);
        $this->assertStringContainsString('智能客服可以降低制造业售后响应时间', $context);
        $this->assertStringContainsString('知识库：'.$manufacturingQuality->name, $context);
        $this->assertStringContainsString('视觉质检可以帮助制造业发现产线异常', $context);
        $this->assertStringNotContainsString('医疗客服需要遵守诊疗合规边界', $context);
    }

    public function test_worker_resolves_entity_and_case_context_by_tag(): void
    {
        $industryTag = app(TagService::class)->firstOrCreateTag('行业', '制造业');
        $scenarioTag = app(TagService::class)->firstOrCreateTag('场景', '售后');
        app(TagService::class)->firstOrCreateTag('行业', '医疗');

        $entity = EntityRecord::query()->create([
            'name' => '制造业客户A',
            'entity_type' => '客户',
            'aliases' => '客户A, A工厂',
            'description' => '专注精密制造，售后问题量大。',
            'attributes_json' => '{"industry":"manufacturing"}',
        ]);
        app(TagService::class)->syncExisting($entity, [(int) $industryTag->id]);

        $caseRecord = CaseRecord::query()->create([
            'entity_id' => (int) $entity->id,
            'title' => '制造业售后响应效率提升案例',
            'case_type' => '客户案例',
            'summary' => '智能客服帮助售后团队提升响应效率。',
            'challenge' => '售后知识分散。',
            'solution' => '用标签化知识库和智能客服统一复用。',
            'result' => '响应时间下降 40%。',
            'metrics' => '响应时间下降 40%',
        ]);
        app(TagService::class)->syncExisting($caseRecord, [(int) $scenarioTag->id]);

        $medicalEntity = EntityRecord::query()->create([
            'name' => '医疗客户B',
            'entity_type' => '客户',
            'description' => '医疗场景实体。',
        ]);
        app(TagService::class)->sync($medicalEntity, '行业:医疗');

        $task = Task::query()->create([
            'name' => '实体案例标签任务',
            'status' => 'active',
            'schedule_enabled' => 1,
            'knowledge_tag_filter' => '行业:制造业, 场景:售后',
        ]);

        $context = $this->resolveKnowledgeContext($task, '制造业智能客服如何提升售后', '智能客服');

        $this->assertStringContainsString('【Entity DB 参考】', $context);
        $this->assertStringContainsString('实体：制造业客户A', $context);
        $this->assertStringContainsString('【Case DB 参考】', $context);
        $this->assertStringContainsString('制造业售后响应效率提升案例', $context);
        $this->assertStringContainsString('响应时间下降 40%', $context);
        $this->assertStringNotContainsString('医疗客户B', $context);
    }

    public function test_worker_filters_task_images_by_library_and_tags(): void
    {
        $library = ImageLibrary::query()->create([
            'name' => '任务配图库',
            'image_count' => 2,
        ]);
        $matchedImage = Image::query()->create([
            'library_id' => (int) $library->id,
            'filename' => 'matched.png',
            'original_name' => 'matched.png',
            'file_path' => 'storage/uploads/images/matched.png',
        ]);
        $otherImage = Image::query()->create([
            'library_id' => (int) $library->id,
            'filename' => 'other.png',
            'original_name' => 'other.png',
            'file_path' => 'storage/uploads/images/other.png',
        ]);
        app(TagService::class)->sync($matchedImage, '场景:产品图');
        app(TagService::class)->sync($otherImage, '场景:环境图');

        $task = Task::query()->create([
            'name' => '图片标签筛选任务',
            'image_library_id' => (int) $library->id,
            'image_count' => 2,
            'image_tag_filter' => '场景:产品图',
        ]);

        $result = $this->insertTaskImagesIntoContent($task, "第一段内容。\n\n第二段内容。\n\n第三段内容。");

        $this->assertCount(1, $result['images']);
        $this->assertSame((int) $matchedImage->id, (int) $result['images'][0]->id);
        $this->assertStringContainsString('matched.png', $result['content']);
        $this->assertStringNotContainsString('other.png', $result['content']);
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

    private function resolveKnowledgeContext(Task $task, string $title, string $keyword): string
    {
        $service = app(WorkerExecutionService::class);
        $method = new ReflectionMethod($service, 'resolveKnowledgeContext');
        $method->setAccessible(true);

        return (string) $method->invoke($service, $task, $title, $keyword);
    }

    /**
     * @return array{content:string,images:list<Image>}
     */
    private function insertTaskImagesIntoContent(Task $task, string $content): array
    {
        $service = app(WorkerExecutionService::class);
        $method = new ReflectionMethod($service, 'insertTaskImagesIntoContent');
        $method->setAccessible(true);

        return $method->invoke($service, $task, $content);
    }
}
