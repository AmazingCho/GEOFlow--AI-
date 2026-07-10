# GEOFlow 上游选择性采纳执行计划

> **For agentic workers:** REQUIRED SUB-SKILL: Use `pm-requirement-translator` before changing product behavior. Use `superpowers:subagent-driven-development` or `superpowers:executing-plans` to implement one phase at a time. Use `geoflow-testing` for every PHP/Laravel verification. All progress is tracked with checkbox (`- [ ]`) syntax in this file.

**Goal:** 在不破坏本地 Collection、Entity、RAG、CRM、模板工厂和分发工作流的前提下，选择性吸收上游 GEOFlow 中真正有价值的改进，并让上下文压缩或更换 Agent 后可以从明确断点继续。

**Architecture:** 不直接合并 `upstream/main`，也不整包 cherry-pick 大功能提交。每一阶段先确认本地是否已经具备等价能力，再用本地模型、路由、UI 组件和测试方式重新实现最小增量；每阶段独立测试、独立提交、独立记录断点。

**Tech Stack:** Laravel 12、PHP 8.2+、Blade、Tailwind Play CDN、本地 Lucide、PostgreSQL/pgvector、Redis、Docker Compose、PHPUnit、GEOFlow Agent 目标站点包。

## Global Constraints

- 本计划的上游审核基线是 `yaojingang/GEOFlow@f60383028371779f0cddf61cdd634e8471519ffc`，提交日期为 2026-07-05。
- 本地审核基线是 `AmazingCho/GEOFlow--AI-@0c6dbe6f510dd12ae06de730df6cea11fa396818`。
- 审核时 merge-base 为 `a909fba26b90e445bb0b21b2f7885815d0d453fd`，本地/上游独有提交数为 `41 / 103`。
- 禁止执行 `git merge upstream/main`；禁止把上游 103 个独有提交理解成 103 个缺失功能。
- 禁止整包 cherry-pick `1d35469`、`b9a294f`、`22fb479`、`57190cd`、`dfbaec0`、`05732c7`。
- 禁止修改现有 PostgreSQL 容器挂载目标 `/var/lib/postgresql/data`；上游改成 `/var/lib/postgresql` 的补丁不适用于当前已有数据目录。
- 禁止恢复“素材自动推荐 Tag”；Tag 仍只允许人工选择既有标签，分组仍受白名单治理。
- 禁止用 Tag 代替 Collection；所有新增 CRM/知识工作流都必须遵守现有 Collection 边界。
- 禁止新增第二套询盘/商机生命周期；公开表单只能作为 CRM 询盘的待处理入口。
- 禁止让 AI、公开表单或同步接口绕过人工确认直接覆盖正式知识库、远端主题或现有网站设置。
- 禁止引入“后台直接编辑并覆盖 Blade/CSS”的在线主题源码编辑器；继续使用隔离草稿、预览、确认发布和回滚的模板工厂边界。
- 上游企业知识工作流当前实际只支持 `txt/md/markdown/docx`，不得在 UI 或文档中宣称已支持 PDF、Excel、CSV、JSON、XML。
- UI 修改必须复用现有组件并读取 `geoflow-ui-guidelines`；桌面和移动端都要做浏览器截图/布局检查。
- 测试必须优先在 `geoflow-app` 容器执行，并使用当前 `.env` 中有效的 `APP_KEY`，不得向仓库写入测试密钥。

---

## 1. 产品理解与范围

### 业务目标

上游更新不是一次“版本升级”，而是可供定制版挑选的参考实现。本计划要解决三个问题：

1. 修复本地仍存在、且上游已验证的小缺陷。
2. 吸收能降低远端覆盖风险、统一 SEO 输出、改善部署稳定性的机制。
3. 把上游增长中心和企业知识草稿的产品思路，改造成兼容现有 CRM 与知识治理的后续模块。

### 受影响区域

- 后台认证与入口重定向。
- 分发渠道、目标站点 Agent、远端站点设置同步。
- 本地前台、三套内置主题、模板工厂与目标站点包的 SEO metadata。
- Docker 前端资源构建，仅在确有运行时需求时处理。
- CRM 公开询盘表单和待处理提交。
- 知识库多来源草稿整理工作区。
- 站点设置长页面的导航体验，作为低优先级可选项。

### 总体验收标准

- 现有 CRM、任务生成、文章审核、RAG、知识纠错、模板复刻和分发流程不退化。
- 不发生数据库内容丢失、远端主题被自动覆盖或正式知识库被 AI 直接改写。
- 每个完成阶段都有聚焦测试、必要回归测试和可见 UI 验证。
- 每个完成阶段在本文件中勾选、填写 commit 和验证结果，新 Agent 能从第一个未完成阶段继续。

---

## 2. 上下文恢复协议

上下文压缩、换 Agent 或隔天继续时，只执行以下恢复步骤，不要重新全量阅读聊天记录：

- [ ] 读取 `agent-docs/AGENT_BRIEF.md`。
- [ ] 读取本文件；不要先读取全部 `agent-docs`。
- [ ] 执行 `git status --short --branch`，确认用户是否有未提交改动。
- [ ] 执行 `git log -1 --oneline`，确认当前本地断点。
- [ ] 执行 `git fetch upstream --prune`，然后比较：

```bash
git log --oneline --decorate f603830..upstream/main
```

