<?php

declare(strict_types=1);

namespace Acunsoft\DataTable\Laravel\Tests;

use Acunsoft\DataTable\Laravel\Column;
use Acunsoft\DataTable\Laravel\DataTableServiceProvider;
use Acunsoft\DataTable\Laravel\Support\FilterPanel;
use Acunsoft\DataTable\Laravel\Tests\Fixtures\FilterOrdersTable;
use Acunsoft\DataTable\Laravel\Tests\Fixtures\Order;
use InvalidArgumentException;
use Livewire\Livewire;
use Livewire\LivewireServiceProvider;

final class LivewireFiltersTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [LivewireServiceProvider::class, DataTableServiceProvider::class];
    }

    /** @return list<string> */
    private function customers(mixed $table): array
    {
        return array_map(fn (Order $order) => $order->customer, $table->instance()->rows->items());
    }

    public function test_the_panel_has_a_control_per_filter_column(): void
    {
        $html = Livewire::test(FilterOrdersTable::class)->html();
        self::assertStringContainsString('class="adt-columns adt-menu adt-filters" wire:ignore.self', $html);
        preg_match_all('/<fieldset class="adt-filter adt-filter--(\w+)" data-field="(\w+)"/', $html, $sets);
        self::assertSame(['customer', 'city', 'total', 'active', 'created'], $sets[2]); // note is filterable but not in the panel
        self::assertSame(['text', 'options', 'range', 'boolean', 'dates'], $sets[1]);
        self::assertStringNotContainsString('adt-filter-chips', $html);
    }

    public function test_filter_by_sets_one_column_and_keeps_the_others(): void
    {
        $table = Livewire::test(FilterOrdersTable::class)
            ->call('setFilter', 'note', 'notEmpty')                         // a filter outside the panel
            ->call('filterBy', 'total', ['min' => '90', 'max' => ''])
            ->assertDispatched('adt-filters-synced');
        self::assertSame(['Ayşe Yılmaz', 'Iğdır Ticaret'], $this->customers($table));
        $table->call('filterBy', 'city', ['selected' => ['İzmir', 'Ankara', 'Mars']]); // unknown options are dropped
        self::assertSame([], $this->customers($table));
        $table->call('filterBy', 'city', ['selected' => ['İstanbul']]);
        self::assertSame(['Ayşe Yılmaz'], $this->customers($table));
        self::assertSame([
            ['field' => 'note', 'op' => 'notEmpty', 'value' => null],
            ['field' => 'total', 'op' => 'gte', 'value' => '90'],
            ['field' => 'city', 'op' => 'in', 'value' => ['İstanbul']],
        ], $table->get('filters'));

        $html = $table->html();
        self::assertMatchesRegularExpression('/<span class="adt-chip">Total: ≥ 90<button/u', $html);
        self::assertMatchesRegularExpression('/<span class="adt-chip">City: İstanbul<button/u', $html);
        self::assertMatchesRegularExpression('/<span class="adt-badge" aria-hidden="true"\s*>2<\/span>/', $html);

        $table->call('clearPanelFilters');
        self::assertSame([['field' => 'note', 'op' => 'notEmpty', 'value' => null]], $table->get('filters')); // outside the panel: kept
    }

    public function test_dates_yes_no_and_text(): void
    {
        $table = Livewire::test(FilterOrdersTable::class)->call('filterBy', 'created', ['min' => '2026-10-23', 'max' => '2026-10-23']);
        self::assertSame(['Ayşe Yılmaz', 'Can Öztürk'], $this->customers($table));
        $table->call('filterBy', 'created')->call('filterBy', 'active', ['flag' => 'false']);
        self::assertSame(['Ali Kaya', 'Iğdır Ticaret'], $this->customers($table));
        $table->call('filterBy', 'active', ['flag' => ''])->call('filterBy', 'customer', ['text' => 'ayse']);
        self::assertSame(['Ayşe Yılmaz'], $this->customers($table));
        $table->call('filterBy', 'total', ['min' => 'abc']); // invalid: not added
        self::assertCount(1, $table->get('filters'));
        $table->call('filterBy', 'note', ['text' => 'x']); // not a panel column: ignored
        self::assertCount(1, $table->get('filters'));
    }

    public function test_values_are_read_back_from_rules_and_described(): void
    {
        $total = Column::make('total')->header('Tutar')->type('decimal')->filter();
        app()->setLocale('tr');
        self::assertSame('1000', FilterPanel::read($total, [['field' => 'total', 'operator' => 'gte', 'value' => '1000']])['min']);
        self::assertSame('Tutar: ≥ 1.000', FilterPanel::describe($total, FilterPanel::read($total, [['field' => 'total', 'op' => 'gte', 'value' => '1000']])));
        self::assertTrue(Column::make('x')->options(['a' => 'A'])->isFilterable());
        $this->expectException(InvalidArgumentException::class);
        Column::make('x')->options([]);
    }
}
