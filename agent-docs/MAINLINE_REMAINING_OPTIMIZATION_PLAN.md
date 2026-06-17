# Mainline Remaining Optimization Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use `superpowers:subagent-driven-development` (recommended) or `superpowers:executing-plans` to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 按既定优先级补齐 GEOFlow 主线剩余治理能力：任务回收站、性能压测补强、知识库重复资料治理增强、Collection 健康度面板。

**Architecture:** 所有改动保持增量、可回滚、向后兼容。任务删除改为软删除并保留文章；性能阶段以远程搜索、懒加载、缓存和压力验证为主；知识库治理只生成可审核的 proposal，不自动覆盖内容；Collection 健康度面板只做诊断和跳转修复入口，不直接批量修改数据。

**Tech Stack:** Laravel / PHP / Blade / Tailwind / PostgreSQL / Docker `geoflow-app` / PHPUnit / 现有 GEOFlow 服务层与后台 UI 组件。

---

## 产品理解

用户现在不是要继续扩展新业务线，而是把已经开发出的 GEOFlow 主线系统变得更安全、更稳定、更可治理。

当前优先级顺序固定为：

1. 任务回收站
2. 性能压测补强
3. 知识库重复资料治理增强
4. Collection 健康度面板

本计划不包含以下内容，避免扩散：

- 不做产品资料线 / Datasheet 系统，该方向保留为待定。
- 不恢复“不同素材自动推荐 tag”功能，该功能已按用户要求删除。
- 不做自动合并知识库内容，知识库治理必须由管理员确认后才应用。
- 不继续扩展 CRM 报价审批、邮件发送、统一 CRM 回收站等 CRM 深水区能力。
- 不重新设计 Entity / Tag / Collection 总架构，只补齐当前架构下的治理闭环。

## 全局保护规则

- 不能删除用户已有文章、知识库、素材、Collection、Entity、Case。
- 任何“删除”默认采用软删除、归档或 proposal，不做物理删除。
- 任何知识库内容修改都必须先有预览、版本快照、管理员确认和回滚路径。
- 后台 UI 复用现有样式：卡片、表格、标签云、分页控件、确认弹窗和筛选模块尽量沿用已存在组件。
- 页面新增入口要清晰但克制，避免把任务创建页、知识库页继续堆得更复杂。
- 执行每个阶段前后都检查 `git status`，不要回滚用户或其他 agent 的未提交改动。

## 通用验证命令

执行阶段代码前先取容器内 `APP_KEY`，避免测试环境 APP_KEY 不一致导致加密相关测试失败：

```bash
KEY=$(docker exec geoflow-app sh -c "grep '^APP_KEY=' /var/www/html/.env | cut -d= -f2-")
```

运行测试时使用：

```bash
docker exec -e APP_KEY="$KEY" geoflow-app php artisan test --filter=AdminTasksPageTest
docker exec -e APP_KEY="$KEY" geoflow-app php artisan test --filter=AdminArticlesPageTest
docker exec -e APP_KEY="$KEY" geoflow-app php artisan test --filter=AdminKnowledgeGovernanceTest
docker exec -e APP_KEY="$KEY" geoflow-app php artisan test --filter=AdminCollectionsPageTest
```

如果 Blade 或路由改动后浏览器看不到变化，清理缓存：

```bash
docker exec geoflow-app php artisan optimize:clear
docker exec geoflow-app sh -c 'rm -f /var/www/html/storage/framework/views/*.php'
```

每阶段至少做一次本地页面可视化检查：

- `http://localhost:18080/admin/tasks`
- `http://localhost:18080/admin/articles`
- `http://localhost:18080/admin/knowledge-bases`
- `http://localhost:18080/admin/knowledge-bases/governance`
- `http://localhost:18080/admin/collections`

## 需要暂停让用户决策的点

只有遇到以下情况才停下来问用户：

- 是否允许“永久删除”任务回收站内任务。默认不做物理删除，只提供恢复。
- 是否允许知识库重复资料 proposal 执行“合并正文”。默认只允许归档重复项，不自动合并正文。
- Collection 健康分阈值是否要改变。默认：`80-100` 健康，`60-79` 需关注，`0-59` 需治理。
- 是否把 CRM 的询盘、单据、商机也纳入同一个回收站。默认本轮只做 GEO 生成任务回收站。

