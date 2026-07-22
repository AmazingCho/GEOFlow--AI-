# GEOFlow V2.2 去模板化与深度生成实施报告

> 日期：2026-07-21
>
> 本地源码状态：已完成
>
> 正式发布状态：No-Go，真实模型与 PM 盲审门禁未通过

## 1. PM 结论

本轮已经完成本地代码、自动化测试、桌面/移动端界面验证，以及 24 篇 DeepSeek V4 Pro 固定对照和独立 PM 盲审。系统现在同时保留低成本的标准生成和质量优先的深度生成，Style Prompt 仍是可选增强，不会增加普通任务的必填负担。

真实评估证明 Style 能形成可辨认的表达差异，但 V2.2 没有稳定守住闭集事实、高风险隐私/安全和自然结构门禁，不能上线。候选 Prompt 尚未写入业务数据库，当前 `18080` Docker 部署也没有切换到本项目目录。

## 2. 已实现内容

1. V2.2 Master 与七类 Skill 完成去模板化减法，保留事实、证据、隐私和安全边界，移除固定章节骨架和强制 FAQ、表格、Key Takeaways、Conclusion。
2. 新增四个候选 Style Prompt：Technical Clarity、Buyer Decision、Editorial Flow、Conversational Expert。Style 只控制语气、句式和段落节奏。
3. 任务新增 `generation_mode=standard|deep`，默认 `standard`，旧任务行为不变。
4. 标准模式继续单轮生成；深度模式执行 `plan -> draft -> review -> optional revision -> final review`，最多五次模型调用。
5. 深度模式只检索一次并冻结证据包；各阶段记录证据 hash、状态、模型、耗时和 token，不保存 Prompt 正文或 RAG 原文。
6. 无法解析的计划、截断输出、危险内容和阻断性审核失败不会生成文章；第二次非阻断审核仍失败时保存为待审核草稿。
7. Case Study 与 Troubleshooting 即使模型审核通过，也必须保留人工治理门禁。
8. 质量评分不再把缺少可选 FAQ 或 Conclusion 当成固定结构缺陷；深度审核作为零权重辅助项展示，不重复抬高基础总分。
9. 反模板评估新增标题骨架、开头重复、通用模块密度、段落碎片化、章节信息增量、Style 遵循和 Style 越权检查。
10. 文章编辑页可查看生成模式、Style、深度阶段、调用次数、耗时、token、审核分和问题代码。

## 3. 用户使用方式

- 普通任务保持“标准生成”，成本和速度与原流程最接近。
- 对事实要求高、结构复杂或值得精修的文章选择“深度生成”。
- 写作风格可以留空；只有希望控制表达节奏时才手动选择 Style。
- 深度审核未通过的文章会保留为待审核草稿，管理员在文章编辑页查看问题后再决定修改或发布。
- Case Study 和 Troubleshooting 不因高分自动越过人工审核。

## 4. 验证结果

### 自动测试

- 深度生成治理补充测试：4 tests / 21 assertions。
- 最终定向回归：158 tests / 1518 assertions，覆盖 Prompt、任务、API、模型调用、深度流水线、评估器、质量评分和生成追踪。
- 完整 Laravel 套件：586 passed / 4509 assertions，另有 2 个与本轮无关的历史文案基线失败：
  - `AdminWelcomeIntroCopyTest` 仍期待旧欢迎页标题。
  - `AdminMaterialsPagesTest` 仍期待基础素材页显示旧“作者管理”入口。

这两个失败对应文件不在本轮修改范围，不应为了让本轮测试变绿而改回旧产品文案。

### UI 验证

- 创建任务页：1440x1000、390x844。
- 编辑任务页：1440x1000、390x844。
- 文章质量和生成追踪页：1440x1000、390x844。
- 已确认无页面级横向溢出、原始翻译 key、控制台错误或明显卡片重叠。
- 深度审核零权重卡片显示实际审核分，例如 `78 / 100`，不再显示误导性的 `0/0`。
- 截图保存在 `output/playwright/`，仅作为本地视觉证据。

