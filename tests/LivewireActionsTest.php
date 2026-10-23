<?php

declare(strict_types=1);

namespace Acunsoft\DataTable\Laravel\Tests;

use Acunsoft\DataTable\Laravel\Action;
use Acunsoft\DataTable\Laravel\DataTableServiceProvider;
use Acunsoft\DataTable\Laravel\Tests\Fixtures\Order;
use Acunsoft\DataTable\Laravel\Tests\Fixtures\OrdersTable;
use Livewire\Livewire;
use Livewire\LivewireServiceProvider;

final class LivewireActionsTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [LivewireServiceProvider::class, DataTableServiceProvider::class];
    }

    public function test_row_actions_render_with_the_row_name_and_confirm(): void
    {
        $html = Livewire::test(OrdersTable::class)->html();
        self::assertStringContainsString('<th scope="col" class="adt-th adt-actions">Actions</th>', $html);
        self::assertStringContainsString('aria-label="Activate: Ali Kaya"', $html);
        self::assertStringNotContainsString('aria-label="Activate: Ayşe Yılmaz"', $html); // already active: hidden
        self::assertStringContainsString('wire:click="runRowAction(\'delete\', \'1\')"', $html);
        self::assertStringContainsString('wire:confirm="Delete this order?"', $html);
    }

    public function test_a_row_action_runs_on_the_row_and_refreshes_the_table(): void
    {
        $table = Livewire::test(OrdersTable::class)->call('runRowAction', 'activate', '2');
        self::assertTrue(Order::query()->find(2)->active);
        self::assertStringNotContainsString('aria-label="Activate: Ali Kaya"', $table->html());
        $table->call('runRowAction', 'delete', 5)->assertDontSee('Deniz Şahin');
        self::assertNull(Order::query()->find(5));
    }

    public function test_row_actions_ignore_forged_keys_hidden_and_unknown_actions(): void
    {
        Livewire::test(OrdersTable::class, ['onlyCity' => 'Ankara'])
            ->call('runRowAction', 'delete', '4') // exists, but outside query()
            ->call('runRowAction', 'activate', '1') // outside query() too
            ->call('runRowAction', 'drop', '2') // not an action
            ->call('runRowAction', 'delete', str_repeat('9', 100));
        self::assertNotNull(Order::query()->find(4));
        self::assertSame(5, Order::query()->count());

        Livewire::test(OrdersTable::class)->call('runRowAction', 'activate', '1'); // hidden for an active row
        self::assertSame('VIP', Order::query()->find(1)->note); // the handler did not run
    }

    public function test_bulk_actions_run_on_the_selection_and_clear_it(): void
    {
        $table = Livewire::test(OrdersTable::class)->call('toggleRow', '1')->call('toggleRow', '3');
        self::assertStringContainsString('wire:click="runBulkAction(\'archive\')"', $table->html());
        self::assertStringContainsString('wire:confirm="Archive the selected orders?"', $table->html());
        $table->call('runBulkAction', 'archive')->assertSet('selected', []);
        self::assertSame([1, 3], Order::query()->where('note', 'archived')->orderBy('id')->pluck('id')->all());
    }

    public function test_bulk_actions_on_all_matching_rows_stay_inside_the_query(): void
    {
        Livewire::test(OrdersTable::class, ['onlyCity' => 'Ankara'])
            ->set('selected', ['4'])->call('runBulkAction', 'archive'); // 4 is outside query()
        self::assertSame(0, Order::query()->where('note', 'archived')->count());

        Livewire::test(OrdersTable::class)->set('search', 'ayse')->call('selectAllMatching')->call('runBulkAction', 'archive');
        self::assertSame([1], Order::query()->where('note', 'archived')->pluck('id')->all());
    }

    public function test_bulk_actions_need_a_selection_and_must_be_visible(): void
    {
        Livewire::test(OrdersTable::class)->call('runBulkAction', 'archive'); // nothing selected
        Livewire::test(OrdersTable::class, ['canArchive' => false])->call('toggleRow', '1')->call('runBulkAction', 'archive')
            ->assertDontSeeHtml('runBulkAction');
        Livewire::test(OrdersTable::class)->call('toggleRow', '1')->call('runBulkAction', 'unknown')->assertSet('selected', ['1']);
        self::assertSame(0, Order::query()->where('note', 'archived')->count());
    }

    public function test_action_definitions_are_validated(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Action::make('1-bad');
    }

    public function test_an_action_without_a_handler_fails_loudly(): void
    {
        $this->expectException(\LogicException::class);
        Action::make('noop')->run(null);
    }
}
