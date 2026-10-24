<?php

declare(strict_types=1);

namespace Acunsoft\DataTable\Laravel;

use Acunsoft\DataTable\Laravel\Support\Text;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\QueryException;
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
        if ($query->searchKeys !== null && $query->search !== '') {
            // A search engine answered the text: its keys, within the filters above.
            if ($query->searchKeys === []) {
                $builder->whereRaw('1 = 0');
            } elseif ($builder instanceof EloquentBuilder) {
                $builder->whereKey($query->searchKeys);
            } else {
                $builder->whereIn($query->searchKeyColumn, $query->searchKeys);
            }
        } elseif ($terms !== []) {
            $searchable = array_values(array_filter($byId, fn (Column $column) => $column->isSearchable()));
            if ($searchable === []) {
                $builder->whereRaw('1 = 0'); // nothing is searchable, so nothing matches (as in the browser)
            }
            foreach ($searchable === [] ? [] : $terms as $term) {
                // Only columns whose text could contain the word: "ayse" is never
                // part of an amount or a date, so those columns are not scanned.
                $candidates = array_values(array_filter($searchable, fn (Column $column) => self::couldContain($column, $term)));
                if ($candidates === []) {
                    $builder->whereRaw('1 = 0'); // every word must match somewhere

                    continue;
                }
                $builder->where(function ($group) use ($candidates, $term): void {
                    foreach ($candidates as $column) {
                        self::like($group, $column, $term, false, 'or');
                    }
                });
            }
        }

        // The user's sort comes first; an order written in query() only breaks ties.
        $base = $builder instanceof EloquentBuilder ? $builder->getQuery() : $builder;
        $written = $query->sorting === [] ? [] : ($base->orders ?? []);
        if ($written !== []) {
            $base->orders = null;
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
        foreach ($written as $order) {
            $base->orders[] = $order;
            if (isset($order['column']) && is_string($order['column'])) {
                $sortedColumns[] = $order['column'];
            }
        }
        if ($builder instanceof EloquentBuilder) {
            $model = $builder->getModel();
            if (! in_array($model->getKeyName(), $sortedColumns, true) && ! in_array($model->getQualifiedKeyName(), $sortedColumns, true)) {
                // Stable pages. Same direction as the last sort rule, so an index on
                // (sorted column, key) serves the whole ORDER BY (deep pages 27× faster, measured on 5M PostgreSQL rows).
                $builder->orderBy($model->getQualifiedKeyName(), $query->sorting === [] ? 'asc' : $query->sorting[array_key_last($query->sorting)]['direction']);
            }
        }

        return $builder;
    }

    /**
     * Apply the query and fetch one page.
     *
     * - `$countLimit` (default 10,000) and `$countTimeout` (default 250 ms): up
     *   to the limit the count is exact (it stops after `$countLimit` + 1
     *   matches; rare matches no index serves can still read the whole
     *   table, so its cost depends on the query and the database). Beyond it an exact
     *   COUNT(*) runs with a time limit (PostgreSQL, MySQL, MariaDB); only when
     *   it would take longer, TablePage::totalIsExact() is false and the UI
     *   shows "10,000+". `$countLimit = null` always counts everything without
     *   a time limit; `$countTimeout = null` never counts past the limit.
     * - `$maxResultWindow` (default 100,000): refuse pages that start past this
     *   many rows (deep OFFSETs are slow and an easy way to load the database).
     *   `null` allows any page.
     *
     * @param iterable<Column> $columns
     *
     * @throws InvalidTableQuery
     */
    /**
     * Keyset paging: the page after `$query->cursor`, continuing after the
     * last row of the page before by its sort values and key, through an index
     * instead of skipping `OFFSET` rows (measured on 5,000,000 PostgreSQL
     * rows: page 100,000 in 0.05 ms against 3.8 s by `OFFSET`; the cost on
     * other tables and databases depends on their indexes). The condition is index-friendly on every
     * database: the first sort column is bounded (`a <= x`) before the exact
     * lexicographic test, which plain `a < x OR (a = x AND …)` is not on
     * PostgreSQL. No result window applies. Mark sort columns that may hold
     * NULL with `Column::nullable()`; a NULL in the cursor is handled either
     * way. A query builder (not Eloquent) gets `$key` (default `id`) as the
     * last sort rule, so rows with equal values are never skipped. The total
     * is counted as in page mode.
     *
     * @param iterable<Column> $columns
     */
    public static function cursorPage(EloquentBuilder|QueryBuilder $builder, TableQuery $query, iterable $columns, ?int $countLimit = 10_000, ?int $countTimeout = 250, ?string $key = null): TablePage
    {
        $applied = self::apply($builder, $query, $columns);
        $base = $applied instanceof EloquentBuilder ? $applied->getQuery() : $applied;
        if (! $applied instanceof EloquentBuilder) {
            $key ??= 'id';
            $table = is_string($base->from) ? $base->from : null;
            $sorted = array_map(fn (array $order) => $order['column'] ?? null, $base->orders ?? []);
            if (! in_array($key, $sorted, true) && ($table === null || ! in_array("{$table}.{$key}", $sorted, true))) {
                $last = $base->orders === null || $base->orders === [] ? 'asc' : strtolower((string) ($base->orders[array_key_last($base->orders)]['direction'] ?? 'asc'));
                $base->orderBy($key, $last === 'desc' ? 'desc' : 'asc');
            }
        }
        $nullable = [];
        foreach ($columns as $column) {
            if ($column->isNullable()) {
                $nullable[$column->databaseColumn()] = true;
            }
        }
        $orders = [];
        foreach ($base->orders ?? [] as $order) {
            if (! isset($order['column']) || ! is_string($order['column'])) {
                throw new \LogicException('Cursor paging needs plain orderBy() columns.');
            }
            $orders[] = ['column' => $order['column'], 'direction' => strtolower((string) $order['direction']) === 'desc' ? 'desc' : 'asc'];
        }
        if ($orders === []) {
            throw new \LogicException('Cursor paging needs a sort with a unique key last (Eloquent models get it).');
        }
        $signature = substr(sha1((string) json_encode($orders)), 0, 12);
        [$total, $exact] = self::count($applied, $countLimit, $countTimeout);
        $page = clone $applied;
        if ($query->cursor !== null) {
            self::after($page, $orders, self::cursorValues($query->cursor, $signature, count($orders)), $nullable, $base->getConnection()->getDriverName() === 'pgsql');
        }
        $items = $page->limit($query->pageSize + 1)->get();
        $more = count($items) > $query->pageSize;
        $items = $more ? $items->slice(0, $query->pageSize)->values() : $items;
        $next = null;
        if ($more) {
            $last = $items->last();
            $values = array_map(fn (array $order) => self::rawValue($last, $order['column']), $orders);
            $next = rtrim(strtr(base64_encode((string) json_encode(['s' => $signature, 'v' => $values])), '+/', '-_'), '=');
        }

        return new TablePage($items, $total, $query->pageSize, $query->page, $exact, $more, $next);
    }

    /**
     * Rows after `$values` in the order of `$orders`: `a ≤ x AND (a < x OR (a = x AND (b > y OR (b = y AND id > z))))`,
     * with each comparison turned by its direction. NULL sorts where the database puts it (the
     * ORDER BY stays plain, so indexes serve it): the largest value on PostgreSQL, the smallest
     * elsewhere.
     *
     * @param list<array{column: string, direction: string}> $orders
     * @param list<mixed> $values
     * @param array<string, true> $nullable
     */
    private static function after(EloquentBuilder|QueryBuilder $builder, array $orders, array $values, array $nullable = [], bool $nullsLargest = false): void
    {
        // The rows past `$values[$index]` in its column ($orEqual: and those equal to it).
        $beyond = function ($where, int $index, bool $orEqual) use ($orders, $values, $nullable, $nullsLargest): void {
            $order = $orders[$index];
            $column = $order['column'];
            $value = $values[$index];
            $nullsFirst = ($order['direction'] === 'asc') !== $nullsLargest;
            if ($value === null) {
                // Past the NULLs when they come first; among the NULLs (or nothing) when they come last.
                $nullsFirst ? ($orEqual ? null : $where->whereNotNull($column)) : ($orEqual ? $where->whereNull($column) : $where->whereRaw('1 = 0'));

                return;
            }
            $operator = ($order['direction'] === 'desc' ? '<' : '>').($orEqual ? '=' : '');
            if (isset($nullable[$column]) && ! $nullsFirst) {
                $where->where(fn ($either) => $either->where($column, $operator, $value)->orWhereNull($column));
            } else {
                $where->where($column, $operator, $value);
            }
        };
        $builder->where(fn ($where) => $beyond($where, 0, true));
        $nest = function ($where, int $index) use (&$nest, $beyond, $orders, $values): void {
            $where->where(fn ($strict) => $beyond($strict, $index, false));
            if ($index + 1 < count($orders)) {
                $where->orWhere(function ($equal) use (&$nest, $orders, $values, $index): void {
                    $values[$index] === null ? $equal->whereNull($orders[$index]['column']) : $equal->where($orders[$index]['column'], '=', $values[$index]);
                    $equal->where(fn ($rest) => $nest($rest, $index + 1));
                });
            }
        };
        $builder->where(fn ($where) => $nest($where, 0));
    }

    /** @return list<mixed> the sort values a cursor carries, checked against this sort */
    private static function cursorValues(string $cursor, string $signature, int $count): array
    {
        $decoded = json_decode((string) base64_decode(strtr($cursor, '-_', '+/'), true), true);
        if (! is_array($decoded) || ($decoded['s'] ?? null) !== $signature) {
            throw InvalidTableQuery::at('cursor', 'cursor does not match the sort; start from the first page.');
        }
        $values = $decoded['v'] ?? null;
        if (! is_array($values) || ! array_is_list($values) || count($values) !== $count
            || array_filter($values, fn ($value) => ! is_scalar($value) && $value !== null) !== []) {
            throw InvalidTableQuery::at('cursor', 'cursor must be the nextCursor of a previous page.');
        }

        return $values;
    }

    /** A row's stored value of an order column (`orders.total` → `total`), before casts. */
    private static function rawValue(mixed $row, string $column): mixed
    {
        $attribute = str_contains($column, '.') ? substr($column, strrpos($column, '.') + 1) : $column;
        $value = $row instanceof \Illuminate\Database\Eloquent\Model ? $row->getRawOriginal($attribute) : data_get($row, $attribute);

        return $value instanceof \DateTimeInterface ? $value->format('Y-m-d H:i:s') : $value;
    }

    public static function paginate(EloquentBuilder|QueryBuilder $builder, TableQuery $query, iterable $columns, ?int $countLimit = 10_000, ?int $maxResultWindow = 100_000, ?int $countTimeout = 250, ?string $key = null): TablePage
    {
        if ($query->cursorPaging) {
            return self::cursorPage($builder, $query, $columns, $countLimit, $countTimeout, $key);
        }
        $offset = ($query->page - 1) * $query->pageSize;
        if ($maxResultWindow !== null && $offset >= $maxResultWindow) {
            throw InvalidTableQuery::at('page', "Pages past the first {$maxResultWindow} results are not available; narrow the filters or sort the other way.");
        }
        $applied = self::apply($builder, $query, $columns);
        [$total, $exact] = self::count($applied, $countLimit, $countTimeout);
        if ($exact) {
            $items = $offset < $total ? (clone $applied)->offset($offset)->limit($query->pageSize)->get() : [];

            return new TablePage($items, $total, $query->pageSize, $query->page, true, false);
        }
        $items = (clone $applied)->offset($offset)->limit($query->pageSize + 1)->get();
        $more = count($items) > $query->pageSize;

        return new TablePage($more ? $items->slice(0, $query->pageSize)->values() : $items, $total, $query->pageSize, $query->page, false, $more);
    }

    /**
     * Count matches. Up to `$limit` the count stops after `$limit` + 1 rows and
     * is exact. Past it, an exact COUNT(*) gets `$timeoutMs` milliseconds on
     * PostgreSQL, MySQL and MariaDB; when it is slower (or on other databases)
     * the result is the limit, marked as a lower bound. A query that ran out of
     * time is remembered for ten minutes, so paging through it does not pay the
     * time limit again.
     *
     * @return array{0: int, 1: bool} the count (or the limit) and whether it is exact
     */
    public static function count(EloquentBuilder|QueryBuilder $builder, ?int $limit = 10_000, ?int $timeoutMs = 250): array
    {
        $base = $builder instanceof EloquentBuilder ? $builder->toBase() : $builder;
        if ($limit === null) {
            return [(clone $base)->getCountForPagination(), true];
        }
        if ($limit < 1) {
            throw new \InvalidArgumentException('The count limit must be at least 1, or null.');
        }
        if ($timeoutMs !== null && $timeoutMs < 1) {
            throw new \InvalidArgumentException('The count timeout must be at least 1 ms, or null.');
        }
        $probe = (clone $base)->reorder()->limit($limit + 1)->offset(0);
        $probe->columns = null;
        $counted = $base->newQuery()->fromSub($probe->selectRaw('1 as adt_match'), 'adt_matches')->count();
        if ($counted <= $limit) {
            return [$counted, true];
        }
        $exact = $timeoutMs === null ? null : self::countWithin($base, $timeoutMs);

        return $exact === null ? [$limit, false] : [$exact, true];
    }

    /**
     * Footer totals of the columns with `aggregate()` over every row the query
     * matches (search and filters; sorting and paging are ignored), in one SQL
     * query. Averages are rounded to two decimals. Keys are column IDs; values
     * are strings, numbers or null.
     *
     * @param iterable<Column> $columns
     *
     * @return array<string, string|int|float|null>
     */
    public static function aggregates(EloquentBuilder|QueryBuilder $builder, TableQuery $query, iterable $columns): array
    {
        $all = is_array($columns) ? $columns : iterator_to_array($columns, false);
        $columns = array_values(array_filter($all, fn (Column $column) => $column->aggregateKind() !== null));
        if ($columns === []) {
            return [];
        }
        // Every column takes part in the search and filters; only the aggregated ones are selected.
        $base = self::apply(clone $builder, $query, $all);
        $base = $base instanceof EloquentBuilder ? $base->toBase() : $base;
        $base = (clone $base)->reorder()->limit(null)->offset(null);
        $grammar = $base->getGrammar();
        $selects = [];
        foreach ($columns as $index => $column) {
            $wrapped = $grammar->wrap($column->databaseColumn());
            $selects[] = match ($column->aggregateKind()) {
                'sum' => "SUM({$wrapped})",
                'avg' => "AVG({$wrapped})",
                'min' => "MIN({$wrapped})",
                'max' => "MAX({$wrapped})",
                default => "COUNT({$wrapped})",
            }." as adt_total_{$index}";
        }
        $base->columns = null;
        $row = (array) $base->selectRaw(implode(', ', $selects))->first();
        $totals = [];
        foreach ($columns as $index => $column) {
            $value = $row["adt_total_{$index}"] ?? null;
            if ($value !== null && $column->aggregateKind() === 'avg') {
                $value = number_format((float) $value, 2, '.', '');
            } elseif (is_float($value) && $column->valueType() === 'decimal') {
                // SQLite sums decimals as floats (PostgreSQL and MySQL return exact text): keep two decimals.
                $value = number_format($value, 2, '.', '');
            }
            $totals[$column->id()] = $value;
        }

        return $totals;
    }

    /** An exact count, or null when the database cannot finish it within `$ms`. */
    private static function countWithin(QueryBuilder $base, int $ms): ?int
    {
        $connection = $base->getConnection();
        $driver = $connection->getDriverName();
        if (! in_array($driver, ['pgsql', 'mysql', 'mariadb'], true)) {
            return null; // no per-statement time limit: keep the lower bound
        }
        $slow = 'acun-datatable:slow-count:'.sha1($connection->getName()."\0".$base->toSql()."\0".serialize($base->getBindings()));
        $cache = self::cache();
        if ($cache?->has($slow)) {
            return null;
        }
        $count = fn (): int => (clone $base)->getCountForPagination();
        try {
            if ($driver === 'pgsql') {
                // In a (nested) transaction, so a cancelled statement only rolls back its own savepoint.
                $previous = (string) $connection->selectOne("select current_setting('statement_timeout') as value")->value;

                return $connection->transaction(function () use ($connection, $ms, $previous, $count): int {
                    $connection->statement("select set_config('statement_timeout', ?, true)", [(string) $ms]);
                    $total = $count();
                    $connection->statement("select set_config('statement_timeout', ?, true)", [$previous]);

                    return $total;
                });
            }
            // MySQL counts milliseconds, MariaDB seconds; both only for this session, restored below.
            // MariaDB is often reached through the mysql driver: ask the server, not the driver name.
            $maria = $driver === 'mariadb' || (method_exists($connection, 'isMaria') && $connection->isMaria());
            $variable = $maria ? 'max_statement_time' : 'max_execution_time';
            // Written as numbers, not bound: MariaDB refuses a bound text value ("0.000000") for its
            // numeric variable. Both values are numbers we made (cast), never text from a request.
            $number = fn (mixed $value): string => $maria ? rtrim(rtrim(sprintf('%.6F', (float) $value), '0'), '.') : (string) (int) $value;
            try {
                $previous = $number($connection->selectOne("select @@session.{$variable} as value")->value);
                $connection->statement("set session {$variable} = ".$number($maria ? $ms / 1000 : $ms));
            } catch (QueryException) {
                return null; // e.g. MariaDB behind the mysql driver: no time limit available
            }
            try {
                return $count();
            } finally {
                $connection->statement("set session {$variable} = {$previous}");
            }
        } catch (QueryException $error) {
            if (! self::timedOut($error, $driver)) {
                throw $error;
            }
            $cache?->put($slow, true, 600);

            return null;
        }
    }

    private static function timedOut(QueryException $error, string $driver): bool
    {
        $state = (string) ($error->errorInfo[0] ?? $error->getCode());
        $code = (int) ($error->errorInfo[1] ?? 0);

        return $driver === 'pgsql'
            ? $state === '57014'                    // query_canceled: statement_timeout
            : in_array($code, [3024, 1969], true);  // MySQL ER_QUERY_TIMEOUT, MariaDB ER_STATEMENT_TIMEOUT
    }

    private static function cache(): ?\Illuminate\Contracts\Cache\Repository
    {
        try {
            return function_exists('app') && app()->bound('cache') ? app('cache')->store() : null;
        } catch (Throwable) {
            return null; // no cache configured: just retry next time
        }
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
        if (in_array($operator, ['gt', 'lt', 'gte', 'lte', 'between'], true) && $column->valueType() === 'boolean') {
            throw InvalidTableQuery::at($at, "{$operator} is not supported for boolean columns.");
        }

        match ($operator) {
            'contains' => self::like($builder, $column, trim($value), false, 'and'),
            'startsWith' => self::like($builder, $column, trim($value), true, 'and'),
            'eq' => self::compare($builder, $column, '=', $value, $at),
            'gt' => self::compare($builder, $column, '>', $value, $at),
            'lt' => self::compare($builder, $column, '<', $value, $at),
            'gte' => self::compare($builder, $column, '>=', $value, $at),
            'lte' => self::compare($builder, $column, '<=', $value, $at),
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
                self::dayRange($builder, $name, $operator, self::day($value, $at), 'Y-m-d');

                return;
            case 'datetime':
                if (preg_match(self::DATE_ONLY, trim($value)) === 1) {
                    // A calendar day in a datetime column means the whole day.
                    self::dayRange($builder, $name, $operator, self::day($value, $at), 'Y-m-d H:i:s');
                } else {
                    $builder->where($name, $operator, self::instant($value, $at));
                }

                return;
            default:
                $builder->where($name, $operator, $value);
        }
    }

    /** The calendar day as written by the client (it sends local dates), e.g. 2026-10-23. */
    /**
     * Compare with a whole calendar day as a half-open range, never as
     * `DATE(column)`: the column stays bare, so its index is used (22× faster,
     * measured on 5M PostgreSQL rows), and no database session timezone is involved.
     * `=` is [day, next day), `>` is ≥ next day, `<` is < day, `>=` is ≥ day,
     * `<=` is < next day (also the bounds of `between`).
     */
    private static function dayRange(EloquentBuilder|QueryBuilder $builder, string $name, string $operator, string $day, string $format): void
    {
        $start = CarbonImmutable::createFromFormat('!Y-m-d', $day);
        $from = $start->format($format);
        $next = $start->addDay()->format($format);
        match ($operator) {
            '=' => $builder->where($name, '>=', $from)->where($name, '<', $next),
            '>' => $builder->where($name, '>=', $next),
            '<' => $builder->where($name, '<', $from),
            '>=' => $builder->where($name, '>=', $from),
            '<=' => $builder->where($name, '<', $next),
        };
    }

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

    /** Whether a search word can appear in the text of a column of this type. */
    private static function couldContain(Column $column, string $term): bool
    {
        return match ($column->valueType()) {
            null, 'text' => true,
            'number', 'decimal' => preg_match('/^[+-]?[\d.,]+$/', $term) === 1,
            'date', 'datetime' => preg_match('/^[\d.\/:T -]+$/', $term) === 1,
            'boolean' => in_array(strtolower($term), ['true', 'false', '1', '0'], true),
            default => true,
        };
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
        if (! in_array($column->valueType(), [null, 'text'], true)) {
            // Numbers, dates and booleans have no case or accents: compare their text as is.
            $expression = $driver === 'pgsql' ? "CAST({$expression} AS TEXT)" : $expression;
            $builder->whereRaw("{$expression} LIKE ? ESCAPE '!'", [$pattern($text)], $boolean);

            return;
        }
        $folded = self::foldSql($query->getConnection(), $driver, $expression);
        $builder->where(function ($group) use ($folded, $expression, $driver, $pattern, $text): void {
            $group->whereRaw("{$folded} LIKE ? ESCAPE '!'", [$pattern(Text::fold($text))])
                ->orWhereRaw($expression.($driver === 'pgsql' ? ' ILIKE' : ' LIKE')." ? ESCAPE '!'", [$pattern($text)]);
        }, null, null, $boolean);
    }
}
