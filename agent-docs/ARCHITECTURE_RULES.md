# GEOFlow 架构规则

这些规则用于防止后续开发重新把 Collection、Entity、Tag、Knowledge Base 和 Case 的职责混在一起。

## 1. Collection 是顶层业务容器

Collection 用于划分业务线或内容资产边界。

示例：

- Automation Equipment
- Industrial Cooling
- Color Sorting
- Lighting

不要用标签承担 Collection 职责。

## 2. Entity 是知识索引节点

Entity 用于表示系统需要长期识别和引用的对象。

示例：

- SJ4060
- DJ771
- PU Resin
- Color-Dec
- Battery Manufacturing
- Wafer Processing

Entity 字段本身不需要承载完整资料。完整上下文来自 Entity 关联的知识库、案例、关键词和图片。

## 3. Tag 描述可复用属性

Tag 只描述属性，不做顶层容器。

允许的标签分组由受控白名单管理，推荐分组：

- Topic
- Audience
- Intent

不要创建无限标签分组。

产品型号、产品线、材料、竞品、行业/应用场景等长期知识对象优先使用 Entity。资料类型优先使用知识库“资料用途”或 knowledge_type。业务线优先使用 Collection。

## 4. Knowledge Base 保存丰富来源资料

知识库用于保存可被 RAG 引用的真实资料。

适合：

- product manual
- FAQ
- competitor analysis
- troubleshooting guide
- technical spec
- installation guide

不使用 `case_study` 作为 knowledge_type，避免和 Case DB 重复。

## 5. Case DB 保存真实案例

Case 用于真实客户故事、应用场景、项目结果和案例数据。

Case 可以关联多个 Entity。

## 6. 文章生成不依赖 Entity 字段本身

生成文章时，Entity 只负责定位相关上下文。

真正的上下文来自：

- Entity-linked Knowledge Bases
- Entity-linked Cases
- Entity-linked Keywords
- Entity-linked Images
- Collection general knowledge
- 用户在任务中手动选择的素材

## 7. 不为每种 Entity 类型创建独立表

Entity 类型通过共享字段和 flexible attributes JSON 表达。

不要为产品型号、产品线、行业、材料等分别创建新表。

## 8. 所有改动保持增量和向后兼容

不要替换现有功能。

新增功能应尽量 additive，旧任务、旧文章、旧素材应继续可访问。

## 9. 数据库和生成流程改动必须加测试

涉及以下内容时必须补充测试：

- 数据库迁移。
- RAG 检索。
- 文章生成流程。
- Entity / Case 关联。
- 标签治理。
- 知识库 role / status。
- 质量评分。

## 10. 库级标签只对标题库生效

关键词和图片素材本身已经有标签。

关键词库和图片库不应再使用库级标签，避免用户误以为库内所有素材隐式继承库级标签。

标题素材本身没有独立 tag 字段，因此标题库可以保留库级标签。
