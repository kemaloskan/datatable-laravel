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
    $span = count($columns) + ($selectable ? 1 : 0) + ($rowActions === [] ? 0 : 1);
    $layout = $this->dataTableLayout();
    $importable = in_array(\Acunsoft\DataTable\Laravel\Livewire\WithTableImport::class, class_uses_recursive($this), true);
    $importId = 'adt-import-'.$this->getId();
@endphp
<div class="adt">
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

    <div class="adt-table-wrap" wire:loading.class="adt-loading" wire:loading.attr="aria-busy">
        <div class="adt-notice" role="status" wire:loading>{{ $this->tableText('loading') }}</div>
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
                                        aria-label="{{ $this->tableText('select_all') }}"
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
                            <th scope="col" class="adt-th adt-actions">{{ $this->tableText('actions') }}</th>
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
                                        aria-label="{{ $this->tableText('select_row', ['name' => $this->rowLabel($row)]) }}"
                                        wire:click="toggleRow({{ \Illuminate\Support\Js::from($key) }})"
                                        @checked($selected)>
                                </td>
                            @endif
                            @foreach ($columns as $column)
                                <td data-label="{{ $column->headerText() }}">{{ \Acunsoft\DataTable\Laravel\Support\Format::cell($column, $row) }}</td>
                            @endforeach
                            @if ($rowActions !== [])
                                <td class="adt-actions" data-label="{{ $this->tableText('actions') }}">
                                    @php $name = $this->rowLabel($row); @endphp
                                    @foreach ($rowActions as $action)
                                        @if ($action->isVisible($row))
                                            <button type="button" class="adt-action" data-action="{{ $action->id() }}"
                                                aria-label="{{ $this->tableText('row_action', ['action' => $action->labelText(), 'name' => $name]) }}"
                                                wire:click="runRowAction({{ \Illuminate\Support\Js::from($action->id()) }}, {{ \Illuminate\Support\Js::from($key) }})"
                                                @if ($action->confirmText() !== null) wire:confirm="{{ $action->confirmText() }}" @endif>{{ $action->labelText() }}</button>
                                        @endif
                                    @endforeach
                                </td>
                            @endif
                        </tr>
                    @empty
                        <tr class="adt-empty">
                            <td colspan="{{ $span }}">{{ $this->tableText('empty') }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    @include('acun-datatable::livewire.bar', ['position' => 'bottom'])
</div>
