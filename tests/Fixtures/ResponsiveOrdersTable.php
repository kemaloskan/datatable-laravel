<?php

declare(strict_types=1);

namespace Acunsoft\DataTable\Laravel\Tests\Fixtures;

use Acunsoft\DataTable\Laravel\Action;
use Acunsoft\DataTable\Laravel\Column;

/** OrdersTable with a responsive mode and a size chosen by the test. */
final class ResponsiveOrdersTable extends \Livewire\Component
{
    use \Acunsoft\DataTable\Laravel\Livewire\WithDataTable;

    public string $mode = 'cards';

    public string $density = 'md';

    protected function columns(): array
    {
        return [
            Column::make('customer')->header('Customer')->sortable()->filterable()->searchUsing('customer_search'),
            Column::make('city')->header('City'),
            Column::make('total')->header('Total')->type('decimal')->priority(1),
            Column::make('note')->header('Note')->priority(3),
        ];
    }

    protected function query(): \Illuminate\Database\Eloquent\Builder
    {
        return Order::query()->orderBy('id');
    }

    protected function rowActions(): array
    {
        return [Action::make('remove')->label('Remove')->handle(fn (Order $order) => $order->delete())];
    }

    protected function responsive(): string
    {
        return $this->mode;
    }

    protected function tableSize(): string
    {
        return $this->density;
    }
}
