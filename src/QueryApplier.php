<?php

declare(strict_types=1);

namespace Acunsoft\DataTable\Laravel;

use Acunsoft\DataTable\Laravel\Support\Text;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use LogicException;
use Throwable;

/**
 * Apply a TableQuery to an Eloquent or query builder, with the same rules as
 * the browser packages:
 *
 * - Only declared columns can be filtered, searched or sorted (allowlist); the
 *   SQL only ever contains developer-given column names, every value is bound.
 * - contains / startsWith use `LIKE ? ESCAPE '!'` (ILIKE on PostgreSQL); with
 *   `Column::searchUsing()` they match a folded column, accent- and
 *   Turkish-insensitively, independent of the database collation.
 * - Smart search: every word must match in some searchable column.
 * - `date` columns compare days; in `datetime` columns a date-only value means
 *   the whole day (`whereDate`), a value with a time an exact instant.
 * - Sorting adds the primary key as a tie-breaker so pages are stable.
 */
final class QueryApplier
{
    private const DATE_ONLY = '/^\d{4}-\d{2}-\d{2}$/';

    /**
     * @param iterable<Column> $columns
     *
     * @throws InvalidTableQuery
     */
    public static function apply(EloquentBuilder|QueryBuilder $builder, TableQuery $query, iterable $columns): EloquentBuilder|QueryBuilder
    {
        $byId = self::index($columns);

        foreach ($query->filters as $index => $filter) {
            $column = $byId[$filter['field']] ?? throw InvalidTableQuery::at("filters.{$index}.field", "Unknown column [{$filter['field']}].");
            if (! $column->isFilterable()) {
                throw InvalidTableQuery::at("filters.{$index}.field", "Column [{$filter['field']}] cannot be filtered.");
            }
            self::filter($builder, $column, $filter['operator'], $filter['value'], "filters.{$index}.value");
        }

        $terms = $query->searchTerms();
        if ($terms !== []) {
            $searchable = array_values(array_filter($byId, fn (Column $column) => $column->isSearchable()));
            if ($searchable === []) {
                $builder->whereRaw('1 = 0'); // nothing is searchable, so nothing matches (as in the browser)
            }
            foreach ($searchable === [] ? [] : $terms as $term) {
                $builder->where(function ($group) use ($searchable, $term): void {
                    foreach ($searchable as $column) {
                        self::like($group, $column, '%'.Text::escapeLike(self::needle($column, $term)).'%', 'or');
                    }
                });
            }
        }

        $sortedColumns = [];
        foreach ($query->sorting as $rule) {
            $column = $byId[$rule['field']] ?? throw InvalidTableQuery::at('sort', "Unknown column [{$rule['field']}].");
            if (! $column->isSortable()) {
                throw InvalidTableQuery::at('sort', "Column [{$rule['field']}] cannot be sorted.");
            }
            $builder->orderBy($column->databaseColumn(), $rule['direction']);
            $sortedColumns[] = $column->databaseColumn();
        }
        if ($builder instanceof EloquentBuilder) {
            $model = $builder->getModel();
            if (! in_array($model->getKeyName(), $sortedColumns, true) && ! in_array($model->getQualifiedKeyName(), $sortedColumns, true)) {
                $builder->orderBy($model->getQualifiedKeyName()); // stable pages
            }
        }

        return $builder;
    }

    /**
     * Apply the query and return one page, with the page size and number from the query.
     *
     * @param iterable<Column> $columns
     */
    public static function paginate(EloquentBuilder|QueryBuilder $builder, TableQuery $query, iterable $columns): LengthAwarePaginator
    {
        return self::apply($builder, $query, $columns)->paginate($query->pageSize, ['*'], 'page', $query->page);
    }

    /**
     * @param iterable<Column> $columns
     *
     * @return array<string, Column>
     */
    private static function index(iterable $columns): array
    {
        $byId = [];
        foreach ($columns as $column) {
            if (! $column instanceof Column) {
                throw new LogicException('Columns must be '.Column::class.' instances.');
            }
            if (isset($byId[$column->id()])) {
                throw new LogicException("Duplicate column ID [{$column->id()}].");
            }
            $byId[$column->id()] = $column;
        }

        return $byId;
    }

