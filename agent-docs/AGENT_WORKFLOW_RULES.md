# Agent 工作规则

这些规则用于保证换 agent、上下文压缩或隔天继续时不丢进度。

## 开始任何开发前

默认不要全量读取文档。

低 token 启动时先读取：

1. [AGENT_BRIEF.md](./AGENT_BRIEF.md)
2. [DOC_READ_POLICY.md](./DOC_READ_POLICY.md)

只有当前任务需要阶段细节、风险细节或架构判断时，再读取：

- [AGENT_HANDOFF.md](./AGENT_HANDOFF.md)
- [IMPLEMENTATION_STATUS.md](./IMPLEMENTATION_STATUS.md)
- [KNOWN_ISSUES.md](./KNOWN_ISSUES.md)
- [ARCHITECTURE_RULES.md](./ARCHITECTURE_RULES.md)

如果涉及具体功能，再读取 [FEATURE_DOC_INDEX.md](./FEATURE_DOC_INDEX.md) 中对应的功能说明。

## 开发中必须检查

1. 是否破坏 Collection / Entity / Tag / Knowledge Base / Case 的职责边界。
2. 是否需要数据库迁移。
3. 是否需要新增或更新测试。
4. 是否需要补充前端入口。
5. 是否需要更新功能说明文档。
6. 是否会影响旧任务、旧文章或旧素材。

## 每完成一个阶段后

不要机械更新所有文档。按影响范围更新：

- 进度变化：[IMPLEMENTATION_STATUS.md](./IMPLEMENTATION_STATUS.md)
- 新缺陷或风险：[KNOWN_ISSUES.md](./KNOWN_ISSUES.md)
- 架构规则变化：[ARCHITECTURE_RULES.md](./ARCHITECTURE_RULES.md)
- 接手摘要变化：[AGENT_BRIEF.md](./AGENT_BRIEF.md) 和 [AGENT_HANDOFF.md](./AGENT_HANDOFF.md)

如果改变了用户操作流程，还必须更新 `功能说明文档/` 中对应文档。

## 状态记录格式

在 [IMPLEMENTATION_STATUS.md](./IMPLEMENTATION_STATUS.md) 中记录：

- 功能名称。
- 状态：未开始、进行中、已完成、部分完成、跳过。
- 涉及文件。
- 测试结果。
- 剩余风险。

## 缺陷记录格式

在 [KNOWN_ISSUES.md](./KNOWN_ISSUES.md) 中记录：

- 问题现象。
- 影响范围。
- 是否有临时解决方式。
- 建议修复顺序。

## 不要做的事

- 不要只把阶段计划写在聊天里。
- 不要新增无限标签分组。
- 不要让标签承担 Collection 职责。
- 不要恢复不同素材自动 tag 推荐，除非用户重新确认。
- 不要把 Case 内容混入 Knowledge Base type。
- 不要为每个 Entity 类型创建独立表。
- 不要在未确认旧数据兼容前改变删除语义。

## 文件编辑铁律（避免已知高频 Bug）

以下模式在本项目中已多次导致 500 错误、静默丢失代码、重复内容等严重 Bug。**严禁使用。**

### 禁止 1：用 `cat >>` 追加代码到 PHP 文件末尾

`cat >> file.php << 'EOF'` 会把代码写到 class 闭合大括号 `}` 之后，PHP 解析直接报语法错误。

**正确做法：** 用 `apply_patch` 或 Python 脚本在 class 内部精确插入。如果必须追加方法，先 `sed -i '' '$d'` 删掉最后的 `}`，追加代码，再补回 `}`。

### 禁止 2：Python 字符串替换中包含未转义的反斜杠

`\\App\\Services\\...` 在 Python 字符串里是 `\\\\App\\\\Services\\\\...`（四个反斜杠），写成两个会导致替换失败或匹配到错误文本。同样的，`\n`、`\t` 等转义序列在 `'''` 多行字符串中会生效，造成意外的换行。

**正确做法：** 涉及 PHP 命名空间时，Python 字符串用四个反斜杠。或者避免在 Python 中使用包含大量反斜杠的替换模式——改用 `sed` 处理简单的单行替换。

### 禁止 3：依赖 Python replace 做精确匹配

`str.replace(old, new)` 要求 `old` 字符串和文件内容逐字符匹配，包括空白符、换行符。稍微差一个空格就会静默跳过，不做任何替换也不报错——代码丢失而不自知。

**正确做法：** 替换后必须 grep 验证目标行是否存在。特别是替换 PHP 方法定义时，替换完必须确认方法名在文件中仍然存在。

### 禁止 4：对同一个文件连续多次独立 patch

先跑一个 Python 脚本替换 A，再跑第二个脚本替换 B，第二个脚本可能在第一次替换后的 text 基础上找不到匹配模式——因为第一个替换已经改变了文本内容。导致部分 patch 被跳过。

**正确做法：** 合并所有改动到一个脚本中，按"从后往前"的顺序替换（后面内容的位置不受前面替换的影响），或每次替换后重新读取文件。

### 禁止 5：创建新 PHP 类后不跑 `composer dump-autoload`

新增 Model、Service、Controller 后，Laravel 的 PSR-4 autoloader 不会自动发现新文件。类找不到时直接 500。

**正确做法：** 创建新 PHP 文件后，在容器内执行 `composer dump-autoload`。

### 禁止 6：修改 Blade partial 的 `@push('scripts')` 时忽略父模板

如果 partial 通过 `@include` 嵌入到父模板中，partial 内的 `@push('scripts')` 会叠加到父模板的 `@stack('scripts')`。如果 partial 中使用了父模板的变量（如 `$isEdit`），必须通过 `@include` 传参，否则 Blade 编译时变量未定义报 500。

### 禁止 7：修改 Blade 后用 `php -l` 检查 PHP 语法但不检查 Blade 编译

`php -l file.blade.php` 只能检查纯 PHP 语法，不能检查 Blade 指令（`@if`、`@php`、`@push` 等）的编译结果。Blade 编译错误只在页面访问时才会暴露。

**正确做法：** 通过 `php artisan test --filter=EntityPageTest` 或 `php artisan tinker --execute="view('...')->render()"` 实际编译一次 Blade 模板来验证。

