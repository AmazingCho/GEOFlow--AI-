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
- 签名统一：Seller Name 用公司名，标题「Seller/Buyer」
- 字段齐全：packing_terms / deposit_percent / 4 个物流字段 / 3 个 package 尺寸
- SKU 全链路删除，Bank Account 标准化
- Form 排版优化（section 标题栏 bg-gray-50）
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
- 新增轻量 CRM 阶段 1-7：客户、联系人、跟进记录、询盘 AI 需求识别、报价单、订单、售后工单、CRM 内容候选和任务 CRM 来源关联。
- 2026-06-06 CRM 单据与客户优化：导航「报价」→「单据制作」；单据类型联动隐藏/显示字段（物流字段组 + HS Code 组分治）；CRM 全模块（客户/询盘/单据/订单/售后）删除功能；客户邮箱字段（migration + Model `$fillable` + Controller + View + 买方联动）；打印类型切换（无需进入编辑页）；卖方信息手动编辑入口确认。详见 [IMPLEMENTATION_STATUS.md](./IMPLEMENTATION_STATUS.md#2026-06-06-crm-单据与客户优化)。
- 不同素材自动推荐 tag 已移除；后续不要在未确认需求前恢复该入口。
- 新增 `功能说明文档/`，用于用户操作说明。

## 新 agent 接手时优先检查

0. 检查 `.env` 的 `APP_KEY` 是否正常（单行、单 base64 值、32 字节），避免执行 `php artisan key:generate --force` 前未确认当前格式；Docker 容器 `APP_KEY=""` 会覆盖 .env，跑测试需带 `-e APP_KEY=...`。
1. 检查 OPcache 配置：`grep validate_timestamps /usr/local/etc/php/conf.d/99-opcache.ini`。如果值为 `0`，改为 `1` 后重启容器，否则所有代码修改不会生效（详见 [KNOWN_ISSUES.md #13](./KNOWN_ISSUES.md#13-opcache-validate_timestamps0-导致代码改动不可见)）。
2. 当前用户要改的是功能逻辑、UI 入口、文档，还是数据治理规则。
5. 是否会破坏 Collection / Entity / Tag / Knowledge Base 的职责边界。
5. 是否需要同步数据库迁移、测试、前端入口和使用说明。
5. 是否需要更新本目录中的交接文档。

## 已知高优先级待办

- 任务回收站尚未实现。
- AI 知识库纠错助手尚未实现。
- 大数据量下的标签远程搜索、懒加载、统计缓存仍需要继续压测和补强。
- 旧文章可能没有完整生成 trace，因此文章编辑页不一定显示生成来源。
- Skill Prompt 自动匹配标题意图尚未实现，目前仅支持任务页手动选择。
- URL 采集和手动 AI 分析仍依赖模型返回质量；表格保真规则已增强，但复杂表格仍建议人工复核。
- 不同素材自动 tag 推荐已移除，若后续重新加入，应先确认不会增加误标签和无效标签。
- CRM 已实现订单和售后工单；报价审批、PDF 导出、邮件发送和客户活动时间线整合尚未实现。

更多风险见 [KNOWN_ISSUES.md](./KNOWN_ISSUES.md)。

## 功能说明入口

如果需要了解用户如何操作系统，请读取 [FEATURE_DOC_INDEX.md](./FEATURE_DOC_INDEX.md)，它会跳转到 `功能说明文档`。
