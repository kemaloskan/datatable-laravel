{{--
  The filter fields and "Clear filters" of livewire.table. Shown by the
  `filters` feature (under the button, in a drawer or a modal; see
  filterPanel()), and yours to include in your own drawer, modal or
  sidebar: in a layout feature view of your own,
  @include('acun-datatable::livewire.filter-panel').
--}}
@php
    $panel ??= collect($allColumns)->filter(fn ($column) => $column->hasFilter())->values();
    $activeFilters ??= $panel->filter(fn ($column) => \Acunsoft\DataTable\Laravel\Support\FilterPanel::rules($column, \Acunsoft\DataTable\Laravel\Support\FilterPanel::read($column, $appliedFilters)) !== [])->count();
@endphp
<div class="adt-filter-panel" role="group" aria-label="{{ $this->tableText('filters') }}">
    @foreach ($panel as $column)
        @php
            $kind = \Acunsoft\DataTable\Laravel\Support\FilterPanel::kind($column);
            $values = \Acunsoft\DataTable\Laravel\Support\FilterPanel::read($column, $appliedFilters);
            $field = \Illuminate\Support\Js::from($column->id());
            $controlId = 'adt-'.$this->getId().'-filter-'.$loop->index;
        @endphp
        <fieldset class="adt-filter adt-filter--{{ $kind }}" data-field="{{ $column->id() }}" x-data wire:key="adt-filter-{{ $column->id() }}">
            <legend>{{ $column->headerText() }}</legend>
            @if ($kind === 'text')
                <input type="search" class="adt-control" autocomplete="off" aria-label="{{ $column->headerText() }}" value="{{ $values['text'] }}"
                    x-on:input.debounce.400ms="$wire.filterBy({{ $field }}, { text: $el.value })">
            @elseif ($kind === 'boolean')
                @foreach (['' => 'filter_any', 'true' => 'yes', 'false' => 'no'] as $flag => $text)
                    <label class="adt-filter-choice">
                        <input type="radio" name="{{ $controlId }}" value="{{ $flag }}" @checked($values['flag'] === $flag)
                            x-on:change="$wire.filterBy({{ $field }}, { flag: $el.value })">
                        <span>{{ $this->tableText($text) }}</span>
                    </label>
                @endforeach
            @elseif ($kind === 'options')
                @foreach ($column->filterOptions() as $value => $label)
                    <label class="adt-filter-choice">
                        <input type="checkbox" value="{{ $value }}" @checked(in_array((string) $value, $values['selected'], true))
                            x-on:change="$wire.filterBy({{ $field }}, { selected: [...$root.querySelectorAll('input:checked')].map(input => input.value) })">
                        <span>{{ $label }}</span>
                    </label>
                @endforeach
            @else
                @php $dates = $kind === 'dates'; @endphp
                <div class="adt-filter-range">
                    <input type="{{ $dates ? 'date' : 'number' }}" class="adt-control" x-ref="min" value="{{ $values['min'] }}"
                        @if (! $dates) inputmode="decimal" step="any" placeholder="{{ $this->tableText('filter_min') }}" @endif
                        aria-label="{{ $column->headerText() }}: {{ $this->tableText($dates ? 'filter_from' : 'filter_min') }}"
                        x-on:change="$wire.filterBy({{ $field }}, { min: $refs.min.value, max: $refs.max.value })">
                    <span aria-hidden="true">–</span>
                    <input type="{{ $dates ? 'date' : 'number' }}" class="adt-control" x-ref="max" value="{{ $values['max'] }}"
                        @if (! $dates) inputmode="decimal" step="any" placeholder="{{ $this->tableText('filter_max') }}" @endif
                        aria-label="{{ $column->headerText() }}: {{ $this->tableText($dates ? 'filter_to' : 'filter_max') }}"
                        x-on:change="$wire.filterBy({{ $field }}, { min: $refs.min.value, max: $refs.max.value })">
                </div>
            @endif
        </fieldset>
    @endforeach
    <div class="adt-filter-foot">
        <button type="button" class="adt-button adt-filter-clear" wire:click="clearPanelFilters" @disabled($activeFilters === 0)>{{ $this->tableText('clear_filters') }}</button>
        @if ($inDialog ?? false)
            <button type="button" class="adt-button adt-button--primary adt-filter-done" x-on:click="$el.closest('dialog').close()">{{ $this->tableText('close') }}</button>
        @endif
    </div>
</div>
