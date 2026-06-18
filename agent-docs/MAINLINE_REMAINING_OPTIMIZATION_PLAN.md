# Mainline Remaining Optimization Plan - Archived Completion Report

更新时间：2026-06-19
状态：已完成归档，不再作为待执行清单

## 结论

原“主线剩余优化计划”的四个阶段已经完成核心功能。后续 agent 不应再按旧清单重新执行任务回收站、性能补强、知识库治理 proposal 或 Collection 健康度面板。

当前状态以这些文档为准：

- [IMPLEMENTATION_STATUS.md](./IMPLEMENTATION_STATUS.md)
- [KNOWN_ISSUES.md](./KNOWN_ISSUES.md)
- [AGENT_BRIEF.md](./AGENT_BRIEF.md)
- [AGENT_HANDOFF.md](./AGENT_HANDOFF.md)
- [../功能说明文档/04-知识库治理与RAG检索使用说明.md](../功能说明文档/04-知识库治理与RAG检索使用说明.md)

## 已完成阶段

| 阶段 | 当前状态 | 已落地结果 |
| --- | --- | --- |
| Phase 1：任务回收站 | 已完成核心功能 | 任务删除改为软删除；已生成文章和来源任务关系保留；文章列表/编辑页可显示“已删除任务”；回收站支持查看和恢复；恢复后任务保持暂停 |
| Phase 2：性能压测补强 | 已完成核心功能 | 标签选择器远程搜索、标签引用懒加载、标签统计缓存 key 统一、知识库队列状态可见；仍建议未来用真实大数据做压力验证 |
| Phase 3：知识库治理建议 | 已完成核心功能 | 重复/冲突检查结果可创建 governance proposal；重复资料应用后只归档为 inactive 并支持回滚；事实冲突只记录人工审核结论，不自动改正文 |
| Phase 4：Collection 健康度 | 已完成核心功能 | Collection 列表展示健康度分数，详情页只读检查 Entity、知识库、标题库、图片库、Case、素材关联、向量化和标签治理问题 |

## 仍可继续优化，但不是本计划遗留阻塞

- 真实大数据压力验证：用上千标签、图片、知识库和大文件验证远程搜索、懒加载、统计缓存、队列 worker、Embedding 限流和失败重试。
- 知识库自动合并正文：当前默认禁用。若要实现，必须另立阶段，加入 diff、版本、回滚、embedding 重建和强确认。
- 任务永久删除：默认不开放。若要实现，必须先确认文章、trace、分发记录和任务名称快照的保留策略。
- 产品资料线 / Datasheet 固定模板：已标注为待定，不属于本主线计划。
- CRM 统一回收站、报价审批、邮件发送：属于 CRM 深水区能力，不属于本主线计划。

## 后续 agent 注意

- 不要恢复“不同素材自动 tag 推荐”，除非用户重新确认规则和审核流程。
- 不要把标签重新设计成 Collection。
- 不要让知识库治理自动覆盖正文或删除资料。
- 不要把这个归档文件中的历史目标当作新的待执行需求。
- 如果用户说“继续主线剩余优化”，应先打开 [IMPLEMENTATION_STATUS.md](./IMPLEMENTATION_STATUS.md) 和 [KNOWN_ISSUES.md](./KNOWN_ISSUES.md)，确认当前真正未完成事项。

## 最近验证口径

主线相关验证记录已集中维护在 [IMPLEMENTATION_STATUS.md](./IMPLEMENTATION_STATUS.md) 的“最近验证记录”部分。旧执行版清单中的未勾选项已经移除，避免上下文压缩后被误判为未完成任务。
