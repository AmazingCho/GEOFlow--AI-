@extends('admin.layouts.app')

@section('content')
    <div class="px-4 sm:px-0">
        <div class="mb-8 flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
            <div class="flex items-center space-x-4">
                <a href="{{ route('admin.material-tags.index') }}" class="text-gray-400 hover:text-gray-600">
                    <i data-lucide="arrow-left" class="h-5 w-5"></i>
                </a>
                <div>
                    <h1 class="text-2xl font-bold text-gray-900">{{ __('admin.material_tags.controlled_groups_page_title') }}</h1>
                    <p class="mt-1 text-sm text-gray-600">{{ __('admin.material_tags.controlled_groups_page_subtitle') }}</p>
                </div>
            </div>
            <a href="{{ route('admin.material-tags.index') }}" class="inline-flex w-fit items-center justify-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50">
                <i data-lucide="tags" class="mr-2 h-4 w-4"></i>
                {{ __('admin.material_tags.back_to_tags') }}
            </a>
        </div>

        <div class="mb-6 rounded-lg border border-amber-200 bg-amber-50 px-5 py-4 text-sm leading-6 text-amber-900">
            <div class="flex gap-3">
                <i data-lucide="triangle-alert" class="mt-0.5 h-4 w-4 shrink-0"></i>
                <p>{{ __('admin.material_tags.controlled_groups_safety_note') }}</p>
            </div>
        </div>

        <div class="overflow-hidden rounded-lg bg-white shadow">
            <div class="flex flex-col gap-4 border-b border-gray-200 px-6 py-5 lg:flex-row lg:items-start lg:justify-between">
                <div>
                    <h2 class="text-lg font-semibold text-gray-900">{{ __('admin.material_tags.controlled_groups_title') }}</h2>
                    <p class="mt-1 text-sm text-gray-600">{{ __('admin.material_tags.controlled_groups_desc') }}</p>
                </div>
                <form method="POST" action="{{ route('admin.material-tags.controlled-groups.store') }}" class="flex w-full gap-2 lg:w-auto">
                    @csrf
                    <input type="text" name="name" maxlength="100" placeholder="{{ __('admin.material_tags.controlled_group_placeholder') }}" class="min-w-0 flex-1 rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500 lg:w-56">
                    <button type="submit" class="inline-flex items-center rounded-md border border-transparent bg-blue-600 px-3 py-2 text-sm font-medium text-white hover:bg-blue-700">
                        <i data-lucide="plus" class="mr-1 h-4 w-4"></i>
                        {{ __('admin.material_tags.controlled_group_add') }}
                    </button>
                </form>
            </div>

            <div class="px-6 py-5">
                <div class="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-3">
                    @forelse (($controlledTagGroups ?? []) as $group)
                        <div class="rounded-lg border border-gray-200 bg-white px-3 py-3 shadow-sm">
                            <form method="POST" action="{{ route('admin.material-tags.controlled-groups.update', ['groupId' => (int) $group['id']]) }}" class="flex gap-2">
                                @csrf
                                @method('PUT')
                                <input type="text" name="name" value="{{ $group['name'] }}" maxlength="100" class="min-w-0 flex-1 rounded-md border border-gray-300 px-3 py-2 text-sm font-semibold text-blue-700 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                <button type="submit" class="inline-flex items-center rounded-md border border-gray-300 bg-white px-3 py-2 text-xs font-medium text-gray-700 hover:bg-gray-50">
                                    {{ __('admin.material_tags.controlled_group_save') }}
                                </button>
                            </form>
                            <form method="POST" action="{{ route('admin.material-tags.controlled-groups.delete', ['groupId' => (int) $group['id']]) }}" class="mt-2" onsubmit="return confirm(@js(__('admin.material_tags.controlled_group_delete_confirm', ['group' => $group['name']])));">
                                @csrf
                                <button type="submit" class="inline-flex items-center text-xs font-medium text-red-600 hover:text-red-700">
                                    <i data-lucide="trash-2" class="mr-1 h-3.5 w-3.5"></i>
                                    {{ __('admin.material_tags.controlled_group_delete') }}
                                </button>
                            </form>
                        </div>
                    @empty
                        <div class="rounded-lg border border-dashed border-gray-300 px-4 py-8 text-center text-sm text-gray-500 md:col-span-2 xl:col-span-3">
                            {{ __('admin.material_tags.group_empty') }}
                        </div>
                    @endforelse
                </div>
            </div>
        </div>
    </div>
@endsection
