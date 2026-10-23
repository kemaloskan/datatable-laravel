<?php

declare(strict_types=1);

namespace Acunsoft\DataTable\Laravel;

use Illuminate\Pagination\LengthAwarePaginator;

/**
 * A page of rows whose total may be a lower bound.
 *
 * Counting every match is the slowest part of a large table, so
 * QueryApplier::paginate() counts at most `countLimit` rows. When there are
 * more, `total()` is that limit, `totalIsExact()` is false (show "10,000+"),
 * and `hasMorePages()` comes from fetching one extra row instead of the total.
 */
final class TablePage extends LengthAwarePaginator
{
    /** @param iterable<mixed> $items */
    public function __construct(iterable $items, int $total, int $perPage, int $currentPage, private readonly bool $exact, private readonly bool $more)
    {
        parent::__construct($items, $total, $perPage, $currentPage, ['pageName' => 'page', 'path' => \Illuminate\Pagination\Paginator::resolveCurrentPath()]);
    }

    public function totalIsExact(): bool
    {
        return $this->exact;
    }

    public function hasMorePages(): bool
    {
        return $this->exact ? parent::hasMorePages() : $this->more;
    }
}
