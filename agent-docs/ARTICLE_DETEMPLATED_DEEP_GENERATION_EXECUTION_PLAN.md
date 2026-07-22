# GEOFlow 去模板化与深度文章生成执行计划

> 状态：本地源码、自动测试、UI 验证、24 篇真实模型对照与 PM 盲审均已完成；正式发布仍为 No-Go
>
> 日期：2026-07-21
>
> 执行原则：先完成 Prompt 减法与 Style 分层，再增加深度生成；任何付费模型复测、业务库 Prompt apply 和 Docker 源码切换都必须单独确认。

实施结果与剩余门禁见 `agent-docs/ARTICLE_DETEMPLATED_DEEP_GENERATION_IMPLEMENTATION_REPORT.md`。真实模型评估已经执行，但事实支持、结构自然度和高风险内容门禁未通过；未勾选项现在仅代表未获批准的业务库写入或实际部署，不代表本地实现缺失。

## 1. 产品目标

在不降低事实准确性、RAG 证据约束、隐私、安全和 GEO 可提取性的前提下，让文章的结构、段落节奏和表达方式服从当前标题与素材，而不是服从固定的 Skill 章节模板。

目标流程：

```text
冻结本次证据包
  -> 根据标题与证据动态策划
  -> 使用 Master + Intent Skill + 可选 Style 生成正文
  -> 确定性检查 + AI 内容审核
  -> 仅在未达标时局部修正一次
  -> 保存草稿和脱敏生成追踪
```

## 2. PM 决策与边界

### 2.1 采用的方案

- 方案 2 是底层：精简 Master/Skill，复用现有可选 Style Prompt。
- 方案 3 是增强层：新增 `standard|deep` 两种生成模式。
- `standard` 保留现有单轮流程，确保兼容历史任务和低成本场景。
- `deep` 执行策划、写作、审核，并在必要时局部修正。
- 新任务初期仍默认 `standard`；真实模型门禁通过后，再决定是否把 UI 推荐项改为 `deep`。

### 2.2 明确不做

- 不开发 URL 抓取、作者识别或自动风格蒸馏。
- 不自动模仿具体在世作者。
- 不随机选择 Style，不做自动 Style 推荐。
- 不把 Intent Skill、Style Prompt 和 HTML Layout Skill 合并。
- 不要求每篇文章必须包含 FAQ、表格、Checklist、Key Takeaways 或 Conclusion。
- 不自动重写历史文章。
- 不在生成追踪中保存 Prompt 正文、完整 RAG 内容、客户隐私或模型密钥。

## 3. 分层职责

| 层级 | 唯一职责 | 不得负责 |
|---|---|---|
| Master Prompt | 事实、证据、隐私、安全、通用质量 | 固定文章章节 |
| Runtime | 目标语言、正文格式、上下文注入、输出完整性 | 决定文章观点 |
| Intent Skill | Comparison、Buying Guide 等特有推理 | 通用写作风格和固定标题模板 |
| Style Prompt | 语气、句子节奏、段落密度、转折和用词 | 改写事实规则、强制模块 |
| Dynamic Plan | 当前文章角度、章节目标、证据映射 | 创造来源中不存在的事实 |
| Reviewer | 发现质量与风格偏差 | 直接添加事实或静默发布 |

冲突按职责处理，而不是简单采用“最后一个 Prompt 覆盖前面”的规则。Style 永远不能削弱事实、隐私和安全边界。

## 4. 数据与代码架构

### 4.1 复用现有能力

- `prompts.type=style`
- `tasks.style_prompt_id`
- `skill_selection_mode=none|manual|auto`
- `WorkerExecutionService` 的 RAG、模型选择、完整性检查和生成追踪
- `PromptPresetSyncService` 的预览、备份、冲突处理和指纹门禁
- `ArticleSkillOutputEvaluator` 的语言、章节、隐私、安全和 PM 评分
- `task_runs.meta` 的脱敏流水线追踪

### 4.2 最小新增数据

在 `tasks` 增加：

```text
generation_mode varchar(20) default 'standard'
```

允许值只有：

- `standard`
- `deep`

