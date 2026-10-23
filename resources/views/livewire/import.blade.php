{{-- Import form for components using WithTableImport; included by livewire.table. --}}
@php
    $importHeaders = collect($this->importColumns())->mapWithKeys(fn ($column) => [$column->id() => $column->headerText()]);
@endphp
<form class="adt-import" wire:submit="import">
    <label>
        <span>{{ __('acun-datatable::table.import_file') }}</span>
        <input type="file" wire:model="importFile"
            accept=".xlsx,.csv,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
            @error('importFile') aria-invalid="true" aria-describedby="adt-import-error" @enderror>
    </label>
    <button type="submit" class="adt-import-button" wire:loading.attr="aria-disabled" wire:target="importFile,import">{{ __('acun-datatable::table.import_submit') }}</button>
    @error('importFile') <p class="adt-field-error" role="alert" id="adt-import-error">{{ $message }}</p> @enderror

    @if ($importReport !== null)
        <div class="adt-import-report" role="status">
            <p>{{ trans_choice('acun-datatable::table.import_done', $importReport['imported'], ['count' => \Acunsoft\DataTable\Laravel\Support\Format::count($importReport['imported'])]) }}</p>
            @if ($importReport['problems'] > 0)
                <p>{{ trans_choice('acun-datatable::table.import_problems', $importReport['problems'], ['count' => \Acunsoft\DataTable\Laravel\Support\Format::count($importReport['problems'])]) }}</p>
                <ul>
                    @foreach ($importReport['errors'] as $problem)
                        <li>{{ __('acun-datatable::table.import_line', ['line' => $problem['line']]) }} · {{ ($importHeaders[$problem['column']] ?? $problem['column']).($problem['value'] !== '' ? ' · “'.$problem['value'].'”' : '') }}: {{ $problem['message'] }}</li>
                    @endforeach
                </ul>
            @endif
            @if ($importReport['ignored'] !== [])
                <p>{{ __('acun-datatable::table.import_ignored', ['columns' => implode(', ', $importReport['ignored'])]) }}</p>
            @endif
        </div>
    @endif
</form>
