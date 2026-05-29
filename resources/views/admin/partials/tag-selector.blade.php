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
    $tone = (string) ($tone ?? 'blue');
    $autoSubmit = (bool) ($autoSubmit ?? false);
    $includePresence = (bool) ($includePresence ?? true);
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

<div data-tag-selector data-tag-selector-auto-submit="{{ $autoSubmit ? '1' : '0' }}" data-selected-option-class="{{ $toneClasses['selectedOption'] }}" class="space-y-2">
    @if ($includePresence)
        <input type="hidden" name="{{ $fieldName }}_present" value="1">
    @endif
    @if ($tagOptions->isEmpty())
        <div class="rounded-md border border-dashed border-gray-300 px-3 py-2 text-xs text-gray-500">
            {{ __('admin.material_tags.selector_empty') }}
            <a href="{{ route('admin.material-tags.index') }}" class="font-medium text-blue-600 hover:text-blue-800">{{ __('admin.material_tags.selector_manage') }}</a>
        </div>
    @else
        <div data-tag-selector-selected class="flex min-h-8 flex-wrap items-center gap-2">
            @foreach ($tagOptions as $tag)
                <span data-tag-selector-chip data-tag-id="{{ $tag['id'] }}" class="group relative {{ empty($selectedTagMap[$tag['id']]) ? 'hidden' : 'inline-flex' }} items-center rounded-full {{ $toneClasses['chip'] }} px-2.5 py-1 text-xs font-medium">
                    {{ $tag['label'] }}
                    <button type="button" data-tag-selector-remove data-tag-id="{{ $tag['id'] }}" class="absolute -right-1 -top-1 hidden rounded-full {{ $toneClasses['chipButton'] }} p-0.5 text-white group-hover:inline-flex" title="{{ __('admin.material_tags.selector_remove') }}">
                        <i data-lucide="x" class="h-3 w-3"></i>
                    </button>
                </span>
            @endforeach
            <span data-tag-selector-empty class="{{ $selectedTagIds === [] ? 'inline' : 'hidden' }} text-xs text-gray-400">{{ __('admin.material_tags.selector_none_selected') }}</span>
        </div>

        <div class="relative">
            <div class="flex min-h-[2.5rem] items-center rounded-md border border-gray-300 bg-white px-3 py-2 shadow-sm {{ $toneClasses['focus'] }} focus-within:ring-1">
                <input type="search"
                       data-tag-selector-search
                       autocomplete="off"
                       placeholder="{{ __('admin.material_tags.selector_search_placeholder') }}"
                       class="min-w-0 flex-1 border-0 p-0 text-sm focus:ring-0">
            </div>
            <div data-tag-selector-menu class="absolute left-0 right-0 z-40 mt-1 hidden max-h-48 overflow-y-auto rounded-md border border-gray-200 bg-white py-1 shadow-lg">
                @foreach ($tagOptions as $tag)
                    <button type="button" data-tag-option data-tag-id="{{ $tag['id'] }}" data-tag-label="{{ mb_strtolower($tag['label'], 'UTF-8') }}" class="flex w-full items-center justify-between px-3 py-2 text-left text-sm text-gray-700 {{ $toneClasses['option'] }}">
                        <span class="min-w-0 truncate" title="{{ $tag['label'] }}">{{ $tag['label'] }}</span>
                        <i data-lucide="check" data-tag-option-check class="{{ empty($selectedTagMap[$tag['id']]) ? 'hidden' : '' }} h-4 w-4 shrink-0"></i>
                    </button>
                    <input type="checkbox"
                           data-tag-checkbox
                           data-tag-id="{{ $tag['id'] }}"
                           name="{{ $fieldName }}[]"
                           value="{{ $tag['id'] }}"
                           @checked(! empty($selectedTagMap[$tag['id']]))
                           class="hidden {{ $toneClasses['checkbox'] }}">
                @endforeach
            </div>
        </div>
        <p class="text-xs text-gray-500">{{ __('admin.material_tags.selector_help') }}</p>
    @endif
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
                        empty: selector.querySelector('[data-tag-selector-empty]'),
                        options: Array.from(selector.querySelectorAll('[data-tag-option]')),
                        chips: Array.from(selector.querySelectorAll('[data-tag-selector-chip]')),
                        checkboxes: Array.from(selector.querySelectorAll('[data-tag-checkbox]')),
                    };
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

                    if (parts.empty) {
                        parts.empty.classList.toggle('hidden', selected.length > 0);
                        parts.empty.classList.toggle('inline', selected.length === 0);
                    }
                    refreshIcons();
                }

                function filterSelector(selector) {
                    const parts = selectorParts(selector);
                    const query = (parts.search?.value || '').trim().toLowerCase();
                    parts.options.forEach((option) => {
                        const label = (option.getAttribute('data-tag-label') || '').toLowerCase();
                        option.classList.toggle('hidden', query !== '' && !label.includes(query));
                    });
                }

                function showMenu(selector) {
                    const parts = selectorParts(selector);
                    parts.menu?.classList.remove('hidden');
                    filterSelector(selector);
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
                        showMenu(selector);
                    }
                });

                document.addEventListener('click', function (event) {
                    const option = event.target.closest('[data-tag-option]');
                    if (option) {
                        const selector = option.closest('[data-tag-selector]');
                        const tagId = option.getAttribute('data-tag-id');
                        const checkbox = selector?.querySelector(`[data-tag-checkbox][data-tag-id="${CSS.escape(tagId || '')}"]`);
                        const changed = checkbox ? !checkbox.checked : false;
                        if (checkbox) {
                            checkbox.checked = true;
                        }
                        const search = selector?.querySelector('[data-tag-selector-search]');
                        if (search) {
                            search.value = '';
                            search.focus();
                        }
                        if (selector) {
                            filterSelector(selector);
                            updateSelector(selector);
                            if (changed) {
                                autoSubmitSelector(selector);
                            }
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
                            if (changed) {
                                autoSubmitSelector(selector);
                            }
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

                document.querySelectorAll('[data-tag-selector]').forEach(updateSelector);
            })();
        </script>
    @endpush
@endonce
