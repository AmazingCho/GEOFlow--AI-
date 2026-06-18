# Entity-to-Entity 关联 — CRM 侧价值及实现清单

> 状态：长期增强规划 / 部分基础能力已存在。`entity_relations` 与 `relation_types` 基础表、Entity 编辑页手动关系入口已存在；本文描述的 CRM 侧自动推荐、单据明细联动、订单建议行、售后关联卡片等能力不要视为已完成，也不要在没有用户明确确认时一次性执行。当前已完成状态以 [IMPLEMENTATION_STATUS.md](./IMPLEMENTATION_STATUS.md) 为准。

本文档基于 `entity关联强化思路.txt` 中的路线，详细列出 Entity-to-Entity 关系表建成后，CRM 管线每一步的具体价值、触发时机和实现细节。

---

## 前提假设

以下全部内容依赖于两个前置建成：

1. `entity_relations` 表：source_entity_id、relation_type_id、target_entity_id、strength（0-100）、source_chunk_id（可空）
2. `relation_types` 表：name、forward_label、reverse_label、bidirectional、description

Entity 编辑页已有"关联 Entity"区域，用户可手动添加（如 SJ4060 → uses → PU Resin，strength=95）。后续可接 AI 自动推荐，但本期不做。

---

## 一、询盘 AI 需求识别（Inquiry Analysis）

### 触发时机

用户粘贴客户邮件/聊天记录，点击"AI 需求识别"。

### 当前行为

AI 分析原文后，推荐已有的 Entity、Knowledge Base 和 Case。推荐基于名称匹配 + 嵌入相似度，只推荐原文中直接提到的 Entity。

### 增强后行为

AI 推荐某个 Entity 后，系统自动遍历该 Entity 的**一度关系**（source 和 target 双向），展示为"关联推荐"：

```
AI 识别结果：
  核心实体：SJ4060（产品型号）

关联推荐（基于 Entity 关系）：
  PU Resin    — SJ4060 uses PU Resin（strength=95）
  Sticker Doming — SJ4060 suitable_for Sticker Doming（strength=80）
  Color-Dec 6150GP — SJ4060 competes_with Color-Dec 6150GP（strength=70）
```

用户可一键勾选，将关联实体也加入询盘。

### 实现细节

```
文件：CrmInquiryAnalysisService.php

1. AI 分析完成后，获取推荐 entity_ids
2. 对每个推荐 entity_id，查询 entity_relations：
   SELECT * FROM entity_relations 
   WHERE source_entity_id IN (推荐 entity_ids) OR target_entity_id IN (推荐 entity_ids)
   ORDER BY strength DESC
3. 对结果去重（同一 target 不重复出现）
4. 前端展示时，如果当前查看的 entity 是 source，显示 forward_label + target
   如果当前查看的 entity 是 target，显示 reverse_label + source
5. 默认展示 strength >= 60 的关系，低于此值折叠为"更多关联"
```

### 前端位置

询盘编辑/创建页，"AI 需求识别"结果区域。在推荐的 Entity 列表下方新增"关联推荐"卡片，与现有推荐样式一致。

---

## 二、单据制作 — 明细行关联推荐

### 触发时机

用户在单据制作页的明细中，选择某个 Entity 关联到单行后。

### 当前行为

选择 Entity 后，自动预填 item_name 和 description。仅此一行。

### 增强后行为

选择 Entity 后，系统检查该 Entity 是否有 strength >= 80 的"依赖"或"配套"关系，在明细行下方显示提示条：

```
💡 SJ4060 常用配套：
  PU Resin（耗材）— 添加为明细行
  Dehumidifier（设备）— 添加为明细行
```

点击"添加为明细行"自动插入一行，预填对应 Entity 的型号和描述。

### 实现细节

```
文件：quotes/form.blade.php（JS 事件） + CrmQuoteController.php

1. 明细行 Entity select 的 change 事件：
   - 获取当前 entity_id
   - fetch API 请求：GET /admin/crm/quotes/entity-relations/{entityId}
   - 后端查询 entity_relations，过滤：
     (source_entity_id = entityId AND relation_type 属于 uses/requires/compatible_with)
     OR (target_entity_id = entityId AND relation_type 属于 used_by/required_by/compatible_with)
   - 按 strength DESC 排序，取前 5 条
2. 前端展示提示条，每条带"添加"按钮
3. 点击"添加"：克隆 template 行，填入 entity_id、model、item_name、description
4. 支持关闭提示条、不再提示等轻量 UX
```

### 前端位置

明细行下方，每行的 Entity select 右侧或下方弹出行内提示，不遮挡现有控件。

---

## 三、单据转订单 — 关联耗材自动带入

### 触发时机

用户在报价详情页点击"生成订单"，或列表页点击"创建订单"。

### 当前行为

复制 Quote 的客户、Collection、所有明细行到新 Order。

### 增强后行为

创建订单时，遍历 Quote 的所有明细行中关联的 Entity，自动补充：

1. 常用耗材（如 SJ4060 uses PU Resin）：如果 Quote 中没有，在 Order 明细中追加一行，quantity=0（标记为"建议行"），用户可自行调整数量或删除
2. 关联设备（如 PU Resin requires Dehumidifier）：同上

### 实现细节

