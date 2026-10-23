<?php

declare(strict_types=1);

namespace Acunsoft\DataTable\Laravel\Import;

/** What `RowImporter` found: valid rows to store and every problem with its line. */
final class ImportResult
{
    /**
     * @param list<array{line: int, values: array<string, mixed>}> $rows
     * @param list<array{line: int, column: string, value: string, message: string}> $errors
     * @param list<string> $ignoredHeaders
     */
    public function __construct(
        public readonly array $rows,
        public readonly array $errors,
        public readonly array $ignoredHeaders,
        public readonly bool $truncated,
    ) {
    }

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }
}
