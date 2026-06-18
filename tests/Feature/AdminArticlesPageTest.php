<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Article;
use App\Models\ArticleDistribution;
use App\Models\ArticleImage;
use App\Models\Author;
use App\Models\Category;
use App\Models\CollectionRecord;
use App\Models\DistributionChannel;
use App\Models\EntityRecord;
use App\Models\Image;
use App\Models\ImageLibrary;
use App\Models\SiteSetting;
use App\Models\Task;
use App\Models\TaskRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * 后台文章页（Blade）最小可用测试：鉴权、列表渲染、创建/编辑页路由。
 */
class AdminArticlesPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_admin_login_when_visiting_articles_page(): void
    {
        $this->get(route('admin.articles.index'))
            ->assertRedirect(route('admin.login'));
    }

    public function test_authenticated_admin_can_view_articles_page(): void
    {
        $admin = Admin::query()->create([
            'username' => 'articles_admin',
            'password' => 'secret-123',
            'email' => 'articles-admin@example.com',
            'display_name' => 'Articles Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.articles.index', ['status' => 'draft']))
            ->assertOk()
            ->assertSee(__('admin.articles.page_title'))
            ->assertSee('data-article-filter-panel', false)
            ->assertViewHas('articles')
            ->assertViewHas('filters');
    }

    public function test_article_list_can_filter_by_collection_and_unassigned_scope(): void
    {
        $admin = Admin::query()->create([
            'username' => 'articles_collection_admin',
            'password' => 'secret-123',
            'email' => 'articles-collection-admin@example.com',
            'display_name' => 'Articles Collection Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);
        $collection = CollectionRecord::query()->create([
            'name' => 'Article Collection Scope',
            'slug' => 'article-collection-scope',
            'status' => 'active',
        ]);
        $category = Category::query()->create([
            'name' => 'Article Collection Category',
            'slug' => 'article-collection-category',
        ]);
        $author = Author::query()->create(['name' => 'Article Collection Author']);
        Article::query()->create([
            'title' => 'Collection Scoped Article',
            'slug' => 'collection-scoped-article',
            'content' => 'Collection content',
            'excerpt' => 'Collection content',
            'category_id' => (int) $category->id,
            'author_id' => (int) $author->id,
            'status' => 'draft',
            'review_status' => 'pending',
            'selected_collection_id' => (int) $collection->id,
        ]);
        Article::query()->create([
            'title' => 'Unassigned Article',
            'slug' => 'unassigned-article',
            'content' => 'Unassigned content',
            'excerpt' => 'Unassigned content',
            'category_id' => (int) $category->id,
            'author_id' => (int) $author->id,
            'status' => 'draft',
            'review_status' => 'pending',
            'selected_collection_id' => null,
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.articles.index', ['collection_id' => (int) $collection->id]))
            ->assertOk()
            ->assertSee('data-article-collection-cloud', false)
            ->assertSee(__('admin.collections.filter_all'))
            ->assertDontSee('admin.collections.filter_all')
            ->assertDontSee('data-collection-sidebar', false)
            ->assertSee('Article Collection Scope')
            ->assertSee('Collection Scoped Article')
            ->assertDontSee('Unassigned Article');

        $this->actingAs($admin, 'admin')
            ->get(route('admin.articles.index', ['collection_id' => 'unassigned']))
            ->assertOk()
            ->assertSee(__('admin.collections.badge_unassigned'))
            ->assertSee('Unassigned Article')
            ->assertDontSee('Collection Scoped Article');
    }

    public function test_authenticated_admin_can_open_article_create_page(): void
    {
        $admin = Admin::query()->create([
            'username' => 'articles_create_admin',
            'password' => 'secret-123',
            'email' => 'articles-create-admin@example.com',
            'display_name' => 'Articles Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.articles.create'))
            ->assertOk()
            ->assertSee(__('admin.article_create.page_heading'));
    }

    public function test_article_edit_page_renders_markdown_editor_assets_and_actions(): void
    {
        $admin = Admin::query()->create([
            'username' => 'articles_editor_admin',
            'password' => 'secret-123',
            'email' => 'articles-editor@example.com',
            'display_name' => 'Articles Editor Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);
        $category = Category::query()->create([
            'name' => '编辑器分类',
            'slug' => 'editor-category',
        ]);
        $author = Author::query()->create(['name' => 'Editor Author']);
        $article = Article::query()->create([
            'title' => 'Markdown 编辑器测试文章',
            'slug' => 'markdown-editor-article',
            'excerpt' => '摘要',
            'content' => "## 小标题\n\n正文内容",
            'category_id' => (int) $category->id,
            'author_id' => (int) $author->id,
            'status' => 'draft',
            'review_status' => 'pending',
        ]);

        $response = $this->actingAs($admin, 'admin')
            ->get(route('admin.articles.edit', ['articleId' => (int) $article->id]));

        $response->assertOk()
            ->assertSee('vendor/vditor/dist/index.css', false)
            ->assertSee('vendor/vditor/dist/index.min.js', false)
            ->assertSee('id="content-textarea"', false)
            ->assertSee('id="content-editor"', false)
            ->assertSee('id="article-editor-copy-markdown"', false)
            ->assertSee('id="article-editor-copy-wechat-html"', false)
            ->assertSee('id="article-editor-quick-image-input"', false)
            ->assertSee('id="article-editor-context-menu"', false)
            ->assertSee(route('admin.articles.editor.wechat-html', [], false), false)
            ->assertSee(route('admin.articles.editor.images.upload', ['articleId' => (int) $article->id], false), false)
            ->assertSee('navigator.clipboard.writeText', false)
            ->assertSee('ClipboardItem', false)
            ->assertSee(__('admin.article_editor.quick_actions.image'))
            ->assertSee(__('admin.article_editor.wechat.button'));
    }

    public function test_admin_can_export_article_editor_wechat_html(): void
    {
        $admin = Admin::query()->create([
            'username' => 'articles_wechat_export_admin',
            'password' => 'secret-123',
            'email' => 'articles-wechat-export@example.com',
            'display_name' => 'Articles WeChat Export Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $response = $this->actingAs($admin, 'admin')
            ->postJson(route('admin.articles.editor.wechat-html'), [
                'content' => "# 主标题\n\n## 参数表\n\n**重点内容**\n\n| 型号 | 功能 |\n| --- | --- |\n| SJ4060 | 视觉点胶 |\n\n<script>alert('xss')</script>",
            ]);

        $response->assertOk()
            ->assertJsonPath('message', __('admin.article_editor.wechat.success'))
            ->assertJsonStructure(['message', 'html', 'plain']);

        $html = (string) $response->json('html');
        $this->assertStringContainsString('data-geoflow-export="wechat-article"', $html);
        $this->assertStringContainsString('style=', $html);
        $this->assertStringContainsString('<h2', $html);
        $this->assertStringContainsString('<strong', $html);
        $this->assertStringContainsString('class="table-scroll"', $html);
        $this->assertStringContainsString('overflow-x: auto;', $html);
        $this->assertStringNotContainsString('article-table-wrap', $html);
        $this->assertStringNotContainsString('article-table', $html);
        $this->assertStringContainsString('<table', $html);
        $this->assertStringNotContainsString('<script', $html);
    }

    public function test_admin_can_upload_article_editor_image_and_receive_markdown(): void
    {
        Storage::fake('public');

        $admin = Admin::query()->create([
            'username' => 'articles_image_upload_admin',
            'password' => 'secret-123',
            'email' => 'articles-image-upload@example.com',
            'display_name' => 'Articles Image Upload Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);
        $category = Category::query()->create([
            'name' => '图片编辑分类',
            'slug' => 'image-editor-category',
        ]);
        $author = Author::query()->create(['name' => 'Image Editor Author']);
        $article = Article::query()->create([
            'title' => '编辑器图片上传文章',
            'slug' => 'editor-image-upload-article',
            'excerpt' => '摘要',
            'content' => '正文',
            'category_id' => (int) $category->id,
            'author_id' => (int) $author->id,
            'status' => 'draft',
            'review_status' => 'pending',
        ]);

        $response = $this->actingAs($admin, 'admin')
            ->postJson(route('admin.articles.editor.images.upload', ['articleId' => (int) $article->id]), [
                'image' => UploadedFile::fake()->image('editor-screenshot.jpg', 640, 360),
                'alt' => 'GEOFlow 编辑器截图',
                'position' => 3,
            ]);

        $response->assertOk()
            ->assertJsonPath('message', __('admin.article_editor.message.upload_success'))
            ->assertJsonPath('image.alt', 'GEOFlow 编辑器截图');

        $url = (string) $response->json('image.url');
        $this->assertStringStartsWith('/storage/uploads/images/', $url);
        $this->assertSame('![GEOFlow 编辑器截图]('.$url.')', (string) $response->json('image.markdown'));
        Storage::disk('public')->assertExists(str_replace('/storage/', '', $url));

        $this->assertSame(1, ImageLibrary::query()->where('name', '文章编辑器图片')->count());
        $this->assertSame(1, Image::query()->count());
        $this->assertSame(1, ArticleImage::query()->where('article_id', (int) $article->id)->count());
        $this->assertDatabaseHas('article_images', [
            'article_id' => (int) $article->id,
            'position' => 3,
        ]);
    }

    public function test_article_editor_image_upload_rejects_non_images(): void
    {
        Storage::fake('public');

        $admin = Admin::query()->create([
            'username' => 'articles_image_reject_admin',
            'password' => 'secret-123',
            'email' => 'articles-image-reject@example.com',
            'display_name' => 'Articles Image Reject Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);
        $category = Category::query()->create([
            'name' => '图片拒绝分类',
            'slug' => 'image-reject-category',
        ]);
        $author = Author::query()->create(['name' => 'Image Reject Author']);
        $article = Article::query()->create([
            'title' => '编辑器图片拒绝文章',
            'slug' => 'editor-image-reject-article',
            'excerpt' => '摘要',
            'content' => '正文',
            'category_id' => (int) $category->id,
            'author_id' => (int) $author->id,
            'status' => 'draft',
            'review_status' => 'pending',
        ]);

        $this->actingAs($admin, 'admin')
            ->postJson(route('admin.articles.editor.images.upload', ['articleId' => (int) $article->id]), [
                'image' => UploadedFile::fake()->create('notes.txt', 4, 'text/plain'),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['image']);

        $this->assertSame(0, Image::query()->count());
        $this->assertSame(0, ArticleImage::query()->count());
    }

    public function test_article_edit_can_suggest_and_apply_entity_internal_links(): void
    {
        $admin = Admin::query()->create([
            'username' => 'articles_internal_link_admin',
            'password' => 'secret-123',
            'email' => 'articles-internal-link@example.com',
            'display_name' => 'Articles Internal Link Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);
        $category = Category::query()->create([
            'name' => '内链分类',
            'slug' => 'internal-link-category',
        ]);
        $author = Author::query()->create(['name' => 'Internal Link Author']);
        $entity = EntityRecord::query()->create([
            'name' => 'SJ4060',
            'entity_type' => '产品型号',
            'description' => '视觉点胶设备',
            'canonical_url' => 'https://example.com/sj4060',
            'link_anchor_text' => '',
            'link_policy' => 'suggest',
        ]);
        $article = Article::query()->create([
            'title' => 'SJ4060 应用文章',
            'slug' => 'sj4060-internal-link-article',
            'excerpt' => '摘要',
            'content' => 'SJ4060 适合视觉点胶应用。本文介绍 SJ4060 的能力。',
            'category_id' => (int) $category->id,
            'author_id' => (int) $author->id,
            'status' => 'draft',
            'review_status' => 'pending',
            'selected_entity_ids' => [(int) $entity->id],
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.articles.edit', ['articleId' => (int) $article->id]))
            ->assertOk()
            ->assertSee(__('admin.article_edit.internal_links.title'))
            ->assertSee('https://example.com/sj4060', false);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.articles.internal-links.apply', ['articleId' => (int) $article->id]), [
                'entity_ids' => [(int) $entity->id],
            ])
            ->assertRedirect(route('admin.articles.edit', ['articleId' => (int) $article->id]));

        $article->refresh();
        $this->assertStringContainsString('[SJ4060](https://example.com/sj4060)', (string) $article->content);
        $this->assertDatabaseHas('article_internal_links', [
            'article_id' => (int) $article->id,
            'entity_id' => (int) $entity->id,
            'canonical_url' => 'https://example.com/sj4060',
            'status' => 'applied',
        ]);
    }

    public function test_admin_can_save_article_hot_and_featured_flags(): void
    {
        $admin = Admin::query()->create([
            'username' => 'articles_flags_admin',
            'password' => 'secret-123',
            'email' => 'articles-flags@example.com',
            'display_name' => 'Articles Flags Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);
        $category = Category::query()->create([
            'name' => '科技资讯',
            'slug' => 'tech',
        ]);
        $author = Author::query()->create([
            'name' => 'GEOFlow',
        ]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.articles.store'), [
                'title' => '推荐标记测试文章',
                'excerpt' => '摘要',
                'content' => '正文',
                'keywords' => 'GEO',
                'meta_description' => 'Meta',
                'category_id' => $category->id,
                'author_id' => $author->id,
                'status' => 'published',
                'review_status' => 'approved',
                'is_hot' => '1',
                'is_featured' => '1',
            ])
            ->assertRedirect();

        $article = Article::query()->where('title', '推荐标记测试文章')->firstOrFail();

        $this->assertTrue((bool) $article->is_hot);
        $this->assertTrue((bool) $article->is_featured);
    }

    public function test_article_list_shows_hot_and_featured_badges(): void
    {
        $admin = Admin::query()->create([
            'username' => 'articles_badges_admin',
            'password' => 'secret-123',
            'email' => 'articles-badges@example.com',
            'display_name' => 'Articles Badges Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);
        $category = Category::query()->create([
            'name' => '科技资讯',
            'slug' => 'tech',
        ]);
        $author = Author::query()->create([
            'name' => 'GEOFlow',
        ]);
        Article::query()->create([
            'title' => '后台标签展示文章',
            'slug' => 'admin-badges-article',
            'excerpt' => '摘要',
            'content' => '正文',
            'category_id' => $category->id,
            'author_id' => $author->id,
            'status' => 'published',
            'review_status' => 'approved',
            'is_hot' => true,
            'is_featured' => true,
            'published_at' => now(),
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.articles.index'))
            ->assertOk()
            ->assertSee(__('admin.articles.badge.hot'))
            ->assertSee(__('admin.articles.badge.featured'));
    }

    public function test_article_list_shows_distribution_status_badge(): void
    {
        $admin = Admin::query()->create([
            'username' => 'articles_distribution_status_admin',
            'password' => 'secret-123',
            'email' => 'articles-distribution-status@example.com',
            'display_name' => 'Articles Distribution Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);
        $category = Category::query()->create([
            'name' => '分发分类',
            'slug' => 'distribution-category',
        ]);
        $author = Author::query()->create([
            'name' => 'GEOFlow',
        ]);
        $channel = DistributionChannel::query()->create([
            'name' => '目标站点',
            'domain' => 'target.example.com',
            'endpoint_url' => 'https://target.example.com/geoflow/agent',
            'status' => 'active',
        ]);
        $article = Article::query()->create([
            'title' => '分发状态展示文章',
            'slug' => 'distribution-status-article',
            'excerpt' => '摘要',
            'content' => '正文',
            'category_id' => $category->id,
            'author_id' => $author->id,
            'status' => 'published',
            'review_status' => 'approved',
            'published_at' => now(),
        ]);
        ArticleDistribution::query()->create([
            'article_id' => $article->id,
            'distribution_channel_id' => $channel->id,
            'action' => 'publish',
            'status' => 'synced',
            'idempotency_key' => 'article-list-synced',
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.articles.index'))
            ->assertOk()
            ->assertSee(__('admin.distribution.article_status.synced'));
    }

    public function test_article_list_shows_quality_score_for_manual_review(): void
    {
        $admin = Admin::query()->create([
            'username' => 'articles_quality_admin',
            'password' => 'secret-123',
            'email' => 'articles-quality@example.com',
            'display_name' => 'Articles Quality Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);
        $category = Category::query()->create([
            'name' => '质量分类',
            'slug' => 'quality-category',
        ]);
        $author = Author::query()->create(['name' => 'Quality Author']);
        $task = Task::query()->create(['name' => 'Quality Task']);
        $article = Article::query()->create([
            'title' => '质量评分测试文章',
            'slug' => 'quality-score-article',
            'excerpt' => '短摘要',
            'content' => '这是一篇很短的文章，没有 FAQ，也没有案例。',
            'keywords' => 'GEOFlow质量',
            'category_id' => $category->id,
            'author_id' => $author->id,
            'task_id' => $task->id,
            'status' => 'draft',
            'review_status' => 'pending',
            'is_ai_generated' => 1,
        ]);
        TaskRun::query()->create([
            'task_id' => $task->id,
            'status' => 'completed',
            'article_id' => $article->id,
            'duration_ms' => 100,
            'meta' => [
                'generation_trace' => [
                    'knowledge' => ['context_length' => 0, 'chunks' => []],
                    'images' => [],
                ],
            ],
            'started_at' => now()->subSecond(),
            'finished_at' => now(),
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.articles.index'))
            ->assertOk()
            ->assertSee(__('admin.articles.column.quality'))
            ->assertDontSee(__('admin.articles.quality.suggestions.knowledge'));
    }

    public function test_article_list_uses_relative_batch_and_action_urls(): void
    {
        $admin = Admin::query()->create([
            'username' => 'articles_relative_url_admin',
            'password' => 'secret-123',
            'email' => 'articles-relative-url@example.com',
            'display_name' => 'Articles Relative URL Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);
        $category = Category::query()->create([
            'name' => 'URL 分类',
            'slug' => 'url-category',
        ]);
        $author = Author::query()->create(['name' => 'URL Author']);
        Article::query()->create([
            'title' => '相对 URL 测试文章',
            'slug' => 'relative-url-article',
            'excerpt' => '',
            'content' => '正文',
            'category_id' => $category->id,
            'author_id' => $author->id,
            'status' => 'draft',
            'review_status' => 'pending',
        ]);

        $response = $this->actingAs($admin, 'admin')
            ->get(route('admin.articles.index'));

        $response->assertOk();
        $content = (string) $response->getContent();

        $this->assertStringContainsString(
            'action="'.route('admin.articles.batch.update-status', [], false).'"',
            $content
        );
        $this->assertStringContainsString(
            'const EMPTY_TRASH_URL = '.json_encode(route('admin.articles.trash.empty', [], false)).';',
            $content
        );
        $this->assertStringContainsString(
            'const ARTICLE_PUBLISH_URL_TEMPLATE = '.json_encode(route('admin.articles.publish', ['articleId' => '__ID__'], false)).';',
            $content
        );
        $this->assertStringContainsString(
            json_encode(route('admin.articles.batch.delete', [], false)),
            $content
        );
    }

    public function test_admin_brand_stays_geoflow_when_public_site_name_changes(): void
    {
        $admin = Admin::query()->create([
            'username' => 'admin_brand_admin',
            'password' => 'secret-123',
            'email' => 'admin-brand@example.com',
            'display_name' => 'Brand Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);

        SiteSetting::query()->create([
            'setting_key' => 'site_name',
            'setting_value' => 'Public Frontend Name',
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('GEOFlow')
            ->assertDontSee('Public Frontend Name');
    }
}