不新增文章计划表、Style 表或审核结果表。动态计划和审核摘要只在当次执行中使用；只把 hash、阶段状态、评分和问题代码写入 `task_runs.meta.generation_trace`。

### 4.3 建议新增服务

- `app/Support/GeoFlow/ArticleGenerationModes.php`
  - 统一定义、校验和兼容 `standard|deep`。
- `app/Services/GeoFlow/ArticleModelCallService.php`
  - 从 Worker 提取模型候选、failover、usage 统计、finish reason 和阶段调用追踪。
  - 标准模式与深度模式必须共用，避免两套模型调用逻辑。
- `app/Services/GeoFlow/DeepArticleGenerationService.php`
  - 编排 plan -> draft -> review -> conditional revision -> final review。
  - 不负责 RAG，不负责保存 Article，不直接修改 Task。
- `app/Services/GeoFlow/ArticleDeepOutputValidator.php`
  - 校验策划 JSON、审核 JSON、结构指标和允许的问题代码。

`WorkerExecutionService` 仍负责标题、素材、RAG、图片、文章持久化和任务计数，只根据 `generation_mode` 选择标准或深度正文生成器。

## 5. 分阶段执行清单

## Phase 0：冻结基线与发布门禁

**修改文件**

- `agent-docs/ARTICLE_PROMPT_SKILLS_PHASE6_EVALUATION_REPORT.md`
- `agent-docs/IMPLEMENTATION_STATUS.md`

**清单**

- [x] 记录当前 V2.1 Master、六个 V2.1 Skill、Technical V2.0 的版本和 SHA-256。
- [x] 保留现有 20 篇配对文章，不覆盖、不删除。
- [x] 固定后续 DeepSeek V4 Pro 测试参数：temperature、max tokens、模型配置 hash 和证据包 hash。
- [x] 将发布结论保持为 `No-Go`，直到 Phase 8 的真实模型审核通过。
- [x] 明确当前运行 Docker 仍挂载 `/Users/leo/Desktop/GEOFlow`，计划实施期间不自动切换。

**验收**

- 基线可重现；没有数据库写入和付费调用。

## Phase 1：V2.2 Prompt 减法与职责消歧

**修改文件**

- `database/seeders/data/prompt_presets_v2.php`
- `tests/Unit/PromptSkillContractTest.php`
- `tests/Unit/WorkerExecutionServicePromptTest.php`

**清单**

- [x] Master 从约 1,189 词压缩至 750-900 词。
- [x] 普通 Skill 控制在 250-400 词。
- [x] Case Study 和 Troubleshooting 控制在 400-550 词。
- [x] 保留 V2.1 闭集证据、安全、隐私和错误产品信息隔离规则。
- [x] 删除 Skill 中重复的通用事实、GEO、语气和输出格式规则。
- [x] 将编号式推理链标注为内部决策逻辑，禁止直接复制为正文标题。
- [x] 移除固定章节顺序、固定模块组合和示例标题骨架。
- [x] 继续禁止正文 H1 和未解析变量。
- [x] 将候选版本升级为 V2.2，但不 apply 到业务库。

**自动验收**

- 恰好七个 canonical Skill。
- Master + 任一 Skill 不超过约 1,450 英文词。
- 高风险规则关键词仍完整存在。
- Prompt 中没有固定 `Introduction -> Key Takeaways -> FAQ -> Conclusion` 链路。

## Phase 2：动态结构规则与反模板契约

**修改文件**

- `database/seeders/data/prompt_presets_v2.php`
- `app/Services/GeoFlow/WorkerExecutionService.php`
- `tests/Unit/PromptSkillContractTest.php`
- `tests/Unit/WorkerExecutionServicePromptTest.php`

**清单**

- [x] Runtime 明确“结构服从标题、证据形态和读者决策”。
- [x] 默认使用自然正文；主题发生实质变化时才新增标题。
- [x] 特殊模块通常选择 0-2 个，复杂内容可例外，但不得为了丰富版式强行使用。
- [x] 不强制 Conclusion；完整回答后允许自然结束。
- [x] 不为单句、重复总结或无新信息创建标题。
- [x] 表格只用于真实可比数据；证据不对称时改为正文说明。
- [x] 不使用随机结构种子，保证相同输入仍可追踪。

