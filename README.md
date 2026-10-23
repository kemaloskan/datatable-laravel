# acunsoft/datatable-laravel

Server side for Acun DataTable, for Laravel and Livewire in one package:

- a **query layer** (allowlisted columns, operators, smart search, limits),
- a **JSON endpoint** for `@acunsoft/datatable-http`,
- a **Livewire table** (`WithDataTable`) with plain, framework-free Blade
  markup.

It is general-purpose: it needs Laravel 12 or 13 and PHP 8.2+, nothing else.
Livewire 4 is only needed for the Livewire table. No CSS framework or UI kit
is required.

```bash
composer require acunsoft/datatable-laravel
```

## JSON endpoint

```php
use Acunsoft\DataTable\Laravel\Column;
use Acunsoft\DataTable\Laravel\TableResponse;

Route::get('/orders/data', function () {
    Gate::authorize('viewAny', Order::class);      // authorize every request

    return TableResponse::for(Order::query())
        ->columns([
            Column::make('customer')->sortable()->filterable()->searchUsing('customer_search'),
            Column::make('total')->type('decimal')->sortable()->filterable(),
            Column::make('created')->column('created_at')->type('datetime')->sortable()->filterable(),
        ])
        ->maxPageSize(100);
});
```

The browser side: `createHttpDataSource({ url: '/orders/data' })` (it sends the
wire format below and reads `{ rows, totalRows }`).

## Livewire table

```php
use Acunsoft\DataTable\Laravel\Column;
use Acunsoft\DataTable\Laravel\Livewire\WithDataTable;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Component;

final class OrdersTable extends Component
{
    use WithDataTable;

    protected function columns(): array
    {
        return [
            Column::make('customer')->header('Müşteri')->sortable()->filterable()->searchUsing('customer_search'),
            Column::make('total')->header('Tutar')->type('decimal')->sortable()->filterable()
                ->format(fn ($value) => number_format((float) $value, 2, ',', '.').' ₺'),
            Column::make('created')->column('created_at')->header('Tarih')->type('datetime')->sortable(),
        ];
    }

    protected function query(): Builder
    {
        return Order::query()->whereBelongsTo(auth()->user()->team); // the rows this user may see
    }

    protected function selectable(): bool { return true; }       // optional
}
```

```blade
<livewire:orders-table />
```

What you get:

- **Search, sorting, filters and page size in the URL**, with the same names as
  the HTTP wire format: `?search=ayse&sort=total:desc&filters[0][field]=…&pageSize=50&page=2`.
  Links and the back button work. One table per page (names are not prefixed).
- **Sorting:** a header click cycles ascending → descending → none;
  **Shift**+click adds a column to a multi-column sort. The primary sorted
  header gets `aria-sort`.
- **Filters** from your own controls: `wire:click="setFilter('total', 'gt', '100')"`,
  `removeFilter($index)`, `clearFilters()`. Same operators and rules as above.
- **Selection** (`selectable()`): row checkboxes, a page checkbox (tri-state),
  "select all N matching rows", and `$this->selectedQuery()` for bulk actions:
  `$this->selectedQuery()->update(['status' => 'archived'])`. A new search or
  filter clears the selection.
- **Pagination** with Previous/Next, `Page 2 of 7 · 160 rows` and a rows-per-page
  select (`pageSizeOptions()`, default `[10, 25, 50, 100]`).
- **Cell text** from `Column::format(fn ($value, $row) => '…')` or a locale-aware
  default (numbers, decimals with their scale, dates, `Yes`/`No`). Everything is
  printed escaped with `{{ }}`: a formatter returns text, never HTML.
- **Texts** in English, Turkish, German, French and Spanish, following
  `app()->getLocale()` (other locales fall back to `app.fallback_locale`), with
  singular and plural forms and the locale's digit grouping
  (`acun-datatable::table.*`; publish with `--tag=acun-datatable-lang`).

### Column visibility

A "Columns" menu (native `<details>`, one checkbox per column) lets users hide
columns; the hidden IDs are kept in the URL (`hidden[]=note`).

```php
Column::make('customer')->hideable(false),   // always shown
Column::make('note')->hidden(),               // hidden until the user shows it
```

Hidden columns still sort, filter and search. Unknown or protected IDs in the
URL are ignored, and at least one column always stays visible.

### Row and bulk actions

