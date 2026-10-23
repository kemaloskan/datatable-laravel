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
 * - contains / startsWith and the search are case-, accent- and
 *   Turkish-insensitive on every database: the column is folded in SQL
 *   (a PHP function on SQLite, LOWER/REPLACE elsewhere), or
 *   `Column::searchUsing()` names a stored folded copy, faster on large tables.
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
                        self::like($group, $column, $term, false, 'or');
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
            'contains' => self::like($builder, $column, trim($value), false, 'and'),
            'startsWith' => self::like($builder, $column, trim($value), true, 'and'),
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

    /**
     * Lowercase letters folded in SQL after LOWER(): Turkish letters and the
     * accents of the supported languages (de, fr, es); same result as
     * Text::fold() for them. `İ` and `I` are replaced before LOWER() so that
     * Turkish collations (I → ı) and ICU (İ → i̇) cannot interfere.
     */
    private const SQL_FOLD = [
        'ı' => 'i', 'ş' => 's', 'ğ' => 'g', 'ç' => 'c', 'ö' => 'o', 'ü' => 'u', 'â' => 'a', 'î' => 'i', 'û' => 'u',
        'á' => 'a', 'à' => 'a', 'ä' => 'a', 'ã' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o',
        'ú' => 'u', 'ù' => 'u', 'ñ' => 'n', 'ÿ' => 'y',
    ];

    /** @var \WeakMap<\PDO, true>|null connections that have the adt_fold() SQLite function */
    private static ?\WeakMap $sqliteFold = null;

    /**
     * SQL for Text::fold() of an expression. SQLite gets a registered PHP
     * function (its LOWER() only knows ASCII, and long REPLACE chains overflow
     * its parser); other databases use LOWER() and a REPLACE chain.
     */
    private static function foldSql(\Illuminate\Database\ConnectionInterface $connection, string $driver, string $expression): string
    {
        if ($driver === 'sqlite' && $connection instanceof \Illuminate\Database\Connection) {
            self::$sqliteFold ??= new \WeakMap();
            $fold = static fn (mixed $value): ?string => $value === null ? null : Text::fold((string) $value);
            foreach (array_unique([$connection->getPdo(), $connection->getReadPdo()], SORT_REGULAR) as $pdo) {
                if (! $pdo instanceof \PDO || isset(self::$sqliteFold[$pdo])) {
                    continue;
                }
                if (class_exists(\Pdo\Sqlite::class) && $pdo instanceof \Pdo\Sqlite) {
                    $pdo->createFunction('adt_fold', $fold, 1, \Pdo\Sqlite::DETERMINISTIC);
                } else {
                    $pdo->sqliteCreateFunction('adt_fold', $fold, 1, \PDO::SQLITE_DETERMINISTIC);
                }
                self::$sqliteFold[$pdo] = true;
            }

            return "adt_fold({$expression})";
        }
        $literal = fn (string $char): string => ($driver === 'sqlsrv' ? 'N' : '')."'{$char}'";
        $folded = "LOWER(REPLACE(REPLACE({$expression}, {$literal('İ')}, {$literal('i')}), {$literal('I')}, {$literal('i')}))";
        foreach (self::SQL_FOLD as $from => $to) {
            $folded = "REPLACE({$folded}, {$literal($from)}, {$literal($to)})";
        }

        return $folded;
    }

    /**
     * `contains` / `startsWith` for text, case-, accent- and Turkish-insensitive on
     * every database:
     *
     * - with `Column::searchUsing()`: the folded column against the folded text
     *   (fastest; the column can be indexed for `startsWith`);
     * - otherwise: the column folded in SQL (see foldSql) against the folded text, OR
     *   the column itself against the text as typed (LIKE, ILIKE on PostgreSQL),
     *   so letters outside SQL_FOLD still match as the collation allows.
     *
     * Every value is bound; `%` and `_` typed by users are literal (`ESCAPE '!'`).
     */
    private static function like(EloquentBuilder|QueryBuilder $builder, Column $column, string $text, bool $prefix, string $boolean): void
    {
        $query = $builder instanceof EloquentBuilder ? $builder->getQuery() : $builder;
        $grammar = $query->getGrammar();
        $driver = $query->getConnection()->getDriverName();
        $pattern = fn (string $value): string => ($prefix ? '' : '%').Text::escapeLike($value).'%';
        if ($column->foldedColumn() !== null) {
            $builder->whereRaw($grammar->wrap($column->foldedColumn())." LIKE ? ESCAPE '!'", [$pattern(Text::fold($text))], $boolean);

            return;
        }
        $expression = $grammar->wrap($column->databaseColumn());
        if ($driver === 'pgsql' && ! in_array($column->valueType(), [null, 'text'], true)) {
            $expression = "CAST({$expression} AS TEXT)";
        }
        $folded = self::foldSql($query->getConnection(), $driver, $expression);
        $builder->where(function ($group) use ($folded, $expression, $driver, $pattern, $text): void {
            $group->whereRaw("{$folded} LIKE ? ESCAPE '!'", [$pattern(Text::fold($text))])
                ->orWhereRaw($expression.($driver === 'pgsql' ? ' ILIKE' : ' LIKE')." ? ESCAPE '!'", [$pattern($text)]);
        }, null, null, $boolean);
    }
}
