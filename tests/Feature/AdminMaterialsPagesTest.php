<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\AiModel;
use App\Models\CaseRecord;
use App\Models\EntityRecord;
use App\Models\Image;
use App\Models\ImageLibrary;
use App\Models\Keyword;
use App\Models\KeywordLibrary;
use App\Models\KnowledgeBase;
use App\Models\KnowledgeChunk;
use App\Models\Prompt;
use App\Models\Tag;
use App\Models\TitleLibrary;
use App\Models\UrlImportJob;
use App\Models\UrlImportJobLog;
use App\Services\GeoFlow\TagService;
use App\Support\GeoFlow\ApiKeyCrypto;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
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
            'admin.entities.index',
            'admin.cases.index',
            'admin.material-tags.index',
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
            ->assertSee(__('admin.knowledge_bases.page_title'));

        $this->actingAs($admin, 'admin')
            ->get(route('admin.entities.create'))
            ->assertOk()
            ->assertSee(__('admin.entities.page_title'));

        $this->actingAs($admin, 'admin')
            ->get(route('admin.cases.create'))
            ->assertOk()
            ->assertSee(__('admin.cases.page_title'));

        $this->actingAs($admin, 'admin')
            ->get(route('admin.url-import'))
            ->assertOk()
            ->assertSee(__('admin.url_import.page_title'));

        $this->actingAs($admin, 'admin')
            ->get(route('admin.url-import.history'))
            ->assertOk()
            ->assertSee(__('admin.url_import_history.page_title'));

        $this->actingAs($admin, 'admin')
            ->get(route('admin.material-tags.index'))
            ->assertOk()
            ->assertSee(__('admin.material_tags.page_title'));
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

    public function test_admin_can_refresh_knowledge_chunks_with_real_embedding_model(): void
    {
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
            ->assertSessionHas('message');

        $chunk = $knowledgeBase->chunks()->firstOrFail();
        $this->assertSame((int) $embeddingModel->id, (int) $chunk->embedding_model_id);
        $this->assertSame(3, (int) $chunk->embedding_dimensions);
        $this->assertSame([0.1, 0.2, 0.3], json_decode((string) $chunk->embedding_json, true));
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

        $this->actingAs($admin, 'admin')
            ->post(route('admin.url-import.store'), [
                'url' => 'example.test/report',
                'outputs' => ['knowledge', 'keywords', 'titles'],
            ])
            ->assertRedirect();

        $job = UrlImportJob::query()->firstOrFail();

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

        $this->assertDatabaseHas('knowledge_bases', ['name' => 'GEO 内容报告 知识库']);
        $this->assertDatabaseHas('keyword_libraries', ['name' => 'GEO 内容报告 关键词库']);
        $this->assertDatabaseHas('title_libraries', ['name' => 'GEO 内容报告 标题库']);
        $this->assertDatabaseMissing('image_libraries', ['name' => 'GEO 内容报告 图片库']);
        $this->assertDatabaseHas('url_import_jobs', [
            'id' => (int) $job->id,
            'current_step' => 'imported',
        ]);
    }

    public function test_url_import_respects_selected_content_language_for_generated_assets(): void
    {
        Http::fake([
            'https://example.test/english-language' => Http::response(
                '<!doctype html><html lang="zh-CN"><head><title>智能制造服务</title><meta name="description" content="面向工厂的自动化服务"></head><body><article><h1>智能制造服务</h1><p>为工厂提供设备监控、产线自动化和维护支持。</p></article></body></html>',
                200,
                ['Content-Type' => 'text/html; charset=utf-8']
            ),
            'https://ai.test/v1/chat/completions' => Http::sequence()
                ->push(['choices' => [['message' => ['content' => json_encode([
                    'clean_title' => 'Smart Manufacturing Services',
                    'clean_summary' => 'The page describes factory automation services for equipment monitoring, production line automation, and maintenance support.',
                    'clean_text' => 'The service helps factories monitor equipment, automate production lines, and support maintenance workflows.',
                    'core_business' => ['industry' => 'manufacturing automation'],
                    'entities' => ['factories', 'equipment monitoring'],
                    'facts' => ['The page describes equipment monitoring and production line automation services.'],
                    'noise_removed' => [],
                ], JSON_UNESCAPED_UNICODE)]]]], 200)
                ->push(['choices' => [['message' => ['content' => json_encode([
                    'summary' => 'Factory automation services for equipment monitoring, production line automation, and maintenance support.',
                    'library_name' => 'Smart Manufacturing Services',
                    'knowledge_markdown' => "# Smart Manufacturing Services\n\n- Source URL: https://example.test/english-language\n- The page describes equipment monitoring, production line automation, and maintenance support for factories.",
                ], JSON_UNESCAPED_UNICODE)]]]], 200)
                ->push(['choices' => [['message' => ['content' => json_encode([
                    'keywords' => ['factory automation', 'equipment monitoring', 'maintenance support'],
                ], JSON_UNESCAPED_UNICODE)]]]], 200)
                ->push(['choices' => [['message' => ['content' => json_encode([
                    'titles' => ['How factory automation improves equipment monitoring', 'What to consider when planning production line automation'],
                ], JSON_UNESCAPED_UNICODE)]]]], 200),
        ]);

        $admin = Admin::query()->create([
            'username' => 'url_import_language_admin',
            'password' => 'secret-123',
            'email' => 'url-import-language@example.com',
            'display_name' => 'Url Import Language Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);
        $this->createReadyUrlImportAiModel();

        $this->actingAs($admin, 'admin')
            ->post(route('admin.url-import.store'), [
                'url' => 'example.test/english-language',
                'content_language' => 'en',
                'outputs' => ['knowledge', 'keywords', 'titles'],
            ])
            ->assertRedirect();

        $job = UrlImportJob::query()->firstOrFail();
        $this->actingAs($admin, 'admin')
            ->postJson(route('admin.url-import.run', ['jobId' => (int) $job->id]))
            ->assertOk()
            ->assertJsonPath('status', 'completed');

        $requestBodies = collect(Http::recorded())
            ->map(static fn (array $record): string => json_encode($record[0]->data(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
            ->implode("\n");
        $this->assertStringContainsString('Target output language: English (en)', $requestBodies);

        $result = json_decode((string) $job->refresh()->result_json, true);
        $this->assertSame('en', $result['analysis']['language']['code'] ?? null);
        $this->assertSame('selected', $result['analysis']['language']['source'] ?? null);
        $this->assertContains('factory automation', $result['analysis']['keywords'] ?? []);
        $this->assertContains('How factory automation improves equipment monitoring', $result['analysis']['titles'] ?? []);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.url-import.commit', ['jobId' => (int) $job->id]))
            ->assertRedirect(route('admin.url-import.show', ['jobId' => (int) $job->id]));

        $this->assertDatabaseHas('knowledge_bases', ['name' => 'Smart Manufacturing Services Knowledge Base']);
        $this->assertDatabaseHas('keyword_libraries', ['name' => 'Smart Manufacturing Services Keyword Library']);
        $this->assertDatabaseHas('title_libraries', ['name' => 'Smart Manufacturing Services Title Library']);
        $this->assertDatabaseHas('keywords', ['keyword' => 'factory automation']);
        $this->assertDatabaseHas('titles', ['title' => 'How factory automation improves equipment monitoring']);
    }

    public function test_url_import_auto_detects_page_language_from_html_lang(): void
    {
        Http::fake([
            'https://example.test/es-page' => Http::response(
                '<!doctype html><html lang="es"><head><title>Servicios de automatización industrial</title><meta name="description" content="Soluciones para fábricas"></head><body><article><h1>Servicios de automatización industrial</h1><p>La página describe monitoreo de equipos, automatización de líneas y soporte de mantenimiento para fábricas.</p></article></body></html>',
                200,
                ['Content-Type' => 'text/html; charset=utf-8']
            ),
            'https://ai.test/v1/chat/completions' => Http::sequence()
                ->push(['choices' => [['message' => ['content' => json_encode([
                    'clean_title' => 'Servicios de automatización industrial',
                    'clean_summary' => 'La página describe servicios de automatización industrial para fábricas.',
                    'clean_text' => 'La página describe monitoreo de equipos, automatización de líneas y soporte de mantenimiento para fábricas.',
                    'core_business' => ['industry' => 'automatización industrial'],
                    'entities' => ['fábricas', 'monitoreo de equipos'],
                    'facts' => ['La página describe automatización de líneas y soporte de mantenimiento.'],
                    'noise_removed' => [],
                ], JSON_UNESCAPED_UNICODE)]]]], 200)
                ->push(['choices' => [['message' => ['content' => json_encode([
                    'summary' => 'Servicios de automatización industrial para fábricas.',
                    'library_name' => 'Servicios de automatización industrial',
                    'knowledge_markdown' => "# Servicios de automatización industrial\n\n- URL de origen: https://example.test/es-page\n- La página describe monitoreo de equipos, automatización de líneas y soporte de mantenimiento.",
                ], JSON_UNESCAPED_UNICODE)]]]], 200)
                ->push(['choices' => [['message' => ['content' => json_encode([
                    'keywords' => ['automatización industrial', 'monitoreo de equipos', 'mantenimiento de fábricas'],
                ], JSON_UNESCAPED_UNICODE)]]]], 200)
                ->push(['choices' => [['message' => ['content' => json_encode([
                    'titles' => ['Cómo la automatización industrial mejora el monitoreo de equipos'],
                ], JSON_UNESCAPED_UNICODE)]]]], 200),
        ]);

        $admin = Admin::query()->create([
            'username' => 'url_import_auto_language_admin',
            'password' => 'secret-123',
            'email' => 'url-import-auto-language@example.com',
            'display_name' => 'Url Import Auto Language Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);
        $this->createReadyUrlImportAiModel();

        $this->actingAs($admin, 'admin')
            ->post(route('admin.url-import.store'), [
                'url' => 'example.test/es-page',
                'content_language' => '',
                'outputs' => ['knowledge', 'keywords', 'titles'],
            ])
            ->assertRedirect();

        $job = UrlImportJob::query()->firstOrFail();
        $this->actingAs($admin, 'admin')
            ->postJson(route('admin.url-import.run', ['jobId' => (int) $job->id]))
            ->assertOk()
            ->assertJsonPath('status', 'completed');

        $requestBodies = collect(Http::recorded())
            ->map(static fn (array $record): string => json_encode($record[0]->data(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
            ->implode("\n");
        $this->assertStringContainsString('Target output language: Spanish (es)', $requestBodies);

        $result = json_decode((string) $job->refresh()->result_json, true);
        $this->assertSame('es', $result['analysis']['language']['code'] ?? null);
        $this->assertSame('html', $result['analysis']['language']['source'] ?? null);
        $this->assertContains('automatización industrial', $result['analysis']['keywords'] ?? []);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.url-import.commit', ['jobId' => (int) $job->id]))
            ->assertRedirect(route('admin.url-import.show', ['jobId' => (int) $job->id]));

        $this->assertDatabaseHas('knowledge_bases', ['name' => 'Servicios de automatización industrial Base de conocimiento']);
        $this->assertDatabaseHas('keyword_libraries', ['name' => 'Servicios de automatización industrial Biblioteca de palabras clave']);
        $this->assertDatabaseHas('title_libraries', ['name' => 'Servicios de automatización industrial Biblioteca de títulos']);
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
            ->assertSee($keywordLibrary->name);
        $this->actingAs($admin, 'admin')
            ->get(route('admin.title-libraries.detail', ['libraryId' => (int) $titleLibrary->id]))
            ->assertOk()
            ->assertSee($titleLibrary->name);
        $this->actingAs($admin, 'admin')
            ->get(route('admin.image-libraries.detail', ['libraryId' => (int) $imageLibrary->id]))
            ->assertOk()
            ->assertSee($imageLibrary->name)
            ->assertSee('storage/uploads/images/demo.png');
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

    public function test_title_ai_generation_can_render_keyword_tag_variables(): void
    {
        $admin = Admin::query()->create([
            'username' => 'materials_title_variable_admin',
            'password' => 'secret-123',
            'email' => 'materials-title-variable@example.com',
            'display_name' => 'Materials Title Variable Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $keywordLibrary = KeywordLibrary::query()->create([
            'name' => '变量关键词库',
            'description' => 'desc',
            'keyword_count' => 0,
        ]);
        $titleLibrary = TitleLibrary::query()->create([
            'name' => '变量标题库',
            'description' => 'desc',
            'title_count' => 0,
            'generation_type' => 'manual',
            'generation_rounds' => 1,
            'is_ai_generated' => 0,
        ]);
        $aiModel = AiModel::query()->create([
            'name' => 'Offline Title Model',
            'version' => '',
            'api_key' => '',
            'model_id' => 'offline-chat',
            'model_type' => 'chat',
            'api_url' => '',
            'failover_priority' => 1,
            'daily_limit' => 100,
            'used_today' => 0,
            'total_used' => 0,
            'status' => 'active',
        ]);

        $industryTag = app(TagService::class)->firstOrCreateTag('行业', '制造业');
        $scenarioTag = app(TagService::class)->firstOrCreateTag('场景', '售后');

        $this->actingAs($admin, 'admin')->post(route('admin.keyword-libraries.keywords.store', ['libraryId' => (int) $keywordLibrary->id]), [
            'keyword' => '智能客服',
            'tag_ids' => [(int) $industryTag->id, (int) $scenarioTag->id],
        ])->assertRedirect(route('admin.keyword-libraries.detail', ['libraryId' => (int) $keywordLibrary->id]));

        $this->actingAs($admin, 'admin')
            ->get(route('admin.title-libraries.ai-generate', ['libraryId' => (int) $titleLibrary->id]))
            ->assertOk()
            ->assertSee('{{keyword}}')
            ->assertSee('{{keyword.tags.行业}}');

        $this->actingAs($admin, 'admin')->post(route('admin.title-libraries.ai-generate.submit', ['libraryId' => (int) $titleLibrary->id]), [
            'keyword_library_id' => (int) $keywordLibrary->id,
            'ai_model_id' => (int) $aiModel->id,
            'title_count' => 1,
            'title_style' => 'professional',
            'custom_prompt' => '{{keyword.tags.行业}} {{keyword}} 应用指南',
        ])->assertRedirect(route('admin.title-libraries.detail', ['libraryId' => (int) $titleLibrary->id]));

        $this->assertDatabaseHas('titles', [
            'library_id' => (int) $titleLibrary->id,
            'title' => '制造业 智能客服 应用指南',
            'keyword' => '智能客服',
            'is_ai_generated' => true,
        ]);
        $this->assertDatabaseHas('title_libraries', [
            'id' => (int) $titleLibrary->id,
            'title_count' => 1,
        ]);
    }

    public function test_admin_can_tag_materials_and_filter_knowledge_by_tag(): void
    {
        $admin = Admin::query()->create([
            'username' => 'materials_tag_admin',
            'password' => 'secret-123',
            'email' => 'materials-tag-admin@example.com',
            'display_name' => 'Materials Tag Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $keywordLibrary = KeywordLibrary::query()->create([
            'name' => '关键词标签库',
            'description' => 'desc',
            'keyword_count' => 0,
        ]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.material-tags.store'), [
                'group_name' => '行业',
                'name' => '制造业',
            ])
            ->assertRedirect(route('admin.material-tags.index'));

        $industryTag = Tag::query()->where('group_name', '行业')->where('name', '制造业')->firstOrFail();
        $scenarioTag = app(TagService::class)->firstOrCreateTag('场景', '售后');
        $productImageTag = app(TagService::class)->firstOrCreateTag('场景', '产品图');
        $documentTypeTag = app(TagService::class)->firstOrCreateTag('类型', '产品资料');

        $this->actingAs($admin, 'admin')->post(route('admin.keyword-libraries.keywords.store', ['libraryId' => (int) $keywordLibrary->id]), [
            'keyword' => '智能客服',
            'tag_ids' => [(int) $industryTag->id, (int) $scenarioTag->id],
        ])->assertRedirect(route('admin.keyword-libraries.detail', ['libraryId' => (int) $keywordLibrary->id]));

        $keyword = Keyword::query()->where('keyword', '智能客服')->firstOrFail();
        $this->assertTrue($keyword->tags()->where('group_name', '行业')->where('name', '制造业')->exists());
        $this->assertTrue($keyword->tags()->where('group_name', '场景')->where('name', '售后')->exists());

        $this->actingAs($admin, 'admin')->post(route('admin.keyword-libraries.keywords.store', ['libraryId' => (int) $keywordLibrary->id]), [
            'keyword' => '误建标签验证',
            'tags_text' => '行业:相似误建',
        ])->assertRedirect(route('admin.keyword-libraries.detail', ['libraryId' => (int) $keywordLibrary->id]));
        $this->assertDatabaseMissing('tags', [
            'group_name' => '行业',
            'name' => '相似误建',
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.keyword-libraries.detail', ['libraryId' => (int) $keywordLibrary->id, 'tag' => '行业:制造业']))
            ->assertOk()
            ->assertSee('智能客服')
            ->assertSee('行业:制造业');

        $imageLibrary = ImageLibrary::query()->create([
            'name' => '图片标签库',
            'description' => 'desc',
            'image_count' => 0,
            'used_task_count' => 0,
        ]);
        $image = Image::query()->create([
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

        $this->actingAs($admin, 'admin')->post(route('admin.image-libraries.images.tags', [
            'libraryId' => (int) $imageLibrary->id,
            'imageId' => (int) $image->id,
        ]), [
            'tag_ids' => [(int) $productImageTag->id, (int) $industryTag->id],
        ])->assertRedirect(route('admin.image-libraries.detail', ['libraryId' => (int) $imageLibrary->id, 'search' => '', 'tag' => '']));

        $image->refresh();
        $this->assertSame('场景:产品图, 行业:制造业', (string) $image->tags);
        $this->assertTrue($image->tags()->where('group_name', '场景')->where('name', '产品图')->exists());

        $this->actingAs($admin, 'admin')->post(route('admin.knowledge-bases.store'), [
            'name' => '制造业知识库',
            'description' => 'desc',
            'file_type' => 'markdown',
            'content' => '智能客服可以降低制造业售后响应时间。',
            'tag_ids' => [(int) $industryTag->id, (int) $documentTypeTag->id],
        ])->assertRedirect(route('admin.knowledge-bases.index'));

        $knowledgeBase = KnowledgeBase::query()->where('name', '制造业知识库')->firstOrFail();
        $this->assertTrue($knowledgeBase->tags()->where('group_name', '行业')->where('name', '制造业')->exists());
        $this->assertDatabaseHas('tags', [
            'group_name' => '类型',
            'name' => '产品资料',
        ]);
        $this->assertGreaterThanOrEqual(4, Tag::query()->count());

        $this->actingAs($admin, 'admin')
            ->get(route('admin.knowledge-bases.index', ['tag' => '行业:制造业']))
            ->assertOk()
            ->assertSee('制造业知识库')
            ->assertSee('行业:制造业');

        $this->actingAs($admin, 'admin')
            ->get(route('admin.knowledge-bases.index', ['tag' => '行业:医疗']))
            ->assertOk()
            ->assertDontSee('制造业知识库');

        $this->actingAs($admin, 'admin')
            ->get(route('admin.material-tags.index'))
            ->assertOk()
            ->assertSee('标签管理')
            ->assertSee('行业:制造业')
            ->assertSee('智能客服')
            ->assertSee('demo.png')
            ->assertSee('制造业知识库');
    }

    public function test_admin_can_manage_entity_and_case_records_with_existing_tags(): void
    {
        $admin = Admin::query()->create([
            'username' => 'materials_entity_case_admin',
            'password' => 'secret-123',
            'email' => 'materials-entity-case@example.com',
            'display_name' => 'Materials Entity Case Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $industryTag = app(TagService::class)->firstOrCreateTag('行业', '制造业');
        $scenarioTag = app(TagService::class)->firstOrCreateTag('场景', '售后');

        $this->actingAs($admin, 'admin')
            ->post(route('admin.entities.store'), [
                'name' => 'GEOFlow 制造业客户',
                'entity_type' => '客户',
                'aliases' => '制造业客户A',
                'description' => '面向制造业售后团队的示例客户实体。',
                'attributes_json' => '{"industry":"manufacturing"}',
                'source_url' => 'https://example.test/entity',
                'tag_ids' => [(int) $industryTag->id],
            ])
            ->assertRedirect(route('admin.entities.index'));

        $entity = EntityRecord::query()->where('name', 'GEOFlow 制造业客户')->firstOrFail();
        $this->assertTrue($entity->tags()->where('group_name', '行业')->where('name', '制造业')->exists());

        $this->actingAs($admin, 'admin')
            ->get(route('admin.entities.index', ['tag' => '行业:制造业']))
            ->assertOk()
            ->assertSee('GEOFlow 制造业客户');

        $this->actingAs($admin, 'admin')
            ->put(route('admin.entities.update', ['entityId' => (int) $entity->id]), [
                'name' => 'GEOFlow 制造业客户升级版',
                'entity_type' => '客户',
                'aliases' => '制造业客户A',
                'description' => '更新后的实体资料。',
                'attributes_json' => '{"industry":"manufacturing","tier":"gold"}',
                'source_url' => 'https://example.test/entity',
                'tag_ids' => [(int) $industryTag->id, (int) $scenarioTag->id],
            ])
            ->assertRedirect(route('admin.entities.index'));

        $entity->refresh();
        $this->assertSame('GEOFlow 制造业客户升级版', (string) $entity->name);
        $this->assertTrue($entity->tags()->where('group_name', '场景')->where('name', '售后')->exists());

        $this->actingAs($admin, 'admin')
            ->post(route('admin.cases.store'), [
                'entity_id' => (int) $entity->id,
                'title' => '制造业售后响应效率提升案例',
                'case_type' => '客户案例',
                'summary' => '通过智能客服降低售后响应时间。',
                'challenge' => '人工响应慢，问题沉淀分散。',
                'solution' => '建设知识库和智能客服流程。',
                'result' => '响应时间下降 40%。',
                'metrics' => '响应时间下降 40%',
                'source_url' => 'https://example.test/case',
                'tag_ids' => [(int) $scenarioTag->id],
            ])
            ->assertRedirect(route('admin.cases.index'));

        $caseRecord = CaseRecord::query()->where('title', '制造业售后响应效率提升案例')->firstOrFail();
        $this->assertSame((int) $entity->id, (int) $caseRecord->entity_id);
        $this->assertTrue($caseRecord->tags()->where('group_name', '场景')->where('name', '售后')->exists());

        $this->actingAs($admin, 'admin')
            ->get(route('admin.cases.index', ['tag' => '场景:售后']))
            ->assertOk()
            ->assertSee('制造业售后响应效率提升案例')
            ->assertSee('GEOFlow 制造业客户升级版');

        $this->actingAs($admin, 'admin')
            ->put(route('admin.cases.update', ['caseId' => (int) $caseRecord->id]), [
                'entity_id' => (int) $entity->id,
                'title' => '制造业售后知识沉淀案例',
                'case_type' => '客户案例',
                'summary' => '通过知识库提升售后复用效率。',
                'challenge' => '知识沉淀分散。',
                'solution' => '统一标签化沉淀。',
                'result' => '高频问题复用率提升。',
                'metrics' => '复用率提升 30%',
                'source_url' => 'https://example.test/case',
                'tag_ids' => [(int) $industryTag->id, (int) $scenarioTag->id],
            ])
            ->assertRedirect(route('admin.cases.index'));

        $caseRecord->refresh();
        $this->assertSame('制造业售后知识沉淀案例', (string) $caseRecord->title);
        $this->assertTrue($caseRecord->tags()->where('group_name', '行业')->where('name', '制造业')->exists());

        $this->actingAs($admin, 'admin')
            ->get(route('admin.material-tags.index'))
            ->assertOk()
            ->assertSee('行业:制造业')
            ->assertSee('GEOFlow 制造业客户升级版')
            ->assertSee('制造业售后知识沉淀案例');

        $this->actingAs($admin, 'admin')
            ->post(route('admin.cases.delete', ['caseId' => (int) $caseRecord->id]))
            ->assertRedirect(route('admin.cases.index'));
        $this->assertDatabaseMissing('case_records', ['id' => (int) $caseRecord->id]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.entities.delete', ['entityId' => (int) $entity->id]))
            ->assertRedirect(route('admin.entities.index'));
        $this->assertDatabaseMissing('entities', ['id' => (int) $entity->id]);
    }

    public function test_admin_can_filter_rename_delete_and_bulk_manage_material_tags(): void
    {
        $admin = Admin::query()->create([
            'username' => 'materials_tag_ops_admin',
            'password' => 'secret-123',
            'email' => 'materials-tag-ops@example.com',
            'display_name' => 'Materials Tag Ops Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $tagService = app(TagService::class);
        $industryTag = $tagService->firstOrCreateTag('行业', '制造业');
        $medicalTag = $tagService->firstOrCreateTag('行业', '医疗');
        $moveTag = $tagService->firstOrCreateTag('阶段', '待移动');

        $keywordLibrary = KeywordLibrary::query()->create([
            'name' => '标签操作关键词库',
            'description' => 'desc',
            'keyword_count' => 0,
        ]);
        $keyword = Keyword::query()->create([
            'library_id' => (int) $keywordLibrary->id,
            'keyword' => '工业客服',
        ]);
        $tagService->syncExisting($keyword, [(int) $industryTag->id]);

        $imageLibrary = ImageLibrary::query()->create([
            'name' => '标签操作图片库',
            'description' => 'desc',
            'image_count' => 0,
            'used_task_count' => 0,
        ]);
        $image = Image::query()->create([
            'library_id' => (int) $imageLibrary->id,
            'filename' => 'tag-ops.png',
            'original_name' => 'tag-ops.png',
            'file_name' => 'tag-ops.png',
            'file_path' => 'storage/uploads/images/tag-ops.png',
            'file_size' => 1024,
            'mime_type' => 'image/png',
            'width' => 100,
            'height' => 100,
            'tags' => $tagService->tagTextForIds([(int) $industryTag->id]),
            'used_count' => 0,
            'usage_count' => 0,
        ]);
        $tagService->syncExisting($image, [(int) $industryTag->id]);

        $knowledgeBase = KnowledgeBase::query()->create([
            'name' => '标签操作知识库',
            'description' => 'desc',
            'content' => '制造业知识内容',
            'character_count' => 7,
            'file_type' => 'markdown',
            'word_count' => 7,
        ]);
        $tagService->syncExisting($knowledgeBase, [(int) $industryTag->id]);

        $entity = EntityRecord::query()->create([
            'name' => '标签操作实体',
            'entity_type' => '客户',
            'description' => '实体描述',
        ]);
        $tagService->syncExisting($entity, [(int) $industryTag->id]);

        $caseRecord = CaseRecord::query()->create([
            'entity_id' => (int) $entity->id,
            'title' => '标签操作案例',
            'case_type' => '客户案例',
            'summary' => '案例摘要',
        ]);
        $tagService->syncExisting($caseRecord, [(int) $industryTag->id]);

        $response = $this->actingAs($admin, 'admin')
            ->get(route('admin.material-tags.index', [
                'scope' => 'images',
                'groups' => ['行业'],
                'per_page' => 20,
            ]))
            ->assertOk()
            ->assertSee(__('admin.material_tags.scope_groups_title', ['scope' => __('admin.material_tags.stat_images')]))
            ->assertSee('data-scope-groups-card', false)
            ->assertSee('data-scope-group-chip', false)
            ->assertSee('data-group-selector', false)
            ->assertSee('data-tag-pagination-summary', false)
            ->assertSee(__('admin.material_tags.pagination_summary', ['from' => 1, 'to' => 1, 'total' => 1]))
            ->assertSee('data-tag-per-page-form', false)
            ->assertSee('data-per-page-select', false)
            ->assertSee('data-open-modal="tag-references-'.(int) $industryTag->id.'"', false)
            ->assertSee('行业:制造业')
            ->assertSee('tag-ops.png')
            ->assertDontSee('阶段:待移动');
        $content = (string) $response->getContent();
        $this->assertLessThan(
            strpos($content, 'data-group-selector'),
            strpos($content, __('admin.material_tags.create_title'))
        );

        $this->actingAs($admin, 'admin')
            ->put(route('admin.material-tags.update', ['tagId' => (int) $industryTag->id]), [
                'group_name' => '行业',
                'name' => '高端制造',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('tags', [
            'id' => (int) $industryTag->id,
            'group_name' => '行业',
            'name' => '高端制造',
        ]);
        $this->assertSame('行业:高端制造', (string) $image->refresh()->tags);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.material-tags.delete', ['tagId' => (int) $medicalTag->id]), [
                'delete_confirmation' => '错误输入',
            ])
            ->assertSessionHasErrors();
        $this->assertDatabaseHas('tags', ['id' => (int) $medicalTag->id]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.material-tags.delete', ['tagId' => (int) $medicalTag->id]), [
                'delete_confirmation' => '确认删除',
            ])
            ->assertRedirect();
        $this->assertDatabaseMissing('tags', ['id' => (int) $medicalTag->id]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.material-tags.bulk'), [
                'tag_ids' => [(int) $moveTag->id],
                'bulk_action' => 'move_group',
                'bulk_group_name' => '新分组',
            ])
            ->assertRedirect();
        $this->assertDatabaseHas('tags', [
            'id' => (int) $moveTag->id,
            'group_name' => '新分组',
            'name' => '待移动',
        ]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.material-tags.bulk'), [
                'tag_ids' => [(int) $moveTag->id],
                'bulk_action' => 'delete',
                'delete_confirmation' => '确认删除',
            ])
            ->assertRedirect();
        $this->assertDatabaseMissing('tags', ['id' => (int) $moveTag->id]);
    }

    public function test_material_tag_selectors_auto_save_and_knowledge_tags_use_lightweight_route(): void
    {
        $admin = Admin::query()->create([
            'username' => 'materials_auto_tag_admin',
            'password' => 'secret-123',
            'email' => 'materials-auto-tag@example.com',
            'display_name' => 'Materials Auto Tag Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $tag = app(TagService::class)->firstOrCreateTag('场景', '产品图');
        $keywordLibrary = KeywordLibrary::query()->create(['name' => '即时保存关键词库']);
        $keyword = Keyword::query()->create([
            'library_id' => (int) $keywordLibrary->id,
            'keyword' => '智能客服',
        ]);
        $imageLibrary = ImageLibrary::query()->create([
            'name' => '即时保存图库',
            'image_count' => 1,
        ]);
        $image = Image::query()->create([
            'library_id' => (int) $imageLibrary->id,
            'filename' => 'auto-tag.png',
            'original_name' => 'auto-tag.png',
            'file_path' => 'storage/uploads/images/auto-tag.png',
        ]);
        $knowledgeBase = KnowledgeBase::query()->create([
            'name' => '即时保存知识库',
            'content' => '无需重建切片即可更新标签。',
            'file_type' => 'markdown',
        ]);
        KnowledgeChunk::query()->create([
            'knowledge_base_id' => (int) $knowledgeBase->id,
            'chunk_index' => 0,
            'content' => '原始切片',
            'content_hash' => 'auto-tag-hash',
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.keyword-libraries.detail', ['libraryId' => (int) $keywordLibrary->id]))
            ->assertOk()
            ->assertSee('data-tag-selector-auto-submit="1"', false)
            ->assertDontSee('border-blue-200 bg-blue-50 px-2.5 py-1.5 text-xs font-medium text-blue-700', false);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.image-libraries.detail', ['libraryId' => (int) $imageLibrary->id]))
            ->assertOk()
            ->assertSee('data-tag-selector-auto-submit="1"', false)
            ->assertDontSee('保存标签');

        $this->actingAs($admin, 'admin')
            ->post(route('admin.keyword-libraries.keywords.tags', [
                'libraryId' => (int) $keywordLibrary->id,
                'keywordId' => (int) $keyword->id,
            ]), [
                'tag_ids_present' => '1',
                'tag_ids' => [(int) $tag->id],
            ])
            ->assertRedirect();
        $this->assertTrue($keyword->fresh()->tags()->whereKey((int) $tag->id)->exists());

        $this->actingAs($admin, 'admin')
            ->post(route('admin.image-libraries.images.tags', [
                'libraryId' => (int) $imageLibrary->id,
                'imageId' => (int) $image->id,
            ]), [
                'tag_ids_present' => '1',
                'tag_ids' => [(int) $tag->id],
            ])
            ->assertRedirect();
        $this->assertTrue($image->fresh()->tags()->whereKey((int) $tag->id)->exists());
        $this->assertSame('场景:产品图', (string) $image->fresh()->tags);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.knowledge-bases.detail', ['knowledgeBaseId' => (int) $knowledgeBase->id]))
            ->assertOk()
            ->assertSee(route('admin.knowledge-bases.tags', ['knowledgeBaseId' => (int) $knowledgeBase->id]), false)
            ->assertSee('data-tag-selector-auto-submit="1"', false);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.knowledge-bases.tags', ['knowledgeBaseId' => (int) $knowledgeBase->id]), [
                'tag_ids_present' => '1',
                'tag_ids' => [(int) $tag->id],
            ])
            ->assertRedirect();

        $this->assertTrue($knowledgeBase->fresh()->tags()->whereKey((int) $tag->id)->exists());
        $this->assertSame(1, KnowledgeChunk::query()->where('knowledge_base_id', (int) $knowledgeBase->id)->count());
        $this->assertDatabaseHas('knowledge_chunks', [
            'knowledge_base_id' => (int) $knowledgeBase->id,
            'content' => '原始切片',
        ]);
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
