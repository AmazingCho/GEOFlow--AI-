# Entity-to-Entity 关联功能 — 实现清单

本文档是 Entity-to-Entity 关系功能的完整实现计划，不包含 CRM 侧的联动逻辑（CRM 侧见另一份文档）。

---

## 一、数据库层

### 1.1 `relation_types` 表

新建 migration，创建实体关系类型表。

```sql
CREATE TABLE relation_types (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(50) NOT NULL UNIQUE,          -- 唯一标识，如 'uses'
    forward_label VARCHAR(50) NOT NULL,        -- 正向显示，如 'Uses'
    reverse_label VARCHAR(50) NOT NULL,        -- 反向显示，如 'Used By'
    bidirectional TINYINT DEFAULT 0,          -- 双向关系标记（如 competes_with）
    description VARCHAR(200) DEFAULT '',
    sort_order INT DEFAULT 0,
    created_at TIMESTAMP,
    updated_at TIMESTAMP
);
```

**预置种子数据**（seeder，匹配 GEOFlow 的 10 种实体类型）：

| name | forward_label | reverse_label | bidirectional | 适用场景 |
|---|---|---|---|---|
| uses | Uses（使用） | Used By（被使用） | 0 | 产品→材料/部件 |
| requires | Requires（依赖） | Required By（被依赖） | 0 | 材料→工艺/设备 |
| compatible_with | Compatible With（兼容） | Compatible With（兼容） | 1 | 产品↔产品、部件↔部件 |
| competes_with | Competes With（竞品） | Competes With（竞品） | 1 | 产品↔竞品 |
| suitable_for | Suitable For（适用） | Suited By（被适用） | 0 | 产品→应用场景 |
| belongs_to | Belongs To（归属） | Contains（包含） | 0 | 产品→产品线 |
| manufactured_by | Manufactured By（制造商） | Manufactures（产品） | 0 | 产品→品牌/公司 |
| sold_to | Sold To（目标客户） | Purchased By（购买方） | 0 | 产品→目标客户 |
| causes | Causes（导致） | Caused By（由…导致） | 0 | 材料→问题 |
| solves | Solves（解决） | Solved By（由…解决） | 0 | 产品/工艺→应用场景 |

### 1.2 `entity_relations` 表

新建 migration。

```sql
CREATE TABLE entity_relations (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    source_entity_id BIGINT NOT NULL,          -- 源实体
    relation_type_id BIGINT NOT NULL,          -- 关系类型
    target_entity_id BIGINT NOT NULL,          -- 目标实体
    strength TINYINT DEFAULT 80,               -- 关系强度 0-100
    source_chunk_id BIGINT DEFAULT NULL,       -- 可追溯来源（远期）
    source_type VARCHAR(20) DEFAULT 'manual',  -- manual / ai / import
    notes VARCHAR(500) DEFAULT '',
    created_at TIMESTAMP,
    updated_at TIMESTAMP,

    FOREIGN KEY (source_entity_id) REFERENCES entities(id) ON DELETE CASCADE,
    FOREIGN KEY (relation_type_id) REFERENCES relation_types(id) ON DELETE CASCADE,
    FOREIGN KEY (target_entity_id) REFERENCES entities(id) ON DELETE CASCADE,

    UNIQUE KEY unique_entity_relation (source_entity_id, relation_type_id, target_entity_id)
);
```

**说明：**
- `source_chunk_id`：远期用于追溯"这条关系来自哪段知识库原文"，首期留空
- `source_type`：manual（手动添加）/ ai（AI 自动推荐）/ import（批量导入）
- `strength`：0-100 数值，用于排序和筛选，默认 80
- UNIQUE 约束防止重复添加相同方向的关系

**Migration 文件命名：** `2026_06_XX_000000_create_entity_relations.php`

---

## 二、模型层

### 2.1 新增 `RelationType` Model

```php
// app/Models/RelationType.php
class RelationType extends Model
{
    protected $fillable = ['name', 'forward_label', 'reverse_label', 'bidirectional', 'description', 'sort_order'];

    public function scopeOrdered($query) { ... }
}
```

### 2.2 `EntityRecord` Model 新增方法

在现有 `EntityRecord` 中追加三个关系查询方法：

```php
// app/Models/EntityRecord.php 追加

public function sourceRelations(): HasMany
{
    return $this->hasMany(EntityRelation::class, 'source_entity_id')->with('relationType');
}

public function targetRelations(): HasMany
{
    return $this->hasMany(EntityRelation::class, 'target_entity_id')->with('relationType');
}

// 一次性获取正反双向关系（用于详情页展示）
public function allRelations(): Collection
{
    $source = $this->sourceRelations()->get();
    $target = $this->targetRelations()->get();
    return $source->concat($target);
}
```

