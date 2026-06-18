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

## 用户协作方式

用户通常用业务语言、页面现象、片段 HTML、截图线索或不完整描述来提出需求，不要求使用技术术语。

每次动手前，先按 [USER_REQUIREMENT_INTERPRETATION.md](./USER_REQUIREMENT_INTERPRETATION.md) 做产品经理式转译：理解业务目标、影响页面、期望行为、边界保护和验收标准。不要把描述不清直接当作阻塞；只有涉及不可逆删除、数据迁移、权限安全、外部发布、费用调用，或多个业务方向无法判断时，才停下来问用户。

## 核心架构规则

- Collection 是顶层业务容器，不让标签承担 Collection 职责。
- Entity 是知识索引节点，不要求字段本身承载完整资料。
- Knowledge Base 存放丰富来源资料，Case DB 存放真实案例。
- Article generation 应使用 Entity 关联的知识库、案例、关键词、图片作为真实上下文。
- Tag 只描述可复用属性，分组由白名单控制，避免无限扩展。
- 关键词库和图片库不使用库级标签；标题库可以保留库级标签。
- 知识库不使用 `case_study` 作为资料类型，避免和 Case DB 重复。
- Prompt Skill System v1 是 Master Prompt + 可选 Skill Prompt；创建任务页支持规则版智能推荐，但必须保留不使用和手动覆盖。

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
- 单据制作支持报价单、PI、CI、装箱单和合同；当前推荐通过打印类型切换输出，不在前端创建独立副本，避免单据列表膨胀。
- CRM 单据 PDF 已采用 Chromium/Puppeteer 路线，复用 HTML 打印模板生成 A4 PDF；详情页和打印预览页已有“下载 PDF”入口，HTML 打印预览仍是失败兜底。后台“PDF 回归检查”可生成五类真实样本回归包、设置视觉基线并做截图 diff。Excel 导出不作为主流程。
- 打印模板已支持动态明细分页和动态 `Page X of Y`，长报价单、长 PI 会自动拆分第一页、续页、末页；PI 银行页会按总页数顺延。
- 不同素材的自动 tag 推荐已按用户要求移除；保留手动选择既有标签和白名单标签分组治理。
- Knowledge / Entity / Case 的 AI 自动分析已统一使用 `MaterialAnalysisPromptRules`。
- URL 智能采集创建任务页可选择 AI 分析模型；不选时自动选择并保留 failover。
- AI 模型页支持聊天模型级 `max_tokens`，仅用于文章正文生成；留空使用 `GEOFLOW_CONTENT_MAX_TOKENS`。
- 文章创建/编辑页已接入 Vditor Markdown 编辑器，支持复制 Markdown、复制公众号/微信格式 HTML 和编辑器图片上传入库。
- 网站设置页已有模板工厂 / 站点模板复刻入口，支持 3 个参考页面生成隔离草稿、预览、迭代、发布和打包。
- 分发首页最近日志已分页；后台登录页已有首次部署默认账号提示。
- 当前本地浏览器后台入口是 `/admin`，`/geo_admin` 可能 404；遇到入口问题先查环境配置、缓存和容器挂载。
- AI 知识库纠错助手已完成核心闭环：AI 只生成纠错提案，管理员确认后才应用；应用会刷新对应知识片段 embedding 并保存版本，可从版本记录回滚。
- 知识库切片/向量化已完成异步队列状态增强：列表页和详情页可看到 queued/running/completed/failed，后台 Job 会写回时间与错误，失败可重试。
- 任务回收站已完成核心功能：任务删除为软删除，已生成文章与来源任务关系保留；恢复后任务保持暂停。如需永久删除，必须先确认数据保留策略。
- Codex 业务录入助手 API Phase 0-6 已完成核心功能：已新增只读上下文搜索、AI 录入草稿箱、草稿预检、后台审核应用、知识库/Case 内容候选、本地调用脚本和基础治理提醒。仍不能跳过草稿箱直接写 CRM、知识库或 Case 最终业务表。

进度细节见 [IMPLEMENTATION_STATUS.md](./IMPLEMENTATION_STATUS.md)。

## 默认读取策略

不要每次全量读取 `agent-docs` 或 `功能说明文档`。

- 普通新任务：读本文件 + 本次涉及的代码文件。
- 新功能/修复/优化：先按 [USER_REQUIREMENT_INTERPRETATION.md](./USER_REQUIREMENT_INTERPRETATION.md) 转译需求。
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
- 不要让 Skill Prompt 承担素材分类、标签或知识库 metadata 职责；智能推荐只用于文章结构策略兜底。
- 不要让模板复刻流程直接覆盖现有主题文件；必须先生成草稿、预览、确认再发布。
- UI 改动要复用已有组件，并遵守 GEOFlow UI skill 中的输入框、下拉、border 规则。
- 涉及 Laravel 测试时，注意 APP_KEY 和 Docker 环境，见 GEOFlow testing skill。
