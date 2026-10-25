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
    $responsive = in_array($this->responsive(), ['cards', 'scroll', 'collapse', 'modal', 'auto'], true) ? $this->responsive() : 'cards';
    $collapsible = in_array($responsive, ['collapse', 'modal', 'auto'], true);
    $hiddenBelow = in_array($responsive, ['collapse', 'auto'], true); // hidden columns open below the row
    $detailView = $this->detailView();
    $expandable = $collapsible || $detailView !== null;
    $inline = $hiddenBelow || ($detailView !== null && $responsive !== 'modal'); // opens below the row
    $size = in_array($this->tableSize(), ['sm', 'md', 'lg'], true) ? $this->tableSize() : 'md';
    $scrollY = is_string($this->scrollY()) && preg_match('/^\d+(\.\d+)?(px|rem|em|vh|dvh|svh|%)$/', trim($this->scrollY())) ? trim($this->scrollY()) : null;
    $levels = collect($columns)->map(fn ($column, $index) => $column->priorityAt($index))->values()->all();
    if ($rowActions !== []) { $levels[] = 2; }
    $span = count($columns) + ($selectable ? 1 : 0) + ($rowActions === [] ? 0 : 1) + ($expandable ? 1 : 0);
    $widths = $this->columnWidthsPx();
    $resizable = $this->resizableColumns();
    $reorderable = $this->reorderableColumns();
    $fixed = $this->fixedColumns();
    $fixedStart = is_int($fixed['start'] ?? null) ? max(0, min(10, $fixed['start'])) : 0;
    $fixedEnd = is_int($fixed['end'] ?? null) ? max(0, min(10, $fixed['end'])) : 0;
    $layout = $this->dataTableLayout();
    $appliedFilters = $this->dataTableQuery()->filters;
    $filterChips = collect($allColumns)->filter(fn ($column) => $column->hasFilter())
        ->map(fn ($column) => [$column, \Acunsoft\DataTable\Laravel\Support\FilterPanel::describe($column, \Acunsoft\DataTable\Laravel\Support\FilterPanel::read($column, $appliedFilters))])
        ->filter(fn ($chip) => $chip[1] !== null)->values();
    $importable = in_array(\Acunsoft\DataTable\Laravel\Livewire\WithTableImport::class, class_uses_recursive($this), true);
    $importId = 'adt-import-'.$this->getId();
