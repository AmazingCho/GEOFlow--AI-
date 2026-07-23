# Agent Docs 入口

这个目录用于解决 GEOFlow 项目在长对话、上下文压缩、换 agent 接手时的信息丢失问题。

新 agent 不应该依赖聊天历史，而应该先读取这里的极简交接文档，再按需跳转到 `功能说明文档`。

读取文档前请先遵守 [DOC_READ_POLICY.md](./DOC_READ_POLICY.md)：默认不要全量读取，只按当前任务读取最少必要文档。

## 最短接手路径

如果只想用最少 token 了解项目，请先读取：

1. [AGENT_BRIEF.md](./AGENT_BRIEF.md)
2. [DOC_READ_POLICY.md](./DOC_READ_POLICY.md)
3. [USER_REQUIREMENT_INTERPRETATION.md](./USER_REQUIREMENT_INTERPRETATION.md)

只有需要阶段细节、风险细节或架构判断时，再读取：

- [AGENT_HANDOFF.md](./AGENT_HANDOFF.md)
- [IMPLEMENTATION_STATUS.md](./IMPLEMENTATION_STATUS.md)
- [KNOWN_ISSUES.md](./KNOWN_ISSUES.md)
- [ARCHITECTURE_RULES.md](./ARCHITECTURE_RULES.md)

如果任务涉及检查、采纳或继续执行原仓库更新，只额外读取：

- [UPSTREAM_SELECTIVE_ADOPTION_PLAN_2026-07.md](./UPSTREAM_SELECTIVE_ADOPTION_PLAN_2026-07.md)

## 详细功能说明

如果需要理解具体功能怎么使用，再读取：

- [新增功能总览](../功能说明文档/00-新增功能总览.md)
- [Collection 业务容器使用说明](../功能说明文档/01-Collection业务容器使用说明.md)
- [Entity 与 Case 使用说明](../功能说明文档/02-Entity与Case使用说明.md)
- [标签与白名单分组使用说明](../功能说明文档/03-标签与白名单分组使用说明.md)
- [知识库治理与 RAG 检索使用说明](../功能说明文档/04-知识库治理与RAG检索使用说明.md)
- [创建任务与生成流程使用说明](../功能说明文档/05-创建任务与生成流程使用说明.md)
- [Prompt / Skill / Style System V2.2](./PROMPT_SKILL_SYSTEM.md)
- [Article Prompt V2.4 部署状态](./ARTICLE_PROMPT_V24_DEPLOYMENT_STATUS.md)
- [V2.2 去模板化与深度生成实施报告](./ARTICLE_DETEMPLATED_DEEP_GENERATION_IMPLEMENTATION_REPORT.md)
- [文章质量评分与审核使用说明](../功能说明文档/06-文章质量评分与审核使用说明.md)
- [素材管理与关联使用说明](../功能说明文档/07-素材管理与关联使用说明.md)
- [URL 智能采集使用说明](../功能说明文档/08-URL智能采集使用说明.md)
- [素材系统推荐使用方法](../功能说明文档/09-素材系统推荐使用方法.md)
- [轻量 CRM 与报价使用说明](../功能说明文档/10-轻量CRM与报价使用说明.md)
- [文章编辑器与模板复刻使用说明](../功能说明文档/11-文章编辑器与模板复刻使用说明.md)
- [Codex 业务录入助手 API 使用说明](../功能说明文档/12-Codex业务录入助手API使用说明.md)

## 本目录文件职责

