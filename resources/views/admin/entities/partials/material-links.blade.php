@php
    $materialGroups = [
        'knowledge_base_ids' => [
            'label' => __('admin.entities.link_group_knowledge'),
            'tone' => 'orange',
            'placeholder' => __('admin.entities.material_selector_search_knowledge'),
        ],
        'keyword_library_ids' => [
            'label' => __('admin.entities.link_group_keywords'),
            'tone' => 'blue',
            'placeholder' => __('admin.entities.material_selector_search_keywords'),
        ],
        'image_library_ids' => [
            'label' => __('admin.entities.link_group_image_libraries'),
            'tone' => 'purple',
            'placeholder' => __('admin.entities.material_selector_search_image_libraries'),
        ],
        'image_ids' => [
            'label' => __('admin.entities.link_group_images'),
            'tone' => 'purple',
            'placeholder' => __('admin.entities.material_selector_search_images'),
        ],
    ];
    $knowledgeRelationTypesById = collect(old('knowledge_relation_types', $knowledgeRelationTypesById ?? []))
        ->mapWithKeys(static fn ($role, $id) => [(int) $id => (string) $role])
        ->all();
@endphp

<div class="rounded-lg border border-slate-200 bg-slate-50/70 p-4">
    <div class="mb-4">
        <h3 class="text-base font-semibold text-slate-900">{{ __('admin.entities.field_linked_materials') }}</h3>
        <p class="mt-1 text-sm text-slate-600">{{ __('admin.entities.linked_materials_desc') }}</p>
    </div>
    <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
        @foreach ($materialGroups as $fieldName => $group)
            @php
                $selectedIds = collect(old($fieldName, $selectedMaterialIds[$fieldName] ?? []))
                    ->map(static fn ($id) => (int) $id)
                    ->filter(static fn (int $id): bool => $id > 0)
                    ->values()
                    ->all();
                $selectedOptions = collect($materialOptions[$fieldName] ?? [])
                    ->filter(static fn (array $option): bool => in_array((int) ($option['id'] ?? 0), $selectedIds, true))
                    ->values();
            @endphp
            <div @if ($fieldName === 'knowledge_base_ids') data-entity-knowledge-link-block @endif>
                <label class="mb-2 block text-sm font-medium text-gray-700">{{ $group['label'] }}</label>
                @if ($fieldName === 'knowledge_base_ids')
                    <input type="hidden" name="knowledge_relation_type" value="{{ old('knowledge_relation_type', (string) ($knowledgeRelationType ?? 'supporting_reference')) }}">
                @endif
                @include('admin.partials.option-multi-selector', [
                    'name' => $fieldName,
                    'options' => $materialOptions[$fieldName] ?? [],
                    'selectedIds' => $selectedIds,
                    'tone' => $group['tone'],
                    'placeholder' => $group['placeholder'],
                    'emptyText' => __('admin.entities.material_selector_empty'),
                    'noneSelectedText' => __('admin.entities.material_selector_none_selected'),
                    'removeText' => __('admin.entities.material_selector_remove'),
                ])
                @if ($fieldName === 'knowledge_base_ids')
                    <div class="mt-3 space-y-2" data-entity-knowledge-relations>
                        @foreach ($selectedOptions as $selectedOption)
                            @php
                                $selectedKnowledgeId = (int) ($selectedOption['id'] ?? 0);
                                $selectedRole = $knowledgeRelationTypesById[$selectedKnowledgeId] ?? old('knowledge_relation_type', (string) ($knowledgeRelationType ?? 'supporting_reference'));
                            @endphp
                            @if ($selectedKnowledgeId > 0)
                                <div class="flex flex-col gap-2 rounded-md border border-orange-100 bg-orange-50 px-3 py-2 sm:flex-row sm:items-center sm:justify-between" data-entity-knowledge-relation-row data-id="{{ (int) $selectedKnowledgeId }}">
                                    <span class="text-sm font-medium text-orange-900">{{ $selectedOption['label'] }}</span>
                                    <select name="knowledge_relation_types[{{ (int) $selectedKnowledgeId }}]" class="block rounded-md border-orange-200 px-3 py-1.5 text-sm shadow-sm focus:border-orange-500 focus:ring-orange-500">
                                        @foreach ($knowledgeRelationTypeOptions ?? [] as $relationTypeOption)
                                            <option value="{{ $relationTypeOption['value'] }}" @selected($selectedRole === $relationTypeOption['value'])>{{ $relationTypeOption['label'] }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            @endif
                        @endforeach
                    </div>
                    <template data-entity-knowledge-relation-template>
                        <div class="flex flex-col gap-2 rounded-md border border-orange-100 bg-orange-50 px-3 py-2 sm:flex-row sm:items-center sm:justify-between" data-entity-knowledge-relation-row>
                            <span class="text-sm font-medium text-orange-900" data-entity-knowledge-relation-label></span>
                            <select class="block rounded-md border-orange-200 px-3 py-1.5 text-sm shadow-sm focus:border-orange-500 focus:ring-orange-500" data-entity-knowledge-relation-select>
                                @foreach ($knowledgeRelationTypeOptions ?? [] as $relationTypeOption)
                                    <option value="{{ $relationTypeOption['value'] }}" @selected((string) ($knowledgeRelationType ?? 'supporting_reference') === $relationTypeOption['value'])>{{ $relationTypeOption['label'] }}</option>
                                @endforeach
                            </select>
                        </div>
                    </template>
                    <p class="mt-2 text-xs text-orange-700">{{ __('admin.entities.knowledge_relation_per_item_help') }}</p>
                @endif
            </div>
        @endforeach
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-entity-knowledge-link-block]').forEach((block) => {
        const selector = block.querySelector('[data-option-multi-selector]');
        const container = block.querySelector('[data-entity-knowledge-relations]');
        const template = block.querySelector('[data-entity-knowledge-relation-template]');
        if (!container || !template) {
            return;
        }

        const syncRows = () => {
            const selected = Array.from(selector?.querySelectorAll('[data-option-chip]') || []).map((chip) => ({
                id: chip.dataset.optionId || '',
                label: chip.dataset.optionLabel || chip.textContent.trim(),
            }));
            const selectedIds = new Set(selected.map((item) => item.id));

            container.querySelectorAll('[data-entity-knowledge-relation-row]').forEach((row) => {
                if (!selectedIds.has(row.dataset.id || '')) {
                    row.remove();
                }
            });

            selected.forEach((item) => {
                let row = container.querySelector(`[data-entity-knowledge-relation-row][data-id="${CSS.escape(item.id)}"]`);
                if (!row) {
                    row = template.content.firstElementChild.cloneNode(true);
                    row.dataset.id = item.id;
                    const label = row.querySelector('[data-entity-knowledge-relation-label]');
                    const relationSelect = row.querySelector('[data-entity-knowledge-relation-select]');
                    if (label) {
                        label.textContent = item.label;
                    }
                    if (relationSelect) {
                        relationSelect.name = `knowledge_relation_types[${item.id}]`;
                        relationSelect.removeAttribute('data-entity-knowledge-relation-select');
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
