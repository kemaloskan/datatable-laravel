<?php

declare(strict_types=1);

namespace Acunsoft\DataTable\Laravel\Support;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

/**
 * The state of queued table tasks (exports, bulk actions), kept in the
 * cache for a day under an unguessable ID: who started it (a user, or a
 * guest's session), whether it is running, ready or failed, and for an
 * export where its file is.
 */
final class TableTasks
{
    public const TTL = 86_400;

    /** @param array<string, mixed> $state */
    public static function put(string $id, array $state): void
    {
        Cache::put(self::key($id), $state + (self::get($id) ?? []), self::TTL);
    }

    /** @return array<string, mixed>|null */
    public static function get(string $id): ?array
    {
        $state = Cache::get(self::key($id));

        return is_array($state) ? $state : null;
    }

    public static function forget(string $id): void
    {
        Cache::forget(self::key($id));
    }

    /**
     * Who is asking, on `$guard`: `user:<id>` when signed in, else `session:<hash>`
     * for a guest with a session; null when there is neither (no task then).
     */
    public static function owner(?string $guard): ?string
    {
        $user = Auth::guard($guard)->id();
        if ($user !== null) {
            return 'user:'.$user;
        }
        $session = app()->bound('session') ? app('session')->driver() : null;

        return $session !== null && $session->isStarted() && $session->getId() !== ''
            ? 'session:'.hash('sha256', $session->getId())
            : null;
    }

    /** Whether the current visitor (on the task's guard) started the task. */
    public static function belongsTo(array $state): bool
    {
        $owner = self::owner(isset($state['guard']) ? (string) $state['guard'] : null);

        return isset($state['owner']) && $owner !== null && hash_equals((string) $state['owner'], $owner);
    }

    private static function key(string $id): string
    {
        if (preg_match('/^[A-Za-z0-9-]{20,64}$/', $id) !== 1) {
            throw new \InvalidArgumentException('Invalid task ID.');
        }

        return 'acun-datatable:task:'.$id;
    }
}
