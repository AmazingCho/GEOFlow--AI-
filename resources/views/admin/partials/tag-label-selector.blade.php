@php
    $countTranslationKey = is_string($countLabelKey ?? null) ? (string) $countLabelKey : '';
    $tagOptions = collect($tagOptions ?? [])
        ->map(function ($tag) use ($countTranslationKey): array {
            $label = (string) ($tag['label'] ?? '');
            $count = (int) ($tag['count'] ?? 0);
            $meta = (string) ($tag['meta'] ?? '');
            if ($meta === '' && $countTranslationKey !== '') {
                $meta = __($countTranslationKey, ['count' => $count]);
            }

            return [
                'label' => $label,
                'count' => $count,
                'meta' => $meta,
            ];
        })
        ->filter(static fn (array $tag): bool => $tag['label'] !== '')
        ->unique(static fn (array $tag): string => mb_strtolower($tag['label'], 'UTF-8'))
        ->values();
    $fieldName = (string) ($name ?? 'tag_filters');
    $selectedLabels = collect($selectedLabels ?? [])
        ->map(static fn ($label): string => trim((string) $label))
        ->filter(static fn (string $label): bool => $label !== '')
        ->unique(static fn (string $label): string => mb_strtolower($label, 'UTF-8'))
        ->values()
        ->all();
    $selectedMap = array_fill_keys($selectedLabels, true);
    $placeholder = (string) ($placeholder ?? __('admin.material_tags.selector_search_placeholder'));
    $emptyText = (string) ($emptyText ?? __('admin.material_tags.selector_empty'));
    $loadingText = (string) ($loadingText ?? __('admin.material_tags.selector_loading'));
    $noneSelectedText = (string) ($noneSelectedText ?? __('admin.material_tags.selector_none_selected'));
    $removeText = (string) ($removeText ?? __('admin.material_tags.selector_remove'));
    $searchUrl = (string) ($searchUrl ?? route('admin.material-tags.search'));
    $searchScope = (string) ($searchScope ?? '');
    $searchGroup = (string) ($searchGroup ?? '');
    $tone = (string) ($tone ?? 'blue');
    $toneClasses = match ($tone) {
        'purple' => [
            'focus' => 'focus-within:border-purple-500 focus-within:ring-purple-500',
            'chip' => 'bg-purple-50 text-purple-700',
            'chipButton' => 'bg-purple-600',
            'option' => 'hover:bg-purple-50 hover:text-purple-700',
            'selectedOption' => 'bg-purple-50 text-purple-700',
        ],
        'orange' => [
            'focus' => 'focus-within:border-orange-500 focus-within:ring-orange-500',
            'chip' => 'bg-orange-50 text-orange-700',
            'chipButton' => 'bg-orange-600',
            'option' => 'hover:bg-orange-50 hover:text-orange-700',
            'selectedOption' => 'bg-orange-50 text-orange-700',
        ],
        default => [
            'focus' => 'focus-within:border-blue-500 focus-within:ring-blue-500',
            'chip' => 'bg-blue-50 text-blue-700',
            'chipButton' => 'bg-blue-600',
            'option' => 'hover:bg-blue-50 hover:text-blue-700',
            'selectedOption' => 'bg-blue-50 text-blue-700',
        ],
    };
@endphp

