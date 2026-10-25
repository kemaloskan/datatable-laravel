<?php

declare(strict_types=1);

namespace Acunsoft\DataTable\Laravel\Tests\Fixtures;

use Acunsoft\DataTable\Laravel\Column;

/** Orders with "Import…" in the tools menu: the import dialog against the OrdersImport endpoint. */
final class ImportOrdersTable extends \Livewire\Component
{
    use \Acunsoft\DataTable\Laravel\Livewire\WithDataTable;

    protected function columns(): array
    {
        return [
            Column::make('customer')->header('Customer')->sortable()->searchable()->searchUsing('customer_search'),
            Column::make('city')->header('City'),
            Column::make('total')->header('Total')->type('decimal')->sortable(),
        ];
    }

    protected function query(): \Illuminate\Database\Eloquent\Builder
    {
        return Order::query()->orderBy('id');
    }

    protected function importEndpoint(): ?string
    {
        return url('import/orders');
    }
}
