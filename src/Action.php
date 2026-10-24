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
    private ?string $icon = null;
    private bool $iconOnly = false;
    private bool $danger = false;
    private bool $queued = false;

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

    /**
     * An icon before the label: `view`, `edit`, `delete`, `copy`, `check`,
     * `download`, or your own SVG path data (24×24).
     */
    public function icon(string $icon, bool $only = false): self
    {
        if (Support\Icons::path($icon) === null) {
            throw new InvalidArgumentException("Action [{$this->id}] icon must be a built-in name or SVG path data.");
        }

        return $this->with('icon', $icon)->with('iconOnly', $only);
    }

    /** Show only the icon; the label becomes its accessible name and tooltip. */
    public function iconOnly(bool $only = true): self
    {
        return $this->with('iconOnly', $only);
    }

    /** A destructive action, shown in the danger colour. */
    public function danger(bool $danger = true): self
    {
        return $this->with('danger', $danger);
    }

    /**
     * A bulk action on many rows runs in a queued job (`RunTableTask`) as the
     * same user, with the same selection; the table shows when it is done.
     * Needs a queue worker.
     */
    public function queued(bool $queued = true): self
    {
        return $this->with('queued', $queued);
    }

    public function isQueued(): bool { return $this->queued; }

    public function handle(Closure $handler): self
    {
        return $this->with('handler', $handler);
    }

    public function id(): string { return $this->id; }
    public function labelText(): string { return $this->label; }
    public function confirmText(): ?string { return $this->confirm; }
    public function iconPath(): ?string { return $this->icon === null ? null : Support\Icons::path($this->icon); }
    public function isIconOnly(): bool { return $this->iconOnly && $this->icon !== null; }
    public function isDanger(): bool { return $this->danger; }

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
