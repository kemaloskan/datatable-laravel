<?php

declare(strict_types=1);

namespace Acunsoft\DataTable\Laravel\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/** Deletes queued-export files nobody downloaded (older than a day). Schedule it daily. */
final class PruneTaskFiles extends Command
{
    protected $signature = 'acun-datatable:prune {--disk=local : The disk exports are written to} {--hours=24 : Delete files older than this}';

    protected $description = 'Delete queued table exports that were never downloaded';

    public function handle(): int
    {
        $disk = Storage::disk((string) $this->option('disk'));
        $before = now()->subHours(max(1, (int) $this->option('hours')))->getTimestamp();
        $deleted = 0;
        foreach ($disk->files('acun-datatable') as $file) {
            if ($disk->lastModified($file) < $before) {
                $disk->delete($file);
                $deleted++;
            }
        }
        $this->info("Deleted {$deleted} file(s).");

        return self::SUCCESS;
    }
}
