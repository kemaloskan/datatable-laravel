{{--
  Acun DataTable for Livewire. The markup and class names match
  @acunsoft/datatable-dom, so its optional styles.css styles both.
  Every value is printed with {{ }} (escaped); nothing is rendered as HTML.
  Arrange the controls with the component's tableLayout() method; publish with
  `php artisan vendor:publish --tag=acun-datatable-views` to change the markup.
--}}
@php
    $allColumns = $this->dataTableColumns();
    $columns = $this->dataTableVisibleColumns();
    $hiddenIds = $this->hiddenColumnIds();
    $rows = $this->rows;
    $sorting = $this->sorting();
    $selectable = $this->selectable();
    $pageSelection = $selectable ? $this->pageSelection() : 'none';
    [$selectedCount, $selectedExact] = $selectable ? $this->selectionSize() : [0, true];
    $exactTotal = $rows->totalIsExact();
    $rowActions = $this->dataTableRowActions();
    $bulkActions = $selectable ? $this->dataTableBulkActions() : [];
    $responsive = in_array($this->responsive(), ['cards', 'scroll', 'collapse', 'modal'], true) ? $this->responsive() : 'cards';
    $collapsible = in_array($responsive, ['collapse', 'modal'], true);
    $size = in_array($this->tableSize(), ['sm', 'md', 'lg'], true) ? $this->tableSize() : 'md';
    $levels = collect($columns)->map(fn ($column, $index) => $column->priorityAt($index))->values()->all();
    if ($rowActions !== []) { $levels[] = 2; }
    $span = count($columns) + ($selectable ? 1 : 0) + ($rowActions === [] ? 0 : 1) + ($collapsible ? 1 : 0);
    $layout = $this->dataTableLayout();
    $importable = in_array(\Acunsoft\DataTable\Laravel\Livewire\WithTableImport::class, class_uses_recursive($this), true);
    $importId = 'adt-import-'.$this->getId();
