<?php

declare(strict_types=1);

namespace Acunsoft\DataTable\Laravel\Livewire;

use Acunsoft\DataTable\Laravel\Action;
use Acunsoft\DataTable\Laravel\Column;
use Acunsoft\DataTable\Laravel\InvalidTableQuery;
use Acunsoft\DataTable\Laravel\QueryApplier;
use Acunsoft\DataTable\Laravel\TableExport;
use Acunsoft\DataTable\Laravel\TablePage;
use Acunsoft\DataTable\Laravel\TableQuery;
use Acunsoft\DataTable\Laravel\Support\PageItems;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\WithPagination;

/**
 * A server-side data table for a Livewire component.
 *
 * Declare `columns()` and `query()`; the trait keeps search, sorting, filters
 * and page size in the URL (the same names as the HTTP wire format: `search`,
 * `sort=field:dir,…`, `filters[i][field|op|value]`, `pageSize`, `page`), applies
 * them with QueryApplier and renders `acun-datatable::livewire.table` unless
 * the component has its own `render()`.
 *
 * Every value from the browser is untrusted: URL state that does not match the
 * declared columns is dropped instead of failing the page, only declared
 * columns reach the SQL, and selected keys are only ever looked up inside
 * `query()`, so selection cannot reach rows outside the developer's scope.
 *
 * One table per page: the URL names are not prefixed.
 */
trait WithDataTable
{
    use WithPagination;

    #[Url(except: '')]
    public string $search = '';

    /** `field:dir,…`, e.g. `total:desc,customer:asc`. */
    #[Url(except: '')]
    public string $sort = '';

    /** @var array<int, mixed> list of `['field' => …, 'op' => …, 'value' => …]` */
    #[Url(except: [])]
    public array $filters = [];

    #[Url(except: 25)]
    public int $pageSize = 25;

    /** @var array<int, mixed> IDs of hidden columns (presentation only: they still sort, filter and search) */
    #[Url(as: 'hidden', except: [])]
    public array $hiddenColumns = [];

    /** @var array<int, mixed> keys of explicitly selected rows */
    public array $selected = [];

    /** Every row matching the current search and filters, except `$excluded`. */
    #[Locked]
    public bool $allMatching = false;

    /** @var list<string> */
    #[Locked]
    public array $excluded = [];

    /** @return iterable<Column> */
    abstract protected function columns(): iterable;

    /** The rows this user may see; everything the table does stays inside it. */
    abstract protected function query(): EloquentBuilder|QueryBuilder;

    /** @return list<int> */
    protected function pageSizeOptions(): array
    {
        return [10, 25, 50, 100];
    }

    /**
     * Up to this many matches (default 10,000) the count is always exact and
     * cheap. Beyond it an exact count gets `countTimeout()`; only when that is
     * not enough the view shows "10,000+". `null` counts everything, always.
     */
    protected function countLimit(): ?int
    {
        return 10_000;
    }

    /**
     * Time an exact count past `countLimit()` may take, in milliseconds
     * (default 250; PostgreSQL, MySQL and MariaDB, other databases show
     * "10,000+"). `null` never counts past the limit.
     */
    protected function countTimeout(): ?int
    {
        return 250;
    }

    /** Pages start at most this many rows deep (default 100,000; `null`: no limit). */
    protected function maxResultWindow(): ?int
    {
        return 100_000;
    }

    /**
     * Where the controls go: a bar above and one below the table, each with a
     * start and an end side. Return only the slots you change; the others keep
     * their defaults: `topStart` page size and search, `topEnd` column menu and
     * tools, `bottomStart` info, `bottomEnd` pages. A slot is a list of features:
     * `pageSize`, `search`, `columns`, `tools` (export, print and import menu),
     * `info`, `paging`, or the name of your own Blade view, which is included
     * with the table's variables (`$rows`, `$columns`, …). `[]` empties a slot.
     *
     * @return array<'topStart'|'topEnd'|'bottomStart'|'bottomEnd', list<string>>
     */
    protected function tableLayout(): array
    {
        return [];
    }

