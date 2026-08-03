@php
    $allocation = array_replace([
        'quote_item_id' => '',
        'allocated_quantity' => '',
    ], is_array($allocation ?? null) ? $allocation : []);
@endphp

<div class="grid grid-cols-[minmax(0,1fr)_40px] items-end gap-2 sm:grid-cols-[minmax(0,1fr)_minmax(120px,0.35fr)_36px]" data-allocation-row>
    <div class="col-span-2 sm:col-span-1">
        <label class="mb-1 block text-xs font-medium text-gray-600">商品明细</label>
        <select name="packages[{{ $packageIndex }}][allocations][{{ $allocationIndex }}][quote_item_id]" class="{{ $compactInputClass }}" data-allocation-item-select>
            <option value="">请选择商品</option>
            @foreach($packingItemOptions as $item)
                <option value="{{ $item['id'] }}" @selected((string) $allocation['quote_item_id'] === (string) $item['id'])>
                    {{ $item['label'] }} · {{ $item['quantity'] }} {{ $item['unit'] }}
                </option>
            @endforeach
        </select>
    </div>
    <div>
        <label class="mb-1 block text-xs font-medium text-gray-600">分配数量</label>
        <input type="number" step="0.01" min="0.01" name="packages[{{ $packageIndex }}][allocations][{{ $allocationIndex }}][allocated_quantity]" value="{{ $allocation['allocated_quantity'] }}" class="{{ $compactInputClass }}">
    </div>
    <button type="button" class="inline-flex h-[38px] w-9 items-center justify-center rounded-md border border-gray-300 bg-white text-gray-500 hover:bg-gray-50 hover:text-red-600" title="移除商品" data-remove-allocation>
        <i data-lucide="x" class="h-4 w-4"></i>
    </button>
</div>