**自动验收**

- 契约测试能够拒绝强制模块和固定章节链。
- 现有语言、H1、完整结尾和 failover 测试继续通过。

## Phase 3：受控 Style Prompt 体系

**修改文件**

- `database/seeders/data/prompt_presets_v2.php`
- `app/Services/GeoFlow/PromptPresetCatalog.php`（仅在 Style 契约校验需要时）
- `tests/Unit/PromptSkillContractTest.php`
- `tests/Feature/PromptPresetSyncCommandTest.php`
- `tests/Feature/PromptPresetSeederTest.php`

**首批 Style**

1. `Technical Clarity`
2. `Buyer Decision`
3. `Editorial Flow`
4. `Conversational Expert`

**清单**

- [x] 每个 Style 只描述语气、句式、段落节奏、转折、开头/结尾偏好和词汇边界。
- [x] Style 不包含固定 H2、必选表格、事实指令、RAG 指令或安全规则。
- [x] Style 不使用具体作者姓名或“模仿某人”的描述。
- [x] Style 保持行业中立，可由管理员复制后自定义。
- [x] Prompt 同步继续保留管理员本地修改冲突门禁。
- [x] 不自动修改用户已有私有 Style Prompt。

**验收**

- 四个默认 Style 可被安全预览和安装。
- Style 与 Master/Skill 组合后没有职责冲突。
- 删除 Style 后，历史任务通过 `nullOnDelete` 回到无 Style，不影响任务。

## Phase 4：创建任务页与数据兼容

**修改文件**

- `database/migrations/2026_07_21_040000_add_generation_mode_to_tasks.php`
- `app/Support/GeoFlow/ArticleGenerationModes.php`
- `app/Models/Task.php`
- `app/Http/Controllers/Admin/TaskController.php`
- `app/Services/GeoFlow/TaskLifecycleService.php`
- `app/Services/GeoFlow/TaskMonitoringQueryService.php`
- `resources/views/admin/tasks/create.blade.php`
- `lang/zh_CN/admin.php`
- `lang/en/admin.php`
- `tests/Feature/AdminTasksPageTest.php`
- `tests/Feature/ApiV1ContractTest.php`

**清单**

- [x] 新增“生成策略”：标准生成、深度生成。
- [x] 默认值为 `standard`，旧任务迁移后行为完全不变。
- [x] `deep` 放在高级配置中，并标注“质量优先，耗时和 Token 较高”。
- [x] “写作风格（可选）”继续默认不指定。
- [x] 选中 Style 后显示简短用途说明，不展示整段 Prompt。
- [x] 保留现有 Prompt 管理页入口，不在任务页增加自定义大文本框。
- [x] API 创建/读取任务同步支持 `generation_mode`，非法值返回验证错误。
- [x] 桌面和移动端检查边框、密度、折叠状态和文字换行。

**验收**

- 标准模式提交字段与旧请求兼容。
- 深度模式能正确保存、编辑、复制和 API 返回。
- Style 仍是可选项，不增加必填负担。

## Phase 5：抽取统一模型调用服务

**修改文件**

- `app/Services/GeoFlow/ArticleModelCallService.php`
- `app/Services/GeoFlow/WorkerExecutionService.php`
- `tests/Feature/WorkerExecutionServiceMaxTokensTest.php`
- `tests/Feature/WorkerGenerationPipelineTraceTest.php`

**清单**

- [x] 将模型候选、固定/智能切换、每日限额和 usage 统计迁移到统一服务。
- [x] 标准模式只做行为等价重构，不改变生成 Prompt 和保存逻辑。
- [x] 每次阶段调用记录 `stage`、model ID、状态、finish reason、耗时和 token 使用；不记录密钥和 Prompt 正文。
- [x] 截断调用仍计入 usage，但不得保存残缺文章。
- [x] 智能切换仍可在主模型截断时尝试备用模型。
- [x] 先让现有标准模式全部回归通过，再开始深度模式。

**验收**

- 现有 Worker 相关测试零行为回归。
- 标准模式生成追踪与现有字段兼容。

## Phase 6：深度生成流水线

**修改文件**

