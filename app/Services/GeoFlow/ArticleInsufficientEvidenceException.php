<?php

namespace App\Services\GeoFlow;

use RuntimeException;

final class ArticleInsufficientEvidenceException extends RuntimeException
{
    private const PREFIX = '深度生成证据不足，已在策划阶段停止。待补资料类型：';

    private const CATEGORY_PATTERNS = [
        '产品参数' => '/specif|parameter|performance|capacity|dimension|weight|power|参数|规格|性能|能力|尺寸|重量|功率/iu',
        '应用或工艺条件' => '/process|application|condition|environment|workflow|工艺|应用|条件|环境|流程/iu',
        '材料与兼容性' => '/material|resin|substrate|compatib|介质|材料|胶水|树脂|基材|兼容/iu',
        '目标效果与验收标准' => '/result|outcome|target|acceptance|quality|效果|结果|目标|验收|质量/iu',
        '安装维护与支持条件' => '/install|maint|service|training|安装|维护|保养|服务|培训/iu',
        '可信来源资料' => '/source|manual|document|evidence|reference|来源|手册|文档|证据|参考/iu',
    ];

    private const FALLBACK_CATEGORY = '关键事实资料';

    private const CATEGORY_CODES = [
        '产品参数' => 'product_specifications',
        '应用或工艺条件' => 'application_conditions',
        '材料与兼容性' => 'material_compatibility',
        '目标效果与验收标准' => 'outcome_acceptance',
        '安装维护与支持条件' => 'installation_support',
        '可信来源资料' => 'source_evidence',
        self::FALLBACK_CATEGORY => 'critical_facts',
    ];

    private const VERIFICATION_CATEGORY_MAP = [
        'specification' => ['产品参数', 'product_specifications'],
        'compatibility' => ['材料与兼容性', 'material_compatibility'],
        'process' => ['应用或工艺条件', 'application_conditions'],
        'integration' => ['安装维护与支持条件', 'installation_support'],
        'safety' => ['关键事实资料', 'critical_facts'],
        'commercial' => ['关键事实资料', 'critical_facts'],
        'case_evidence' => ['可信来源资料', 'source_evidence'],
        'other' => ['关键事实资料', 'critical_facts'],
    ];

    /** @param list<string> $categoryCodes */
    private function __construct(string $message, public readonly array $categoryCodes)
    {
        parent::__construct($message);
    }

    /** @param list<mixed> $openQuestions */
    public static function fromOpenQuestions(array $openQuestions): self
    {
        $categories = [];
        foreach ($openQuestions as $question) {
            if (! is_string($question)) {
                continue;
            }
            foreach (self::CATEGORY_PATTERNS as $category => $pattern) {
                if (preg_match($pattern, $question) === 1) {
                    $categories[$category] = true;
                }
            }
        }

        $safeCategories = array_slice(array_keys($categories), 0, 3);
        if ($safeCategories === []) {
            $safeCategories = [self::FALLBACK_CATEGORY];
        }

        return new self(
            self::PREFIX.implode('、', $safeCategories),
            array_values(array_map(static fn (string $category): string => self::CATEGORY_CODES[$category], $safeCategories))
        );
    }

    /** @param list<mixed> $verificationItems */
    public static function fromVerificationItems(array $verificationItems): self
    {
        $categories = [];
        $codes = [];
        foreach ($verificationItems as $item) {
            if (! is_array($item) || ($item['required_for_draft'] ?? false) !== true) {
                continue;
            }
            $mapped = self::VERIFICATION_CATEGORY_MAP[(string) ($item['category'] ?? '')]
                ?? [self::FALLBACK_CATEGORY, self::CATEGORY_CODES[self::FALLBACK_CATEGORY]];
            $categories[$mapped[0]] = true;
            $codes[$mapped[1]] = true;
        }

        $safeCategories = array_slice(array_keys($categories), 0, 3);
        $safeCodes = array_slice(array_keys($codes), 0, 3);
        if ($safeCategories === []) {
            $safeCategories = [self::FALLBACK_CATEGORY];
            $safeCodes = [self::CATEGORY_CODES[self::FALLBACK_CATEGORY]];
        }

        return new self(self::PREFIX.implode('、', $safeCategories), $safeCodes);
    }

    public static function isSafePublicMessage(string $message): bool
    {
        if (! str_starts_with($message, self::PREFIX)) {
            return false;
        }

        $categories = explode('、', substr($message, strlen(self::PREFIX)));
        $allowed = array_merge(array_keys(self::CATEGORY_PATTERNS), [self::FALLBACK_CATEGORY]);

        return $categories !== []
            && count($categories) <= 3
            && count(array_unique($categories)) === count($categories)
            && collect($categories)->every(
                static fn (string $category): bool => in_array($category, $allowed, true)
            );
    }

    public static function isSafeCategoryCode(string $code): bool
    {
        return in_array($code, array_values(self::CATEGORY_CODES), true);
    }
}