- [ ] 如果上游 HEAD 仍为 `f603830`，直接从“阶段进度表”中第一个未完成阶段继续。
- [ ] 如果上游已有新提交，只把新提交追加到本文件“增量审核记录”；不要重开已审核阶段，也不要直接 merge。
- [ ] 开始阶段前读取该阶段列出的 1-3 份相关文档和代码文件，不全量读取功能说明。
- [ ] 阶段完成后更新本文件的复选框、进度表、commit、测试和 UI 证据。

### 状态规则

- `未开始`：没有修改代码。
- `进行中`：已写失败测试或已有未提交阶段代码；必须在进度表写清当前断点。
- `已完成`：聚焦测试、必要回归和 UI 检查全部通过，并已形成独立 commit。
- `跳过`：经过必要性门槛判定不值得实现；必须写原因，不得只删除任务。
- `阻塞`：存在外部环境或用户决策阻塞；必须写下一条可执行动作。

---

## 3. 阶段总览与 PM 必要性判断

| 顺序 | 阶段 | 必要性 | 预计工作量 | 当前状态 | 允许直接搬代码 |
| --- | --- | --- | --- | --- | --- |
| 1 | 已登录管理员入口重定向修复 | 高，低风险缺陷 | 0.5 天 | 已完成 | 仅核心 1 行逻辑和测试思路 |
| 2 | 远端站点同步预览与覆盖保护 | 与现有产品边界冲突 | 0 天 | 跳过 | 否；本地已禁用该同步能力 |
| 3 | SEO Metadata 统一契约 | 高，减少多主题输出漂移 | 1-2 天 | 已完成 | 可参考 partial，但需适配模板工厂/目标包 |
| 4 | Docker 前端资源构建必要性门槛 | 无运行时消费者 | 0 天 | 跳过 | 不直接搬上游 Compose 补丁 |
| 5 | 公开询盘表单接入现有 CRM | 中高，取决于是否用 GEOFlow 前台获客 | 4-7 天 | 未开始 | 否，禁止复制第二套 Lead 生命周期 |
| 6 | 多来源知识草稿工作区 | 中，适合复杂资料整理 | 5-8 天 | 未开始 | 否，必须复用现有 KB/纠错/队列 |
| 7 | 站点设置页分组导航 | 低，纯体验增强 | 0.5-1 天 | 未开始 | 只借鉴锚点和展开交互 |

推荐先完成阶段 1-3。阶段 4 先做必要性判断；阶段 5-7 必须由用户明确说“执行阶段 N”后再进入。

---

## 4. 阶段 0：执行前基线与保护

**目的：** 确保后续任何阶段都能独立回滚，不把用户已有改动混入上游适配提交。

**涉及文件：** 无业务代码修改。

- [x] 已获取上游最新引用，审核基线为 `f603830`。
- [x] 已确认本地基线为 `0c6dbe6`。
- [x] 已确认本地在计划生成前工作区干净。
- [x] 开始第一个代码阶段前再次执行 `git status --short --branch`。
- [x] 如果工作区不干净，先按文件归属审计；不得覆盖或回滚用户改动。
- [ ] 为本轮实施创建分支，例如：

```bash
git switch -c codex/upstream-selective-adoption
```

- [x] 记录开始 commit：`0c6dbe6f510dd12ae06de730df6cea11fa396818`。

```bash
git rev-parse HEAD
```

- [ ] 涉及数据库迁移的阶段 5/6 开始前，先完成数据库备份或确认最近备份可恢复。

**通过门槛：** 工作区状态、分支和起点 commit 已填写到本文件进度记录。

---

## 5. 阶段 1：已登录管理员入口重定向修复

**上游参考：** `bcd31e2` / `a1be4e3`

**产品目标：** 已登录管理员再次访问 `/admin/login` 或后台入口时，始终回到后台 Dashboard，不跳到公开内容站。

**文件：**

- Modify: `bootstrap/app.php`
- Create: `tests/Feature/AdminGuestRedirectTest.php`
- Update after completion: `docs/CHANGELOG.md`、`docs/CHANGELOG_en.md`

**接口约定：**

- Laravel guest middleware 的已登录用户目标固定为 `route('admin.dashboard')`。
- 未登录用户访问后台入口仍跳到 `admin.login`。
- 不修改后台前缀、登录 guard、登录控制器或公开首页路由。

### 实施清单

- [x] 阅读 `bootstrap/app.php` 与 `routes/web.php` 中 `guest:admin`、`admin.entry`、`admin.login` 路由。
- [x] 新增失败测试，覆盖以下三个行为：
  - 已登录管理员 GET `admin.login` → `admin.dashboard`。
  - 已登录管理员 GET `admin.entry` → `admin.dashboard`。
  - 未登录用户 GET `admin.login` 正常渲染，GET `admin.entry` → `admin.login`。
- [x] 运行测试并确认修改前已登录访问登录页错误跳到公开首页。
- [x] 在 `bootstrap/app.php` 的 middleware 配置中加入：

```php
$middleware->redirectUsersTo(fn () => route('admin.dashboard'));
```

