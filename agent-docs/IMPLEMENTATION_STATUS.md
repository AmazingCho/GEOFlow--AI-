# GEOFlow 实现状态

本文档记录当前定制功能的完成状态。每次开发后都应更新。

## 已完成

| 功能 | 状态 | 说明 |
| --- | --- | --- |
| Collection 顶层容器 | 已完成 | 创建任务页必选，素材按 Collection 归属治理 |
| Entity DB | 已完成核心功能 | 支持基础字段、属性 JSON、标签、AI 分析、素材关联 |
| Case DB | 已完成核心功能 | 支持案例管理、受控 Case 类型并关联核心 Entity |
| 知识库治理字段 | 已完成 | type、role、importance、summary、source URL、status |
| 知识库 Entity 独立关系 | 已完成 | 知识库可关联多个 Entity，并为每个 Entity 单独设置关系 |
| 关系多选组件复用 | 已完成 | Entity 页关联知识库与知识库页关联 Entity 复用 `relation-multi-selector` |
| RAG 检索 | 已完成核心功能 | 支持 Entity、Case、Knowledge Base、Collection 上下文 |
| RAG 检索解释增强 | 已完成 | trace 记录 evidence_score、retrieval_source、match_reasons、score_components 与 evidence_summary |
| 生成追踪 | 已完成核心功能 | 文章可记录生成来源、检索 chunk、上下文 |
| 文章质量评分 | 已完成核心功能 | 列表和编辑页显示评分与审核建议 |
| 标签管理页增强 | 已完成核心功能 | 标签列表、引用统计、引用明细、删除、重命名、分页 |
| 受控标签分组白名单 | 已完成核心功能 | 支持新增、编辑、删除可用分组 |
| 创建任务页重整 | 已完成核心功能 | Collection 必选，Entity / Case 多选，标签筛选折叠 |
| 创建任务页 Collection 联动 | 已完成 | 选择 Collection 后，Entity / Case 仅允许选择同 Collection 内容；跨 Collection 需显式开启 |
| 图片配置优化 | 已完成核心功能 | 区分不指定图库和不配图，支持 Entity 关联图库思路 |
| URL 采集标题关键词关联 | 已完成 | 标题库关联关键词库，单条标题可编辑关联关键词 |
| URL 采集 Case 类型归一 | 已完成 | URL 采集生成 Case 时归一到受控 Case 类型 |
| URL 采集 AI 模型选择 | 已完成 | 创建采集任务时可指定 AI 分析模型；默认仍自动选择并保留 failover |
| AI 素材分析公共规则 | 已完成 | Knowledge / Entity / Case 分析统一复用语言一致、事实约束、表格保真规则 |
| AI 分析补充要求 | 已完成 | Knowledge、Entity、Case 表单支持折叠式“补充分析要求”与快捷模板 |
| URL 采集 Entity 语言修复 | 已完成 | 英文等非中文页面不再套中文 Entity 描述模板 |
| 文章生成语言强制 | 已完成 | 生成语言优先由标题和关键词判断，最终提示词强制目标语言 |
| Prompt Skill System v1 | 已完成 | 任务支持可选 `skill_prompt_id`，提示词页可管理 Master Prompt 与 Skill Prompt |
| 关键词和图片库级标签移除 | 已完成 | 只保留标题库库级标签 |
| 素材库删除后保留筛选位置 | 已完成 | 关键词库、标题库、图片库、知识库删除后保留 query 并回到列表区域 |
| 功能说明文档 | 已完成 | 已创建 `功能说明文档/` |
| Agent 交接文档 | 已完成 | 已创建 `agent-docs/` |

## 部分完成，建议继续检查

| 功能 | 状态 | 待检查点 |
| --- | --- | --- |
| 阶段 7 性能优化 | 部分完成 | 需要用大量标签、图片、知识库、文章做真实压力验证 |
| 标签远程搜索 | 部分完成或需确认 | 检查所有标签选择器是否都避免一次性渲染大量数据 |
| 标签引用明细懒加载 | 部分完成或需确认 | 检查标签管理页查看明细是否按需请求 |
| 统计缓存 | 部分完成或需确认 | 检查素材统计和标签统计是否频繁重复计算 |
| 知识库向量化异步队列 | 部分完成或需确认 | 大文件导入时是否阻塞页面操作 |
| 旧文章生成来源展示 | 兼容性风险 | 旧文章没有 trace 时可能不显示生成来源 |