---

## Phase 1: 任务回收站

**状态：已完成（2026-06-17）。**  
已实现任务软删除、任务回收站、恢复任务、文章保留 `task_id` 来源关系、文章列表/编辑页“已删除任务”标识；恢复后任务保持暂停。验证：`AdminTasksPageTest` 18 tests / 145 assertions，`AdminArticlesPageTest` 15 tests / 88 assertions。

### 业务目标

删除文章生成任务时，不再把任务生成的文章一起删掉，也不让文章失去来源信息。任务进入回收站后，文章仍然存在，文章详情显示来源任务为“已删除”，管理员可以在回收站恢复任务。

### 影响页面和流程

- 任务列表：`/admin/tasks`
- 任务回收站：新增 `/admin/tasks/trash`
- 文章列表：`/admin/articles`
- 文章编辑页：`/admin/articles/{articleId}/edit`
- 任务 API 删除：`routes/api.php` 的任务删除接口需要与后台行为一致

### 文件计划

- Modify: `/Users/leo/Desktop/GEOFlow/app/Models/Task.php`
- Modify: `/Users/leo/Desktop/GEOFlow/app/Models/Article.php`
- Modify: `/Users/leo/Desktop/GEOFlow/app/Services/GeoFlow/TaskLifecycleService.php`
- Modify: `/Users/leo/Desktop/GEOFlow/app/Services/GeoFlow/TaskMonitoringQueryService.php`
- Modify: `/Users/leo/Desktop/GEOFlow/app/Http/Controllers/Admin/TaskController.php`
- Modify: `/Users/leo/Desktop/GEOFlow/app/Http/Controllers/Api/V1/TaskController.php`
- Modify: `/Users/leo/Desktop/GEOFlow/routes/web.php`
- Modify: `/Users/leo/Desktop/GEOFlow/resources/views/admin/tasks/index.blade.php`
- Create: `/Users/leo/Desktop/GEOFlow/resources/views/admin/tasks/trash.blade.php`
- Modify: `/Users/leo/Desktop/GEOFlow/resources/views/admin/articles/index.blade.php`
- Modify: `/Users/leo/Desktop/GEOFlow/resources/views/admin/articles/form.blade.php`
- Create: `/Users/leo/Desktop/GEOFlow/database/migrations/2026_06_17_120000_add_deleted_at_to_tasks_table.php`
- Modify: `/Users/leo/Desktop/GEOFlow/tests/Feature/AdminTasksPageTest.php`
- Modify: `/Users/leo/Desktop/GEOFlow/tests/Feature/AdminArticlesPageTest.php`

### Implementation Tasks

#### Task 1.1: 写失败测试，锁定任务删除不删除文章

- [ ] 在 `AdminTasksPageTest` 新增测试：创建任务和文章，调用任务删除接口后，文章仍在正常文章列表。

建议测试结构：

```php
public function test_deleting_generation_task_moves_task_to_trash_without_deleting_articles(): void
{
    $this->seedAdminUserAndLogin();

    $task = Task::query()->create([
        'name' => 'Trash Safety Task',
        'status' => 'paused',
        'title_library_id' => $this->createTitleLibrary()->id,
        'prompt_id' => $this->createPrompt()->id,
        'ai_model_id' => $this->createAiModel()->id,
        'article_limit' => 3,
        'draft_limit' => 3,
        'publish_interval' => 3600,
    ]);

    $article = Article::query()->create([
        'title' => 'Article should stay',
        'slug' => 'article-should-stay',
        'content' => '<p>content</p>',
        'status' => 'draft',
        'task_id' => $task->id,
        'is_ai_generated' => 1,
    ]);

    $this->post(route('admin.tasks.delete', ['taskId' => $task->id]))
        ->assertRedirect(route('admin.tasks.index'));

    $this->assertSoftDeleted('tasks', ['id' => $task->id]);
    $this->assertDatabaseHas('articles', [
        'id' => $article->id,
        'task_id' => $task->id,
        'deleted_at' => null,
    ]);
}
```

