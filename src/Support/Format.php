<?php

declare(strict_types=1);

namespace Acunsoft\DataTable\Laravel\Support;

use Acunsoft\DataTable\Laravel\Column;
use Carbon\CarbonInterface;
use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * Plain display text for a cell, following the same rules as the DOM renderer:
 * numbers and decimals in the locale (decimals keep their written scale), dates
 * as a medium date, datetimes with the time, booleans as Yes/No, missing values
 * as empty text. A value a typed column cannot read is shown as written.
 */
final class Format
{
    public static function cell(Column $column, mixed $row): string
    {
        $value = data_get($row, $column->databaseColumn());
        if ($column->formatter() !== null) {
            $text = ($column->formatter())($value, $row);
            if (! is_string($text)) {
                throw new \UnexpectedValueException("Formatter for column [{$column->id()}] must return a string.");
            }

            return $text;
        }

        return self::value($value, $column->valueType());
    }

    public static function value(mixed $value, ?string $type, ?string $locale = null): string
    {
        $locale ??= app()->getLocale();
        if ($value === null || (is_float($value) && is_nan($value))) {
            return '';
        }
        if (is_bool($value) || $type === 'boolean') {
            $flag = match (true) {
                is_bool($value) => $value,
                in_array(strtolower((string) $value), ['1', 'true'], true) => true,
                in_array(strtolower((string) $value), ['0', 'false'], true) => false,
                default => null,
            };

            return $flag === null ? (string) $value : __($flag ? 'acun-datatable::table.yes' : 'acun-datatable::table.no');
        }
        if (in_array($type, ['number', 'decimal'], true) || is_int($value) || is_float($value)) {
            $text = trim((string) $value);
            if (! is_numeric($text)) {
                return (string) $value;
            }
            $scale = $type === 'decimal' && str_contains($text, '.') ? strlen(substr($text, strpos($text, '.') + 1)) : null;

            return self::number((float) $text, $scale, $locale);
        }
        if ($value instanceof DateTimeInterface || in_array($type, ['date', 'datetime'], true)) {
            try {
                $date = $value instanceof DateTimeInterface ? Carbon::instance($value) : Carbon::parse((string) $value);
            } catch (\Throwable) {
                return (string) $value;
            }

            return self::date($date, $type === 'datetime', $locale);
        }

        return (string) $value;
    }

    private static function number(float $number, ?int $scale, string $locale): string
    {
        if (class_exists(\NumberFormatter::class)) {
            $formatter = new \NumberFormatter($locale, \NumberFormatter::DECIMAL);
            if ($scale !== null) {
                $formatter->setAttribute(\NumberFormatter::MIN_FRACTION_DIGITS, $scale);
                $formatter->setAttribute(\NumberFormatter::MAX_FRACTION_DIGITS, $scale);
            } else {
                $formatter->setAttribute(\NumberFormatter::MAX_FRACTION_DIGITS, 20);
            }
            $text = $formatter->format($number);
            if ($text !== false) {
                return $text;
            }
        }

        return $scale === null ? (string) $number : number_format($number, $scale, '.', '');
    }

    private static function date(CarbonInterface $date, bool $withTime, string $locale): string
    {
        if (class_exists(\IntlDateFormatter::class)) {
            $formatter = new \IntlDateFormatter(
                $locale,
                \IntlDateFormatter::MEDIUM,
                $withTime ? \IntlDateFormatter::SHORT : \IntlDateFormatter::NONE,
                $date->getTimezone(),
            );
            $text = $formatter->format($date->toDateTimeImmutable());
            if ($text !== false) {
                return $text;
            }
        }

        return $date->format($withTime ? 'Y-m-d H:i' : 'Y-m-d');
    }
}