## 未完成

| 功能 | 状态 | 原因 |
| --- | --- | --- |
| 任务回收站 | 未实现 | 会改变任务删除语义，建议独立阶段做 |
| AI 知识库纠错助手 | 未实现 | 涉及新表、diff UI、审批、embedding、回滚，建议独立阶段做 |
| Collection 健康度评分 | 未实现 | 可作为后续治理增强 |
| 重复素材合并 | 未实现 | 需要更明确的数据合并策略 |

## 最近验证记录

最近已通过的核心测试包括：

- `AdminMaterialsPagesTest`
- `AdminTasksPageTest`
- `AdminAiPromptsPageTest`
- `WorkerExecutionServicePromptTest`
- `RagRetrievalServiceTest`
- `UrlImportProcessingServiceTest`
- `WorkerGenerationPipelineTraceTest`

最近补充验证：

- `EntityExtractionServiceTest`
- `MaterialAnalysisPromptRulesTest`
- URL 采集指定 AI 模型聚焦测试
- 知识库 AI 分析聚焦测试

## 2026-06-05 Prompt Skill System v1

已完成：

- 新增 `tasks.skill_prompt_id` 迁移并在本地 Docker 数据库执行。
- 原“正文提示词配置”页升级为“文章提示词配置”，同时管理 `content` Master Prompt 和 `skill` Skill Prompt。
- 创建任务页内容配置区新增可选 Skill Prompt 下拉框。
- Worker 生成文章时会组合 Master Prompt 与 Skill Prompt，并继续保留最终语言指令。
- 生成 trace 记录 Skill Prompt 使用情况。
- API catalog 新增 `skill_prompts`。

继续优化建议：

- 后续如要做自动匹配 Skill，应新增明确的 intent 字段或分类器，并保留任务页人工覆盖入口。
- 不建议把 Skill Prompt 当作素材分类或知识库 metadata 使用。

## 2026-06-04 上游更新吸收记录

已采纳：

- OpenAI-compatible embedding 直连 `/embeddings`，避免 Doubao 等接口因 `dimensions` 参数报错。
- AI 模型页补充 Doubao Embedding、MiniMax M3、MiniMax M2.7 预设。
- 文章批量操作、清空回收站、发布弹窗改为相对 URL。
- 现有 RAG 流程局部吸收上游“证据评分 / 召回解释”思路，不整体替换自定义 RAG。

已跳过：

- Generic HTTP 发布器整套合并。
- 上游 RAG 服务整体替换。
- System Update Center。
- Apple Support Clone / 前台主题更新。
- 上游 docs 大量文档更新。

后续 agent 修改数据库、RAG、生成流程或任务页时，应继续运行相关测试。

## 2026-06-05 AI 分析与 URL 采集增强

已完成：

- 新增 `MaterialAnalysisPromptRules`，集中管理语言一致性、事实不可编造、表格/规格参数保真和 JSON 输出规则。
- `MaterialFormAnalysisService` 的 Knowledge / Entity / Case 分析统一使用公共规则。
- Knowledge、Entity、Case 表单新增“补充分析要求”折叠区，支持快捷模板；该内容只作为附加要求，不覆盖系统规则。
- URL 智能采集的知识库提示词复用公共事实与表格规则。
- URL 智能采集生成 Entity 时，非中文页面不再使用中文描述模板。
- URL 智能采集创建任务页新增 AI 分析模型选择；不选时仍按优先级自动选择，指定时优先尝试所选模型并保留 failover。

相关文件：

- `app/Support/GeoFlow/MaterialAnalysisPromptRules.php`
- `app/Services/GeoFlow/MaterialFormAnalysisService.php`
- `app/Services/GeoFlow/UrlImportProcessingService.php`
- `app/Services/GeoFlow/EntityExtractionService.php`
- `resources/views/admin/partials/material-ai-analysis-instructions.blade.php`
- `resources/views/admin/url-import/index.blade.php`

后续注意：

- 自定义提示词不要设计成“完全替换系统提示词”，应继续保持“补充分析要求”模式。
- 如果新增新的素材 AI 分析入口，应复用 `MaterialAnalysisPromptRules`。
