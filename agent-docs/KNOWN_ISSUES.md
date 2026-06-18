# 已知问题与风险

本文档用于记录容易被上下文压缩丢失的问题。

### 12. APP_KEY 损坏风险与 Docker 环境覆盖

**症状：** Server Error 500，日志显示 `Unsupported cipher or incorrect key length`。

**根因：** 

- `php artisan key:generate --force` 可能不会替换 `APP_KEY=` 行，而是把新 key 拼接到旧 key 后面，造成 `.env` 中出现类似 `APP_KEY=base64:OLD_KEY=base64:NEW_KEY=` 的双 key 拼接（88 字符 / 66 字节），远超 AES-256-CBC 所需的 32 字节，Laravel 加密器直接崩溃。
- Docker 容器环境变量 `APP_KEY=""`（空值）会覆盖 `.env` 中的有效 key。entrypoint 脚本检测到空值后 `unset APP_KEY`，但 PHP 测试子进程仍可能继承空值。

**修复步骤：**

1. 检查 `.env` 的 `APP_KEY` 是否只有一个 `base64:...` 值（44 字符 base64 + 1 个 padding = 32 字节）
2. 用 `sed` 直接写一行有效 key：`sed -i "s|^APP_KEY=.*|APP_KEY=base64:<32字节随机key>|" .env`
3. 重启容器：`docker restart geoflow-app`
4. 验证：`curl -s http://localhost:18080/` 应返回 200

**运行测试的正确方式：**

容器内直接跑 `php artisan test` 会因为 `APP_KEY=""` 环境变量覆盖 `.env` 而报 `MissingAppKeyException`。正确方式：

```shell
docker exec -e APP_KEY=base64:$(grep '^APP_KEY=' .env | cut -d= -f2-) \
  geoflow-app sh -c 'php artisan config:clear && php artisan test --filter=AdminCrmPagesTest'
```

## 高优先级

### 1. 任务回收站已完成，永久删除仍需谨慎

当前任务删除已改为软删除：

- 删除任务不会删除文章。
- 文章仍保留 `task_id` 来源关系，并可显示“已删除任务”状态。
- 任务会进入回收站。
- 回收站支持查看和恢复；恢复后任务保持暂停，避免自动继续生成。

仍需注意：

- 默认不开放物理永久删除任务，除非用户明确确认数据保留策略。
- 如果后续要做永久删除，必须继续保护文章、生成 trace、分发记录和任务名称快照。
- 不要把这个历史待办重新当作未实现功能执行。

### 2. AI 知识库纠错助手已完成核心链路，仍有可增强项

当前已实现：

- 用户可从知识库详情页或文章编辑页发起纠错。
- AI 只生成 correction proposal，不直接覆盖知识库内容。
- 管理员可批准、拒绝、应用纠错。
- 应用后更新目标 knowledge chunk，并刷新该 chunk 的 embedding / fallback vector。
- 保存知识片段版本并支持从旧版本回滚。

仍可增强：

- 当前详情页使用“原文 / 建议内容”并排对比，不是字符级 diff。
- 当前主要刷新单个命中的 chunk；如果未来知识库切片策略变化很大，可能需要提供“重新切片整库”的人工入口。
- 纠错记录列表已有筛选，但还没有高级批量审核能力。

### 3. CRM V2 仍保留轻量边界

轻量 CRM 与 CRM 销售链路 V2 已完成核心功能：

- 客户资料、多外部联系人、内部负责人、活动记录和未来待办。
- 询盘与 AI 需求识别。
- 商机阶段管道、来源模式、重复保护、归档与恢复。
- 客户 / 询盘 / 商机统一活动时间线。
- 活动创建待办，待办完成可写回活动结果。
- 单据链一致性校验与自动补齐。
- `crm:pipeline-audit` 只读审计与唯一候选历史修复。
- 单据制作与五类 HTML 打印页。
- 订单管理。
- 售后工单。
- CRM 内容候选审核。
- 创建任务页 CRM 来源关联。
- 询盘活动按询盘隔离；客户页可汇总活动。
- 客户及主要商业对象使用软删除归档，不再因归档客户而级联丢失记录。

仍未实现且建议保持独立阶段：

- 报价审批。
- 邮件发送。
- 询盘、待办、单据的统一归档回收站。
- 旧模块负责人文本字段到 Admin 外键的完整迁移。
- 剩余无唯一候选历史数据的人工治理界面。

已完成并应继续保护：