@endphp
<div class="adt{{ $size === 'md' ? '' : ' adt-size--'.$size }}">
    @include('acun-datatable::livewire.bar', ['position' => 'top'])

    @if ($importable)
        @include('acun-datatable::livewire.import')
    @endif

    @if ($selectable && $selectedCount > 0)
        <div class="adt-selection" role="group" aria-label="{{ $this->tableText('bulk_actions') }}">
            <span class="adt-selection-count" role="status">{{ $selectedExact
                ? $this->tableText('selected', $selectedCount, ['count' => \Acunsoft\DataTable\Laravel\Support\Format::count($selectedCount)])
                : $this->tableText('selected_many', ['count' => \Acunsoft\DataTable\Laravel\Support\Format::count($selectedCount)]) }}</span>
            @foreach ($bulkActions as $action)
                <button type="button" class="adt-bulk-action" data-action="{{ $action->id() }}"
                    wire:click="runBulkAction({{ \Illuminate\Support\Js::from($action->id()) }})"
                    @if ($action->confirmText() !== null) wire:confirm="{{ $action->confirmText() }}" @endif>{{ $action->labelText() }}</button>
            @endforeach
            @if (! $this->allMatching && ($rows->total() > $selectedCount || ! $exactTotal))
                <button type="button" wire:click="selectAllMatching">{{ $exactTotal
                    ? $this->tableText('select_matching', $rows->total(), ['total' => \Acunsoft\DataTable\Laravel\Support\Format::count($rows->total())])
                    : $this->tableText('select_matching_many', ['total' => \Acunsoft\DataTable\Laravel\Support\Format::count($rows->total())]) }}</button>
            @endif
            <button type="button" class="adt-clear-selection" wire:click="clearSelection">{{ $this->tableText('clear_selection') }}</button>
        </div>
    @endif

    <div class="adt-table-wrap adt-responsive--{{ $responsive }}" wire:loading.class="adt-loading" wire:loading.attr="aria-busy">
        <div class="adt-notice" role="status" wire:loading>{{ $this->tableText('loading') }}</div>
        <div class="adt-scroll">
            <table @class(['adt-table', 'adt-has-p2' => $collapsible && in_array(2, $levels, true), 'adt-has-p3' => $collapsible && in_array(3, $levels, true)])>
                @if ($this->caption() !== null)
                    <caption>{{ $this->caption() }}</caption>
                @endif
                <thead>
                    <tr>
                        @if ($collapsible)
                            <td class="adt-expand-cell"></td>
                        @endif
                        @if ($selectable)
                            <th scope="col" class="adt-select">
                                @if ($rows->isNotEmpty())
                                    <input type="checkbox"
                                        aria-label="{{ $this->tableText('select_all') }}"
                                        wire:key="adt-page-{{ $pageSelection }}"
                                        wire:click="togglePage"
                                        @checked($pageSelection === 'all')
                                        @if ($pageSelection === 'some') x-init="$el.indeterminate = true" @endif>
                                @endif
                            </th>
                        @endif
                        @foreach ($columns as $index => $column)
                            @php
                                $position = collect($sorting)->search(fn ($rule) => $rule['field'] === $column->id());
                                $rule = $position === false ? null : $sorting[$position];
                            @endphp
                            <th scope="col" class="adt-th{{ $collapsible ? ' adt-col--p'.$column->priorityAt($index) : '' }}"
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
                            <th scope="col" class="adt-th adt-actions{{ $collapsible ? ' adt-col--p2' : '' }}">{{ $this->tableText('actions') }}</th>
                        @endif
                    </tr>
                </thead>
                @forelse ($rows->items() as $row)
                    @php
                        $key = $this->rowKey($row);
                        $selected = $selectable && $this->isSelected($key);
                        $name = $this->rowLabel($row);
                        $detailId = 'adt-'.$this->getId().'-detail-'.$loop->index;
                    @endphp
                    {{-- One <tbody> per row, so a row and its details share their Alpine state. --}}
                    <tbody wire:key="adt-row-{{ $key }}" @if ($responsive === 'collapse') x-data="{ open: false }" @endif>
                        <tr class="adt-row{{ $selected ? ' adt-row--selected' : '' }}" data-row-id="{{ $key }}"
                            @if ($responsive === 'collapse') x-bind:class="{ 'adt-row--expanded': open }" @endif>
                            @if ($collapsible)
                                <td class="adt-expand-cell">
                                    @if ($responsive === 'collapse')
                                        <button type="button" class="adt-expand" aria-label="{{ $this->tableText('details', ['name' => $name]) }}"
                                            aria-expanded="false" aria-controls="{{ $detailId }}"
                                            x-on:click="open = ! open" x-bind:aria-expanded="open.toString()">
                                            <svg class="adt-icon" x-show="! open" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M12 8v8M8 12h8M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18z"/></svg>
                                            <svg class="adt-icon" x-show="open" style="display: none" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M8 12h8M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18z"/></svg>
                                        </button>
                                    @else
                                        <button type="button" class="adt-expand" aria-label="{{ $this->tableText('details', ['name' => $name]) }}" aria-haspopup="dialog"
                                            x-on:click="$el.nextElementSibling.showModal()">
                                            <svg class="adt-icon" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M12 8v8M8 12h8M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18z"/></svg>
                                        </button>
                                        {{-- wire:ignore.self keeps it open while Livewire updates its content. --}}
                                        <dialog class="adt-dialog" aria-labelledby="{{ $detailId }}-title" wire:ignore.self
                                            x-on:click="$event.target === $el && $el.close()" x-on:close="$el.previousElementSibling.focus()">
                                            <div class="adt-dialog-head">
                                                <h2 class="adt-dialog-title" id="{{ $detailId }}-title">{{ $this->tableText('details', ['name' => $name]) }}</h2>
                                                <button type="button" class="adt-dialog-close" aria-label="{{ $this->tableText('close') }}" x-on:click="$el.closest('dialog').close()">
                                                    <svg class="adt-icon" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M18 6 6 18M6 6l12 12"/></svg>
                                                </button>
                                            </div>
                                            @include('acun-datatable::livewire.details', ['all' => true])
                                        </dialog>
                                    @endif
                                </td>
                            @endif
                            @if ($selectable)
                                <td class="adt-select">
                                    <input type="checkbox"
                                        aria-label="{{ $this->tableText('select_row', ['name' => $name]) }}"
                                        wire:click="toggleRow({{ \Illuminate\Support\Js::from($key) }})"
                                        @checked($selected)>
                                </td>
                            @endif
                            @foreach ($columns as $index => $column)
                                <td data-label="{{ $column->headerText() }}" @if ($collapsible) class="adt-col--p{{ $column->priorityAt($index) }}" @endif>{{ \Acunsoft\DataTable\Laravel\Support\Format::cell($column, $row) }}</td>
                            @endforeach
                            @if ($rowActions !== [])
                                <td class="adt-actions{{ $collapsible ? ' adt-col--p2' : '' }}" data-label="{{ $this->tableText('actions') }}">
                                    @include('acun-datatable::livewire.row-actions')
                                </td>
                            @endif
                        </tr>
                        @if ($responsive === 'collapse')
                            <tr class="adt-detail" id="{{ $detailId }}" x-show="open" style="display: none">
                                <td colspan="{{ $span }}">@include('acun-datatable::livewire.details', ['all' => false])</td>
                            </tr>
                        @endif
                    </tbody>
                @empty
                    <tbody>
                        <tr class="adt-empty">
                            <td colspan="{{ $span }}">{{ $this->tableText('empty') }}</td>
                        </tr>
                    </tbody>
                @endforelse
            </table>
        </div>
    </div>

    @include('acun-datatable::livewire.bar', ['position' => 'bottom'])
</div>
