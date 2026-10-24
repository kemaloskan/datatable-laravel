<?php

declare(strict_types=1);

namespace Acunsoft\DataTable\Laravel\Tests\Fixtures;

use Acunsoft\DataTable\Laravel\Column;

/** Orders with a filter panel: text, range, dates, yes/no and options. */
final class FilterOrdersTable extends \Livewire\Component
{
    use \Acunsoft\DataTable\Laravel\Livewire\WithDataTable;

    protected function columns(): array
    {
        return [
            Column::make('customer')->header('Customer')->sortable()->filter()->searchUsing('customer_search'),
            Column::make('city')->header('City')->options(['İstanbul' => 'İstanbul', 'Ankara' => 'Ankara', 'İzmir' => 'İzmir']),
            Column::make('total')->header('Total')->type('decimal')->sortable()->filter()->sum(),
            Column::make('active')->header('Active')->type('boolean')->filter(),
            Column::make('created')->column('created_at')->header('Created')->type('datetime')->filter(),
            Column::make('note')->header('Note')->filterable(), // filterable, not in the panel
        ];
    }

    protected function query(): \Illuminate\Database\Eloquent\Builder
    {
        return Order::query()->orderBy('id');
    }
}
