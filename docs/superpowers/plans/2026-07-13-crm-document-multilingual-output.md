# CRM Document Multilingual Output Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add English, Simplified Chinese, Russian, and Spanish system-label output to all CRM document formats without translating or duplicating business data.

**Architecture:** Add one explicit `CrmDocumentLocale` support class backed by four `crm_document.php` language catalogs. Controllers, Blade print partials, Excel export, PDF generation, and regression rendering resolve the output language independently of the admin UI locale and consume the same catalog.

**Tech Stack:** Laravel, PHP 8, Blade, Carbon, PhpSpreadsheet, Chromium/Puppeteer PDF renderer, PHPUnit/Laravel feature tests.

## Global Constraints

- Supported language codes are exactly `en`, `zh_CN`, `ru`, and `es`.
- Query parameter `language` overrides the saved default for one output only and never writes to the database.
- Product, customer, seller, bank, term, note, amount, currency, model, SKU, HS Code, and unit values remain byte-for-byte unchanged.
- Only fixed system labels, fixed notices, document titles, pagination text, and displayed dates are localized.
- No database migration, duplicated print template, AI translation, language version table, or editable translation admin is added.
- Existing document pagination and monetary calculations must remain unchanged.
- Unknown query languages fall back to the saved language; unknown saved languages fall back to English.

---

### Task 1: Central document locale catalog

**Files:**
- Create: `app/Support/GeoFlow/CrmDocumentLocale.php`
- Create: `lang/en/crm_document.php`
- Create: `lang/zh_CN/crm_document.php`
- Create: `lang/ru/crm_document.php`
- Create: `lang/es/crm_document.php`
- Create: `tests/Unit/CrmDocumentLocaleTest.php`

**Interfaces:**
- Produces: `CrmDocumentLocale::options(): array<string,string>`
- Produces: `CrmDocumentLocale::supported(): array<int,string>`
- Produces: `CrmDocumentLocale::resolve(?string $requested, ?string $stored): string`
- Produces: `CrmDocumentLocale::labels(string $language): array<string,string>`
- Produces: `CrmDocumentLocale::documentTitles(string $language): array<string,string>`
- Produces: `CrmDocumentLocale::text(string $language, string $key, string $fallback = '', array $replace = []): string`
- Produces: `CrmDocumentLocale::formatDate(mixed $date, string $language): string`
- Produces: `CrmDocumentLocale::htmlLang(string $language): string`

- [ ] **Step 1: Write locale contract tests**

Create tests that assert:

```php
$this->assertSame(['en', 'zh_CN', 'ru', 'es'], CrmDocumentLocale::supported());
$this->assertSame('ru', CrmDocumentLocale::resolve('ru', 'en'));
$this->assertSame('zh_CN', CrmDocumentLocale::resolve('xx', 'zh_CN'));
$this->assertSame('en', CrmDocumentLocale::resolve('xx', 'xx'));
$this->assertSame('Коммерческое предложение', CrmDocumentLocale::documentTitles('ru')['quotation']);
$this->assertSame('Factura proforma', CrmDocumentLocale::documentTitles('es')['proforma_invoice']);
$this->assertSame('zh-CN', CrmDocumentLocale::htmlLang('zh_CN'));
```

Load each catalog and assert the sorted key sets for `document_titles` and `labels` equal the English key sets.

- [ ] **Step 2: Run the locale test and confirm failure**

Run:

```bash
KEY=$(docker exec geoflow-app grep '^APP_KEY=' /var/www/html/.env | cut -d= -f2-)
docker exec -e APP_KEY="$KEY" geoflow-app php artisan test tests/Unit/CrmDocumentLocaleTest.php --stop-on-failure
```

Expected: failure because `CrmDocumentLocale` and the catalogs do not exist.

- [ ] **Step 3: Implement the four catalogs and support class**

Each catalog must contain the same top-level keys. The English catalog defines the exact contract:

