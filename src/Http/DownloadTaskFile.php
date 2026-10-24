<?php

declare(strict_types=1);

namespace Acunsoft\DataTable\Laravel\Http;

use Acunsoft\DataTable\Laravel\Support\TableTasks;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The file of a finished queued export: only through a signed, expiring URL,
 * and only for the user who started it. The file is deleted once sent.
 */
final class DownloadTaskFile
{
    public function __invoke(string $id): StreamedResponse
    {
        $state = TableTasks::get($id);
        abort_if($state === null || ($state['status'] ?? null) !== 'ready' || ! isset($state['disk'], $state['path']), 404);
        abort_unless(TableTasks::belongsTo($state), 403);
        $disk = Storage::disk($state['disk']);
        abort_unless($disk->exists($state['path']), 410);
        $response = $disk->download($state['path'], $state['name'] ?? basename($state['path']), ['X-Content-Type-Options' => 'nosniff']);
        app()->terminating(function () use ($disk, $state, $id): void {
            $disk->delete($state['path']);
            TableTasks::forget($id);
        });

        return $response;
    }
}
