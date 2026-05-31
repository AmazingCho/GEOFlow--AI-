@php
    $tagOptions = collect($tagOptions ?? [])
        ->map(static fn ($tag): array => [
            'id' => (int) ($tag['id'] ?? 0),
            'label' => (string) ($tag['label'] ?? ''),
        ])
        ->filter(static fn (array $tag): bool => $tag['id'] > 0 && $tag['label'] !== '')
        ->values();
    $fieldName = (string) ($name ?? 'tag_ids');
    $selectedTagIds = collect(old($fieldName, $selectedTagIds ?? []))
        ->map(static fn ($id): int => (int) $id)
        ->filter(static fn (int $id): bool => $id > 0)
        ->unique()
        ->values()
        ->all();
    $selectedTagMap = array_fill_keys($selectedTagIds, true);
    $recommendedTags = collect($recommendedTags ?? [])
        ->map(static fn ($tag): array => [
            'id' => (int) ($tag['id'] ?? 0),
            'label' => (string) ($tag['label'] ?? ''),
        ])
        ->filter(static fn (array $tag): bool => $tag['id'] > 0 && $tag['label'] !== '' && empty($selectedTagMap[$tag['id']]))
        ->unique('id')
        ->values();
    $tone = (string) ($tone ?? 'blue');
    $autoSubmit = (bool) ($autoSubmit ?? false);
    $includePresence = (bool) ($includePresence ?? true);
    $recommendationUrl = (string) ($recommendationUrl ?? '');
    $recommendationSourceSelector = (string) ($recommendationSourceSelector ?? '');
    $searchUrl = (string) ($searchUrl ?? route('admin.material-tags.search'));
    $hasRecommendationBox = $recommendedTags->isNotEmpty() || ($recommendationUrl !== '' && $recommendationSourceSelector !== '');
    $selectedTagOptions = $tagOptions->filter(static fn (array $tag): bool => !empty($selectedTagMap[$tag['id']]))->values();
    $toneClasses = match ($tone) {
        'purple' => [
            'focus' => 'focus-within:border-purple-500 focus-within:ring-purple-500',
            'chip' => 'bg-purple-50 text-purple-700',
            'chipButton' => 'bg-purple-600',
            'option' => 'hover:bg-purple-50 hover:text-purple-700',
            'selectedOption' => 'bg-purple-50 text-purple-700',
            'checkbox' => 'text-purple-600 focus:ring-purple-500',
        ],
        'orange' => [
            'focus' => 'focus-within:border-orange-500 focus-within:ring-orange-500',
            'chip' => 'bg-orange-50 text-orange-700',
            'chipButton' => 'bg-orange-600',
            'option' => 'hover:bg-orange-50 hover:text-orange-700',
            'selectedOption' => 'bg-orange-50 text-orange-700',
            'checkbox' => 'text-orange-600 focus:ring-orange-500',
        ],
        default => [
            'focus' => 'focus-within:border-blue-500 focus-within:ring-blue-500',
            'chip' => 'bg-blue-50 text-blue-700',
            'chipButton' => 'bg-blue-600',
            'option' => 'hover:bg-blue-50 hover:text-blue-700',
            'selectedOption' => 'bg-blue-50 text-blue-700',
            'checkbox' => 'text-blue-600 focus:ring-blue-500',
        ],
    };
@endphp