- `app/Services/GeoFlow/DeepArticleGenerationService.php`
- `app/Services/GeoFlow/ArticleDeepOutputValidator.php`
- `app/Services/GeoFlow/WorkerExecutionService.php`
- `tests/Unit/ArticleDeepOutputValidatorTest.php`
- `tests/Feature/WorkerDeepGenerationPipelineTest.php`
- `tests/Feature/WorkerGenerationPipelineTraceTest.php`

**策划阶段输出**

- `reader_question`
- `central_answer`
- `article_angle`
- `section_goals[]`
- `evidence_mapping[]`
- `optional_modules[]`
- `unsupported_claims_to_avoid[]`
- `open_questions[]`

**清单**

- [x] RAG 只检索一次并冻结证据包，所有阶段使用同一 hash。
- [x] 策划阶段只返回结构化 JSON，不写正文。
- [x] 策划中的每个章节目标必须映射证据或明确标记为通用解释/待确认。
- [x] 写作阶段同时接收计划和原始证据，不能把计划当作事实来源。
- [x] 审核阶段只输出评分、问题代码和局部修正指令，不生成新事实。
- [x] 审核通过则进入图片和保存流程。
- [x] 审核失败最多局部修正一次，然后重新审核一次。
- [x] 第二次仍未通过时保留草稿价值，但强制 `review_status=pending`，禁止自动发布，并在质量区域显示问题。
- [x] 截断、危险内容或无法解析的策划结果不保存草稿，返回可操作错误。
- [x] Case Study 和 Troubleshooting 无论自动评分如何都保留现有人工治理门禁。
- [x] 每个阶段记录 hash、状态、模型尝试和非敏感评分，不保存完整计划、完整审核 Prompt 或 RAG 正文。

**调用上限**

- 正常通过：3 次，plan + draft + review。
- 首次审核失败：最多 5 次，增加 revision + final review。
- 单篇不得无限循环。

## Phase 7：反模板评估器与质量门禁

**修改文件**

- `app/Services/GeoFlow/ArticleSkillOutputEvaluator.php`
- `app/Services/GeoFlow/ArticleSkillEvaluationCatalog.php`
- `tests/Unit/ArticleSkillOutputEvaluatorTest.php`
- `tests/Unit/ArticleSkillEvaluationCatalogTest.php`
- `tests/Feature/ArticleSkillEvaluationCommandTest.php`

**新增检测**

- [x] 同 Skill 多篇文章的标准化标题骨架相似度。
- [x] Introduction、Key Takeaways、FAQ、Conclusion 等通用模块重复率。
- [x] 单句章节和异常标题密度。
- [x] 开头句式重复度。
- [x] 段落长度分布是否极端碎片化。
- [x] Style 遵循度。
- [x] Style 是否越权改变事实、安全或隐私边界。
- [x] 计划章节是否都提供了新信息，而不是同义改写。

这些指标只用于评估和发布门禁，不直接机械控制正文结构。

**PM 评分继续保留**

- factual support
- clarity
- buyer decision value
- structure naturalness
- uncertainty and negative fit
- privacy and safety
- style fitness（新增）
- non-template naturalness（新增）

## Phase 8：真实模型对照、UI 验证与受控发布

### 8.1 付费测试范围

- [x] 6 个标题执行 V2.1 与 V2.2 无 Style 配对：12 篇。
- [x] 3 个标题执行无 Style + 三种代表 Style：12 篇。
- [x] 共 24 篇，固定 DeepSeek V4 Pro、temperature=0、max output tokens=4096、语言和证据包。
- [x] PM 盲审时使用 `A001-A024` 匿名编号，隐藏 Prompt 版本和 Style 名称。

执行批次：`deepseek-v4-pro-20260721-v22-phase8-r1`。共发生 26 次请求，其中 2 次因 `finish_reason=length` 被拒绝并重试；已知 token 用量为 115,134，另有 1 次早期截断请求未返回可恢复的 usage，因此真实总量略高。24 篇文章以 `draft/pending` 私有评估草稿保存，文章 ID 为 81-104，未关联任务、发布时间或分发记录。

### 8.2 发布阈值

