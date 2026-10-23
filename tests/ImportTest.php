<?php

declare(strict_types=1);

namespace Acunsoft\DataTable\Laravel\Tests;

use Acunsoft\DataTable\Laravel\Column;
use Acunsoft\DataTable\Laravel\DataTableServiceProvider;
use Acunsoft\DataTable\Laravel\Import\ImportException;
use Acunsoft\DataTable\Laravel\Import\RowImporter;
use Acunsoft\DataTable\Laravel\Import\Sheet;
use Acunsoft\DataTable\Laravel\Import\SheetReader;
use Carbon\CarbonImmutable;

final class ImportTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [DataTableServiceProvider::class];
    }

    private function file(string $content, string $extension = 'csv'): string
    {
        $path = tempnam(sys_get_temp_dir(), 'adt-test-').'.'.$extension;
        file_put_contents($path, $content);
        $this->beforeApplicationDestroyed(fn () => @unlink($path));

        return $path;
    }

    /** @param array<string, string> $parts */
    private function xlsx(array $parts): string
    {
        $path = $this->file('', 'xlsx');
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::OVERWRITE);
        foreach ($parts as $name => $xml) {
            $zip->addFromString($name, $xml);
        }
        $zip->close();

        return $path;
    }

    /** @return list<Column> */
    private function columns(): array
    {
        return [
            Column::make('customer')->header('Müşteri'),
            Column::make('total')->header('Tutar')->type('decimal'),
            Column::make('day')->header('Tarih')->type('date'),
            Column::make('at')->header('Zaman')->type('datetime'),
            Column::make('active')->header('Aktif')->type('boolean'),
            Column::make('qty')->header('Adet')->type('number'),
        ];
    }

    public function test_csv_reading_detects_delimiters_quotes_bom_and_turkish_excel_encoding(): void
    {
        $reader = new SheetReader();
        $sheet = $reader->read($this->file("\u{FEFF}Müşteri;Not\r\n\"Ayşe; A.\";\"satır1\nsatır2\"\r\n\r\nAli;\"\"\"x\"\"\"\r\n"));
        self::assertSame(['Müşteri', 'Not'], $sheet->headers);
        self::assertSame([['Ayşe; A.', "satır1\nsatır2"], ['Ali', '"x"']], $sheet->rows);

        $windows1254 = mb_convert_encoding("Müşteri,Şehir\nİğdır,Iğdır\n", 'Windows-1254', 'UTF-8');
        self::assertSame([['İğdır', 'Iğdır']], $reader->read($this->file($windows1254))->rows);
        self::assertSame([['1', null]], $reader->read($this->file("a\tb\n1\n"))->rows); // tab, short row padded
    }

    public function test_xlsx_reading_matches_the_browser_package_fixtures(): void
    {
        $sheet = (new SheetReader())->read(__DIR__.'/Fixtures/openpyxl.xlsx');
        self::assertSame(['Müşteri', 'Tutar', 'Tarih', 'Zaman', 'Aktif', 'Not'], $sheet->headers);
        self::assertCount(3, $sheet->rows); // the empty row is skipped
        [$name, $total, $day, $at, $active, $note] = $sheet->rows[0];
        self::assertSame(['Ayşe Yılmaz', 1250.5, true, 'VIP <müşteri> & "özel"'], [$name, $total, $active, $note]);
        self::assertSame('2026-10-23 00:00:00', $day->format('Y-m-d H:i:s'));
        self::assertSame('2026-10-23 14:05:00', $at->format('Y-m-d H:i:s'));
        self::assertSame("satır1\nsatır2", $sheet->rows[2][5]);
        self::assertSame([['A-1']], (new SheetReader())->read(__DIR__.'/Fixtures/openpyxl.xlsx', null, 'İkinci')->rows);
        self::assertSame('2026-10-23', (new SheetReader())->read(__DIR__.'/Fixtures/mac1904.xlsx')->rows[0][0]->format('Y-m-d'));
    }

    public function test_xlsx_shared_strings_rich_text_errors_and_formula_results(): void
    {
        $main = 'xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"';
        $path = $this->xlsx([
            'xl/workbook.xml' => "<workbook {$main} xmlns:r=\"r\"><sheets><sheet name=\"S\" sheetId=\"1\" r:id=\"rId1\"/></sheets></workbook>",
            'xl/_rels/workbook.xml.rels' => '<Relationships><Relationship Id="rId1" Type="x/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="x/sharedStrings" Target="sharedStrings.xml"/></Relationships>',
            'xl/sharedStrings.xml' => "<sst {$main}><si><t>Müşteri</t></si><si><r><t>Tu</t></r><r><t>tar</t></r></si><si><t>Ayşe &amp; Co</t><rPh><t>x</t></rPh></si></sst>",
            'xl/worksheets/sheet1.xml' => "<worksheet {$main}><sheetData><row r=\"1\"><c r=\"A1\" t=\"s\"><v>0</v></c><c r=\"B1\" t=\"s\"><v>1</v></c></row>"
                .'<row r="2"><c r="A2" t="s"><v>2</v></c><c r="B2" t="e"><v>#DIV/0!</v></c></row><row r="3"><c r="A3" t="str"><f>A1</f><v>Ali</v></c><c r="B3"><f>1+1</f><v>2</v></c></row></sheetData></worksheet>',
        ]);
        $sheet = (new SheetReader())->read($path);
        self::assertSame(['Müşteri', 'Tutar'], $sheet->headers);
        self::assertSame([['Ayşe & Co', null], ['Ali', 2]], $sheet->rows);
    }

    public function test_limits_and_bad_files(): void
    {
        $reader = new SheetReader(maxRows: 2, maxColumns: 2, maxBytes: 2048, maxEntryBytes: 1024);
        foreach ([
            fn () => $reader->read($this->file("a\n1\n2\n3")),
            fn () => $reader->read($this->file('a,b,c')),
            fn () => $reader->read($this->file(str_repeat('x', 3000))),
            fn () => $reader->read($this->file('not a zip', 'xlsx')),
            fn () => $reader->read($this->file('a', 'exe')),
            fn () => $reader->read($this->xlsx(['xl/workbook.xml' => '<workbook>'.str_repeat(' ', 5000).'</workbook>'])), // inflates past the limit
            fn () => $reader->read($this->xlsx(['other.xml' => '<x/>'])),
        ] as $index => $attempt) {
            try {
                $attempt();
                self::fail("attempt {$index} should fail");
            } catch (ImportException $error) {
                self::assertNotSame('', $error->getMessage());
            }
        }
        app()->setLocale('tr');
        $this->expectExceptionMessage('Yalnızca .csv ve .xlsx dosyaları içe aktarılabilir.');
        $reader->read($this->file('a', 'pdf'));
    }

    public function test_rows_are_typed_with_turkish_formats_and_problems_are_reported_by_line(): void
    {
        $sheet = (new SheetReader())->read($this->file(
            "MUSTERI;tutar;TARİH;Zaman;aktif;Adet;Fazla\n".
            "Ayşe;1.234,50;23.10.2026;23.10.2026 14:05;Evet;1.000;x\n".
            "Ali;1,5;31.02.2026;2026-10-22 09:00;belki;abc;y\n".
            ";0,10;2026-10-22;;hayır;;z\n"
        ));
        $result = (new RowImporter($this->columns(), required: ['customer'], decimalSeparator: ','))->import($sheet);
        self::assertSame(['Fazla'], $result->ignoredHeaders);
        self::assertCount(1, $result->rows);
        $values = $result->rows[0]['values'];
        self::assertSame(['Ayşe', '1234.50', true, 1000], [$values['customer'], $values['total'], $values['active'], $values['qty']]);
        self::assertTrue(CarbonImmutable::create(2026, 10, 23, 0, 0, 0, 'Europe/Istanbul')->equalTo($values['day']));
        self::assertTrue(CarbonImmutable::create(2026, 10, 23, 14, 5, 0, 'Europe/Istanbul')->equalTo($values['at']));
        self::assertSame([
            [3, 'day', '31.02.2026'], [3, 'active', 'belki'], [3, 'qty', 'abc'], [4, 'customer', ''],
        ], array_map(fn ($e) => [$e['line'], $e['column'], $e['value']], $result->errors));
        self::assertSame('not a valid date value', $result->errors[0]['message']);
        self::assertSame('1.5', (new RowImporter($this->columns(), decimalSeparator: ','))->import(new Sheet(['Tutar'], [['1,5']]))->rows[0]['values']['total']);
        self::assertTrue((new RowImporter($this->columns()))->import(new Sheet(['Tutar'], [['1,5']]))->hasErrors()); // "." separator: not 15
    }

    public function test_missing_required_columns_stop_the_import_in_the_users_language(): void
    {
        app()->setLocale('tr');
        $result = (new RowImporter($this->columns(), required: ['total']))->import(new Sheet(['Müşteri'], [['Ayşe']]));
        self::assertSame([], $result->rows);
        self::assertSame([['line' => 1, 'column' => 'total', 'value' => '', 'message' => 'zorunlu kolon eksik']], $result->errors);
        $this->expectException(\InvalidArgumentException::class);
        new RowImporter($this->columns(), decimalSeparator: ';');
    }
}
