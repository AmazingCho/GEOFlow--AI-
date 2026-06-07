# GEOFlow Agent Brief

这是新 agent 或新会话的低 token 启动文件。默认先读本文件；只有当前任务需要时，再按链接读取更详细文档。

## 项目根目录

真实项目通常在：

`/Users/leo/Desktop/GEOFlow`

开始写文件前必须确认该目录存在 `artisan`、`app/`、`database/`、`resources/`。如果 shell 当前在 `/Users/leo/Documents/GEOWorkflow-optimized`，先切回真实项目根目录。

## 项目定位

这是 GEOFlow 的本地深度定制版，目标是把原内容生成系统升级为：

Collection + Entity + Case + Knowledge Base + Tag + RAG + Quality Review + Lightweight CRM 的 GEO 内容生产系统。

所有新增功能应保持增量、向后兼容，优先保护已有业务流程和用户数据。

## 核心架构规则

- Collection 是顶层业务容器，不让标签承担 Collection 职责。
- Entity 是知识索引节点，不要求字段本身承载完整资料。
- Knowledge Base 存放丰富来源资料，Case DB 存放真实案例。
- Article generation 应使用 Entity 关联的知识库、案例、关键词、图片作为真实上下文。
- Tag 只描述可复用属性，分组由白名单控制，避免无限扩展。
- 关键词库和图片库不使用库级标签；标题库可以保留库级标签。
- 知识库不使用 `case_study` 作为资料类型，避免和 Case DB 重复。
- Prompt Skill System v1 是 Master Prompt + 可选 Skill Prompt，不自动匹配意图。

详细边界见 [ARCHITECTURE_RULES.md](./ARCHITECTURE_RULES.md)。

## 当前状态快照

- 主线阶段 1-7 已完成核心功能。
- Collection、Entity、Case、RAG、质量评分、Prompt Skill v1、URL 智能采集增强已落地。
- 轻量 CRM 阶段 1-7 已落地：客户、询盘、报价、订单、售后工单、内容候选和任务 CRM 来源关联。
- 不同素材的自动 tag 推荐已按用户要求移除；保留手动选择既有标签和白名单标签分组治理。
- Knowledge / Entity / Case 的 AI 自动分析已统一使用 `MaterialAnalysisPromptRules`。
- URL 智能采集创建任务页可选择 AI 分析模型；不选时自动选择并保留 failover。
- AI 模型页支持聊天模型级 `max_tokens`，仅用于文章正文生成；留空使用 `GEOFLOW_CONTENT_MAX_TOKENS`。
- 任务回收站、AI 知识库纠错助手仍未实现。

进度细节见 [IMPLEMENTATION_STATUS.md](./IMPLEMENTATION_STATUS.md)。

## 默认读取策略

不要每次全量读取 `agent-docs` 或 `功能说明文档`。

- 普通新任务：读本文件 + 本次涉及的代码文件。
- 架构判断：再读 [ARCHITECTURE_RULES.md](./ARCHITECTURE_RULES.md)。
- 阶段/进度判断：再读 [IMPLEMENTATION_STATUS.md](./IMPLEMENTATION_STATUS.md) 和 [KNOWN_ISSUES.md](./KNOWN_ISSUES.md)。
- 具体功能使用方式：按 [FEATURE_DOC_INDEX.md](./FEATURE_DOC_INDEX.md) 只读相关功能说明。
- 提示词、任务生成、Skill：再读 [PROMPT_SKILL_SYSTEM.md](./PROMPT_SKILL_SYSTEM.md)。

完整读取策略见 [DOC_READ_POLICY.md](./DOC_READ_POLICY.md)。

## 开发后更新规则

不要机械更新所有文档。只更新受影响的文件：

- 进度变化：更新 [IMPLEMENTATION_STATUS.md](./IMPLEMENTATION_STATUS.md)。
- 新风险/缺陷：更新 [KNOWN_ISSUES.md](./KNOWN_ISSUES.md)。
- 架构边界变化：更新 [ARCHITECTURE_RULES.md](./ARCHITECTURE_RULES.md)。
- 用户操作变化：更新 `功能说明文档/` 对应文件。
- 接手摘要明显过期：更新本文件和 [AGENT_HANDOFF.md](./AGENT_HANDOFF.md)。

## 高频风险

- 不要把标签重新设计成 Collection。
- 不要恢复不同素材的自动 tag 推荐，除非用户重新确认。
- 不要让 Skill Prompt 承担素材分类、标签或知识库 metadata 职责。
- UI 改动要复用已有组件，并遵守 GEOFlow UI skill 中的输入框、下拉、border 规则。
- 涉及 Laravel 测试时，注意 APP_KEY 和 Docker 环境，见 GEOFlow testing skill。
