<?php

declare(strict_types=1);

namespace Acunsoft\DataTable\Laravel\Tests\Fixtures;

use Acunsoft\DataTable\Laravel\Action;
use Acunsoft\DataTable\Laravel\Column;
use Acunsoft\DataTable\Laravel\Livewire\WithDataTable;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Component;

final class OrdersTable extends Component
{
    use WithDataTable;

    /** Lets a test narrow the scope, as an app would for the current user. */
    public ?string $onlyCity = null;

    protected function columns(): array
    {
        return [
            Column::make('customer')->header('Customer')->sortable()->filterable()->searchUsing('customer_search'),
            Column::make('city')->header('City')->sortable()->filterable(),
            Column::make('total')->header('Total')->type('decimal')->sortable()->filterable(),
            Column::make('active')->header('Active')->type('boolean')->filterable()->searchable(false),
            Column::make('created')->column('created_at')->header('Created')->type('datetime')->sortable()->filterable()->searchable(false),
            Column::make('note')->header('Note')->format(fn ($value) => $value === null ? '—' : (string) $value),
        ];
    }

    protected function query(): Builder
    {
        return Order::query()->when($this->onlyCity, fn ($query, $city) => $query->where('city', $city));
    }

    protected function selectable(): bool
    {
        return true;
    }

    protected function caption(): ?string
    {
        return 'Orders';
    }

    protected function pageSizeOptions(): array
    {
        return [2, 25];
    }

    /** Lets a test switch the bulk action off, as an app would per permission. */
    public bool $canArchive = true;

    protected function rowActions(): array
    {
        return [
            Action::make('activate')->label('Activate')
                ->visible(fn (Order $order) => ! $order->active)
                ->handle(fn (Order $order) => $order->update(['active' => true, 'note' => 'activated'])),
            Action::make('delete')->label('Delete')->confirm('Delete this order?')
                ->handle(fn (Order $order) => $order->delete()),
        ];
    }

    protected function bulkActions(): array
    {
        return [
            Action::make('archive')->label('Archive')->confirm('Archive the selected orders?')
                ->visible(fn () => $this->canArchive)
                ->handle(fn (Builder $selected) => $selected->update(['note' => 'archived'])),
        ];
    }
}