<div data-tag-label-selector data-field-name="{{ $fieldName }}" data-selected-option-class="{{ $toneClasses['selectedOption'] }}" data-remove-title="{{ $removeText }}" data-chip-class="{{ $toneClasses['chip'] }}" data-chip-button-class="{{ $toneClasses['chipButton'] }}" data-tag-label-search-url="{{ $searchUrl }}" data-tag-label-search-scope="{{ $searchScope }}" data-tag-label-search-group="{{ $searchGroup }}" data-tag-label-option-class="{{ $toneClasses['option'] }}" class="space-y-2">
    <div data-tag-label-selected class="flex min-h-[1.75rem] w-full flex-wrap items-center gap-2">
        @foreach ($selectedLabels as $label)
            <span data-tag-label-chip data-tag-label="{{ $label }}" class="group relative inline-flex items-center rounded-full {{ $toneClasses['chip'] }} px-2.5 py-1 text-xs font-medium">
                {{ $label }}
                <button type="button" data-tag-label-remove data-tag-label="{{ $label }}" class="absolute -right-1 -top-1 hidden rounded-full {{ $toneClasses['chipButton'] }} p-0.5 text-white group-hover:inline-flex" title="{{ $removeText }}">
                    <i data-lucide="x" class="h-3 w-3"></i>
                </button>
                <input type="hidden" name="{{ $fieldName }}[]" value="{{ $label }}">
            </span>
        @endforeach
        <span data-tag-label-empty class="{{ $selectedLabels === [] ? 'inline' : 'hidden' }} text-xs text-gray-400">{{ $noneSelectedText }}</span>
    </div>
    <div class="relative">
        <div class="flex min-h-[2.5rem] items-center rounded-md border border-gray-300 bg-white px-3 py-2 shadow-sm {{ $toneClasses['focus'] }} focus-within:ring-1">
            <input type="search" data-tag-label-search autocomplete="off" placeholder="{{ $placeholder }}" class="min-w-0 flex-1 border-0 p-0 text-sm outline-none focus:ring-0">
        </div>
        <div data-tag-label-menu class="absolute left-0 right-0 z-40 mt-1 hidden max-h-48 overflow-y-auto rounded-md border border-gray-200 bg-white py-1 shadow-lg">
            <div data-tag-label-loading class="hidden px-3 py-2 text-sm text-gray-400">{{ $loadingText }}</div>
            <div data-tag-label-options>
                @foreach ($tagOptions as $tag)
                    <button type="button" data-tag-label-option data-tag-label="{{ $tag['label'] }}" data-tag-search-label="{{ mb_strtolower($tag['label'], 'UTF-8') }}" class="flex w-full items-center justify-between gap-3 px-3 py-2 text-left text-sm text-gray-700 {{ $toneClasses['option'] }}">
                        <span class="min-w-0">
                            <span class="block truncate font-medium" title="{{ $tag['label'] }}">{{ $tag['label'] }}</span>
                            @if ($tag['meta'] !== '')
                                <span class="block text-xs text-gray-400">{{ $tag['meta'] }}</span>
                            @endif
                        </span>
                        <i data-lucide="check" data-tag-label-option-check class="{{ empty($selectedMap[$tag['label']]) ? 'hidden' : '' }} h-4 w-4 shrink-0"></i>
                    </button>
                @endforeach
            </div>
            <div data-tag-label-menu-empty class="{{ $tagOptions->isEmpty() ? '' : 'hidden' }} px-3 py-2 text-sm text-gray-400">{{ $emptyText }}</div>
        </div>
    </div>
</div>

