<?php

declare(strict_types=1);

namespace Acunsoft\DataTable\Laravel\Tests;

use Acunsoft\DataTable\Laravel\Column;
use Acunsoft\DataTable\Laravel\DataTableServiceProvider;
use Acunsoft\DataTable\Laravel\InvalidTableQuery;
use Acunsoft\DataTable\Laravel\QueryApplier;
use Acunsoft\DataTable\Laravel\TableQuery;
use Acunsoft\DataTable\Laravel\TableResponse;
use Acunsoft\DataTable\Laravel\Tests\Fixtures\Order;
use Acunsoft\DataTable\Laravel\Tests\Fixtures\SmallWindowOrdersTable;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Livewire\LivewireServiceProvider;

/** Behaviour that keeps large tables fast: index-friendly dates, bounded counts, the result window. */
final class LargeTableTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [LivewireServiceProvider::class, DataTableServiceProvider::class];
    }

    /** @return list<Column> */
    private function columns(): array
    {
        return [
            Column::make('customer')->sortable()->filterable()->searchUsing('customer_search'),
            Column::make('total')->type('decimal')->sortable()->filterable(),
            Column::make('created')->column('created_at')->type('datetime')->sortable()->filterable(),
            Column::make('day')->column('created_at')->type('date')->filterable(),
        ];
    }

    /** @return list<string> */
    private function customers(array $filter): array
    {
        return QueryApplier::apply(Order::query(), TableQuery::fromArray(['filters' => [$filter], 'sort' => 'customer:asc']), $this->columns())
            ->pluck('customer')->all();
    }

    public function test_whole_days_are_index_friendly_ranges_with_correct_edges(): void
    {
        // Created: Ayşe 23 Oct 14:05, Ali 22 Oct 23:59:59, Can 23 Oct 00:00:00, Iğdır 24 Oct 09:00.
        foreach (['created', 'day'] as $field) {
            self::assertSame(['Ayşe Yılmaz', 'Can Öztürk'], $this->customers(['field' => $field, 'op' => 'eq', 'value' => '2026-10-23']), $field);
            self::assertSame(['Iğdır Ticaret'], $this->customers(['field' => $field, 'op' => 'gt', 'value' => '2026-10-23']), $field);
            self::assertSame(['Ali Kaya'], $this->customers(['field' => $field, 'op' => 'lt', 'value' => '2026-10-23']), $field);
            self::assertSame(['Ali Kaya', 'Ayşe Yılmaz', 'Can Öztürk'], $this->customers(['field' => $field, 'op' => 'between', 'value' => ['2026-10-22', '2026-10-23']]), $field);
            self::assertSame(['Ali Kaya', 'Iğdır Ticaret'], $this->customers(['field' => $field, 'op' => 'in', 'value' => ['2026-10-22', '2026-10-24']]), $field);
        }
        $sql = QueryApplier::apply(Order::query(), TableQuery::fromArray(['filters' => [['field' => 'created', 'op' => 'eq', 'value' => '2026-10-23']]]), $this->columns());
        self::assertStringNotContainsString('date(', strtolower($sql->toSql())); // the column stays bare, so its index is used
        self::assertSame(['2026-10-23 00:00:00', '2026-10-24 00:00:00'], $sql->getBindings());
    }

    public function test_counts_stop_at_the_limit_and_more_pages_come_from_one_extra_row(): void
    {
        $page = fn (int $number, ?int $limit) => QueryApplier::paginate(Order::query(), TableQuery::fromArray(['page' => $number, 'pageSize' => 2, 'sort' => 'total:asc']), $this->columns(), $limit);
        $first = $page(1, 3);
        self::assertFalse($first->totalIsExact());
        self::assertSame(3, $first->total());
        self::assertTrue($first->hasMorePages());
        self::assertCount(2, $first->items());
        $last = $page(3, 3);
        self::assertSame(['Iğdır Ticaret'], array_map(fn ($o) => $o->customer, $last->items()));
        self::assertFalse($last->hasMorePages());

        $exact = $page(1, null);
        self::assertTrue($exact->totalIsExact());
        self::assertSame(5, $exact->total());
        self::assertSame([5, true], QueryApplier::count(Order::query(), 5));
        self::assertSame([4, false], QueryApplier::count(Order::query(), 4));
    }

    public function test_pages_past_the_result_window_are_refused(): void
    {
        $this->expectException(InvalidTableQuery::class);
        $this->expectExceptionMessage('Pages past the first 4 results');
        QueryApplier::paginate(Order::query(), TableQuery::fromArray(['page' => 3, 'pageSize' => 2]), $this->columns(), 10, 4);
    }

    public function test_the_json_endpoint_reports_a_lower_bound_and_refuses_deep_pages(): void
    {
        Route::get('/bounded', fn () => TableResponse::for(Order::query())->columns($this->columns())->countLimit(3)->maxResultWindow(4));
        $this->getJson('/bounded?pageSize=2')->assertOk()->assertJsonMissingPath('totalRows')->assertJsonPath('totalRowsAtLeast', 4);
        $this->getJson('/bounded?pageSize=2&search=ayse')->assertOk()->assertJsonPath('totalRows', 1);
        $this->getJson('/bounded?pageSize=2&page=3')->assertStatus(422)->assertJsonValidationErrors('page');
    }

    public function test_livewire_shows_lower_bounds_and_stays_inside_the_window(): void
    {
        app()->setLocale('tr');
        $table = Livewire::test(SmallWindowOrdersTable::class)->set('pageSize', 2);
        $table->assertSee('Toplam: 2+ kaydın 1 ile 2 arasını gösteriyor')->assertSeeHtml('wire:click="nextPage"');
        $table->call('selectAllMatching')->assertSee('2+ seçili');
        $table->call('clearSelection')->call('toggleRow', '1')->assertSee('Eşleşen 2+ kaydın tümünü seç');

        $deep = Livewire::withQueryParams(['page' => '50', 'pageSize' => '2'])->test(SmallWindowOrdersTable::class);
        self::assertSame(2, $deep->instance()->rows->currentPage()); // window 4 rows / 2 per page
        self::assertMatchesRegularExpression('/adt-next" aria-label="[^"]+"\s+aria-disabled="true"/', $deep->html()); // the window ends here
    }
}
