<?php

declare(strict_types=1);

namespace TimurTurdyev\Ariadne\Tests\Support\Shop;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $slug
 * @property string $name
 * @property string $hex
 */
final class Color extends Model
{
    public $timestamps = false;

    protected $table = 'shop_colors';

    protected $guarded = [];
}
