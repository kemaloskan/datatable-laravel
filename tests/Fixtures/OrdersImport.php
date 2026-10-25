<?php

declare(strict_types=1);

namespace Acunsoft\DataTable\Laravel\Tests\Fixtures;

use Acunsoft\DataTable\Laravel\Column;
use Acunsoft\DataTable\Laravel\Import\ImportContext;
use Acunsoft\DataTable\Laravel\Import\TableImport;
use Acunsoft\DataTable\Laravel\Support\Text;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/** Orders imported by customer name: insert, update or upsert; queued from `queueFrom` rows. */
final class OrdersImport extends TableImport
{
    public static int $queueFrom = PHP_INT_MAX;

    public static int $maxKilobytes = 64;

    protected function columns(): array
    {
        return [
            Column::make('customer')->header('Customer'),
            Column::make('city')->header('City'),
            Column::make('total')->header('Total')->type('decimal'),
            Column::make('active')->header('Active')->type('boolean'),
        ];
    }

    public function authorize(Request $request): bool
    {
        return $request->header('X-Deny') === null;
    }

    protected function required(): array
    {
        return ['customer'];
    }

    protected function importKey(): ?string
    {
        return 'customer';
    }

    protected function tenant(Request $request): ?string
    {
        return $request->header('X-Tenant');
    }

    protected function maxKilobytes(): int
    {
        return self::$maxKilobytes;
    }

    protected function maxRows(): int
    {
        return 500;
    }

    protected function chunkSize(): int
    {
        return 3;
    }

    protected function shouldQueue(int $rows): bool
    {
        return $rows >= self::$queueFrom;
    }

    protected function query(ImportContext $context): Builder
    {
        return Order::query();
    }

    protected function attributes(array $values, ImportContext $context): array
    {
        $attributes = parent::attributes($values, $context);
        if (isset($attributes['customer'])) {
            $attributes['customer_search'] = Text::fold($attributes['customer']);
        }

        return $attributes;
    }
}
