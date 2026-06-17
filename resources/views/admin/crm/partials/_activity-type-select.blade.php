@php
    $name = $name ?? 'activity_type';
    $value = (string) old($name, $value ?? 'note');
    $label = $label ?? null;
    $selectClass = $class ?? 'block w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm shadow-sm outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500';
    $typeOptions = \App\Services\GeoFlow\CrmActivityService::typeOptions();
@endphp

@if ($label)
    <label class="block text-xs font-medium text-gray-500">{{ $label }}</label>
@endif
<select name="{{ $name }}" class="{{ $selectClass }}">
    @foreach ($typeOptions as $typeKey => $typeLabel)
        <option value="{{ $typeKey }}" @selected($value === $typeKey)>{{ $typeLabel }}</option>
    @endforeach
</select>
