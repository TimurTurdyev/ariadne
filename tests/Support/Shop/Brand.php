<?php

declare(strict_types=1);

namespace TimurTurdyev\Ariadne\Tests\Support\Shop;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $slug
 * @property string $name
 */
final class Brand extends Model
{
    public $timestamps = false;

    protected $table = 'shop_brands';

    protected $guarded = [];
}
