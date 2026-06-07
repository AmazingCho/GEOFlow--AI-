@php
    $row = array_replace([
        'entity_id' => '',
        'line_type' => 'product',
        'model' => '',
        'hs_code' => '',
        'image_id' => '',
        'image_path' => '',
        'image_original_name' => '',
        'item_name' => '',
        'description' => '',
        'quantity' => '1',
        'unit' => '',
        'unit_price' => '0',
        'package_count' => '0',
        'net_weight' => '0',
        'gross_weight' => '0',
        'volume_cbm' => '0',
    ], $row ?? []);
    $rowIndex = $index ?? null;
    $valueFor = static function (string $field, string $default = '') use ($rowIndex, $row): string {
        $value = is_int($rowIndex)
            ? old("items.$field.$rowIndex", (string) ($row[$field] ?? $default))
            : (string) ($row[$field] ?? $default);

        return (string) $value;
    };
    $inputClass = $inputClass ?? 'block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500';
    $compactInputClass = $compactInputClass ?? 'block w-full rounded-md border border-gray-300 px-2 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500';
    $textareaClass = $textareaClass ?? 'block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500';
    $imageId = $valueFor('image_id');
    $imagePath = $valueFor('image_path');
    $imageOriginalName = $valueFor('image_original_name');
    $selectedImageOption = collect($imageOptions ?? [])->firstWhere('id', (int) $imageId);
    $imageUrl = $selectedImageOption['url'] ?? \App\Support\GeoFlow\ImageUrlNormalizer::toPublicUrl($imagePath);
@endphp

