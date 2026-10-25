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

The browser side, in one call with `@acunsoft/datatable`:

```js
new AcunDataTable('#orders', { serverSide: true, ajax: { url: '/orders/data', method: 'POST' }, language: 'tr' });
```

or `createHttpDataSource({ url: '/orders/data' })` with the separate packages.
The query is read from the URL for `GET` and from the form or JSON body for
`POST` (register the route with `Route::match(['get', 'post'], …)`; POST needs
the CSRF token, which `AcunDataTable` sends from `<meta name="csrf-token">`).

**An existing DataTables front end works too.** A request in DataTables'
server-side protocol (it carries `draw`) is read as such — `start`/`length`,
`order[i][column|dir]` by column index, `columns[i][search][value]` as a
`contains` filter, `search[value]`, `length=-1` as the largest page allowed —
and answered with `{ draw, recordsTotal, recordsFiltered, data }`; an
invalid request gets DataTables' `error` (with a 200, as DataTables expects).
The same column allowlist and limits apply. So a page still on DataTables
can move its endpoint to `TableResponse` first and its front end later.

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

`->icon('edit')` (or `'view'`, `'delete'`, `'copy'`, `'check'`, `'download'`,
or SVG path data), `->icon('view', only: true)` for an icon button,
`->danger()` for destructive actions; `maxInlineActions()` keeps the first
actions in the row and moves the rest into a "⋮" menu. Shift+click on a row
checkbox selects a range (`selectRange()`).

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

### Layout, pagination and the tools menu

The table sits between two bars, like DataTables: page size and search top
left, the column menu and the **tools menu** top right, the row range
(`Toplam: 5.000 kaydın 1 ile 25 arasını gösteriyor`) bottom left and **numbered pages** bottom right
(`‹ 1 … 4 5 6 … 200 ›`, with the current page marked `aria-current="page"`).
Return only the slots you want to change from `tableLayout()`:

```php
protected function tableLayout(): array
{
    return [
        'topStart' => ['pageSize', 'search', 'orders.filters'], // your own Blade view
        'bottomEnd' => ['paging'],
        'bottomStart' => [],                                     // empty
    ];
}

protected function pagingOptions(): array
{
    return ['siblings' => 2, 'boundaries' => 1, 'firstLast' => true]; // « ‹ 1 … 3 4 5 6 7 … 200 › »
}
```

**Filter panel.** `->filter()` puts a column in the "Filters" menu with a
control for its type (text, min–max, from–to, yes/no); `->options([...])`
gives ticks instead. Active filters show as removable chips above the table;
your own buttons can call `filterBy('status', ['selected' => ['paid']])`,
`clearPanelFilters()` clears the panel's filters and keeps the others.

```php
Column::make('total')->header('Tutar')->type('decimal')->filter(),
Column::make('status')->header('Durum')->options(['paid' => 'Ödendi', 'new' => 'Yeni']),
```

The fields open under the button by default; `filterPanel()` opens them in a
drawer from the right (`drawer`, full width on phones) or a centred dialog
(`modal`): a native `<dialog>`, closed with Escape, the backdrop or "Close".
For a drawer or modal of your own, leave `filters` out of `tableLayout()` and
include only the fields in your own layout feature view:

```php
protected function filterPanel(): string { return 'drawer'; } // dropdown | drawer | modal

// Or your own component in place of the built-in button:
protected function tableLayout(): array { return ['topEnd' => ['orders.filter-modal', 'columns', 'tools']]; }
```

```blade
{{-- resources/views/orders/filter-modal.blade.php --}}
<div x-data>
    <button type="button" class="btn btn-light" x-on:click="$refs.modal.showModal()">Filtrele</button>
    <dialog x-ref="modal" wire:ignore.self>@include('acun-datatable::livewire.filter-panel')</dialog>
</div>
```