@endphp
<div class="adt{{ $size === 'md' ? '' : ' adt-size--'.$size }}">
    @include('acun-datatable::livewire.bar', ['position' => 'top'])

    @if ($filterChips->isNotEmpty())
        <div class="adt-filter-chips" role="group" aria-label="{{ $this->tableText('filters') }}">
            @foreach ($filterChips as [$column, $chip])
                <span class="adt-chip">{{ $chip }}<button type="button" class="adt-chip-remove" aria-label="{{ $this->tableText('remove_filter', ['column' => $column->headerText()]) }}"
                    wire:click="filterBy({{ \Illuminate\Support\Js::from($column->id()) }})"><svg class="adt-icon" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M18 6 6 18M6 6l12 12"/></svg></button></span>
            @endforeach
            <button type="button" class="adt-chip-clear" wire:click="clearPanelFilters">{{ $this->tableText('clear_filters') }}</button>
        </div>
    @endif

    @if ($importable)
        @include('acun-datatable::livewire.import')
    @endif

    @if ($this->tableTask !== null)
        @include('acun-datatable::livewire.task')
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
            {{-- Phones: the same choices as one select (styles.css shows it instead of the buttons there). --}}
            <select class="adt-control adt-selection-menu" aria-label="{{ $this->tableText('bulk_actions') }}" x-data
                x-on:change="const option = $el.selectedOptions[0]; $el.value = ''; if (option.value === '__matching') $wire.selectAllMatching(); else if (option.value === '__clear') $wire.clearSelection();@if (count($bulkActions) > 0) else if (! option.dataset.confirm || window.confirm(option.dataset.confirm)) $wire.runBulkAction(option.value)@endif">
                <option value="" disabled selected>{{ $this->tableText('actions') }}</option>
                @foreach ($bulkActions as $action)
                    <option value="{{ $action->id() }}" @if ($action->confirmText() !== null) data-confirm="{{ $action->confirmText() }}" @endif>{{ $action->labelText() }}</option>
                @endforeach
                @if (! $this->allMatching && ($rows->total() > $selectedCount || ! $exactTotal))
                    <option value="__matching">{{ $exactTotal
                        ? $this->tableText('select_matching', $rows->total(), ['total' => \Acunsoft\DataTable\Laravel\Support\Format::count($rows->total())])
                        : $this->tableText('select_matching_many', ['total' => \Acunsoft\DataTable\Laravel\Support\Format::count($rows->total())]) }}</option>
                @endif
                <option value="__clear">{{ $this->tableText('clear_selection') }}</option>
            </select>
        </div>
    @endif

    <div @class(['adt-table-wrap', 'adt-responsive--'.$responsive, 'adt-sticky-header' => $this->stickyHeader(), 'adt-scroll-y' => $scrollY !== null])
        @if ($scrollY !== null) style="--adt-scroll-y: {{ $scrollY }}" @endif
        @if ($fixedStart > 0 || $fixedEnd > 0 || $responsive === 'auto') x-data
            x-init="@if ($fixedStart > 0 || $fixedEnd > 0)@include('acun-datatable::livewire.pin', ['start' => $fixedStart, 'end' => $fixedEnd]);@endif @if ($responsive === 'auto')@include('acun-datatable::livewire.auto-responsive');@endif" @endif
        wire:loading.class="adt-loading" wire:loading.attr="aria-busy">
        <div class="adt-notice" role="status" wire:loading>{{ $this->tableText('loading') }}</div>
        <div class="adt-scroll">
            <table @class(['adt-table', 'adt-has-p2' => $collapsible && in_array(2, $levels, true), 'adt-has-p3' => $collapsible && in_array(3, $levels, true), 'adt-has-detail' => $detailView !== null, 'adt-fixed-layout' => $widths !== [], 'adt-user-widths' => $widths !== []])
                @if (isset($widths['__actions'])) style="--adt-actions-width: {{ $widths['__actions'] }}px" @endif
                @if ($selectable) x-data="{ anchor: null }" @endif>
                @if ($this->caption() !== null)
                    <caption>{{ $this->caption() }}</caption>
                @endif
                <thead>
                    <tr>
                        @if ($expandable)
                            <td class="adt-expand-cell"></td>
                        @endif
                        @if ($selectable)
                            <th scope="col" class="adt-select">
                                @if ($rows->isNotEmpty())
                                    {{-- The text shows only in cards (phones), where the header row is gone. --}}
                                    <label class="adt-select-all"><input type="checkbox"
                                        aria-label="{{ $this->tableText('select_all') }}"
                                        wire:key="adt-page-{{ $pageSelection }}"
                                        wire:click="togglePage"
                                        @checked($pageSelection === 'all')
                                        @if ($pageSelection === 'some') x-init="$el.indeterminate = true" @endif><span class="adt-select-all-text" aria-hidden="true">{{ $this->tableText('select_all') }}</span></label>
                                @endif
                            </th>
                        @endif
                        @foreach ($columns as $index => $column)
                            @php
                                $position = collect($sorting)->search(fn ($rule) => $rule['field'] === $column->id());
                                $rule = $position === false ? null : $sorting[$position];
                            @endphp
                            <th scope="col" class="adt-th{{ $collapsible ? ' adt-col--p'.$column->priorityAt($index) : '' }}{{ $column->isNumeric() ? ' adt-num' : '' }}"
                                data-column-id="{{ $column->id() }}" wire:key="adt-th-{{ $column->id() }}"
                                @if (isset($widths[$column->id()])) style="width: {{ $widths[$column->id()] }}px" @endif
                                @if ($reorderable) draggable="true" @include('acun-datatable::livewire.header-drag') @endif
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
                                @if ($resizable)
                                    @include('acun-datatable::livewire.resize-handle', ['width' => $widths[$column->id()] ?? null])
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
                        $detailOpen = $detailView !== null && $this->detailIsOpen($key);
                        $jsKey = \Illuminate\Support\Js::from($key);
                    @endphp
                    {{-- One <tbody> per row, so a row and its details share their Alpine state. --}}
                    <tbody wire:key="adt-row-{{ $key }}" @if ($inline) x-data="{ open: {{ $detailOpen ? 'true' : 'false' }} }" @endif>
                        <tr class="adt-row{{ $selected ? ' adt-row--selected' : '' }}{{ $detailOpen ? ' adt-row--expanded' : '' }}" data-row-id="{{ $key }}"
                            @if ($inline) x-bind:class="{ 'adt-row--expanded': open }" @endif>
                            @if ($expandable)
                                <td class="adt-expand-cell">
                                    @if ($inline)
                                        <button type="button" class="adt-expand" aria-label="{{ $this->tableText('details', ['name' => $name]) }}"
                                            aria-expanded="{{ $detailOpen ? 'true' : 'false' }}" aria-controls="{{ $detailId }}"
                                            x-on:click="open = ! open{{ $detailView !== null ? '; $wire.toggleDetail('.$jsKey.', open)' : '' }}" x-bind:aria-expanded="open.toString()">
                                            <svg class="adt-icon" x-show="! open" @if ($detailOpen) style="display: none" @endif viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M12 8v8M8 12h8M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18z"/></svg>
                                            <svg class="adt-icon" x-show="open" @if (! $detailOpen) style="display: none" @endif viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M8 12h8M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18z"/></svg>
                                        </button>
                                    @else
                                        <button type="button" class="adt-expand" aria-label="{{ $this->tableText('details', ['name' => $name]) }}" aria-haspopup="dialog"
                                            x-on:click="$el.nextElementSibling.showModal(){{ $detailView !== null ? '; $wire.toggleDetail('.$jsKey.', true)' : '' }}">
                                            <svg class="adt-icon" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M12 8v8M8 12h8M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18z"/></svg>
                                        </button>
                                        {{-- wire:ignore.self keeps it open while Livewire updates its content. --}}
                                        <dialog class="adt-dialog" aria-labelledby="{{ $detailId }}-title" wire:ignore.self
                                            x-on:click="$event.target === $el && $el.close()" x-on:close="$el.previousElementSibling.focus(){{ $detailView !== null ? '; $wire.toggleDetail('.$jsKey.', false)' : '' }}">
                                            <div class="adt-dialog-head">
                                                <h2 class="adt-dialog-title" id="{{ $detailId }}-title">{{ $this->tableText('details', ['name' => $name]) }}</h2>
                                                <button type="button" class="adt-dialog-close" aria-label="{{ $this->tableText('close') }}" x-on:click="$el.closest('dialog').close()">
                                                    <svg class="adt-icon" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M18 6 6 18M6 6l12 12"/></svg>
                                                </button>
                                            </div>
                                            @include('acun-datatable::livewire.details', ['all' => true])
                                            @if ($detailView !== null)
                                                @include('acun-datatable::livewire.detail-content')
                                            @endif
                                        </dialog>
                                    @endif
                                </td>
                            @endif
                            @if ($selectable)
                                <td class="adt-select">
                                    <input type="checkbox"
                                        aria-label="{{ $this->tableText('select_row', ['name' => $name]) }}"
                                        {{-- Shift+click selects (or clears) the rows between the last clicked one and this one. --}}
                                        x-on:click="const key = {{ \Illuminate\Support\Js::from($key) }}; ($event.shiftKey && anchor !== null && anchor !== key) ? $wire.selectRange(anchor, key, $el.checked) : $wire.toggleRow(key); anchor = key"
                                        @checked($selected)>
                                </td>
                            @endif
                            @foreach ($columns as $index => $column)
                                @php $cellClass = trim(($collapsible ? 'adt-col--p'.$column->priorityAt($index) : '').($column->isNumeric() ? ' adt-num' : '').($column->isEditable() ? ' adt-editable' : '')); @endphp
                                <td data-label="{{ $column->headerText() }}" @if ($cellClass !== '') class="{{ $cellClass }}" @endif>@if ($column->isEditable())@include('acun-datatable::livewire.edit-cell')@else @include('acun-datatable::livewire.cell')@endif</td>
                            @endforeach
                            @if ($rowActions !== [])
                                <td class="adt-actions{{ $collapsible ? ' adt-col--p2' : '' }}" data-label="{{ $this->tableText('actions') }}">
                                    @include('acun-datatable::livewire.row-actions', ['maxInline' => $this->maxInlineActions()])
                                </td>
                            @endif
                        </tr>
                        @if ($inline)
                            <tr class="adt-detail" id="{{ $detailId }}" x-show="open" @if (! $detailOpen) style="display: none" @endif>
                                <td colspan="{{ $span }}">
                                    @if ($hiddenBelow)
                                        @include('acun-datatable::livewire.details', ['all' => false])
                                    @endif
                                    @if ($detailView !== null)
                                        @include('acun-datatable::livewire.detail-content')
                                    @endif
                                </td>
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
                @php $totals = collect($columns)->contains(fn ($column) => $column->aggregateKind() !== null) ? $this->totals : null; @endphp
                @if ($totals !== null)
                    {{-- Footer totals over every matching row (shown while the row count is exact). --}}
                    <tfoot>
                        <tr class="adt-total">
                            @if ($expandable)<td class="adt-expand-cell"></td>@endif
                            @if ($selectable)<td class="adt-select"></td>@endif
                            @foreach ($columns as $index => $column)
                                @php
                                    $kind = $column->aggregateKind();
                                    $has = $kind !== null && array_key_exists($column->id(), $totals);
                                    $value = $has ? $totals[$column->id()] : null;
                                    $totalClass = trim(($collapsible ? 'adt-col--p'.$column->priorityAt($index) : '').($has ? ' adt-num' : ''));
                                @endphp
                                @if ($index === 0 && $kind === null)
                                    <th scope="row" data-label="{{ $column->headerText() }}" @if ($totalClass !== '') class="{{ $totalClass }}" @endif>{{ $this->tableText('totals') }}</th>
                                @else
                                    <td data-label="{{ $column->headerText() }}{{ $has ? ' · '.$this->tableText('aggregate_'.$kind) : '' }}" @if ($totalClass !== '') class="{{ $totalClass }}" @endif @if ($has) title="{{ $this->tableText('aggregate_'.$kind) }}" @endif>{{ $has ? \Acunsoft\DataTable\Laravel\Support\Format::total($column, $value, $kind) : '' }}</td>
                                @endif
                            @endforeach
                            @if ($rowActions !== [])<td class="adt-actions{{ $collapsible ? ' adt-col--p2' : '' }}"></td>@endif
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>
    </div>

    @include('acun-datatable::livewire.bar', ['position' => 'bottom'])
</div>