```php
use Acunsoft\DataTable\Laravel\Action;

protected function rowActions(): array
{
    return [
        Action::make('edit')->label('Düzenle')
            ->handle(fn (Order $order) => $this->redirectRoute('orders.edit', $order)),
        Action::make('delete')->label('Sil')->confirm('Bu sipariş silinsin mi?')
            ->visible(fn (Order $order) => auth()->user()->can('delete', $order))
            ->handle(function (Order $order) {
                $this->authorize('delete', $order);
                $order->delete();
            }),
    ];
}

protected function bulkActions(): array
{
    return [
        Action::make('archive')->label('Arşivle')->confirm('Seçili siparişler arşivlensin mi?')
            ->handle(fn (Builder $selected) => $selected->update(['status' => 'archived'])),
    ];
}
```

- A **row action** adds an "Actions" column; its handler receives the row,
  looked up again inside `query()`, so a forged key cannot reach other rows.
  `visible()` is checked when rendering **and** before running.
- A **bulk action** appears in the selection bar; its handler receives the
  selected rows as a query inside `query()` (also for "all matching" selection).
  The selection is cleared afterwards.
- `confirm()` adds `wire:confirm`. Whatever the handler returns (a redirect, a
  download) goes back to Livewire. Authorize inside the handler; `visible()`
  only decides what is shown.

### Export and import

```php
protected function exportFormats(): array { return ['xlsx', 'csv']; }  // shows the buttons
protected function exportFilename(): string { return 'siparisler'; }
```

The buttons download the **visible columns of every row matching the current
search, filters and sorting** (or only the selected rows when there is a
selection), read in chunks on the server. Up to `exportLimit()` rows (100,000);
beyond that the user is asked to narrow the filters. See `TableExport` below.

```php
use Acunsoft\DataTable\Laravel\Livewire\WithTableImport;

final class OrdersTable extends Component
{
    use WithDataTable, WithTableImport;

    protected function importOptions(): array
    {
        return ['required' => ['customer', 'total'], 'headers' => ['total' => ['Amount', 'Tutar']]];
    }

    protected function importRow(array $values, int $line): void
    {
        $this->authorize('create', Order::class);
        Order::create($values); // or validate further first
    }
}
```

