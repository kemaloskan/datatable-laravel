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
        Route::get('/orders/data', fn () => TableResponse::for(Order::query())->columns($columns())->maxPageSize(3, 2));
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
}
