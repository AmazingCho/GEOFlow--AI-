<?php

namespace App\Support\GeoFlow;

final class EntityTypes
{
    public const PRODUCT_MODEL = '产品型号';
    public const PRODUCT_LINE = '产品线';
    public const INDUSTRY = '行业领域';
    public const APPLICATION = '应用场景';
    public const MATERIAL_COMPONENT = '材料/部件';
    public const TECHNOLOGY_PROCESS = '技术/工艺';
    public const BRAND_COMPANY = '品牌/公司';
    public const COMPETITOR = '竞品';
    public const CUSTOMER_SEGMENT = '目标客户';
    public const GENERAL = '业务实体';

    public const LINK_POLICY_SUGGEST = 'suggest';
    public const LINK_POLICY_DISABLED = 'disabled';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return [
            self::PRODUCT_MODEL,
            self::PRODUCT_LINE,
            self::INDUSTRY,
            self::APPLICATION,
            self::MATERIAL_COMPONENT,
            self::TECHNOLOGY_PROCESS,
            self::BRAND_COMPANY,
            self::COMPETITOR,
            self::CUSTOMER_SEGMENT,
            self::GENERAL,
        ];
    }

    /**
     * @return list<string>
     */
    public static function linkableValues(): array
    {
        return [
            self::PRODUCT_MODEL,
            self::PRODUCT_LINE,
            self::APPLICATION,
            self::TECHNOLOGY_PROCESS,
            self::BRAND_COMPANY,
        ];
    }

    /**
     * @return list<array{value:string,label:string,description:string,is_historical:bool}>
     */
    public static function options(?string $current = null): array
    {
        $current = trim((string) $current);
        $options = array_map(static fn (string $value): array => [
            'value' => $value,
            'label' => $value,
            'description' => self::roleDescription($value),
            'is_historical' => false,
        ], self::values());

        if ($current !== '' && ! in_array($current, self::values(), true)) {
            array_unshift($options, [
                'value' => $current,
                'label' => $current.'（历史类型）',
                'description' => '历史自由类型。建议在下次维护时改为受控类型。',
                'is_historical' => true,
            ]);
        }

        return $options;
    }

    public static function isControlled(string $type): bool
    {
        return in_array(trim($type), self::values(), true);
    }

    public static function isLinkable(string $type): bool
    {
        return in_array(trim($type), self::linkableValues(), true);
    }

    public static function normalize(string $type): string
    {
        $type = trim($type);

        return self::isControlled($type) ? $type : self::GENERAL;
    }

    public static function normalizeLinkPolicy(string $policy, string $type): string
    {
        if (! self::isLinkable($type)) {
            return self::LINK_POLICY_DISABLED;
        }

        return $policy === self::LINK_POLICY_DISABLED
            ? self::LINK_POLICY_DISABLED
            : self::LINK_POLICY_SUGGEST;
    }

    public static function defaultLinkPolicyFor(string $type): string
    {
        return self::isLinkable($type) ? self::LINK_POLICY_SUGGEST : self::LINK_POLICY_DISABLED;
    }

    public static function roleDescription(string $type): string
    {
        return match (trim($type)) {
            self::PRODUCT_MODEL => '具体产品型号。生成时用于锁定产品事实、参数、手册、FAQ 和可建议内链。',
            self::PRODUCT_LINE => '产品线或产品系列。生成时用于限定产品族、应用边界和相关型号。',
            self::INDUSTRY => '行业领域。用于组织业务语境，不直接作为内链目标。',
            self::APPLICATION => '应用场景。用于匹配案例、工艺场景和可建议内链。',
            self::MATERIAL_COMPONENT => '材料、部件或配套物料。用于补充技术背景。',
            self::TECHNOLOGY_PROCESS => '技术、工艺或方法。用于解释方案原理和可建议内链。',
            self::BRAND_COMPANY => '品牌、公司或组织。用于品牌/公司事实和可建议内链。',
            self::COMPETITOR => '竞品或替代方案。用于比较，不默认作为站内内链。',
            self::CUSTOMER_SEGMENT => '目标客户或人群。用于受众和购买决策语境。',
            default => '通用业务实体。用于归档尚未规范化的实体。',
        };
    }
}
