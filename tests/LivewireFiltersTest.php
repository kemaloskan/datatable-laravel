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

    public function test_footer_totals_follow_the_filters(): void
    {
        $table = Livewire::test(FilterOrdersTable::class);
        $html = preg_replace('/<!--.*?-->/s', '', $table->html());
        self::assertMatchesRegularExpression('/<th scope="row" data-label="Customer"\s*>Total<\/th>/', $html);
        self::assertMatchesRegularExpression('/data-label="Total · Sum"\s+class="adt-num"\s+title="Sum"\s*>5,350.40<\/td>/', $html);
        $table->call('filterBy', 'total', ['max' => '100']);
        self::assertSame(['total' => 229.9], array_map('floatval', $table->instance()->totals()));
    }

    public function test_footer_totals_use_the_column_formatter(): void
    {
        $column = Column::make('total')->type('decimal')->sum()->format(fn ($value) => number_format((float) $value, 2, ',', '.').' ₺');
        self::assertSame('5.350,40 ₺', \Acunsoft\DataTable\Laravel\Support\Format::total($column, '5350.40', 'sum'));
        self::assertSame('3', \Acunsoft\DataTable\Laravel\Support\Format::total($column, 3, 'count'));
        $needsRow = Column::make('total')->type('decimal')->sum()->format(fn ($value, $row) => $row->currency.' '.$value);
        self::assertSame('5,350.40', \Acunsoft\DataTable\Laravel\Support\Format::total($needsRow, '5350.40', 'sum')); // falls back
        self::assertSame('', \Acunsoft\DataTable\Laravel\Support\Format::total($column, null, 'sum'));
    }

    public function test_the_json_endpoint_sends_totals(): void
    {
        \Illuminate\Support\Facades\Route::get('/totals', fn () => \Acunsoft\DataTable\Laravel\TableResponse::for(Order::query())->columns([
            Column::make('total')->type('decimal')->sortable()->filterable()->sum(),
            Column::make('created')->column('created_at')->type('datetime')->aggregate('max'),
            Column::make('note')->aggregate('count'),
        ]));
        $json = $this->getJson('/totals?pageSize=2')->assertOk()->json();
        self::assertEqualsWithDelta(5350.4, (float) $json['totals']['total'], 0.001);
        self::assertSame('2026-10-24 09:00:00', $json['totals']['created']);
        self::assertSame(4, (int) $json['totals']['note']); // COUNT skips NULL; an empty string is a value in SQL
        self::assertCount(2, $json['rows']);
    }
}
