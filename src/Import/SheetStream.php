<?php

declare(strict_types=1);

namespace Acunsoft\DataTable\Laravel\Import;

use Generator;
use LogicException;

/**
 * A file being read (SheetReader::stream()): its headers at once, its rows in
 * chunks as they are read, each row as wide as the headers. The rows can be
 * read once; the file is released when they are read or the stream is gone.
 */
final class SheetStream
{
    private bool $used = false;

    /**
     * @param list<string> $headers
     * @param Generator<int, list<mixed>> $records the data rows still to read
     */
    public function __construct(public readonly array $headers, private readonly Generator $records)
    {
    }

    /** @return Generator<int, list<list<mixed>>> chunks of `$size` rows (the last one may be smaller) */
    public function chunks(int $size = 1_000): Generator
    {
        if ($this->used) {
            throw new LogicException('The rows of a SheetStream can be read once.');
        }
        $this->used = true;
        $size = max(1, $size);
        $width = count($this->headers);
        $chunk = [];
        // The generator has started (its header was taken): iterate it by hand, it cannot rewind.
        for (; $this->records->valid(); $this->records->next()) {
            $record = $this->records->current();
            $row = [];
            for ($i = 0; $i < $width; $i++) {
                $row[] = $record[$i] ?? null;
            }
            $chunk[] = $row;
            if (count($chunk) === $size) {
                yield $chunk;
                $chunk = [];
            }
        }
        if ($chunk !== []) {
            yield $chunk;
        }
    }
}
