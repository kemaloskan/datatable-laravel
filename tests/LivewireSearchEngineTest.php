<?php

declare(strict_types=1);

namespace Acunsoft\DataTable\Laravel\Tests;

use Acunsoft\DataTable\Laravel\DataTableServiceProvider;
use Acunsoft\DataTable\Laravel\Tests\Fixtures\EngineOrdersTable;
use Livewire\Livewire;
use Livewire\LivewireServiceProvider;

final class LivewireSearchEngineTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [LivewireServiceProvider::class, DataTableServiceProvider::class];
    }

    public function test_the_engine_answers_the_search_once_per_request_and_everything_follows_it(): void
    {
        EngineOrdersTable::$asked = 0;
        $table = Livewire::test(EngineOrdersTable::class);
        self::assertSame(0, EngineOrdersTable::$asked); // no search: not asked
        EngineOrdersTable::$asked = 0;
        $table->set('search', 'gold');
        self::assertSame(1, EngineOrdersTable::$asked); // rows, count and the rest share one answer
        self::assertEqualsCanonicalizing([2, 4], array_map(fn ($row) => $row->id, $table->instance()->rows->items()));
        $table->call('selectAllMatching');
        $selected = (fn () => $this->selectedQuery()->pluck('id')->all())->call($table->instance());
        self::assertEqualsCanonicalizing([2, 4], $selected); // bulk actions get the engine's rows too
        $table->set('search', 'nothing');
        self::assertSame([], $table->instance()->rows->items());
    }
}