### Prompt 只读预演

隔离临时 PostgreSQL 数据库执行了：

```bash
php artisan geoflow:prompt-presets:sync --json
```

- `applied=false`
- plan fingerprint：`114f792c48d1733c3d7d4f35be32bd9b490c87c340af772c35f098ebee0c7f7a`
- 未解决冲突：`article.style.technical_clarity`

该指纹只对应隔离验证库，不能直接用于业务库 apply。业务库必须重新 preview，并由管理员选择保留本地 Style 或使用候选预设。

## 5. 真实模型与 PM 盲审结果

评估批次为 `deepseek-v4-pro-20260721-v22-phase8-r1`：

- 6 个标题完成 V2.1 / V2.2 无 Style 配对，共 12 篇。
- 3 个标题完成无 Style / Technical Clarity / Buyer Decision / Editorial Flow 对照，共 12 篇。
- 24 篇均以 `draft/pending` 私有评估草稿保存，文章 ID 81-104，未关联任务、发布时间或分发记录。
- 共发起 26 次模型请求；2 次长度截断被拒绝后重试。已知 token 用量 115,134，另有 1 次早期截断请求没有可恢复的 usage。
- 24 篇正文均唯一；标题骨架与开头重复检查均为 0/48。

盲审八项平均分：事实支持 2.46、清晰度 4.17、采购决策价值 4.21、结构自然度 3.83、不确定性处理 3.58、隐私/安全 4.33、Style 适配 4.25、去模板自然度 3.92。

发布门禁失败原因：

1. A002 重复了来源未支持的 `18 percent` 主张并补写项目细节，属于真实闭集事实失败。
2. A011 触发 Troubleshooting 安全规则；其中一条正则命中带有误报成分，但正文仍增加了来源未支持的检测和操作步骤，因此 PM 安全评分同样未达标。
3. Case Study 和 Comparison 的 V2.2 事实支持分别比 V2.1 下降 1 分。
4. 7 篇文章未达到结构自然度与去模板自然度 4/5，且 A021 证明无 Style 基线仍可能结构退化。
5. 三种代表 Style 整体可辨认，说明 Style 层本身有价值，但它不能弥补事实底座和高风险规则不足。

最终结论为 `No-Go`。这不是撤销 V2.2 本地功能，而是禁止将本轮候选 Prompt apply 到业务库或切换为正式默认。

## 6. 下一轮修正与受控发布步骤

1. 针对 Application、Case Study、Troubleshooting 收紧“通用背景”可用范围，禁止把行业常识写成当前产品、客户或项目事实。
2. 对受限词、未验证指标和客户数字增加输出后硬门禁；高风险内容不能只依赖 Prompt 自律。
3. 将 Troubleshooting 的安全检测改为风险动作与否定语境分离，降低误报，同时禁止来源之外的检测、拆卸和参数建议。
4. 保持 Style 轻量职责，不再通过增加固定章节修复结构；改进无 Style 基线的段落计划与信息增量约束。
5. 完成针对性修正后重新运行同一固定矩阵和盲审；只有全部门禁通过，才进入业务库 Prompt dry-run、冲突处理、备份和 apply。
6. 获得发布批准后，再同步实际 Docker 挂载、执行迁移、缓存清理、后台冒烟测试和回滚演练。

## 7. 回滚边界

- `generation_mode` 默认 `standard`，无需删除数据即可关闭深度生成。
- Style Prompt 外键使用 `nullOnDelete`，删除 Style 后历史任务回到无 Style。
- Prompt apply 前必须保留数据库备份和当次 plan fingerprint。
- 深度生成失败不会增加标题使用次数、任务生成数或保存残缺文章；已经完成的模型调用仍保留 usage 统计。
