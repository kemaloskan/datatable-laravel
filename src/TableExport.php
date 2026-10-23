<?php

declare(strict_types=1);

namespace Acunsoft\DataTable\Laravel;

use Acunsoft\DataTable\Laravel\Export\XlsxWriter;
use Acunsoft\DataTable\Laravel\Support\Format;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Export every row matching a table query, on the server, as CSV or xlsx.
 *
 *   return TableExport::for(Order::query())
 *       ->columns($columns)
 *       ->query(TableQuery::fromRequest($request))   // filters, search, sort
 *       ->xlsx('orders.xlsx');
 *
 * Rows are read in chunks (`lazy()`) and written as they come, so memory
 * stays flat. Values follow the column types: typed columns give real
 * numbers, decimals, dates and booleans; an untyped column with
 * `Column::format()` exports its formatted text. CSV text is guarded
 * against formula injection; xlsx text is never a formula.
 */
final class TableExport
{
    /** @var list<Column> */
    private array $columns = [];
    private ?TableQuery $query = null;
    /** @var list<Column>|null */
    private ?array $queryColumns = null;
    private int $limit = 100_000;
    private int $chunk = 1_000;

    private function __construct(private readonly EloquentBuilder|QueryBuilder $builder)
    {
    }

    /** The rows the user may export: scope it like any query. */
    public static function for(EloquentBuilder|QueryBuilder $builder): self
    {
        return new self($builder);
    }

    /** @param iterable<Column> $columns the exported columns, in order (also the allowlist for the query) */
    public function columns(iterable $columns): self
    {
        $copy = clone $this;
        $copy->columns = [];
        foreach ($columns as $column) {
            if (! $column instanceof Column) {
                throw new \InvalidArgumentException('columns() takes Column instances.');
            }
            $copy->columns[] = $column;
        }

        return $copy;
    }

    /**
     * Filters, search and sorting to apply; paging is ignored. `$columns` is
     * the allowlist for them when it differs from the exported columns (for
     * example, hidden columns that are still filterable).
     *
     * @param iterable<Column>|null $columns
     */
    public function query(?TableQuery $query, ?iterable $columns = null): self
    {
        $copy = clone $this;
        $copy->query = $query;
        $copy->queryColumns = $columns === null ? null : array_values(is_array($columns) ? $columns : iterator_to_array($columns, false));

        return $copy;
    }

    /** Refuse exports larger than this (default 100,000 rows). */
    public function limit(int $rows): self
    {
        if ($rows < 1 || $rows > XlsxWriter::MAX_ROWS) {
            throw new \InvalidArgumentException('limit must be from 1 to '.XlsxWriter::MAX_ROWS.'.');
        }
        $copy = clone $this;
        $copy->limit = $rows;

        return $copy;
    }

