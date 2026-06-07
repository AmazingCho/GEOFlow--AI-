@php
    $relationTypes = $entityRelationService?->relationTypes() ?? collect();
@endphp

<div class="rounded-lg border border-purple-200 bg-purple-50/50 p-4" data-entity-relations-section>
    <div class="mb-4">
        <h3 class="text-base font-semibold text-purple-950">关联 Entity</h3>
        <p class="mt-1 text-sm text-purple-800">定义当前实体与其他实体之间的业务关系。系统会自动显示正反方向。</p>
    </div>

    {{-- Add new relation form --}}
    <div class="mb-4 grid grid-cols-1 gap-3 md:grid-cols-[minmax(0,1fr)_180px_100px_auto] md:items-end">
        <div>
            <label class="mb-1 block text-xs font-medium text-gray-600">目标 Entity</label>
            <select data-entity-relation-target class="block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-purple-500 focus:ring-purple-500">
                <option value="">搜索 Entity...</option>
            </select>
        </div>
        <div>
            <label class="mb-1 block text-xs font-medium text-gray-600">关系类型</label>
            <select data-entity-relation-type class="block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-purple-500 focus:ring-purple-500">
                @foreach ($relationTypes as $rt)
                    <option value="{{ (int) $rt->id }}">{{ $rt->forward_label }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="mb-1 block text-xs font-medium text-gray-600">强度</label>
            <input type="number" data-entity-relation-strength value="80" min="0" max="100" class="block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-purple-500 focus:ring-purple-500">
        </div>
        <div>
            <button type="button" data-add-entity-relation class="inline-flex items-center rounded-md border border-transparent bg-purple-600 px-4 py-2 text-sm font-medium text-white hover:bg-purple-700">
                <i data-lucide="plus" class="mr-2 h-4 w-4"></i>添加
            </button>
        </div>
    </div>

    {{-- Saved relations list --}}
    <div data-entity-relations-list class="space-y-2">
        @if ($isEdit && $entityId > 0 && $entityRelationService)
            @php
                $existing = $entityRelationService->relatedEntities((int) $entityId);
            @endphp
            @foreach (array_merge($existing['as_source'], $existing['as_target']) as $rel)
                @php
                    $label = $rel['direction'] === 'forward' ? ($rel['relation_type']['forward_label'] ?? '') : ($rel['relation_type']['reverse_label'] ?? '');
                    $otherName = $rel['entity']['name'] ?? 'Unknown';
                    $otherType = $rel['entity']['entity_type'] ?? '';
                @endphp
                <div class="flex items-center justify-between rounded-md border border-purple-100 bg-white px-3 py-2 text-sm" data-relation-row data-relation-id="{{ (int) ($rel['id'] ?? 0) }}">
                    <div>
                        <span class="font-medium text-purple-900">{{ $otherName }}</span>
                        <span class="mx-2 text-purple-400">&mdash;</span>
                        <span class="text-purple-700">{{ $label }}</span>
                        <span class="ml-2 text-xs text-gray-400">({{ $rel['strength'] ?? 80 }})</span>
                        @if (($otherType ?? '') !== '')
                            <span class="ml-2 rounded bg-purple-50 px-1.5 py-0.5 text-xs text-purple-600">{{ $otherType }}</span>
                        @endif
                    </div>
                    <button type="button" data-remove-entity-relation class="text-gray-400 hover:text-red-500">
                        <i data-lucide="x" class="h-4 w-4"></i>
                    </button>
                </div>
            @endforeach
        @endif
    </div>

    {{-- Hidden input for form submission --}}
    <input type="hidden" name="entity_relations" value="" data-entity-relations-input>
</div>

@push('scripts')
<script>
(() => {
    const section = document.querySelector('[data-entity-relations-section]');
    if (!section) return;

    const targetEl = section.querySelector('[data-entity-relation-target]');
    const typeEl = section.querySelector('[data-entity-relation-type]');
    const strengthEl = section.querySelector('[data-entity-relation-strength]');
    const addBtn = section.querySelector('[data-add-entity-relation]');
    const listEl = section.querySelector('[data-entity-relations-list]');
    const hiddenInput = section.querySelector('[data-entity-relations-input]');
    const entityId = {{ $isEdit ? (int) $entityId : 0 }};

    // Simple entity search via datalist
    const entityOptions = @json($entityOptionsForRelation ?? []);
    let datalistId = 'entity-relation-datalist-' + Date.now();

    // Build datalist for simple typeahead
    (() => {
        const dl = document.createElement('datalist');
        dl.id = datalistId;
        entityOptions.forEach(e => {
            const opt = document.createElement('option');
            opt.value = e.name + ' (' + e.entity_type + ')';
            opt.setAttribute('data-id', e.id);
            dl.appendChild(opt);
        });
        section.appendChild(dl);
        targetEl.setAttribute('list', datalistId);
    })();

    function getSelectedEntityId() {
        const val = targetEl.value.trim();
        const opt = Array.from(document.getElementById(datalistId)?.options || [])
            .find(o => o.value === val);
        return opt ? parseInt(opt.getAttribute('data-id')) : 0;
    }

    function buildRelationsJson() {
        const rows = listEl.querySelectorAll('[data-relation-row]');
        const data = [];
        rows.forEach(row => {
            data.push({
                source_entity_id: entityId > 0 ? entityId : 0,
                relation_type_id: parseInt(row.getAttribute('data-relation-type') || '0'),
                target_entity_id: parseInt(row.getAttribute('data-relation-target') || '0'),
                strength: parseInt(row.getAttribute('data-relation-strength') || '80'),
                source_type: 'manual',
            });
        });
        hiddenInput.value = JSON.stringify(data);
    }

    addBtn?.addEventListener('click', () => {
        const targetId = getSelectedEntityId();
        if (targetId <= 0 || targetId === entityId) {
            alert('请选择一个有效的目标 Entity。');
            return;
        }
        const typeId = parseInt(typeEl.value) || 0;
        if (typeId <= 0) return;

        const strength = Math.max(0, Math.min(100, parseInt(strengthEl.value) || 80));
        const typeLabel = typeEl.options[typeEl.selectedIndex]?.text || '';
        const targetName = targetEl.value.trim();

        // Check duplicate
        const existing = listEl.querySelector(`[data-relation-target="${targetId}"][data-relation-type="${typeId}"]`);
        if (existing) {
            existing.setAttribute('data-relation-strength', strength);
            existing.querySelector('.text-gray-400')?.parentElement?.querySelector?.('span:last-child')?.remove();
            return;
        }

        const div = document.createElement('div');
        div.className = 'flex items-center justify-between rounded-md border border-purple-100 bg-white px-3 py-2 text-sm';
        div.setAttribute('data-relation-row', '');
        div.setAttribute('data-relation-target', targetId);
        div.setAttribute('data-relation-type', typeId);
        div.setAttribute('data-relation-strength', strength);
        div.innerHTML = `
            <div>
                <span class="font-medium text-purple-900">${targetName}</span>
                <span class="mx-2 text-purple-400">&mdash;</span>
                <span class="text-purple-700">${typeLabel}</span>
                <span class="ml-2 text-xs text-gray-400">(${strength})</span>
            </div>
            <button type="button" class="text-gray-400 hover:text-red-500">
                <i data-lucide="x" class="h-4 w-4"></i>
            </button>`;
        div.querySelector('button')?.addEventListener('click', () => {
            div.remove();
            buildRelationsJson();
        });
        listEl.appendChild(div);
        buildRelationsJson();

        // Clear inputs
        targetEl.value = '';
        strengthEl.value = '80';

        if (window.lucide) window.lucide.createIcons();
    });

    // Delegate remove for pre-rendered rows
    listEl.addEventListener('click', (e) => {
        const btn = e.target.closest('[data-remove-entity-relation]');
        if (!btn) return;
        const row = btn.closest('[data-relation-row]');
        if (row) {
            row.remove();
            buildRelationsJson();
        }
    });

    // Init existing relations
    buildRelationsJson();
})();
</script>
@endpush
