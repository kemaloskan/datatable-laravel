{{-- The row-action buttons of one row (table cell, details row and dialog). --}}
@foreach ($rowActions as $action)
    @if ($action->isVisible($row))
        <button type="button" class="adt-action" data-action="{{ $action->id() }}"
            aria-label="{{ $this->tableText('row_action', ['action' => $action->labelText(), 'name' => $name]) }}"
            wire:click="runRowAction({{ \Illuminate\Support\Js::from($action->id()) }}, {{ \Illuminate\Support\Js::from($key) }})"
            @if ($action->confirmText() !== null) wire:confirm="{{ $action->confirmText() }}" @endif>{{ $action->labelText() }}</button>
    @endif
@endforeach
