{{--
  The resize handle of a header (resizableColumns()): drag it (Escape cancels),
  ←/→ on it (Shift: larger steps), Delete or a double click for the natural
  width. The first resize fixes every column at its current width, so nothing
  else jumps; the widths reach the server once, when the drag ends.
--}}
<span class="adt-resize" role="separator" aria-orientation="vertical" tabindex="0"
    aria-label="{{ $this->tableText('resize_column', ['name' => $column->headerText()]) }}"
    data-column-id="{{ $column->id() }}" aria-valuemin="48" aria-valuemax="2000" @if ($width !== null) aria-valuenow="{{ $width }}" @endif
    x-data="{
        widths() {
            const row = this.$el.closest('tr');
            const out = {};
            row.querySelectorAll('th[data-column-id]').forEach(th => { out[th.dataset.columnId] = Math.round(th.getBoundingClientRect().width); });
            const actions = row.querySelector('th.adt-actions');
            if (actions) out.__actions = Math.ceil(actions.getBoundingClientRect().width);
            return out;
        },
        clamp(width) { return Math.min(2000, Math.max(48, Math.round(width))); },
        send(width) { this.$wire.resizeColumns({ ...this.widths(), [this.$el.dataset.columnId]: width }); },
        step(delta) { this.send(this.clamp(this.$el.closest('th').getBoundingClientRect().width + delta)); },
        start(event) {
            if (event.button !== 0) return;
            const th = this.$el.closest('th');
            const wrap = this.$el.closest('.adt-table-wrap');
            const startX = event.clientX;
            const start = this.clamp(th.getBoundingClientRect().width);
            const widths = this.widths();
            let width = start;
            wrap.classList.add('adt-resizing');
            const move = e => { width = this.clamp(start + e.clientX - startX); th.style.width = width + 'px'; };
            const stop = commit => {
                document.removeEventListener('pointermove', move);
                document.removeEventListener('pointerup', up);
                document.removeEventListener('keydown', escape, true);
                wrap.classList.remove('adt-resizing');
                if (commit) this.$wire.resizeColumns({ ...widths, [th.dataset.columnId]: width });
                else th.style.width = start + 'px';
            };
            const up = () => stop(true);
            const escape = e => { if (e.key === 'Escape') { e.stopPropagation(); stop(false); } };
            document.addEventListener('pointermove', move);
            document.addEventListener('pointerup', up);
            document.addEventListener('keydown', escape, true);
        },
    }"
    x-on:pointerdown.prevent="start($event)"
    x-on:keydown.arrow-right.prevent="step($event.shiftKey ? 64 : 16)"
    x-on:keydown.arrow-left.prevent="step($event.shiftKey ? -64 : -16)"
    x-on:keydown.delete.prevent="$wire.resizeColumns({ [$el.dataset.columnId]: null })"
    x-on:keydown.backspace.prevent="$wire.resizeColumns({ [$el.dataset.columnId]: null })"
    x-on:dblclick="$wire.resizeColumns({ [$el.dataset.columnId]: null })"></span>
