<?php

declare(strict_types=1);

namespace Acunsoft\DataTable\Laravel\Tests\Fixtures;

use Acunsoft\DataTable\Laravel\Column;
use Acunsoft\DataTable\Laravel\Livewire\WithDataTable;
use Acunsoft\DataTable\Laravel\Livewire\WithTableImport;
use Acunsoft\DataTable\Laravel\Support\Text;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Component;

final class OrdersImportTable extends Component
{
    use WithDataTable;
    use WithTableImport;

    /** Lets a test make storing fail halfway. */
    public ?int $failOnLine = null;

    protected function columns(): array
    {
        return [
            Column::make('customer')->header('Müşteri')->sortable()->filterable()->searchUsing('customer_search'),
            Column::make('city')->header('Şehir')->filterable(),
            Column::make('total')->header('Tutar')->type('decimal')->sortable(),
            Column::make('active')->header('Aktif')->type('boolean'),
        ];
    }

    protected function query(): Builder
    {
        return Order::query();
    }

    protected function selectable(): bool
    {
        return true;
    }

    protected function exportFormats(): array
    {
        return ['xlsx', 'csv'];
    }

    protected function importOptions(): array
    {
        return ['required' => ['customer', 'total'], 'decimalSeparator' => ',', 'headers' => ['total' => 'Amount']];
    }

    protected function importRow(array $values, int $line): void
    {
        if ($line === $this->failOnLine) {
            throw new \RuntimeException("cannot store line {$line}");
        }
        Order::query()->create([
            'customer' => $values['customer'],
            'customer_search' => Text::fold($values['customer']),
            'city' => $values['city'] ?? null,
            'total' => $values['total'],
            'active' => $values['active'] ?? false,
        ]);
    }
}
