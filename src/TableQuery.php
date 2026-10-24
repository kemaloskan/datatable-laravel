<?php

declare(strict_types=1);

namespace Acunsoft\DataTable\Laravel;

use Illuminate\Http\Request;

/**
 * A validated table query, read from the wire format of @acunsoft/datatable-http
 * (`page`, `pageSize`, `sort=field:dir,…`, `filters[i][field|op|value]`,
 * `search`) or from Livewire properties.
 *
 * Only the shape is checked here; whether a field may be sorted or filtered is
 * checked against the declared columns by QueryApplier.
 */
final class TableQuery
{
    public const OPERATORS = ['contains', 'startsWith', 'eq', 'gt', 'lt', 'gte', 'lte', 'between', 'in', 'empty', 'notEmpty'];
    public const MAX_PAGE = 10_000_000;
    public const MAX_SORT_RULES = 5;
    public const MAX_FILTERS = 20;
    public const MAX_IN_VALUES = 1000;
    public const MAX_TEXT = 200;
    public const MAX_SEARCH_TERMS = 10;

    /**
     * @param list<array{field: string, direction: 'asc'|'desc'}> $sorting
     * @param list<array{field: string, operator: string, value: mixed}> $filters
     */
    private function __construct(
        public readonly int $page,
        public readonly int $pageSize,
        public readonly array $sorting,
        public readonly array $filters,
        public readonly string $search,
        /** Keyset paging (`paging=cursor`): pages continue after the last row, with no OFFSET. */
        public readonly bool $cursorPaging = false,
        /** The `nextCursor` of the page before; null for the first page. */
        public readonly ?string $cursor = null,
        /** Keys a search engine found for `$search` (see withSearchKeys()); null: search the columns. */
        public readonly ?array $searchKeys = null,
        public readonly string $searchKeyColumn = 'id',
    ) {
    }

    /**
     * The same query with the search answered by a search engine (Laravel
     * Scout, Meilisearch, …): only rows whose key is in `$keys` match, and the
     * text is not looked for in the columns. Filters, sorting and paging stay
     * in the database. `$column` is the key column of a query-builder table
     * (Eloquent uses the model key).
     *
     * @param iterable<int|string> $keys
     */
    public function withSearchKeys(iterable $keys, string $column = 'id'): self
    {
        $list = [];
        foreach ($keys as $key) {
            if (is_int($key) || is_string($key)) {
                $list[] = $key;
            }
        }

        return new self($this->page, $this->pageSize, $this->sorting, $this->filters, $this->search, $this->cursorPaging, $this->cursor, array_values(array_unique($list)), $column);
    }

    public static function fromRequest(Request $request, int $maxPageSize = 100, int $defaultPageSize = 25): self
    {
        // GET: the URL; POST (datatable-http with method 'POST'): the form or JSON body.
        $input = $request->isMethod('GET') || $request->isMethod('HEAD')
            ? $request->query()
            : ($request->isJson() ? $request->json()->all() : $request->request->all());

        return self::fromArray($input, $maxPageSize, $defaultPageSize);
    }

    /**
     * @param array<string, mixed> $input
     *
     * @throws InvalidTableQuery
     */
    public static function fromArray(array $input, int $maxPageSize = 100, int $defaultPageSize = 25): self
    {
        if ($maxPageSize < 1 || $maxPageSize > 1000 || $defaultPageSize < 1 || $defaultPageSize > $maxPageSize) {
            throw new \InvalidArgumentException('Page sizes must satisfy 1 <= default <= max <= 1000.');
        }
        $page = self::integer($input['page'] ?? 1, 'page', 1, self::MAX_PAGE);
        $pageSize = self::integer($input['pageSize'] ?? $defaultPageSize, 'pageSize', 1, $maxPageSize);

        return new self(
            $page,
            $pageSize,
            self::sorting($input['sort'] ?? ''),
            self::filters($input['filters'] ?? []),
            self::search($input['search'] ?? ''),
            ($input['paging'] ?? null) === 'cursor' || isset($input['cursor']),
            self::cursor($input['cursor'] ?? null),
        );
    }

    private static function cursor(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_string($value) || strlen($value) > 4096 || preg_match('/^[A-Za-z0-9_\-=]+$/', $value) !== 1) {
            throw InvalidTableQuery::at('cursor', 'cursor must be the nextCursor of a previous page.');
        }