    /**
     * The numbered pager: pages around the current one (`siblings`), pages
     * always shown at both ends (`boundaries`) and first/last buttons.
     *
     * @return array{siblings: int, boundaries: int, firstLast: bool}
     */
    protected function pagingOptions(): array
    {
        return ['siblings' => 1, 'boundaries' => 1, 'firstLast' => false];
    }

    /** Show "Print" in the tools menu (prints the visible page without the controls). */
    protected function printable(): bool
    {
        return false;
    }

    /**
     * Texts for this table only, by the keys of the package's language file
     * (`lang/en/table.php`), e.g. a noun that fits the rows:
     * `['page_info' => ':total personel üyesinden :from ile :to arası gösteriliyor']`.
     * Use `__()` inside for several languages; `a|b` gives singular and plural.
     *
     * @return array<string, string>
     */
    protected function texts(): array
    {
        return [];
    }

    /**
     * A text of the views: the table's own from `texts()`, else the package's.
     * Pass a number as the second argument for singular/plural texts.
     *
     * @param int|array<string, mixed> $count the number for plural texts, or the replacements
     * @param array<string, mixed> $replace
     */
    protected function tableText(string $key, int|array $count = [], array $replace = []): string
    {
        $line = $this->texts()[$key] ?? 'acun-datatable::table.'.$key;
        if (is_array($count)) {
            return (string) __($line, $count);
        }

        return trans_choice($line, $count, $replace);
    }

    /** Explicitly selected keys are limited; "all matching" selection has no limit. */
    protected function maxSelected(): int
    {
        return 1000;
    }

    /** Off by default: return true to show the selection column. */
    protected function selectable(): bool
    {
        return false;
    }

    protected function caption(): ?string
    {
        return null;
    }

    /** @return list<string> export buttons to show: `'xlsx'` and/or `'csv'`. None by default. */
    protected function exportFormats(): array
    {
        return [];
    }

    /** File name without extension. */
    protected function exportFilename(): string
    {
        return 'export';
    }

    protected function exportLimit(): int
    {
        return 100_000;
    }

    /** CSV delimiter: `;` opens directly in Turkish Excel. */
    protected function exportDelimiter(): string
    {
        return app()->getLocale() === 'tr' ? ';' : ',';
    }

    /** @return list<Action> buttons in each row; handlers receive the row */
    protected function rowActions(): array
    {
        return [];
    }

    /** @return list<Action> buttons for the selected rows; handlers receive `selectedQuery()` */
    protected function bulkActions(): array
    {
        return [];
    }

    /** The key column for a query builder (Eloquent uses the model key). */
    protected function keyName(): string
    {
        return 'id';
    }

    /** The row's name for screen readers ("Select Ayşe"): the first column's text by default. */
    protected function rowLabel(mixed $row): string
    {
        foreach ($this->dataTableColumns() as $column) {
            $text = \Acunsoft\DataTable\Laravel\Support\Format::cell($column, $row);
            if (trim($text) !== '') {
                return $text;
            }
        }

        return $this->rowKey($row);
    }

    protected function rowKey(mixed $row): string
    {
        $key = is_object($row) && method_exists($row, 'getKey') ? $row->getKey() : data_get($row, $this->keyName());

        return (string) $key;
    }

    public function render(): View
    {
        return view('acun-datatable::livewire.table');
    }

    #[Computed]
    public function rows(): LengthAwarePaginator
    {
        $query = $this->dataTableQuery();
        $page = fn () => QueryApplier::paginate($this->query(), $this->dataTableQuery(), $this->dataTableColumns(), $this->countLimit(), $this->maxResultWindow(), $this->countTimeout());
        $rows = $page();
        if ($rows->isEmpty() && $query->page > 1 && $rows->total() > 0) {
            // The page is past the end (rows were deleted, or the URL was edited): show the last one.
            $this->setPage($rows->totalIsExact() ? $rows->lastPage() : 1);
            $rows = $page();
        }

        return $rows;
    }

    /** @return list<Column> */
    protected function dataTableColumns(): array
    {
        $columns = [];
        foreach ($this->columns() as $column) {
            if (! $column instanceof Column) {
                throw new \UnexpectedValueException(static::class.'::columns() must return Column instances.');
            }
            $columns[] = $column;
        }

        return $columns;
    }