```php
return [
    'language_name' => 'English',
    'document_titles' => [
        'quotation' => 'Quotation',
        'proforma_invoice' => 'Proforma Invoice',
        'invoice' => 'Commercial Invoice',
        'packing_list' => 'Packing List',
        'contract' => 'Contract',
    ],
    'labels' => [
        'document_type' => 'Document Type',
        'output_language' => 'Output Language',
        'document_no' => 'Document No.',
        'number' => 'No.',
        'packing_no' => 'Packing No.',
        'invoice_no' => 'Invoice No.',
        'date' => 'Date',
        'valid_until' => 'Valid Until',
        'validity' => 'Validity',
        'currency' => 'Currency',
        'trade_term' => 'Trade Term',
        'lead_time' => 'Lead Time',
        'origin' => 'Origin',
        'items' => 'Items',
        'item' => 'Item',
        'description' => 'Description',
        'description_goods' => 'Description of Goods',
        'model' => 'Model',
        'sku_model' => 'SKU / Model',
        'hs_code' => 'HS Code',
        'qty' => 'Qty',
        'package_qty' => 'Pkg Qty',
        'unit' => 'Unit',
        'unit_price' => 'Unit Price',
        'amount' => 'Amount',
        'package_count' => 'Packages',
        'net_weight' => 'Net Weight',
        'gross_weight' => 'Gross Weight',
        'volume_cbm' => 'Volume CBM',
        'net_weight_short' => 'N.W. (kg)',
        'gross_weight_short' => 'G.W. (kg)',
        'package_size_cm' => 'Pkg Size (cm)',
        'cbm' => 'CBM',
        'summary' => 'Summary',
        'note' => 'Note',
        'notes' => 'Notes',
        'subtotal' => 'Items Subtotal',
        'shipping' => 'Shipping Fee',
        'freight_shipping' => 'Freight / Shipping',
        'discount' => 'Discount',
        'tax' => 'Tax',
        'total_invoice_value' => 'Total Invoice Value',
        'grand_total' => 'Grand Total',
        'payment_terms' => 'Payment Terms',
        'delivery_terms' => 'Delivery Terms',
        'warranty_terms' => 'Warranty Terms',
        'installation_terms' => 'Installation Terms',
        'packing_terms' => 'Packing',
        'terms_conditions' => 'Terms & Conditions',
        'standard_export_wooden_case' => 'Standard export wooden case',
        'seller' => 'Seller',
        'buyer' => 'Buyer',
        'name' => 'Name',
        'signature' => 'Signature',
        'authorized_signature' => 'Authorized Signature',
        'exporter_seller' => 'Exporter / Seller',
        'importer_buyer' => 'Importer / Buyer',
        'buyer_importer' => 'Buyer / Importer',
        'seller_info' => 'Seller Info',
        'shipper_seller' => 'Shipper / Seller',
        'consignee_buyer' => 'Consignee / Buyer',
        'buyer_customer' => 'Buyer / Customer',
        'commercial_info' => 'Commercial Info',
        'company' => 'Company',
        'tax_id' => 'Tax ID',
        'contact' => 'Contact',
        'phone' => 'Phone',
        'address' => 'Address',
        'country' => 'Country',
        'tel' => 'Tel',
        'email' => 'Email',
        'shipping_information' => 'Shipping Information',
        'port_loading' => 'Port of Loading',
        'port_destination' => 'Port of Destination',
        'transport' => 'Transport',
        'package_summary' => 'Package Summary',
        'total_packages' => 'Total Packages',
        'total_net_weight' => 'Total Net Weight',
        'total_gross_weight' => 'Total Gross Weight',
        'total_volume' => 'Total Volume',
        'shipment' => 'Shipment',
        'shipping_mark' => 'Shipping Mark',
        'port_info' => 'Port Info',
        'loading' => 'Loading',
        'destination' => 'Destination',
        'packaging_note' => 'Package type: Export-grade wooden case. All dimensions and weights are for customs clearance and logistics reference.',
        'declaration' => 'Declaration',
        'declaration_text' => 'The above information is true and correct. Goods are of Chinese origin unless otherwise stated.',
        'bank_account' => 'Bank Account',
        'bank_account_title' => 'BANK ACCOUNT',
        'bank_wire_transfer' => 'Bank Account for Wire Transfer',
        'make_payment_to' => 'Make Payment To',
        'bank_details_final_page' => 'Bank details on the final page',
        'beneficiary' => 'Beneficiary',
        'bank_name' => 'Bank Name',
        'account_no' => 'Account No.',
        'bank_code' => 'Bank Code',
        'branch_code' => 'Branch Code',
        'swift' => 'SWIFT',
        'bank_address' => 'Bank Address',
        'payment_summary' => 'Payment Summary',
        'invoice_total' => 'Invoice Total',
        'deposit_required' => 'Deposit Required (:percent%)',
        'balance_before_shipment' => 'Balance Before Shipment (:percent%)',
        'remittance_note' => 'Please include :reference in your remittance reference.',
        'contract_terms' => 'Contract Terms',
        'governing_law' => 'Governing Law',
        'dispute_resolution' => 'Dispute Resolution',
        'contract_review_notice' => 'This contract template is a commercial draft and should be reviewed before sending.',
        'download_pdf' => 'Download PDF',
        'generating' => 'Generating...',
        'items_continued' => 'Items continued',
        'summary_terms' => 'Summary & Terms',
        'page_of' => 'Page :current of :total',
        'document_page_of' => ':title · Page :current of :total',
    ],
];
```