- [ ] 运行测试，预期失败：当前 `Task` 没有 soft delete，且 `TaskLifecycleService::deleteTask()` 会软删文章并清空 `task_id`。

```bash
KEY=$(docker exec geoflow-app sh -c "grep '^APP_KEY=' /var/www/html/.env | cut -d= -f2-")
docker exec -e APP_KEY="$KEY" geoflow-app php artisan test --filter=AdminTasksPageTest
```

#### Task 1.2: 给生成任务增加软删除能力

- [ ] 新增 migration `2026_06_17_120000_add_deleted_at_to_tasks_table.php`。

核心逻辑：

```php
public function up(): void
{
    if (Schema::hasTable('tasks') && ! Schema::hasColumn('tasks', 'deleted_at')) {
        Schema::table('tasks', function (Blueprint $table): void {
            $table->softDeletes();
        });
    }
}

public function down(): void
{
    if (Schema::hasTable('tasks') && Schema::hasColumn('tasks', 'deleted_at')) {
        Schema::table('tasks', function (Blueprint $table): void {
            $table->dropSoftDeletes();
        });
    }
}
```

- [ ] `Task` model 引入 `SoftDeletes`，并在 casts 中加入：

```php
'deleted_at' => 'datetime',
```

- [ ] `Article::task()` 改为可读取已删除任务：

```php
public function task(): BelongsTo
{
    return $this->belongsTo(Task::class, 'task_id')->withTrashed();
}
```

#### Task 1.3: 修改删除逻辑，保留文章来源

- [ ] 修改 `TaskLifecycleService::deleteTask()`：

原行为需要替换：

- 不再软删 `articles`
- 不再把 `articles.task_id` 更新为 `null`
- 删除 `task_schedules` 可以保留，因为恢复任务后可重新初始化调度
- `task_runs` 保留，用于回收站详情和文章来源追踪
- `article_queue` 中 pending/running 记录需要取消或删除，避免已删除任务继续执行

建议实现：

```php
DB::transaction(function () use ($task): void {
    foreach (['article_queue', 'task_materials', 'task_schedules'] as $table) {
        if (Schema::hasTable($table)) {
            DB::table($table)->where('task_id', (int) $task->id)->delete();
        }
    }

    $task->update([
        'status' => 'paused',
        'schedule_enabled' => 0,
        'next_run_at' => null,
        'updated_at' => now(),
    ]);

    $task->delete();
});
```

- [ ] 删除后广播任务概览，保持现有实时面板刷新。

#### Task 1.4: 新增任务回收站后台入口

- [ ] 在 `routes/web.php` 的 `admin.tasks` 组里新增路由，注意 `trash` 必须放在 `{taskId}/edit` 前：

```php
Route::get('trash', [TaskController::class, 'trash'])->name('trash');
Route::post('{taskId}/restore', [TaskController::class, 'restore'])->name('restore')->whereNumber('taskId');
Route::post('{taskId}/force-delete', [TaskController::class, 'forceDelete'])->name('force-delete')->whereNumber('taskId');
```

- [ ] `TaskController` 新增：

```php
public function trash(): View
{
    $tasks = $this->taskMonitoringQueryService->listTrashedTaskMonitoringRows();

    return view('admin.tasks.trash', [
        'pageTitle' => '任务回收站',
        'activeMenu' => 'tasks',
        'adminSiteName' => AdminWeb::siteName(),
        'tasks' => $tasks,
    ]);
}

public function restore(int $taskId): RedirectResponse
{
    $task = Task::onlyTrashed()->whereKey($taskId)->firstOrFail();
    $task->restore();

    return back()->with('message', '任务已恢复');
}
```

- [ ] `forceDelete` 默认只做入口和二次确认；如果没有用户明确允许，先不展示或只显示禁用提示。

#### Task 1.5: 文章来源显示“已删除”

- [ ] 文章列表任务筛选仍然可以按已删除任务过滤文章。
- [ ] 文章编辑页“生成来源”模块显示任务名，旁边加灰色 badge：`已删除`。
- [ ] 如果任务被永久删除或历史数据没有 task_id，显示：`任务信息不可用`。