- [x] 执行 PHP lint。
- [x] 执行聚焦测试。
- [x] 回归 `AdminLoginPageTest`，确认首次部署提示和登录流程未退化。
- [x] 浏览器验证由 HTTP 功能测试完成；Codex Browser WebView 当前无法附着，最终验收时再次尝试可视检查。
- [x] 更新中英文 changelog。
- [x] 形成独立 commit：`0c1c412 fix: redirect authenticated admins to dashboard`。

```bash
git commit -m "fix: redirect authenticated admins to dashboard"
```

### 验证命令

```bash
KEY=$(docker exec geoflow-app grep '^APP_KEY=' /var/www/html/.env | cut -d= -f2-)
docker exec -e APP_KEY="$KEY" geoflow-app php artisan test tests/Feature/AdminGuestRedirectTest.php tests/Feature/AdminLoginPageTest.php --stop-on-failure
```

**验收：** 三个路由行为全部通过；公开首页路由未改变；无数据库迁移。

**回滚：** 还原 `bootstrap/app.php` 中该 middleware 回调并删除新增测试文件即可，不影响数据。

---

## 6. 阶段 2：远端站点同步预览与覆盖保护

> **2026-07-11 PM 审计结论：跳过。** 本地版本已经有意移除远端静态首页/站点设置覆盖能力：渠道编辑不调用远端设置同步，详情页不显示同步入口，`syncSettings` 端点固定返回“已禁用”，且完整分发测试覆盖了不得同步、不得重新排队文章等行为。继续实现本阶段会重新引入用户曾明确要求删除的高风险功能，与“优先保护现有功能”冲突。保留正常文章发布、远端文章编辑/删除和分发日志，不新增设置同步预览 UI。

**上游参考：** `22fb479`

**产品目标：** 渠道设置保存只保存本地配置；管理员主动点击“同步设置”后，必须先看到将修改的字段、远端能力和风险，确认后才向目标站发送选定字段。

**核心边界：** 本阶段不是恢复远端首页装修功能。默认不选择 `active_theme`、`front_mode` 或未来的首页布局字段；文章发布不受设置同步预览影响。

**文件：**

- Create: `app/Services/GeoFlow/FrontendExperienceInspector.php`
- Modify: `app/Models/DistributionChannel.php`
- Modify: `app/Services/GeoFlow/DistributionHttpClient.php`
- Modify: `app/Services/GeoFlow/DistributionTargetSitePackageBuilder.php`
- Modify: `app/Http/Controllers/Admin/DistributionController.php`
- Modify: `routes/web.php`
- Create: `resources/views/admin/distribution/sync-preview.blade.php`
- Modify: `resources/views/admin/distribution/show.blade.php`
- Modify: `resources/views/admin/distribution/edit.blade.php` only if current update still triggers automatic sync
- Modify: `lang/zh_CN/admin.php`、`lang/en/admin.php`
- Test: `tests/Feature/AdminDistributionPageTest.php`
- Update after completion: `功能说明文档/` 中分发相关说明、`docs/CHANGELOG*.md`

**服务接口：**

```php
FrontendExperienceInspector::inspect(DistributionChannel $channel, bool $liveRemote = false): array
FrontendExperienceInspector::preview(DistributionChannel $channel, array $selectedKeys): array
FrontendExperienceInspector::requiresConfirmation(array $preview): bool
DistributionHttpClient::frontendCapabilities(DistributionChannel $channel): array
DistributionHttpClient::syncSiteSettings(DistributionChannel $channel, ?array $settings = null): array
```

**缓存约定：** 不新增数据库列。远端能力缓存存入现有 `distribution_channels.channel_config['frontend_capabilities_cache']`，包括 `checked_at`、`capability_version`、`supported_keys`、`current_settings` 和错误状态。

**字段风险分组：**

- 普通设置：`site_name`、`site_subtitle`、`site_description`、`site_keywords`、`copyright_info`、`site_logo`、`site_favicon`、`seo_title_template`、`seo_description_template`。
- 渲染风险设置：`active_theme`、`front_mode`、`featured_limit`、`per_page`，默认不勾选。
- 未知字段：不发送；目标站未声明支持的字段不允许静默发送。

### 审计证据（替代实施清单）

- [x] `DistributionController::syncSettings()` 当前固定返回 `remote_site_sync_disabled`。
- [x] 渠道详情测试确认不输出 `admin.distribution.sync-settings` 入口。
- [x] 渠道更新测试确认即使存在密钥也不会同步远端站点设置。
- [x] 禁用端点测试确认不会发送 HTTP 请求、不会写 `site.settings.synced` 日志。
- [x] 禁用同步不会重新排队已存在的远端文章副本。
- [x] 完整 `AdminDistributionPageTest` 通过：55 tests / 470 assertions。

### 原候选实施清单（归档，不执行）

