<?php

namespace Tests\Unit;

use App\Support\GeoFlow\CrmDocumentLocale;
use DateTimeImmutable;
use Tests\TestCase;

class CrmDocumentLocaleTest extends TestCase
{
    public function test_it_exposes_the_controlled_document_languages(): void
    {
        $this->assertSame(['en', 'zh_CN', 'ru', 'es'], CrmDocumentLocale::supported());
        $this->assertSame([
            'en' => 'English',
            'zh_CN' => '简体中文',
            'ru' => 'Русский',
            'es' => 'Español',
        ], CrmDocumentLocale::options());
    }

    public function test_it_resolves_temporary_saved_and_fallback_languages_in_order(): void
    {
        $this->assertSame('ru', CrmDocumentLocale::resolve('ru', 'en'));
        $this->assertSame('zh_CN', CrmDocumentLocale::resolve('fr', 'zh_CN'));
        $this->assertSame('es', CrmDocumentLocale::resolve(null, 'es'));
        $this->assertSame('en', CrmDocumentLocale::resolve('fr', 'de'));
    }

    public function test_all_catalogs_have_identical_document_and_label_keys(): void
    {
        $englishTitleKeys = array_keys(CrmDocumentLocale::documentTitles('en'));
        $englishLabelKeys = array_keys(CrmDocumentLocale::labels('en'));
        sort($englishTitleKeys);
        sort($englishLabelKeys);

        foreach (CrmDocumentLocale::supported() as $language) {
            $titleKeys = array_keys(CrmDocumentLocale::documentTitles($language));
            $labelKeys = array_keys(CrmDocumentLocale::labels($language));
            sort($titleKeys);
            sort($labelKeys);

            $this->assertSame($englishTitleKeys, $titleKeys, "Document title keys differ for {$language}.");
            $this->assertSame($englishLabelKeys, $labelKeys, "Document label keys differ for {$language}.");
        }
    }

    public function test_localized_catalogs_do_not_silently_inherit_english_labels(): void
    {
        $englishLabels = CrmDocumentLocale::labels('en');
        $allowedIndustryAbbreviations = [
            'zh_CN' => ['cbm', 'hs_code', 'swift'],
            'ru' => ['swift'],
            'es' => ['cbm', 'swift'],
        ];

        foreach ($allowedIndustryAbbreviations as $language => $allowedKeys) {
            $unchangedKeys = array_keys(array_filter(
                CrmDocumentLocale::labels($language),
                static fn (string $value, string $key): bool => ($englishLabels[$key] ?? null) === $value,
                ARRAY_FILTER_USE_BOTH,
            ));
            sort($allowedKeys);
            sort($unchangedKeys);

            $this->assertSame($allowedKeys, $unchangedKeys, "Unexpected English labels remain in {$language}.");
        }
    }

    public function test_it_returns_expected_russian_and_spanish_trade_document_labels(): void
    {
        $this->assertSame('Коммерческое предложение', CrmDocumentLocale::documentTitles('ru')['quotation']);
        $this->assertSame('Счёт-проформа', CrmDocumentLocale::documentTitles('ru')['proforma_invoice']);
        $this->assertSame('Cotización', CrmDocumentLocale::documentTitles('es')['quotation']);
        $this->assertSame('Factura proforma', CrmDocumentLocale::documentTitles('es')['proforma_invoice']);
        $this->assertSame('Покупатель / Заказчик', CrmDocumentLocale::text('ru', 'buyer_customer'));
        $this->assertSame('Términos y condiciones', CrmDocumentLocale::text('es', 'terms_conditions'));
    }

    public function test_chinese_catalog_covers_previously_hardcoded_document_sections(): void
    {
        $this->assertSame('买方 / 客户', CrmDocumentLocale::text('zh_CN', 'buyer_customer'));
        $this->assertSame('条款与条件', CrmDocumentLocale::text('zh_CN', 'terms_conditions'));
        $this->assertSame('银行账户', CrmDocumentLocale::text('zh_CN', 'bank_account_title'));
        $this->assertSame('付款汇总', CrmDocumentLocale::text('zh_CN', 'payment_summary'));
        $this->assertSame('申报', CrmDocumentLocale::text('zh_CN', 'declaration'));
        $this->assertSame(
            '报价单 · 第 1 页，共 2 页',
            CrmDocumentLocale::text('zh_CN', 'document_page_of', '', [
                'title' => '报价单',
                'current' => 1,
                'total' => 2,
            ])
        );
    }

    public function test_it_replaces_placeholders_and_falls_back_to_english_keys(): void
    {
        $this->assertSame(
            'Страница 2 из 5',
            CrmDocumentLocale::text('ru', 'page_of', '', ['current' => 2, 'total' => 5])
        );
        $this->assertSame(
            'Download PDF',
            CrmDocumentLocale::text('fr', 'download_pdf')
        );
        $this->assertSame(
            'Explicit fallback',
            CrmDocumentLocale::text('es', 'missing_key', 'Explicit fallback')
        );
    }

    public function test_it_formats_dates_and_html_language_codes_for_each_locale(): void
    {
        $date = new DateTimeImmutable('2026-07-13');

        $this->assertSame('Jul 13, 2026', CrmDocumentLocale::formatDate($date, 'en'));
        $this->assertSame('2026年7月13日', CrmDocumentLocale::formatDate($date, 'zh_CN'));
        $this->assertSame('13 июл. 2026 г.', CrmDocumentLocale::formatDate($date, 'ru'));
        $this->assertSame('13 jul 2026', CrmDocumentLocale::formatDate($date, 'es'));
        $this->assertSame('', CrmDocumentLocale::formatDate(null, 'ru'));

        $this->assertSame('en', CrmDocumentLocale::htmlLang('en'));
        $this->assertSame('zh-CN', CrmDocumentLocale::htmlLang('zh_CN'));
        $this->assertSame('ru', CrmDocumentLocale::htmlLang('ru'));
        $this->assertSame('es', CrmDocumentLocale::htmlLang('es'));
    }
}