建议显示规则：

```php
$sourceTask = $article->task;
$sourceTaskLabel = $sourceTask
    ? $sourceTask->name.($sourceTask->trashed() ? '（已删除）' : '')
    : '任务信息不可用';
```

#### Task 1.6: UI 检查

- [ ] `/admin/tasks` 顶部增加“回收站”入口，样式复用文章管理页的回收站入口。
- [ ] `/admin/tasks/trash` 用表格展示任务名、创建时间、删除时间、生成文章数、操作。
- [ ] 删除任务确认弹窗说明：删除任务不会删除已生成文章。
- [ ] 视觉检查任务列表、回收站、文章编辑页生成来源模块，没有表格挤压和按钮换行异常。

#### Task 1.7: 文档更新

- [ ] 更新 `/Users/leo/Desktop/GEOFlow/agent-docs/IMPLEMENTATION_STATUS.md`：任务回收站从未完成改为已完成。
- [ ] 更新 `/Users/leo/Desktop/GEOFlow/agent-docs/KNOWN_ISSUES.md`：移除或降级任务回收站风险。
- [ ] 更新功能说明文档中的任务管理说明。
- [ ] 更新项目更新日志。

---

## Phase 2: 性能压测补强

**状态：已完成核心功能（2026-06-17）。**  
已确认标签选择器远程搜索、标签引用明细懒加载和知识库向量化队列状态已有；本阶段补齐远程搜索 `pagination.has_more`、统一标签统计缓存 key 与写入侧失效，并新增性能表面测试。验证：`AdminPerformanceSurfaceTest` 2 tests / 8 assertions，material tag 聚焦回归 4 tests / 21 assertions，`AdminTasksPageTest` 18 tests / 145 assertions。真实大数据压测仍可作为后续运维/治理任务继续执行。

### 业务目标

数据量变大后，标签、图片、知识库、文章列表、任务创建页不能一次性渲染大量数据导致卡顿。该阶段重点是验证并补齐性能薄弱点，而不是重做 UI。

### 影响页面和流程

- 标签管理：`/admin/material-tags`
- 关键词库、图片库、知识库列表和详情
- 任务创建页：`/admin/tasks/create`
- 文章列表：`/admin/articles`
- 知识库向量化流程

### 文件计划

- Modify: `/Users/leo/Desktop/GEOFlow/app/Http/Controllers/Admin/TagController.php`
- Modify: `/Users/leo/Desktop/GEOFlow/app/Http/Controllers/Admin/KnowledgeBaseController.php`
- Modify: `/Users/leo/Desktop/GEOFlow/app/Http/Controllers/Admin/ImageLibraryController.php`
- Modify: `/Users/leo/Desktop/GEOFlow/app/Http/Controllers/Admin/KeywordLibraryController.php`
- Modify: `/Users/leo/Desktop/GEOFlow/resources/views/admin/material-tags/index.blade.php`
- Modify: `/Users/leo/Desktop/GEOFlow/resources/views/admin/tasks/form.blade.php`
- Modify: `/Users/leo/Desktop/GEOFlow/resources/views/admin/knowledge-bases/index.blade.php`
- Modify: `/Users/leo/Desktop/GEOFlow/resources/views/admin/image-libraries/detail.blade.php`
- Modify: `/Users/leo/Desktop/GEOFlow/resources/views/admin/keyword-libraries/detail.blade.php`
- Modify: `/Users/leo/Desktop/GEOFlow/app/Jobs/SyncKnowledgeBaseChunksJob.php`
- Create: `/Users/leo/Desktop/GEOFlow/tests/Feature/AdminPerformanceSurfaceTest.php`

### Implementation Tasks

#### Task 2.1: 建立性能覆盖测试

- [x] 创建 `AdminPerformanceSurfaceTest`。
- [x] 测试标签选择器初始页面不输出全部标签。

建议断言：

```php
public function test_task_create_page_does_not_render_all_material_tags_initially(): void
{
    $this->seedAdminUserAndLogin();
    Tag::factory()->count(120)->create(['type' => 'material', 'group_name' => 'Topic']);

    $response = $this->get(route('admin.tasks.create'));

    $response->assertOk();
    $this->assertLessThan(60, substr_count((string) $response->getContent(), 'data-tag-option'));
}
```