- CRM 单据 PDF 下载已接入 Chromium/Puppeteer，详情页和打印预览页已有入口。
- PDF 复用现有 HTML 打印模板，保留 HTML 打印预览作为失败兜底。
- 长明细动态分页已落地，不要再按“PDF 导出未实现”处理该模块。

说明：人工活动记录与未来待办已经拆分并可联动；尚未实现的是更广义的自动活动流，例如状态变更、单据创建、订单推进和邮件事件自动写入同一时间线。

后续继续保持轻量 CRM 定位，不要引入完整 ERP 财务、库存、采购和生产排程逻辑。

### 4. 旧文章可能没有生成来源

旧流程生成的文章可能没有 trace。

如果文章编辑页看不到“生成来源”，不要直接判断为 UI bug，应先检查该文章是否有生成追踪数据。

## 中优先级

### 5. 阶段 7 性能优化仍需要真实数据压力验证

核心机制已经补齐或确认，包括标签远程搜索、标签引用明细懒加载、标签统计短缓存和知识库向量化队列状态。后续仍需要在大量真实数据下检查：

- 图片、关键词、知识库列表分页是否足够稳定。
- 标签远程搜索在上千标签下的响应速度。
- 标签引用明细在大量关联素材下的分页或跳转体验。
- 统计缓存是否覆盖 Collection 素材统计、知识库治理统计等更多重计算场景。
- 大文件知识库向量化已经改为异步队列并具备状态追踪，但仍需用真实大文件验证 queue worker、Embedding 模型限流、失败重试和超时配置。

### 6. UI 入口可能出现后端已实现但前端不明显

之前多次出现功能已实现但页面入口不明显的情况。

每次新增功能都要检查：

- 列表页入口。
- 创建页入口。
- 编辑页入口。
- 详情页入口。
- 批量操作入口。
- 空状态提示。

### 7. 标签分组治理需要继续保持克制

不要把标签分组无限扩展。

新增标签分组前应判断：

- 是否已经可以用现有分组表达。
- 是否其实应该是 Collection。
- 是否其实应该是 Entity 类型。
- 是否其实应该是 Knowledge Base type 或 role。

不同素材自动 tag 推荐已按用户要求移除。后续不要因为看到旧阶段名称就恢复该功能，除非用户重新确认推荐规则、误标签处理和人工审核流程。

### 8. AI 表格解析已增强但仍需人工复核

`MaterialAnalysisPromptRules` 已加入表格/参数保真规则，但复杂网页表格、合并单元格、图片表格、PDF 转文本错位等场景仍可能出错。

涉及产品参数、尺寸、电压、功率、容量、价格、测试条件等内容时，入库前必须人工检查 Markdown 表格或 key-value 列表。

### 9. 知识库重复与冲突检测是启发式结果，应用前仍需人工确认

阶段 5 已新增知识库治理检查页，可发现疑似重复资料和数值型事实冲突。主线 Phase 3 已补充 proposal 工作流：重复资料可以创建治理建议，管理员输入确认文本后将重复项状态改为 `inactive`，并支持回滚。

需要注意：

- 它根据内容指纹、来源 URL、标题相似度、Entity / Collection 范围和数值型字段做启发式判断。
- 结果用于人工复核，不代表一定错误。
- 创建 proposal 不会立即修改知识库。
- 应用重复归档 proposal 只会把重复知识库状态改为 `inactive`，不会删除资料、删除 chunks、覆盖正文或重建 embedding。
- 事实冲突 proposal 只记录人工审核结论，不会自动修改正文。
- 如果后续要做“自动合并正文”，必须先设计更严格的人工确认、版本记录、diff、回滚和 embedding 重建策略。

### 10. Skill Prompt 自动匹配仍是规则版，不是 AI 分类器

当前 Prompt Skill System v1 支持任务页人工选择 Skill Prompt，也支持创建任务页默认“智能推荐”。

已实现：

- 根据标题自动识别 comparison / buying guide / application 等意图。
- 根据识别结果匹配已有 Skill Prompt。
- 保留“不使用 Skill Prompt”和手动覆盖入口。

尚未实现：

- 根据关键词 intent 自动匹配 Skill。
- AI Intent Classification。

后续如引入 AI Intent Classification，不要替代当前规则兜底；应继续保留人工覆盖入口，并在任务页说明推荐原因。

## 低优先级

### 11. 当前项目路径容易混淆

历史工作主要在：

`/Users/leo/Desktop/GEOFlow`

当前 shell 环境有时显示：

`/Users/leo/Documents/GEOWorkflow-optimized`

