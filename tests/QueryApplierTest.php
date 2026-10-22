<?php

declare(strict_types=1);

namespace Acunsoft\DataTable\Laravel\Tests;

use Acunsoft\DataTable\Laravel\Column;
use Acunsoft\DataTable\Laravel\InvalidTableQuery;
use Acunsoft\DataTable\Laravel\QueryApplier;
use Acunsoft\DataTable\Laravel\TableQuery;
use Acunsoft\DataTable\Laravel\Tests\Fixtures\Order;
use Illuminate\Support\Facades\DB;

final class QueryApplierTest extends TestCase
{
    /** @return list<Column> */
    private function columns(): array
    {
        return [
            Column::make('customer')->sortable()->filterable()->searchUsing('customer_search'),
            Column::make('city')->sortable()->filterable(),
            Column::make('total')->type('decimal')->sortable()->filterable(),
            Column::make('active')->type('boolean')->sortable()->filterable()->searchable(false),
            Column::make('created')->column('created_at')->type('datetime')->sortable()->filterable()->searchable(false),
            Column::make('day')->column('created_at')->type('date')->filterable()->searchable(false),
            Column::make('note')->filterable(),
            Column::make('internal')->column('secret'), // declared, but neither sortable nor filterable
        ];
    }

    /** @param array<string, mixed> $input @return list<string> */
    private function customers(array $input): array
    {
        $builder = QueryApplier::apply(Order::query(), TableQuery::fromArray($input), $this->columns());

        return $builder->pluck('customer')->all();
    }

    /** @param array<string, mixed> $filter */
    private function where(array $filter): array
    {
        return $this->customers(['filters' => [$filter]]);
    }

    public function test_contains_and_search_are_turkish_and_accent_insensitive_on_the_folded_column(): void
    {
        self::assertSame(['Ayşe Yılmaz'], $this->where(['field' => 'customer', 'op' => 'contains', 'value' => 'AYSE']));
        self::assertSame(['Iğdır Ticaret'], $this->where(['field' => 'customer', 'op' => 'startsWith', 'value' => 'igdir']));
        self::assertSame(['Can Öztürk'], $this->customers(['search' => 'ozturk']));
    }

    public function test_smart_search_needs_every_word_in_some_column(): void
    {
        // 'ayse' matches the folded customer column; 'İstanbul' matches the city as written.
        self::assertSame(['Ayşe Yılmaz'], $this->customers(['search' => 'ayse İstanbul']));
        self::assertSame(['Ali Kaya', 'Deniz Şahin'], $this->customers(['search' => 'ankara'])); // city or note
        self::assertSame(['Deniz Şahin'], $this->customers(['search' => '"ankara caddesi"']));
        self::assertSame([], $this->customers(['search' => 'ayse ankara']));
    }

    public function test_like_wildcards_in_user_text_are_literal(): void
    {
        self::assertSame(['Iğdır Ticaret'], $this->customers(['search' => '50%']));
        self::assertSame([], $this->where(['field' => 'note', 'op' => 'contains', 'value' => '_']));
    }

    public function test_decimal_and_boolean_comparisons(): void
    {
        self::assertSame(['Iğdır Ticaret'], $this->where(['field' => 'total', 'op' => 'gt', 'value' => '1000']));
        self::assertSame(['Ayşe Yılmaz', 'Ali Kaya', 'Can Öztürk'], $this->where(['field' => 'total', 'op' => 'between', 'value' => ['80', '120.50']]));
        self::assertSame(['Ali Kaya', 'Deniz Şahin'], $this->where(['field' => 'total', 'op' => 'in', 'value' => ['80.00', '50']]));
        self::assertSame(['Ayşe Yılmaz', 'Can Öztürk', 'Deniz Şahin'], $this->where(['field' => 'active', 'op' => 'eq', 'value' => 'true']));
    }

