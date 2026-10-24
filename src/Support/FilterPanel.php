<?php

declare(strict_types=1);

namespace Acunsoft\DataTable\Laravel\Support;

use Acunsoft\DataTable\Laravel\Column;

/**
 * The filter panel's rules, the same as `filterControls` / `filterRules` /
 * `readFilter` / `describeFilter` in @acunsoft/datatable-dom: one control per
 * column with `filter()`, its values read back from the rules (also rules from
 * a URL) and turned into rules again.
 */
final class FilterPanel
{
    /** `options`, `boolean`, `range`, `dates` or `text`. */
    public static function kind(Column $column): string
    {
        return match (true) {
            $column->filterOptions() !== null => 'options',
            $column->valueType() === 'boolean' => 'boolean',
            in_array($column->valueType(), ['number', 'decimal'], true) => 'range',
            in_array($column->valueType(), ['date', 'datetime'], true) => 'dates',
            default => 'text',
        };
    }

    /**
     * @param list<array{field: string, operator?: string, op?: string, value?: mixed}> $filters
     *
     * @return array{text: string, selected: list<string>, min: string, max: string, flag: string}
     */
    public static function read(Column $column, array $filters): array
    {
        $values = ['text' => '', 'selected' => [], 'min' => '', 'max' => '', 'flag' => ''];
        foreach ($filters as $rule) {
            if (($rule['field'] ?? null) !== $column->id()) {
                continue;
            }
            $op = $rule['operator'] ?? $rule['op'] ?? '';
            $value = $rule['value'] ?? null;
            match ($op) {
                'contains' => $values['text'] = (string) $value,
                'eq' => self::kind($column) === 'boolean'
                    ? $values['flag'] = in_array($value, [true, 1, '1', 'true'], true) ? 'true' : 'false'
                    : $values['selected'][] = (string) $value,
                'in' => $values['selected'] = array_merge($values['selected'], array_map('strval', (array) $value)),
                'between' => [$values['min'], $values['max']] = array_map('strval', array_values((array) $value) + ['', '']),
                'gte', 'gt' => $values['min'] = (string) $value,
                'lte', 'lt' => $values['max'] = (string) $value,
                default => null,
            };
        }

        return $values;
    }

    /**
     * Wire-format rules (`field`, `op`, `value`) for a control's values; none when it constrains nothing.
     *
     * @param array{text?: string, selected?: list<string>, min?: string|null, max?: string|null, flag?: string} $values
     *
     * @return list<array{field: string, op: string, value: mixed}>
     */
    public static function rules(Column $column, array $values): array
    {
        $field = $column->id();
        switch (self::kind($column)) {
            case 'text':
                $text = trim((string) ($values['text'] ?? ''));

                return $text === '' ? [] : [['field' => $field, 'op' => 'contains', 'value' => $text]];
            case 'boolean':
                $flag = $values['flag'] ?? '';

                return in_array($flag, ['true', 'false'], true) ? [['field' => $field, 'op' => 'eq', 'value' => $flag]] : [];
            case 'options':
                $allowed = array_map('strval', array_keys($column->filterOptions() ?? []));
                $chosen = array_values(array_intersect($allowed, array_map('strval', (array) ($values['selected'] ?? []))));

                return $chosen === [] ? [] : [['field' => $field, 'op' => 'in', 'value' => $chosen]];
            default:
                $min = trim((string) ($values['min'] ?? ''));
                $max = trim((string) ($values['max'] ?? ''));

                return match (true) {
                    $min !== '' && $max !== '' => [['field' => $field, 'op' => 'between', 'value' => [$min, $max]]],
                    $min !== '' => [['field' => $field, 'op' => 'gte', 'value' => $min]],
                    $max !== '' => [['field' => $field, 'op' => 'lte', 'value' => $max]],
                    default => [],
                };
        }
    }

    /**
     * Chip text, e.g. `Durum: Ödendi, Kargoda` or `Tutar: ≥ 1.000`; null when the control is inactive.
     *
     * @param array{text: string, selected: list<string>, min: string, max: string, flag: string} $values
     */
    public static function describe(Column $column, array $values): ?string
    {
        if (self::rules($column, $values) === []) {
            return null;
        }
        $kind = self::kind($column);
        $show = fn (string $value): string => match ($kind) {
            'dates' => Format::value(substr($value, 0, 10), 'date'),
            'range' => is_numeric($value) ? Format::value($value, 'number') : $value,
            default => $value,
        };
        $summary = match ($kind) {
            'text' => '“'.trim($values['text']).'”',
            'boolean' => __($values['flag'] === 'true' ? 'acun-datatable::table.yes' : 'acun-datatable::table.no'),
            'options' => implode(', ', array_map(
                fn ($key) => (string) $column->filterOptions()[$key],
                array_values(array_filter(array_keys($column->filterOptions() ?? []), fn ($key) => in_array((string) $key, $values['selected'], true))),
            )),
            default => $values['min'] !== '' && $values['max'] !== ''
                ? $show($values['min']).' – '.$show($values['max'])
                : ($values['min'] !== '' ? '≥ '.$show($values['min']) : '≤ '.$show($values['max'])),
        };

        return $column->headerText().': '.$summary;
    }
}
