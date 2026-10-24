<?php

declare(strict_types=1);

namespace Acunsoft\DataTable\Laravel\Tests\Fixtures;

use Acunsoft\DataTable\Laravel\Action;
use Acunsoft\DataTable\Laravel\Column;

/** OrdersTable whose large exports and one bulk action run in the queue. */
final class QueuedOrdersTable extends \Livewire\Component
{
    use \Acunsoft\DataTable\Laravel\Livewire\WithDataTable;

    protected function columns(): array
    {
        return [
            Column::make('customer')->header('Customer')->sortable()->filterable(),
            Column::make('total')->header('Total')->type('decimal')->sortable(),
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

    protected function exportFormats(): array
    {
        return ['csv', 'xlsx'];
    }

    protected function exportLimit(): int
    {
        return 2;
    }

    protected function queuedExports(): bool
    {
        return true;
    }

    protected function bulkActions(): array
    {
        return [Action::make('activate')->label('Activate')->queued()->handle(fn ($selected) => $selected->update(['active' => true]))];
    }
}
