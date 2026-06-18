# Codex 业务录入助手 API 使用说明

## 这个功能解决什么问题

当你在 Codex 里用自然语言记录客户、询盘、售后、Case 或知识库沉淀想法时，可以让 Codex 先调用 GEOFlow API 创建“AI 录入草稿”。草稿进入后台审核台，管理员确认后才真正写入 CRM 或内容候选。

它不是让 Codex 直接替你改数据库，而是把“聊天里的业务想法”整理成可审核、可追踪、可拒绝的业务操作建议。

## 基本流程

1. Codex 先搜索上下文：客户、询盘、商机、单据、订单、售后、Entity、知识库、Case。
2. Codex 根据搜索结果生成结构化草稿。
3. GEOFlow 保存到 AI 录入草稿箱。
4. 管理员进入后台查看风险、置信度、字段和关联关系。
5. 管理员点击应用后，系统才创建 CRM 记录或内容候选。

## 后台入口

超级管理员登录后台后，在右上角用户菜单中进入：

```text
AI 录入草稿箱
```

列表页可以按状态、风险、来源筛选。详情页会展示：

- 原始输入
- AI 摘要
- 待执行动作
- 每个动作的风险等级和置信度
- 字段内容
- 关联关系
- 治理提醒
- 应用 / 拒绝按钮

## API 权限

需要给 API Token 分配最小权限：

```text
assistant:read
assistant:write
```

`assistant:read` 用于查询上下文。
`assistant:write` 用于创建草稿和预检草稿。

不要给 Codex 默认授予高风险权限，例如发布文章、删除数据、直接修改订单金额等。

## 常用接口

### 1. 搜索上下文

```http
GET /api/v1/assistant/context/search?q=SJ4060 Spain&collection_id=1
Authorization: Bearer <token>
```

用于在创建草稿前确认相关客户、Entity、知识库、Case 或售后工单是否已经存在。

### 2. 预检草稿

```http
POST /api/v1/assistant/intake-drafts/validate
Authorization: Bearer <token>
Content-Type: application/json
```

预检不会保存数据，只返回风险、重复客户、缺 Collection、低置信度等提醒。

### 3. 创建草稿

```http
POST /api/v1/assistant/intake-drafts
Authorization: Bearer <token>
X-Idempotency-Key: codex-intake:20260619:sj4060
Content-Type: application/json
```

创建草稿会写入 `ai_intake_drafts` 和 `ai_intake_actions`，但不会直接创建客户、知识库或 Case。

### 4. 查看草稿

```http
GET /api/v1/assistant/intake-drafts/{id}
Authorization: Bearer <token>
```

## 本地脚本

项目提供脚本：

```bash
scripts/codex-intake.mjs
```

使用前在本地环境变量设置：

```bash
export GEOFLOW_API_BASE_URL="http://localhost:18080/api/v1"
export GEOFLOW_API_TOKEN="你的 API Token"
```

示例：

```bash
node scripts/codex-intake.mjs search "SJ4060 Spain nozzle clogging"
node scripts/codex-intake.mjs create draft.json
node scripts/codex-intake.mjs show 12
```

不要把 API Token 写入文档、脚本或聊天记录。

## 当前支持的应用动作

管理员确认应用后，当前支持：

- 创建客户
- 创建询盘
- 创建活动记录
- 创建待办
- 创建售后工单草稿
- 创建知识库 FAQ 候选
- 创建 Case 候选

知识库和 Case 只会先进入 `CRM 内容候选`，不会直接覆盖知识库正文或直接写入 Case DB。

## 当前治理提醒

系统会提示：

- 草稿缺少 Collection
- 可能重复创建客户
- 草稿整体置信度过低
- 单个动作置信度过低

这些提醒不会阻止保存草稿，但应用前应该人工检查。

## 边界

当前不支持自动执行以下操作：

- 修改订单金额
- 修改付款状态
- 关闭售后工单
- 覆盖知识库正文
- 删除或合并数据
- 发布文章或对外发布内容

这些动作后续即使扩展，也必须保留人工确认和审计。