@once
    @push('scripts')
        <script>
            (function () {
                function refreshIcons() {
                    if (typeof lucide !== 'undefined') {
                        lucide.createIcons();
                    }
                }

                function parts(selector) {
                    return {
                        selected: selector.querySelector('[data-tag-label-selected]'),
                        search: selector.querySelector('[data-tag-label-search]'),
                        menu: selector.querySelector('[data-tag-label-menu]'),
                        loading: selector.querySelector('[data-tag-label-loading]'),
                        optionsContainer: selector.querySelector('[data-tag-label-options]'),
                        menuEmpty: selector.querySelector('[data-tag-label-menu-empty]'),
                        empty: selector.querySelector('[data-tag-label-empty]'),
                        options: Array.from(selector.querySelectorAll('[data-tag-label-option]')),
                    };
                }

                function selectedValues(selector) {
                    return Array.from(selector.querySelectorAll('input[type="hidden"]'))
                        .map((input) => input.value)
                        .filter((value) => value !== '');
                }

                function updateSelector(selector) {
                    const selected = selectedValues(selector);
                    const selectedClass = selector.getAttribute('data-selected-option-class') || 'bg-blue-50 text-blue-700';
                    const selectedClasses = selectedClass.split(/\s+/).filter(Boolean);
                    const selectorParts = parts(selector);

                    selectorParts.options.forEach((option) => {
                        const isSelected = selected.includes(option.getAttribute('data-tag-label') || '');
                        selectedClasses.forEach((className) => option.classList.toggle(className, isSelected));
                        option.querySelector('[data-tag-label-option-check]')?.classList.toggle('hidden', !isSelected);
                    });

                    if (selectorParts.empty) {
                        selectorParts.empty.classList.toggle('hidden', selected.length > 0);
                        selectorParts.empty.classList.toggle('inline', selected.length === 0);
                    }
                    refreshIcons();
                }

                function filterOptions(selector) {
                    const selectorParts = parts(selector);
                    const query = (selectorParts.search?.value || '').trim().toLowerCase();
                    selectorParts.options.forEach((option) => {
                        const label = (option.getAttribute('data-tag-search-label') || '').toLowerCase();
                        option.classList.toggle('hidden', query !== '' && !label.includes(query));
                    });
                }

                function escapeHtml(value) {
                    return String(value).replace(/[&<>"']/g, function (char) {
                        return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'}[char] || char;
                    });
                }

                function renderOptions(selector, items) {
                    const selectorParts = parts(selector);
                    if (!selectorParts.optionsContainer) {
                        return;
                    }

                    selectorParts.optionsContainer.innerHTML = '';
                    (items || []).forEach((item) => {
                        if (!item || !item.label) {
                            return;
                        }

                        const button = document.createElement('button');
                        button.type = 'button';
                        button.setAttribute('data-tag-label-option', '');
                        button.setAttribute('data-tag-label', String(item.label));
                        button.setAttribute('data-tag-search-label', String(item.label).toLowerCase());
                        button.className = 'flex w-full items-center justify-between gap-3 px-3 py-2 text-left text-sm text-gray-700 ' + (selector.getAttribute('data-tag-label-option-class') || 'hover:bg-blue-50 hover:text-blue-700');
                        const meta = item.meta ? '<span class="block text-xs text-gray-400">' + escapeHtml(item.meta) + '</span>' : '';
                        button.innerHTML = '<span class="min-w-0"><span class="block truncate font-medium" title="' + escapeHtml(item.label) + '">' + escapeHtml(item.label) + '</span>' + meta + '</span><i data-lucide="check" data-tag-label-option-check class="hidden h-4 w-4 shrink-0"></i>';
                        selectorParts.optionsContainer.appendChild(button);
                    });

                    selectorParts.menuEmpty?.classList.toggle('hidden', selectorParts.optionsContainer.children.length > 0);
                    updateSelector(selector);
                }

                function searchOptions(selector) {
                    const selectorParts = parts(selector);
                    const url = selector.getAttribute('data-tag-label-search-url') || '';
                    if (!url) {
                        filterOptions(selector);
                        return;
                    }

                    const requestUrl = new URL(url, window.location.origin);
                    requestUrl.searchParams.set('q', (selectorParts.search?.value || '').trim());
                    requestUrl.searchParams.set('limit', '20');
                    const scope = selector.getAttribute('data-tag-label-search-scope') || '';
                    if (scope !== '') {
                        requestUrl.searchParams.set('scope', scope);
                    }
                    const group = selector.getAttribute('data-tag-label-search-group') || '';
                    if (group !== '') {
                        requestUrl.searchParams.set('group', group);
                    }

                    selectorParts.loading?.classList.remove('hidden');
                    selectorParts.menuEmpty?.classList.add('hidden');
                    fetch(requestUrl.toString(), {
                        headers: {'Accept': 'application/json'},
                        credentials: 'same-origin',
                    })
                        .then((response) => response.ok ? response.json() : {items: []})
                        .then((payload) => renderOptions(selector, payload.items || []))
                        .catch(() => renderOptions(selector, []))
                        .finally(() => selectorParts.loading?.classList.add('hidden'));
                }

                function debounceSearch(selector, delay = 180) {
                    window.clearTimeout(selector._tagLabelSearchTimer);
                    selector._tagLabelSearchTimer = window.setTimeout(() => searchOptions(selector), delay);
                }

                function showMenu(selector) {
                    const selectorParts = parts(selector);
                    selectorParts.menu?.classList.remove('hidden');
                    debounceSearch(selector, 0);
                    updateSelector(selector);
                }

                function addChip(selector, label) {
                    const selectorParts = parts(selector);
                    if (!selectorParts.selected || !selectorParts.search || label === '' || selectedValues(selector).includes(label)) {
                        return;
                    }

                    const chip = document.createElement('span');
                    chip.setAttribute('data-tag-label-chip', '');
                    chip.setAttribute('data-tag-label', label);
                    chip.className = 'group relative inline-flex items-center rounded-full ' + (selector.getAttribute('data-chip-class') || 'bg-blue-50 text-blue-700') + ' px-2.5 py-1 text-xs font-medium';
                    chip.appendChild(document.createTextNode(label));

                    const removeButton = document.createElement('button');
                    removeButton.type = 'button';
                    removeButton.setAttribute('data-tag-label-remove', '');
                    removeButton.setAttribute('data-tag-label', label);
                    removeButton.className = 'absolute -right-1 -top-1 hidden rounded-full ' + (selector.getAttribute('data-chip-button-class') || 'bg-blue-600') + ' p-0.5 text-white group-hover:inline-flex';
                    removeButton.title = selector.getAttribute('data-remove-title') || 'Remove';
                    const icon = document.createElement('i');
                    icon.setAttribute('data-lucide', 'x');
                    icon.className = 'h-3 w-3';
                    removeButton.appendChild(icon);
                    chip.appendChild(removeButton);

                    const input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = (selector.getAttribute('data-field-name') || 'tag_filters') + '[]';
                    input.value = label;
                    chip.appendChild(input);

                    selectorParts.selected.insertBefore(chip, selectorParts.empty || null);
                    selectorParts.search.value = '';
                    selectorParts.search.focus();
                    filterOptions(selector);
                    updateSelector(selector);
                    document.dispatchEvent(new CustomEvent('geoflow:tag-label-selection-changed', {detail: {selector}}));
                }

                document.addEventListener('focusin', function (event) {
                    const search = event.target.closest('[data-tag-label-search]');
                    if (search) {
                        const selector = search.closest('[data-tag-label-selector]');
                        if (selector) {
                            parts(selector).menu?.classList.remove('hidden');
                            debounceSearch(selector);
                        }
                    }
                });

                document.addEventListener('input', function (event) {
                    const search = event.target.closest('[data-tag-label-search]');
                    if (search) {
                        const selector = search.closest('[data-tag-label-selector]');
                        if (selector) {
                            showMenu(selector);
                        }
                    }
                });

                document.addEventListener('click', function (event) {
                    const option = event.target.closest('[data-tag-label-option]');
                    if (option) {
                        const selector = option.closest('[data-tag-label-selector]');
                        if (selector) {
                            addChip(selector, option.getAttribute('data-tag-label') || '');
                        }
                        return;
                    }

                    const remove = event.target.closest('[data-tag-label-remove]');
                    if (remove) {
                        const selector = remove.closest('[data-tag-label-selector]');
                        remove.closest('[data-tag-label-chip]')?.remove();
                        if (selector) {
                            updateSelector(selector);
                            document.dispatchEvent(new CustomEvent('geoflow:tag-label-selection-changed', {detail: {selector}}));
                        }
                        event.preventDefault();
                        return;
                    }

                    document.querySelectorAll('[data-tag-label-selector]').forEach((selector) => {
                        if (!selector.contains(event.target)) {
                            selector.querySelector('[data-tag-label-menu]')?.classList.add('hidden');
                        }
                    });
                });

                document.querySelectorAll('[data-tag-label-selector]').forEach(updateSelector);
            })();
        </script>
    @endpush
@endonce
