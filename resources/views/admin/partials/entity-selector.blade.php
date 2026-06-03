@include('admin.partials.option-multi-selector', [
    'name' => $name ?? 'entity_ids',
    'options' => $entityOptions ?? [],
    'selectedIds' => $selectedEntityIds ?? [],
    'tone' => $tone ?? 'blue',
    'placeholder' => $placeholder ?? __('admin.entities.selector_placeholder'),
    'emptyText' => $emptyText ?? __('admin.entities.no_entity_options'),
    'noneSelectedText' => $noneSelectedText ?? __('admin.entities.selector_none_selected'),
    'removeText' => $removeText ?? __('admin.entities.selector_remove'),
])
