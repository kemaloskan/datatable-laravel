<?php

declare(strict_types=1);

namespace Acunsoft\DataTable\Laravel;

use Closure;
use InvalidArgumentException;

/**
 * A column the server allows a client to sort, filter or search by.
 *
 * Only declared columns ever reach the query, and the database column name
 * comes from the developer (`column()`), never from the request. Immutable:
 * every modifier returns a copy.
 */
final class Column
{
    public const TYPES = ['text', 'number', 'decimal', 'date', 'datetime', 'boolean'];

    private string $column;
    private string $header;
    private ?string $type = null;
    private bool $sortable = false;
    private bool $filterable = false;
    private ?bool $searchable = null;
    private ?string $searchColumn = null;
    private ?Closure $formatter = null;
    private bool $hideable = true;
    private bool $hidden = false;
    private ?int $priority = null;

    private function __construct(private readonly string $id)
    {
        $this->column = $id;
        $this->header = $id;
    }

    /** `$id` is the public name used by the browser and in URLs. */
    public static function make(string $id): self
    {
        if (preg_match('/^[A-Za-z][A-Za-z0-9_.-]{0,63}$/', $id) !== 1) {
            throw new InvalidArgumentException("Column ID [{$id}] must start with a letter and use letters, digits, _ . - (max 64).");
        }

        return new self($id);
    }

    /** The database column, e.g. `created_at` or `orders.total`. Defaults to the ID. */
    public function column(string $column): self
    {
        return $this->with('column', self::identifier($column));
    }

    public function header(string $header): self
    {
        return $this->with('header', $header);
    }

    /** One of text, number, decimal, date (a calendar day), datetime (an instant), boolean. */
    public function type(string $type): self
    {
        if (! in_array($type, self::TYPES, true)) {
            throw new InvalidArgumentException("Column type [{$type}] must be one of: ".implode(', ', self::TYPES).'.');
        }

        return $this->with('type', $type);
    }

    public function sortable(bool $sortable = true): self
    {
        return $this->with('sortable', $sortable);
    }

    /** Filterable columns are also searchable unless `searchable(false)` says otherwise. */
    public function filterable(bool $filterable = true): self
    {
        return $this->with('filterable', $filterable);
    }

    public function searchable(bool $searchable = true): self
    {
        return $this->with('searchable', $searchable);
    }

    /**
     * Search and `contains`/`startsWith` against a column that stores
     * `Text::fold()` of the text, for the same accent- and Turkish-insensitive
     * matching as the browser regardless of the database collation.
     */
    public function searchUsing(string $foldedColumn): self
    {
        return $this->with('searchColumn', self::identifier($foldedColumn));
    }

    /**
     * Display text for the Livewire views: `fn (mixed $value, mixed $row): string`.
     * Blade escapes the result; return plain text, not HTML.
     */
    public function format(Closure $formatter): self
    {
        return $this->with('formatter', $formatter);
    }

    /** Whether users may hide the column in the Livewire view. Default true. */
    public function hideable(bool $hideable = true): self
    {
        return $this->with('hideable', $hideable);
    }

    /** Hidden until the user shows it (Livewire). It still sorts, filters and searches. */
    public function hidden(bool $hidden = true): self
    {
        return $this->with('hidden', $hidden);
    }

    /**
     * Which columns stay on narrow screens with `responsive()` `collapse` or
     * `modal`: 1 always, 2 hidden below 640px, 3 hidden below 960px; hidden
     * values move into the row's details. Default: 1 for the first visible
     * column, 2 for the others.
     */
    public function priority(int $priority): self
    {
        if (! in_array($priority, [1, 2, 3], true)) {
            throw new InvalidArgumentException("Column [{$this->id}] priority must be 1, 2 or 3.");
        }

        return $this->with('priority', $priority);
    }

    /** The responsive priority at a position among the visible columns. */
    public function priorityAt(int $index): int { return $this->priority ?? ($index === 0 ? 1 : 2); }

    public function formatter(): ?Closure { return $this->formatter; }
    public function isHideable(): bool { return $this->hideable; }
    public function isHiddenByDefault(): bool { return $this->hidden && $this->hideable; }
    public function id(): string { return $this->id; }
    public function databaseColumn(): string { return $this->column; }

    /** The attribute on a result row: `total` for the column `orders.total`. */
    public function attributeName(): string
    {
        $dot = strrpos($this->column, '.');

        return $dot === false ? $this->column : substr($this->column, $dot + 1);
    }
    public function headerText(): string { return $this->header; }
    public function valueType(): ?string { return $this->type; }
    public function isSortable(): bool { return $this->sortable; }
    public function isFilterable(): bool { return $this->filterable; }
    public function isSearchable(): bool { return $this->searchable ?? $this->filterable; }
    public function foldedColumn(): ?string { return $this->searchColumn; }

    private static function identifier(string $column): string
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)?$/', $column) !== 1) {
            throw new InvalidArgumentException("Database column [{$column}] is not a plain identifier.");
        }

        return $column;
    }

    private function with(string $property, mixed $value): self
    {
        $copy = clone $this;
        $copy->{$property} = $value;

        return $copy;
    }
}
