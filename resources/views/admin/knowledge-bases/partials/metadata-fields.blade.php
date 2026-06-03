@php
    $metadataFieldClass = $class ?? 'w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm leading-6 shadow-sm transition focus:border-orange-500 focus:ring-orange-500';
    $selectedType = old('knowledge_type', (string) ($knowledgeForm['knowledge_type'] ?? ($knowledgeBase->knowledge_type ?? 'reference')));
    $selectedRole = old('knowledge_role', (string) ($knowledgeForm['knowledge_role'] ?? ($knowledgeBase->knowledge_role ?? 'supporting_context')));
    $selectedImportance = (string) old('importance', (string) ($knowledgeForm['importance'] ?? ($knowledgeBase->importance ?? '3')));
    $typeLabels = collect($knowledgeTypeOptions ?? [])->mapWithKeys(static fn (array $option): array => [(string) $option['value'] => (string) $option['label']])->all();
    $roleLabels = collect($knowledgeRoleOptions ?? [])->mapWithKeys(static fn (array $option): array => [(string) $option['value'] => (string) $option['label']])->all();
    $importanceLabels = collect($importanceOptions ?? [])->mapWithKeys(static fn (array $option): array => [(string) $option['value'] => (string) $option['label']])->all();
    $purposeOptions = [
        ['value' => 'reference', 'type' => 'reference', 'role' => 'supporting_context', 'importance' => '3'],
        ['value' => 'product_manual', 'type' => 'product_manual', 'role' => 'primary_source', 'importance' => '5'],
        ['value' => 'technical_spec', 'type' => 'technical_spec', 'role' => 'primary_source', 'importance' => '5'],
        ['value' => 'faq', 'type' => 'faq', 'role' => 'supporting_context', 'importance' => '4'],
        ['value' => 'troubleshooting', 'type' => 'troubleshooting', 'role' => 'supporting_context', 'importance' => '4'],
        ['value' => 'competitor_analysis', 'type' => 'competitor_analysis', 'role' => 'comparison_reference', 'importance' => '3'],
        ['value' => 'policy', 'type' => 'policy', 'role' => 'constraint', 'importance' => '5'],
        ['value' => 'marketing_copy', 'type' => 'marketing_copy', 'role' => 'style_reference', 'importance' => '2'],
        ['value' => 'archive', 'type' => 'reference', 'role' => 'archive', 'importance' => '1'],
        ['value' => 'other', 'type' => 'other', 'role' => 'supporting_context', 'importance' => '3'],
    ];
    $selectedPurpose = old('knowledge_purpose', '');
    if ($selectedPurpose === '') {
        $matchedPurpose = collect($purposeOptions)->first(static fn (array $option): bool => $option['type'] === $selectedType && $option['role'] === $selectedRole && $option['importance'] === $selectedImportance);
        $selectedPurpose = is_array($matchedPurpose) ? (string) $matchedPurpose['value'] : 'custom';
    }
    $purposePayload = collect($purposeOptions)->map(static fn (array $option): array => [
        'value' => $option['value'],
        'type' => $option['type'],
        'role' => $option['role'],
        'importance' => $option['importance'],
        'label' => (string) __('admin.knowledge_bases.knowledge_purpose.'.$option['value']),
        'description' => (string) __('admin.knowledge_bases.knowledge_purpose_help.'.$option['value']),
    ])->values()->all();
    $purposeDescriptionDefault = (string) __('admin.knowledge_bases.knowledge_purpose_desc');
    $purposeCurrentMappingTemplate = (string) __('admin.knowledge_bases.knowledge_purpose_current_mapping', [
        'type' => '__TYPE__',
        'role' => '__ROLE__',
        'importance' => '__IMPORTANCE__',
    ]);
@endphp

