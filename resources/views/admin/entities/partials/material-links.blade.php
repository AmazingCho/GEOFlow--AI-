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
            @endphp
            <div>
                <label class="mb-2 block text-sm font-medium text-gray-700">{{ $group['label'] }}</label>
                @if ($fieldName === 'knowledge_base_ids')
                    @include('admin.partials.relation-multi-selector', [
                        'selectorName' => $fieldName,
                        'options' => $materialOptions[$fieldName] ?? [],
                        'selectedIds' => $selectedIds,
                        'relationFieldName' => 'knowledge_relation_types',
                        'defaultRelationFieldName' => 'knowledge_relation_type',
                        'defaultRelationType' => (string) ($knowledgeRelationType ?? 'supporting_reference'),
                        'relationTypesById' => $knowledgeRelationTypesById,
                        'relationOptions' => $knowledgeRelationTypeOptions ?? [],
                        'tone' => $group['tone'],
                        'placeholder' => $group['placeholder'],
                        'emptyText' => __('admin.entities.material_selector_empty'),
                        'noneSelectedText' => __('admin.entities.material_selector_none_selected'),
                        'removeText' => __('admin.entities.material_selector_remove'),
                        'relationSuffixText' => __('admin.entities.relation_suffix'),
                        'helpText' => __('admin.entities.knowledge_relation_per_item_help'),
                    ])
                @else
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
                @endif
            </div>
        @endforeach
    </div>
</div>
