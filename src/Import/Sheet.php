<?php

declare(strict_types=1);

namespace Acunsoft\DataTable\Laravel\Import;

/** Headers and rows read from a file; cells are strings, numbers, booleans, dates or null. */
final class Sheet
{
    /**
     * @param list<string> $headers
     * @param list<list<mixed>> $rows
     */
    public function __construct(public readonly array $headers, public readonly array $rows)
    {
    }
}
