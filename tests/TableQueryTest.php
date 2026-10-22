<?php

declare(strict_types=1);

namespace Acunsoft\DataTable\Laravel\Tests;

use Acunsoft\DataTable\Laravel\InvalidTableQuery;
use Acunsoft\DataTable\Laravel\TableQuery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase as PlainTestCase;

final class TableQueryTest extends PlainTestCase
{
    public function test_reads_the_http_wire_format(): void
    {
        $query = TableQuery::fromArray([
            'page' => '2', 'pageSize' => '50', 'sort' => 'total:desc,customer:asc', 'search' => '  ayşe "new york" ',
            'filters' => [
                ['field' => 'total', 'op' => 'between', 'value' => ['80.00', '120']],
                ['field' => 'note', 'op' => 'empty'],
                ['field' => 'customer', 'op' => 'contains', 'value' => '   '], // blank: dropped
                ['field' => 'active', 'op' => 'eq', 'value' => true],
            ],
        ]);
        self::assertSame(2, $query->page);
        self::assertSame(50, $query->pageSize);
        self::assertSame([['field' => 'total', 'direction' => 'desc'], ['field' => 'customer', 'direction' => 'asc']], $query->sorting);
        self::assertSame([
            ['field' => 'total', 'operator' => 'between', 'value' => ['80.00', '120']],
            ['field' => 'note', 'operator' => 'empty', 'value' => null],
            ['field' => 'active', 'operator' => 'eq', 'value' => 'true'],
        ], $query->filters);
        self::assertSame(['ayşe', 'new york'], $query->searchTerms());
    }

    public function test_defaults(): void
    {
        $query = TableQuery::fromArray([]);
        self::assertSame([1, 25, [], [], ''], [$query->page, $query->pageSize, $query->sorting, $query->filters, $query->search]);
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function invalid(): array
    {
        return [
            'page 0' => [['page' => '0'], 'page'],
            'page too big' => [['page' => '10000001'], 'page'],
            'page not a number' => [['page' => '1e3'], 'page'],
            'page size over max' => [['pageSize' => '101'], 'pageSize'],
            'sort shape' => [['sort' => 'total'], 'sort'],
            'sort SQL' => [['sort' => 'total;drop:asc'], 'sort'],
            'sort twice' => [['sort' => 'total:asc,total:desc'], 'sort'],
            'operator DROP' => [['filters' => [['field' => 'total', 'op' => 'DROP', 'value' => '1']]], 'filters.0.op'],
            'field injection' => [['filters' => [['field' => 'total)--', 'op' => 'eq', 'value' => '1']]], 'filters.0.field'],
            'between shape' => [['filters' => [['field' => 'total', 'op' => 'between', 'value' => '1']]], 'filters.0.value'],
            'in empty' => [['filters' => [['field' => 'total', 'op' => 'in', 'value' => []]]], 'filters.0.value'],
            'empty with value' => [['filters' => [['field' => 'note', 'op' => 'empty', 'value' => 'x']]], 'filters.0.value'],
            'value too long' => [['filters' => [['field' => 'note', 'op' => 'eq', 'value' => str_repeat('x', 201)]]], 'filters.0.value'],
            'filters not a list' => [['filters' => 'x'], 'filters'],
            'search not text' => [['search' => ['x']], 'search'],
        ];
    }

    /** @param array<string, mixed> $input */
    #[DataProvider('invalid')]
    public function test_rejects_invalid_input(array $input, string $parameter): void
    {
        try {
            TableQuery::fromArray($input);
            self::fail('expected InvalidTableQuery');
        } catch (InvalidTableQuery $error) {
            self::assertArrayHasKey($parameter, $error->errors);
        }
    }

    public function test_search_is_capped(): void
    {
        $query = TableQuery::fromArray(['search' => str_repeat('a ', 300)]);
        self::assertLessThanOrEqual(200, mb_strlen($query->search));
        self::assertCount(10, $query->searchTerms());
    }
}
