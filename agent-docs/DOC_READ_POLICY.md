# Agent 文档读取策略

这个文件用于避免每次优化都全量读取 `agent-docs` 和 `功能说明文档`，造成不必要的 token 浪费。

## 核心原则

默认不要全量读取文档。

文档是断点恢复、换 agent 接手和架构冲突判断时使用的索引，不是每次开发前都要完整阅读的资料库。

## 场景 1：同一个对话中继续开发

优先使用当前对话上下文和代码检查结果。

通常不需要读取 `agent-docs`。

只有当新需求涉及以下情况时，才读取最相关的 1 到 2 个文件：

- 不确定当前阶段进度。
- 不确定架构边界。
- 不确定某个功能的既定设计。
- 用户要求回顾阶段、路线图或已知问题。

## 场景 2：上下文压缩后继续

只读取最短恢复集：

1. [AGENT_HANDOFF.md](./AGENT_HANDOFF.md)
2. [IMPLEMENTATION_STATUS.md](./IMPLEMENTATION_STATUS.md)
3. [KNOWN_ISSUES.md](./KNOWN_ISSUES.md)

如果任务涉及架构判断，再补读：

4. [ARCHITECTURE_RULES.md](./ARCHITECTURE_RULES.md)

## 场景 3：新 agent 接手

先读取：

1. [README.md](./README.md)
2. [AGENT_HANDOFF.md](./AGENT_HANDOFF.md)
3. [IMPLEMENTATION_STATUS.md](./IMPLEMENTATION_STATUS.md)
4. [KNOWN_ISSUES.md](./KNOWN_ISSUES.md)
5. [DOC_READ_POLICY.md](./DOC_READ_POLICY.md)

然后根据任务类型按需读取其他文档。

## 场景 4：具体功能开发

只读取与当前任务直接相关的文档。

示例：

| 当前任务 | 建议读取 |
| --- | --- |
| 修改创建任务页 | [创建任务与生成流程使用说明](../功能说明文档/05-创建任务与生成流程使用说明.md) |
| 修改 Entity / Case | [Entity 与 Case 使用说明](../功能说明文档/02-Entity与Case使用说明.md) |
| 修改标签、标签分组、标签治理 | [ARCHITECTURE_RULES.md](./ARCHITECTURE_RULES.md)、[标签与白名单分组使用说明](../功能说明文档/03-标签与白名单分组使用说明.md) |
| 修改知识库或 RAG | [知识库治理与 RAG 检索使用说明](../功能说明文档/04-知识库治理与RAG检索使用说明.md) |
| 修改文章质量评分 | [文章质量评分与审核使用说明](../功能说明文档/06-文章质量评分与审核使用说明.md) |
| 修改素材管理页 | [素材管理与关联使用说明](../功能说明文档/07-素材管理与关联使用说明.md) |
| 修改 URL 智能采集 | [URL 智能采集使用说明](../功能说明文档/08-URL智能采集使用说明.md) |

## 场景 5：全局审计或阶段回顾

只有在用户明确要求以下任务时，才读取更多文档：

- 完整回顾项目当前优化状态。
- 检查所有阶段是否有遗漏。
- 重新整理优化路线图。
- 判断多个功能之间是否有架构冲突。
- 换新 agent 并需要完整接手。

此时可以读取：

1. [README.md](./README.md)
2. [AGENT_HANDOFF.md](./AGENT_HANDOFF.md)
3. [ARCHITECTURE_RULES.md](./ARCHITECTURE_RULES.md)
4. [OPTIMIZATION_ROADMAP.md](./OPTIMIZATION_ROADMAP.md)
5. [IMPLEMENTATION_STATUS.md](./IMPLEMENTATION_STATUS.md)
6. [KNOWN_ISSUES.md](./KNOWN_ISSUES.md)
7. [FEATURE_DOC_INDEX.md](./FEATURE_DOC_INDEX.md)

## 每次完成开发后的更新规则

完成开发后，不要机械更新所有文档。

按影响范围更新：

| 影响范围 | 需要更新 |
| --- | --- |
| 阶段进度变化 | [IMPLEMENTATION_STATUS.md](./IMPLEMENTATION_STATUS.md) |
| 新增缺陷或风险 | [KNOWN_ISSUES.md](./KNOWN_ISSUES.md) |
| 架构规则变化 | [ARCHITECTURE_RULES.md](./ARCHITECTURE_RULES.md) |
| 路线图变化 | [OPTIMIZATION_ROADMAP.md](./OPTIMIZATION_ROADMAP.md) |
| 接手摘要变化 | [AGENT_HANDOFF.md](./AGENT_HANDOFF.md) |
| 用户操作方式变化 | 对应的 `功能说明文档/*.md` |

## 用户可使用的固定提示

如果用户希望减少 token 消耗，可以在新任务前加：

```md
本轮不要全量读取 agent-docs。
如果需要上下文，只读取与本任务直接相关的最少文档。
完成后只更新受影响的 agent-docs 文件和相关功能说明。
```

