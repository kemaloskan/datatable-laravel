<?php

declare(strict_types=1);

namespace Acunsoft\DataTable\Laravel;

use InvalidArgumentException;

/** A request asked for something the table does not allow; answer with HTTP 422. */
final class InvalidTableQuery extends InvalidArgumentException
{
    /** @param array<string, string> $errors keyed by request parameter */
    public function __construct(public readonly array $errors)
    {
        parent::__construct('The table query is invalid: '.implode(' ', $errors));
    }

    public static function at(string $parameter, string $message): self
    {
        return new self([$parameter => $message]);
    }
}
