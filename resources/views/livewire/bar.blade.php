{{-- One control bar (top or bottom) of livewire.table; the slots come from tableLayout(). --}}
@if ($layout[$position.'Start'] !== [] || $layout[$position.'End'] !== [])
    <div class="adt-bar adt-{{ $position }}">
        @foreach (['Start' => 'start', 'End' => 'end'] as $slot => $side)
            <div class="adt-bar-{{ $side }}">
                @foreach ($layout[$position.$slot] as $feature)
                    @include('acun-datatable::livewire.feature', ['feature' => $feature])
                @endforeach
            </div>
        @endforeach
    </div>
@endif
