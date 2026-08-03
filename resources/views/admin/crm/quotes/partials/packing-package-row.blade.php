@php
    $package = array_replace([
        'package_no' => '',
        'package_type' => 'wooden_case',
        'package_length' => '',
        'package_width' => '',
        'package_height' => '',
        'net_weight' => '0',
        'gross_weight' => '0',
        'volume_cbm' => '',
        'volume_is_manual' => '0',
        'notes' => '',
        'allocations' => [],
    ], is_array($package ?? null) ? $package : []);
    $allocations = is_array($package['allocations'] ?? null) ? array_values($package['allocations']) : [];
@endphp

<article class="rounded-md border border-gray-200 bg-white p-4" data-package-row>
    <div class="mb-4 flex items-start justify-between gap-3">
        <div>
            <h3 class="text-sm font-semibold text-gray-900">包装 <span data-package-sequence></span></h3>
            <p class="mt-1 text-xs text-gray-500">一行代表一个实际箱、托盘或其他包装单元。</p>
        </div>
        <button type="button" class="inline-flex h-8 items-center rounded-md border border-gray-300 bg-white px-2.5 text-xs font-medium text-gray-600 hover:bg-gray-50" data-remove-package>
            <i data-lucide="trash-2" class="mr-1 h-3.5 w-3.5"></i>删除包装
        </button>
    </div>

    <div class="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-6">
        <div>
            <label class="mb-1 block text-xs font-medium text-gray-600">包装编号</label>
            <input type="text" name="packages[{{ $packageIndex }}][package_no]" value="{{ $package['package_no'] }}" class="{{ $compactInputClass }}" placeholder="CASE-01">
        </div>
        <div>
            <label class="mb-1 block text-xs font-medium text-gray-600">包装类型</label>
            <select name="packages[{{ $packageIndex }}][package_type]" class="{{ $compactInputClass }}">
                @foreach($packageTypes as $type => $label)
                    <option value="{{ $type }}" @selected((string) $package['package_type'] === $type)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="mb-1 block text-xs font-medium text-gray-600">长 (cm)</label>
            <input type="number" step="0.1" min="0.1" name="packages[{{ $packageIndex }}][package_length]" value="{{ $package['package_length'] }}" class="{{ $compactInputClass }}" data-package-dimension>
        </div>
        <div>
            <label class="mb-1 block text-xs font-medium text-gray-600">宽 (cm)</label>
            <input type="number" step="0.1" min="0.1" name="packages[{{ $packageIndex }}][package_width]" value="{{ $package['package_width'] }}" class="{{ $compactInputClass }}" data-package-dimension>
        </div>
        <div>
            <label class="mb-1 block text-xs font-medium text-gray-600">高 (cm)</label>
            <input type="number" step="0.1" min="0.1" name="packages[{{ $packageIndex }}][package_height]" value="{{ $package['package_height'] }}" class="{{ $compactInputClass }}" data-package-dimension>
        </div>
        <div>
            <label class="mb-1 block text-xs font-medium text-gray-600">CBM</label>
            <input type="number" step="0.001" min="0" name="packages[{{ $packageIndex }}][volume_cbm]" value="{{ $package['volume_cbm'] }}" class="{{ $compactInputClass }}" data-package-volume>
        </div>
    </div>

    <div class="mt-3 grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-4">
        <div>
            <label class="mb-1 block text-xs font-medium text-gray-600">净重 (kg)</label>
            <input type="number" step="0.001" min="0" name="packages[{{ $packageIndex }}][net_weight]" value="{{ $package['net_weight'] }}" class="{{ $compactInputClass }}">
        </div>
        <div>
            <label class="mb-1 block text-xs font-medium text-gray-600">毛重 (kg)</label>
            <input type="number" step="0.001" min="0" name="packages[{{ $packageIndex }}][gross_weight]" value="{{ $package['gross_weight'] }}" class="{{ $compactInputClass }}">
        </div>
        <label class="flex min-h-[38px] items-center gap-2 self-end rounded-md border border-gray-300 bg-white px-3 text-sm text-gray-700">
            <input type="hidden" name="packages[{{ $packageIndex }}][volume_is_manual]" value="0">
            <input type="checkbox" name="packages[{{ $packageIndex }}][volume_is_manual]" value="1" class="h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500" data-volume-manual @checked((string) $package['volume_is_manual'] === '1')>
            手动填写 CBM
        </label>
        <div>
            <label class="mb-1 block text-xs font-medium text-gray-600">包装备注</label>
            <input type="text" name="packages[{{ $packageIndex }}][notes]" value="{{ $package['notes'] }}" maxlength="500" class="{{ $compactInputClass }}" placeholder="例如：与主机同箱">
        </div>
    </div>

    <div class="mt-4 border-t border-gray-200 pt-4">
        <div class="mb-3 flex items-center justify-between gap-3">
            <div>
                <h4 class="text-sm font-semibold text-gray-900">箱内商品</h4>
                <p class="mt-1 text-xs text-gray-500">选择明细并填写放入此包装的数量。</p>
            </div>
            <button type="button" class="inline-flex h-8 items-center rounded-md border border-blue-200 bg-blue-50 px-2.5 text-xs font-medium text-blue-700 hover:bg-blue-100" data-add-allocation>
                <i data-lucide="plus" class="mr-1 h-3.5 w-3.5"></i>添加商品
            </button>
        </div>
        <div class="space-y-2" data-allocation-list>
            @foreach($allocations as $allocationIndex => $allocation)
                @include('admin.crm.quotes.partials.packing-allocation-row', [
                    'allocation' => $allocation,
                    'packageIndex' => $packageIndex,
                    'allocationIndex' => $allocationIndex,
                    'packingItemOptions' => $packingItemOptions,
                    'compactInputClass' => $compactInputClass,
                ])
            @endforeach
        </div>
        <template data-allocation-template>
            @include('admin.crm.quotes.partials.packing-allocation-row', [
                'allocation' => [],
                'packageIndex' => $packageIndex,
                'allocationIndex' => '__ALLOCATION_INDEX__',
                'packingItemOptions' => $packingItemOptions,
                'compactInputClass' => $compactInputClass,
            ])
        </template>
    </div>
</article>
