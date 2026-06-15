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
- 轻量 CRM 已形成 V2 销售链：客户、多联系人、询盘、商机、活动、待办、单据、订单、售后和内容候选串联。
- CRM 业务对象使用软删除归档；归档客户不会级联删除询盘、单据、订单和售后记录。
- 已发生的沟通存入活动记录，未来动作存入 `crm_tasks`；活动可同步创建待办，待办完成可写回活动结果，不要再把二者合并回同一字段。
- 商机来自询盘或无来源直接创建；同一询盘只能有一个活动商机。商机归档不会删除待办、活动或单据。
- 单据保存会校验并归一化客户、询盘、商机和 Collection 销售链，冲突组合会拒绝保存。
- `crm:pipeline-audit` 默认只读，`--apply` 只修复唯一候选历史断链；当前真实数据已从 16 项降至 8 项，剩余项需人工判断。
- 单据制作支持报价单、PI、CI、装箱单和合同，并可从已有单据转换出带来源关系的独立记录。
- HTML 打印预览是当前稳定输出方案；自动 PDF/Excel 导出没有前端入口，不要误判为已完成能力。
- 不同素材的自动 tag 推荐已按用户要求移除；保留手动选择既有标签和白名单标签分组治理。
- Knowledge / Entity / Case 的 AI 自动分析已统一使用 `MaterialAnalysisPromptRules`。
- URL 智能采集创建任务页可选择 AI 分析模型；不选时自动选择并保留 failover。
- AI 模型页支持聊天模型级 `max_tokens`，仅用于文章正文生成；留空使用 `GEOFLOW_CONTENT_MAX_TOKENS`。
- 文章创建/编辑页已接入 Vditor Markdown 编辑器，支持复制 Markdown、复制公众号/微信格式 HTML 和编辑器图片上传入库。
- 网站设置页已有模板工厂 / 站点模板复刻入口，支持 3 个参考页面生成隔离草稿、预览、迭代、发布和打包。
- 分发首页最近日志已分页；后台登录页已有首次部署默认账号提示。
- 当前本地浏览器后台入口是 `/admin`，`/geo_admin` 可能 404；遇到入口问题先查环境配置、缓存和容器挂载。
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
- 不要让模板复刻流程直接覆盖现有主题文件；必须先生成草稿、预览、确认再发布。
- UI 改动要复用已有组件，并遵守 GEOFlow UI skill 中的输入框、下拉、border 规则。
- 涉及 Laravel 测试时，注意 APP_KEY 和 Docker 环境，见 GEOFlow testing skill。