- [ ] 先写测试：更新渠道配置不得自动调用 `syncSiteSettings()`。
- [ ] 先写测试：GET 同步预览不得修改远端或本地渠道数据。
- [ ] 先写测试：只发送 `selected_keys` 对应字段，不发送空值和未选择字段。
- [ ] 先写测试：`active_theme` 或 `front_mode` 变化必须要求显式风险确认。
- [ ] 先写测试：旧目标包没有 capabilities 接口时默认阻止同步；只有超级管理员输入“确认同步”后才允许强制继续。
- [ ] 先写测试：目标站收到部分字段时必须与原设置合并，未选择字段保持原值。
- [ ] 在 `DistributionChannel` 增加能力缓存读取、标准化和写入 helper，继续复用 `channel_config` cast。
- [ ] 在 `DistributionHttpClient` 增加签名 GET `/geoflow-agent/v1/frontend-capabilities`，保留 `index.php` fallback。
- [ ] 在目标站点包增加只读 capabilities 接口，返回当前设置、支持字段、主题/前台模式和包版本；不得在读取时写文件。
- [ ] 实现 `FrontendExperienceInspector`：生成本地值、远端值、差异、风险和待发送 payload。
- [ ] 增加路由：
  - GET `admin.distribution.sync-preview`
  - POST `admin.distribution.capabilities.refresh`
  - POST 继续复用 `admin.distribution.sync-settings` 执行最终同步
- [ ] 修改渠道保存逻辑：只保存本地；取消“保存后自动同步远端设置”。
- [ ] 同步预览页采用左右差异表，而不是整页 JSON：字段、远端当前值、本地待同步值、是否选择、风险等级。
- [ ] 渲染风险设置放入折叠区域并默认不选；选中后显示醒目警告。
- [ ] 旧目标包强制同步入口只对超级管理员显示，并要求输入“确认同步”；普通管理员只能先升级目标包。
- [ ] 目标站点包的 settings update 改为白名单字段 merge，禁止用部分 payload 覆盖整份现有设置。
- [ ] 最终同步使用 transaction 之外的 HTTP 调用；失败只记录错误，不回滚已保存的本地渠道设置。
- [ ] 记录管理员活动日志：预览、刷新能力、确认同步、强制同步、同步失败。
- [ ] 执行聚焦测试和完整 `AdminDistributionPageTest`。
- [ ] 浏览器验证桌面与移动端：渠道详情 → 同步预览 → 选择普通字段 → 确认同步；无横向溢出、无双层边框、无控制台错误。
- [ ] 使用测试目标站或 mock 验证预览不会写远端文件。
- [ ] 更新功能说明、changelog 和本计划进度表。
- [ ] 形成独立 commit（阶段已跳过，不执行此项）。

```bash
git commit -m "feat: add guarded distribution settings sync preview"
```

### 验收

- 渠道编辑保存不再产生远端写操作。
- 管理员能看见逐字段差异，且只同步勾选字段。
- 主题和前台模式永远不会在无确认情况下被覆盖。
- WordPress REST 渠道不显示 GEOFlow Agent 专用能力检查。
- 文章发布、重试、删除远端副本和现有分发日志不退化。

### 回滚

删除新增预览路由、服务和 view，恢复原同步按钮即可。能力缓存位于 `channel_config`，回滚代码后残留 key 不影响模型读取，不需要数据库回滚。

---

## 7. 阶段 3：SEO Metadata 统一契约

**上游参考：** `57190cd`

**产品目标：** 默认前台、三套内置主题、模板工厂草稿和 GEOFlow Agent 目标站点包使用同一组 SEO 变量语义，避免重复或遗漏 title、description、canonical 和 Open Graph。

**文件：**

- Create: `resources/views/site/partials/seo-head.blade.php`
- Modify: `resources/views/site/layout.blade.php`
- Modify: `resources/views/site/article.blade.php`
- Modify: `resources/views/theme/netease-news-20260507/layout.blade.php`
- Modify: `resources/views/theme/netease-news-20260507/article.blade.php`
- Modify: `resources/views/theme/tdwh-netease-news-en-20260508/layout.blade.php`
- Modify: `resources/views/theme/tdwh-netease-news-en-20260508/article.blade.php`
- Modify: `resources/views/theme/toutiao-news-20260426/layout.blade.php`
- Modify: `resources/views/theme/toutiao-news-20260426/article.blade.php`
- Modify: `app/Http/Controllers/Site/HomeController.php`
- Modify: `app/Http/Controllers/Site/CategoryController.php`
- Modify: `app/Http/Controllers/Site/ArchiveController.php`
- Modify: `app/Http/Controllers/Site/ArticleController.php`
- Modify: `app/Services/Admin/SiteThemeReplication/ThemePreviewRenderer.php`
- Modify: `app/Services/Admin/SiteThemeReplication/ThemeScaffoldWriter.php`
- Modify: `app/Services/GeoFlow/DistributionTargetSitePackageBuilder.php`
- Test: `tests/Feature/SiteArticleMarkdownRenderTest.php`
- Test: `tests/Feature/AdminSiteThemeReplicationTest.php`
- Test: `tests/Feature/AdminDistributionPageTest.php`

**统一变量契约：**

```php
$pageTitle;       // 当前页面完整 title
$pageDescription; // 当前页面描述
$pageKeywords;    // 可为空
$canonicalUrl;    // 当前页面规范 URL
$pageOgType;      // website 或 article
$pageImage;       // 可为空，绝对 URL
$siteName;        // 站点名称
$siteFavicon;     // 可为空
```

**输出规则：**

- 每页只能有一个 `<title>`、一个 canonical、一个 `og:title`、一个 `og:description`、一个 `og:type` 和一个 `og:url`。
- `keywords` 和 `og:image` 为空时不输出空标签。
- Article 使用 `og:type=article`；首页、分类和归档使用 `website`。
- 保留现有 JSON-LD，不能把 Schema 逻辑塞进共享 metadata partial。
- partial 只负责 HTML head metadata，不负责业务数据查询。

