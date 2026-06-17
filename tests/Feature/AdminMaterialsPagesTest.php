<?php

namespace Tests\Feature;

use App\Jobs\SyncKnowledgeBaseChunksJob;
use App\Models\Admin;
use App\Models\AiModel;
use App\Models\CollectionRecord;
use App\Models\Image;
use App\Models\ImageLibrary;
use App\Models\Keyword;
use App\Models\KeywordLibrary;
use App\Models\KnowledgeBase;
use App\Models\EntityRecord;
use App\Models\Prompt;
use App\Models\Tag;
use App\Models\Title;
use App\Models\TitleLibrary;
use App\Models\UrlImportJob;
use App\Models\UrlImportJobLog;
use App\Services\GeoFlow\KnowledgeChunkSyncService;
use App\Support\GeoFlow\ApiKeyCrypto;
use App\Support\GeoFlow\EntityTypes;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * 素材管理模块最小可用测试：
 * - 路由鉴权
 * - 主要列表/创建页可访问
 * - 知识库创建链路可用
 */
class AdminMaterialsPagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    private function createReadyUrlImportAiModel(string $apiUrl = 'https://ai.test/v1'): AiModel
    {
        return AiModel::query()->create([
            'name' => 'URL Import AI Model',
            'version' => '',
            'api_key' => app(ApiKeyCrypto::class)->encrypt('test-key'),
            'model_id' => 'test-chat',
            'model_type' => 'chat',
            'api_url' => $apiUrl,
            'failover_priority' => 1,
            'daily_limit' => 100,
            'used_today' => 0,
            'total_used' => 0,
            'status' => 'active',
        ]);
    }

    public function test_guest_is_redirected_from_material_pages(): void
    {
        $routes = [
            'admin.materials.index',
            'admin.authors.index',
            'admin.keyword-libraries.index',
            'admin.title-libraries.index',
            'admin.image-libraries.index',
            'admin.knowledge-bases.index',
            'admin.knowledge-bases.governance',
            'admin.url-import',
            'admin.url-import.history',
        ];

        foreach ($routes as $routeName) {
            $this->get(route($routeName))->assertRedirect(route('admin.login'));
        }

        $this->get(route('admin.keyword-libraries.detail', ['libraryId' => 1]))->assertRedirect(route('admin.login'));
        $this->get(route('admin.title-libraries.detail', ['libraryId' => 1]))->assertRedirect(route('admin.login'));
        $this->get(route('admin.image-libraries.detail', ['libraryId' => 1]))->assertRedirect(route('admin.login'));
        $this->get(route('admin.knowledge-bases.detail', ['knowledgeBaseId' => 1]))->assertRedirect(route('admin.login'));
    }

    public function test_authenticated_admin_can_open_material_pages(): void
    {
        $admin = Admin::query()->create([
            'username' => 'materials_admin',
            'password' => 'secret-123',
            'email' => 'materials-admin@example.com',
            'display_name' => 'Materials Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.materials.index'))
            ->assertOk()
            ->assertSee(__('admin.materials.page_title'))
            ->assertSee(__('admin.materials.knowledge_hub_label'))
            ->assertSee(__('admin.materials.knowledge_hub_vector_progress'))
            ->assertSeeInOrder([
                __('admin.materials.knowledge_hub_create'),
                __('admin.materials.manage_knowledge_bases'),
                __('admin.materials.knowledge_hub_vector_config'),
            ])
            ->assertSee(__('admin.materials.foundation_title'))
            ->assertSee(__('admin.materials.governance_title'))
            ->assertSee(__('admin.materials.author_manage_title'))
            ->assertSee(__('admin.materials.url_import'));

        $this->actingAs($admin, 'admin')
            ->get(route('admin.authors.index'))
            ->assertOk()
            ->assertSee(__('admin.authors.page_title'));

        $this->actingAs($admin, 'admin')
            ->get(route('admin.keyword-libraries.create'))
            ->assertOk()
            ->assertSee(__('admin.keyword_libraries.page_title'));

        $this->actingAs($admin, 'admin')
            ->get(route('admin.title-libraries.create'))
            ->assertOk()
            ->assertSee(__('admin.title_libraries.page_title'));

        $this->actingAs($admin, 'admin')
            ->get(route('admin.image-libraries.create'))
            ->assertOk()
            ->assertSee(__('admin.image_libraries.page_title'));

        $this->actingAs($admin, 'admin')
            ->get(route('admin.knowledge-bases.create'))
            ->assertOk()
            ->assertSee(__('admin.knowledge_bases.page_title'))
            ->assertSeeInOrder([
                __('admin.knowledge_bases.source_files_title'),
                __('admin.knowledge_bases.source_text_title'),
            ])
            ->assertSee('data-knowledge-name-input', false)
            ->assertSee('data-knowledge-description-input', false)
            ->assertSee('data-import-client-error', false)
            ->assertSee(__('admin.knowledge_bases.import_error_title'))
            ->assertSee('name="knowledge_files[]"', false)
            ->assertSee('multiple', false)
            ->assertSee('data-knowledge-upload-dropzone', false)
            ->assertSeeInOrder([
                __('admin.button.cancel'),
                __('admin.knowledge_bases.import_submit_only'),
                __('admin.knowledge_bases.import_submit'),
            ])
            ->assertSee('name="import_action" value="save"', false)
            ->assertSee('name="import_action" value="save_and_chunk"', false)
            ->assertSee('50MB')
            ->assertSee('10');

        $this->actingAs($admin, 'admin')
            ->get(route('admin.url-import'))
            ->assertOk()
            ->assertSee(__('admin.url_import.page_title'));

        $this->actingAs($admin, 'admin')
            ->get(route('admin.url-import.history'))
            ->assertOk()
            ->assertSee(__('admin.url_import_history.page_title'));
    }

    public function test_material_tag_references_are_loaded_lazily(): void
    {
        $admin = Admin::query()->create([
            'username' => 'material_tag_lazy_admin',
            'password' => 'secret-123',
            'email' => 'material-tag-lazy-admin@example.com',
            'display_name' => 'Material Tag Lazy Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $tag = Tag::query()->create([
            'type' => 'material',
            'group_name' => '行业',
            'name' => '制造业',
            'slug' => 'material-industry-manufacturing',
            'color' => '',
        ]);
        $library = KeywordLibrary::query()->create([
            'name' => '测试关键词库',
            'description' => '',
            'keyword_count' => 1,
        ]);
        $keyword = Keyword::query()->create([
            'library_id' => (int) $library->id,
            'keyword' => '独特引用关键词',
            'used_count' => 0,
            'usage_count' => 0,
        ]);
        $keyword->tags()->attach((int) $tag->id);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.material-tags.index'))
            ->assertOk()
            ->assertSee($tag->displayName())
            ->assertSee(route('admin.material-tags.references', ['tagId' => (int) $tag->id]), false)
            ->assertDontSee('独特引用关键词');

        $this->actingAs($admin, 'admin')
            ->getJson(route('admin.material-tags.references', ['tagId' => (int) $tag->id]))
            ->assertOk()
            ->assertJsonPath('sections.keywords.0.label', '独特引用关键词')
            ->assertJsonPath('sections.keywords.0.meta', '测试关键词库');
    }

    public function test_admin_can_manage_controlled_tag_groups_without_deleting_tags(): void
    {
        $admin = Admin::query()->create([
            'username' => 'controlled_group_admin',
            'password' => 'secret-123',
            'email' => 'controlled-group-admin@example.com',
            'display_name' => 'Controlled Group Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);
        $tag = Tag::query()->create([
            'type' => 'material',
            'group_name' => 'Application',
            'name' => 'Battery',
            'slug' => 'application-battery',
            'color' => '',
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.material-tags.index'))
            ->assertOk()
            ->assertSee(route('admin.material-tags.controlled-groups.index'), false)
            ->assertSee(__('admin.material_tags.controlled_groups_manage'))
            ->assertDontSee(__('admin.material_tags.controlled_group_add'));

        $this->actingAs($admin, 'admin')
            ->get(route('admin.material-tags.controlled-groups.index'))
            ->assertOk()
            ->assertSee(__('admin.material_tags.controlled_groups_title'))
            ->assertSee('Topic')
            ->assertSee('Audience')
            ->assertSee('Intent')
            ->assertSee(__('admin.material_tags.controlled_group_add'));

        $this->actingAs($admin, 'admin')
            ->post(route('admin.material-tags.controlled-groups.store'), ['name' => 'Application'])
            ->assertRedirect();
        $groupId = (int) DB::table('controlled_tag_groups')->where('name', 'Application')->value('id');
        $this->assertGreaterThan(0, $groupId);

        $this->actingAs($admin, 'admin')
            ->put(route('admin.material-tags.controlled-groups.update', ['groupId' => $groupId]), ['name' => 'Application Scenario'])
            ->assertRedirect();
        $this->assertDatabaseHas('controlled_tag_groups', ['id' => $groupId, 'name' => 'Application Scenario']);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.material-tags.controlled-groups.delete', ['groupId' => $groupId]))
            ->assertRedirect();
        $this->assertDatabaseMissing('controlled_tag_groups', ['id' => $groupId]);
        $this->assertDatabaseHas('tags', ['id' => (int) $tag->id, 'group_name' => 'Application']);
    }

    public function test_material_tag_search_supports_scoped_remote_selectors(): void
    {
        $admin = Admin::query()->create([
            'username' => 'material_tag_search_admin',
            'password' => 'secret-123',
            'email' => 'material-tag-search-admin@example.com',
            'display_name' => 'Material Tag Search Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $imageTag = Tag::query()->create([
            'type' => 'material',
            'group_name' => '图片',
            'name' => '产品图',
            'slug' => 'material-image-product',
            'color' => '',
        ]);
        $knowledgeTag = Tag::query()->create([
            'type' => 'material',
            'group_name' => '知识',
            'name' => '售后',
            'slug' => 'material-knowledge-support',
            'color' => '',
        ]);
        $imageLibrary = ImageLibrary::query()->create([
            'name' => '测试图库',
            'description' => '',
            'image_count' => 1,
        ]);
        $image = Image::query()->create([
            'library_id' => (int) $imageLibrary->id,
            'filename' => 'product.jpg',
            'original_name' => 'product.jpg',
            'file_name' => 'product.jpg',
            'file_path' => 'images/product.jpg',
            'file_size' => 100,
            'mime_type' => 'image/jpeg',
            'width' => 100,
            'height' => 100,
            'tags' => '',
            'used_count' => 0,
            'usage_count' => 0,
        ]);
        $image->tags()->attach((int) $imageTag->id);
        $knowledgeBase = KnowledgeBase::query()->create([
            'name' => '售后知识库',
            'description' => '',
            'content' => '售后说明',
            'character_count' => 4,
            'file_type' => 'markdown',
            'word_count' => 4,
        ]);
        $knowledgeBase->tags()->attach((int) $knowledgeTag->id);

        $imageResponse = $this->actingAs($admin, 'admin')
            ->getJson(route('admin.material-tags.search', ['scope' => 'images', 'q' => '图']));
        $imageResponse->assertOk()
            ->assertJsonFragment(['label' => '图片:产品图'])
            ->assertJsonMissing(['label' => '知识:售后']);

        $knowledgeResponse = $this->actingAs($admin, 'admin')
            ->getJson(route('admin.material-tags.search', ['scope' => 'knowledge', 'q' => '售后']));
        $knowledgeResponse->assertOk()
            ->assertJsonFragment(['label' => '知识:售后'])
            ->assertJsonMissing(['label' => '图片:产品图']);
    }

    public function test_admin_can_create_knowledge_base_from_form(): void
    {
        $admin = Admin::query()->create([
            'username' => 'knowledge_create_admin',
            'password' => 'secret-123',
            'email' => 'knowledge-create-admin@example.com',
            'display_name' => 'Knowledge Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $response = $this->actingAs($admin, 'admin')
            ->post(route('admin.knowledge-bases.store'), [
                'name' => '测试知识库',
                'description' => '测试描述',
                'file_type' => 'markdown',
                'content' => "第一段内容。\n\n第二段内容。",
            ]);

        $response->assertRedirect(route('admin.knowledge-bases.index'));
        $this->assertDatabaseHas('knowledge_bases', [
            'name' => '测试知识库',
            'file_type' => 'markdown',
        ]);
        $this->assertGreaterThan(0, KnowledgeBase::query()->count());
    }

    public function test_admin_can_save_knowledge_metadata_and_case_study_is_not_allowed(): void
    {
        Queue::fake();

        $admin = Admin::query()->create([
            'username' => 'knowledge_metadata_admin',
            'password' => 'secret-123',
            'email' => 'knowledge-metadata-admin@example.com',
            'display_name' => 'Knowledge Metadata Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.knowledge-bases.store'), [
                'name' => 'SJ4060 产品手册',
                'description' => '产品资料',
                'file_type' => 'markdown',
                'knowledge_type' => 'product_manual',
                'knowledge_role' => 'primary_source',
                'importance' => 5,
                'content' => "SJ4060 支持高精度视觉定位。\n\n适合自动点胶场景。",
            ])
            ->assertRedirect(route('admin.knowledge-bases.index'));

        $this->assertDatabaseHas('knowledge_bases', [
            'name' => 'SJ4060 产品手册',
            'knowledge_type' => 'product_manual',
            'knowledge_role' => 'primary_source',
            'importance' => 5,
        ]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.knowledge-bases.store'), [
                'name' => '重复案例资料',
                'description' => '',
                'file_type' => 'markdown',
                'knowledge_type' => 'case_study',
                'knowledge_role' => 'supporting_context',
                'importance' => 3,
                'content' => '案例资料应进入 Case DB。',
            ])
            ->assertSessionHasErrors('knowledge_type');

        $this->assertDatabaseMissing('knowledge_bases', [
            'name' => '重复案例资料',
        ]);
    }

    public function test_admin_can_filter_and_bulk_manage_knowledge_governance(): void
    {
        Queue::fake();

        $admin = Admin::query()->create([
            'username' => 'knowledge_governance_admin',
            'password' => 'secret-123',
            'email' => 'knowledge-governance-admin@example.com',
            'display_name' => 'Knowledge Governance Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);
        $collection = CollectionRecord::query()->create([
            'name' => 'Automation Equipment',
            'slug' => 'automation-equipment-governance',
            'status' => 'active',
            'sort_order' => 1,
        ]);
        $entity = EntityRecord::query()->create([
            'collection_id' => (int) $collection->id,
            'name' => 'SJ4060',
            'entity_type' => 'Product Model',
            'description' => 'Vision doming model',
        ]);
        $productTag = Tag::query()->create([
            'type' => 'material',
            'group_name' => 'Product Model',
            'name' => 'SJ4060',
            'slug' => 'product-model-sj4060-governance',
            'color' => '',
        ]);
        $caseMaterialTag = Tag::query()->create([
            'type' => 'material',
            'group_name' => 'Content Type',
            'name' => 'Case Material',
            'slug' => 'content-type-case-material-governance',
            'color' => '',
        ]);
        $manual = KnowledgeBase::query()->create([
            'collection_id' => (int) $collection->id,
            'name' => 'SJ4060 Manual Governance',
            'description' => 'Manual source',
            'summary' => 'Manual summary',
            'source_url' => 'https://manual.example.com/sj4060',
            'content' => 'SJ4060 manual content',
            'character_count' => 21,
            'file_type' => 'markdown',
            'knowledge_type' => 'product_manual',
            'knowledge_role' => 'primary_source',
            'importance' => 5,
            'status' => 'active',
            'word_count' => 21,
        ]);
        $manual->tags()->attach((int) $productTag->id);
        DB::table('entity_material_links')->insert([
            'entity_id' => (int) $entity->id,
            'linkable_type' => KnowledgeBase::class,
            'linkable_id' => (int) $manual->id,
            'link_role' => 'primary_subject',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $faq = KnowledgeBase::query()->create([
            'collection_id' => (int) $collection->id,
            'name' => 'SJ4060 FAQ Governance',
            'description' => '',
            'content' => 'SJ4060 FAQ content',
            'character_count' => 18,
            'file_type' => 'markdown',
            'knowledge_type' => 'faq',
            'knowledge_role' => 'supporting_context',
            'importance' => 3,
            'status' => 'active',
            'word_count' => 18,
        ]);
        $caseMaterial = KnowledgeBase::query()->create([
            'collection_id' => (int) $collection->id,
            'name' => 'SJ4060 Case Material Governance',
            'description' => '',
            'content' => 'Case source notes should stay in knowledge when they are raw material.',
            'character_count' => 66,
            'file_type' => 'markdown',
            'knowledge_type' => 'reference',
            'knowledge_role' => 'supporting_context',
            'importance' => 3,
            'status' => 'active',
            'word_count' => 66,
        ]);
        $caseMaterial->tags()->attach((int) $caseMaterialTag->id);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.knowledge-bases.index', ['view' => 'product_manuals']))
            ->assertOk()
            ->assertSee(__('admin.knowledge_bases.saved_views.title'))
            ->assertSee('SJ4060 Manual Governance')
            ->assertDontSee('SJ4060 FAQ Governance');

        $this->actingAs($admin, 'admin')
            ->get(route('admin.knowledge-bases.index', [
                'entity_id' => (int) $entity->id,
                'search' => 'manual.example.com',
                'tag_group' => 'Product Model',
                'knowledge_purpose' => 'product_manual',
                'status' => 'active',
            ]))
            ->assertOk()
            ->assertSee('data-knowledge-filter-panel', false)
            ->assertSee('data-knowledge-bulk-panel-shell', false)
            ->assertSee('https://manual.example.com/sj4060')
            ->assertSee('SJ4060 / Product Model')
            ->assertSee(__('admin.knowledge_bases.bulk.title'));

        $this->actingAs($admin, 'admin')
            ->post(route('admin.knowledge-bases.bulk'), [
                'knowledge_ids' => [(int) $manual->id],
                'bulk_action' => 'assign_purpose',
                'knowledge_purpose' => 'faq',
            ])
            ->assertRedirect();
        $this->assertDatabaseHas('knowledge_bases', [
            'id' => (int) $manual->id,
            'knowledge_type' => 'faq',
            'knowledge_role' => 'supporting_context',
            'importance' => 4,
        ]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.knowledge-bases.bulk'), [
                'knowledge_ids' => [(int) $manual->id],
                'bulk_action' => 'set_status',
                'status' => 'inactive',
            ])
            ->assertRedirect();
        $this->assertDatabaseHas('knowledge_bases', [
            'id' => (int) $manual->id,
            'status' => 'inactive',
        ]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.knowledge-bases.bulk'), [
                'knowledge_ids' => [(int) $faq->id],
                'bulk_action' => 'add_tags',
                'bulk_tag_ids' => [(int) $productTag->id],
            ])
            ->assertRedirect();
        $this->assertDatabaseHas('taggables', [
            'tag_id' => (int) $productTag->id,
            'taggable_type' => KnowledgeBase::class,
            'taggable_id' => (int) $faq->id,
        ]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.knowledge-bases.bulk'), [
                'knowledge_ids' => [(int) $faq->id],
                'bulk_action' => 'link_entity',
                'entity_ids' => [(int) $entity->id],
                'entity_relation_type' => 'supporting_reference',
            ])
            ->assertRedirect();
        $this->assertDatabaseHas('entity_material_links', [
            'entity_id' => (int) $entity->id,
            'linkable_type' => KnowledgeBase::class,
            'linkable_id' => (int) $faq->id,
            'link_role' => 'supporting_reference',
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.materials.index'))
            ->assertOk()
            ->assertSee(__('admin.materials.governance_title'));
    }

    public function test_knowledge_governance_page_detects_duplicates_and_conflicts(): void
    {
        $admin = Admin::query()->create([
            'username' => 'knowledge_report_admin',
            'password' => 'secret-123',
            'email' => 'knowledge-report-admin@example.com',
            'display_name' => 'Knowledge Report Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);
        $collection = CollectionRecord::query()->create([
            'name' => 'Automation Equipment Report',
            'slug' => 'automation-equipment-report',
            'status' => 'active',
            'sort_order' => 1,
        ]);

        KnowledgeBase::query()->create([
            'collection_id' => (int) $collection->id,
            'name' => 'SJ4060 Product Manual Voltage A',
            'description' => '',
            'summary' => '',
            'source_url' => 'https://example.com/manuals/sj4060',
            'content' => "Voltage: 220V\nPower: 3kW\nWorking area: 300mm",
            'character_count' => 46,
            'word_count' => 46,
            'file_type' => 'markdown',
            'knowledge_type' => 'technical_spec',
            'knowledge_role' => 'primary_source',
            'importance' => 5,
            'status' => 'active',
        ]);
        KnowledgeBase::query()->create([
            'collection_id' => (int) $collection->id,
            'name' => 'SJ4060 Product Manual Voltage B',
            'description' => '',
            'summary' => '',
            'source_url' => 'https://example.com/manuals/sj4060/',
            'content' => "Voltage: 110V\nPower: 3kW\nWorking area: 300mm",
            'character_count' => 46,
            'word_count' => 46,
            'file_type' => 'markdown',
            'knowledge_type' => 'technical_spec',
            'knowledge_role' => 'primary_source',
            'importance' => 5,
            'status' => 'active',
        ]);
        $duplicateContent = str_repeat('SJ4060 duplicate product specification content with exact source facts. ', 3);
        KnowledgeBase::query()->create([
            'collection_id' => (int) $collection->id,
            'name' => 'SJ4060 Duplicate Source Alpha',
            'description' => '',
            'summary' => '',
            'source_url' => '',
            'content' => $duplicateContent,
            'character_count' => mb_strlen($duplicateContent, 'UTF-8'),
            'word_count' => mb_strlen($duplicateContent, 'UTF-8'),
            'file_type' => 'markdown',
            'knowledge_type' => 'reference',
            'knowledge_role' => 'supporting_context',
            'importance' => 3,
            'status' => 'active',
        ]);
        KnowledgeBase::query()->create([
            'collection_id' => (int) $collection->id,
            'name' => 'SJ4060 Duplicate Source Beta',
            'description' => '',
            'summary' => '',
            'source_url' => '',
            'content' => $duplicateContent,
            'character_count' => mb_strlen($duplicateContent, 'UTF-8'),
            'word_count' => mb_strlen($duplicateContent, 'UTF-8'),
            'file_type' => 'markdown',
            'knowledge_type' => 'reference',
            'knowledge_role' => 'supporting_context',
            'importance' => 3,
            'status' => 'active',
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.knowledge-bases.governance'))
            ->assertOk()
            ->assertSee(__('admin.knowledge_governance.heading'))
            ->assertSee('SJ4060 Duplicate Source Alpha')
            ->assertSee('SJ4060 Duplicate Source Beta')
            ->assertSee('SJ4060 Product Manual Voltage A')
            ->assertSee('SJ4060 Product Manual Voltage B')
            ->assertSee('voltage')
            ->assertSee('220v')
            ->assertSee('110v');
    }

    public function test_knowledge_governance_page_can_filter_by_collection(): void
    {
        $admin = Admin::query()->create([
            'username' => 'knowledge_report_filter_admin',
            'password' => 'secret-123',
            'email' => 'knowledge-report-filter-admin@example.com',
            'display_name' => 'Knowledge Report Filter Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);
        $leftCollection = CollectionRecord::query()->create([
            'name' => 'Automation Filter Left',
            'slug' => 'automation-filter-left',
            'status' => 'active',
            'sort_order' => 1,
        ]);
        $rightCollection = CollectionRecord::query()->create([
            'name' => 'Automation Filter Right',
            'slug' => 'automation-filter-right',
            'status' => 'active',
            'sort_order' => 2,
        ]);
        $duplicateContent = str_repeat('Right collection duplicated source content. ', 4);

        KnowledgeBase::query()->create([
            'collection_id' => (int) $leftCollection->id,
            'name' => 'Left Unique Knowledge',
            'content' => 'Left collection unique source. Voltage: 220V',
            'character_count' => 43,
            'word_count' => 43,
            'file_type' => 'markdown',
            'knowledge_type' => 'reference',
            'knowledge_role' => 'supporting_context',
            'importance' => 3,
            'status' => 'active',
        ]);
        foreach (['A', 'B'] as $suffix) {
            KnowledgeBase::query()->create([
                'collection_id' => (int) $rightCollection->id,
                'name' => 'Right Duplicate Knowledge '.$suffix,
                'content' => $duplicateContent,
                'character_count' => mb_strlen($duplicateContent, 'UTF-8'),
                'word_count' => mb_strlen($duplicateContent, 'UTF-8'),
                'file_type' => 'markdown',
                'knowledge_type' => 'reference',
                'knowledge_role' => 'supporting_context',
                'importance' => 3,
                'status' => 'active',
            ]);
        }

        $this->actingAs($admin, 'admin')
            ->get(route('admin.knowledge-bases.governance', ['collection_id' => (int) $leftCollection->id]))
            ->assertOk()
            ->assertDontSee('Right Duplicate Knowledge A')
            ->assertDontSee('Right Duplicate Knowledge B')
            ->assertSee(__('admin.knowledge_governance.duplicate_empty'));
    }

    public function test_knowledge_governance_ignores_spec_conflicts_between_different_products(): void
    {
        $admin = Admin::query()->create([
            'username' => 'knowledge_report_product_scope_admin',
            'password' => 'secret-123',
            'email' => 'knowledge-report-product-scope-admin@example.com',
            'display_name' => 'Knowledge Report Product Scope Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);
        $collection = CollectionRecord::query()->create([
            'name' => 'Automation Product Scope',
            'slug' => 'automation-product-scope',
            'status' => 'active',
            'sort_order' => 1,
        ]);
        $brand = EntityRecord::query()->create([
            'collection_id' => (int) $collection->id,
            'name' => 'Robota',
            'entity_type' => EntityTypes::BRAND_COMPANY,
            'description' => 'Brand entity shared by multiple products.',
        ]);
        $leftProduct = EntityRecord::query()->create([
            'collection_id' => (int) $collection->id,
            'name' => 'S-331R Soldering Robot',
            'entity_type' => EntityTypes::PRODUCT_LINE,
            'description' => 'Soldering robot product.',
        ]);
        $rightProduct = EntityRecord::query()->create([
            'collection_id' => (int) $collection->id,
            'name' => 'PJ180 Semi-Auto Doming Machine',
            'entity_type' => EntityTypes::PRODUCT_MODEL,
            'description' => 'Doming machine product.',
        ]);
        $leftKnowledge = KnowledgeBase::query()->create([
            'collection_id' => (int) $collection->id,
            'name' => 'S-331R Product Detail',
            'source_url' => 'https://example.com/s331r',
            'content' => "Power: 350W\nWeight: 180kg\nVoltage: 220V",
            'character_count' => 39,
            'word_count' => 39,
            'file_type' => 'markdown',
            'knowledge_type' => 'technical_spec',
            'knowledge_role' => 'primary_source',
            'importance' => 5,
            'status' => 'active',
        ]);
        $rightKnowledge = KnowledgeBase::query()->create([
            'collection_id' => (int) $collection->id,
            'name' => 'PJ180 Product Detail',
            'source_url' => 'https://example.com/pj180',
            'content' => "Power: 3500W\nWeight: 90kg\nVoltage: 220V",
            'character_count' => 40,
            'word_count' => 40,
            'file_type' => 'markdown',
            'knowledge_type' => 'technical_spec',
            'knowledge_role' => 'primary_source',
            'importance' => 5,
            'status' => 'active',
        ]);

        DB::table('entity_material_links')->insert([
            [
                'entity_id' => (int) $brand->id,
                'linkable_type' => KnowledgeBase::class,
                'linkable_id' => (int) $leftKnowledge->id,
                'link_role' => 'supporting_reference',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'entity_id' => (int) $leftProduct->id,
                'linkable_type' => KnowledgeBase::class,
                'linkable_id' => (int) $leftKnowledge->id,
                'link_role' => 'primary_subject',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'entity_id' => (int) $brand->id,
                'linkable_type' => KnowledgeBase::class,
                'linkable_id' => (int) $rightKnowledge->id,
                'link_role' => 'supporting_reference',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'entity_id' => (int) $rightProduct->id,
                'linkable_type' => KnowledgeBase::class,
                'linkable_id' => (int) $rightKnowledge->id,
                'link_role' => 'primary_subject',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.knowledge-bases.governance', ['collection_id' => (int) $collection->id]))
            ->assertOk()
            ->assertSee(__('admin.knowledge_governance.conflict_empty'));
    }

    public function test_admin_can_ai_classify_knowledge_base_and_review_before_saving(): void
    {
        Queue::fake();

        $admin = Admin::query()->create([
            'username' => 'knowledge_ai_classify_admin',
            'password' => 'secret-123',
            'email' => 'knowledge-ai-classify-admin@example.com',
            'display_name' => 'Knowledge AI Classify Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);
        $collection = CollectionRecord::query()->firstOrCreate(
            ['slug' => 'automation-equipment'],
            [
                'name' => 'Automation Equipment',
                'description' => '',
                'status' => 'active',
                'sort_order' => 1,
            ]
        );
        $entity = EntityRecord::query()->create([
            'collection_id' => (int) $collection->id,
            'name' => 'SJ4060',
            'entity_type' => '产品型号',
            'description' => '视觉点胶设备型号',
        ]);
        Tag::query()->create([
            'type' => 'material',
            'group_name' => 'Product Model',
            'name' => 'SJ4060',
            'slug' => 'product-model-sj4060',
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.knowledge-bases.create'))
            ->assertOk()
            ->assertSee('data-ai-analysis-form', false)
            ->assertSee('data-ai-analysis-instructions', false)
            ->assertSee('name="summary"', false)
            ->assertSee('archive', false);

        $response = $this->actingAs($admin, 'admin')->postJson(route('admin.knowledge-bases.analyze'), [
            'title' => 'SJ4060 产品手册',
            'content' => 'Automation Equipment 的 SJ4060 product manual，包含视觉点胶参数和 FAQ。',
            'ai_model_id' => 0,
            'analysis_instructions' => '请重点保留表格中的型号和单位。',
        ]);

        $response->assertOk()
            ->assertJsonPath('fields.collection_id', (int) $collection->id)
            ->assertJsonPath('fields.knowledge_type', 'faq')
            ->assertJsonPath('fields.knowledge_role', 'supporting_context')
            ->assertJsonPath('fields.content', 'Automation Equipment 的 SJ4060 product manual，包含视觉点胶参数和 FAQ。')
            ->assertJsonPath('fields.entity_ids.0', (int) $entity->id)
            ->assertJsonPath('fields.tags.0', 'Product Model:SJ4060');

        $this->assertDatabaseMissing('knowledge_bases', [
            'name' => 'SJ4060 产品手册',
        ]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.knowledge-bases.store'), [
                'name' => 'SJ4060 产品手册',
                'description' => '产品资料',
                'summary' => 'SJ4060 视觉点胶产品手册摘要',
                'file_type' => 'markdown',
                'knowledge_type' => 'product_manual',
                'knowledge_role' => 'archive',
                'importance' => 2,
                'collection_id' => (int) $collection->id,
                'entity_ids' => [(int) $entity->id],
                'entity_relation_type' => 'primary_subject',
                'content' => 'SJ4060 支持视觉定位。',
            ])
            ->assertRedirect(route('admin.knowledge-bases.index'));

        $knowledgeBase = KnowledgeBase::query()->where('name', 'SJ4060 产品手册')->firstOrFail();
        $this->assertDatabaseHas('knowledge_bases', [
            'id' => (int) $knowledgeBase->id,
            'summary' => 'SJ4060 视觉点胶产品手册摘要',
            'knowledge_role' => 'archive',
        ]);
        $this->assertDatabaseHas('entity_material_links', [
            'entity_id' => (int) $entity->id,
            'linkable_type' => KnowledgeBase::class,
            'linkable_id' => (int) $knowledgeBase->id,
            'link_role' => 'primary_subject',
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.knowledge-bases.detail', ['knowledgeBaseId' => (int) $knowledgeBase->id]))
            ->assertOk()
            ->assertSee('data-ai-analysis-form', false)
            ->assertSee('name="summary"', false);
    }

    public function test_admin_can_link_entities_from_entity_and_material_forms(): void
    {
        Queue::fake();

        $admin = Admin::query()->create([
            'username' => 'entity_link_admin',
            'password' => 'secret-123',
            'email' => 'entity-link-admin@example.com',
            'display_name' => 'Entity Link Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);
        $knowledgeBase = KnowledgeBase::query()->create([
            'name' => 'SJ4060 手册',
            'description' => '',
            'content' => 'SJ4060 产品资料',
            'character_count' => 10,
            'file_type' => 'markdown',
            'knowledge_type' => 'product_manual',
            'knowledge_role' => 'primary_source',
            'importance' => 5,
            'word_count' => 10,
        ]);
        $faqKnowledgeBase = KnowledgeBase::query()->create([
            'name' => 'SJ4060 FAQ',
            'description' => '',
            'content' => 'SJ4060 常见问题',
            'character_count' => 10,
            'file_type' => 'markdown',
            'knowledge_type' => 'faq',
            'knowledge_role' => 'supporting_context',
            'importance' => 3,
            'word_count' => 10,
        ]);
        $imageLibrary = ImageLibrary::query()->create([
            'name' => 'SJ4060 图库',
            'description' => '产品图片',
            'image_count' => 1,
            'used_task_count' => 0,
        ]);
        $image = Image::query()->create([
            'library_id' => (int) $imageLibrary->id,
            'filename' => 'sj4060.jpg',
            'original_name' => 'SJ4060 product image',
            'file_path' => 'storage/images/sj4060.jpg',
            'file_size' => 1024,
            'mime_type' => 'image/jpeg',
            'width' => 800,
            'height' => 600,
            'tags' => 'Product Model:SJ4060',
            'used_count' => 0,
            'usage_count' => 0,
        ]);
        TitleLibrary::query()->create([
            'name' => '不应关联的标题库',
            'description' => '',
            'title_count' => 0,
        ]);
        $entity = EntityRecord::query()->create([
            'name' => 'SJ4060',
            'entity_type' => '产品型号',
            'description' => '视觉点胶设备型号',
        ]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.keyword-libraries.store'), [
                'name' => 'SJ4060 关键词库',
                'description' => '',
                'entity_ids' => [(int) $entity->id],
            ])
            ->assertRedirect(route('admin.keyword-libraries.index'));

        $keywordLibrary = KeywordLibrary::query()->where('name', 'SJ4060 关键词库')->firstOrFail();
        $this->assertDatabaseHas('entity_material_links', [
            'entity_id' => (int) $entity->id,
            'linkable_type' => KeywordLibrary::class,
            'linkable_id' => (int) $keywordLibrary->id,
        ]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.entities.store'), [
                'name' => 'SJ4060 Pro',
                'entity_type' => '产品型号',
                'description' => '升级型号',
                'attributes_json' => '{}',
                'knowledge_base_ids' => [(int) $knowledgeBase->id, (int) $faqKnowledgeBase->id],
                'keyword_library_ids' => [(int) $keywordLibrary->id],
                'image_library_ids' => [(int) $imageLibrary->id],
                'image_ids' => [(int) $image->id],
                'knowledge_relation_types' => [
                    (int) $knowledgeBase->id => 'primary_subject',
                    (int) $faqKnowledgeBase->id => 'troubleshooting_reference',
                ],
            ])
            ->assertRedirect(route('admin.entities.index'));

        $linkedEntity = EntityRecord::query()->where('name', 'SJ4060 Pro')->firstOrFail();
        $this->assertDatabaseHas('entity_material_links', [
            'entity_id' => (int) $linkedEntity->id,
            'linkable_type' => KnowledgeBase::class,
            'linkable_id' => (int) $knowledgeBase->id,
            'link_role' => 'primary_subject',
        ]);
        $this->assertDatabaseHas('entity_material_links', [
            'entity_id' => (int) $linkedEntity->id,
            'linkable_type' => KnowledgeBase::class,
            'linkable_id' => (int) $faqKnowledgeBase->id,
            'link_role' => 'troubleshooting_reference',
        ]);
        $this->assertDatabaseHas('entity_material_links', [
            'entity_id' => (int) $linkedEntity->id,
            'linkable_type' => ImageLibrary::class,
            'linkable_id' => (int) $imageLibrary->id,
            'link_role' => 'related',
        ]);
        $this->assertDatabaseHas('entity_material_links', [
            'entity_id' => (int) $linkedEntity->id,
            'linkable_type' => Image::class,
            'linkable_id' => (int) $image->id,
            'link_role' => 'related',
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.entities.edit', ['entityId' => (int) $linkedEntity->id]))
            ->assertOk()
            ->assertSee('data-option-multi-selector', false)
            ->assertSee('name="knowledge_base_ids[]"', false)
            ->assertSee('name="image_ids[]"', false)
            ->assertSee('storage/images/sj4060.jpg', false)
            ->assertSee('name="knowledge_relation_types['.(int) $knowledgeBase->id.']"', false)
            ->assertSee('知识库 / SJ4060 手册 - 关系', false)
            ->assertDontSee('name="title_library_ids[]"', false)
            ->assertDontSee('不应关联的标题库');

        $this->actingAs($admin, 'admin')
            ->get(route('admin.knowledge-bases.detail', ['knowledgeBaseId' => (int) $knowledgeBase->id]))
            ->assertOk()
            ->assertSee('name="entity_relation_type"', false)
            ->assertSee('name="entity_relation_types['.(int) $linkedEntity->id.']"', false)
            ->assertSee('SJ4060 Pro / 产品型号 - 关系', false)
            ->assertSee('primary_subject', false);

        $this->actingAs($admin, 'admin')
            ->put(route('admin.knowledge-bases.detail.update', ['knowledgeBaseId' => (int) $knowledgeBase->id]), [
                'name' => 'SJ4060 手册',
                'description' => '',
                'summary' => '',
                'source_url' => '',
                'content' => 'SJ4060 产品资料',
                'file_type' => 'markdown',
                'knowledge_type' => 'product_manual',
                'knowledge_role' => 'primary_source',
                'importance' => 5,
                'status' => 'active',
                'entity_ids' => [(int) $linkedEntity->id, (int) $entity->id],
                'entity_relation_type' => 'supporting_reference',
                'entity_relation_types' => [
                    (int) $linkedEntity->id => 'primary_subject',
                    (int) $entity->id => 'application_reference',
                ],
            ])
            ->assertRedirect(route('admin.knowledge-bases.detail', ['knowledgeBaseId' => (int) $knowledgeBase->id]));

        $this->assertDatabaseHas('entity_material_links', [
            'entity_id' => (int) $linkedEntity->id,
            'linkable_type' => KnowledgeBase::class,
            'linkable_id' => (int) $knowledgeBase->id,
            'link_role' => 'primary_subject',
        ]);
        $this->assertDatabaseHas('entity_material_links', [
            'entity_id' => (int) $entity->id,
            'linkable_type' => KnowledgeBase::class,
            'linkable_id' => (int) $knowledgeBase->id,
            'link_role' => 'application_reference',
        ]);
    }

    public function test_entity_form_uses_controlled_types_and_link_fields(): void
    {
        $admin = Admin::query()->create([
            'username' => 'entity_type_admin',
            'password' => 'secret-123',
            'email' => 'entity-type-admin@example.com',
            'display_name' => 'Entity Type Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.entities.create'))
            ->assertOk()
            ->assertSee('data-entity-type-select', false)
            ->assertSee('name="canonical_url"', false)
            ->assertSee('产品型号')
            ->assertSee('业务实体');

        $this->actingAs($admin, 'admin')
            ->post(route('admin.entities.store'), [
                'name' => 'SJ4060',
                'entity_type' => '产品型号',
                'description' => '视觉点胶设备',
                'attributes_json' => '{}',
                'canonical_url' => 'https://example.com/sj4060',
                'link_anchor_text' => 'SJ4060 点胶机',
                'link_policy' => 'suggest',
            ])
            ->assertRedirect(route('admin.entities.index'));

        $this->assertDatabaseHas('entities', [
            'name' => 'SJ4060',
            'entity_type' => '产品型号',
            'canonical_url' => 'https://example.com/sj4060',
            'link_anchor_text' => 'SJ4060 点胶机',
            'link_policy' => 'suggest',
        ]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.entities.store'), [
                'name' => '半导体行业',
                'entity_type' => '行业领域',
                'description' => '行业背景',
                'attributes_json' => '{}',
                'canonical_url' => 'https://example.com/industry',
                'link_anchor_text' => '半导体行业',
                'link_policy' => 'suggest',
            ])
            ->assertRedirect(route('admin.entities.index'));

        $this->assertDatabaseHas('entities', [
            'name' => '半导体行业',
            'entity_type' => '行业领域',
            'canonical_url' => '',
            'link_anchor_text' => '',
            'link_policy' => 'disabled',
        ]);
    }

    public function test_admin_can_edit_library_level_tags_only_for_title_libraries(): void
    {
        $admin = Admin::query()->create([
            'username' => 'library_tag_admin',
            'password' => 'secret-123',
            'email' => 'library-tag-admin@example.com',
            'display_name' => 'Library Tag Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);
        $tag = Tag::query()->create([
            'type' => 'material',
            'group_name' => 'Product Line',
            'name' => 'Vision Doming Machine',
            'slug' => 'product-line-vision-doming-library',
            'color' => '',
        ]);
        $keywordLibrary = KeywordLibrary::query()->create([
            'name' => '库级关键词库',
            'description' => '',
            'keyword_count' => 0,
        ]);
        $titleLibrary = TitleLibrary::query()->create([
            'name' => '库级标题库',
            'description' => '',
            'title_count' => 0,
        ]);
        $entity = EntityRecord::query()->create([
            'name' => '历史实体关联',
            'entity_type' => '产品型号',
            'description' => '',
        ]);
        DB::table('entity_material_links')->insert([
            'entity_id' => (int) $entity->id,
            'linkable_type' => TitleLibrary::class,
            'linkable_id' => (int) $titleLibrary->id,
            'link_role' => 'related',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $imageLibrary = ImageLibrary::query()->create([
            'name' => '库级图库',
            'description' => '',
            'image_count' => 0,
            'used_task_count' => 0,
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.title-libraries.index'))
            ->assertOk()
            ->assertSee(route('admin.title-libraries.edit', ['libraryId' => (int) $titleLibrary->id]), false);

        $this->actingAs($admin, 'admin')
            ->put(route('admin.title-libraries.update', ['libraryId' => (int) $titleLibrary->id]), [
                'name' => '库级标题库更新',
                'description' => 'title desc',
                'tag_ids' => [(int) $tag->id],
                'tag_ids_present' => '1',
            ])
            ->assertRedirect(route('admin.title-libraries.edit', ['libraryId' => (int) $titleLibrary->id]));

        $this->assertDatabaseHas('taggables', [
            'tag_id' => (int) $tag->id,
            'taggable_type' => TitleLibrary::class,
            'taggable_id' => (int) $titleLibrary->id,
        ]);
        $this->assertDatabaseMissing('entity_material_links', [
            'entity_id' => (int) $entity->id,
            'linkable_type' => TitleLibrary::class,
            'linkable_id' => (int) $titleLibrary->id,
        ]);
        $this->assertDatabaseMissing('taggables', [
            'tag_id' => (int) $tag->id,
            'taggable_type' => KeywordLibrary::class,
            'taggable_id' => (int) $keywordLibrary->id,
        ]);
        $this->assertDatabaseMissing('taggables', [
            'tag_id' => (int) $tag->id,
            'taggable_type' => ImageLibrary::class,
            'taggable_id' => (int) $imageLibrary->id,
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.keyword-libraries.edit', ['libraryId' => (int) $keywordLibrary->id]))
            ->assertOk()
            ->assertDontSee('data-tag-selector', false)
            ->assertDontSee('Vision Doming Machine');

        $this->actingAs($admin, 'admin')
            ->get(route('admin.image-libraries.edit', ['libraryId' => (int) $imageLibrary->id]))
            ->assertOk()
            ->assertDontSee('data-tag-selector', false)
            ->assertDontSee('Vision Doming Machine');

        $this->actingAs($admin, 'admin')
            ->get(route('admin.title-libraries.edit', ['libraryId' => (int) $titleLibrary->id]))
            ->assertOk()
            ->assertSee('data-tag-selector', false)
            ->assertSee('Vision Doming Machine')
            ->assertDontSee('name="entity_ids[]"', false)
            ->assertDontSee('历史实体关联');
    }

    public function test_admin_can_bulk_delete_titles_from_title_detail_page(): void
    {
        $admin = Admin::query()->create([
            'username' => 'title_detail_bulk_delete_admin',
            'password' => 'secret-123',
            'email' => 'title-detail-bulk-delete-admin@example.com',
            'display_name' => 'Title Detail Bulk Delete Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);
        $library = TitleLibrary::query()->create([
            'name' => '标题详情批量删除库',
            'description' => '',
            'title_count' => 2,
        ]);
        $firstTitle = Title::query()->create([
            'library_id' => (int) $library->id,
            'title' => 'Bulk removable title A',
            'keyword' => '',
            'is_ai_generated' => false,
            'used_count' => 0,
            'usage_count' => 0,
        ]);
        $secondTitle = Title::query()->create([
            'library_id' => (int) $library->id,
            'title' => 'Bulk removable title B',
            'keyword' => '',
            'is_ai_generated' => false,
            'used_count' => 0,
            'usage_count' => 0,
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.title-libraries.detail', ['libraryId' => (int) $library->id]))
            ->assertOk()
            ->assertSee('data-title-select-all', false)
            ->assertSee('data-title-checkbox', false)
            ->assertSee(__('admin.title_detail.bulk_delete'));

        $this->actingAs($admin, 'admin')
            ->post(route('admin.title-libraries.titles.delete', ['libraryId' => (int) $library->id]), [
                'title_ids' => [(int) $firstTitle->id, (int) $secondTitle->id],
            ])
            ->assertRedirect(route('admin.title-libraries.detail', ['libraryId' => (int) $library->id]));

        $this->assertDatabaseMissing('titles', ['id' => (int) $firstTitle->id]);
        $this->assertDatabaseMissing('titles', ['id' => (int) $secondTitle->id]);
        $this->assertDatabaseHas('title_libraries', ['id' => (int) $library->id, 'title_count' => 0]);
    }

    public function test_admin_can_copy_keywords_between_libraries_with_tags(): void
    {
        $admin = Admin::query()->create([
            'username' => 'keyword_bulk_organize_admin',
            'password' => 'secret-123',
            'email' => 'keyword-bulk-organize-admin@example.com',
            'display_name' => 'Keyword Bulk Organize Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);
        $source = KeywordLibrary::query()->create([
            'name' => 'URL采集关键词库',
            'description' => '',
            'keyword_count' => 1,
        ]);
        $target = KeywordLibrary::query()->create([
            'name' => '正式关键词库',
            'description' => '',
            'keyword_count' => 0,
        ]);
        $tag = Tag::query()->create([
            'type' => 'material',
            'group_name' => 'Product Model',
            'name' => 'SJ4060',
            'slug' => 'material-product-model-sj4060',
            'color' => '',
        ]);
        $keyword = Keyword::query()->create([
            'library_id' => (int) $source->id,
            'keyword' => 'SJ4060 dispensing machine',
            'used_count' => 2,
            'usage_count' => 3,
        ]);
        $keyword->tags()->attach((int) $tag->id);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.keyword-libraries.detail', ['libraryId' => (int) $source->id]))
            ->assertOk()
            ->assertSee(route('admin.keyword-libraries.keywords.organize', ['libraryId' => (int) $source->id]), false)
            ->assertSee(__('admin.material_bulk.action_move'));

        $this->actingAs($admin, 'admin')
            ->post(route('admin.keyword-libraries.keywords.organize', ['libraryId' => (int) $source->id]), [
                'bulk_action' => 'copy',
                'target_library_id' => (int) $target->id,
                'keyword_ids' => [(int) $keyword->id],
            ])
            ->assertRedirect(route('admin.keyword-libraries.detail', ['libraryId' => (int) $source->id]));

        $this->assertDatabaseHas('keywords', [
            'library_id' => (int) $source->id,
            'keyword' => 'SJ4060 dispensing machine',
        ]);
        $this->assertDatabaseHas('keywords', [
            'library_id' => (int) $target->id,
            'keyword' => 'SJ4060 dispensing machine',
            'used_count' => 2,
            'usage_count' => 3,
        ]);
        $copiedKeywordId = (int) Keyword::query()
            ->where('library_id', (int) $target->id)
            ->where('keyword', 'SJ4060 dispensing machine')
            ->value('id');
        $this->assertDatabaseHas('taggables', [
            'tag_id' => (int) $tag->id,
            'taggable_type' => Keyword::class,
            'taggable_id' => $copiedKeywordId,
        ]);
        $this->assertDatabaseHas('keyword_libraries', ['id' => (int) $source->id, 'keyword_count' => 1]);
        $this->assertDatabaseHas('keyword_libraries', ['id' => (int) $target->id, 'keyword_count' => 1]);
    }

    public function test_admin_can_copy_images_between_libraries_with_tags_and_entities(): void
    {
        $admin = Admin::query()->create([
            'username' => 'image_bulk_organize_admin',
            'password' => 'secret-123',
            'email' => 'image-bulk-organize-admin@example.com',
            'display_name' => 'Image Bulk Organize Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);
        $collection = CollectionRecord::query()->create([
            'name' => 'Image Bulk Automation Equipment',
            'slug' => 'image-bulk-automation-equipment',
            'description' => '',
            'status' => 'active',
        ]);
        $entity = EntityRecord::query()->create([
            'collection_id' => (int) $collection->id,
            'name' => 'SJ4060',
            'entity_type' => 'product_model',
            'aliases' => '',
            'description' => '',
            'attributes_json' => '{}',
            'source_url' => '',
        ]);
        $source = ImageLibrary::query()->create([
            'collection_id' => (int) $collection->id,
            'name' => 'URL采集图库',
            'description' => '',
            'image_count' => 1,
        ]);
        $target = ImageLibrary::query()->create([
            'collection_id' => (int) $collection->id,
            'name' => '正式图库',
            'description' => '',
            'image_count' => 0,
        ]);
        $tag = Tag::query()->create([
            'type' => 'material',
            'group_name' => 'Topic',
            'name' => 'Product Image',
            'slug' => 'material-topic-product-image',
            'color' => '',
        ]);
        $image = Image::query()->create([
            'library_id' => (int) $source->id,
            'filename' => 'sj4060.jpg',
            'original_name' => 'SJ4060 product image',
            'file_name' => 'sj4060.jpg',
            'file_path' => 'storage/images/sj4060.jpg',
            'file_size' => 1024,
            'mime_type' => 'image/jpeg',
            'width' => 800,
            'height' => 600,
            'tags' => 'Topic:Product Image',
            'used_count' => 4,
            'usage_count' => 5,
        ]);
        $image->tags()->attach((int) $tag->id);
        DB::table('entity_material_links')->insert([
            'entity_id' => (int) $entity->id,
            'linkable_type' => Image::class,
            'linkable_id' => (int) $image->id,
            'link_role' => 'related',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.image-libraries.detail', ['libraryId' => (int) $source->id]))
            ->assertOk()
            ->assertSee(route('admin.image-libraries.images.organize', ['libraryId' => (int) $source->id]), false)
            ->assertSee(__('admin.material_bulk.action_copy'));

        $this->actingAs($admin, 'admin')
            ->post(route('admin.image-libraries.images.organize', ['libraryId' => (int) $source->id]), [
                'bulk_action' => 'copy',
                'target_library_id' => (int) $target->id,
                'image_ids' => [(int) $image->id],
            ])
            ->assertRedirect(route('admin.image-libraries.detail', ['libraryId' => (int) $source->id]));

        $copiedImageId = (int) Image::query()
            ->where('library_id', (int) $target->id)
            ->where('file_path', 'storage/images/sj4060.jpg')
            ->value('id');
        $this->assertGreaterThan(0, $copiedImageId);
        $this->assertDatabaseHas('images', [
            'id' => $copiedImageId,
            'original_name' => 'SJ4060 product image',
            'used_count' => 0,
            'usage_count' => 0,
        ]);
        $this->assertDatabaseHas('taggables', [
            'tag_id' => (int) $tag->id,
            'taggable_type' => Image::class,
            'taggable_id' => $copiedImageId,
        ]);
        $this->assertDatabaseHas('entity_material_links', [
            'entity_id' => (int) $entity->id,
            'linkable_type' => Image::class,
            'linkable_id' => $copiedImageId,
        ]);
        $this->assertDatabaseHas('image_libraries', ['id' => (int) $source->id, 'image_count' => 1]);
        $this->assertDatabaseHas('image_libraries', ['id' => (int) $target->id, 'image_count' => 1]);
    }

    public function test_admin_can_move_titles_between_libraries(): void
    {
        $admin = Admin::query()->create([
            'username' => 'title_bulk_organize_admin',
            'password' => 'secret-123',
            'email' => 'title-bulk-organize-admin@example.com',
            'display_name' => 'Title Bulk Organize Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);
        $source = TitleLibrary::query()->create([
            'name' => 'URL采集标题库',
            'description' => '',
            'title_count' => 1,
        ]);
        $target = TitleLibrary::query()->create([
            'name' => '正式标题库',
            'description' => '',
            'title_count' => 0,
        ]);
        $title = Title::query()->create([
            'library_id' => (int) $source->id,
            'title' => 'Best SJ4060 dispensing machine guide',
            'keyword' => 'SJ4060',
            'is_ai_generated' => true,
            'used_count' => 1,
            'usage_count' => 2,
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.title-libraries.detail', ['libraryId' => (int) $source->id]))
            ->assertOk()
            ->assertSee(route('admin.title-libraries.titles.organize', ['libraryId' => (int) $source->id]), false)
            ->assertSee('data-title-bulk-action', false);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.title-libraries.titles.organize', ['libraryId' => (int) $source->id]), [
                'bulk_action' => 'move',
                'target_library_id' => (int) $target->id,
                'title_ids' => [(int) $title->id],
            ])
            ->assertRedirect(route('admin.title-libraries.detail', ['libraryId' => (int) $source->id]));

        $this->assertDatabaseMissing('titles', ['id' => (int) $title->id]);
        $this->assertDatabaseHas('titles', [
            'library_id' => (int) $target->id,
            'title' => 'Best SJ4060 dispensing machine guide',
            'keyword' => 'SJ4060',
            'is_ai_generated' => true,
            'used_count' => 1,
            'usage_count' => 2,
        ]);
        $this->assertDatabaseHas('title_libraries', ['id' => (int) $source->id, 'title_count' => 0]);
        $this->assertDatabaseHas('title_libraries', ['id' => (int) $target->id, 'title_count' => 1]);
    }

    public function test_knowledge_base_chunk_generation_is_queued(): void
    {
        Queue::fake();

        $admin = Admin::query()->create([
            'username' => 'knowledge_queue_admin',
            'password' => 'secret-123',
            'email' => 'knowledge-queue-admin@example.com',
            'display_name' => 'Knowledge Queue Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.knowledge-bases.store'), [
                'name' => '队列知识库',
                'description' => '测试队列',
                'file_type' => 'markdown',
                'content' => "第一段内容。\n\n第二段内容。",
            ])
            ->assertRedirect(route('admin.knowledge-bases.index'))
            ->assertSessionHas('message', __('admin.knowledge_bases.message.chunk_sync_queued'));

        $knowledgeBase = KnowledgeBase::query()->where('name', '队列知识库')->firstOrFail();
        Queue::assertPushed(SyncKnowledgeBaseChunksJob::class, function (SyncKnowledgeBaseChunksJob $job) use ($knowledgeBase): bool {
            return $job->knowledgeBaseId === (int) $knowledgeBase->id && $job->requireRealEmbedding === false;
        });
        $this->assertSame(KnowledgeBase::CHUNK_SYNC_QUEUED, (string) $knowledgeBase->fresh()->chunk_sync_status);
        $this->assertSame(0, $knowledgeBase->chunks()->count());

        $this->actingAs($admin, 'admin')
            ->getJson(route('admin.knowledge-bases.chunks.status', ['knowledgeBaseId' => (int) $knowledgeBase->id]))
            ->assertOk()
            ->assertJsonPath('status', KnowledgeBase::CHUNK_SYNC_QUEUED)
            ->assertJsonPath('is_active', true);
    }

    public function test_knowledge_chunk_sync_job_updates_completion_status(): void
    {
        $knowledgeBase = KnowledgeBase::query()->create([
            'name' => '异步完成知识库',
            'description' => '',
            'content' => "第一段内容。\n\n第二段内容。",
            'character_count' => 12,
            'file_type' => 'markdown',
            'word_count' => 12,
            'chunk_sync_status' => KnowledgeBase::CHUNK_SYNC_QUEUED,
        ]);

        $job = new SyncKnowledgeBaseChunksJob((int) $knowledgeBase->id, false);
        $job->handle(app(KnowledgeChunkSyncService::class));

        $knowledgeBase->refresh();
        $this->assertSame(KnowledgeBase::CHUNK_SYNC_COMPLETED, (string) $knowledgeBase->chunk_sync_status);
        $this->assertNotNull($knowledgeBase->chunk_sync_started_at);
        $this->assertNotNull($knowledgeBase->chunk_sync_completed_at);
        $this->assertGreaterThan(0, $knowledgeBase->chunks()->count());
    }

    public function test_knowledge_chunk_sync_job_records_failure_status(): void
    {
        $knowledgeBase = KnowledgeBase::query()->create([
            'name' => '异步失败知识库',
            'description' => '',
            'content' => "第一段内容。\n\n第二段内容。",
            'character_count' => 12,
            'file_type' => 'markdown',
            'word_count' => 12,
            'chunk_sync_status' => KnowledgeBase::CHUNK_SYNC_QUEUED,
        ]);

        $job = new SyncKnowledgeBaseChunksJob((int) $knowledgeBase->id, true);

        try {
            $job->handle(app(KnowledgeChunkSyncService::class));
            $this->fail('Expected the sync job to require a real embedding model.');
        } catch (\Throwable $exception) {
            $job->failed($exception);
        }

        $knowledgeBase->refresh();
        $this->assertSame(KnowledgeBase::CHUNK_SYNC_FAILED, (string) $knowledgeBase->chunk_sync_status);
        $this->assertNotNull($knowledgeBase->chunk_sync_failed_at);
        $this->assertStringContainsString('Embedding', (string) $knowledgeBase->chunk_sync_message);
    }

    public function test_admin_can_create_knowledge_base_from_multiple_uploaded_files(): void
    {
        Storage::fake('local');

        $admin = Admin::query()->create([
            'username' => 'knowledge_multi_upload_admin',
            'password' => 'secret-123',
            'email' => 'knowledge-multi-upload-admin@example.com',
            'display_name' => 'Knowledge Multi Upload Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.knowledge-bases.store'), [
                'name' => '批量合并知识库',
                'description' => '多文件合并测试',
                'file_type' => 'markdown',
                'content' => "手动输入的 GEO 背景。\n\n第二段。",
                'knowledge_files' => [
                    UploadedFile::fake()->createWithContent('alpha.md', "# Alpha\nMarkdown 内容"),
                    UploadedFile::fake()->createWithContent('beta.txt', "Beta 文本内容"),
                ],
            ])
            ->assertRedirect(route('admin.knowledge-bases.index'));

        $knowledgeBase = KnowledgeBase::query()->where('name', '批量合并知识库')->firstOrFail();
        $this->assertSame('markdown', (string) $knowledgeBase->file_type);
        $this->assertStringContainsString('# 手动输入内容', (string) $knowledgeBase->content);
        $this->assertStringContainsString('# 文件：alpha.md', (string) $knowledgeBase->content);
        $this->assertStringContainsString('# 文件：beta.txt', (string) $knowledgeBase->content);

        $storedPaths = json_decode((string) $knowledgeBase->file_path, true);
        $this->assertIsArray($storedPaths);
        $this->assertCount(2, $storedPaths);
        foreach ($storedPaths as $storedPath) {
            Storage::disk('local')->assertExists((string) $storedPath);
        }
    }

    public function test_admin_can_create_text_only_knowledge_base_without_manual_name(): void
    {
        Storage::fake('local');

        $admin = Admin::query()->create([
            'username' => 'knowledge_text_auto_name_admin',
            'password' => 'secret-123',
            'email' => 'knowledge-text-auto-name-admin@example.com',
            'display_name' => 'Knowledge Text Auto Name Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.knowledge-bases.store'), [
                'name' => '',
                'description' => '',
                'file_type' => 'markdown',
                'content' => "# GEO 白皮书\n\n这是一段直接粘贴的知识库内容。",
            ])
            ->assertRedirect(route('admin.knowledge-bases.index'));

        $this->assertDatabaseHas('knowledge_bases', [
            'name' => 'GEO 白皮书',
            'file_type' => 'markdown',
        ]);
    }

    public function test_admin_can_submit_knowledge_base_without_generating_chunks(): void
    {
        Storage::fake('local');

        $admin = Admin::query()->create([
            'username' => 'knowledge_submit_only_admin',
            'password' => 'secret-123',
            'email' => 'knowledge-submit-only-admin@example.com',
            'display_name' => 'Knowledge Submit Only Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $this->mock(KnowledgeChunkSyncService::class, function ($mock): void {
            $mock->shouldNotReceive('sync');
        });

        $this->actingAs($admin, 'admin')
            ->post(route('admin.knowledge-bases.store'), [
                'name' => '仅提交知识库',
                'description' => '',
                'file_type' => 'markdown',
                'content' => "# 仅保存\n\n稍后再生成切片。",
                'import_action' => 'save',
            ])
            ->assertRedirect(route('admin.knowledge-bases.index'))
            ->assertSessionHas('message', __('admin.knowledge_bases.message.create_saved'));

        $knowledgeBase = KnowledgeBase::query()->where('name', '仅提交知识库')->firstOrFail();
        $this->assertSame(0, $knowledgeBase->chunks()->count());
    }

    public function test_create_keeps_knowledge_base_when_chunk_sync_is_queued(): void
    {
        Queue::fake();
        Storage::fake('local');

        $admin = Admin::query()->create([
            'username' => 'knowledge_chunk_failure_admin',
            'password' => 'secret-123',
            'email' => 'knowledge-chunk-failure-admin@example.com',
            'display_name' => 'Knowledge Chunk Failure Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.knowledge-bases.store'), [
                'name' => '已保存但切片失败',
                'description' => '',
                'file_type' => 'markdown',
                'content' => "第一段内容。\n\n第二段内容。",
            ])
            ->assertRedirect(route('admin.knowledge-bases.index'))
            ->assertSessionHas('message', __('admin.knowledge_bases.message.chunk_sync_queued'));

        $knowledgeBase = KnowledgeBase::query()->where('name', '已保存但切片失败')->firstOrFail();
        $this->assertDatabaseHas('knowledge_bases', [
            'name' => '已保存但切片失败',
            'content' => "第一段内容。\n\n第二段内容。",
        ]);
        Queue::assertPushed(SyncKnowledgeBaseChunksJob::class, fn (SyncKnowledgeBaseChunksJob $job): bool => $job->knowledgeBaseId === (int) $knowledgeBase->id);
        $this->assertSame(0, $knowledgeBase->chunks()->count());
    }

    public function test_detail_update_keeps_changes_when_chunk_sync_is_queued(): void
    {
        Queue::fake();

        $admin = Admin::query()->create([
            'username' => 'knowledge_detail_chunk_failure_admin',
            'password' => 'secret-123',
            'email' => 'knowledge-detail-chunk-failure-admin@example.com',
            'display_name' => 'Knowledge Detail Chunk Failure Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $knowledgeBase = KnowledgeBase::query()->create([
            'name' => '原始知识库',
            'description' => '',
            'content' => '原始内容',
            'character_count' => 4,
            'file_type' => 'markdown',
            'word_count' => 4,
        ]);

        $this->actingAs($admin, 'admin')
            ->put(route('admin.knowledge-bases.detail.update', ['knowledgeBaseId' => (int) $knowledgeBase->id]), [
                'name' => '更新后的知识库',
                'description' => '更新说明',
                'file_type' => 'markdown',
                'content' => '更新后的正文内容',
            ])
            ->assertRedirect(route('admin.knowledge-bases.detail', ['knowledgeBaseId' => (int) $knowledgeBase->id]))
            ->assertSessionHas('message', __('admin.knowledge_bases.message.chunk_sync_queued'));

        $this->assertDatabaseHas('knowledge_bases', [
            'id' => (int) $knowledgeBase->id,
            'name' => '更新后的知识库',
            'description' => '更新说明',
            'content' => '更新后的正文内容',
        ]);
        Queue::assertPushed(SyncKnowledgeBaseChunksJob::class, fn (SyncKnowledgeBaseChunksJob $job): bool => $job->knowledgeBaseId === (int) $knowledgeBase->id);
    }

    public function test_admin_cannot_upload_more_than_ten_knowledge_files(): void
    {
        Storage::fake('local');

        $admin = Admin::query()->create([
            'username' => 'knowledge_file_limit_admin',
            'password' => 'secret-123',
            'email' => 'knowledge-file-limit-admin@example.com',
            'display_name' => 'Knowledge File Limit Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $files = [];
        for ($index = 1; $index <= 11; $index++) {
            $files[] = UploadedFile::fake()->createWithContent("source-{$index}.md", "第 {$index} 份资料");
        }

        $this->actingAs($admin, 'admin')
            ->from(route('admin.knowledge-bases.create'))
            ->post(route('admin.knowledge-bases.store'), [
                'name' => '超量知识库',
                'description' => '',
                'file_type' => 'markdown',
                'content' => '',
                'knowledge_files' => $files,
            ])
            ->assertRedirect(route('admin.knowledge-bases.create'))
            ->assertSessionHasErrors('knowledge_files');

        $this->assertDatabaseMissing('knowledge_bases', [
            'name' => '超量知识库',
        ]);
    }

    public function test_admin_cannot_upload_knowledge_file_larger_than_fifty_mb(): void
    {
        Storage::fake('local');

        $admin = Admin::query()->create([
            'username' => 'knowledge_file_size_admin',
            'password' => 'secret-123',
            'email' => 'knowledge-file-size-admin@example.com',
            'display_name' => 'Knowledge File Size Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $this->actingAs($admin, 'admin')
            ->from(route('admin.knowledge-bases.create'))
            ->post(route('admin.knowledge-bases.store'), [
                'name' => '超大知识库',
                'description' => '',
                'file_type' => 'markdown',
                'content' => '',
                'knowledge_files' => [
                    UploadedFile::fake()->create('large.md', 50 * 1024 + 1, 'text/markdown'),
                ],
            ])
            ->assertRedirect(route('admin.knowledge-bases.create'))
            ->assertSessionHasErrors('knowledge_files.0');

        $this->assertDatabaseMissing('knowledge_bases', [
            'name' => '超大知识库',
        ]);
    }

    public function test_knowledge_base_index_uses_unified_import_page(): void
    {
        $admin = Admin::query()->create([
            'username' => 'knowledge_unified_import_admin',
            'password' => 'secret-123',
            'email' => 'knowledge-unified-import-admin@example.com',
            'display_name' => 'Knowledge Unified Import Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.knowledge-bases.index'))
            ->assertOk()
            ->assertSee(route('admin.knowledge-bases.create', ['mode' => 'upload']), false)
            ->assertDontSee('upload-modal', false)
            ->assertDontSee('showUploadModal', false);
    }

    public function test_deleting_multi_file_knowledge_base_cleans_all_stored_files(): void
    {
        Storage::fake('local');

        $admin = Admin::query()->create([
            'username' => 'knowledge_delete_files_admin',
            'password' => 'secret-123',
            'email' => 'knowledge-delete-files-admin@example.com',
            'display_name' => 'Knowledge Delete Files Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);

        Storage::disk('local')->put('knowledge-bases/2026/alpha.md', '# Alpha');
        Storage::disk('local')->put('knowledge-bases/2026/beta.md', '# Beta');

        $knowledgeBase = KnowledgeBase::query()->create([
            'name' => '待删除多文件知识库',
            'description' => '',
            'content' => "# Alpha\n\n# Beta",
            'character_count' => 16,
            'file_type' => 'markdown',
            'word_count' => 16,
            'file_path' => json_encode([
                'knowledge-bases/2026/alpha.md',
                'knowledge-bases/2026/beta.md',
            ], JSON_UNESCAPED_SLASHES),
        ]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.knowledge-bases.delete', ['knowledgeBaseId' => (int) $knowledgeBase->id]))
            ->assertRedirect(route('admin.knowledge-bases.index').'#material-list');

        Storage::disk('local')->assertMissing('knowledge-bases/2026/alpha.md');
        Storage::disk('local')->assertMissing('knowledge-bases/2026/beta.md');
        $this->assertDatabaseMissing('knowledge_bases', [
            'id' => (int) $knowledgeBase->id,
        ]);
    }

    public function test_admin_can_refresh_knowledge_chunks_with_real_embedding_model(): void
    {
        Queue::fake();
        Http::fake([
            'https://ai.test/v1/embeddings' => Http::response([
                'data' => [
                    ['embedding' => [0.1, 0.2, 0.3]],
                ],
            ]),
        ]);

        $admin = Admin::query()->create([
            'username' => 'knowledge_refresh_admin',
            'password' => 'secret-123',
            'email' => 'knowledge-refresh-admin@example.com',
            'display_name' => 'Knowledge Refresh Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $embeddingModel = AiModel::query()->create([
            'name' => 'Test Embedding',
            'version' => '',
            'api_key' => app(ApiKeyCrypto::class)->encrypt('test-api-key'),
            'model_id' => 'test-embedding-model',
            'model_type' => 'embedding',
            'api_url' => 'https://ai.test',
            'failover_priority' => 1,
            'daily_limit' => 100,
            'used_today' => 0,
            'total_used' => 0,
            'status' => 'active',
        ]);

        $knowledgeBase = KnowledgeBase::query()->create([
            'name' => '待向量化知识库',
            'description' => 'desc',
            'content' => 'GEOFlow 支持知识库切片和向量化检索。',
            'character_count' => 22,
            'file_type' => 'markdown',
            'word_count' => 22,
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.knowledge-bases.index'))
            ->assertOk()
            ->assertSee(__('admin.knowledge_bases.refresh_chunks'));

        $this->actingAs($admin, 'admin')
            ->post(route('admin.knowledge-bases.chunks.refresh', ['knowledgeBaseId' => (int) $knowledgeBase->id]))
            ->assertRedirect(route('admin.knowledge-bases.index'))
            ->assertSessionHas('message', __('admin.knowledge_bases.message.chunk_sync_queued'));

        Queue::assertPushed(SyncKnowledgeBaseChunksJob::class, function (SyncKnowledgeBaseChunksJob $job) use ($knowledgeBase): bool {
            return $job->knowledgeBaseId === (int) $knowledgeBase->id && $job->requireRealEmbedding === true;
        });
        $this->assertSame(0, $knowledgeBase->chunks()->count());
    }

    public function test_knowledge_base_list_uses_friendly_refresh_chunks_progress_ui(): void
    {
        $admin = Admin::query()->create([
            'username' => 'knowledge_refresh_ui_admin',
            'password' => 'secret-123',
            'email' => 'knowledge-refresh-ui-admin@example.com',
            'display_name' => 'Knowledge Refresh UI Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);

        AiModel::query()->create([
            'name' => 'Test Embedding',
            'version' => '',
            'api_key' => app(ApiKeyCrypto::class)->encrypt('test-api-key'),
            'model_id' => 'test-embedding-model',
            'model_type' => 'embedding',
            'api_url' => 'https://ai.test',
            'failover_priority' => 1,
            'daily_limit' => 100,
            'used_today' => 0,
            'total_used' => 0,
            'status' => 'active',
        ]);

        KnowledgeBase::query()->create([
            'name' => '待更新切片知识库',
            'description' => 'desc',
            'content' => 'GEOFlow 支持知识库切片和向量化检索。',
            'character_count' => 22,
            'file_type' => 'markdown',
            'word_count' => 22,
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.knowledge-bases.index'))
            ->assertOk()
            ->assertSee('data-knowledge-refresh-modal', false)
            ->assertSee('data-refresh-chunks-form', false)
            ->assertSee('data-refresh-progress', false)
            ->assertSee(__('admin.knowledge_bases.refresh_confirm_title'))
            ->assertSee(__('admin.knowledge_bases.refresh_progress_initial'))
            ->assertDontSee(__('admin.knowledge_bases.confirm_refresh_chunks', ['name' => '待更新切片知识库']));
    }

    public function test_refresh_knowledge_chunks_requires_embedding_model(): void
    {
        Http::fake();

        $admin = Admin::query()->create([
            'username' => 'knowledge_no_embedding_admin',
            'password' => 'secret-123',
            'email' => 'knowledge-no-embedding-admin@example.com',
            'display_name' => 'Knowledge No Embedding Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $knowledgeBase = KnowledgeBase::query()->create([
            'name' => '无向量模型知识库',
            'description' => 'desc',
            'content' => '没有 embedding 模型时不能把 fallback 当作真实向量。',
            'character_count' => 28,
            'file_type' => 'markdown',
            'word_count' => 28,
        ]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.knowledge-bases.chunks.refresh', ['knowledgeBaseId' => (int) $knowledgeBase->id]))
            ->assertRedirect(route('admin.knowledge-bases.index'))
            ->assertSessionHasErrors();

        $this->assertSame(0, $knowledgeBase->chunks()->count());
        Http::assertNothingSent();
    }

    public function test_admin_can_create_url_import_job_without_url_scheme(): void
    {
        Http::fake([
            'https://example.test/report' => Http::response(
                '<!doctype html><html><head><title>示例项目</title><meta name="description" content="示例项目页面摘要"></head><body><main><h1>示例项目</h1><p>这是一个用于采集测试的 GEO 页面。</p></main></body></html>',
                200,
                ['Content-Type' => 'text/html; charset=utf-8']
            ),
        ]);

        $admin = Admin::query()->create([
            'username' => 'url_import_admin',
            'password' => 'secret-123',
            'email' => 'url-import-admin@example.com',
            'display_name' => 'Url Import Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);
        $this->createReadyUrlImportAiModel();

        $response = $this->actingAs($admin, 'admin')
            ->post(route('admin.url-import.store'), [
                'url' => 'example.test/report',
                'project_name' => '示例项目',
                'outputs' => ['knowledge', 'keywords', 'titles'],
            ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('url_import_jobs', [
            'url' => 'example.test/report',
            'normalized_url' => 'https://example.test/report',
            'source_domain' => 'example.test',
            'status' => 'queued',
            'created_by' => 'url_import_admin',
        ]);

        $job = UrlImportJob::query()->firstOrFail();
        config(['app.url' => 'https://configured.example']);
        $runPath = route('admin.url-import.run', ['jobId' => (int) $job->id], false);
        $statusPath = route('admin.url-import.status', ['jobId' => (int) $job->id], false);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.url-import.show', ['jobId' => (int) $job->id]))
            ->assertOk()
            ->assertSee('name="csrf-token"', false)
            ->assertSee('data-run-url="'.$runPath.'"', false)
            ->assertSee('data-status-url="'.$statusPath.'"', false)
            ->assertSee('data-status="queued"', false)
            ->assertSee('data-has-result="0"', false)
            ->assertDontSee('https://configured.example'.$runPath, false)
            ->assertDontSee('https://configured.example'.$statusPath, false)
            ->assertDontSee('sessionStorage', false)
            ->assertDontSee('setTimeout(() => window.location.reload(), 1000)', false);

        $this->assertDatabaseHas('url_import_jobs', [
            'id' => (int) $job->id,
            'status' => 'queued',
            'current_step' => 'queued',
        ]);
    }

    public function test_url_import_requires_ready_ai_model_before_creating_job(): void
    {
        $admin = Admin::query()->create([
            'username' => 'url_import_no_model_admin',
            'password' => 'secret-123',
            'email' => 'url-import-no-model@example.com',
            'display_name' => 'Url Import No Model Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.url-import.store'), [
                'url' => 'example.test/report',
                'outputs' => ['knowledge', 'keywords', 'titles'],
            ])
            ->assertRedirect(route('admin.ai-models.index'))
            ->assertSessionHasErrors('ai_model');

        $this->assertDatabaseCount('url_import_jobs', 0);
    }

    public function test_url_import_can_prioritize_selected_ai_model(): void
    {
        Http::fake([
            'https://source.test/manual' => Http::response(
                '<!doctype html><html><head><title>SJ4060 Manual</title><meta name="description" content="Product manual"></head><body><main><h1>SJ4060 Manual</h1><p>SJ4060 supports vision dispensing workflows.</p></main></body></html>',
                200,
                ['Content-Type' => 'text/html; charset=utf-8']
            ),
            'https://selected-ai.test/v1/chat/completions' => Http::sequence()
                ->push([
                    'id' => 'chatcmpl-clean-selected',
                    'object' => 'chat.completion',
                    'choices' => [[
                        'index' => 0,
                        'message' => [
                            'role' => 'assistant',
                            'content' => json_encode([
                                'clean_title' => 'Selected Model Clean Title',
                                'clean_summary' => 'Selected model cleaned the product manual.',
                                'clean_text' => 'SJ4060 supports vision dispensing workflows.',
                                'core_business' => [
                                    'products_services' => ['SJ4060'],
                                    'commercial_scenarios' => ['vision dispensing workflows'],
                                ],
                                'entities' => ['SJ4060'],
                                'facts' => ['SJ4060 supports vision dispensing workflows.'],
                                'noise_removed' => [],
                            ], JSON_UNESCAPED_UNICODE),
                        ],
                        'finish_reason' => 'stop',
                    ]],
                ], 200)
                ->push([
                    'id' => 'chatcmpl-knowledge-selected',
                    'object' => 'chat.completion',
                    'choices' => [[
                        'index' => 0,
                        'message' => [
                            'role' => 'assistant',
                            'content' => json_encode([
                                'summary' => 'Selected model generated the knowledge summary.',
                                'library_name' => 'Selected Model Material',
                                'knowledge_markdown' => "# Selected Model Material\n\n- SJ4060 supports vision dispensing workflows.",
                            ], JSON_UNESCAPED_UNICODE),
                        ],
                        'finish_reason' => 'stop',
                    ]],
                ], 200),
        ]);

        AiModel::query()->create([
            'name' => 'Default URL Import Model',
            'version' => '',
            'api_key' => app(ApiKeyCrypto::class)->encrypt('test-key'),
            'model_id' => 'default-chat',
            'model_type' => 'chat',
            'api_url' => 'https://default-ai.test/v1',
            'failover_priority' => 1,
            'daily_limit' => 100,
            'used_today' => 0,
            'total_used' => 0,
            'status' => 'active',
        ]);
        $selectedModel = AiModel::query()->create([
            'name' => 'Selected URL Import Model',
            'version' => '',
            'api_key' => app(ApiKeyCrypto::class)->encrypt('test-key'),
            'model_id' => 'selected-chat',
            'model_type' => 'chat',
            'api_url' => 'https://selected-ai.test/v1',
            'failover_priority' => 50,
            'daily_limit' => 100,
            'used_today' => 0,
            'total_used' => 0,
            'status' => 'active',
        ]);

        $admin = Admin::query()->create([
            'username' => 'url_import_selected_model',
            'password' => 'secret-123',
            'email' => 'url-import-selected-model@example.com',
            'display_name' => 'Url Import Selected Model',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.url-import'))
            ->assertOk()
            ->assertSee('name="ai_model_id"', false)
            ->assertSee('Selected URL Import Model');

        $this->actingAs($admin, 'admin')
            ->post(route('admin.url-import.store'), [
                'url' => 'source.test/manual',
                'ai_model_id' => (int) $selectedModel->id,
                'outputs' => ['knowledge'],
            ])
            ->assertRedirect();

        $job = UrlImportJob::query()->firstOrFail();
        $options = json_decode((string) $job->options_json, true);
        $this->assertSame((int) $selectedModel->id, (int) ($options['ai_model_id'] ?? 0));

        $this->actingAs($admin, 'admin')
            ->postJson(route('admin.url-import.run', ['jobId' => (int) $job->id]))
            ->assertOk()
            ->assertJsonPath('status', 'completed');

        $result = json_decode((string) $job->refresh()->result_json, true);
        $this->assertSame((int) $selectedModel->id, (int) data_get($result, 'analysis.model.id'));
        $this->assertSame('Selected Model Material', data_get($result, 'analysis.library_name'));
    }

    public function test_url_import_preview_shows_all_generated_titles(): void
    {
        $admin = Admin::query()->create([
            'username' => 'url_import_preview_admin',
            'password' => 'secret-123',
            'email' => 'url-import-preview-admin@example.com',
            'display_name' => 'Url Import Preview Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $titles = collect(range(1, 13))
            ->map(static fn (int $index): string => '标题建议 '.$index)
            ->all();
        $job = UrlImportJob::query()->create([
            'url' => 'https://example.test/report',
            'normalized_url' => 'https://example.test/report',
            'source_domain' => 'example.test',
            'page_title' => '预览测试',
            'status' => 'completed',
            'current_step' => 'preview',
            'progress_percent' => 100,
            'options_json' => json_encode(['title_count' => 13], JSON_UNESCAPED_UNICODE),
            'result_json' => json_encode([
                'page' => ['title' => '预览测试'],
                'analysis' => [
                    'summary' => '预览测试摘要',
                    'keywords' => ['测试关键词'],
                    'titles' => $titles,
                    'knowledge_markdown' => '# 预览测试',
                ],
            ], JSON_UNESCAPED_UNICODE),
            'error_message' => '',
            'created_by' => 'url_import_preview_admin',
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.url-import.show', ['jobId' => (int) $job->id]))
            ->assertOk()
            ->assertSee('标题建议 13')
            ->assertSee('(13)');
    }

    public function test_admin_can_run_and_commit_url_import_job(): void
    {
        Http::fake([
            'https://example.test/report' => Http::response(
                '<!doctype html><html><head><title>GEO 内容报告</title><meta name="description" content="这是一份关于 GEO 内容系统的页面摘要"><meta property="og:image" content="https://example.test/cover.jpg"></head><body><article><h1>GEO 内容报告</h1><p>GEO 内容系统需要知识库、关键词库和标题库协同工作。</p><img src="/body.png" alt="正文配图"></article></body></html>',
                200,
                ['Content-Type' => 'text/html; charset=utf-8']
            ),
            'https://ai.test/v1/chat/completions' => Http::sequence()
                ->push([
                    'choices' => [[
                        'message' => [
                            'content' => json_encode([
                                'clean_title' => 'GEO 内容报告',
                                'clean_summary' => 'GEO 内容系统需要知识库、关键词库和标题库协同工作。',
                                'clean_text' => 'GEO 内容系统需要知识库、关键词库和标题库协同工作。',
                                'core_business' => [
                                    'industry' => 'GEO 内容系统',
                                    'products_services' => ['内容资产管理'],
                                    'target_audience' => ['内容运营团队'],
                                    'commercial_scenarios' => ['AI 搜索优化'],
                                    'value_proposition' => '沉淀真实素材并自动生成内容',
                                    'evidence_limits' => '仅来自测试页面',
                                ],
                                'entities' => ['GEO 内容系统', '知识库', '关键词库'],
                                'facts' => ['GEO 内容系统需要知识库、关键词库和标题库协同工作。'],
                                'noise_removed' => [],
                            ], JSON_UNESCAPED_UNICODE),
                        ],
                    ]],
                ], 200)
                ->push([
                    'choices' => [[
                        'message' => [
                            'content' => json_encode([
                                'summary' => 'GEO 内容系统需要知识库、关键词库和标题库协同工作。',
                                'library_name' => 'GEO 内容报告',
                                'knowledge_markdown' => "# GEO 内容报告\n\n- 来源 URL：https://example.test/report\n- 原子化事实：GEO 内容系统需要知识库、关键词库和标题库协同工作。",
                            ], JSON_UNESCAPED_UNICODE),
                        ],
                    ]],
                ], 200)
                ->push([
                    'choices' => [[
                        'message' => [
                            'content' => json_encode(['keywords' => ['内容资产', '知识库', '标题库', '关键词库']], JSON_UNESCAPED_UNICODE),
                        ],
                    ]],
                ], 200)
                ->push([
                    'choices' => [[
                        'message' => [
                            'content' => json_encode(['titles' => ['GEO 内容系统如何建立可信内容资产', '知识库如何支撑 GEO 内容生成']], JSON_UNESCAPED_UNICODE),
                        ],
                    ]],
                ], 200),
        ]);

        $admin = Admin::query()->create([
            'username' => 'url_import_runner',
            'password' => 'secret-123',
            'email' => 'url-import-runner@example.com',
            'display_name' => 'Url Import Runner',
            'role' => 'admin',
            'status' => 'active',
        ]);
        $this->createReadyUrlImportAiModel();
        $collection = CollectionRecord::query()->create([
            'name' => 'URL 采集 Collection',
            'slug' => 'url-import-collection',
            'description' => '',
            'status' => 'active',
            'sort_order' => 10,
        ]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.url-import.store'), [
                'url' => 'example.test/report',
                'collection_id' => (int) $collection->id,
                'title_count' => 20,
                'outputs' => ['knowledge', 'keywords', 'titles'],
            ])
            ->assertRedirect();

        $job = UrlImportJob::query()->firstOrFail();
        $options = json_decode((string) $job->options_json, true);
        $this->assertSame((int) $collection->id, (int) ($options['collection_id'] ?? 0));
        $this->assertSame(20, (int) ($options['title_count'] ?? 0));

        $this->actingAs($admin, 'admin')
            ->postJson(route('admin.url-import.run', ['jobId' => (int) $job->id]))
            ->assertOk()
            ->assertJsonPath('status', 'completed')
            ->assertJsonPath('current_step', 'preview')
            ->assertJsonPath('result_ready', true)
            ->assertJsonPath('progress_percent', 100);

        $job->refresh();
        $this->assertSame('completed', $job->status);
        $this->assertStringContainsString('GEO 内容报告', (string) $job->result_json);
        $this->assertDatabaseHas('url_import_job_logs', [
            'job_id' => (int) $job->id,
            'step' => 'keywords',
        ]);
        $this->assertDatabaseHas('url_import_job_logs', [
            'job_id' => (int) $job->id,
            'step' => 'preview',
        ]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.url-import.commit', ['jobId' => (int) $job->id]))
            ->assertRedirect(route('admin.url-import.show', ['jobId' => (int) $job->id]));

        $this->assertDatabaseHas('knowledge_bases', ['name' => 'GEO 内容报告 知识库', 'collection_id' => (int) $collection->id]);
        $this->assertDatabaseHas('keyword_libraries', ['name' => 'GEO 内容报告 关键词库', 'collection_id' => (int) $collection->id]);
        $this->assertDatabaseHas('title_libraries', ['name' => 'GEO 内容报告 标题库', 'collection_id' => (int) $collection->id]);
        $this->assertDatabaseMissing('image_libraries', ['name' => 'GEO 内容报告 图片库']);
        $this->assertDatabaseHas('url_import_jobs', [
            'id' => (int) $job->id,
            'current_step' => 'imported',
        ]);
    }

    public function test_url_import_analysis_prefers_active_ai_model_and_backend_prompts(): void
    {
        Http::fake([
            'https://source.test/report' => Http::response(
                '<!doctype html><html><head><title>原始页面标题</title><meta name="description" content="原始页面摘要"></head><body><article><h1>原始页面标题</h1><p>页面正文包含 CRM、GEO 和知识库信息。</p><img src="/hero.png" alt="GEO 服务主图"></article></body></html>',
                200,
                ['Content-Type' => 'text/html; charset=utf-8']
            ),
            'https://ai.test/v1/chat/completions' => Http::sequence()
                ->push([
                    'id' => 'chatcmpl-clean',
                    'object' => 'chat.completion',
                    'choices' => [[
                        'index' => 0,
                        'message' => [
                            'role' => 'assistant',
                            'content' => json_encode([
                                'clean_title' => 'AI清洗标题',
                                'clean_summary' => 'AI 生成的页面摘要，用于描述页面的核心内容和素材价值。',
                                'clean_text' => '页面正文包含 CRM、GEO 和知识库信息。',
                                'entities' => ['CRM', 'GEO', '知识库'],
                                'facts' => ['页面正文包含 CRM、GEO 和知识库信息。'],
                                'noise_removed' => ['导航'],
                            ], JSON_UNESCAPED_UNICODE),
                        ],
                        'finish_reason' => 'stop',
                    ]],
                ], 200)
                ->push([
                    'id' => 'chatcmpl-knowledge',
                    'object' => 'chat.completion',
                    'choices' => [[
                        'index' => 0,
                        'message' => [
                            'role' => 'assistant',
                            'content' => json_encode([
                                'summary' => 'AI 生成的页面摘要，用于描述页面的核心内容和素材价值。',
                                'library_name' => 'AI命名素材',
                                'knowledge_markdown' => "# AI知识库\n\n- 来源真实\n- 可用于 GEO 内容生成",
                            ], JSON_UNESCAPED_UNICODE),
                        ],
                        'finish_reason' => 'stop',
                    ]],
                ], 200)
                ->push([
                    'id' => 'chatcmpl-keywords',
                    'object' => 'chat.completion',
                    'choices' => [[
                        'index' => 0,
                        'message' => [
                            'role' => 'assistant',
                            'content' => json_encode(['keywords' => ['AI关键词一', 'AI关键词二', '查看详情']], JSON_UNESCAPED_UNICODE),
                        ],
                        'finish_reason' => 'stop',
                    ]],
                ], 200)
                ->push([
                    'id' => 'chatcmpl-titles',
                    'object' => 'chat.completion',
                    'choices' => [[
                        'index' => 0,
                        'message' => [
                            'role' => 'assistant',
                            'content' => json_encode(['titles' => ['AI生成标题一', 'AI生成标题二']], JSON_UNESCAPED_UNICODE),
                        ],
                        'finish_reason' => 'stop',
                    ]],
                ], 200),
        ]);

        Prompt::query()->create([
            'name' => '关键词提示词',
            'type' => 'keyword',
            'content' => '请提炼关键词',
            'variables' => '',
        ]);
        Prompt::query()->create([
            'name' => '正文提示词',
            'type' => 'content',
            'content' => '请生成真实可信内容',
            'variables' => '',
        ]);
        AiModel::query()->create([
            'name' => 'AI Test Model',
            'version' => '',
            'api_key' => app(ApiKeyCrypto::class)->encrypt('test-key'),
            'model_id' => 'test-chat',
            'model_type' => 'chat',
            'api_url' => 'https://ai.test/v1',
            'failover_priority' => 1,
            'daily_limit' => 100,
            'used_today' => 0,
            'total_used' => 0,
            'status' => 'active',
        ]);

        $admin = Admin::query()->create([
            'username' => 'url_import_ai_runner',
            'password' => 'secret-123',
            'email' => 'url-import-ai-runner@example.com',
            'display_name' => 'Url Import AI Runner',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.url-import.store'), [
                'url' => 'source.test/report',
                'outputs' => ['knowledge', 'keywords', 'titles'],
            ])
            ->assertRedirect();

        $job = UrlImportJob::query()->firstOrFail();
        $this->actingAs($admin, 'admin')
            ->postJson(route('admin.url-import.run', ['jobId' => (int) $job->id]))
            ->assertOk()
            ->assertJsonPath('status', 'completed');

        $result = json_decode((string) $job->refresh()->result_json, true);

        $this->assertSame('ai', $result['analysis']['analysis_source'] ?? null);
        $this->assertSame('AI命名素材', $result['analysis']['library_name'] ?? null);
        $this->assertContains('AI关键词一', $result['analysis']['keywords'] ?? []);
        $this->assertNotContains('查看详情', $result['analysis']['keywords'] ?? []);
        $this->assertContains('AI生成标题一', $result['analysis']['titles'] ?? []);
        $this->assertArrayNotHasKey('images', $result['analysis'] ?? []);
    }

    public function test_url_import_outputs_skip_unselected_ai_assets(): void
    {
        Http::fake([
            'https://source.test/knowledge-only' => Http::response(
                '<!doctype html><html><head><title>知识页</title><meta name="description" content="知识页摘要"></head><body><article><h1>知识页</h1><p>这是一段只需要生成知识库的页面正文。</p></article></body></html>',
                200,
                ['Content-Type' => 'text/html; charset=utf-8']
            ),
            'https://ai.test/v1/chat/completions' => Http::sequence()
                ->push(['choices' => [['message' => ['content' => json_encode([
                    'clean_title' => '知识页',
                    'clean_summary' => '这是一段只需要生成知识库的页面正文。',
                    'clean_text' => '这是一段只需要生成知识库的页面正文。',
                    'entities' => ['知识库'],
                    'facts' => ['页面只需要生成知识库。'],
                    'noise_removed' => [],
                ], JSON_UNESCAPED_UNICODE)]]]], 200)
                ->push(['choices' => [['message' => ['content' => json_encode([
                    'summary' => '这是一段只需要生成知识库的页面正文。',
                    'library_name' => '知识页素材',
                    'knowledge_markdown' => "# 知识页素材\n\n- 页面只需要生成知识库。",
                ], JSON_UNESCAPED_UNICODE)]]]], 200),
        ]);

        AiModel::query()->create([
            'name' => 'AI Test Model',
            'version' => '',
            'api_key' => app(ApiKeyCrypto::class)->encrypt('test-key'),
            'model_id' => 'test-chat',
            'model_type' => 'chat',
            'api_url' => 'https://ai.test/v1',
            'failover_priority' => 1,
            'daily_limit' => 100,
            'used_today' => 0,
            'total_used' => 0,
            'status' => 'active',
        ]);
        $admin = Admin::query()->create([
            'username' => 'url_import_outputs_admin',
            'password' => 'secret-123',
            'email' => 'url-import-outputs@example.com',
            'display_name' => 'Url Import Outputs Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.url-import.store'), [
                'url' => 'source.test/knowledge-only',
                'outputs' => ['knowledge'],
            ])
            ->assertRedirect();

        $job = UrlImportJob::query()->firstOrFail();
        $this->actingAs($admin, 'admin')
            ->postJson(route('admin.url-import.run', ['jobId' => (int) $job->id]))
            ->assertOk()
            ->assertJsonPath('status', 'completed');

        $result = json_decode((string) $job->refresh()->result_json, true);

        $this->assertSame('ai', $result['analysis']['analysis_source'] ?? null);
        $this->assertSame('知识页素材', $result['analysis']['library_name'] ?? null);
        $this->assertSame([], $result['analysis']['keywords'] ?? null);
        $this->assertSame([], $result['analysis']['titles'] ?? null);
        $this->assertSame([], data_get($result, 'analysis.entity_extraction.entities'));
        $this->assertSame([], data_get($result, 'analysis.entity_extraction.cases'));
        Http::assertSentCount(3);
    }

    public function test_url_import_accepts_ai_json_wrapped_in_markdown_or_reasoning_text(): void
    {
        Http::fake([
            'https://source.test/wrapped-json' => Http::response(
                '<!doctype html><html><head><title>CRM 业务页</title><meta name="description" content="CRM 业务页摘要"></head><body><article><h1>CRM 业务页</h1><p>面向销售团队的客户数据管理和流程自动化服务。</p></article></body></html>',
                200,
                ['Content-Type' => 'text/html; charset=utf-8']
            ),
            'https://ai.test/v1/chat/completions' => Http::sequence()
                ->push(['choices' => [['message' => ['content' => "<think>先分析页面主体。</think>\n```json\n".json_encode([
                    'clean_title' => 'CRM 业务页',
                    'clean_summary' => '面向销售团队的客户数据管理和流程自动化服务。',
                    'clean_text' => '面向销售团队的客户数据管理和流程自动化服务。',
                    'core_business' => ['industry' => 'CRM', 'products_services' => ['客户数据管理', '流程自动化']],
                    'entities' => ['CRM', '销售团队'],
                    'facts' => ['页面介绍客户数据管理和流程自动化服务。'],
                    'noise_removed' => ['导航'],
                ], JSON_UNESCAPED_UNICODE)."\n```"]]]], 200)
                ->push(['choices' => [['message' => ['content' => "以下是结构化 JSON：\n".json_encode([
                    'summary' => '面向销售团队的客户数据管理和流程自动化服务。',
                    'library_name' => 'CRM 业务知识库',
                    'knowledge_markdown' => "# CRM 业务知识库\n\n- 来源 URL：https://source.test/wrapped-json\n- 服务面向销售团队。",
                ], JSON_UNESCAPED_UNICODE)]]]], 200)
                ->push(['choices' => [['message' => ['content' => "```json\n".json_encode(['keywords' => ['客户管理', '销售自动化', 'CRM选型']], JSON_UNESCAPED_UNICODE)."\n```"]]]], 200)
                ->push(['choices' => [['message' => ['content' => "已生成：\n".json_encode(['titles' => ['客户管理系统如何帮助销售团队提升效率']], JSON_UNESCAPED_UNICODE)."\n请查收。"]]]], 200),
        ]);

        $admin = Admin::query()->create([
            'username' => 'url_import_wrapped_json_admin',
            'password' => 'secret-123',
            'email' => 'url-import-wrapped-json@example.com',
            'display_name' => 'Url Import Wrapped Json Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);
        $this->createReadyUrlImportAiModel();

        $this->actingAs($admin, 'admin')
            ->post(route('admin.url-import.store'), [
                'url' => 'source.test/wrapped-json',
                'outputs' => ['knowledge', 'keywords', 'titles'],
            ])
            ->assertRedirect();

        $job = UrlImportJob::query()->firstOrFail();

        $this->actingAs($admin, 'admin')
            ->postJson(route('admin.url-import.run', ['jobId' => (int) $job->id]))
            ->assertOk()
            ->assertJsonPath('status', 'completed');

        $result = json_decode((string) $job->refresh()->result_json, true);

        $this->assertSame('CRM 业务知识库', $result['analysis']['library_name'] ?? null);
        $this->assertContains('客户管理', $result['analysis']['keywords'] ?? []);
        $this->assertContains('客户管理系统如何帮助销售团队提升效率', $result['analysis']['titles'] ?? []);
    }

    public function test_url_import_accepts_plain_text_lists_from_ai_for_keywords_and_titles(): void
    {
        Http::fake([
            'https://source.test/plain-lists' => Http::response(
                '<!doctype html><html><head><title>CRM 自动化页</title><meta name="description" content="CRM 自动化页摘要"></head><body><article><h1>CRM 自动化页</h1><p>面向中小企业的客户数据统一、销售管道管理和营销自动化服务。</p></article></body></html>',
                200,
                ['Content-Type' => 'text/html; charset=utf-8']
            ),
            'https://ai.test/v1/chat/completions' => Http::sequence()
                ->push(['choices' => [['message' => ['content' => json_encode([
                    'clean_title' => 'CRM 自动化页',
                    'clean_summary' => '面向中小企业的客户数据统一、销售管道管理和营销自动化服务。',
                    'clean_text' => '面向中小企业的客户数据统一、销售管道管理和营销自动化服务。',
                    'core_business' => ['industry' => 'CRM', 'products_services' => ['销售管道管理', '营销自动化']],
                    'entities' => ['CRM', '中小企业'],
                    'facts' => ['页面介绍客户数据统一、销售管道管理和营销自动化服务。'],
                    'noise_removed' => [],
                ], JSON_UNESCAPED_UNICODE)]]]], 200)
                ->push(['choices' => [['message' => ['content' => json_encode([
                    'summary' => '面向中小企业的客户数据统一、销售管道管理和营销自动化服务。',
                    'library_name' => 'CRM 自动化知识库',
                    'knowledge_markdown' => "# CRM 自动化知识库\n\n- 面向中小企业。\n- 支持客户数据统一、销售管道管理和营销自动化。",
                ], JSON_UNESCAPED_UNICODE)]]]], 200)
                ->push(['choices' => [['message' => ['content' => '智能CRM,营销自动化,销售管道管理,客户数据统一,中小企业CRM']]]], 200)
                ->push(['choices' => [['message' => ['content' => "1. 智能 CRM 如何帮助中小企业统一客户数据\n2. 营销自动化系统怎么提升销售转化\n3. 销售管道管理工具选型要看哪些指标"]]]], 200),
        ]);

        $admin = Admin::query()->create([
            'username' => 'url_import_plain_list_admin',
            'password' => 'secret-123',
            'email' => 'url-import-plain-list@example.com',
            'display_name' => 'Url Import Plain List Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);
        $this->createReadyUrlImportAiModel();

        $this->actingAs($admin, 'admin')
            ->post(route('admin.url-import.store'), [
                'url' => 'source.test/plain-lists',
                'outputs' => ['knowledge', 'keywords', 'titles'],
            ])
            ->assertRedirect();

        $job = UrlImportJob::query()->firstOrFail();

        $this->actingAs($admin, 'admin')
            ->postJson(route('admin.url-import.run', ['jobId' => (int) $job->id]))
            ->assertOk()
            ->assertJsonPath('status', 'completed');

        $result = json_decode((string) $job->refresh()->result_json, true);

        $this->assertContains('营销自动化', $result['analysis']['keywords'] ?? []);
        $this->assertContains('销售管道管理', $result['analysis']['keywords'] ?? []);
        $this->assertContains('智能 CRM 如何帮助中小企业统一客户数据', $result['analysis']['titles'] ?? []);
    }

    public function test_url_import_fails_over_to_next_available_ai_model(): void
    {
        Http::fake([
            'https://source.test/failover' => Http::response(
                '<!doctype html><html><head><title>GEO 采集页</title><meta name="description" content="GEO 采集页摘要"></head><body><article><h1>GEO 采集页</h1><p>面向企业的内容资产管理服务。</p></article></body></html>',
                200,
                ['Content-Type' => 'text/html; charset=utf-8']
            ),
            'https://bad.test/v1/chat/completions' => Http::response(['detail' => 'API Key 无效'], 401),
            'https://ai.test/v1/chat/completions' => Http::sequence()
                ->push(['choices' => [['message' => ['content' => json_encode([
                    'clean_title' => 'GEO 采集页',
                    'clean_summary' => '面向企业的内容资产管理服务。',
                    'clean_text' => '面向企业的内容资产管理服务。',
                    'core_business' => ['industry' => '内容管理', 'products_services' => ['内容资产管理']],
                    'entities' => ['内容资产管理'],
                    'facts' => ['面向企业的内容资产管理服务。'],
                    'noise_removed' => [],
                ], JSON_UNESCAPED_UNICODE)]]]], 200)
                ->push(['choices' => [['message' => ['content' => json_encode([
                    'summary' => '面向企业的内容资产管理服务。',
                    'library_name' => 'GEO 采集页',
                    'knowledge_markdown' => "# GEO 采集页\n\n- 面向企业的内容资产管理服务。",
                ], JSON_UNESCAPED_UNICODE)]]]], 200)
                ->push(['choices' => [['message' => ['content' => json_encode(['keywords' => ['内容资产', '内容管理']], JSON_UNESCAPED_UNICODE)]]]], 200)
                ->push(['choices' => [['message' => ['content' => json_encode(['titles' => ['内容资产管理如何支撑 GEO 运营']], JSON_UNESCAPED_UNICODE)]]]], 200),
        ]);

        $admin = Admin::query()->create([
            'username' => 'url_import_failover_admin',
            'password' => 'secret-123',
            'email' => 'url-import-failover@example.com',
            'display_name' => 'Url Import Failover Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);

        AiModel::query()->create([
            'name' => 'Bad Model',
            'version' => '',
            'api_key' => app(ApiKeyCrypto::class)->encrypt('bad-key'),
            'model_id' => 'bad-chat',
            'model_type' => 'chat',
            'api_url' => 'https://bad.test/v1',
            'failover_priority' => 1,
            'daily_limit' => 100,
            'used_today' => 0,
            'total_used' => 0,
            'status' => 'active',
        ]);
        $this->createReadyUrlImportAiModel();

        $this->actingAs($admin, 'admin')
            ->post(route('admin.url-import.store'), [
                'url' => 'source.test/failover',
                'outputs' => ['knowledge', 'keywords', 'titles'],
            ])
            ->assertRedirect();

        $job = UrlImportJob::query()->firstOrFail();

        $this->actingAs($admin, 'admin')
            ->postJson(route('admin.url-import.run', ['jobId' => (int) $job->id]))
            ->assertOk()
            ->assertJsonPath('status', 'completed');

        $result = json_decode((string) $job->refresh()->result_json, true);
        $this->assertSame('URL Import AI Model', $result['analysis']['model']['name'] ?? null);
        $this->assertDatabaseHas('url_import_job_logs', [
            'job_id' => (int) $job->id,
            'level' => 'warning',
        ]);
        $this->assertSame(3, UrlImportJobLog::query()
            ->where('job_id', (int) $job->id)
            ->where('level', 'warning')
            ->where('message', 'like', '%Bad Model%')
            ->count());
    }

    public function test_url_import_retries_transient_ai_failure_before_success(): void
    {
        Http::fake([
            'https://source.test/transient' => Http::response(
                '<!doctype html><html><head><title>CRM 增长页</title><meta name="description" content="CRM 增长页摘要"></head><body><article><h1>CRM 增长页</h1><p>面向企业的 CRM 增长服务。</p></article></body></html>',
                200,
                ['Content-Type' => 'text/html; charset=utf-8']
            ),
            'https://ai.test/v1/chat/completions' => Http::sequence()
                ->push(['error' => ['message' => 'temporary upstream error']], 500)
                ->push(['choices' => [['message' => ['content' => json_encode([
                    'clean_title' => 'CRM 增长页',
                    'clean_summary' => '面向企业的 CRM 增长服务。',
                    'clean_text' => '面向企业的 CRM 增长服务。',
                    'core_business' => ['industry' => 'CRM', 'products_services' => ['CRM 增长服务']],
                    'entities' => ['CRM 增长服务'],
                    'facts' => ['面向企业的 CRM 增长服务。'],
                    'noise_removed' => [],
                ], JSON_UNESCAPED_UNICODE)]]]], 200)
                ->push(['choices' => [['message' => ['content' => json_encode([
                    'summary' => '面向企业的 CRM 增长服务。',
                    'library_name' => 'CRM 增长页',
                    'knowledge_markdown' => "# CRM 增长页\n\n- 面向企业的 CRM 增长服务。",
                ], JSON_UNESCAPED_UNICODE)]]]], 200)
                ->push(['choices' => [['message' => ['content' => json_encode(['keywords' => ['CRM增长', '客户管理']], JSON_UNESCAPED_UNICODE)]]]], 200)
                ->push(['choices' => [['message' => ['content' => json_encode(['titles' => ['CRM 增长服务如何支撑 GEO 运营']], JSON_UNESCAPED_UNICODE)]]]], 200),
        ]);

        $admin = Admin::query()->create([
            'username' => 'url_import_retry_admin',
            'password' => 'secret-123',
            'email' => 'url-import-retry@example.com',
            'display_name' => 'Url Import Retry Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);
        $this->createReadyUrlImportAiModel();

        $this->actingAs($admin, 'admin')
            ->post(route('admin.url-import.store'), [
                'url' => 'source.test/transient',
                'outputs' => ['knowledge', 'keywords', 'titles'],
            ])
            ->assertRedirect();

        $job = UrlImportJob::query()->firstOrFail();

        $this->actingAs($admin, 'admin')
            ->postJson(route('admin.url-import.run', ['jobId' => (int) $job->id]))
            ->assertOk()
            ->assertJsonPath('status', 'completed');

        $result = json_decode((string) $job->refresh()->result_json, true);
        $this->assertSame('URL Import AI Model', $result['analysis']['model']['name'] ?? null);
        $this->assertDatabaseHas('url_import_job_logs', [
            'job_id' => (int) $job->id,
            'level' => 'warning',
        ]);
    }

    public function test_admin_can_open_all_material_detail_pages(): void
    {
        $admin = Admin::query()->create([
            'username' => 'materials_detail_admin',
            'password' => 'secret-123',
            'email' => 'materials-detail-admin@example.com',
            'display_name' => 'Materials Detail Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $keywordLibrary = KeywordLibrary::query()->create([
            'name' => '关键词库A',
            'description' => 'desc',
            'keyword_count' => 0,
        ]);
        Keyword::query()->create([
            'library_id' => (int) $keywordLibrary->id,
            'keyword' => 'SJ4060 keyword',
            'used_count' => 0,
            'usage_count' => 0,
        ]);
        $titleLibrary = TitleLibrary::query()->create([
            'name' => '标题库A',
            'description' => 'desc',
            'title_count' => 0,
            'generation_type' => 'manual',
            'generation_rounds' => 1,
            'is_ai_generated' => 0,
        ]);
        $imageLibrary = ImageLibrary::query()->create([
            'name' => '图片库A',
            'description' => 'desc',
            'image_count' => 0,
            'used_task_count' => 0,
        ]);
        Image::query()->create([
            'library_id' => (int) $imageLibrary->id,
            'filename' => 'demo.png',
            'original_name' => 'demo.png',
            'file_name' => 'demo.png',
            'file_path' => 'storage/uploads/images/demo.png',
            'file_size' => 1024,
            'mime_type' => 'image/png',
            'width' => 100,
            'height' => 100,
            'tags' => '',
            'used_count' => 0,
            'usage_count' => 0,
        ]);
        $knowledgeBase = KnowledgeBase::query()->create([
            'name' => '知识库A',
            'description' => 'desc',
            'content' => '知识内容',
            'character_count' => 4,
            'used_task_count' => 0,
            'file_type' => 'markdown',
            'file_path' => '',
            'word_count' => 4,
            'usage_count' => 0,
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.keyword-libraries.detail', ['libraryId' => (int) $keywordLibrary->id]))
            ->assertOk()
            ->assertSee($keywordLibrary->name)
            ->assertSee('id="keyword-select-all"', false)
            ->assertSee(__('admin.material_bulk.select_current_page'))
            ->assertDontSee('data-tag-selector-auto-submit="1"', false)
            ->assertSee(__('admin.button.save'));
        $this->actingAs($admin, 'admin')
            ->get(route('admin.title-libraries.detail', ['libraryId' => (int) $titleLibrary->id]))
            ->assertOk()
            ->assertSee($titleLibrary->name);
        $this->actingAs($admin, 'admin')
            ->get(route('admin.image-libraries.detail', ['libraryId' => (int) $imageLibrary->id]))
            ->assertOk()
            ->assertSee($imageLibrary->name)
            ->assertSee('storage/uploads/images/demo.png')
            ->assertSee('id="image-select-all"', false)
            ->assertSee(__('admin.material_bulk.select_current_page'))
            ->assertDontSee('data-tag-selector-auto-submit="1"', false)
            ->assertSee(__('admin.button.save'));
        $this->actingAs($admin, 'admin')
            ->get(route('admin.knowledge-bases.detail', ['knowledgeBaseId' => (int) $knowledgeBase->id]))
            ->assertOk()
            ->assertSee(__('admin.knowledge_detail.heading'));
    }

    public function test_admin_can_manage_keyword_and_title_details(): void
    {
        $admin = Admin::query()->create([
            'username' => 'materials_ops_admin',
            'password' => 'secret-123',
            'email' => 'materials-ops-admin@example.com',
            'display_name' => 'Materials Ops Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $keywordLibrary = KeywordLibrary::query()->create([
            'name' => '关键词库B',
            'description' => 'desc',
            'keyword_count' => 0,
        ]);
        $titleLibrary = TitleLibrary::query()->create([
            'name' => '标题库B',
            'description' => 'desc',
            'title_count' => 0,
            'generation_type' => 'manual',
            'generation_rounds' => 1,
            'is_ai_generated' => 0,
        ]);

        $this->actingAs($admin, 'admin')->post(route('admin.keyword-libraries.keywords.store', ['libraryId' => (int) $keywordLibrary->id]), [
            'keyword' => '增长策略',
        ])->assertRedirect(route('admin.keyword-libraries.detail', ['libraryId' => (int) $keywordLibrary->id]));
        $this->assertDatabaseHas('keywords', [
            'library_id' => (int) $keywordLibrary->id,
            'keyword' => '增长策略',
        ]);

        $this->actingAs($admin, 'admin')->post(route('admin.title-libraries.titles.store', ['libraryId' => (int) $titleLibrary->id]), [
            'title' => '增长策略完整指南',
            'keyword' => '增长策略',
        ])->assertRedirect(route('admin.title-libraries.detail', ['libraryId' => (int) $titleLibrary->id]));
        $this->assertDatabaseHas('titles', [
            'library_id' => (int) $titleLibrary->id,
            'title' => '增长策略完整指南',
        ]);

        $this->actingAs($admin, 'admin')->post(route('admin.title-libraries.import', ['libraryId' => (int) $titleLibrary->id]), [
            'titles_text' => "标题A|关键词A\n标题B",
        ])->assertRedirect(route('admin.title-libraries.detail', ['libraryId' => (int) $titleLibrary->id]));
        $this->assertDatabaseHas('titles', [
            'library_id' => (int) $titleLibrary->id,
            'title' => '标题A',
        ]);

        $this->actingAs($admin, 'admin')->post(route('admin.title-libraries.ai-generate.submit', ['libraryId' => (int) $titleLibrary->id]), [
            'keyword_library_id' => (int) $keywordLibrary->id,
            'ai_model_id' => 1,
            'title_count' => 3,
            'title_style' => 'professional',
            'custom_prompt' => '',
        ])->assertSessionHasErrors();
    }

    public function test_admin_can_upload_image_and_knowledge_file_from_detail_flow(): void
    {
        Storage::fake('public');

        $admin = Admin::query()->create([
            'username' => 'materials_upload_admin',
            'password' => 'secret-123',
            'email' => 'materials-upload-admin@example.com',
            'display_name' => 'Materials Upload Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $imageLibrary = ImageLibrary::query()->create([
            'name' => '图片库C',
            'description' => 'desc',
            'image_count' => 0,
            'used_task_count' => 0,
        ]);

        $image = UploadedFile::fake()->image('banner.png', 100, 100);
        $this->actingAs($admin, 'admin')->post(route('admin.image-libraries.images.upload', ['libraryId' => (int) $imageLibrary->id]), [
            'images' => [$image],
        ])->assertRedirect(route('admin.image-libraries.detail', ['libraryId' => (int) $imageLibrary->id]));

        $this->assertDatabaseHas('images', [
            'library_id' => (int) $imageLibrary->id,
            'original_name' => 'banner.png',
        ]);

        $storedImage = Image::query()
            ->where('library_id', (int) $imageLibrary->id)
            ->where('original_name', 'banner.png')
            ->firstOrFail();
        $this->assertStringStartsWith('storage/uploads/images/', (string) $storedImage->file_path);
        Storage::disk('public')->assertExists(str_replace('storage/', '', (string) $storedImage->file_path));

        $knowledgeFile = UploadedFile::fake()->createWithContent('manual.md', "# 标题\n内容段落");
        $this->actingAs($admin, 'admin')->post(route('admin.knowledge-bases.upload'), [
            'name' => '上传知识库',
            'description' => '测试上传',
            'knowledge_file' => $knowledgeFile,
        ])->assertRedirect(route('admin.knowledge-bases.index'));

        $this->assertDatabaseHas('knowledge_bases', [
            'name' => '上传知识库',
        ]);
    }
}
