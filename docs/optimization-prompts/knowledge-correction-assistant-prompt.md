# 后续优化提示词：AI 知识纠错助手

请为 GEOFlow 增加“AI 知识纠错助手”，用于在知识库管理和文章详情页中发现、分析、审批和应用知识库纠错建议。

## 背景

当前知识库可以导入文本、切片、向量化并参与 RAG 检索。但当生成文章中出现事实错误、过期资料、产品参数错误或知识库内容冲突时，系统缺少一个从“错误反馈”到“定位知识片段”再到“审批修正并重新向量化”的闭环。

## 核心目标

1. 用户可以在知识库详情页发起纠错。
2. 用户可以在文章详情页选中错误段落并发起纠错。
3. 系统根据错误描述检索相关 `knowledge_chunks`。
4. 系统调用现有 AI 模型分析错误来源。
5. AI 只生成 correction proposal，不得直接覆盖知识库内容。
6. 管理员确认后，才更新 `knowledge_chunks.content`。
7. 更新 chunk 后必须重新生成 embedding/vector。
8. 所有操作写入 admin activity logs。
9. 支持查看 diff 和回滚旧版本。
10. 不破坏现有知识库导入、切片、embedding、RAG 检索流程。

## 数据库设计

### 新增 `knowledge_corrections` 表

字段建议：

- `id`
- `article_id` nullable
- `knowledge_base_id` nullable
- `knowledge_chunk_id` nullable
- `reported_by_admin_id` nullable
- `reviewed_by_admin_id` nullable
- `ai_model_id` nullable
- `status` string：`pending`、`approved`、`rejected`、`applied`
- `error_description` text
- `selected_article_text` text nullable
- `retrieved_context` json nullable
- `ai_result` json nullable
- `confirmed_error` boolean default false
- `error_type` string nullable
- `suggested_content` longText nullable
- `reasoning` text nullable
- `confidence` decimal nullable
- `review_note` text nullable
- `applied_at` timestamp nullable
- `created_at`
- `updated_at`

AI 输出必须包含：

- `confirmed_error`
- `error_type`
- `original_error_description`
- `suggested_content`
- `reasoning`
- `confidence`

### 新增 `knowledge_chunk_versions` 表

字段建议：

- `id`
- `knowledge_correction_id` nullable
- `knowledge_base_id`
- `knowledge_chunk_id`
- `version_no`
- `old_content` longText
- `new_content` longText nullable
- `old_embedding_hash` string nullable
- `new_embedding_hash` string nullable
- `changed_by_admin_id` nullable
- `change_reason` text nullable
- `created_at`
- `updated_at`

用途：

- 记录每次 chunk 修改前后的版本。
- 支持从旧版本恢复内容。
- 支持审计历史。

## 后端服务设计

### 新增服务：`KnowledgeCorrectionService`

职责：

1. 创建纠错记录。
2. 根据错误描述、文章选中文本、知识库范围检索相关 chunks。
3. 组装 AI prompt。
4. 调用现有 AI 模型。
5. 解析 AI 输出为结构化 proposal。
6. 保存 proposal，不直接修改知识库。
7. 管理员确认后应用修改。
8. 应用修改时写入 `knowledge_chunk_versions`。
9. 触发 chunk 重新 embedding。
10. 支持回滚版本。

### 检索逻辑

根据入口不同决定检索范围：

1. 知识库详情页发起：
   - 优先检索当前 `knowledge_base_id` 下 chunks。
2. 文章详情页发起：
   - 优先使用文章 generation trace 中引用过的 knowledge base 和 chunks。
   - 如果 trace 不完整，再按错误描述做全局检索。
3. 可选增强：
   - 支持按 Collection、Entity、Tag 限定检索范围。

### AI 分析要求

Prompt 必须明确：

- 你只能生成纠错建议，不能直接修改知识库。
- 必须说明是否确认错误。
- 必须指出错误类型。
- 必须引用相关 chunk 作为依据。
- 必须输出 JSON。
- 如果证据不足，应返回 `confirmed_error=false`，并说明原因。

