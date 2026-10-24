{{-- A queued task (large export, queued bulk action): polled until done, then the result. --}}
@php $task = $this->tableTaskView(); @endphp
@if ($task !== null)
    @php $busy = in_array($task['status'], ['queued', 'running'], true); @endphp
    <div class="adt-task adt-task--{{ $task['status'] }}" role="status" aria-live="polite" @if ($busy) wire:poll.2s="checkTableTask" @endif>
        @if ($busy)
            <span class="adt-task-spinner" aria-hidden="true"></span>
            <span>{{ $this->tableText($task['task'] === 'bulk' ? 'task_bulk_running' : 'task_export_running') }}</span>
        @elseif ($task['status'] === 'ready')
            <span>{{ $this->tableText($task['task'] === 'bulk' ? 'task_bulk_done' : 'task_export_ready') }}</span>
            @if ($task['url'] !== null)
                <a class="adt-task-link" href="{{ $task['url'] }}" download>{{ $this->tableText('task_download') }}</a>
            @endif
        @else
            <span>{{ $this->tableText('task_failed') }}</span>
        @endif
        @unless ($busy)
            <button type="button" class="adt-task-close" wire:click="dismissTableTask" aria-label="{{ $this->tableText('close') }}">
                <svg class="adt-icon" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M18 6 6 18M6 6l12 12"/></svg>
            </button>
        @endunless
    </div>
@endif
@error('tableTask') <p class="adt-field-error" role="alert">{{ $message }}</p> @enderror
