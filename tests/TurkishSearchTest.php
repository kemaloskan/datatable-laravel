<?php

declare(strict_types=1);

namespace Acunsoft\DataTable\Laravel\Tests;

use Acunsoft\DataTable\Laravel\Column;
use Acunsoft\DataTable\Laravel\QueryApplier;
use Acunsoft\DataTable\Laravel\TableQuery;
use Acunsoft\DataTable\Laravel\Tests\Fixtures\Order;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\PostgresConnection;

/** Search and contains on columns without a stored folded copy (no searchUsing). */
final class TurkishSearchTest extends TestCase
{
    /** @return list<string> cities found */
    private function cities(array $input): array
    {
        $columns = [Column::make('city')->sortable()->filterable(), Column::make('note')->filterable()];

        return QueryApplier::apply(Order::query(), TableQuery::fromArray($input + ['sort' => 'city:asc']), $columns)
            ->pluck('city')->unique()->values()->all();
    }

    public function test_search_ignores_case_accents_and_turkish_i_on_plain_columns(): void
    {
        foreach (['istanbul', 'ISTANBUL', 'İstanbul', 'İSTANBUL', 'Istanbul'] as $typed) {
            self::assertSame(['İstanbul'], $this->cities(['search' => $typed]), $typed);
        }
        foreach (['ığdır', 'IĞDIR', 'igdir', 'IGDIR', 'Iğdır'] as $typed) {
            self::assertSame(['Iğdır'], $this->cities(['search' => $typed]), $typed);
        }
        self::assertSame(['İzmir'], $this->cities(['search' => 'IZMIR']));
        self::assertSame(['Isparta'], $this->cities(['search' => 'ısparta']));
        self::assertSame(['Ankara', 'Isparta'], $this->cities(['search' => 'ANKARA'])); // Isparta: note "Ankara Caddesi"
    }

    public function test_contains_and_starts_with_fold_too_and_wildcards_stay_literal(): void
    {
        self::assertSame(['İzmir'], $this->cities(['filters' => [['field' => 'city', 'op' => 'contains', 'value' => 'ZMİ']]]));
        self::assertEqualsCanonicalizing(['Iğdır', 'İstanbul', 'İzmir', 'Isparta'], $this->cities(['filters' => [['field' => 'city', 'op' => 'startsWith', 'value' => 'i']]]));
        self::assertSame(['Iğdır'], $this->cities(['filters' => [['field' => 'note', 'op' => 'contains', 'value' => '50%']]]));
        self::assertSame([], $this->cities(['filters' => [['field' => 'note', 'op' => 'contains', 'value' => '5_%']]]));
    }

    public function test_other_databases_get_a_lower_replace_chain_and_keep_the_collation_match(): void
    {
        $columns = [Column::make('city')->filterable()];
        $query = TableQuery::fromArray(['search' => 'istanbul']);

        $pgsql = new PostgresConnection(fn () => null, 'app', '', ['driver' => 'pgsql']);
        $sql = QueryApplier::apply($pgsql->table('orders'), $query, $columns)->toSql();
        self::assertStringContainsString('LOWER(REPLACE(REPLACE("city", \'İ\', \'i\'), \'I\', \'i\'))', $sql);
        self::assertStringContainsString(' LIKE ? ESCAPE \'!\' or "city" ILIKE ? ESCAPE \'!\'', $sql);
        self::assertLessThanOrEqual(31, substr_count($sql, 'REPLACE(')); // shallow enough for every database

        $mysql = new MySqlConnection(fn () => null, 'app', '', ['driver' => 'mysql']);
        $sql = QueryApplier::apply($mysql->table('orders'), $query, $columns)->toSql();
        self::assertStringContainsString('LOWER(REPLACE(REPLACE(`city`', $sql);
        self::assertStringContainsString('or `city` LIKE ? ESCAPE \'!\'', $sql);
    }
}