### 实施清单

- [x] 先扫描所有 layout/article 模板中的 `<title>`、canonical 和 `og:*`，保存基线数量。
- [x] 先写失败测试：默认主题和三套内置主题的文章页各只输出一组 metadata。
- [x] 先写失败测试：首页、分类、归档和文章页都有非空 canonical；文章页 `og:type=article`。
- [x] 新建 `site.partials.seo-head`，只根据统一变量渲染标签。
- [x] 默认 layout 和三套主题 layout 引入 partial，并删除各 layout 中重复标签。
- [x] 默认 article 和三套主题 article 删除重复 Open Graph push；保留 JSON-LD 和正文结构。
- [x] 补齐四个 Site Controller 的变量，保证 preview 和真实页面语义一致。
- [x] 更新模板工厂的 preview renderer 与 scaffold writer，新生成主题默认引用统一 partial。
- [x] 更新目标站点包生成模板，使远端静态页输出相同 metadata 契约；目标包不依赖主应用 Blade partial。
- [x] 执行聚焦测试和主题/分发回归。
- [x] 浏览器检查真实首页与文章页 metadata；四套视图组合由自动测试覆盖，无重复标签。
- [x] 对模板工厂生成隔离草稿并预览，确认 metadata 不报 undefined variable。
- [x] 运行目标站点包下载测试，确认生成模板仍可独立运行。
- [x] 更新 changelog、本计划进度表；用户操作未变化，不新建功能说明。
- [x] 形成独立 commit：`e46e5b8 refactor: unify frontend SEO metadata contract`。

```bash
git commit -m "refactor: unify frontend SEO metadata contract"
```

### 验收

- 默认前台、三套主题、模板草稿和目标站点包的 metadata 字段一致。
- 页面只有一组 canonical/Open Graph。
- JSON-LD、文章内容、广告注入和主题 CSS 不受影响。
- 无数据库迁移。

---

## 8. 阶段 4：Docker 前端资源构建必要性门槛

**上游参考：** `8bab907`

**当前 PM 结论：** 当前本地运行时模板没有 `@vite` 引用，后台和前台使用本地 Tailwind Play CDN/Lucide 资源；因此不应为了追随上游而增加每次 Compose 启动都执行 `npm ci && npm run build` 的容器。

### 必要性检查

- [x] 完成阶段 1-3 后执行：

```bash
rg -n "@vite|Vite::|public/build|manifest.json" app resources routes
```

- [x] 扫描结果仅命中主题描述用 `manifest.json`，没有 `@vite`、`Vite::` 或 `public/build/manifest.json` 运行时消费者；本阶段标记“跳过”。
- [ ] 如果阶段 2/3 或其他新功能引入 `@vite`，先验证开发和生产容器是否都缺少 `public/build/manifest.json`。
- [ ] 只有确认存在真实缺陷后，才设计不可变镜像的 Node build stage；不要采用运行时每次安装依赖的常驻方案。

### 条件实施边界

- [ ] 不修改 PostgreSQL volume target。
- [ ] 不删除现有 `public/js`、`public/assets` 或主题静态资源。
- [ ] 开发环境可提供显式的一次性 assets profile，但不能让每次 `docker compose up` 都重新下载 npm 依赖。
- [ ] 生产环境若引入 Vite，PHP-FPM 镜像和 Nginx 镜像都必须拿到同一份 `public/build/manifest.json` 与资源文件。
- [ ] 增加部署 smoke test：容器中 manifest 存在，后台首页无 `Vite manifest not found`。
- [ ] 更新部署文档后再提交。

**默认决策：** 当前优先跳过。只有代码实际开始依赖 Vite 时再实施。

---

## 9. 阶段 5：公开询盘表单接入现有 CRM

**上游参考：** `1d35469`、`8de2568`、`d573ae5`

**产品目标：** 在 GEOFlow 前台配置公开询盘表单，外部提交先进入待处理箱；管理员确认后再创建或关联客户，并转换为现有 `crm_inquiries`，不产生第二套销售生命周期。

**推荐数据模型：**

- `crm_public_forms`：表单配置、默认 Collection、默认负责人、字段 JSON、启停状态。
- `crm_public_submissions`：原始提交、来源、UTM、状态 `new/converted/rejected`、转换后的 customer/inquiry ID。
- Submission 只是安全缓冲区，不承担 contacted/qualified/proposal 等 CRM 阶段。

**文件：**

- Create: `database/migrations/2026_07_11_020000_create_crm_public_forms_table.php`
- Create: `database/migrations/2026_07_11_021000_create_crm_public_submissions_table.php`
- Create: `app/Models/CrmPublicForm.php`
- Create: `app/Models/CrmPublicSubmission.php`
- Create: `app/Support/Crm/PublicInquiryFormFields.php`
- Create: `app/Services/Crm/PublicInquiryConversionService.php`
- Create: `app/Http/Controllers/Site/PublicInquiryFormController.php`
- Create: `app/Http/Controllers/Admin/CrmPublicFormController.php`
- Create: `app/Http/Controllers/Admin/CrmPublicSubmissionController.php`
- Create: `resources/views/site/inquiry-forms/show.blade.php`
- Create: `resources/views/admin/crm/public-forms/index.blade.php`
- Create: `resources/views/admin/crm/public-forms/form.blade.php`
- Create: `resources/views/admin/crm/public-submissions/index.blade.php`
- Create: `resources/views/admin/crm/public-submissions/show.blade.php`
- Modify: `routes/web.php`
- Modify: `lang/zh_CN/admin.php`、`lang/en/admin.php`、前台语言文件
- Test: `tests/Feature/CrmPublicInquiryTest.php`
- Update after completion: `功能说明文档/10-轻量CRM与报价使用说明.md`、README、Agent 状态和 changelog

