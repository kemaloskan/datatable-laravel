<?php

declare(strict_types=1);

namespace Acunsoft\DataTable\Laravel\Tests;

use Acunsoft\DataTable\Laravel\Column;
use Acunsoft\DataTable\Laravel\DataTableServiceProvider;
use Acunsoft\DataTable\Laravel\Import\SheetReader;
use Acunsoft\DataTable\Laravel\TableExport;
use Acunsoft\DataTable\Laravel\TableQuery;
use Acunsoft\DataTable\Laravel\Tests\Fixtures\Order;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Route;

final class TableExportTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [DataTableServiceProvider::class];
    }

    /** @return list<Column> */
    private function columns(): array
    {
        return [
            Column::make('customer')->header('Müşteri')->sortable()->filterable()->searchUsing('customer_search'),
            Column::make('total')->header('Tutar')->type('decimal')->sortable()->filterable(),
            Column::make('active')->header('Aktif')->type('boolean')->filterable(),
            Column::make('created')->column('orders.created_at')->header('Tarih')->type('datetime')->sortable(),
            Column::make('note')->header('Not')->format(fn ($value) => $value === null ? '—' : strtoupper((string) $value)),
        ];
    }

    private function csv(\Symfony\Component\HttpFoundation\Response $response): string
    {
        ob_start();
        $response->sendContent();

        return (string) ob_get_clean();
    }

    public function test_csv_streams_every_matching_row_with_typed_values_and_a_formula_guard(): void
    {
        Order::query()->whereKey(2)->update(['customer' => '=HYPERLINK("http://x")', 'customer_search' => 'hyperlink']);
        $response = TableExport::for(Order::query())->columns($this->columns())
            ->query(TableQuery::fromArray(['sort' => 'total:desc', 'filters' => [['field' => 'total', 'op' => 'gt', 'value' => '60']], 'pageSize' => 1]))
            ->csv('siparişler.csv', ';');
        self::assertSame('text/csv; charset=UTF-8', $response->headers->get('Content-Type'));
        self::assertSame('attachment; filename="siparisler.csv"; filename*=UTF-8\'\'sipari%C5%9Fler.csv', $response->headers->get('Content-Disposition'));
        $lines = explode("\r\n", $this->csv($response));
        self::assertSame("\u{FEFF}Müşteri;Tutar;Aktif;Tarih;Not", $lines[0]);
        self::assertSame(['Iğdır Ticaret', '5000.00', 'false', '2026-10-24 09:00:00'], array_slice(str_getcsv($lines[1], ';', '"', ''), 0, 4));
        self::assertSame(['Ayşe Yılmaz', '120.50', 'true', '2026-10-23 14:05:00', 'VIP'], str_getcsv($lines[2], ';', '"', ''));
        self::assertSame("'=HYPERLINK(\"http://x\")", str_getcsv($lines[4], ';', '"', '')[0]); // all pages, guarded
        self::assertSame('—', str_getcsv($lines[3], ';', '"', '')[4]); // Can: null note through the formatter
        self::assertCount(6, $lines); // header + 4 rows + final newline; 50.00 is filtered out
    }

    public function test_xlsx_round_trips_through_the_reader(): void
    {
        $response = TableExport::for(Order::query())->columns($this->columns())->xlsx('orders.xlsx', 'Siparişler');
        $path = $response->getFile()->getPathname();
        $sheet = (new SheetReader())->read($path, 'xlsx');
        self::assertSame(['Müşteri', 'Tutar', 'Aktif', 'Tarih', 'Not'], $sheet->headers);
        self::assertSame('Ayşe Yılmaz', $sheet->rows[0][0]);
        self::assertSame(120.5, $sheet->rows[0][1]);
        self::assertTrue($sheet->rows[0][2]);
        self::assertTrue(CarbonImmutable::parse('2026-10-23 14:05:00', 'Europe/Istanbul')->equalTo($sheet->rows[0][3]));
        self::assertNull($sheet->rows[4][3]); // Deniz: no date
        self::assertSame('—', $sheet->rows[2][4]);
        self::assertStringContainsString('attachment; filename=orders.xlsx', (string) $response->headers->get('Content-Disposition'));
        $zip = new \ZipArchive();
        $zip->open($path);
        self::assertStringContainsString('<sheet name="Siparişler"', $zip->getFromName('xl/workbook.xml'));
        self::assertStringContainsString('<c r="B2" s="5"><v>120.50</v></c>', $zip->getFromName('xl/worksheets/sheet1.xml'));
        $zip->close();
    }

    public function test_the_query_is_checked_and_large_exports_are_refused_with_422(): void
    {
        Route::get('/export', fn () => TableExport::for(Order::query())->columns($this->columns())->limit(3)->csv());
        Route::get('/export-bad', fn () => TableExport::for(Order::query())->columns($this->columns())
            ->query(TableQuery::fromArray(['sort' => 'secret:asc']))->csv());
        $this->getJson('/export')->assertStatus(422)->assertJsonPath('errors.export', 'The export has more than 3 rows; narrow the filters.');
        $this->getJson('/export-bad')->assertStatus(422)->assertJsonPath('errors.sort', 'Unknown column [secret].');
    }

    public function test_options_are_validated(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        TableExport::for(Order::query())->columns($this->columns())->csv('x.csv', '|');
    }
}
