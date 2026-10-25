<?php

declare(strict_types=1);

namespace Acunsoft\DataTable\Laravel\Tests;

use Acunsoft\DataTable\Laravel\Column;
use Acunsoft\DataTable\Laravel\DataTableServiceProvider;
use Acunsoft\DataTable\Laravel\Import\ImportContext;
use Acunsoft\DataTable\Laravel\Import\TableImport;
use Acunsoft\DataTable\Laravel\Support\Text;
use Acunsoft\DataTable\Laravel\Tests\Fixtures\Order;
use Illuminate\Auth\GenericUser;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

/**
 * Measures a TableImport (upload, then the import with the default save()) on
 * SQLite. Skipped unless ADT_IMPORT_BENCH is set:
 *   ADT_IMPORT_BENCH=100000 vendor/bin/phpunit --filter ImportBenchTest
 */
final class ImportBenchTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [DataTableServiceProvider::class];
    }

    protected function defineRoutes($router): void
    {
        Route::middleware('web')->group(fn () => Route::acunImport('bench/import', BenchImport::class));
    }

    public function test_import_speed_and_memory(): void
    {
        $rows = (int) getenv('ADT_IMPORT_BENCH');
        if ($rows <= 0) {
            self::markTestSkipped('Set ADT_IMPORT_BENCH to a number of rows.');
        }
        Storage::fake('local');
        // As an app would have it: the import key is indexed.
        \Illuminate\Support\Facades\Schema::table('orders', fn (\Illuminate\Database\Schema\Blueprint $table) => $table->index('customer'));
        $this->actingAs(new GenericUser(['id' => 1]));
        $names = ['Ayşe Yılmaz', 'Ali Kaya', 'İrem Şahin', 'Çağrı Öztürk', 'Zeynep Doğan', 'Ümit Kılıç'];
        $lines = ['Customer,City,Total'];
        for ($i = 0; $i < $rows; $i++) {
            $lines[] = $names[$i % 6]." {$i},".['İstanbul', 'Ankara', 'İzmir'][$i % 3].','.($i % 1000 === 999 ? 'abc' : (($i * 7919) % 100000).'.'.str_pad((string) ($i % 100), 2, '0', STR_PAD_LEFT));
        }
        $file = UploadedFile::fake()->createWithContent('orders.csv', implode("\n", $lines));
        unset($lines);
        gc_collect_cycles();
        memory_reset_peak_usage();
        $base = memory_get_usage(true);

        $started = hrtime(true);
        $id = $this->post('/bench/import', ['file' => $file], ['Accept' => 'application/json'])->assertCreated()->json('id');
        $upload = (hrtime(true) - $started) / 1e9;
        $started = hrtime(true);
        $done = $this->postJson("/bench/import/{$id}/start", ['mapping' => ['customer', 'city', 'total'], 'mode' => getenv('ADT_IMPORT_MODE') ?: 'upsert'])->assertOk()->json();
        $import = (hrtime(true) - $started) / 1e9;

        $result = [
            'rows' => $rows, 'php' => PHP_VERSION, 'mode' => getenv('ADT_IMPORT_MODE') ?: 'upsert',
            'uploadSeconds' => round($upload, 3), 'importSeconds' => round($import, 3), 'rowsPerSecond' => (int) round($rows / $import),
            'peakMemoryMB' => (int) round(memory_get_peak_usage(true) / 1048576), 'aboveBaselineMB' => (int) round((memory_get_peak_usage(true) - $base) / 1048576),
            'status' => $done['status'], 'inserted' => $done['inserted'], 'failed' => $done['failed'], 'stored' => Order::query()->count() - 5,
        ];
        fwrite(STDERR, json_encode($result, JSON_PRETTY_PRINT)."\n");
        if ($path = getenv('ADT_IMPORT_JSON')) {
            file_put_contents($path, json_encode($result, JSON_PRETTY_PRINT)."\n");
        }
        self::assertSame('done', $done['status']);
    }
}

final class BenchImport extends TableImport
{
    protected function columns(): array
    {
        return [
            Column::make('customer')->header('Customer'),
            Column::make('city')->header('City'),
            Column::make('total')->header('Total')->type('decimal'),
        ];
    }

    public function authorize(Request $request): bool
    {
        return true;
    }

    protected function required(): array
    {
        return ['customer'];
    }

    protected function importKey(): ?string
    {
        return 'customer';
    }

    protected function maxKilobytes(): int
    {
        return 1024 * 1024;
    }

    protected function maxRows(): int
    {
        return 2_000_000;
    }

    protected function query(ImportContext $context): Builder
    {
        return Order::query();
    }

    protected function attributes(array $values, ImportContext $context): array
    {
        return parent::attributes($values, $context) + ['customer_search' => Text::fold((string) $values['customer'])];
    }
}
