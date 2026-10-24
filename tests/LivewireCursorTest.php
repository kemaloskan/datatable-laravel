<?php

declare(strict_types=1);

namespace Acunsoft\DataTable\Laravel\Tests;

use Acunsoft\DataTable\Laravel\DataTableServiceProvider;
use Acunsoft\DataTable\Laravel\Tests\Fixtures\CursorOrdersTable;
use Acunsoft\DataTable\Laravel\Tests\Fixtures\Order;
use Livewire\Livewire;
use Livewire\LivewireServiceProvider;

final class LivewireCursorTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [LivewireServiceProvider::class, DataTableServiceProvider::class];
    }

    /** @return list<int> */
    private function ids(\Livewire\Features\SupportTesting\Testable $table): array
    {
        return array_map(fn ($row) => $row->id, $table->instance()->rows->items());
    }

    public function test_pages_follow_the_cursors_and_match_offset_paging(): void
    {
        $expected = Order::query()->orderByDesc('total')->orderByDesc('id')->pluck('id')->all();
        $table = Livewire::test(CursorOrdersTable::class)->call('sortBy', 'total')->call('sortBy', 'total'); // total:desc
        $seen = [];
        for ($page = 1; $page <= (int) ceil(count($expected) / 2); $page++) {
            if ($page > 1) {
                $table->call('setPage', $page);
            }
            $seen = [...$seen, ...$this->ids($table)];
        }
        self::assertSame($expected, $seen);
        self::assertArrayNotHasKey((int) ceil(count($expected) / 2) + 1, $table->get('cursors')); // no page after the last
    }

    public function test_only_known_pages_open_and_changes_start_over(): void
    {
        $table = Livewire::test(CursorOrdersTable::class);
        $html = preg_replace('/<!--.*?-->/s', '', $table->html());
        self::assertSame([2], array_keys($table->get('cursors')));
        self::assertMatchesRegularExpression('/aria-label="Page 2"/', $html);
        self::assertDoesNotMatchRegularExpression('/aria-label="Page 3"/', $html); // not reached yet
        $table->call('setPage', 3); // unknown: back to the first page
        self::assertSame(1, $table->instance()->rows->currentPage());
        $table->call('setPage', 2);
        self::assertSame([2, 3], array_keys($table->get('cursors')));
        $table->call('sortBy', 'customer');
        self::assertSame(1, $table->instance()->rows->currentPage());
        self::assertSame([2], array_keys($table->get('cursors')));
    }
}
