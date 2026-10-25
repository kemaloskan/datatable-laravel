<?php

declare(strict_types=1);

namespace Acunsoft\DataTable\Laravel\Tests\Fixtures;

use Acunsoft\DataTable\Laravel\Column;

/** Orders with the filter panel in a drawer or a modal, or in a view of your own, and custom toolbar buttons. */
final class PanelOrdersTable extends \Livewire\Component
{
    use \Acunsoft\DataTable\Laravel\Livewire\WithDataTable;

    public string $mode = 'drawer';

    /** @var array<string, array<string, mixed>> */
    public array $buttons = [];

    public bool $ownModal = false;

    protected function filterPanel(): string
    {
        return $this->mode;
    }

    protected function toolbarButtons(): array
    {
        return $this->buttons;
    }

    protected function tableLayout(): array
    {
        return $this->ownModal ? ['topEnd' => ['fixtures::own-filter-modal', 'columns', 'tools']] : [];
    }

    protected function exportFormats(): array
    {
        return ['csv'];
    }

    protected function columns(): array
    {
        return [
            Column::make('customer')->header('Customer')->filter(),
            Column::make('total')->header('Total')->type('decimal')->filter(),
        ];
    }

    protected function query(): \Illuminate\Database\Eloquent\Builder
    {
        return Order::query()->orderBy('id');
    }
}
