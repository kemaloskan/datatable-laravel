<?php

declare(strict_types=1);

namespace Acunsoft\DataTable\Laravel;

use Illuminate\Support\ServiceProvider;

/**
 * Registers the views (`acun-datatable::…`) and the TR/EN texts
 * (`acun-datatable::table.*`). Loaded automatically by package discovery.
 */
final class DataTableServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $views = __DIR__.'/../resources/views';
        $this->loadViewsFrom($views, 'acun-datatable');
        $this->loadTranslationsFrom(__DIR__.'/../lang', 'acun-datatable');
        // Files of queued exports (WithDataTable::queuedExports()): signed links for the user who asked.
        \Illuminate\Support\Facades\Route::middleware(['web', 'signed'])
            ->get('acun-datatable/tasks/{id}/download', Http\DownloadTaskFile::class)
            ->where('id', '[A-Za-z0-9-]{20,64}')
            ->name('acun-datatable.download');

        if ($this->app->runningInConsole()) {
            $this->commands([Console\PruneTaskFiles::class]);
            $this->publishes([$views => resource_path('views/vendor/acun-datatable')], 'acun-datatable-views');
            $this->publishes([__DIR__.'/../lang' => lang_path('vendor/acun-datatable')], 'acun-datatable-lang');
        }
    }
}
