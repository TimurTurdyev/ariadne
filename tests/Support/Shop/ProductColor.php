<?php

declare(strict_types=1);

namespace TimurTurdyev\Ariadne\Tests\Support\Shop;

use Illuminate\Database\Eloquent\Relations\Pivot;

final class ProductColor extends Pivot
{
    public $timestamps = false;

    protected $table = 'shop_product_color';
}