The complete label key set must cover: document metadata, buyer/seller panels, item headers, packing/logistics fields, totals, terms, contract notices, signatures, bank fields, payment summary, remittance notice, document controls, continuation headings, page templates, and fixed declaration/packing text.

Implement explicit locale loading through Laravel's translator, English key fallback, placeholder replacement using `:name` tokens, Carbon date formatting, and HTML language mapping.

- [ ] **Step 4: Run lint and locale tests**

Run:

```bash
docker exec geoflow-app php -l app/Support/GeoFlow/CrmDocumentLocale.php
docker exec geoflow-app php -l lang/en/crm_document.php
docker exec geoflow-app php -l lang/zh_CN/crm_document.php
docker exec geoflow-app php -l lang/ru/crm_document.php
docker exec geoflow-app php -l lang/es/crm_document.php
KEY=$(docker exec geoflow-app grep '^APP_KEY=' /var/www/html/.env | cut -d= -f2-)
docker exec -e APP_KEY="$KEY" geoflow-app php artisan test tests/Unit/CrmDocumentLocaleTest.php --stop-on-failure
```

Expected: all syntax checks and unit tests pass.

### Task 2: Resolve output language consistently

**Files:**
- Modify: `app/Http/Controllers/Admin/CrmQuoteController.php`
- Modify: `app/Services/GeoFlow/CrmDocumentPdfRegressionService.php`
- Modify: `tests/Feature/AdminCrmPagesTest.php`

**Interfaces:**
- Consumes: all Task 1 `CrmDocumentLocale` methods.
- Produces: print view variables `documentLanguage`, `documentLabels`, `documentTitles`, and `languageOptions`.

- [ ] **Step 1: Add failing feature tests for four-language validation and temporary override**

Add tests that:

```php
$response = $this->actingAs($admin, 'admin')->get(route('admin.crm.quotes.print', [
    'quoteId' => $quote->id,
    'type' => 'quotation',
    'language' => 'ru',
]));
$response->assertOk()->assertSee('Коммерческое предложение')->assertSee('Покупатель / Заказчик');
$this->assertSame('en', $quote->fresh()->document_language);
```

Also assert `ru` and `es` can be saved, `fr` is rejected during save, and `language=fr` falls back to the saved language.

- [ ] **Step 2: Run focused tests and confirm failure**

Run:

```bash
KEY=$(docker exec geoflow-app grep '^APP_KEY=' /var/www/html/.env | cut -d= -f2-)
docker exec -e APP_KEY="$KEY" geoflow-app php artisan test tests/Feature/AdminCrmPagesTest.php --filter='multilingual' --stop-on-failure
```

Expected: failure because validation and rendering only support English and Chinese.

- [ ] **Step 3: Integrate the locale resolver**

In `CrmQuoteController`:

- Replace the hard-coded form language options with `CrmDocumentLocale::options()`.
- Validate with `Rule::in(CrmDocumentLocale::supported())`.
- Resolve `language` for print, PDF, and Excel using `CrmDocumentLocale::resolve($request->query('language'), $quote->document_language)`.
- Pass the resolved language, labels, titles, and options to print views.
- Preserve `language` in PDF failure redirects.
- Remove the controller's duplicated `documentLabels()` method.

In `CrmDocumentPdfRegressionService`, use `CrmDocumentLocale` and remove its duplicated label map. Preserve existing default behavior while accepting an explicit language for feature verification.

- [ ] **Step 4: Run controller/service lint and focused tests**

Run:

```bash
docker exec geoflow-app php -l app/Http/Controllers/Admin/CrmQuoteController.php
docker exec geoflow-app php -l app/Services/GeoFlow/CrmDocumentPdfRegressionService.php
KEY=$(docker exec geoflow-app grep '^APP_KEY=' /var/www/html/.env | cut -d= -f2-)
docker exec -e APP_KEY="$KEY" geoflow-app php artisan test tests/Feature/AdminCrmPagesTest.php --filter='multilingual' --stop-on-failure
```

Expected: language validation and resolution tests pass.

