<?php

declare(strict_types=1);

namespace TimurTurdyev\Ariadne\Tests\Support\Shop;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $product_id
 * @property string $size
 * @property int $stock
 */
final class Variant extends Model
{
    public $timestamps = false;

    protected $table = 'shop_variants';

    protected $guarded = [];

    /**
     * Model increment: Laravel sends only updating and updated for it, not saved.
     */
    public function addStock(int $amount): void
    {
        $amount >= 0 ? $this->increment('stock', $amount) : $this->decrement('stock', -$amount);
    }
}
