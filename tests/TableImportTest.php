<?php

declare(strict_types=1);

namespace Acunsoft\DataTable\Laravel\Tests;

use Acunsoft\DataTable\Laravel\DataTableServiceProvider;
use Acunsoft\DataTable\Laravel\Jobs\RunTableImport;
use Acunsoft\DataTable\Laravel\Tests\Fixtures\ImportOrdersTable;
use Acunsoft\DataTable\Laravel\Tests\Fixtures\Order;
use Acunsoft\DataTable\Laravel\Tests\Fixtures\OrdersImport;
use Illuminate\Auth\GenericUser;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

final class TableImportTest extends TestCase
{
    private const URL = '/import/orders';

    protected function getPackageProviders($app): array
    {
        return [\Livewire\LivewireServiceProvider::class, DataTableServiceProvider::class];
    }

    protected function defineRoutes($router): void
    {
        Route::middleware('web')->group(fn () => Route::acunImport('import/orders', OrdersImport::class));
    }

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        // Users the queued job can find again (a plain users table through the database provider).
        config(['auth.providers.users' => ['driver' => 'database', 'table' => 'users']]);
        \Illuminate\Support\Facades\Schema::create('users', fn (\Illuminate\Database\Schema\Blueprint $table) => $table->id());
        \Illuminate\Support\Facades\DB::table('users')->insert([['id' => 5], ['id' => 6]]);
        OrdersImport::$queueFrom = PHP_INT_MAX;
        OrdersImport::$maxKilobytes = 64;
        $this->actingAs(new GenericUser(['id' => 5]));
    }

    private function csv(string $content, string $name = 'orders.csv'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $content);
    }

    /** @param array<string, string> $headers */
    private function upload(UploadedFile $file, array $headers = []): TestResponse
    {
        return $this->post(self::URL, ['file' => $file], $headers + ['Accept' => 'application/json']);
    }

    private function orders(int $count, int $from = 1): string
    {
        return "Customer,City,Total,Note\n".implode("\n", array_map(fn ($i) => "New {$i},Bursa,{$i}.50,x", range($from, $from + $count - 1)));
    }

    public function test_capabilities_repeat_the_back_end_limits_for_the_ui(): void
    {
        $this->getJson(self::URL)->assertOk()->assertExactJson([
            'protocol' => 'acun-import/1', 'accept' => ['.xlsx', '.csv'], 'maxBytes' => 65536, 'maxRows' => 500, 'modes' => ['upsert', 'insert', 'update'],
        ]);
        $this->getJson(self::URL, ['X-Deny' => '1'])->assertForbidden()->assertJsonPath('message', 'You may not import here.');
    }

    public function test_upload_reads_the_header_and_first_rows_and_suggests_a_mapping(): void
    {
        $response = $this->upload($this->csv("Müşteri,CITY,Total,Note\nAyşe Yılmaz,Ankara,1.250,x\n"))->assertCreated();
        $response->assertJson([
            'protocol' => 'acun-import/1', 'status' => 'mapping', 'file' => ['name' => 'orders.csv', 'rows' => 1],
            'headers' => ['Müşteri', 'CITY', 'Total', 'Note'],
            'mapping' => [null, 'city', 'total', null], // "Müşteri" is no column name here; "CITY" and "Total" are
            'preview' => [['line' => 2, 'cells' => ['Ayşe Yılmaz', 'Ankara', '1.250', 'x']]],
            'modes' => ['upsert', 'insert', 'update'], 'mode' => 'upsert',
            'columns' => [['id' => 'customer', 'title' => 'Customer', 'type' => 'text', 'required' => true], ['id' => 'city', 'title' => 'City', 'type' => 'text']],
        ]);
        self::assertMatchesRegularExpression('/^imp_[A-Za-z0-9]{32}$/', $response->json('id'));
        Storage::disk('local')->assertExists("acun-datatable/imports/{$response->json('id')}/source.csv");
    }

    public function test_the_back_end_limits_are_checked_on_every_upload(): void
    {
        OrdersImport::$maxKilobytes = 1;
        $this->upload($this->csv(str_repeat('a', 2000)))->assertStatus(413)->assertJsonPath('errors.file.0', 'The file is too large (more than 0 MB).');
        OrdersImport::$maxKilobytes = 64;
        $this->upload($this->csv('Customer', 'orders.pdf'))->assertStatus(415);
        $this->upload($this->csv("PK\x03\x04 not an xlsx", 'orders.csv'))->assertCreated(); // a CSV that starts with PK is still text
        $this->upload($this->csv("Customer\0binary", 'orders.csv'))->assertStatus(415); // NUL bytes: not a CSV
        $this->upload($this->csv('not a zip', 'orders.xlsx'))->assertStatus(415); // the xlsx signature
        $this->upload($this->csv($this->orders(501)))->assertStatus(422)->assertJsonPath('message', 'The file has more than 500 rows.');
        $this->upload($this->csv(''))->assertStatus(422);
        $this->post(self::URL, [], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonPath('errors.file.0', 'Choose a file to import.');
    }

    public function test_a_zip_bomb_is_refused_while_it_is_read(): void
    {
        // 207 KB whose sheet inflates to 200 MB while the archive claims 1 KB.
        OrdersImport::$maxKilobytes = 1024;
        $bomb = UploadedFile::fake()->createWithContent('orders.xlsx', (string) file_get_contents(__DIR__.'/Fixtures/zip-bomb.xlsx'));
        $before = memory_get_usage(true);
        $this->upload($bomb)->assertStatus(422);
        self::assertLessThan(120 * 1024 * 1024, memory_get_peak_usage(true) - $before);
    }

    public function test_the_same_idempotency_key_gives_the_same_import(): void
    {
        $first = $this->upload($this->csv($this->orders(2)), ['Idempotency-Key' => 'abc'])->assertCreated()->json('id');
        $this->upload($this->csv($this->orders(2)), ['Idempotency-Key' => 'abc'])->assertOk()->assertJsonPath('id', $first);
        self::assertNotSame($first, $this->upload($this->csv($this->orders(2)), ['Idempotency-Key' => 'other'])->json('id'));
    }

    public function test_start_checks_the_mapping_again_then_imports_at_once(): void
    {
        $id = $this->upload($this->csv("Customer,City,Total,Note\nAli Kaya,İzmir,10.5,x\nNew One,Bursa,7,y\n,Nowhere,1,\nBad Total,Bursa,abc,"))->json('id');
        $start = fn (array $body) => $this->postJson(self::URL."/{$id}/start", $body);
        $start(['mapping' => ['customer', 'city'], 'mode' => 'upsert'])->assertStatus(422)->assertJsonPath('errors.mapping', 'The mapping needs one entry per file column (4).');
        $start(['mapping' => ['customer', 'secret', null, null], 'mode' => 'upsert'])->assertStatus(422)->assertJsonPath('errors.mapping', 'Unknown column: secret.');
        $start(['mapping' => ['customer', 'customer', null, null], 'mode' => 'upsert'])->assertStatus(422)->assertJsonPath('errors.mapping', 'A column is chosen twice: Customer.');
        $start(['mapping' => [null, 'city', 'total', null], 'mode' => 'upsert'])->assertStatus(422)->assertJsonPath('errors.mapping', 'Match the required columns: Customer.');
        $start(['mapping' => ['customer', 'city', 'total', null]])->assertStatus(422)->assertJsonPath('errors.mode', 'Choose what happens to existing rows: upsert, insert, update.');
        $start(['mapping' => ['customer', 'city', 'total', null], 'mode' => 'merge'])->assertStatus(422);

        $done = $start(['mapping' => ['customer', 'city', 'total', null], 'mode' => 'upsert'])->assertOk();
        $done->assertJson(['status' => 'done', 'inserted' => 1, 'updated' => 1, 'failed' => 2, 'processed' => 4, 'total' => 4, 'errorReport' => "{$id}/errors.csv", 'cancellable' => false]);
        self::assertSame([4, 'customer', 'Required value is empty'], [$done->json('errors.0.line'), $done->json('errors.0.column'), ucfirst($done->json('errors.0.message'))]);
        self::assertSame('10.50', Order::query()->where('customer', 'Ali Kaya')->value('total')); // updated by the key
        self::assertSame('İzmir', Order::query()->where('customer', 'Ali Kaya')->value('city'));
        self::assertSame('new one', Order::query()->where('customer', 'New One')->value('customer_search')); // attributes() added it
        Storage::disk('local')->assertMissing("acun-datatable/imports/{$id}/source.csv"); // the upload is deleted once done
        $start(['mapping' => ['customer', 'city', 'total', null], 'mode' => 'upsert'])->assertStatus(409);
    }

    public function test_insert_refuses_existing_rows_and_update_skips_new_ones(): void
    {
        $file = "Customer,Total\nAli Kaya,1\nBrand New,2";
        $id = $this->upload($this->csv($file))->json('id');
        $this->postJson(self::URL."/{$id}/start", ['mapping' => ['customer', 'total'], 'mode' => 'insert'])
            ->assertJson(['inserted' => 1, 'failed' => 1, 'errors' => [['line' => 2, 'column' => 'customer', 'value' => 'Ali Kaya', 'code' => 'refused', 'message' => 'a row with this value exists already']]]);
        $id = $this->upload($this->csv("Customer,Total\nAli Kaya,3\nNobody Yet,4"))->json('id');
        $this->postJson(self::URL."/{$id}/start", ['mapping' => ['customer', 'total'], 'mode' => 'update'])->assertJson(['updated' => 1, 'skipped' => 1, 'inserted' => 0]);
        self::assertFalse(Order::query()->where('customer', 'Nobody Yet')->exists());
    }

    public function test_a_key_twice_in_one_file_inserts_once_then_updates(): void
    {
        $id = $this->upload($this->csv("Customer,Total\nTwice,1\nTwice,2\nAli Kaya,3"))->json('id');
        $this->postJson(self::URL."/{$id}/start", ['mapping' => ['customer', 'total'], 'mode' => 'upsert'])->assertJson(['inserted' => 1, 'updated' => 2]);
        self::assertSame(['2.00'], Order::query()->where('customer', 'Twice')->pluck('total')->all());
    }

    public function test_the_error_report_lists_every_refused_row_and_guards_formulas(): void
    {
        $id = $this->upload($this->csv("Customer,Total\n=HYPERLINK(\"x\"),abc\nOk,1"))->json('id');
        $this->postJson(self::URL."/{$id}/start", ['mapping' => ['customer', 'total'], 'mode' => 'upsert'])->assertJsonPath('failed', 1);
        $csv = $this->get(self::URL."/{$id}/errors.csv")->assertOk()->assertHeader('Content-Type', 'text/csv; charset=utf-8')->streamedContent();
        self::assertSame("line,column,value,message\n2,Total,abc,not a valid decimal value", $csv);
        $id = $this->upload($this->csv("Customer,Total\nSomeone,=cmd|' /C calc'!A0"))->json('id');
        $this->postJson(self::URL."/{$id}/start", ['mapping' => ['customer', 'total'], 'mode' => 'insert'])->assertJsonPath('failed', 1);
        self::assertSame("line,column,value,message\n2,Total,'=cmd|' /C calc'!A0,not a valid decimal value", $this->get(self::URL."/{$id}/errors.csv")->streamedContent()); // the refused value, guarded
    }

    public function test_imports_belong_to_their_user_and_tenant(): void
    {
        $id = $this->upload($this->csv($this->orders(1)), ['X-Tenant' => 'a'])->json('id');
        $this->getJson(self::URL."/{$id}", ['X-Tenant' => 'a'])->assertOk();
        $this->getJson(self::URL."/{$id}", ['X-Tenant' => 'b'])->assertNotFound(); // another tenant
        $this->actingAs(new GenericUser(['id' => 6]));
        $this->getJson(self::URL."/{$id}", ['X-Tenant' => 'a'])->assertNotFound(); // another user
        $this->deleteJson(self::URL."/{$id}", [], ['X-Tenant' => 'a'])->assertNotFound();
        $this->getJson(self::URL.'/imp_'.str_repeat('x', 32))->assertNotFound();
        $this->getJson(self::URL.'/../secrets')->assertNotFound();
    }

    public function test_cancel_discards_an_import_waiting_for_its_mapping(): void
    {
        $id = $this->upload($this->csv($this->orders(2)))->json('id');
        $this->deleteJson(self::URL."/{$id}")->assertOk()->assertJson(['status' => 'cancelled', 'cancellable' => false]);
        Storage::disk('local')->assertMissing("acun-datatable/imports/{$id}/source.csv");
        $this->deleteJson(self::URL."/{$id}")->assertStatus(409);
        $this->postJson(self::URL."/{$id}/start", ['mapping' => ['customer', 'city', 'total', null], 'mode' => 'upsert'])->assertStatus(409);
    }

    public function test_the_back_end_may_queue_it_and_the_ui_follows_the_job(): void
    {
        OrdersImport::$queueFrom = 5;
        Queue::fake();
        $id = $this->upload($this->csv($this->orders(7)))->json('id');
        $this->postJson(self::URL."/{$id}/start", ['mapping' => ['customer', 'city', 'total', null], 'mode' => 'upsert'])
            ->assertOk()->assertJson(['status' => 'queued', 'processed' => 0, 'retryAfter' => 1000, 'cancellable' => true]);
        Queue::assertPushed(RunTableImport::class, fn (RunTableImport $job) => $job->id === $id && $job->user === 5);
        // The worker runs it later, as the user who started it.
        (new RunTableImport(OrdersImport::class, $id, 5, null, 'en'))->handle();
        $this->getJson(self::URL."/{$id}")->assertJson(['status' => 'done', 'inserted' => 7, 'processed' => 7, 'total' => 7]);
        self::assertSame(12, Order::query()->count());
    }

    public function test_a_running_import_stops_between_chunks_when_cancelled(): void
    {
        OrdersImport::$queueFrom = 5;
        Queue::fake();
        $id = $this->upload($this->csv($this->orders(9)))->json('id');
        $this->postJson(self::URL."/{$id}/start", ['mapping' => ['customer', 'city', 'total', null], 'mode' => 'insert'])->assertJson(['status' => 'queued']);
        $this->deleteJson(self::URL."/{$id}")->assertOk()->assertJson(['status' => 'cancelled']);
        (new RunTableImport(OrdersImport::class, $id, 5, null, 'en'))->handle();
        $this->getJson(self::URL."/{$id}")->assertJson(['status' => 'cancelled', 'inserted' => 0]);
        self::assertSame(5, Order::query()->count());
    }

    public function test_with_the_sync_queue_a_queued_import_is_done_in_the_same_request(): void
    {
        OrdersImport::$queueFrom = 1;
        config(['queue.default' => 'sync']);
        $id = $this->upload($this->csv($this->orders(4)))->json('id');
        $this->postJson(self::URL."/{$id}/start", ['mapping' => ['customer', 'city', 'total', null], 'mode' => 'insert'])->assertJson(['status' => 'done', 'inserted' => 4]);
    }

    public function test_guests_need_a_session_and_route_names_stay_free(): void
    {
        $this->app['auth']->forgetGuards();
        $this->getJson(self::URL)->assertOk(); // the web middleware started a session: a guest can own an import
        self::assertSame(6, collect(Route::getRoutes()->getRoutes())->filter(fn ($route) => str_starts_with($route->uri(), 'import/orders'))->count());
    }

    public function test_a_livewire_table_with_import_endpoint_opens_the_import_dialog(): void
    {
        app()->setLocale('tr');
        $html = \Livewire\Livewire::test(ImportOrdersTable::class)->html();
        self::assertStringContainsString('class="adt-menu-item adt-import-open"', $html);
        self::assertMatchesRegularExpression("~window\\.AcunImport\\.openImport\\(\\{ url: 'http:\\\\/\\\\/localhost(:\\d+)?\\\\/import\\\\/orders', language: 'tr', headers: ~", $html);
        self::assertStringContainsString('table: { reload: () => $wire.$refresh() }', $html);
        self::assertStringContainsString('>İçe aktar…</button>', $html);
    }

    public function test_prune_deletes_import_folders_older_than_the_limit(): void
    {
        $old = $this->upload($this->csv($this->orders(1)))->json('id');
        $fresh = $this->upload($this->csv($this->orders(1)))->json('id');
        touch(Storage::disk('local')->path("acun-datatable/imports/{$old}/source.csv"), time() - 25 * 3600); // the file system's clock
        $this->artisan('acun-datatable:prune')->expectsOutput('Deleted 1 file(s).')->assertSuccessful();
        Storage::disk('local')->assertMissing("acun-datatable/imports/{$old}/source.csv");
        Storage::disk('local')->assertExists("acun-datatable/imports/{$fresh}/source.csv");
    }
}
