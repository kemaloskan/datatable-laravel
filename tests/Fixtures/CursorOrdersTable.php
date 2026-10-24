<?php

declare(strict_types=1);

namespace Acunsoft\DataTable\Laravel\Tests\Fixtures;

use Acunsoft\DataTable\Laravel\Column;

/** OrdersTable with keyset paging, two rows a page. */
final class CursorOrdersTable extends \Livewire\Component
{
    use \Acunsoft\DataTable\Laravel\Livewire\WithDataTable;

    protected function columns(): array
    {
        return [
            Column::make('customer')->header('Customer')->sortable(),
            Column::make('total')->header('Total')->type('decimal')->sortable(),
        ];
    }

    protected function query(): \Illuminate\Database\Eloquent\Builder
    {
        return Order::query();
    }

    protected function pageSizeOptions(): array
    {
        return [2];
    }

    protected function defaultPageSize(): int
    {
        return 2;
    }

    protected function cursorPaging(): bool
    {
        return true;
    }
}
