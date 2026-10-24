{{-- A row's child row: the component's detailView(), rendered once the row is opened. --}}
<div class="adt-detail-content" @if (! $detailOpen) aria-busy="true" @endif>
    @if ($detailOpen)
        @include($detailView, ['row' => $row, 'key' => $key])
    @else
        <span class="adt-detail-status">{{ $this->tableText('loading') }}</span>
    @endif
</div>
