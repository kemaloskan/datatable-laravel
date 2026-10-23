<?php

declare(strict_types=1);

namespace Acunsoft\DataTable\Laravel\Tests\Fixtures;

/** OrdersTable with tiny limits, to exercise bounded counts and the result window. */
final class SmallWindowOrdersTable extends \Livewire\Component
{
    use \Acunsoft\DataTable\Laravel\Livewire\WithDataTable;

    protected function columns(): array
    {
        return [
            \Acunsoft\DataTable\Laravel\Column::make('customer')->header('Customer')->sortable()->filterable()->searchUsing('customer_search'),
            \Acunsoft\DataTable\Laravel\Column::make('total')->header('Total')->type('decimal')->sortable(),
        ];
    }

    protected function query(): \Illuminate\Database\Eloquent\Builder
    {
        return Order::query();
    }

    protected function selectable(): bool
    {
        return true;
    }

    protected function pageSizeOptions(): array
    {
        return [2, 25];
    }

    protected function countLimit(): ?int
    {
        return 2;
    }

    protected function maxResultWindow(): ?int
    {
        return 4;
    }
}