但该目录可能不是完整项目。新 agent 开始写文件前必须先确认项目根目录中是否存在 `app/`、`database/`、`resources/`、`artisan`。

### 13. Docker 缓存可能导致前端刷新不生效

如果代码已改但页面无变化，先检查：

- Laravel view cache。
- config cache。
- Docker app 容器是否需要重启。
- 浏览器缓存。

### 14. OPcache `validate_timestamps=0` 导致代码改动不可见

容器 `/usr/local/etc/php/conf.d/99-opcache.ini` 的 `opcache.validate_timestamps=0` 会在本地开发挂载代码卷的场景下导致所有源文件修改被 OPcache 忽略——即使执行 `view:clear`、`config:clear`、`docker restart` 也不行。

**症状：** Blade/PHP 语法检查通过、缓存清完、容器已重启，但页面行为仍然是旧代码。

**修复（一次性）：**

```bash
docker exec geoflow-app sh -c '
  sed -i "s/opcache.validate_timestamps=0/opcache.validate_timestamps=1/" /usr/local/etc/php/conf.d/99-opcache.ini
'
docker restart geoflow-app
```

**说明：** `validate_timestamps=1` 让 PHP 检查文件 mtime 并自动重新编译——本地开发的正确配置。生产环境（代码不挂载卷）应保持 `0` 并通过重建镜像更新代码。

### 15. Eloquent Model `$fillable` 遗漏导致字段静默保存失败

Laravel mass assignment 保护会**静默丢弃**所有未在 `$fillable` 中声明的字段，`$customer->update($data)` 或 `CrmCustomer::create($data)` 不会报错，字段值直接丢失。

**症状：** 表单提交成功（无 422/500），redirect 正常，但新填的值在数据库中仍是旧的。

**排查步骤：**

1. 检查 Controller 的 `validateCustomer()` 是否包含该字段 ✅
2. 检查 `normalizeCustomerPayload()` 是否包含该字段 ✅
3. 检查 Model 的 `$fillable`（或 `$guarded`）是否包含该字段 ← 常见遗漏点
4. 确认后运行 `AdminCrmPagesTest` 验证

**高频遗漏场景：** 新增 migration 列 + 更新 Controller + 更新 View，但忘了加 Model `$fillable`。涉及 CRM 模块时尤其容易发生。

### 16. CRM 单据 PDF/Excel 导出方案与 HTML 打印预览的取舍

**已尝试方案：**
1. `spatie/laravel-pdf`（dompdf 后端）：生成的 PDF 排版严重错位，A4 打印样式无法直接转为 PDF 渲染
2. `PhpSpreadsheet` Excel 导出：可成功导出 .xlsx，但样式与 HTML 差距大，用户期望表格化的布局
3. 纯 HTML 打印预览 + 浏览器手动打印：长期稳定兜底方案
4. Chromium/Puppeteer PDF：当前主路线，复用 HTML 打印模板生成 PDF

**当前状态：** `downloadPdf` 已改为 `CrmDocumentPdfService` + `scripts/render-crm-document-pdf.mjs`，通过容器内 Chromium/Puppeteer 生成 A4 PDF。单据详情页和打印预览页已提供 PDF 下载入口；HTML 打印预览继续作为失败兜底。打印模板已支持动态明细分页和动态 `Page X of Y`，长报价单与长 PI 已做真实 PDF 视觉烟测。后台 `/admin/crm/quotes/pdf-regression` 已提供 PDF 回归检查入口，可一键生成五类真实样本回归包、设置默认视觉基线、查看截图 diff，并通过 `crm:document-pdf-regression:prune` 清理旧回归文件。`downloadExcel` 仍不作为主流程。

**关键约束：**
- `print-document.blade.php` 是打印预览的核心共享组件，CSS 重写风险极高
- 打印样式需兼容 A4 尺寸（210mm × 297mm），修改 CSS 变量可能导致页码断裂
- 切换单据类型（quotation / proforma_invoice / invoice / packing_list / contract）依赖该组件的 CSS 一致性
- PDF 生成依赖容器中的 `node`、`chromium` 和项目 `puppeteer`；如果环境缺失，应显示失败提示并继续使用 HTML 打印预览
- 中文 PDF 依赖容器内 CJK 字体。当前 Dockerfile 已安装 `fonts-wqy-zenhei`，打印 CSS 字体栈包含 `"WenQuanYi Zen Hei"`。如果新环境中文显示方块，先运行 `fc-list :lang=zh family` 检查字体。
- 动态分页是展示层估算，不修改单据明细数据；如某些极端长描述仍不理想，应优先调整分页容量和视觉测试样本，而不是重写整套打印 CSS
- 修改打印模板后优先运行 `php artisan crm:document-pdf-regression` 或后台“PDF 回归检查”，检查五类真实样本 PDF、截图、报告和视觉 diff；不要只看一个单据类型就判断打印系统安全。
- 当前本地 Docker 中 `geoflow-app` 有 Node/Chromium，但 `geoflow-queue` 没有，因此 PDF 回归 Job 固定使用 `sync` 连接执行。若后续要改回异步队列，必须先给 queue 容器补齐 Node/Chromium，并重新验证后台按钮。

