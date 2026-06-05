@php
    $selectorName = (string) ($selectorName ?? 'item_ids');
    $relationFieldName = (string) ($relationFieldName ?? 'relation_types');
    $defaultRelationFieldName = (string) ($defaultRelationFieldName ?? 'relation_type');
    $defaultRelationType = (string) old($defaultRelationFieldName, (string) ($defaultRelationType ?? 'supporting_reference'));
    $relationTypesById = collect(old($relationFieldName, $relationTypesById ?? []))
        ->mapWithKeys(static fn ($role, $id) => [(int) $id => (string) $role])
        ->all();
    $selectedIds = collect(old($selectorName, $selectedIds ?? []))
        ->map(static fn ($id) => (int) $id)
        ->filter(static fn (int $id): bool => $id > 0)
        ->values()
        ->all();
    $selectedOptions = collect($options ?? [])
        ->filter(static fn (array $option): bool => in_array((int) ($option['id'] ?? 0), $selectedIds, true))
        ->values();
    $tone = (string) ($tone ?? 'orange');
    $rowClass = (string) ($rowClass ?? 'flex flex-col gap-2 rounded-md border border-orange-100 bg-orange-50 px-3 py-2 sm:flex-row sm:items-center sm:justify-between');
    $labelClass = (string) ($relationLabelClass ?? 'text-sm font-medium text-orange-900');
    $selectClass = (string) ($relationSelectClass ?? 'block rounded-md border border-orange-200 bg-white px-3 py-1.5 text-sm shadow-sm focus:border-orange-500 focus:ring-orange-500');
    $relationSuffixText = (string) ($relationSuffixText ?? '关系');
@endphp

<div data-relation-multi-selector-block data-relation-field-name="{{ $relationFieldName }}" data-relation-suffix="{{ $relationSuffixText }}">
    <input type="hidden" name="{{ $defaultRelationFieldName }}" value="{{ $defaultRelationType }}">
    @include('admin.partials.option-multi-selector', [
        'name' => $selectorName,
        'options' => $options ?? [],
        'selectedIds' => $selectedIds,
        'tone' => $tone,
        'placeholder' => $placeholder ?? __('admin.entities.selector_placeholder'),
        'emptyText' => $emptyText ?? __('admin.entities.no_entity_options'),
        'noneSelectedText' => $noneSelectedText ?? __('admin.entities.selector_none_selected'),
        'removeText' => $removeText ?? __('admin.entities.selector_remove'),
    ])

    <div class="mt-3 space-y-2" data-relation-multi-selector-rows>
        @foreach ($selectedOptions as $selectedOption)
            @php
                $selectedItemId = (int) ($selectedOption['id'] ?? 0);
                $selectedRole = $relationTypesById[$selectedItemId] ?? $defaultRelationType;
            @endphp
            @if ($selectedItemId > 0)
                <div class="{{ $rowClass }}" data-relation-multi-selector-row data-id="{{ $selectedItemId }}">
                    <span class="{{ $labelClass }}">{{ $selectedOption['label'] }} - {{ $relationSuffixText }}</span>
                    <select name="{{ $relationFieldName }}[{{ $selectedItemId }}]" class="{{ $selectClass }}">
                        @foreach ($relationOptions ?? [] as $relationTypeOption)
                            <option value="{{ $relationTypeOption['value'] }}" @selected($selectedRole === $relationTypeOption['value'])>{{ $relationTypeOption['label'] }}</option>
                        @endforeach
                    </select>
                </div>
            @endif
        @endforeach
    </div>

    <template data-relation-multi-selector-template>
        <div class="{{ $rowClass }}" data-relation-multi-selector-row>
            <span class="{{ $labelClass }}" data-relation-multi-selector-label></span>
            <select class="{{ $selectClass }}" data-relation-multi-selector-select>
                @foreach ($relationOptions ?? [] as $relationTypeOption)
                    <option value="{{ $relationTypeOption['value'] }}" @selected($defaultRelationType === $relationTypeOption['value'])>{{ $relationTypeOption['label'] }}</option>
                @endforeach
            </select>
        </div>
    </template>

    @if (! empty($helpText))
        <p class="mt-2 text-xs text-orange-700">{{ $helpText }}</p>
    @endif
</div>

@once
    @push('scripts')
        <script>
            document.addEventListener('DOMContentLoaded', () => {
                document.querySelectorAll('[data-relation-multi-selector-block]').forEach((block) => {
                    const selector = block.querySelector('[data-option-multi-selector]');
                    const container = block.querySelector('[data-relation-multi-selector-rows]');
                    const template = block.querySelector('[data-relation-multi-selector-template]');
                    const relationFieldName = block.dataset.relationFieldName || 'relation_types';
                    const relationSuffix = block.dataset.relationSuffix || '关系';
                    if (!container || !template) {
                        return;
                    }

                    const syncRows = () => {
                        const selected = Array.from(selector?.querySelectorAll('[data-option-chip]') || []).map((chip) => ({
                            id: chip.dataset.optionId || '',
                            label: chip.dataset.optionLabel || chip.textContent.trim(),
                        }));
                        const selectedIds = new Set(selected.map((item) => item.id));

                        container.querySelectorAll('[data-relation-multi-selector-row]').forEach((row) => {
                            if (!selectedIds.has(row.dataset.id || '')) {
                                row.remove();
                            }
                        });

                        selected.forEach((item) => {
                            let row = container.querySelector(`[data-relation-multi-selector-row][data-id="${CSS.escape(item.id)}"]`);
                            if (!row) {
                                row = template.content.firstElementChild.cloneNode(true);
                                row.dataset.id = item.id;
                                const label = row.querySelector('[data-relation-multi-selector-label]');
                                const relationSelect = row.querySelector('[data-relation-multi-selector-select]');
                                if (label) {
                                    label.textContent = `${item.label} - ${relationSuffix}`;
                                }
                                if (relationSelect) {
                                    relationSelect.name = `${relationFieldName}[${item.id}]`;
                                    relationSelect.removeAttribute('data-relation-multi-selector-select');
                                }
                                container.appendChild(row);
                            }
                        });
                    };

                    block.addEventListener('option-selector:updated', syncRows);
                    syncRows();
                });
            });
        </script>
    @endpush
@endonce
