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

    @case('columns')
        @if (count($allColumns) > 1 && collect($allColumns)->contains(fn ($column) => $column->isHideable()))
            <details class="adt-columns" x-data @click.outside="$el.open = false" @keydown.escape="$el.open = false; $el.querySelector('summary').focus()">
                <summary>
                    <svg class="adt-icon" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M9 4v16M15 4v16M4 4h16v16H4z"/></svg>
                    {{ $this->tableText('columns') }}
                </summary>
                <fieldset>
                    <legend>{{ $this->tableText('show_columns') }}</legend>
                    @foreach ($allColumns as $column)
                        @php $shown = ! in_array($column->id(), $hiddenIds, true); @endphp
                        <label>
                            <input type="checkbox" value="{{ $column->id() }}"
                                wire:click="toggleColumn({{ \Illuminate\Support\Js::from($column->id()) }})"
                                @checked($shown)
                                @disabled(! $column->isHideable() || ($shown && count($columns) === 1))>
                            <span>{{ $column->headerText() }}</span>
                        </label>
                    @endforeach
                </fieldset>
            </details>
        @endif
        @break

    @case('tools')
        @php $exportFormats = array_values(array_intersect($this->exportFormats(), ['xlsx', 'csv'])); @endphp
        @if ($exportFormats !== [] || $this->printable() || $importable)
            <details class="adt-columns adt-menu" x-data @click.outside="$el.open = false" @keydown.escape="$el.open = false; $el.querySelector('summary').focus()">
                <summary>
                    <svg class="adt-icon" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M12 3v12M7 10l5 5 5-5M5 21h14"/></svg>
                    {{ $this->tableText('tools') }}
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
                                @click="$el.closest('details').open = false; window.print()">{{ $this->tableText('print') }}</button>
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
