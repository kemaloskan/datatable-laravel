{{--
  An editable cell (Column::editable()): the value with an edit button; the
  button or a double click opens the editor. Enter or leaving the field saves
  through updateCell() (validated and authorized on the server), Escape
  cancels. A validation error keeps the editor open with the message.
--}}
@php
    $errorKey = 'cells.'.md5($key.'|'.$column->id());
    $editError = $errors->first($errorKey);
    $initial = $this->editValue($column, $row);
    $kind = $column->filterOptions() !== null ? 'select' : ($column->valueType() ?? 'text');
    $jsField = \Illuminate\Support\Js::from($column->id());
@endphp
<div class="adt-edit-cell" wire:key="adt-edit-{{ $key }}-{{ $column->id() }}"
    x-data="{
        editing: {{ $editError ? 'true' : 'false' }},
        saving: false,
        {{-- Shown before x-show runs, so the focus lands at once. --}}
        open() { this.editing = true; this.$refs.editor.style.display = ''; this.$refs.control.focus(); this.$refs.control.select?.(); },
        close() { this.editing = false; this.$refs.button.style.display = ''; this.$refs.button.focus(); },
        cancel() {
            this.$refs.control.value = this.$refs.control.dataset.initial;
            if (this.$el.querySelector('[role=alert]')) this.$wire.cancelEdit({{ $jsKey }}, {{ $jsField }});
            this.close();
        },
        async save() {
            if (this.saving || ! this.editing) return;
            const value = this.$refs.control.value;
            if (value === this.$refs.control.dataset.initial) return this.close();
            this.saving = true;
            const saved = await this.$wire.updateCell({{ $jsKey }}, {{ $jsField }}, value);
            this.saving = false;
            if (saved) this.close(); else this.$refs.control.focus();
        },
    }"
    x-on:dblclick="if (! editing && ! $event.target.closest('a, button, input, select')) open()">
    <span class="adt-edit-value" x-show="! editing" @if ($editError) style="display: none" @endif>@include('acun-datatable::livewire.cell')</span>
    <button type="button" class="adt-edit" x-ref="button" x-show="! editing" @if ($editError) style="display: none" @endif x-on:click="open()"
        aria-label="{{ $this->tableText('edit_cell', ['column' => $column->headerText(), 'name' => $name]) }}">
        <svg class="adt-icon" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M12 20h9M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4z"/></svg>
    </button>
    <div class="adt-editor" x-ref="editor" x-show="editing" @if (! $editError) style="display: none" @endif>
        @if ($kind === 'select' || $kind === 'boolean')
            <select class="adt-edit-control" x-ref="control" data-initial="{{ $initial }}" aria-label="{{ $column->headerText() }}"
                x-on:keydown.enter.prevent="save()" x-on:keydown.escape.prevent.stop="cancel()" x-on:blur="save()"
                x-bind:disabled="saving" x-bind:aria-busy="saving.toString()" @if ($editError) aria-invalid="true" @endif>
                @if ($kind === 'boolean')
                    <option value="1" @selected($initial === '1')>{{ $this->tableText('yes') }}</option>
                    <option value="0" @selected($initial !== '1')>{{ $this->tableText('no') }}</option>
                @else
                    <option value="" @selected($initial === '')>—</option>
                    @foreach ($column->filterOptions() as $value => $label)
                        <option value="{{ $value }}" @selected((string) $value === $initial)>{{ $label }}</option>
                    @endforeach
                @endif
            </select>
        @else
            <input class="adt-edit-control" x-ref="control" data-initial="{{ $initial }}" value="{{ $initial }}" aria-label="{{ $column->headerText() }}" autocomplete="off"
                type="{{ $kind === 'date' ? 'date' : ($kind === 'datetime' ? 'datetime-local' : 'text') }}"
                @if ($column->isNumeric()) inputmode="decimal" @endif
                x-on:keydown.enter.prevent="save()" x-on:keydown.escape.prevent.stop="cancel()" x-on:blur="save()"
                x-bind:readonly="saving" x-bind:aria-busy="saving.toString()" @if ($editError) aria-invalid="true" @endif>
        @endif
        {{-- Two elements: Alpine owns the saving text, the server the error (so one never overwrites the other). --}}
        <span class="adt-edit-message" role="status" x-text="saving ? {{ \Illuminate\Support\Js::from($this->tableText('saving')) }} : ''"></span>
        @if ($editError)
            <span class="adt-edit-message" role="alert">{{ $editError }}</span>
        @endif
    </div>
</div>
