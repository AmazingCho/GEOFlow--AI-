@php
    $fieldName = (string) ($name ?? 'option_ids');
    $options = collect($options ?? [])
        ->map(static fn (array $option): array => [
            'id' => (int) ($option['id'] ?? 0),
            'label' => (string) ($option['label'] ?? $option['name'] ?? ''),
            'meta' => (string) ($option['meta'] ?? ''),
            'thumbnail' => (string) ($option['thumbnail'] ?? ''),
            'collection_id' => (int) ($option['collection_id'] ?? 0),
        ])
        ->filter(static fn (array $option): bool => $option['id'] > 0 && $option['label'] !== '')
        ->values();
    $selectedIds = collect(old($fieldName, $selectedIds ?? []))
        ->map(static fn ($id): int => (int) $id)
        ->filter(static fn (int $id): bool => $id > 0)
        ->unique()
        ->values()
        ->all();
    $selectedMap = array_fill_keys($selectedIds, true);
    $selectedOptions = $options->filter(static fn (array $option): bool => !empty($selectedMap[$option['id']]))->values();
    $tone = (string) ($tone ?? 'blue');
    $toneClasses = match ($tone) {
        'purple' => [
            'focus' => 'focus-within:border-purple-500 focus-within:ring-purple-500',
            'chip' => 'bg-purple-50 text-purple-700',
            'chipButton' => 'bg-purple-600',
            'option' => 'hover:bg-purple-50 hover:text-purple-700',
        ],
        'orange' => [
            'focus' => 'focus-within:border-orange-500 focus-within:ring-orange-500',
            'chip' => 'bg-orange-50 text-orange-700',
            'chipButton' => 'bg-orange-600',
            'option' => 'hover:bg-orange-50 hover:text-orange-700',
        ],
        'green' => [
            'focus' => 'focus-within:border-green-500 focus-within:ring-green-500',
            'chip' => 'bg-green-50 text-green-700',
            'chipButton' => 'bg-green-600',
            'option' => 'hover:bg-green-50 hover:text-green-700',
        ],
        default => [
            'focus' => 'focus-within:border-blue-500 focus-within:ring-blue-500',
            'chip' => 'bg-blue-50 text-blue-700',
            'chipButton' => 'bg-blue-600',
            'option' => 'hover:bg-blue-50 hover:text-blue-700',
        ],
    };
    $placeholder = (string) ($placeholder ?? __('admin.material_tags.selector_search_placeholder'));
    $emptyText = (string) ($emptyText ?? __('admin.material_tags.selector_empty'));
    $noneSelectedText = (string) ($noneSelectedText ?? __('admin.material_tags.selector_none_selected'));
    $removeText = (string) ($removeText ?? __('admin.material_tags.selector_remove'));
@endphp