**路由命名：**

- Public GET/POST: `site.inquiry-forms.show`、`site.inquiry-forms.submit`
- Admin forms: `admin.crm.public-forms.*`
- Admin submissions: `admin.crm.public-submissions.*`

### 业务规则

- 公开提交绝不直接创建商机、单据、订单或售后。
- 管理员点击“转换为询盘”时才创建/关联客户与 `crm_inquiries`。
- 转换必须用 transaction + row lock；重复点击返回已生成询盘，不能重复创建。
- Email 精确匹配现有客户时提供候选，不自动覆盖客户资料。
- 新客户创建字段只包括明确提交的信息；未知字段留空，不由 AI 猜测。
- 表单可配置默认 Collection；没有默认 Collection 时，转换前要求管理员确认 Collection 或明确选择“未指定”。
- 来源保存 `source_url`、referrer 和 UTM；`source_channel` 固定为 `website_form`。
- 安全至少包含 CSRF、`throttle:10,1`、honeypot、字段长度限制、select 白名单和同源 redirect。
- CSV 导出必须防止公式注入；敏感数据导出只允许管理员。
- 不提供公开删除或查询提交详情接口。

### 实施清单

- [ ] 先写 migration/model 测试，确认删除表单后 submission 保留且 `form_id` 置空。
- [ ] 先写表单字段标准化和提交校验测试。
- [ ] 先写 throttle、honeypot、禁用表单和非法 select 值测试。
- [ ] 实现后台表单创建/编辑/停用，不实现拖拽式复杂表单设计器。
- [ ] 实现前台独立表单页；第一版不接入首页模块，不恢复远端首页装修能力。
- [ ] 实现待处理列表、详情、拒绝和转换操作。
- [ ] 实现 `PublicInquiryConversionService`，把 payload 映射到客户和 `crm_inquiries`。
- [ ] 转换详情页展示影响摘要：将关联/创建哪个客户、Collection、负责人和询盘标题。
- [ ] 写重复转换保护、客户候选匹配和 transaction rollback 测试。
- [ ] 回归 `AdminCrmPagesTest`、`CrmPipelineAuditTest`，确认现有询盘/商机/单据链不变。
- [ ] 浏览器验证公开表单、后台待处理箱和转换确认弹窗的桌面/移动端。
- [ ] 更新 CRM 使用说明，明确“公开提交不是正式询盘，管理员转换后才进入销售链”。
- [ ] 形成独立 commit，推荐信息：

```bash
git commit -m "feat: add guarded public inquiry intake for CRM"
```

### 验收

- 外部提交不会直接污染正式客户和询盘表。
- 管理员确认后只生成一个客户关联和一个询盘。
- 转换后的询盘可以继续使用现有商机、活动、待办和单据链。
- CRM 管道审计没有新增孤儿记录。

---

## 10. 阶段 6：多来源知识草稿工作区

**上游参考：** `b9a294f`

**产品目标：** 用户可以把多份同一主题资料先放入临时工作区，让 AI 整理成可校对草稿；管理员确认后发布为一条新的正式 Knowledge Base，再进入现有切片/向量化流程。

**推荐命名：** 使用“知识草稿工作区”，不要在导航中再造一个与 Knowledge Base 并列的永久“企业知识库”。

**推荐数据模型：**

- `knowledge_draft_projects`：Collection、名称、状态、AI 模型、草稿内容、错误信息。
- `knowledge_draft_sources`：项目下每份来源的文件名、类型、原文、字符数、顺序和 source hash。
- `knowledge_draft_revisions`：每次 AI 或人工保存的草稿、摘要、来源和 content hash。
- 第一版发布只允许“创建新的 Knowledge Base”；不直接覆盖已有知识库。

**文件：**

- Create: `database/migrations/2026_07_11_030000_create_knowledge_draft_projects_table.php`
- Create: `database/migrations/2026_07_11_031000_create_knowledge_draft_sources_table.php`
- Create: `database/migrations/2026_07_11_032000_create_knowledge_draft_revisions_table.php`
- Create: `app/Models/KnowledgeDraftProject.php`
- Create: `app/Models/KnowledgeDraftSource.php`
- Create: `app/Models/KnowledgeDraftRevision.php`
- Extract/reuse: `app/Services/GeoFlow/KnowledgeSourceParser.php`
- Create: `app/Services/GeoFlow/KnowledgeDraftService.php`
- Create: `app/Jobs/GenerateKnowledgeDraftJob.php`
- Create: `app/Http/Controllers/Admin/KnowledgeDraftController.php`
- Create: `resources/views/admin/knowledge-drafts/index.blade.php`
- Create: `resources/views/admin/knowledge-drafts/create.blade.php`
- Create: `resources/views/admin/knowledge-drafts/show.blade.php`
- Modify: `routes/web.php`、知识库列表/详情入口、中文/英文语言包
- Test: `tests/Feature/AdminKnowledgeDraftTest.php`
- Update after completion: `功能说明文档/04-知识库治理与RAG检索使用说明.md`、Agent 状态、README/changelog

