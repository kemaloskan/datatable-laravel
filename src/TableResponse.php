<?php

declare(strict_types=1);

namespace Acunsoft\DataTable\Laravel;

use Closure;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A JSON endpoint for @acunsoft/datatable-http: reads the query from the
 * request, applies it with QueryApplier and answers `{ rows, totalRows }`.
 * An invalid query answers HTTP 422 `{ message, errors }`.
 *
 * Rows contain only the key and the declared columns unless `transform()`
 * says otherwise, so hidden model attributes are never sent by accident.
 * Authorize the request before returning this (policy, middleware).
 *
 *     Route::get('/orders/data', fn () => TableResponse::for(Order::query())
 *         ->columns([Column::make('customer')->sortable()->filterable()])
 *         ->maxPageSize(100));
 */
final class TableResponse implements Responsable
{
    /** @var list<Column> */
    private array $columns = [];
    private int $maxPageSize = 100;
    private int $defaultPageSize = 25;
    private ?Closure $transform = null;
    private ?int $countLimit = 10_000;
    private ?int $maxResultWindow = 100_000;
    private ?int $countTimeout = 250;

    private function __construct(private readonly EloquentBuilder|QueryBuilder $builder)
    {
    }

    public static function for(EloquentBuilder|QueryBuilder $builder): self
    {
        return new self($builder);
    }

    /** @param iterable<Column> $columns */
    public function columns(iterable $columns): self
    {
        $this->columns = [...$columns];

        return $this;
    }

    public function maxPageSize(int $max, int $default = 25): self
    {
        if ($max < 1 || $max > 1000 || $default < 1 || $default > $max) {
            throw new \InvalidArgumentException('Page sizes must satisfy 1 <= default <= max <= 1000.');
        }
        $this->maxPageSize = $max;
        $this->defaultPageSize = $default;

        return $this;
    }

    /**
     * Up to this many matches (default 10,000) the count is always exact and
     * cheap. Beyond it an exact count gets `countTimeout()`; when that is not
     * enough, `totalRows` is left out and `totalRowsAtLeast` is sent. `null`
     * counts every match without a time limit, which is slow on large tables.
     */
    public function countLimit(?int $limit): self
    {
        if ($limit !== null && $limit < 1) {
            throw new \InvalidArgumentException('The count limit must be at least 1, or null.');
        }
        $this->countLimit = $limit;

        return $this;
    }

    /**
     * Time an exact count past `countLimit()` may take, in milliseconds
     * (default 250; PostgreSQL, MySQL and MariaDB). `null` never counts past
     * the limit.
     */
    public function countTimeout(?int $milliseconds): self
    {
        if ($milliseconds !== null && $milliseconds < 1) {
            throw new \InvalidArgumentException('The count timeout must be at least 1 ms, or null.');
        }
        $this->countTimeout = $milliseconds;

        return $this;
    }

    /** Refuse pages starting past this many rows (default 100,000; `null`: no limit). Answers 422. */
    public function maxResultWindow(?int $rows): self
    {
        if ($rows !== null && $rows < 1) {
            throw new \InvalidArgumentException('The result window must be at least 1, or null.');
        }
        $this->maxResultWindow = $rows;

        return $this;
    }

    /** @param Closure(mixed $row): array<string, mixed> $transform e.g. fn ($order) => new OrderResource($order) */
    public function transform(Closure $transform): self
    {
        $this->transform = $transform;

        return $this;
    }

    /** @param Request $request */
    public function toResponse($request): JsonResponse
    {
        try {
            $query = TableQuery::fromRequest($request, $this->maxPageSize, $this->defaultPageSize);
            $fresh = clone $this->builder; // paginate() applies the query to the builder it gets
            $page = QueryApplier::paginate($this->builder, $query, $this->columns, $this->countLimit, $this->maxResultWindow, $this->countTimeout);
            // Footer totals (columns with aggregate()) only while the count is exact.
            $totals = $page->totalIsExact() ? QueryApplier::aggregates($fresh, $query, $this->columns) : [];
        } catch (InvalidTableQuery $error) {
            return new JsonResponse(['message' => $error->getMessage(), 'errors' => $error->errors], 422);
        }

        $rows = array_map(fn ($row) => $this->row($row), $page->items());

        return new JsonResponse(($page->totalIsExact()
            ? ['rows' => $rows, 'totalRows' => $page->total()]
            : ['rows' => $rows, 'totalRowsAtLeast' => $page->total() + 1])
            + ($page->nextCursor() === null ? [] : ['nextCursor' => $page->nextCursor()])
            + ($totals === [] ? [] : ['totals' => $totals]));
    }

    /** @return array<string, mixed> */
    private function row(mixed $row): array
    {
        if ($this->transform !== null) {
            $result = ($this->transform)($row);

            return $result instanceof Responsable || $result instanceof \JsonSerializable
                ? (array) json_decode((string) json_encode($result), true)
                : (array) $result;
        }
        $data = [];
        if ($row instanceof Model) {
            $data[$row->getKeyName()] = $row->getKey();
        } elseif (is_object($row) && isset($row->id)) {
            $data['id'] = $row->id;
        }
        foreach ($this->columns as $column) {
            $data[$column->id()] = data_get($row, $column->attributeName());
        }

        return $data;
    }
}
