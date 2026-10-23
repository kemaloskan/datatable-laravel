<?php

declare(strict_types=1);

namespace Acunsoft\DataTable\Laravel;

use Closure;
use InvalidArgumentException;
use LogicException;

/**
 * A button in a Livewire table: per row (`rowActions()`) or for the selected
 * rows (`bulkActions()`).
 *
 * - A row action's handler receives the row, looked up again inside `query()`
 *   (a forged key cannot reach other rows): `fn (Model $row) => …`.
 * - A bulk action's handler receives the selected rows as a query inside
 *   `query()`: `fn (Builder $selected) => $selected->update([...])`.
 *
 * Authorize inside the handler (`$this->authorize(...)` works there). Whatever
 * the handler returns (a redirect, a download) is returned to Livewire.
 * Immutable: every modifier returns a copy.
 */
final class Action
{
    private string $label;
    private ?string $confirm = null;
    private ?Closure $visible = null;
    private ?Closure $handler = null;

    private function __construct(private readonly string $id)
    {
        $this->label = $id;
    }

    public static function make(string $id): self
    {
        if (preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,63}$/', $id) !== 1) {
            throw new InvalidArgumentException("Action ID [{$id}] must start with a letter and use letters, digits, _ - (max 64).");
        }

        return new self($id);
    }

    /** Button text (plain text, escaped). */
    public function label(string $label): self
    {
        return $this->with('label', $label);
    }

    /** Ask before running (`wire:confirm`), e.g. for deleting. */
    public function confirm(string $question): self
    {
        return $this->with('confirm', $question);
    }

    /**
     * Row actions: `fn ($row): bool`, checked when rendering and again before
     * running. Bulk actions: `fn (): bool`.
     */
    public function visible(Closure $visible): self
    {
        return $this->with('visible', $visible);
    }

    public function handle(Closure $handler): self
    {
        return $this->with('handler', $handler);
    }

    public function id(): string { return $this->id; }
    public function labelText(): string { return $this->label; }
    public function confirmText(): ?string { return $this->confirm; }

    public function isVisible(mixed ...$arguments): bool
    {
        return $this->visible === null || ($this->visible)(...$arguments) === true;
    }

    public function run(mixed $target): mixed
    {
        if ($this->handler === null) {
            throw new LogicException("Action [{$this->id}] has no handler; call handle().");
        }

        return ($this->handler)($target);
    }

    private function with(string $property, mixed $value): self
    {
        $copy = clone $this;
        $copy->{$property} = $value;

        return $copy;
    }
}
