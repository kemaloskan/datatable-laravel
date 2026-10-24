<?php

declare(strict_types=1);

namespace Acunsoft\DataTable\Laravel\Tests\Fixtures;

/** OrdersTable with a custom layout, first/last buttons and printing. */
final class LayoutOrdersTable extends \Livewire\Component
{
    use \Acunsoft\DataTable\Laravel\Livewire\WithDataTable;

    protected function columns(): array
    {
        return [
            \Acunsoft\DataTable\Laravel\Column::make('customer')->header('Customer')->sortable()->filterable()->searchUsing('customer_search'),
            \Acunsoft\DataTable\Laravel\Column::make('total')->header('Total')->type('decimal')->sortable()->view('fixtures::pill'),
        ];
    }

    protected function query(): \Illuminate\Database\Eloquent\Builder
    {
        return Order::query();
    }

    protected function pageSizeOptions(): array
    {
        return [1, 25];
    }

    protected function tableLayout(): array
    {
        return ['topStart' => ['search'], 'topEnd' => ['fixtures::badge', 'tools'], 'bottomStart' => [], 'bottomEnd' => ['paging', 'info']];
    }

    protected function pagingOptions(): array
    {
        return ['siblings' => 0, 'boundaries' => 1, 'firstLast' => true];
    }

    protected function texts(): array
    {
        return ['page_info' => 'Total: :total order, :from to :to|Total: :total orders, :from to :to', 'search_placeholder' => 'Find an order…'];
    }

    protected function printable(): bool
    {
        return true;
    }
}
