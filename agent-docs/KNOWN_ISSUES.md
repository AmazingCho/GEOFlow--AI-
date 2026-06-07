# 已知问题与风
### 12. APP_KEY 损坏风险与 Docker 环境覆盖

**症状：** Server Error 500，日志显示 `Unsupported cipher or incorrect key length`。

**根因：** 

- `php artisan key:generate --force` 可能不会替换 `APP_KEY=` 行，而是把新 key 拼接到旧 key 后面，造成 `.env` 中出现类似 `APP_KEY=base64:OLD_KEY=base64:NEW_KEY=` 的双 key 拼接（88 字符 / 66 字节），远超 AES-256-CBC 所需的 32 字节，Laravel 加密器直接崩溃。
- Docker 容器环境变量 `APP_KEY=""`（空值）会覆盖 `.env` 中的有效 key。entrypoint 脚本检测到空值后 `unset APP_KEY`，但 PHP 测试子进程仍可能继承空值。

**修复步骤：**

1. 检查 `.env` 的 `APP_KEY` 是否只有一个 `base64:...` 值（44 字符 base64 + 1 个 padding = 32 字节）
2. 用 `sed` 直接写一行有效 key：`sed -i "s|^APP_KEY=.*|APP_KEY=base64:<32字节随机key>|" .env`
3. 重启容器：`docker restart geoflow-app`
4. 验证：`curl -s http://localhost:18080/` 应返回 200

**运行测试的正确方式：**

容器内直接跑 `php artisan test` 会因为 `APP_KEY=""` 环境变量覆盖 `.env` 而报 `MissingAppKeyException`。正确方式：

```shell
docker exec -e APP_KEY=base64:$(grep '^APP_KEY=' .env | cut -d= -f2-) \
  geoflow-app sh -c 'php artisan config:clear && php artisan test --filter=AdminCrmPagesTest'
```

险

本文档用于记录容易被上下文压缩丢失的问题。

## 高优先级

### 1. 任务回收站未实现

当前任务删除逻辑仍需谨慎。

目标状态应是：

- 删除任务不删除文章。
- 文章显示来源任务已删除。
- 任务进入回收站。
- 支持恢复或永久删除。

### 2. AI 知识库纠错助手未实现

该功能尚未落地。

目标状态应是：

- 用户可从知识库或文章详情页发起纠错。
- AI 生成 correction proposal。
- 管理员确认后才更新 knowledge chunk。
- 更新后重新 embedding。
- 保存版本并支持回滚。

### 3. CRM 仍保留轻量边界

轻量 CRM 阶段 1-7 已完成核心功能：

- 客户、联系人、跟进记录。
- 询盘与 AI 需求识别。
- 报价单与打印页。
- 订单管理。
- 售后工单。
- CRM 内容候选审核。
- 创建任务页 CRM 来源关联。

仍未实现且建议保持独立阶段：

- 报价审批。
- PDF 导出。
- 邮件发送。
- 客户活动时间线整合。

后续继续保持轻量 CRM 定位，不要引入完整 ERP 财务、库存、采购和生产排程逻辑。

### 4. 旧文章可能没有生成来源

旧流程生成的文章可能没有 trace。

如果文章编辑页看不到“生成来源”，不要直接判断为 UI bug，应先检查该文章是否有生成追踪数据。

## 中优先级

### 5. 阶段 7 性能优化需要真实数据验证

虽然已经做了多处优化，但仍需要在大量数据下检查：

- 标签远程搜索是否完整覆盖。
- 标签引用明细是否懒加载。
- 图片、关键词、知识库列表分页是否足够稳定。
- 统计数据是否有缓存。
- 大文件知识库向量化是否阻塞页面。

### 6. UI 入口可能出现后端已实现但前端不明显

之前多次出现功能已实现但页面入口不明显的情况。

每次新增功能都要检查：