```
文件：CrmSalesOrderController.php — fromQuote() 方法

1. 创建 Order 后，插入 items（现有逻辑）
2. 新增步骤：遍历 items 的 entity_id
3. 对每个 entity_id，查询 entity_relations 中 strength >= 80 的 uses/requires/compatible_with
4. 检查这些关联 entity 是否已在 Order items 中出现
5. 未出现的，追加一行（quantity=0，line_type=建议行），标记 notes 字段注明来源关系
6. 返回 Order 编辑页时，建议行有视觉区分（虚线边框、灰色文字）
```

### 前端位置

Order 编辑页 / 详情页的明细表中，建议行用浅灰底色或 `line_type = '建议行'` 的标识区分。

---

## 四、售后工单 — 关联实体 + 知识库自动推荐

### 触发时机

用户在工单页选择了核心 Entity（`entity_id`）后。

### 当前行为

选择核心 Entity 后，无联动。

### 增强后行为

选择核心 Entity 后，系统自动查询该 Entity 的关系网络，推荐：

1. **关联知识库**：查询 Entity 的 `entity_material_links` 中 linkable_type=KnowledgeBase 的记录，按 confidence 排序展示
2. **关联实体**（材料/部件/竞品）：查询 entity_relations，按关系类型分组展示

```
📋 SJ4060 关联信息：
  知识库：
    SJ4060 产品详情知识库（主资料）
    PU Resin 树脂知识库（辅助参考）
  关联实体：
    PU Resin — 使用材料
    Color-Dec 6150GP — 竞品
    Dehumidifier — 依赖设备
```

用户可一键将推荐的知识库和关联 Entity 加入工单。

### 实现细节

```
文件：CrmAfterSalesTicketController.php — edit()/show() 方法 / tickets/form.blade.php

1. 工单编辑页，Entity select 的 change 事件中：
   - 获取 entity_id
   - fetch API：GET /admin/crm/tickets/entity-context/{entityId}
   - 后端返回：
     a. knowledge_bases（来自 entity_material_links，按 confidence 排序）
     b. related_entities（来自 entity_relations，按 strength 分组）
2. 前端在 Entity select 下方展示"关联信息"卡片
3. 每个推荐项带"加入工单"按钮，点击后自动选入对应的多选组件
```

### 前端位置

工单创建/编辑页，"核心 Entity"选择器下方，折叠式"关联信息"卡片。

---

## 五、AI 工单分析增强

### 触发时机

与询盘 AI 需求识别类似，粘贴售后问题原文后点击"AI 工单分析"。

### 当前行为

AI 推荐关联知识库、Case、核心 Entity。

### 增强后行为

与询盘相同逻辑：推荐 Entity 后，遍历一度关系展示"关联推荐"。此外，如果 AI 推荐的知识库中有 chunk 提到了某 entity，反过来推荐该 entity 的关联方。

### 实现细节

复用车轮——与询盘 AI 需求识别共用同一个 `EntityRelationResolver` 服务类。

---

## 六、实体关系可视化（Entity Detail 页在 CRM 侧的入口）

### 触发时机

CRM 各模块详情页中，任何地方展示 Entity 名称时（询盘关联 Entity、报价明细 Entity、工单核心 Entity），点击 Entity 名称。

### 当前行为

跳转到 Entity 编辑页。

### 增强后行为

在跳转的目标 Entity 编辑页/详情页中，新增"关系网络"标签页或 section，展示：

- 当前 Entity 为中心的关系链路图（Mermaid 方向图）
- 关系按类型分组，每组按 strength 排序
- 每条关系可追溯到来源（手动创建 / AI 推荐 / 来自哪个知识库 chunk）

### 实现细节

```
文件：entities/form.blade.php 或新增 partial

1. Entity 编辑页增加"关系网络"section
2. 渲染 Mermaid 图：source_entity → relation_label → target_entity
3. 下方表格列出所有关系，带 strength、创建来源、创建时间
4. 支持从 Entity 详情页跳转到关联 Entity 的详情页
```

---

## 优先级矩阵

| 模块 | 复杂度 | 价值 | 优先级 |
|------|--------|------|--------|
| 询盘 AI 需求识别关联推荐 | 中 | 高（直接减少遗漏） | P0 |
| 单据明细行关联推荐 | 中 | 高（提高报价完整性） | P0 |
| 售后工单关联信息卡片 | 低 | 中（提高排查效率） | P1 |
| 单据转订单耗材自动带入 | 低 | 中（减少人工劳动） | P1 |
| AI 工单分析增强 | 低（复用） | 中 | P1 |
| Entity 关系可视化 | 中 | 低（Nice to have） | P2 |

---

## 开发依赖

全部 CRM 侧功能依赖以下后端基础设施：

1. `entity_relations` 表 + `relation_types` 表（新建）
2. `EntityRecord` 模型中新增 `entityRelations()` 和 `reverseRelations()` 方法
3. `EntityRelationResolver` 服务类：统一封装查询逻辑（根据 entity_id 返回正反向关系的结构化数组）
4. 两个轻量 API 路由：
   - `GET /admin/crm/quotes/entity-relations/{entityId}` — 单据明细用
   - `GET /admin/crm/tickets/entity-context/{entityId}` — 售后工单用
