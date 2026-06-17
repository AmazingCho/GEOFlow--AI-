# CRM 单据 PDF 真实样本视觉回归记录

日期：2026-06-16

## 目标

用现有 CRM 真实单据数据验证五种单据 PDF 输出，不只依赖临时测试数据。

## 样本

| 单据类型 | 使用单据 | 页数 | 结果 |
| --- | --- | ---: | --- |
| Quotation | `RT-20260612A` | 2 | 通过 |
| Proforma Invoice | `RT-20260612A` | 3 | 通过，银行页按总页数显示 |
| Commercial Invoice | `RT-20260607B` | 1 | 修复中文字体后通过 |
| Packing List | `RT-20260607B` | 1 | 通过 |
| Contract | `RT-20260612A` | 1 | 通过，页面较满但未溢出 |

## 发现的问题

Commercial Invoice 的真实备注 `客户备注信息` 在 PDF 中显示为方块。

根因：

- 容器中只有 DejaVu 字体，没有中文字体。
- Chromium 生成 PDF 时无法找到 CJK 字体 fallback。

修复：

- `docker/Dockerfile` 和 `docker/Dockerfile.prod` 安装 `fontconfig` + `fonts-wqy-zenhei`。
- `print-document.blade.php` 的打印字体栈加入 `"WenQuanYi Zen Hei"`，同时保留 `Noto Sans CJK` 和 `Microsoft YaHei` fallback。
- 当前运行中的 `geoflow-app` 容器已安装 `fonts-wqy-zenhei` 并刷新 `fc-cache`。

## 验证

- 重新生成 `RT-20260607B` Commercial Invoice PDF。
- 渲染第一页后确认 `客户备注信息` 正常显示。
- Quotation / PI / CI / PL / Contract 均可生成 PDF。
- PDF 页码与页面数量一致。
- 未发现表格重叠、页脚跑位或明显内容截断。

## 自动化命令

已新增 Artisan 命令：

```bash
php artisan crm:document-pdf-regression
```

命令能力：

- 自动选择真实 CRM 单据样本。
- 生成 Quotation、PI、Commercial Invoice、Packing List、Contract 五类 PDF。
- 输出对应 HTML、PDF、逐页 PNG 截图和 `report.md` / `report.json`。
- 校验 PDF 页数与 HTML `.page` 容器数量是否一致。
- 检查运行环境是否存在中文字体。

常用参数：

```bash
php artisan crm:document-pdf-regression --quote=19 --invoice-quote=9
php artisan crm:document-pdf-regression --output=pdf-regression
php artisan crm:document-pdf-regression --skip-screenshots
php artisan crm:document-pdf-regression --fail-on-warnings
```

最近一次真实执行：

- 输出目录：`storage/app/pdf-regression/20260616_035846/`
- 结果：五类 PDF 均生成成功，Warnings 为 None。

## 后台回归检查与视觉 Diff

已新增后台入口：

- 页面：`/admin/crm/quotes/pdf-regression`
- 导航：CRM -> 单据制作 -> PDF 回归检查
- 能力：
  - 一键生成五类真实样本回归包。
  - 查看运行记录、报告摘要、HTML / PDF / PNG / diff 产物。
  - 将成功运行设为默认视觉基线。
  - 新运行会自动与默认视觉基线做逐页截图差异比较。
  - 支持清理旧回归文件，清理范围限制在 `storage/app/pdf-regression`。

最近一次后台链路验证：

- 成功运行：`#4`
- 输出目录：`storage/app/pdf-regression/20260616_142804/`
- 状态：`completed`
- Warnings：`0`
- 已设为默认视觉基线：`crm_document_pdf_regression_baselines.name = default`

最近一次视觉 diff 验证：

- 成功运行：`#5`
- 输出目录：`storage/app/pdf-regression/20260616_143026/`
- 视觉结果：`passed`
- 五类单据截图 diff ratio：`0`
- 报价单 `RT-20260612A-quotation-page-1-of-1.png` 已确认第 7 项留在第一页，summary / terms / signature 未错位。
- PI 第二页 `RT-20260612A-proforma_invoice-page-2-of-2.png` 已确认页眉、支付摘要、提示条、签名区和页脚正常。

本地 Docker 说明：

- `geoflow-app` 容器有 `/usr/bin/node` 和 `/usr/bin/chromium`。
- `geoflow-queue` 容器当前没有 Node/Chromium。
- 因此 `GenerateCrmDocumentPdfRegressionRun` 当前固定 `onConnection('sync')`，保证后台按钮在当前部署中可用。
- 如果以后要改回异步队列，先为 queue 容器安装 Node/Chromium，再重新验证后台生成、截图和 diff。

清理验证：

```bash
php artisan crm:document-pdf-regression:prune --dry-run
```

结果：当前候选为 0，默认基线和近期运行记录未被误删。

## 后续注意

- 新环境如果中文仍显示方块，先检查容器内是否存在中文字体：

```bash
docker exec geoflow-app fc-list :lang=zh family | head
```

- 如果没有中文字体，重建镜像或在容器内安装 `fonts-wqy-zenhei` 后执行：

```bash
docker exec geoflow-app fc-cache -f
```