- [x] 测试标签远程搜索接口支持关键词过滤、数量限制和 `pagination.has_more`。

建议接口响应规则：

```php
[
    'items' => [
        ['id' => 1, 'label' => 'Topic: Troubleshooting', 'group_name' => 'Topic', 'name' => 'Troubleshooting'],
    ],
    'pagination' => ['has_more' => true],
]
```

#### Task 2.2: 补齐标签远程搜索覆盖面

- [x] 盘点标签选择入口，确认任务创建页和素材编辑页复用已有标签选择器。
- [x] 继续复用已有 `admin.partials.tag-selector`，不新增重复组件。
- [x] 输入框聚焦时只通过远程接口加载候选，不在页面初始输出全部标签。
- [x] 输入搜索词后请求远程接口。
- [x] 已选标签显示为标签云，不把全部候选平铺在素材下方。
- [x] 所有相关 `<input>` 保持：

```html
class="border-0 p-0 text-sm focus:ring-0 outline-none"
```

#### Task 2.3: 标签引用明细懒加载

- [x] 标签管理页的“查看引用”弹窗点击后再请求详情。
- [x] 列表页只加载引用计数，不预加载所有引用素材清单。
- [x] 复用接口：

```php
GET /admin/material-tags/{tagId}/references
```

- [x] 响应按素材类型分组：关键词、图片、知识库、实体、案例。
- [x] 弹窗内按组限制返回数量，并提供对应素材入口跳转。

#### Task 2.4: 统计数据缓存

- [x] 标签管理统计使用短 TTL 缓存。
- [x] 默认 TTL：`300` 秒。
- [x] 标签创建、删除、重命名、批量移动和素材标签同步后清理 `admin:material-tags:stats:v1`。
- [ ] Collection 素材统计、知识库治理统计更多缓存覆盖保留为真实大数据压测后的扩展项。

建议 cache key 格式：

```php
"admin:material-tags:stats:v1"
"admin:collections:stats:v1"
"admin:knowledge-governance:stats:v1:collection:{$collectionId}"
```

#### Task 2.5: 大文件知识库向量化队列验证

- [x] 检查 `SyncKnowledgeBaseChunksJob` 已设置 `timeout`、`tries` 和失败状态写回。
- [x] 确认大文件导入后的 queued / running / completed / failed 状态流程已存在。
- [x] 复用既有知识库队列聚焦测试，不重复改全局队列配置。
- [ ] 真实超大文件导入、worker 限流和长耗时失败恢复保留为后续压力验证。

#### Task 2.6: UI 和浏览器检查

- [x] 通过 `AdminTasksPageTest` 回归确认任务创建页核心筛选与提交流程未回退。
- [x] 本阶段未新增复杂 UI，继续沿用已有标签选择器和懒加载弹窗，避免引入新的遮挡和双边框问题。
- [ ] 图片库、关键词库、知识库在真实大量素材下的滚动与选择体验仍建议后续用浏览器做专项压测。

---

## Phase 3: 知识库重复资料治理增强

**状态：已完成核心功能（2026-06-17）。**  
已实现 `KnowledgeGovernanceProposal`、proposal 服务、控制器、详情页和治理页入口；重复资料可创建 pending proposal，输入确认文本后只把重复知识库状态改为 `inactive`，不删除资料、不删除切片、不改正文，并支持回滚。事实冲突 proposal 只记录人工审核结果。自动合并正文仍保持禁用。验证：`AdminKnowledgeGovernanceTest` 4 tests / 34 assertions，旧知识库治理聚焦回归 4 tests / 35 assertions。

### 业务目标

当前知识库治理页能提示重复和冲突，但不能形成可追踪的处理流程。该阶段增加 proposal 工作流：系统发现问题，管理员创建治理建议，审核后应用，保留版本和回滚能力。

### 影响页面和流程

- 知识库治理页：`/admin/knowledge-bases/governance`
- 知识库详情页
- 知识库切片和 embedding 更新流程
- 管理员操作日志

