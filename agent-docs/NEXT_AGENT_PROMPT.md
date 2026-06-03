# 新 Agent 启动提示词

下面这段可以直接复制给新 agent，用于最少 token 接手项目。

```md
你正在接手我的 GEOFlow 本地定制项目。

请不要依赖历史聊天记录，先读取以下文件：

1. agent-docs/README.md
2. agent-docs/AGENT_HANDOFF.md
3. agent-docs/IMPLEMENTATION_STATUS.md
4. agent-docs/KNOWN_ISSUES.md
5. agent-docs/ARCHITECTURE_RULES.md

如果涉及具体功能操作，再读取：

- agent-docs/FEATURE_DOC_INDEX.md

读取后请用 10 条以内总结：

- 当前项目架构
- 已完成优化
- 当前阶段进度
- 主要风险
- 下一步建议

然后等待我确认是否继续开发。
```

## 如果要继续开发

可以追加：

```md
请按照 agent-docs/AGENT_WORKFLOW_RULES.md 执行。
每完成一个阶段后，运行核心自检或相关测试，并更新 agent-docs/IMPLEMENTATION_STATUS.md、agent-docs/KNOWN_ISSUES.md、agent-docs/AGENT_HANDOFF.md。
如果改变了用户操作流程，同步更新 功能说明文档/ 中对应文档。
```

