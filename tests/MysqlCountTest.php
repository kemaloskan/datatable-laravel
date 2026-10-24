<?php

declare(strict_types=1);

namespace Acunsoft\DataTable\Laravel\Tests;

use Acunsoft\DataTable\Laravel\QueryApplier;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Exact counts with a time limit on a real MySQL or MariaDB server (through
 * the mysql driver, as most apps connect to MariaDB too). Skipped unless
 * ADT_TEST_MYSQL names a database, e.g.
 * `ADT_TEST_MYSQL=mysql://root@127.0.0.1:3306/adt_test vendor/bin/phpunit`.
 * Only a temporary table is used; it disappears with the connection.
 */
final class MysqlCountTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $url = getenv('ADT_TEST_MYSQL') ?: null;
        if ($url !== null) {
            $app['config']->set('database.connections.my', ['driver' => 'mysql', 'url' => $url, 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'prefix' => '']);
        }
        $app['config']->set('cache.default', 'array');
    }

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('ADT_TEST_MYSQL') === false) {
            self::markTestSkipped('Set ADT_TEST_MYSQL to run the MySQL/MariaDB tests.');
        }
        $my = DB::connection('my');
        $my->statement('create temporary table adt_count_test (n int not null)');
        // 100,000 rows from five copies of the digits (a CTE: MySQL cannot open a temporary table twice in one query).
        $my->statement('insert into adt_count_test
            with digits (d) as (select 0 union all select 1 union all select 2 union all select 3 union all select 4
                union all select 5 union all select 6 union all select 7 union all select 8 union all select 9)
            select a.d + 10 * b.d + 100 * c.d + 1000 * e.d + 10000 * f.d + 1 from digits a, digits b, digits c, digits e, digits f');
    }

    private function variable(): string
    {
        return DB::connection('my')->isMaria() ? 'max_statement_time' : 'max_execution_time';
    }

    public function test_counts_exactly_past_the_limit_when_the_database_is_fast_enough(): void
    {
        $my = DB::connection('my');
        self::assertSame([100_000, true], QueryApplier::count($my->table('adt_count_test'), 1000, 5000));
        self::assertSame([1000, false], QueryApplier::count($my->table('adt_count_test'), 1000, null));
        self::assertSame([500, true], QueryApplier::count($my->table('adt_count_test')->where('n', '<=', 500), 1000, 1));
    }

    public function test_falls_back_to_the_lower_bound_restores_the_limit_and_remembers(): void
    {
        $my = DB::connection('my');
        $before = $my->selectOne("select @@session.{$this->variable()} as value")->value;
        // sleep() on every row makes the count slow, so 1 ms can never be enough.
        $slow = fn () => $my->table('adt_count_test')->whereRaw('n > 0 and sleep(0.0001) = 0');
        self::assertSame([1000, false], QueryApplier::count($slow(), 1000, 1));
        self::assertEquals($before, $my->selectOne("select @@session.{$this->variable()} as value")->value); // restored
        self::assertSame(1, (int) $my->selectOne('select 1 as one')->one);

        $my->enableQueryLog();
        self::assertSame([1000, false], QueryApplier::count($slow(), 1000, 1));
        self::assertCount(1, $my->getQueryLog()); // only the bounded count: the slow one is remembered
        Cache::flush();
    }
}
