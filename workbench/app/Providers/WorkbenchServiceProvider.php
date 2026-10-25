<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use Acunsoft\DataTable\Laravel\Support\Text;
use Acunsoft\DataTable\Laravel\Tests\Fixtures;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

/**
 * The browser-test app: fixture tables at /tables/{name}, their data in a
 * SQLite file per browser (cookie `adt_db`, so parallel browsers never share
 * rows), /reset for a fresh copy, and the shared stylesheet at /styles.css.
 */
final class WorkbenchServiceProvider extends ServiceProvider
{
    private const TABLES = [
        'responsive' => Fixtures\ResponsiveOrdersTable::class,
        'edit' => Fixtures\EditOrdersTable::class,
        'cursor' => Fixtures\CursorOrdersTable::class,
        'filters' => Fixtures\FilterOrdersTable::class,
        'import' => Fixtures\ImportOrdersTable::class,
    ];

    public function register(): void
    {
        $name = preg_match('/^[a-z]{1,20}$/', (string) ($_COOKIE['adt_db'] ?? '')) === 1 ? $_COOKIE['adt_db'] : 'default';
        $this->app['config']->set('database.default', 'workbench');
        $this->app['config']->set('database.connections.workbench', [
            'driver' => 'sqlite', 'database' => sys_get_temp_dir()."/adt-workbench-{$name}.sqlite", 'prefix' => '', 'foreign_key_constraints' => true,
        ]);
    }

    public function boot(): void
    {
        View::addNamespace('workbench', __DIR__.'/../../resources/views');
        View::addNamespace('fixtures', __DIR__.'/../../../tests/Fixtures/views');
        $database = config('database.connections.workbench.database');
        if (! is_file($database)) {
            $this->fresh();
        }

        Route::middleware('web')->group(function (): void {
            Route::get('/', fn () => 'Acun DataTable workbench: '.implode(', ', array_map(fn ($name) => "/tables/{$name}", array_keys(self::TABLES))));
            Route::get('/styles.css', fn () => response()->file(__DIR__.'/../../../../renderer-dom/src/styles.css', ['Content-Type' => 'text/css']));
            // The import dialog (@acunsoft/datatable-import, built) and the import protocol for OrdersImport.
            Route::get('/import/{file}', fn (string $file) => response()->file(__DIR__.'/../../../../import/dist/'.$file, [
                'Content-Type' => str_ends_with($file, '.css') ? 'text/css' : 'text/javascript',
            ]))->where('file', 'acun-datatable-import\.min\.js|styles\.css|testing\.js');
            Route::acunImport('import/orders', Fixtures\OrdersImport::class);
            Route::get('/reset', function () {
                $this->fresh();

                return 'reset';
            });
            Route::get('/tables/{name}', function (string $name) {
                abort_unless(isset(self::TABLES[$name]), 404);
                // Fixture properties from the URL, e.g. ?mode=auto&resize=1&fixed[start]=1
                $params = [];
                foreach (request()->query() as $key => $value) {
                    if (in_array($key, ['mode', 'density', 'height', 'detail'], true) && is_string($value)) {
                        $params[$key] = $value;
                    } elseif (in_array($key, ['resize', 'reorder', 'sticky'], true)) {
                        $params[$key] = $value === '1';
                    } elseif ($key === 'fixed' && is_array($value)) {
                        $params['fixed'] = array_map('intval', $value);
                    }
                }

                return view('workbench::page', ['component' => self::TABLES[$name], 'params' => $params]);
            });
        });
    }

    /** A fresh copy of the test data: the five orders of the PHPUnit tests and 25 more for paging. */
    private function fresh(): void
    {
        $database = config('database.connections.workbench.database');
        @unlink($database);
        touch($database);
        DB::purge('workbench');
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
        $rows = [
            ['Ayşe Yılmaz', 'İstanbul', '120.50', true, '2026-10-23 14:05:00', 'VIP'],
            ['Ali Kaya', 'Ankara', '80.00', false, '2026-10-22 23:59:59', ''],
            ['Can Öztürk', 'İzmir', '99.90', true, '2026-10-23 00:00:00', null],
            ['Iğdır Ticaret', 'Iğdır', '5000.00', false, '2026-10-24 09:00:00', '50% indirim'],
            ['Deniz Şahin', 'Isparta', '50.00', true, null, 'Ankara Caddesi'],
        ];
        foreach (range(6, 30) as $n) {
            $rows[] = ["Müşteri {$n}", ['İstanbul', 'Ankara', 'İzmir'][$n % 3], number_format($n * 13.25, 2, '.', ''), $n % 2 === 0, '2026-10-'.str_pad((string) (1 + $n % 28), 2, '0', STR_PAD_LEFT).' 10:00:00', null];
        }
        foreach ($rows as [$customer, $city, $total, $active, $created, $note]) {
            DB::table('orders')->insert([
                'customer' => $customer, 'customer_search' => Text::fold($customer), 'city' => $city,
                'total' => $total, 'active' => $active, 'created_at' => $created, 'note' => $note,
            ]);
        }
    }
}
