# GEOFlow Agent Handoff

## 项目定位

这是 GEOFlow 的本地深度定制版本，目标是把原有内容生成系统升级为：

Collection + Entity + Case + Knowledge Base + Tag + RAG + Quality Review + 轻量 CRM 的 GEO 内容生产系统。

当前项目主要在本地 Docker 中运行，定制开发优先保护已有功能，所有新功能应保持向后兼容。

## 当前核心架构

1. Collection 是顶层业务容器。
2. Entity 是知识索引节点。
3. Knowledge Base 保存丰富来源资料。
4. Case DB 保存真实客户或应用案例。
5. Tag 描述可复用属性，不承担 Collection 职责。
6. Article generation 不依赖 Entity 字段本身，而依赖 Entity 关联的知识库、案例、关键词和图片。
7. 知识库 role 和 status 会影响 RAG 检索。
8. 质量评分用于生成后的审核优先级判断。
9. 任务生成提示词采用 Master Prompt + 可选 Skill Prompt 分层，Skill 只负责文章结构策略增强。
10. AI 素材分析入口应复用公共规则，不允许用户补充提示词覆盖系统字段、语言和事实约束。
11. CRM 是轻量销售辅助模块，不要扩展成完整 ERP；客户、询盘、报价、订单、售后工单和内容候选应复用 Collection / Entity / Knowledge / Case 体系。
12. 模板工厂 / 站点模板复刻是独立的主题草稿与发布流程，不应直接覆盖现有主题文件；发布前必须先预览和保留回滚路径。

详细规则见 [ARCHITECTURE_RULES.md](./ARCHITECTURE_RULES.md)。

## 已完成主线

- 阶段 1：生成追踪基础。
- 阶段 2：正式 RAG 检索。
- 阶段 3：自动标签推荐历史阶段，后续已按用户要求移除不同素材自动推荐 tag，转为手动选择既有标签和白名单分组治理。
- 阶段 4：Entity / Case 结构化。
- 阶段 5：GEO 生成流水线。
- 阶段 6：质量评分与审核辅助。
- 阶段 7：性能与后台体验优化，核心功能已做，仍需大数据量验证。

进度细节见 [IMPLEMENTATION_STATUS.md](./IMPLEMENTATION_STATUS.md)。

## CRM 单据系统（最近完成）

- 打印模板：5 种 document_type 统一 CSS（A4 标准），10 个 Blade partial 组件化
- PI 银行信息独立第 2 页，CI+PL 无图片，Quotation+PI 有图片
- 卖方公司信息来自站点设置或单据级 JSON；打印页 Contact 与签名 Name 使用单据负责人
- 字段齐全：packing_terms / deposit_percent / 4 个物流字段 / 3 个 package 尺寸
- SKU 全链路删除，Bank Account 标准化
- Form 已拆分为基础、买方、卖方、商业、明细、汇总、条款、合同条款和备注等可折叠分区
- 客户资料可联动填入 buyer_contact / buyer_company / phone / email / country / address
- 单据列表、详情和打印页可直接切换打印类型，不必修改原单据 document_type
- 跟进记录已贯通客户、询盘、单据、订单和售后详情页
- 测试：AdminCrmPagesTest 7/7

## 最近完成的重要治理增强