    /** @return list<array{field: string, direction: 'asc'|'desc'}> */
    protected function sorting(): array
    {
        return $this->dataTableQuery()->sorting;
    }

    /** Apply `Column::hidden()` unless the URL already says which columns are hidden. */
    public function mountWithDataTable(): void
    {
        if ($this->hiddenColumns === []) {
            $this->hiddenColumns = array_values(array_map(
                fn (Column $column) => $column->id(),
                array_filter($this->dataTableColumns(), fn (Column $column) => $column->isHiddenByDefault()),
            ));
        }
    }

    // Column visibility ----------------------------------------------------

    /** Show or hide a column; one column always stays visible. */
    public function toggleColumn(string $id): void
    {
        $column = $this->dataTableColumn($id);
        if ($column === null || ! $column->isHideable()) {
            return;
        }
        $hidden = $this->hiddenColumnIds();
        $next = in_array($id, $hidden, true) ? array_values(array_diff($hidden, [$id])) : [...$hidden, $id];
        if (count($next) < count($this->dataTableColumns())) {
            $this->hiddenColumns = $next;
        }
    }

    /** @return list<Column> the columns to show, in declaration order */
    protected function dataTableVisibleColumns(): array
    {
        $hidden = $this->hiddenColumnIds();

        return array_values(array_filter($this->dataTableColumns(), fn (Column $column) => ! in_array($column->id(), $hidden, true)));
    }

    /** @return list<string> valid hidden IDs: known, hideable, never all of them */
    protected function hiddenColumnIds(): array
    {
        $hidden = [];
        foreach ($this->dataTableColumns() as $column) {
            if ($column->isHideable() && in_array($column->id(), $this->hiddenColumns, true)) {
                $hidden[] = $column->id();
            }
        }

        return count($hidden) >= count($this->dataTableColumns()) ? [] : $hidden;
    }

    // Sorting --------------------------------------------------------------

    /**
     * A click cycles ascending → descending → none. With `$additive` (Shift+click
     * in the view) the column is added to or changed in a multi-column sort.
     */
    public function sortBy(string $field, bool $additive = false): void
    {
        $column = $this->dataTableColumn($field);
        if ($column === null || ! $column->isSortable()) {
            return;
        }
        $current = [];
        foreach ($this->sorting() as $rule) {
            $current[$rule['field']] = $rule['direction'];
        }
        $existing = $current[$field] ?? null;
        $direction = $existing === null ? 'asc' : ($existing === 'asc' ? 'desc' : null);
        if (! $additive) {
            $current = $direction === null ? [] : [$field => $direction];
        } elseif ($direction === null) {
            unset($current[$field]);
        } else {
            $current[$field] = $direction; // keeps its position when it was already sorted
        }
        $current = array_slice($current, 0, TableQuery::MAX_SORT_RULES, true);
        $this->sort = implode(',', array_map(fn ($f, $d) => "{$f}:{$d}", array_keys($current), $current));
        $this->resetPage();
    }

    // Filters --------------------------------------------------------------

    /** Add or replace the rule for this field and operator. */
    public function setFilter(string $field, string $op, mixed $value = null): void
    {
        $rules = array_values(array_filter(
            $this->dataTableQuery()->filters,
            fn (array $rule) => ! ($rule['field'] === $field && $rule['operator'] === $op),
        ));
        $rules = array_map(fn (array $rule) => ['field' => $rule['field'], 'op' => $rule['operator'], 'value' => $rule['value']], $rules);
        $candidate = ['field' => $field, 'op' => $op, 'value' => $value];
        if ($this->validFilter($candidate)) {
            $rules[] = $candidate;
        }
        $this->filters = array_slice($rules, 0, TableQuery::MAX_FILTERS);
        $this->tableChanged();
    }

    public function removeFilter(int $index): void
    {
        $filters = array_values($this->filters);
        unset($filters[$index]);
        $this->filters = array_values($filters);
        $this->tableChanged();
    }

    public function clearFilters(): void
    {
        $this->filters = [];
        $this->search = '';
        $this->tableChanged();
    }

    public function updatedSearch(): void
    {
        $this->tableChanged();
    }

    public function updatedFilters(): void
    {
        $this->tableChanged();
    }

    public function updatedPageSize(): void
    {
        $this->resetPage();
    }

