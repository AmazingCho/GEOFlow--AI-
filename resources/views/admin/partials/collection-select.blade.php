@php
    $collectionSelectName = $name ?? 'collection_id';
    $selectedCollectionId = (string) old($collectionSelectName, (string) ($selectedId ?? ''));
    $collectionSelectLabel = $label ?? __('admin.collections.field_collection');
    $collectionSelectHelp = $help ?? __('admin.collections.field_collection_help');
    $collectionSelectClass = $class ?? 'block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-slate-500 focus:ring-slate-500';
    $collectionRequired = (bool) ($required ?? false);
    $collectionEmptyLabel = (string) ($emptyLabel ?? __('admin.collections.option_no_collection'));
@endphp

<div>
    <label class="mb-2 block text-sm font-medium text-gray-700">{{ $collectionSelectLabel }}@if($collectionRequired) <span class="text-red-500">*</span>@endif</label>
    <select name="{{ $collectionSelectName }}" class="{{ $collectionSelectClass }}" @required($collectionRequired)>
        <option value="">{{ $collectionEmptyLabel }}</option>
        @foreach (($collectionOptions ?? []) as $collectionOption)
            <option value="{{ (int) $collectionOption['id'] }}" @selected($selectedCollectionId === (string) $collectionOption['id'])>
                {{ $collectionOption['name'] }}
                @if (($collectionOption['status'] ?? 'active') !== 'active')
                    ({{ __('admin.collections.status_inactive') }})
                @endif
            </option>
        @endforeach
    </select>
    <p class="mt-1 text-xs text-gray-500">{{ $collectionSelectHelp }}</p>
</div>