<div data-knowledge-purpose-widget data-purpose-options='@json($purposePayload)' data-type-labels='@json($typeLabels)' data-role-labels='@json($roleLabels)' data-importance-labels='@json($importanceLabels)' class="space-y-3">
    <input type="hidden" name="knowledge_type" value="{{ $selectedType }}" data-knowledge-purpose-type>
    <input type="hidden" name="knowledge_role" value="{{ $selectedRole }}" data-knowledge-purpose-role>
    <input type="hidden" name="importance" value="{{ $selectedImportance }}" data-knowledge-purpose-importance>

    <div>
        <label class="block text-sm font-medium text-gray-700 mb-2">{{ __('admin.knowledge_bases.field_knowledge_purpose') }}</label>
        <select name="knowledge_purpose" data-knowledge-purpose-select class="{{ $metadataFieldClass }}">
            @foreach ($purposeOptions as $option)
                <option value="{{ $option['value'] }}" @selected($selectedPurpose === $option['value'])>{{ __('admin.knowledge_bases.knowledge_purpose.'.$option['value']) }}</option>
            @endforeach
            <option value="custom" @selected($selectedPurpose === 'custom')>{{ __('admin.knowledge_bases.knowledge_purpose.custom') }}</option>
        </select>
        <p data-knowledge-purpose-description class="mt-2 text-xs leading-5 text-gray-500">{{ __('admin.knowledge_bases.knowledge_purpose_desc') }}</p>
    </div>

    @if (! empty($showRoleHelp))
        <details class="rounded-lg border border-orange-100 bg-orange-50/70 px-4 py-3">
            <summary class="cursor-pointer text-sm font-semibold text-orange-900">{{ __('admin.knowledge_bases.knowledge_purpose_advanced_summary') }}</summary>
            <p data-knowledge-purpose-current class="mt-2 text-xs leading-5 text-orange-900"></p>
            <div class="mt-3 grid grid-cols-1 gap-2 text-xs leading-5 text-orange-900 lg:grid-cols-2">
                @foreach ($knowledgeRoleOptions ?? [] as $option)
                    <div>
                        <span class="font-semibold">{{ $option['label'] }}：</span>{{ $option['instruction'] }}
                    </div>
                @endforeach
            </div>
        </details>
    @endif
</div>

@once
    @push('scripts')
        <script>
            document.addEventListener('DOMContentLoaded', function () {
                function parseJsonAttribute(node, name, fallback) {
                    try {
                        return JSON.parse(node.getAttribute(name) || '');
                    } catch (error) {
                        return fallback;
                    }
                }

                function applyPurpose(widget, option, writeHidden) {
                    const typeInput = widget.querySelector('[data-knowledge-purpose-type]');
                    const roleInput = widget.querySelector('[data-knowledge-purpose-role]');
                    const importanceInput = widget.querySelector('[data-knowledge-purpose-importance]');

                    if (option && writeHidden) {
                        typeInput.value = option.type;
                        roleInput.value = option.role;
                        importanceInput.value = option.importance;
                        [typeInput, roleInput, importanceInput].forEach((input) => {
                            input.dispatchEvent(new Event('change', {bubbles: true}));
                        });
                    }

                    const descriptions = parseJsonAttribute(widget, 'data-purpose-options', []);
                    const selectedDescription = option
                        ? (descriptions.find((item) => item.value === option.value)?.description || '')
                        : @json($purposeDescriptionDefault);
                    const descriptionTarget = widget.querySelector('[data-knowledge-purpose-description]');
                    if (descriptionTarget) {
                        descriptionTarget.textContent = selectedDescription;
                    }

                    const typeLabels = parseJsonAttribute(widget, 'data-type-labels', {});
                    const roleLabels = parseJsonAttribute(widget, 'data-role-labels', {});
                    const importanceLabels = parseJsonAttribute(widget, 'data-importance-labels', {});
                    const currentTarget = widget.querySelector('[data-knowledge-purpose-current]');
                    if (currentTarget) {
                        currentTarget.textContent = @json($purposeCurrentMappingTemplate)
                            .replace('__TYPE__', typeLabels[typeInput.value] || typeInput.value)
                            .replace('__ROLE__', roleLabels[roleInput.value] || roleInput.value)
                            .replace('__IMPORTANCE__', importanceLabels[importanceInput.value] || importanceInput.value);
                    }
                }

                function syncPurposeFromHidden(widget) {
                    const options = parseJsonAttribute(widget, 'data-purpose-options', []);
                    const select = widget.querySelector('[data-knowledge-purpose-select]');
                    const type = widget.querySelector('[data-knowledge-purpose-type]')?.value || '';
                    const role = widget.querySelector('[data-knowledge-purpose-role]')?.value || '';
                    const importance = widget.querySelector('[data-knowledge-purpose-importance]')?.value || '';
                    const matched = options.find((option) => option.type === type && option.role === role && option.importance === importance);

                    if (select) {
                        select.value = matched ? matched.value : 'custom';
                    }
                    applyPurpose(widget, matched || null, false);
                }

                document.querySelectorAll('[data-knowledge-purpose-widget]').forEach((widget) => {
                    const options = parseJsonAttribute(widget, 'data-purpose-options', []);
                    const select = widget.querySelector('[data-knowledge-purpose-select]');
                    select?.addEventListener('change', function () {
                        const selected = options.find((option) => option.value === select.value);
                        applyPurpose(widget, selected || null, Boolean(selected));
                    });
                    widget.querySelectorAll('[data-knowledge-purpose-type], [data-knowledge-purpose-role], [data-knowledge-purpose-importance]').forEach((input) => {
                        input.addEventListener('change', function () {
                            syncPurposeFromHidden(widget);
                        });
                    });
                    if (select && select.value !== 'custom') {
                        applyPurpose(widget, options.find((option) => option.value === select.value) || null, false);
                    } else {
                        syncPurposeFromHidden(widget);
                    }
                });
            });
        </script>
    @endpush
@endonce
