<?php

declare(strict_types=1);

namespace Acunsoft\DataTable\Laravel\Tests;

use Acunsoft\DataTable\Laravel\Column;
use Acunsoft\DataTable\Laravel\DataTableServiceProvider;
use Acunsoft\DataTable\Laravel\Support\Format;
use Carbon\CarbonImmutable;

final class FormatTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [DataTableServiceProvider::class];
    }

    public function test_formats_typed_values_in_the_locale(): void
    {
        self::assertSame('1,234.5', Format::value(1234.5, 'number', 'en'));
        self::assertSame('1.234,50', Format::value('1234.50', 'decimal', 'tr')); // the written scale is kept
        self::assertSame('0,1', Format::value('0.1', 'decimal', 'tr'));
        self::assertSame('23 Eki 2026', Format::value('2026-10-23', 'date', 'tr'));
        self::assertMatchesRegularExpression('/^Oct 23, 2026.+2:05\s?PM$/u', Format::value(CarbonImmutable::parse('2026-10-23 14:05'), 'datetime', 'en'));
    }

    public function test_booleans_missing_and_unreadable_values(): void
    {
        self::assertSame('Yes', Format::value(true, null));
        self::assertSame('No', Format::value('0', 'boolean'));
        self::assertSame('maybe', Format::value('maybe', 'boolean'));
        self::assertSame('', Format::value(null, 'number'));
        self::assertSame('', Format::value(NAN, 'number'));
        self::assertSame('n/a', Format::value('n/a', 'number'));
        self::assertSame('not a date', Format::value('not a date', 'date'));
        self::assertSame('<b>', Format::value('<b>', null)); // plain text: Blade escapes it
    }

    public function test_a_column_formatter_must_return_text(): void
    {
        $row = ['total' => 5];
        self::assertSame('5 ₺', Format::cell(Column::make('total')->format(fn ($value) => "{$value} ₺"), $row));
        $this->expectException(\UnexpectedValueException::class);
        Format::cell(Column::make('total')->format(fn ($value) => $value), $row);
    }
}
