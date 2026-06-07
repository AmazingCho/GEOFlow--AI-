# Agent 工作规则

这些规则用于保证换 agent、上下文压缩或隔天继续时不丢进度。

## 开始任何开发前

默认不要全量读取文档。

低 token 启动时先读取：

1. [AGENT_BRIEF.md](./AGENT_BRIEF.md)
2. [DOC_READ_POLICY.md](./DOC_READ_POLICY.md)

只有当前任务需要阶段细节、风险细节或架构判断时，再读取：

- [AGENT_HANDOFF.md](./AGENT_HANDOFF.md)
- [IMPLEMENTATION_STATUS.md](./IMPLEMENTATION_STATUS.md)
- [KNOWN_ISSUES.md](./KNOWN_ISSUES.md)
- [ARCHITECTURE_RULES.md](./ARCHITECTURE_RULES.md)

如果涉及具体功能，再读取 [FEATURE_DOC_INDEX.md](./FEATURE_DOC_INDEX.md) 中对应的功能说明。

## 开发中必须检查

1. 是否破坏 Collection / Entity / Tag / Knowledge Base / Case 的职责边界。
2. 是否需要数据库迁移。
3. 是否需要新增或更新测试。
4. 是否需要补充前端入口。
5. 是否需要更新功能说明文档。
6. 是否会影响旧任务、旧文章或旧素材。

## 每完成一个阶段后

不要机械更新所有文档。按影响范围更新：

- 进度变化：[IMPLEMENTATION_STATUS.md](./IMPLEMENTATION_STATUS.md)
- 新缺陷或风险：[KNOWN_ISSUES.md](./KNOWN_ISSUES.md)
- 架构规则变化：[ARCHITECTURE_RULES.md](./ARCHITECTURE_RULES.md)
- 接手摘要变化：[AGENT_BRIEF.md](./AGENT_BRIEF.md) 和 [AGENT_HANDOFF.md](./AGENT_HANDOFF.md)

如果改变了用户操作流程，还必须更新 `功能说明文档/` 中对应文档。

## 状态记录格式

在 [IMPLEMENTATION_STATUS.md](./IMPLEMENTATION_STATUS.md) 中记录：

- 功能名称。
- 状态：未开始、进行中、已完成、部分完成、跳过。
- 涉及文件。
- 测试结果。
- 剩余风险。

## 缺陷记录格式

在 [KNOWN_ISSUES.md](./KNOWN_ISSUES.md) 中记录：

- 问题现象。
- 影响范围。
- 是否有临时解决方式。
- 建议修复顺序。

## 不要做的事

- 不要只把阶段计划写在聊天里。
- 不要新增无限标签分组。
- 不要让标签承担 Collection 职责。
- 不要恢复不同素材自动 tag 推荐，除非用户重新确认。
- 不要把 Case 内容混入 Knowledge Base type。
- 不要为每个 Entity 类型创建独立表。
- 不要在未确认旧数据兼容前改变删除语义。
