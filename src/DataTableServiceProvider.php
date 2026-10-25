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

        // Route::acunImport('orders/import', OrdersImport::class): the import protocol (acun-import/1) for one
        // TableImport. Call it inside your own group for middleware: Route::middleware(['web', 'auth'])->group(…).
        \Illuminate\Support\Facades\Route::macro('acunImport', function (string $uri, string $import): void {
            if (! is_subclass_of($import, Import\TableImport::class)) {
                throw new \InvalidArgumentException("[{$import}] must extend ".Import\TableImport::class.'.');
            }
            $uri = trim($uri, '/');
            $router = \Illuminate\Support\Facades\Route::getFacadeRoot();
            $id = '{acunImportId}';
            foreach ([
                ['get', $uri, 'capabilities'], ['post', $uri, 'upload'],
                ['post', "{$uri}/{$id}/start", 'start'], ['get', "{$uri}/{$id}/errors.csv", 'errorReport'],
                ['get', "{$uri}/{$id}", 'state'], ['delete', "{$uri}/{$id}", 'cancel'],
            ] as [$method, $path, $action]) {
                $router->{$method}($path, [Http\ImportController::class, $action])
                    ->defaults('acunImport', $import)
                    ->where('acunImportId', 'imp_[A-Za-z0-9]{32}');
            }
        });

        if ($this->app->runningInConsole()) {
            $this->commands([Console\PruneTaskFiles::class]);
            $this->publishes([$views => resource_path('views/vendor/acun-datatable')], 'acun-datatable-views');
            $this->publishes([__DIR__.'/../lang' => lang_path('vendor/acun-datatable')], 'acun-datatable-lang');
        }
    }
}
