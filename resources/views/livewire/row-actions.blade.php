{{--
  The row-action buttons of one row: the first `$maxInline` in the row, the
  rest in a "⋮" menu (in the details and the dialog every action is a button).
--}}
@php
    $visibleActions = array_values(array_filter($rowActions, fn ($action) => $action->isVisible($row)));
    $limit = $maxInline ?? null;
    $keep = $limit === null || count($visibleActions) <= $limit + 1 ? count($visibleActions) : $limit;
    $menuActions = array_slice($visibleActions, $keep);
@endphp
@foreach (array_slice($visibleActions, 0, $keep) as $action)
    <button type="button" @class(['adt-action', 'adt-action--icon' => $action->isIconOnly(), 'adt-action--danger' => $action->isDanger()]) data-action="{{ $action->id() }}"
        aria-label="{{ $this->tableText('row_action', ['action' => $action->labelText(), 'name' => $name]) }}"
        @if ($action->isIconOnly()) title="{{ $action->labelText() }}" @endif
        wire:click="runRowAction({{ \Illuminate\Support\Js::from($action->id()) }}, {{ \Illuminate\Support\Js::from($key) }})"
        @if ($action->confirmText() !== null) wire:confirm="{{ $action->confirmText() }}" @endif>@if ($action->iconPath() !== null)<svg class="adt-icon" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="{{ $action->iconPath() }}"/></svg>@endif @unless ($action->isIconOnly())<span>{{ $action->labelText() }}</span>@endunless</button>
@endforeach
@if ($menuActions !== [])
    <details class="adt-row-menu" x-data @click.outside="$el.open = false" @keydown.escape="$el.open = false; $el.querySelector('summary').focus()">
        <summary class="adt-action adt-action--icon" aria-label="{{ $this->tableText('more_actions', ['name' => $name]) }}" title="{{ $this->tableText('more_actions', ['name' => $name]) }}"><svg class="adt-icon" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="{{ \Acunsoft\DataTable\Laravel\Support\Icons::PATHS['more'] }}"/></svg></summary>
        <div class="adt-menu-list">
            @foreach ($menuActions as $action)
                <button type="button" @class(['adt-menu-item', 'adt-menu-item--danger' => $action->isDanger()]) data-action="{{ $action->id() }}"
                    aria-label="{{ $this->tableText('row_action', ['action' => $action->labelText(), 'name' => $name]) }}"
                    x-on:click="$el.closest('details').open = false"
                    wire:click="runRowAction({{ \Illuminate\Support\Js::from($action->id()) }}, {{ \Illuminate\Support\Js::from($key) }})"
                    @if ($action->confirmText() !== null) wire:confirm="{{ $action->confirmText() }}" @endif>@if ($action->iconPath() !== null)<svg class="adt-icon" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="{{ $action->iconPath() }}"/></svg>@endif<span>{{ $action->labelText() }}</span></button>
            @endforeach
        </div>
    </details>
@endif