### 文件计划

- Modify: `/Users/leo/Desktop/GEOFlow/app/Services/GeoFlow/KnowledgeDuplicateDetectionService.php`
- Modify: `/Users/leo/Desktop/GEOFlow/app/Services/GeoFlow/MaterialGovernanceAuditService.php`
- Create: `/Users/leo/Desktop/GEOFlow/app/Models/KnowledgeGovernanceProposal.php`
- Create: `/Users/leo/Desktop/GEOFlow/app/Services/GeoFlow/KnowledgeGovernanceProposalService.php`
- Modify: `/Users/leo/Desktop/GEOFlow/app/Http/Controllers/Admin/KnowledgeBaseController.php`
- Modify: `/Users/leo/Desktop/GEOFlow/routes/web.php`
- Modify: `/Users/leo/Desktop/GEOFlow/resources/views/admin/knowledge-bases/governance.blade.php`
- Create: `/Users/leo/Desktop/GEOFlow/resources/views/admin/knowledge-bases/governance-proposal.blade.php`
- Create: `/Users/leo/Desktop/GEOFlow/database/migrations/2026_06_17_121000_create_knowledge_governance_proposals_table.php`
- Create: `/Users/leo/Desktop/GEOFlow/tests/Feature/AdminKnowledgeGovernanceTest.php`

### Implementation Tasks

#### Task 3.1: 创建 proposal 数据结构

- [x] 新增表 `knowledge_governance_proposals`。

字段建议：

```php
$table->id();
$table->string('proposal_type', 40); // duplicate_archive, duplicate_merge, conflict_review
$table->string('status', 40)->default('pending'); // pending, approved, rejected, applied, rolled_back
$table->foreignId('collection_id')->nullable()->constrained('collections')->nullOnDelete();
$table->foreignId('primary_knowledge_base_id')->nullable()->constrained('knowledge_bases')->nullOnDelete();
$table->json('related_knowledge_base_ids')->nullable();
$table->json('detection_snapshot')->nullable();
$table->longText('proposed_content')->nullable();
$table->longText('before_content_snapshot')->nullable();
$table->text('admin_note')->nullable();
$table->timestamp('applied_at')->nullable();
$table->timestamp('rolled_back_at')->nullable();
$table->timestamps();
```

- [x] Model casts：

```php
'related_knowledge_base_ids' => 'array',
'detection_snapshot' => 'array',
'applied_at' => 'datetime',
'rolled_back_at' => 'datetime',
```

#### Task 3.2: 治理页只创建 proposal，不直接改知识库

- [x] 在重复组卡片里增加“创建治理建议”按钮。
- [x] 在冲突组卡片里增加“创建审核建议”按钮。
- [x] 按钮提交后生成 pending proposal。
- [x] 页面提示：该操作不会修改知识库内容。

#### Task 3.3: Proposal 详情页

- [x] 详情页展示：
  - 问题类型
  - 涉及知识库
  - 相似度 / 冲突字段
  - 原文摘要
  - 建议动作
  - 管理员备注
- [x] 对 duplicate_archive，默认建议保留主知识库，归档重复知识库。
- [x] 对 duplicate_merge，默认禁用应用按钮，除非用户明确确认允许合并正文。
- [x] 对 conflict_review，只记录审核结果，不自动改正文。

#### Task 3.4: 应用和回滚

- [x] 应用 duplicate_archive 时：
  - 重复知识库 `status` 改为 `inactive` 或 `archived`
  - 不删除知识库，不删除 chunks
  - 写入 `admin_activity_logs`
- [ ] 如果未来用户允许 duplicate_merge：
  - 应用前写入 `knowledge_chunk_versions`
  - 更新 knowledge base content
  - 重新 dispatch chunk/embedding job
- [x] 回滚时恢复 status 快照；content 没有被本阶段修改。

#### Task 3.5: 测试

- [x] 测试创建 proposal 后，知识库内容未变化。
- [x] 测试 archive proposal 应用后，重复知识库变为归档。
- [x] 测试 rollback 后，状态恢复。
- [x] 测试没有确认文本时不能应用 destructive action。

