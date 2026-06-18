# 功能说明文档索引

本文件用于把 agent 交接文档和用户操作说明联动起来。

## Agent 协作与需求转译

- [用户需求理解与产品经理式转译规则](./USER_REQUIREMENT_INTERPRETATION.md)
- [Codex 全局个性化提示词建议](./CODEX_GLOBAL_PERSONAL_PROMPT.md)
- [Codex 业务录入助手 API 白皮书](./CODEX_BUSINESS_INTAKE_API_WHITEPAPER.md)

适合理解：

- 用户用非技术语言描述需求时，Agent 应如何先做产品经理式转译。
- 每次优化前如何把需求拆成业务目标、影响页面、期望行为、边界保护和验收标准。
- 如何把规则复制到 Codex 个性化提示词，并配合本地 Skill 使用。
- 如何把用户关于客户、订单、售后、Case 和知识库的自然语言思考整理为可审核的 AI 录入草稿，并通过 GEOFlow API 安全入库。

## 总览

- [新增功能总览](../功能说明文档/00-新增功能总览.md)

适合新 agent 或新用户快速了解系统新增能力。

## Collection

- [Collection 业务容器使用说明](../功能说明文档/01-Collection业务容器使用说明.md)

适合理解：

- 为什么 Collection 是顶层容器。
- 为什么不要用标签承担 Collection。
- 创建任务时为什么 Collection 必选。

## Entity / Case

- [Entity 与 Case 使用说明](../功能说明文档/02-Entity与Case使用说明.md)

适合理解：

- Entity 为什么不是完整资料库。
- Case 为什么单独存在。
- Entity 和知识库、图片、关键词、Case 如何关联。
- Entity / Case AI 自动分析如何使用补充分析要求。

## 标签与白名单分组

- [标签与白名单分组使用说明](../功能说明文档/03-标签与白名单分组使用说明.md)

适合理解：

- 受控分组标签的作用。
- 白名单标签分组如何减少混乱。
- 为什么关键词库和图片库不使用库级标签。

## 知识库与 RAG

- [知识库治理与 RAG 检索使用说明](../功能说明文档/04-知识库治理与RAG检索使用说明.md)

适合理解：

- knowledge_type 和 knowledge_role。
- 为什么 `case_study` 不属于 knowledge_type。
- RAG 如何使用 Entity-linked knowledge bases。
- 知识库 AI 自动分析如何处理摘要、描述、Markdown 正文和表格参数。
- AI 知识库纠错助手如何从知识库或文章发起 proposal、审批、应用和回滚。

## 创建任务

- [创建任务与生成流程使用说明](../功能说明文档/05-创建任务与生成流程使用说明.md)
- [Prompt Skill System v1](./PROMPT_SKILL_SYSTEM.md)

适合理解：

- 创建任务页各选项的顺序。
- Entity / Case 多选。
- 受控分组标签是否必须选择。
- 图片配置中“不指定图库”和“不配图”的区别。
- Master Prompt 与可选 Skill Prompt 的职责边界。

## 文章质量

- [文章质量评分与审核使用说明](../功能说明文档/06-文章质量评分与审核使用说明.md)

适合理解：

- 文章评分在哪里看。
- 质量问题建议如何辅助审核。
- 生成来源为什么可能不显示。

## 文章编辑器与模板复刻

- [文章编辑器与模板复刻使用说明](../功能说明文档/11-文章编辑器与模板复刻使用说明.md)

适合理解：

- 文章 Markdown 编辑器如何使用。
- 如何复制 Markdown 或公众号/微信格式 HTML。
- 编辑器上传图片如何入库并关联文章。
- 模板工厂如何从 3 个参考页面创建主题草稿。
- 模板复刻的预览、迭代、发布、打包和归档边界。

## 素材管理

- [素材管理与关联使用说明](../功能说明文档/07-素材管理与关联使用说明.md)
- [素材系统推荐使用方法](../功能说明文档/09-素材系统推荐使用方法.md)

适合理解：

- 基础素材库排序。
- 素材如何关联 Entity。
- 图片标题编辑。
- 标签选择器交互。
- Collection / Entity / Knowledge / Case / Tag 的推荐分工。

## URL 智能采集

- [URL 智能采集使用说明](../功能说明文档/08-URL智能采集使用说明.md)

适合理解：

- 自动检测语言。
- AI 分析模型选择。
- Entity / Case 生成勾选。
- 采集结果入库确认。

## 轻量 CRM

- [轻量 CRM 与报价使用说明](../功能说明文档/10-轻量CRM与报价使用说明.md)
- [CRM 单据 PDF 真实样本视觉回归记录](./CRM_DOCUMENT_PDF_VISUAL_REGRESSION_2026-06-16.md)
- [Codex 业务录入助手 API 白皮书](./CODEX_BUSINESS_INTAKE_API_WHITEPAPER.md)
- [实现状态与验证记录](./IMPLEMENTATION_STATUS.md)
- [已知问题与风险](./KNOWN_ISSUES.md)

适合理解：

- 客户联系人字段、内部负责人和跟进记录如何维护。
- 询盘如何关联 Collection、Entity、知识库和 Case。
- 询盘和商机的职责边界，以及何时从询盘转为商机。
- AI 需求识别的边界。
- 如何从询盘或商机生成单据并切换报价单、PI、CI、装箱单和合同打印模板。
- 如何从单据生成订单。
- 如何维护售后工单并复用知识库、Case 和 Entity。
- 如何把询盘/工单沉淀为标题、FAQ 或 Case 内容候选。
- 创建任务时 CRM 来源如何与 Collection 联动。
- 跟进记录如何在客户、询盘、单据、订单和售后页面联动展示。
- CRM 销售链路 V2 的当前规则、剩余历史歧义数据和已完成验证。
- CRM 单据 PDF 当前采用 Chromium/Puppeteer 路线，HTML 打印预览作为失败兜底。
- 修改单据模板后如何运行 PDF 回归检查。
