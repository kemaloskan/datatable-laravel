<?php

declare(strict_types=1);

namespace Acunsoft\DataTable\Laravel\Tests;

use Acunsoft\DataTable\Laravel\DataTableServiceProvider;
use Acunsoft\DataTable\Laravel\Support\PageItems;
use Acunsoft\DataTable\Laravel\Tests\Fixtures\LayoutOrdersTable;
use Acunsoft\DataTable\Laravel\Tests\Fixtures\Order;
use Acunsoft\DataTable\Laravel\Tests\Fixtures\OrdersImportTable;
use Acunsoft\DataTable\Laravel\Tests\Fixtures\OrdersTable;
use Illuminate\Support\Facades\View;
use InvalidArgumentException;
use Livewire\Livewire;
use Livewire\LivewireServiceProvider;

final class LivewireLayoutTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [LivewireServiceProvider::class, DataTableServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();
        View::addNamespace('fixtures', __DIR__.'/Fixtures/views');
    }

    public function test_page_items_match_the_javascript_pager(): void
    {
        self::assertSame([1, 2, 3, 4, 5, 6, 7], PageItems::make(3, 7));
        self::assertSame([1, 2, 3, 4, 5, null, 20], PageItems::make(4, 20));
        self::assertSame([1, null, 4, 5, 6, null, 20], PageItems::make(5, 20));
        self::assertSame([1, null, 16, 17, 18, 19, 20], PageItems::make(20, 20));
        self::assertSame([1, 2, 3, 4, 5, null, 8], PageItems::make(4, 8));
        self::assertSame([null, 49, 50, 51, null], PageItems::make(50, 100, 1, 0));
        self::assertSame([1, 2, 3], PageItems::make(9, 3));
        for ($page = 1; $page <= 20; $page++) {
            self::assertCount(7, PageItems::make($page, 20));
        }
        $this->expectException(InvalidArgumentException::class);
        PageItems::make(0, 3);
    }

    public function test_the_default_layout_puts_size_and_search_on_top_and_info_and_pages_below(): void
    {
        $html = Livewire::test(OrdersTable::class)->html();
        $top = strpos($html, 'adt-bar adt-top');
        $table = strpos($html, '<table class="adt-table"');
        $bottom = strpos($html, 'adt-bar adt-bottom');
        self::assertTrue($top < strpos($html, 'class="adt-length"') && strpos($html, 'class="adt-length"') < $table);
        self::assertTrue($top < strpos($html, 'class="adt-search"') && strpos($html, 'class="adt-search"') < $table);
        self::assertTrue($bottom > $table && strpos($html, 'class="adt-info"') > $bottom && strpos($html, 'class="adt-paging"') > $bottom);
        self::assertStringNotContainsString('adt-menu', $html); // no export, print or import: no tools menu
    }

    public function test_a_custom_layout_moves_features_and_includes_your_own_views(): void
    {
        $table = Livewire::test(LayoutOrdersTable::class)->set('pageSize', 1);
        $html = $table->html();
        self::assertStringNotContainsString('class="adt-length"', $html);
        self::assertStringContainsString('<span class="custom-feature">5 custom</span>', $html); // your view sees $rows
        self::assertTrue(strpos($html, 'class="adt-paging"') < strpos($html, 'class="adt-info"'));
        self::assertStringContainsString('Total: 5 orders, 1 to 1</div>', $html); // texts(): this table's own words
        self::assertStringContainsString('placeholder="Find an order…"', $html);
        self::assertStringContainsString('aria-label="Previous"', $html); // the other texts stay
        $bottomStart = strstr(strstr($html, 'adt-bar adt-bottom'), '<div class="adt-bar-end">', true);
        self::assertStringNotContainsString('adt-info', $bottomStart); // the emptied slot
        self::assertStringNotContainsString('adt-paging', $bottomStart);

        // siblings 0 over 9 pages: 1 … 5 … 9, plus first and last buttons
        foreach (range(1, 4) as $n) {
            Order::query()->insert(['customer' => "Extra $n", 'customer_search' => "extra $n", 'total' => '1.00']);
        }
        $table->call('gotoPage', 5);
        $html = $table->html();
        self::assertSame(['1', '…', '5', '…', '9'], $this->pagerItems($html));
        self::assertStringContainsString('wire:click="gotoPage(9)"', $html);
        self::assertStringContainsString('adt-first', $html);
        self::assertStringContainsString('adt-last', $html);
        $table->call('gotoPage', 9);
        self::assertMatchesRegularExpression('/adt-last" aria-label="Last"\s+aria-disabled="true"/', $table->html());
    }

    public function test_the_tools_menu_holds_export_print_and_import(): void
    {
        $html = Livewire::test(LayoutOrdersTable::class)->html();
        self::assertStringContainsString('class="adt-columns adt-menu"', $html);
        self::assertStringContainsString('window.print()', $html);
        self::assertStringNotContainsString('adt-export-button', $html); // no export formats declared

        $import = Livewire::test(OrdersImportTable::class);
        $html = $import->html();
        self::assertStringContainsString('wire:click="export(\'xlsx\')"', $html);
        self::assertStringNotContainsString('window.print()', $html); // printable() is off by default
        self::assertMatchesRegularExpression('/<label class="adt-menu-item" for="(adt-import-[^"]+)"/', $html);
        preg_match('/<label class="adt-menu-item" for="(adt-import-[^"]+)"/', $html, $match);
        self::assertStringContainsString('id="'.$match[1].'"', $html); // the label opens the hidden file input
        self::assertStringContainsString('adt-import adt-import--idle', $html); // panel closed until a file is chosen
    }

    /** @return list<string> */
    private function pagerItems(string $html): array
    {
        preg_match('/<span class="adt-page-numbers">(.*?)<\/span>\s*<button/s', $html, $numbers);
        preg_match_all('/>(\d+|…)<\/(?:button|span)>/u', $numbers[1], $items);

        return $items[1];
    }
}
