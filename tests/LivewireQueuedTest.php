<?php

declare(strict_types=1);

namespace Acunsoft\DataTable\Laravel\Tests;

use Acunsoft\DataTable\Laravel\DataTableServiceProvider;
use Acunsoft\DataTable\Laravel\Support\TableTasks;
use Acunsoft\DataTable\Laravel\Tests\Fixtures\Order;
use Acunsoft\DataTable\Laravel\Tests\Fixtures\QueuedOrdersTable;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Livewire\LivewireServiceProvider;

final class LivewireQueuedTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [LivewireServiceProvider::class, DataTableServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('queue.default', 'sync');
        $app['config']->set('session.driver', 'array');
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
    }

    public function test_a_large_export_is_prepared_in_the_queue_and_downloaded_once_by_its_owner(): void
    {
        Storage::fake('local');
        $this->startSession(); // a guest's task belongs to the guest's session
        $session = session()->getId();
        $table = Livewire::test(QueuedOrdersTable::class)->set('search', 'a')->call('export', 'csv');
        $id = $table->get('tableTask');
        self::assertIsString($id);
        $state = TableTasks::get($id);
        self::assertSame('ready', $state['status']);
        $matching = Order::query()->where('customer', 'like', '%a%')->count();
        $csv = Storage::disk('local')->get($state['path']);
        self::assertCount($matching + 1, array_filter(explode("\r\n", ltrim($csv, "\u{FEFF}")))); // the search applied, past the limit of 2
        $html = $table->html();
        self::assertStringContainsString('The export is ready.', $html);
        preg_match('/<a class="adt-task-link" href="([^"]+)"/', $html, $link);
        $url = html_entity_decode($link[1]);
        self::assertStringContainsString('signature=', $url);

        $this->get(preg_replace('/signature=[^&]+/', 'signature=forged', $url))->assertForbidden();
        $this->get($url)->assertForbidden(); // another guest (no session cookie): the link alone is not enough
        $cookie = config('session.cookie');
        $this->withCookie($cookie, $session)->get($url)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        self::assertFalse(Storage::disk('local')->exists($state['path'])); // deleted once sent
        $this->withCookie($cookie, $session)->get($url)->assertNotFound();
    }

    public function test_only_the_user_who_started_it_may_download(): void
    {
        Storage::fake('local');
        $this->actingAs(new \Illuminate\Auth\GenericUser(['id' => 7]));
        $table = Livewire::test(QueuedOrdersTable::class)->call('export', 'csv');
        $id = $table->get('tableTask');
        $state = TableTasks::get($id);
        self::assertSame('7', (string) $state['user']);
        self::assertSame('failed', $state['status']); // the job runs as user 7, who cannot be loaded here
        self::assertStringContainsString('The task could not be completed.', $table->html()); // no details shown
        self::assertStringNotContainsString('SQLSTATE', $table->html());

        Storage::disk('local')->put('acun-datatable/'.$id.'.csv', 'x');
        TableTasks::put($id, ['status' => 'ready', 'disk' => 'local', 'path' => 'acun-datatable/'.$id.'.csv', 'name' => 'orders.csv']);
        $url = \Illuminate\Support\Facades\URL::temporarySignedRoute('acun-datatable.download', now()->addHour(), ['id' => $id]);
        $this->actingAs(new \Illuminate\Auth\GenericUser(['id' => 8]));
        $this->get($url)->assertForbidden();
        $this->actingAs(new \Illuminate\Auth\GenericUser(['id' => 7]));
        $this->get($url)->assertOk();
    }

    public function test_a_queued_bulk_action_runs_with_the_selection_and_reports_done(): void
    {
        Order::query()->update(['active' => false]);
        $this->startSession();
        $table = Livewire::test(QueuedOrdersTable::class)->call('toggleRow', 1)->call('toggleRow', 3)->call('runBulkAction', 'activate');
        self::assertSame([1, 3], Order::query()->where('active', true)->orderBy('id')->pluck('id')->all());
        self::assertSame([], $table->get('selected'));
        $table->call('checkTableTask');
        self::assertStringContainsString('The action is done.', $table->html());
        $table->call('dismissTableTask');
        self::assertNull($table->get('tableTask'));
    }

    public function test_old_files_nobody_downloaded_are_pruned(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('acun-datatable/old.csv', 'x');
        Storage::disk('local')->put('acun-datatable/new.csv', 'x');
        touch(Storage::disk('local')->path('acun-datatable/old.csv'), time() - 2 * 86_400);
        $this->artisan('acun-datatable:prune')->expectsOutput('Deleted 1 file(s).')->assertSuccessful();
        self::assertSame(['acun-datatable/new.csv'], Storage::disk('local')->files('acun-datatable'));
    }

    public function test_one_task_at_a_time_within_a_limit_and_never_without_an_owner(): void
    {
        \Illuminate\Support\Facades\Queue::fake();
        $this->startSession();
        $table = Livewire::test(QueuedOrdersTable::class)->call('export', 'csv');
        $first = $table->get('tableTask');
        self::assertSame('queued', TableTasks::get($first)['status']);
        $table->call('export', 'csv')->assertHasErrors('tableTask');
        self::assertSame($first, $table->get('tableTask'));
        self::assertStringContainsString('still running', $table->html());
        \Illuminate\Support\Facades\Queue::assertPushed(\Acunsoft\DataTable\Laravel\Jobs\RunTableTask::class, 1);

        for ($i = 0; $i < 9; $i++) {
            $table->call('dismissTableTask')->call('export', 'csv');
        }
        $table->call('dismissTableTask')->call('export', 'csv')->assertHasErrors('tableTask'); // the 11th in 10 minutes
        \Illuminate\Support\Facades\Queue::assertPushed(\Acunsoft\DataTable\Laravel\Jobs\RunTableTask::class, 10);
    }

    public function test_the_worker_forgets_the_user_of_a_job_once_it_is_done(): void
    {
        $guard = \Illuminate\Support\Facades\Auth::guard();
        $guard->setUser(new \Illuminate\Auth\GenericUser(['id' => 1]));
        $guard->forgetUser();
        TableTasks::put('a1b2c3d4e5f6g7h8i9j0', ['status' => 'queued', 'owner' => 'user:1']);
        $provider = \Mockery::mock(\Illuminate\Contracts\Auth\UserProvider::class);
        $provider->shouldReceive('retrieveById')->with(1)->andReturn(new \Illuminate\Auth\GenericUser(['id' => 1]));
        $guard->setProvider($provider);
        (new \Acunsoft\DataTable\Laravel\Jobs\RunTableTask(QueuedOrdersTable::class, [], 'bulk', 'activate', 'a1b2c3d4e5f6g7h8i9j0', 1, null, 'en'))->handle();
        self::assertNull($guard->user()); // the next job does not run as user 1
    }
}
