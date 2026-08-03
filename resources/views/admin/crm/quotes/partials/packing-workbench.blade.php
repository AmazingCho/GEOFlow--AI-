@php
    $packingStatus = (string) ($quoteForm['packing_status'] ?? 'draft');
    $packingStatusLabels = [
        'draft' => '草稿',
        'applied' => '已应用',
        'invalid' => '已失效',
    ];
    $packingStatusClasses = [
        'draft' => 'border-amber-200 bg-amber-50 text-amber-700',
        'applied' => 'border-emerald-200 bg-emerald-50 text-emerald-700',
        'invalid' => 'border-red-200 bg-red-50 text-red-700',
    ];
    $packageRows = old('packages', $quotePackages ?? []);
    $packageRows = is_array($packageRows) ? array_values($packageRows) : [];
    $packingErrorMessages = collect($errors->getMessages())
        ->filter(static fn (array $messages, string $key): bool => $key === 'packages' || str_starts_with($key, 'packages.'))
        ->flatten()
        ->unique()
        ->values();
    $hasPackingErrors = $packingErrorMessages->isNotEmpty();
    $packingItemOptions = collect($quoteItems ?? [])
        ->filter(static fn (array $item): bool => (int) ($item['id'] ?? 0) > 0)
        ->reject(static fn (array $item): bool => (string) ($item['packing_exempt'] ?? '0') === '1')
        ->map(static fn (array $item): array => [
            'id' => (int) $item['id'],
            'label' => trim((string) ($item['item_name'] ?? '')) ?: '未命名明细',
            'quantity' => (string) ($item['quantity'] ?? '0'),
            'unit' => (string) ($item['unit'] ?? ''),
        ])
        ->values()
        ->all();
    $packageTypes = [
        'wooden_case' => '木箱',
        'carton' => '纸箱',
        'pallet' => '托盘',
        'other' => '其他',
    ];
@endphp