    /** Stream a CSV download (UTF-8 with BOM). `;` suits Turkish Excel. */
    public function csv(string $filename = 'export.csv', string $delimiter = ',', bool $guardFormulas = true): StreamedResponse
    {
        if (! in_array($delimiter, [',', ';', "\t"], true)) {
            throw new \InvalidArgumentException('delimiter must be ",", ";" or a tab.');
        }
        $rows = $this->rows();

        return new StreamedResponse(function () use ($rows, $delimiter, $guardFormulas): void {
            $out = fopen('php://output', 'wb');
            fwrite($out, "\u{FEFF}");
            fputcsv($out, array_map(fn (Column $column) => self::guard($column->headerText(), $guardFormulas), $this->columns), $delimiter, '"', '', "\r\n");
            foreach ($rows as $row) {
                $fields = [];
                foreach ($this->columns as $column) {
                    [$kind, $value] = $this->cell($column, $row);
                    $fields[] = match ($kind) {
                        'null' => '',
                        'boolean' => $value ? 'true' : 'false',
                        'date' => $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : (string) $value,
                        'datetime' => $value instanceof \DateTimeInterface ? $value->format('Y-m-d H:i:s') : (string) $value,
                        'text' => self::guard((string) $value, $guardFormulas),
                        default => (string) $value,
                    };
                }
                fputcsv($out, $fields, $delimiter, '"', '', "\r\n");
            }
            fclose($out);
        }, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => self::disposition($filename),
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** An xlsx download, built in a temporary file that is deleted after sending. */
    public function xlsx(string $filename = 'export.xlsx', ?string $sheetName = null): BinaryFileResponse
    {
        $path = tempnam(sys_get_temp_dir(), 'adt-xlsx-') ?: throw new \RuntimeException('Cannot create a temporary file.');
        try {
            $writer = new XlsxWriter($path, $sheetName ?? pathinfo($filename, PATHINFO_FILENAME));
            $writer->header(array_map(fn (Column $column) => $column->headerText(), $this->columns));
            foreach ($this->rows() as $row) {
                $writer->row(array_map(fn (Column $column) => $this->cell($column, $row), $this->columns));
            }
            $writer->close();
        } catch (Throwable $error) {
            @unlink($path);
            throw $error;
        }

        return (new BinaryFileResponse($path, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'X-Content-Type-Options' => 'nosniff',
        ]))->setContentDisposition('attachment', self::asciiName($filename), $filename)->deleteFileAfterSend();
    }

    /** @return iterable<mixed> */
    private function rows(): iterable
    {
        if ($this->columns === []) {
            throw new \LogicException('Call columns() before exporting.');
        }
        $query = $this->query ?? TableQuery::fromArray([]);
        $builder = QueryApplier::apply(clone $this->builder, TableQuery::fromArray([
            'filters' => array_map(fn (array $rule) => ['field' => $rule['field'], 'op' => $rule['operator'], 'value' => $rule['value']], $query->filters),
            'search' => $query->search,
            'sort' => implode(',', array_map(fn (array $rule) => "{$rule['field']}:{$rule['direction']}", $query->sorting)),
        ]), $this->queryColumns ?? $this->columns);
        if ((clone $builder)->limit($this->limit + 1)->count() > $this->limit) {
            throw InvalidTableQuery::at('export', __('acun-datatable::table.export_too_large', ['max' => $this->limit]));
        }
        if ($builder instanceof QueryBuilder && $builder->orders === null) {
            $builder->orderBy($this->columns[0]->databaseColumn()); // lazy() needs an order
        }

        return $builder->lazy($this->chunk);
    }

    /** @return array{0: string, 1: mixed} */
    private function cell(Column $column, mixed $row): array
    {
        $value = data_get($row, $column->attributeName());
        $type = $column->valueType();
        if ($type === null && $column->formatter() !== null) {
            return ['text', Format::cell($column, $row)];
        }
        if ($value === null || $value === '') {
            return ['null', null];
        }
        try {
            return match ($type) {
                'number' => is_numeric($value) ? ['number', 0 + $value] : ['text', (string) $value],
                'decimal' => is_numeric($value) && preg_match('/^-?\d+(\.\d+)?$/', trim((string) $value)) === 1
                    ? ['decimal', trim((string) $value)] : ['text', (string) $value],
                'boolean' => ['boolean', filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? (bool) $value],
                'date', 'datetime' => [$type, $value instanceof \DateTimeInterface
                    ? CarbonImmutable::instance($value)->setTimezone(config('app.timezone') ?: 'UTC')
                    : CarbonImmutable::parse((string) $value, config('app.timezone') ?: 'UTC')],
                default => is_bool($value) ? ['boolean', $value]
                    : (is_int($value) || is_float($value) ? ['number', $value]
                    : ($value instanceof \DateTimeInterface ? ['datetime', $value] : ['text', (string) $value])),
            };
        } catch (Throwable) {
            return ['text', (string) $value]; // unreadable dates are exported as written
        }
    }

    private static function guard(string $text, bool $on): string
    {
        return $on && preg_match('/^[=+\-@\t\r]/', $text) === 1 ? "'".$text : $text;
    }

    private static function disposition(string $filename): string
    {
        return 'attachment; filename="'.self::asciiName($filename).'"; filename*=UTF-8\'\''.rawurlencode($filename);
    }

    private static function asciiName(string $filename): string
    {
        $base = basename(str_replace('\\', '/', $filename));
        $ascii = preg_replace('/[^A-Za-z0-9._-]+/', '_', \Illuminate\Support\Str::ascii($base)) ?: 'export';

        return trim($ascii, '_') ?: 'export';
    }
}
