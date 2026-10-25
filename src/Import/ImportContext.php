<?php

declare(strict_types=1);

namespace Acunsoft\DataTable\Laravel\Import;

/**
 * One import while it is saved: its mode, owner and tenant, and the counts
 * your `save()` reports (`inserted()`, `updated()`, `skipped()`, `reject()`).
 */
final class ImportContext
{
    public int $inserted = 0;

    public int $updated = 0;

    public int $skipped = 0;

    /** @var list<array{line: int, column?: string, value?: string, message: string}> */
    public array $rejected = [];

    public function __construct(
        public readonly string $id,
        /** `insert`, `update` or `upsert`. */
        public readonly string $mode,
        /** Who started it (`user:5`, or a guest's session), on the import's guard. */
        public readonly string $owner,
        /** The tenant `TableImport::tenant()` gave on upload (team, company …), or null. */
        public readonly ?string $tenant,
        /** The authenticated user's id when the import was started, or null for a guest. */
        public readonly int|string|null $user,
    ) {
    }

    public function inserted(int $count = 1): void
    {
        $this->inserted += $count;
    }

    public function updated(int $count = 1): void
    {
        $this->updated += $count;
    }

    public function skipped(int $count = 1): void
    {
        $this->skipped += $count;
    }

    /** Refuse one row: it is counted as failed and listed in the error report. */
    public function reject(int $line, string $message, ?string $column = null, string $value = ''): void
    {
        $this->rejected[] = ['line' => $line, 'message' => $message] + ($column !== null ? ['column' => $column] : []) + ($value !== '' ? ['value' => $value] : []);
    }
}