**Toolbar buttons.** `toolbarButtons()` sets the text, icon, icon-only look and
classes of the `columns`, `filters` and `tools` buttons; `icon` is a built-in
name, SVG path data or `false`, and your classes (e.g. Bootstrap's) win over
the package's `:where()` styles. Below 640px these buttons show only their icon.

```php
protected function toolbarButtons(): array
{
    return [
        'filters' => ['label' => 'Süz', 'class' => 'btn btn-outline-secondary'],
        'columns' => ['iconOnly' => true],
    ];
}
```

**Rows per page.** `pageSizeOptions()` sets the choices of the select and
`defaultPageSize()` the size at the start (default 25, or the first option);
`pageSize` stays in the URL only while it differs from the default.

```php
protected function pageSizeOptions(): array { return [10, 20, 50]; }
protected function defaultPageSize(): int { return 20; }
```

**Header and sorting.** `stickyHeader()` keeps the header at the top of the
window while the page scrolls (DataTables `fixedHeader`; under a fixed top bar
set `--adt-sticky-top: 64px`), `scrollY()` gives the table a fixed height
that scrolls inside with the header in place (`'60vh'`), and `multiSort()`
returning `false` makes Shift+click sort by one column only.

**Narrow screens and sizes.** Choose how the table behaves on phones and how
dense it is:

```php
protected function responsive(): string { return 'collapse'; } // cards (default), scroll, collapse, modal, auto
protected function tableSize(): string { return 'sm'; }         // sm, md (default), lg

protected function columns(): array
{
    return [
        Column::make('customer')->header('Müşteri'),              // first column: always shown
        Column::make('total')->header('Tutar')->priority(1),      // stays on phones too
        Column::make('email')->header('E-posta')->priority(3),    // hides below 960px already
        Column::make('city')->header('Şehir'),                    // default 2: hides below 640px
    ];
}
```

`cards` turns every row into a card, `scroll` keeps the table and scrolls it
sideways, `collapse` hides lower-priority columns behind a ⊕ button that opens
their values below the row (DataTables Responsive style), and `modal` opens
every value of the row, with its actions, in a native `<dialog>` (Escape, ✕ or
a click outside closes it). The button only appears while columns are hidden.

Every text can be changed for one table with `texts()`, by the keys of the
package's language file, for example a noun that fits the rows (Turkish
suffixes cannot be generated, so give the whole sentence):

```php
protected function texts(): array
{
    return [
        'page_info' => __('Toplam: :total personel üyesinin :from ile :to arasını gösteriyor'),
        'search_placeholder' => __('Personel ara…'),
    ];
}
```

For every table at once, publish the language files
(`php artisan vendor:publish --tag=acun-datatable-lang`) or put the keys in
`lang/vendor/acun-datatable/{locale}/table.php`.

Features are `pageSize`, `search`, `columns`, `tools`, `info` and `paging`;
any other name is included as a Blade view with the table's variables (`$rows`,
`$columns`, and `$this` for the component), so filter buttons or a "New" link
fit into the bars. The pager keeps the same number of buttons as you move, so
it never jumps in width; with a "10,000+" total it numbers the pages known to
exist and stops at the result window.

The tools menu holds everything that is not about the rows on screen: **export**
(`exportFormats()`), **print** (`printable()`: only the table with the rows on
screen, not the page around it) and **import** (`WithTableImport`). It only appears when at least one
of them is on.

### Editing in place

```php
Column::make('city')->header('Şehir')->editable(['required', 'max:60']),
Column::make('total')->header('Tutar')->type('decimal')->editable('min:0'),
Column::make('status')->header('Durum')->options(['new' => 'Yeni', 'paid' => 'Ödendi'])->editable(),
```

The cell gets an edit button (and a double click) with an editor for its type;
Enter or leaving the field calls `updateCell()`. On the server the row is
looked up inside `query()`, the model's policy must allow `update` (when it
has one), and the value is validated against the type, `options()` and your
rules (`nullable` unless `required`; a decimal comma is accepted); an error
keeps the editor open with the message. `saveCell()` then sets only that
column's attribute and saves the model; override it for query-builder rows or
extra logic, and `authorizeEdit()` for other checks.

### Resizing and moving columns

```php
protected function resizableColumns(): bool { return true; }  // drag a header's edge, ←/→ on it
protected function reorderableColumns(): bool { return true; } // drag headers; ↑/↓ in the column menu
```

Widths and order are component state (`$columnWidths`, `$columnOrder`), read
back through validation (known columns only, 48–2000 px). A resize sends the
widths once, when the drag ends; the first one fixes every column at its
current width so nothing else jumps. "Reset columns" in the column menu
restores both.

### Fixed columns

```php
protected function fixedColumns(): array
{
    return ['start' => 1, 'end' => 1]; // first data column (with the checkbox) and the actions
}
```

