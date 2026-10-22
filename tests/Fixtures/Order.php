<?php

declare(strict_types=1);

namespace Acunsoft\DataTable\Laravel\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

final class Order extends Model
{
    public $timestamps = false;
    protected $guarded = [];
    protected $casts = ['total' => 'decimal:2', 'active' => 'boolean'];
}