<div data-option-multi-selector data-field-name="{{ $fieldName }}" data-chip-class="{{ $toneClasses['chip'] }}" data-chip-button-class="{{ $toneClasses['chipButton'] }}" class="space-y-2">
    <div data-option-selected class="flex min-h-[1.75rem] w-full flex-wrap items-center gap-2">
        @foreach ($selectedOptions as $option)
            <span data-option-chip data-option-id="{{ $option['id'] }}" data-option-label="{{ $option['label'] }}" class="group relative inline-flex items-center rounded-full {{ $toneClasses['chip'] }} px-2.5 py-1 text-xs font-medium">
                {{ $option['label'] }}
                <button type="button" data-option-remove data-option-id="{{ $option['id'] }}" class="absolute -right-1 -top-1 hidden rounded-full {{ $toneClasses['chipButton'] }} p-0.5 text-white group-hover:inline-flex" title="{{ $removeText }}">
                    <i data-lucide="x" class="h-3 w-3"></i>
                </button>
                <input type="hidden" name="{{ $fieldName }}[]" value="{{ $option['id'] }}">
            </span>
        @endforeach
        <span data-option-empty class="{{ $selectedIds === [] ? 'inline' : 'hidden' }} text-xs text-gray-400">{{ $noneSelectedText }}</span>
    </div>
    <div class="relative">
        <div class="flex min-h-[2.5rem] items-center rounded-md border border-gray-300 bg-white px-3 py-2 shadow-sm focus-within:ring-1 {{ $toneClasses['focus'] }}">
            <input type="search" data-option-search autocomplete="off" placeholder="{{ $placeholder }}" class="min-w-0 flex-1 border-0 p-0 text-sm outline-none focus:ring-0">
        </div>
        <div data-option-menu class="absolute left-0 right-0 z-40 mt-1 hidden max-h-48 overflow-y-auto rounded-md border border-gray-200 bg-white py-1 shadow-lg">
            <div data-option-list>
                @forelse ($options as $option)
                    <button type="button" data-option-item data-option-id="{{ $option['id'] }}" data-option-label="{{ $option['label'] }}" data-option-collection-id="{{ $option['collection_id'] }}" data-option-filter-hidden="0" data-option-search-label="{{ mb_strtolower($option['label'].' '.$option['meta'], 'UTF-8') }}" class="flex w-full items-center justify-between gap-3 px-3 py-2 text-left text-sm text-gray-700 {{ $toneClasses['option'] }}">
                        <span class="flex min-w-0 items-center gap-3">
                            @if ($option['thumbnail'] !== '')
                                <img src="{{ $option['thumbnail'] }}" alt="" class="h-10 w-10 shrink-0 rounded border border-gray-200 object-cover">
                            @endif
                            <span class="min-w-0">
                                <span class="block truncate font-medium">{{ $option['label'] }}</span>
                                @if ($option['meta'] !== '')
                                    <span class="block truncate text-xs text-gray-400">{{ $option['meta'] }}</span>
                                @endif
                            </span>
                        </span>
                        <i data-option-check class="{{ empty($selectedMap[$option['id']]) ? 'hidden' : '' }} h-4 w-4 shrink-0"></i>
                    </button>
                @empty
                    <div class="px-3 py-2 text-sm text-gray-400">{{ $emptyText }}</div>
                @endforelse
            </div>
            <div data-option-menu-empty class="hidden px-3 py-2 text-sm text-gray-400">{{ $emptyText }}</div>
        </div>
    </div>
</div>