    // Selection ------------------------------------------------------------

    protected function isSelected(string $key): bool
    {
        return $this->allMatching
            ? ! in_array($key, $this->excluded, true)
            : in_array($key, $this->selectedKeys(), true);
    }

    public function toggleRow(string|int $key): void
    {
        $key = (string) $key;
        if ($this->allMatching) {
            $this->excluded = in_array($key, $this->excluded, true)
                ? array_values(array_diff($this->excluded, [$key]))
                : [...$this->excluded, $key];

            return;
        }
        $selected = $this->selectedKeys();
        if (in_array($key, $selected, true)) {
            $this->selected = array_values(array_diff($selected, [$key]));
        } elseif (count($selected) < $this->maxSelected()) {
            $this->selected = [...$selected, $key];
        }
    }

    /** Select the rows of the current page, or clear them when all are selected. */
    public function togglePage(): void
    {
        $keys = $this->pageKeys();
        $all = $keys !== [] && array_reduce($keys, fn (bool $carry, string $key) => $carry && $this->isSelected($key), true);
        if ($this->allMatching) {
            $this->excluded = $all
                ? array_values(array_unique([...$this->excluded, ...$keys]))
                : array_values(array_diff($this->excluded, $keys));

            return;
        }
        $selected = $this->selectedKeys();
        $this->selected = $all
            ? array_values(array_diff($selected, $keys))
            : array_slice(array_values(array_unique([...$selected, ...$keys])), 0, $this->maxSelected());
    }

    /** 'none', 'some' or 'all' for the rows on the current page. */
    protected function pageSelection(): string
    {
        $keys = $this->pageKeys();
        $count = count(array_filter($keys, fn (string $key) => $this->isSelected($key)));

        return match (true) {
            $count === 0 => 'none',
            $count === count($keys) => 'all',
            default => 'some',
        };
    }

    public function selectAllMatching(): void
    {
        $this->allMatching = true;
        $this->excluded = [];
        $this->selected = [];
    }

    public function clearSelection(): void
    {
        $this->allMatching = false;
        $this->excluded = [];
        $this->selected = [];
    }

    /** The selection size; for "all matching" a bounded count (see selectionSize()). */
    protected function selectedCount(): int
    {
        return $this->selectionSize()[0];
    }

    /** @return array{0: int, 1: bool} the selection size and whether it is exact */
    protected function selectionSize(): array
    {
        return $this->allMatching
            ? QueryApplier::count($this->selectedQuery(), $this->countLimit(), $this->countTimeout())
            : [count($this->selectedKeys()), true];
    }

    /**
     * The selected rows as a query inside `query()`, for bulk actions:
     * `$this->selectedQuery()->update([...])` or `->get()`.
     */
    protected function selectedQuery(): EloquentBuilder|QueryBuilder
    {
        if ($this->allMatching) {
            $query = $this->dataTableQuery();
            $builder = QueryApplier::apply(
                $this->query(),
                TableQuery::fromArray(['filters' => $this->wireFilters($query), 'search' => $query->search]),
                $this->dataTableColumns(),
            );

            return $this->excluded === [] ? $builder : $this->whereKeys($builder, $this->excluded, not: true);
        }

        return $this->whereKeys($this->query(), $this->selectedKeys());
    }

    // Export ---------------------------------------------------------------

    /**
     * Download the visible columns of every row matching the current search,
     * filters and sorting (or of the selected rows, when there is a
     * selection), as xlsx or CSV. Only formats from `exportFormats()` work.
     */
    public function export(string $format): mixed
    {
        if (! in_array($format, $this->exportFormats(), true) || ! in_array($format, ['xlsx', 'csv'], true)) {
            return null;
        }
        $query = $this->dataTableQuery();
        $selected = $this->selectable() && $this->selectedCount() > 0;
        $export = TableExport::for($selected ? $this->selectedQuery() : $this->query())
            ->columns($this->dataTableVisibleColumns())
            ->limit($this->exportLimit())
            ->query($selected
                ? TableQuery::fromArray(['sort' => implode(',', array_map(fn (array $rule) => "{$rule['field']}:{$rule['direction']}", $query->sorting))])
                : $query, $this->dataTableColumns());
        $name = $this->exportFilename();
        try {
            return $format === 'xlsx'
                ? $export->xlsx("{$name}.xlsx", $name)
                : $export->csv("{$name}.csv", $this->exportDelimiter());
        } catch (InvalidTableQuery $error) {
            $this->addError('export', implode(' ', $error->errors));

            return null;
        }
    }