错误类型建议：

- `factual_error`
- `outdated_information`
- `product_spec_error`
- `unsupported_claim`
- `contradiction`
- `translation_error`
- `missing_context`
- `other`

## 后台 UI

### 知识库详情页

新增“AI 知识纠错助手”卡片：

- 错误描述 textarea
- AI 模型选择
- 可选：限定当前知识库
- “分析错误”按钮
- 分析后展示：
  - 是否确认错误
  - 错误类型
  - 置信度
  - 相关 chunk
  - 原文
  - 建议修正内容
  - 修改理由
  - diff 对比
- 操作：
  - 保存为待审核
  - 确认并应用
  - 拒绝

### 文章编辑页 / 文章详情页

在文章内容区域增加纠错入口：

- 支持用户复制或选中文章中的错误段落。
- 点击“发起知识纠错”。
- 自动带入：
  - `article_id`
  - 选中文本
  - generation trace 中的知识库来源
- 跳转或弹窗进入纠错分析流程。

### 纠错记录列表页

新增页面：

- 路由建议：`/geo_admin/knowledge-corrections`
- 筛选：
  - 状态
  - 知识库
  - 文章
  - 错误类型
  - 置信度
  - 创建时间
- 列表字段：
  - ID
  - 状态
  - 错误类型
  - 知识库
  - chunk
  - article
  - 置信度
  - 创建时间
  - 操作

### 纠错详情页

展示：

- 错误描述
- 文章选中文本
- 相关 chunk 原文
- AI 建议内容
- diff 对比
- 修改理由
- 置信度
- 审核记录
- 操作按钮：
  - 批准
  - 拒绝
  - 应用修改
  - 回滚版本

## 应用修改流程

管理员点击“应用修改”后：

1. 校验 correction 状态为 approved 或 pending 且管理员确认。
2. 读取当前 `knowledge_chunks.content`。
3. 写入 `knowledge_chunk_versions`。
4. 更新 `knowledge_chunks.content`。
5. 更新 chunk metadata，例如 hash 或 updated_at。
6. 重新生成 embedding/vector。
7. 更新 correction 状态为 `applied`。
8. 写入 admin activity log。

如果重新 embedding 失败：

- 不应静默失败。
- 应显示错误。
- correction 可保持 approved 或 pending retry 状态。
- 不要破坏原 chunk 内容，除非已经有版本记录且能回滚。

## 回滚流程

管理员在版本记录中点击“恢复旧版本”：

1. 读取 `knowledge_chunk_versions.old_content`。
2. 写入新的版本记录，标记为 rollback。
3. 更新 `knowledge_chunks.content`。
4. 重新生成 embedding/vector。
5. 写入 admin activity log。

## 测试要求

请新增测试：

1. 可以从知识库详情页创建纠错记录。
2. 可以从文章详情页携带 article_id 和选中文本创建纠错记录。
3. AI 返回 JSON 后保存 proposal。
4. AI 不确认错误时，不允许直接应用。
5. 管理员批准后可以应用修改。
6. 应用修改后 `knowledge_chunks.content` 更新。
7. 应用修改后写入 `knowledge_chunk_versions`。
8. 应用修改后触发重新 embedding。
9. 拒绝 correction 后不能应用。
10. 可以从版本记录回滚旧内容。
11. 所有关键操作写入 admin activity logs。
12. 现有知识库导入、切片、embedding、RAG 检索测试仍通过。

## 冲突保护

实现时必须保护：

- 不修改现有知识库导入流程。
- 不修改现有切片策略的默认行为。
- 不让 AI 自动覆盖知识库。
- 不让未审批 proposal 进入 RAG。
- 不破坏 `knowledge_chunks` 的 embedding 字段格式。
- 不破坏文章 generation trace。
- 不破坏现有 RAG 检索结果结构。

## 验收标准

完成后请输出：

- 新增表结构说明
- 新增页面与入口说明
- correction 状态流转说明
- embedding 重新生成说明
- 回滚能力说明
- 冲突检查报告
- 测试结果