- 列表页入口。
- 创建页入口。
- 编辑页入口。
- 详情页入口。
- 批量操作入口。
- 空状态提示。

### 7. 标签分组治理需要继续保持克制

不要把标签分组无限扩展。

新增标签分组前应判断：

- 是否已经可以用现有分组表达。
- 是否其实应该是 Collection。
- 是否其实应该是 Entity 类型。
- 是否其实应该是 Knowledge Base type 或 role。

不同素材自动 tag 推荐已按用户要求移除。后续不要因为看到旧阶段名称就恢复该功能，除非用户重新确认推荐规则、误标签处理和人工审核流程。

### 8. AI 表格解析已增强但仍需人工复核

`MaterialAnalysisPromptRules` 已加入表格/参数保真规则，但复杂网页表格、合并单元格、图片表格、PDF 转文本错位等场景仍可能出错。

涉及产品参数、尺寸、电压、功率、容量、价格、测试条件等内容时，入库前必须人工检查 Markdown 表格或 key-value 列表。

### 9. Skill Prompt 自动匹配尚未实现

当前 Prompt Skill System v1 支持任务页人工选择 Skill Prompt。

尚未实现：

- 根据标题自动识别 comparison / buying guide / application 等意图。
- 根据关键词 intent 自动匹配 Skill。
- AI Intent Classification。

后续开发自动匹配时，应保留人工覆盖入口。

## 低优先级

### 10. 当前项目路径容易混淆

历史工作主要在：

`/Users/leo/Desktop/GEOFlow`

当前 shell 环境有时显示：

`/Users/leo/Documents/GEOWorkflow-optimized`

但该目录可能不是完整项目。新 agent 开始写文件前必须先确认项目根目录中是否存在 `app/`、`database/`、`resources/`、`artisan`。

### 11. Docker 缓存可能导致前端刷新不生效

如果代码已改但页面无变化，先检查：

- Laravel view cache。
- config cache。
- Docker app 容器是否需要重启。
- 浏览器缓存。

### 13. OPcache `validate_timestamps=0` 导致代码改动不可见

容器 `/usr/local/etc/php/conf.d/99-opcache.ini` 的 `opcache.validate_timestamps=0` 会在本地开发挂载代码卷的场景下导致所有源文件修改被 OPcache 忽略——即使执行 `view:clear`、`config:clear`、`docker restart` 也不行。

**症状：** Blade/PHP 语法检查通过、缓存清完、容器已重启，但页面行为仍然是旧代码。

**修复（一次性）：**

```bash
docker exec geoflow-app sh -c '
  sed -i "s/opcache.validate_timestamps=0/opcache.validate_timestamps=1/" /usr/local/etc/php/conf.d/99-opcache.ini
'
docker restart geoflow-app
```

**说明：** `validate_timestamps=1` 让 PHP 检查文件 mtime 并自动重新编译——本地开发的正确配置。生产环境（代码不挂载卷）应保持 `0` 并通过重建镜像更新代码。

### 14. Eloquent Model `$fillable` 遗漏导致字段静默保存失败

Laravel mass assignment 保护会**静默丢弃**所有未在 `$fillable` 中声明的字段，`$customer->update($data)` 或 `CrmCustomer::create($data)` 不会报错，字段值直接丢失。

**症状：** 表单提交成功（无 422/500），redirect 正常，但新填的值在数据库中仍是旧的。

**排查步骤：**

1. 检查 Controller 的 `validateCustomer()` 是否包含该字段 ✅
2. 检查 `normalizeCustomerPayload()` 是否包含该字段 ✅
3. 检查 Model 的 `$fillable`（或 `$guarded`）是否包含该字段 ← 常见遗漏点
4. 确认后运行 `AdminCrmPagesTest` 验证

**高频遗漏场景：** 新增 migration 列 + 更新 Controller + 更新 View，但忘了加 Model `$fillable`。涉及 CRM 模块时尤其容易发生。