    // Actions --------------------------------------------------------------

    /**
     * Run a row action. The row is looked up inside `query()`, and the action
     * must exist and be visible for that row; otherwise nothing happens.
     */
    public function runRowAction(string $action, string|int $key): mixed
    {
        $found = $this->findAction($this->dataTableRowActions(), $action);
        $key = (string) $key;
        if ($found === null || strlen($key) > 64) {
            return null;
        }
        $row = $this->whereKeys($this->query(), [$key])->first();
        if ($row === null || ! $found->isVisible($row)) {
            return null;
        }
        $result = $found->run($row);
        unset($this->rows); // show the changed data

        return $result;
    }

    /** Run a bulk action on the selection, then clear the selection. */
    public function runBulkAction(string $action): mixed
    {
        $found = $this->findAction($this->dataTableBulkActions(), $action);
        if ($found === null || ! $found->isVisible() || $this->selectedCount() === 0) {
            return null;
        }
        $result = $found->run($this->selectedQuery());
        $this->clearSelection();
        unset($this->rows);

        return $result;
    }

    /** @return list<Action> */
    protected function dataTableRowActions(): array
    {
        return $this->checkedActions($this->rowActions(), 'rowActions');
    }

    /** @return list<Action> */
    protected function dataTableBulkActions(): array
    {
        return array_values(array_filter(
            $this->checkedActions($this->bulkActions(), 'bulkActions'),
            fn (Action $action) => $action->isVisible(),
        ));
    }

    // Internals ------------------------------------------------------------

    /** @return list<Action> */
    private function checkedActions(array $actions, string $method): array
    {
        $seen = [];
        foreach ($actions as $action) {
            if (! $action instanceof Action) {
                throw new \UnexpectedValueException(static::class."::{$method}() must return Action instances.");
            }
            if (isset($seen[$action->id()])) {
                throw new \UnexpectedValueException(static::class."::{$method}() has the action [{$action->id()}] twice.");
            }
            $seen[$action->id()] = true;
        }

        return array_values($actions);
    }

    /** @param list<Action> $actions */
    private function findAction(array $actions, string $id): ?Action
    {
        foreach ($actions as $action) {
            if ($action->id() === $id) {
                return $action;
            }
        }

        return null;
    }

    /** The current state as a validated query; parts that do not fit the columns are dropped. */
    protected function dataTableQuery(): TableQuery
    {
        $sizes = $this->pageSizeOptions();
        $max = max($sizes);
        $pageSize = in_array($this->pageSize, $sizes, true) ? $this->pageSize : (in_array(25, $sizes, true) ? 25 : $sizes[0]);

        $sort = [];
        foreach ($this->sort === '' ? [] : explode(',', $this->sort) as $part) {
            [$field, $direction] = array_pad(explode(':', $part, 2), 2, '');
            $column = $this->dataTableColumn($field);
            if ($column !== null && $column->isSortable() && in_array($direction, ['asc', 'desc'], true) && ! isset($sort[$field])) {
                $sort[$field] = "{$field}:{$direction}";
            }
        }

        $filters = [];
        foreach (array_values($this->filters) as $rule) {
            if (count($filters) < TableQuery::MAX_FILTERS && $this->validFilter($rule)) {
                $filters[] = $rule;
            }
        }

        $page = $this->getPage();
        $page = is_int($page) || (is_string($page) && ctype_digit($page)) ? (int) $page : 1;

        return TableQuery::fromArray([
            'page' => min(max($page, 1), TableQuery::MAX_PAGE, $this->maxResultWindow() === null ? TableQuery::MAX_PAGE : max(1, intdiv($this->maxResultWindow() - 1, $pageSize) + 1)),
            'pageSize' => $pageSize,
            'sort' => implode(',', array_slice($sort, 0, TableQuery::MAX_SORT_RULES)),
            'filters' => $filters,
            'search' => mb_substr($this->search, 0, TableQuery::MAX_TEXT),
        ], $max, $pageSize);
    }

