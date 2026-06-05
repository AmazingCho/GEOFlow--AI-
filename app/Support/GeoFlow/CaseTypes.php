<?php

namespace App\Support\GeoFlow;

final class CaseTypes
{
    public const CUSTOMER_SUCCESS = '客户成功案例';
    public const APPLICATION_SCENARIO = '应用场景案例';
    public const TROUBLESHOOTING = '问题排查案例';
    public const COMPARISON_VALIDATION = '对比验证案例';
    public const IMPLEMENTATION_DELIVERY = '实施交付案例';
    public const ROI_METRICS = 'ROI/指标案例';
    public const GENERAL = '通用案例';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return [
            self::CUSTOMER_SUCCESS,
            self::APPLICATION_SCENARIO,
            self::TROUBLESHOOTING,
            self::COMPARISON_VALIDATION,
            self::IMPLEMENTATION_DELIVERY,
            self::ROI_METRICS,
            self::GENERAL,
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
            'description' => self::referenceRule($value),
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

    public static function normalize(string $type): string
    {
        $type = trim($type);

        return in_array($type, self::values(), true) ? $type : self::GENERAL;
    }

    public static function referenceRule(string $type): string
    {
        return match (trim($type)) {
            self::CUSTOMER_SUCCESS => '用于证明真实客户结果。生成时优先引用客户背景、采用方案、量化结果和可公开的成效。',
            self::APPLICATION_SCENARIO => '用于说明具体应用场景。生成时优先引用场景条件、使用方式、适配边界和方案价值。',
            self::TROUBLESHOOTING => '用于解释问题和解决路径。生成时优先引用故障现象、原因、处理步骤和预防建议。',
            self::COMPARISON_VALIDATION => '用于支撑对比或选型。生成时优先引用对比对象、测试条件、差异结论和决策建议。',
            self::IMPLEMENTATION_DELIVERY => '用于说明项目落地过程。生成时优先引用实施步骤、交付周期、协作要求和上线注意事项。',
            self::ROI_METRICS => '用于支撑收益和指标。生成时优先引用成本、效率、良率、回收周期等可量化证据。',
            default => '通用案例。生成时作为辅助事实来源，不夸大未明确记录的结论。',
        };
    }
}
