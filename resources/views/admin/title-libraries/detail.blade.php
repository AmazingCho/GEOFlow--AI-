@extends('admin.layouts.app')

@section('content')
    <div class="px-4 sm:px-0">
        <div class="mb-8 flex items-center justify-between">
            <div class="flex items-center space-x-4">
                <a href="{{ route('admin.title-libraries.index') }}" class="text-gray-400 hover:text-gray-600">
                    <i data-lucide="arrow-left" class="w-5 h-5"></i>
                </a>
                <div>
                    <h1 class="text-2xl font-bold text-gray-900">{{ $library->name }}</h1>
                    <p class="mt-1 text-sm text-gray-600">{{ __('admin.title_detail.subtitle') }}</p>
                    <div class="mt-2">
                        <span class="inline-flex rounded-full bg-slate-100 px-2.5 py-1 text-xs font-medium text-slate-700">
                            {{ $library->collection?->name ?? __('admin.collections.badge_unassigned') }}
                        </span>
                    </div>
                </div>
            </div>
            <div class="flex space-x-2">
                <button type="button" onclick="showImportModal()" class="inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                    <i data-lucide="upload" class="w-4 h-4 mr-2"></i>
                    {{ __('admin.title_detail.import_batch') }}
                </button>
                <button type="button" onclick="showAddModal()" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-green-600 hover:bg-green-700">
                    <i data-lucide="plus" class="w-4 h-4 mr-2"></i>
                    {{ __('admin.title_detail.add_title') }}
                </button>
                <a href="{{ route('admin.title-libraries.ai-generate', ['libraryId' => (int) $library->id]) }}" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700">
                    <i data-lucide="zap" class="w-4 h-4 mr-2"></i>
                    {{ __('admin.title_detail.ai_generate') }}
                </a>
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <i data-lucide="list" class="h-6 w-6 text-green-600"></i>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-gray-500 truncate">{{ __('admin.title_detail.total_titles') }}</dt>
                                <dd class="text-lg font-medium text-gray-900">{{ $titles->total() }}</dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>

            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <i data-lucide="calendar" class="h-6 w-6 text-blue-600"></i>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-gray-500 truncate">{{ __('admin.title_detail.created_date') }}</dt>
                                <dd class="text-lg font-medium text-gray-900">{{ optional($library->created_at)->format('Y-m-d') ?? '-' }}</dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>

            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <i data-lucide="trending-up" class="h-6 w-6 text-purple-600"></i>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-gray-500 truncate">{{ __('admin.title_detail.usage_total') }}</dt>
                                <dd class="text-lg font-medium text-gray-900">{{ $usageTotal }}</dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="bg-white shadow rounded-lg">
            <div class="flex flex-col gap-3 border-b border-gray-200 px-6 py-4 lg:flex-row lg:items-center lg:justify-between">
                <h3 class="text-lg font-medium text-gray-900">{{ __('admin.title_detail.list_title') }}</h3>
                @if (! $titles->isEmpty())
                    <div class="flex flex-wrap items-center gap-3">
                        <span class="text-sm text-gray-500" data-title-selected-label>
                            {{ __('admin.title_detail.bulk_selected', ['count' => 0]) }}
                        </span>
                        <select name="bulk_action" class="rounded-md border border-gray-300 px-3 py-1.5 text-xs font-medium text-gray-700 shadow-sm focus:border-green-500 focus:ring-green-500" data-title-bulk-action form="delete-title-form">
                            <option value="delete">{{ __('admin.material_bulk.action_delete') }}</option>
                            <option value="move">{{ __('admin.material_bulk.action_move') }}</option>
                            <option value="copy">{{ __('admin.material_bulk.action_copy') }}</option>
                        </select>
                        <select name="target_library_id" class="hidden min-w-[220px] rounded-md border border-gray-300 px-3 py-1.5 text-xs font-medium text-gray-700 shadow-sm focus:border-green-500 focus:ring-green-500" data-title-target-library form="delete-title-form">
                            <option value="">{{ __('admin.material_bulk.target_placeholder') }}</option>
                            @foreach (($targetLibraryOptions ?? []) as $targetLibrary)
                                <option value="{{ (int) $targetLibrary['id'] }}">
                                    {{ $targetLibrary['name'] }}@if ((string) ($targetLibrary['collection_name'] ?? '') !== '') / {{ $targetLibrary['collection_name'] }} @endif
                                </option>
                            @endforeach
                        </select>
                        <button type="button" class="hidden inline-flex items-center rounded-md border border-green-200 bg-green-50 px-3 py-1.5 text-xs font-semibold text-green-700 hover:bg-green-100 disabled:cursor-not-allowed disabled:opacity-50" data-title-bulk-organize disabled>
                            <i data-lucide="folder-input" class="mr-1 h-4 w-4"></i>
                            <span>{{ __('admin.material_bulk.submit_move') }}</span>
                        </button>
                        <button type="button" class="inline-flex items-center rounded-md border border-red-200 bg-red-50 px-3 py-1.5 text-xs font-semibold text-red-700 hover:bg-red-100 disabled:cursor-not-allowed disabled:opacity-50" data-title-bulk-delete disabled>
                            <i data-lucide="trash-2" class="mr-1 h-4 w-4"></i>
                            {{ __('admin.title_detail.bulk_delete') }}
                        </button>
                    </div>
                @endif
            </div>

            @if ($titles->isEmpty())
                <div class="px-6 py-8 text-center">
                    <i data-lucide="list" class="w-12 h-12 mx-auto text-gray-400 mb-4"></i>
                    <h3 class="text-lg font-medium text-gray-900 mb-2">{{ __('admin.title_detail.empty') }}</h3>
                    <p class="text-gray-500 mb-4">{{ __('admin.title_detail.empty_desc') }}</p>
                    <div class="flex justify-center space-x-2">
                        <button type="button" onclick="showAddModal()" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-green-600 hover:bg-green-700">
                            <i data-lucide="plus" class="w-4 h-4 mr-2"></i>
                            {{ __('admin.title_detail.add_title') }}
                        </button>
                        <button type="button" onclick="showImportModal()" class="inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                            <i data-lucide="upload" class="w-4 h-4 mr-2"></i>
                            {{ __('admin.title_detail.import_batch') }}
                        </button>
                    </div>
                </div>
            @else
                <div class="flex items-center justify-between gap-6 border-b border-gray-200 bg-gray-50 px-6 py-3 text-xs font-semibold uppercase tracking-wide text-gray-500">
                    <label class="inline-flex items-center gap-2 normal-case tracking-normal">
                        <input type="checkbox" class="rounded border-gray-300 text-green-600 focus:ring-green-500" data-title-select-all>
                        {{ __('admin.title_detail.bulk_select_all') }}
                    </label>
                    <div class="text-right">{{ __('admin.common.actions') }}</div>
                </div>
                <div class="divide-y divide-gray-200">
                    @foreach ($titles as $title)
                        <div class="px-6 py-4">
                            <div class="flex items-start justify-between gap-4">
                                <div class="pt-1">
                                    <input type="checkbox" form="delete-title-form" name="title_ids[]" value="{{ (int) $title->id }}" class="rounded border-gray-300 text-green-600 focus:ring-green-500" data-title-checkbox>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-center space-x-3">
                                        <h4 class="text-lg font-medium text-gray-900 break-all">{{ $title->title }}</h4>
                                        @if ((bool) $title->is_ai_generated)
                                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-blue-100 text-blue-800">
                                                <i data-lucide="zap" class="w-3 h-3 mr-1"></i>
                                                {{ __('admin.title_detail.ai_badge') }}
                                            </span>
                                        @endif
                                        @if ((string) ($title->keyword ?? '') !== '')
                                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-800">
                                                {{ $title->keyword }}
                                            </span>
                                        @endif
                                    </div>
                                    <div class="mt-2 flex items-center space-x-4 text-sm text-gray-500">
                                        <span>{{ __('admin.title_detail.usage_count', ['count' => (int) ($title->used_count ?? 0)]) }}</span>
                                        <span>{{ __('admin.title_detail.created_at', ['value' => optional($title->created_at)->format('Y-m-d H:i') ?? '-']) }}</span>
                                    </div>
                                </div>
                                <div class="flex items-center space-x-2">
                                    <button type="button" onclick="showEditModal({{ (int) $title->id }}, @js($title->title), @js((string) ($title->keyword ?? '')))" class="inline-flex items-center px-3 py-1.5 border border-gray-300 text-xs font-medium rounded text-gray-700 bg-white hover:bg-gray-50">
                                        <i data-lucide="pencil" class="w-4 h-4 mr-1"></i>
                                        {{ __('admin.button.edit') }}
                                    </button>
                                    <button type="button" onclick="deleteTitle({{ (int) $title->id }}, @js($title->title))" class="inline-flex items-center px-3 py-1.5 border border-transparent text-xs font-medium rounded text-white bg-red-600 hover:bg-red-700">
                                        <i data-lucide="trash-2" class="w-4 h-4 mr-1"></i>
                                        {{ __('admin.button.delete') }}
                                    </button>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>

                @if ($titles->lastPage() > 1)
                    <div class="px-6 py-4 border-t border-gray-200">
                        <div class="flex items-center justify-between">
                            <div class="text-sm text-gray-700">
                                {{ __('admin.title_detail.pagination', ['start' => $titles->firstItem(), 'end' => $titles->lastItem(), 'total' => $titles->total()]) }}
                            </div>
                            <div>
                                {{ $titles->links() }}
                            </div>
                        </div>
                    </div>
                @endif
            @endif
        </div>
    </div>

    <form method="POST" action="{{ route('admin.title-libraries.titles.delete', ['libraryId' => (int) $library->id]) }}" id="delete-title-form" class="hidden" data-delete-action="{{ route('admin.title-libraries.titles.delete', ['libraryId' => (int) $library->id]) }}" data-organize-action="{{ route('admin.title-libraries.titles.organize', ['libraryId' => (int) $library->id]) }}">
        @csrf
        <input type="hidden" name="title_ids[]" id="delete-title-id" value="">
    </form>

    <div id="title-bulk-delete-modal" class="hidden fixed inset-0 z-50">
        <div class="absolute inset-0 bg-slate-900/45" data-title-bulk-cancel></div>
        <div class="relative flex min-h-screen items-center justify-center p-4">
            <div class="w-full max-w-md rounded-2xl bg-white shadow-2xl ring-1 ring-slate-200">
                <div class="border-b border-slate-100 px-6 py-5">
                    <div class="flex items-start gap-4">
                        <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-red-50 text-red-600">
                            <i data-lucide="trash-2" class="h-5 w-5"></i>
                        </div>
                        <div>
                            <h3 class="text-lg font-semibold text-slate-900">{{ __('admin.title_detail.bulk_delete_title') }}</h3>
                            <p class="mt-1 text-sm leading-6 text-slate-600">{{ __('admin.title_detail.bulk_delete_desc') }}</p>
                        </div>
                    </div>
                </div>
                <div class="px-6 py-5">
                    <input type="text" class="block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-red-500 focus:ring-red-500" placeholder="{{ __('admin.title_detail.bulk_delete_placeholder') }}" data-title-bulk-confirm-input>
                </div>
                <div class="flex items-center justify-end gap-3 border-t border-slate-100 px-6 py-4">
                    <button type="button" class="inline-flex items-center rounded-lg border border-slate-200 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50" data-title-bulk-cancel>
                        {{ __('admin.button.cancel') }}
                    </button>
                    <button type="button" class="inline-flex items-center rounded-lg bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-700 disabled:cursor-not-allowed disabled:opacity-50" data-title-bulk-confirm disabled>
                        {{ __('admin.title_detail.bulk_delete_submit') }}
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div id="add-modal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
        <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
            <div class="mt-3">
                <h3 class="text-lg font-medium text-gray-900 mb-4">{{ __('admin.title_detail.modal_add') }}</h3>
                <form method="POST" action="{{ route('admin.title-libraries.titles.store', ['libraryId' => (int) $library->id]) }}">
                    @csrf
                    <div class="space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700">{{ __('admin.title_detail.field_title') }}</label>
                            <input type="text" name="title" required class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-green-500 focus:border-green-500 sm:text-sm" placeholder="{{ __('admin.title_detail.placeholder_title') }}">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">{{ __('admin.title_detail.field_keyword') }}</label>
                            <input type="text" name="keyword" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-green-500 focus:border-green-500 sm:text-sm" placeholder="{{ __('admin.title_detail.placeholder_keyword') }}">
                        </div>
                    </div>
                    <div class="mt-6 flex justify-end space-x-3">
                        <button type="button" onclick="hideAddModal()" class="px-4 py-2 border border-gray-300 rounded-md text-sm font-medium text-gray-700 hover:bg-gray-50">
                            {{ __('admin.button.cancel') }}
                        </button>
                        <button type="submit" class="px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-green-600 hover:bg-green-700">
                            {{ __('admin.button.add') }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div id="edit-modal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
        <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
            <div class="mt-3">
                <h3 class="text-lg font-medium text-gray-900 mb-4">{{ __('admin.title_detail.modal_edit') }}</h3>
                <form method="POST" id="edit-title-form" action="">
                    @csrf
                    @method('PUT')
                    <div class="space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700">{{ __('admin.title_detail.field_title') }}</label>
                            <input type="text" name="title" id="edit-title-input" required class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-green-500 focus:border-green-500 sm:text-sm" placeholder="{{ __('admin.title_detail.placeholder_title') }}">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">{{ __('admin.title_detail.field_keyword') }}</label>
                            <input type="text" name="keyword" id="edit-keyword-input" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-green-500 focus:border-green-500 sm:text-sm" placeholder="{{ __('admin.title_detail.placeholder_keyword') }}">
                        </div>
                    </div>
                    <div class="mt-6 flex justify-end space-x-3">
                        <button type="button" onclick="hideEditModal()" class="px-4 py-2 border border-gray-300 rounded-md text-sm font-medium text-gray-700 hover:bg-gray-50">
                            {{ __('admin.button.cancel') }}
                        </button>
                        <button type="submit" class="px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-green-600 hover:bg-green-700">
                            {{ __('admin.button.save') }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div id="import-modal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
        <div class="relative top-10 mx-auto p-5 border w-2/3 max-w-2xl shadow-lg rounded-md bg-white">
            <div class="mt-3">
                <h3 class="text-lg font-medium text-gray-900 mb-4">{{ __('admin.title_detail.modal_import') }}</h3>
                <form method="POST" action="{{ route('admin.title-libraries.import', ['libraryId' => (int) $library->id]) }}">
                    @csrf
                    <div class="space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700">{{ __('admin.title_detail.field_titles') }}</label>
                            <textarea name="titles_text" rows="10" required class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-green-500 focus:border-green-500 sm:text-sm" placeholder="{{ __('admin.title_detail.placeholder_titles') }}"></textarea>
                        </div>
                        <div class="text-sm text-gray-500">
                            <p class="mb-2">{{ __('admin.title_detail.import_format_title') }}</p>
                            <ul class="list-disc list-inside space-y-1">
                                <li>{{ __('admin.title_detail.import_format_line') }}</li>
                                <li>{{ __('admin.title_detail.import_format_pipe') }}</li>
                                <li>{{ __('admin.title_detail.import_format_dedupe') }}</li>
                            </ul>
                        </div>
                    </div>
                    <div class="mt-6 flex justify-end space-x-3">
                        <button type="button" onclick="hideImportModal()" class="px-4 py-2 border border-gray-300 rounded-md text-sm font-medium text-gray-700 hover:bg-gray-50">
                            {{ __('admin.button.cancel') }}
                        </button>
                        <button type="submit" class="px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-green-600 hover:bg-green-700">
                            {{ __('admin.title_detail.import_button') }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        function showAddModal() {
            document.getElementById('add-modal').classList.remove('hidden');
        }

        function hideAddModal() {
            document.getElementById('add-modal').classList.add('hidden');
        }

        function showEditModal(titleId, titleText, keywordText) {
            const form = document.getElementById('edit-title-form');
            form.action = @json(route('admin.title-libraries.titles.update', ['libraryId' => (int) $library->id, 'titleId' => '__TITLE_ID__'])).replace('__TITLE_ID__', String(titleId));
            document.getElementById('edit-title-input').value = titleText || '';
            document.getElementById('edit-keyword-input').value = keywordText || '';
            document.getElementById('edit-modal').classList.remove('hidden');
        }

        function hideEditModal() {
            document.getElementById('edit-modal').classList.add('hidden');
        }

        function showImportModal() {
            document.getElementById('import-modal').classList.remove('hidden');
        }

        function hideImportModal() {
            document.getElementById('import-modal').classList.add('hidden');
        }

        function deleteTitle(titleId, titleName) {
            const confirmed = confirm(@json(__('admin.title_detail.confirm_delete', ['name' => '{name}'])).replace('{name}', titleName));
            if (!confirmed) {
                return;
            }

            document.querySelectorAll('[data-title-checkbox]').forEach((checkbox) => {
                checkbox.checked = false;
            });
            document.getElementById('delete-title-id').value = String(titleId);
            document.getElementById('delete-title-form').submit();
        }

        document.addEventListener('DOMContentLoaded', function () {
            const checkboxes = Array.from(document.querySelectorAll('[data-title-checkbox]'));
            const selectAll = document.querySelector('[data-title-select-all]');
            const label = document.querySelector('[data-title-selected-label]');
            const deleteButton = document.querySelector('[data-title-bulk-delete]');
            const organizeButton = document.querySelector('[data-title-bulk-organize]');
            const bulkAction = document.querySelector('[data-title-bulk-action]');
            const targetLibrary = document.querySelector('[data-title-target-library]');
            const modal = document.getElementById('title-bulk-delete-modal');
            const confirmInput = document.querySelector('[data-title-bulk-confirm-input]');
            const confirmButton = document.querySelector('[data-title-bulk-confirm]');
            const form = document.getElementById('delete-title-form');
            const singleDeleteInput = document.getElementById('delete-title-id');
            const confirmText = '确认删除';

            if (!checkboxes.length || !form || !modal) {
                return;
            }

            const selectedCount = () => checkboxes.filter((checkbox) => checkbox.checked).length;

            const updateBulkState = () => {
                const count = selectedCount();
                if (label) {
                    label.textContent = @js(__('admin.title_detail.bulk_selected', ['count' => '__COUNT__'])).replace('__COUNT__', count);
                }
                if (deleteButton) {
                    deleteButton.disabled = count === 0;
                }
                if (organizeButton) {
                    organizeButton.disabled = count === 0;
                }
                if (selectAll) {
                    selectAll.checked = count > 0 && count === checkboxes.length;
                    selectAll.indeterminate = count > 0 && count < checkboxes.length;
                }
            };

            const updateBulkControls = () => {
                const action = bulkAction?.value || 'delete';
                const organizing = action === 'move' || action === 'copy';
                targetLibrary?.classList.toggle('hidden', !organizing);
                organizeButton?.classList.toggle('hidden', !organizing);
                deleteButton?.classList.toggle('hidden', organizing);
                if (organizeButton) {
                    organizeButton.querySelector('span').textContent = action === 'copy'
                        ? @js(__('admin.material_bulk.submit_copy'))
                        : @js(__('admin.material_bulk.submit_move'));
                    organizeButton.querySelector('i')?.setAttribute('data-lucide', action === 'copy' ? 'copy' : 'folder-input');
                    if (typeof lucide !== 'undefined') {
                        lucide.createIcons();
                    }
                }
            };

            const closeBulkModal = () => {
                modal.classList.add('hidden');
                if (confirmInput) {
                    confirmInput.value = '';
                }
                if (confirmButton) {
                    confirmButton.disabled = true;
                }
            };

            checkboxes.forEach((checkbox) => checkbox.addEventListener('change', updateBulkState));
            bulkAction?.addEventListener('change', () => {
                updateBulkControls();
                updateBulkState();
            });
            if (selectAll) {
                selectAll.addEventListener('change', () => {
                    checkboxes.forEach((checkbox) => {
                        checkbox.checked = selectAll.checked;
                    });
                    updateBulkState();
                });
            }

            if (deleteButton) {
                deleteButton.addEventListener('click', () => {
                    if (selectedCount() === 0) {
                        return;
                    }
                    modal.classList.remove('hidden');
                    confirmInput?.focus();
                });
            }

            if (organizeButton) {
                organizeButton.addEventListener('click', () => {
                    const count = selectedCount();
                    if (count === 0) {
                        return;
                    }
                    if (!targetLibrary?.value) {
                        alert(@json(__('admin.material_bulk.error_target_required')));
                        return;
                    }
                    const action = bulkAction?.value || 'move';
                    const confirmTemplate = action === 'copy'
                        ? @json(__('admin.material_bulk.confirm_copy', ['count' => '{count}']))
                        : @json(__('admin.material_bulk.confirm_move', ['count' => '{count}']));
                    if (!confirm(confirmTemplate.replace('{count}', String(count)))) {
                        return;
                    }
                    if (singleDeleteInput) {
                        singleDeleteInput.value = '';
                    }
                    form.action = form.dataset.organizeAction || form.action;
                    form.submit();
                });
            }

            if (confirmInput) {
                confirmInput.addEventListener('input', () => {
                    if (confirmButton) {
                        confirmButton.disabled = confirmInput.value.trim() !== confirmText;
                    }
                });
            }

            document.querySelectorAll('[data-title-bulk-cancel]').forEach((element) => {
                element.addEventListener('click', closeBulkModal);
            });

            if (confirmButton) {
                confirmButton.addEventListener('click', () => {
                    if (confirmInput?.value.trim() !== confirmText || selectedCount() === 0) {
                        return;
                    }
                    if (singleDeleteInput) {
                        singleDeleteInput.value = '';
                    }
                    form.submit();
                });
            }

            updateBulkControls();
            updateBulkState();
        });

        window.onclick = function (event) {
            const addModal = document.getElementById('add-modal');
            const editModal = document.getElementById('edit-modal');
            const importModal = document.getElementById('import-modal');
            const bulkDeleteModal = document.getElementById('title-bulk-delete-modal');

            if (event.target === addModal) {
                hideAddModal();
            }

            if (event.target === editModal) {
                hideEditModal();
            }

            if (event.target === importModal) {
                hideImportModal();
            }

            if (event.target === bulkDeleteModal) {
                bulkDeleteModal.classList.add('hidden');
            }
        };
    </script>
@endpush