        return $value;
    }

    /** @return list<string> */
    public function searchTerms(): array
    {
        return array_slice(Support\Text::searchTerms($this->search), 0, self::MAX_SEARCH_TERMS);
    }

    private static function integer(mixed $value, string $name, int $min, int $max): int
    {
        if (is_string($value) && preg_match('/^\d{1,9}$/', $value) === 1) {
            $value = (int) $value;
        }
        if (! is_int($value) || $value < $min || $value > $max) {
            throw InvalidTableQuery::at($name, "{$name} must be an integer from {$min} to {$max}.");
        }

        return $value;
    }

    /** @return list<array{field: string, direction: 'asc'|'desc'}> */
    private static function sorting(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }
        if (! is_string($value) || strlen($value) > 500) {
            throw InvalidTableQuery::at('sort', 'sort must be text such as "total:desc,customer:asc".');
        }
        $rules = [];
        foreach (explode(',', $value) as $part) {
            if (preg_match('/^([A-Za-z][A-Za-z0-9_.-]{0,63}):(asc|desc)$/', $part, $match) !== 1) {
                throw InvalidTableQuery::at('sort', "sort rule [{$part}] must be field:asc or field:desc.");
            }
            if (isset($rules[$match[1]])) {
                throw InvalidTableQuery::at('sort', "sort field [{$match[1]}] appears twice.");
            }
            $rules[$match[1]] = ['field' => $match[1], 'direction' => $match[2]];
        }
        if (count($rules) > self::MAX_SORT_RULES) {
            throw InvalidTableQuery::at('sort', 'sort may hold at most '.self::MAX_SORT_RULES.' rules.');
        }

        return array_values($rules);
    }

    /** @return list<array{field: string, operator: string, value: mixed}> */
    private static function filters(mixed $value): array
    {
        if (! is_array($value)) {
            throw InvalidTableQuery::at('filters', 'filters must be a list.');
        }
        if (count($value) > self::MAX_FILTERS) {
            throw InvalidTableQuery::at('filters', 'filters may hold at most '.self::MAX_FILTERS.' rules.');
        }
        $filters = [];
        foreach (array_values($value) as $index => $rule) {
            $at = "filters.{$index}";
            if (! is_array($rule) || ! is_string($rule['field'] ?? null) || preg_match('/^[A-Za-z][A-Za-z0-9_.-]{0,63}$/', $rule['field']) !== 1) {
                throw InvalidTableQuery::at("{$at}.field", 'field must be a column ID.');
            }
            $operator = $rule['op'] ?? $rule['operator'] ?? null;
            if (! in_array($operator, self::OPERATORS, true)) {
                throw InvalidTableQuery::at("{$at}.op", 'op must be one of '.implode(', ', self::OPERATORS).'.');
            }
            $raw = $rule['value'] ?? null;
            $checked = match ($operator) {
                'empty', 'notEmpty' => $raw === null || $raw === '' ? null : throw InvalidTableQuery::at("{$at}.value", "{$operator} takes no value."),
                'between' => is_array($raw) && count($raw) === 2
                    ? array_map(fn ($item) => self::scalar($item, "{$at}.value"), array_values($raw))
                    : throw InvalidTableQuery::at("{$at}.value", 'between needs [min, max].'),
                'in' => is_array($raw) && $raw !== [] && count($raw) <= self::MAX_IN_VALUES
                    ? array_map(fn ($item) => self::scalar($item, "{$at}.value"), array_values($raw))
                    : throw InvalidTableQuery::at("{$at}.value", 'in needs a list of 1 to '.self::MAX_IN_VALUES.' values.'),
                default => self::scalar($raw, "{$at}.value"),
            };
            // A blank contains/startsWith constrains nothing, as in the browser.
            if (in_array($operator, ['contains', 'startsWith'], true) && trim($checked) === '') {
                continue;
            }
            $filters[] = ['field' => $rule['field'], 'operator' => $operator, 'value' => $checked];
        }

        return $filters;
    }

    private static function scalar(mixed $value, string $at): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value) || is_float($value)) {
            $value = (string) $value;
        }
        if (! is_string($value) || mb_strlen($value) > self::MAX_TEXT) {
            throw InvalidTableQuery::at($at, 'value must be text of at most '.self::MAX_TEXT.' characters.');
        }

        return $value;
    }

    private static function search(mixed $value): string
    {
        if (! is_string($value)) {
            throw InvalidTableQuery::at('search', 'search must be text.');
        }

        return trim(mb_substr($value, 0, self::MAX_TEXT));
    }
}