**路由命名：** `admin.knowledge-drafts.index/create/store/show/save/retry/publish`。

### 业务规则

- Collection 必填；Entity 关联可选，并在最终发布时复用现有 `EntityMaterialLinkService`。
- 第一版只接受现有已验证格式：`txt`、`md`、`docx`，最多 10 个文件、单文件 50 MB。
- 不宣称支持 PDF/Excel/CSV/JSON/XML；后续每增加一种格式都必须有独立 parser 和表格保真测试。
- 每份来源独立保存，不把所有文件先无痕拼成一段；AI 输出必须保留来源覆盖摘要和待确认项。
- AI 调用走队列，状态至少为 `draft/queued/running/ready/failed/published`。
- AI 失败时保留来源和项目，允许重试；不能生成空知识库。
- 发布前显示草稿 diff/来源覆盖/待确认项，管理员确认后才创建 Knowledge Base。
- 发布使用现有知识库字段简化规则，不恢复多余必填 metadata。
- 发布成功后调用现有 `SyncKnowledgeBaseChunksJob`；切片失败不回滚已创建的知识库，但要显示失败状态和重试入口。
- 后续如需更新现有知识库，应走 Knowledge Correction proposal 或独立明确流程，不在第一版实现。

### 实施清单

- [ ] 先把当前 `KnowledgeBaseController` 的文件解析逻辑提取为共享 parser，并用现有多文件导入测试防回归。
- [ ] 写 project/source/revision migration 与关系测试。
- [ ] 写多来源保留、source hash、文件限制和非法格式测试。
- [ ] 写 Job 状态流转、失败保留来源和重试测试。
- [ ] 实现创建页：Collection、项目名称、AI 模型、粘贴内容、多文件上传。
- [ ] 实现项目详情：来源列表、生成状态、草稿编辑、修订历史、待确认项和发布入口。
- [ ] 实现 AI 草稿规则：不得发明来源外事实；表格按行列语义转成 Markdown；来源事实遗漏要进入待确认项。
- [ ] 实现自动保存/人工保存 revision，不覆盖历史版本。
- [ ] 实现“发布为新知识库”事务：创建 KB、同步 Entity、写活动日志、派发切片 Job、回写 published ID。
- [ ] 写重复发布幂等保护，已发布项目再次点击只能打开目标知识库。
- [ ] 回归 `AdminMaterialsPagesTest`、`AdminKnowledgeCorrectionTest`、`AdminKnowledgeGovernanceTest`。
- [ ] 浏览器验证创建、排队、失败、ready、修订和发布状态的桌面/移动端 UI。
- [ ] 使用一份含表格的真实样本人工核对数字、单位、行列和来源覆盖。
- [ ] 更新知识库说明，明确“草稿工作区不参与 RAG，发布后的正式知识库才参与”。
- [ ] 形成独立 commit：

```bash
git commit -m "feat: add multi-source knowledge drafting workspace"
```

### 验收

- 多份来源可追溯，草稿不直接进入 RAG。
- AI 失败不会丢失上传资料。
- 发布只创建一条新的正式 KB，且正常进入现有异步切片/向量化链。
- 纠错、重复治理、Collection 健康度和 Entity 关系继续正常工作。

---

## 11. 阶段 7：站点设置页分组导航（可选）

**上游参考：** `3b8df7f`

**产品目标：** 让很长的站点设置页可以快速跳到基础设置、主题/模板、广告和安全区域，不改变任何保存逻辑。

**文件：**

- Modify: `resources/views/admin/site-settings/index.blade.php`
- Modify: `lang/zh_CN/admin.php`、`lang/en/admin.php`
- Test: `tests/Feature/AdminSiteSettingsPageTest.php`

### 实施清单

- [ ] 先截图并统计当前 details 分区，确认真实导航痛点仍存在。
- [ ] 顶部增加紧凑分组导航，不做大面积营销卡片。
- [ ] 点击入口时只展开目标 `<details>` 并滚动到标题；支持 URL hash 直接打开。
- [ ] 保持当前表单、POST 路由和字段 name 不变。
- [ ] 不引入额外 JS 框架；使用短小原生 JS。
- [ ] 桌面与移动端检查，不让顶部导航占用过多首屏。
- [ ] 跑完整 `AdminSiteSettingsPageTest`。
- [ ] 形成独立 commit。

**跳过条件：** 如果当前用户已经能通过浏览器查找或现有折叠分区高效定位，且没有实际误操作记录，则不实施。

---

## 12. 明确不采纳清单

以下内容已完成 PM/架构审核，后续 Agent 不得因“上游已有”而自行恢复：

