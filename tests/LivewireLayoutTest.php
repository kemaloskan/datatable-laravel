<?php

declare(strict_types=1);

namespace Acunsoft\DataTable\Laravel\Tests;

use Acunsoft\DataTable\Laravel\DataTableServiceProvider;
use Acunsoft\DataTable\Laravel\Support\PageItems;
use Acunsoft\DataTable\Laravel\Tests\Fixtures\LayoutOrdersTable;
use Acunsoft\DataTable\Laravel\Tests\Fixtures\Order;
use Acunsoft\DataTable\Laravel\Tests\Fixtures\PageSizeOrdersTable;
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
        self::assertStringNotContainsString('class="adt-columns adt-menu"', $html); // no export, print or import: no tools menu
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
        self::assertStringContainsString('<span class="adt-summary-text">Tools</span>', $html); // the name stays when phones show the icon
        self::assertStringContainsString('--adt-menu-shift', $html); // the open menu is kept inside the window
        self::assertStringContainsString('adt-print-button', $html);
        self::assertStringContainsString('view.print()', $html); // only the table, in a hidden frame
        self::assertStringNotContainsString('window.print()', $html); // not the whole page
        self::assertStringNotContainsString('adt-export-button', $html); // no export formats declared

        $import = Livewire::test(OrdersImportTable::class);
        $html = $import->html();
        self::assertStringContainsString('wire:click="export(\'xlsx\')"', $html);
        self::assertStringNotContainsString('adt-print-button', $html); // printable() is off by default
        self::assertMatchesRegularExpression('/<label class="adt-menu-item" for="(adt-import-[^"]+)"/', $html);
        preg_match('/<label class="adt-menu-item" for="(adt-import-[^"]+)"/', $html, $match);
        self::assertStringContainsString('id="'.$match[1].'"', $html); // the label opens the hidden file input
        self::assertStringContainsString('adt-import adt-import--idle', $html); // panel closed until a file is chosen
    }

    public function test_a_column_view_renders_the_cell_and_numbers_align_right(): void
    {
        Order::query()->whereKey(1)->update(['customer' => '<i>x</i>']);
        $html = Livewire::test(LayoutOrdersTable::class)->html();
        self::assertStringContainsString('<b class="pill" data-raw="120.50">120.50</b>', $html);
        self::assertMatchesRegularExpression('/<td data-label="Total"\s+class="adt-num"\s*>/', $html);
        self::assertStringContainsString('<th scope="col" class="adt-th adt-num"', $html);
        self::assertStringContainsString('&lt;i&gt;x&lt;/i&gt;', $html); // plain cells stay escaped
    }

    public function test_page_size_options_and_default_are_configurable(): void
    {
        $table = Livewire::test(PageSizeOrdersTable::class);
        self::assertSame(3, $table->instance()->rows->perPage()); // defaultPageSize()
        self::assertMatchesRegularExpression('/<option value="3"\s+selected>3<\/option>/', $table->html());
        self::assertSame(['2', '3', '25'], $this->sizeOptions($table->html()));
        $table->set('pageSize', 25);
        self::assertSame(25, $table->instance()->rows->perPage());

        self::assertSame(2, Livewire::withQueryParams(['pageSize' => '2'])->test(PageSizeOrdersTable::class)->instance()->rows->perPage());
        self::assertSame(3, Livewire::withQueryParams(['pageSize' => '7'])->test(PageSizeOrdersTable::class)->instance()->rows->perPage()); // not an option
    }

    /** @return list<string> */
    private function sizeOptions(string $html): array
    {
        preg_match('/<select class="adt-control" wire:model.live="pageSize">(.*?)<\/select>/s', $html, $select);
        preg_match_all('/<option value="(\d+)"/', $select[1], $values);

        return $values[1];
    }

    public function test_row_actions_with_icons_danger_and_a_more_menu(): void
    {
        $table = Livewire::test(LayoutOrdersTable::class);
        $html = preg_replace('/<!--.*?-->/s', '', $table->html());
        self::assertMatchesRegularExpression('/<button type="button" class="adt-action adt-action--icon" data-action="view"\s+aria-label="View: Ayşe Yılmaz"\s+title="View"/', $html);
        self::assertMatchesRegularExpression('/<summary class="adt-action adt-action--icon" aria-label="More actions: Ayşe Yılmaz"/', $html);
        self::assertMatchesRegularExpression('/<button type="button" class="adt-menu-item adt-menu-item--danger" data-action="remove"/', $html);
        self::assertStringContainsString('wire:confirm="Sure?"', $html);
        $table->call('runRowAction', 'remove', '1');
        self::assertNull(Order::query()->find(1));
        $this->expectException(\InvalidArgumentException::class);
        \Acunsoft\DataTable\Laravel\Action::make('x')->icon('"><script>');
    }

    /** @return list<string> */
    private function pagerItems(string $html): array
    {
        preg_match('/<span class="adt-page-numbers">(.*?)<\/span>\s*<button/s', $html, $numbers);
        preg_match_all('/>(\d+|…)<\/(?:button|span)>/u', $numbers[1], $items);

        return $items[1];
    }
}