| 文件 | 用途 |
| --- | --- |
| [AGENT_BRIEF.md](./AGENT_BRIEF.md) | 新 agent 或新会话的最低 token 启动文件 |
| [AGENT_HANDOFF.md](./AGENT_HANDOFF.md) | 详细交接摘要，适合需要阶段细节时读取 |
| [ARCHITECTURE_RULES.md](./ARCHITECTURE_RULES.md) | Collection、Entity、Tag、Knowledge Base、Case 的规则边界 |
| [IMPLEMENTATION_STATUS.md](./IMPLEMENTATION_STATUS.md) | 已完成、部分完成、未完成事项 |
| [KNOWN_ISSUES.md](./KNOWN_ISSUES.md) | 已知缺陷、风险和容易误判的问题 |
| [DOC_READ_POLICY.md](./DOC_READ_POLICY.md) | 文档读取策略，避免每次任务全量读取造成 token 浪费 |
| [USER_REQUIREMENT_INTERPRETATION.md](./USER_REQUIREMENT_INTERPRETATION.md) | 非技术表达优先、产品经理式需求转译和基础优化流程 |
| [CODEX_GLOBAL_PERSONAL_PROMPT.md](./CODEX_GLOBAL_PERSONAL_PROMPT.md) | 可复制到 Codex 个性化提示词的全局协作规则 |
| [AGENT_WORKFLOW_RULES.md](./AGENT_WORKFLOW_RULES.md) | 每个 agent 开发前后必须遵守的交接规则 |
| [FEATURE_DOC_INDEX.md](./FEATURE_DOC_INDEX.md) | 功能说明文档索引和阅读建议 |
| [PROMPT_SKILL_SYSTEM.md](./PROMPT_SKILL_SYSTEM.md) | Master + Skill + 可选 Style 与标准/深度生成说明 |
| [ARTICLE_DETEMPLATED_DEEP_GENERATION_IMPLEMENTATION_REPORT.md](./ARTICLE_DETEMPLATED_DEEP_GENERATION_IMPLEMENTATION_REPORT.md) | V2.2 本地实施结果、测试证据和剩余发布门禁 |
| [CODEX_BUSINESS_INTAKE_API_WHITEPAPER.md](./CODEX_BUSINESS_INTAKE_API_WHITEPAPER.md) | Codex / AI 通过 GEOFlow API 创建客户、订单、售后、Case、知识库录入草稿的规划白皮书 |
| [UPSTREAM_SELECTIVE_ADOPTION_PLAN_2026-07.md](./UPSTREAM_SELECTIVE_ADOPTION_PLAN_2026-07.md) | 上游 `f603830` 选择性采纳的阶段清单、禁止项、测试、回滚和断点记录 |
| [MAINLINE_REMAINING_OPTIMIZATION_PLAN.md](./MAINLINE_REMAINING_OPTIMIZATION_PLAN.md) | 主线剩余优化的已完成归档报告，不再作为待执行清单 |
| [Entity-to-Entity关联CRM侧实现清单.md](./Entity-to-Entity关联CRM侧实现清单.md) | Entity 关系在 CRM 场景中的长期增强规划，当前不是已完成主线 |
| [CRM_DOCUMENT_PDF_VISUAL_REGRESSION_2026-06-16.md](./CRM_DOCUMENT_PDF_VISUAL_REGRESSION_2026-06-16.md) | CRM 单据 PDF 五类真实样本视觉回归记录 |

## 每次开发后按需更新

完成开发后不要机械更新所有文档。按影响范围更新：

- 进度变化：[IMPLEMENTATION_STATUS.md](./IMPLEMENTATION_STATUS.md)
- 新风险或缺陷：[KNOWN_ISSUES.md](./KNOWN_ISSUES.md)
- 架构边界变化：[ARCHITECTURE_RULES.md](./ARCHITECTURE_RULES.md)
- 接手摘要明显过期：[AGENT_BRIEF.md](./AGENT_BRIEF.md) 和 [AGENT_HANDOFF.md](./AGENT_HANDOFF.md)
- 协作方式、需求理解或基础优化流程变化：[USER_REQUIREMENT_INTERPRETATION.md](./USER_REQUIREMENT_INTERPRETATION.md) 和 [AGENT_WORKFLOW_RULES.md](./AGENT_WORKFLOW_RULES.md)

如果新增或改变了用户操作方式，还要同步更新 `功能说明文档` 中对应说明。

## 最近需要特别记住的变化

- URL 智能采集创建任务页可以选择 AI 分析模型；不选时自动选择，指定模型优先并保留 failover。
- Knowledge / Entity / Case 的 AI 自动分析共用 `MaterialAnalysisPromptRules`。
- AI 自动分析的自定义输入是“补充分析要求”，不是完整替换系统提示词。
- 复杂表格、参数表和规格表虽然已有保真规则，仍建议人工复核。
- 不同素材的自动 tag 推荐已移除；不要在未确认需求前恢复。
- 文章创建/编辑页已接入 Vditor Markdown 编辑器，可复制 Markdown 或公众号/微信格式 HTML。
- 网站设置页已有模板工厂 / 站点模板复刻入口；当前本地浏览器后台入口优先使用 `/admin`。
- Codex 业务录入助手 API Phase 0-6 已完成核心功能：只读上下文搜索、AI 录入草稿箱、草稿预检、后台审核应用、知识库/Case 内容候选、本地调用脚本和基础治理提醒均已落地；仍不要新增绕过草稿箱的直接写入捷径。
- 文章 V2.4 Master 与 Intent Skill 已按用户决定同步到 Docker 业务库：Master 和
  大部分 Skill 为 `2.4.0`，Technical 为 `2.4.1`。后续通过真实任务人工审核，
  不代表批准跳过事实审核或自动发布，也不要自动恢复付费 A/B 循环。
- 上游 System Update Center 准备度补丁尚未接入，后续应单独规划。
- 上游选择性采纳的新主计划已保存；禁止直接 merge `upstream/main`，阶段 1-3 为优先主线，后续按计划文件中的断点继续。