<div data-tag-selector data-tag-selector-auto-submit="{{ $autoSubmit ? '1' : '0' }}" data-selected-option-class="{{ $toneClasses['selectedOption'] }}" data-tag-recommendation-url="{{ $recommendationUrl }}" data-tag-recommendation-source="{{ $recommendationSourceSelector }}" data-tag-search-url="{{ $searchUrl }}" data-tag-field-name="{{ $fieldName }}" data-tag-option-class="{{ $toneClasses['option'] }}" class="space-y-2">
    @if ($includePresence)
        <input type="hidden" name="{{ $fieldName }}_present" value="1">
    @endif
        <div data-tag-selector-selected class="flex min-h-8 flex-wrap items-center gap-2">
            @foreach ($selectedTagOptions as $tag)
                <span data-tag-selector-chip data-tag-id="{{ $tag['id'] }}" class="group relative inline-flex items-center rounded-full {{ $toneClasses['chip'] }} px-2.5 py-1 text-xs font-medium">
                    {{ $tag['label'] }}
                    <button type="button" data-tag-selector-remove data-tag-id="{{ $tag['id'] }}" class="absolute -right-1 -top-1 hidden rounded-full {{ $toneClasses['chipButton'] }} p-0.5 text-white group-hover:inline-flex" title="{{ __('admin.material_tags.selector_remove') }}">
                        <i data-lucide="x" class="h-3 w-3"></i>
                    </button>
                </span>
                <input type="checkbox"
                       data-tag-checkbox
                       data-tag-id="{{ $tag['id'] }}"
                       name="{{ $fieldName }}[]"
                       value="{{ $tag['id'] }}"
                       checked
                       class="hidden {{ $toneClasses['checkbox'] }}">
            @endforeach
            <span data-tag-selector-empty class="{{ $selectedTagIds === [] ? 'inline' : 'hidden' }} text-xs text-gray-400">{{ __('admin.material_tags.selector_none_selected') }}</span>
        </div>

        @if ($hasRecommendationBox)
            <div data-tag-selector-recommendations class="{{ $recommendedTags->isEmpty() ? 'hidden' : 'flex' }} flex-wrap items-center gap-1.5">
                <span class="text-xs text-gray-500">{{ __('admin.material_tags.selector_recommended') }}</span>
                <span data-tag-recommendation-items class="contents">
                    @foreach ($recommendedTags as $tag)
                        <button type="button" data-tag-recommendation data-tag-id="{{ $tag['id'] }}" class="inline-flex items-center rounded-full border border-gray-200 bg-gray-50 px-2 py-0.5 text-xs font-medium text-gray-700 hover:border-blue-200 hover:bg-blue-50 hover:text-blue-700">
                            <i data-lucide="sparkles" class="mr-1 h-3 w-3"></i>
                            {{ $tag['label'] }}
                        </button>
                    @endforeach
                </span>
            </div>
        @endif

        <div class="relative">
            <div class="flex min-h-[2.5rem] items-center rounded-md border border-gray-300 bg-white px-3 py-2 shadow-sm {{ $toneClasses['focus'] }} focus-within:ring-1">
                <input type="search"
                       data-tag-selector-search
                       autocomplete="off"
                       placeholder="{{ __('admin.material_tags.selector_search_placeholder') }}"
                       class="min-w-0 flex-1 border-0 p-0 text-sm outline-none focus:ring-0">
            </div>
            <div data-tag-selector-menu class="absolute left-0 right-0 z-40 mt-1 hidden max-h-48 overflow-y-auto rounded-md border border-gray-200 bg-white py-1 shadow-lg">
                <div data-tag-selector-loading class="hidden px-3 py-2 text-sm text-gray-400">{{ __('admin.material_tags.selector_loading') }}</div>
                <div data-tag-selector-options></div>
                <div data-tag-selector-menu-empty class="hidden px-3 py-2 text-sm text-gray-400">{{ __('admin.material_tags.selector_empty') }}</div>
            </div>
        </div>
        <p class="text-xs text-gray-500">{{ __('admin.material_tags.selector_help') }}</p>
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

                function selectorParts(selector) {
                    return {
                        search: selector.querySelector('[data-tag-selector-search]'),
                        menu: selector.querySelector('[data-tag-selector-menu]'),
                        loading: selector.querySelector('[data-tag-selector-loading]'),
                        optionsContainer: selector.querySelector('[data-tag-selector-options]'),
                        menuEmpty: selector.querySelector('[data-tag-selector-menu-empty]'),
                        empty: selector.querySelector('[data-tag-selector-empty]'),
                        options: Array.from(selector.querySelectorAll('[data-tag-option]')),
                        chips: Array.from(selector.querySelectorAll('[data-tag-selector-chip]')),
                        checkboxes: Array.from(selector.querySelectorAll('[data-tag-checkbox]')),
                        recommendations: Array.from(selector.querySelectorAll('[data-tag-recommendation]')),
                    };
                }

                function optionClass(selector) {
                    return selector.getAttribute('data-tag-option-class') || 'hover:bg-blue-50 hover:text-blue-700';
                }

                function fieldName(selector) {
                    return selector.getAttribute('data-tag-field-name') || 'tag_ids';
                }

                function chipClasses(selector) {
                    const example = selector.querySelector('[data-tag-selector-chip]');
                    if (example) {
                        return Array.from(example.classList).filter((className) => !['hidden', 'inline-flex'].includes(className)).join(' ');
                    }

                    return 'group relative items-center rounded-full bg-blue-50 px-2.5 py-1 text-xs font-medium text-blue-700';
                }

                function ensureTagControls(selector, tagId, label) {
                    if (!selector || !tagId) {
                        return null;
                    }

                    let checkbox = selector.querySelector(`[data-tag-checkbox][data-tag-id="${CSS.escape(tagId)}"]`);
                    if (!checkbox) {
                        checkbox = document.createElement('input');
                        checkbox.type = 'checkbox';
                        checkbox.setAttribute('data-tag-checkbox', '');
                        checkbox.setAttribute('data-tag-id', tagId);
                        checkbox.name = fieldName(selector) + '[]';
                        checkbox.value = tagId;
                        checkbox.className = 'hidden';
                        selector.appendChild(checkbox);
                    }

                    let chip = selector.querySelector(`[data-tag-selector-chip][data-tag-id="${CSS.escape(tagId)}"]`);
                    if (!chip) {
                        chip = document.createElement('span');
                        chip.setAttribute('data-tag-selector-chip', '');
                        chip.setAttribute('data-tag-id', tagId);
                        chip.className = chipClasses(selector) + ' inline-flex';
                        chip.appendChild(document.createTextNode(label || tagId));

                        const removeButton = document.createElement('button');
                        removeButton.type = 'button';
                        removeButton.setAttribute('data-tag-selector-remove', '');
                        removeButton.setAttribute('data-tag-id', tagId);
                        removeButton.className = 'absolute -right-1 -top-1 hidden rounded-full bg-blue-600 p-0.5 text-white group-hover:inline-flex';
                        removeButton.title = @json(__('admin.material_tags.selector_remove'));
                        removeButton.innerHTML = '<i data-lucide="x" class="h-3 w-3"></i>';
                        chip.appendChild(removeButton);

                        const selected = selector.querySelector('[data-tag-selector-selected]');
                        const empty = selector.querySelector('[data-tag-selector-empty]');
                        selected?.insertBefore(chip, empty || null);
                    }

                    return checkbox;
                }

                function checkedIds(selector) {
                    return selectorParts(selector).checkboxes
                        .filter((checkbox) => checkbox.checked)
                        .map((checkbox) => checkbox.getAttribute('data-tag-id'));
                }

                function updateSelector(selector) {
                    const parts = selectorParts(selector);
                    const selected = checkedIds(selector);
                    const selectedClass = selector.getAttribute('data-selected-option-class') || 'bg-blue-50 text-blue-700';
                    const selectedClasses = selectedClass.split(/\s+/).filter(Boolean);

                    parts.chips.forEach((chip) => {
                        const isSelected = selected.includes(chip.getAttribute('data-tag-id'));
                        chip.classList.toggle('hidden', !isSelected);
                        chip.classList.toggle('inline-flex', isSelected);
                    });

                    parts.options.forEach((option) => {
                        const isSelected = selected.includes(option.getAttribute('data-tag-id'));
                        selectedClasses.forEach((className) => option.classList.toggle(className, isSelected));
                        option.querySelector('[data-tag-option-check]')?.classList.toggle('hidden', !isSelected);
                    });

                    parts.recommendations.forEach((recommendation) => {
                        const isSelected = selected.includes(recommendation.getAttribute('data-tag-id'));
                        recommendation.classList.toggle('hidden', isSelected);
                    });

                    if (parts.empty) {
                        parts.empty.classList.toggle('hidden', selected.length > 0);
                        parts.empty.classList.toggle('inline', selected.length === 0);
                    }
                    refreshIcons();
                }

                function sourceText(selector) {
                    const sourceSelector = selector.getAttribute('data-tag-recommendation-source') || '';
                    if (!sourceSelector) {
                        return '';
                    }

                    return Array.from(document.querySelectorAll(sourceSelector))
                        .map((input) => input.value || input.textContent || '')
                        .join(' ')
                        .trim();
                }

                function escapeHtml(value) {
                    return String(value).replace(/[&<>"']/g, function (char) {
                        return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'}[char] || char;
                    });
                }

                function renderRecommendations(selector, items) {
                    const box = selector.querySelector('[data-tag-selector-recommendations]');
                    const target = selector.querySelector('[data-tag-recommendation-items]');
                    if (!box || !target) {
                        return;
                    }

                    target.innerHTML = '';
                    (items || []).forEach((item) => {
                        if (!item || !item.id || !item.label) {
                            return;
                        }

                        const button = document.createElement('button');
                        button.type = 'button';
                        button.setAttribute('data-tag-recommendation', '');
                        button.setAttribute('data-tag-id', String(item.id));
                        button.className = 'inline-flex items-center rounded-full border border-gray-200 bg-gray-50 px-2 py-0.5 text-xs font-medium text-gray-700 hover:border-blue-200 hover:bg-blue-50 hover:text-blue-700';
                        button.innerHTML = '<i data-lucide="sparkles" class="mr-1 h-3 w-3"></i>' + escapeHtml(item.label);
                        target.appendChild(button);
                    });

                    const hasItems = target.querySelector('[data-tag-recommendation]') !== null;
                    box.classList.toggle('hidden', !hasItems);
                    box.classList.toggle('flex', hasItems);
                    updateSelector(selector);
                }

                function refreshRecommendations(selector) {
                    const url = selector.getAttribute('data-tag-recommendation-url') || '';
                    if (!url) {
                        return;
                    }
                    const text = sourceText(selector);
                    if (!text) {
                        renderRecommendations(selector, []);
                        return;
                    }

                    const requestUrl = new URL(url, window.location.origin);
                    requestUrl.searchParams.set('text', text);
                    checkedIds(selector).forEach((id) => requestUrl.searchParams.append('selected_ids[]', id));

                    fetch(requestUrl.toString(), {
                        headers: {'Accept': 'application/json'},
                        credentials: 'same-origin',
                    })
                        .then((response) => response.ok ? response.json() : {items: []})
                        .then((payload) => renderRecommendations(selector, payload.items || []))
                        .catch(() => renderRecommendations(selector, []));
                }

                function debounceRefresh(selector) {
                    window.clearTimeout(selector._tagRecommendationTimer);
                    selector._tagRecommendationTimer = window.setTimeout(() => refreshRecommendations(selector), 250);
                }

                function renderOptions(selector, items) {
                    const parts = selectorParts(selector);
                    if (!parts.optionsContainer) {
                        return;
                    }

                    parts.optionsContainer.innerHTML = '';
                    (items || []).forEach((item) => {
                        if (!item || !item.id || !item.label) {
                            return;
                        }

                        const button = document.createElement('button');
                        button.type = 'button';
                        button.setAttribute('data-tag-option', '');
                        button.setAttribute('data-tag-id', String(item.id));
                        button.setAttribute('data-tag-label', String(item.label).toLowerCase());
                        button.setAttribute('data-tag-option-text', String(item.label));
                        button.className = 'flex w-full items-center justify-between px-3 py-2 text-left text-sm text-gray-700 ' + optionClass(selector);
                        button.innerHTML = '<span class="min-w-0 truncate" title="' + escapeHtml(item.label) + '">' + escapeHtml(item.label) + '</span><i data-lucide="check" data-tag-option-check class="hidden h-4 w-4 shrink-0"></i>';
                        parts.optionsContainer.appendChild(button);
                    });

                    parts.menuEmpty?.classList.toggle('hidden', parts.optionsContainer.children.length > 0);
                    updateSelector(selector);
                }

                function searchOptions(selector) {
                    const parts = selectorParts(selector);
                    const url = selector.getAttribute('data-tag-search-url') || '';
                    if (!url) {
                        renderOptions(selector, []);
                        return;
                    }

                    const requestUrl = new URL(url, window.location.origin);
                    requestUrl.searchParams.set('q', (parts.search?.value || '').trim());
                    requestUrl.searchParams.set('limit', '20');
                    parts.loading?.classList.remove('hidden');
                    parts.menuEmpty?.classList.add('hidden');

                    fetch(requestUrl.toString(), {
                        headers: {'Accept': 'application/json'},
                        credentials: 'same-origin',
                    })
                        .then((response) => response.ok ? response.json() : {items: []})
                        .then((payload) => renderOptions(selector, payload.items || []))
                        .catch(() => renderOptions(selector, []))
                        .finally(() => parts.loading?.classList.add('hidden'));
                }

                function normalizeLabel(value) {
                    return String(value || '').trim().toLowerCase();
                }

                function selectTagOption(selector, item) {
                    if (!selector || !item || !item.id || !item.label) {
                        return false;
                    }

                    ensureTagControls(selector, String(item.id), String(item.label));
                    const checkbox = selector.querySelector(`[data-tag-checkbox][data-tag-id="${CSS.escape(String(item.id))}"]`);
                    const changed = checkbox ? !checkbox.checked : false;
                    if (checkbox) {
                        checkbox.checked = true;
                    }
                    updateSelector(selector);
                    if (changed) {
                        autoSubmitSelector(selector);
                    }

                    return changed;
                }

                function selectLabels(selector, labels) {
                    const url = selector?.getAttribute('data-tag-search-url') || '';
                    const uniqueLabels = Array.from(new Set((labels || []).map((label) => String(label || '').trim()).filter(Boolean)));
                    if (!selector || !url || uniqueLabels.length === 0) {
                        return Promise.resolve([]);
                    }

                    return Promise.all(uniqueLabels.map((label) => {
                        const requestUrl = new URL(url, window.location.origin);
                        requestUrl.searchParams.set('q', label);
                        requestUrl.searchParams.set('limit', '10');

                        return fetch(requestUrl.toString(), {
                            headers: {'Accept': 'application/json'},
                            credentials: 'same-origin',
                        })
                            .then((response) => response.ok ? response.json() : {items: []})
                            .then((payload) => {
                                const items = Array.isArray(payload.items) ? payload.items : [];
                                const normalized = normalizeLabel(label);
                                const exact = items.find((item) => normalizeLabel(item.label) === normalized)
                                    || items.find((item) => normalizeLabel(item.label).endsWith(':' + normalized))
                                    || items[0];
                                if (!exact) {
                                    return null;
                                }

                                selectTagOption(selector, exact);

                                return exact;
                            })
                            .catch(() => null);
                    })).then((items) => items.filter(Boolean));
                }

                function debounceSearch(selector, delay = 180) {
                    window.clearTimeout(selector._tagSearchTimer);
                    selector._tagSearchTimer = window.setTimeout(() => searchOptions(selector), delay);
                }

                function showMenu(selector) {
                    const parts = selectorParts(selector);
                    parts.menu?.classList.remove('hidden');
                    debounceSearch(selector, 0);
                    updateSelector(selector);
                }

                function autoSubmitSelector(selector) {
                    if (selector.getAttribute('data-tag-selector-auto-submit') !== '1') {
                        return;
                    }

                    const form = selector.closest('form');
                    if (!form || form.dataset.tagSelectorSubmitting === '1') {
                        return;
                    }

                    form.dataset.tagSelectorSubmitting = '1';
                    window.setTimeout(function () {
                        if (typeof form.requestSubmit === 'function') {
                            form.requestSubmit();
                            return;
                        }

                        form.submit();
                    }, 80);
                }

                document.addEventListener('focusin', function (event) {
                    const search = event.target.closest('[data-tag-selector-search]');
                    if (!search) {
                        return;
                    }

                    const selector = search.closest('[data-tag-selector]');
                    if (selector) {
                        showMenu(selector);
                    }
                });

                document.addEventListener('input', function (event) {
                    const search = event.target.closest('[data-tag-selector-search]');
                    if (!search) {
                        return;
                    }

                    const selector = search.closest('[data-tag-selector]');
                    if (selector) {
                        selectorParts(selector).menu?.classList.remove('hidden');
                        debounceSearch(selector);
                    }
                });

                document.addEventListener('click', function (event) {
	                    const option = event.target.closest('[data-tag-option]');
	                    if (option) {
	                        const selector = option.closest('[data-tag-selector]');
	                        const tagId = option.getAttribute('data-tag-id');
	                        const label = option.getAttribute('data-tag-option-text') || option.textContent || tagId || '';
	                        const checkbox = selector?.querySelector(`[data-tag-checkbox][data-tag-id="${CSS.escape(tagId || '')}"]`);
	                        const changed = checkbox ? !checkbox.checked : true;
	                        selectTagOption(selector, {id: tagId || '', label: label.trim()});
	                        const search = selector?.querySelector('[data-tag-selector-search]');
	                        if (search) {
	                            search.value = '';
	                            search.focus();
	                        }
	                        if (selector) {
	                            debounceSearch(selector, 0);
	                            debounceRefresh(selector);
	                        }
	                        return;
	                    }

                    const remove = event.target.closest('[data-tag-selector-remove]');
                    if (remove) {
                        const selector = remove.closest('[data-tag-selector]');
                        const tagId = remove.getAttribute('data-tag-id');
                        const checkbox = selector?.querySelector(`[data-tag-checkbox][data-tag-id="${CSS.escape(tagId || '')}"]`);
                        const changed = checkbox ? checkbox.checked : false;
                        if (checkbox) {
                            checkbox.checked = false;
                        }
                        if (selector) {
                            updateSelector(selector);
                            debounceRefresh(selector);
                            if (changed) {
                                autoSubmitSelector(selector);
                            }
                        }
                        event.preventDefault();
                        return;
                    }

                    const recommendation = event.target.closest('[data-tag-recommendation]');
                    if (recommendation) {
	                        const selector = recommendation.closest('[data-tag-selector]');
	                        const tagId = recommendation.getAttribute('data-tag-id');
	                        const label = recommendation.textContent || tagId || '';
	                        selectTagOption(selector, {id: tagId || '', label: label.trim()});
	                        recommendation.classList.add('hidden');
	                        if (selector) {
	                            debounceRefresh(selector);
	                        }
	                        event.preventDefault();
	                        return;
                    }

                    document.querySelectorAll('[data-tag-selector]').forEach((selector) => {
                        if (!selector.contains(event.target)) {
                            selector.querySelector('[data-tag-selector-menu]')?.classList.add('hidden');
                        }
                    });
                });

                document.addEventListener('input', function (event) {
                    document.querySelectorAll('[data-tag-selector][data-tag-recommendation-source]').forEach((selector) => {
                        const sourceSelector = selector.getAttribute('data-tag-recommendation-source') || '';
                        if (sourceSelector && event.target.matches(sourceSelector)) {
                            debounceRefresh(selector);
                        }
                    });
                });

	                document.querySelectorAll('[data-tag-selector]').forEach(updateSelector);
	                document.querySelectorAll('[data-tag-selector][data-tag-recommendation-url]').forEach((selector) => {
	                    if (selector.getAttribute('data-tag-recommendation-url')) {
	                        debounceRefresh(selector);
	                    }
	                });
	                window.GeoFlowTagSelector = Object.assign(window.GeoFlowTagSelector || {}, {
	                    selectLabels,
	                });
	            })();
        </script>
    @endpush
@endonce