**恢复方法：**
```bash
cd /Users/leo/Desktop/GEOFlow
git checkout print-stable-20260609 -- resources/views/admin/crm/quotes/partials/print-document.blade.php
docker cp resources/views/admin/crm/quotes/partials/print-document.blade.php geoflow-app:/var/www/html/resources/views/admin/crm/quotes/partials/print-document.blade.php
docker exec -e APP_KEY="$(grep '^APP_KEY=' .env | cut -d= -f2-)" geoflow-app sh -c 'cd /var/www/html && php artisan optimize:clear'
```

### 17. print-document.blade.php CSS 重构导致单据类型切换样式断裂

**症状：** 修改 `print-document.blade.php` 的 CSS 后，切换单据类型（如从报价单改为发票）时页面的 A4 格式、面板布局、表格样式全部消失。

**根因：** `print-document.blade.php` 被所有 5 种打印模板（print-quotation、print-proforma-invoice、print-invoice、print-packing-list、print-contract）共享引用。CSS 变量重命名（如 `var(--text)` → 硬编码颜色）和类名重构（`.summary-wrap` → 新结构）会导致不同模板因条件渲染路径不同而产生样式断裂。

**教训：** 修改此文件前必须先 git commit 当前稳定版本。修改后必须逐一测试所有 5 种单据类型的打印预览。如出问题，通过 `print-stable-20260609` tag 回滚。

### 18. Controller 回滚后需同步清除 Laravel 层叠缓存

**症状：** 回滚 Controller 或 Blade 文件后，页面行为仍是旧代码。

**完整清除步骤（必须按顺序，缺一不可）：**
```bash
docker cp <file> geoflow-app:/var/www/html/<path>
docker exec -e APP_KEY="$(grep '^APP_KEY=' .env | cut -d= -f2-)" geoflow-app sh -c 'cd /var/www/html && php artisan optimize:clear'
```

仅 `view:clear` 不够，必须 `optimize:clear`（同时清除 config/routes/views/cache/compiled）。另外注意 OPcache 问题（见 Known Issue #13）。

### 19. 不要尝试在 print-document.blade.php 中引入新的 CSS 框架或大规模样式重写

**原因：**
- 该文件服务于打印场景，A4 纸张 210mm 宽度固定
- 打印媒体查询与屏幕媒体查询行为不同
- Tailwind print 变体在此场景下不稳定
- 5 种单据类型的条件渲染路径交叉复杂

**安全做法：** 只做局部微调（颜色、间距、字号），不做结构性 CSS 重写。任何 CSS 改动后必须逐一验证 5 种打印模板。

### 20. CRM `_markdown-editor` 组件不要依赖 Alpine

**原因：** 当前后台页面不能默认假设 Alpine 已加载。之前用 `x-data`、`@click`、`x-show` 写出的编辑器会正常渲染 HTML，但按钮点击、预览切换、源码切换不会真正执行。项目也没有定义 `[x-cloak] { display: none !important; }` CSS 规则。

**症状：** 活动记录编辑器看起来有 `编辑 / 预览 / 源码` 和快捷按钮，但点击加粗、预览等按钮没有反应；或者预览区和源码区在初始化前短暂裸显。

**解决方案：** CRM 轻量编辑器使用 `data-crm-markdown-editor` + 原生 JS 初始化，写/预览/源码三个 panel 使用 `hidden` 控制。除非先确认页面已全局加载 Alpine，否则不要再给该组件添加 `x-data`、`@click`、`x-show` 或 `x-cloak`。

### 21. 活动记录编辑和删除路由必须在 CRM 组公用层级

**原因：** 如果放在 `inquiries` 子路由组中，路由名会带上 `admin.crm.inquiries` 前缀，客户详情页无法稳定复用。