    /**
     * What the info text and the pager show for a page of rows.
     *
     * @return array{from: int, to: int, items: list<int|null>, page: int, last: ?int, previous: bool, next: bool}
     */
    protected function pagerState(TablePage $rows): array
    {
        $options = $this->pagingOptions() + ['siblings' => 1, 'boundaries' => 1];
        $size = $rows->perPage();
        $page = $rows->currentPage();
        $window = $this->maxResultWindow() === null ? TableQuery::MAX_PAGE : max(1, intdiv($this->maxResultWindow() - 1, $size) + 1);
        // A lower-bound total still proves that its pages exist; past the window none can be opened.
        $pages = max($page, min((int) ceil(max($rows->total(), 1) / $size), $window, TableQuery::MAX_PAGE));
        $from = $rows->isEmpty() ? 0 : ($page - 1) * $size + 1;

        return [
            'from' => $from,
            'to' => $rows->isEmpty() ? 0 : $from + $rows->count() - 1,
            'items' => PageItems::make($page, $pages, (int) $options['siblings'], (int) $options['boundaries']),
            'page' => $page,
            'last' => $rows->totalIsExact() ? $pages : null,
            'previous' => $page > 1,
            'next' => $page < $window && $rows->hasMorePages(),
        ];
    }

    /** @return array<string, list<string>> */
    protected function dataTableLayout(): array
    {
        $custom = $this->tableLayout();
        $layout = [];
        foreach ([
            'topStart' => ['pageSize', 'search'],
            'topEnd' => ['columns', 'tools'],
            'bottomStart' => ['info'],
            'bottomEnd' => ['paging'],
        ] as $slot => $default) {
            $features = array_key_exists($slot, $custom) ? $custom[$slot] : $default;
            $layout[$slot] = array_values(array_filter(is_array($features) ? $features : [$features], 'is_string'));
        }

        return $layout;
    }

    protected function tableChanged(): void
    {
        $this->resetPage();
        $this->clearSelection();
    }

    private function validFilter(mixed $rule): bool
    {
        if (! is_array($rule) || ! is_string($rule['field'] ?? null)) {
            return false;
        }
        $column = $this->dataTableColumn($rule['field']);
        if ($column === null || ! $column->isFilterable()) {
            return false;
        }
        try {
            $query = TableQuery::fromArray(['filters' => [$rule]]);
            if ($query->filters === []) {
                return false; // a blank contains/startsWith constrains nothing
            }
            QueryApplier::apply($this->query(), $query, [$column]); // checks the value for the column type; nothing runs
        } catch (InvalidTableQuery) {
            return false;
        }

        return true;
    }

    private function dataTableColumn(string $id): ?Column
    {
        foreach ($this->dataTableColumns() as $column) {
            if ($column->id() === $id) {
                return $column;
            }
        }

        return null;
    }

    /** @return list<string> */
    private function selectedKeys(): array
    {
        $keys = [];
        foreach ($this->selected as $key) {
            if ((is_string($key) || is_int($key)) && strlen((string) $key) <= 64 && ! in_array((string) $key, $keys, true)) {
                $keys[] = (string) $key;
            }
        }

        return array_slice($keys, 0, $this->maxSelected());
    }

    /** @return list<string> */
    private function pageKeys(): array
    {
        return array_map(fn ($row) => $this->rowKey($row), $this->rows->items());
    }

    /** @return list<array{field: string, op: string, value: mixed}> */
    private function wireFilters(TableQuery $query): array
    {
        return array_map(fn (array $rule) => ['field' => $rule['field'], 'op' => $rule['operator'], 'value' => $rule['value']], $query->filters);
    }

    /** @param list<string> $keys */
    private function whereKeys(EloquentBuilder|QueryBuilder $builder, array $keys, bool $not = false): EloquentBuilder|QueryBuilder
    {
        if ($builder instanceof EloquentBuilder) {
            return $not ? $builder->whereKeyNot($keys) : $builder->whereKey($keys);
        }

        return $not ? $builder->whereNotIn($this->keyName(), $keys) : $builder->whereIn($this->keyName(), $keys);
    }
}
