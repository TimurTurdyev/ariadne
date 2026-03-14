<?php

declare(strict_types=1);

namespace TimurTurdyev\Ariadne\Tests\Support\Shop;

use Illuminate\Database\Eloquent\Model;

/**
 * A property from the admin: its group names a panel group of the dynamic facet.
 *
 * @property int $id
 * @property int $product_id
 * @property string $group
 * @property string $group_title
 * @property string $value
 * @property string $label
 * @property int $sort
 */
final class Feature extends Model
{
    public $timestamps = false;

    protected $table = 'shop_features';

    protected $guarded = [];
}
