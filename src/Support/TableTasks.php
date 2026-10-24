<?php

declare(strict_types=1);

namespace Acunsoft\DataTable\Laravel\Support;

use Illuminate\Support\Facades\Cache;

/**
 * The state of queued table tasks (exports, bulk actions), kept in the
 * cache for a day under an unguessable ID: who started it, whether it is
 * running, ready or failed, and for an export where its file is.
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

    /** Whether `$user` (the current user's ID, or null for a guest) started the task. */
    public static function belongsTo(array $state, mixed $user): bool
    {
        return array_key_exists('user', $state) && (string) $state['user'] === (string) $user;
    }

    private static function key(string $id): string
    {
        if (preg_match('/^[A-Za-z0-9-]{20,64}$/', $id) !== 1) {
            throw new \InvalidArgumentException('Invalid task ID.');
        }

        return 'acun-datatable:task:'.$id;
    }
}