### Task 3: Replace hard-coded print labels and add language switcher

**Files:**
- Modify: `resources/views/admin/crm/quotes/partials/print-document.blade.php`
- Modify: `resources/views/admin/crm/quotes/partials/print-header.blade.php`
- Modify: `resources/views/admin/crm/quotes/partials/print-buyer-commercial.blade.php`
- Modify: `resources/views/admin/crm/quotes/partials/print-items.blade.php`
- Modify: `resources/views/admin/crm/quotes/partials/print-summary.blade.php`
- Modify: `resources/views/admin/crm/quotes/partials/print-terms.blade.php`
- Modify: `resources/views/admin/crm/quotes/partials/print-final-content.blade.php`
- Modify: `resources/views/admin/crm/quotes/partials/print-contract-terms.blade.php`
- Modify: `resources/views/admin/crm/quotes/partials/print-invoice-logistics.blade.php`
- Modify: `resources/views/admin/crm/quotes/partials/print-packing-summary.blade.php`
- Modify: `resources/views/admin/crm/quotes/partials/print-pl-shipment.blade.php`
- Modify: `resources/views/admin/crm/quotes/partials/print-signature.blade.php`
- Modify: `resources/views/admin/crm/quotes/form.blade.php`
- Modify: `tests/Feature/AdminCrmPagesTest.php`

**Interfaces:**
- Consumes: resolved view variables from Task 2.
- Produces: four-language HTML used unchanged by browser print and PDF renderer.

- [ ] **Step 1: Add failing template coverage tests**

For Chinese, assert previously missed labels are Chinese and the unintended English forms are absent. For Russian and Spanish, assert titles, buyer panel, terms heading, summary, bank labels, page text, and document controls are translated while sample values such as `Test Bank`, `123456`, `SJ4060 System`, and `60% deposit` remain unchanged.

- [ ] **Step 2: Run focused template tests and confirm failure**

Run:

```bash
KEY=$(docker exec geoflow-app grep '^APP_KEY=' /var/www/html/.env | cut -d= -f2-)
docker exec -e APP_KEY="$KEY" geoflow-app php artisan test tests/Feature/AdminCrmPagesTest.php --filter='document_language' --stop-on-failure
```

Expected: failures identify remaining hard-coded English and `$isZh` branches.

- [ ] **Step 3: Refactor the shared print template and partials**

- Derive the title from `$documentTitles[$documentKind]`.
- Define `$label` with replacement support and `$formatDate` through `CrmDocumentLocale`.
- Set `<html lang>` through `CrmDocumentLocale::htmlLang()`.
- Add `DejaVu Sans` to the document font stack.
- Add a non-printing output-language select next to the document-type select.
- Preserve both `type` and `language` in every preview/PDF URL.
- Replace every fixed English/Chinese branch with a catalog key.
- Replace hard-coded `Origin: China` with the localized Origin label and the stored `origin_country`, falling back to the existing `China` value only when empty.
- Localize JavaScript-generated page labels through translated `page_of` and `document_page_of` templates.
- Leave all record values untouched.

In the edit form, add the fixed explanatory note below the language select.

- [ ] **Step 4: Scan for remaining binary language logic and unintended hard-coded labels**

Run:

```bash
rg -n '\$isZh\s*\?|\bisZh\s*\?' resources/views/admin/crm/quotes/partials/print-*.blade.php
rg -n '>Buyer / Customer<|>Terms &amp; Conditions<|>BANK ACCOUNT<|>Payment Summary<|>Total Invoice Value<' resources/views/admin/crm/quotes/partials/print-*.blade.php
```

Expected: no matches.

- [ ] **Step 5: Lint Blade files, clear caches, and run template tests**

Run PHP lint for every modified Blade file, then:

```bash
docker exec geoflow-app php artisan optimize:clear
docker exec geoflow-app sh -c 'rm -f /var/www/html/storage/framework/views/*.php'
KEY=$(docker exec geoflow-app grep '^APP_KEY=' /var/www/html/.env | cut -d= -f2-)
docker exec -e APP_KEY="$KEY" geoflow-app php artisan test tests/Feature/AdminCrmPagesTest.php --filter='document_language' --stop-on-failure
```

Expected: all focused template tests pass.

### Task 4: Localize Excel output without changing data values

**Files:**
- Modify: `app/Http/Controllers/Admin/CrmQuoteController.php`
- Modify: `tests/Feature/AdminCrmPagesTest.php`

