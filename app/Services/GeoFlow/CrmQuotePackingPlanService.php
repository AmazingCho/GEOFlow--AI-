<?php

namespace App\Services\GeoFlow;

use App\Models\Admin;
use App\Models\CrmQuote;
use App\Support\AdminActivityLogger;
use Illuminate\Validation\ValidationException;

final class CrmQuotePackingPlanService
{
    /** @param list<array<string, mixed>> $packages */
    public function sync(CrmQuote $quote, array $packages): void
    {
        $quoteItems = $quote->items()->get()->keyBy(static fn ($item): int => (int) $item->id);
        foreach (array_values($packages) as $packageIndex => $packagePayload) {
            $seenItemIds = [];
            foreach ((array) ($packagePayload['allocations'] ?? []) as $allocation) {
                $quoteItemId = (int) ($allocation['quote_item_id'] ?? 0);
                $quoteItem = $quoteItems->get($quoteItemId);
                if ($quoteItem === null) {
                    throw ValidationException::withMessages([
                        'packages' => '包装中包含不属于当前单据的商品明细。',
                    ]);
                }
                if (isset($seenItemIds[$quoteItemId])) {
                    $packageNo = trim((string) ($packagePayload['package_no'] ?? '')) ?: '第 '.($packageIndex + 1).' 个包装';
                    throw ValidationException::withMessages([
                        'packages' => "包装 {$packageNo} 中的商品 {$quoteItem->item_name} 被重复添加，请合并为一条分配记录。",
                    ]);
                }
                if ((bool) $quoteItem->packing_exempt) {
                    throw ValidationException::withMessages([
                        'packages' => "商品 {$quoteItem->item_name} 已设置为无需装箱，不能加入包装方案。",
                    ]);
                }
                $seenItemIds[$quoteItemId] = true;
            }
        }

        $quote->packages()->delete();
        foreach (array_values($packages) as $index => $packagePayload) {
            $length = max(0, (float) ($packagePayload['package_length'] ?? 0));
            $width = max(0, (float) ($packagePayload['package_width'] ?? 0));
            $height = max(0, (float) ($packagePayload['package_height'] ?? 0));
            $manualVolume = (bool) ($packagePayload['volume_is_manual'] ?? false);
            $volume = $manualVolume
                ? max(0, (float) ($packagePayload['volume_cbm'] ?? 0))
                : round(($length * $width * $height) / 1000000, 3);

            $package = $quote->packages()->create([
                'package_no' => trim((string) ($packagePayload['package_no'] ?? '')),
                'package_type' => trim((string) ($packagePayload['package_type'] ?? 'wooden_case')),
                'package_length' => $length,
                'package_width' => $width,
                'package_height' => $height,
                'net_weight' => max(0, (float) ($packagePayload['net_weight'] ?? 0)),
                'gross_weight' => max(0, (float) ($packagePayload['gross_weight'] ?? 0)),
                'volume_cbm' => $volume,
                'volume_is_manual' => $manualVolume,
                'notes' => trim((string) ($packagePayload['notes'] ?? '')),
                'sort_order' => $index + 1,
            ]);

            foreach ((array) ($packagePayload['allocations'] ?? []) as $allocation) {
                $quoteItemId = (int) ($allocation['quote_item_id'] ?? 0);
                $package->allocations()->create([
                    'quote_id' => (int) $quote->id,
                    'quote_item_id' => $quoteItemId,
                    'allocated_quantity' => max(0, (float) ($allocation['allocated_quantity'] ?? 0)),
                ]);
            }
        }

        $quote->forceFill([
            'packing_mode' => 'package_plan',
            'packing_status' => 'draft',
            'packing_applied_at' => null,
            'packing_applied_by_admin_id' => null,
            'packing_invalid_reason' => '',
        ])->save();

    }

    public function apply(CrmQuote $quote, Admin $admin): void
    {
        $errors = $this->validationErrors($quote);
        if ($errors !== []) {
            throw ValidationException::withMessages(['packages' => implode(' ', $errors)]);
        }

        $quote->forceFill([
            'packing_mode' => 'package_plan',
            'packing_status' => 'applied',
            'packing_applied_at' => now(),
            'packing_applied_by_admin_id' => (int) $admin->id,
            'packing_invalid_reason' => '',
        ])->save();

        AdminActivityLogger::log($admin, 'crm:quote:packing-plan:apply', [
            'request_method' => 'PUT',
            'page' => 'crm-quote-packing-plan',
            'target_type' => 'crm_quote',
            'target_id' => (int) $quote->id,
            'details' => [
                'package_count' => (int) $quote->packages()->count(),
                'allocation_count' => (int) $quote->packages()->withCount('allocations')->get()->sum('allocations_count'),
            ],
        ]);
    }

    public function invalidate(CrmQuote $quote, string $reason): void
    {
        if ((string) $quote->packing_mode !== 'package_plan' || ! $quote->packages()->exists()) {
            return;
        }

        $quote->forceFill([
            'packing_status' => 'invalid',
            'packing_applied_at' => null,
            'packing_applied_by_admin_id' => null,
            'packing_invalid_reason' => trim($reason),
        ])->save();
    }

    public function useItemLevel(CrmQuote $quote): void
    {
        $quote->forceFill([
            'packing_mode' => 'item_level',
            'packing_status' => 'draft',
            'packing_applied_at' => null,
            'packing_applied_by_admin_id' => null,
            'packing_invalid_reason' => '',
        ])->save();
    }

    /** @return list<string> */
    public function validationErrors(CrmQuote $quote): array
    {
        $quote->loadMissing(['items', 'packages.allocations']);
        if ($quote->packages->isEmpty()) {
            return ['至少需要创建一个包装。'];
        }

        $errors = [];
        $allocatedByItem = [];
        foreach ($quote->packages as $package) {
            if ($package->allocations->isEmpty()) {
                $errors[] = "包装 {$package->package_no} 尚未添加商品。";
            }
            if ((float) $package->package_length <= 0 || (float) $package->package_width <= 0 || (float) $package->package_height <= 0) {
                $errors[] = "包装 {$package->package_no} 的尺寸必须完整填写。";
            }
            if ((float) $package->gross_weight < (float) $package->net_weight) {
                $errors[] = "包装 {$package->package_no} 的毛重不能小于净重。";
            }

            foreach ($package->allocations as $allocation) {
                $quantity = (float) $allocation->allocated_quantity;
                if ($quantity <= 0) {
                    $errors[] = "包装 {$package->package_no} 中的分配数量必须大于 0。";
                }
                $itemId = (int) $allocation->quote_item_id;
                $allocatedByItem[$itemId] = ($allocatedByItem[$itemId] ?? 0) + $quantity;
            }
        }

        foreach ($quote->items as $item) {
            if ((bool) $item->packing_exempt) {
                continue;
            }

            $required = (float) $item->quantity;
            $allocated = (float) ($allocatedByItem[(int) $item->id] ?? 0);
            if (abs($required - $allocated) > 0.004) {
                $direction = $allocated > $required ? '超出' : '尚缺';
                $difference = number_format(abs($required - $allocated), 2, '.', '');
                $errors[] = "商品 {$item->item_name} 分配数量{$direction} {$difference} {$item->unit}。";
            }
        }

        return array_values(array_unique($errors));
    }
}