### 2.3 新增 `EntityRelation` Model

```php
// app/Models/EntityRelation.php
class EntityRelation extends Model
{
    protected $fillable = [
        'source_entity_id', 'relation_type_id', 'target_entity_id',
        'strength', 'source_chunk_id', 'source_type', 'notes',
    ];

    public function sourceEntity(): BelongsTo { ... }
    public function targetEntity(): BelongsTo { ... }
    public function relationType(): BelongsTo { ... }
}
```

---

## 三、业务逻辑层

### 3.1 `EntityRelationService` 服务类

封装核心查询逻辑，避免 Controller 臃肿：

```php
// app/Services/GeoFlow/EntityRelationService.php

public function relationTypes(): Collection
// 返回所有 relation_types，按 sort_order 排序

public function relatedEntities(int $entityId, array $options = []): array
// 核心方法：给定 entity_id，返回结构化的关系数据
// 返回格式：
// [
//   'as_source' => [[entity, relation_type, strength, reverse_label], ...],
//   'as_target' => [[entity, relation_type, strength, forward_label], ...],
// ]
// $options: 'min_strength' => 60, 'limit' => 20

public function addRelation(int $sourceId, int $typeId, int $targetId, int $strength = 80, array $extra = []): EntityRelation
// 创建关系，UNIQUE 冲突时更新 strength 和 notes

public function removeRelation(int $relationId): void
// 删除关系，级联无关（只有一条记录，不存在双向同步问题）

public function bulkSuggest(int $entityId): array
// 远期：根据现有关系网络，推荐可能缺失的关联（如 transitive 推理）
```

---

## 四、控制器层

### 4.1 Entity 列表页 — 增加实体类型筛选

**文件：** `app/Http/Controllers/Admin/EntityController.php` — `index()` 方法

```
1. 新增 query 参数：entity_type（可选）
2. if ($entityType !== '' && in_array($entityType, EntityTypes::values())) {
       $query->where('entity_type', $entityType);
   }
3. 新增 entity_type 传参到 view
```

**前端：** index 页搜索栏增加一个实体类型 `<select>`（值取自 `EntityTypes::values()`），放在搜索框右侧。

### 4.2 Entity 列表页 — 增加状态筛选（依赖 § 5.1 状态字段 migration）

```
1. 新增 query 参数：status（可选）
2. if ($status !== '') { $query->where('status', $status); }
3. 「核心实体」快捷视图：?status=core 预设链接
```

### 4.3 Entity 编辑页 — 新增「关联 Entity」区域

**文件：** `app/Http/Controllers/Admin/EntityController.php` — `edit()` / `update()` 方法

**edit() 需传参：**
```
'relationTypes' => RelationType::ordered()->get(),
'relatedEntities' => $entity->allRelations(),
'entityOptions' => $this->entityOptionsForRelation($collectionId, $entity->id), // 可关联的候选实体列表
```

**update() 需处理：**
```
// 解析表单提交的关系数据
$relations = json_decode($request->input('entity_relations', '[]'), true);
// 对比现有关系，新增/更新/删除
$this->entityRelationService->syncRelations($entity->id, $relations);
```

### 4.4 API 路由（轻量查询接口）

CRM 侧和 Entity 编辑页的 Entity 搜索框会用到：

```
// routes/web.php
Route::get('entities/search', [EntityController::class, 'search'])
    ->name('entities.search');

Route::get('entities/{entityId}/relations', [EntityController::class, 'relations'])
    ->name('entities.relations');
```

- `search`：接受 `?q=` 和 `?collection_id=`，返回 JSON `[{id, name, entity_type}]`，用于前端关联选择器的远程搜索
- `relations`：返回 JSON 格式的关系列表，用于异步加载 Entity 详情页的关系面板

---

## 五、前端层

### 5.1 Entity 列表页

**改动 1：搜索栏增加实体类型下拉**

在现有搜索框和标签筛选之间，增加一个 `<select name="entity_type">`，选项来自后端 `EntityTypes::values()`。

**改动 2：状态筛选（依赖 § 5.1 状态字段）**

一个简单的标签式切换：`全部 | 核心 | 普通 | 已废弃`，点击带 `?status=core` 参数。

### 5.2 Entity 编辑页

在现有 `material-links` 区域下方，新增「关联 Entity」section：

