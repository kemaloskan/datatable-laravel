<?php

declare(strict_types=1);

namespace Acunsoft\DataTable\Laravel\Tests;

use Acunsoft\DataTable\Laravel\QueryApplier;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Exact counts with a time limit on a real PostgreSQL server. Skipped unless
 * ADT_TEST_PGSQL names a database, e.g.
 * `ADT_TEST_PGSQL=postgres://postgres@127.0.0.1:5432/adt_bench vendor/bin/phpunit`.
 * Only a temporary table is used; it disappears with the connection.
 */
final class PostgresCountTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $url = getenv('ADT_TEST_PGSQL') ?: null;
        if ($url !== null) {
            $app['config']->set('database.connections.pg', ['driver' => 'pgsql', 'url' => $url, 'charset' => 'utf8', 'prefix' => '', 'search_path' => 'public']);
        }
        $app['config']->set('cache.default', 'array');
    }

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('ADT_TEST_PGSQL') === false) {
            self::markTestSkipped('Set ADT_TEST_PGSQL to run the PostgreSQL tests.');
        }
        DB::connection('pg')->statement('create temporary table adt_count_test as select n from generate_series(1, 300000) as n');
    }

    public function test_counts_exactly_past_the_limit_when_the_database_is_fast_enough(): void
    {
        self::assertSame([300_000, true], QueryApplier::count(DB::connection('pg')->table('adt_count_test'), 1000, 5000));
        self::assertSame([1000, false], QueryApplier::count(DB::connection('pg')->table('adt_count_test'), 1000, null)); // never past the limit
        self::assertSame([500, true], QueryApplier::count(DB::connection('pg')->table('adt_count_test')->where('n', '<=', 500), 1000, 1));
    }

    public function test_falls_back_to_the_lower_bound_and_remembers_slow_queries(): void
    {
        $pg = DB::connection('pg');
        // pg_sleep makes every row slow, so 1 ms can never be enough.
        $slow = fn () => $pg->table('adt_count_test')->whereRaw('n > 0 and pg_sleep(0.00001) is not null');
        self::assertSame([1000, false], QueryApplier::count($slow(), 1000, 1));
        self::assertSame('0', (string) $pg->selectOne("select current_setting('statement_timeout') as value")->value); // restored
        self::assertSame(1, $pg->selectOne('select 1 as one')->one); // the connection still works

        $pg->enableQueryLog();
        self::assertSame([1000, false], QueryApplier::count($slow(), 1000, 1));
        self::assertCount(1, $pg->getQueryLog()); // only the cheap bounded count: the slow one is remembered
        Cache::flush();
    }

    public function test_a_timeout_inside_a_transaction_does_not_break_it(): void
    {
        $pg = DB::connection('pg');
        $pg->transaction(function () use ($pg): void {
            $pg->table('adt_count_test')->where('n', 1)->update(['n' => -1]);
            $slow = $pg->table('adt_count_test')->whereRaw('n > 0 and pg_sleep(0.00001) is not null');
            self::assertSame([1000, false], QueryApplier::count($slow, 1000, 1));
            self::assertSame(1, $pg->table('adt_count_test')->where('n', -1)->count()); // still usable, change kept
            self::assertSame('0', (string) $pg->selectOne("select current_setting('statement_timeout') as value")->value);
        });
    }

    public function test_cursor_paging_follows_postgresqls_null_order(): void
    {
        $pg = DB::connection('pg');
        $pg->statement('create temporary table adt_cursor_test (id int primary key, score int)');
        $pg->statement('insert into adt_cursor_test select n, case when n % 3 = 0 then null else n % 5 end from generate_series(1, 20) as n');
        $columns = [\Acunsoft\DataTable\Laravel\Column::make('score')->type('number')->sortable()->nullable()];
        foreach (['asc', 'desc'] as $direction) {
            $seen = [];
            $cursor = null;
            for ($page = 1; $page < 20; $page++) {
                $query = \Acunsoft\DataTable\Laravel\TableQuery::fromArray(['sort' => "score:{$direction}", 'pageSize' => 3, 'paging' => 'cursor', 'page' => $page] + ($cursor === null ? [] : ['cursor' => $cursor]));
                $result = QueryApplier::cursorPage($pg->table('adt_cursor_test'), $query, $columns);
                $seen = [...$seen, ...array_map(fn ($row) => $row->id, $result->items())];
                if (($cursor = $result->nextCursor()) === null) {
                    break;
                }
            }
            self::assertSame($pg->table('adt_cursor_test')->orderBy('score', $direction)->orderBy('id', $direction)->pluck('id')->all(), $seen, $direction);
        }
    }
}
