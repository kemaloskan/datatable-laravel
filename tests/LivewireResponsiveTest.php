<?php

declare(strict_types=1);

namespace Acunsoft\DataTable\Laravel\Tests;

use Acunsoft\DataTable\Laravel\Column;
use Acunsoft\DataTable\Laravel\DataTableServiceProvider;
use Acunsoft\DataTable\Laravel\Tests\Fixtures\Order;
use Acunsoft\DataTable\Laravel\Tests\Fixtures\ResponsiveOrdersTable;
use Illuminate\Support\Facades\View;
use InvalidArgumentException;
use Livewire\Livewire;
use Livewire\LivewireServiceProvider;

final class LivewireResponsiveTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [LivewireServiceProvider::class, DataTableServiceProvider::class];
    }

    public function test_cards_and_scroll_only_mark_the_mode(): void
    {
        $html = Livewire::test(ResponsiveOrdersTable::class)->html();
        self::assertStringContainsString('adt-table-wrap adt-responsive--cards', $html);
        self::assertStringNotContainsString('adt-expand', $html);
        self::assertStringNotContainsString('adt-col--p', $html);
        $html = Livewire::test(ResponsiveOrdersTable::class, ['mode' => 'scroll'])->html();
        self::assertStringContainsString('adt-responsive--scroll', $html);
        $html = Livewire::test(ResponsiveOrdersTable::class, ['mode' => 'sideways'])->html();
        self::assertStringContainsString('adt-responsive--cards', $html); // unknown: the default
    }

    public function test_collapse_marks_priorities_and_renders_a_details_row(): void
    {
        $html = Livewire::test(ResponsiveOrdersTable::class, ['mode' => 'collapse'])->html();
        self::assertStringContainsString('class="adt-table adt-has-p2 adt-has-p3"', $html);
        preg_match_all('/<th scope="col" class="([^"]+)"/', $html, $headers);
        self::assertSame(['adt-th adt-col--p1', 'adt-th adt-col--p2', 'adt-th adt-col--p1 adt-num', 'adt-th adt-col--p3', 'adt-th adt-actions adt-col--p2'], $headers[1]);
        self::assertMatchesRegularExpression('/<button type="button" class="adt-expand" aria-label="Details of Ayşe Yılmaz"\s+aria-expanded="false" aria-controls="(adt-[^"]+-detail-0)"/', $html);
        preg_match('/aria-controls="(adt-[^"]+-detail-0)"/', $html, $id);
        $detail = substr($html, strpos($html, 'id="'.$id[1].'"'));
        $detail = preg_replace('/<!--.*?-->/s', '', substr($detail, 0, strpos($detail, '</tr>'))); // without Livewire's markers
        self::assertStringContainsString('x-show="open"', $detail);
        self::assertMatchesRegularExpression('/adt-detail-item adt-col--p2">\s*<dt>City<\/dt>\s*<dd>İstanbul<\/dd>/', $detail);
        self::assertMatchesRegularExpression('/adt-detail-item adt-col--p3">\s*<dt>Note<\/dt>\s*<dd>VIP<\/dd>/', $detail);
        self::assertStringNotContainsString('<dt>Customer</dt>', $detail); // priority 1 stays in the row
        self::assertStringContainsString('aria-label="Remove: Ayşe Yılmaz"', $detail);
    }

    public function test_modal_renders_a_labelled_dialog_with_every_value(): void
    {
        app()->setLocale('tr');
        $table = Livewire::test(ResponsiveOrdersTable::class, ['mode' => 'modal']);
        $html = $table->html();
        self::assertStringContainsString('aria-label="Ayşe Yılmaz ayrıntıları" aria-haspopup="dialog"', $html);
        $dialog = substr($html, strpos($html, '<dialog'));
        $dialog = substr($dialog, 0, strpos($dialog, '</dialog>'));
        self::assertStringContainsString('wire:ignore.self', $dialog);
        self::assertMatchesRegularExpression('/<h2 class="adt-dialog-title" id="(adt-[^"]+-title)">Ayşe Yılmaz ayrıntıları<\/h2>/', $dialog);
        preg_match_all('/<dt>([^<]+)<\/dt>/', $dialog, $labels);
        self::assertSame(['Customer', 'City', 'Total', 'Note', 'İşlemler'], $labels[1]);
        self::assertStringContainsString('aria-label="Kapat"', $dialog);
        $table->call('runRowAction', 'remove', '1');
        self::assertNull(Order::query()->find(1)); // actions in the dialog run like the row's
    }

    public function test_size_classes_and_priority_validation(): void
    {
        self::assertStringContainsString('class="adt adt-size--sm"', Livewire::test(ResponsiveOrdersTable::class, ['density' => 'sm'])->html());
        self::assertStringContainsString('class="adt adt-size--lg"', Livewire::test(ResponsiveOrdersTable::class, ['density' => 'lg'])->html());
        self::assertStringContainsString(' class="adt">', Livewire::test(ResponsiveOrdersTable::class)->html());
        self::assertSame(1, Column::make('a')->priorityAt(0));
        self::assertSame(2, Column::make('a')->priorityAt(3));
        self::assertSame(3, Column::make('a')->priority(3)->priorityAt(0));
        $this->expectException(InvalidArgumentException::class);
        Column::make('a')->priority(4);
    }

    public function test_sticky_header_and_fixed_height(): void
    {
        self::assertMatchesRegularExpression('/class="adt-table-wrap adt-responsive--cards adt-sticky-header"/', Livewire::test(ResponsiveOrdersTable::class, ['sticky' => true])->html());
        $html = Livewire::test(ResponsiveOrdersTable::class, ['height' => '60vh'])->html();
        self::assertStringContainsString('adt-scroll-y', $html);
        self::assertStringContainsString('style="--adt-scroll-y: 60vh"', $html);
        $html = Livewire::test(ResponsiveOrdersTable::class, ['height' => '1px;color:red'])->html();
        self::assertStringNotContainsString('adt-scroll-y', $html); // not a plain length: ignored
    }

    public function test_detail_view_opens_a_child_row_rendered_on_demand(): void
    {
        View::addNamespace('fixtures', __DIR__.'/Fixtures/views');
        $table = Livewire::test(ResponsiveOrdersTable::class, ['detail' => 'fixtures::order-lines']);
        $html = $table->html();
        self::assertStringContainsString('class="adt-table adt-has-detail"', $html);
        self::assertSame(substr_count($html, '<tr class="adt-row'), substr_count($html, 'class="adt-expand"')); // every row, in cards mode too
        self::assertStringNotContainsString('Lines of', $html); // nothing is rendered before a row opens
        self::assertStringContainsString('x-on:click="open = ! open; $wire.toggleDetail(&#039;1&#039;, open)"', $html);

        $html = $table->call('toggleDetail', '1', true)->html();
        self::assertStringContainsString('<p class="lines">Lines of Ayşe Yılmaz (#1)</p>', $html);
        self::assertStringContainsString('x-data="{ open: true }"', $html);
        self::assertMatchesRegularExpression('/aria-expanded="true" aria-controls="adt-[^"]+-detail-0"/', $html);
        self::assertSame(1, substr_count($html, 'Lines of'));

        $table->call('toggleDetail', '999'); // not on the page: ignored
        self::assertSame(['1'], $table->get('openDetails'));
        $html = $table->call('toggleDetail', '1')->html();
        self::assertStringNotContainsString('Lines of', $html);
    }

    public function test_detail_view_in_collapse_and_modal(): void
    {
        View::addNamespace('fixtures', __DIR__.'/Fixtures/views');
        $html = Livewire::test(ResponsiveOrdersTable::class, ['mode' => 'collapse', 'detail' => 'fixtures::order-lines'])
            ->call('toggleDetail', '1')->html();
        $detail = substr($html, strpos($html, 'class="adt-detail"'));
        $detail = preg_replace('/<!--.*?-->/s', '', substr($detail, 0, strpos($detail, '</tr>')));
        self::assertMatchesRegularExpression('/<dl class="adt-detail-list">.*<\/dl>\s*<div class="adt-detail-content"\s*>\s*<p class="lines">Lines of Ayşe Yılmaz/s', $detail);

        $html = Livewire::test(ResponsiveOrdersTable::class, ['mode' => 'modal', 'detail' => 'fixtures::order-lines'])->html();
        self::assertStringContainsString('showModal(); $wire.toggleDetail(&#039;1&#039;, true)', $html);
        $dialog = substr($html, strpos($html, '<dialog'));
        $dialog = substr($dialog, 0, strpos($dialog, '</dialog>'));
        self::assertMatchesRegularExpression('/<div class="adt-detail-content"\s+aria-busy="true"/', $dialog); // loads once opened
        self::assertStringNotContainsString('class="adt-detail"', $html); // no inline child row in modal mode
    }

    public function test_a_lazy_table_shows_a_skeleton_until_it_loads(): void
    {
        Livewire::component('lazy-orders', ResponsiveOrdersTable::class);
        $html = \Illuminate\Support\Facades\Blade::render('<livewire:lazy-orders lazy />');
        self::assertStringContainsString('x-intersect="$wire.__lazyLoad(', $html);
        self::assertStringContainsString('class="adt-table-wrap adt-skeleton-on" aria-busy="true"', $html);
        self::assertStringContainsString('<div class="adt-notice" role="status">Loading…</div>', $html);
        preg_match_all('/<th scope="col" class="([^"]+)">([^<]+)<\/th>/', $html, $headers);
        self::assertSame(['Customer', 'City', 'Total', 'Note'], $headers[2]);
        self::assertSame(10, substr_count($html, 'class="adt-row adt-skeleton-row"')); // a page of 25, capped at ten
        self::assertStringNotContainsString('Ayşe', $html); // no query ran
    }

    public function test_fixed_columns_measure_in_the_browser(): void
    {
        $html = Livewire::test(ResponsiveOrdersTable::class)->html();
        self::assertStringNotContainsString('adt-pin', $html);
        $html = Livewire::test(ResponsiveOrdersTable::class, ['fixed' => ['start' => 1, 'end' => 1]])->html();
        preg_match('/<div class="adt-table-wrap[^"]*"\s+x-data x-init="([^"]+)"/', $html, $init);
        self::assertNotEmpty($init, 'the wrapper has the Alpine pin script');
        self::assertStringContainsString('start = 1, end = 1;', $init[1]);
        $html = Livewire::test(ResponsiveOrdersTable::class, ['fixed' => ['start' => 99, 'end' => 'x']])->html();
        self::assertStringContainsString('start = 10, end = 0;', $html); // clamped, non-numbers ignored
    }

    public function test_columns_can_be_resized(): void
    {
        $html = Livewire::test(ResponsiveOrdersTable::class)->html();
        self::assertStringNotContainsString('adt-resize', $html);
        $table = Livewire::test(ResponsiveOrdersTable::class, ['resize' => true]);
        $html = $table->html();
        self::assertSame(4, substr_count($html, 'class="adt-resize" role="separator" aria-orientation="vertical" tabindex="0"'));
        self::assertStringContainsString('aria-label="Resize Customer"', $html);
        self::assertStringNotContainsString('adt-fixed-layout', $html);

        $html = $table->call('resizeColumns', ['customer' => 210.6, 'city' => 9, 'note' => 99999, 'secret' => 300, '__actions' => 120])->html();
        self::assertSame(['customer' => 211, 'city' => 48, 'note' => 2000, '__actions' => 120], $table->get('columnWidths'));
        self::assertStringContainsString('adt-fixed-layout', $html);
        self::assertStringContainsString('style="--adt-actions-width: 120px"', $html);
        self::assertMatchesRegularExpression('/data-column-id="customer" wire:key="adt-th-customer"\s+style="width: 211px"/', $html);
        self::assertStringContainsString('aria-valuenow="211"', $html);

        $table->call('resizeColumns', ['customer' => null]);
        self::assertArrayNotHasKey('customer', $table->get('columnWidths'));
        $table->set('columnWidths', ['city' => 'drop table', 'total' => '150']); // tampered: read through validation
        self::assertStringContainsString('style="width: 150px"', $table->html());
        self::assertStringNotContainsString('drop table', $table->html());
    }

    public function test_columns_can_be_moved(): void
    {
        $table = Livewire::test(ResponsiveOrdersTable::class);
        $table->call('moveColumn', 'note', 0);
        self::assertSame([], $table->get('columnOrder')); // off unless reorderableColumns()

        $table = Livewire::test(ResponsiveOrdersTable::class, ['reorder' => true]);
        $html = $table->html();
        self::assertSame(4, substr_count($html, 'draggable="true"'));
        self::assertStringContainsString('$wire.moveColumnNextTo(from, $el.dataset.columnId, after)', $html);
        self::assertStringContainsString('aria-label="Move Customer up"', $html);

        $table->call('moveColumn', 'note', 0);
        self::assertSame(['note', 'customer', 'city', 'total'], $table->get('columnOrder'));
        preg_match_all('/<th scope="col" class="adt-th[^"]*"\s+data-column-id="([^"]+)"/', $table->html(), $headers);
        self::assertSame(['note', 'customer', 'city', 'total'], $headers[1]);
        $table->call('moveColumnNextTo', 'customer', 'total', true);
        self::assertSame(['note', 'city', 'total', 'customer'], $table->get('columnOrder'));
        $table->call('moveColumn', 'nope', 0);
        self::assertSame(['note', 'city', 'total', 'customer'], $table->get('columnOrder'));
        self::assertStringContainsString('Reset columns', $table->html());
        $table->call('resetColumns');
        self::assertSame([], $table->get('columnOrder'));
        self::assertStringNotContainsString('Reset columns', $table->html());
    }
}

