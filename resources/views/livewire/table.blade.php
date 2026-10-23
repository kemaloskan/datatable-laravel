{{--
  Acun DataTable for Livewire. The markup and class names match
  @acunsoft/datatable-dom, so its optional styles.css styles both.
  Every value is printed with {{ }} (escaped); nothing is rendered as HTML.
  Publish with `php artisan vendor:publish --tag=acun-datatable-views` to change it.
--}}
@php
    $columns = $this->dataTableColumns();
    $rows = $this->rows;
    $sorting = $this->sorting();
    $selectable = $this->selectable();
    $pageSelection = $selectable ? $this->pageSelection() : 'none';
    $selectedCount = $selectable ? $this->selectedCount() : 0;
    $hasSearch = collect($columns)->contains(fn ($column) => $column->isSearchable());
    $sizes = $this->pageSizeOptions();
    $rowActions = $this->dataTableRowActions();
    $bulkActions = $selectable ? $this->dataTableBulkActions() : [];
    $span = count($columns) + ($selectable ? 1 : 0) + ($rowActions === [] ? 0 : 1);
    $pageSize = $rows->perPage();
@endphp
<div class="adt">
    @if ($hasSearch)
        <div class="adt-search">
            <label>
                <span>{{ __('acun-datatable::table.search') }}</span>
                <input type="search" wire:model.live.debounce.300ms="search" autocomplete="off">
            </label>
        </div>
    @endif

    @if ($selectable && $selectedCount > 0)
        <div class="adt-selection" role="group" aria-label="{{ __('acun-datatable::table.bulk_actions') }}">
            <span class="adt-selection-count" role="status">{{ __('acun-datatable::table.selected', ['count' => $selectedCount]) }}</span>
            @foreach ($bulkActions as $action)
                <button type="button" class="adt-bulk-action" data-action="{{ $action->id() }}"
                    wire:click="runBulkAction({{ \Illuminate\Support\Js::from($action->id()) }})"
                    @if ($action->confirmText() !== null) wire:confirm="{{ $action->confirmText() }}" @endif>{{ $action->labelText() }}</button>
            @endforeach
            @if (! $this->allMatching && $rows->total() > $selectedCount)
                <button type="button" wire:click="selectAllMatching">{{ __('acun-datatable::table.select_matching', ['total' => $rows->total()]) }}</button>
            @endif
            <button type="button" class="adt-clear-selection" wire:click="clearSelection">{{ __('acun-datatable::table.clear_selection') }}</button>
        </div>
    @endif

    <div class="adt-table-wrap" wire:loading.class="adt-loading" wire:loading.attr="aria-busy">
        <div class="adt-notice" role="status" wire:loading>{{ __('acun-datatable::table.loading') }}</div>
        <div class="adt-scroll">
            <table class="adt-table">
                @if ($this->caption() !== null)
                    <caption>{{ $this->caption() }}</caption>
                @endif
                <thead>
                    <tr>
                        @if ($selectable)
                            <th scope="col" class="adt-select">
                                @if ($rows->isNotEmpty())
                                    <input type="checkbox"
                                        aria-label="{{ __('acun-datatable::table.select_all') }}"
                                        wire:key="adt-page-{{ $pageSelection }}"
                                        wire:click="togglePage"
                                        @checked($pageSelection === 'all')
                                        @if ($pageSelection === 'some') x-init="$el.indeterminate = true" @endif>
                                @endif
                            </th>
                        @endif
                        @foreach ($columns as $column)
                            @php
                                $position = collect($sorting)->search(fn ($rule) => $rule['field'] === $column->id());
                                $rule = $position === false ? null : $sorting[$position];
                            @endphp
                            <th scope="col" class="adt-th"
                                @if ($position === 0) aria-sort="{{ $rule['direction'] === 'asc' ? 'ascending' : 'descending' }}" @endif>
                                @if ($column->isSortable())
                                    <button type="button"
                                        class="adt-sort{{ $rule ? ' adt-sort--'.$rule['direction'] : '' }}"
                                        wire:click="sortBy({{ \Illuminate\Support\Js::from($column->id()) }}, $event.shiftKey)">
                                        {{ $column->headerText() }}<span class="adt-sort-mark" aria-hidden="true">{{ $rule ? ($rule['direction'] === 'asc' ? '▲' : '▼').(count($sorting) > 1 ? $position + 1 : '') : '' }}</span>
                                    </button>
                                @else
                                    {{ $column->headerText() }}
                                @endif
                            </th>
                        @endforeach
                        @if ($rowActions !== [])
                            <th scope="col" class="adt-th adt-actions">{{ __('acun-datatable::table.actions') }}</th>
                        @endif
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows->items() as $row)
                        @php $key = $this->rowKey($row); $selected = $selectable && $this->isSelected($key); @endphp
                        <tr class="adt-row{{ $selected ? ' adt-row--selected' : '' }}" data-row-id="{{ $key }}" wire:key="adt-row-{{ $key }}">
                            @if ($selectable)
                                <td class="adt-select">
                                    <input type="checkbox"
                                        aria-label="{{ __('acun-datatable::table.select_row', ['name' => $this->rowLabel($row)]) }}"
                                        wire:click="toggleRow({{ \Illuminate\Support\Js::from($key) }})"
                                        @checked($selected)>
                                </td>
                            @endif
                            @foreach ($columns as $column)
                                <td data-label="{{ $column->headerText() }}">{{ \Acunsoft\DataTable\Laravel\Support\Format::cell($column, $row) }}</td>
                            @endforeach
                            @if ($rowActions !== [])
                                <td class="adt-actions" data-label="{{ __('acun-datatable::table.actions') }}">
                                    @php $name = $this->rowLabel($row); @endphp
                                    @foreach ($rowActions as $action)
                                        @if ($action->isVisible($row))
                                            <button type="button" class="adt-action" data-action="{{ $action->id() }}"
                                                aria-label="{{ __('acun-datatable::table.row_action', ['action' => $action->labelText(), 'name' => $name]) }}"
                                                wire:click="runRowAction({{ \Illuminate\Support\Js::from($action->id()) }}, {{ \Illuminate\Support\Js::from($key) }})"
                                                @if ($action->confirmText() !== null) wire:confirm="{{ $action->confirmText() }}" @endif>{{ $action->labelText() }}</button>
                                        @endif
                                    @endforeach
                                </td>
                            @endif
                        </tr>
                    @empty
                        <tr class="adt-empty">
                            <td colspan="{{ $span }}">{{ __('acun-datatable::table.empty') }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <nav class="adt-pagination" aria-label="{{ __('acun-datatable::table.pagination') }}">
        <button type="button" class="adt-previous"
            @if ($rows->onFirstPage()) aria-disabled="true" @else wire:click="previousPage" @endif>{{ __('acun-datatable::table.previous') }}</button>
        <span class="adt-status" aria-live="polite">{{ __('acun-datatable::table.page_status', ['page' => $rows->currentPage(), 'pages' => max($rows->lastPage(), 1), 'total' => $rows->total()]) }}</span>
        <button type="button" class="adt-next"
            @if ($rows->hasMorePages()) wire:click="nextPage" @else aria-disabled="true" @endif>{{ __('acun-datatable::table.next') }}</button>
        <label class="adt-page-size">
            <span>{{ __('acun-datatable::table.page_size') }}</span>
            <select wire:model.live="pageSize">
                @foreach ($sizes as $size)
                    <option value="{{ $size }}" @selected($size === $pageSize)>{{ $size }}</option>
                @endforeach
            </select>
        </label>
    </nav>
</div>