- 新增 Collection 顶层容器规则。
- 移除“行业领域标签承担 Collection 职责”的设计。
- 新增 Entity 与知识库、关键词、图片、Case 的关联能力。
- 新增知识库 type、role、status、importance、summary、source URL 等治理字段。
- 新增受控标签分组白名单，支持在标签管理页维护。
- 创建任务页改为 Collection 必选，Entity / Case 多选。
- 创建任务页受控标签筛选改为 accordion，作为可选高级筛选。
- 关键词库和图片库移除库级标签，只保留标题库库级标签。
- 文章提示词配置页支持 `content` Master Prompt 与 `skill` Skill Prompt，任务创建页可选 Skill Prompt。
- Knowledge / Entity / Case 的 AI 自动分析已统一使用公共提示词规则，并支持“补充分析要求”折叠输入区。
- URL 智能采集创建任务时可选择 AI 分析模型；默认自动选择，指定模型优先执行并保留 failover。
- URL 智能采集生成 Entity 时已修复非中文页面混入中文描述模板的问题。
- AI 模型页新增聊天模型级“正文最大输出 Token”；Worker 正文生成会按 provider 写入对应参数，URL 采集和素材分析不受影响。
- 新增轻量 CRM 阶段 1-7：客户资料、内部负责人、跟进记录、询盘 AI 需求识别、单据制作、订单、售后工单、CRM 内容候选和任务 CRM 来源关联。
- 2026-06-06 CRM 单据与客户优化：导航「报价」→「单据制作」；单据类型联动隐藏/显示字段（物流字段组 + HS Code 组分治）；CRM 全模块（客户/询盘/单据/订单/售后）删除功能；客户邮箱字段（migration + Model `$fillable` + Controller + View + 买方联动）；打印类型切换（无需进入编辑页）；卖方信息手动编辑入口确认。详见 [IMPLEMENTATION_STATUS.md](./IMPLEMENTATION_STATUS.md#2026-06-06-crm-单据与客户优化)。
- 2026-06-12 吸收上游文章 Markdown 编辑器：文章创建/编辑页支持 Vditor、快捷插入、复制 Markdown、复制公众号/微信格式 HTML 和编辑器图片上传入库。
- 2026-06-12 吸收上游模板工厂 / 站点模板复刻：支持 3 个参考页面创建复刻任务，包含分析、草稿生成、预览、迭代、发布、打包和归档流程。
- 2026-06-12 吸收上游分发最近日志分页和首次部署登录提示。
- 不同素材自动推荐 tag 已移除；后续不要在未确认需求前恢复该入口。
- 新增 `功能说明文档/`，用于用户操作说明。

## 新 agent 接手时优先检查

0. 检查 `.env` 的 `APP_KEY` 是否正常（单行、单 base64 值、32 字节），避免执行 `php artisan key:generate --force` 前未确认当前格式；Docker 容器 `APP_KEY=""` 会覆盖 .env，跑测试需带 `-e APP_KEY=...`。
1. 检查 OPcache 配置：`grep validate_timestamps /usr/local/etc/php/conf.d/99-opcache.ini`。如果值为 `0`，改为 `1` 后重启容器，否则所有代码修改不会生效（详见 [KNOWN_ISSUES.md #13](./KNOWN_ISSUES.md#13-opcache-validate_timestamps0-导致代码改动不可见)）。
2. 确认当前用户要改的是功能逻辑、UI 入口、文档，还是数据治理规则。
3. 检查是否会破坏 Collection / Entity / Tag / Knowledge Base 的职责边界。
4. 检查是否需要同步数据库迁移、测试、前端入口和使用说明。
5. 检查是否需要更新本目录中的交接文档。
6. 当前本地浏览器可访问后台入口为 `/admin`；如果用户提到 `/geo_admin` 404，先查配置缓存、环境变量、容器代码挂载和 Web 服务器路由，不要直接改业务路由。

## 已知高优先级待办

- 任务回收站尚未实现。
- AI 知识库纠错助手尚未实现。
- 大数据量下的标签远程搜索、懒加载、统计缓存仍需要继续压测和补强。
- 旧文章可能没有完整生成 trace，因此文章编辑页不一定显示生成来源。
- Skill Prompt 自动匹配标题意图尚未实现，目前仅支持任务页手动选择。
- URL 采集和手动 AI 分析仍依赖模型返回质量；表格保真规则已增强，但复杂表格仍建议人工复核。
- 不同素材自动 tag 推荐已移除，若后续重新加入，应先确认不会增加误标签和无效标签。
- CRM 已实现订单、售后工单和跟进记录多端时间线；单据审批、自动 PDF 导出和邮件发送尚未实现。
- 上游 System Update Center 准备度补丁未接入；本地分支缺少更新中心基底，后续如需要应单独规划。
- 本地 HTTP 后台入口与 CLI 路由前缀存在不一致风险，详见 [KNOWN_ISSUES.md](./KNOWN_ISSUES.md#23-http-后台入口与-cli-路由前缀可能不一致)。

更多风险见 [KNOWN_ISSUES.md](./KNOWN_ISSUES.md)。

## 功能说明入口

如果需要了解用户如何操作系统，请读取 [FEATURE_DOC_INDEX.md](./FEATURE_DOC_INDEX.md)，它会跳转到 `功能说明文档`。

---

## 2026-06-09: 编辑单据页面 Section 卡片重构（已完成）

**背景：** 编辑单据页面 (`quotes/form.blade.php`) 的所有 section 卡片需要统一升级样式和增加折叠功能。

**完成状态：**

1. 基础信息、买方信息、卖方信息、商业信息、明细区域、汇总区域、条款和条件、合同自定义条款、备注信息均已拆成独立分区。
2. 分区标题、图标、折叠按钮和内容容器已统一；“从客户资料带入”和“添加明细”保留在对应标题栏。
3. 修复了历史遗留的多余闭合标签，当前 `form.blade.php` PHP 语法检查通过。

**关键约束：** 后续只做局部表单 UI 调整，不要把编辑页样式修改扩散到共享打印 CSS。

---

## 2026-06-09（夜间）：打印版本回滚与Git锚点

**关键事件：** 编辑页面 Section 卡片重构过程中，`print-document.blade.php` 的 CSS 被大规模重写，导致所有 5 种单据打印预览样式全部断裂——切换单据类型后 A4 格式、面板布局、表格样式消失。

**解决：** 将 `print-document.blade.php` 回滚到 git HEAD（commit `8a7666b`），恢复打印预览。编辑页 UI 优化（`form.blade.php`、`item-row.blade.php`）不受影响。

**Git 锚点（重要）：**
- Commit: `d609891` — 当前稳定版本
- Tag: `print-stable-20260609`

**后续提醒：**
- `print-document.blade.php` 是 5 种单据打印模板的共享 CSS 核心，修改风险极高
- 如需恢复打印样式，`git checkout print-stable-20260609 -- resources/views/admin/crm/quotes/partials/print-document.blade.php`
- 打印样式改动后必须逐一验证 quotation / proforma_invoice / invoice / packing_list / contract 五种类型
- 缓存清除：`docker exec -e APP_KEY="..." geoflow-app sh -c 'cd /var/www/html && php artisan optimize:clear'`

---

## 2026-06-09（第9轮）：跟进记录多端展示 + Markdown 编辑器

**核心变更：**

跟进记录不再只属于客户详情页，改为多端展示：
- 询盘详情页：可写（表单 + 列表），`inquiry_id` 自动填入
- 客户详情页：可写（表单含询盘下拉 + 列表含来源标签）
- 报价/订单/售后详情页：只读 timeline，通过关联链路 `inquiry→customer→followUps` 加载

**新增组件：**
- `_markdown-editor.blade.php` — 三态切换编辑器，用于跟进表单
- `_follow-up-item.blade.php` — 跟进记录统一渲染（含删除按钮）
- `_follow-up-section.blade.php` — 未使用，可清理

**新增路由：**
- `POST admin/crm/follow-ups/{followUpId}/delete` → `admin.crm.follow-ups.delete`（CRM 组公用层级）

**关键注意：**
- `_markdown-editor` 使用内联 `style="display:none"` 而非 `x-cloak`（项目无 x-cloak CSS）
- 跟进记录数据通过 `customer.followUps` 加载，跨询盘共享
- 各 Controller show 方法需预加载 `followUps.inquiry`

**布局：** 跟进记录放在各页面左侧主内容区（非 aside），独占宽列。
## 2026-06-11：CRM 新工作流交接

- CRM 默认入口改为 `admin.crm.dashboard`，顶部子导航为工作台、客户、询盘、商机、待办、单据、订单、售后、内容候选。
- `crm_follow_ups` 现在只表达已发生的活动；未来动作使用 `crm_tasks`，不要再依赖 `next_action` / `next_followup_at` 构建提醒。
- 询盘详情必须加载 `followUps`，不能恢复为 `customer.followUps`，否则会重新出现跨询盘串记录问题。
- 客户归档使用 SoftDeletes，商业记录仍保留原 `customer_id`；当前没有归档回收站 UI。
- 客户联系人表为 `crm_customer_contacts`，主联系人会同步旧字段 `contact_person/phone/email/contact_title`，用于兼容现有单据带入逻辑。
- 商机表为 `crm_opportunities`，阶段：qualified / discovery / solution / proposal / negotiation / won / lost。
- 单据转换仍复用 `crm_quotes`，通过 `source_quote_id` 建立来源，不要把打印预览的 `?type=` 当成独立单据。
