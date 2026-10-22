<?php

declare(strict_types=1);

namespace Acunsoft\DataTable\Laravel\Tests;

use Acunsoft\DataTable\Laravel\DataTableServiceProvider;
use Acunsoft\DataTable\Laravel\Tests\Fixtures\Order;
use Acunsoft\DataTable\Laravel\Tests\Fixtures\OrdersTable;
use Livewire\Livewire;
use Livewire\LivewireServiceProvider;

final class LivewireTableTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [LivewireServiceProvider::class, DataTableServiceProvider::class];
    }

    /** @return list<string> customers in display order */
    private function customers(mixed $component): array
    {
        return array_map(fn (Order $order) => $order->customer, $component->instance()->rows->items());
    }

    public function test_renders_a_semantic_table_with_the_dom_renderer_class_names(): void
    {
        $table = Livewire::test(OrdersTable::class);
        self::assertMatchesRegularExpression('/class="adt-previous"\s+aria-disabled="true"/', $table->html());
        self::assertMatchesRegularExpression('/class="adt-next"\s+aria-disabled="true"/', $table->html());
        $table
            ->assertSeeHtml('<caption>Orders</caption>')
            ->assertSeeHtml('class="adt-table"')
            ->assertSeeHtml('<th scope="col" class="adt-th"')
            ->assertSeeHtml('data-label="Customer"')
            ->assertSeeHtml('aria-label="Select Ayşe Yılmaz"')
            ->assertSeeHtml('<span class="adt-status" aria-live="polite">Page 1 of 1 · 5 rows</span>')
            ->assertSee('Yes')
            ->assertSee('—'); // Column::format
    }

    public function test_texts_follow_the_application_locale(): void
    {
        app()->setLocale('tr');
        Livewire::test(OrdersTable::class)
            ->assertSee('Sayfa 1 / 1 · 5 kayıt')
            ->assertSee('Ayşe Yılmaz satırını seç')
            ->assertSee('Evet')
            ->assertSee('5.000,00'); // decimal in the Turkish format, scale kept
    }

    public function test_escapes_hostile_values(): void
    {
        Order::query()->whereKey(1)->update(['customer' => '<img src=x onerror=alert(1)>', 'note' => '<script>x</script>']);
        Livewire::test(OrdersTable::class)
            ->assertDontSeeHtml('<img src=x')
            ->assertDontSeeHtml('<script>x</script>')
            ->assertSeeHtml('&lt;img src=x onerror=alert(1)&gt;');
    }

    public function test_sorting_cycles_and_shift_adds_a_column(): void
    {
        $table = Livewire::test(OrdersTable::class)->call('sortBy', 'total');
        $table->assertSet('sort', 'total:asc')->assertSeeHtml('aria-sort="ascending"');
        self::assertSame('Deniz Şahin', $this->customers($table)[0]);

        $table->call('sortBy', 'total')->assertSet('sort', 'total:desc')->assertSeeHtml('aria-sort="descending"');
        self::assertSame('Iğdır Ticaret', $this->customers($table)[0]);

        $table->call('sortBy', 'city', true)->assertSet('sort', 'total:desc,city:asc')->assertSee('▼1')->assertSee('▲2');
        $table->call('sortBy', 'total', true)->assertSet('sort', 'city:asc'); // removed, city stays
        $table->call('sortBy', 'customer')->assertSet('sort', 'customer:asc'); // a plain click replaces
        $table->call('sortBy', 'customer')->call('sortBy', 'customer')->assertSet('sort', '')->assertDontSeeHtml('aria-sort');
        $table->call('sortBy', 'note')->assertSet('sort', ''); // not sortable
        $table->call('sortBy', 'secret')->assertSet('sort', ''); // not a column
    }

    public function test_search_and_filters_use_the_query_rules_and_reset_the_page(): void
    {
        $table = Livewire::test(OrdersTable::class)->set('pageSize', 2)->call('nextPage');
        self::assertSame(2, $table->instance()->getPage());

        $table->set('search', 'İstanbul ayse'); // city as written + the folded customer column, words ANDed
        self::assertSame(1, $table->instance()->getPage());
        self::assertSame(['Ayşe Yılmaz'], $this->customers($table));

        $table->set('search', '')->set('pageSize', 25)->call('setFilter', 'total', 'between', ['80', '120.50']);
        self::assertSame(['Ayşe Yılmaz', 'Ali Kaya', 'Can Öztürk'], $this->customers($table)); // inclusive
        $table->call('setFilter', 'total', 'between', ['90', '200'])->assertCount('filters', 1); // replaces
        $table->call('setFilter', 'active', 'eq', 'true');
        self::assertSame(['Ayşe Yılmaz', 'Can Öztürk'], $this->customers($table));
        $table->call('setFilter', 'created', 'eq', '2026-10-23'); // whole day
        self::assertSame(['Ayşe Yılmaz', 'Can Öztürk'], $this->customers($table));
        $table->call('removeFilter', 0)->assertCount('filters', 2)->call('clearFilters')->assertSet('filters', []);
        self::assertCount(5, $this->customers($table));
    }

    public function test_invalid_filters_are_not_added(): void
    {
        Livewire::test(OrdersTable::class)
            ->call('setFilter', 'total', 'gt', 'abc') // not a number
            ->call('setFilter', 'note', 'eq', 'VIP') // not filterable
            ->call('setFilter', 'secret', 'eq', 'x') // not a column
            ->call('setFilter', 'customer', 'contains', '  ') // blank
            ->call('setFilter', 'customer', 'regex', '.*') // unknown operator
            ->assertSet('filters', []);
    }

    public function test_tampered_url_state_is_dropped_instead_of_failing(): void
    {
        $table = Livewire::withQueryParams([
            'sort' => 'secret:asc,total:sideways,city:desc,city:asc',
            'filters' => [['field' => 'secret', 'op' => 'eq', 'value' => 'x'], ['field' => 'total', 'op' => 'gt', 'value' => '100'], 'junk'],
            'pageSize' => '7',
            'page' => '999',
        ])->test(OrdersTable::class);

        $query = (fn () => $this->dataTableQuery())->call($table->instance());
        self::assertSame([['field' => 'city', 'direction' => 'desc']], $query->sorting);
        self::assertSame([['field' => 'total', 'operator' => 'gt', 'value' => '100']], $query->filters);
        self::assertSame(25, $query->pageSize); // 7 is not an option
        self::assertSame(['Ayşe Yılmaz', 'Iğdır Ticaret'], $this->customers($table)); // the last page instead of an empty one
        $table->assertSee('Page 1 of 1 · 2 rows');
    }

    public function test_page_size_must_be_an_option_and_resets_the_page(): void
    {
        $table = Livewire::test(OrdersTable::class)->set('pageSize', 2)->call('nextPage')->call('nextPage');
        self::assertSame(['Deniz Şahin'], $this->customers($table));
        self::assertMatchesRegularExpression('/class="adt-next"\s+aria-disabled="true"/', $table->html());
        self::assertMatchesRegularExpression('/class="adt-previous"\s+wire:click="previousPage"/', $table->html());
        $table->set('pageSize', 25);
        self::assertSame(1, $table->instance()->getPage());
        self::assertCount(5, $this->customers($table));
    }

    public function test_selects_rows_pages_and_every_matching_row(): void
    {
        $table = Livewire::test(OrdersTable::class)->set('pageSize', 2);
        $table->call('toggleRow', 1)->call('toggleRow', '3')->assertSet('selected', ['1', '3'])
            ->assertSee('2 selected')->assertSee('Select all 5 matching rows');
        $table->call('toggleRow', '1')->assertSet('selected', ['3']);

        $table->call('togglePage')->assertSet('selected', ['3', '1', '2']); // page 1 holds 1 and 2
        $table->call('togglePage')->assertSet('selected', ['3']);

        $table->set('search', 'a')->assertSet('selected', []); // a new result set clears the selection
        $table->call('selectAllMatching')->call('toggleRow', '2')->assertSet('excluded', ['2']);
        $selected = (fn () => $this->selectedQuery()->pluck('id')->all())->call($table->instance());
        self::assertSame([1, 3, 4, 5], $selected); // matching "a", without 2
        $table->call('clearSelection')->assertSet('allMatching', false)->assertDontSeeHtml('class="adt-selection"');
    }

    public function test_selection_never_reaches_rows_outside_the_query(): void
    {
        $table = Livewire::test(OrdersTable::class, ['onlyCity' => 'Ankara'])
            ->set('selected', ['2', '4', ['nested'], str_repeat('9', 100)]);
        $selected = (fn () => $this->selectedQuery()->pluck('id')->all())->call($table->instance());
        self::assertSame([2], $selected); // 4 exists but is outside query(); junk keys are ignored
    }

    public function test_locked_and_helper_members_cannot_be_driven_from_the_browser(): void
    {
        $table = Livewire::test(OrdersTable::class);
        foreach (['dataTableQuery', 'selectedQuery', 'rowKey', 'columns', 'query'] as $method) {
            try {
                $table->call($method);
                self::fail("{$method} should not be callable");
            } catch (\Throwable $error) {
                self::assertStringNotContainsString('should not be callable', $error->getMessage());
            }
        }
        $this->expectException(\Throwable::class);
        $table->set('allMatching', true);
    }
}
