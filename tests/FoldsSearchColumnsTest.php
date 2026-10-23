<?php

declare(strict_types=1);

namespace Acunsoft\DataTable\Laravel\Tests;

use Acunsoft\DataTable\Laravel\Concerns\FoldsSearchColumns;
use Illuminate\Database\Eloquent\Model;

final class FoldingOrder extends Model
{
    use FoldsSearchColumns;

    protected $table = 'orders';
    public $timestamps = false;
    protected $guarded = [];

    protected function foldedSearchColumns(): array
    {
        return ['customer' => 'customer_search'];
    }
}

final class FoldsSearchColumnsTest extends TestCase
{
    public function test_saving_keeps_the_folded_copy_in_step(): void
    {
        $order = FoldingOrder::query()->create(['customer' => 'İSMAİL Çağrı Öztürk', 'total' => '1.00']);
        self::assertSame('ismail cagri ozturk', $order->fresh()->customer_search);
        $order->update(['customer' => 'Şule IŞIK']);
        self::assertSame('sule isik', $order->fresh()->customer_search);
        $order->update(['total' => '2.00']); // unrelated change keeps the copy
        self::assertSame('sule isik', $order->fresh()->customer_search);
    }
}