#### Task 3.6: UI 检查

- [x] 治理页保留只读扫描结果。
- [x] Proposal 操作按钮不抢主视觉，避免用户误以为系统已经自动处理。
- [x] Proposal 详情页提供返回治理页和返回知识库详情页入口。

---

## Phase 4: Collection 健康度面板

**状态：已完成（2026-06-17）。**  
已实现 `CollectionHealthService`、Collection 列表健康分数徽标、`/admin/collections/{collectionId}/health` 只读健康详情页；检查 Entity、知识库、标题库、图片库、Case、素材-Entity 关联、知识片段向量化、受控标签分组和重复标签。验证：`AdminCollectionsPageTest` 6 tests / 90 assertions。

### 业务目标

Collection 是顶层业务容器。用户需要知道某个 Collection 是否已经具备生成文章所需的素材基础，哪些缺失、哪些有风险、应该去哪里修。

### 影响页面和流程

- Collection 列表：`/admin/collections`
- Collection 健康页：新增 `/admin/collections/{collectionId}/health`
- 知识库、Entity、Case、图片库、标题库、标签治理入口

### 文件计划

- Create: `/Users/leo/Desktop/GEOFlow/app/Services/GeoFlow/CollectionHealthService.php`
- Modify: `/Users/leo/Desktop/GEOFlow/app/Http/Controllers/Admin/CollectionController.php`
- Modify: `/Users/leo/Desktop/GEOFlow/routes/web.php`
- Modify: `/Users/leo/Desktop/GEOFlow/resources/views/admin/collections/index.blade.php`
- Create: `/Users/leo/Desktop/GEOFlow/resources/views/admin/collections/health.blade.php`
- Modify: `/Users/leo/Desktop/GEOFlow/lang/zh_CN/admin.php`
- Modify: `/Users/leo/Desktop/GEOFlow/tests/Feature/AdminCollectionsPageTest.php`

### Health Rules

默认健康度从 100 分开始扣分：

| 问题 | 严重度 | 扣分 |
| --- | --- | --- |
| Collection 没有 Entity | high | -20 |
| Collection 没有知识库 | high | -20 |
| Collection 没有标题库 | high | -15 |
| Collection 没有图片库 | medium | -10 |
| Collection 没有 Case | medium | -10 |
| 知识库未关联 Entity | medium | -8 |
| Case 未关联 Entity | medium | -8 |
| 存在未向量化知识库 chunks | high | -15 |
| 存在非白名单 tag group | medium | -8 |
| 存在重复 tag | low | -5 |

分数区间：

- `80-100`：健康
- `60-79`：需关注
- `0-59`：需治理

### Implementation Tasks

#### Task 4.1: 创建 CollectionHealthService

- [ ] 服务输出固定结构：

```php
[
    'score' => 82,
    'status' => 'healthy',
    'summary' => [
        'entities' => 12,
        'knowledge_bases' => 8,
        'title_libraries' => 2,
        'image_libraries' => 3,
        'cases' => 4,
    ],
    'issues' => [
        [
            'key' => 'knowledge_without_entities',
            'severity' => 'medium',
            'label' => '有知识库未关联 Entity',
            'count' => 3,
            'action_label' => '查看知识库',
            'action_url' => route('admin.knowledge-bases.index', ['collection_id' => $collectionId]),
        ],
    ],
]
```

- [ ] 所有检查只读，不修改数据。

#### Task 4.2: 新增健康页路由和控制器方法

- [ ] `routes/web.php`：

```php
Route::get('{collectionId}/health', [CollectionController::class, 'health'])
    ->name('health')
    ->whereNumber('collectionId');
```

- [ ] `CollectionController::health()`：

```php
public function health(int $collectionId, CollectionHealthService $healthService): View
{
    $collection = CollectionRecord::query()->whereKey($collectionId)->firstOrFail();

    return view('admin.collections.health', [
        'pageTitle' => 'Collection 健康度',
        'activeMenu' => 'collections',
        'adminSiteName' => AdminWeb::siteName(),
        'collection' => $collection,
        'health' => $healthService->build($collection),
    ]);
}
```

