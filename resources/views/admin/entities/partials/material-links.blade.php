@php
    $materialGroups = [
        'knowledge_base_ids' => ['label' => __('admin.entities.link_group_knowledge'), 'tone' => 'orange'],
        'keyword_library_ids' => ['label' => __('admin.entities.link_group_keywords'), 'tone' => 'blue'],
        'title_library_ids' => ['label' => __('admin.entities.link_group_titles'), 'tone' => 'green'],
        'image_library_ids' => ['label' => __('admin.entities.link_group_image_libraries'), 'tone' => 'purple'],
        'image_ids' => ['label' => __('admin.entities.link_group_images'), 'tone' => 'purple'],
    ];
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
                $focusClass = match ($group['tone']) {
                    'purple' => 'focus:border-purple-500 focus:ring-purple-500',
                    'green' => 'focus:border-green-500 focus:ring-green-500',
                    'orange' => 'focus:border-orange-500 focus:ring-orange-500',
                    default => 'focus:border-blue-500 focus:ring-blue-500',
                };
            @endphp
            <div>
                <label class="mb-2 block text-sm font-medium text-gray-700">{{ $group['label'] }}</label>
                @if ($fieldName === 'knowledge_base_ids')
                    <div class="mb-3">
                        <label class="mb-1 block text-xs font-medium text-gray-500">{{ __('admin.entities.field_knowledge_relation_type') }}</label>
                        <select name="knowledge_relation_type" class="block w-full rounded-md border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-orange-500 focus:ring-orange-500">
                            @foreach ($knowledgeRelationTypeOptions ?? [] as $relationTypeOption)
                                <option value="{{ $relationTypeOption['value'] }}" @selected(old('knowledge_relation_type', (string) ($knowledgeRelationType ?? 'supporting_reference')) === $relationTypeOption['value'])>
                                    {{ $relationTypeOption['label'] }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                @endif
                <select name="{{ $fieldName }}[]" multiple size="{{ min(5, max(3, count($materialOptions[$fieldName] ?? []))) }}" class="block w-full rounded-md border-gray-300 px-3 py-2 text-sm shadow-sm {{ $focusClass }}">
                    @foreach ($materialOptions[$fieldName] ?? [] as $option)
                        <option value="{{ (int) $option['id'] }}" @selected(in_array((int) $option['id'], $selectedIds, true))>
                            {{ $option['label'] }}
                        </option>
                    @endforeach
                </select>
            </div>
        @endforeach
    </div>
</div>
