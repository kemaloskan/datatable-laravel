<?php

declare(strict_types=1);

namespace Acunsoft\DataTable\Laravel\Support;

use InvalidArgumentException;

/**
 * The numbers of a pager such as `1 … 4 5 6 … 20`; the same rules as
 * `getPageItems` in @acunsoft/datatable-core. While there are more pages than
 * fit, the list always has `2·boundaries + 2·siblings + 3` entries, so the pager
 * does not change width. A gap (`null`) replaces at least two pages.
 */
final class PageItems
{
    /** @return list<int|null> page numbers; `null` is a gap */
    public static function make(int $page, int $pageCount, int $siblings = 1, int $boundaries = 1): array
    {
        if ($page < 1 || $pageCount < 1) {
            throw new InvalidArgumentException('page and pageCount must be positive');
        }
        if ($siblings < 0 || $siblings > 10 || $boundaries < 0 || $boundaries > 10) {
            throw new InvalidArgumentException('siblings and boundaries must be from 0 to 10');
        }
        $current = min($page, $pageCount);
        $slots = 2 * $boundaries + 2 * $siblings + 3;
        if ($pageCount <= $slots) {
            return range(1, $pageCount);
        }

        $start = max(min($current - $siblings, $pageCount - $boundaries - 2 * $siblings - 1), $boundaries + 2);
        $end = min(max($current + $siblings, $boundaries + 2 * $siblings + 2), $pageCount - $boundaries - 1);

        return [
            ...($boundaries > 0 ? range(1, $boundaries) : []),
            $start > $boundaries + 2 ? null : $boundaries + 1,
            ...range($start, $end),
            $end < $pageCount - $boundaries - 1 ? null : $pageCount - $boundaries,
            ...($boundaries > 0 ? range($pageCount - $boundaries + 1, $pageCount) : []),
        ];
    }
}
