<?php

declare(strict_types=1);

namespace Acunsoft\DataTable\Laravel\Tests;

use Acunsoft\DataTable\Laravel\Support\Text;
use Acunsoft\DataTable\Laravel\Tests\Fixtures\Order;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        $app['config']->set('app.timezone', 'Europe/Istanbul');
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    }

    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('orders', function (Blueprint $table): void {
            $table->id();
            $table->string('customer');
            $table->string('customer_search');
            $table->string('city')->nullable();
            $table->decimal('total', 10, 2);
            $table->boolean('active')->default(false);
            $table->dateTime('created_at')->nullable();
            $table->string('note')->nullable();
            $table->string('secret')->default('do-not-send');
        });
        foreach ([
            ['Ayşe Yılmaz', 'İstanbul', '120.50', true, '2026-10-23 14:05:00', 'VIP'],
            ['Ali Kaya', 'Ankara', '80.00', false, '2026-10-22 23:59:59', ''],
            ['Can Öztürk', 'İzmir', '99.90', true, '2026-10-23 00:00:00', null],
            ['Iğdır Ticaret', 'Iğdır', '5000.00', false, '2026-10-24 09:00:00', '50% indirim'],
            ['Deniz Şahin', 'Isparta', '50.00', true, null, 'Ankara Caddesi'],
        ] as [$customer, $city, $total, $active, $created, $note]) {
            Order::query()->insert([
                'customer' => $customer, 'customer_search' => Text::fold($customer), 'city' => $city,
                'total' => $total, 'active' => $active, 'created_at' => $created, 'note' => $note,
            ]);
        }
    }
}
