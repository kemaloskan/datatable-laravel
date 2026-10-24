<?php

declare(strict_types=1);

namespace Acunsoft\DataTable\Laravel\Livewire;

use Acunsoft\DataTable\Laravel\Column;
use Acunsoft\DataTable\Laravel\Import\ImportException;
use Acunsoft\DataTable\Laravel\Import\RowImporter;
use Acunsoft\DataTable\Laravel\Import\SheetReader;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;
use Livewire\WithFileUploads;

/**
 * Import an uploaded CSV or xlsx file into a Livewire table component.
 *
 * Implement `importRow(array $values, int $line)` to store one valid row
 * (values are typed by the columns, keyed by column ID). Rows are read and
 * checked first; then every valid row is stored in one database transaction,
 * so an exception in `importRow` stores nothing. Rows with problems are not
 * passed on; they are listed with their line in `$importReport`.
 *
 * Authorize in `importRow` or before (e.g. in `mount`); the file is
 * size-limited and read safely (see SheetReader).
 */
trait WithTableImport
{
    use WithFileUploads;

    /** @var \Livewire\Features\SupportFileUploads\TemporaryUploadedFile|null */
    public $importFile = null;

    /**
     * Result of the last import: counts, the first 100 problems and ignored headers.
     *
     * @var array{imported: int, problems: int, errors: list<array{line: int, column: string, value: string, message: string}>, ignored: list<string>}|null
     */
    #[Locked]
    public ?array $importReport = null;

    /** Store one valid row. */
    abstract protected function importRow(array $values, int $line): void;

    /** @return iterable<Column> the importable columns (default: the table's columns) */
    protected function importColumns(): iterable
    {
        return method_exists($this, 'dataTableColumns') ? $this->dataTableColumns() : [];
    }

    /**
     * Options for RowImporter: `headers` (extra header names per column ID),
     * `required` (column IDs), `decimalSeparator`, `dateOrder`.
     *
     * @return array<string, mixed>
     */
    protected function importOptions(): array
    {
        return app()->getLocale() === 'tr' ? ['decimalSeparator' => ','] : [];
    }

    protected function importMaxKilobytes(): int
    {
        return 10_240;
    }

    protected function importMaxRows(): int
    {
        return 10_000;
    }

    public function import(): void
    {
        $this->importReport = null;
        $this->validate([
            'importFile' => ['required', 'file', 'max:'.$this->importMaxKilobytes(), 'extensions:csv,txt,xlsx'],
        ], [], ['importFile' => __('acun-datatable::table.import_file')]);

        try {
            $sheet = (new SheetReader(maxRows: $this->importMaxRows(), maxBytes: $this->importMaxKilobytes() * 1024))
                ->read($this->importFile->getRealPath(), $this->importFile->getClientOriginalExtension());
        } catch (ImportException $error) {
            $this->addError('importFile', $error->getMessage());

            return;
        }
        $options = $this->importOptions();
        $result = (new RowImporter(
            $this->importColumns(),
            headers: $options['headers'] ?? [],
            required: $options['required'] ?? [],
            decimalSeparator: $options['decimalSeparator'] ?? '.',
            dateOrder: $options['dateOrder'] ?? 'dmy',
        ))->import($sheet);

        DB::transaction(function () use ($result): void {
            foreach ($result->rows as $row) {
                $this->importRow($row['values'], $row['line']);
            }
        });

        $this->importReport = [
            'imported' => count($result->rows),
            'problems' => count($result->errors) + ($result->truncated ? 1 : 0),
            'errors' => array_slice($result->errors, 0, 100),
            'ignored' => $result->ignoredHeaders,
        ];
        $this->importFile = null;
        if (method_exists($this, 'clearSelection')) {
            $this->clearSelection();
            unset($this->rows);
        }
    }

    /** Close the import panel: forget the chosen file, its errors and the last report. */
    public function closeImport(): void
    {
        $this->importFile = null;
        $this->importReport = null;
        $this->resetErrorBag('importFile');
    }
}