@once
    @push('scripts')
        <script>
            document.addEventListener('DOMContentLoaded', function () {
                function controls(selector) {
                    return {
                        selected: selector.querySelector('[data-option-selected]'),
                        empty: selector.querySelector('[data-option-empty]'),
                        search: selector.querySelector('[data-option-search]'),
                        menu: selector.querySelector('[data-option-menu]'),
                        menuEmpty: selector.querySelector('[data-option-menu-empty]'),
                        items: Array.from(selector.querySelectorAll('[data-option-item]')),
                    };
                }

                function selectedIds(selector) {
                    return Array.from(selector.querySelectorAll('[data-option-chip]'))
                        .map((chip) => chip.getAttribute('data-option-id') || '')
                        .filter(Boolean);
                }

                function escapeHtml(value) {
                    return String(value)
                        .replace(/&/g, '&amp;')
                        .replace(/</g, '&lt;')
                        .replace(/>/g, '&gt;')
                        .replace(/"/g, '&quot;')
                        .replace(/'/g, '&#039;');
                }

                function update(selector) {
                    const c = controls(selector);
                    const selected = selectedIds(selector);
                    if (c.empty) {
                        c.empty.classList.toggle('hidden', selected.length > 0);
                        c.empty.classList.toggle('inline', selected.length === 0);
                    }
                    c.items.forEach((item) => {
                        const isSelected = selected.includes(item.getAttribute('data-option-id') || '');
                        item.querySelector('[data-option-check]')?.classList.toggle('hidden', !isSelected);
                    });
                    selector.dispatchEvent(new CustomEvent('option-selector:updated', {
                        bubbles: true,
                        detail: {selectedIds: selected},
                    }));
                    if (typeof lucide !== 'undefined') {
                        lucide.createIcons();
                    }
                }

                function add(selector, id, label) {
                    if (!id || !label || selectedIds(selector).includes(id)) {
                        return;
                    }
                    const fieldName = selector.getAttribute('data-field-name') || 'option_ids';
                    const chip = document.createElement('span');
                    chip.setAttribute('data-option-chip', '');
                    chip.setAttribute('data-option-id', id);
                    chip.setAttribute('data-option-label', label);
                    chip.className = 'group relative inline-flex items-center rounded-full ' + (selector.getAttribute('data-chip-class') || 'bg-blue-50 text-blue-700') + ' px-2.5 py-1 text-xs font-medium';
                    chip.innerHTML = escapeHtml(label) + '<button type="button" data-option-remove data-option-id="' + escapeHtml(id) + '" class="absolute -right-1 -top-1 hidden rounded-full ' + (selector.getAttribute('data-chip-button-class') || 'bg-blue-600') + ' p-0.5 text-white group-hover:inline-flex" title="{{ $removeText }}"><i data-lucide="x" class="h-3 w-3"></i></button><input type="hidden" name="' + escapeHtml(fieldName) + '[]" value="' + escapeHtml(id) + '">';
                    selector.querySelector('[data-option-empty]')?.before(chip);
                    update(selector);
                }

                document.addEventListener('focusin', function (event) {
                    const search = event.target.closest('[data-option-search]');
                    if (search) {
                        search.closest('[data-option-multi-selector]')?.querySelector('[data-option-menu]')?.classList.remove('hidden');
                    }
                });

                document.addEventListener('input', function (event) {
                    const search = event.target.closest('[data-option-search]');
                    if (!search) return;
                    const selector = search.closest('[data-option-multi-selector]');
                    const query = search.value.trim().toLowerCase();
                    let visible = 0;
                    selector.querySelectorAll('[data-option-item]').forEach((item) => {
                        const filterHidden = item.hidden || item.dataset.optionFilterHidden === '1';
                        const show = !filterHidden && (query === '' || (item.getAttribute('data-option-search-label') || '').includes(query));
                        item.classList.toggle('hidden', !show);
                        if (show) visible++;
                    });
                    selector.querySelector('[data-option-menu-empty]')?.classList.toggle('hidden', visible > 0);
                });

                document.addEventListener('click', function (event) {
                    const item = event.target.closest('[data-option-item]');
                    if (item) {
                        if (item.hidden || item.dataset.optionFilterHidden === '1' || item.classList.contains('hidden')) {
                            event.preventDefault();
                            return;
                        }
                        const selector = item.closest('[data-option-multi-selector]');
                        add(selector, item.getAttribute('data-option-id') || '', item.getAttribute('data-option-label') || '');
                        selector.querySelector('[data-option-search]').value = '';
                        selector.querySelector('[data-option-menu]')?.classList.add('hidden');
                        event.preventDefault();
                        return;
                    }

                    const remove = event.target.closest('[data-option-remove]');
                    if (remove) {
                        const selector = remove.closest('[data-option-multi-selector]');
                        remove.closest('[data-option-chip]')?.remove();
                        update(selector);
                        event.preventDefault();
                        return;
                    }

                    document.querySelectorAll('[data-option-multi-selector]').forEach((selector) => {
                        if (!selector.contains(event.target)) {
                            selector.querySelector('[data-option-menu]')?.classList.add('hidden');
                        }
                    });
                });

                document.addEventListener('option-selector:changed', function (event) {
                    const selector = event.target.closest('[data-option-multi-selector]');
                    if (selector) {
                        update(selector);
                    }
                });

                document.querySelectorAll('[data-option-multi-selector]').forEach(update);
            });
        </script>
    @endpush
@endonce
