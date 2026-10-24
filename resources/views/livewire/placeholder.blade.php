{{-- A lazy table while it loads (WithDataTable::placeholder()): the header and shimmering rows. --}}
<div class="adt{{ $size === 'md' ? '' : ' adt-size--'.$size }}">
    <div class="adt-bar adt-top" aria-hidden="true">
        <div class="adt-bar-start"><span class="adt-skeleton adt-skeleton--control"></span><span class="adt-skeleton adt-skeleton--search"></span></div>
    </div>
    <div class="adt-table-wrap adt-skeleton-on" aria-busy="true">
        <div class="adt-notice" role="status">{{ $loading }}</div>
        <div class="adt-scroll">
            <table class="adt-table">
                @if ($columns !== [])
                    <thead>
                        <tr>
                            @if ($selectable)<th scope="col" class="adt-select"></th>@endif
                            @foreach ($columns as $column)
                                <th scope="col" class="adt-th{{ $column->isNumeric() ? ' adt-num' : '' }}">{{ $column->headerText() }}</th>
                            @endforeach
                        </tr>
                    </thead>
                @endif
                <tbody aria-hidden="true">
                    @for ($i = 0; $i < $count; $i++)
                        <tr class="adt-row adt-skeleton-row">
                            @if ($selectable)<td class="adt-select"></td>@endif
                            @forelse ($columns as $column)
                                <td data-label="{{ $column->headerText() }}" @if ($column->isNumeric()) class="adt-num" @endif><span class="adt-skeleton"></span></td>
                            @empty
                                <td><span class="adt-skeleton"></span></td>
                            @endforelse
                        </tr>
                    @endfor
                </tbody>
            </table>
        </div>
    </div>
</div>
