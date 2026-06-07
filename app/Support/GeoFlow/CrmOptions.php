<?php

namespace App\Support\GeoFlow;

use App\Models\Admin;

final class CrmOptions
{
    /**
     * @return array<string, string>
     */
    public static function customerTypes(): array
    {
        return [
            'End Customer' => '终端客户',
            'Distributor' => '经销商',
            'Integrator' => '系统集成商',
            'OEM' => 'OEM / 设备商',
            'Agent' => '代理商',
            'Partner' => '合作伙伴',
            'Supplier' => '供应商',
            'Other' => '其他',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function sourceChannels(): array
    {
        return [
            'Website' => '官网',
            'Email' => '邮件',
            'Trade Show' => '展会',
            'Google' => 'Google / 搜索',
            'LinkedIn' => 'LinkedIn',
            'Alibaba' => 'Alibaba',
            'WhatsApp' => 'WhatsApp',
            'Phone' => '电话',
            'Referral' => '客户转介绍',
            'Existing Customer' => '老客户',
            'Other' => '其他',
        ];
    }

    /**
     * @return list<string>
     */
    public static function countries(): array
    {
        return [
            'US',
            'United States',
            'Canada',
            'Mexico',
            'Brazil',
            'United Kingdom',
            'Germany',
            'France',
            'Italy',
            'Spain',
            'Netherlands',
            'Poland',
            'Turkey',
            'Australia',
            'New Zealand',
            'China',
            'Hong Kong',
            'Taiwan',
            'Japan',
            'South Korea',
            'Singapore',
            'Malaysia',
            'Thailand',
            'Vietnam',
            'Indonesia',
            'India',
            'Pakistan',
            'United Arab Emirates',
            'Saudi Arabia',
            'South Africa',
            'Egypt',
            'Nigeria',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function employeeOptions(): array
    {
        return Admin::query()
            ->where('status', 'active')
            ->orderBy('display_name')
            ->orderBy('username')
            ->get(['username', 'display_name'])
            ->mapWithKeys(static function (Admin $admin): array {
                $value = trim((string) ($admin->display_name ?: $admin->username));
                $label = trim((string) ($admin->display_name ?: $admin->username));
                $username = trim((string) $admin->username);
                if ($username !== '' && $username !== $label) {
                    $label .= ' · '.$username;
                }

                return $value !== '' ? [$value => $label] : [];
            })
            ->all();
    }
}