| 上游内容 | 决策 | 原因 |
| --- | --- | --- |
| Live Theme Editor `dfbaec0` | 跳过 | 直接写 Blade/CSS，破坏模板工厂隔离草稿与确认发布边界 |
| APIHot 主题 `9952bba` | 跳过 | 约 7000 行主题资产，当前没有明确业务需求，维护成本高 |
| 首页模块体系 `e7688cb` | 跳过 | 可能重新引入远端首页设置覆盖；本地没有恢复该能力的需求 |
| GEO 演示文案 `05732c7` | 跳过 | 主要是展示叙事；本地已有真实 RAG、质量评分、追踪和治理 |
| 文章分发可见性 `fecfa30` | 跳过 | 本地已有分发 badge、远端链接、渠道日志和测试 |
| 全主题 null guard `3f0885c` | 跳过 | 上游补丁针对其大量主题，本地现有模板没有命中相同模式 |
| Growth Center 原始 Lead 生命周期 | 跳过原实现 | 会与 CRM 询盘和商机重复；只采纳公开表单入口思路 |
| Enterprise Knowledge 原始并列模块 | 跳过原实现 | 会与正式 KB、纠错、治理、队列重复；改为临时草稿工作区 |
| PostgreSQL volume target 改为父目录 | 永久跳过 | 现有宿主数据目录层级不兼容，可能表现为空库或重新初始化 |
| Compose 每次启动强制 npm install/build | 默认跳过 | 当前无运行时 Vite 消费者，会增加启动时间和网络依赖 |

---

## 13. 每阶段统一验证与提交模板

### 修改前

- [ ] `git status --short --branch`
- [ ] 阅读该阶段列出的代码和最多 1-3 份相关文档。
- [ ] 写失败测试或至少先记录可复现的当前行为。
- [ ] 确认不会修改用户未提交的无关文件。

### 修改后

- [ ] 对所有变更 PHP/Blade/语言文件运行容器内 `php -l`。
- [ ] 运行该阶段聚焦测试。
- [ ] 运行列出的相关回归测试。
- [ ] 涉及 UI 时，用浏览器检查桌面与移动端，并保存截图或写明工具限制。
- [ ] 涉及远端/队列时，验证失败路径、重试和幂等。
- [ ] 检查 `git diff --check`。
- [ ] 检查敏感文件没有被 stage：`.env`、Token、数据库目录、日志、缓存、session、`vendor`、`node_modules`。
- [ ] 只更新受影响文档；不机械更新所有 Agent 文档。
- [ ] 独立 commit，不把下一个阶段混入。
- [ ] 更新下方进度表。

### 测试命令基线

```bash
KEY=$(docker exec geoflow-app grep '^APP_KEY=' /var/www/html/.env | cut -d= -f2-)
docker exec -e APP_KEY="$KEY" geoflow-app php artisan test <相关测试文件> --stop-on-failure
git diff --check
git status --short
```

---

## 14. 阶段进度表

每完成一个阶段立即更新，不要等所有阶段结束后一起补。

| 阶段 | 状态 | 开始基线 | 完成 commit | 自动测试 | UI/运行验证 | 备注/下一断点 |
| --- | --- | --- | --- | --- | --- | --- |
| 0 基线保护 | 已完成 | `0c6dbe6` | 当前分支 `codex/upstream-selective-adoption` | 基线测试已执行 | 不适用 | 已审计并保留计划文档改动 |
| 1 登录重定向 | 已完成 | `0c6dbe6` | `0c1c412` | 7 tests / 25 assertions | 功能测试验证 | 已完成 |
| 2 同步预览保护 | 跳过 | `0c6dbe6` | 不适用 | 55 tests / 470 assertions | 当前 UI 无同步入口 | 本地已明确禁用设置同步，禁止恢复 |
| 3 SEO 契约 | 已完成 | `0c1c412` | `e46e5b8` | 87 tests / 733 assertions | 浏览器检查首页和真实文章页唯一 metadata | 默认/三主题/模板工厂/目标包已统一 |
| 4 Docker assets 门槛 | 跳过 | `0c1c412` | 不适用 | 静态扫描通过 | 无 UI 变更 | 没有运行时 Vite 消费者 |
| 5 公开询盘表单 | 未开始 | - | - | - | - | 等用户明确执行 |
| 6 知识草稿工作区 | 未开始 | - | - | - | - | 等用户明确执行 |
| 7 设置页导航 | 未开始 | - | - | - | - | 低优先级可选 |

---

## 15. 增量审核记录

上游基线 `f603830` 之后如有新提交，只追加记录，不改写历史审核结论。

| 审核日期 | 上游范围 | 结论 | 是否加入现有阶段 | 备注 |
| --- | --- | --- | --- | --- |
| 2026-07-11 | `a909fba..f603830` | 已完成选择性审核 | 是 | 形成阶段 1-7 与明确跳过清单 |

---

## 16. 完成定义

本计划只有在以下条件全部满足时才可标记“主线完成”：

- [ ] 阶段 1-3 已完成并通过测试与 UI 验证。
- [ ] 阶段 4 已明确完成或因无 Vite 消费者标记跳过。
- [ ] 阶段 5-7 均有明确的已完成、跳过或用户暂缓决策，不留模糊“以后再说”。
- [ ] 所有完成阶段已写入 `IMPLEMENTATION_STATUS.md` 和中英文 changelog。
- [ ] 新增用户操作已同步到对应 `功能说明文档`。
- [ ] `AGENT_BRIEF.md` 的状态摘要与本文件一致。
- [ ] 最终测试记录、UI 证据和 commit 已填写到阶段进度表。
- [ ] 推送前完成敏感文件检查，并由用户确认是否推送 GitHub。

本计划生成本身不代表任何阶段功能已经实现。
