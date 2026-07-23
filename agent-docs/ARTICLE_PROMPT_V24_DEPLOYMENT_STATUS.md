# Article Prompt V2.4 部署状态

## 当前状态

2026-07-24，用户明确决定停止继续付费 A/B 测试，将已完成相对对照的候选
Master / Intent Skill 直接同步到本地 Docker 业务库，并在后续真实文章任务中
人工审核有效性。

这是一项有意识的运行决策，不改变此前测试结论：Technical 2.4.1 相对旧版有
改善，但稀疏闭卷资料下仍可能补充未确认的通用内部架构。因此当前允许用于生成
草稿，不代表批准跳过文章事实审核或自动发布。

## 已部署版本

| Preset | Version |
| --- | --- |
| `article.master.trust_based` | `2.4.0` |
| `article.skill.comparison` | `2.4.0` |
| `article.skill.buying_guide` | `2.4.0` |
| `article.skill.application` | `2.4.0` |
| `article.skill.technical` | `2.4.1` |
| `article.skill.troubleshooting` | `2.4.0` |
| `article.skill.case_study` | `2.4.0` |
| `article.skill.definition` | `2.4.0` |

同步采用 `geoflow:prompt-presets:sync` 的预览、计划指纹、私有备份和数据库事务，
原有 Prompt ID 保持不变，没有创建重复记录。

## 备份

应用前备份：

`storage/app/private/prompt-preset-backups/20260724-023434-c92e6be3/`

目录权限为 `0700`，备份文件权限为 `0600`。备份包含：

- 全部 Prompt 快照；
- Task 与 Master / Skill / Style 的引用关系；
- 标题库与 Prompt 的引用关系；
- 操作 manifest 和文件哈希。

## 应用后验证

- 再次执行同步预览，8 个目标项均为 `unchanged / Already synchronized`；
- 业务文章数保持为 52，没有生成测试文章；
- 后台 `/admin/` 正常响应；
- app、queue、scheduler、reverb、PostgreSQL、Redis 容器均正常运行；
- 本次没有调用 LLM，也没有自动发布或分发文章。

## 后续人工审核重点

真实任务中优先检查：

1. Technical 是否把通用设备结构写成当前产品的确认结构；
2. Buying Guide / Application 是否补充知识库没有提供的数字、标准或材料；
3. Case Study 是否保留隐私和发布许可边界；
4. 内容是否比旧版更直接、更少模板化，同时没有牺牲事实准确性。

除非用户再次明确授权，不要自动恢复付费 A/B 循环，也不要自动回退到 2.0.0。
