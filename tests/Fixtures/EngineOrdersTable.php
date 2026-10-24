<?php

declare(strict_types=1);

namespace Acunsoft\DataTable\Laravel\Tests\Fixtures;

use Acunsoft\DataTable\Laravel\Column;

/** OrdersTable whose search a (fake) search engine answers. */
final class EngineOrdersTable extends \Livewire\Component
{
    use \Acunsoft\DataTable\Laravel\Livewire\WithDataTable;

    /** How often the engine was asked in this request. */
    public static int $asked = 0;

    protected function columns(): array
    {
        return [
            Column::make('customer')->header('Customer')->sortable()->filterable(),
            Column::make('total')->header('Total')->type('decimal')->sortable()->filter(),
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

    protected function searchEngine(string $search): ?iterable
    {
        self::$asked++;

        return $search === 'gold' ? [2, 4] : [];
    }
}
