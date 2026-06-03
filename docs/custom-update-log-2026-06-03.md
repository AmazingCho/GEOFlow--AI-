# GEOFlow 自定义优化更新日志

日期：2026-06-03

## 本次优化目标

本次优化围绕“素材治理、生成上下文、RAG 检索、质量审核、后台性能体验”展开，目标是把 GEOFlow 从“能生成文章”升级为“能按业务容器组织素材、按 Entity/Case 检索上下文、生成后可追踪和质检”的系统。

## 已完成内容

### 1. Collection 顶层容器

- 新增 Collection 作为顶层业务容器。
- Collection 可用于管理不同业务线或产品领域，例如 Automation Equipment、Industrial Cooling、Lighting。
- 关键词库、标题库、图片库、知识库、Entity、Case 等素材支持 Collection 归属。
- 不再让“行业领域”标签承担 Collection 职责，避免标签命名和业务边界混乱。

### 2. Entity / Case 结构化素材

- Entity 作为知识索引节点，可表示产品型号、产品线、材料、应用行业、工艺场景等。
- Case 继续用于真实客户案例、应用故事、项目结果等内容。
- 新增 Entity 与知识库、关键词、图片等素材的关联能力。
- 新增 Entity / Case 创建页 AI 自动识别分析入口，可从粘贴文本中提取字段并填入表单。

### 3. 知识库治理

- 知识库新增以下元数据：
  - Collection
  - knowledge_type
  - knowledge_role
  - importance
  - summary
  - source_url
  - status
- `knowledge_type` 不包含 `case_study`，避免与 Case DB 职责重复。
- 知识库 role 支持：
  - `primary_source`
  - `supporting_context`
  - `constraint`
  - `comparison_reference`
  - `style_reference`
  - `archive`
- 知识库列表新增筛选与批量治理：
  - 保存视图
  - Collection 筛选
  - 类型筛选
  - role 筛选
  - 重要度筛选
  - Entity 筛选
  - 标签分组筛选
  - 状态筛选
  - 批量分配 Collection
  - 批量追加标签
  - 批量设置 role
  - 批量关联 Entity
  - 批量归档停用

### 4. RAG 检索与生成上下文

- RAG 检索优先使用 Entity 关联知识库与 Case 作为真实上下文。
- Collection general knowledge 可作为补充上下文。
- `inactive` 或 `archive` 知识库默认不参与自动检索。
- 手动指定知识库时，仍允许引用归档或停用资料，兼容旧任务和特殊场景。
- 文章生成 trace 记录 Collection、Entity、Case、知识库和检索 chunk 信息。

### 5. 质量评分与审核辅助

- 后台文章列表显示质量评分。
- 文章编辑页显示质量检查信息。
- 质量检查覆盖：
  - 内容语言
  - 知识库引用
  - 关键词覆盖
  - FAQ
  - 图片匹配
  - 结构完整度
  - 生成来源
- 修复文章列表质量评分与编辑页评分可能不一致的问题。

### 6. 标签与素材编辑体验

- 标签选择器改为搜索式选择，避免标签过多时页面过长。
- 关键词库、标题库、图片库支持库级标签。
- 标题库列表补充编辑信息入口。
- 图片、关键词、知识库等素材管理页保留已有标签功能，并减少重复展示。

### 7. 后台治理检查

- 素材首页新增“素材治理检查”。
- 可检查：
  - 未分配 Collection 的素材
  - 未关联 Entity 的知识库
  - 无素材或无 Case 的 Entity
  - 未关联 Entity 的 Case
  - 未向量化 chunk
  - 停用或归档知识库
  - 非白名单 tag group
  - 重复 tag
  - 未分配 Collection 的库或素材

### 8. 创建任务页图片配置

- 图库选择默认改为“不指定图库”。
- “不使用图片”保留在图片数量中。
- 这样可以区分：
  - 不指定图库：仍允许系统从可用图库中按规则配图。
  - 不使用图片：明确不为文章配置图片。

## 本轮刻意跳过的功能

### 任务回收站

未在本轮实现。原因是它会改变现有任务删除语义，并影响任务恢复、文章来源展示、调度状态、队列状态和删除权限。建议作为独立阶段开发。

### AI 知识纠错助手

未在本轮实现。原因是它涉及新表、diff UI、AI 分析、审批、重新 embedding、活动日志、回滚和文章详情页选段纠错，属于完整工作流。建议作为独立阶段开发。

## 已验证测试

- `tests/Feature/AdminTasksPageTest.php`
- `tests/Feature/AdminMaterialsPagesTest.php`
- `tests/Feature/RagRetrievalServiceTest.php`
- `tests/Feature/AdminArticlesPageTest.php`
- `tests/Feature/ArticleQualityAssessmentServiceTest.php`
- `tests/Feature/AdminCollectionsPageTest.php`
- `tests/Feature/EntityExtractionServiceTest.php`
- `tests/Feature/WorkerGenerationPipelineTraceTest.php`

## 后续建议

1. 先实现任务回收站，解决任务删除后文章保留与来源显示问题。
2. 再实现 AI 知识纠错助手，形成知识库纠错、审批、更新、重新向量化、回滚闭环。
3. 后续可继续增强治理能力，例如重复素材合并、标签命名规范建议、Collection 健康度评分。

## 2026-06-03 补充更新：治理增强与任务页整理

### 1. 受控标签分组白名单

- 新增 `controlled_tag_groups` 表。
- 新增标签管理页白名单分组维护模块。
- 支持新增、编辑、删除允许使用的标签分组。
- 素材治理检查、标签管理和创建任务页统一读取数据库白名单。
- 删除白名单分组不会删除已有标签，历史标签会在治理检查中提示为异常分组。

### 2. 创建任务页重构

- Collection 改为必选项。
- Entity 与 Case 改为可搜索多选。
- 受控分组标签改为 accordion 展示，作为可选高级筛选。
- 图片配置不再把“不指定图库”和“不配图”混在一起：
  - 不指定图库：仍允许系统自动匹配图片。
  - 不配图：明确关闭配图。
- 图片图库选择会优先呈现与已选 Entity 相关的图库。
- 统一下拉菜单、输入框、标签选择器的边框和聚焦样式。

### 3. 基础素材库与库级标签规则

- 基础素材库移除业务容器卡片，Collection 仅保留顶部菜单入口。
- 基础素材库卡片排序调整为：
  1. Entity DB
  2. 标题库
  3. 关键词库
  4. 图片库
  5. Case DB
  6. 作者库
- 关键词库和图片库移除库级标签，避免用户误解为库内素材自动继承库级标签。
- 标题库保留库级标签，因为标题素材本身没有独立 tag 字段。

### 4. 素材关联 Entity

- 素材关联 Entity 的选择器支持多选。
- 已关联 Entity 可取消关联。
- 交互样式复用标签选择器，降低学习成本。

### 5. 本次补充验证

- 已通过 `AdminMaterialsPagesTest`。
- 已通过 `AdminTasksPageTest`。
- 已通过 `RagRetrievalServiceTest`。
- 新增受控标签分组相关迁移已执行成功。
