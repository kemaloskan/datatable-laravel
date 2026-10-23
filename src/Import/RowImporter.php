<?php

declare(strict_types=1);

namespace Acunsoft\DataTable\Laravel\Import;

use Acunsoft\DataTable\Laravel\Column;
use Acunsoft\DataTable\Laravel\Support\Text;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Turn a Sheet into typed values for the given columns and report every value
 * that does not fit, with its line, instead of guessing (same rules as the
 * browser package):
 *
 * - headers match a column's header text or ID, ignoring case, accents and
 *   Turkish ı/İ, plus extra names from `headers`;
 * - `number` → int|float, `decimal` → exact string (`"1234.50"`),
 *   `date` → CarbonImmutable at the start of the day, `datetime` →
 *   CarbonImmutable, `boolean` → bool (true/false/1/0/evet/hayır/yes/no),
 *   text → trimmed string; empty cells → null;
 * - `decimalSeparator: ','` reads `1.234,50`; group separators must be real
 *   groups of three, so `1,5` with `'.'` is an error, not 15;
 * - text dates `23.10.2026` (`dateOrder` dmy, default) or ISO `2026-10-23`.
 *
 * Nothing is saved: validate further (Laravel validation, uniqueness) and store
 * the rows yourself.
 */
final class RowImporter
{
    private const TRUE = ['true', '1', 'yes', 'evet', 'y', 'e', 'x'];
    private const FALSE = ['false', '0', 'no', 'hayir', 'n', 'h'];

    /**
     * @param iterable<Column> $columns
     * @param array<string, string|list<string>> $headers extra header names per column ID
     * @param list<string> $required column IDs that need a header and a value
     */
    public function __construct(
        private readonly iterable $columns,
        private readonly array $headers = [],
        private readonly array $required = [],
        private readonly string $decimalSeparator = '.',
        private readonly string $dateOrder = 'dmy',
        private readonly int $maxErrors = 1_000,
    ) {
        if (! in_array($decimalSeparator, ['.', ','], true) || ! in_array($dateOrder, ['dmy', 'mdy'], true)) {
            throw new \InvalidArgumentException('decimalSeparator must be "." or ","; dateOrder "dmy" or "mdy".');
        }
    }

    public function import(Sheet $sheet): ImportResult
    {
        $byId = [];
        foreach ($this->columns as $column) {
            $byId[$column->id()] = $column;
        }
        foreach ([...$this->required, ...array_keys($this->headers)] as $id) {
            if (! isset($byId[$id])) {
                throw new \InvalidArgumentException("Unknown column ID [{$id}].");
            }
        }
        $names = [];
        foreach ($byId as $id => $column) {
            foreach ([$column->headerText(), $id, ...(array) ($this->headers[$id] ?? [])] as $name) {
                $key = Text::fold(trim((string) $name));
                if ($key !== '' && ! isset($names[$key])) {
                    $names[$key] = $column;
                }
            }
        }

        $errors = [];
        $truncated = false;
        $report = function (int $line, string $column, string $value, string $message) use (&$errors, &$truncated): void {
            if (count($errors) < $this->maxErrors) {
                $errors[] = ['line' => $line, 'column' => $column, 'value' => mb_substr($value, 0, 200), 'message' => $message];
            } else {
                $truncated = true;
            }
        };

        $mapping = [];
        $ignored = [];
        $seen = [];
        foreach ($sheet->headers as $position => $header) {
            $column = $names[Text::fold(trim($header))] ?? null;
            if ($column === null) {
                if (trim($header) !== '') {
                    $ignored[] = $header;
                }
            } elseif (isset($seen[$column->id()])) {
                $report(1, $column->id(), $header, __('acun-datatable::table.import_duplicate_column'));
            } else {
                $seen[$column->id()] = true;
                $mapping[$position] = $column;
            }
        }
        $missing = array_values(array_filter($this->required, fn (string $id) => ! isset($seen[$id])));
        foreach ($missing as $id) {
            $report(1, $id, '', __('acun-datatable::table.import_missing_column'));
        }
        if ($missing !== []) {
            return new ImportResult([], $errors, $ignored, $truncated);
        }

        $rows = [];
        foreach ($sheet->rows as $index => $cells) {
            $line = $index + 2;
            $values = [];
            $valid = true;
            foreach ($mapping as $position => $column) {
                $cell = $cells[$position] ?? null;
                [$ok, $value] = $this->convert($cell, $column->valueType());
                if (! $ok) {
                    $valid = false;
                    $report($line, $column->id(), self::describe($cell), __('acun-datatable::table.import_invalid', [
                        'type' => __('acun-datatable::table.type_'.($column->valueType() ?? 'text')),
                    ]));
                } elseif ($value === null && in_array($column->id(), $this->required, true)) {
                    $valid = false;
                    $report($line, $column->id(), '', __('acun-datatable::table.import_required'));
                } else {
                    $values[$column->id()] = $value;
                }
            }
            if ($valid) {
                $rows[] = ['line' => $line, 'values' => $values];
            }
        }

        return new ImportResult($rows, $errors, $ignored, $truncated);
    }

