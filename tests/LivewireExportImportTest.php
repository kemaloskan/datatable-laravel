<?php

declare(strict_types=1);

namespace Acunsoft\DataTable\Laravel\Tests;

use Acunsoft\DataTable\Laravel\DataTableServiceProvider;
use Acunsoft\DataTable\Laravel\Tests\Fixtures\Order;
use Acunsoft\DataTable\Laravel\Tests\Fixtures\OrdersImportTable;
use Acunsoft\DataTable\Laravel\Tests\Fixtures\OrdersTable;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Livewire\LivewireServiceProvider;

final class LivewireExportImportTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [LivewireServiceProvider::class, DataTableServiceProvider::class];
    }

    public function test_export_buttons_download_the_current_view_or_the_selection(): void
    {
        $table = Livewire::test(OrdersImportTable::class)->set('search', 'ayse');
        self::assertStringContainsString('wire:click="export(\'xlsx\')"', $table->html());
        $table->call('export', 'csv')->assertFileDownloaded('export.csv');
        $table->call('export', 'xlsx')->assertFileDownloaded('export.xlsx');

        $selected = Livewire::test(OrdersImportTable::class)->call('toggleRow', '4')->call('export', 'csv');
        $selected->assertFileDownloaded('export.csv');
        $content = base64_decode($selected->effects['download']['content']);
        self::assertStringContainsString('Iğdır Ticaret', $content);
        self::assertStringNotContainsString('Ayşe', $content);
    }

    public function test_export_is_off_unless_formats_are_declared(): void
    {
        $table = Livewire::test(OrdersTable::class)->call('export', 'csv');
        self::assertArrayNotHasKey('download', $table->effects);
        self::assertStringNotContainsString('adt-export', $table->html());
        Livewire::test(OrdersImportTable::class)->call('export', 'pdf')->assertNoFileDownloaded();
    }

    public function test_an_import_stores_valid_rows_and_lists_problems(): void
    {
        app()->setLocale('tr');
        $file = UploadedFile::fake()->createWithContent('orders.csv', "Müşteri;Amount;Aktif;Not\nZeynep;1.234,50;evet;x\nYusuf;abc;hayır;y\n;5;1;z\n");
        $table = Livewire::test(OrdersImportTable::class)->set('importFile', $file)->call('import')->assertHasNoErrors();
        $report = $table->get('importReport');
        self::assertSame(1, $report['imported']);
        self::assertSame(2, $report['problems']);
        self::assertSame(['Not'], $report['ignored']);
        self::assertSame('1234.50', Order::query()->where('customer', 'Zeynep')->value('total'));
        $html = $table->html();
        self::assertStringContainsString('1 satır içe aktarıldı.', $html);
        self::assertStringContainsString('Satır 3 · Tutar · “abc”: geçerli bir ondalık sayı değeri değil', $html);
        self::assertStringContainsString('Satır 4 · Müşteri: zorunlu değer boş', $html);
    }

    public function test_an_import_is_all_or_nothing_and_checks_the_file(): void
    {
        $file = UploadedFile::fake()->createWithContent('orders.csv', "Müşteri,Amount\nA,1\nB,2\n");
        try {
            Livewire::test(OrdersImportTable::class, ['failOnLine' => 3])->set('importFile', $file)->call('import');
            self::fail('the exception should reach the caller');
        } catch (\RuntimeException $error) {
            self::assertSame('cannot store line 3', $error->getMessage());
        }
        self::assertSame(0, Order::query()->whereIn('customer', ['A', 'B'])->count()); // rolled back

        Livewire::test(OrdersImportTable::class)->set('importFile', UploadedFile::fake()->createWithContent('x.pdf', 'a'))
            ->call('import')->assertHasErrors(['importFile']);
        Livewire::test(OrdersImportTable::class)->set('importFile', UploadedFile::fake()->createWithContent('x.xlsx', 'not a zip'))
            ->call('import')->assertHasErrors(['importFile']);
    }
}
