{{--
  The values of one row as a description list: in the dialog (`$all`: every
  column) or below the row (only the columns a narrow screen hides).
--}}
<dl class="adt-detail-list">
    @foreach ($columns as $index => $column)
        @php $level = $column->priorityAt($index); @endphp
        @if ($all || $level > 1)
            <div class="adt-detail-item{{ $all ? '' : ' adt-col--p'.$level }}">
                <dt>{{ $column->headerText() }}</dt>
                <dd>@include('acun-datatable::livewire.cell')</dd>
            </div>
        @endif
    @endforeach
    @if ($rowActions !== [])
        <div class="adt-detail-item{{ $all ? '' : ' adt-col--p2' }}">
            <dt>{{ $this->tableText('actions') }}</dt>
            <dd class="adt-detail-actions">@include('acun-datatable::livewire.row-actions')</dd>
        </div>
    @endif
</dl>
