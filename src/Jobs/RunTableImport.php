<?php

declare(strict_types=1);

namespace Acunsoft\DataTable\Laravel\Jobs;

use Acunsoft\DataTable\Laravel\Import\TableImport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Auth;

/**
 * An import in the background, when TableImport::shouldQueue() says so: it
 * runs as the user who started it (so query() and policies see the same
 * user), on whatever queue connection the app uses — none is required.
 */
final class RunTableImport implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 3600;

    /** @param class-string<TableImport> $import */
    public function __construct(
        public readonly string $import,
        public readonly string $id,
        public readonly int|string|null $user,
        public readonly ?string $guard,
        public readonly string $locale,
    ) {
    }

    public function handle(): void
    {
        app()->setLocale($this->locale);
        // A worker runs many jobs in one process: whoever this job acts as is undone afterwards.
        $guard = Auth::guard($this->guard);
        $previous = method_exists($guard, 'hasUser') && $guard->hasUser() ? $guard->user() : null;
        try {
            if ($this->user !== null && method_exists($guard, 'getProvider') && method_exists($guard, 'setUser')) {
                $model = $guard->getProvider()->retrieveById($this->user);
                if ($model !== null) {
                    $guard->setUser($model);
                }
            }
            app($this->import)->process($this->id);
        } finally {
            if ($previous !== null && method_exists($guard, 'setUser')) {
                $guard->setUser($previous);
            } elseif (method_exists($guard, 'forgetUser')) {
                $guard->forgetUser();
            }
        }
    }
}
