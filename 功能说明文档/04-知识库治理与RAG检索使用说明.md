# 知识库治理与 RAG 检索使用说明

知识库用于保存生成文章时可引用的真实资料。它是 RAG 检索的主要来源。

## 知识库适合保存什么

适合保存：

- 产品手册
- FAQ
- 安装说明
- 技术参数
- 故障排查
- 竞品分析
- 约束说明
- 风格参考

不建议保存：

- 真实客户案例。案例应进入 Case DB。
- 单个关键词。关键词应进入关键词库。
- 图片说明。图片应进入图片库。

## knowledge_type 使用建议

knowledge_type 用于描述资料本身是什么。

推荐示例：

- product_manual：产品手册
- faq：常见问题
- troubleshooting：故障排查
- competitor_analysis：竞品分析
- technical_spec：技术参数
- installation_guide：安装指南
- style_reference：风格参考

注意：knowledge_type 不再使用 case_study，避免和 Case DB 重复。

## knowledge_role 使用建议

knowledge_role 用于描述生成文章时如何使用这份资料。

| role | 适用场景 | 生成时的意义 |
| --- | --- | --- |
| primary_source | 产品手册、官方参数、核心 FAQ | 作为事实依据优先引用 |
| supporting_context | 背景资料、补充说明 | 扩展内容深度 |
| constraint | 禁止事项、合规要求、技术限制 | 约束生成内容，避免乱写 |
| comparison_reference | 竞品分析、对比资料 | 用于比较和差异表达 |
| style_reference | 风格样稿、表达模板 | 参考写作风格，不作为事实来源 |
| archive | 旧资料、历史资料 | 默认不参与自动 RAG |

## 知识库状态

知识库状态会影响自动检索：

- active：正常参与检索。
- inactive：默认不参与自动检索。
- archive：默认不参与自动检索。

手动指定知识库时，系统仍允许引用 inactive 或 archive 资料，用于兼容旧任务或特殊需求。

## Entity 关联的重要性

知识库最好关联 Entity。

例如：

- SJ4060 产品手册关联 Entity：SJ4060
- 视觉灌胶机 FAQ 关联 Entity：视觉灌胶机
- 电池制造应用资料关联 Entity：电池制造

这样创建任务选择 Entity 后，RAG 可以优先找到真正相关的知识库，而不是只靠标签猜测。

## 批量治理

知识库列表支持批量操作：

- 批量分配 Collection。
- 批量追加标签。
- 批量设置 role。
- 批量关联 Entity。
- 批量归档或停用。

导入大量资料后，建议先完成这些治理动作，再进行批量生成。

## RAG 检索流程

生成文章时，系统会综合使用：

1. 当前任务 Collection。
2. 任务选择的 Entity。
3. Entity 关联知识库。
4. Entity 关联 Case。
5. 任务选择的知识库。
6. 受控标签筛选。
7. 知识库状态和 role。

最终文章会记录生成来源和检索 chunk，便于审核。

