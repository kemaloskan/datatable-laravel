<?php

declare(strict_types=1);

namespace Acunsoft\DataTable\Laravel\Concerns;

use Acunsoft\DataTable\Laravel\Support\Text;
use Illuminate\Database\Eloquent\Model;

/**
 * Keep folded copies of text columns up to date, for `Column::searchUsing()`.
 *
 *   final class Order extends Model
 *   {
 *       use FoldsSearchColumns;
 *
 *       protected function foldedSearchColumns(): array
 *       {
 *           return ['customer' => 'customer_search', 'city' => 'city_search'];
 *       }
 *   }
 *
 * Every save writes Text::fold() of the source column into its copy
 * ("İSTANBUL" → "istanbul", "Ayşe" → "ayse"). Mass updates
 * (`Order::query()->update()`) bypass model events: update the copies
 * yourself there (see the README's large-table guide).
 */
trait FoldsSearchColumns
{
    /** @return array<string, string> source column => folded column */
    abstract protected function foldedSearchColumns(): array;

    public static function bootFoldsSearchColumns(): void
    {
        static::saving(function (Model $model): void {
            /** @var Model&self $model */
            foreach ($model->foldedSearchColumns() as $source => $folded) {
                if (! $model->exists || $model->isDirty($source) || $model->getAttribute($folded) === null) {
                    $value = $model->getAttribute($source);
                    $model->setAttribute($folded, $value === null ? null : Text::fold((string) $value));
                }
            }
        });
    }
}
