{{--
  The values of one row as a description list: in the dialog (`$all`: every
  column) or below the row (only the columns a narrow screen hides; with
  responsive() auto, the ones that did not fit).
--}}
<dl class="adt-detail-list">
    @foreach ($columns as $index => $column)
        @php $level = $column->priorityAt($index); @endphp
        @if ($all || $level > 1)
            <div class="adt-detail-item{{ $all ? '' : ' adt-col--p'.$level }}" data-column-id="{{ $column->id() }}">
                <dt>{{ $column->headerText() }}</dt>
                <dd>@include('acun-datatable::livewire.cell')</dd>
            </div>
        @endif
    @endforeach
    @if ($rowActions !== [])
        <div class="adt-detail-item{{ $all ? '' : ' adt-col--p2' }}" data-column-id="__actions">
            <dt>{{ $this->tableText('actions') }}</dt>
            <dd class="adt-detail-actions">@include('acun-datatable::livewire.row-actions', ['maxInline' => null])</dd>
        </div>
    @endif
</dl>
