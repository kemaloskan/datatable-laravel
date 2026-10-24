<?php

declare(strict_types=1);

namespace Acunsoft\DataTable\Laravel\Tests\Fixtures;

use Acunsoft\DataTable\Laravel\Column;

/** OrdersTable with cells edited in place. */
final class EditOrdersTable extends \Livewire\Component
{
    use \Acunsoft\DataTable\Laravel\Livewire\WithDataTable;

    protected function columns(): array
    {
        return [
            Column::make('customer')->header('Customer')->sortable()->editable(['required', 'max:40']),
            Column::make('total')->header('Total')->type('decimal')->editable('min:0'),
            Column::make('active')->header('Active')->type('boolean')->editable(),
            Column::make('city')->header('City')->options(['İstanbul' => 'İstanbul', 'Ankara' => 'Ankara'])->editable(),
            Column::make('note')->header('Note'),
        ];
    }

    protected function query(): \Illuminate\Database\Eloquent\Builder
    {
        return Order::query()->where('id', '<=', 3)->orderBy('id');
    }
}
