<?php

declare(strict_types=1);

namespace Acunsoft\DataTable\Laravel\Tests;

use Acunsoft\DataTable\Laravel\Column;
use Acunsoft\DataTable\Laravel\TableResponse;
use Acunsoft\DataTable\Laravel\Tests\Fixtures\Order;
use Illuminate\Support\Facades\Route;

final class TableResponseTest extends TestCase
{
    protected function defineRoutes($router): void
    {
        $columns = fn () => [
            Column::make('customer')->sortable()->filterable()->searchUsing('customer_search'),
            Column::make('total')->type('decimal')->sortable()->filterable(),
        ];
        Route::match(['get', 'post'], '/orders/data', fn () => TableResponse::for(Order::query())->columns($columns())->maxPageSize(3, 2));
        Route::get('/orders/engine', fn () => TableResponse::for(Order::query())->columns($columns())
            ->searchEngine(fn (string $search) => $search === 'vip' ? [1, 3, 99] : []));
        Route::get('/orders/custom', fn () => TableResponse::for(Order::query())->columns($columns())
            ->transform(fn (Order $order) => ['name' => mb_strtoupper($order->customer)]));
    }

    public function test_answers_rows_and_total_in_the_http_package_format(): void
    {
        $response = $this->getJson('/orders/data?sort=total:desc&page=1&pageSize=2&search=a');
        $response->assertOk()->assertExactJson([
            'rows' => [
                ['id' => 4, 'customer' => 'Iğdır Ticaret', 'total' => '5000.00'],
                ['id' => 1, 'customer' => 'Ayşe Yılmaz', 'total' => '120.50'],
            ],
            'totalRows' => 5,
        ]);
    }

    public function test_reads_the_query_from_a_post_form_or_json_body(): void
    {
        $expected = ['rows' => [['id' => 4, 'customer' => 'Iğdır Ticaret', 'total' => '5000.00']], 'totalRows' => 5];
        $body = ['sort' => 'total:desc', 'page' => '1', 'pageSize' => '1', 'filters' => [['field' => 'total', 'op' => 'gt', 'value' => '1']]];
        $this->post('/orders/data', $body, ['Accept' => 'application/json'])->assertOk()->assertExactJson($expected);
        $this->postJson('/orders/data', $body)->assertOk()->assertExactJson($expected);
        $this->postJson('/orders/data?pageSize=3', ['pageSize' => '1'])->assertOk()->assertJsonCount(1, 'rows'); // the body, not the URL
    }

    public function test_never_sends_undeclared_attributes(): void
    {
        $body = $this->getJson('/orders/data')->assertOk()->json();
        self::assertSame(2, count($body['rows'])); // default page size
        self::assertArrayNotHasKey('secret', $body['rows'][0]);
        self::assertArrayNotHasKey('note', $body['rows'][0]);
    }

    public function test_invalid_queries_answer_422(): void
    {
        $this->getJson('/orders/data?pageSize=4')->assertStatus(422)->assertJsonStructure(['message', 'errors' => ['pageSize']]);
        $this->getJson('/orders/data?sort=secret:asc')->assertStatus(422)->assertJsonPath('errors.sort', 'Unknown column [secret].');
        $this->getJson('/orders/data?filters[0][field]=total&filters[0][op]=DROP&filters[0][value]=1')->assertStatus(422);
    }

    public function test_transform_controls_the_row_shape(): void
    {
        $this->getJson('/orders/custom?pageSize=1&sort=customer:asc')->assertOk()
            ->assertExactJson(['rows' => [['name' => 'ALI KAYA']], 'totalRows' => 5]);
    }

    public function test_cursor_paging_walks_every_row_once_in_order(): void
    {
        $byOffset = [];
        for ($page = 1; $page <= 3; $page++) {
            $byOffset = [...$byOffset, ...array_column($this->getJson("/orders/data?sort=total:desc&pageSize=2&page={$page}")->json('rows'), 'id')];
        }
        $byCursor = [];
        $cursor = null;
        $page = 1;
        do {
            $body = $this->getJson('/orders/data?sort=total:desc&pageSize=2&paging=cursor&page='.$page.($cursor ? '&cursor='.$cursor : ''))->assertOk()->json();
            $byCursor = [...$byCursor, ...array_column($body['rows'], 'id')];
            self::assertSame(count($byOffset), $body['totalRows']); // still counted
            $cursor = $body['nextCursor'] ?? null;
            $page++;
        } while ($cursor !== null && $page < 20);
        self::assertSame($byOffset, $byCursor);
        self::assertSame(count(array_unique($byCursor)), count($byCursor));
    }

    public function test_a_bad_cursor_is_refused(): void
    {
        $this->getJson('/orders/data?paging=cursor&cursor=not%20base64')->assertStatus(422)->assertJsonStructure(['errors' => ['cursor']]);
        $this->getJson('/orders/data?paging=cursor&cursor=bm9wZQ')->assertStatus(422)->assertJsonStructure(['errors' => ['cursor']]);
        $first = $this->getJson('/orders/data?sort=total:desc&pageSize=2&paging=cursor')->json('nextCursor');
        $this->getJson('/orders/data?sort=customer:asc&pageSize=2&paging=cursor&cursor='.$first)->assertStatus(422); // made for another sort
    }

    public function test_a_search_engine_answers_the_search_within_the_filters(): void
    {
        $body = $this->getJson('/orders/engine?search=vip&sort=total:desc')->assertOk()->json();
        self::assertEqualsCanonicalizing([1, 3], array_column($body['rows'], 'id')); // 99 does not exist
        self::assertSame(2, $body['totalRows']);
        $body = $this->getJson('/orders/engine?search=vip&filters[0][field]=total&filters[0][op]=gt&filters[0][value]=1000')->json();
        self::assertSame(array_values(array_filter([1, 3], fn ($id) => (float) Order::query()->find($id)->total > 1000)), array_column($body['rows'], 'id'));
        self::assertSame([], $this->getJson('/orders/engine?search=nothing')->json('rows'));
        self::assertCount(3, $this->getJson('/orders/engine?pageSize=3')->json('rows')); // no search: every row, the engine is not asked
    }
}

