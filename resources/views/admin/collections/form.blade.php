@extends('admin.layouts.app')

@php
    $formAction = $isEdit
        ? route('admin.collections.update', ['collectionId' => (int) $collectionId])
        : route('admin.collections.store');
@endphp

@section('content')
    <div class="px-4 sm:px-0">
        <div class="mb-8 flex items-center space-x-4">
            <a href="{{ route('admin.collections.index') }}" class="text-gray-400 hover:text-gray-600">
                <i data-lucide="arrow-left" class="h-5 w-5"></i>
            </a>
            <div>
                <h1 class="text-2xl font-bold text-gray-900">{{ $isEdit ? __('admin.collections.edit_title') : __('admin.collections.create_title') }}</h1>
                <p class="mt-1 text-sm text-gray-600">{{ __('admin.collections.form_subtitle') }}</p>
            </div>
        </div>

        <div class="rounded-lg bg-white shadow">
            <div class="px-6 py-6">
                @if ($errors->any())
                    <div class="mb-6 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                        {{ $errors->first() }}
                    </div>
                @endif

                <form method="POST" action="{{ $formAction }}" class="space-y-6">
                    @csrf
                    @if ($isEdit)
                        @method('PUT')
                    @endif

                    <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
                        <div>
                            <label class="mb-2 block text-sm font-medium text-gray-700">{{ __('admin.collections.field_name') }}</label>
                            <input type="text" name="name" required maxlength="120" value="{{ old('name', (string) ($collectionForm['name'] ?? '')) }}" class="block w-full rounded-md border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-slate-500 focus:ring-slate-500" placeholder="{{ __('admin.collections.placeholder_name') }}">
                        </div>
                        <div>
                            <label class="mb-2 block text-sm font-medium text-gray-700">{{ __('admin.collections.field_slug') }}</label>
                            <input type="text" name="slug" maxlength="160" value="{{ old('slug', (string) ($collectionForm['slug'] ?? '')) }}" class="block w-full rounded-md border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-slate-500 focus:ring-slate-500" placeholder="{{ __('admin.collections.placeholder_slug') }}">
                            <p class="mt-1 text-xs text-gray-500">{{ __('admin.collections.slug_help') }}</p>
                        </div>
                    </div>

                    <div>
                        <label class="mb-2 block text-sm font-medium text-gray-700">{{ __('admin.collections.field_description') }}</label>
                        <textarea name="description" rows="4" class="block w-full rounded-md border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-slate-500 focus:ring-slate-500" placeholder="{{ __('admin.collections.placeholder_description') }}">{{ old('description', (string) ($collectionForm['description'] ?? '')) }}</textarea>
                    </div>

                    <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
                        <div>
                            <label class="mb-2 block text-sm font-medium text-gray-700">{{ __('admin.collections.field_status') }}</label>
                            <select name="status" class="block w-full rounded-md border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-slate-500 focus:ring-slate-500">
                                <option value="active" @selected(old('status', (string) ($collectionForm['status'] ?? 'active')) === 'active')>{{ __('admin.collections.status_active') }}</option>
                                <option value="inactive" @selected(old('status', (string) ($collectionForm['status'] ?? 'active')) === 'inactive')>{{ __('admin.collections.status_inactive') }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="mb-2 block text-sm font-medium text-gray-700">{{ __('admin.collections.field_sort_order') }}</label>
                            <input type="number" name="sort_order" min="0" max="999999" value="{{ old('sort_order', (int) ($collectionForm['sort_order'] ?? 0)) }}" class="block w-full rounded-md border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-slate-500 focus:ring-slate-500">
                        </div>
                    </div>

                    <div class="flex justify-end gap-3">
                        <a href="{{ route('admin.collections.index') }}" class="inline-flex items-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                            {{ __('admin.button.cancel') }}
                        </a>
                        <button type="submit" class="inline-flex items-center rounded-md border border-transparent bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800">
                            <i data-lucide="save" class="mr-2 h-4 w-4"></i>
                            {{ __('admin.button.save') }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection
