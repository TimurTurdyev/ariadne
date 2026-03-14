<?php

declare(strict_types=1);

namespace TimurTurdyev\Ariadne\Tests\Support\Shop;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use TimurTurdyev\Ariadne\Catalog\Attributes\Fields;
use TimurTurdyev\Ariadne\Catalog\Attributes\Price;
use TimurTurdyev\Ariadne\Catalog\CatalogDefinition;
use TimurTurdyev\Ariadne\Catalog\Facet;
use TimurTurdyev\Ariadne\Catalog\Panel;
use TimurTurdyev\Ariadne\Catalog\ShapesPanel;
use TimurTurdyev\Ariadne\Catalog\Sort;
use TimurTurdyev\Ariadne\Catalog\Term;

/**
 * A shop with a brand, colors through a pivot class, sizes of variants, properties from the admin and a toggle.
 *
 * @implements CatalogDefinition<Product>
 * @implements ShapesPanel<mixed>
 */
#[Price('price')]
#[Fields('category_id', 'brand', 'colors')]
final class ShopCatalog implements CatalogDefinition, ShapesPanel
{
    /** @var (Closure(Panel, mixed): Panel)|null rules a test sets for its screens */
    public static ?Closure $rules = null;

    /** @var (Closure(): iterable<Facet>)|null groups of the properties as the admin describes them; null - from the terms */
    public static ?Closure $described = null;

    /** @var (Closure(Product): void)|null runs inside the brand producer: an edit in the middle of a rebuild */
    public static ?Closure $whileProducing = null;

    public function documents(): Builder
    {
        return Product::query()->where('active', true);
    }

    public function facets(): array
    {
        $props = Facet::dynamic('props', static function (Product $product): iterable {
            foreach ($product->features as $feature) {
                yield Term::of($feature->value, $feature->label, $feature->sort)->in($feature->group, $feature->group_title);
            }
        })->with('features');

        return [
            Facet::make('brand', 'Brand', static function (Product $product): array {
                if (self::$whileProducing !== null) {
                    (self::$whileProducing)($product);
                }

                return $product->brand === null ? [] : [$product->brand->slug => $product->brand->name];
            })->with('brand')->single(),
            Facet::make('color', 'Color', static function (Product $product): array {
                $terms = [];

                foreach ($product->colors as $color) {
                    $terms[] = Term::of($color->slug, $color->name)->image($color->hex);
                }

                return $terms;
            })->with('colors')->swatches(),
            Facet::make('size', 'Size', static function (Product $product): array {
                $sizes = [];

                foreach ($product->variants as $variant) {
                    if ($variant->stock > 0) {
                        $sizes[$variant->size] = $variant->size;
                    }
                }

                return $sizes;
            })->with('variants'),
            self::$described === null ? $props : $props->describedBy(self::$described),
            Facet::toggle('sale', 'On sale', static fn (Product $product): bool => $product->old_price !== null && $product->old_price > $product->price),
        ];
    }

    public function sorts(): array
    {
        return [
            Sort::make('popular', 'Popular', 'sales desc'),
            Sort::make('new', 'Newest', 'published_at desc'),
            Sort::make('price-asc', 'Cheaper first', 'price'),
            Sort::make('price-desc', 'Dearer first', 'price desc'),
            Sort::relevance('Relevance'),
        ];
    }

    public function panel(Panel $panel, mixed $context): Panel
    {
        return self::$rules === null ? $panel : (self::$rules)($panel, $context);
    }
}