`WithTableImport` adds an upload form (`.xlsx` or `.csv`, 10 MB by default).
The file is read with limits, every value is converted to its column's type
(Turkish `1.234,50` and `23.10.2026` with the `tr` locale), and only valid rows
reach `importRow()`, **all in one database transaction**: an exception stores
nothing. Problems are listed with their line ("Satır 3 · Tutar · “abc”:
geçerli bir ondalık sayı değeri değil").

**Safety.** All state from the browser is treated as untrusted. URL state that
does not fit the columns (unknown or non-sortable fields, bad values, a page
size that is not an option, a page past the end) is dropped or corrected
instead of failing the page. Selected keys are only looked up inside `query()`,
so a forged key cannot reach rows outside it; "all matching" and the excluded
keys are `#[Locked]`. Helper methods are `protected`, so the browser can only
call the actions listed above (plus `runRowAction` and `runBulkAction`, which
only run actions you declared). Authorize in `query()` or `mount()` as usual.

**Markup and styling.** The view is plain Blade with the class names of
`@acunsoft/datatable-dom` (`adt-table`, `adt-sort`, `adt-pagination`, …), so
its optional stylesheet styles both. Include it from npm
(`@acunsoft/datatable-dom/styles.css`) or style the classes yourself. To
change the markup, publish the view:

```bash
php artisan vendor:publish --tag=acun-datatable-views
```

or give your component its own `render()` and `@include('acun-datatable::livewire.table')`
inside your layout. Override `caption()`, `pageSizeOptions()`, `maxSelected()`
(explicit keys, default 1000), `rowLabel($row)` (the checkbox name for screen
readers, default the first column's text) and `keyName()` (query-builder tables)
as needed.

## Columns are the allowlist

`Column::make($id)` declares what a client may use; nothing else reaches SQL.

| Method | Meaning |
|---|---|
| `column('created_at')` | database column (default: the ID); a plain identifier, set by you, never by the request |
| `type('decimal')` | `text`, `number`, `decimal`, `date` (a calendar day), `datetime` (an instant), `boolean` |
| `sortable()` / `filterable()` | allow sorting / filtering; filterable columns are also searchable |
| `searchable(false)` | keep a filterable column out of the global search |
| `searchUsing('customer_search')` | match search and `contains`/`startsWith` against a folded copy (see below) |
| `header('Müşteri')` | display text (used by the Livewire views) |
| `format(fn ($value, $row) => '…')` | cell text in the Livewire views (plain text, escaped) |
| `hideable(false)` / `hidden()` | Livewire column menu: never hidden / hidden at first |

Unknown, undeclared or not-allowed fields and malformed values throw
`InvalidTableQuery`; `TableResponse` answers HTTP 422 `{ message, errors }`.

## Query rules (same as the browser)

- **Operators:** `contains`, `startsWith` (`LIKE ? ESCAPE '!'`, ILIKE on
  PostgreSQL; `%` and `_` typed by users are literal), `eq`, `gt`, `lt`,
  `between` (inclusive), `in`, `empty` (NULL or `''`), `notEmpty`. Every value is
  a bound parameter.
- **Smart search:** the `search` text is split into words, a quoted phrase is one
  term; every term must match in some searchable column. At most 10 terms.
- **Types:** `number`/`decimal` values must be finite numbers; `boolean` reads
  `true/false/1/0`; `date` compares days with `whereDate`; in a `datetime` column
  a date-only value (`2026-10-23`, also how the browser sends a date picker's
  value) means **the whole day** (`whereDate`), a value with a time is an exact
  instant converted to `app.timezone`.
- **Sorting:** up to 5 rules (`sort=total:desc,customer:asc`); the primary key
  is added as a tie-breaker so pages are stable.
- **Limits:** page 1–10,000,000; page size up to `maxPageSize` (≤ 1000); at most
  20 filters, 1000 `in` values, 200 characters per value and search.
- **Rows:** only the key and the declared columns are returned, unless you pass
  `transform(fn ($model) => …)` (e.g. an API resource). Hidden attributes are
  never sent by accident.

## Turkish and accent-insensitive search

Search and `contains` / `startsWith` ignore case, accents and the Turkish
dotted/dotless i **on every database**, like the browser (`Text::fold`:
`İSTANBUL`, `Iğdır`, `Ayşe` → `istanbul`, `igdir`, `ayse`): `istanbul` finds
`İstanbul`, `IGDIR` finds `Iğdır`. The column is folded in SQL (a registered
PHP function on SQLite, `LOWER`/`REPLACE` on MySQL, PostgreSQL and SQL
Server); the text as typed is matched too, so the database collation still
helps with letters outside the Turkish, German, French and Spanish ones.

That fold runs for every row of every search. On large tables, store a folded
copy once and point the column at it; it is then a plain `LIKE`, and
`startsWith` can use an index:

```php
// migration: $table->string('customer_search')->index();
// model:
protected static function booted(): void
{
    static::saving(fn (Order $order) => $order->customer_search = Text::fold($order->customer));
}
// column:
Column::make('customer')->sortable()->filterable()->searchUsing('customer_search');
```

## Large tables (millions of rows)

The browser only ever holds one page, so table size is a database question.
These defaults keep requests fast; the numbers are medians measured on
**5,000,000 rows in PostgreSQL 17** (Apple M2, 25 rows per page, count + page):

| One request (count + page) | Before 0.3 | 0.3 defaults | + recommended indexes |
|---|---|---|---|
| First page, sorted by date | 52.8 ms | 1.4 ms | 1.1 ms |
| One month of dates | 373.3 ms | 1.7 ms | 1.7 ms |
| Status filter, sorted by amount | 27.9 ms | 1.3 ms | 1.4 ms |
| Search "ayse" | 3,480 ms | 79.4 ms | 22.4 ms |
| Search "istanbul" | 3,497 ms | 42.3 ms | 13.2 ms |
| Search "ayse istanbul" | 3,737 ms | 824.6 ms | 68.2 ms |
| Search with no match | > 2,413 ms | 2,170 ms | 0.4 ms |
| Page 2,000 | 136.8 ms | 82.1 ms | 79.6 ms |
| Page 100,000 | 4,199 ms | refused (result window) | refused |

"Recommended indexes": a folded copy of each searchable text column with a
GIN trigram index (step 2 below).

What the package does for you:

- **Bounded counts.** At most 10,000 matches are counted; beyond that the
  table says "10,000+" (Livewire) or sends `totalRowsAtLeast` instead of
  `totalRows` (JSON), and "next page" comes from fetching one extra row.
  `countLimit(null)` (TableResponse) or `countLimit(): ?int` (Livewire)
  restores exact counts.
- **Result window.** Pages that start past 100,000 rows are refused (422) or,
  in Livewire, clamped. Deep `OFFSET`s read and throw away millions of rows;
  sort the other way or filter instead. `maxResultWindow(null)` removes it.
- **Index-friendly dates.** A day is a range `[00:00, next day 00:00)` on the
  bare column, never `DATE(column)`, so the column's index is used.
- **Index-friendly sorting.** The primary-key tie-breaker follows the last
  sort direction, so an index on `(sorted_column, id)` covers the whole
  `ORDER BY`.
- **Search only where a word can be.** Letters are never looked for in
  amounts, dates or yes/no columns; numbers and dates are compared as text
  without folding.

What you do:

1. **Index what users sort and filter by**, together with the key:
   `$table->index(['ordered_at', 'id']); $table->index(['status', 'id']);`
   Only mark indexed columns `sortable()`.
2. **Searchable text: a folded copy plus a trigram index.** `%term%` cannot use
   a normal index, and folding in SQL costs time on every row. Store the folded
   text once and index it:

   ```php
   // migration (PostgreSQL)
   $table->string('customer_search');
   DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
   DB::statement('CREATE INDEX orders_customer_trgm ON orders USING gin (customer_search gin_trgm_ops)');

   // model
   use Acunsoft\DataTable\Laravel\Concerns\FoldsSearchColumns;

   protected function foldedSearchColumns(): array
   {
       return ['customer' => 'customer_search', 'city' => 'city_search'];
   }

   // column
   Column::make('customer')->sortable()->filterable()->searchUsing('customer_search'),
   ```

   On MySQL use a `FULLTEXT` index with the ngram parser, or a search engine.
   Add the column **before** loading data. On an existing large table do not
   fill it with one `UPDATE`: that rewrites every row in every index (it ran
   for hours on 5M rows). Build a new table with `CREATE TABLE … AS SELECT`
   and swap it in (37 s for 5M rows), or update in batches by primary key.
3. **Beyond a few million searchable rows, use a search engine**
   (Meilisearch, Typesense or OpenSearch through Laravel Scout) for the text
   search, and keep filters, sorting and paging in the database.

## Export on the server: `TableExport`

```php
Route::get('/orders/export', function (Request $request) {
    Gate::authorize('viewAny', Order::class);

    return TableExport::for(Order::query())
        ->columns($columns)
        ->query(TableQuery::fromRequest($request))  // the user's filters, search, sorting
        ->limit(50_000)
        ->xlsx('siparisler.xlsx');                  // or ->csv('siparisler.csv', ';')
});
```

- Rows are read with `lazy()` and written as they come (xlsx via a temporary
  file, deleted after sending), so memory stays flat.
- Typed columns give real numbers, exact decimals (with their scale), Excel
  dates and booleans; an untyped column with `format()` exports its text.
- CSV is UTF-8 with a BOM and guarded against formula injection; xlsx text is
  never a formula. Too many rows or a bad query answer HTTP 422.
- xlsx needs `ext-zip`.

## Import on the server: `SheetReader` and `RowImporter`

```php
$sheet  = (new SheetReader(maxRows: 5_000))->read($file->getRealPath(), $file->getClientOriginalExtension());
$result = (new RowImporter($columns, required: ['customer'], decimalSeparator: ','))->import($sheet);
foreach ($result->rows as ['line' => $line, 'values' => $values]) { /* store */ }
$result->errors; // [['line' => 3, 'column' => 'total', 'value' => 'abc', 'message' => '…'], …]
```

`SheetReader` reads CSV (delimiter detection, quotes, BOM; non-UTF-8 files as
Windows-1254, as Turkish Excel writes them) and xlsx (shared strings, dates,
1904 dates, cached formula results; never evaluates formulas), with limits on
file size, rows, columns, archive entries and inflated entry size (counted
while reading). Errors are `ImportException`s with a translated message.

## Using the parts directly

```php
$query = TableQuery::fromRequest($request, maxPageSize: 100);   // or fromArray([...])
$page  = QueryApplier::paginate(Order::query(), $query, $columns); // LengthAwarePaginator
```

## Tests

```bash
composer install && composer test
```

PHPUnit + Orchestra Testbench on SQLite, with the same folding and
search-splitting vectors as the TypeScript core, and Livewire component tests
for sorting, filters, tampered URL state, selection scope and escaping.

## License

[MIT](LICENSE). Provided “as is”, without warranty of any kind; the authors and Acunsoft accept no liability for any damage or data loss arising from its use (see LICENSE).
