<?php

declare(strict_types=1);

namespace Acunsoft\DataTable\Laravel\Http;

use Acunsoft\DataTable\Laravel\Import\TableImport;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The routes of Route::acunImport(): each hands the request to its
 * TableImport (named in the route's defaults, so `route:cache` works).
 */
final class ImportController
{
    public function capabilities(Request $request): Response
    {
        return $this->import($request)->capabilities($request);
    }

    public function upload(Request $request): Response
    {
        return $this->import($request)->upload($request);
    }

    public function start(Request $request, string $acunImportId): Response
    {
        return $this->import($request)->start($request, $acunImportId);
    }

    public function state(Request $request, string $acunImportId): Response
    {
        return $this->import($request)->state($request, $acunImportId);
    }

    public function cancel(Request $request, string $acunImportId): Response
    {
        return $this->import($request)->cancel($request, $acunImportId);
    }

    public function errorReport(Request $request, string $acunImportId): Response
    {
        return $this->import($request)->errorReport($request, $acunImportId);
    }

    private function import(Request $request): TableImport
    {
        $class = $request->route()?->defaults['acunImport'] ?? null;
        if (! is_string($class) || ! is_subclass_of($class, TableImport::class)) {
            abort(404);
        }

        return app($class);
    }
}