    /** @return array{0: bool, 1: mixed} */
    private function convert(mixed $cell, ?string $type): array
    {
        if ($cell === null || (is_string($cell) && trim($cell) === '')) {
            return [true, null];
        }
        $timezone = config('app.timezone') ?: 'UTC';
        switch ($type) {
            case null:
            case 'text':
                return [true, $cell instanceof DateTimeInterface ? $cell->format('Y-m-d') : (is_bool($cell) ? ($cell ? 'true' : 'false') : trim((string) $cell))];
            case 'number':
                if (is_int($cell) || is_float($cell)) {
                    return [is_finite((float) $cell), $cell];
                }
                $text = $this->numberText((string) $cell);
                if (preg_match('/^[+-]?\d+(\.\d+)?([eE][+-]?\d+)?$/', $text) !== 1 || ! is_finite((float) $text)) {
                    return [false, null];
                }

                return [true, str_contains($text, '.') || stripos($text, 'e') !== false ? (float) $text : (int) $text];
            case 'decimal':
                $text = is_int($cell) || is_float($cell) ? rtrim(rtrim(sprintf('%.10F', $cell), '0'), '.') : $this->numberText((string) $cell);
                if (preg_match('/^([+-])?(\d+)(\.\d+)?$/', $text, $m) !== 1) {
                    return [false, null];
                }
                $int = ltrim($m[2], '0') ?: '0';

                return [true, (($m[1] ?? '') === '-' ? '-' : '').$int.($m[3] ?? '')];
            case 'date':
            case 'datetime':
                $date = $cell instanceof DateTimeInterface ? CarbonImmutable::instance($cell)->setTimezone($timezone) : $this->textDate((string) $cell, $type === 'date', $timezone);
                if ($date === null) {
                    return [false, null];
                }

                return [true, $type === 'date' ? $date->startOfDay() : $date];
            case 'boolean':
                if (is_bool($cell)) {
                    return [true, $cell];
                }
                if ($cell === 1 || $cell === 0) {
                    return [true, $cell === 1];
                }
                $text = Text::fold(trim((string) $cell));
                if (in_array($text, self::TRUE, true)) {
                    return [true, true];
                }

                return in_array($text, self::FALSE, true) ? [true, false] : [false, null];
            default:
                return [false, null];
        }
    }

    private function numberText(string $text): string
    {
        $clean = preg_replace('/[\s\x{00A0}\x{202F}\']/u', '', trim($text)) ?? '';
        $group = $this->decimalSeparator === '.' ? ',' : '.';
        $parts = explode($this->decimalSeparator, $clean);
        if (count($parts) > 2) {
            return $clean;
        }
        $int = $parts[0];
        $fraction = isset($parts[1]) ? '.'.$parts[1] : '';
        if (! str_contains($int, $group)) {
            return $int.$fraction;
        }

        return preg_match('/^[+-]?\d{1,3}(?:[.,]\d{3})+$/', $int) === 1 ? str_replace($group, '', $int).$fraction : $clean;
    }

    private function textDate(string $text, bool $dayOnly, string $timezone): ?CarbonImmutable
    {
        $text = trim($text);
        if (preg_match('/^(\d{1,2})[.\/-](\d{1,2})[.\/-](\d{4})(?:[ T](\d{1,2}):(\d{2})(?::(\d{2}))?)?$/', $text, $m) === 1) {
            [$day, $month] = $this->dateOrder === 'dmy' ? [(int) $m[1], (int) $m[2]] : [(int) $m[2], (int) $m[1]];
            $year = (int) $m[3];
            [$h, $i, $s] = $dayOnly ? [0, 0, 0] : [(int) ($m[4] ?? 0), (int) ($m[5] ?? 0), (int) ($m[6] ?? 0)];
            if (! checkdate($month, $day, $year) || $h > 23 || $i > 59 || $s > 59) {
                return null;
            }

            return CarbonImmutable::create($year, $month, $day, $h, $i, $s, $timezone);
        }
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:[ T]\d{2}:\d{2}(?::\d{2}(?:\.\d+)?)?(?:Z|[+-]\d{2}:?\d{2})?)?$/', $text, $m) !== 1
            || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }

        try {
            return CarbonImmutable::parse($text, $timezone)->setTimezone($timezone);
        } catch (\Throwable) {
            return null;
        }
    }

    private static function describe(mixed $cell): string
    {
        return match (true) {
            $cell === null => '',
            $cell instanceof DateTimeInterface => $cell->format('Y-m-d H:i:s'),
            is_bool($cell) => $cell ? 'true' : 'false',
            default => (string) $cell,
        };
    }
}