<div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm" data-crm-quote-item-row>
    <div class="mb-4 flex items-start justify-between gap-3">
        <div>
            <div class="text-sm font-semibold text-gray-900">明细行</div>
            <p class="mt-1 text-xs text-gray-500">项目名称为空的行不会保存。</p>
        </div>
        <button type="button" class="inline-flex h-8 items-center rounded-md border border-gray-300 bg-white px-2.5 text-xs font-medium text-gray-600 hover:bg-gray-50" data-remove-quote-item>
            <i data-lucide="trash-2" class="mr-1 h-3.5 w-3.5"></i>
            删除
        </button>
    </div>

    <div class="grid grid-cols-1 gap-4 lg:grid-cols-12">
        <div class="lg:col-span-2">
            <label class="mb-1 block text-xs font-medium text-gray-600">行类型</label>
            <select name="items[line_type][]" class="{{ $compactInputClass }}">
                @foreach (($lineTypeOptions ?? []) as $typeValue => $typeLabel)
                    <option value="{{ $typeValue }}" @selected($valueFor('line_type', 'product') === (string) $typeValue)>{{ $typeLabel }}</option>
                @endforeach
            </select>
        </div>
        <div class="lg:col-span-3">
            <label class="mb-1 block text-xs font-medium text-gray-600">关联 Entity</label>
            <select name="items[entity_id][]" class="{{ $compactInputClass }}">
                <option value="">不关联</option>
                @foreach (($entityOptions ?? []) as $entity)
                    <option value="{{ (int) $entity['id'] }}" @selected($valueFor('entity_id') === (string) $entity['id'])>
                        {{ $entity['label'] }} @if (($entity['meta'] ?? '') !== '') · {{ $entity['meta'] }} @endif
                    </option>
                @endforeach
            </select>
        </div>
        <div class="lg:col-span-2">
            <label class="mb-1 block text-xs font-medium text-gray-600">型号</label>
            <input type="text" name="items[model][]" value="{{ $valueFor('model') }}" class="{{ $compactInputClass }}">
        </div>
        <div class="lg:col-span-3">
            <label class="mb-1 block text-xs font-medium text-gray-600">项目名称</label>
            <input type="text" name="items[item_name][]" value="{{ $valueFor('item_name') }}" class="{{ $compactInputClass }}" data-quote-item-name>
        </div>
    </div>

    <div class="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-12">
        <div class="lg:col-span-6">
            <label class="mb-1 block text-xs font-medium text-gray-600">描述</label>
            <textarea name="items[description][]" rows="4" class="{{ $textareaClass }}">{{ $valueFor('description') }}</textarea>
        </div>
        <div class="lg:col-span-6">
            <label class="mb-1 block text-xs font-medium text-gray-600">图片</label>
            <div class="grid grid-cols-1 gap-3 md:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
                <select name="items[image_id][]" class="{{ $compactInputClass }}">
                    <option value="">不选择图库图片</option>
                    @foreach (($imageOptions ?? []) as $image)
                        <option value="{{ (int) $image['id'] }}" @selected($imageId === (string) $image['id'])>
                            {{ $image['label'] }} @if (($image['meta'] ?? '') !== '') · {{ $image['meta'] }} @endif
                        </option>
                    @endforeach
                </select>
                <input type="file" name="items[image_upload][]" accept="image/jpeg,image/png,image/webp" class="{{ $compactInputClass }}">
            </div>
            <input type="hidden" name="items[image_path][]" value="{{ $imagePath }}">
            <input type="hidden" name="items[image_original_name][]" value="{{ $imageOriginalName }}">
            <div class="mt-2 flex items-center gap-3 text-xs text-gray-500">
                @if ($imageUrl !== '')
                    <img src="{{ $imageUrl }}" alt="" class="h-10 w-10 rounded border border-gray-200 object-cover">
                @endif
                <span>{{ $imageOriginalName !== '' ? $imageOriginalName : '可从图片库选择，或本地上传 200KB 以内图片。' }}</span>
            </div>
        </div>
    </div>

    <div class="mt-4 grid grid-cols-2 gap-4 md:grid-cols-4 xl:grid-cols-8">
        <div>
            <label class="mb-1 block text-xs font-medium text-gray-600">数量</label>
            <input type="number" step="0.01" min="0" name="items[quantity][]" value="{{ $valueFor('quantity', '1') }}" class="{{ $compactInputClass }}" data-quote-quantity>
        </div>
        <div>
            <label class="mb-1 block text-xs font-medium text-gray-600">单位</label>
            <input type="text" name="items[unit][]" value="{{ $valueFor('unit') }}" class="{{ $compactInputClass }}">
        </div>
        <div>
            <label class="mb-1 block text-xs font-medium text-gray-600">单价</label>
            <input type="number" step="0.01" min="0" name="items[unit_price][]" value="{{ $valueFor('unit_price', '0') }}" class="{{ $compactInputClass }}" data-quote-unit-price>
        </div>
        <div>
            <label class="mb-1 block text-xs font-medium text-gray-600">金额</label>
            <div class="flex h-[38px] items-center rounded-md border border-gray-200 bg-gray-50 px-2 text-sm font-semibold text-gray-900" data-quote-row-amount>0.00</div>
        </div>
        <div data-logistics-field>
            <label class="mb-1 block text-xs font-medium text-gray-600">件数</label>
            <input type="number" min="0" name="items[package_count][]" value="{{ $valueFor('package_count', '0') }}" class="{{ $compactInputClass }}">
        </div>
        <div data-logistics-field>
            <label class="mb-1 block text-xs font-medium text-gray-600">净重</label>
            <input type="number" step="0.001" min="0" name="items[net_weight][]" value="{{ $valueFor('net_weight', '0') }}" class="{{ $compactInputClass }}">
        </div>
        <div data-logistics-field>
            <label class="mb-1 block text-xs font-medium text-gray-600">毛重</label>
            <input type="number" step="0.001" min="0" name="items[gross_weight][]" value="{{ $valueFor('gross_weight', '0') }}" class="{{ $compactInputClass }}">
        </div>
        <div data-logistics-field>
            <label class="mb-1 block text-xs font-medium text-gray-600">体积 CBM</label>
            <input type="number" step="0.001" min="0" name="items[volume_cbm][]" value="{{ $valueFor('volume_cbm', '0') }}" class="{{ $compactInputClass }}">
        </div>
        <div data-logistics-field>
            <label class="mb-1 block text-xs font-medium text-gray-600">包装尺寸 L×W×H (cm)</label>
            <div class="grid grid-cols-3 gap-2">
                <input type="number" step="0.1" min="0" name="items[package_length][]" value="{{ $valueFor('package_length') }}" class="{{ $compactInputClass }} min-w-[80px]" placeholder="长">
                <input type="number" step="0.1" min="0" name="items[package_width][]" value="{{ $valueFor('package_width') }}" class="{{ $compactInputClass }} min-w-[80px]" placeholder="宽">
                <input type="number" step="0.1" min="0" name="items[package_height][]" value="{{ $valueFor('package_height') }}" class="{{ $compactInputClass }} min-w-[80px]" placeholder="高">
            </div>
        </div>
    </div>

    <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-3">
        <div data-hscode-field>
            <label class="mb-1 block text-xs font-medium text-gray-600">HS Code</label>
            <input type="text" name="items[hs_code][]" value="{{ $valueFor('hs_code') }}" class="{{ $compactInputClass }}">
        </div>
    </div>
</div>