The cells stay in place while the table scrolls sideways (`responsive()`
`scroll`, or a wide table). Offsets are measured in the browser with Alpine
and follow every update; on a phone pinned cells never take more than 60% of
the width.

### Lazy loading

```php
use Livewire\Attributes\Lazy;

#[Lazy]
final class OrdersTable extends Component { use WithDataTable; /* … */ }
```

A lazy table (`#[Lazy]`, or `<livewire:orders-table lazy />`) renders the
page first and loads itself once it scrolls into view. Meanwhile the trait's
`placeholder()` shows the header and shimmering placeholder rows, so the page
does not jump; define your own `placeholder()` to replace it. During later
updates the current rows stay, dimmed, with `aria-busy`.

### Child rows

```php
protected function detailView(): ?string
{
    return 'orders.lines'; // rendered with $row and $key
}
```

```blade
{{-- resources/views/orders/lines.blade.php --}}
<ul>
    @foreach ($row->lines as $line)
        <li>{{ $line->product }} × {{ $line->quantity }}</li>
    @endforeach
</ul>
```

Every row gets a ⊕ button that opens the view below it (DataTables child
rows; in the dialog with `responsive()` `modal`, after the row's values). The
view is rendered only for open rows, once the button is pressed, so it can
load relations or run queries for that row alone; "Loading…" shows
meanwhile. Only rows of the current page open (`toggleDetail($key)`).

### Footer totals

```php
Column::make('total')->type('decimal')->sum(),          // or ->aggregate('avg' | 'min' | 'max' | 'count')
```

A `<tfoot>` row shows the total over every row matching the search and
filters, computed in one SQL query (`SUM`, `AVG`, …) next to the count. It is
left out while the count is only "10,000+", since a total of an unknown
number of rows would cost what the count saved. The JSON endpoint sends the
same values as `totals`, which the browser packages show. A column's
`format()` also formats its total, with `$row` `null`; a formatter that needs
the row falls back to the default text.

### Export and import

```php
protected function exportFormats(): array { return ['xlsx', 'csv']; }  // shows them in the tools menu
protected function exportFilename(): string { return 'siparisler'; }
protected function printable(): bool { return true; }                  // adds "Print"
```

The export items download the **visible columns of every row matching the current
search, filters and sorting** (or only the selected rows when there is a
selection), read in chunks on the server. Up to `exportLimit()` rows (100,000);
beyond that the user is asked to narrow the filters. See `TableExport` below.

#### Large exports and bulk actions in the queue

```php
protected function queuedExports(): bool { return true; }          // past exportLimit(): prepared in the queue
protected function queuedExportLimit(): int { return 1_000_000; }  // the most a queued export writes
protected function exportDisk(): string { return 'local'; }        // where the file waits

protected function bulkActions(): array
{
    return [Action::make('archive')->label('Arşivle')->queued()->handle(fn ($selected) => $selected->update(['archived' => true]))];
}
```

An export past `exportLimit()` (or a `queued()` bulk action) goes to a job,
`RunTableTask`, and the table says so while you keep working. The job
rebuilds the table from its state (search, filters, sorting, selection) **as
the user who started it**, so `query()` and policies see the same user (and
the worker forgets that user afterwards). When the file is ready the table
shows "Download": a signed link valid for an hour, only for that user (a
guest's task belongs to the guest's session), and the file is deleted once
sent. A table runs one task at a time, and one visitor may start
`queuedTaskLimit()` tasks (10) per 10 minutes. Tasks belong to the request's
auth guard; `taskGuard()` names another. A failure shows a generic message
(the details go to your log). Run a queue worker, and schedule
`php artisan acun-datatable:prune` daily for files nobody downloaded.

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

`WithTableImport` adds "Import…" to the tools menu: it opens the file picker,
and once a file is chosen a panel above the table shows its name with
"Import" and "Close" (`.xlsx` or `.csv`, 10 MB by default).
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
`@acunsoft/datatable-dom` (`adt-table`, `adt-sort`, `adt-paging`, …), so
its optional stylesheet styles both, including the responsive layout, dark mode
and the `--adt-*` custom properties for your colours, radii and density. The
menus close with Escape or a click outside (Alpine, which Livewire ships). Include it from npm
(`@acunsoft/datatable-dom/styles.css`) or style the classes yourself. To
change the markup, publish the views (`table`, `bar`, `feature`, `import`):

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
| `view('orders.status-badge')` | Livewire cell rendered by your Blade view (`$row`, `$value`, `$text`, `$column`), e.g. a badge or an avatar; exports keep the text |
| `priority(1)` | narrow screens with `responsive()` `collapse` / `modal`: 1 always, 2 below 640px, 3 below 960px; with `auto`, the order columns hide in when they do not fit |

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
What these defaults did in one measurement — medians on **5,000,000 rows in
PostgreSQL 17** (Apple M2, 25 rows per page, count + page). Other tables,
filters, indexes and databases give other numbers; measure yours
(`bench/` in the repository):

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
| Page 100,000 | 4,199 ms | refused (result window) | refused; cursor paging: 55.5 ms |

"Recommended indexes": a folded copy of each searchable text column with a
GIN trigram index (step 2 below).

What the package does for you:

- **Exact counts within a time limit.** Up to 10,000 matches the count is
  exact and stops after the 10,001st match. That is usually a few
  milliseconds, but not always: when few rows match and no index serves the
  filter, finding them reads the whole table (716 ms on 5,000,000 rows, see
  [bench/](../../bench/results/postgres-5m.md)), so index the filters your
  users need. Past that, an exact `COUNT(*)` gets 250 ms
  (PostgreSQL `statement_timeout`, MySQL `max_execution_time`, MariaDB
  `max_statement_time`, set only for that statement and restored; inside a
  transaction it runs in a savepoint). On 5,000,000 rows that is enough for the
  whole table (65 ms) and for most filters, so the table says "Toplam:
  5.000.000 kaydın 1 ile 25 arasını gösteriyor". Only a count that would take
  longer falls back to "10,000+" (Livewire) or `totalRowsAtLeast` instead of
  `totalRows` (JSON), with "next page" from fetching one extra row; that query
  is remembered for ten minutes in your cache, so paging through it does not
  pay the 250 ms again. Tune it with `countTimeout()` (`null`: never count past
  the limit) and `countLimit()` (`null`: always count everything, no time
  limit). SQLite and SQL Server have no per-statement time limit and keep the
  "10,000+" bound.
- **Result window.** Pages that start past 100,000 rows are refused (422) or,
  in Livewire, clamped. Deep `OFFSET`s read and throw away millions of rows;
  sort the other way, filter, or use cursor paging. `maxResultWindow(null)`
  removes it.
- **Cursor (keyset) paging.** `cursorPaging()` in Livewire, or
  `paging=cursor` on the JSON endpoint (the browser packages send it with
  `paging: 'cursor'` / AcunDataTable `pagination: { cursor: true }`): a page
  continues after the last row of the page before, by its sort values and
  key. On 5,000,000 rows page 100,001 takes 55.5 ms with the count, the same
  as page 2, where the `OFFSET` takes 3,898 ms; no result window applies.
  The condition is written so every database can use the `(column, id)`
  index: the first sort column is bounded before the exact comparison
  (Laravel's own `cursorPaginate()` writes it as a plain `OR`, which
  PostgreSQL scans for 3.9 s). Pages are reached one after another: the pager
  offers those seen so far and the next one. Mark sort columns that may hold
  `NULL` with `Column::nullable()`, so later pages also reach the `NULL` rows
  (in the database's own order). A query builder (not Eloquent) gets the key
  (`keyName()`, default `id`) as the last sort rule. A cursor made for another
  sort is refused (422) on the JSON endpoint; in Livewire the table starts
  again from page 1.
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
   For filters that are used **together**, add one index with the equality
   column first and the range column after it:
   `$table->index(['status', 'ordered_at', 'id']);` On 5M rows, "paid in
   the last month" then counts from the index alone: 1,373 ms → 19 ms per
   request. Check slow combinations with `EXPLAIN ANALYZE`.
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
   search, and keep filters, sorting and paging in the database:

   ```php
   // Livewire
   protected function searchEngine(string $search): ?iterable
   {
       return Order::search($search)->take(10_000)->keys();
   }

   // JSON endpoint
   TableResponse::for(Order::query())->columns([...])
       ->searchEngine(fn (string $search) => Order::search($search)->take(10_000)->keys());
   ```

   The engine is asked once per request and only for a search; its keys then
   limit the rows (`whereKey`) for the page, the count, footer totals, bulk
   actions and exports alike, inside `query()` and the filters. The package
   does not depend on Scout: any function that returns keys works.

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

## Import with the import dialog: `TableImport`

The import dialog of `@acunsoft/datatable-import` (file and drag and drop,
column mapping with a preview, progress, results, error report) talks to your
back end by the import protocol `acun-import/1`. `TableImport` is that back
end for one kind of record — or write your own; the dialog does not mind.

```php
use Acunsoft\DataTable\Laravel\Column;
use Acunsoft\DataTable\Laravel\Import\{ImportContext, TableImport};

final class OrdersImport extends TableImport
{
    protected function columns(): array
    {
        return [
            Column::make('email')->header('E-posta'),
            Column::make('customer')->header('Müşteri'),
            Column::make('total')->header('Tutar')->type('decimal'),
        ];
    }

    public function authorize(Request $request): bool { return $request->user()?->can('import', Order::class) ?? false; }
    protected function required(): array { return ['email']; }
    protected function importKey(): ?string { return 'email'; }            // insert, update or upsert by e-mail
    protected function tenant(Request $request): ?string { return (string) $request->user()->team_id; }
    protected function query(ImportContext $context): Relation { return Team::findOrFail($context->tenant)->orders(); }
}

// routes/web.php
Route::middleware(['web', 'auth'])->group(fn () => Route::acunImport('orders/import', OrdersImport::class));
```

On a Livewire table, `importEndpoint()` adds "Import…" to the tools menu, which
opens the dialog (load `acun-datatable-import.min.js` and its `styles.css` on
the page); the table is refreshed when rows changed:

```php
protected function importEndpoint(): ?string { return url('orders/import'); }
```

**What it does for you** — everything the protocol asks of a back end:

- **Limits, always checked here:** `maxKilobytes()` (10 MB), `maxRows()`
  (100,000), `accept()` (`xlsx`, `csv`), plus the file's own signature (an
  xlsx is a zip archive, a CSV has no NUL bytes) and `SheetReader`'s archive
  limits. The dialog only shows them as hints.
- **Ownership:** every request is authorized; an import belongs to its user
  (or a guest's session) on `guard()` and to its `tenant()`; anyone else gets
  404. Uploads with the same `Idempotency-Key` give the same import.
- **The mapping is checked again on start**: one entry per file column, known
  columns at most once, every `required()` column, an offered mode.
- **Saving** in chunks of `chunkSize()` (1,000), each in a transaction, typed
  by `RowImporter` (Turkish `1.234,50` and `23.10.2026` with
  `readOptions()`). The default `save()` inserts, updates or upserts by
  `importKey()` within `query()` (a query, a relation such as
  `$team->orders()` — which also sets the foreign key — or a query builder);
  `attributes()` adds what the file does not hold. Override `save()` to store
  rows your way and report with `$context->inserted()`, `updated()`,
  `skipped()` and `reject($line, $message, $column, $value)`.
- **At once or in the background — your choice:** `shouldQueue(int $rows)`
  (default `false`) dispatches `RunTableImport` on your queue
  (`queueConnection()`, `queueName()`; `database`, `redis`, `sqs`, `sync` —
  none is required), as the user who started it. The dialog follows the
  progress after every chunk and can cancel between chunks.
- **State** lives in the app's cache store (any driver; `array` only works in
  one request), files on `disk()` (`local`, private) for `keepSeconds()` (a
  day): the upload is deleted when the import ends, the CSV error report
  (formula-guarded) when it expires. Schedule `acun-datatable:prune` daily to
  remove what was left behind.

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

## Upgrading

From 0.4: see [UPGRADING.md](https://github.com/kemaloskan/acun-datatable/blob/main/UPGRADING.md#acunsoftdatatable-laravel-04--05) (re-publish customized views, the user's sort now comes first).

## Tests

```bash
composer install && composer test
```

PHPUnit + Orchestra Testbench on SQLite, with the same folding and
search-splitting vectors as the TypeScript core, and Livewire component tests
for sorting, filters, tampered URL state, selection scope and escaping.

## License

[MIT](LICENSE). Provided “as is”, without warranty of any kind; the authors and Acunsoft accept no liability for any damage or data loss arising from its use (see LICENSE).