#### Task 4.3: Collection 列表增加健康入口

- [ ] Collection 列表每行增加健康状态 badge。
- [ ] 操作列增加“健康度”图标按钮。
- [ ] 不把详细 issues 全部塞进列表页，避免列表拥挤。

#### Task 4.4: 健康页 UI

- [ ] 顶部卡片展示 score、状态、Collection 名称。
- [ ] 下方五个素材统计卡片：Entity、知识库、标题库、图片库、Case。
- [ ] 问题列表按 high、medium、low 分组。
- [ ] 每个问题提供修复跳转，不提供一键自动修复。

#### Task 4.5: 测试

- [ ] 测试没有素材的 Collection 分数低于 60。
- [ ] 测试素材完整的 Collection 分数高于 80。
- [ ] 测试健康页包含修复链接。
- [ ] 测试非白名单 tag group 会出现在 issues 中。

---

## 阶段执行节奏

每个阶段执行时按这个固定节奏：

1. `git status --short`，记录当前未提交改动。
2. 写失败测试。
3. 运行目标测试，确认失败原因符合预期。
4. 实现最小可用代码。
5. 运行目标测试。
6. 运行相关 PHP lint：

```bash
docker exec geoflow-app php -l app/Models/Task.php
docker exec geoflow-app php -l app/Http/Controllers/Admin/TaskController.php
docker exec geoflow-app php -l app/Services/GeoFlow/TaskLifecycleService.php
```

7. 清理缓存并进行浏览器可视化检查。
8. 更新 `agent-docs/IMPLEMENTATION_STATUS.md`、`agent-docs/KNOWN_ISSUES.md` 和更新日志。
9. 阶段总结，说明实现、跳过、风险和下一阶段。

## 完成情况对照表模板

每阶段完成后用以下表格汇报：

| 阶段 | 状态 | 已实现 | 跳过/未实现 | 测试 | UI 检查 | 风险 |
| --- | --- | --- | --- | --- | --- | --- |
| Phase 1 任务回收站 | 已完成 | 任务软删除、回收站、恢复、文章来源保留和已删除任务标识 | 未提供永久删除入口 | `AdminTasksPageTest` 18 tests / 145 assertions；`AdminArticlesPageTest` 15 tests / 88 assertions | 已完成 PM/UI 自检 | 删除语义已变为回收站，恢复后保持暂停 |
| Phase 2 性能压测补强 | 已完成核心功能 | 标签远程搜索、引用懒加载、`has_more`、标签统计缓存失效、性能表面测试 | 未做真实上千级数据压测 | `AdminPerformanceSurfaceTest` 2 tests / 8 assertions；material tag 聚焦回归 4 tests / 21 assertions；`AdminTasksPageTest` 18 tests / 145 assertions | 已完成 PM/UI 自检，未新增复杂页面 | 仍需真实大数据验证图片、知识库、文章等列表性能 |
| Phase 3 知识库重复资料治理增强 | 已完成核心功能 | 治理 proposal 表、创建入口、详情页、重复项确认归档、冲突审核记录、管理员日志和回滚 | 自动合并正文默认禁用 | `AdminKnowledgeGovernanceTest` 4 tests / 34 assertions；旧治理页聚焦回归 4 tests / 35 assertions | 已完成 PM/UI 自检，详情页渲染测试通过 | 归档只改 `inactive`；正文合并需后续单独设计 |
| Phase 4 Collection 健康度面板 | 已完成 | Collection 列表健康徽标和只读详情页 | 未提供自动修复入口 | `AdminCollectionsPageTest` 6 tests / 90 assertions | 已完成 PM/UI 自检 | 只读体检，不自动修复 |

## 自检结果

- 顺序已按当前主线优先级固定。
- 任务回收站限定为 GEO 文章生成任务，不混入 CRM 待办。
- 性能阶段以验证和补齐为主，不重构页面。
- 知识库治理默认不自动合并，保护现有知识库内容。
- Collection 健康度只做诊断和跳转，不做自动修改。
- 自动 tag 推荐不在本计划内，避免恢复已删除功能。
- Product Datasheet 和更深 CRM 模块不在本轮范围，避免主线失焦。
