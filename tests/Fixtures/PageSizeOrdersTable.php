<?php

declare(strict_types=1);

namespace Acunsoft\DataTable\Laravel\Tests\Fixtures;

/** Orders with custom page sizes and a default that is not 25. */
final class PageSizeOrdersTable extends \Livewire\Component
{
    use \Acunsoft\DataTable\Laravel\Livewire\WithDataTable;

    protected function columns(): array
    {
        return [\Acunsoft\DataTable\Laravel\Column::make('customer')->header('Customer')->sortable()];
    }

    protected function query(): \Illuminate\Database\Eloquent\Builder
    {
        return Order::query();
    }

    protected function pageSizeOptions(): array
    {
        return [2, 3, 25];
    }

    protected function defaultPageSize(): int
    {
        return 3;
    }
}
