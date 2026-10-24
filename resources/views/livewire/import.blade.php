{{--
  Import panel for components using WithTableImport; included by livewire.table.
  The file input is always present (visually hidden) so "Import…" in the tools
  menu can open the file picker; the panel shows once a file is chosen.
--}}
@php
    $importHeaders = collect($this->importColumns())->mapWithKeys(fn ($column) => [$column->id() => $column->headerText()]);
    $importOpen = $importFile !== null || $importReport !== null || $errors->has('importFile');
@endphp
<form class="adt-import{{ $importOpen ? '' : ' adt-import--idle' }}" wire:submit="import"
    aria-label="{{ $this->tableText('import') }}">
    <input type="file" id="{{ $importId }}" class="adt-sr-only" wire:model="importFile" tabindex="-1"
        accept=".xlsx,.csv,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
        aria-label="{{ $this->tableText('import_file') }}"
        @error('importFile') aria-invalid="true" aria-describedby="{{ $importId }}-error" @enderror>
    @if ($importOpen)
        <strong class="adt-import-title">{{ $this->tableText('import') }}</strong>
        <span class="adt-import-file">{{ is_object($importFile) && method_exists($importFile, 'getClientOriginalName')
            ? $importFile->getClientOriginalName()
            : $this->tableText('import_file') }}</span>
        <span class="adt-import-buttons">
            <label class="adt-button" for="{{ $importId }}" tabindex="0"
                @keydown.enter.prevent="$el.click()" @keydown.space.prevent="$el.click()">{{ $this->tableText('import_choose') }}</label>
            @if ($importFile !== null)
                <button type="submit" class="adt-button adt-button--primary adt-import-button"
                    wire:loading.attr="aria-disabled" wire:target="importFile,import">{{ $this->tableText('import_submit') }}</button>
            @endif
            <button type="button" class="adt-button" wire:click="closeImport">{{ $this->tableText('close') }}</button>
        </span>
        @error('importFile') <p class="adt-field-error" role="alert" id="{{ $importId }}-error">{{ $message }}</p> @enderror

        @if ($importReport !== null)
            <div class="adt-import-report" role="status">
                <p>{{ $this->tableText('import_done', $importReport['imported'], ['count' => \Acunsoft\DataTable\Laravel\Support\Format::count($importReport['imported'])]) }}</p>
                @if ($importReport['problems'] > 0)
                    <p>{{ $this->tableText('import_problems', $importReport['problems'], ['count' => \Acunsoft\DataTable\Laravel\Support\Format::count($importReport['problems'])]) }}</p>
                    <ul>
                        @foreach ($importReport['errors'] as $problem)
                            <li>{{ $this->tableText('import_line', ['line' => $problem['line']]) }} · {{ ($importHeaders[$problem['column']] ?? $problem['column']).($problem['value'] !== '' ? ' · “'.$problem['value'].'”' : '') }}: {{ $problem['message'] }}</li>
                        @endforeach
                    </ul>
                @endif
                @if ($importReport['ignored'] !== [])
                    <p>{{ $this->tableText('import_ignored', ['columns' => implode(', ', $importReport['ignored'])]) }}</p>
                @endif
            </div>
        @endif
    @endif
</form>