    public function test_date_and_datetime_days(): void
    {
        // datetime column + date-only value: the whole day, not just midnight
        self::assertSame(['Ayşe Yılmaz', 'Can Öztürk'], $this->where(['field' => 'created', 'op' => 'eq', 'value' => '2026-10-23']));
        self::assertSame(['Iğdır Ticaret'], $this->where(['field' => 'created', 'op' => 'gt', 'value' => '2026-10-23']));
        self::assertSame(['Ali Kaya'], $this->where(['field' => 'created', 'op' => 'lt', 'value' => '2026-10-23']));
        self::assertSame(['Ayşe Yılmaz', 'Ali Kaya', 'Can Öztürk'], $this->where(['field' => 'created', 'op' => 'between', 'value' => ['2026-10-22', '2026-10-23']]));
        // datetime with a time: an exact instant, read in the app time zone (Europe/Istanbul)
        self::assertSame(['Ayşe Yılmaz'], $this->where(['field' => 'created', 'op' => 'eq', 'value' => '2026-10-23T14:05:00+03:00']));
        self::assertSame(['Ayşe Yılmaz'], $this->where(['field' => 'created', 'op' => 'eq', 'value' => '2026-10-23T11:05:00Z']));
        // date column ignores the time of the value
        self::assertSame(['Ayşe Yılmaz', 'Can Öztürk'], $this->where(['field' => 'day', 'op' => 'eq', 'value' => '2026-10-23T18:30:00+03:00']));
    }

    public function test_empty_and_not_empty(): void
    {
        self::assertSame(['Ali Kaya', 'Can Öztürk'], $this->where(['field' => 'note', 'op' => 'empty']));
        self::assertSame(['Ayşe Yılmaz', 'Iğdır Ticaret', 'Deniz Şahin'], $this->where(['field' => 'note', 'op' => 'notEmpty']));
        self::assertSame(['Deniz Şahin'], $this->where(['field' => 'created', 'op' => 'empty']));
    }

    public function test_multi_column_sorting_with_a_key_tie_breaker(): void
    {
        self::assertSame(['Deniz Şahin', 'Ali Kaya', 'Can Öztürk', 'Ayşe Yılmaz', 'Iğdır Ticaret'], $this->customers(['sort' => 'total:asc']));
        // Two columns: inactive first, then the highest total within each group.
        self::assertSame(['Iğdır Ticaret', 'Ali Kaya', 'Ayşe Yılmaz', 'Can Öztürk', 'Deniz Şahin'],
            $this->customers(['sort' => 'active:asc,total:desc']));
        $sql = QueryApplier::apply(Order::query(), TableQuery::fromArray(['sort' => 'total:desc']), $this->columns())->toSql();
        self::assertStringContainsString('order by "total" desc, "orders"."id" asc', $sql);
    }

    public function test_only_declared_and_allowed_columns_reach_the_query(): void
    {
        foreach ([
            ['filters' => [['field' => 'secret', 'op' => 'eq', 'value' => 'x']]], // not declared
            ['filters' => [['field' => 'internal', 'op' => 'eq', 'value' => 'x']]], // declared, not filterable
            ['sort' => 'internal:asc'], // not sortable
            ['sort' => 'secret:asc'],
        ] as $input) {
            try {
                $this->customers($input);
                self::fail('expected InvalidTableQuery for '.json_encode($input));
            } catch (InvalidTableQuery $error) {
                self::assertNotEmpty($error->errors);
            }
        }
    }

    public function test_values_a_type_cannot_read_are_rejected(): void
    {
        foreach ([
            ['field' => 'total', 'op' => 'gt', 'value' => 'abc'],
            ['field' => 'total', 'op' => 'gt', 'value' => '1e400'],
            ['field' => 'active', 'op' => 'eq', 'value' => 'maybe'],
            ['field' => 'active', 'op' => 'gt', 'value' => 'true'],
            ['field' => 'created', 'op' => 'eq', 'value' => '2026-02-30'],
            ['field' => 'created', 'op' => 'eq', 'value' => 'soon'],
        ] as $filter) {
            try {
                $this->where($filter);
                self::fail('expected InvalidTableQuery for '.json_encode($filter));
            } catch (InvalidTableQuery $error) {
                self::assertArrayHasKey('filters.0.value', $error->errors);
            }
        }
    }

    public function test_every_value_is_bound(): void
    {
        $evil = "x' OR '1'='1";
        DB::enableQueryLog();
        self::assertSame([], $this->customers(['search' => $evil, 'filters' => [['field' => 'note', 'op' => 'eq', 'value' => $evil]]]));
        $log = DB::getQueryLog();
        self::assertStringNotContainsString("'1'='1", $log[0]['query']);
        self::assertContains($evil, $log[0]['bindings']);
    }

    public function test_paginate_uses_page_and_page_size(): void
    {
        $page = QueryApplier::paginate(Order::query(), TableQuery::fromArray(['page' => '2', 'pageSize' => '2', 'sort' => 'total:asc']), $this->columns());
        self::assertSame(5, $page->total());
        self::assertSame(['Can Öztürk', 'Ayşe Yılmaz'], array_map(fn ($order) => $order->customer, $page->items()));
    }
}