- [x] 已评估自动事实、语言、隐私和安全检查：**未通过**，A002 与 A011 触发门禁。
- [x] 已对比每篇事实支持是否不低于 V2.1：**未通过**，Case Study 与 Comparison 各下降 1 分。
- [x] 已评估结构自然度至少 4/5：**未通过**，A004、A005、A007、A018、A021、A022、A023 未达标。
- [x] 已评估 non-template naturalness 至少 4/5：**未通过**，失败编号与结构门禁相同。
- [x] Style 版本在不查看名称时仍能被人工识别出明显表达差异：**通过**，三种代表 Style 均满足整体辨识门槛。
- [x] 已评估无 Style 基线是否保持结构：**未通过**，A021 退化为结构不足。
- [x] 已评估 Case Study、Troubleshooting 的隐私和安全至少 4/5：**未通过**，A002 与 A011 未达标。
- [x] 任一门禁失败则保持 `No-Go`，不得正式 apply：**已执行**。

盲审八项平均分：事实支持 2.46、清晰度 4.17、采购决策价值 4.21、结构自然度 3.83、不确定性处理 3.58、隐私/安全 4.33、Style 适配 4.25、去模板自然度 3.92。结论不是“Style 无效”，而是“Style 已产生可辨认差异，但事实与安全底座尚不足以支持发布”。

### 8.3 前端检查

- [x] 创建任务页：1440x1000、390x844。
- [x] 任务编辑页：标准/深度与 Style 回显正确。
- [x] 文章详情页：显示生成模式、各阶段状态、Style 和质量问题。
- [x] 无横向溢出、双层边框、原始翻译 key 或卡片嵌套冲突。

### 8.4 正式同步

- [x] 在隔离验证库执行 Prompt 同步只读 preview。
- [x] 审核隔离库冲突和 plan fingerprint；业务库发布前必须重新 preview。
- [ ] 备份 Prompt 后按批准范围 apply。
- [ ] 将本项目源码同步到实际 Docker 挂载目录或重新配置容器挂载。
- [ ] 执行迁移、缓存清理和后台冒烟测试。
- [ ] 保留 V2.1 内容和数据库备份，验证回滚命令。

## 6. 测试命令

宿主机没有 PHP，统一使用隔离 Docker：

```bash
docker run --rm --entrypoint php \
  -e APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= \
  -v "$PWD":/var/www/html -w /var/www/html \
  geoflow-app:latest artisan test \
  tests/Unit/PromptSkillContractTest.php \
  tests/Unit/WorkerExecutionServicePromptTest.php \
  tests/Feature/AdminTasksPageTest.php \
  tests/Feature/ApiV1ContractTest.php \
  tests/Feature/WorkerExecutionServiceMaxTokensTest.php \
  tests/Feature/WorkerDeepGenerationPipelineTest.php \
  tests/Feature/WorkerGenerationPipelineTraceTest.php \
  tests/Unit/ArticleSkillOutputEvaluatorTest.php \
  tests/Feature/PromptPresetSyncCommandTest.php
```

每个 Phase 必须先运行新增测试确认失败，再实现最小代码使其通过；阶段结束后运行相关回归，Phase 8 前运行完整 Laravel 测试。

## 7. 回滚策略

- `generation_mode` 默认 `standard`，关闭深度生成无需删除数据。
- Style 删除使用现有 `nullOnDelete`，任务自动回到无 Style。
- V2.2 使用受控 Prompt sync，不直接 Seeder 覆盖业务库。
- Prompt apply 前保存数据库备份与 plan fingerprint。
- 深度流水线失败不修改标题使用次数、任务生成计数或文章表，只有最终持久化事务成功后才计数。
- 已产生的模型调用必须保留 usage 统计，即使正文因截断或安全问题被拒绝。

## 8. 最终完成定义

只有同时满足以下条件，才能宣布本规划完成：

1. 标准模式无回归。
2. 深度模式完整执行且有调用上限。
3. V2.2 的事实质量不低于 V2.1。
4. 同类文章的结构重复度明显下降。
5. 至少三种 Style 能产生可辨识但不越权的表达差异。
6. 失败内容不会被自动发布。
7. 真实模型、PM 和移动/桌面 UI 三类审核全部通过。
8. 更新实施状态、Prompt 使用说明和中英文更新日志。
