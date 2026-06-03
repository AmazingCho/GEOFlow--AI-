# GEOFlow Agent Handoff

## 项目定位

这是 GEOFlow 的本地深度定制版本，目标是把原有内容生成系统升级为：

Collection + Entity + Case + Knowledge Base + Tag + RAG + Quality Review 的 GEO 内容生产系统。

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

详细规则见 [ARCHITECTURE_RULES.md](./ARCHITECTURE_RULES.md)。

## 已完成主线

- 阶段 1：生成追踪基础。
- 阶段 2：正式 RAG 检索。
- 阶段 3：自动标签推荐。
- 阶段 4：Entity / Case 结构化。
- 阶段 5：GEO 生成流水线。
- 阶段 6：质量评分与审核辅助。
- 阶段 7：性能与后台体验优化，核心功能已做，仍需大数据量验证。

进度细节见 [IMPLEMENTATION_STATUS.md](./IMPLEMENTATION_STATUS.md)。

## 最近完成的重要治理增强

- 新增 Collection 顶层容器规则。
- 移除“行业领域标签承担 Collection 职责”的设计。
- 新增 Entity 与知识库、关键词、图片、Case 的关联能力。
- 新增知识库 type、role、status、importance、summary、source URL 等治理字段。
- 新增受控标签分组白名单，支持在标签管理页维护。
- 创建任务页改为 Collection 必选，Entity / Case 多选。
- 创建任务页受控标签筛选改为 accordion，作为可选高级筛选。
- 关键词库和图片库移除库级标签，只保留标题库库级标签。
- 新增 `功能说明文档/`，用于用户操作说明。

## 新 agent 接手时优先检查

1. 当前用户要改的是功能逻辑、UI 入口、文档，还是数据治理规则。
2. 是否会破坏 Collection / Entity / Tag / Knowledge Base 的职责边界。
3. 是否需要同步数据库迁移、测试、前端入口和使用说明。
4. 是否需要更新本目录中的交接文档。

## 已知高优先级待办

- 任务回收站尚未实现。
- AI 知识库纠错助手尚未实现。
- 大数据量下的标签远程搜索、懒加载、统计缓存仍需要继续压测和补强。
- 旧文章可能没有完整生成 trace，因此文章编辑页不一定显示生成来源。

更多风险见 [KNOWN_ISSUES.md](./KNOWN_ISSUES.md)。

## 功能说明入口

如果需要了解用户如何操作系统，请读取 [FEATURE_DOC_INDEX.md](./FEATURE_DOC_INDEX.md)，它会跳转到 `功能说明文档`。