**Interfaces:**
- Consumes: `CrmDocumentLocale` and the resolved request language.
- Produces: localized workbook headers with unchanged cell values.

- [ ] **Step 1: Add an Excel localization test**

Request Excel with `language=es`, load the workbook with PhpSpreadsheet, and assert `Cotización`, `Fecha`, `Cliente`, and `Importe` appear while product and bank/customer values remain unchanged.

- [ ] **Step 2: Run the test and confirm failure**

Run the focused Excel test and expect English/Chinese-only output.

- [ ] **Step 3: Replace Excel `$isZh` branches**

Use the resolved locale catalog for title, metadata, item headers, logistics headers, subtotal, shipping, discount, and grand total. Use the existing data values and numeric formats unchanged.

- [ ] **Step 4: Run lint and the Excel test**

Expected: workbook labels are Spanish and data values match the source quote.

### Task 5: Full regression and visual verification

**Files:**
- Modify as needed only when verification reveals a multilingual layout defect.

**Interfaces:**
- Consumes: final HTML/PDF implementation.
- Produces: evidence that Russian and Spanish render without corruption or layout regressions.

- [ ] **Step 1: Run unit and focused feature suites**

```bash
KEY=$(docker exec geoflow-app grep '^APP_KEY=' /var/www/html/.env | cut -d= -f2-)
docker exec -e APP_KEY="$KEY" geoflow-app php artisan test tests/Unit/CrmDocumentLocaleTest.php tests/Feature/AdminCrmPagesTest.php --stop-on-failure
```

Expected: pass.

- [ ] **Step 2: Render real Russian and Spanish previews**

Use quote `30` without changing its database language:

```text
http://localhost:18080/admin/crm/quotes/30/print?type=quotation&language=ru
http://localhost:18080/admin/crm/quotes/30/print?type=proforma_invoice&language=es
```

Capture desktop screenshots and inspect the title, language control, buyer/commercial panels, item table, summary/terms, page labels, and bank page.

- [ ] **Step 3: Generate and inspect PDFs**

Download Russian quotation and Spanish PI PDFs through the existing Chromium service. Confirm page counts match visible HTML page containers and no Cyrillic characters render as boxes.

- [ ] **Step 4: Re-run existing pagination tests**

Run the compact no-image, image-item, eighth-image-row, and Chromium PDF tests already present in `AdminCrmPagesTest`.

Expected: no pagination regression.

### Task 6: Update user and agent documentation

**Files:**
- Modify: `功能说明文档/10-轻量CRM与报价使用说明.md`
- Modify: `docs/CHANGELOG.md`
- Modify: `agent-docs/IMPLEMENTATION_STATUS.md`

**Interfaces:**
- Consumes: verified behavior from Tasks 1-5.
- Produces: concise usage and handoff documentation.

- [ ] **Step 1: Document the workflow**

Explain default document language, temporary output language, supported locales, unchanged-value boundary, and HTML/PDF/Excel behavior.

- [ ] **Step 2: Record verification evidence**

Add the exact focused test commands and visual checks completed, without claiming full-content translation.

- [ ] **Step 3: Run documentation consistency checks**

```bash
rg -n 'English|简体中文|Русский|Español|业务内容保持原文' 功能说明文档/10-轻量CRM与报价使用说明.md docs/CHANGELOG.md agent-docs/IMPLEMENTATION_STATUS.md
git diff --check
```

Expected: all four languages and the translation boundary are documented; no whitespace errors.

### Task 7: Final verification and commit

**Files:**
- All files listed above.

**Interfaces:**
- Produces: one reviewable implementation commit after all verification passes.

- [ ] **Step 1: Review the final diff for scope**

Confirm there is no database migration, no translated business value, no duplicated print template, and no unrelated refactor.

- [ ] **Step 2: Run final lint, tests, and cache clear**

Use Docker PHP lint for all changed PHP/Blade/language files, run the focused unit/feature suites with the current container `APP_KEY`, clear Laravel caches and compiled views, and verify `git diff --check`.

- [ ] **Step 3: Commit the implementation**

```bash
git add app lang resources/views/admin/crm/quotes tests/Unit/CrmDocumentLocaleTest.php tests/Feature/AdminCrmPagesTest.php 功能说明文档/10-轻量CRM与报价使用说明.md docs/CHANGELOG.md agent-docs/IMPLEMENTATION_STATUS.md
git commit -m "feat: add multilingual CRM document output"
```

Expected: commit succeeds and the worktree contains no untracked generated PDF or screenshot artifacts.
