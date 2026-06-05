# Agent Docs 入口

这个目录用于解决 GEOFlow 项目在长对话、上下文压缩、换 agent 接手时的信息丢失问题。

新 agent 不应该依赖聊天历史，而应该先读取这里的交接文档，再按需跳转到 `功能说明文档`。

读取文档前请先遵守 [DOC_READ_POLICY.md](./DOC_READ_POLICY.md)：默认不要全量读取，只按当前任务读取最少必要文档。

## 最短接手路径

如果只想用最少 token 了解项目，请按顺序读取：

1. [AGENT_HANDOFF.md](./AGENT_HANDOFF.md)
2. [IMPLEMENTATION_STATUS.md](./IMPLEMENTATION_STATUS.md)
3. [KNOWN_ISSUES.md](./KNOWN_ISSUES.md)
4. [ARCHITECTURE_RULES.md](./ARCHITECTURE_RULES.md)

## 详细功能说明

如果需要理解具体功能怎么使用，再读取：

- [新增功能总览](../功能说明文档/00-新增功能总览.md)
- [Collection 业务容器使用说明](../功能说明文档/01-Collection业务容器使用说明.md)
- [Entity 与 Case 使用说明](../功能说明文档/02-Entity与Case使用说明.md)
- [标签与白名单分组使用说明](../功能说明文档/03-标签与白名单分组使用说明.md)
- [知识库治理与 RAG 检索使用说明](../功能说明文档/04-知识库治理与RAG检索使用说明.md)
- [创建任务与生成流程使用说明](../功能说明文档/05-创建任务与生成流程使用说明.md)
- [Prompt Skill System v1](./PROMPT_SKILL_SYSTEM.md)
- [文章质量评分与审核使用说明](../功能说明文档/06-文章质量评分与审核使用说明.md)
- [素材管理与关联使用说明](../功能说明文档/07-素材管理与关联使用说明.md)
- [URL 智能采集使用说明](../功能说明文档/08-URL智能采集使用说明.md)

## 本目录文件职责

| 文件 | 用途 |
| --- | --- |
| [AGENT_HANDOFF.md](./AGENT_HANDOFF.md) | 新 agent 第一入口，压缩项目定位、当前状态、下一步 |
| [ARCHITECTURE_RULES.md](./ARCHITECTURE_RULES.md) | Collection、Entity、Tag、Knowledge Base、Case 的规则边界 |
| [OPTIMIZATION_ROADMAP.md](./OPTIMIZATION_ROADMAP.md) | 阶段规划和后续开发顺序 |
| [IMPLEMENTATION_STATUS.md](./IMPLEMENTATION_STATUS.md) | 已完成、部分完成、未完成事项 |
| [KNOWN_ISSUES.md](./KNOWN_ISSUES.md) | 已知缺陷、风险和容易误判的问题 |
| [DOC_READ_POLICY.md](./DOC_READ_POLICY.md) | 文档读取策略，避免每次任务全量读取造成 token 浪费 |
| [AGENT_WORKFLOW_RULES.md](./AGENT_WORKFLOW_RULES.md) | 每个 agent 开发前后必须遵守的交接规则 |
| [FEATURE_DOC_INDEX.md](./FEATURE_DOC_INDEX.md) | 功能说明文档索引和阅读建议 |
| [PROMPT_SKILL_SYSTEM.md](./PROMPT_SKILL_SYSTEM.md) | Master Prompt + Skill Prompt 生成层说明 |
| [NEXT_AGENT_PROMPT.md](./NEXT_AGENT_PROMPT.md) | 可以直接复制给新 agent 的启动提示词 |

## 每次开发后必须更新

每完成一个阶段或修复一个重要问题，至少更新：

1. [IMPLEMENTATION_STATUS.md](./IMPLEMENTATION_STATUS.md)
2. [KNOWN_ISSUES.md](./KNOWN_ISSUES.md)
3. [AGENT_HANDOFF.md](./AGENT_HANDOFF.md)

如果新增或改变了用户操作方式，还要同步更新 `功能说明文档` 中对应说明。

## 最近需要特别记住的变化

- URL 智能采集创建任务页可以选择 AI 分析模型；不选时自动选择，指定模型优先并保留 failover。
- Knowledge / Entity / Case 的 AI 自动分析共用 `MaterialAnalysisPromptRules`。
- AI 自动分析的自定义输入是“补充分析要求”，不是完整替换系统提示词。
- 复杂表格、参数表和规格表虽然已有保真规则，仍建议人工复核。