<section class="{{ $sectionClass }}" data-packing-workbench>
    @if($hasPackingErrors || $packingStatus === 'invalid')
        <details open>
    @else
        <details>
    @endif
        <summary class="-mx-5 -my-5 flex cursor-pointer list-none items-center justify-between gap-4 rounded-lg border-b border-transparent px-5 py-4 marker:hidden">
            <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-2">
                    <h2 class="text-base font-semibold text-gray-900">
                        <i data-lucide="package-open" class="mr-2 inline-block h-4 w-4 text-blue-600"></i>包装方案
                    </h2>
                    <span class="inline-flex rounded-full border px-2.5 py-1 text-xs font-semibold {{ $packingStatusClasses[$packingStatus] ?? $packingStatusClasses['draft'] }}">
                        {{ $packingStatusLabels[$packingStatus] ?? '草稿' }}
                    </span>
                </div>
                <p class="mt-1 text-sm text-gray-500">按实际箱、托盘组织装箱单；不改变报价明细，也不影响历史逐行装箱数据。</p>
            </div>
            <i data-lucide="chevron-down" class="h-5 w-5 shrink-0 text-gray-400"></i>
        </summary>

        <div class="mt-5 border-t border-gray-200 pt-5">
            <input type="hidden" name="packing_mode" value="package_plan">

            @if($hasPackingErrors)
                <div class="mb-4 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700" data-packing-errors>
                    <div class="font-semibold">包装方案尚未保存，请检查以下内容：</div>
                    <ul class="mt-2 list-disc space-y-1 pl-5">
                        @foreach($packingErrorMessages->take(5) as $message)
                            <li>{{ $message }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @if($packingStatus === 'invalid' && (string) ($quoteForm['packing_invalid_reason'] ?? '') !== '')
                <div class="mb-4 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                    {{ $quoteForm['packing_invalid_reason'] }}
                </div>
            @endif

            <div class="mb-4 flex flex-col gap-3 rounded-md border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-900 lg:flex-row lg:items-center lg:justify-between">
                <div>
                    <div class="font-semibold">数量必须完整分配</div>
                    <p class="mt-1 text-xs leading-5 text-blue-700">同一包装可放多个商品，同一商品也可拆到多个包装；所有分配数量合计必须等于明细数量。</p>
                </div>
                <button type="button" class="inline-flex h-9 shrink-0 items-center justify-center rounded-md border border-blue-300 bg-white px-3 text-sm font-medium text-blue-700 hover:bg-blue-50" data-add-package>
                    <i data-lucide="plus" class="mr-2 h-4 w-4"></i>新增包装
                </button>
            </div>

            <div class="mb-4 hidden rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800" data-packing-dirty-notice>
                包装方案有未保存修改。点击页面底部“保存单据”时，将同时保存为包装草稿。
            </div>

            @if($packingItemOptions !== [])
                <div class="mb-4 overflow-hidden rounded-md border border-gray-200" data-allocation-summary>
                    <div class="border-b border-gray-200 bg-gray-50 px-4 py-2 text-sm font-semibold text-gray-800">商品分配进度</div>
                    <div class="divide-y divide-gray-100">
                        @foreach($packingItemOptions as $item)
                            <div class="grid grid-cols-1 gap-1 px-4 py-2 text-sm sm:grid-cols-[minmax(0,1fr)_auto_auto] sm:items-center sm:gap-5" data-allocation-summary-item data-item-id="{{ $item['id'] }}" data-required="{{ $item['quantity'] }}">
                                <span class="font-medium text-gray-800">{{ $item['label'] }}</span>
                                <span class="text-gray-500">应分配 {{ $item['quantity'] }} {{ $item['unit'] }} · 已分配 <strong data-allocated>0</strong></span>
                                <span class="font-semibold text-amber-700" data-remaining>剩余 {{ $item['quantity'] }}</span>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            <div class="space-y-4" data-package-list>
                @foreach($packageRows as $packageIndex => $package)
                    @include('admin.crm.quotes.partials.packing-package-row', [
                        'package' => $package,
                        'packageIndex' => $packageIndex,
                        'packingItemOptions' => $packingItemOptions,
                        'packageTypes' => $packageTypes,
                        'compactInputClass' => $compactInputClass,
                    ])
                @endforeach
            </div>

            <div class="mt-4 rounded-md border border-dashed border-gray-300 px-4 py-5 text-center text-sm text-gray-500 {{ $packageRows !== [] ? 'hidden' : '' }}" data-package-empty>
                尚未创建包装。点击“新增包装”开始组合箱、托盘和商品。
            </div>

            <template data-package-template>
                @include('admin.crm.quotes.partials.packing-package-row', [
                    'package' => ['allocations' => []],
                    'packageIndex' => '__PACKAGE_INDEX__',
                    'packingItemOptions' => $packingItemOptions,
                    'packageTypes' => $packageTypes,
                    'compactInputClass' => $compactInputClass,
                ])
            </template>

            <div class="mt-5 flex flex-wrap justify-end gap-3">
                @if((string) ($quoteForm['packing_mode'] ?? 'item_level') === 'package_plan')
                    <button type="submit" name="packing_action" value="item_level" class="mr-auto inline-flex h-10 items-center rounded-md border border-gray-300 bg-white px-4 text-sm font-medium text-gray-700 hover:bg-gray-50">
                        <i data-lucide="list" class="mr-2 h-4 w-4"></i>改用逐行装箱
                    </button>
                @endif
                <button type="submit" name="packing_action" value="save" class="inline-flex h-10 items-center rounded-md border border-gray-300 bg-white px-4 text-sm font-medium text-gray-700 hover:bg-gray-50" data-save-packing>
                    <i data-lucide="save" class="mr-2 h-4 w-4"></i>保存包装草稿
                </button>
                <button type="submit" name="packing_action" value="apply" class="inline-flex h-10 items-center rounded-md border border-transparent bg-emerald-600 px-4 text-sm font-medium text-white hover:bg-emerald-700" data-apply-packing>
                    <i data-lucide="circle-check" class="mr-2 h-4 w-4"></i>应用包装方案
                </button>
            </div>
        </div>
    </details>
</section>
