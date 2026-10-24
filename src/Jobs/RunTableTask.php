<?php

declare(strict_types=1);

namespace Acunsoft\DataTable\Laravel\Jobs;

use Acunsoft\DataTable\Laravel\Support\TableTasks;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * A table task in the background: a large export, or a bulk action on many
 * rows. The Livewire table is built again from its public state (search,
 * filters, sorting, selection) as the user who started it, so `query()` and
 * policies see the same user, and the component's own runTableTask() does
 * the work.
 */
final class RunTableTask implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 3600;

    /** @param array<string, mixed> $state the component's public properties */
    public function __construct(
        public readonly string $component,
        public readonly array $state,
        public readonly string $task,
        public readonly string $argument,
        public readonly string $id,
        public readonly int|string|null $user,
        public readonly ?string $guard,
        public readonly string $locale,
    ) {
    }

    public function handle(): void
    {
        app()->setLocale($this->locale);
        TableTasks::put($this->id, ['status' => 'running']);
        // A worker runs many jobs in one process: whoever this job acts as is undone afterwards.
        $guard = Auth::guard($this->guard);
        $previous = method_exists($guard, 'hasUser') && $guard->hasUser() ? $guard->user() : null;
        try {
            if ($this->user !== null) {
                $model = method_exists($guard, 'getProvider') ? $guard->getProvider()->retrieveById($this->user) : null;
                if ($model === null) {
                    throw new \RuntimeException('The user who started the task no longer exists.');
                }
                $guard->setUser($model);
            } elseif (method_exists($guard, 'forgetUser')) {
                $guard->forgetUser(); // a guest's task runs as a guest
            }
            $component = app($this->component);
            foreach ($this->state as $name => $value) {
                if (property_exists($component, $name)) {
                    $component->{$name} = $value;
                }
            }
            $task = $this->task;
            $argument = $this->argument;
            $id = $this->id;
            $result = (fn () => $this->runTableTask($task, $argument, $id))->call($component);
            TableTasks::put($this->id, ['status' => 'ready'] + (is_array($result) ? $result : []));
        } catch (Throwable $error) {
            TableTasks::put($this->id, ['status' => 'failed', 'message' => $error->getMessage()]);
            report($error);
        } finally {
            if ($previous !== null) {
                $guard->setUser($previous);
            } elseif (method_exists($guard, 'forgetUser')) {
                $guard->forgetUser();
            }
        }
    }
}
