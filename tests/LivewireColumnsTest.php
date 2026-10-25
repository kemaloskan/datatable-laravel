<?php

declare(strict_types=1);

namespace Acunsoft\DataTable\Laravel\Tests;

use Acunsoft\DataTable\Laravel\DataTableServiceProvider;
use Acunsoft\DataTable\Laravel\Tests\Fixtures\OrdersTable;
use Livewire\Livewire;
use Livewire\LivewireServiceProvider;

final class LivewireColumnsTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [LivewireServiceProvider::class, DataTableServiceProvider::class];
    }

    /** @return list<string> visible data column headers */
    private function headers(string $html): array
    {
        preg_match_all('/<th scope="col" class="adt-th(?: adt-num)?"[^>]*>(.*?)<\/th>/s', $html, $matches);

        return array_map(fn (string $cell) => trim(preg_replace('/[▲▼]\d*/u', '', strip_tags($cell))), $matches[1]);
    }

    public function test_hidden_by_default_columns_start_hidden_and_the_menu_lists_every_column(): void
    {
        $table = Livewire::test(OrdersTable::class)->assertSet('hiddenColumns', ['created']);
        self::assertSame(['Customer', 'City', 'Total', 'Active', 'Note'], $this->headers($table->html()));
        $html = $table->html();
        self::assertMatchesRegularExpression('/<summary>\s*<svg[^>]*>.*?<\/svg>\s*<span class="adt-summary-text">Columns<\/span>\s*<\/summary>/s', $html);
        self::assertMatchesRegularExpression('/value="created"\s+wire:click="toggleColumn\(\'created\'\)"\s+(?!checked)/', $html);
        self::assertMatchesRegularExpression('/value="customer"[^>]*checked[^>]*disabled/s', $html); // not hideable
        self::assertStringNotContainsString('data-label="Created"', $html);
    }

    public function test_toggling_shows_and_hides_columns_without_changing_the_query(): void
    {
        $table = Livewire::test(OrdersTable::class)->call('sortBy', 'total')->call('toggleColumn', 'created');
        $table->assertSet('hiddenColumns', [])->assertSet('sort', 'total:asc');
        self::assertContains('Created', $this->headers($table->html()));
        $table->call('toggleColumn', 'total')->assertSet('hiddenColumns', ['total']);
        self::assertNotContains('Total', $this->headers($table->html()));
        self::assertSame(['Deniz Şahin', 'Ali Kaya'], array_slice(array_map(fn ($o) => $o->customer, $table->instance()->rows->items()), 0, 2)); // still sorted by total
    }

    public function test_hidden_columns_still_search(): void
    {
        $table = Livewire::test(OrdersTable::class)->call('toggleColumn', 'city')->set('search', 'Ankara');
        self::assertSame(['Ali Kaya'], array_map(fn ($o) => $o->customer, $table->instance()->rows->items())); // matched by the hidden city
    }

    public function test_protected_unknown_and_all_hidden_requests_are_ignored(): void
    {
        $table = Livewire::test(OrdersTable::class)
            ->call('toggleColumn', 'customer') // hideable(false)
            ->call('toggleColumn', 'secret') // not a column
            ->assertSet('hiddenColumns', ['created']);
        foreach (['city', 'total', 'active', 'note'] as $id) {
            $table->call('toggleColumn', $id);
        }
        self::assertSame(['Customer'], $this->headers($table->html())); // customer can never be hidden
    }

    public function test_tampered_url_state_is_normalized(): void
    {
        $table = Livewire::withQueryParams(['hidden' => ['customer', 'nope', 'city']])->test(OrdersTable::class);
        self::assertSame(['Customer', 'Total', 'Active', 'Created', 'Note'], $this->headers($table->html()));
    }
}