    private static function filter(EloquentBuilder|QueryBuilder $builder, Column $column, string $operator, mixed $value, string $at): void
    {
        $name = $column->databaseColumn();
        $textual = in_array($column->valueType(), [null, 'text'], true);
        if (in_array($operator, ['gt', 'lt', 'between'], true) && $column->valueType() === 'boolean') {
            throw InvalidTableQuery::at($at, "{$operator} is not supported for boolean columns.");
        }

        match ($operator) {
            'contains' => self::like($builder, $column, '%'.Text::escapeLike(self::needle($column, trim($value))).'%', 'and'),
            'startsWith' => self::like($builder, $column, Text::escapeLike(self::needle($column, trim($value))).'%', 'and'),
            'eq' => self::compare($builder, $column, '=', $value, $at),
            'gt' => self::compare($builder, $column, '>', $value, $at),
            'lt' => self::compare($builder, $column, '<', $value, $at),
            'between' => $builder->where(function ($group) use ($column, $value, $at): void {
                self::compare($group, $column, '>=', $value[0], $at);
                self::compare($group, $column, '<=', $value[1], $at);
            }),
            'in' => $builder->where(function ($group) use ($column, $value, $at): void {
                foreach ($value as $item) {
                    $group->orWhere(fn ($one) => self::compare($one, $column, '=', $item, $at));
                }
            }),
            'empty' => $builder->where(function ($group) use ($name, $textual): void {
                $group->whereNull($name);
                if ($textual) {
                    $group->orWhere($name, '=', '');
                }
            }),
            'notEmpty' => $textual ? $builder->whereNotNull($name)->where($name, '<>', '') : $builder->whereNotNull($name),
        };
    }

    private static function compare(EloquentBuilder|QueryBuilder $builder, Column $column, string $operator, string $value, string $at): void
    {
        $name = $column->databaseColumn();
        switch ($column->valueType()) {
            case 'number':
            case 'decimal':
                if (! is_numeric(trim($value)) || ! is_finite((float) $value)) {
                    throw InvalidTableQuery::at($at, "[{$value}] is not a number.");
                }
                $builder->where($name, $operator, trim($value));

                return;
            case 'boolean':
                $flag = match (strtolower(trim($value))) {
                    'true', '1' => 1,
                    'false', '0' => 0,
                    default => throw InvalidTableQuery::at($at, "[{$value}] is not true or false."),
                };
                $builder->where($name, '=', $flag);

                return;
            case 'date':
                $builder->whereDate($name, $operator, self::day($value, $at));

                return;
            case 'datetime':
                if (preg_match(self::DATE_ONLY, trim($value)) === 1) {
                    // A calendar day in a datetime column means the whole day.
                    $builder->whereDate($name, $operator, self::day($value, $at));
                } else {
                    $builder->where($name, $operator, self::instant($value, $at));
                }

                return;
            default:
                $builder->where($name, $operator, $value);
        }
    }

    /** The calendar day as written by the client (it sends local dates), e.g. 2026-10-23. */
    private static function day(string $value, string $at): string
    {
        $value = trim($value);
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:[T ].*)?$/', $value, $match) !== 1 || ! checkdate((int) $match[2], (int) $match[3], (int) $match[1])) {
            throw InvalidTableQuery::at($at, "[{$value}] is not a date.");
        }

        return "{$match[1]}-{$match[2]}-{$match[3]}";
    }

    /** An instant in the application's time zone, as stored in datetime columns. */
    private static function instant(string $value, string $at): string
    {
        try {
            $parsed = CarbonImmutable::parse(trim($value));
        } catch (Throwable) {
            throw InvalidTableQuery::at($at, "[{$value}] is not a date and time.");
        }
        $zone = function_exists('config') ? (string) config('app.timezone', 'UTC') : 'UTC';

        return $parsed->setTimezone($zone)->format('Y-m-d H:i:s');
    }

    private static function needle(Column $column, string $text): string
    {
        return $column->foldedColumn() !== null ? Text::fold($text) : $text;
    }

    private static function like(EloquentBuilder|QueryBuilder $builder, Column $column, string $pattern, string $boolean): void
    {
        $query = $builder instanceof EloquentBuilder ? $builder->getQuery() : $builder;
        $grammar = $query->getGrammar();
        $postgres = $query->getConnection()->getDriverName() === 'pgsql';
        $target = $column->foldedColumn() ?? $column->databaseColumn();
        $expression = $grammar->wrap($target);
        if ($postgres && $column->foldedColumn() === null && ! in_array($column->valueType(), [null, 'text'], true)) {
            $expression = "CAST({$expression} AS TEXT)";
        }
        $builder->whereRaw($expression.($postgres ? ' ILIKE' : ' LIKE')." ? ESCAPE '!'", [$pattern], $boolean);
    }
}
