<?php

declare(strict_types=1);

namespace TimurTurdyev\Ariadne\Tests\Support\Shop;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property string $name
 * @property int|null $brand_id
 * @property int $category_id
 * @property int|null $model_id
 * @property int $price
 * @property int|null $old_price
 * @property int $sales
 * @property string|null $published_at
 * @property bool $active
 * @property-read Brand|null $brand
 * @property-read Collection<int, Color> $colors
 * @property-read Collection<int, Variant> $variants
 * @property-read Collection<int, Feature> $features
 */
final class Product extends Model
{
    use SoftDeletes;

    public $timestamps = false;

    protected $table = 'shop_products';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    /**
     * @return BelongsTo<Brand, $this>
     */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /**
     * @return BelongsToMany<Color, $this, ProductColor>
     */
    public function colors(): BelongsToMany
    {
        return $this->belongsToMany(Color::class, 'shop_product_color', 'product_id', 'color_id')->using(ProductColor::class);
    }

    /**
     * @return HasMany<Variant, $this>
     */
    public function variants(): HasMany
    {
        return $this->hasMany(Variant::class);
    }

    /**
     * @return HasMany<Feature, $this>
     */
    public function features(): HasMany
    {
        return $this->hasMany(Feature::class);
    }
}
