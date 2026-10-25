{{--
  One control of livewire.table, named by tableLayout(): pageSize, search, columns,
  tools, info, paging, or the name of your own Blade view.
--}}
@switch ($feature)
    @case('pageSize')
        @php $sizes = $this->pageSizeOptions(); $pageSize = $rows->perPage(); @endphp
        <label class="adt-length">
            <span class="adt-sr-only">{{ $this->tableText('page_size') }}</span>
            <select class="adt-control" wire:model.live="pageSize">
                @foreach (in_array($pageSize, $sizes, true) ? $sizes : collect([...$sizes, $pageSize])->sort()->values()->all() as $size)
                    <option value="{{ $size }}" @selected($size === $pageSize)>{{ $size }}</option>
                @endforeach
            </select>
        </label>
        @break

    @case('search')
        @if (collect($allColumns)->contains(fn ($column) => $column->isSearchable()))
            <div class="adt-feature adt-feature--search">
                <label class="adt-search">
                    <span class="adt-sr-only">{{ $this->tableText('search') }}</span>
                    <svg class="adt-icon" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M21 21l-4.35-4.35M11 18a7 7 0 1 0 0-14 7 7 0 0 0 0 14z"/></svg>
                    <input type="search" class="adt-control" wire:model.live.debounce.300ms="search" autocomplete="off"
                        placeholder="{{ $this->tableText('search_placeholder') }}">
                </label>
            </div>
        @endif
        @break

    @case('filters')
        @php
            $panel = collect($allColumns)->filter(fn ($column) => $column->hasFilter())->values();
            $activeFilters = $panel->filter(fn ($column) => \Acunsoft\DataTable\Laravel\Support\FilterPanel::rules($column, \Acunsoft\DataTable\Laravel\Support\FilterPanel::read($column, $appliedFilters)) !== [])->count();
            $panelMode = $this->filterPanel();
            if (! in_array($panelMode, ['dropdown', 'drawer', 'modal'], true)) {
                throw new \InvalidArgumentException("filterPanel() must be dropdown, drawer or modal, [{$panelMode}] given.");
            }
            $button = $this->toolbarButton('filters', $this->tableText('filters'), 'filter');
            $syncFields = "\$el.querySelectorAll('input').forEach(input => { if (input === document.activeElement) return; if (input.type === 'checkbox' || input.type === 'radio') input.checked = input.hasAttribute('checked'); else input.value = input.getAttribute('value') ?? ''; })";
        @endphp
        @if ($panel->isNotEmpty())
            @if ($panelMode === 'dropdown')
                {{-- wire:ignore.self keeps the menu open while Livewire updates its content. --}}
                <details class="adt-columns adt-menu adt-filters" wire:ignore.self x-data @click.outside="$el.open = false" @keydown.escape="$el.open = false; $el.querySelector('summary').focus()" @toggle="@include('acun-datatable::livewire.menu-place')"
                    x-on:adt-filters-synced.window="{{ $syncFields }}">
                    <summary @if ($button['class'] !== '') class="{{ $button['class'] }}" @endif @if ($button['iconOnly']) title="{{ $button['label'] }}" @endif>
                        @include('acun-datatable::livewire.toolbar-button')
                        <span class="adt-badge" aria-hidden="true" @if ($activeFilters === 0) hidden @endif>{{ $activeFilters }}</span>
                        <span class="adt-sr-only">{{ $activeFilters > 0 ? $this->tableText('active_filters', $activeFilters, ['count' => \Acunsoft\DataTable\Laravel\Support\Format::count($activeFilters)]) : '' }}</span>
                    </summary>
                    @include('acun-datatable::livewire.filter-panel')
                </details>
            @else
                {{-- A drawer or a modal: a <dialog> (Escape closes it, focus stays inside); wire:ignore.self keeps it open while Livewire updates. --}}
                <div class="adt-columns adt-menu adt-filters adt-filters--{{ $panelMode }}" x-data x-on:adt-filters-synced.window="{{ $syncFields }}">
                    <button type="button" class="adt-filter-open{{ $button['class'] !== '' ? ' '.$button['class'] : '' }}" aria-haspopup="dialog"
                        @if ($button['iconOnly']) title="{{ $button['label'] }}" @endif x-on:click="$refs.dialog.showModal()">
                        @include('acun-datatable::livewire.toolbar-button')
                        <span class="adt-badge" aria-hidden="true" @if ($activeFilters === 0) hidden @endif>{{ $activeFilters }}</span>
                        <span class="adt-sr-only">{{ $activeFilters > 0 ? $this->tableText('active_filters', $activeFilters, ['count' => \Acunsoft\DataTable\Laravel\Support\Format::count($activeFilters)]) : '' }}</span>
                    </button>
                    <dialog class="adt-dialog adt-filter-dialog adt-filter-dialog--{{ $panelMode }}" x-ref="dialog" wire:ignore.self aria-labelledby="adt-{{ $this->getId() }}-filters-title"
                        x-on:click="if ($event.target === $el) { const box = $el.getBoundingClientRect(); if ($event.clientX < box.left || $event.clientX > box.right || $event.clientY < box.top || $event.clientY > box.bottom) $el.close(); }"
                        x-on:close="$root.querySelector('.adt-filter-open').focus()">
                        <div class="adt-dialog-head">
                            <h2 class="adt-dialog-title" id="adt-{{ $this->getId() }}-filters-title">{{ $this->tableText('filters') }}</h2>
                            <button type="button" class="adt-dialog-close" aria-label="{{ $this->tableText('close') }}" x-on:click="$refs.dialog.close()"><svg class="adt-icon" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M18 6 6 18M6 6l12 12"/></svg></button>
                        </div>
                        @include('acun-datatable::livewire.filter-panel', ['inDialog' => true])
                    </dialog>
                </div>
            @endif
        @endif
        @break

    @case('columns')
        @if (count($allColumns) > 1 && (collect($allColumns)->contains(fn ($column) => $column->isHideable()) || $this->reorderableColumns() || $this->resizableColumns()))
            <details class="adt-columns" wire:ignore.self x-data @click.outside="$el.open = false" @keydown.escape="$el.open = false; $el.querySelector('summary').focus()" @toggle="@include('acun-datatable::livewire.menu-place')">
                @php $button = $this->toolbarButton('columns', $this->tableText('columns'), 'columns'); @endphp
                <summary @if ($button['class'] !== '') class="{{ $button['class'] }}" @endif @if ($button['iconOnly']) title="{{ $button['label'] }}" @endif>
                    @include('acun-datatable::livewire.toolbar-button')
                </summary>
                @php $ordered = $this->orderedColumns(); $reorderable = $this->reorderableColumns(); @endphp
                <fieldset>
                    <legend>{{ $this->tableText('show_columns') }}</legend>
                    @foreach ($ordered as $position => $column)
                        @php $shown = ! in_array($column->id(), $hiddenIds, true); $jsId = \Illuminate\Support\Js::from($column->id()); @endphp
                        @if ($reorderable) <div class="adt-column-item" wire:key="adt-column-{{ $column->id() }}"> @endif
                        <label>
                            <input type="checkbox" value="{{ $column->id() }}"
                                wire:click="toggleColumn({{ $jsId }})"
                                @checked($shown)
                                @disabled(! $column->isHideable() || ($shown && count($columns) === 1))>
                            <span>{{ $column->headerText() }}</span>
                        </label>
                        @if ($reorderable)
                            @foreach (['up' => -1, 'down' => 1] as $direction => $delta)
                                @php $to = $position + $delta; $off = $to < 0 || $to >= count($ordered); @endphp
                                <button type="button" class="adt-move" aria-label="{{ $this->tableText('move_column_'.$direction, ['name' => $column->headerText()]) }}"
                                    @if ($off) aria-disabled="true" @else wire:click="moveColumn({{ $jsId }}, {{ $to }})" @endif>
                                    <svg class="adt-icon" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="{{ $direction === 'up' ? 'M18 15l-6-6-6 6' : 'M6 9l6 6 6-6' }}"/></svg>
                                </button>
                            @endforeach
                            </div>
                        @endif
                    @endforeach
                    @if ($this->columnsChanged())
                        <button type="button" class="adt-columns-reset" wire:click="resetColumns">{{ $this->tableText('reset_columns') }}</button>
                    @endif
                </fieldset>
            </details>
        @endif
        @break

    @case('tools')
        @php $exportFormats = array_values(array_intersect($this->exportFormats(), ['xlsx', 'csv'])); @endphp
        @if ($exportFormats !== [] || $this->printable() || $importable)
            <details class="adt-columns adt-menu" wire:ignore.self x-data @click.outside="$el.open = false" @keydown.escape="$el.open = false; $el.querySelector('summary').focus()" @toggle="@include('acun-datatable::livewire.menu-place')">
                @php $button = $this->toolbarButton('tools', $this->tableText('tools'), 'download'); @endphp
                <summary @if ($button['class'] !== '') class="{{ $button['class'] }}" @endif @if ($button['iconOnly']) title="{{ $button['label'] }}" @endif>
                    @include('acun-datatable::livewire.toolbar-button')
                </summary>
                <div class="adt-menu-list">
                    @if ($exportFormats !== [])
                        <div class="adt-menu-label" aria-hidden="true">{{ $this->tableText('export') }}</div>
                        @foreach ($exportFormats as $format)
                            <button type="button" class="adt-menu-item adt-export-button" data-format="{{ $format }}"
                                aria-label="{{ $this->tableText('export') }}: {{ $this->tableText('export_'.$format) }}"
                                wire:click="export({{ \Illuminate\Support\Js::from($format) }})"
                                @click="$el.closest('details').open = false">{{ $this->tableText('export_'.$format) }}</button>
                        @endforeach
                    @endif
                    @if ($this->printable() || $importable)
                        @if ($exportFormats !== []) <hr class="adt-menu-separator"> @endif
                        @if ($this->printable())
                            <button type="button" class="adt-menu-item adt-print-button"
                                @click="$el.closest('details').open = false; @include('acun-datatable::livewire.print')">{{ $this->tableText('print') }}</button>
                        @endif
                        @if ($importable)
                            <label class="adt-menu-item" for="{{ $importId }}" tabindex="0"
                                @keydown.enter.prevent="$el.click()" @keydown.space.prevent="$el.click()"
                                @click="$el.closest('details').open = false">{{ $this->tableText('import_open') }}</label>
                        @endif
                    @endif
                </div>
            </details>
            @error('export') <span class="adt-field-error" role="alert">{{ $message }}</span> @enderror
        @endif
        @break

    @case('info')
        @php $pager = $this->pagerState($rows); @endphp
        <div class="adt-info" aria-live="polite" aria-atomic="true">{{ $rows->isEmpty()
            ? $this->tableText('page_info_empty')
            : ($exactTotal
                ? $this->tableText('page_info', $rows->total(), ['from' => \Acunsoft\DataTable\Laravel\Support\Format::count($pager['from']), 'to' => \Acunsoft\DataTable\Laravel\Support\Format::count($pager['to']), 'total' => \Acunsoft\DataTable\Laravel\Support\Format::count($rows->total())])
                : $this->tableText('page_info_many', ['from' => \Acunsoft\DataTable\Laravel\Support\Format::count($pager['from']), 'to' => \Acunsoft\DataTable\Laravel\Support\Format::count($pager['to']), 'total' => \Acunsoft\DataTable\Laravel\Support\Format::count($rows->total())])) }}</div>
        @break

    @case('paging')
        @php $pager = $this->pagerState($rows); $firstLast = (bool) ($this->pagingOptions()['firstLast'] ?? false); @endphp
        <nav class="adt-paging" aria-label="{{ $this->tableText('pagination') }}">
            @if ($firstLast)
                <button type="button" class="adt-page adt-page-arrow adt-first" aria-label="{{ $this->tableText('first') }}"
                    @if ($pager['previous']) wire:click="gotoPage(1)" @else aria-disabled="true" @endif><svg class="adt-icon" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M11 17l-5-5 5-5M18 17l-5-5 5-5"/></svg></button>
            @endif
            <button type="button" class="adt-page adt-page-arrow adt-previous" aria-label="{{ $this->tableText('previous') }}"
                @if ($pager['previous']) wire:click="previousPage" @else aria-disabled="true" @endif><svg class="adt-icon" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M15 18l-6-6 6-6"/></svg></button>
            <span class="adt-page-numbers">
                @foreach ($pager['items'] as $item)
                    @if ($item === null)
                        <span class="adt-page-gap" aria-hidden="true">…</span>
                    @else
                        <button type="button" class="adt-page" aria-label="{{ $this->tableText('page', ['page' => \Acunsoft\DataTable\Laravel\Support\Format::count($item)]) }}"
                            @if ($item === $pager['page']) aria-current="page" @else wire:click="gotoPage({{ $item }})" @endif>{{ \Acunsoft\DataTable\Laravel\Support\Format::count($item) }}</button>
                    @endif
                @endforeach
            </span>
            <button type="button" class="adt-page adt-page-arrow adt-next" aria-label="{{ $this->tableText('next') }}"
                @if ($pager['next']) wire:click="nextPage" @else aria-disabled="true" @endif><svg class="adt-icon" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M9 18l6-6-6-6"/></svg></button>
            @if ($firstLast && $pager['last'] !== null)
                <button type="button" class="adt-page adt-page-arrow adt-last" aria-label="{{ $this->tableText('last') }}"
                    @if ($pager['page'] < $pager['last']) wire:click="gotoPage({{ $pager['last'] }})" @else aria-disabled="true" @endif><svg class="adt-icon" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M13 17l5-5-5-5M6 17l5-5-5-5"/></svg></button>
            @endif
        </nav>
        @break

    @default
        @include($feature)
@endswitch