```
┌─────────────────────────────────────────────┐
│ 关联 Entity                                 │
│ 定义当前实体与其他实体之间的业务关系。      │
│                                             │
│ [选择目标 Entity  ▼]  [关系类型 ▼]  [强度] │
│                                         [添加]│
│                                             │
│ ▸ SJ4060 — uses → PU Resin (95) ✕          │
│ ▸ SJ4060 — suitable_for → Sticker Doming (80)│
│ ▸ PU Resin — Used By → SJ4060 (auto)       │
└─────────────────────────────────────────────┘
```

**实现方式：** 仿照现有的 `relation-multi-selector` 组件模式。关键差异是：
- 目标候选是其他 Entity（不是 KnowledgeBase），需要 Entity 远程搜索
- 关系类型是 `relation_types` 而非 `knowledge_relation_type`
- 每条关系展示时，根据查询方向自动显示正确的 label

**行内显示逻辑（已保存的关系列表）：**

```
// 伪代码
for each relation:
  if relation.source_entity_id == current_entity.id:
    label = relation.relationType.forward_label  // "Uses"
    other = relation.target_entity               // PU Resin
  else:
    label = relation.relationType.reverse_label  // "Used By"
    other = relation.source_entity               // SJ4060

  渲染: "other.name — label — strength" + 删除按钮
```

### 5.3 Entity 详情/展示页

暂无独立的 Entity 详情页（当前 Entity 只有列表和编辑页）。如果后续需要详情页（如从 CRM 侧点击 Entity 名称跳转），可在编辑页底部增加只读的「关系网络」面板：

- 当前 Entity 的关系以表格列出（按关系类型分组）
- 可选增加 Mermaid 方向图（远期）

### 5.4 Entity 状态字段（补充，作为同步改动）

**Migration：** `entities` 表增加 `status VARCHAR(20) DEFAULT 'normal'`

**编辑页：** 在"实体类型" select 下方增加"状态"select：
```
选项：core（核心实体）| normal（普通实体）| deprecated（已废弃）
```

**列表页：** 标签式快速筛选入口；核心实体在列表中加蓝色标记。

---

## 六、lang 翻译键

需要新增的翻译键（`zh_CN` / `en`）：

| 键 | 中文 | 英文 |
|---|---|---|
| `admin.entities.field_entity_relations` | 关联 Entity | Entity Relations |
| `admin.entities.entity_relations_desc` | 定义与其他实体的业务关系 | Define business relations with other entities |
| `admin.entities.relation_type` | 关系类型 | Relation Type |
| `admin.entities.relation_strength` | 关系强度 | Strength |
| `admin.entities.entity_relation_add` | 添加关联 | Add Relation |
| `admin.entities.entity_relation_remove` | 移除 | Remove |
| `admin.entities.filter_entity_type` | 实体类型 | Entity Type |
| `admin.entities.filter_status` | 状态 | Status |
| `admin.entities.status_core` | 核心实体 | Core |
| `admin.entities.status_normal` | 普通实体 | Normal |
| `admin.entities.status_deprecated` | 已废弃 | Deprecated |

---

## 七、实施顺序

按依赖关系，分为 4 个批次：

**批次 1（基础设施，无前端）：**
1. `relation_types` migration + seeder
2. `entity_relations` migration
3. `RelationType` Model
4. `EntityRelation` Model
5. `EntityRecord` Model 新增 sourceRelations / targetRelations / allRelations

**批次 2（业务逻辑 + API）：**
6. `EntityRelationService` 服务类
7. API 路由：Entity 搜索 + 关系查询

**批次 3（前端）：**
8. Entity 编辑页新增「关联 Entity」section
9. Entity 列表页增加实体类型筛选
10. Entity 状态字段 migration + 编辑页 + 列表页筛选

**批次 4（测试 + 文档）：**
11. `AdminCrmPagesTest` 补充 Entity 关系相关断言
12. 更新 agent-docs 和功能说明文档
13. Git commit + tag

---

## 八、与现有架构的兼容性

| 现有组件 | 影响 |
|---------|------|
| `entity_material_links` | 不冲突，两套独立系统。Entity↔Material 用于知识关联，Entity↔Entity 用于业务关系 |
| `EntityRecord` Model | 只追加方法，不修改现有 fillable/relations |
| `EntityController` | 只追加代码，不删改现有逻辑 |
| 前端 Blade partial | 在 `material-links` 下方新增 section，不改变现有布局 |
| CRM 模块 | 首期不联动，后续通过 `EntityRelationService` 独立调用 |

**首期不做的：**
- AI 自动推荐 Entity Relation（第二期）
- AI 找实体（自然语言搜索，远期）
- Entity ↔ Chunk 绑定（远期，满足 5 个条件时才启动）
- Mermaid 关系可视化图（远期）