**正确位置：** 在 CRM 组公用层级定义 `admin.crm.follow-ups.update` 和 `admin.crm.follow-ups.delete`。客户、询盘页面可编辑和删除；单据、订单、售后只读。

### 22. 活动记录组件必须用 @include 引入而非内联 HTML

**原因：** 各详情页如果直接内联渲染活动记录，编辑、删除和 Markdown 展示规则容易在不同页面分叉。

**正确做法：** 所有详情页使用 `@include('admin.crm.partials._follow-up-item', [...])`；仅客户和询盘传入 `editable => true`。

### 23. 多页面 sed 批量替换 Blade 模板易导致变量丢失

**原因：** Python 正则匹配 Blade 变量（`$xxx->yyy`）时容易出现转义问题，导致 `@forelse ($inquiry->customer?->followUps ?? [] as $followUp)` 等语句被截断。

**解决方案：** 使用精确字符串替换（完整 block match），而非正则匹配。出问题后从 `git checkout HEAD -- <file>` 恢复并重新精确替换。

### 24. HTTP 后台入口与 CLI 路由前缀可能不一致

**症状：** 浏览器访问 `http://localhost:18080/geo_admin/site-settings` 返回 404，但 `http://localhost:18080/admin/site-settings` 可以正常打开；同时 CLI `route:list` 可能仍显示 `geo_admin` 前缀。

**当前观察：**

- 本地浏览器可访问后台入口为 `/admin`。
- 模板工厂入口在 `/admin/site-settings` 页面可见。
- 模板工厂创建页链接为 `/admin/site-settings/theme-replications/create`。

**排查顺序：**

1. 检查 `.env` / Docker 环境里的后台路径配置，例如 `ADMIN_BASE_PATH` 或项目自定义 admin path。
2. 检查 Laravel config / route cache：`php artisan optimize:clear` 后再验证。
3. 检查 Web 容器实际挂载的代码是否与当前工作区一致。
4. 检查是否存在代理、Nginx rewrite 或历史缓存把后台入口固定到 `/admin`。

**处理原则：** 在确认真实部署入口前，不要直接改业务路由。当前本地操作和浏览器验证优先使用 `/admin`。

### 25. 联系人可选字段不能直接以 null 写入非空字符串列

**症状：** 客户详情页新增联系人时，只填写姓名或将电话、邮箱、职位等可选字段留空，会返回 `500`，日志包含 `null value in column ... violates not-null constraint`。

**原因：** Laravel 的 `ConvertEmptyStringsToNull` 中间件会把空输入转为 `null`，但 `crm_customer_contacts` 的部分字符串列使用 `NOT NULL` 和空字符串默认值。

**正确做法：** 在 `CrmContactController` 的验证边界将可选字符串统一规范化为 `''`，并在同步 `crm_customers` 旧兼容字段时继续做字符串转换。不要只修改前端 `required` 属性掩盖数据库契约问题。

### 26. 商机表单空字符串会被转换为 null

**症状：** 手动新建商机时，如果“竞争对手”留空，保存返回 `500`，日志提示 `crm_opportunities.competitor` 违反非空约束。

**原因：** 表单始终提交该字段，Laravel 会把空字符串转换为 `null`；数据库的 `competitor` 列虽然默认值为 `''`，但显式写入 `null` 时不会使用默认值。

**正确做法：** `CrmOpportunityController` 在验证后将 `competitor` 规范化为字符串，并为可清空的金额兜底为 `0`。相关测试必须模拟浏览器提交空字符串，不能只省略字段，否则无法覆盖该错误。

### 27. CRM 销售链路历史断链风险已收口，仍需保护一致性规则

**历史症状：** 全局新增商机可能没有来源询盘；询盘待办和已有单据不会自动补关联新商机；商机页缺少统一活动时间线；同一询盘可能通过不同入口产生重复商机。

**当前状态：** CRM 销售链路 V2 已完成核心收口：商机来源模式、重复保护、询盘转商机事务编排、统一活动时间线、单据链一致性和唯一候选历史修复都已落地。剩余 8 项真实历史歧义数据保留人工判断，不自动猜测。

**正确处理：** 后续修改 CRM 客户、询盘、商机、活动、待办或单据链路时，优先读取 `功能说明文档/10-轻量CRM与报价使用说明.md`、`agent-docs/IMPLEMENTATION_STATUS.md` 和本文件。不要恢复旧的局部补丁式方案，也不要新增会绕过客户、询盘、商机、单据和 Collection 一致性校验的入口。
